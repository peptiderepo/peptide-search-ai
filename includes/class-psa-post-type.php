<?php
/**
 * Registers the Peptide custom post type and its meta fields.
 *
 * @package PeptideSearchAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSA_Post_Type {

	/** Meta field keys used throughout the plugin. */
	const META_FIELDS = array(
		'psa_sequence'           => 'Amino Acid Sequence',
		'psa_molecular_weight'   => 'Molecular Weight',
		'psa_molecular_formula'  => 'Molecular Formula',
		'psa_aliases'            => 'Aliases / Synonyms',
		'psa_mechanism'          => 'Mechanism of Action',
		'psa_research_apps'      => 'Research Applications',
		'psa_safety_profile'     => 'Safety & Side Effects',
		'psa_dosage_info'        => 'Dosage Information',
		'psa_references'         => 'References & Citations',
		'psa_source'             => 'Data Source',
		'psa_pubchem_cid'        => 'PubChem CID',
	);

	/**
	 * Register admin-only hooks (meta boxes, save).
	 */
	public static function init_admin() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_peptide', array( __CLASS__, 'save_meta' ) );
	}

	/**
	 * Register the peptide CPT.
	 */
	public static function register_peptide_post_type() {
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
	 * Add meta boxes to the peptide editor.
	 */
	public static function add_meta_boxes() {
		add_meta_box(
			'psa_peptide_data',
			'Peptide Scientific Data',
			array( __CLASS__, 'render_meta_box' ),
			'peptide',
			'normal',
			'high'
		);
	}

	/**
	 * Render the meta box fields.
	 *
	 * @param WP_Post $post The current post object.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'psa_save_meta', 'psa_meta_nonce' );

		$textarea_fields = array(
			'psa_sequence', 'psa_mechanism', 'psa_research_apps',
			'psa_safety_profile', 'psa_dosage_info', 'psa_references',
		);

		echo '<table class="form-table">';
		foreach ( self::META_FIELDS as $key => $label ) {
			$value = get_post_meta( $post->ID, $key, true );
			echo '<tr>';
			echo '<th><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th>';
			echo '<td>';

			if ( $key === 'psa_source' ) {
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
	 * Save meta field values.
	 *
	 * @param int $post_id The post ID.
	 */
	public static function save_meta( $post_id ) {
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

		foreach ( self::META_FIELDS as $key => $label ) {
			if ( isset( $_POST[ $key ] ) ) {
				$value = sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) );
				update_post_meta( $post_id, $key, $value );
			}
		}
	}
}
