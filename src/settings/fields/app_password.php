<?php

namespace owaWp\settings\fields;

use owaWp\settings\field;

/**
 * An OWA 2.x application password: 44 letters and digits, shown as a password
 * input. Empty is allowed (the 1.x keys may be in use instead).
 */
class app_password extends field {

	const PATTERN = '/^[0-9A-Za-z]{44}$/D';

	// the shape of a 1.x API key, which OWA 2.x application passwords never have
	const LEGACY_KEY_PATTERN = '/^[a-f0-9]{32}$/D';

	public function render( $attrs ) {

		$this->out( sprintf(
			'<input name="%s" id="%s" value="%s" type="password" autocomplete="off" size="%s" /> ',
			esc_attr( $attrs['name'] ),
			esc_attr( $attrs['dom_id'] ),
			esc_attr( $this->options[ $attrs['id'] ] ?? '' ),
			esc_attr( $attrs['length'] ?? 60 )
		) );

		$this->out( sprintf( '<p class="description">%s</p>', $attrs['description'] ) );
	}

	public function sanitize( $value ) {

		return trim( (string) $value );
	}

	public function isValid( $value ) {

		if ( $value === '' || preg_match( self::PATTERN, $value ) ) {

			return true;
		}

		$message = preg_match( self::LEGACY_KEY_PATTERN, $value )
			? 'This looks like a 1.x API key. OWA 2.x uses an application password: create one in OWA under My Preferences > Application Passwords.'
			: 'An application password is 44 letters and digits.';

		$this->addError( $this->get( 'dom_id' ), \owaWp\util::localize( $message ) );
	}
}

?>
