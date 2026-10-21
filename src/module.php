<?php

namespace owaWp;

use owaWp\util;

class module {

	public $module_name;
	public $package_name;
	public $ns;
	public $options;

	public function __construct( $params = array() ) {

		// set module name
		if ( array_key_exists( 'module_name', $params ) ) {

			$this->module_name = $params['module_name'];
		}

		// set package name
		if ( array_key_exists( 'package_name', $params ) ) {

			$this->package_name = $params['package_name'];
		}

		// set namespace
		if ( array_key_exists( 'ns', $params ) ) {

			$this->ns = $params['ns'];
		}

		// kick off the init sequence for each module during Wordpress 'init' hook.
		add_action('init', array( $this, 'init'), 15, 0 );
	}

	/**
	 * Get an option value
	 */
	function getOption( $key ) {

		if ( is_array( $this->options ) && array_key_exists( $key, $this->options ) ) {

			return $this->options[ $key ];
		}
	}

	/**
	 * Set an option value
	 */
	function setOption( $key, $value ) {

		$this->options[ $key ] = $value;
	}

	public function init() {

		$this->_init();
		// load public hooks
		$this->definePublicHooks();
		// load admin hooks during WordPress 'admin_init' hook

		util::addAction( 'admin_init', array( $this, 'defineAdminHooks') );
	}

	public function registerOptions() {

		return false;
	}

	/**
	 * Register all of the hooks related to the module
	 * of the plugin.
	 */
	public function defineAdminHooks() {

		return false;
	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 */
	public function definePublicHooks() {

		return false;
	}
}

?>
