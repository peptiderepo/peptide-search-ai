<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * As of PSA v4.5.0 the `peptide` custom post type and `peptide_category`
 * taxonomy are owned by Peptide Repo Core (PR Core) — PSA is a consumer, not
 * the owner. Uninstalling PSA must therefore NOT delete peptide posts,
 * peptide_category terms, or any `psa_*` post meta: that data predates PR
 * Core and is consumed by other plugins via PR Core's data layer + PSA's
 * REST endpoints. Removing it on uninstall would be destructive across
 * plugin boundaries.
 *
 * This uninstaller only removes PSA's own option rows, transients, and the
 * `wp_psa_api_logs` table (PSA's internal cost-tracking table — no other
 * plugin reads from it).
 *
 * @package PeptideSearchAI
 */

// Abort if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// 1. Delete plugin options.
delete_option( 'psa_settings' );
delete_option( 'psa_search_cache_gen' );
delete_option( 'psa_db_version' );

// 2. Delete all plugin transients (validation cache, rate limits, PubChem cache).
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_psa_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_psa_' ) . '%'
	)
);

// 3. Drop PSA's API logs table. Owned entirely by PSA; no cross-plugin reads.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}psa_api_logs" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// 4. NOT deleted (owned by Peptide Repo Core as of PSA v4.5.0)