<?php
	
namespace owaWp\settings\fields;

use owaWp\settings\field;

class text extends field {

	public function render( $attrs ) {
	
		$value = $this->options[ $attrs['id'] ];
		
		if ( ! $value ) {
			//print_r($this->properties);
			//$value = $this->options[] ;
		}
		
		if ( array_key_exists( 'length', $attrs ) ) {
			
			$size = $attrs['length'];	
			
		} else {
			
			$size = 30;
		}
		
		$this->out( sprintf(
			'<input name="%s" id="%s" value="%s" type="text" size="%s" /> ', 
			esc_attr( $attrs['name'] ), 
			esc_attr( $attrs['dom_id'] ),
			esc_attr( $value ),
			esc_attr( $size ) 
		) );
		
		$this->out( sprintf('<p class="description">%s</p>', $attrs['description'] ) );
	}	
	
	public function sanitize( $value ) {
		
		return trim($value);
	}
}

?>