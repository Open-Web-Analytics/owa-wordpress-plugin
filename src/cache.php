<?php

namespace owaWp;

/**
 * Page caches and the tracking tag.
 *
 * A cached page keeps the tag it was rendered with, so when a site's tag
 * changes (tracking turned on or off, another Website ID or server) its
 * pages have to be purged. The plugin fires the action TAG_CHANGED in that
 * site's context; Proxy Cache Purge (varnish-http-purge) is told to purge the
 * whole site on it, and any other page cache can listen for it too.
 */
class cache {

	const TAG_CHANGED = 'owa_wp_tag_changed';

	// the settings that decide a site's tag
	const TAG_SETTINGS = [ 'enable', 'owaVersion', 'owaEndpoint', 'siteId' ];

	/**
	 * Adds TAG_CHANGED to Proxy Cache Purge's events, as a full purge. Called
	 * when the plugin loads: Proxy Cache Purge reads these filters on init.
	 */
	public static function register() {

		// both, as its source asks: the first registers the action, the second makes it a full purge
		add_filter( 'varnish_http_purge_events', [ self::class, 'addPurgeEvent' ] );
		add_filter( 'varnish_http_purge_events_full', [ self::class, 'addPurgeEvent' ] );
	}

	/**
	 * @param array<int, string> $events
	 * @return array<int, string>
	 */
	public static function addPurgeEvent( $events ) {

		$events = (array) $events;
		$events[] = self::TAG_CHANGED;

		return array_values( array_unique( $events ) );
	}

	/**
	 * Fires TAG_CHANGED for a site, in that site's context so a purge is of
	 * its own pages.
	 */
	public static function tagChanged( $blog_id = 0 ) {

		if ( ! is_multisite() ) {

			do_action( self::TAG_CHANGED, 1 );

			return;
		}

		$blog_id = (int) ( $blog_id ?: get_current_blog_id() );
		$switch = $blog_id !== get_current_blog_id();

		if ( $switch ) {

			switch_to_blog( $blog_id );
		}

		do_action( self::TAG_CHANGED, $blog_id );

		if ( $switch ) {

			restore_current_blog();
		}
	}

	/**
	 * The part of a set of settings that decides the tag, to compare before
	 * and after a save.
	 *
	 * @param array<string, mixed> $options
	 */
	public static function tagState( array $options ) {

		$state = [];

		foreach ( self::TAG_SETTINGS as $key ) {

			$state[ $key ] = (string) ( $options[ $key ] ?? '' );
		}

		return $state;
	}
}

?>
