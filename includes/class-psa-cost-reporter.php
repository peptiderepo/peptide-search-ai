<?php
/**
 * Read-only reporting queries for API usage data.
 *
 * What: Monthly spend/token/estimate-count summaries and recent log retrieval.
 * Who calls it: PSA_Admin_Page (usage summary), PSA_Cost_Tracker (budget check).
 * Dependencies: WordPress $wpdb.
 *
 * @package PeptideSearchAI
 * @since   4.5.0
 * @see     includes/class-psa-cost-tracker.php — Write-side: logging, cost estimation, table management.
 * @see     includes/class-psa-admin-page.php   — Renders usage summary using these queries.
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSA_Cost_Reporter {

	/**
	 * Get total spend for a given month.
	 *
	 * @param int|null $year  Year (defaults to current).
	 * @param int|null $month Month (defaults to current).
	 * @return float Total USD spent.
	 */
	public static function get_monthly_spend( ?int $year = null, ?int $month = null ): float {
		global $wpdb;
		list( $start, $end ) = self::month_range( $year, $month );
		$table = $wpdb->prefix . 'psa_api_logs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(estimated_cost_usd), 0) FROM ' . $table . ' WHERE created_at >= %s AND created_at <= %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$start,
				$end
			)
		);

		return floatval( $result );
	}

	/**
	 * Get total token usage for a given month.
	 *
	 * @param int|null $year  Year (defaults to current).
	 * @param int|null $month Month (defaults to current).
	 * @return int Total tokens.
	 */
	public static function get_monthly_tokens( ?int $year = null, ?int $month = null ): int {
		global $wpdb;
		list( $start, $end ) = self::month_range( $year, $month );
		$table = $wpdb->prefix . 'psa_api_logs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(total_tokens), 0) FROM ' . $table . ' WHERE created_at >= %s AND created_at <= %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$start,
				$end
			)
		);

		return (int) $result;
	}

	/**
	 * Count API calls this month that used character-based token estimates.
	 *
	 * Why: Lets the admin UI flag when reported costs include approximations
	 * so operators can distinguish measured spend from estimated spend.
	 *
	 * @param int|null $year  Year (defaults to current).
	 * @param int|null $month Month (defaults to current).
	 * @return int Number of estimated rows.
	 */
	public static function get_monthly_estimated_count( ?int $year = null, ?int $month = null ): int {
		global $wpdb;
		list( $start, $end ) = self::month_range( $year, $month );
		$table = $wpdb->prefix . 'psa_api_logs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $table . ' WHERE created_at >= %s AND created_at <= %s AND token_source = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$start,
				$end,
				'estimated'
			)
		);

		return (int) $result;
	}

	/**
	 * Get the most recent API call logs.
	 *
	 * @param int $limit Maximum number of logs to return.
	 * @return array Array of log row objects.
	 */
	public static function get_recent_logs( int $limit = 20 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'psa_api_logs';
		$limit = max( 1, (int) $limit );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $table . ' ORDER BY created_at DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$limit
			)
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Build start/end datetime strings for a given month.
	 *
	 * @param int|null $year  Year (defaults to current).
	 * @param int|null $month Month (defaults to current).
	 * @return array{0: string, 1: string} [start_date, end_datetime]
	 */
	private static function month_range( ?int $year, ?int $month ): array {
		$year  = $year ?? (int) gmdate( 'Y' );
		$month = max( 1, min( 12, $month ?? (int) gmdate( 'm' ) ) );

		$start = gmdate( 'Y-m-01', mktime( 0, 0, 0, $month, 1, $year ) );
		$end   = gmdate( 'Y-m-t', mktime( 0, 0, 0, $month, 1, $year ) ) . ' 23:59:59';

		return array( $start, $end );
	}
}
