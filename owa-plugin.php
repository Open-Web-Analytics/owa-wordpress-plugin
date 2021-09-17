<?php 

/*
Plugin Name: Open Web Analytics
Plugin URI: http://www.openwebanalytics.com
Description: This plugin enables Wordpress blog owners to use the Open Web Analytics Framework.
Author: Peter Adams
Version: 2.0.4
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
define('OWA_WP_PATH', plugin_dir_path( __FILE__ ) );


// Hook package creation
add_action('plugins_loaded', [ 'owaWp_plugin', 'getInstance' ], 10 );

// activation hook that checks for old version of plugin
register_activation_hook( __FILE__, ['owaWp_plugin', 'install'] );

/////////////////////////////////////////////////////////////////////////////////

require_once( OWA_WP_PATH .'/vendor/autoload.php' );

use owaWp\module;
use owaWp\util;

/**
 * OWA WordPress Plugin Class
 *
 */
class owaWp_plugin extends module {
	
	// cmd array
	var $cmds = [];
	
	// plugin options
	var $options = [
		
		'track_feed_links'			=> true,
		'feed_tracking_medium' 		=> 'feed',
		'feed_subscription_param' 	=> 'owa_sid'
	];
	
	// SDK singleton
	var $owaSdk = '';
	
	var $adminMsgs = [];
	
	/**
	 * Constructor
	 *
	 */	
	function __construct() {
		
		// needed???
		ob_start();
		
		// bail if this isn't a request type that OWA needs ot be loaded on.
		if ( ! $this->isProperWordPressRequest() ) {
			
			return;
		}
				
		// load parent constructor
		$params = array();
		$params['module_name'] = 'owa-wordpress';
		parent::__construct( $params ); 
	}
	
	public static function install() {
		
		$old_plugin = 'owa/wp_plugin.php';
		
		if ( is_plugin_active( $old_plugin ) ) {
			
			deactivate_plugins( $old_plugin );
		}
	}
	
	private function isProperWordPressRequest() {
		
		// cron requests
		if ( array_key_exists('doing_wp_cron', $_GET ) ) {
			
			return;
		}
		
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			
			return;
		}
		
		if ( defined( 'JSON_REQUEST' ) && JSON_REQUEST ) {
			
			return;
		}
		
		
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
		
		// register WordPress hooks and filters
		
		if ( $this->getOption('enable') ) {
			
			// insert javascript tracking tag on all pages/posts
			add_action('wp_head', array( $this,'insertTrackingTag' ), 100 );
			
			
			// insert javascript tracker on admin pages.
			if (  $this->getOption('trackAdminPages') ) {
				
				add_action('admin_head', array( $this,'insertTrackingTag' ), 100 );	
							
			}

			// track feeds
			if ( $this->getOption('trackFeeds') ) {
				// add tracking to feed entry permalinks
				add_filter('the_permalink_rss', array( $this, 'decorateFeedEntryPermalink' ) );
			
				// add tracking to feed subscription links
				add_filter('bloginfo_url', array($this, 'decorateFeedSubscriptionLink' ) );
			}
			
			// init the sdk
			$this->initOwaSdk();
				
			// track wordpress admin actions.
			$this->defineActionHooks();
			
			// Create a new tracked site in OWA
			add_action('wpmu_new_blog', array($this, 'createTrackedSiteForNewBlog'), 10, 6);
			
			// remove this if uneeded
			if ( ! $this->isOwaReadyToTrack() ) {
				
				$this->adminMsgs[] = ['message' => 'Open Web Analytics requires a valid <b>API Key</b>, <b>Endpoint</b>, and <b>Site ID</b> before tracking can begin.', 'class' => 'notice-warning'];
				
			}
			
			add_action('admin_notices', array( $this, 'showNag') );
			
		}

	}
	
	function addNag( $message, $class = '' ) {
		
		
		$defaults = [
					
			'class' => 'notice-warning'
		];
			
		if ( $message ) {
			
			$msg = ['message' => $message, 'class' => $class];				
			
			wp_parse_args( $msg, $defaults );
			
			$this->adminMsgs[] = $msg;
		}
		
	}
		
	function showNag( $msg ) {
		
		
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
	
		
		if ( $this->adminMsgs ) {
		
			foreach ( $this->adminMsgs as $msg ) {
				
				$message =  wp_kses( $msg['message'], $allowed_html );
				
				$class = esc_attr( $msg['class'] );
				
				_e( sprintf( '<BR><div class="notice %s"><p>%s</p></div>', $class, $message ) );	
			}	
		}
	}
	
		
	private function initOptions() {
		
		// needs to be first as default Options are set here and used down stream in
		// all other hooks and classes.
		$this->processAdminConfig();
		
		// get user defaults from option page
		$user_defaults = array_combine( array_keys( $this->registerOptions() ), array_column( $this->registerOptions() , 'default_value') );
		
		// merge in the default values
		if ( $user_defaults ) {
			
			$this->options = array_merge($this->options, $user_defaults);
		}
		
		// fetch plugin options from DB and override defaults.
		$options = get_option( 'owa_wp' );
		
		if ( $options ) {
			
			$this->options = array_merge($this->options, $options);
		}
		
		// Setup filter to populate site list from owa instance on the settings page
		owaWp\util::addFilter('owa_wp_settings_field_siteId', array( $this, 'getSitesFromOwa'), 10, 1);
	}
	
			
	/**
	 * Hooks for tracking WordPress Admin actions
	 */
	function defineActionHooks() {
			
		if ( $this->getOption( 'trackAdminActions' ) ) {
			
			// New Comment
			add_action( 'comment_post', array( $this, 'trackCommentAction' ), 10, 2);
			// Comment Edit
			add_action( 'transition_comment_status', array( $this, 'trackCommentEditAction' ), 10, 3);
			// User Registration
			add_action( 'user_register', array( $this, 'trackUserRegistrationAction' ) );
			// user login
			add_action( 'wp_login', array( $this, 'trackUserLoginAction' ) );
			// User Profile Update
			add_action( 'profile_update', array( $this, 'trackUserProfileUpdateAction' ), 10, 2);
			// Password Reset
			add_action( 'password_reset', array( $this, 'trackPasswordResetAction' ) );
			// Trackback
			add_action( 'trackback_post', array( $this, 'trackTrackbackAction' ) );
			// New Attachment
			add_action( 'add_attachment', array( $this, 'trackAttachmentCreatedAction' ) );
			// Attachment Edit
			add_action( 'edit_attachment', array( $this, 'trackAttachmentEditAction' ) );
			// Post Edit
			add_action( 'transition_post_status', array( $this, 'trackPostAction') , 10, 3);
			// New Blog (WPMU)
			add_action( 'wpmu_new_blog', array( $this, 'trackNewBlogAction') , 10, 5);
		}
		
		// track feeds
		
		if ( $this->getOption( 'trackFeeds' ) ) {
		
			add_action('wp_loaded', array( $this, 'addFeedTrackingQueryParams'));
			add_action( 'template_redirect', array( $this, 'trackFeedRequest'), 1 );
		}
	}	
	
	// Add query vars to WordPress
	function addFeedTrackingQueryParams() {
		
		global $wp; 
		
		// feed tracking param
		$wp->add_query_var( $this->getOption( 'feed_subscription_param' ) ); 
		
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
			
			$title = "Search Results for \"" . get_search_query() . "\"";	
		
		} else {
			
			$title = wp_title($sep = '', $display = 0);
		}	
		
		return $title;
	}
	
	function setPageTitleCmd() {
		
		$this->cmds[] = sprintf("owa_cmds.push([ 'setPageTitle', '%s' ]);", esc_html( $this->getPageTitle() ) );
	}
	
	function setUserNameCmd() {
		
		$current_user = wp_get_current_user();
		$this->cmds[] = sprintf("owa_cmds.push([ 'setUserName', '%s' ]);", esc_html( $current_user->user_login ) );
	}
	
	public static function generateSiteId() {
		
		return md5( get_option( 'siteurl' ) );
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
		
		$this->cmds[] = "owa_cmds.push( ['setDebug', true ] );";
	}
	
	function setSiteIdCmd() {
		
		$this->cmds[] = sprintf("owa_cmds.push( ['setSiteId', '%s' ] );", $this->getOption('siteId') );
	}
	
	function setPageTypeCmd() {
		
		$this->cmds[] = sprintf("owa_cmds.push( ['setPageType', '%s' ] );", $this->getPageType() );
	}
	
	function setTrackPageViewCmd() {
		
		$this->cmds[] = "owa_cmds.push( ['trackPageView'] );";
	}
	
	function setTrackClicksCmd() {
		
		$this->cmds[] = "owa_cmds.push( ['trackClicks'] );";
	}
	
	function setTrackDomstreamsCmd() {
		
		$this->cmds[] = "owa_cmds.push( ['trackDomStream'] );";
	}
	
	function cmdsToString() {
		
		$out = '';
		
		foreach ( $this->cmds as $cmd ) {
			
			$out .= $cmd . " \n";	
		}
		
		return $out;
	}
	
	function isOwaReadyToTrack() {
		
		
		if ( $this->getOption( 'owaEndpoint' ) && $this->getOption( 'apiKey' ) && $this->getOption('siteId') ) {
			
			return true;
		}
		
	}
	
	function makeOwaInstanceValidationHash() {
		
		
	}
	
	// init the OWA SDK
	function initOwaSdk() {
					
		if( empty( $this->owaSdk ) ) {
			
			if ( $this->getOption( 'owaEndpoint' ) && $this->getOption( 'apiKey' ) ) {
		
				$config = [
					
					//'cookie_domain' => 'your.domain.com',
					'api_key'		=> $this->getOption('apiKey'),	
				    'instance_url'  => $this->getOption('owaEndpoint')
				];
				
				$sdk = new OwaSdk\sdk( $config );
				
				$this->owaSdk = $sdk;
			}
		}
	}
	
			
	/**
	 * Insert Tracking Tag
	 *
	 * Adds javascript tracking tag int <head> of all pages.
	 * 
	 */
	function insertTrackingTag() {
			
		if (is_admin()) {
			
			$screen = get_current_screen();
			///print_r($screen);	
			if ( in_array( $screen->id, [ 'owa_page_owa-analytics', 'owa_page_owa-wordpress' ] ) ) {
				
				return;
			}
		}
				
		// Don't log if the page request is a preview - Wordpress 2.x or greater
		if ( function_exists( 'is_preview' ) ) {
			
			if ( is_preview() ) {
				
				return;
			}
		}
		
		// dont log customizer previews either.
		if ( function_exists( 'is_customize_preview' ) ) {
			
			if ( is_customize_preview() ) {
				
				return;
			}
		}
		
		// dont log requests for admin interface pages
		if ( ! $this->getOption( 'trackAdminPages') && function_exists( ' is_admin' ) && is_admin() ) {
			
			return;
		}
		
		// set user name in tracking for names users with wp-admin accounts
		if ( $this->getOption( 'trackNamedUsers') ) {
			
			$this->setUserNameCmd();
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
		
		// convert cmds to string and pass to tracking tag template	
		$options = $this->cmdsToString();
		
		_e( sprintf( $this->getTrackerSnippetTemplate(), $options ) );
		
	}	
	
	function getTrackerSnippetTemplate() {
		
		$tag =  "<!-- Open Web Analytics --> \n";
		$tag .= '<script type="text/javascript">' . "\n";
		$tag .= "var owa_cmds = owa_cmds || []; \n";
		
		$base_url = esc_url( $this->getOption('owaEndpoint') );
		
		$tag .= "var owa_baseUrl = '$base_url'; \n";
		
		$tag .= "%s";
		
		$tag .= "
		(function() {var _owa = document.createElement('script'); _owa.type = 'text/javascript'; _owa.async = true;
		owa_baseUrl = ('https:' == document.location.protocol ? window.owa_baseSecUrl || owa_baseUrl.replace(/http:/, 'https:') : owa_baseUrl );
		_owa.src = owa_baseUrl + 'modules/base/js/owa.tracker-combined-min.js';
		var _owa_s = document.getElementsByTagName('script')[0]; _owa_s.parentNode.insertBefore(_owa, _owa_s);}()); 
		
		\n ";
		
		$tag .= "</script> \n
		<!-- End Open Web Analytics --> \n
		";
		
		return $tag;
	}
	
		
	/**
	 * Adds tracking source param to links in feeds
	 *
	 * @param string $link
	 * @return string
	 */
	function decorateFeedEntryPermalink($link) {
		
		// check for presence of '?' which is not present under URL rewrite conditions
	
		if ( $this->getOption( 'track_feed_links' ) ) {
		
			if ( strpos($link, "?") === false ) {
				// add the '?' if not found
				$link .= '?';
			}
			
			// setup link template
			$link_template = "%s&amp;%s=%s&amp;%s=%s";
				
			return sprintf($link_template,
						   $link,
						   'owa_medium',
						   $this->getOption( 'feed_tracking_medium' ),
						   $this->getOption( 'feed_subscription_param' ),
						   esc_attr( get_query_var( $this->getOption( 'feed_subscription_param' ) ) )
			);
		}
	}
	
	/**
	 * Wordpress filter function adds a GUID to the feed URL.
	 *
	 * @param array $binfo
	 * @return string $newbinfo
	 */
	function decorateFeedSubscriptionLink( $binfo ) {
		
		$is_feed = strpos($binfo, "feed=");
		
		if ( $is_feed && $this->getOption( 'track_feed_links' ) ) {
			
			$guid = crc32(getmypid().microtime());
		
			$newbinfo = $binfo . "&amp;" . $this->getOption('feed_subscription_param') . "=" . $guid;
		
		} else { 
			
			$newbinfo = $binfo;
		}
		
		return $newbinfo;
	}
	
	// create a new tracked site.
	function createTrackedSiteForNewBlog($blog_id, $user_id, $domain, $path, $site_id, $meta) {
	
		$owa = $this->getOwaTrackerInstance();
		// @todo move this to REST API call when it's ready.
		// $sm = owa->siteManager(...);
		//$sm->createNewSite( $domain, $domain, '', ''); 
	}
	
	
	/**
	 * New Blog Action Tracker
	 */
	function trackNewBlogAction( $blog_id, $user_id, $domain, $path, $site_id ) {
	
		$owa = $this->getOwaTrackerInstance();
		$owa->trackAction('Blog Created', 'WordPress', $domain);
	}
	
	/**
	 * Edit Post Action Tracker
	 */
	function trackedPostEditAction( $post_id, $post ) {
		
		// we don't want to track autosaves...
		if( wp_is_post_autosave( $post ) ) {
			
			return;
		}
		
		$owa = $this->getOwaTrackerInstance();
		$label = $post->post_title;
		$owa->trackAction($post->post_type.' edited', 'WordPress', $label );
	}
	
	/**
	 * Post Action Tracker
	 *
	 * Trackes new and edited post actions. Including custom post types.
	 */
	function trackPostAction( $new_status, $old_status, $post ) {
		
		$action_name = '';
		
		// we don't want to track autosaves...
		if(wp_is_post_autosave( $post ) ) {
			
			return;
		}
		
		// or drafts
		if ( $new_status === 'draft' && $old_status === 'draft' ) {
			
			return;
		
		} 
		
		// set action label
		if ( $new_status === 'publish' && $old_status != 'publish' ) {
			
			$action_name = $post->post_type.' publish';
		
		} elseif ( $new_status === $old_status ) {
		
			$action_name = $post->post_type.' edit';
		}
		
		// track action
		if ( $action_name ) {	
		

			$owa = $this->getOwaTrackerInstance();
			self::debug(sprintf("new: %s, old: %s, post: %s", $new_status, $old_status, print_r($post, true)));
			$label = $post->post_title;
			
			$owa->trackAction($action_name, 'WordPress', $label);
		}
	}
	
	/**
	 * Edit Attachment Action Tracker
	 */
	function trackAttachmentEditAction( $post_id ) {
	
		$owa = $this->getOwaTrackerInstance();
		$post = get_post( $post_id );
		$label = $post->post_title;
		$owa->trackAction('Attachment Edit', 'WordPress', $label);
	}
	
	/**
	 * New Attachment Action Tracker
	 */
	function trackAttachmentCreatedAction( $post_id ) {
	
		$owa = $this->getOwaTrackerInstance();
		$post = get_post($post_id);
		$label = $post->post_title;
		$owa->trackAction('Attachment Created', 'WordPress', $label);
	}
	
	/**
	 * User Registration Action Tracker
	 */
	function trackUserRegistrationAction( $user_id ) {
		
		$owa = $this->getOwaTrackerInstance();
		$user = get_userdata($user_id);
		if (!empty($user->first_name) && !empty($user->last_name)) {
			$label = $user->first_name.' '.$user->last_name;	
		} else {
			$label = $user->display_name;
		}
		
		$owa->trackAction('User Registration', 'WordPress', $label);
	}
	
	/**
	 * User Login Action Tracker
	 */
	function trackUserLoginAction( $user_id ) {
	
		$owa = $this->getOwaTrackerInstance();
		$label = $user_id;
		$owa->trackAction('User Login', 'WordPress', $label);
	}
	
	/**
	 * Profile Update Action Tracker
	 */
	function trackUserProfileUpdateAction( $user_id, $old_user_data = '' ) {
	
		$owa = $this->getOwaTrackerInstance();
		$user = get_userdata($user_id);
		if (!empty($user->first_name) && !empty($user->last_name)) {
			$label = $user->first_name.' '.$user->last_name;	
		} else {
			$label = $user->display_name;
		}
		
		$owa->trackAction('User Profile Update', 'WordPress', $label);
	}
	
	/**
	 * Password Reset Action Tracker
	 */
	function trackPasswordResetAction( $user ) {
		
		$owa = $this->getOwaTrackerInstance();
		$label = $user->display_name;
		$owa->trackAction('User Password Reset', 'WordPress', $label);
	}
	
	/**
	 * Trackback Action Tracker
	 */
	function trackTrackbackAction( $comment_id ) {
		
		$owa = $this->getOwaTrackerInstance();
		$label = $comment_id;
		$owa->trackAction('Trackback', 'WordPress', $label);
	}
	
	function trackCommentAction( $id, $comment_data = '' ) {

		if ( $comment_data === 'approved' || $comment_data === 1 ) {
	
			$owa = $this->getOwaTrackerInstance();
			$label = '';
			$owa->trackAction('Comment', 'WordPress', $label);
		}
	}
	
	function trackCommentEditAction( $new_status, $old_status, $comment ) {
		
		if ($new_status === 'approved') {
			
			if (isset($comment->comment_author)) {
				
				$label = $comment->comment_author; 
			
			} else {
			
				$label = '';
			}
			
			$owa = $this->getOwaTrackerInstance();
			$owa->trackAction('Comment Edit', 'WordPress', $label);
		}
	}
	
	// Tracks feed requests
	
	function trackFeedRequest() {
		
		if ( is_feed() && $this->getOption( 'trackFeeds') ) {
				
			self::debug('Tracking WordPress feed request');			
			
			$owa = $this->getOwaTrackerInstance();
				
			$event = $owa->makeEvent();
			// set event type
			$event->setEventType( 'base.feed_request' );
			// determine and set the type of feed
			$event->set( 'feed_format', esc_attr( get_query_var( 'feed' ) ) );
			$event->set( 'feed_subscription_id', esc_attr( get_query_var( $this->getOption( 'feed_subscription_param' ) ) ) );
			//$event->set( 'feed_subscription_id', $_GET['owa_sid'] );
			// track
			$owa->trackEvent( $event );		
		}
	}
	
	public function registerOptions() {		
		
		$settings = array(
		
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
					'type'									=> 'text',
					'title'									=> 'API Key',
					'page_name'								=> 'owa-wordpress',
					'section'								=> 'general',
					'description'							=> 'API key for accessing your OWA instance.',
					'label_for'								=> 'OWA API Key',
					'length'								=> 70,
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
					'description'							=> 'The URL of your OWA instance (i.e. http://www.mydomain.com/path/to/owa/). This should be the same as the OWA_PUBLIC_URL of your OWA server instance as defined in its owa-config.php file.',
					'label_for'								=> 'OWA Endpoint',
					'length'								=> 70,
					'error_message'							=> ''		
				)				
			),
			
			'siteId'				=> array(
			
				'default_value'							=> '',
				'field'									=> array(
					'type'									=> 'select',
					'title'									=> 'Website ID',
					'page_name'								=> 'owa-wordpress',
					'section'								=> 'general',
					'description'							=> 'Select the ID of the website you want to track. New tracked websites can be added via the OWA server admin interface.',
					'label_for'								=> 'Tracked website ID',
					'length'								=> 90,
					'error_message'							=> '',
					'options'								=> []		
				)				
			),

			
			'trackClicks'				=> array(
			
				'default_value'							=> true,
				'field'									=> array(
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
					'type'									=> 'boolean',
					'title'									=> 'Track Domstreams',
					'page_name'								=> 'owa-wordpress',
					'section'								=> 'tracking',
					'description'							=> 'Record visitor mouse movements on each web page.',
					'label_for'								=> 'Record mouse movements',
					'error_message'							=> 'You must select On or Off.'		
				)				
			),
			
			'trackFeeds'				=> array(
			
				'default_value'							=> false,
				'field'									=> array(
					'type'									=> 'boolean',
					'title'									=> 'Track Feed Requests',
					'page_name'								=> 'owa-wordpress',
					'section'								=> 'tracking',
					'description'							=> 'Track requests for RSS/ATOM syndication feeds.',
					'label_for'								=> 'Track RSSS/ATOM Feeds',
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
					'description'							=> 'Track user names and email addresses of WordPress admin users.',
					'label_for'								=> 'Track named users',
					'error_message'							=> 'You must select On or Off.'		
				)				
			),
			
			'trackAdminPages'				=> array(
			
				'default_value'							=> false,
				'field'									=> array(
					'type'									=> 'boolean',
					'title'									=> 'Track WP Admin Pages',
					'page_name'								=> 'owa-wordpress',
					'section'								=> 'tracking',
					'description'							=> 'Track WordPress admin interface pages (/wp-admin...)',
					'label_for'								=> 'Track WP admin pages',
					'error_message'							=> 'You must select On or Off.'		
				)				
			),
			
			'trackAdminActions'				=> array(
			
				'default_value'							=> false,
				'field'									=> array(
					'type'									=> 'boolean',
					'title'									=> 'Track WP Admin Actions',
					'page_name'								=> 'owa-wordpress',
					'section'								=> 'tracking',
					'description'							=> 'Track WordPress admin actions such as login, new posts, edits, etc.',
					'label_for'								=> 'Track WP admin actions',
					'error_message'							=> 'You must select On or Off.'		
				)				
			),
						
			'debug'				=> array(
			
				'default_value'							=> false,
				'field'									=> array(
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
	
		return $settings;
	}

	public function registerSettingsPages() {
		
		$pages = array(
		
			'owa-wordpress'			=> array(
				
				'parent_slug'					=> 'owa-wordpress',
				'is_top_level'					=> true,
				'top_level_menu_title'			=> 'OWA',
				'title'							=> 'Open Web Analytics',
				'menu_title'					=> 'Tracking Settings',
				'required_capability'			=> 'manage_options',
				'menu_slug'						=> 'owa-wordpress-settings',
				'menu-icon'						=> 'dashicons-chart-pie',
				'description'					=> 'Settings for integrating WordPress with an existing Open Web Analytics (OWA) server instance.',
				'sections'						=> array(
					'general'						=> array(
						'id'							=> 'general',
						'title'							=> 'General',
						'description'					=> 'These settings control the connection between WordPress and your Open Web Analytics (OWA) server instance.'
					),
					'tracking'						=> array(
						'id'							=> 'tracking',
						'title'							=> 'Tracking',
						'description'					=> 'These settings control the tracking events that will be sent to your Open Web Analytics (OWA) server instance.'
					),
					'advanced'						=> array(
						'id'							=> 'advanced',
						'title'							=> 'Advanced',
						'description'					=> 'These are advanced integration settings that are seldom used. Do not change these unless you know what you are doing. ;)'
					)
				)
			)
		);
		
	
		$pages['owa-analytics']	= array(
			
			'parent_slug'					=> 'owa-wordpress',
			'title'							=> 'OWA Analytics',
			'menu_title'					=> 'Analytics',
			'required_capability'			=> 'manage_options',
			'menu_slug'						=> 'owa-analytics',
			'description'					=> 'OWA Analytics dashboard.',
			'render_callback'				=> array( $this, 'pageController')
		);
		
		
		return $pages;
	}
	
	public static function debug( $msg, $exit = false ) {
		
		if ( defined( 'WP_DEBUG' ) && defined( 'WP_DEBUG_LOG') && WP_DEBUG == true && WP_DEBUG_LOG == true) {
			
			if (is_array( $msg) || is_object( $msg ) ) {
				
				$msg = print_r( $msg , true);
			}

			error_log( $msg );
			
			if ( $exit ) {
				
				exit;
			}
		}
	}
	
	function owaRemoteGet( $params ) {
		
		if ( $this->getOption('apiKey') && $this->getOption('owaEndpoint') ) {
			
			$params['owa_apiKey'] = $this->getOption('apiKey');
			$ret =  wp_remote_get( $this->getOption('owaEndpoint').'api/?' . build_query( $params ) );	
			self::debug('Got response from OWA endpoint' );
			if ( ! is_wp_error( $ret ) ) {
				
				$body = wp_remote_retrieve_body( $ret );
				$body = json_decode($body);
				return $body;
				
			} else {
				
				self::debug('REST call from WordPress Failed with params: '. print_r($params, true) );
			}
		}
	}
	
	// @todo renovate this to use an SDK method
	function getSitesFromOwa( $sites ) {
		
		$params = ['owa_module' => 'base', 'owa_version' => 'v1', 'owa_do' => 'sites' ];
		
		$sites = $this->owaRemoteGet( $params );
			
		$list = [];
		
		foreach ( $sites->data as $site) {
			
			$list[ $site->properties->site_id->value ] = ['label' => sprintf('%s (%s)', $site->properties->site_id->value, $site->properties->domain->value), 'siteId' => $site->properties->site_id->value ];
		}
		
		return $list;
		
	}
			
	// get instance of OWA's tracker
	public function getOwaTrackerInstance() {
		
		$tracker = $this->owaSdk->createTracker();
	
		$tracker->setSiteId( self::generateSiteId() );
	
		return $tracker;
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
		
		_e( wp_kses( $out, $allowed_html ) );
		
	}
		
	/**
	 * Translate WordPress to OWA Authentication Roles
	 *
	 * @param $roles	array	array of WP roles
	 * @return	string
	 */ 
	static function translateAuthRole( $roles ) {
		
		if (!empty($roles)) {
		
			if (in_array('administrator', $roles)) {
				$owa_role = 'admin';
			} elseif (in_array('editor', $roles)) {
				$owa_role = 'viewer';
			} elseif (in_array('author', $roles)) {
				$owa_role = 'viewer';
			} elseif (in_array('contributor', $roles)) {
				$owa_role = 'viewer';
			} elseif (in_array('subscriber', $roles)) {
				$owa_role = 'everyone';
			} else {
				$owa_role = 'everyone';
			}
			
		} else {
			$owa_role = 'everyone';
		}
		
		return $owa_role;
	}

}

?>