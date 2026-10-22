<?php

namespace owaWp\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

class PluginTest extends TestCase {

	public function testCronRequestsDoNotLoadThePlugin(): void {

		Functions\when( 'wp_doing_cron' )->justReturn( true );
		Actions\expectAdded( 'init' )->never();

		new \owaWp_plugin();
	}

	public function testOtherRequestsLoadThePlugin(): void {

		Actions\expectAdded( 'init' )->once();

		new \owaWp_plugin();
	}

	public function testAdminActionsAndFeedsAreNotTrackedEvenWithTheOldSettingsStored(): void {

		Functions\when( 'get_option' )->justReturn( [
			'enable'            => 1,
			'trackAdminActions' => 1,
			'trackFeeds'        => 1,
			'owaEndpoint'       => 'https://owa.example.com/',
			'siteId'            => 'abc123',
		] );

		$removed = [ 'comment_post', 'transition_comment_status', 'user_register', 'wp_login',
			'profile_update', 'password_reset', 'trackback_post', 'add_attachment', 'edit_attachment',
			'transition_post_status', 'wp_initialize_site', 'wpmu_new_blog', 'template_redirect' ];

		foreach ( $removed as $hook ) {

			Actions\expectAdded( $hook )->never();
		}

		foreach ( [ 'the_permalink_rss', 'bloginfo_url' ] as $filter ) {

			\Brain\Monkey\Filters\expectAdded( $filter )->never();
		}

		Actions\expectAdded( 'wp_head' )->once();

		$plugin = $this->makePlugin();
		$plugin->_init();

		$this->assertArrayNotHasKey( 'trackAdminActions', $plugin->registerOptions() );
		$this->assertArrayNotHasKey( 'trackFeeds', $plugin->registerOptions() );
	}

	public function testTheTagNeedsNoApiKey(): void {

		$plugin = $this->makePlugin( [ 'owaEndpoint' => 'https://owa.example.com/', 'siteId' => 'abc123' ] );

		$this->assertTrue( $plugin->isOwaReadyToTrack() );
	}

	public function testNagDefaultsToAWarning(): void {

		$plugin = $this->makePlugin();
		$plugin->addNag( 'Choose a site.' );
		$plugin->addNag( 'Broken.', 'notice-error' );

		ob_start();
		$plugin->showNag();
		$out = ob_get_clean();

		$this->assertStringContainsString( '<div class="notice notice-warning"><p>Choose a site.</p></div>', $out );
		$this->assertStringContainsString( '<div class="notice notice-error"><p>Broken.</p></div>', $out );
	}

	public function testSetupNoticesAreWordedForTheVersionAsked(): void {

		$plugin = $this->makePlugin( [ 'owaVersion' => '1' ] );

		$this->assertSame( [
			'Enter your OWA Endpoint, API Key and Secret Auth Key to load the list of websites from OWA.',
			'Open Web Analytics requires that you select a Website ID before tracking can begin.',
		], $plugin->setupNotices() );

		$this->assertSame( [
			'Enter your OWA Endpoint and an Application Password to load the list of Observation Profiles from OWA.',
			'Open Web Analytics requires that you select an Observation Profile before tracking can begin.',
		], $plugin->setupNotices( 2 ) );
	}

	public function testSetupNoticesGoOnceTheSettingsAreSaved(): void {

		$plugin = $this->makePlugin( [ 'owaVersion' => '2', 'owaEndpoint' => 'https://owa.example.com/', 'appPassword' => 'owa_app_x', 'siteId' => 'abc123' ] );

		$this->assertSame( [], $plugin->setupNotices() );
		$this->assertSame( [ 'Enter your OWA Endpoint, API Key and Secret Auth Key to load the list of websites from OWA.' ], $plugin->setupNotices( 1 ) );
	}

	public function testTheSettingsScreenLeavesSetupNoticesToThePage(): void {

		$plugin = $this->makePlugin();
		$plugin->addNag( 'Broken.', 'notice-error' );

		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'id' => 'toplevel_page_owa-wordpress' ] );
		ob_start();
		$plugin->showNag();
		$settings = ob_get_clean();

		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'id' => 'dashboard' ] );
		ob_start();
		$plugin->showNag();
		$dashboard = ob_get_clean();

		$this->assertSame( '<div class="notice notice-error"><p>Broken.</p></div>', $settings );
		$this->assertStringContainsString( 'select a Website ID before tracking can begin', $dashboard );
	}
}
