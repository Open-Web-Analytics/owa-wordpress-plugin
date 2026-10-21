<?php

namespace owaWp;

use owaWp\settings\fields\app_password;

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

	const NONCE = 'owa_wp_network';

	const MENU_SLUG = 'owa-wordpress-network';

	const EDIT_ACTION = 'owa_wp_network';

	// second-level labels under a two-letter country code that are registered
	// as one name: example.co.uk, example.com.au
	const COUNTRY_SECOND_LEVELS = [ 'ac', 'co', 'com', 'edu', 'gob', 'go', 'gov', 'ltd', 'me', 'mil', 'ne', 'net', 'nic', 'or', 'org', 'plc' ];

	/**
	 * @return array{owaEndpoint: string, appPassword: string, assignProfiles: bool, assignedSiteIds: array<int, string>, sites: array<string, array>, sitesFetchedAt: int, newProfileIds: string[]}
	 */
	public static function options() {

		$stored = function_exists( 'get_site_option' ) ? get_site_option( self::OPTION, [] ) : [];
		$stored = is_array( $stored ) ? $stored : [];

		return [
			'owaEndpoint'   => (string) ( $stored['owaEndpoint'] ?? '' ),
			'appPassword'   => (string) ( $stored['appPassword'] ?? '' ),
			'assignProfiles'  => ! empty( $stored['assignProfiles'] ),
			'assignedSiteIds' => array_map( 'strval', (array) ( $stored['assignedSiteIds'] ?? [] ) ),
			'sites'         => (array) ( $stored['sites'] ?? [] ),
			'sitesFetchedAt' => (int) ( $stored['sitesFetchedAt'] ?? 0 ),
			// Profiles that arrived with the last refresh, until the next save
			'newProfileIds' => array_map( 'strval', (array) ( $stored['newProfileIds'] ?? [] ) ),
		];
	}

	/**
	 * Network mode applies: a multisite with the endpoint and the application
	 * password set at network level.
	 */
	public static function isActive() {

		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {

			return false;
		}

		$options = self::options();

		return $options['owaEndpoint'] !== '' && $options['appPassword'] !== '';
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
	 * ('' is "not tracked"), else the one Profile whose domain is exactly the
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
	 * @param array<mixed> $submitted blog ID => site ID ('' = not tracked)
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

				$errors[] = sprintf( '%s: %s is not in the list fetched from OWA, so its Profile was not changed.', $blog_hosts[ $blog_id ], $site_id );
			}
		}

		return [ $map, $errors ];
	}

	/**
	 * What a refresh changed: Profiles new to the list and gone from it.
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
	 * A site row's state: saved, new to the network (not saved yet), or saved
	 * on a Profile OWA no longer lists.
	 *
	 * @param array<string, array> $sites
	 * @param array<int, string> $map
	 */
	public static function rowStatus( array $sites, array $map, $blog_id, $selected ) {

		if ( ! array_key_exists( (int) $blog_id, $map ) ) {

			return $selected !== ''
				? 'New site: starts on its exact match, saved when you save'
				: 'New site: no exact match, not tracked until you choose a Profile';
		}

		$saved = (string) $map[ (int) $blog_id ];

		if ( $saved !== '' && $sites && ! isset( $sites[ $saved ] ) ) {

			return 'The saved Profile is no longer in the list from OWA';
		}

		return 'Saved';
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
	 * Validates a submitted network form against what is stored.
	 *
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $stored
	 * @return array{0: array<string, mixed>, 1: string[]} the options to store, and the problems found
	 */
	public static function sanitize( array $input, array $stored ) {

		$errors = [];
		$out = $stored;

		$endpoint = trim( (string) ( $input['owaEndpoint'] ?? '' ) );

		if ( $endpoint === '' ) {

			$out['owaEndpoint'] = '';

		} elseif ( ! preg_match( '#^https?://#i', $endpoint ) || ! filter_var( $endpoint, FILTER_VALIDATE_URL ) ) {

			$errors[] = 'OWA Endpoint: not a valid http:// or https:// URL. The stored value is kept.';

		} elseif ( substr( $endpoint, -1 ) !== '/' ) {

			$errors[] = 'OWA Endpoint: the URL must end with a slash (e.g. https://owa.example.com/). The stored value is kept.';

		} else {

			$out['owaEndpoint'] = $endpoint;
		}

		$password = trim( (string) ( $input['appPassword'] ?? '' ) );

		if ( $password === '' || preg_match( app_password::PATTERN, $password ) ) {

			$out['appPassword'] = $password;

		} elseif ( preg_match( app_password::LEGACY_KEY_PATTERN, $password ) ) {

			$errors[] = 'Application Password: this looks like a 1.x API key. Network mode needs an OWA 2.x application password (My Preferences > Application Passwords). The stored value is kept.';

		} else {

			$errors[] = 'Application Password: an application password is 44 letters and digits. The stored value is kept.';
		}

		$out['assignProfiles'] = ! empty( $input['assignProfiles'] );

		return [ $out, $errors ];
	}

	/**
	 * Network Admin > OWA.
	 */
	public static function addMenuPage( $plugin ) {

		add_menu_page(
			'OWA Network Settings',
			'OWA',
			'manage_network_options',
			self::MENU_SLUG,
			function () use ( $plugin ) { self::renderPage( $plugin ); },
			'dashicons-chart-pie'
		);
	}

	/**
	 * The network settings form, and which Profile each site uses.
	 */
	public static function renderPage( $plugin ) {

		if ( ! current_user_can( 'manage_network_options' ) ) {

			wp_die( esc_html__( 'You do not have sufficient permissions to access this page!' ) );
		}

		$options = self::options();
		$notice = get_site_transient( 'owa_wp_network_notice' );

		if ( $notice ) {

			delete_site_transient( 'owa_wp_network_notice' );
		}

		echo '<div class="wrap"><h1>OWA Network Settings</h1>';

		echo '<p>' . esc_html( 'Network mode connects every site in this network to one OWA 2.x server with one application password. Leave the endpoint or the password empty to let each site be set up on its own.' ) . '</p>';

		foreach ( (array) ( $notice['info'] ?? [] ) as $info ) {

			printf( '<div class="notice notice-info"><p>%s</p></div>', esc_html( $info ) );
		}

		foreach ( (array) ( $notice['errors'] ?? [] ) as $error ) {

			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $error ) );
		}

		if ( ! empty( $notice['saved'] ) ) {

			echo '<div class="notice notice-success"><p>' . esc_html( 'Settings saved.' ) . '</p></div>';
		}

		printf( '<form method="post" action="%s">', esc_url( network_admin_url( 'edit.php?action=' . self::EDIT_ACTION ) ) );
		wp_nonce_field( self::NONCE );

		echo '<table class="form-table" role="presentation">';

		printf(
			'<tr><th scope="row"><label for="owa_wp_network_owaEndpoint">OWA Endpoint</label></th><td><input name="owa_wp_network[owaEndpoint]" id="owa_wp_network_owaEndpoint" type="url" class="regular-text" value="%s"><p class="description">%s</p></td></tr>',
			esc_attr( $options['owaEndpoint'] ),
			esc_html( 'The URL of your OWA 2.x server, ending with a / (e.g. https://owa.example.com/).' )
		);

		printf(
			'<tr><th scope="row"><label for="owa_wp_network_appPassword">Application Password</label></th><td><input name="owa_wp_network[appPassword]" id="owa_wp_network_appPassword" type="password" autocomplete="off" class="regular-text" value="%s"><p class="description">%s</p></td></tr>',
			esc_attr( $options['appPassword'] ),
			esc_html( 'An application password from OWA (My Preferences > Application Passwords). Site admins can list the Profiles its OWA user can see, so use a read-only password of a user limited to this network\'s Properties.' )
		);

		printf(
			'<tr><th scope="row">Site Profiles</th><td><label for="owa_wp_network_assignProfiles"><input name="owa_wp_network[assignProfiles]" id="owa_wp_network_assignProfiles" type="checkbox" value="1" %s> %s</label><p class="description">%s</p></td></tr>',
			checked( $options['assignProfiles'], true, false ),
			esc_html( 'Network configures each site\'s Profile (site admins cannot change it)' ),
			esc_html( 'Each site starts on the Profile whose domain is exactly the site\'s domain. Change any site below and save.' )
		);

		echo '</table>';

		if ( self::isActive() ) {

			self::renderSiteTable( $options );
		}

		echo '<p class="submit">';
		submit_button( 'Save Changes', 'primary', 'submit', false );

		if ( self::isActive() ) {

			echo ' ';
			submit_button( 'Refresh site list', 'secondary', 'owa_wp_network_refresh', false );
		}

		echo '</p></form></div>';
	}

	/**
	 * Which Profile each site uses: a select per site while Profiles are
	 * assigned here, the site's own choice otherwise.
	 */
	private static function renderSiteTable( array $options ) {

		echo '<h2>' . esc_html( 'Sites' ) . '</h2>';

		if ( $options['sitesFetchedAt'] ) {

			printf( '<p>%s</p>', esc_html( sprintf( 'Profiles last fetched from OWA: %s UTC (%d).', gmdate( 'Y-m-d H:i', $options['sitesFetchedAt'] ), count( $options['sites'] ) ) ) );

		} elseif ( $options['assignProfiles'] ) {

			echo '<p>' . esc_html( 'Refresh the site list to choose each site\'s Profile.' ) . '</p>';
		}

		$columns = $options['assignProfiles'] ? '<th>Site</th><th>Website ID</th><th>Status</th>' : '<th>Site</th><th>Website ID</th>';

		printf( '<table class="widefat striped owa-wp-network-sites"><thead><tr>%s</tr></thead><tbody>', $columns );

		foreach ( self::blogHosts() as $blog_id => $host ) {

			if ( $options['assignProfiles'] ) {

				$map = $options['assignedSiteIds'];
				$selected = self::defaultSiteIdFor( $options['sites'], $host, $map, $blog_id );
				$saved = array_key_exists( $blog_id, $map ) ? (string) $map[ $blog_id ] : null;

				printf(
					'<tr><td><label for="owa_wp_network_site_%1$d">%2$s</label></td><td>%3$s</td><td class="owa-wp-status" data-status="%4$s">%4$s</td></tr>',
					$blog_id,
					esc_html( $host ),
					self::siteSelect( $blog_id, $selected, $options['sites'], $host, $saved, $options['newProfileIds'] ),
					esc_html( self::rowStatus( $options['sites'], $map, $blog_id, $selected ) )
				);

				continue;
			}

			$site_options = get_blog_option( $blog_id, 'owa_wp', [] );
			$id = is_array( $site_options ) ? (string) ( $site_options['siteId'] ?? '' ) : '';

			printf( '<tr><td>%s</td><td>%s</td></tr>', esc_html( $host ), esc_html( $id !== '' ? $id : 'not chosen yet' ) );
		}

		echo '</tbody></table>';

		if ( $options['assignProfiles'] ) {

			// marks a row whose choice differs from what is saved
			echo "<script>
document.querySelectorAll( '.owa-wp-network-sites select' ).forEach( function ( select ) {
	select.addEventListener( 'change', function () {
		var cell = select.closest( 'tr' ).querySelector( '.owa-wp-status' );
		var saved = select.getAttribute( 'data-saved' );
		cell.textContent = ( saved !== null && select.value === saved ) ? cell.getAttribute( 'data-status' ) : 'Changed: saved when you save';
	} );
} );
</script>";
		}
	}

	/**
	 * One site's Profile select: not tracked, then the fetched Profiles, those
	 * sharing the site's base domain first.
	 *
	 * @param array<string, array> $sites
	 * @param string|null $saved the stored choice; null for a site not saved yet
	 * @param string[] $new_ids Profiles that arrived with the last refresh
	 */
	private static function siteSelect( $blog_id, $selected, array $sites, $host, $saved = null, array $new_ids = [] ) {

		$base = self::baseDomain( $host );

		uasort( $sites, function ( $a, $b ) use ( $base ) {

			$a_near = self::baseDomain( $a['domain'] ) === $base;
			$b_near = self::baseDomain( $b['domain'] ) === $base;

			return $a_near === $b_near ? strcmp( $a['domain'], $b['domain'] ) : ( $a_near ? -1 : 1 );
		} );

		// no data-saved on a site not saved yet: any change to it is unsaved
		$html = sprintf( '<select name="owa_wp_network[assignedSiteIds][%1$d]" id="owa_wp_network_site_%1$d"%2$s>', $blog_id, $saved === null ? '' : sprintf( ' data-saved="%s"', esc_attr( $saved ) ) );
		$html .= sprintf( '<option value=""%s>%s</option>', selected( $selected, '', false ), esc_html( '— not tracked —' ) );

		foreach ( $sites as $id => $row ) {

			$label = $row['property_name'] !== ''
				? sprintf( '%s – %s (%s)', $row['property_name'], $row['domain'], $id )
				: sprintf( '%s (%s)', $id, $row['domain'] );

			if ( in_array( (string) $id, $new_ids, true ) ) {

				$label .= ' — new';
			}

			$html .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $id ), selected( $selected, (string) $id, false ), esc_html( $label ) );
		}

		if ( $selected !== '' && ! isset( $sites[ $selected ] ) ) {

			$html .= sprintf( '<option value="%1$s" selected>%2$s</option>', esc_attr( $selected ), esc_html( $selected . ' (not in the list from OWA)' ) );
		}

		return $html . '</select>';
	}

	/**
	 * Saves the network form (network_admin_edit_{action}). "Refresh site list"
	 * fetches the Profiles from OWA; saving while the network configures the
	 * Profiles stores each site's, and a site not yet assigned one gets its
	 * exact match.
	 */
	public static function handleSave( $plugin ) {

		check_admin_referer( self::NONCE );

		if ( ! current_user_can( 'manage_network_options' ) ) {

			wp_die( esc_html__( 'You do not have sufficient permissions to access this page!' ) );
		}

		$input = isset( $_POST['owa_wp_network'] ) && is_array( $_POST['owa_wp_network'] ) ? wp_unslash( $_POST['owa_wp_network'] ) : [];
		$refresh = ! empty( $_POST['owa_wp_network_refresh'] );

		$stored = get_site_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];

		[ $options, $errors ] = self::sanitize( $input, $stored );
		$info = [];

		if ( ! $refresh ) {

			// saving marks everything seen
			$options['newProfileIds'] = [];
		}

		// stored first: the fetch below uses these credentials
		update_site_option( self::OPTION, $options );

		if ( self::isActive() && ( $refresh || ( ! empty( $options['assignProfiles'] ) && empty( $options['sites'] ) ) ) ) {

			$sites = $plugin->listSitesWithNetworkCredentials();

			if ( is_array( $sites ) ) {

				$before = (array) ( $options['sites'] ?? [] );
				$options['sites'] = self::cacheRows( $sites );
				$options['sitesFetchedAt'] = time();

				$change = self::compareProfiles( $before, $options['sites'] );
				$options['newProfileIds'] = $before ? $change['new'] : [];

				$info[] = sprintf( 'Fetched %d Profiles from OWA.', count( $options['sites'] ) )
					. ( $before && $change['new'] ? ' New: ' . implode( ', ', $change['new'] ) . '.' : '' )
					. ( $change['gone'] ? ' No longer in OWA: ' . implode( ', ', $change['gone'] ) . '.' : '' );

			} else {

				$errors[] = 'Could not load the list of websites from OWA: ' . $plugin->getLastSitesError();
			}
		}

		if ( self::isActive() && ! empty( $options['assignProfiles'] ) ) {

			$blog_hosts = self::blogHosts();
			$sites = (array) ( $options['sites'] ?? [] );
			$map = array_map( 'strval', (array) ( $options['assignedSiteIds'] ?? [] ) );

			if ( ! $refresh && isset( $input['assignedSiteIds'] ) && is_array( $input['assignedSiteIds'] ) ) {

				[ $map, $mapping_errors ] = self::sanitizeMapping( $input['assignedSiteIds'], $sites, $map, $blog_hosts );
				$errors = array_merge( $errors, $mapping_errors );
			}

			$new_blogs = array_diff_key( $blog_hosts, $map );

			if ( $refresh ) {

				// listed, not saved: the table shows them as new until Save
				if ( $new_blogs ) {

					$info[] = 'New sites in the network, marked below: ' . implode( ', ', $new_blogs ) . '.';
				}

			} else {

				$options['assignedSiteIds'] = self::fillDefaults( $sites, $map, $blog_hosts );
			}
		}

		update_site_option( self::OPTION, $options );

		set_site_transient( 'owa_wp_network_notice', [ 'saved' => ! $refresh, 'info' => $info, 'errors' => $errors ], 60 );

		wp_safe_redirect( network_admin_url( 'admin.php?page=' . self::MENU_SLUG ) );

		if ( ! defined( 'OWA_WP_TESTING' ) ) {

			exit;
		}
	}
}

?>
