<?php

namespace owaWp;

/**
 * The REST routes the settings pages (js/) use, under owa-wp/v1.
 *
 * Secrets (the API key, the Secret Auth Key, the application password) are
 * write-only: a response says whether each is set, never what it is. In a
 * save, a secret that is left out is kept, and '' clears it.
 */
class rest {

	const NAMESPACE = 'owa-wp/v1';

	// field types whose values never leave the server
	const SECRET_TYPES = [ 'secret', 'app_password' ];

	// an OWA 2.x application password: 44 letters and digits
	const APP_PASSWORD_PATTERN = '/^[0-9A-Za-z]{44}$/D';

	// a 1.x API key, which an application password never looks like
	const LEGACY_KEY_PATTERN = '/^[a-f0-9]{32}$/D';

	public static function register( $plugin ) {

		add_action( 'rest_api_init', fn() => self::registerRoutes( $plugin ) );
	}

	public static function registerRoutes( $plugin ) {

		$site = fn() => current_user_can( 'manage_options' );
		$network = fn() => is_multisite() && current_user_can( 'manage_network_options' );

		register_rest_route( self::NAMESPACE, '/settings', [
			[ 'methods' => 'GET', 'callback' => fn() => self::settingsPayload( $plugin ), 'permission_callback' => $site ],
			[ 'methods' => 'POST', 'callback' => fn( $request ) => self::saveSettings( $plugin, (array) $request->get_json_params() ), 'permission_callback' => $site ],
		] );

		register_rest_route( self::NAMESPACE, '/sites', [
			[ 'methods' => 'GET', 'callback' => fn() => self::sitesPayload( $plugin ), 'permission_callback' => $site ],
		] );

		register_rest_route( self::NAMESPACE, '/network', [
			[ 'methods' => 'GET', 'callback' => fn() => self::loadNetwork( $plugin ), 'permission_callback' => $network ],
			[ 'methods' => 'POST', 'callback' => fn( $request ) => self::saveNetwork( $plugin, (array) $request->get_json_params() ), 'permission_callback' => $network ],
		] );
	}

	/**
	 * What the site settings page renders: the fields (from
	 * registerOptions()), their values, which secrets are set, and the
	 * network-mode note.
	 */
	public static function settingsPayload( $plugin ) {

		$fields = [];
		$values = [];
		$secrets = [];

		foreach ( $plugin->registerOptions() as $key => $option ) {

			$field = $option['field'];
			$type = $field['type'];

			$fields[] = [
				'key'         => $key,
				'type'        => $type,
				'title'       => html_entity_decode( (string) $field['title'], ENT_QUOTES, 'UTF-8' ),
				'description' => html_entity_decode( (string) ( $field['description'] ?? '' ), ENT_QUOTES, 'UTF-8' ),
				'section'     => $field['section'] ?? 'general',
				'version'     => $field['owa_version'] ?? null,
				'options'     => self::choices( $field['options'] ?? [] ),
				'managed'     => ! empty( $field['managed'] ),
				'readonly'    => ! empty( $field['readonly'] ),
			];

			if ( in_array( $type, self::SECRET_TYPES, true ) ) {

				$secrets[ $key ] = (string) $plugin->getOption( $key ) !== '';
				continue;
			}

			$value = $plugin->getOption( $key );
			$values[ $key ] = $type === 'boolean' ? (bool) $value : (string) $value;
		}

		$sections = [];

		foreach ( $plugin->registerSections() as $name => $section ) {

			$sections[] = [ 'name' => $name ] + $section;
		}

		return [
			'fields'         => $fields,
			'values'         => $values,
			'secrets'        => $secrets,
			'sections'       => $sections,
			'network'        => [ 'active' => network::isActive(), 'note' => $plugin->getNetworkNote() ],
			'tagSettingsUrl' => $plugin->getOwaVersion() === 2 && $plugin->getOption( 'owaEndpoint' ) && $plugin->getOption( 'siteId' ) ? $plugin->getTagSettingsUrl() : '',
		];
	}

	/**
	 * @param array<string, string> $options value => label
	 * @return array<int, array{value: string, label: string}>
	 */
	private static function choices( array $options ) {

		$out = [];

		foreach ( $options as $value => $label ) {

			$out[] = [ 'value' => (string) $value, 'label' => (string) $label ];
		}

		return $out;
	}

	/**
	 * Validates and stores the site settings. All or nothing: with any
	 * invalid field, nothing is stored and each problem is returned under its
	 * field. Fields the network manages are ignored.
	 *
	 * @param array<string, mixed> $input
	 */
	public static function saveSettings( $plugin, array $input ) {

		$stored = get_option( 'owa_wp' );
		$stored = is_array( $stored ) ? $stored : [];

		[ $out, $errors ] = self::sanitizeSettings( $plugin->registerOptions(), $input, $stored );

		if ( $errors ) {

			return new \WP_Error( 'owa_wp_invalid', 'The settings were not saved.', [ 'status' => 400, 'errors' => $errors ] );
		}

		update_option( 'owa_wp', $out );

		$plugin->reloadOptions();

		return self::settingsPayload( $plugin ) + [ 'saved' => true ];
	}

	/**
	 * @param array<string, array> $schema registerOptions()
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $stored
	 * @return array{0: array<string, mixed>, 1: array<string, string>}
	 */
	public static function sanitizeSettings( array $schema, array $input, array $stored ) {

		$out = $stored;
		$errors = [];

		foreach ( $schema as $key => $option ) {

			$field = $option['field'];

			if ( ! empty( $field['managed'] ) || ! array_key_exists( $key, $input ) ) {

				continue;
			}

			$value = $input[ $key ];

			switch ( $field['type'] ) {

				case 'boolean':

					$out[ $key ] = filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? 1 : 0;
					break;

				case 'radio':

					if ( array_key_exists( (string) $value, (array) ( $field['options'] ?? [] ) ) ) {

						$out[ $key ] = (string) $value;

					} else {

						$errors[ $key ] = 'Choose one of the listed options.';
					}
					break;

				case 'url':

					$error = self::endpointProblem( (string) $value );

					if ( $error ) {

						$errors[ $key ] = $error;

					} else {

						$out[ $key ] = trim( (string) $value );
					}
					break;

				case 'app_password':

					if ( $value === null ) {

						break;
					}

					$error = self::appPasswordProblem( (string) $value );

					if ( $error ) {

						$errors[ $key ] = $error;

					} else {

						$out[ $key ] = trim( (string) $value );
					}
					break;

				case 'secret':

					if ( $value !== null ) {

						$out[ $key ] = trim( (string) $value );
					}
					break;

				default:

					$out[ $key ] = trim( (string) $value );
			}
		}

		// 2.x loads <siteId>.js, so the ID has to be a file name OWA can publish
		if ( (string) ( $out['owaVersion'] ?? '1' ) === '2'
			&& (string) ( $out['siteId'] ?? '' ) !== ''
			&& ! preg_match( \owaWp_plugin::BUNDLE_SITE_ID_PATTERN, (string) $out['siteId'] ) ) {

			$errors['siteId'] = 'OWA 2.x cannot publish a tracking bundle for this Website ID.';
		}

		return [ $out, $errors ];
	}

	/**
	 * Why an OWA endpoint cannot be used, or '' ('' itself is allowed).
	 */
	public static function endpointProblem( $endpoint ) {

		$endpoint = trim( (string) $endpoint );

		if ( $endpoint === '' ) {

			return '';
		}

		if ( ! preg_match( '#^https?://#i', $endpoint ) || ! filter_var( $endpoint, FILTER_VALIDATE_URL ) ) {

			return 'Not a valid http:// or https:// URL.';
		}

		if ( substr( $endpoint, -1 ) !== '/' ) {

			return 'The URL must end with a slash (e.g. https://owa.example.com/).';
		}

		return '';
	}

	/**
	 * Why an application password cannot be used, or '' ('' clears it).
	 */
	public static function appPasswordProblem( $password ) {

		$password = trim( (string) $password );

		if ( $password === '' || preg_match( self::APP_PASSWORD_PATTERN, $password ) ) {

			return '';
		}

		return preg_match( self::LEGACY_KEY_PATTERN, $password )
			? 'This looks like a 1.x API key. OWA 2.x uses an application password: create one in OWA under My Preferences > Application Passwords.'
			: 'An application password is 44 letters and digits.';
	}

	/**
	 * The Website ID choices, fetched from OWA with the saved settings.
	 */
	public static function sitesPayload( $plugin ) {

		$choices = [];

		foreach ( $plugin->getSitesFromOwa() as $id => $site ) {

			$choices[] = [ 'value' => (string) $id, 'label' => $site['label'] ];
		}

		return [ 'choices' => $choices, 'notice' => $plugin->getSitesNotice() ];
	}

	/**
	 * "Property – domain (site ID)", or "site ID (domain)" when the Property
	 * is unnamed or named after the domain, as the site's picker shows them.
	 *
	 * @param array{domain: string, property_name: string} $row
	 */
	public static function profileLabel( $id, array $row ) {

		$property = (string) ( $row['property_name'] ?? '' );

		if ( $property === '' || network::hostOf( $property ) === network::hostOf( $row['domain'] ) ) {

			return sprintf( '%s (%s)', $id, $row['domain'] );
		}

		return sprintf( '%s – %s (%s)', $property, $row['domain'], $id );
	}

	/**
	 * What the network settings page renders.
	 */
	public static function networkPayload() {

		$options = network::options();
		$profiles = [];

		foreach ( $options['sites'] as $id => $row ) {

			$profiles[] = [
				'id'         => (string) $id,
				'label'      => self::profileLabel( (string) $id, $row ),
				'domain'     => $row['domain'],
				'baseDomain' => network::baseDomain( $row['domain'] ),
				'new'        => in_array( (string) $id, $options['newProfileIds'], true ),
			];
		}

		$blogs = [];

		if ( network::isActive() ) {

			$map = $options['assignedSiteIds'];

			foreach ( network::blogHosts() as $blog_id => $host ) {

				$blog = [ 'id' => $blog_id, 'host' => $host, 'baseDomain' => network::baseDomain( $host ) ];

				if ( $options['assignProfiles'] ) {

					$selected = network::defaultSiteIdFor( $options['sites'], $host, $map, $blog_id );

					$blog += [
						'saved'    => array_key_exists( $blog_id, $map ) ? (string) $map[ $blog_id ] : null,
						'selected' => $selected,
						'status'   => network::rowStatus( $options['sites'], $map, $blog_id, $selected ),
						'enabled'  => network::isSiteOn( $options['disabledSiteIds'], $blog_id ),
					];

				} else {

					$site_options = get_blog_option( $blog_id, 'owa_wp', [] );
					$blog['own'] = is_array( $site_options ) ? (string) ( $site_options['siteId'] ?? '' ) : '';
				}

				$blogs[] = $blog;
			}
		}

		return [
			'active'         => network::isActive(),
			'endpoint'       => $options['owaEndpoint'],
			'appPasswordSet' => $options['appPassword'] !== '',
			'assignProfiles' => $options['assignProfiles'],
			'profiles'       => $profiles,
			'sitesFetchedAt' => $options['sitesFetchedAt'],
			'blogs'          => $blogs,
		];
	}

	/**
	 * Opening the network page: the Profiles are fetched from OWA as the
	 * network's sites are read (network::load()).
	 */
	public static function loadNetwork( $plugin ) {

		$result = network::load( $plugin );

		return self::networkPayload() + [
			'saved'  => false,
			'errors' => $result['error'] !== '' ? [ 'fetch' => $result['error'] ] : [],
			'info'   => $result['info'],
		];
	}

	/**
	 * Saves the network settings. Invalid credentials store nothing (400,
	 * errors by field); otherwise the response carries what was stored and
	 * what to tell the administrator.
	 *
	 * @param array<string, mixed> $input
	 */
	public static function saveNetwork( $plugin, array $input ) {

		[ $saved, $errors, $info ] = network::save( $input, $plugin );

		if ( $saved === null ) {

			return new \WP_Error( 'owa_wp_invalid', 'The network settings were not saved.', [ 'status' => 400, 'errors' => $errors ] );
		}

		return self::networkPayload() + [ 'saved' => true, 'errors' => $errors, 'info' => $info ];
	}
}

?>
