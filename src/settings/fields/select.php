<?php

namespace owaWp\settings\fields;

use owaWp\settings\field;

class select extends field {
	
	public function sanitize ( $value ) {
		
		return $value;
	}
	
	public function render( $attrs ) {
		
		$selected = $this->options[ $attrs['id'] ];
		
		$options = $attrs['options'];
		$options = apply_filters( 'owa_wp_settings_field_'.$attrs['id'] , $options );
		
		if ( $options) {
			$opts = '<option value="">Select...</option>';
			
			foreach ($options as $option) {
				
				$selected_attr = '';
				
				if ($option['siteId'] === $selected) {
					
					$selected_attr = 'selected';
				}
				
				$opts .= sprintf(
					'<option value="%s" %s>%s</option> \n',
					$option['siteId'],
					$selected_attr,
					$option['label']
				);
		
			}
		} else {
			
			$opts = '<option value="">No options are available.</option>';
		}
		
		echo sprintf(
			'<select id="%s" name="%s">%s</select>', 
			
			esc_attr( $attrs['dom_id'] ),
			esc_attr( $attrs['name'] ), 
			$opts
		);
		
		echo sprintf('<p class="description">%s</p>', $attrs['description']);
	
	}
}


	
?>