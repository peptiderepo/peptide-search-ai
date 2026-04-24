<?php
/**
 * Handles encryption/decryption of sensitive data at rest.
 *
 * What: Provides AES-256-CBC encryption for API keys stored in the database.
 * Who calls it: PSA_Admin (encrypt on save), PSA_AI_Generator (decrypt on read).
 * Dependencies: PHP openssl extension, WordPress wp_salt() function.
 *
 * @package PeptideSearchAI
 * @see     includes/class-psa-admin.php
 * @see     includes/class-psa-ai-generator.php
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSA_Encryption {

	/**
	 * Encrypt plaintext using AES-256-CBC.
	 *
	 * Prepends the IV to the ciphertext and base64-encodes the result.
	 *
	 * @param string $plaintext The plaintext to encrypt.
	 * @return string|false Base64-encoded IV + ciphertext, or false on failure.
	 */
	public static function encrypt( string $plaintext ) {
		if ( empty( $plaintext ) ) {
			return false;
		}

		$key = self::get_key();
		if ( false === $key ) {
			return false;
		}

		// Generate a random IV for this encryption.
		$iv = openssl_random_pseudo_bytes( 16 );
		if ( false === $iv ) {
			return false;
		}

		// Encrypt using AES-256-CBC.
		$ciphertext = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $ciphertext ) {
			return false;
		}

		// Prepend IV to ciphertext and base64-encode.
		$encrypted = base64_encode( $iv . $ciphertext );

		return $encrypted;
	}

	/**
	 * Decrypt AES-256-CBC ciphertext.
	 *
	 * Expects base64-encoded input with IV prepended (as produced by encrypt()).
	 *
	 * @param string $ciphertext The base64-encoded IV + ciphertext.
	 * @return string|false Decrypted plaintext, or false on failure.
	 */
	public static function decrypt( string $ciphertext ) {
		if ( empty( $ciphertext ) ) {
			return false;
		}

		$key = self::get_key();
		if ( false === $key ) {
			return false;
		}

		// Base64-decode and extract IV + ciphertext.
		$data = base64_decode( $ciphertext, true );
		if ( false === $data || strlen( $data ) < 16 ) {
			return false;
		}

		// First 16 bytes are the IV.
		$iv             = substr( $data, 0, 16 );
		$encrypted_data = substr( $data, 16 );

		// Decrypt.
		$plaintext = openssl_decrypt( $encrypted_data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

		return $plaintext;
	}

	/**
	 * Check if a value looks encrypted.
	 *
	 * A value is considered encrypted if it's base64-decodable and contains
	 * at least 16 bytes (IV) + some ciphertext.
	 *
	 * @param string $value The value to check.
	 * @return bool True if the value appears to be encrypted.
	 */
	public static function is_encrypted( $value ): bool {
		if ( empty( $value ) || ! is_string( $value ) ) {
			return false;
		}

		$data = base64_decode( $value, true );
		if ( false === $data || strlen( $data ) < 16 ) {
			return false;
		}

		return true;
	}

	/**
	 * Derive a 32-byte encryption key from WordPress salt.
	 *
	 * Uses SHA-256 hash of wp_salt( 'auth' ) to produce a consistent 32-byte key.
	 *
	 * @return string|false 32-byte key, or false if salt is unavailable.
	 */
	private static function get_key() {
		$salt = wp_salt( 'auth' );
		if ( empty( $salt ) ) {
			return false;
		}

		// SHA-256 produces a 64-character hex string (32 bytes when decoded).
		$key = hash( 'sha256', $salt, true );
		return $key;
	}
}
