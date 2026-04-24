<?php
/**
 * PSA's consumer-side integration with the `peptide` custom post type.
 *
 * As of v4.5.0 the `peptide` CPT and `peptide_category` taxonomy are owned by
 * Peptide Repo Core (PR Core) >= 0.2.0 — PSA no longer registers them. This
 * class now wires PSA-owned admin surfaces (meta boxes, admin columns) onto
 * the CPT that PR Core registers, and exposes the `psa_*` meta key namespace
 * via constants for the rest of PSA to consume.
 *
 * What: Admin columns, meta key definitions, get_all_meta_keys() helper, and
 * backward-compatible proxies to PSA_Post_Type_Meta.
 * Who calls it: `psa_admin_init()` in the main plugin file, gated by
 * PSA_Dependency_Check::is_satisfied() so admin surfaces don't bind against
 * a missing CPT when PR Core is absent.
 * Dependencies: PR Core >= 0.2.0 for the CPT itself (runtime); WordPress
 * meta-box + admin-column APIs; PSA_Post_Type_Meta for meta box rendering.
 *
 * @package PeptideSearchAI
 * @since   1.0.0 (CPT registration removed in 4.5.0)
 * @see     includes/class-psa-post-type-meta.php    — Meta box rendering and save handlers.
 * @see     includes/class-psa-dependency-check.php  — PR Core gate used by init_admin().
 * @see     includes/class-psa-directory.php         — Reads `peptide_category` terms for directory filtering.
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSA_Post_Type {

	/**
	 * Canonical slug → display-name map for the peptide categories PSA's
	 * AI and admin tools know how to assign terms to. This is a consumer
	 * reference list (used by PSA_AI_Content::assign_category_term() and
	 * the "Migrate Existing Peptides to Categories" admin button) — not a
	 * seed list. As of PSA v4.5.0 PR Core >= 0.2.0 owns the
	 * `peptide_category` taxonomy; the 8 terms below were seeded by PSA
	 * in pre-v4.5.0 releases and remain intact in `wp_term_taxonomy`. PSA
	 * no longer seeds them on activation.
	 */
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
		'psa_half_life'             => 'Half-Life',
		'psa_stability'             => 'Stability',
		'psa_solubility'            => 'Solubility / Reconstitution',
		'psa_vial_size_mg'          => 'Common Vial Size (mg)',
		'psa_storage_lyophilized'   => 'Storage (Lyophilized)',
		'psa_storage_reconstituted' => 'Storage (Reconstituted)',
		'psa_typical_dose_mcg'      => 'Typical Research Dose',
		'psa_cycle_parameters'      => 'Cycle / Protocol Parameters',
		'psa_amino_acid_count'      => 'Amino Acid Count',
	);

	/**
	 * Register admin-only hooks (meta boxes, save, admin columns).
	 *
	 * Caller (`psa_admin_init`) gates this behind
	 * PSA_Dependency_Check::is_satisfied() so meta boxes + admin columns
	 * don't attempt to bind against a `peptide` CPT that PR Core has not
	 * yet registered.
	 */
	public static function init_admin(): void {
		add_action( 'add_meta_boxes', array( 'PSA_Post_Type_Meta', 'add_meta_boxes' ) );
		add_action( 'save_post_peptide', array( 'PSA_Post_Type_Meta', 'save_meta' ) );
		add_filter( 'manage_peptide_posts_columns', array( __CLASS__, 'add_admin_columns' ) );
		add_action( 'manage_peptide_posts_custom_column', array( __CLASS__, 'render_admin_column' ), 10, 2 );
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
				'ai-generated' => 'AI',
				'pubchem'      => 'PubChem',
				'manual'       => 'Manual',
				'pending'      => 'Pending',
				'failed'       => 'Failed',
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
	public static function add_meta_boxes(): void {
		PSA_Post_Type_Meta::add_meta_boxes(); }

	/** @see PSA_Post_Type_Meta::render_meta_box() */
	public static function render_meta_box( $post ): void {
		PSA_Post_Type_Meta::render_meta_box( $post ); }

	/** @see PSA_Post_Type_Meta::render_extended_meta_box() */
	public static function render_extended_meta_box( $post ): void {
		PSA_Post_Type_Meta::render_extended_meta_box( $post ); }

	/** @see PSA_Post_Type_Meta::save_meta() */
	public static function save_meta( int $post_id ): void {
		PSA_Post_Type_Meta::save_meta( $post_id ); }
}
