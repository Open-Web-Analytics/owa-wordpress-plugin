<?php

namespace owaWp\settings\fields;

use owaWp\settings\fields\text;

class commaseparatedlist extends text {
	
	public function sanitize( $value ) {
		
		$value = trim( $value );
		$value = str_replace(' ', '', $value ); 
		$value = trim( $value, ',');
		
		return $value;
	}
	
	public function isValid( $value ) {
		
		$re = '/^\d+(?:,\d+)*$/';
	
		if ( preg_match( $re, $value ) ) {
		    
		    return true;
		
		} else {
		
		    $this->addError( 
		    	$this->get('dom_id'), 
				sprintf(
					'%s %s',
					$this->get( 'label_for' ),
					util::localize( 'can only contain a list of numbers separated by commas.' ) 
				)
			);
		}
	}
}

	
?>