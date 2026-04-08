<?php
/**
 * Standardized error handling for Peptide Search AI.
 * Provides a unified interface for sending error responses with consistent structure.
 *
 * @package PeptideSearchAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSA_Error {

	// Error type constants
	const TYPE_VALIDATION = 'validation_error';
	const TYPE_RATE_LIMIT = 'rate_limit_error';
	const TYPE_SYSTEM = 'system_error';
	const TYPE_NOT_FOUND = 'not_found';

	/**
	 * Send a standardized error response.
	 *
	 * @param string $type     The error type (one of the TYPE_* constants).
	 * @param string $message  User-friendly error message.
	 * @param int    $http_code HTTP response code (default 400).
	 * @return void
	 */
	public static function send( $type, $message, $http_code = 400 ) {
		wp_send_json_error(
			array(
				'error_type' => $type,
				'message'    => $message,
			),
			$http_code
		);
	}

}
