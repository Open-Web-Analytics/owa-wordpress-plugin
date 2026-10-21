<?php

namespace owaWp;


/**
 * Multisite network mode (OWA 2.x only).
 *
 * The network administrator enters the OWA endpoint and one application
 * password; each site picks its own Website ID (Profile) with them, from the
 * Profiles that share its base domain. Or the network configures each site's
 * Profile, on the network settings page, where each site starts on the
 * Profile whose domain is exactly its own; the site only shows it.
 *
 * Everything is in one network option, including the Profiles last fetched
 * from OWA, so no page render makes a request to OWA.
 */
class network {

	const OPTION = 'owa_wp_network';

	const MENU_SLUG = 'owa-wordpress-network';

	// second-level labels under a two-letter country code that are registered
	// as one name: example.co.uk, example.com.au
	const COUNTRY_SECOND_LEVELS = [ 'ac', 'co', 'com', 'edu', 'gob', 'go', 'gov', 'ltd', 'me', 'mil', 'ne', 'net', 'nic', 'or', 'org', 'plc' ];

	/**
	 * @return array{owaEndpoint: string, appPassword: string, assignProfiles: bool, assignedSiteIds: array<int, string>, disabledSiteIds: int[], sites: array<string, array>, sitesFetchedAt: int, newProfileIds: string[]}
	 */
	public static function options() {

		$stored = function_exists( 'get_site_option' ) ? get_site_option( self::OPTION, [] ) : [];

		return self::normalize( is_array( $stored ) ? $stored : [] );
	}

	/**
	 * The network option with every key, typed.
	 *
	 * @param array<string, mixed> $stored
	 */
	public static function normalize( array $stored ) {

		return [
			'owaEndpoint'   => (string) ( $stored['owaEndpoint'] ?? '' ),
			'appPassword'   => (string) ( $stored['appPassword'] ?? '' ),
			'assignProfiles'  => ! empty( $stored['assignProfiles'] ),
			'assignedSiteIds' => array_map( 'strval', (array) ( $stored['assignedSiteIds'] ?? [] ) ),
			// sites the network has switched off (network-configured mode); any other is on
			'disabledSiteIds' => array_map( 'intval', (array) ( $stored['disabledSiteIds'] ?? [] ) ),
			'sites'         => (array) ( $stored['sites'] ?? [] ),
			'sitesFetchedAt' => (int) ( $stored['sitesFetchedAt'] ?? 0 ),
			// Profiles that appeared in OWA since the last save
			'newProfileIds' => array_map( 'strval', (array) ( $stored['newProfileIds'] ?? [] ) ),
		];
	}

	/**
	 * Network mode applies: a multisite with the endpoint and the application
	 * password set at network level.
	 */
	public static function isActive() {

		// a single site never reads the network option
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {

			return false;
		}

		return self::isActiveFor( self::options() );
	}

	/**
	 * Whether these network settings put the network in network mode.
	 *
	 * @param array<string, mixed> $options normalized
	 */
	public static function isActiveFor( array $options ) {

		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {

			return false;
		}

		return $options['owaEndpoint'] !== '' && $options['appPassword'] !== '';
	}

	/**
	 * What decides each site's tag under these network settings: the
	 * network's server, Profile and switch in network mode, the site's own
	 * settings otherwise (as owaWp_plugin::applyNetworkOptions() applies them).
	 *
	 * @param array<string, mixed> $options normalized
	 * @param array<int, string> $blog_hosts
	 * @return array<int, array<string, string>>
	 */
	public static function tagStates( array $options, array $blog_hosts ) {

		$states = [];

		foreach ( array_keys( $blog_hosts ) as $blog_id ) {

			$own = get_blog_option( $blog_id, 'owa_wp', [] );
			$state = is_array( $own ) ? $own : [];

			if ( self::isActiveFor( $options ) ) {

				$state['owaVersion'] = '2';
				$state['owaEndpoint'] = $options['owaEndpoint'];

				if ( $options['assignProfiles'] ) {

					$state['siteId'] = $options['assignedSiteIds'][ $blog_id ] ?? '';
					$state['enable'] = self::isSiteOn( $options['disabledSiteIds'], $blog_id ) ? 1 : 0;
				}
			}

			$states[ $blog_id ] = cache::tagState( $state );
		}

		return $states;
	}

	/**
	 * The host of a URL or bare domain, lower case: 'https://Example.com/x' and
	 * 'example.com' are both 'example.com'.
	 */
	public static function hostOf( $url ) {

		$url = trim( (string) $url );

		if ( $url === '' ) {

			return '';
		}

		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ) {

			$url = '//' . $url;
		}

		return rtrim( strtolower( (string) parse_url( $url, PHP_URL_HOST ) ), '.' );
	}

	/**
	 * The registrable domain of a host: the name and its public suffix.
	 *
	 * archive.example.com -> example.com; www.example.co.uk -> example.co.uk.
	 * An approximation without the Public Suffix List: a two-letter country
	 * code under one of COUNTRY_SECOND_LEVELS takes three labels, anything
	 * else two. An IP address or a single label is returned as it is.
	 */
	public static function baseDomain( $host ) {

		$host = self::hostOf( $host );

		if ( $host === '' || filter_var( $host, FILTER_VALIDATE_IP ) ) {

			return $host;
		}

		$labels = explode( '.', $host );
		$count = count( $labels );

		if ( $count <= 2 ) {

			return $host;
		}

		$tld = $labels[ $count - 1 ];
		$second = $labels[ $count - 2 ];
		$keep = ( strlen( $tld ) === 2 && in_array( $second, self::COUNTRY_SECOND_LEVELS, true ) ) ? 3 : 2;

		return implode( '.', array_slice( $labels, -$keep ) );
	}

	/**
	 * The rows of GET /base/v1/sites whose domain shares the site's base domain.
	 *
	 * @param array<mixed> $sites
	 * @return array<mixed>
	 */
	public static function filterByBaseDomain( array $sites, $site_host ) {

		$base = self::baseDomain( $site_host );

		return array_filter( $sites, function ( $site ) use ( $base ) {

			return $base !== '' && self::baseDomain( self::rowDomain( $site ) ) === $base;
		} );
	}

	/**
	 * Site IDs of the rows whose domain is exactly the host.
	 *
	 * @param array<mixed> $sites
	 * @return string[]
	 */
	public static function exactMatches( array $sites, $host ) {

		$host = self::hostOf( $host );
		$ids = [];

		foreach ( $sites as $site ) {

			if ( $host !== '' && self::hostOf( self::rowDomain( $site ) ) === $host ) {

				$ids[] = (string) self::rowSiteId( $site );
			}
		}

		return $ids;
	}

	public static function rowDomain( $site ) {

		return (string) ( $site['domain'] ?? $site['properties']['domain']['value'] ?? '' );
	}

	public static function rowSiteId( $site ) {

		return (string) ( $site['site_id'] ?? $site['properties']['site_id']['value'] ?? '' );
	}

	/**
	 * Every blog in the network, ID => host.
	 *
	 * @return array<int, string>
	 */
	public static function blogHosts() {

		$hosts = [];

		foreach ( get_sites( [ 'number' => 0, 'deleted' => 0, 'archived' => 0, 'spam' => 0 ] ) as $site ) {

			$hosts[ (int) $site->blog_id ] = self::hostOf( $site->domain );
		}

		return $hosts;
	}

	/**
	 * The current blog's host.
	 */
	public static function currentHost() {

		$site = get_site();

		return $site ? self::hostOf( $site->domain ) : self::hostOf( home_url() );
	}

	/**
	 * The fields of GET /base/v1/sites rows the network page needs, keyed by
	 * site ID: what is cached in the network option.
	 *
	 * @param array<mixed> $sites
	 * @return array<string, array{site_id: string, domain: string, property_name: string}>
	 */
	public static function cacheRows( array $sites ) {

		$rows = [];

		foreach ( $sites as $site ) {

			$id = self::rowSiteId( $site );

			if ( $id !== '' ) {

				$rows[ $id ] = [ 'site_id' => $id, 'domain' => self::rowDomain( $site ), 'property_name' => (string) ( $site['property_name'] ?? '' ) ];
			}
		}

		return $rows;
	}

	/**
	 * The Profile a site's row starts on: the network's stored choice for it
	 * ('' is "no Profile"), else the one Profile whose domain is exactly the
	 * site's.
	 *
	 * @param array<mixed> $sites
	 * @param array<int, string> $map
	 */
	public static function defaultSiteIdFor( array $sites, $host, array $map, $blog_id ) {

		if ( array_key_exists( (int) $blog_id, $map ) ) {

			return (string) $map[ (int) $blog_id ];
		}

		$ids = self::exactMatches( $sites, $host );

		return count( $ids ) === 1 ? $ids[0] : '';
	}

	/**
	 * The submitted Profile for each site, checked against the fetched list.
	 *
	 * @param array<mixed> $submitted blog ID => site ID ('' = no Profile)
	 * @param array<string, array> $sites cached rows
	 * @param array<int, string> $map stored mapping
	 * @param array<int, string> $blog_hosts
	 * @return array{0: array<int, string>, 1: string[]}
	 */
	public static function sanitizeMapping( array $submitted, array $sites, array $map, array $blog_hosts ) {

		$errors = [];

		foreach ( $submitted as $blog_id => $site_id ) {

			$blog_id = (int) $blog_id;
			$site_id = trim( (string) $site_id );

			if ( ! isset( $blog_hosts[ $blog_id ] ) ) {

				continue;
			}

			if ( $site_id === '' || isset( $sites[ $site_id ] ) ) {

				$map[ $blog_id ] = $site_id;

			} else {

				$errors[] = sprintf( '%s: %s is not in the list fetched from OWA, so its Observation Profile was not changed.', $blog_hosts[ $blog_id ], $site_id );
			}
		}

		return [ $map, $errors ];
	}

	/**
	 * What a fetch changed: Profiles new to the list and gone from it.
	 *
	 * @param array<string, array> $before
	 * @param array<string, array> $after
	 * @return array{new: string[], gone: string[]}
	 */
	public static function compareProfiles( array $before, array $after ) {

		return [
			'new'  => array_values( array_map( 'strval', array_diff( array_keys( $after ), array_keys( $before ) ) ) ),
			'gone' => array_values( array_map( 'strval', array_diff( array_keys( $before ), array_keys( $after ) ) ) ),
		];
	}

	/**
	 * Whether the network has a site switched on. A site not switched off,
	 * including one new to the network, is on.
	 *
	 * @param int[] $disabled
	 */
	public static function isSiteOn( array $disabled, $blog_id ) {

		return ! in_array( (int) $blog_id, $disabled, true );
	}

	/**
	 * The sites switched off after a save: each submitted switch for a site of
	 * the network, the stored state for the rest.
	 *
	 * @param array<mixed> $submitted blog ID => on (bool)
	 * @param int[] $disabled stored
	 * @param array<int, string> $blog_hosts
	 * @return int[]
	 */
	public static function sanitizeSwitches( array $submitted, array $disabled, array $blog_hosts ) {

		$disabled = array_map( 'intval', $disabled );

		foreach ( $submitted as $blog_id => $on ) {

			$blog_id = (int) $blog_id;

			if ( ! isset( $blog_hosts[ $blog_id ] ) ) {

				continue;
			}

			$disabled = array_values( array_diff( $disabled, [ $blog_id ] ) );

			if ( ! filter_var( $on, FILTER_VALIDATE_BOOLEAN ) ) {

				$disabled[] = $blog_id;
			}
		}

		sort( $disabled );

		return $disabled;
	}

	/**
	 * Each site without a stored choice, put on its exact match.
	 *
	 * @param array<string, array> $sites
	 * @param array<int, string> $map
	 * @param array<int, string> $blog_hosts
	 * @return array<int, string>
	 */
	public static function fillDefaults( array $sites, array $map, array $blog_hosts ) {

		foreach ( $blog_hosts as $blog_id => $host ) {

			if ( ! array_key_exists( (int) $blog_id, $map ) ) {

				$map[ (int) $blog_id ] = self::defaultSiteIdFor( $sites, $host, $map, $blog_id );
			}
		}

		return $map;
	}

	/**
	 * Validates submitted network settings against what is stored. The
	 * application password is kept when it is left out (null), cleared by ''.
	 *
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $stored
	 * @return array{0: array<string, mixed>, 1: array<string, string>} the options to store, and the problems by field
	 */
	public static function sanitize( array $input, array $stored ) {

		$errors = [];
		$out = $stored;

		if ( array_key_exists( 'owaEndpoint', $input ) ) {

			$problem = rest::endpointProblem( (string) $input['owaEndpoint'] );

			if ( $problem ) {

				$errors['owaEndpoint'] = $problem;

			} else {

				$out['owaEndpoint'] = trim( (string) $input['owaEndpoint'] );
			}
		}

		if ( array_key_exists( 'appPassword', $input ) && $input['appPassword'] !== null ) {

			$problem = rest::appPasswordProblem( (string) $input['appPassword'] );

			if ( $problem ) {

				$errors['appPassword'] = str_replace( 'OWA 2.x uses an application password', 'Network mode needs an OWA 2.x application password', $problem );

			} else {

				$out['appPassword'] = trim( (string) $input['appPassword'] );
			}
		}

		if ( array_key_exists( 'assignProfiles', $input ) ) {

			$out['assignProfiles'] = ! empty( $input['assignProfiles'] );
		}

		return [ $out, $errors ];
	}

	/**
	 * Fetches the Profiles from OWA into the network option. A Profile that
	 * was not in the cached list is marked new, and stays marked until the
	 * next save; one OWA no longer lists loses its mark. The first fetch marks
	 * nothing: everything would be new.
	 *
	 * @param array<string, mixed> $options the network option, updated in place
	 * @return array{info: string[], error: string}
	 */
	public static function fetchProfiles( $plugin, array &$options ) {

		$sites = $plugin->listSitesWithNetworkCredentials();

		if ( ! is_array( $sites ) ) {

			$fetched = (int) ( $options['sitesFetchedAt'] ?? 0 );

			return [
				'info'  => [],
				'error' => 'Could not load the list of Observation Profiles from OWA: ' . $plugin->getLastSitesError()
					. ( $fetched ? sprintf( '. Showing the Observation Profiles fetched %s UTC.', gmdate( 'Y-m-d H:i', $fetched ) ) : '.' ),
			];
		}

		$before = (array) ( $options['sites'] ?? [] );
		$options['sites'] = self::cacheRows( $sites );
		$options['sitesFetchedAt'] = time();

		$change = self::compareProfiles( $before, $options['sites'] );
		$marked = array_merge( (array) ( $options['newProfileIds'] ?? [] ), $before ? $change['new'] : [] );
		$options['newProfileIds'] = array_values( array_intersect( array_unique( array_map( 'strval', $marked ) ), array_map( 'strval', array_keys( $options['sites'] ) ) ) );

		$info = [];

		if ( $before && $change['new'] ) {

			$info[] = 'New Observation Profiles in OWA since the last visit: ' . implode( ', ', $change['new'] ) . '.';
		}

		if ( $change['gone'] ) {

			$info[] = 'Observation Profiles no longer in OWA: ' . implode( ', ', $change['gone'] ) . '.';
		}

		return [ 'info' => $info, 'error' => '' ];
	}

	/**
	 * Run when the network settings page is opened: the Profiles are fetched
	 * from OWA at the same time the network's sites are read, so the table
	 * never pairs today's sites with an old list of Profiles.
	 *
	 * @return array{info: string[], error: string}
	 */
	public static function load( $plugin ) {

		if ( ! self::isActive() ) {

			return [ 'info' => [], 'error' => '' ];
		}

		$options = get_site_option( self::OPTION, [] );
		$options = is_array( $options ) ? $options : [];

		$result = self::fetchProfiles( $plugin, $options );

		if ( $result['error'] === '' ) {

			update_site_option( self::OPTION, $options );
		}

		if ( ! empty( $options['assignProfiles'] ) ) {

			// listed, not saved: the table marks them until a save
			$new_blogs = array_diff_key( self::blogHosts(), (array) ( $options['assignedSiteIds'] ?? [] ) );

			if ( $new_blogs ) {

				$result['info'][] = 'New sites in the network, marked below: ' . implode( ', ', $new_blogs ) . '.';
			}
		}

		return $result;
	}

	/**
	 * Stores the network settings. Invalid credentials store nothing. Saving
	 * while the network configures the Profiles stores each site's from
	 * assignedSiteIds, and a site not yet assigned one gets its exact match;
	 * the first such save fetches the Profiles if none are cached yet.
	 *
	 * @param array<string, mixed> $input
	 * @return array{0: array<string, mixed>|null, 1: array<string, mixed>, 2: string[]} what was stored (null: nothing), problems, messages
	 */
	public static function save( array $input, $plugin ) {

		$stored = get_site_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];

		[ $options, $errors ] = self::sanitize( $input, $stored );

		if ( $errors ) {

			return [ null, $errors, [] ];
		}

		$info = [];
		$blog_hosts = is_multisite() ? self::blogHosts() : [];
		$before = self::tagStates( self::normalize( $stored ), $blog_hosts );

		// saving marks everything seen
		$options['newProfileIds'] = [];

		// stored first: the fetch below uses these credentials
		update_site_option( self::OPTION, $options );

		if ( self::isActive() && ! empty( $options['assignProfiles'] ) && empty( $options['sites'] ) ) {

			$fetch = self::fetchProfiles( $plugin, $options );

			if ( $fetch['error'] !== '' ) {

				$errors['fetch'] = $fetch['error'];
			}
		}

		if ( self::isActive() && ! empty( $options['assignProfiles'] ) ) {

			$sites = (array) ( $options['sites'] ?? [] );
			$map = array_map( 'strval', (array) ( $options['assignedSiteIds'] ?? [] ) );

			if ( isset( $input['assignedSiteIds'] ) && is_array( $input['assignedSiteIds'] ) ) {

				[ $map, $mapping_errors ] = self::sanitizeMapping( $input['assignedSiteIds'], $sites, $map, $blog_hosts );

				if ( $mapping_errors ) {

					$errors['assignedSiteIds'] = $mapping_errors;
				}
			}

			$options['assignedSiteIds'] = self::fillDefaults( $sites, $map, $blog_hosts );

			if ( isset( $input['enabledSites'] ) && is_array( $input['enabledSites'] ) ) {

				$options['disabledSiteIds'] = self::sanitizeSwitches( $input['enabledSites'], (array) ( $options['disabledSiteIds'] ?? [] ), $blog_hosts );
			}
		}

		update_site_option( self::OPTION, $options );

		// purge the pages of each site whose tag this changed
		foreach ( self::tagStates( self::normalize( $options ), $blog_hosts ) as $blog_id => $state ) {

			if ( $state !== ( $before[ $blog_id ] ?? null ) ) {

				cache::tagChanged( $blog_id );
			}
		}

		return [ $options, $errors, $info ];
	}
}

?>
