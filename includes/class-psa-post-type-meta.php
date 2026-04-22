<?php
/**
 * Meta box rendering and save handlers for the Peptide CPT editor.
 *
 * What: Adds and renders meta boxes (core + extended fields), saves meta on post update.
 * Who calls it: PSA_Post_Type::init_admin() registers hooks that point here.
 * Dependencies: PSA_Post_Type (META_FIELDS, EXTENDED_META_FIELDS constants).
 *
 * @package PeptideSearchAI
 * @since   4.5.0
 * @see     includes/class-psa-post-type.php — CPT registration, taxonomy, admin columns.
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSA_Post_Type_Meta {

	/** Register meta boxes on the peptide editor screen. */
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
	 * Render core meta box fields (sequence, molecular data, mechanism, etc.).
	 *
	 * @param WP_Post $post The current post object.
	 */
	public static function render_meta_box( $post ): void {
		wp_nonce_field( 'psa_save_meta', 'psa_meta_nonce' );

		$textarea_fields = array(
			'psa_sequence', 'psa_mechanism', 'psa_research_apps',
			'psa_safety_profile', 'psa_dosage_info', 'psa_references',
		);

		echo '<table class="form-table">';
		foreach ( PSA_Post_Type::META_FIELDS as $key => $label ) {
			$value = get_post_meta( $post->ID, $key, true );
			echo '<tr>';
			echo '<th><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th>';
			echo '<td>';

			if ( 'psa_source' === $key ) {
				$options = array(
					'' => '— Select —', 'ai-generated' => 'AI Generated',
					'pubchem' => 'PubChem Verified', 'manual' => 'Manual Entry', 'pending' => 'Pending Generation',
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
	 * Render extended meta box fields (half-life, stability, storage, etc.).
	 *
	 * @param WP_Post $post The current post object.
	 */
	public static function render_extended_meta_box( $post ): void {
		echo '<table class="form-table">';
		foreach ( PSA_Post_Type::EXTENDED_META_FIELDS as $key => $label ) {
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
	 * Save meta field values (core + extended) on post save.
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
		$all_fields     = array_merge( PSA_Post_Type::META_FIELDS, PSA_Post_Type::EXTENDED_META_FIELDS );
		foreach ( $all_fields as $key => $label ) {
			if ( isset( $_POST[ $key ] ) ) {
				$raw = wp_unslash( $_POST[ $key ] );
				if ( in_array( $key, $numeric_fields, true ) ) {
					$value = ( '' === trim( $raw ) ) ? '' : (string) max( 0, floatval( $raw ) );
				} else {
					$value = sanitize_textarea_field( $raw );
				}
				update_post_meta( $post_id, $key, $value );
			}
		}
	}
}
