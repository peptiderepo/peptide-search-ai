<?php
declare( strict_types=1 );
/**
 * Browsable peptide directory — REST endpoint, shortcode, and data formatting.
 *
 * What: Provides the [peptide_directory] shortcode and GET /v1/compounds REST endpoint.
 * Who calls it: WordPress shortcode parser, REST API router, main plugin file via PSA_Directory::init().
 * Dependencies: PSA_Post_Type (meta fields, taxonomy), PSA_Config.
 *
 * @package PeptideSearchAI
 * @see     includes/class-psa-post-type.php   — CPT + taxonomy registration, meta keys
 * @see     includes/class-psa-search.php      — existing search shortcode (separate from directory)
 * @see     assets/js/psa-directory.js          — frontend grid, filter, modal logic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSA_Directory {

	/** Cards per page for directory pagination. */
	const PER_PAGE_DEFAULT = 12;
	const PER_PAGE_MAX     = 100;

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_shortcode( 'peptide_directory', array( __CLASS__, 'render_shortcode' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	// -------------------------------------------------------------------------
	// REST API
	// -------------------------------------------------------------------------

	/**
	 * Register the /v1/compounds endpoint for directory listing and cross-plugin data access.
	 *
	 * Public endpoint (no auth required) — serves the browsable directory.
	 * Rate limiting should be handled at the server/CDN level.
	 */
	public static function register_rest_routes(): void {
		register_rest_route(
			'peptide-search-ai/v1',
			'/compounds',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_compounds' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'search'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'category' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'fields'   => array(
						'type'              => 'string',
						'enum'              => array( 'basic', 'full' ),
						'default'           => 'full',
					),
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => self::PER_PAGE_DEFAULT,
						'minimum'           => 1,
						'maximum'           => self::PER_PAGE_MAX,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * REST callback for GET /v1/compounds.
	 *
	 * Supports search, category filtering, pagination, and basic/full field sets.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response
	 */
	public static function rest_compounds( $request ) {
		$search   = $request->get_param( 'search' );
		$category = $request->get_param( 'category' );
		$fields   = $request->get_param( 'fields' );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( self::PER_PAGE_MAX, max( 1, (int) $request->get_param( 'per_page' ) ) );

		$query_args = array(
			'post_type'      => 'peptide',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		// Search filter: title and alias matching.
		if ( ! empty( $search ) ) {
			$query_args['s'] = $search;
		}

		// Category filter: taxonomy query.
		if ( ! empty( $category ) ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'peptide_category',
					'field'    => 'slug',
					'terms'    => $category,
				),
			);
		}

		$query = new WP_Query( $query_args );

		// Prime caches for performance.
		$post_ids = wp_list_pluck( $query->posts, 'ID' );
		if ( ! empty( $post_ids ) ) {
			update_meta_cache( 'post', $post_ids );
		}

		$compounds = array();
		foreach ( $query->posts as $post ) {
			$compounds[] = self::format_compound( $post, $fields );
		}

		$response_data = array(
			'compounds'  => $compounds,
			'total'      => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'       => $page,
			'per_page'   => $per_page,
		);

		$response = new WP_REST_Response( $response_data, 200 );

		// Pagination headers for client convenience.
		$response->header( 'X-WP-Total', (string) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (string) $query->max_num_pages );

		return $response;
	}

	/**
	 * Format a single compound for REST response.
	 *
	 * @param WP_Post $post   The peptide post.
	 * @param string  $fields 'basic' for lightweight data, 'full' for everything.
	 * @return array Formatted compound data.
	 */
	public static function format_compound( $post, string $fields = 'full' ): array {
		$post_id = $post->ID;

		// Get category terms.
		$terms      = wp_get_object_terms( $post_id, 'peptide_category', array( 'fields' => 'all' ) );
		$categories = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$categories[] = array(
					'slug' => $term->slug,
					'name' => $term->name,
				);
			}
		}

		$basic = array(
			'id'         => $post_id,
			'name'       => $post->post_title,
			'slug'       => $post->post_name,
			'url'        => get_permalink( $post_id ),
			'categories' => $categories,
			'half_life'  => get_post_meta( $post_id, 'psa_half_life', true ),
			'stability'  => get_post_meta( $post_id, 'psa_stability', true ),
			'source'     => get_post_meta( $post_id, 'psa_source', true ),
		);

		// Allow other plugins to add data to directory cards (e.g., observation count).
		$basic['extras'] = apply_filters( 'psa_directory_card_extras', '', $post_id );

		if ( 'basic' === $fields ) {
			// Lightweight response for cross-plugin use.
			$basic['vial_size_mg'] = get_post_meta( $post_id, 'psa_vial_size_mg', true );
			return $basic;
		}

		// Full response: merge in all meta for detail modal.
		$full = array_merge(
			$basic,
			array(
				'excerpt'              => wp_trim_words( $post->post_content, 30 ),
				'description'          => wp_trim_words( $post->post_content, 80 ),
				'sequence'             => get_post_meta( $post_id, 'psa_sequence', true ),
				'molecular_weight'     => get_post_meta( $post_id, 'psa_molecular_weight', true ),
				'molecular_formula'    => get_post_meta( $post_id, 'psa_molecular_formula', true ),
				'aliases'              => get_post_meta( $post_id, 'psa_aliases', true ),
				'mechanism'            => get_post_meta( $post_id, 'psa_mechanism', true ),
				'research_apps'        => get_post_meta( $post_id, 'psa_research_apps', true ),
				'safety_profile'       => get_post_meta( $post_id, 'psa_safety_profile', true ),
				'dosage_info'          => get_post_meta( $post_id, 'psa_dosage_info', true ),
				'references'           => get_post_meta( $post_id, 'psa_references', true ),
				'pubchem_cid'          => get_post_meta( $post_id, 'psa_pubchem_cid', true ),
				'solubility'           => get_post_meta( $post_id, 'psa_solubility', true ),
				'vial_size_mg'         => get_post_meta( $post_id, 'psa_vial_size_mg', true ),
				'storage_lyophilized'  => get_post_meta( $post_id, 'psa_storage_lyophilized', true ),
				'storage_reconstituted' => get_post_meta( $post_id, 'psa_storage_reconstituted', true ),
				'typical_dose_mcg'     => get_post_meta( $post_id, 'psa_typical_dose_mcg', true ),
				'cycle_parameters'     => get_post_meta( $post_id, 'psa_cycle_parameters', true ),
				'amino_acid_count'     => get_post_meta( $post_id, 'psa_amino_acid_count', true ),
			)
		);

		// Allow other plugins (e.g., Peptide Community) to enrich the compound data.
		$full = apply_filters( 'psa_rest_compound_data', $full, $post_id );

		return $full;
	}

	// -------------------------------------------------------------------------
	// Shortcode
	// -------------------------------------------------------------------------

	/**
	 * Render the [peptide_directory] shortcode.
	 *
	 * Outputs a container div that the frontend JS populates with the card grid.
	 * Passes REST endpoint URL and category data via wp_localize_script.
	 *
	 * @param array $atts Shortcode attributes (unused for now, reserved for future).
	 * @return string HTML output.
	 */
	public static function render_shortcode( $atts ): string {
		// Get categories for filter buttons.
		$terms = get_terms(
			array(
				'taxonomy'   => 'peptide_category',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		$categories = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$categories[] = array(
					'slug' => $term->slug,
					'name' => $term->name,
				);
			}
		}

		// Localize script data for the directory JS.
		wp_localize_script(
			'psa-directory',
			'psaDirectory',
			array(
				'restUrl'    => esc_url_raw( rest_url( 'peptide-search-ai/v1/compounds' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'categories' => $categories,
				'perPage'    => self::PER_PAGE_DEFAULT,
				'i18n'       => array(
					'title'        => __( 'Peptide Directory', 'peptide-search-ai' ),
					'subtitle'     => __( 'Browse reagents with detailed research parameters.', 'peptide-search-ai' ),
					'searchPlaceholder' => __( 'Search peptides...', 'peptide-search-ai' ),
					'showAll'      => __( 'Show All', 'peptide-search-ai' ),
					'loadMore'     => __( 'Load More', 'peptide-search-ai' ),
					'loading'      => __( 'Loading...', 'peptide-search-ai' ),
					'noResults'    => __( 'No peptides found matching your criteria.', 'peptide-search-ai' ),
					'viewDetails'  => __( 'View Research Details', 'peptide-search-ai' ),
					'viewFullPage' => __( 'View Full Page', 'peptide-search-ai' ),
					'close'        => __( 'Close', 'peptide-search-ai' ),
					'halfLife'     => __( 'Half-Life', 'peptide-search-ai' ),
					'stability'    => __( 'Stability', 'peptide-search-ai' ),
					'solubility'   => __( 'Solubility', 'peptide-search-ai' ),
					'vialSize'     => __( 'Vial Size', 'peptide-search-ai' ),
					'storageLyo'   => __( 'Storage (Lyophilized)', 'peptide-search-ai' ),
					'storageRecon' => __( 'Storage (Reconstituted)', 'peptide-search-ai' ),
					'molecularWeight' => __( 'Molecular Weight', 'peptide-search-ai' ),
					'formula'      => __( 'Formula', 'peptide-search-ai' ),
					'typicalDose'  => __( 'Typical Dose', 'peptide-search-ai' ),
					'cycleParams'  => __( 'Cycle Parameters', 'peptide-search-ai' ),
					'sequence'     => __( 'Sequence', 'peptide-search-ai' ),
					'singleReagents'   => __( 'Single Reagents', 'peptide-search-ai' ),
					'protocolModels'   => __( 'Protocol Models', 'peptide-search-ai' ),
					'comingSoon'       => __( 'Coming Soon', 'peptide-search-ai' ),
					'researchParams'   => __( 'Research Parameters', 'peptide-search-ai' ),
					'communityObs'     => __( 'Community Observations', 'peptide-search-ai' ),
					'pubchemLink'      => __( 'View on PubChem', 'peptide-search-ai' ),
					'copySequence'     => __( 'Copy', 'peptide-search-ai' ),
					'copied'           => __( 'Copied!', 'peptide-search-ai' ),
				),
			)
		);

		ob_start();
		?>
		<div id="psa-directory-root" class="psa-dir-wrap" aria-label="<?php esc_attr_e( 'Peptide Directory', 'peptide-search-ai' ); ?>">
			<noscript>
				<p><?php esc_html_e( 'Please enable JavaScript to browse the peptide directory.', 'peptide-search-ai' ); ?></p>
			</noscript>
		</div>
		<?php
		return ob_get_clean();
	}
}
