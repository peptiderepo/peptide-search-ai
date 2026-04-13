<?php
declare( strict_types=1 );
/**
 * Registers the Peptide custom post type, taxonomy, and meta fields.
 *
 * What: Registers the 'peptide' CPT, 'peptide_category' taxonomy, meta boxes, and save handlers.
 * Who calls it: Main plugin file via psa_init() and psa_admin_init() hooks.
 * Dependencies: WordPress CPT API, taxonomy API, meta box API.
 *
 * @package PeptideSearchAI
 * @see     includes/class-psa-ai-generator.php  — auto-assigns category term after generation
 * @see     includes/class-psa-directory.php     — reads taxonomy for directory filtering
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSA_Post_Type {

	/**
	 * Default peptide category terms pre-populated on activation.
	 * Key = slug, value = display name.
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

	/**
	 * Extended meta fields added in v4.3.0 for the directory feature.
	 * Separated from META_FIELDS so existing code continues to work unchanged.
	 */
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

	/**
	 * Register admin-only hooks (meta boxes, save, admin columns).
	 */
	public static function init_admin(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_peptide', array( __CLASS__, 'save_meta' ) );
		add_filter( 'manage_peptide_posts_columns', array( __CLASS__, 'add_admin_columns' ) );
		add_action( 'manage_peptide_posts_custom_column', array( __CLASS__, 'render_admin_column' ), 10, 2 );
	}

	/**
	 * Register the peptide CPT.
	 */
	public static function register_peptide_post_type(): void {
		if ( post_type_exists( 'peptide' ) ) {
			return;
		}

		$labels = array(
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
		);

		register_post_type(
			'peptide',
			array(
				'labels'             => $labels,
				'public'             => true,
				'has_archive'        => true,
				'rewrite'            => array( 'slug' => 'peptides' ),
				'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'menu_icon'          => 'dashicons-database',
				'show_in_rest'       => true,
				'capability_type'    => 'post',
				'hierarchical'       => false,
			)
		);
	}

	/**
	 * Register the peptide_category taxonomy on the peptide CPT.
	 * Called from psa_init() after CPT registration.
	 */
	public static function register_taxonomy(): void {
		if ( taxonomy_exists( 'peptide_category' ) ) {
			return;
		}

		$labels = array(
			'name'              => __( 'Peptide Categories', 'peptide-search-ai' ),
			'singular_name'     => __( 'Peptide Category', 'peptide-search-ai' ),
			'search_items'      => __( 'Search Categories', 'peptide-search-ai' ),
			'all_items'         => __( 'All Categories', 'peptide-search-ai' ),
			'edit_item'         => __( 'Edit Category', 'peptide-search-ai' ),
			'update_item'       => __( 'Update Category', 'peptide-search-ai' ),
			'add_new_item'      => __( 'Add New Category', 'peptide-search-ai' ),
			'new_item_name'     => __( 'New Category Name', 'peptide-search-ai' ),
			'menu_name'         => __( 'Categories', 'peptide-search-ai' ),
		);

		register_taxonomy(
			'peptide_category',
			'peptide',
			array(
				'labels'            => $labels,
				'hierarchical'      => true,
				'public'            => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => 'peptide-category' ),
			)
		);
	}

	/**
	 * Pre-populate default category terms on activation.
	 * Safe to call multiple times — skips existing terms.
	 */
	public static function populate_default_categories(): void {
		foreach ( self::DEFAULT_CATEGORIES as $slug => $name ) {
			if ( ! term_exists( $slug, 'peptide_category' ) ) {
				wp_insert_term( $name, 'peptide_category', array( 'slug' => $slug ) );
			}
		}
	}

	/**
	 * Add meta boxes to the peptide editor.
	 */
	public static function add_meta_boxes(): void {
		add_meta_box(
			'psa_peptide_data',
			'Peptide Scientific Data',
			array( __CLASS__, 'render_meta_box' ),
			'peptide',
			'normal',
			'high'
		);
		add_meta_box(
			'psa_extended_data',
			'Extended Research Data',
			array( __CLASS__, 'render_extended_meta_box' ),
			'peptide',
			'normal',
			'default'
		);
	}

	/**
	 * Render the core meta box fields.
	 *
	 * @param WP_Post $post The current post object.
	 * @return void
	 */
	public static function render_meta_box( $post ): void {
		wp_nonce_field( 'psa_save_meta', 'psa_meta_nonce' );

		$textarea_fields = array(
			'psa_sequence',
			'psa_mechanism',
			'psa_research_apps',
			'psa_safety_profile',
			'psa_dosage_info',
			'psa_references',
		);

		echo '<table class="form-table">';
		foreach ( self::META_FIELDS as $key => $label ) {
			$value = get_post_meta( $post->ID, $key, true );
			echo '<tr>';
			echo '<th><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th>';
			echo '<td>';

			if ( 'psa_source' === $key ) {
				$options = array(
					''             => '— Select —',
					'ai-generated' => 'AI Generated',
					'pubchem'      => 'PubChem Verified',
					'manual'       => 'Manual Entry',
					'pending'      => 'Pending Generation',
				);
				echo '<select name="' . esc_attr( $key ) . '" id="' . esc_attr( $key ) . '">';
				foreach ( $options as $opt_val => $opt_label ) {
					echo '<option value="' . esc_attr( $opt_val ) . '"' . selected( $value, $opt_val, false ) . '>' . esc_html( $opt_label ) . '</option>';
				}
				echo '</select>';
			} elseif ( in_array( $key, $textarea_fields, true ) ) {
				echo '<textarea name="' . esc_attr( $key ) . '" id="' . esc_attr( $key ) . '" rows="4" class="large-text">' . esc_textarea( $value ) . '</textarea>';
			} else {
				echo '<input type="text" name="' . esc_attr( $key ) . '" id="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" class="regular-text" />';
			}

			echo '</td></tr>';
		}
		echo '</table>';
	}

	/**
	 * Render the extended meta box fields (half-life, stability, storage, etc.).
	 *
	 * @param WP_Post $post The current post object.
	 * @return void
	 */
	public static function render_extended_meta_box( $post ): void {
		echo '<table class="form-table">';
		foreach ( self::EXTENDED_META_FIELDS as $key => $label ) {
			$value = get_post_meta( $post->ID, $key, true );
			echo '<tr>';
			echo '<th><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th>';
			echo '<td>';

			if ( 'psa_vial_size_mg' === $key ) {
				echo '<input type="number" step="0.01" name="' . esc_attr( $key ) . '" id="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" class="small-text" /> mg';
			} elseif ( 'psa_amino_acid_count' === $key ) {
				echo '<input type="number" step="1" name="' . esc_attr( $key ) . '" id="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" class="small-text" />';
			} else {
				echo '<input type="text" name="' . esc_attr( $key ) . '" id="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" class="regular-text" />';
			}

			echo '</td></tr>';
		}
		echo '</table>';
	}

	/**
	 * Save meta field values (core + extended).
	 *
	 * @param int $post_id The post ID.
	 */
	public static function save_meta( int $post_id ): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! isset( $_POST['psa_meta_nonce'] ) ||
			 ! wp_verify_nonce( wp_unslash( $_POST['psa_meta_nonce'] ), 'psa_save_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$numeric_fields = array( 'psa_vial_size_mg', 'psa_amino_acid_count' );
		$all_fields     = array_merge( self::META_FIELDS, self::EXTENDED_META_FIELDS );
		foreach ( $all_fields as $key => $label ) {
			if ( isset( $_POST[ $key ] ) ) {
				$raw = wp_unslash( $_POST[ $key ] );
				if ( in_array( $key, $numeric_fields, true ) ) {
					// Numeric fields: store as non-negative number or empty string.
					$value = ( '' === trim( $raw ) ) ? '' : (string) max( 0, floatval( $raw ) );
				} else {
					$value = sanitize_textarea_field( $raw );
				}
				update_post_meta( $post_id, $key, $value );
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
			// Insert source column after title.
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
		return array_merge(
			array_keys( self::META_FIELDS ),
			array_keys( self::EXTENDED_META_FIELDS )
		);
	}
}
