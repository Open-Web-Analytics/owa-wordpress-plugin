<?php

namespace owaWp;
use owaWp\util;
use owaWp\settings\page;

class module {
	
	public $module_name;
	public $controllers;
	public $entities;
	public $views;
	public $ns;
	public $package_name;
	public $options;
	public $settings;
	public $settings_pages;
	
	public function __construct( $params = array() ) {
	
		$this->controllers = array();
		$this->entities	= array();
		$this->views = array();
		$this->settings_pages = array();
		
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
		
		$options = array();
		$options = $this->options;
		if ( array_key_exists( $key, $options ) ) {
			
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
	
		/**
	 * Inititalizes Settings Page Objects
	 *
	 */
	public function initSettingsPage() {
		
		// check for prior initialization as I'm not sure if the WP hook admin_init or admin_menu 
		// gets called first.
		if ( ! $this->settings_pages ) {			
			
			$sp_params = array(
			
				'ns'				=> $this->ns,
				'package'			=> $this->package_name,
				'module'			=> $this->module_name
			);
			
			$pages = $this->registerSettingsPages();
			
			if ( $pages ) {
				
				foreach ( $pages as $k => $params ) {
					
					$new_params = array_merge($params, $sp_params);
					$new_params['name'] = $k;
					
					$this->settings_pages[ $k ] = new page( $new_params, $this->options );
				}
			}
		}
	}
	
	/**
	 * Callback function for WordPress admin_menu hook
	 *
	 * Hooks create Menu Pages.
	 */
	public function addSettingsPages() {
	
		$this->initSettingsPage();
		
		$pages = $this->settings_pages;
		
		if ( $pages ) {
			
			foreach ( $pages as $k => $page ) {
				
				$menu_slug = '';
				
				$menu_slug = $page->get('menu_slug');
				
				// check for custom callback function.
				if ( $page->get( 'render_callback' ) ) {
					
					$callback = $page->get( 'render_callback' );
					
				} else {
					
					$callback = array( $page, 'renderPage' );
				}
				
				if ( $page->get('is_top_level') ) {
					
					add_menu_page( 
						$page->get('title'), 
						$page->get('top_level_menu_title'), 
						$page->get('required_capability'), 
						$page->get('parent_slug'), 
						$callback, 
						$page->get('menu-icon'), 
						2 
					);
					
					$menu_slug = $page->get('parent_slug');
				}
				
				// register the page with WordPress admin navigation.
				add_submenu_page( 
					$page->get('parent_slug'), 
					$page->get('title'), 
					$page->get('menu_title'), 
					$page->get('required_capability'),
					$menu_slug,
					$callback 
				);			
			}
		}
	}
	
	public function processAdminConfig() {
		
		$config = $this->registerOptions();
		
		if ( $config ) {
		
			foreach ( $config as $k => $v ) {
				
				// register setting field with module
				if ( array_key_exists( 'field', $v ) ) {
					// check for page_name, if not set it as 'default'
					if ( ! array_key_exists( 'page_name', $v['field'] ) ) {
						
						$v['field']['page_name'] = 'default';
					}
					
					// add field to settings array
					$this->settings[ $v['field']['page_name'] ][ $k ] = $v[ 'field' ];
				}
				
				// register default option value with module
				if (array_key_exists( 'default_value', $v ) ) {
				
					$this->options[ $k ] = $v[ 'default_value' ];
				}
			}
			
			// hook settings fields into WordPress		
			if ( $this->settings ) {
				
				// we need ot init the settings page objects here 
				// as they are needed by two the callbacks to seperate WordPress Hooks admin_init and admin_menu.
				//$this->initSettingsPage();
				
				add_action( 'admin_init', array($this, 'registerSettings'),10,0);
				// regsiter the settings pages with WordPress
				add_action( 'admin_menu', array($this, 'addSettingsPages'), 11,0);
		
			}				
		}
	}
	
	public function registerAdminConfig() {
		
		return false;
	}
	
	public function registerSettings() {
					
		// process options
		
		$this->initSettingsPage();
		
		//add_action( 'admin_menu', array($this, 'addSettingsPages'), 10, 0 );
		
		// iterate throught group of settings fields.
		
		foreach ( $this->settings as $group_name => $group ) {
		
			// iterate throug thhe fields in the group
			foreach ( $group as $k => $v ) {
				
				// register each field with WordPress
				$this->settings_pages[ $group_name ]->registerField( $k, $v );
			}
			
			// register the group
			$this->settings_pages[ $group_name ]->registerSettings( $group_name );
			
			// register the sections
			
			$sections = $this->settings_pages[ $group_name ]->get('sections');
			
			if ( $sections ) {
				
				foreach ( $sections as $section_name => $section ) {
				
					$this->settings_pages[ $group_name ]->registerSection( $section );		
				}
			}
		}
	}
	
	/**
	 * Get Options Key 
	 *
	 * Gets the key under which options for the module should be persisted.
	 *
	 * @return string
	 */
	public function getOptionsKey() {
		
		//return owaWp\util::getModuleOptionKey( $this->package_name, $this->module_name );
	}
	
	public function registerController( $action_name, $class, $path ) {
		
		$this->controllers[ $action_name ] = array(
			'class'			=> $class,
			'path'			=> $path
		);
	}
	
	public function registerControllers( $controllers = array() ) {
		
		return $controllers;
	}
	
	public function loadDependancies() {
			
		return false;
	}
	
	public function registerOptions() {
		
		return false;
	}
	
	public function setDefaultOptions( $options ) {
		
		//$options[ $this->getOptionsKey() ] = $this->options;
		return $this->options;
		//return $options;
	} 
	
	/**
	 * Register all of the hooks related to the module
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	public function defineAdminHooks() {
		
		return false;
	}
	
	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	public function definePublicHooks() {
		
		return false;
	}
}

	
?>