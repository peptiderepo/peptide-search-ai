<?php
/**
 * Custom template rendering for single peptide pages.
 *
 * What: Appends structured peptide data (quick facts, extended data, sections, badges) below post content.
 * Who calls it: WordPress 'the_content' filter on single peptide pages.
 * Dependencies: WordPress post meta API, PSA_Post_Type meta field keys.
 *
 * @package PeptideSearchAI
 * @see     includes/class-psa-post-type.php
 * @see     includes/class-psa-directory.php  — detail modal uses the same data via REST
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSA_Template {

	public static function init(): void {
		add_filter( 'the_content', array( __CLASS__, 'append_peptide_data' ) );
	}

	/**
	 * Append structured peptide data below the post content on single peptide pages.
	 *
	 * @param string $content The post content.
	 * @return string Modified content.
	 */
	public static function append_peptide_data( string $content ): string {
		if ( ! is_singular( 'peptide' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post_id     = get_the_ID();
		$source      = get_post_meta( $post_id, 'psa_source', true );
		$pubchem_cid = get_post_meta( $post_id, 'psa_pubchem_cid', true );

		$data = array(
			'post_id'     => $post_id,
			'source'      => $source,
			'pubchem_cid' => $pubchem_cid,
		);

		$data = apply_filters( 'psa_template_data', $data, $post_id );

		$post_id     = $data['post_id'] ?? $post_id;
		$source      = $data['source'] ?? $source;
		$pubchem_cid = $data['pubchem_cid'] ?? $pubchem_cid;

		$html = '<div class="psa-peptide-data">';

		// Quick facts box.
		$html .= self::render_quick_facts( $post_id, $pubchem_cid );

		// Extended research data box (half-life, stability, storage, etc.).
		$html .= self::render_extended_data( $post_id );

		// Detailed sections.
		$html .= self::render_detail_sections( $post_id );

		// Source badge.
		$html .= self::render_source_badge( $source );

		// Integration hook: allow Peptide Community and other plugins to append content.
		ob_start();
		do_action( 'psa_after_peptide_detail', $post_id );
		$html .= ob_get_clean();

		$html .= '</div>';

		return $content . $html;
	}

	/**
	 * Render the quick facts table.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $pubchem_cid PubChem CID (may be empty).
	 * @return string HTML.
	 */
	private static function render_quick_facts( int $post_id, string $pubchem_cid ): string {
		$weight  = get_post_meta( $post_id, 'psa_molecular_weight', true );
		$formula = get_post_meta( $post_id, 'psa_molecular_formula', true );
		$seq     = get_post_meta( $post_id, 'psa_sequence', true );
		$aliases = get_post_meta( $post_id, 'psa_aliases', true );

		if ( ! $weight && ! $formula && ! $seq ) {
			return '';
		}

		$html  = '<div class="psa-quick-facts">';
		$html .= '<h3>Quick Facts</h3>';
		$html .= '<table class="psa-facts-table">';

		if ( $aliases ) {
			$html .= '<tr><th>Also Known As</th><td>' . esc_html( $aliases ) . '</td></tr>';
		}
		if ( $seq ) {
			$html .= '<tr><th>Sequence</th><td><code class="psa-sequence">' . esc_html( $seq ) . '</code></td></tr>';
		}
		if ( $formula ) {
			$html .= '<tr><th>Molecular Formula</th><td>' . esc_html( $formula ) . '</td></tr>';
		}
		if ( $weight ) {
			$html .= '<tr><th>Molecular Weight</th><td>' . esc_html( $weight ) . '</td></tr>';
		}
		if ( $pubchem_cid ) {
			$cid_int = intval( $pubchem_cid );
			$html   .= '<tr><th>PubChem CID</th><td><a href="https://pubchem.ncbi.nlm.nih.gov/compound/' . $cid_int . '" target="_blank" rel="noopener">' . $cid_int . '</a></td></tr>';
		}

		$html .= '</table></div>';
		return $html;
	}

	/**
	 * Render the extended research data table (v4.3.0 fields).
	 *
	 * @param int $post_id Post ID.
	 * @return string HTML (empty string if no extended data).
	 */
	private static function render_extended_data( int $post_id ): string {
		$fields = array(
			'psa_half_life'             => 'Half-Life',
			'psa_stability'             => 'Stability',
			'psa_solubility'            => 'Solubility',
			'psa_vial_size_mg'          => 'Vial Size',
			'psa_storage_lyophilized'   => 'Storage (Lyophilized)',
			'psa_storage_reconstituted' => 'Storage (Reconstituted)',
			'psa_typical_dose_mcg'      => 'Typical Research Dose',
			'psa_cycle_parameters'      => 'Cycle Parameters',
			'psa_amino_acid_count'      => 'Amino Acid Count',
		);

		$rows = '';
		foreach ( $fields as $key => $label ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( ! empty( $value ) ) {
				$display = $value;
				if ( 'psa_vial_size_mg' === $key ) {
					$display = floatval( $value ) . ' mg';
				}
				$rows .= '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $display ) . '</td></tr>';
			}
		}

		if ( empty( $rows ) ) {
			return '';
		}

		return '<div class="psa-quick-facts">' .
			'<h3>Research Parameters</h3>' .
			'<table class="psa-facts-table">' . $rows . '</table>' .
			'</div>';
	}

	/**
	 * Render detailed text sections (mechanism, research apps, safety, etc.).
	 *
	 * @param int $post_id Post ID.
	 * @return string HTML.
	 */
	private static function render_detail_sections( int $post_id ): string {
		$detail_fields = array(
			'psa_mechanism'      => 'Mechanism of Action',
			'psa_research_apps'  => 'Research Applications',
			'psa_safety_profile' => 'Safety & Side Effects',
			'psa_dosage_info'    => 'Dosage Information',
			'psa_references'     => 'References',
		);

		$html = '';
		foreach ( $detail_fields as $key => $label ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( ! empty( $value ) ) {
				$html .= '<div class="psa-section">';
				$html .= '<h3>' . esc_html( $label ) . '</h3>';
				$html .= '<div class="psa-section-content">' . wp_kses_post( wpautop( $value ) ) . '</div>';
				$html .= '</div>';
			}
		}

		return $html;
	}

	/**
	 * Render the source badge.
	 *
	 * @param string $source The data source value.
	 * @return string HTML (empty if source is empty or 'pending').
	 */
	private static function render_source_badge( string $source ): string {
		if ( ! $source || 'pending' === $source ) {
			return '';
		}

		$badges = array(
			'ai-generated' => array(
				'label' => 'AI-Generated Content',
				'class' => 'psa-badge-ai',
			),
			'pubchem'      => array(
				'label' => 'PubChem Verified',
				'class' => 'psa-badge-verified',
			),
			'manual'       => array(
				'label' => 'Manually Curated',
				'class' => 'psa-badge-manual',
			),
		);
		$badge = $badges[ $source ] ?? $badges['ai-generated'];

		$html  = '<div class="psa-source-badge ' . esc_attr( $badge['class'] ) . '">';
		$html .= esc_html( $badge['label'] );
		if ( 'ai-generated' === $source ) {
			$html .= ' &mdash; This entry was automatically generated and may contain inaccuracies. Please verify critical information with primary sources.';
		}
		$html .= '</div>';

		return $html;
	}
}
