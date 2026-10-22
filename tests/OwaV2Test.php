<?php

namespace owaWp\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

/**
 * 2.x mode: the Profile's tracking bundle, an application password for the
 * Website ID list, and none of the tag options the OWA server now owns.
 */
class OwaV2Test extends TestCase {

	const TOKEN = 'AbCdEfGhIjKl0123456789abcdefghijABCDEFGHIJ01';

	private function v2( array $options = [] ): \owaWp_plugin {

		return $this->makePlugin( $options + [
			'owaVersion'  => '2',
			'owaEndpoint' => 'https://owa.example.com/',
			'siteId'      => 'OWA-9b62dc7ce78a6934',
		] );
	}

	private function renderTag( \owaWp_plugin $plugin ): string {

		ob_start();
		$plugin->insertTrackingTag();

		return ob_get_clean();
	}

	public function testTagLoadsTheProfilesBundle(): void {

		$tag = $this->v2()->getBundleSnippet( '' );

		$this->assertStringContainsString( '<script async src="https://owa.example.com/public/tracker/OWA-9b62dc7ce78a6934.js"></script>', $tag );
		$this->assertStringContainsString( '<link rel="preconnect" href="https://owa.example.com">', $tag );
		$this->assertStringContainsString( '<script>var owa_cmds = owa_cmds || [];', $tag );
		$this->assertStringNotContainsString( 'owa.tracker.js', $tag );
	}

	public function testThePageAddsOnlyPageLevelCommands(): void {

		$this->stubConditionals( [ 'is_single' ] );
		Functions\when( 'wp_title' )->justReturn( 'Post' );

		// 1.x tag options still stored from before the switch
		$plugin = $this->v2( [ 'trackClicks' => true, 'trackDomstreams' => true, 'debug' => true ] );

		$cmds = $this->pushedCmds( $this->renderTag( $plugin ) );

		$this->assertSame( [ [ 'setPageType', 'Post' ], [ 'setPageTitle', 'Post' ] ], $cmds,
			'The bundle sets the site ID, the Profile\'s features and the page view.' );
	}

	public function testUserNameIsSentWhenNamedUsersAreTracked(): void {

		$this->stubConditionals( [ 'is_single' ] );
		Functions\when( 'wp_title' )->justReturn( 'Post' );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'wp_get_current_user' )->justReturn( (object) [ 'user_login' => 'editor1' ] );

		$cmds = $this->pushedCmds( $this->renderTag( $this->v2( [ 'trackNamedUsers' => true ] ) ) );

		$this->assertContains( [ 'setUserName', 'editor1' ], $cmds );
	}

	public function testAdminPagesAreNeverTagged(): void {

		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'id' => 'dashboard' ] );

		$this->assertSame( '', $this->renderTag( $this->v2( [ 'trackAdminPages' => true ] ) ) );
	}

	public function testAdminHeadIsNotHookedEvenWithTheOldSettingStored(): void {

		Functions\when( 'get_option' )->justReturn( [
			'enable' => 1, 'owaVersion' => '2', 'trackAdminPages' => 1,
			'owaEndpoint' => 'https://owa.example.com/', 'siteId' => 'OWA-9b62dc7ce78a6934',
		] );

		$hooked = [];
		Actions\expectAdded( 'admin_head' )->zeroOrMoreTimes()->whenHappen( function ( $callback ) use ( &$hooked ) { $hooked[] = $callback[1]; } );

		$this->makePlugin()->_init();

		$this->assertNotContains( 'insertTrackingTag', $hooked );
	}

	public function testASiteIdThatCannotBeABundleFileGetsNoTag(): void {

		$this->stubConditionals( [ 'is_single' ] );
		Functions\when( 'wp_title' )->justReturn( 'Post' );

		$this->assertSame( '', $this->renderTag( $this->v2( [ 'siteId' => '../owa-config' ] ) ) );
	}

	public function testLoggedInUsersCanBeExcludedInBothVersions(): void {

		$this->stubConditionals( [ 'is_single' ] );
		Functions\when( 'wp_title' )->justReturn( 'Post' );
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$this->assertSame( '', $this->renderTag( $this->v2( [ 'excludeLoggedInUsers' => true ] ) ) );

		$v1 = $this->makePlugin( [ 'owaEndpoint' => 'https://owa.example.com/', 'siteId' => 'abc123', 'excludeLoggedInUsers' => true ] );
		$this->assertSame( '', $this->renderTag( $v1 ) );

		$this->assertNotSame( '', $this->renderTag( $this->v2() ), 'Logged-in users are tracked unless excluded.' );
	}

	public function testTheApplicationPasswordGoesToTheSdk(): void {

		$plugin = $this->v2( [ 'appPassword' => self::TOKEN, 'apiKey' => 'legacy-key', 'authKey' => 'legacy-auth' ] );

		$this->assertTrue( $plugin->isOwaSdkReady() );

		$plugin->initOwaSdk();

		$credentials = $plugin->owaSdk->createSites()->getCredentials();

		$this->assertSame( self::TOKEN, $credentials['app_password'] );
		$this->assertSame( '', $credentials['api_key'], 'The 1.x keys are not sent in 2.x mode.' );
	}

	public function testAnHttpEndpointIsReportedUnderThePicker(): void {

		$plugin = $this->v2( [ 'owaEndpoint' => 'http://owa.example.com/', 'appPassword' => self::TOKEN ] );
		$plugin->initOwaSdk();

		$previous = ini_set( 'error_log', '/dev/null' );

		try {

			$sites = $plugin->getSitesFromOwa();

		} finally {

			ini_set( 'error_log', (string) $previous );
		}

		$this->assertSame( [ 'OWA-9b62dc7ce78a6934' ], array_keys( $sites ), 'The saved ID stays selectable.' );
		$this->assertSame( 'Could not load the list of Observation Profiles from OWA: Application password not sent: instance_url is not https', $plugin->getSitesNotice() );
	}

	public function testTheSdkConfigCanBeFiltered(): void {

		\Brain\Monkey\Filters\expectApplied( 'owa_wp_sdk_config' )
			->once()
			->andReturnUsing( fn( $config ) => $config + [ 'allow_insecure_app_password' => true ] );

		$plugin = $this->v2( [ 'owaEndpoint' => 'http://owa.example.com/', 'appPassword' => self::TOKEN ] );
		$plugin->initOwaSdk();

		$this->assertTrue( $plugin->owaSdk->createSites()->getSetting( 'allow_insecure_app_password' ) );
	}

	public function testTheWebsiteIdListWorksWithTrackingOff(): void {

		Functions\when( 'get_option' )->justReturn( [
			'enable' => 0, 'owaVersion' => '2', 'owaEndpoint' => 'https://owa.example.com/', 'appPassword' => self::TOKEN,
		] );

		$plugin = $this->makePlugin();
		$plugin->_init();

		$this->assertInstanceOf( \OwaSdk\sdk::class, $plugin->owaSdk, 'The settings page can still list the Website IDs.' );
	}

	public function testTheLegacyKeysAreNotEnoughIn2x(): void {

		$this->assertFalse( $this->v2( [ 'apiKey' => 'legacy-key', 'authKey' => 'legacy-auth' ] )->isOwaSdkReady() );
	}

	public function testPickerLabelsCarryThePropertyName(): void {

		$plugin = $this->v2( [ 'appPassword' => self::TOKEN, 'siteId' => '' ] );

		$client = new class {
			public function listSites(): array {
				return [ 'OWA-1' => [ 'site_id' => 'OWA-1', 'domain' => 'https://photopress.io', 'property_name' => 'PhotoPress' ] ];
			}
			public function getLastErrors(): array { return []; }
		};

		$plugin->owaSdk = new class( $client ) {
			public function __construct( private object $client ) {}
			public function createSites(): object { return $this->client; }
		};

		$this->assertSame( 'OWA-1 – https://photopress.io (PhotoPress)', $plugin->getSitesFromOwa()['OWA-1']['label'] );
	}

	public function testTheSettingsPageLinksToTheTrackingTagScreen(): void {

		$this->assertSame(
			'https://owa.example.com/index.php?owa_do=base.sitesInvocation&owa_siteId=OWA-9b62dc7ce78a6934',
			\owaWp\rest::settingsPayload( $this->v2() )['tagSettingsUrl']
		);

		$this->assertSame( '', \owaWp\rest::settingsPayload( $this->v2( [ 'siteId' => '' ] ) )['tagSettingsUrl'] );
		$this->assertSame( '', \owaWp\rest::settingsPayload( $this->v2( [ 'owaVersion' => '1' ] ) )['tagSettingsUrl'], '1.x has no such screen.' );
	}

	public function testAnUnnamedProfileHasNoParentheses(): void {

		$plugin = $this->v2( [ 'appPassword' => self::TOKEN, 'siteId' => '' ] );

		$client = new class {
			public function listSites(): array {
				return [ 'OWA-1' => [ 'site_id' => 'OWA-1', 'domain' => 'https://wp.test', 'property_name' => '' ] ];
			}
			public function getLastErrors(): array { return []; }
		};

		$plugin->owaSdk = new class( $client ) {
			public function __construct( private object $client ) {}
			public function createSites(): object { return $this->client; }
		};

		$this->assertSame( 'OWA-1 – https://wp.test', $plugin->getSitesFromOwa()['OWA-1']['label'] );
	}

	public function testOnly2xFieldsAreShownIn2x(): void {

		$shown = [];

		foreach ( $this->v2()->registerOptions() as $key => $option ) {

			if ( ( $option['field']['owa_version'] ?? '2' ) === '2' ) {

				$shown[] = $key;
			}
		}

		$this->assertSame(
			[ 'owaVersion', 'enable', 'appPassword', 'owaEndpoint', 'siteId', 'trackNamedUsers', 'excludeLoggedInUsers' ],
			$shown
		);
	}

	public function testTheSwitchDefaultsTo1x(): void {

		$options = $this->makePlugin()->registerOptions();

		$this->assertSame( '1', $options['owaVersion']['default_value'] );
		$this->assertSame( 1, $this->makePlugin()->getOwaVersion() );
	}
}
