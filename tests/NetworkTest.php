<?php

namespace owaWp\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use owaWp\network;
use owaWp\rest;

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

		$this->networkMode();

		$schema = ( new \owaWp_plugin() )->registerOptions();
		$stored = [ 'owaEndpoint' => 'https://own.example.com/', 'owaVersion' => '1' ];

		[ $out, $errors ] = rest::sanitizeSettings( $schema, [ 'owaEndpoint' => 'https://owa.example.com/', 'owaVersion' => '2', 'trackNamedUsers' => false ], $stored );

		$this->assertSame( [], $errors );
		$this->assertSame( 'https://own.example.com/', $out['owaEndpoint'], 'The network\'s endpoint is not written into the site\'s settings.' );
		$this->assertSame( '1', $out['owaVersion'] );
		$this->assertSame( 0, $out['trackNamedUsers'], 'The site\'s own fields are saved.' );
	}

	public function testAConfiguredSitesPageShowsItsWebsiteIdReadOnly(): void {

		$this->networkMode( [ 'assignProfiles' => true, 'assignedSiteIds' => [ 3 => 'OWA-archive' ] ] );
		$this->onBlog( 3, 'archive.peteradamsphoto.com' );

		$payload = rest::settingsPayload( $this->initedPlugin() );
		$fields = array_column( $payload['fields'], null, 'key' );

		$this->assertTrue( $fields['siteId']['readonly'] );
		$this->assertTrue( $fields['owaEndpoint']['managed'] );
		$this->assertFalse( $fields['owaEndpoint']['readonly'], 'Managed and not read-only: hidden.' );
		$this->assertSame( 'OWA-archive', $payload['values']['siteId'] );
		$this->assertStringContainsString( 'configures this site\'s Profile', $payload['network']['note'] );
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

		$plugin = $this->pluginListing( self::rows( 'https://photopress.io' ) );

		$this->assertSame( [], $plugin->getSitesFromOwa() );
		$this->assertSame( 'The network administrator has not assigned this site an OWA Profile, so it is not tracked.', $plugin->getSitesNotice() );
	}

	// ---- the network settings ------------------------------------------------

	public function testInvalidNetworkSettingsStoreNothing(): void {

		$this->networkMode();
		$before = $this->network;

		[ $saved, $errors ] = network::save( [ 'owaEndpoint' => 'https://owa.example.com', 'appPassword' => '0123456789abcdef0123456789abcdef' ], $this->networkPlugin() );

		$this->assertNull( $saved );
		$this->assertSame( $before, $this->network );
		$this->assertSame( 'The URL must end with a slash (e.g. https://owa.example.com/).', $errors['owaEndpoint'] );
		$this->assertStringStartsWith( 'This looks like a 1.x API key. Network mode needs an OWA 2.x application password', $errors['appPassword'] );
	}

	public function testTheApplicationPasswordIsKeptUnlessReplacedOrCleared(): void {

		$this->stubBlogs();
		$this->networkMode();

		network::save( [ 'owaEndpoint' => 'https://owa.example.com/' ], $this->networkPlugin() );
		$this->assertSame( self::TOKEN, $this->network['appPassword'], 'Left out: kept.' );

		network::save( [ 'appPassword' => null ], $this->networkPlugin() );
		$this->assertSame( self::TOKEN, $this->network['appPassword'], 'null: kept.' );

		network::save( [ 'appPassword' => '' ], $this->networkPlugin() );
		$this->assertSame( '', $this->network['appPassword'], '\'\': cleared, which turns network mode off.' );
		$this->assertFalse( network::isActive() );
	}

	private function stubBlogs(): void {

		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_sites' )->justReturn( [
			(object) [ 'blog_id' => 1, 'domain' => 'photopress.io' ],
			(object) [ 'blog_id' => 3, 'domain' => 'archive.peteradamsphoto.com' ],
		] );
	}

	private function networkPlugin( ?array &$fetches = null ): object {

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

	private function credentials( array $more = [] ): array {

		return [ 'owaEndpoint' => 'https://owa.example.com/', 'appPassword' => self::TOKEN, 'assignProfiles' => true ] + $more;
	}

	public function testTurningOnNetworkConfigurationStartsEachSiteOnItsExactMatch(): void {

		$this->stubBlogs();

		$fetches = [];
		[ $saved, $errors, $info ] = network::save( $this->credentials(), $this->networkPlugin( $fetches ) );

		$this->assertSame( [], $errors );
		$this->assertCount( 1, $fetches, 'No Profiles cached yet: fetched once.' );
		$this->assertSame( [ 'OWA-main', 'OWA-arch', 'OWA-www' ], array_keys( $this->network['sites'] ) );
		$this->assertSame( [ 1 => 'OWA-main', 3 => 'OWA-arch' ], $this->network['assignedSiteIds'] );
		$this->assertSame( $this->network, $saved );
	}

	public function testTheMappingTableIsSaved(): void {

		$this->stubBlogs();
		$this->networkMode( [ 'assignProfiles' => true, 'sites' => network::cacheRows( $this->networkPlugin()->listSitesWithNetworkCredentials() ), 'assignedSiteIds' => [ 1 => 'OWA-main', 3 => 'OWA-arch' ] ] );

		$fetches = [];
		network::save( $this->credentials( [ 'assignedSiteIds' => [ 1 => '', 3 => 'OWA-www' ] ] ), $this->networkPlugin( $fetches ) );

		$this->assertSame( [], $fetches, 'Profiles already cached: no request to OWA.' );
		$this->assertSame( [ 1 => '', 3 => 'OWA-www' ], $this->network['assignedSiteIds'], 'The admin\'s choices, "not tracked" included.' );
	}

	public function testAProfileNotInTheListIsRefusedAndTheRestSaved(): void {

		$this->stubBlogs();
		$this->networkMode( [ 'assignProfiles' => true, 'sites' => network::cacheRows( $this->networkPlugin()->listSitesWithNetworkCredentials() ), 'assignedSiteIds' => [ 1 => 'OWA-main', 3 => 'OWA-arch' ] ] );

		[ , $errors ] = network::save( $this->credentials( [ 'assignedSiteIds' => [ 1 => 'OWA-gone', 3 => 'OWA-www' ] ] ), $this->networkPlugin() );

		$this->assertSame( [ 1 => 'OWA-main', 3 => 'OWA-www' ], $this->network['assignedSiteIds'] );
		$this->assertSame( [ 'photopress.io: OWA-gone is not in the list fetched from OWA, so its Profile was not changed.' ], $errors['assignedSiteIds'] );
	}

	public function testOpeningThePageFetchesTheProfilesAndKeepsTheMapping(): void {

		$this->stubBlogs();
		$this->networkMode( [ 'assignProfiles' => true, 'sites' => [ 'OWA-old' => [ 'site_id' => 'OWA-old', 'domain' => 'x', 'property_name' => '' ] ], 'assignedSiteIds' => [ 1 => '', 3 => 'OWA-www' ] ] );

		$fetches = [];
		$result = network::load( $this->networkPlugin( $fetches ) );

		$this->assertCount( 1, $fetches );
		$this->assertSame( '', $result['error'] );
		$this->assertSame( [ 'OWA-main', 'OWA-arch', 'OWA-www' ], array_keys( $this->network['sites'] ) );
		$this->assertSame( [ 1 => '', 3 => 'OWA-www' ], $this->network['assignedSiteIds'], 'Loading the page saves no mapping.' );
	}

	public function testOpeningThePageSaysWhatIsNewAndWhatIsGone(): void {

		$this->stubBlogs();
		$this->networkMode( [
			'assignProfiles' => true,
			'sites' => [ 'OWA-old' => [ 'site_id' => 'OWA-old', 'domain' => 'https://old.example.com', 'property_name' => '' ], 'OWA-main' => [ 'site_id' => 'OWA-main', 'domain' => 'https://photopress.io', 'property_name' => '' ] ],
			'assignedSiteIds' => [ 1 => 'OWA-main' ],
		] );

		$result = network::load( $this->networkPlugin() );

		$this->assertSame( [ 'OWA-arch', 'OWA-www' ], $this->network['newProfileIds'] );
		$this->assertSame( [
			'New in OWA since the last visit: OWA-arch, OWA-www.',
			'No longer in OWA: OWA-old.',
			'New sites in the network, marked below: archive.peteradamsphoto.com.',
		], $result['info'] );
	}

	public function testNewMarksLastUntilTheNextSave(): void {

		$this->stubBlogs();
		$this->networkMode( [ 'assignProfiles' => true, 'sites' => [ 'OWA-main' => [ 'site_id' => 'OWA-main', 'domain' => 'https://photopress.io', 'property_name' => '' ] ], 'assignedSiteIds' => [ 1 => 'OWA-main', 3 => '' ] ] );

		network::load( $this->networkPlugin() );
		$second = network::load( $this->networkPlugin() );

		$this->assertSame( [ 'OWA-arch', 'OWA-www' ], $this->network['newProfileIds'], 'A reload keeps them marked.' );
		$this->assertSame( [], $second['info'], 'Nothing changed since the first load.' );

		network::save( $this->credentials( [ 'assignedSiteIds' => [ 1 => 'OWA-main', 3 => 'OWA-arch' ] ] ), $this->networkPlugin() );

		$this->assertSame( [], $this->network['newProfileIds'] );
	}

	public function testWhenOwaCannotBeReachedTheCachedProfilesAreShown(): void {

		$this->stubBlogs();
		$this->networkMode( [ 'sites' => [ 'OWA-main' => [ 'site_id' => 'OWA-main', 'domain' => 'https://photopress.io', 'property_name' => '' ] ], 'sitesFetchedAt' => 1792000000 ] );
		$before = $this->network;

		$down = new class {
			public function listSitesWithNetworkCredentials(): ?array { return null; }
			public function getLastSitesError(): string { return 'Could not resolve host'; }
		};

		$result = network::load( $down );

		$this->assertSame( 'Could not load the list of websites from OWA: Could not resolve host. Showing the Profiles fetched 2026-10-14 17:46 UTC.', $result['error'] );
		$this->assertSame( $before, $this->network, 'The cached Profiles stay.' );
	}

	public function testNothingIsFetchedOutsideNetworkMode(): void {

		$fetches = [];
		$this->assertSame( [ 'info' => [], 'error' => '' ], network::load( $this->networkPlugin( $fetches ) ) );
		$this->assertSame( [], $fetches );
	}

	public function testSavingClearsTheNewMarks(): void {

		$this->stubBlogs();
		$this->networkMode( [ 'assignProfiles' => true, 'sites' => network::cacheRows( $this->networkPlugin()->listSitesWithNetworkCredentials() ), 'newProfileIds' => [ 'OWA-www' ] ] );

		network::save( $this->credentials( [ 'assignedSiteIds' => [ 1 => 'OWA-main', 3 => 'OWA-www' ] ] ), $this->networkPlugin() );

		$this->assertSame( [], $this->network['newProfileIds'] );
		$this->assertSame( [ 1 => 'OWA-main', 3 => 'OWA-www' ], $this->network['assignedSiteIds'] );
	}

	// ---- the per-site switches (network-configured mode) ------------------------

	public function testASiteTheNetworkSwitchedOffIsNotTracked(): void {

		$this->networkMode( [ 'assignProfiles' => true, 'assignedSiteIds' => [ 3 => 'OWA-archive' ], 'disabledSiteIds' => [ 3 ] ] );
		$this->onBlog( 3, 'archive.peteradamsphoto.com' );

		Functions\when( 'get_option' )->justReturn( [ 'enable' => 1 ] );
		\Brain\Monkey\Actions\expectAdded( 'wp_head' )->never();

		$plugin = new \owaWp_plugin();
		$plugin->_init();

		$this->assertSame( 0, $plugin->getOption( 'enable' ), 'The site\'s own Enable OWA does not count.' );
		$this->assertStringContainsString( 'has tracking on this site turned off', $plugin->getNetworkNote() );
	}

	public function testASiteTheNetworkSwitchedOnIsTrackedWhateverItsOwnToggle(): void {

		$this->networkMode( [ 'assignProfiles' => true, 'assignedSiteIds' => [ 3 => 'OWA-archive' ], 'disabledSiteIds' => [ 4 ] ] );
		$this->onBlog( 3, 'archive.peteradamsphoto.com' );

		$this->assertSame( 1, $this->initedPlugin( [ 'enable' => 0 ] )->getOption( 'enable' ) );
	}

	public function testSwitchesApplyOnlyWhenTheNetworkConfiguresProfiles(): void {

		$this->networkMode( [ 'disabledSiteIds' => [ 3 ] ] );
		$this->onBlog( 3, 'archive.peteradamsphoto.com' );

		$this->assertSame( 1, $this->initedPlugin( [ 'enable' => 1 ] )->getOption( 'enable' ), 'Site-choice mode: the site\'s own toggle.' );

		$options = ( new \owaWp_plugin() )->registerOptions();
		$this->assertArrayNotHasKey( 'managed', $options['enable']['field'] );

		$this->network['assignProfiles'] = true;
		$this->assertTrue( ( new \owaWp_plugin() )->registerOptions()['enable']['field']['managed'], 'Network-configured: the network\'s switch.' );
	}

	public function testTheSwitchesAreSavedForTheNetworksSitesOnly(): void {

		$this->stubBlogs();
		$this->networkMode( [ 'assignProfiles' => true, 'sites' => network::cacheRows( $this->networkPlugin()->listSitesWithNetworkCredentials() ), 'disabledSiteIds' => [ 1 ] ] );

		network::save( $this->credentials( [ 'enabledSites' => [ 1 => true, 3 => false, 99 => false ] ] ), $this->networkPlugin() );
		$this->assertSame( [ 3 ], $this->network['disabledSiteIds'] );

		network::save( $this->credentials(), $this->networkPlugin() );
		$this->assertSame( [ 3 ], $this->network['disabledSiteIds'], 'Left out: kept.' );
	}

	public function testANewSiteStartsSwitchedOn(): void {

		$this->stubBlogs();
		$this->networkMode( [ 'assignProfiles' => true, 'disabledSiteIds' => [ 1 ] ] );

		$blogs = array_column( rest::networkPayload()['blogs'], null, 'host' );

		$this->assertFalse( $blogs['photopress.io']['enabled'] );
		$this->assertTrue( $blogs['archive.peteradamsphoto.com']['enabled'] );
	}

	// ---- what the network page is given ----------------------------------------

	public function testTheMappingStartsOnTheExactMatchAndMarksWhatIsNew(): void {

		$this->stubBlogs();
		$this->networkMode( [
			'assignProfiles' => true,
			'sites' => network::cacheRows( $this->networkPlugin()->listSitesWithNetworkCredentials() ),
			'sitesFetchedAt' => 1792000000,
			'newProfileIds' => [ 'OWA-www' ],
			'assignedSiteIds' => [ 1 => 'OWA-gone' ],
		] );

		$payload = rest::networkPayload();
		$blogs = array_column( $payload['blogs'], null, 'host' );
		$profiles = array_column( $payload['profiles'], null, 'id' );

		$this->assertTrue( $payload['appPasswordSet'] );
		$this->assertStringNotContainsString( self::TOKEN, json_encode( $payload ), 'The password never leaves the server.' );

		$this->assertSame( 'OWA-gone', $blogs['photopress.io']['saved'] );
		$this->assertSame( 'The saved Profile is no longer in the list from OWA', $blogs['photopress.io']['status'] );

		$this->assertNull( $blogs['archive.peteradamsphoto.com']['saved'], 'New to the network: not saved.' );
		$this->assertSame( 'OWA-arch', $blogs['archive.peteradamsphoto.com']['selected'], 'Starts on its exact match.' );
		$this->assertSame( 'New site: starts on its exact match, saved when you save', $blogs['archive.peteradamsphoto.com']['status'] );
		$this->assertSame( 'peteradamsphoto.com', $blogs['archive.peteradamsphoto.com']['baseDomain'] );

		$this->assertTrue( $profiles['OWA-www']['new'] );
		$this->assertFalse( $profiles['OWA-main']['new'] );
		$this->assertSame( 'PAA – https://www.peteradamsphoto.com (OWA-www)', $profiles['OWA-www']['label'] );
	}

	public function testWithoutConfigurationTheTableShowsEachSitesOwnChoice(): void {

		$this->stubBlogs();
		$this->networkMode();
		Functions\when( 'get_blog_option' )->alias( fn( $blog_id ) => $blog_id === 3 ? [ 'siteId' => 'OWA-own' ] : false );

		$blogs = array_column( rest::networkPayload()['blogs'], null, 'host' );

		$this->assertSame( 'OWA-own', $blogs['archive.peteradamsphoto.com']['own'] );
		$this->assertSame( '', $blogs['photopress.io']['own'] );
		$this->assertArrayNotHasKey( 'selected', $blogs['photopress.io'] );
	}
}
