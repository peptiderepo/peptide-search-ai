<?php
/**
 * AJAX search handler and peptide generation workflow.
 *
 * What: Orchestrates the AJAX search flow — checks existing results, handles
 *       pending retries, validates new peptide names, creates placeholders,
 *       and schedules background generation.
 * Who calls it: PSA_Search::init() registers the AJAX action that invokes ajax_search().
 * Dependencies: PSA_Config, PSA_AI_Generator, PSA_Search (for search_peptides/find_pending).
 *
 * @package PeptideSearchAI
 * @since   2.6.0
 * @see     includes/class-psa-search.php         — Core search and cache logic.
 * @see     includes/class-psa-ai-generator.php   — Peptide validation and content generation.
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSA_Search_Handler {

	/**
	 * AJAX handler: search for peptides.
	 *
	 * Orchestrates: search → pending check → rate limit → validate → generate.
	 * Side effects: nonce check, JSON response, may create posts and schedule cron.
	 */
	public static function ajax_search(): void {
		check_ajax_referer( 'psa_search_nonce', 'nonce' );

		$query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';

		if ( mb_strlen( $query ) < PSA_Config::MIN_QUERY_LENGTH || mb_strlen( $query ) > PSA_Config::MAX_QUERY_LENGTH ) {
			wp_send_json_error( 'Search query must be between ' . PSA_Config::MIN_QUERY_LENGTH . ' and ' . PSA_Config::MAX_QUERY_LENGTH . ' characters.' );
		}

		// 1. Search existing published peptides.
		$results = PSA_Search::search_peptides( $query );

		if ( ! empty( $results['results'] ) ) {
			wp_send_json_success( array( 'status' => 'found', 'data' => $results ) );
			return;
		}

		// 2. Check if already pending — handles retries if timed out.
		$pending = PSA_Search::find_pending_peptide( $query );
		if ( $pending ) {
			self::handle_pending_retry( $pending );
			return;
		}

		// 3. Check rate limit before attempting new generation.
		if ( ! self::check_rate_limit() ) {
			wp_send_json_success( array( 'status' => 'rate_limited', 'message' => 'Too many requests. Please try again later.' ) );
			return;
		}

		// 4. Validate and generate.
		self::handle_new_generation( $query );
	}

	/**
	 * Handle a pending peptide — retry if timed out, else return pending status.
	 *
	 * Side effects: may update post meta, schedule cron, JSON response.
	 *
	 * @param WP_Post $pending The pending post.
	 */
	private static function handle_pending_retry( $pending ): void {
		$started     = (int) get_post_meta( $pending->ID, 'psa_generation_started', true );
		$retry_count = (int) get_post_meta( $pending->ID, 'psa_generation_attempts', true );
		$timed_out   = $started && ( time() - $started ) > PSA_Config::PENDING_TIMEOUT;

		if ( $timed_out ) {
			if ( $retry_count >= PSA_Config::MAX_GENERATION_RETRIES ) {
				error_log( 'PSA: Max retries (' . PSA_Config::MAX_GENERATION_RETRIES . ') exceeded for "' . $pending->post_title . '" (post ' . $pending->ID . '). Marking as failed.' );
				update_post_meta( $pending->ID, 'psa_source', 'failed' );
				update_post_meta( $pending->ID, 'psa_generation_error', 'Generation failed after ' . PSA_Config::MAX_GENERATION_RETRIES . ' attempts.' );
				wp_send_json_success( array(
					'status'  => 'invalid',
					'message' => 'We were unable to generate content for this peptide. Please try again later.',
				) );
				return;
			}

			error_log( 'PSA: Retrying stale pending peptide ' . $pending->post_title . ' (post ' . $pending->ID . ', attempt ' . ( $retry_count + 1 ) . '/' . PSA_Config::MAX_GENERATION_RETRIES . ')' );
			update_post_meta( $pending->ID, 'psa_generation_started', time() );
			update_post_meta( $pending->ID, 'psa_generation_attempts', $retry_count + 1 );
			self::schedule_background_generation( $pending->ID, $pending->post_title );
		}

		wp_send_json_success( array( 'status' => 'pending', 'peptide_name' => $pending->post_title ) );
	}

	/**
	 * Check rate limit for AJAX generation requests.
	 *
	 * Uses dual-layer caching (transient + object cache) for resilience.
	 *
	 * @return bool True if under limit.
	 */
	private static function check_rate_limit(): bool {
		$ip       = psa_get_client_ip();
		$rate_key = 'psa_rate_' . md5( $ip );

		$count_transient = (int) get_transient( $rate_key );
		$count_cache     = (int) wp_cache_get( $rate_key, 'psa_rate_limits' );
		$count           = max( $count_transient, $count_cache );

		return $count < PSA_Config::AJAX_RATE_LIMIT;
	}

	/**
	 * Handle a new generation request — validate, create placeholder, and schedule.
	 *
	 * Side effects: AI validation call, post creation, rate limit update, cron schedule, JSON response.
	 *
	 * @param string $query The search query (peptide name).
	 */
	private static function handle_new_generation( string $query ): void {
		// Global daily generation cap to prevent cost overruns.
		$daily_key   = 'psa_daily_gen_' . gmdate( 'Y-m-d' );
		$daily_count = (int) get_transient( $daily_key );
		if ( $daily_count >= PSA_Config::DAILY_GENERATION_CAP ) {
			wp_send_json_success( array(
				'status'  => 'rate_limited',
				'message' => 'Our system has reached its daily limit for adding new peptides. Please try again tomorrow.',
			) );
			return;
		}

		$validation = PSA_AI_Generator::validate_peptide_name( $query );

		if ( is_wp_error( $validation ) ) {
			error_log( 'PSA: Validation error for "' . $query . '": ' . $validation->get_error_message() );
			wp_send_json_success( array( 'status' => 'invalid', 'message' => 'Could not verify this peptide name.' ) );
		}

		if ( empty( $validation['is_valid'] ) ) {
			wp_send_json_success( array(
				'status'  => 'invalid',
				'message' => $validation['reason'] ?? 'This does not appear to be a recognized peptide.',
			) );
		}

		// Valid peptide — increment rate limit and create placeholder.
		$ip       = psa_get_client_ip();
		$rate_key = 'psa_rate_' . md5( $ip );
		$count    = (int) get_transient( $rate_key );
		set_transient( $rate_key, $count + 1, HOUR_IN_SECONDS );
		wp_cache_set( $rate_key, $count + 1, 'psa_rate_limits', HOUR_IN_SECONDS );

		$canonical_name = sanitize_text_field( $validation['canonical_name'] ?? $query );
		$placeholder_id = self::create_pending_placeholder( $canonical_name );

		if ( is_wp_error( $placeholder_id ) ) {
			wp_send_json_error( 'Failed to create peptide entry. Please try again.' );
		}

		// Increment global daily generation counter.
		set_transient( $daily_key, $daily_count + 1, DAY_IN_SECONDS );

		error_log( 'PSA: Scheduling async generation for "' . $canonical_name . '" (post ' . $placeholder_id . ')' );
		self::schedule_background_generation( $placeholder_id, $canonical_name );

		wp_send_json_success( array( 'status' => 'pending', 'peptide_name' => $canonical_name ) );
	}

	/**
	 * Create a placeholder draft post for the pending peptide.
	 *
	 * @param string $peptide_name Canonical peptide name.
	 * @return int|WP_Error Post ID or error.
	 */
	public static function create_pending_placeholder( string $peptide_name ) {
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'peptide',
				'post_title'   => $peptide_name,
				'post_content' => '',
				'post_status'  => 'draft',
				'post_author'  => psa_get_admin_user_id(),
			),
			true
		);

		if ( ! is_wp_error( $post_id ) ) {
			update_post_meta( $post_id, 'psa_source', 'pending' );
			update_post_meta( $post_id, 'psa_generation_started', time() );
			do_action( 'psa_peptide_created', $post_id, $peptide_name );
		}

		return $post_id;
	}

	/**
	 * Schedule background generation using WP-Cron (non-blocking).
	 *
	 * @param int    $post_id      Placeholder post ID.
	 * @param string $peptide_name Peptide to research.
	 */
	public static function schedule_background_generation( int $post_id, string $peptide_name ): void {
		$args = array( $post_id, $peptide_name );
		if ( ! wp_next_scheduled( 'psa_generate_peptide_background', $args ) ) {
			wp_schedule_single_event( time(), 'psa_generate_peptide_background', $args );
		}
		spawn_cron();
	}
}
