<?php 
	
/////// settings class ///////////
namespace owaWp\settings;

use owaWp\util;


class page {
	
	public $page_slug;
	
	public $package;
	
	public $module;
	
	public $ns;
	
	public $name;
	
	public $option_group_name; // owa-package-module-groupname
	
	public $fields;
	
	public $properties;
	
	public $options;
	
	public function __construct( $params, $options ) {
		

		$defaults = array(
			
			'ns'					=> 'owa_wp',
			'package'				=> '',
			'module'				=> '',
			'page_slug'				=> '',
			'name'					=> '',
			'title'					=> 'Placeholder Title',
			'description'			=> 'Placeholder description.',
			'sections'				=> array(),
			'required_capability'	=> 'manage_options'	
		
		);
		
		$params = util::setDefaultParams( $defaults, $params );
		$this->options = $options;
		$this->ns 				= $params['ns'];
		$this->package 			= $params['package'];
		$this->module 			= $params['module'];
		$this->name 			= $params['name'];
	
		if ( ! $params['page_slug'] ) {
						
			$params['page_slug'] = $this->generatePageSlug();		
		}
		
		$this->page_slug = $params['page_slug'];
		
		$this->default_options = array();
		
		$this->properties = $params;
				
		util::addFilter('owa_wp_settings_field_types', array( $this, 'registerFieldTypes'), 10, 1);
		
		// add error display callback.
		add_action( 'admin_notices', array( $this, 'displayErrorNotices' ) );
	}
	
	public function registerFieldTypes( $types = array() ) {
		
		
		$types['text'] = 'owaWp\settings\fields\text';
		
		$types['boolean'] = 'owaWp\settings\fields\boolean_field';
			
		$types['integer'] = 'owaWp\settings\fields\integer_field';
		
		$types['boolean_array'] = 'owaWp\settings\fields\booleanarray';
		
		$types['on_off_array'] = 'owaWp\settings\fields\onoffarray';
		
		$types['comma_separated_list'] = 'owaWp\settings\fields\commaseparatedlist';
		
		$types['select'] = 'owaWp\settings\fields\select';
		
		$types['textarea'] = 'owaWp\settings\fields\textarea';
		
		$types['url'] = 'owaWp\settings\fields\url';
		
		return $types;
	}
	
	public function get( $key ) {
		
		if (array_key_exists( $key, $this->properties ) ) {
			
			return $this->properties[ $key ];
		} 
	}
	
	public function generatePageSlug() {
		
		return sprintf( '%s-%s', $this->ns, $this->name );
	}
	
	public function registerSettings() {

			register_setting( $this->getOptionGroupName(), 'owa_wp', array( $this, 'validateAndSanitize' ) );
	}
	
	public function validateAndSanitize( $options ) {
	
		$sanitized = '';
		
		if ( is_array( $options ) ) {	
			
			$sanitized = array();
			
			foreach ( $this->fields as $k => $f ) {
				
				// if the option is present
				if ( array_key_exists( $k, $options ) ) {	
					
					$value = $options[ $k ] ;
					
					// check if value is required.
					if ( ! $value && $f->isRequired() ) {
						
						$f->addError( $k, $f->get('label_for'). ' field is required' );
						continue;
					}
					
					// sanitize value
					$value = $f->sanitize( $options[ $k ] );
					
					// validate value. Could be empty at this point.
					if ( $f->isValid( $value ) ) {
						//sanitize
						$sanitized[ $k ] =  $value;
					}
					
				} else {
				
					// set a false value in case it's a boollean type
					$sanitized[ $k ] = $f->setFalseValue();
				}
			}			
		}
		
		return $sanitized;
	}
	
	public function getOptionGroupName() {
		
		return sprintf( '%s_group', $this->get('page_slug') );
	}
	
	//
	 //Register a Settings Section with WordPress.
	 //
	 //
	public function registerSection( $params ) {
		
		// todo: add in a class type lookup here to use a custom section object
		// so that we can do custom rendering of section HTML if we 
		// ever need to.
		// $section = somemaplookup( $params['type']);
		
		$section = new section($params);
		
		// Store the section object in case we need it later or want to inspect
		$this->sections[ $section->get( 'id' ) ] = $section;
		
		// register the section with WordPress
		add_settings_section( $section->get('id'), $section->get('title'), $section->get('callback'), $this->page_slug );
	}
	
	public function registerField( $key, $params ) {
		
		// Add to params array
		// We need to pack params because ultimately add_settings_field 
		// can only pass an array to the callback function that renders
		// the field. Sux. wish it would accept an object...
			
		$params['id'] = $key;
		$params['package'] = $this->package;
		$params['module'] = $this->module;
		
		// make field object based on type
		
		$types = apply_filters( 'owa_wp_settings_field_types', array() );
		
		$field = new $types[ $params['type'] ]($params, $this->options);
		
		if ( $field ) {
			// park this field object for use later by validation and sanitization 			
			$this->fields[ $key ] = $field;
				
			// register label formatter callback
			$callback = $field->get( 'value_label_callback' );
			if ( $callback ) {
				util::addFilter( $field->get( 'id' ) . '_field_value_label', $callback, 10, 1 );
			}
			// add setting to wordpress settings api
			add_settings_field( 
				$key, 
				$field->get( 'title' ), 
				array( $field, 'render'), 
				$this->page_slug, 
				$field->get( 'section' ), 
				$field->getProperties() 
			); 
		} else {
			
			error_log("No field of type {$params['type']} registered.");
		}
	}
		
	public function renderPage() {
				
		if ( ! current_user_can( $this->get('required_capability') ) ) {
    
        	wp_die(__( 'You do not have sufficient permissions to access this page!' ) );
		}
		
		wp_enqueue_script('jquery','','','',true);
		wp_enqueue_script('jquery-ui-core','','','',true);
		wp_enqueue_script('jquery-ui-tabs','','','',true);

		$allowed_html = [
			
		    'div'       => [
			    
		        'class'  	=> [],
		        'id'		=> [],
		        'style'		=> []
		    ],
		    'a'			=> [
			    'href'		=> [],
			    'target'	=> []
		    ],
		    
		    'form'		=> [
				'class'		=> [],
				'id'		=> [],
				'action'	=> [],
				'method'	=> []
		    ],
		    'h2'     => [],
		    'em'     => [],
		    'br'	 => [],
		    'p'		 => [
			    'class'		=> []
			],
			'input'		=> [
				
				'name'		=> [],
				'type'		=> [],
				'class'		=> [],
				'value'		=> [],
				'checked'	=> [],
				'size'		=> [],
				'name'		=> []
				
			]
		    
		];

		$out = '';
		
		$out .= '<div class="wrap">';
		$out .=	'<div class="icon32" id="icon-options-general"><br></div>';
		$out .=	sprintf('<h2>%s</h2>', $this->get( 'title') );
		$out .=	$this->get('description');
		
		_e( wp_kses( $out, $allowed_html ) );
		
		if ( $this->fields ) {
			
			settings_errors();
			
			$out =	sprintf('<form id="%s" action="options.php" method="post">', $this->page_slug);
			
			_e( wp_kses( $out, $allowed_html ) );
			
			settings_fields( $this->getOptionGroupName() );
			
			$this->doTabbedSettingsSections( $this->get('page_slug') );
			
			$out =	'<p class="submit">';
			$out .=	sprintf('<input name="Submit" type="submit" class="button-primary" value="%s" />', 'Save Changes' );
			$out .=	'</p>';
			$out .=	'</form>';
			
			_e( wp_kses( $out, $allowed_html ) );
		}

		$out =    '</div>';
		_e( wp_kses( $out, $allowed_html ) );
	}
	
	///
	 // Outputs Settings Sections and Fields
	 //
	 // Sadly this is a replacement for WP's do_settings_sections template function
	 // because it doesn't allows for filtered output which we need for adding tabs.
	 //
	 // var $page	string	name of the settings page.
	 //
	public function doTabbedSettingsSections( $page ) {
		
		
		global $wp_settings_sections, $wp_settings_fields;
 
	    if ( ! isset( $wp_settings_sections[$page] ) ) {
	    
	        return;
		}
		
		
		$allowed_html = [
			
		    'div'       => [
			    
		        'class'  	=> [],
		        'id'		=> [],
		        'style'		=> []
		    ],
		    
		    'ul'		=> [
			  				
			  	'style'		=> []  
		    ],
		    
		    'li'		=> [
			    
			    'class'		=> [],
			    'style'		=> []
			  
		    ],
		    
		    'a'			=> [
			    
			    'href'		=> [],
			    'class'		=> []
		    ],
		    
		    'form'		=> [
				'class'		=> [],
				'id'		=> [],
				'action'	=> [],
				'method'	=> []
		    ],
		    
		    'h2'     => [
			    	
			    	'class'		=> []
		    ],
		    'h3'	 => [],
		    'em'     => [],
		    'br'	 => [],
		    
		    'p'		 => [
			    'class'		=> []
			],
			
			'input'		=> [
				
				'name'		=> [],
				'type'		=> [],
				'class'		=> [],
				'value'		=> []
				
			],
			
			'table'		=> [
				
				'class'		=> []
			]
		    
		];
		
		$out = '';

		
		$out .= '<div class="owa_wp_admin_tabs">';
		$out .= '<h2 class="nav-tab-wrapper">';
		$out .= '<ul style="padding:0px;margin:0px;">';
		
		foreach ( (array) $wp_settings_sections[$page] as $section ) {
			
			$out .=  sprintf('<li class="nav-tab" style=""><a href="#%s" class="%s">%s</a></li>', $section['id'], '', $section['title']);
			
		}
		
		$out .= '</ul>';
		$out .= '</h2>';
		
		_e( wp_kses( $out, $allowed_html ) );
		
	    foreach ( (array) $wp_settings_sections[$page] as $section ) {
	    	
	    	$out = sprintf( '<div id="%s">', $section['id'] );
	       
	        if ( $section['title'] ) {
		        
	            $out .= "<h3>{$section['title']}</h3>\n";
			}
			
			_e( wp_kses( $out, $allowed_html ) );	
			
	        if ( $section['callback'] ) {
		        
	            call_user_func( $section['callback'], $section );
			}
			
	        if ( ! isset( $wp_settings_fields ) || !isset( $wp_settings_fields[$page] ) || !isset( $wp_settings_fields[$page][$section['id']] ) ) {
	            
	            continue;
	        }
	        
	        $out = '<table class="form-table">';
	        
	        _e( wp_kses( $out, $allowed_html ) );
	        
	        
	        do_settings_fields( $page, $section['id'] );
	        
	        $out = '</table>';
	        $out .= '</div>';
	        
	        _e( wp_kses( $out, $allowed_html ) );
	    }
	    
	    $out = '</div>';
	    
	    $out .= '<script>';
	    $out .= "
					jQuery(function() { 
					
						jQuery( '.owa_wp_admin_tabs' ).tabs({
							 
							create: function(event, ui) {
								
								// CSS hackery to match up with WP built in tab styles.
								jQuery(this).find('li a').css({'text-decoration': 'none', color: 'grey'});
								ui.tab.find('a').css({color: 'black'});
								ui.tab.addClass('nav-tab-active');
								// properly set the form action to correspond to active tab
								// in case it is resubmitted
								target = jQuery('.owa_wp_admin_tabs').parent().attr('action');
								new_target = target + '' + window.location.hash;
								jQuery('.owa_wp_admin_tabs').parent().attr('action', new_target);
							},
							
							activate: function(event, ui) {
								
								// CSS hackery to match up with WP built in tab styles.
								ui.oldTab.removeClass('nav-tab-active');
								ui.oldTab.find('a').css({color: 'grey'});
								ui.newTab.addClass('nav-tab-active');
								ui.newTab.find('a').css({color: 'black'});
								
								// get target tab nav link.
								new_tab_anchor = ui.newTab.find('a').attr('href');
								// set the url anchor
								window.location.hash = new_tab_anchor;
								// get current action attr of the form
								target = jQuery('.owa_wp_admin_tabs').parent().attr('action');
								// clear any existing hash from form target
								if ( target.indexOf('#') > -1 ) {
								
									pieces = target.split('#');
									new_target = pieces[0] + '' + new_tab_anchor;
									
								} else {
								
									new_target = target + '' + new_tab_anchor;
								}
								// add the anchor hash to the form action so that
								// the user returns to the correct tab after submit
								jQuery('.owa_wp_admin_tabs').parent().attr('action', new_target);
								
							}
						});
					});
					
		";
		$out .= '</script>';
		
		_e( $out );
	}
	
	public function displayErrorNotices() {
	
    	settings_errors( $this->page_slug );
	}
}

?>