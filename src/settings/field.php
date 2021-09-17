<?php

namespace owaWp\settings;

use owaWp\util;

class field {
	
	public $id;
	
	public $package;
	
	public $module;
	
	public $properties;
	
	public $options;
	
	public $allowed_html;
	
	//
	 // name of the validator callback to be used
	 //
	public $validator_callback;
	
	//
	 // name of the santizer callback to be used
	 //
	public $santizer_callback;
	
	public function __construct( $params = '', $options ) {
		
		$defaults = array(
			
			'title'			=> 'Sample Title',
			'type'			=> 'text',
			'section'		=> '',
			'default_value'	=> '',
			'dom_id'		=> '',
			'name'			=> '',
			'id'			=> '',
			'package'		=> '',
			'module'		=> '',
			'required'		=> false,
			'label_for'		=> ''
			
		);
		
		$this->allowed_html = [
			
			'label'		=> [
				'for'		=> []
			],
			'input'		=> [
				
				'class'		=> [],
				'id'		=> [],
				'value'		=> [],
				'type'		=> [],
				'checked'	=> [],
				'size'		=> [],
				'name'		=> []
			],
			'p'		=> [
				
				'class'		=> []
			],
			
			'select'		=> [
				
				'id'		=> [],
				'name'		=> []
			],
			
			'option'		=> [
				
				'value'		=> [],
				'selected'	=> []
			],
			
			'textarea'		=> [
				
				'name'		=> [],
				'rows'		=> [],
				'cols'		=> []
				
			]
		];
		
		$params = util::setDefaultParams( $defaults, $params );
		
		$this->options = $options;
		
		$this->package 		= $params['package'];
		$this->module		= $params['module'];
		$this->id 			= $params['id'];
		$this->properties 	= $params;
		
		$this->properties['name'] = $this->setName();
		$this->properties['dom_id'] = $this->setDomId();
	}
	
	public function get( $key ) {
		
		if (array_key_exists( $key, $this->properties) ) {
			
			return $this->properties[ $key ];
		}
	}
	
	public function getProperties() {
		
		return $this->properties;
	}
	
	public function setName( ) {
		
		return sprintf( 
			'%s[%s]', 
			'owa_wp', 
			$this->id
		);
	}
	
	public function render( $field ) {
		
		return false;
	}	
	
	public function setDomId( ) {
		
		return sprintf( 
			'%s_%s', 
			'owa_wp', 
			$this->id
		);
	}	
	
	public function sanitize( $value ) {
		
		return $value;
	}
	
	public function isValid( $value ) {
		
		return true;
	}
		
	public function addError( $key, $message ) {
		
		add_settings_error(
			$this->get( 'id' ),
			$key,
			$message,
			'error'
		);
		
	}
	
	public function setFalseValue() {
		
		return 0;
	}
	
	public function isRequired() {
		
		return $this->get('required');
	}
	
	public function getErrorMessage() {
		
		return $this->get('error_message');
	}
	
	public function out( $string ) {
		
		_e( wp_kses( $string, $this->allowed_html ) );
	}
}

	
?>