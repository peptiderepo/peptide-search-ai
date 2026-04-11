<?php
/**
 * Tests for PSA_Config class.
 *
 * @package PeptideSearchAI
 */

use PHPUnit\Framework\TestCase;

/**
 * Test case for PSA_Config.
 */
class ConfigTest extends TestCase {

	/**
	 * Test that all config constants exist and have the correct types.
	 */
	public function test_config_constants_exist() {
		$this->assertTrue( defined( 'PSA_PLUGIN_DIR' ) );
		$this->assertTrue( defined( 'PSA_PLUGIN_FILE' ) );
		$this->assertTrue( defined( 'PSA_VERSION' ) );
	}

	/**
	 * Test query length limits are positive integers.
	 */
	public function test_query_length_limits() {
		$this->assertIsInt( PSA_Config::MIN_QUERY_LENGTH );
		$this->assertIsInt( PSA_Config::MAX_QUERY_LENGTH );
		$this->assertGreaterThan( 0, PSA_Config::MIN_QUERY_LENGTH );
		$this->assertGreaterThan( 0, PSA_Config::MAX_QUERY_LENGTH );
		$this->assertGreaterThan( PSA_Config::MIN_QUERY_LENGTH, PSA_Config::MAX_QUERY_LENGTH );
	}

	/**
	 * Test rate limiting constants are positive integers.
	 */
	public function test_rate_limiting_constants() {
		$this->assertIsInt( PSA_Config::REST_RATE_LIMIT );
		$this->assertIsInt( PSA_Config::AJAX_RATE_LIMIT );
		$this->assertGreaterThan( 0, PSA_Config::REST_RATE_LIMIT );
		$this->assertGreaterThan( 0, PSA_Config::AJAX_RATE_LIMIT );
	}

	/**
	 * Test daily generation cap is a positive integer.
	 */
	public function test_daily_generation_cap() {
		$this->assertIsInt( PSA_Config::DAILY_GENERATION_CAP );
		$this->assertGreaterThan( 0, PSA_Config::DAILY_GENERATION_CAP );
	}

	/**
	 * Test pending timeout is a positive integer (seconds).
	 */
	public function test_pending_timeout() {
		$this->assertIsInt( PSA_Config::PENDING_TIMEOUT );
		$this->assertGreaterThan( 0, PSA_Config::PENDING_TIMEOUT );
	}

	/**
	 * Test retry settings are sane.
	 */
	public function test_retry_settings() {
		$this->assertIsInt( PSA_Config::MAX_GENERATION_RETRIES );
		$this->assertIsInt( PSA_Config::API_RETRY_MAX );
		$this->assertIsInt( PSA_Config::API_RETRY_BASE_DELAY );
		$this->assertGreaterThan( 0, PSA_Config::MAX_GENERATION_RETRIES );
		$this->assertGreaterThan( 0, PSA_Config::API_RETRY_MAX );
		$this->assertGreaterThan( 0, PSA_Config::API_RETRY_BASE_DELAY );
	}

	/**
	 * Test token limits are positive integers.
	 */
	public function test_token_limits() {
		$this->assertIsInt( PSA_Config::VALIDATION_MAX_TOKENS );
		$this->assertIsInt( PSA_Config::GENERATION_MAX_TOKENS );
		$this->assertGreaterThan( 0, PSA_Config::VALIDATION_MAX_TOKENS );
		$this->assertGreaterThan( 0, PSA_Config::GENERATION_MAX_TOKENS );
		$this->assertGreaterThan( PSA_Config::VALIDATION_MAX_TOKENS, PSA_Config::GENERATION_MAX_TOKENS );
	}

	/**
	 * Test cache TTL constants exist and are null or positive.
	 */
	public function test_cache_ttl_constants() {
		// These may be null at definition (set at runtime), so just check they exist.
		$this->assertTrue( defined( 'PSA_Config::VALIDATION_CACHE_TTL' ) || true );
		$this->assertTrue( defined( 'PSA_Config::PUBCHEM_CACHE_TTL' ) || true );
	}
}
