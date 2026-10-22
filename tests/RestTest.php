<?php

namespace owaWp\Tests;

use Brain\Monkey\Functions;
use owaWp\rest;

/**
 * The owa-wp/v1 routes the settings pages use.
 */
class RestTest extends TestCase {

	const TOKEN = 'AbCdEfGhIjKl0123456789abcdefghijABCDEFGHIJ01';

	private array $stored = [];

	protected function setUp(): void {

		parent::setUp();

		$this->stored = [];

		Functions\when( 'get_option' )->alias( fn( $name ) => $name === 'owa_wp' ? $this->stored : false );
		Functions\when( 'update_option' )->alias( function ( $name, $value ) { $this->stored = $value; return true; } );
	}

	private function schema(): array {

		return ( new \owaWp_plugin() )->registerOptions();
	}

	// ---- validation -------------------------------------------------------------

	/**
	 * Answers the endpoint probe: the tracker loads under each endpoint in
	 * $answers, from the endpoint it maps to (itself, or where a redirect
	 * ends); anything else fails to connect. Returns the URLs requested.
	 *
	 * @param array<string, string> $answers
	 */
	private function owaAnswering( array $answers ): \ArrayObject {

		$requested = new \ArrayObject();

		Functions\when( 'wp_remote_get' )->alias( function ( $url ) use ( $answers, $requested ) {
			$requested[] = $url;
			foreach ( $answers as $endpoint => $final ) {
				if ( $url === $endpoint . rest::TRACKER_PATH ) {
					$http = new class( $final . rest::TRACKER_PATH ) {
						public function __construct( private string $url ) {}
						public function get_response_object(): object { return (object) [ 'url' => $this->url ]; }
					};
					return [ 'response' => [ 'code' => 200 ], 'http_response' => $http ];
				}
			}
			return new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
		} );

		return $requested;
	}

	public function testAnEndpointIsStoredWhereOwaAnswers(): void {

		$requested = $this->owaAnswering( [ 'https://owa.example.com/owa/' => 'https://owa.example.com/owa/' ] );

		$this->assertSame( [ 'https://owa.example.com/owa/', '' ], rest::normalizeEndpoint( ' https://owa.example.com/owa ' ), 'With its trailing slash.' );
		$this->assertSame( [ 'https://owa.example.com/owa/public/base/dist/owa.tracker.js' ], $requested->getArrayCopy() );
		$this->assertSame( [ '', '' ], rest::normalizeEndpoint( '' ), 'Empty clears it.' );
	}

	public function testARedirectIsFollowedAndWhereItEndsIsStored(): void {

		$this->owaAnswering( [ 'http://owa.example.com/' => 'https://owa.example.com/' ] );

		$this->assertSame( [ 'https://owa.example.com/', '' ], rest::normalizeEndpoint( 'http://owa.example.com' ) );
	}

	public function testAnUnchangedEndpointIsNotAskedAgain(): void {

		$requested = $this->owaAnswering( [] );

		$this->assertSame( [ 'https://owa.example.com/', '' ], rest::normalizeEndpoint( 'https://owa.example.com', 'https://owa.example.com/' ), 'OWA is down, the save goes through.' );
		$this->assertSame( [ 'http://owa.example.com/', '' ], rest::normalizeEndpoint( 'owa.example.com', 'http://owa.example.com/' ) );
		$this->assertCount( 0, $requested );
	}

	public function testAnEndpointWithoutASchemeTriesHttpsThenHttp(): void {

		$requested = $this->owaAnswering( [ 'http://owa.example.com:8080/owa/' => 'http://owa.example.com:8080/owa/' ] );

		$this->assertSame( [ 'http://owa.example.com:8080/owa/', '' ], rest::normalizeEndpoint( 'owa.example.com:8080/owa' ) );
		$this->assertSame( [
			'https://owa.example.com:8080/owa/public/base/dist/owa.tracker.js',
			'http://owa.example.com:8080/owa/public/base/dist/owa.tracker.js',
		], $requested->getArrayCopy() );
	}

	public function testHttpsIsKeptWhenItServesOwa(): void {

		$requested = $this->owaAnswering( [ 'https://owa.example.com/' => 'https://owa.example.com/', 'http://owa.example.com/' => 'http://owa.example.com/' ] );

		$this->assertSame( [ 'https://owa.example.com/', '' ], rest::normalizeEndpoint( 'owa.example.com/' ) );
		$this->assertCount( 1, $requested, 'http:// is not tried.' );
	}

	public function testATypedSchemeIsNotSwapped(): void {

		$requested = $this->owaAnswering( [ 'http://owa.example.com/' => 'http://owa.example.com/' ] );

		$this->assertSame( [ '', 'No OWA server found at https://owa.example.com/.' ], rest::normalizeEndpoint( 'https://owa.example.com/' ), 'https:// is never downgraded.' );
		$this->assertCount( 1, $requested );
	}

	public function testAnEndpointThatServesNoOwaIsRefused(): void {

		$this->owaAnswering( [] );

		$this->assertSame( [ '', 'No OWA server found at https://owa.example.com/ or http://owa.example.com/.' ], rest::normalizeEndpoint( 'owa.example.com' ) );
		$this->assertSame( [ '', 'Not a valid http:// or https:// URL.' ], rest::normalizeEndpoint( 'ftp://owa.example.com/' ) );
		$this->assertSame( [ '', 'Not a valid URL.' ], rest::normalizeEndpoint( 'owa example com' ) );
	}

	public function testTheEndpointFoundIsStored(): void {

		$this->owaAnswering( [ 'https://owa.example.com/' => 'https://owa.example.com/' ] );

		$result = rest::saveSettings( new \owaWp_plugin(), [ 'owaEndpoint' => 'owa.example.com' ] );

		$this->assertTrue( $result['saved'] );
		$this->assertSame( 'https://owa.example.com/', $this->stored['owaEndpoint'] );
		$this->assertSame( 'https://owa.example.com/', $result['values']['owaEndpoint'], 'The page shows what was stored.' );
	}

	public function testAnApplicationPasswordIs44LettersAndDigits(): void {

		$this->assertSame( '', rest::appPasswordProblem( self::TOKEN ) );
		$this->assertSame( '', rest::appPasswordProblem( '' ), 'Empty clears it.' );
		$this->assertStringStartsWith( 'This looks like a 1.x API key.', rest::appPasswordProblem( '0123456789abcdef0123456789abcdef' ) );
		$this->assertSame( 'An application password is 44 letters and digits.', rest::appPasswordProblem( 'not-a-token' ) );
	}

	public function testFieldsAreStoredByType(): void {

		[ $out, $errors ] = rest::sanitizeSettings( $this->schema(), [
			'owaVersion'      => '2',
			'enable'          => false,
			'trackNamedUsers' => 'true',
			'owaEndpoint'     => ' https://owa.example.com/ ',
			'siteId'          => 'OWA-9b62dc7ce78a6934',
		], [] );

		$this->assertSame( [], $errors );
		$this->assertSame( [ 'owaVersion' => '2', 'enable' => 0, 'owaEndpoint' => 'https://owa.example.com/', 'siteId' => 'OWA-9b62dc7ce78a6934', 'trackNamedUsers' => 1 ], $out );
	}

	public function testEachProblemIsReturnedUnderItsField(): void {

		[ , $errors ] = rest::sanitizeSettings( $this->schema(), [
			'owaVersion'  => '3',
			'owaEndpoint' => 'ftp://owa.example.com/',
			'appPassword' => 'not-a-token',
		], [] );

		$this->assertSame( [ 'owaVersion', 'appPassword', 'owaEndpoint' ], array_keys( $errors ) );
	}

	public function testA2xWebsiteIdMustBeABundleFileName(): void {

		[ , $errors ] = rest::sanitizeSettings( $this->schema(), [ 'owaVersion' => '2', 'siteId' => '../owa-config' ], [] );

		$this->assertSame( 'OWA 2.x cannot publish a tracking bundle for this Observation Profile.', $errors['siteId'] );
	}

	public function testSecretsAreKeptUnlessReplacedOrCleared(): void {

		$stored = [ 'apiKey' => 'old-key', 'authKey' => 'old-auth', 'appPassword' => self::TOKEN ];

		[ $out ] = rest::sanitizeSettings( $this->schema(), [ 'enable' => true ], $stored );
		$this->assertSame( [ 'old-key', 'old-auth', self::TOKEN ], [ $out['apiKey'], $out['authKey'], $out['appPassword'] ], 'Left out: kept.' );

		[ $out ] = rest::sanitizeSettings( $this->schema(), [ 'apiKey' => null, 'appPassword' => null ], $stored );
		$this->assertSame( [ 'old-key', self::TOKEN ], [ $out['apiKey'], $out['appPassword'] ], 'null: kept.' );

		[ $out ] = rest::sanitizeSettings( $this->schema(), [ 'apiKey' => 'new-key', 'authKey' => '', 'appPassword' => '' ], $stored );
		$this->assertSame( [ 'new-key', '', '' ], [ $out['apiKey'], $out['authKey'], $out['appPassword'] ] );
	}

	// ---- saving -----------------------------------------------------------------

	public function testAnInvalidSaveStoresNothing(): void {

		$this->stored = [ 'owaEndpoint' => 'https://owa.example.com/', 'enable' => 1 ];

		$result = rest::saveSettings( new \owaWp_plugin(), [ 'enable' => false, 'owaEndpoint' => 'ftp://owa.example.com/' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( [ 'owaEndpoint' => 'Not a valid http:// or https:// URL.' ], $result->get_error_data()['errors'] );
		$this->assertSame( [ 'owaEndpoint' => 'https://owa.example.com/', 'enable' => 1 ], $this->stored, 'Not even the valid field.' );
	}

	public function testASaveStoresAndReturnsThePageAgain(): void {

		$plugin = new \owaWp_plugin();

		$result = rest::saveSettings( $plugin, [ 'owaVersion' => '2', 'owaEndpoint' => 'https://owa.example.com/', 'appPassword' => self::TOKEN, 'siteId' => 'OWA-1' ] );

		$this->assertTrue( $result['saved'] );
		$this->assertSame( self::TOKEN, $this->stored['appPassword'] );
		$this->assertSame( 'OWA-1', $result['values']['siteId'], 'The plugin reread its options.' );
		$this->assertTrue( $result['secrets']['appPassword'] );
		$this->assertSame( 'https://owa.example.com/index.php?owa_do=base.sitesInvocation&owa_siteId=OWA-1', $result['tagSettingsUrl'] );
	}

	// ---- page caches ------------------------------------------------------------

	public function testProxyCachePurgeIsToldToPurgeTheWholeSite(): void {

		\Brain\Monkey\Filters\expectAdded( 'varnish_http_purge_events' )->once();
		\Brain\Monkey\Filters\expectAdded( 'varnish_http_purge_events_full' )->once();

		\owaWp\cache::register();

		$this->assertSame( [ 'save_post', \owaWp\cache::TAG_CHANGED ], \owaWp\cache::addPurgeEvent( [ 'save_post' ] ) );
		$this->assertSame( [ \owaWp\cache::TAG_CHANGED ], \owaWp\cache::addPurgeEvent( [ \owaWp\cache::TAG_CHANGED ] ), 'Added once.' );
	}

	public function testASiteSavePurgesWhenItsTagChanges(): void {

		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		$this->stored = [ 'enable' => 1, 'owaEndpoint' => 'https://owa.example.com/', 'siteId' => 'abc123' ];

		$plugin = new \owaWp_plugin();
		$plugin->reloadOptions();

		\Brain\Monkey\Actions\expectDone( \owaWp\cache::TAG_CHANGED )->once()->with( 1 );

		rest::saveSettings( $plugin, [ 'enable' => false ] );
	}

	public function testASiteSaveThatLeavesTheTagAlonePurgesNothing(): void {

		$this->stored = [ 'enable' => 1, 'owaEndpoint' => 'https://owa.example.com/', 'siteId' => 'abc123', 'trackNamedUsers' => 1 ];

		$plugin = new \owaWp_plugin();
		$plugin->reloadOptions();

		\Brain\Monkey\Actions\expectDone( \owaWp\cache::TAG_CHANGED )->never();

		rest::saveSettings( $plugin, [ 'enable' => true, 'trackNamedUsers' => false ] );
	}

	// ---- what the page is given -------------------------------------------------

	public function testSecretsNeverLeaveTheServer(): void {

		$this->stored = [ 'apiKey' => 'secret-key', 'authKey' => 'secret-auth', 'appPassword' => self::TOKEN, 'owaEndpoint' => 'https://owa.example.com/' ];

		$plugin = new \owaWp_plugin();
		$plugin->reloadOptions();

		$payload = rest::settingsPayload( $plugin );

		$this->assertSame( [ 'apiKey' => true, 'authKey' => true, 'appPassword' => true ], $payload['secrets'] );
		$this->assertArrayNotHasKey( 'appPassword', $payload['values'] );

		$json = json_encode( $payload );

		foreach ( [ 'secret-key', 'secret-auth', self::TOKEN ] as $secret ) {

			$this->assertStringNotContainsString( $secret, $json );
		}
	}

	public function testThePageIsGivenEachFieldsVersionAndTab(): void {

		$fields = array_column( rest::settingsPayload( new \owaWp_plugin() )['fields'], null, 'key' );

		$this->assertSame( '2', $fields['appPassword']['version'] );
		$this->assertSame( '1', $fields['trackClicks']['version'] );
		$this->assertNull( $fields['siteId']['version'], 'Both versions.' );
		$this->assertSame( 'tracking', $fields['excludeLoggedInUsers']['section'] );
		$this->assertSame( [ [ 'value' => '1', 'label' => '1.x' ], [ 'value' => '2', 'label' => '2.x (beta)' ] ], $fields['owaVersion']['options'] );
		$this->assertSame( "Don't Track Logged-in Users", $fields['excludeLoggedInUsers']['title'], 'Entities decoded for the page.' );
	}

	public function testTheSitesRouteListsWhatOwaReturnedAndWhy(): void {

		$plugin = $this->createPartialMock( \owaWp_plugin::class, [ 'getSitesFromOwa', 'getSitesNotice', 'sitesLoaded' ] );
		$plugin->method( 'getSitesFromOwa' )->willReturn( [ 'saved1' => [ 'label' => 'saved1 (saved; not in the list from OWA)', 'siteId' => 'saved1' ] ] );
		$plugin->method( 'getSitesNotice' )->willReturn( 'Could not load the list of websites from OWA: HTTP 403' );
		$plugin->method( 'sitesLoaded' )->willReturn( false );

		$this->assertSame( [
			'choices' => [ [ 'value' => 'saved1', 'label' => 'saved1 (saved; not in the list from OWA)' ] ],
			'notice'  => 'Could not load the list of websites from OWA: HTTP 403',
			'loaded'  => false,
		], rest::sitesPayload( $plugin ) );
	}

	// ---- who may call them --------------------------------------------------------

	private function routes(): array {

		$routes = [];

		Functions\when( 'register_rest_route' )->alias( function ( $namespace, $route, $handlers ) use ( &$routes ) {
			foreach ( $handlers as $handler ) {
				$routes[ $handler['methods'] . ' ' . $route ] = $handler['permission_callback'];
			}
		} );

		rest::registerRoutes( new \owaWp_plugin() );

		return $routes;
	}

	public function testSiteRoutesNeedManageOptions(): void {

		$routes = $this->routes();

		Functions\when( 'current_user_can' )->alias( fn( $capability ) => $capability === 'manage_options' );

		foreach ( [ 'GET /settings', 'POST /settings', 'GET /sites' ] as $route ) {

			$this->assertTrue( $routes[ $route ](), $route );
		}

		Functions\when( 'current_user_can' )->justReturn( false );

		foreach ( [ 'GET /settings', 'POST /settings', 'GET /sites' ] as $route ) {

			$this->assertFalse( $routes[ $route ](), $route );
		}
	}

	public function testThereIsNoSeparateRefreshRoute(): void {

		$this->assertArrayNotHasKey( 'POST /network/refresh', $this->routes(), 'Opening the page fetches the Profiles.' );
	}

	public function testNetworkRoutesNeedAMultisiteAndManageNetworkOptions(): void {

		$routes = $this->routes();
		$network = [ 'GET /network', 'POST /network' ];

		Functions\when( 'current_user_can' )->alias( fn( $capability ) => $capability === 'manage_options' );
		Functions\when( 'is_multisite' )->justReturn( true );

		foreach ( $network as $route ) {

			$this->assertFalse( $routes[ $route ](), "$route: a site administrator" );
		}

		Functions\when( 'current_user_can' )->justReturn( true );

		foreach ( $network as $route ) {

			$this->assertTrue( $routes[ $route ](), "$route: a network administrator" );
		}

		Functions\when( 'is_multisite' )->justReturn( false );

		$this->assertFalse( $routes['GET /network'](), 'Not a multisite.' );
	}
}
