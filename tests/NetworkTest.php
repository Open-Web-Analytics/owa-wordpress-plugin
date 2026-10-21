<?php

namespace owaWp\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use owaWp\network;
use owaWp\settings\fields\select;
use owaWp\settings\page;

/**
 * Multisite network mode: one OWA 2.x server and application password for the
 * network, a Website ID per site.
 */
class NetworkTest extends TestCase {

	const TOKEN = 'AbCdEfGhIjKl0123456789abcdefghijABCDEFGHIJ01';

	private array $network = [];

	protected function setUp(): void {

		parent::setUp();

		$this->network = [];

		Functions\when( 'get_site_option' )->alias( fn( $name, $default = false ) => $name === network::OPTION ? $this->network : $default );
		Functions\when( 'update_site_option' )->alias( function ( $name, $value ) { $this->network = $value; return true; } );
	}

	private function networkMode( array $options = [] ): void {

		Functions\when( 'is_multisite' )->justReturn( true );

		$this->network = $options + [ 'owaEndpoint' => 'https://owa.example.com/', 'appPassword' => self::TOKEN ];
	}

	private function onBlog( int $blog_id, string $domain ): void {

		Functions\when( 'get_current_blog_id' )->justReturn( $blog_id );
		Functions\when( 'get_site' )->justReturn( (object) [ 'blog_id' => $blog_id, 'domain' => $domain ] );
	}

	/**
	 * A plugin after _init(), with the site's own stored options.
	 */
	private function initedPlugin( array $site_options = [] ): \owaWp_plugin {

		Functions\when( 'get_option' )->justReturn( $site_options + [ 'enable' => 1 ] );

		$plugin = new \owaWp_plugin();
		$plugin->_init();

		return $plugin;
	}

	private static function rows( string ...$domains ): array {

		$rows = [];

		foreach ( $domains as $i => $domain ) {

			$rows[ "OWA-$i" ] = [ 'site_id' => "OWA-$i", 'domain' => $domain, 'property_name' => "P$i" ];
		}

		return $rows;
	}

	// ---- domain matching ---------------------------------------------------

	public function testHostOfAcceptsUrlsAndBareDomains(): void {

		$this->assertSame( 'example.com', network::hostOf( 'https://Example.com/path' ) );
		$this->assertSame( 'example.com', network::hostOf( 'example.com' ) );
		$this->assertSame( 'archive.example.com', network::hostOf( 'archive.example.com.' ) );
		$this->assertSame( '', network::hostOf( '' ) );
	}

	public function testBaseDomainIsTheRegistrableDomain(): void {

		$this->assertSame( 'peteradamsphoto.com', network::baseDomain( 'archive.peteradamsphoto.com' ) );
		$this->assertSame( 'peteradamsphoto.com', network::baseDomain( 'www.peteradamsphoto.com' ) );
		$this->assertSame( 'photopress.io', network::baseDomain( 'photopress.io' ) );
		$this->assertSame( 'example.co.uk', network::baseDomain( 'www.example.co.uk' ) );
		$this->assertSame( 'example.com.au', network::baseDomain( 'shop.example.com.au' ) );
		$this->assertSame( 'localhost', network::baseDomain( 'localhost' ) );
		$this->assertSame( '127.0.0.1', network::baseDomain( '127.0.0.1' ) );
	}

	public function testTheListKeepsProfilesSharingTheBaseDomain(): void {

		$rows = self::rows( 'https://www.peteradamsphoto.com', 'peteradamsphoto.com', 'https://photopress.io', 'https://archive.peteradamsphoto.com' );

		$this->assertSame( [ 'OWA-0', 'OWA-1', 'OWA-3' ], array_keys( network::filterByBaseDomain( $rows, 'archive.peteradamsphoto.com' ) ) );
	}

	public function testAnExactMatchIsTheFullDomain(): void {

		$rows = self::rows( 'https://www.peteradamsphoto.com', 'https://peteradamsphoto.com', 'https://archive.peteradamsphoto.com' );

		$this->assertSame( [ 'OWA-2' ], network::exactMatches( $rows, 'archive.peteradamsphoto.com' ) );
		$this->assertSame( [ 'OWA-1' ], network::exactMatches( $rows, 'peteradamsphoto.com' ), 'www. is a different domain.' );
	}

	public function testEachSiteStartsOnItsExactMatch(): void {

		$rows = network::cacheRows( self::rows( 'https://photopress.io', 'https://www.oncefuture.com', 'https://www.oncefuture.com' ) );
		$hosts = [ 1 => 'photopress.io', 2 => 'www.oncefuture.com', 3 => 'www.peteradams.org', 4 => 'archive.peteradamsphoto.com' ];

		$this->assertSame(
			[ 4 => '', 1 => 'OWA-0', 2 => '', 3 => '' ],
			network::fillDefaults( $rows, [ 4 => '' ], $hosts ),
			'No match, or several: not tracked. A stored choice, even "not tracked", stays.'
		);
	}

	public function testAStoredChoiceWinsOverTheExactMatch(): void {

		$rows = network::cacheRows( self::rows( 'https://photopress.io', 'https://www.photopress.io' ) );

		$this->assertSame( 'OWA-1', network::defaultSiteIdFor( $rows, 'photopress.io', [ 1 => 'OWA-1' ], 1 ) );
		$this->assertSame( 'OWA-0', network::defaultSiteIdFor( $rows, 'photopress.io', [], 1 ) );
	}

	public function testASubmittedProfileMustBeInTheFetchedList(): void {

		$rows = network::cacheRows( self::rows( 'https://photopress.io' ) );

		[ $map, $errors ] = network::sanitizeMapping(
			[ 1 => 'OWA-0', 3 => 'OWA-gone', 9 => 'OWA-0' ],
			$rows,
			[ 3 => 'OWA-old' ],
			[ 1 => 'photopress.io', 3 => 'archive.peteradamsphoto.com' ]
		);

		$this->assertSame( [ 3 => 'OWA-old', 1 => 'OWA-0' ], $map, 'Blog 9 is not in the network.' );
		$this->assertSame( [ 'archive.peteradamsphoto.com: OWA-gone is not in the list fetched from OWA, so its Profile was not changed.' ], $errors );
	}

	public function testNetworkModeNeedsAMultisiteAndBothSettings(): void {

		$this->network = [ 'owaEndpoint' => 'https://owa.example.com/', 'appPassword' => self::TOKEN ];
		$this->assertFalse( network::isActive(), 'Not a multisite.' );

		Functions\when( 'is_multisite' )->justReturn( true );
		$this->assertTrue( network::isActive() );

		$this->network['appPassword'] = '';
		$this->assertFalse( network::isActive(), 'No application password.' );
	}

	public function testASiteUsesTheNetworksServerAndCredentials(): void {

		$this->networkMode();
		$this->onBlog( 3, 'archive.peteradamsphoto.com' );

		$plugin = $this->initedPlugin( [ 'owaVersion' => '1', 'owaEndpoint' => 'https://old.example.com/', 'apiKey' => 'k', 'authKey' => 'a', 'siteId' => 'OWA-own' ] );

		$this->assertSame( 2, $plugin->getOwaVersion() );
		$this->assertSame( 'https://owa.example.com/', $plugin->getOption( 'owaEndpoint' ) );
		$this->assertSame( self::TOKEN, $plugin->getOption( 'appPassword' ) );
		$this->assertSame( 'OWA-own', $plugin->getOption( 'siteId' ), 'Unlocked: the site chooses its own.' );
	}

	public function testAConfiguredSiteUsesItsAssignedProfile(): void {

		$this->networkMode( [ 'assignProfiles' => true, 'assignedSiteIds' => [ 3 => 'OWA-archive' ] ] );

		$this->onBlog( 3, 'archive.peteradamsphoto.com' );
		$this->assertSame( 'OWA-archive', $this->initedPlugin( [ 'siteId' => 'OWA-own' ] )->getOption( 'siteId' ) );

		$this->onBlog( 4, 'www.peteradams.org' );
		$unassigned = $this->initedPlugin( [ 'siteId' => 'OWA-own' ] );

		$this->assertSame( '', $unassigned->getOption( 'siteId' ) );

		ob_start();
		$unassigned->insertTrackingTag();
		$this->assertSame( '', ob_get_clean(), 'A site without a Profile is not tracked.' );
	}

	public function testWithoutNetworkModeTheSitesOwnSettingsApply(): void {

		$this->network = [ 'owaEndpoint' => 'https://owa.example.com/', 'appPassword' => self::TOKEN ];

		$plugin = $this->initedPlugin( [ 'owaVersion' => '1', 'owaEndpoint' => 'https://own.example.com/' ] );

		$this->assertSame( 'https://own.example.com/', $plugin->getOption( 'owaEndpoint' ) );
		$this->assertSame( 1, $plugin->getOwaVersion() );
	}

	public function testTheNetworksFieldsAreMarkedManaged(): void {

		$this->networkMode();

		$options = ( new \owaWp_plugin() )->registerOptions();

		foreach ( [ 'owaVersion', 'owaEndpoint', 'appPassword', 'apiKey', 'authKey' ] as $key ) {

			$this->assertTrue( $options[ $key ]['field']['managed'] ?? false, $key );
			$this->assertStringContainsString( 'owa-wp-network-managed', $options[ $key ]['field']['class'], $key );
		}

		$this->assertArrayNotHasKey( 'managed', $options['siteId']['field'], 'Unlocked: the site picks.' );

		$this->network['assignProfiles'] = true;
		$locked = ( new \owaWp_plugin() )->registerOptions();

		$this->assertTrue( $locked['siteId']['field']['managed'] );
		$this->assertTrue( $locked['siteId']['field']['readonly'] );
	}

	public function testSavingASiteKeepsItsStoredValuesForManagedFields(): void {

		Functions\when( 'add_settings_field' )->justReturn( null );
		Functions\when( 'get_option' )->justReturn( [ 'owaEndpoint' => 'https://own.example.com/' ] );
		Filters\expectApplied( 'owa_wp_settings_field_types' )->andReturn( [ 'url' => \owaWp\settings\fields\url::class ] );

		// the page holds the effective (network) value
		$page = new page( [ 'name' => 'owa-wordpress' ], [ 'owaEndpoint' => 'https://owa.example.com/' ] );
		$page->registerField( 'owaEndpoint', [ 'type' => 'url', 'title' => 'OWA Endpoint', 'section' => 'general', 'managed' => true ] );

		$this->assertSame(
			[ 'owaEndpoint' => 'https://own.example.com/' ],
			$page->validateAndSanitize( [ 'owaEndpoint' => 'https://owa.example.com/' ] ),
			'The network\'s endpoint is not written into the site\'s settings.'
		);
	}

	public function testAReadonlyPickerIsDisabled(): void {

		Filters\expectApplied( 'owa_wp_settings_field_siteId' )->andReturn( [ 'OWA-1' => [ 'label' => 'P – x (OWA-1)', 'siteId' => 'OWA-1' ] ] );

		$field = new select( [ 'id' => 'siteId', 'options' => [], 'readonly' => true ], [ 'siteId' => 'OWA-1' ] );

		ob_start();
		$field->render( $field->getProperties() + [ 'description' => '' ] );

		$this->assertStringContainsString( '<select id="owa_wp_siteId" name="owa_wp[siteId]" disabled>', ob_get_clean() );
	}

	private function pluginListing( array $rows ): \owaWp_plugin {

		$plugin = $this->initedPlugin();

		$client = new class( $rows ) {
			public function __construct( private array $rows ) {}
			public function listSites(): array { return $this->rows; }
			public function getLastErrors(): array { return []; }
		};

		$plugin->owaSdk = new class( $client ) {
			public function __construct( private object $client ) {}
			public function createSites(): object { return $this->client; }
		};

		return $plugin;
	}

	public function testThePickerListsTheProfilesSharingTheSitesBaseDomain(): void {

		$this->networkMode();
		$this->onBlog( 3, 'archive.peteradamsphoto.com' );

		$list = $this->pluginListing( self::rows( 'https://www.peteradamsphoto.com', 'https://photopress.io', 'https://archive.peteradamsphoto.com' ) )->getSitesFromOwa();

		$this->assertSame( [ 'OWA-0', 'OWA-2' ], array_keys( $list ) );
	}

	public function testAConfiguredSitesPageShowsItsProfileAndStoresNothing(): void {

		$this->networkMode( [ 'assignProfiles' => true, 'assignedSiteIds' => [ 3 => 'OWA-0', 1 => 'OWA-main' ] ] );
		$this->onBlog( 3, 'archive.peteradamsphoto.com' );

		$before = $this->network;

		$list = $this->pluginListing( self::rows( 'https://www.peteradamsphoto.com', 'https://archive.peteradamsphoto.com' ) )->getSitesFromOwa();

		$this->assertSame( [ 'OWA-0' ], array_keys( $list ), 'The assigned Profile, not the exact match.' );
		$this->assertSame( $before, $this->network, 'Opening a site\'s page changes nothing.' );
	}

	public function testAnUnassignedSiteSaysSo(): void {

		$this->networkMode( [ 'assignProfiles' => true ] );
		$this->onBlog( 4, 'www.peteradams.org' );

		$notice = null;
		Filters\expectAdded( 'owa_wp_settings_field_siteId_notice' )
			->once()
			->whenHappen( function ( $callback ) use ( &$notice ) { $notice = $callback; } );

		$this->assertSame( [], $this->pluginListing( self::rows( 'https://photopress.io' ) )->getSitesFromOwa() );
		$this->assertSame( 'The network administrator has not assigned this site an OWA Profile, so it is not tracked.', $notice() );
	}

	public function testTheNetworkFormIsValidatedAgainstWhatIsStored(): void {

		$stored = [ 'owaEndpoint' => 'https://owa.example.com/', 'appPassword' => self::TOKEN ];

		[ $out, $errors ] = network::sanitize( [ 'owaEndpoint' => 'https://owa.example.com', 'appPassword' => '0123456789abcdef0123456789abcdef', 'assignProfiles' => '1' ], $stored );

		$this->assertSame( 'https://owa.example.com/', $out['owaEndpoint'], 'Missing slash: kept.' );
		$this->assertSame( self::TOKEN, $out['appPassword'], 'A 1.x key: kept.' );
		$this->assertTrue( $out['assignProfiles'] );
		$this->assertCount( 2, $errors );
		$this->assertStringContainsString( 'looks like a 1.x API key', $errors[1] );

		[ $cleared ] = network::sanitize( [ 'owaEndpoint' => '', 'appPassword' => '' ], $stored );

		$this->assertSame( '', $cleared['owaEndpoint'], 'Emptying the fields turns network mode off.' );
		$this->assertFalse( $cleared['assignProfiles'] );
	}

	private function stubNetworkAdmin( bool $can = true ): void {

		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( $can );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'set_site_transient' )->justReturn( true );
		Functions\when( 'network_admin_url' )->alias( fn( $path = '' ) => 'https://photopress.io/wp-admin/network/' . $path );
		Functions\when( 'wp_die' )->alias( function ( $message ) { throw new \RuntimeException( (string) $message ); } );
		Functions\when( 'get_sites' )->justReturn( [
			(object) [ 'blog_id' => 1, 'domain' => 'photopress.io' ],
			(object) [ 'blog_id' => 3, 'domain' => 'archive.peteradamsphoto.com' ],
		] );
	}

	private function networkPlugin( array &$fetches = null ): object {

		return new class( $fetches ) {
			public function __construct( private ?array &$fetches ) {}
			public function listSitesWithNetworkCredentials(): array {
				$this->fetches[] = 1;
				return [
					[ 'site_id' => 'OWA-main', 'domain' => 'https://photopress.io', 'property_name' => 'PhotoPress' ],
					[ 'site_id' => 'OWA-arch', 'domain' => 'https://archive.peteradamsphoto.com', 'property_name' => 'Archive' ],
					[ 'site_id' => 'OWA-www', 'domain' => 'https://www.peteradamsphoto.com', 'property_name' => 'PAA' ],
				];
			}
			public function getLastSitesError(): string { return ''; }
		};
	}

	private function save( array $form, object $plugin, bool $refresh = false ): void {

		$_POST['owa_wp_network'] = $form;

		if ( $refresh ) {

			$_POST['owa_wp_network_refresh'] = 'Refresh site list';
		}

		try {

			network::handleSave( $plugin );

		} finally {

			unset( $_POST['owa_wp_network'], $_POST['owa_wp_network_refresh'] );
		}
	}

	public function testTurningOnNetworkConfigurationStartsEachSiteOnItsExactMatch(): void {

		$this->stubNetworkAdmin();
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\expect( 'wp_safe_redirect' )->once()->with( 'https://photopress.io/wp-admin/network/admin.php?page=owa-wordpress-network' );

		$fetches = [];
		$this->save( [ 'owaEndpoint' => 'https://owa.example.com/', 'appPassword' => self::TOKEN, 'assignProfiles' => '1' ], $this->networkPlugin( $fetches ) );

		$this->assertCount( 1, $fetches, 'No Profiles cached yet: fetched once.' );
		$this->assertSame( [ 'OWA-main', 'OWA-arch', 'OWA-www' ], array_keys( $this->network['sites'] ) );
		$this->assertSame( [ 1 => 'OWA-main', 3 => 'OWA-arch' ], $this->network['assignedSiteIds'] );
	}

	public function testTheMappingTableIsSaved(): void {

		$this->stubNetworkAdmin();
		$this->networkMode( [ 'assignProfiles' => true, 'sites' => network::cacheRows( $this->networkPlugin()->listSitesWithNetworkCredentials() ), 'assignedSiteIds' => [ 1 => 'OWA-main', 3 => 'OWA-arch' ] ] );
		Functions\when( 'wp_safe_redirect' )->justReturn( true );

		$fetches = [];
		$this->save( [
			'owaEndpoint' => 'https://owa.example.com/', 'appPassword' => self::TOKEN, 'assignProfiles' => '1',
			'assignedSiteIds' => [ 1 => '', 3 => 'OWA-www' ],
		], $this->networkPlugin( $fetches ) );

		$this->assertSame( [], $fetches, 'Profiles already cached: no request to OWA.' );
		$this->assertSame( [ 1 => '', 3 => 'OWA-www' ], $this->network['assignedSiteIds'], 'The admin\'s choices, "not tracked" included.' );
	}

	public function testRefreshFetchesTheProfilesAndKeepsTheMapping(): void {

		$this->stubNetworkAdmin();
		$this->networkMode( [ 'assignProfiles' => true, 'sites' => [ 'OWA-old' => [ 'site_id' => 'OWA-old', 'domain' => 'x', 'property_name' => '' ] ], 'assignedSiteIds' => [ 1 => '', 3 => 'OWA-www' ] ] );
		Functions\when( 'wp_safe_redirect' )->justReturn( true );

		$fetches = [];
		$this->save( [
			'owaEndpoint' => 'https://owa.example.com/', 'appPassword' => self::TOKEN, 'assignProfiles' => '1',
			'assignedSiteIds' => [ 1 => 'OWA-main', 3 => 'OWA-main' ],
		], $this->networkPlugin( $fetches ), true );

		$this->assertCount( 1, $fetches );
		$this->assertSame( [ 'OWA-main', 'OWA-arch', 'OWA-www' ], array_keys( $this->network['sites'] ) );
		$this->assertSame( [ 1 => '', 3 => 'OWA-www' ], $this->network['assignedSiteIds'], 'Refresh only fetches; the table is saved with Save.' );
	}

	public function testOnlyANetworkAdministratorCanSave(): void {

		$this->stubNetworkAdmin( false );
		Functions\expect( 'update_site_option' )->never();

		$this->expectException( \RuntimeException::class );

		network::handleSave( new class {} );
	}

	public function testRefreshSaysWhatIsNewAndWhatIsGone(): void {

		$this->stubNetworkAdmin();
		$this->networkMode( [
			'assignProfiles' => true,
			'sites' => [ 'OWA-old' => [ 'site_id' => 'OWA-old', 'domain' => 'https://old.example.com', 'property_name' => '' ], 'OWA-main' => [ 'site_id' => 'OWA-main', 'domain' => 'https://photopress.io', 'property_name' => '' ] ],
			'assignedSiteIds' => [ 1 => 'OWA-main' ],
		] );
		Functions\when( 'wp_safe_redirect' )->justReturn( true );

		$notice = null;
		Functions\when( 'set_site_transient' )->alias( function ( $name, $value ) use ( &$notice ) { $notice = $value; return true; } );

		$this->save( [ 'owaEndpoint' => 'https://owa.example.com/', 'appPassword' => self::TOKEN, 'assignProfiles' => '1' ], $this->networkPlugin(), true );

		$this->assertSame( [ 'OWA-arch', 'OWA-www' ], $this->network['newProfileIds'] );
		$this->assertSame( [
			'Fetched 3 Profiles from OWA. New: OWA-arch, OWA-www. No longer in OWA: OWA-old.',
			'New sites in the network, marked below: archive.peteradamsphoto.com.',
		], $notice['info'] );
		$this->assertFalse( $notice['saved'], 'A refresh saves no mapping.' );
		$this->assertSame( [ 1 => 'OWA-main' ], $this->network['assignedSiteIds'], 'The new site is listed, not saved.' );
	}

	public function testSavingClearsTheNewMarks(): void {

		$this->stubNetworkAdmin();
		$this->networkMode( [ 'assignProfiles' => true, 'sites' => network::cacheRows( $this->networkPlugin()->listSitesWithNetworkCredentials() ), 'newProfileIds' => [ 'OWA-www' ] ] );
		Functions\when( 'wp_safe_redirect' )->justReturn( true );

		$this->save( [ 'owaEndpoint' => 'https://owa.example.com/', 'appPassword' => self::TOKEN, 'assignProfiles' => '1', 'assignedSiteIds' => [ 1 => 'OWA-main', 3 => 'OWA-www' ] ], $this->networkPlugin() );

		$this->assertSame( [], $this->network['newProfileIds'] );
		$this->assertSame( [ 1 => 'OWA-main', 3 => 'OWA-www' ], $this->network['assignedSiteIds'] );
	}

	public function testTheTableMarksNewSitesNewProfilesAndGoneProfiles(): void {

		$this->stubNetworkAdmin();
		$this->networkMode( [
			'assignProfiles' => true,
			'sites' => network::cacheRows( $this->networkPlugin()->listSitesWithNetworkCredentials() ),
			'newProfileIds' => [ 'OWA-www' ],
			'assignedSiteIds' => [ 1 => 'OWA-gone' ],
		] );

		$out = $this->renderNetworkPage();

		// blog 1: saved, on a Profile OWA no longer lists
		$this->assertStringContainsString( '<select name="owa_wp_network[assignedSiteIds][1]" id="owa_wp_network_site_1" data-saved="OWA-gone">', $out );
		$this->assertStringContainsString( '<option value="OWA-gone" selected>OWA-gone (not in the list from OWA)</option>', $out );
		$this->assertStringContainsString( 'data-status="The saved Profile is no longer in the list from OWA"', $out );

		// blog 3: new to the network, not saved
		$this->assertStringContainsString( '<select name="owa_wp_network[assignedSiteIds][3]" id="owa_wp_network_site_3">', $out );
		$this->assertStringContainsString( 'data-status="New site: starts on its exact match, saved when you save"', $out );

		$this->assertStringContainsString( 'PAA – https://www.peteradamsphoto.com (OWA-www) — new</option>', $out );
		$this->assertStringContainsString( 'Changed: saved when you save', $out, 'The script that marks unsaved edits.' );
	}

	private function renderNetworkPage(): string {

		Functions\when( 'get_site_transient' )->justReturn( false );
		Functions\when( 'wp_nonce_field' )->justReturn( null );
		Functions\when( 'checked' )->alias( fn( $a, $b = true, $echo = true ) => $a === $b ? " checked='checked'" : '' );
		Functions\when( 'selected' )->alias( fn( $a, $b = true, $echo = true ) => (string) $a === (string) $b ? " selected='selected'" : '' );
		Functions\when( 'submit_button' )->alias( function ( $text, $type = 'primary', $name = 'submit' ) { echo "[button $name: $text]"; } );

		ob_start();
		network::renderPage( null );

		return ob_get_clean();
	}

	public function testTheMappingTableStartsOnTheExactMatch(): void {

		$this->stubNetworkAdmin();
		$this->networkMode( [
			'assignProfiles' => true,
			'sites' => network::cacheRows( $this->networkPlugin()->listSitesWithNetworkCredentials() ),
			'sitesFetchedAt' => 1792000000,
			'assignedSiteIds' => [],
		] );
		Functions\when( 'get_site_transient' )->justReturn( false );
		Functions\when( 'wp_nonce_field' )->justReturn( null );
		Functions\when( 'checked' )->alias( fn( $a, $b = true, $echo = true ) => $a === $b ? " checked='checked'" : '' );
		Functions\when( 'selected' )->alias( fn( $a, $b = true, $echo = true ) => (string) $a === (string) $b ? " selected='selected'" : '' );
		Functions\when( 'submit_button' )->alias( function ( $text, $type = 'primary', $name = 'submit' ) { echo "[button $name: $text]"; } );

		ob_start();
		network::renderPage( null );
		$out = ob_get_clean();

		$this->assertStringContainsString( 'action="https://photopress.io/wp-admin/network/edit.php?action=owa_wp_network"', $out );
		$this->assertStringContainsString( '[button owa_wp_network_refresh: Refresh site list]', $out );
		$this->assertStringContainsString( 'Profiles last fetched from OWA: 2026-10-14', $out );

		$this->assertMatchesRegularExpression( '#<select name="owa_wp_network\[assignedSiteIds\]\[1\]"[^>]*>.*<option value="OWA-main" selected=\'selected\'>PhotoPress – https://photopress.io \(OWA-main\)</option>#U', $out );
		$this->assertMatchesRegularExpression( '#<select name="owa_wp_network\[assignedSiteIds\]\[3\]"[^>]*><option value="">— not tracked —</option><option value="OWA-arch" selected=\'selected\'>#', $out,
			'Exact match preselected; Profiles sharing the base domain listed first.' );
	}

}
