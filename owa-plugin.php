<?php 

/*
Plugin Name: Open Web Analytics
Plugin URI: http://www.openwebanalytics.com
Description: This plugin enables Wordpress blog owners to use the Open Web Analytics Framework.
Author: Peter Adams
Version: 2.4.0
Requires at least: 5.2
Requires PHP: 8.2
Author URI: http://www.openwebanalytics.com
*/

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2008 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//
// $Id$
//

// if this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Define the plugin path constant 


define('OWA_WP_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );


// Hook package creation
add_action('plugins_loaded', [ 'owaWp_plugin', 'getInstance' ], 10 );

/////////////////////////////////////////////////////////////////////////////////

require_once( OWA_WP_PLUGIN_PATH .'/vendor/autoload.php' );

use owaWp\module;
use owaWp\util;

// page caches purge a site when its tag changes; Proxy Cache Purge reads its events on init
owaWp\cache::register();

/**
 * OWA WordPress Plugin Class
 *
 */
class owaWp_plugin extends module {
	
	// cmd array
	var $cmds = [];
	
	// plugin options
	var $options = [];
	
	// SDK singleton
	var $owaSdk = '';
	
	var $adminMsgs = [];
	
	// why the last site list could not be loaded
	var $lastSitesError = '';
	
	// shown with the Website ID list: why it is empty or short
	var $sitesNotice = '';
	
	// OWA returned its list with the saved credentials
	var $sitesLoaded = false;
	
	// OWA 2.x TrackerBundle::SITE_ID_PATTERN: the IDs it publishes a bundle for
	const BUNDLE_SITE_ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/D';
	
	/**
	 * Constructor
	 *
	 */	
	function __construct() {

		// bail if this isn't a request type that OWA needs ot be loaded on.
		if ( ! $this->isProperWordPressRequest() ) {
			
			return;
		}
				
		// load parent constructor
		$params = array();
		$params['module_name'] = 'owa-wordpress';
		parent::__construct( $params ); 
	}
	
	private function isProperWordPressRequest() {

		// cron requests
		if ( wp_doing_cron() ) {

			return false;
		}

		// REST requests are not excluded: REST_REQUEST is not defined yet at
		// plugins_loaded, and the block editor saves posts over REST, so
		// excluding them would stop post actions from being tracked.

		return true;
	}
	
	/**
	 * Singelton
	 */
	static function getInstance() {
		
		static $o;
	
		if ( ! isset( $o ) ) {
			
			$o = new owaWp_plugin();
		}
		
		return $o;
	}
	
	
	function _init() {
				
		// setup plugin options
		$this->initOptions();
		
		// the SDK serves the Website ID list on the settings page, which a
		// site with tracking off needs too
		$this->initOwaSdk();
		
		// register WordPress hooks and filters
		
		if ( $this->getOption('enable') ) {
			
			// insert javascript tracking tag on all pages/posts
			add_action('wp_head', array( $this,'insertTrackingTag' ), 100 );
			
			
			// insert javascript tracker on admin pages (1.x only).
			if ( $this->getOwaVersion() === 1 && $this->getOption('trackAdminPages') ) {
				
				add_action('admin_head', array( $this,'insertTrackingTag' ), 100 );	
							
			}

			add_action('admin_notices', array( $this, 'showNag') );
			
		}
		
		// the settings pages (React, src/js) and the REST routes they use
		owaWp\admin::register( $this );
		owaWp\rest::register( $this );

	}
	
	function addNag( $message, $class = 'notice-warning' ) {

		if ( $message ) {

			$this->adminMsgs[] = ['message' => $message, 'class' => $class ?: 'notice-warning'];
		}

	}

	function showNag() {
		
		
		$allowed_html = array(
		    'a'      => array(
		        'href'  => array(),
		        'title' => array(),
		    ),
		    'br'     => array(),
		    'em'     => array(),
		    'strong' => array(),
		    'b'		=> array(),
		);
	
		
		$messages = $this->adminMsgs;
		
		// the settings page shows these itself, for the version chosen on it
		if ( ! $this->isSettingsScreen() ) {
			
			foreach ( $this->setupNotices() as $notice ) {
				
				$messages[] = [ 'message' => $notice, 'class' => 'notice-warning' ];
			}
		}
		
		if ( $messages ) {
		
			foreach ( $messages as $msg ) {
				
				$message =  wp_kses( $msg['message'], $allowed_html );
				
				$class = esc_attr( $msg['class'] );
				
				printf( '<div class="notice %s"><p>%s</p></div>', $class, $message );
			}	
		}
	}
	
		
	private function initOptions() {
		
		// defaults first: they are used down stream in all other hooks and classes.
		foreach ( $this->registerOptions() as $key => $option ) {
			
			if ( array_key_exists( 'default_value', $option ) ) {
				
				$this->options[ $key ] = $option['default_value'];
			}
		}
		
		// fetch plugin options from DB and override defaults.
		$options = get_option( 'owa_wp' );
		
		if ( is_array( $options ) ) {
			
			$this->options = array_merge( $this->options, $options );
		}
		
		$this->applyNetworkOptions();
	}
	
	/**
	 * Determines the title of the page being requested
	 *
	 * @param string $page_type
	 * @return string $title
	 */
	function getPageTitle() {
	
		$page_type = $this->getPageType();
		
		if ( $page_type == "Home" ) {
		
			$title = get_bloginfo( "name" );
		
		} elseif ( $page_type == "Search Results" ) {

			$title = "Search Results for \"" . get_search_query( false ) . "\"";

		} else {

			$title = wp_title($sep = '', $display = 0);
		}

		// WordPress titles carry HTML entities (&#8211; etc.); send the text a
		// browser shows in its title bar.
		return trim( html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Builds one owa_cmds.push() statement. Arguments are JSON encoded, so a
	 * quote, backslash or </script> in a title cannot leave the string.
	 */
	function makeCmd( $name, ...$args ) {

		return sprintf( 'owa_cmds.push(%s);', wp_json_encode( array_merge( [ $name ], $args ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ) );
	}

	function setPageTitleCmd() {

		$title = $this->getPageTitle();

		// wp_title() is empty on a static front page; leave the tracker to
		// read document.title rather than overwrite it with nothing.
		if ( $title !== '' ) {

			$this->cmds[] = $this->makeCmd( 'setPageTitle', $title );
		}
	}

	function setUserNameCmd() {

		if ( ! is_user_logged_in() ) {

			return;
		}

		$current_user = wp_get_current_user();
		$this->cmds[] = $this->makeCmd( 'setUserName', $current_user->user_login );
	}

	/**
	 * Determines the type of WordPress page
	 *
	 * @return string $type
	 */
	function getPageType() {	
		
		if ( is_home() ) {
			$type = "Home";
		} elseif ( is_attachment() ){
			$type = "Attachment";
		} elseif ( is_page() ) {
			$type = "Page";
		// general page catch, should be after more specific post types	
		} elseif ( is_single() ) {
			$type = "Post";
		} elseif ( is_feed() ) {
			$type = "Feed";
		} elseif ( is_author() ) {
			$type = "Author";
		} elseif ( is_category() ) {
			$type = "Category";
		} elseif ( is_search() ) {
			$type = "Search Results";
		} elseif ( is_month() ) {
			$type = "Month";
		} elseif ( is_day() ) {
			$type = "Day";
		} elseif ( is_year() ) {
			$type = "Year";
		} elseif ( is_time() ) {
			$type = "Time";
		} elseif ( is_tag() ) {
			$type = "Tag";
		} elseif ( is_tax() ) {
			$type = "Taxonomy";
		// general archive catch, should be after specific archive types	
		} elseif ( is_archive() ) {
			$type = "Archive";
		} elseif ( is_admin() ) {
			$type = "Admin";
		} else {
			$type = '(not set)';
		}
		
		return $type;
	}
	
	function setDebugCmd() {

		$this->cmds[] = $this->makeCmd( 'setDebug', true );
	}

	function setSiteIdCmd() {

		$this->cmds[] = $this->makeCmd( 'setSiteId', (string) $this->getOption('siteId') );
	}

	function setPageTypeCmd() {

		$this->cmds[] = $this->makeCmd( 'setPageType', $this->getPageType() );
	}

	function setTrackPageViewCmd() {

		$this->cmds[] = $this->makeCmd( 'trackPageView' );
	}

	function setTrackClicksCmd() {

		$this->cmds[] = $this->makeCmd( 'trackClicks' );
	}

	function setTrackDomstreamsCmd() {

		$this->cmds[] = $this->makeCmd( 'trackDomStream' );
	}
	
	function cmdsToString() {
		
		$out = '';
		
		foreach ( $this->cmds as $cmd ) {
			
			$out .= $cmd . " \n";	
		}
		
		return $out;
	}
	
	/**
	 * Reads the options again, after they are saved, and the SDK with them.
	 */
	function reloadOptions() {
		
		$this->options = [];
		$this->owaSdk = '';
		$this->initOptions();
		$this->initOwaSdk();
	}
	
	/**
	 * Network mode: the network's server and credentials replace the site's,
	 * and when the network configures each site's Profile, so does the
	 * Website ID.
	 */
	function applyNetworkOptions() {
		
		if ( ! owaWp\network::isActive() ) {
			
			return;
		}
		
		$network = owaWp\network::options();
		
		$this->options['owaVersion'] = '2';
		$this->options['owaEndpoint'] = $network['owaEndpoint'];
		$this->options['appPassword'] = $network['appPassword'];
		
		if ( $network['assignProfiles'] ) {
			
			$this->options['siteId'] = $network['assignedSiteIds'][ get_current_blog_id() ] ?? '';
			
			// the network's switch for this site is the only on/off
			$this->options['enable'] = owaWp\network::isSiteOn( $network['disabledSiteIds'], get_current_blog_id() ) ? 1 : 0;
		}
	}
	
	/**
	 * The site list, fetched with the network's credentials (network mode).
	 *
	 * @return array|null
	 */
	function listSitesWithNetworkCredentials() {
		
		$network = owaWp\network::options();
		
		$config = apply_filters( 'owa_wp_sdk_config', [
			'credentials'   => [ 'app_password' => $network['appPassword'] ],
			'instance_url'  => $network['owaEndpoint'],
		] );
		
		$client = ( new OwaSdk\sdk( $config ) )->createSites();
		$sites = $client->listSites();
		
		$this->lastSitesError = is_array( $sites ) ? '' : ( implode( '; ', $client->getLastErrors() ) ?: 'no response' );
		
		return $sites;
	}
	
	function getLastSitesError() {
		
		return $this->lastSitesError;
	}
	
	/**
	 * The OWA server version the settings are for: 1 or 2.
	 */
	function getOwaVersion() {
		
		return (string) $this->getOption( 'owaVersion' ) === '2' ? 2 : 1;
	}
	
	/**
	 * @param int|null $version 1 or 2; the saved version by default
	 */
	function isOwaReadyToTrack( $version = null ) {
		
		$version = $version ?? $this->getOwaVersion();
		
		if ( ! $this->getOption( 'owaEndpoint' ) || ! $this->getOption( 'siteId' ) ) {
			
			return false;
		}
		
		// 2.x loads <siteId>.js, so the ID has to be a file name OWA can publish
		if ( $version === 2 && ! preg_match( self::BUNDLE_SITE_ID_PATTERN, (string) $this->getOption( 'siteId' ) ) ) {
			
			return false;
		}
		
		return true;
	}
	
	/**
	 * Credentials for the Website ID list are present.
	 *
	 * @param int|null $version 1 or 2; the saved version by default
	 */
	function isOwaSdkReady( $version = null ) {
		
		$version = $version ?? $this->getOwaVersion();
		
		if ( ! $this->getOption( 'owaEndpoint' ) ) {
			
			return false;
		}
		
		if ( $version === 2 ) {
			
			return (bool) $this->getOption( 'appPassword' );
		}
		
		return $this->getOption( 'apiKey' ) && $this->getOption( 'authKey' );
	}
	
	// init the OWA SDK
	function initOwaSdk() {
					
		if ( ! empty( $this->owaSdk ) ) {
			
			return;
		}
		
		if ( ! $this->isOwaSdkReady() ) {
			
			return;
		}
		
		$credentials = $this->getOwaVersion() === 2
			? [ 'app_password' => $this->getOption( 'appPassword' ) ]
			: [ 'api_key' => $this->getOption( 'apiKey' ), 'auth_key' => $this->getOption( 'authKey' ) ];
		
		/**
		 * The OWA SDK's configuration. For example, an install that serves OWA
		 * over plain http and allows application passwords there can set
		 * 'allow_insecure_app_password' => true; the SDK refuses otherwise.
		 */
		$config = apply_filters( 'owa_wp_sdk_config', [
			'credentials'   => $credentials,
			'instance_url'  => $this->getOption( 'owaEndpoint' ),
		] );
		
		$this->owaSdk = new OwaSdk\sdk( $config );
	}
		
	/**
	 * Insert Tracking Tag
	 *
	 * Adds javascript tracking tag int <head> of all pages.
	 * 
	 */
	function insertTrackingTag() {
		
		// without an endpoint the tracker URL resolves against the page itself
		// (a 404 per page view); without a site ID OWA has nothing to log to.
		if ( ! $this->isOwaReadyToTrack() ) {
			
			return;
		}
		
		if ( is_admin() ) {
			
			// 2.x tags public pages only
			if ( $this->getOwaVersion() === 2 || ! $this->getOption( 'trackAdminPages' ) || $this->isOwnAdminScreen() ) {
				
				return;
			}
		}
				
		// Don't log if the page request is a preview - Wordpress 2.x or greater
		if ( function_exists( 'is_preview' ) && is_preview() ) {
			
			return;
		}
		
		// dont log customizer previews either.
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			
			return;
		}
		
		if ( $this->getOption( 'excludeLoggedInUsers' ) && is_user_logged_in() ) {
			
			return;
		}
		
		// set user name in tracking for names users with wp-admin accounts
		if ( $this->getOption( 'trackNamedUsers') ) {
			
			$this->setUserNameCmd();
		}
		
		if ( $this->getOwaVersion() === 2 ) {
			
			// the bundle sets the site ID, the Profile's features and the page view
			$this->setPageTypeCmd();
			$this->setPageTitleCmd();
			
			echo $this->getBundleSnippet( $this->cmdsToString() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			
			$this->cmds = [];
			
			return;
		}
		
		// set any cmds
		
		if ( $this->getOption('debug') ) {
			
			$this->setDebugCmd();
		}
		
		$this->setSiteIdCmd();
		$this->setPageTypeCmd();
		$this->setPageTitleCmd();
		
		// set track clicks command
		if ( $this->getOption('trackClicks') ) {
			
			$this->setTrackClicksCmd();
		}
		
		// set track domstream command
		if ( $this->getOption('trackDomstreams') ) {
			
			$this->setTrackDomstreamsCmd();
		}
		
		// set track page view command
		$this->setTrackPageViewCmd();
		
		// every part of the tag is escaped where it is built.
		echo $this->getTrackerSnippet( $this->cmdsToString() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		
		$this->cmds = [];
	}
	
	/**
	 * The URL OWA 2.x publishes the Website ID's tracking bundle at
	 * (TrackerBundle::url()).
	 */
	function getBundleUrl() {
		
		return rtrim( esc_url_raw( $this->getOption( 'owaEndpoint' ) ), '/' ) . '/public/tracker/' . $this->getOption( 'siteId' ) . '.js';
	}
	
	/**
	 * The tag OWA 2.x shows on a Website ID's Tracking Tag screen
	 * (js_bundle_tag.php), with the page's own commands in the queue.
	 */
	function getBundleSnippet( $cmds ) {
		
		$url = $this->getBundleUrl();
		
		$tag = '';
		
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host = wp_parse_url( $url, PHP_URL_HOST );
		
		if ( $scheme && $host ) {
			
			$port = wp_parse_url( $url, PHP_URL_PORT );
			$origin = $scheme . '://' . $host . ( $port ? ':' . $port : '' );
			
			$tag .= sprintf( '<link rel="preconnect" href="%s">' . "\n", esc_attr( $origin ) );
			$tag .= sprintf( '<link rel="dns-prefetch" href="%s">' . "\n", esc_attr( $origin ) );
		}
		
		$tag .= "<!-- Start Open Web Analytics Tracker -->\n";
		$tag .= "<script>var owa_cmds = owa_cmds || [];\n" . $cmds . "</script>\n";
		$tag .= sprintf( '<script async src="%s"></script>' . "\n", esc_url( $url ) );
		$tag .= "<!-- End Open Web Analytics Code -->\n";
		
		return $tag;
	}
	
	/**
	 * The Website ID's Tracking Tag screen in OWA 2.x, where its tracker
	 * settings are made.
	 */
	function getTagSettingsUrl() {
		
		return trailingslashit( esc_url_raw( $this->getOption( 'owaEndpoint' ) ) )
			. 'index.php?owa_do=base.sitesInvocation&owa_siteId=' . rawurlencode( (string) $this->getOption( 'siteId' ) );
	}
	
	/**
	 * Network mode: the Profiles a site may choose from, those that share its
	 * base domain. When the network configures each site's Profile, only the
	 * one it assigned, shown read-only.
	 */
	function filterSitesForNetwork( array $sites ) {
		
		if ( ! owaWp\network::options()['assignProfiles'] ) {
			
			return owaWp\network::filterByBaseDomain( $sites, owaWp\network::currentHost() );
		}
		
		$assigned = (string) $this->getOption( 'siteId' );
		
		if ( $assigned === '' ) {
			
			$this->sitesNotice = 'The network administrator has not assigned this site an Observation Profile, so it is not tracked.';
		}
		
		return array_filter( $sites, fn( $site ) => owaWp\network::rowSiteId( $site ) === $assigned );
	}
	
	/**
	 * Network mode: where the server and credentials come from, for the
	 * settings page.
	 */
	function getNetworkNote() {
		
		if ( ! owaWp\network::isActive() ) {
			
			return '';
		}
		
		return owaWp\network::options()['assignProfiles']
			? sprintf( 'The network administrator manages the OWA server (%s), configures this site\'s Observation Profile and has tracking on this site turned %s.', $this->getOption( 'owaEndpoint' ), $this->getOption( 'enable' ) ? 'on' : 'off' )
			: sprintf( 'The network administrator manages the OWA server (%s) and its credentials. The list shows the Observation Profiles that share this site\'s domain.', $this->getOption( 'owaEndpoint' ) );
	}
	
	/**
	 * What is left to set up before this site is tracked, from the saved
	 * settings, worded for a version (the saved one by default).
	 *
	 * @param int|null $version 1 or 2
	 * @return string[]
	 */
	function setupNotices( $version = null ) {
		
		$version = $version ?? $this->getOwaVersion();
		$notices = [];
		
		if ( ! $this->isOwaSdkReady( $version ) ) {
			
			$notices[] = $version === 2
				? 'Enter your OWA Endpoint and an Application Password to load the list of Observation Profiles from OWA.'
				: 'Enter your OWA Endpoint, API Key and Secret Auth Key to load the list of websites from OWA.';
		}
		
		// where the network sets the Observation Profile, the site admin cannot, and the page says so
		$assigned = owaWp\network::isActive() && owaWp\network::options()['assignProfiles'];
		
		// the picker shows once the credentials are in
		if ( ! $assigned && $this->isOwaSdkReady( $version ) && ! $this->isOwaReadyToTrack( $version ) ) {
			
			$notices[] = $version === 2
				? 'Open Web Analytics requires that you select an Observation Profile before tracking can begin.'
				: 'Open Web Analytics requires that you select a Website ID before tracking can begin.';
		}
		
		return $notices;
	}
	
	/**
	 * The site's OWA > Settings screen.
	 */
	function isSettingsScreen() {
		
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		
		return $screen && $screen->id === 'toplevel_page_owa-wordpress';
	}
	
	/**
	 * The plugin's own admin screens.
	 */
	function isOwnAdminScreen() {
		
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		
		return $screen && in_array( $screen->id, [ 'toplevel_page_owa-wordpress', 'owa_page_owa-analytics', 'toplevel_page_' . owaWp\network::MENU_SLUG . '-network' ], true );
	}
	
	/**
	 * The tracking tag OWA 1.14 generates on its Tracking Tag screen, with the
	 * plugin's commands in place of the server's.
	 */
	function getTrackerSnippet( $cmds ) {

		$base_url = esc_url_raw( $this->getOption('owaEndpoint') );

		$tag = '';

		$host = wp_parse_url( $base_url, PHP_URL_HOST );

		if ( $host ) {

			$port = wp_parse_url( $base_url, PHP_URL_PORT );
			$origin = '//' . $host . ( $port ? ':' . $port : '' );

			$tag .= sprintf( '<link rel="preconnect" href="%s">' . "\n", esc_attr( $origin ) );
			$tag .= sprintf( '<link rel="dns-prefetch" href="%s">' . "\n", esc_attr( $origin ) );
		}

		$tag .= "<!-- Start Open Web Analytics Tracker -->\n";
		$tag .= '<script type="text/javascript">' . "\n";
		$tag .= "//<![CDATA[\n";
		$tag .= 'var owa_baseUrl = ' . wp_json_encode( $base_url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ) . ";\n";
		$tag .= "var owa_cmds = owa_cmds || [];\n";
		$tag .= $cmds;
		$tag .= "
(function() {
    var _owa = document.createElement('script'); _owa.async = true;
    owa_baseUrl = ('https:' == document.location.protocol ? window.owa_baseSecUrl || owa_baseUrl.replace(/http:/, 'https:') : owa_baseUrl );
    _owa.src = owa_baseUrl + 'public/base/dist/owa.tracker.js';
    var _owa_p = document.head || document.getElementsByTagName('head')[0] || document.documentElement;
    _owa_p.appendChild(_owa);
}());
";
		$tag .= "//]]>\n";
		$tag .= "</script>\n";
		$tag .= "<!-- End Open Web Analytics Code -->\n";

		return $tag;
	}
	
		
	public function registerOptions() {		
		
		$settings = array(
		
			'owaVersion'				=> array(
			
				'default_value'							=> '1',
				'field'									=> array(
					'type'									=> 'radio',
					'title'									=> 'OWA Server Version',
					'page_name'								=> 'owa-wordpress',
					'section'								=> 'general',
					'description'							=> 'Which Open Web Analytics server this site reports to. 2.x (beta) loads the Observation Profile&#039;s tracking bundle; its tracker settings are made on the OWA server.',
					'label_for'								=> 'OWA server version',
					'options'								=> [ '1' => '1.x', '2' => '2.x (beta)' ],
					'error_message'							=> ''		
				)				
			),
			
			'enable'				=> array(
			
				'default_value'							=> true,
				'field'									=> array(
					'type'									=> 'boolean',
					'title'									=> 'Enable OWA ',
					'page_name'								=> 'owa-wordpress',
					'section'								=> 'general',
					'description'							=> 'Enable OWA.',
					'label_for'								=> 'Enable OWA.',
					'error_message'							=> 'You must select On or Off.'		
				)				
			),
			
			'apiKey'				=> array(
			
				'default_value'							=> '',
				'field'									=> array(
					'owa_version'							=> '1',
					'class'								=> 'owa-wp-v1',
					'type'									=> 'secret',
					'title'									=> 'API Key',
					'page_name'								=> 'owa-wordpress',
					'section'								=> 'general',
					'description'							=> 'API key for accessing your OWA instance.',
					'label_for'								=> 'OWA API Key',
					'length'								=> 70,
					'error_message'							=> ''		
				)				
			),
			
			'authKey'				=> array(
			
				'default_value'							=> '',
				'field'									=> array(
					'owa_version'							=> '1',
					'class'								=> 'owa-wp-v1',
					'type'									=> 'secret',
					'title'									=> 'Secret Auth Key',
					'page_name'								=> 'owa-wordpress',
					'section'								=> 'general',
					'description'							=> 'The secret OWA_AUTH_KEY found the owa-config.php file of your OWA Server instance.',
					'label_for'								=> 'OWA Auth Key',
					'length'								=> 70,
					'error_message'							=> ''		
				)				
			),

			
			'appPassword'				=> array(
			
				'default_value'							=> '',
				'field'									=> array(
					'owa_version'							=> '2',
					'class'								=> 'owa-wp-v2',
					'type'									=> 'app_password',
					'title'									=> 'Application Password',
					'page_name'								=> 'owa-wordpress',
					'section'								=> 'general',
					'description'							=> 'An application password from your OWA 2.x server (My Preferences &gt; Application Passwords). A read-only one is enough. OWA refuses it over plain http unless the server allows that.',
					'label_for'								=> 'Application password',
					'length'								=> 60,
					'error_message'							=> ''		
				)				
			),
			
			'owaEndpoint'			=> array(
			
				'default_value'							=> '',
				'field'									=> array(
					'type'									=> 'url',
					'title'									=> 'OWA Endpoint',
					'page_name'								=> 'owa-wordpress',
					'section'								=> 'general',
					'description'							=> 'The URL of your OWA instance (e.g. https://owa.example.com/path/to/owa/), the OWA_PUBLIC_URL in its owa-config.php. Saving checks that OWA answers there. Without https:// or http://, https is tried first.',
					'label_for'								=> 'OWA Endpoint',
					'length'								=> 70,
					'endWithSlash'							=> true,
					'error_message'							=> ''		
				)				
			),
			
			'siteId'				=> array(
			
				'default_value'							=> '',
				'field'									=> array(
					'type'									=> 'select',
					'title'									=> 'Website ID',
					'title_v2'								=> 'Observation Profile',
					'page_name'								=> 'owa-wordpress',
					'section'								=> 'general',
					'description'							=> 'Select the ID of the website you want to track. New tracked websites can be added via the OWA server admin interface.',
					'description_v2'						=> 'Select the Observation Profile you want to track. New Observation Profiles can be added in OWA.',
					'label_for'								=> 'Tracked website ID',
					'length'								=> 90,
					'error_message'							=> '',
					'options'								=> []		
				)				
			),

			
			'trackClicks'				=> array(
			
				'default_value'							=> true,
				'field'									=> array(
					'owa_version'							=> '1',
					'class'								=> 'owa-wp-v1',
					'type'									=> 'boolean',
					'title'									=> 'Track Clicks',
					'page_name'								=> 'owa-wordpress',
					'section'								=> 'tracking',
					'description'							=> 'Track the clicks visitors make on your web pages.',
					'label_for'								=> 'Track clicks within a web page',
					'error_message'							=> 'You must select On or Off.'		
				)				
			),
			
			'trackDomstreams'				=> array(
			
				'default_value'							=> false,
				'field'									=> array(
					'owa_version'							=> '1',
					'class'								=> 'owa-wp-v1',
					'type'									=> 'boolean',
					'title'									=> 'Track Domstreams',
					'page_name'								=> 'owa-wordpress',
					'section'								=> 'tracking',
					'description'							=> 'Record visitor mouse movements on each web page.',
					'label_for'								=> 'Record mouse movements',
					'error_message'							=> 'You must select On or Off.'		
				)				
			),
			
			'trackNamedUsers'				=> array(
			
				'default_value'							=> true,
				'field'									=> array(
					'type'									=> 'boolean',
					'title'									=> 'Track Named Users',
					'page_name'								=> 'owa-wordpress',
					'section'								=> 'tracking',
					'description'							=> 'Send the user name (login) of logged-in WordPress users with their page views.',
					'label_for'								=> 'Track named users',
					'error_message'							=> 'You must select On or Off.'		
				)				
			),
			
			'excludeLoggedInUsers'				=> array(
			
				'default_value'							=> true,
				'field'									=> array(
					'type'									=> 'boolean',
					'title'									=> 'Don&#039;t Track Logged-in Users',
					'page_name'								=> 'owa-wordpress',
					'section'								=> 'tracking',
					'description'							=> 'No tracking tag for visitors who are logged in to WordPress.',
					'label_for'								=> 'Do not track logged-in users',
					'error_message'							=> ''		
				)				
			),
			
			'trackAdminPages'				=> array(
			
				'default_value'							=> false,
				'field'									=> array(
					'owa_version'							=> '1',
					'class'								=> 'owa-wp-v1',
					'type'									=> 'boolean',
					'title'									=> 'Track WP Admin Pages',
					'page_name'								=> 'owa-wordpress',
					'section'								=> 'tracking',
					'description'							=> 'Track WordPress admin interface pages (/wp-admin...)',
					'label_for'								=> 'Track WP admin pages',
					'error_message'							=> 'You must select On or Off.'		
				)				
			),
			
			'debug'				=> array(
			
				'default_value'							=> false,
				'field'									=> array(
					'owa_version'							=> '1',
					'class'								=> 'owa-wp-v1',
					'type'									=> 'boolean',
					'title'									=> 'Debug Mode',
					'page_name'								=> 'owa-wordpress',
					'section'								=> 'advanced',
					'description'							=> 'Outputs debug notices to log file and browser console.',
					'label_for'								=> 'Debug Mode',
					'error_message'							=> 'You must select On or Off.'		
				)				
			),

		);
		
		if ( owaWp\network::isActive() ) {
			
			$managed = [ 'owaVersion', 'owaEndpoint', 'appPassword', 'apiKey', 'authKey' ];
			
			if ( owaWp\network::options()['assignProfiles'] ) {
				
				$managed[] = 'siteId';
				$managed[] = 'enable';
				$settings['siteId']['field']['readonly'] = true;
			}
			
			foreach ( $managed as $key ) {
				
				// the stored value is kept on save; the network's is used
				$settings[ $key ]['field']['managed'] = true;
				
				if ( $key !== 'siteId' ) {
					
					$settings[ $key ]['field']['class'] = trim( ( $settings[ $key ]['field']['class'] ?? '' ) . ' owa-wp-network-managed' );
				}
			}
		}
	
		return $settings;
	}

	/**
	 * The settings page's tabs, in order.
	 */
	public function registerSections() {
		
		return [
			'general'	=> [
				'title'			=> 'General',
				'description'	=> 'These settings control the connection between WordPress and your Open Web Analytics (OWA) server instance.',
			],
			'tracking'	=> [
				'title'			=> 'Tracking',
				'description'	=> 'These settings control the tracking events that will be sent to your Open Web Analytics (OWA) server instance.',
			],
			'advanced'	=> [
				'title'			=> 'Advanced',
				'description'	=> 'These are advanced integration settings that are seldom used. Do not change these unless you know what you are doing.',
			],
		];
	}
	
	/**
	 * Why the last site list is empty or short, for the settings page.
	 */
	function getSitesNotice() {
		
		return $this->sitesNotice;
	}
	
	/**
	 * Whether OWA returned the last site list: the credentials work.
	 */
	function sitesLoaded() {
		
		return $this->sitesLoaded;
	}
	
	/**
	 * Options for the Website ID picker, from OWA's GET /base/v1/sites.
	 *
	 * Rows are flat (site_id, domain) since OWA 1.10.1; earlier servers sent
	 * serialized entities (properties.site_id.value), which is still read.
	 *
	 * The saved site ID is always kept as an option: if OWA cannot be reached
	 * the picker would otherwise be empty, and saving the form would clear it.
	 */
	function getSitesFromOwa( $options = [] ) {
		
		$sites = null;
		$this->sitesNotice = '';
		$this->sitesLoaded = false;
		
		if ( $this->isOwaSdkReady() && ! empty( $this->owaSdk ) ) {
			
			$sites_client = $this->owaSdk->createSites();
			$sites = $sites_client->listSites();
			$this->sitesLoaded = is_array( $sites );
			
			if ( ! is_array( $sites ) ) {
				
				$errors = $sites_client->getLastErrors();
				$this->sitesNotice = sprintf( 'Could not load the list of %s from OWA: ', $this->getOwaVersion() === 2 ? 'Observation Profiles' : 'websites' ) . ( $errors ? implode( '; ', $errors ) : 'no response' );
			}
		}
		
		if ( is_array( $sites ) && owaWp\network::isActive() ) {
			
			$sites = $this->filterSitesForNetwork( $sites );
		}
		
		$list = [];
		
		foreach ( (array) $sites as $site ) {
			
			$site_id = $site['site_id'] ?? $site['properties']['site_id']['value'] ?? '';
			$domain = $site['domain'] ?? $site['properties']['domain']['value'] ?? '';
			
			if ( $site_id === '' ) {
				
				continue;
			}
			
			// 2.x: a Property holds the website's name, a Profile only "Observation Profile 1"
			$property = $this->getOwaVersion() === 2 ? (string) ( $site['property_name'] ?? '' ) : '';
			
			$label = owaWp\rest::profileLabel( (string) $site_id, [ 'domain' => $domain, 'property_name' => $property ] );
			
			$list[ $site_id ] = [ 'label' => $label, 'siteId' => (string) $site_id ];
		}
		
		$saved = (string) $this->getOption( 'siteId' );
		
		if ( $saved !== '' && ! isset( $list[ $saved ] ) ) {
			
			$list[ $saved ] = [ 'label' => sprintf( '%s (saved; not in the list from OWA)', $saved ), 'siteId' => $saved ];
		}
		
		return $list;
	}
			
	/**
	 * Callback for reporting dashboard/pages 
	 */
	function pageController( $params = array() ) {
	
		// insert link to OWA endpoint	
		
		if ( ! current_user_can( 'manage_options' ) ) {
    
        	wp_die(__( 'You do not have sufficient permissions to access this page!' ) );
		}
		
		
		$allowed_html = [
		    'div'      => [
		        'class'  	=> [],
		        'id'		=> [],
		        'style'		=> []
		        
		    ],
		    'a'			=> [
			    'href'		=> [],
			    'target'	=> []
		    ],
		    'h2'     => [],
		    'em'     => []
		];
		
		$url = esc_url( $this->getOption('owaEndpoint') );
		
		$out = '';
		$out .= '<div class="wrap">';
		$out .=	'<div class="icon32" id="icon-options-general"><br></div>';
		$out .=	sprintf('<h2>%s</h2>', 'Analytics' );
		$out .=	'Click the link below to view analytics in your OWA instance.';

		$out .= sprintf('<div style="margin-top: 50px;"><a href="%s" target="_new">Launch your OWA Dashboard</a>', $url);
		
		$out .= '</div>';
		
		echo wp_kses( $out, $allowed_html );
		
	}

}

?>