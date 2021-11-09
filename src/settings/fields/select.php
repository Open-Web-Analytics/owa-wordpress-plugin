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
				
				$v = '';
				
				if (is_array( $option ) ) {
					
					$v = $option[ $attrs['id'] ];	
				} else {
					
					$v = $option;
				}
				
				$selected_attr = '';
				
				if ($v === $selected) {
					
					$selected_attr = 'selected';
				}
				
				$opts .= sprintf(
					'<option value="%s" %s>%s</option> \n',
					esc_attr( $v ),
					esc_html( $selected_attr ),
					esc_html( $option['label'] )
				);
			}
			
		} else {
			
			$opts = '<option value="">No options are available.</option>';
		}
		
		$this->out( sprintf(
			'<select id="%s" name="%s">%s</select>', 
			
			esc_attr( $attrs['dom_id'] ),
			esc_attr( $attrs['name'] ), 
			$opts
		) );
		
		$this->out( sprintf( '<p class="description">%s</p>', $attrs['description'] ) );	
	}
}
	
?>