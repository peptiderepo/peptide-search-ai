<?php
/**
 * Registers the Peptide custom post type, taxonomy, and admin columns.
 *
 * What: CPT registration, taxonomy registration, default category seeding, admin columns.
 * Who calls it: Main plugin file via psa_init() and psa_admin_init() hooks.
 * Dependencies: WordPress CPT API, taxonomy API.
 *
 * @package PeptideSearchAI
 * @since   1.0.0
 * @see     includes/class-psa-post-type-meta.php — Meta box rendering and save handlers.
 * @see     includes/class-psa-ai-content.php     — Uses DEFAULT_CATEGORIES for category assignment.
 * @see     includes/class-psa-directory.php       — Reads taxonomy for directory filtering.
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSA_Post_Type {

	/** Default peptide category terms pre-populated on activation. slug => display name. */
	const DEFAULT_CATEGORIES = array(
		'tissue-repair'    => 'Tissue Repair',
		'lipid-metabolism' => 'Lipid Metabolism',
		'aging-research'   => 'Aging Research',
		'dermatological'   => 'Dermatological',
		'metabolic'        => 'Metabolic',
		'growth-hormone'   => 'Growth Hormone',
		'immunology'       => 'Immunology',
		'endocrine'        => 'Endocrine',
	);

	/** Core meta field keys used throughout the plugin. */
	const META_FIELDS = array(
		'psa_sequence'          => 'Amino Acid Sequence',
		'psa_molecular_weight'  => 'Molecular Weight',
		'psa_molecular_formula' => 'Molecular Formula',
		'psa_aliases'           => 'Aliases / Synonyms',
		'psa_mechanism'         => 'Mechanism of Action',
		'psa_research_apps'     => 'Research Applications',
		'psa_safety_profile'    => 'Safety & Side Effects',
		'psa_dosage_info'       => 'Dosage Information',
		'psa_references'        => 'References & Citations',
		'psa_source'            => 'Data Source',
		'psa_pubchem_cid'       => 'PubChem CID',
	);

	/** Extended meta fields added in v4.3.0 for the directory feature. */
	const EXTENDED_META_FIELDS = array(
		'psa_half_life'              => 'Half-Life',
		'psa_stability'              => 'Stability',
		'psa_solubility'             => 'Solubility / Reconstitution',
		'psa_vial_size_mg'           => 'Common Vial Size (mg)',
		'psa_storage_lyophilized'    => 'Storage (Lyophilized)',
		'psa_storage_reconstituted'  => 'Storage (Reconstituted)',
		'psa_typical_dose_mcg'       => 'Typical Research Dose',
		'psa_cycle_parameters'       => 'Cycle / Protocol Parameters',
		'psa_amino_acid_count'       => 'Amino Acid Count',
	);

	/** Register admin-only hooks (meta boxes, save, admin columns). */
	public static function init_admin(): void {
		add_action( 'add_meta_boxes', array( 'PSA_Post_Type_Meta', 'add_meta_boxes' ) );
		add_action( 'save_post_peptide', array( 'PSA_Post_Type_Meta', 'save_meta' ) );
		add_filter( 'manage_peptide_posts_columns', array( __CLASS__, 'add_admin_columns' ) );
		add_action( 'manage_peptide_posts_custom_column', array( __CLASS__, 'render_admin_column' ), 10, 2 );
	}

	/** Register the peptide CPT. */
	public static function register_peptide_post_type(): void {
		if ( post_type_exists( 'peptide' ) ) {
			return;
		}

		register_post_type( 'peptide', array(
			'labels'          => array(
				'name'               => 'Peptides',
				'singular_name'      => 'Peptide',
				'menu_name'          => 'Peptides',
				'add_new'            => 'Add Peptide',
				'add_new_item'       => 'Add New Peptide',
				'edit_item'          => 'Edit Peptide',
				'new_item'           => 'New Peptide',
				'view_item'          => 'View Peptide',
				'search_items'       => 'Search Peptides',
				'not_found'          => 'No peptides found',
				'not_found_in_trash' => 'No peptides found in Trash',
			),
			'public'          => true,
			'has_archive'     => true,
			'rewrite'         => array( 'slug' => 'peptides' ),
			'supports'        => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
			'menu_icon'       => 'dashicons-database',
			'show_in_rest'    => true,
			'capability_type' => 'post',
			'hierarchical'    => false,
		) );
	}

	/** Register the peptide_category taxonomy on the peptide CPT. */
	public static function register_taxonomy(): void {
		if ( taxonomy_exists( 'peptide_category' ) ) {
			return;
		}

		register_taxonomy( 'peptide_category', 'peptide', array(
			'labels'            => array(
				'name'          => __( 'Peptide Categories', 'peptide-search-ai' ),
				'singular_name' => __( 'Peptide Category', 'peptide-search-ai' ),
				'search_items'  => __( 'Search Categories', 'peptide-search-ai' ),
				'all_items'     => __( 'All Categories', 'peptide-search-ai' ),
				'edit_item'     => __( 'Edit Category', 'peptide-search-ai' ),
				'update_item'   => __( 'Update Category', 'peptide-search-ai' ),
				'add_new_item'  => __( 'Add New Category', 'peptide-search-ai' ),
				'new_item_name' => __( 'New Category Name', 'peptide-search-ai' ),
				'menu_name'     => __( 'Categories', 'peptide-search-ai' ),
			),
			'hierarchical'      => true,
			'public'            => true,
			'show_in_rest'      => true,
			'show_admin_column' => true,
			'rewrite'           => array( 'slug' => 'peptide-category' ),
		) );
	}

	/** Pre-populate default category terms on activation. Idempotent. */
	public static function populate_default_categories(): void {
		foreach ( self::DEFAULT_CATEGORIES as $slug => $name ) {
			if ( ! term_exists( $slug, 'peptide_category' ) ) {
				wp_insert_term( $name, 'peptide_category', array( 'slug' => $slug ) );
			}
		}
	}

	/**
	 * Add custom columns to the peptide admin list.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public static function add_admin_columns( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['psa_source'] = __( 'Source', 'peptide-search-ai' );
			}
		}
		return $new;
	}

	/**
	 * Render custom column content in the admin post list.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function render_admin_column( string $column, int $post_id ): void {
		if ( 'psa_source' === $column ) {
			$source = get_post_meta( $post_id, 'psa_source', true );
			$labels = array(
				'ai-generated' => 'AI', 'pubchem' => 'PubChem',
				'manual' => 'Manual', 'pending' => 'Pending', 'failed' => 'Failed',
			);
			echo esc_html( $labels[ $source ] ?? '—' );
		}
	}

	/**
	 * Get all meta field keys (core + extended) as a flat array.
	 *
	 * @return array<string> Meta keys.
	 */
	public static function get_all_meta_keys(): array {
		return array_merge( array_keys( self::META_FIELDS ), array_keys( self::EXTENDED_META_FIELDS ) );
	}

	// ── Backward-compatible proxies (meta boxes moved to PSA_Post_Type_Meta) ──

	/** @see PSA_Post_Type_Meta::add_meta_boxes() */
	public static function add_meta_boxes(): void { PSA_Post_Type_Meta::add_meta_boxes(); }

	/** @see PSA_Post_Type_Meta::render_meta_box() */
	public static function render_meta_box( $post ): void { PSA_Post_Type_Meta::render_meta_box( $post ); }

	/** @see PSA_Post_Type_Meta::render_extended_meta_box() */
	public static function render_extended_meta_box( $post ): void { PSA_Post_Type_Meta::render_extended_meta_box( $post ); }

	/** @see PSA_Post_Type_Meta::save_meta() */
	public static function save_meta( int $post_id ): void { PSA_Post_Type_Meta::save_meta( $post_id ); }
}
