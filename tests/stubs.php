<?php

// The parts of WordPress classes the plugin's REST layer uses.

if ( ! class_exists( 'WP_Error' ) ) {

	class WP_Error {

		public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}

		public function get_error_code() { return $this->code; }

		public function get_error_message() { return $this->message; }

		public function get_error_data() { return $this->data; }
	}
}
