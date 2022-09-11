<?php
	
namespace owaWp\settings\fields;

use owaWp\settings\field;
	
class url extends field {

	public function render( $attrs ) {
	
		$value = $this->options[ $attrs['id'] ];
				
		$size = $attrs['length'];
		
		if ( ! $size ) {
			
			$size = 60;
		}
		
		$this->out( sprintf(
			'<input name="%s" id="%s" value="%s" type="text" size="%s" /> ', 
			esc_attr( $attrs['name'] ), 
			esc_attr( $attrs['dom_id'] ),
			esc_attr( $value ),
			esc_attr( $size )
		) );
		
		$this->out( sprintf( '<p class="description">%s</p>', $attrs['description'] ) );
	}	
	
	public function sanitize( $value ) {
		
		$value = trim( $value );
		
		$value = $url = filter_var( $value, FILTER_SANITIZE_URL );
			
		return $value;
	}
	
	public function isValid( $value ) {
		
			
		if ( ! substr( $value, 0, 4 ) === "http" ) {
			
			$this->addError( 
	    	$this->get('dom_id'), 
			sprintf(
				'%s %s',
				$this->get( 'label_for' ),
				\owaWp\util::localize( 'URL scheme required. (i.e. http:// or https:// only.)' ) ) );
			
			return false;
		}
			
		if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
			
			return true;	
			
		} else {
			
			// not a valid url
			$this->addError( 
			    	$this->get('dom_id'), 
					sprintf(
						'%s %s',
						$this->get( 'label_for' ),
						\owaWp\util::localize( 'Not a valid URL' ) ) );
		}		
	}
	
}
	
?>