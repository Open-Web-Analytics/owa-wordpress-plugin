<?php

namespace owaWp\settings\fields;

use owaWp\settings\field;

class onoffarray extends field {

	public function render ( $attrs ) {
		
		// get persisted options
		$values = $this->options[ $attrs['id'] ];
		
		// get the default options
		//$defaults = pp_api::getDefaultOption( $this->package, $this->module, $attrs['id'] );
		
		$options = $attrs['options'];
		
		if ( ! $values ) {
		
			$values = $defaults;
		}
	
		echo sprintf('<p class="description">%s</p>', $attrs['description']);
		
		foreach ( $options as $k => $label ) {
			
			$checked = '';
			$check = false;
			
			if ( in_array( trim( $k ), array_keys( $values ), true ) && $values[ trim( $k ) ] == true ) {
				
				$check = true;
			} 
				
			$on_checked = '';
			$off_checked = '';
			
			if ( $check ) {
				
				$on_checked = 'checked=checked';
				
			} else {
				
				$off_checked = 'checked';
			}
			
			//$callback = $this->get('value_label_callback');
				
			//$dvalue_label = apply_filters( $this->get('id').'_field_value_label', $ovalue );
			
			echo sprintf(
				'<p>%s: <label for="%s_on"><input class="" name="%s[%s]" id="%s_on" value="1" type="radio" %s> On</label>&nbsp; &nbsp; ', 
				$label,
				esc_attr( $attrs['dom_id'] ),
				esc_attr( $attrs['name'] ), 
				esc_attr( $k ),
				esc_attr( $attrs['dom_id'] ),
				$on_checked
			);
			
			echo sprintf(
				'<label for="%s_off"><input class="" name="%s[%s]" id="%s" value="0" type="radio" %s> Off</label></p>', 
				
				esc_attr( $attrs['dom_id'] ),
				esc_attr( $attrs['name'] ), 
				esc_attr( $k ),
				esc_attr( $attrs['dom_id'] ),
				$off_checked
			);
		}
	}
	
	public function setFalseValue() {
		
		return array();
	}
}

	
?>