<?php
/**
 * Tests for the schema-upgrade decision logic in peptide-search-ai.php.
 *
 * Covers psa_upgrade_needed() across the three version-comparison
 * scenarios: fresh install, already-upgraded, and future-version.
 *
 * @package PeptideSearchAI
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

// Stub WP hook registrars so requiring the plugin file does not explode.
// The plugin bootstraps itself with add_action() / register_*_hook() calls at
// top level; these must be no-ops in the test context.
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {}
}
if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode() {}
}
if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook() {}
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook() {}
}

// Load the plugin file once so the pure helper becomes available. The
// plugin's top-level side effects are limited to hook registrations, which
// are neutralized by the stubs above and the ones in bootstrap.php.
if ( ! function_exists( 'psa_upgrade_needed' ) ) {
	require_once ABSPATH . 'peptide-search-ai.php';
}

/**
 * Test case for psa_upgrade_needed().
 *
 * psa_upgrade_needed() is a pure function — no side effects, no WordPress
 * API calls — so it can be exercised directly without a live option store.
 */
class UpgradeTest extends TestCase {

	/**
	 * Fresh install: stored version defaults to '0.0.0', which is strictly
	 * less than any real released version, so an upgrade must run.
	 */
	public function test_fresh_install_triggers_upgrade(): void {
		$this->assertTrue(
			psa_upgrade_needed( '0.0.0', '4.4.3' ),
			'Fresh install (stored = 0.0.0) must trigger an upgrade against any released version.'
		);
	}

	/**
	 * Already-upgraded: stored version equals current. No upgrade needed.
	 */
	public function test_same_version_skips_upgrade(): void {
		$this->assertFalse(
			psa_upgrade_needed( '4.4.3', '4.4.3' ),
			'Stored version equal to current version must not trigger an upgrade.'
		);
	}

	/**
	 * Future-version: stored version is ahead of the running code (e.g., a
	 * downgrade, or a user running multiple sites out of sync). Do not run
	 * upgrade — it could apply an older schema over a newer one.
	 */
	public function test_future_stored_version_skips_upgrade(): void {
		$this->assertFalse(
			psa_upgrade_needed( '5.0.0', '4.4.3' ),
			'Stored version greater than current version must not trigger an upgrade.'
		);
	}

	/**
	 * Belt-and-braces: a strictly newer code version against an older
	 * stored version must trigger an upgrade. Guards against an accidental
	 * operator flip in version_compare() (e.g., '>=' vs '<').
	 */
	public function test_older_stored_version_triggers_upgrade(): void {
		$this->assertTrue(
			psa_upgrade_needed( '4.4.2', '4.4.3' ),
			'Stored version older than current version must trigger an upgrade.'
		);
	}
}
