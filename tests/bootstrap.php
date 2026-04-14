<?php
/**
 * PHPUnit bootstrap file for Peptide Search AI tests.
 *
 * Initializes the test environment with minimal WordPress stubs.
 *
 * @package PeptideSearchAI
 */

// Define WordPress constants.
define( 'ABSPATH', __DIR__ . '/../' );
define( 'PSA_PLUGIN_DIR', ABSPATH );
define( 'PSA_PLUGIN_FILE', ABSPATH . 'peptide-search-ai.php' );
define( 'PSA_VERSION', '1.0.0' );

// Stub WordPress functions.
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return $str;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		return array();
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $transient ) {
		return false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $transient, $value, $expiration = 0 ) {
		return true;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		// Return the first non-hook argument (typically the second arg onwards)
		$args = func_get_args();
		return isset( $args[1] ) ? $args[1] : $value;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return class_exists( 'WP_Error' ) && $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $data ) {
		return $data;
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		if ( is_array( $args ) ) {
			return array_merge( $defaults, $args );
		}
		return $defaults;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

// Define WP_Error class if it doesn't exist.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $errors = array();
		public $error_data = array();

		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( ! empty( $code ) ) {
				$this->add( $code, $message, $data );
			}
		}

		public function add( $code, $message = '', $data = '' ) {
			if ( empty( $code ) ) {
				return;
			}
			if ( ! isset( $this->errors[ $code ] ) ) {
				$this->errors[ $code ] = array();
			}
			$this->errors[ $code ][] = $message;
			if ( ! empty( $data ) ) {
				$this->error_data[ $code ] = $data;
			}
		}

		public function get_error_code() {
			$codes = array_keys( $this->errors );
			return ! empty( $codes ) ? $codes[0] : '';
		}

		public function get_error_message( $code = '' ) {
			if ( empty( $code ) ) {
				$code = $this->get_error_code();
			}
			return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
		}
	}
}

// Require the class files under test.
require_once ABSPATH . 'includes/class-psa-config.php';
require_once ABSPATH . 'includes/class-psa-cost-tracker.php';
require_once ABSPATH . 'includes/class-psa-openrouter.php';
require_once ABSPATH . 'includes/class-psa-kb-builder.php';
require_once ABSPATH . 'includes/class-psa-ai-generator.php';
require_once ABSPATH . 'includes/class-psa-upgrade.php';
