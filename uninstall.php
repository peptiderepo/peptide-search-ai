<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Cleans up all plugin data: peptide posts, post meta, options, transients, and rewrite rules.
 *
 * @package PeptideSearchAI
 */

// Abort if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// 1. Delete all peptide custom posts and their meta.
$peptide_ids = $wpdb->get_col(
	"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'peptide'"
);

if ( ! empty( $peptide_ids ) ) {
	foreach ( $peptide_ids as $post_id ) {
		wp_delete_post( (int) $post_id, true ); // Force delete, bypass trash.
	}
}

// 2. Delete plugin options.
delete_option( 'psa_settings' );

// 3. Delete all plugin transients (validation cache, rate limits).
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_psa_%'
	    OR option_name LIKE '_transient_timeout_psa_%'"
);

// 4. Flush rewrite rules to clean up the peptide post type permalinks.
flush_rewrite_rules();
