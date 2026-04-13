<?php
/**
 * AJAX-based batch enrichment for peptides missing extended meta fields.
 *
 * What: Provides a cron-independent way to re-enrich peptides one at a time via AJAX polling.
 * Who calls it: PSA_Admin settings page JavaScript; AJAX hooks registered on admin_init.
 * Dependencies: PSA_AI_Generator (content generation), PSA_Config (caps), PSA_Cost_Tracker (budget).
 *
 * @package PeptideSearchAI
 * @see     includes/class-psa-admin.php
 * @see     includes/class-psa-ai-generator.php
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSA_Batch_Enrichment {

	/**
	 * Register AJAX handlers for batch enrichment.
	 * Called from psa_init() in the main plugin file.
	 */
	public static function init(): void {
		add_action( 'wp_ajax_psa_batch_enrich_status', array( __CLASS__, 'ajax_status' ) );
		add_action( 'wp_ajax_psa_batch_enrich_next', array( __CLASS__, 'ajax_process_next' ) );
	}

	/**
	 * AJAX: Return the count of peptides that need enrichment.
	 *
	 * Response JSON: { total: int, remaining: int, completed: int }
	 * Side effects: None (read-only).
	 */
	public static function ajax_status(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		check_ajax_referer( 'psa_batch_enrich', '_nonce' );

		$needs_enrichment = self::get_unenriched_ids();
		$total_published  = self::get_total_published_count();

		wp_send_json_success(
			array(
				'total'     => $total_published,
				'remaining' => count( $needs_enrichment ),
				'completed' => $total_published - count( $needs_enrichment ),
			)
		);
	}

	/**
	 * AJAX: Process one peptide — set to draft, generate, republish.
	 *
	 * Response JSON on success: { post_id: int, name: string, status: 'ok', remaining: int }
	 * Response JSON on skip:    { status: 'done', remaining: 0 }
	 * Response JSON on error:   { post_id: int, name: string, status: 'error', message: string, remaining: int }
	 *
	 * Side effects: Updates post meta via AI generation, logs API cost.
	 */
	public static function ajax_process_next(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		check_ajax_referer( 'psa_batch_enrich', '_nonce' );

		// Respect daily generation cap.
		$daily_key   = 'psa_daily_gen_' . gmdate( 'Y-m-d' );
		$daily_count = (int) get_transient( $daily_key );
		if ( $daily_count >= PSA_Config::DAILY_GENERATION_CAP ) {
			wp_send_json_success(
				array(
					'status'    => 'cap_reached',
					'message'   => sprintf(
						'Daily generation cap reached (%d/%d). Try again tomorrow.',
						$daily_count,
						PSA_Config::DAILY_GENERATION_CAP
					),
					'remaining' => count( self::get_unenriched_ids() ),
				)
			);
		}

		$ids = self::get_unenriched_ids();
		if ( empty( $ids ) ) {
			wp_send_json_success(
				array(
					'status'    => 'done',
					'remaining' => 0,
				)
			);
		}

		$post_id = $ids[0];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( 'Post not found: ' . $post_id, 404 );
		}

		$peptide_name    = $post->post_title;
		$original_status = $post->post_status;

		// Temporarily set to draft + pending so background_generate processes it.
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);
		update_post_meta( $post_id, 'psa_source', 'pending' );
		update_post_meta( $post_id, 'psa_generation_started', time() );

		// Call the generator directly (synchronous — no cron dependency).
		PSA_AI_Generator::background_generate( $post_id, $peptide_name );

		// Bump daily counter.
		$daily_count = (int) get_transient( $daily_key );
		set_transient( $daily_key, $daily_count + 1, DAY_IN_SECONDS );

		// Check outcome: did generation succeed?
		$error = get_post_meta( $post_id, 'psa_generation_error', true );
		$refreshed_post = get_post( $post_id );

		// If still in draft (generation failed or auto_publish is 'draft'), force republish.
		if ( $refreshed_post && 'draft' === $refreshed_post->post_status ) {
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'publish',
				)
			);
		}

		$remaining = count( self::get_unenriched_ids() );

		if ( ! empty( $error ) ) {
			wp_send_json_success(
				array(
					'status'    => 'error',
					'post_id'   => $post_id,
					'name'      => $peptide_name,
					'message'   => $error,
					'remaining' => $remaining,
				)
			);
		}

		wp_send_json_success(
			array(
				'status'    => 'ok',
				'post_id'   => $post_id,
				'name'      => $peptide_name,
				'remaining' => $remaining,
			)
		);
	}

	/**
	 * Get IDs of published peptides missing the half_life extended field.
	 *
	 * @return int[] Post IDs.
	 */
	private static function get_unenriched_ids(): array {
		return get_posts(
			array(
				'post_type'      => 'peptide',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => 'psa_half_life',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => 'psa_half_life',
						'value' => '',
					),
				),
			)
		);
	}

	/**
	 * Get total published peptide count.
	 *
	 * @return int
	 */
	private static function get_total_published_count(): int {
		$counts = wp_count_posts( 'peptide' );
		return (int) ( $counts->publish ?? 0 );
	}
}
