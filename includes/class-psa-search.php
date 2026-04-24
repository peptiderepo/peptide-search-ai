<?php
/**
 * Core search, caching, shortcode rendering, and REST endpoint for peptides.
 *
 * What: Shortcode rendering, REST search, transient caching, result formatting.
 * Who calls it: WordPress shortcode parser, REST API, PSA_Search_Handler (for search_peptides).
 * Dependencies: PSA_Config, PSA_Search_Handler (for AJAX/generation workflow).
 *
 * @package PeptideSearchAI
 * @see     includes/class-psa-search-handler.php — AJAX handler and generation workflow.
 * @see     includes/class-psa-config.php
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSA_Search {

	/** @var int Static counter for unique shortcode instance IDs. */
	private static $instance_count = 0;

	public static function init(): void {
		add_shortcode( 'peptide_search', array( __CLASS__, 'render_search_form' ) );
		add_action( 'wp_ajax_psa_search', array( 'PSA_Search_Handler', 'ajax_search' ) );
		add_action( 'wp_ajax_nopriv_psa_search', array( 'PSA_Search_Handler', 'ajax_search' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( 'save_post_peptide', array( __CLASS__, 'invalidate_search_cache' ) );
	}

	/**
	 * Get the current cache generation counter.
	 *
	 * Used to invalidate all psa_search_* caches without expensive DELETE queries.
	 * When a peptide is saved, we increment the generation, causing all searches
	 * to use a new cache key and bypass stale results.
	 *
	 * @return int Current generation counter.
	 */
	private static function get_cache_generation(): int {
		return (int) get_option( 'psa_search_cache_gen', 0 );
	}

	/**
	 * Invalidate all search caches by incrementing the generation counter.
	 *
	 * O(1) option update vs. O(n) DELETE query.
	 *
	 * @param int $post_id The post ID being saved.
	 */
	public static function invalidate_search_cache( int $post_id ): void {
		update_option( 'psa_search_cache_gen', self::get_cache_generation() + 1 );
	}

	/**
	 * Flush all search caches immediately via DELETE query.
	 *
	 * WARNING: Expensive — only use for manual admin actions, not on every save.
	 */
	public static function flush_all_search_caches(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_psa_search_%'
			   OR option_name LIKE '_transient_timeout_psa_search_%'"
		);
	}

	/** Register REST API route for external/headless access. */
	public static function register_rest_routes(): void {
		register_rest_route(
			'peptides/v1',
			'/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_search' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'q' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function ( $param ) {
							return is_string( $param ) && strlen( $param ) >= PSA_Config::MIN_QUERY_LENGTH;
						},
					),
				),
			)
		);
	}

	/**
	 * REST search callback — returns published peptides only (no generation trigger).
	 *
	 * Uses dual-layer rate limiting (transient + object cache) for resilience.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response
	 */
	public static function rest_search( $request ) {
		$ip       = psa_get_client_ip();
		$rate_key = 'psa_rest_rate_' . md5( $ip );

		$count_transient = (int) get_transient( $rate_key );
		$count_cache     = (int) wp_cache_get( $rate_key, 'psa_rate_limits' );
		$count           = max( $count_transient, $count_cache );

		if ( $count >= PSA_Config::REST_RATE_LIMIT ) {
			return new WP_REST_Response( array( 'error' => 'Rate limit exceeded. Please try again later.' ), 429 );
		}

		set_transient( $rate_key, $count + 1, HOUR_IN_SECONDS );
		wp_cache_set( $rate_key, $count + 1, 'psa_rate_limits', HOUR_IN_SECONDS );

		$query = $request->get_param( 'q' );

		if ( mb_strlen( $query ) > PSA_Config::MAX_QUERY_LENGTH ) {
			return new WP_REST_Response(
				array( 'error' => 'Search query must not exceed ' . PSA_Config::MAX_QUERY_LENGTH . ' characters.' ),
				400
			);
		}

		return new WP_REST_Response( self::search_peptides( $query ), 200 );
	}

	/**
	 * Render the [peptide_search] shortcode.
	 *
	 * Uses class-based selectors (no IDs) to support multiple instances per page.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render_search_form( $atts ): string {
		++self::$instance_count;
		$instance = self::$instance_count;

		$atts = shortcode_atts(
			array( 'placeholder' => 'Search for a peptide (e.g., BPC-157, Thymosin Beta-4)...' ),
			$atts,
			'peptide_search'
		);

		ob_start();
		?>
		<div class="psa-search-wrap" data-psa-instance="<?php echo esc_attr( $instance ); ?>">
			<form class="psa-search-form" role="search" autocomplete="off">
				<div class="psa-search-input-wrap">
					<input type="text" class="psa-search-input" placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>" aria-label="Search peptide database" minlength="2" required />
					<button type="submit" class="psa-search-btn">
						<span class="psa-search-icon">&#128269;</span> Search
					</button>
				</div>
			</form>
			<div class="psa-search-results" aria-live="polite" aria-busy="false" style="display:none;"></div>
			<div class="psa-checking" role="status" style="display:none;">
				<div class="psa-checking-inner">
					<div class="psa-spinner"></div>
					<p class="psa-checking-text">Searching our database...</p>
				</div>
			</div>
			<div class="psa-pending" role="status" style="display:none;">
				<div class="psa-pending-inner">
					<div class="psa-pending-icon">&#9203;</div>
					<p>This peptide is currently being added to our database. Please check back again later.</p>
				</div>
			</div>
			<div class="psa-invalid" role="status" style="display:none;">
				<div class="psa-invalid-inner">
					<p class="psa-invalid-text">Peptide not found.</p>
				</div>
			</div>
			<div class="psa-error" role="status" style="display:none;"></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Find an existing pending peptide post by name or alias.
	 *
	 * @param string $query Search term (peptide name or alias).
	 * @return WP_Post|null Pending post or null.
	 */
	public static function find_pending_peptide( string $query ) {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $query ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} src ON p.ID = src.post_id
					AND src.meta_key = 'psa_source' AND src.meta_value = 'pending'
				LEFT JOIN {$wpdb->postmeta} alias ON p.ID = alias.post_id
					AND alias.meta_key = 'psa_aliases'
				WHERE p.post_type = 'peptide'
					AND p.post_status = 'draft'
					AND ( p.post_title LIKE %s OR alias.meta_value LIKE %s )
				ORDER BY p.post_date DESC
				LIMIT 1",
				$like,
				$like
			)
		);

		return $post_id ? get_post( $post_id ) : null;
	}

	/**
	 * Core search — queries CPT by title and aliases (published only).
	 *
	 * Includes transient caching with generation-counter invalidation.
	 *
	 * @param string $query Search term.
	 * @return array{results: array, total: int, query: string}
	 */
	public static function search_peptides( string $query ): array {
		global $wpdb;

		$cache_key = 'psa_search_' . self::get_cache_generation() . '_' . md5( sanitize_text_field( $query ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$like = '%' . $wpdb->esc_like( $query ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = 'psa_aliases'
				WHERE p.post_type = 'peptide'
					AND p.post_status = 'publish'
					AND ( p.post_title LIKE %s OR m.meta_value LIKE %s )
				ORDER BY p.post_title ASC
				LIMIT 20",
				$like,
				$like
			)
		);

		if ( ! empty( $post_ids ) ) {
			_prime_post_caches( $post_ids );
			update_meta_cache( 'post', $post_ids );
		}

		$results = array();
		foreach ( $post_ids as $id ) {
			$results[] = self::format_peptide( (int) $id );
		}

		$response = array(
			'results' => $results,
			'total'   => count( $results ),
			'query'   => $query,
		);

		set_transient( $cache_key, $response, 6 * HOUR_IN_SECONDS );

		return apply_filters( 'psa_search_results', $response, $query );
	}

	/**
	 * Format a peptide post for JSON response.
	 *
	 * @param int $post_id The post ID.
	 * @return array
	 */
	public static function format_peptide( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		return array(
			'id'                => $post->ID,
			'title'             => $post->post_title,
			'url'               => get_permalink( $post->ID ),
			'excerpt'           => wp_trim_words( $post->post_content, 30 ),
			'sequence'          => get_post_meta( $post->ID, 'psa_sequence', true ),
			'molecular_weight'  => get_post_meta( $post->ID, 'psa_molecular_weight', true ),
			'molecular_formula' => get_post_meta( $post->ID, 'psa_molecular_formula', true ),
			'source'            => get_post_meta( $post->ID, 'psa_source', true ),
		);
	}

	// ── Backward-compatible proxies ─────────────────────────────────────

	/** @see PSA_Search_Handler::ajax_search() */
	public static function ajax_search(): void {
		PSA_Search_Handler::ajax_search(); }

	/** @see PSA_Search_Handler::create_pending_placeholder() */
	public static function create_pending_placeholder( string $peptide_name ) {
		return PSA_Search_Handler::create_pending_placeholder( $peptide_name ); }

	/** @see PSA_Search_Handler::schedule_background_generation() */
	public static function schedule_background_generation( int $post_id, string $peptide_name ): void {
		PSA_Search_Handler::schedule_background_generation( $post_id, $peptide_name ); }
}
