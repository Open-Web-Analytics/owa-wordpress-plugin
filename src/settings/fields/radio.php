<?php

namespace owaWp\settings\fields;

use owaWp\settings\field;

/**
 * One choice from a fixed list: 'options' maps each stored value to its label.
 */
class radio extends field {

	public function render( $attrs ) {

		$value = (string) ( $this->options[ $attrs['id'] ] ?? $attrs['default_value'] ?? '' );

		foreach ( (array) $attrs['options'] as $option => $label ) {

			$this->out( sprintf(
				'<label for="%1$s_%2$s"><input class="" name="%3$s" id="%1$s_%2$s" value="%2$s" type="radio" %4$s> %5$s</label>&nbsp; &nbsp; ',
				esc_attr( $attrs['dom_id'] ),
				esc_attr( $option ),
				esc_attr( $attrs['name'] ),
				(string) $option === $value ? 'checked' : '',
				esc_html( $label )
			) );
		}

		$this->out( sprintf( '<p class="description">%s</p>', $attrs['description'] ) );
	}

	public function sanitize( $value ) {

		return (string) $value;
	}

	public function isValid( $value ) {

		if ( array_key_exists( (string) $value, (array) $this->get( 'options' ) ) ) {

			return true;
		}

		$this->addError( $this->get( 'dom_id' ), $this->get( 'label_for' ) . ' ' . \owaWp\util::localize( 'must be one of the listed choices.' ) );
	}
}

?>
