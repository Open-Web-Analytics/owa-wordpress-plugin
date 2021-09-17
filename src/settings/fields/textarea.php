<?php

namespace owaWp\settings\fields;

use owaWp\settings\field;

class textarea extends field {

	public function render( $attrs ) {
	//print_r();
		$value = $this->options[ $attrs['id'] ];
		
		$this->out( sprintf(
			'<textarea name="%s" rows="%s" cols="%s" />%s</textarea> ', 
			esc_attr( $attrs['name'] ), 
			esc_attr( $attrs['rows'] ),
			esc_attr( $attrs['cols'] ),
			esc_textarea( $value ) 
		) );
		
		$this->out( sprintf('<p class="description">%s</p>', $attrs['description'] ) );
	}	
	
	public function sanitize( $value ) {
		
		return trim($value);
	}
}

	
?>