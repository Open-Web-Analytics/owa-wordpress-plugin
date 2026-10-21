<?php

namespace owaWp;

/**
 * The admin pages: OWA > Settings and Analytics on each site, OWA on the
 * network admin. The settings pages are React apps (js/, built into build/)
 * that read and save through the owa-wp/v1 REST routes (rest.php).
 */
class admin {

	const SLUG = 'owa-wordpress';

	// built entry => the admin page hook it loads on
	const ENTRIES = [
		'settings' => 'toplevel_page_owa-wordpress',
		'network'  => 'toplevel_page_' . network::MENU_SLUG,
	];

	public static function register( $plugin ) {

		add_action( 'admin_menu', fn() => self::addMenus( $plugin ) );

		if ( is_multisite() ) {

			add_action( 'network_admin_menu', fn() => self::addNetworkMenu() );
		}

		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	public static function addMenus( $plugin ) {

		add_menu_page( 'OWA Settings', 'OWA', 'manage_options', self::SLUG, [ self::class, 'renderSettings' ], 'dashicons-chart-pie', 2 );
		add_submenu_page( self::SLUG, 'OWA Settings', 'Settings', 'manage_options', self::SLUG, [ self::class, 'renderSettings' ] );
		add_submenu_page( self::SLUG, 'OWA Analytics', 'Analytics', 'manage_options', 'owa-analytics', [ $plugin, 'pageController' ] );
	}

	public static function addNetworkMenu() {

		add_menu_page( 'OWA Network Settings', 'OWA', 'manage_network_options', network::MENU_SLUG, [ self::class, 'renderNetwork' ], 'dashicons-chart-pie' );
	}

	public static function renderSettings() {

		self::renderRoot( 'OWA Settings', 'owa-wp-settings', 'manage_options' );
	}

	public static function renderNetwork() {

		self::renderRoot( 'OWA Network Settings', 'owa-wp-network', 'manage_network_options' );
	}

	private static function renderRoot( $title, $id, $capability ) {

		if ( ! current_user_can( $capability ) ) {

			wp_die( esc_html__( 'You do not have sufficient permissions to access this page!' ) );
		}

		printf(
			'<div class="wrap"><h1>%s</h1><div id="%s"><p>%s</p></div></div>',
			esc_html( $title ),
			esc_attr( $id ),
			esc_html( self::isBuilt( $id === 'owa-wp-network' ? 'network' : 'settings' )
				? 'Loading…'
				: 'This page has not been built: run npm ci && npm run build in the plugin\'s directory.' )
		);
	}

	public static function isBuilt( $entry ) {

		return is_readable( self::buildPath( $entry . '.asset.php' ) );
	}

	private static function buildPath( $file ) {

		return OWA_WP_PLUGIN_PATH . 'build/' . $file;
	}

	/**
	 * Loads a page's built script, with the dependencies and version
	 * wp-scripts recorded in its .asset.php.
	 */
	public static function enqueue( $hook ) {

		$entry = array_search( $hook, self::ENTRIES, true );

		if ( $entry === false || ! self::isBuilt( $entry ) ) {

			return;
		}

		$asset = include self::buildPath( $entry . '.asset.php' );
		$url = plugins_url( 'build/', OWA_WP_PLUGIN_PATH . 'owa-plugin.php' );

		wp_enqueue_script( 'owa-wp-' . $entry, $url . $entry . '.js', $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( 'wp-components' );

		// both pages import the same stylesheet, which webpack emits once
		if ( is_readable( self::buildPath( 'style-settings.css' ) ) ) {

			wp_enqueue_style( 'owa-wp-admin', $url . 'style-settings.css', [ 'wp-components' ], $asset['version'] );
		}
	}
}

?>
