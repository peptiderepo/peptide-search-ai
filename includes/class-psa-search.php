<?php
/**
 * Handles AJAX search, the [peptide_search] shortcode, and the REST API endpoint.
 * When a peptide is not found, it automatically validates and queues generation.
 *
 * @package PeptideSearchAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSA_Search {

	/**
	 * Static counter for unique shortcode instance IDs.
	 *
	 * @var int
	 */
	private static $instance_count = 0;

	public static function init() {
		add_shortcode( 'peptide_search', array( __CLASS__, 'render_search_form' ) );
		add_action( 'wp_ajax_psa_search', array( __CLASS__, 'ajax_search' ) );
		add_action( 'wp_ajax_nopriv_psa_search', array( __CLASS__, 'ajax_search' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		// Cache invalidation when a peptide is saved.
		add_action( 'save_post_peptide', array( __CLASS__, 'invalidate_search_cache' ) );
	}

	/**
	 * Invalidate all search caches when a peptide is published/updated.
	 * Since we can't know which search queries would be affected, clear all caches.
	 *
	 * @param int $post_id The post ID being saved.
	 */
	public static function invalidate_search_cache( $post_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_psa_search_%'
			   OR option_name LIKE '_transient_timeout_psa_search_%'"
		);
	}

	/**
	 * Register REST API routes for external/headless access.
	 * Note: REST route only returns existing published results — it does NOT trigger generation.
	 */
	public static function register_rest_routes() {
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
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response
	 */
	public static function rest_search( $request ) {
		// Rate limiting: max REST_RATE_LIMIT requests per IP per hour.
		$ip       = psa_get_client_ip();
		$rate_key = 'psa_rest_rate_' . md5( $ip );
		$count    = (int) get_transient( $rate_key );

		if ( $count >= PSA_Config::REST_RATE_LIMIT ) {
			return new WP_REST_Response(
				array( 'error' => 'Rate limit exceeded. Please try again later.' ),
				429
			);
		}

		set_transient( $rate_key, $count + 1, HOUR_IN_SECONDS );

		$query = $request->get_param( 'q' );

		// Max input length validation.
		if ( mb_strlen( $query ) > PSA_Config::MAX_QUERY_LENGTH ) {
			return new WP_REST_Response(
				array( 'error' => 'Search query must not exceed ' . PSA_Config::MAX_QUERY_LENGTH . ' characters.' ),
				400
			);
		}

		$results = self::search_peptides( $query );
		return new WP_REST_Response( $results, 200 );
	}

	/**
	 * Render the [peptide_search] shortcode.
	 *
	 * Uses class-based selectors (no IDs) to support multiple shortcode instances
	 * on the same page (e.g., hero section + search overlay). Each instance is
	 * independently functional via scoped JS initialization.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render_search_form( $atts ) {
		self::$instance_count++;
		$instance = self::$instance_count;

		$atts = shortcode_atts(
			array(
				'placeholder' => 'Search for a peptide (e.g., BPC-157, Thymosin Beta-4)...',
			),
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
	 * AJAX handler: search for peptides.
	 * Orchestrates complete workflow: search, pending check, rate limiting, validation, generation.
	 *
	 * @return void Exits with wp_send_json_* responses.
	 */
	public static function ajax_search() {
		check_ajax_referer( 'psa_search_nonce', 'nonce' );

		$query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';

		// Validate query length using PSA_Config constants.
		if ( mb_strlen( $query ) < PSA_Config::MIN_QUERY_LENGTH || mb_strlen( $query ) > PSA_Config::MAX_QUERY_LENGTH ) {
			wp_send_json_error( 'Search query must be between ' . PSA_Config::MIN_QUERY_LENGTH . ' and ' . PSA_Config::MAX_QUERY_LENGTH . ' characters.' );
		}

		// 1. Search existing published peptides.
		$results = self::search_peptides( $query );

		if ( ! empty( $results['results'] ) ) {
			wp_send_json_success(
				array(
					'status' => 'found',
					'data'   => $results,
				)
			);
			return;
		}

		// 2. Check if already pending — handles retries if timed out.
		$pending = self::find_pending_peptide( $query );
		if ( $pending ) {
			self::handle_pending_retry( $pending );
			return; // handle_pending_retry() exits with wp_send_json_success()
		}

		// 3. Check rate limit before attempting new generation.
		if ( ! self::check_rate_limit() ) {
			wp_send_json_success(
				array(
					'status'  => 'rate_limited',
					'message' => 'Too many requests. Please try again later.',
				)
			);
			return;
		}

		// 4. Validate and generate.
		self::handle_new_generation( $query );
		return; // handle_new_generation() exits with wp_send_json_*()
	}

	/**
	 * Handle a pending peptide — retry if timed out, else return pending status.
	 *
	 * @param WP_Post $pending The pending post.
	 * @return void Exits with wp_send_json_success().
	 */
	private static function handle_pending_retry( $pending ) {
		$started     = (int) get_post_meta( $pending->ID, 'psa_generation_started', true );
		$retry_count = (int) get_post_meta( $pending->ID, 'psa_generation_attempts', true );
		$timed_out   = $started && ( time() - $started ) > PSA_Config::PENDING_TIMEOUT;

		if ( $timed_out ) {
			if ( $retry_count >= PSA_Config::MAX_GENERATION_RETRIES ) {
				// Max retries exceeded — mark as failed.
				error_log( 'PSA: Max retries (' . PSA_Config::MAX_GENERATION_RETRIES . ') exceeded for "' . $pending->post_title . '" (post ' . $pending->ID . '). Marking as failed.' );
				update_post_meta( $pending->ID, 'psa_source', 'failed' );
				update_post_meta( $pending->ID, 'psa_generation_error', 'Generation failed after ' . PSA_Config::MAX_GENERATION_RETRIES . ' attempts.' );
				wp_send_json_success(
					array(
						'status'  => 'invalid',
						'message' => 'We were unable to generate content for this peptide. Please try again later.',
					)
				);
				return;
			}

			// Retry generation.
			error_log( 'PSA: Retrying stale pending peptide ' . $pending->post_title . ' (post ' . $pending->ID . ', attempt ' . ( $retry_count + 1 ) . '/' . PSA_Config::MAX_GENERATION_RETRIES . ')' );
			update_post_meta( $pending->ID, 'psa_generation_started', time() );
			update_post_meta( $pending->ID, 'psa_generation_attempts', $retry_count + 1 );
			self::schedule_background_generation( $pending->ID, $pending->post_title );
		}

		wp_send_json_success(
			array(
				'status'       => 'pending',
				'peptide_name' => $pending->post_title,
			)
		);
	}

	/**
	 * Check rate limit for AJAX generation requests.
	 *
	 * @return bool True if under limit, false if limit exceeded.
	 */
	private static function check_rate_limit() {
		$ip       = psa_get_client_ip();
		$rate_key = 'psa_rate_' . md5( $ip );
		$count    = (int) get_transient( $rate_key );

		return $count < PSA_Config::AJAX_RATE_LIMIT;
	}

	/**
	 * Handle a new generation request — validate, create placeholder, and schedule generation.
	 *
	 * @param string $query The search query (peptide name).
	 * @return void Exits with wp_send_json_* responses.
	 */
	private static function handle_new_generation( $query ) {
		// Global daily generation cap to prevent cost overruns.
		$daily_key   = 'psa_daily_gen_' . gmdate( 'Y-m-d' );
		$daily_count = (int) get_transient( $daily_key );
		if ( $daily_count >= PSA_Config::DAILY_GENERATION_CAP ) {
			wp_send_json_success(
				array(
					'status'  => 'rate_limited',
					'message' => 'Our system has reached its daily limit for adding new peptides. Please try again tomorrow.',
				)
			);
			return;
		}

		// Validate peptide name via AI.
		$validation = PSA_AI_Generator::validate_peptide_name( $query );

		if ( is_wp_error( $validation ) ) {
			error_log( 'PSA: Validation error for "' . $query . '": ' . $validation->get_error_message() );
			wp_send_json_success(
				array(
					'status'  => 'invalid',
					'message' => 'Could not verify this peptide name.',
				)
			);
		}

		if ( empty( $validation['is_valid'] ) ) {
			wp_send_json_success(
				array(
					'status'  => 'invalid',
					'message' => $validation['reason'] ?? 'This does not appear to be a recognized peptide.',
				)
			);
		}

		// It's a real peptide — increment rate limit and create placeholder.
		$ip       = psa_get_client_ip();
		$rate_key = 'psa_rate_' . md5( $ip );
		$count    = (int) get_transient( $rate_key );
		set_transient( $rate_key, $count + 1, HOUR_IN_SECONDS );

		$canonical_name = sanitize_text_field( $validation['canonical_name'] ?? $query );
		$placeholder_id = self::create_pending_placeholder( $canonical_name );

		if ( is_wp_error( $placeholder_id ) ) {
			wp_send_json_error( 'Failed to create peptide entry. Please try again.' );
		}

		// Increment global daily generation counter.
		$daily_key   = 'psa_daily_gen_' . gmdate( 'Y-m-d' );
		$daily_count = (int) get_transient( $daily_key );
		set_transient( $daily_key, $daily_count + 1, DAY_IN_SECONDS );

		// Schedule async background generation.
		error_log( 'PSA: Scheduling async generation for "' . $canonical_name . '" (post ' . $placeholder_id . ')' );
		self::schedule_background_generation( $placeholder_id, $canonical_name );

		wp_send_json_success(
			array(
				'status'       => 'pending',
				'peptide_name' => $canonical_name,
			)
		);
	}

	/**
	 * Find an existing pending peptide post by name.
	 *
	 * Performs a multi-table query to locate pending draft posts by title or alias.
	 * Queries the posts table joined with postmeta for 'psa_source' = 'pending' status,
	 * plus LEFT JOIN on postmeta for aliases. Returns the most recent match.
	 *
	 * @param string $query Search term (peptide name or alias).
	 * @return WP_Post|null Pending post object or null if not found.
	 */
	public static function find_pending_peptide( $query ) {
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
	 * Create a placeholder draft post so we can track the pending state.
	 *
	 * @param string $peptide_name The canonical peptide name.
	 * @return int|WP_Error Post ID or error.
	 */
	public static function create_pending_placeholder( $peptide_name ) {
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
			// Allow developers to hook into placeholder creation.
			do_action( 'psa_peptide_created', $post_id, $peptide_name );
		}

		return $post_id;
	}

	/**
	 * Schedule background generation using WP-Cron (non-blocking).
	 *
	 * @param int    $post_id      The placeholder post ID.
	 * @param string $peptide_name The peptide to research.
	 */
	public static function schedule_background_generation( $post_id, $peptide_name ) {
		$args = array( $post_id, $peptide_name );
		if ( ! wp_next_scheduled( 'psa_generate_peptide_background', $args ) ) {
			wp_schedule_single_event( time(), 'psa_generate_peptide_background', $args );
		}
		spawn_cron();
	}

	/**
	 * Core search logic — queries CPT by title and aliases (published only).
	 * Includes transient caching and batch post/meta priming to avoid N+1 queries.
	 *
	 * @param string $query Search term.
	 * @return array { results: array, total: int, query: string }
	 */
	public static function search_peptides( $query ) {
		global $wpdb;

		// Check transient cache first.
		$cache_key = 'psa_search_' . md5( sanitize_text_field( $query ) );
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
			// Prime the WordPress object cache for all posts and their meta in batch.
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

		// Cache results for 6 hours.
		set_transient( $cache_key, $response, 6 * HOUR_IN_SECONDS );

		// Allow filtering of search results before return.
		$response = apply_filters( 'psa_search_results', $response, $query );

		return $response;
	}

	/**
	 * Format a peptide post for JSON response.
	 *
	 * @param int $post_id The post ID.
	 * @return array
	 */
	public static function format_peptide( $post_id ) {
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
}
