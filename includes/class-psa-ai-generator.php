<?php
/**
 * Orchestrates AI content generation for new peptide entries.
 *
 * What: Validation, generation, PubChem enrichment, background processing, dry-run costing.
 * Who calls it: PSA_Search_Handler (validation + generation scheduling), WP-Cron (background_generate).
 * Dependencies: PSA_Config, PSA_Cost_Tracker, PSA_Encryption, PSA_OpenRouter, PSA_PubChem,
 *               PSA_KB_Builder, PSA_AI_Content (prompts + meta saving).
 *
 * @package PeptideSearchAI
 * @since   2.6.0
 * @see     includes/class-psa-ai-content.php  — Prompt templates, meta saving, category assignment.
 * @see     includes/class-psa-search-handler.php
 * @see     includes/class-psa-cost-tracker.php
 * @see     includes/class-psa-pubchem.php
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSA_AI_Generator {

	public static function init(): void {
		add_action( 'psa_generate_peptide_background', array( __CLASS__, 'background_generate' ), 10, 2 );
	}

	/**
	 * Validate whether a search term is a legitimate peptide name.
	 * Uses a lightweight AI call before committing to full generation.
	 *
	 * @param string $name The name to validate.
	 * @return array|WP_Error { is_valid: bool, canonical_name: string, reason: string }
	 */
	public static function validate_peptide_name( string $name ) {
		if ( ! self::validate_peptide_input( $name ) ) {
			return array(
				'is_valid'       => false,
				'canonical_name' => $name,
				'reason'         => 'Invalid characters in peptide name.',
			);
		}

		$options = self::get_settings();
		if ( empty( $options['api_key'] ) ) {
			return new WP_Error( 'no_api_key', 'OpenRouter API key is not configured.' );
		}
		if ( PSA_Cost_Tracker::is_budget_exceeded() ) {
			return new WP_Error( 'budget_exceeded', 'Monthly API budget reached.' );
		}

		// Transient cache to avoid repeated API calls for same term.
		$cache_key = 'psa_validate_' . md5( strtolower( trim( $name ) ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$prompt   = PSA_AI_Content::build_validation_prompt( $name );
		$prompt   = apply_filters( 'psa_validation_prompt', $prompt, $name );
		$response = PSA_OpenRouter::send_request(
			$options['api_key'],
			$options['validation_model'] ? $options['validation_model'] : 'google/gemini-2.0-flash-001',
			$prompt,
			PSA_Config::VALIDATION_MAX_TOKENS,
			'validation',
			$name
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = PSA_OpenRouter::parse_response( $response );
		if ( is_wp_error( $data ) ) {
			return array(
				'is_valid'       => false,
				'canonical_name' => $name,
				'reason'         => 'Could not verify this peptide name.',
			);
		}

		$result = array(
			'is_valid'       => ! empty( $data['is_valid'] ) && true === $data['is_valid'],
			'canonical_name' => sanitize_text_field( $data['canonical_name'] ?? $name ),
			'reason'         => sanitize_text_field( $data['reason'] ?? '' ),
		);

		set_transient( $cache_key, $result, DAY_IN_SECONDS );
		return $result;
	}

	/**
	 * Background generation handler (called via WP-Cron or synchronously).
	 *
	 * @param int    $post_id      The placeholder post ID.
	 * @param string $peptide_name The peptide to research.
	 */
	public static function background_generate( int $post_id, string $peptide_name ): void {
		$post = get_post( $post_id );
		if ( ! $post || 'draft' !== $post->post_status || 'peptide' !== $post->post_type ) {
			error_log( 'PSA: background_generate skipped — post ' . $post_id . ' not a draft peptide.' );
			return;
		}

		$source = get_post_meta( $post_id, 'psa_source', true );
		if ( 'pending' !== $source ) {
			error_log( 'PSA: background_generate skipped — post ' . $post_id . ' source is "' . $source . '", not "pending".' );
			return;
		}

		$options = self::get_settings();
		error_log( 'PSA: Generating content for "' . $peptide_name . '" (post ' . $post_id . ') using model: ' . ( $options['ai_model'] ? $options['ai_model'] : 'google/gemini-2.5-flash' ) );

		do_action( 'psa_before_generation', $post_id, $peptide_name );

		$result = self::generate_peptide_content( $peptide_name );
		if ( is_wp_error( $result ) ) {
			error_log( 'PSA: Generation FAILED for "' . $peptide_name . '": ' . $result->get_error_message() );
			update_post_meta( $post_id, 'psa_generation_error', $result->get_error_message() );
			return;
		}

		error_log( 'PSA: AI content generated successfully for "' . $peptide_name . '". Fields: ' . implode( ', ', array_keys( $result ) ) );

		$post_content = $result['overview'] ?? $result['description'] ?? '';
		if ( empty( trim( $post_content ) ) ) {
			error_log( 'PSA: WARNING — AI returned valid JSON but overview/description is empty for "' . $peptide_name . '".' );
			update_post_meta( $post_id, 'psa_generation_error', 'AI returned empty overview/description. Fields: ' . implode( ', ', array_keys( $result ) ) );
			return;
		}

		// PubChem enrichment for molecular data.
		$pubchem_data = null;
		if ( ! empty( $options['use_pubchem'] ) ) {
			$pubchem_result = PSA_PubChem::lookup( $peptide_name );
			if ( is_wp_error( $pubchem_result ) ) {
				error_log( 'PSA: PubChem lookup failed for "' . $peptide_name . '": ' . $pubchem_result->get_error_message() );
			} elseif ( is_array( $pubchem_result ) ) {
				$pubchem_data = $pubchem_result;
				error_log( 'PSA: PubChem data found for "' . $peptide_name . '" (CID: ' . ( $pubchem_data['cid'] ?? 'unknown' ) . ')' );
			}
		}

		$post_status   = ( 'publish' === $options['auto_publish'] ) ? 'publish' : 'draft';
		$update_result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_title'   => sanitize_text_field( $result['name'] ?? $peptide_name ),
				'post_content' => wp_kses_post( $post_content ),
				'post_status'  => $post_status,
			),
			true
		);

		if ( is_wp_error( $update_result ) ) {
			error_log( 'PSA: wp_update_post FAILED for post ' . $post_id . ': ' . $update_result->get_error_message() );
			update_post_meta( $post_id, 'psa_generation_error', 'Failed to update post: ' . $update_result->get_error_message() );
			return;
		}

		PSA_AI_Content::save_peptide_meta( $post_id, $result, $pubchem_data );
		PSA_AI_Content::assign_category_term( $post_id, $result );
		PSA_KB_Builder::create_article( $post_id, $result, $peptide_name, $post_status );

		delete_post_meta( $post_id, 'psa_generation_started' );
		delete_post_meta( $post_id, 'psa_generation_error' );
		update_post_meta( $post_id, 'psa_generation_completed', time() );

		do_action( 'psa_after_generation', $post_id, $result, $peptide_name );
		error_log( 'PSA: Successfully completed generation for "' . $peptide_name . '" (post ' . $post_id . ', status: ' . $post_status . ')' );
	}

	/**
	 * Generate full peptide content via AI.
	 *
	 * @param string $peptide_name The peptide to research.
	 * @return array|WP_Error Parsed peptide data or error.
	 */
	public static function generate_peptide_content( string $peptide_name ) {
		$options = self::get_settings();
		if ( empty( $options['api_key'] ) ) {
			return new WP_Error( 'no_api_key', 'OpenRouter API key is not configured.' );
		}
		if ( PSA_Cost_Tracker::is_budget_exceeded() ) {
			return new WP_Error( 'budget_exceeded', 'Monthly API budget reached.' );
		}

		$prompt   = PSA_AI_Content::build_generation_prompt( $peptide_name );
		$prompt   = apply_filters( 'psa_generation_prompt', $prompt, $peptide_name );
		$response = PSA_OpenRouter::send_request(
			$options['api_key'],
			$options['ai_model'] ? $options['ai_model'] : 'google/gemini-2.5-flash',
			$prompt,
			PSA_Config::GENERATION_MAX_TOKENS,
			'generation',
			$peptide_name
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return PSA_OpenRouter::parse_response( $response );
	}

	/**
	 * Dry-run: estimate costs without making API calls.
	 *
	 * @param string $peptide_name The peptide name to estimate.
	 * @return array { validation: {tokens, cost}, generation: {tokens, cost}, total }
	 */
	public static function dry_run( string $peptide_name ): array {
		$options          = self::get_settings();
		$validation_model = $options['validation_model'] ? $options['validation_model'] : 'google/gemini-2.0-flash-001';
		$generation_model = $options['ai_model'] ? $options['ai_model'] : 'google/gemini-2.5-flash';

		$val_prompt = PSA_AI_Content::build_validation_prompt( $peptide_name );
		$val_tokens = (int) ceil( strlen( $val_prompt ) / 4 );
		$val_cost   = PSA_Cost_Tracker::estimate_cost( $validation_model, $val_tokens, PSA_Config::VALIDATION_MAX_TOKENS );

		$gen_prompt = PSA_AI_Content::build_generation_prompt( $peptide_name );
		$gen_tokens = (int) ceil( strlen( $gen_prompt ) / 4 );
		$gen_cost   = PSA_Cost_Tracker::estimate_cost( $generation_model, $gen_tokens, PSA_Config::GENERATION_MAX_TOKENS );

		return array(
			'validation'               => array(
				'tokens'             => $val_tokens + PSA_Config::VALIDATION_MAX_TOKENS,
				'estimated_cost_usd' => $val_cost,
			),
			'generation'               => array(
				'tokens'             => $gen_tokens + PSA_Config::GENERATION_MAX_TOKENS,
				'estimated_cost_usd' => $gen_cost,
			),
			'total_estimated_cost_usd' => $val_cost + $gen_cost,
		);
	}

	// ── Private helpers ─────────────────────────────────────────────────

	/**
	 * Get plugin settings with defaults, decrypted API key, and constant override.
	 *
	 * @return array
	 */
	private static function get_settings(): array {
		$defaults = array(
			'api_key'          => '',
			'ai_model'         => '',
			'validation_model' => '',
			'auto_publish'     => 'draft',
			'use_pubchem'      => '1',
		);
		$settings = wp_parse_args( get_option( 'psa_settings', array() ), $defaults );

		if ( ! empty( $settings['api_key'] ) && ! ( defined( 'PSA_OPENROUTER_KEY' ) && PSA_OPENROUTER_KEY ) ) {
			$decrypted = PSA_Encryption::decrypt( $settings['api_key'] );
			if ( false !== $decrypted ) {
				$settings['api_key'] = $decrypted;
			}
		}

		// wp-config.php constant takes precedence for security.
		if ( defined( 'PSA_OPENROUTER_KEY' ) && PSA_OPENROUTER_KEY ) {
			$settings['api_key'] = PSA_OPENROUTER_KEY;
		}

		return apply_filters( 'psa_ai_settings', $settings );
	}

	/**
	 * Validate peptide name input characters and block injection patterns.
	 *
	 * @param string $name The peptide name to validate.
	 * @return bool True if input looks legitimate.
	 */
	private static function validate_peptide_input( string $name ): bool {
		if ( ! preg_match( '/^[\p{L}\d\s\-\(\),\.\/\+\[\]]+$/u', $name ) ) {
			return false;
		}
		$blocked = array( 'ignore', 'instruction', 'override', 'system prompt', 'forget', 'disregard', 'pretend' );
		$lower   = strtolower( $name );
		foreach ( $blocked as $word ) {
			if ( strpos( $lower, $word ) !== false ) {
				return false;
			}
		}
		return true;
	}

	// ── Backward-compatible proxies ─────────────────────────────────────

	/** @see PSA_AI_Content::build_validation_prompt() */
	public static function build_validation_prompt( string $name ): string {
		return PSA_AI_Content::build_validation_prompt( $name ); }

	/** @see PSA_AI_Content::build_generation_prompt() */
	public static function build_generation_prompt( string $peptide_name ): string {
		return PSA_AI_Content::build_generation_prompt( $peptide_name ); }

	/** @see PSA_AI_Content::save_peptide_meta() */
	public static function save_peptide_meta( int $post_id, array $ai_data, ?array $pubchem_data ): void {
		PSA_AI_Content::save_peptide_meta( $post_id, $ai_data, $pubchem_data ); }

	/** @see PSA_AI_Content::assign_category_term() */
	public static function assign_category_term( int $post_id, array $ai_data ): void {
		PSA_AI_Content::assign_category_term( $post_id, $ai_data ); }
}
