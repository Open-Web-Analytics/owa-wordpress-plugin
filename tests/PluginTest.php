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

	public function testFeedLinksAreLeftAloneWhenLinkTrackingIsOff(): void {

		$plugin = $this->makePlugin( [ 'track_feed_links' => false ] );

		$this->assertSame( 'https://example.com/post/', $plugin->decorateFeedEntryPermalink( 'https://example.com/post/' ) );
	}

	public function testFeedLinksAreDecoratedWhenLinkTrackingIsOn(): void {

		Functions\when( 'get_query_var' )->justReturn( '42' );

		$plugin = $this->makePlugin();

		$this->assertSame(
			'https://example.com/post/?&amp;owa_medium=feed&amp;owa_sid=42',
			$plugin->decorateFeedEntryPermalink( 'https://example.com/post/' )
		);
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
}
