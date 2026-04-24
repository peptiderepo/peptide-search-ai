<?php
/**
 * Logs API usage costs and enforces monthly budget limits.
 *
 * What: Table management, API call logging, cost estimation, budget enforcement.
 * Who calls it: PSA_OpenRouter (logs calls), PSA_AI_Generator (checks budget).
 * Dependencies: WordPress $wpdb, PSA_Config, PSA_Cost_Reporter (for spend queries).
 *
 * @package PeptideSearchAI
 * @since   1.0.0
 * @see     includes/class-psa-cost-reporter.php — Read-side: monthly summaries, recent logs.
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSA_Cost_Tracker {

	/** Create the API logs table if it doesn't exist. Uses dbDelta for safe schema updates. */
	public static function create_table(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'psa_api_logs';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id BIGINT AUTO_INCREMENT PRIMARY KEY,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			provider VARCHAR(50) NOT NULL DEFAULT 'openrouter',
			model VARCHAR(100) NOT NULL,
			prompt_tokens INT NOT NULL DEFAULT 0,
			completion_tokens INT NOT NULL DEFAULT 0,
			total_tokens INT NOT NULL DEFAULT 0,
			estimated_cost_usd DECIMAL(10,6) NOT NULL DEFAULT 0,
			request_type VARCHAR(20) NOT NULL,
			peptide_name VARCHAR(200) DEFAULT '',
			success TINYINT(1) NOT NULL DEFAULT 1,
			token_source VARCHAR(20) NOT NULL DEFAULT 'api',
			INDEX idx_created_at (created_at),
			INDEX idx_request_type (request_type)
		) $charset;";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		if ( ! empty( $wpdb->last_error ) ) {
			error_log( 'PSA: Table creation error: ' . $wpdb->last_error );
		}
	}

	/**
	 * Log an API call with token counts and cost.
	 *
	 * @param array $data {
	 *     @type string $provider, @type string $model, @type int $prompt_tokens,
	 *     @type int $completion_tokens, @type int $total_tokens, @type string $request_type,
	 *     @type string $peptide_name, @type bool $success, @type float $estimated_cost_usd,
	 *     @type string $token_source  'api'|'estimated'|'none'
	 * }
	 * @return int|false Insert ID or false on error.
	 */
	public static function log_api_call( array $data ) {
		global $wpdb;

		$table             = $wpdb->prefix . 'psa_api_logs';
		$prompt_tokens     = (int) ( $data['prompt_tokens'] ?? 0 );
		$completion_tokens = (int) ( $data['completion_tokens'] ?? 0 );
		$total_tokens      = (int) ( $data['total_tokens'] ?? 0 );
		$estimated_cost    = floatval( $data['estimated_cost_usd'] ?? 0 );

		if ( 0 === $total_tokens && ( $prompt_tokens > 0 || $completion_tokens > 0 ) ) {
			$total_tokens = $prompt_tokens + $completion_tokens;
		}
		if ( 0 === $estimated_cost && $prompt_tokens > 0 ) {
			$estimated_cost = self::estimate_cost( $data['model'] ?? '', $prompt_tokens, $completion_tokens );
		}

		$insert_data = array(
			'provider'           => sanitize_text_field( $data['provider'] ?? 'openrouter' ),
			'model'              => sanitize_text_field( $data['model'] ?? '' ),
			'prompt_tokens'      => $prompt_tokens,
			'completion_tokens'  => $completion_tokens,
			'total_tokens'       => $total_tokens,
			'estimated_cost_usd' => $estimated_cost,
			'request_type'       => sanitize_text_field( $data['request_type'] ?? 'generation' ),
			'peptide_name'       => sanitize_text_field( $data['peptide_name'] ?? '' ),
			'success'            => ! empty( $data['success'] ) ? 1 : 0,
			'token_source'       => sanitize_text_field( $data['token_source'] ?? 'api' ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->insert( $table, $insert_data, array( '%s', '%s', '%d', '%d', '%d', '%f', '%s', '%s', '%d', '%s' ) );

		if ( false === $result ) {
			error_log( 'PSA: Failed to insert API log: ' . $wpdb->last_error );
			return false;
		}

		return $wpdb->insert_id;
	}

	/**
	 * Check if current month's budget has been exceeded. Budget of 0 = unlimited.
	 *
	 * @return bool True if budget exceeded.
	 */
	public static function is_budget_exceeded(): bool {
		$settings = get_option( 'psa_settings', array() );
		$budget   = floatval( $settings['monthly_budget'] ?? PSA_Config::DEFAULT_MONTHLY_BUDGET );

		if ( 0.0 === $budget ) {
			return false;
		}

		return PSA_Cost_Reporter::get_monthly_spend() >= $budget;
	}

	/**
	 * Estimate cost for an API call based on model and token counts.
	 *
	 * @param string $model             Model identifier.
	 * @param int    $prompt_tokens     Tokens in prompt.
	 * @param int    $completion_tokens Tokens in completion.
	 * @return float Estimated cost in USD.
	 */
	public static function estimate_cost( string $model, int $prompt_tokens, int $completion_tokens ): float {
		// Per 1M tokens (input, output). Conservative default for unknown models.
		$pricing = array(
			'google/gemini-2.5-flash'     => array( 0.15, 0.60 ),
			'google/gemini-2.0-flash-001' => array( 0.10, 0.40 ),
			'deepseek/deepseek-v3.2'      => array( 0.27, 1.10 ),
			'deepseek/deepseek-chat'      => array( 0.27, 1.10 ),
			'deepseek/deepseek-r1'        => array( 0.55, 2.19 ),
			'qwen/qwen3.6-plus:free'      => array( 0.00, 0.00 ),
		);

		$model = sanitize_text_field( $model );
		if ( isset( $pricing[ $model ] ) ) {
			list( $in, $out ) = $pricing[ $model ];
		} else {
			$in  = 1.0;
			$out = 2.0;
		}

		return (float) ( ( $in / 1000000 ) * $prompt_tokens + ( $out / 1000000 ) * $completion_tokens );
	}

	/** Drop the API logs table during uninstall. */
	public static function drop_table(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'psa_api_logs' );
	}

	// ── Backward-compatible proxies (reporting moved to PSA_Cost_Reporter) ──

	/** @see PSA_Cost_Reporter::get_monthly_spend() */
	public static function get_monthly_spend( ?int $year = null, ?int $month = null ): float {
		return PSA_Cost_Reporter::get_monthly_spend( $year, $month ); }

	/** @see PSA_Cost_Reporter::get_monthly_tokens() */
	public static function get_monthly_tokens( ?int $year = null, ?int $month = null ): int {
		return PSA_Cost_Reporter::get_monthly_tokens( $year, $month ); }

	/** @see PSA_Cost_Reporter::get_monthly_estimated_count() */
	public static function get_monthly_estimated_count( ?int $year = null, ?int $month = null ): int {
		return PSA_Cost_Reporter::get_monthly_estimated_count( $year, $month ); }

	/** @see PSA_Cost_Reporter::get_recent_logs() */
	public static function get_recent_logs( int $limit = 20 ): array {
		return PSA_Cost_Reporter::get_recent_logs( $limit ); }
}
