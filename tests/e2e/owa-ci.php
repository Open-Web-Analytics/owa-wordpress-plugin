<?php
/*
 * Must-use plugin for CI's throwaway WordPress only.
 *
 * Its OWA is served by php -S over plain http, where the SDK otherwise refuses
 * to send an application password.
 */
add_filter( 'owa_wp_sdk_config', function ( $config ) {
	return $config + [ 'allow_insecure_app_password' => true ];
} );

/*
 * WordPress 7's admin view transitions: in headless Chromium the transition of
 * a form-submit navigation never ends and the tab stops painting.
 */
remove_action( 'admin_enqueue_scripts', 'wp_enqueue_view_transitions_admin_css' );
