<?php
/**
 * Tests for the schema-upgrade decision logic in PSA_Upgrade.
 *
 * Covers PSA_Upgrade::is_needed() across the four version-comparison
 * scenarios: fresh install, already-upgraded, future-version (downgrade),
 * and older stored version.
 *
 * @package PeptideSearchAI
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

// PSA_Upgrade is already loaded by tests/bootstrap.php via class-psa-upgrade.php.
// No plugin-file bootstrap needed — the decision helper is a pure function.

/**
 * Test case for PSA_Upgrade::is_needed().
 *
 * The helper is a pure static method (no WordPress API calls, no side
 * effects), so it can be exercised directly without a live option store.
 */
class UpgradeTest extends TestCase {

	/**
	 * Fresh install: stored version defaults to '0.0.0', which is strictly
	 * less than any real released version, so an upgrade must run.
	 */
	public function test_fresh_install_triggers_upgrade(): void {
		$this->assertTrue(
			PSA_Upgrade::is_needed( '0.0.0', '4.4.3' ),
			'Fresh install (stored = 0.0.0) must trigger an upgrade against any released version.'
		);
	}

	/**
	 * Already-upgraded: stored version equals current. No upgrade needed.
	 */
	public function test_same_version_skips_upgrade(): void {
		$this->assertFalse(
			PSA_Upgrade::is_needed( '4.4.3', '4.4.3' ),
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
			PSA_Upgrade::is_needed( '5.0.0', '4.4.3' ),
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
			PSA_Upgrade::is_needed( '4.4.2', '4.4.3' ),
			'Stored version older than current version must trigger an upgrade.'
		);
	}

	/**
	 * Constants used by the driver should be stable across releases so
	 * existing installs keep pointing at the same option key.
	 */
	public function test_option_name_constant_is_stable(): void {
		$this->assertSame( 'psa_db_version', PSA_Upgrade::OPTION_NAME );
	}

	/**
	 * Default stored version must be strictly less than any future release
	 * so fresh-install detection keeps working.
	 */
	public function test_default_stored_version_is_zero(): void {
		$this->assertSame( '0.0.0', PSA_Upgrade::DEFAULT_STORED_VERSION );
	}
}
