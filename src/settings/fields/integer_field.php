<?php

namespace owaWp\settings\fields;

use owaWp\settings\fields\text;

class integer_field extends text {
	
	
	public function sanitize( $value ) {
		
		return intval( trim( $value ) );
	}
	
	public function isValid( $value ) {
		
		if ( is_numeric( $value ) && $value > $this->get('min_value') ) {
			
			return true;
			
		} else {
		
			$this->addError( 
				$this->get('dom_id'), 
				sprintf(
					'%s %s %s %s %s.',
					$this->get('label_for'),
					util::localize('must be a number between'),
					$this->get('min_value'),
					util::localize('and'),
					$this->get('max_value')
				)
			);
		}
	}
}

	
?>