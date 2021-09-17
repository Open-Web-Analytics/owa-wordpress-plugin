<?php

namespace owaWp\settings\fields;

use owaWp\settings\field;

class boolean_field extends field {
	
	public function isValid( $value ) {
	
		$value = intval($value);
		
		if ( $value === 1 || $value === 0 ) {
			
			return true;
		} else {
		
			$this->addError( $this->get('dom_id'), $this->get('label_for') . ' ' . owaWp\util::localize( 'field must be On or Off.' ) );
		}

	}
	
	public function sanitize ( $value ) {
		
		return intval( $value );
	}
	
	public function render( $attrs ) {
		//print_r($attrs);
		//print_r($this->options);
		$value = $this->options[ $attrs['id'] ];
		
		if ( ! $value && ! is_numeric( $value )  ) {
			
			//$value = pp_api::getDefaultOption( $this->package, $this->module, $attrs['id'] );
		}
		
		$on_checked = '';
		$off_checked = '';
		
		if ( $value ) {
			
			$on_checked = 'checked=checked';
			
		} else {
			
			$off_checked = 'checked';
		}
		
		$this->out( sprintf(
			'<label for="%s_on"><input class="" name="%s" id="%s_on" value="1" type="radio" %s> On</label>&nbsp; &nbsp; ', 
			
			esc_attr( $attrs['dom_id'] ),
			esc_attr( $attrs['name'] ), 
			esc_attr( $attrs['dom_id'] ),
			$on_checked
		) );
		
		$this->out( sprintf(
			'<label for="%s_off"><input class="" name="%s" id="%s" value="0" type="radio" %s> Off</label>', 
			esc_attr( $attrs['dom_id'] ),
			esc_attr( $attrs['name'] ), 
			esc_attr( $attrs['dom_id'] ),
			$off_checked
		) );
		
		$this->out( sprintf('<p class="description">%s</p>', $attrs['description'] ) );
	}
}


	
?>