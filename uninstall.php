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

// 2. Delete peptide_category taxonomy terms and relationships.
$terms = get_terms(
	array(
		'taxonomy'   => 'peptide_category',
		'hide_empty' => false,
		'fields'     => 'ids',
	)
);
if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
	foreach ( $terms as $term_id ) {
		wp_delete_term( (int) $term_id, 'peptide_category' );
	}
}

// 3. Delete plugin options.
delete_option( 'psa_settings' );
delete_option( 'psa_search_cache_gen' );

// 4. Delete all plugin transients (validation cache, rate limits).
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_psa_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_psa_' ) . '%'
	)
);

// 5. Drop API logs table.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}psa_api_logs" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// 6. Flush rewrite rules to clean up the peptide post type permalinks.
flush_rewrite_rules();
