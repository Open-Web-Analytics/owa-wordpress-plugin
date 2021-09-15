<?php

namespace owaWp;

class util {

	public static function getTaxonomies( $args ) {
	
		return get_taxonomies( $args );
	}
	
	public static function getPostTypes( $args, $type = 'names', $operator = 'and') {
		
		return get_post_types( $args, $type, $operator );
	}
	
	public static function getRemoteUrl( $url ) {
		
		return wp_remote_get ( urlencode ( $url ) );
	}
	
	public static function getModuleOptionKey( $package_name, $module_name ) {
		
		return sprintf( '%s_%s_%s', 'owa_wp', $package_name, $module_name );
	}
	
	public static function setDefaultParams( $defaults, $params, $class_name = '' ) {
		
		$newparams = $defaults;
		
		foreach ( $params as $k => $v ) {
			
			$newparams[$k] = $v;
		}
		
		return $newparams;
	}
	
	public static function addFilter( $hook, $callback, $priority = '', $accepted_args = '' ) {
		
		return add_filter( $hook, $callback, $priority, $accepted_args );
	}
	
	public static function addAction( $hook, $callback, $priority = '', $accepted_args = '' ) {
		
		return add_action( $hook, $callback, $priority, $accepted_args );
	}
	
	public static function escapeOutput( $string ) {
		
		return esc_html( $string );
	}
	
	//
	 // Outputs Localized String
	 //
	 //
	public static function out( $string ) {
		
		echo ( owa_wp_util::escapeOutput ( $string ) );
	}
	
	//
	 // Localize String
	 //
	 //
	public static function localize( $string ) {
		
		return $string;
	}
	
	//
	 // Flushes WordPress rewrite rules.
	 //
	 //
	public static function flushRewriteRules() {
		
		global $wp_rewrite;
		$wp_rewrite->flush_rules();
	}
	
	//
	 // Get a direct link to install or update a plugin
	 //
	 //
	public static function getWpPluginInstallUrl( $slug, $action = 'install-plugin' ) {
		
		return wp_nonce_url(
		    add_query_arg(
		        array(
		            'action' => $action,
		            'plugin' => $slug
		        ),
		        admin_url( 'update.php' )
		    ),
		    $action . '_' . $slug
		);
	}
}

?>