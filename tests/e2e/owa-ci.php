<?php
/*
 * Must-use plugin for CI's throwaway WordPress only: its OWA is served by
 * php -S over plain http, where the SDK otherwise refuses to send an
 * application password.
 */
add_filter( 'owa_wp_sdk_config', function ( $config ) {
	return $config + [ 'allow_insecure_app_password' => true ];
} );
