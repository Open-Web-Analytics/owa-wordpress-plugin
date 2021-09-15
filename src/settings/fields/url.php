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
		
		echo sprintf(
			'<input name="%s" id="%s" value="%s" type="text" size="%s" /> ', 
			esc_attr( $attrs['name'] ), 
			esc_attr( $attrs['dom_id'] ),
			esc_attr( $value ),
			$size 
		);
		
		echo sprintf('<p class="description">%s</p>', $attrs['description']);
	}	
	
	public function sanitize( $value ) {
		
		$value = trim( $value );
		
		$value = $url = filter_var( $value, FILTER_SANITIZE_URL );

/*
		if ( ! strpos( $value, '/', -1 ) ) {
			
			$value .= '/'; 
		}
*/
			
		return $value;
	}
	
	public function isValid( $value ) {
		
	
		if ( substr( $value, -4 ) === '.php' ) {
			
			$this->addError( 
		    	$this->get('dom_id'), 
				sprintf(
					'%s %s',
					$this->get( 'label_for' ),
					util::localize( 'URL should be the base directory of your OWA instance, not a file endpoint.' ) ) );
					
			return false;
		}
		
		if ( ! substr( $value, 0, 4 ) === "http" ) {
			
			$this->addError( 
	    	$this->get('dom_id'), 
			sprintf(
				'%s %s',
				$this->get( 'label_for' ),
				util::localize( 'URL scheme required. (i.e. http:// or https://)' ) ) );
			
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
						util::localize( 'Not a valid URL' ) ) );
		}		
	}
	
}
	
?>