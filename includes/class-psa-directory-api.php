<?php
/**
 * REST API endpoint for the browsable peptide directory.
 *
 * What: GET /v1/compounds — paginated, filterable compound listing with basic/full field sets.
 * Who calls it: PSA_Directory::init() registers the REST route; frontend JS fetches data.
 * Dependencies: PSA_Post_Type (meta fields, taxonomy), PSA_Directory (constants).
 *
 * @package PeptideSearchAI
 * @since   4.5.0
 * @see     includes/class-psa-directory.php    — Shortcode rendering, hook registration.
 * @see     assets/js/psa-directory.js           — Frontend grid, filter, modal logic.
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSA_Directory_API {

	/**
	 * Register the /v1/compounds endpoint. Public (no auth) — rate limiting at server/CDN level.
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
						'type'    => 'string',
						'enum'    => array( 'basic', 'full' ),
						'default' => 'full',
					),
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => PSA_Directory::PER_PAGE_DEFAULT,
						'minimum'           => 1,
						'maximum'           => PSA_Directory::PER_PAGE_MAX,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * REST callback for GET /v1/compounds.
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
		$per_page = min( PSA_Directory::PER_PAGE_MAX, max( 1, (int) $request->get_param( 'per_page' ) ) );

		$query_args = array(
			'post_type'      => 'peptide',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( ! empty( $search ) ) {
			$query_args['s'] = $search;
		}

		if ( ! empty( $category ) ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'peptide_category',
					'field'    => 'slug',
					'terms'    => $category,
				),
			);
		}

		$query    = new WP_Query( $query_args );
		$post_ids = wp_list_pluck( $query->posts, 'ID' );
		if ( ! empty( $post_ids ) ) {
			update_meta_cache( 'post', $post_ids );
		}

		$compounds = array();
		foreach ( $query->posts as $post ) {
			$compounds[] = self::format_compound( $post, $fields );
		}

		$response = new WP_REST_Response(
			array(
				'compounds'   => $compounds,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
				'page'        => $page,
				'per_page'    => $per_page,
			),
			200
		);

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
		$id = $post->ID;

		$terms      = wp_get_object_terms( $id, 'peptide_category', array( 'fields' => 'all' ) );
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
			'id'         => $id,
			'name'       => $post->post_title,
			'slug'       => $post->post_name,
			'url'        => get_permalink( $id ),
			'categories' => $categories,
			'half_life'  => get_post_meta( $id, 'psa_half_life', true ),
			'stability'  => get_post_meta( $id, 'psa_stability', true ),
			'source'     => get_post_meta( $id, 'psa_source', true ),
			'extras'     => apply_filters( 'psa_directory_card_extras', '', $id ),
		);

		if ( 'basic' === $fields ) {
			$basic['vial_size_mg'] = get_post_meta( $id, 'psa_vial_size_mg', true );
			return $basic;
		}

		$full = array_merge(
			$basic,
			array(
				'excerpt'               => wp_trim_words( $post->post_content, 30 ),
				'description'           => wp_trim_words( $post->post_content, 80 ),
				'sequence'              => get_post_meta( $id, 'psa_sequence', true ),
				'molecular_weight'      => get_post_meta( $id, 'psa_molecular_weight', true ),
				'molecular_formula'     => get_post_meta( $id, 'psa_molecular_formula', true ),
				'aliases'               => get_post_meta( $id, 'psa_aliases', true ),
				'mechanism'             => get_post_meta( $id, 'psa_mechanism', true ),
				'research_apps'         => get_post_meta( $id, 'psa_research_apps', true ),
				'safety_profile'        => get_post_meta( $id, 'psa_safety_profile', true ),
				'dosage_info'           => get_post_meta( $id, 'psa_dosage_info', true ),
				'references'            => get_post_meta( $id, 'psa_references', true ),
				'pubchem_cid'           => get_post_meta( $id, 'psa_pubchem_cid', true ),
				'solubility'            => get_post_meta( $id, 'psa_solubility', true ),
				'vial_size_mg'          => get_post_meta( $id, 'psa_vial_size_mg', true ),
				'storage_lyophilized'   => get_post_meta( $id, 'psa_storage_lyophilized', true ),
				'storage_reconstituted' => get_post_meta( $id, 'psa_storage_reconstituted', true ),
				'typical_dose_mcg'      => get_post_meta( $id, 'psa_typical_dose_mcg', true ),
				'cycle_parameters'      => get_post_meta( $id, 'psa_cycle_parameters', true ),
				'amino_acid_count'      => get_post_meta( $id, 'psa_amino_acid_count', true ),
			)
		);

		return apply_filters( 'psa_rest_compound_data', $full, $id );
	}
}
