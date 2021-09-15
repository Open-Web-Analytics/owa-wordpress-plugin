<?php

namespace owaWp\settings\fields;

use owaWp\settings\field;

class booleanarray extends field {

	public function render ( $attrs ) {
		
		// get persisted options
		$values = $this->options[ $attrs['id'] ];
		
		// get the default options
		//$defaults = pp_api::getDefaultOption( $this->package, $this->module, $attrs['id'] );
		
		if ( ! $values ) {
		
			$values = array();
		}
	
		echo sprintf('<p class="description">%s</p>', $attrs['description']);
		
		foreach ( $defaults as $dvalue ) {
			
			$checked = '';
			$check = in_array( trim($dvalue), $values, true ); 
				
			if ( $check ) {
				
				$checked = 'checked="checked"';
			}
			
			$callback = $this->get('value_label_callback');
				
			$dvalue_label = apply_filters( $this->get('id').'_field_value_label', $dvalue );
			
			echo sprintf(
				'<p><input name="%s[]" id="%s" value="%s" type="checkbox" %s> %s</p>', 
				esc_attr( $attrs['name'] ), 
				esc_attr( $attrs['dom_id'] ),
				esc_attr( $dvalue ),
				$checked,
				esc_html( $dvalue_label )
			);
		}
	}
	
	public function setFalseValue() {
		
		return array();
	}
}


	
?>