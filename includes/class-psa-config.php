<?php
/**
 * Configuration constants for Peptide Search AI.
 * Centralizes all magic numbers and configuration values.
 *
 * @package PeptideSearchAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSA_Config {

	// Search query validation
	const MIN_QUERY_LENGTH = 2;
	const MAX_QUERY_LENGTH = 100;

	// Rate limiting (requests per hour)
	const REST_RATE_LIMIT = 20;
	const AJAX_RATE_LIMIT = 10;

	// Generation timing
	const PENDING_TIMEOUT = 300; // seconds (5 minutes)
	const MAX_GENERATION_RETRIES = 3;

	// AI token limits
	const VALIDATION_MAX_TOKENS = 300;
	const GENERATION_MAX_TOKENS = 4096;

	// Cache TTL
	const VALIDATION_CACHE_TTL = null; // Set to DAY_IN_SECONDS at runtime
	const PUBCHEM_CACHE_TTL = null; // Set to 7 * DAY_IN_SECONDS at runtime

	// API retry settings
	const API_RETRY_MAX = 3;
	const API_RETRY_BASE_DELAY = 5; // seconds

}
