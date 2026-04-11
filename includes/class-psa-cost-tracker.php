<?php
/**
 * Tracks API usage costs and enforces monthly budget limits.
 *
 * What: Logs every OpenRouter API call with token counts and estimated cost.
 *       Provides budget enforcement and usage summaries for the admin UI.
 * Who calls it: PSA_AI_Generator (logs calls, checks budget), PSA_Admin (displays usage).
 * Dependencies: WordPress $wpdb, PSA_Config.
 *
 * @package PeptideSearchAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSA_Cost_Tracker {

	/**
	 * Create the API logs table if it doesn't exist.
	 * Uses dbDelta for safe schema updates.
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'psa_api_logs';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
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
			INDEX idx_created_at (created_at),
			INDEX idx_request_type (request_type)
		) $charset_collate;";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Check for errors during table creation.
		if ( ! empty( $wpdb->last_error ) ) {
			error_log( 'PSA: Table creation error: ' . $wpdb->last_error );
		}
	}

	/**
	 * Log an API call with token counts and cost.
	 *
	 * @param array $data {
	 *     @type string   $provider             API provider (default: 'openrouter')
	 *     @type string   $model                Model identifier
	 *     @type int      $prompt_tokens        Tokens in prompt
	 *     @type int      $completion_tokens    Tokens in completion
	 *     @type int      $total_tokens         Total tokens (or 0 to auto-sum)
	 *     @type string   $request_type         'validation' or 'generation'
	 *     @type string   $peptide_name         Name of peptide being processed
	 *     @type bool     $success              Whether API call succeeded
	 *     @type float    $estimated_cost_usd   Cost (or 0 to auto-calculate)
	 * }
	 * @return int|false Insert ID or false on error.
	 */
	public static function log_api_call( $data ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'psa_api_logs';

		// Sanitize and prepare data.
		$provider      = $data['provider'] ?? 'openrouter';
		$model         = $data['model'] ?? '';
		$prompt_tokens = (int) ( $data['prompt_tokens'] ?? 0 );
		$completion_tokens = (int) ( $data['completion_tokens'] ?? 0 );
		$total_tokens  = (int) ( $data['total_tokens'] ?? 0 );
		$request_type  = $data['request_type'] ?? 'generation';
		$peptide_name  = $data['peptide_name'] ?? '';
		$success       = ! empty( $data['success'] ) ? 1 : 0;
		$estimated_cost = floatval( $data['estimated_cost_usd'] ?? 0 );

		// Auto-sum tokens if not provided.
		if ( 0 === $total_tokens && ( $prompt_tokens > 0 || $completion_tokens > 0 ) ) {
			$total_tokens = $prompt_tokens + $completion_tokens;
		}

		// Auto-calculate cost if not provided.
		if ( 0 === $estimated_cost && $prompt_tokens > 0 ) {
			$estimated_cost = self::estimate_cost( $model, $prompt_tokens, $completion_tokens );
		}

		$insert_data = array(
			'provider'           => sanitize_text_field( $provider ),
			'model'              => sanitize_text_field( $model ),
			'prompt_tokens'      => $prompt_tokens,
			'completion_tokens'  => $completion_tokens,
			'total_tokens'       => $total_tokens,
			'estimated_cost_usd' => $estimated_cost,
			'request_type'       => sanitize_text_field( $request_type ),
			'peptide_name'       => sanitize_text_field( $peptide_name ),
			'success'            => $success,
		);

		$insert_format = array( '%s', '%s', '%d', '%d', '%d', '%f', '%s', '%s', '%d' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->insert( $table_name, $insert_data, $insert_format );

		if ( false === $result ) {
			error_log( 'PSA: Failed to insert API log: ' . $wpdb->last_error );
			return false;
		}

		return $wpdb->insert_id;
	}

	/**
	 * Get total spend for a given month.
	 *
	 * @param int|null $year  Year (defaults to current year).
	 * @param int|null $month Month (defaults to current month).
	 * @return float Total USD spent in the month.
	 */
	public static function get_monthly_spend( $year = null, $month = null ) {
		global $wpdb;

		if ( null === $year ) {
			$year = (int) gmdate( 'Y' );
		}
		if ( null === $month ) {
			$month = (int) gmdate( 'm' );
		}

		$table_name = $wpdb->prefix . 'psa_api_logs';

		// Ensure valid month.
		$month = max( 1, min( 12, (int) $month ) );

		// Build date range for the month.
		$start_date = gmdate( 'Y-m-01', mktime( 0, 0, 0, $month, 1, $year ) );
		$end_date   = gmdate( 'Y-m-t', mktime( 0, 0, 0, $month, 1, $year ) );
		$end_date   = $end_date . ' 23:59:59';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(estimated_cost_usd), 0) FROM $table_name WHERE created_at >= %s AND created_at <= %s",
				$start_date,
				$end_date
			)
		);

		return floatval( $result );
	}

	/**
	 * Get total token usage for a given month.
	 *
	 * @param int|null $year  Year (defaults to current year).
	 * @param int|null $month Month (defaults to current month).
	 * @return int Total tokens used in the month.
	 */
	public static function get_monthly_tokens( $year = null, $month = null ) {
		global $wpdb;

		if ( null === $year ) {
			$year = (int) gmdate( 'Y' );
		}
		if ( null === $month ) {
			$month = (int) gmdate( 'm' );
		}

		$table_name = $wpdb->prefix . 'psa_api_logs';

		// Ensure valid month.
		$month = max( 1, min( 12, (int) $month ) );

		// Build date range for the month.
		$start_date = gmdate( 'Y-m-01', mktime( 0, 0, 0, $month, 1, $year ) );
		$end_date   = gmdate( 'Y-m-t', mktime( 0, 0, 0, $month, 1, $year ) );
		$end_date   = $end_date . ' 23:59:59';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(total_tokens), 0) FROM $table_name WHERE created_at >= %s AND created_at <= %s",
				$start_date,
				$end_date
			)
		);

		return (int) $result;
	}

	/**
	 * Check if current month's budget has been exceeded.
	 * Budget of 0 means unlimited.
	 *
	 * @return bool True if budget exceeded, false otherwise.
	 */
	public static function is_budget_exceeded() {
		$settings = get_option( 'psa_settings', array() );
		$budget   = floatval( $settings['monthly_budget'] ?? PSA_Config::DEFAULT_MONTHLY_BUDGET );

		// Budget of 0 = unlimited.
		if ( 0 === $budget ) {
			return false;
		}

		$current_spend = self::get_monthly_spend();
		return $current_spend >= $budget;
	}

	/**
	 * Get the most recent API call logs.
	 *
	 * @param int $limit Maximum number of logs to return.
	 * @return array Array of log objects.
	 */
	public static function get_recent_logs( $limit = 20 ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'psa_api_logs';
		$limit      = max( 1, (int) $limit );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d",
				$limit
			)
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Estimate cost for an API call based on model and token counts.
	 * Uses known pricing, defaults to conservative estimate for unknown models.
	 *
	 * @param string $model             Model identifier.
	 * @param int    $prompt_tokens     Tokens in prompt.
	 * @param int    $completion_tokens Tokens in completion.
	 * @return float Estimated cost in USD.
	 */
	public static function estimate_cost( $model, $prompt_tokens, $completion_tokens ) {
		// Known model pricing per 1 million tokens (input, output).
		$pricing = array(
			'google/gemini-2.5-flash'      => array( 0.15, 0.60 ),
			'google/gemini-2.0-flash-001'  => array( 0.10, 0.40 ),
		);

		$model = sanitize_text_field( $model );

		if ( isset( $pricing[ $model ] ) ) {
			list( $input_price, $output_price ) = $pricing[ $model ];
		} else {
			// Conservative default for unknown models.
			$input_price  = 1.0;
			$output_price = 2.0;
		}

		// Convert per 1M to per token.
		$input_cost  = ( $input_price / 1000000 ) * $prompt_tokens;
		$output_cost = ( $output_price / 1000000 ) * $completion_tokens;

		return (float) ( $input_cost + $output_cost );
	}

	/**
	 * Drop the API logs table during uninstall.
	 */
	public static function drop_table() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'psa_api_logs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DROP TABLE IF EXISTS $table_name" );
	}
}
