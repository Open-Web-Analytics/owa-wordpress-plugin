<?php
	
namespace owaWp\settings;

use owaWp\util;

class section {
	
	public $properties;
	
	public function __construct( $params ) {
	
		$this->properties = array();
		
		$defaults = array(
			
			'id'			=> '',
			'title'			=> '',
			'callback'		=> array( $this, 'renderSection'),
			'description'	=> ''
		);
		
		$this->properties = util::setDefaultParams( $defaults, $params );
	}
	
	public function get( $key ) {
		
		if ( array_key_exists( $key, $this->properties ) ) {
			
			return $this->properties[ $key ];
		}
	}
	
	//
	 // Renders the html of the section header
	 //
	 // Callback function for 
	 //
	 // wordpress passes a single array here that contains ID, etc..
	 //
	public function renderSection( $arg ) {
	
		_e( esc_html( $this->get('description') ) );
	}
}

?>