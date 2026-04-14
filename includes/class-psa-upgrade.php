<?php
/**
 * Schema upgrade helpers for Peptide Search AI.
 *
 * What: Pure-function helpers that decide whether a database schema upgrade
 * is required, plus the driver that runs the upgrade when the installed
 * code version is newer than the stored psa_db_version option.
 *
 * Who triggers it: psa_admin_init() in peptide-search-ai.php calls
 * PSA_Upgrade::maybe_run() on every WordPress admin_init hook.
 *
 * Dependencies: PSA_Cost_Tracker (for re-running dbDelta on the
 * cost-tracker table), WordPress options API (get_option / update_option),
 * and the PSA_VERSION constant defined in peptide-search-ai.php.
 *
 * @package PeptideSearchAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PSA_Upgrade — thin wrapper around version_compare + dbDelta.
 *
 * The class exists so the decision logic (is_needed) can be unit-tested as a
 * pure function, while the impure driver (maybe_run) stays isolated.
 */
class PSA_Upgrade {

	/**
	 * Option name used to persist the schema version last migrated to.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'psa_db_version';

	/**
	 * Default stored version for fresh installs. Strictly less than any
	 * real released version so version_compare treats it as "older".
	 *
	 * @var string
	 */
	const DEFAULT_STORED_VERSION = '0.0.0';

	/**
	 * Decide whether a schema upgrade is needed given a stored version and
	 * the currently-installed code version. Pure function — no side effects,
	 * no WordPress API calls — so it can be exercised in isolation.
	 *
	 * @param string $stored_version  Version string recorded in psa_db_version.
	 * @param string $current_version Version string of the running code.
	 * @return bool True if an upgrade must run; false otherwise.
	 */
	public static function is_needed( string $stored_version, string $current_version ): bool {
		return version_compare( $stored_version, $current_version, '<' );
	}

	/**
	 * Run database upgrades when the installed code version is newer than
	 * the stored psa_db_version option. Keeps users from needing to
	 * deactivate/reactivate the plugin after a schema change.
	 *
	 * Side effects: reads psa_db_version option; on upgrade, calls
	 * PSA_Cost_Tracker::create_table() and writes psa_db_version.
	 *
	 * @return void
	 */
	public static function maybe_run(): void {
		$stored = get_option( self::OPTION_NAME, self::DEFAULT_STORED_VERSION );
		if ( ! self::is_needed( $stored, PSA_VERSION ) ) {
			return;
		}

		// Re-run CREATE TABLE via dbDelta — additive schema changes (new
		// columns, new indexes) are applied automatically.
		PSA_Cost_Tracker::create_table();

		update_option( self::OPTION_NAME, PSA_VERSION, false );
	}
}
