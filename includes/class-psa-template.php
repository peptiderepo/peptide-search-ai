<?php
/**
 * Custom template rendering for single peptide pages.
 *
 * @package PeptideSearchAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSA_Template {

	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'append_peptide_data' ) );
	}

	/**
	 * Append structured peptide data below the post content on single peptide pages.
	 *
	 * @param string $content The post content.
	 * @return string Modified content.
	 */
	public static function append_peptide_data( $content ) {
		if ( ! is_singular( 'peptide' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post_id     = get_the_ID();
		$source      = get_post_meta( $post_id, 'psa_source', true );
		$pubchem_cid = get_post_meta( $post_id, 'psa_pubchem_cid', true );

		// Build template data for filtering.
		$data = array(
			'post_id'     => $post_id,
			'source'      => $source,
			'pubchem_cid' => $pubchem_cid,
		);

		// Allow filtering of template data before rendering.
		$data = apply_filters( 'psa_template_data', $data, $post_id );

		// Restore values from possibly filtered data.
		$post_id     = $data['post_id'] ?? $post_id;
		$source      = $data['source'] ?? $source;
		$pubchem_cid = $data['pubchem_cid'] ?? $pubchem_cid;

		$html = '<div class="psa-peptide-data">';

		// Quick facts box.
		$weight  = get_post_meta( $post_id, 'psa_molecular_weight', true );
		$formula = get_post_meta( $post_id, 'psa_molecular_formula', true );
		$seq     = get_post_meta( $post_id, 'psa_sequence', true );
		$aliases = get_post_meta( $post_id, 'psa_aliases', true );

		if ( $weight || $formula || $seq ) {
			$html .= '<div class="psa-quick-facts">';
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

			$html .= '</table>';
			$html .= '</div>';
		}

		// Detailed sections.
		$detail_fields = array(
			'psa_mechanism'      => 'Mechanism of Action',
			'psa_research_apps'  => 'Research Applications',
			'psa_safety_profile' => 'Safety & Side Effects',
			'psa_dosage_info'    => 'Dosage Information',
			'psa_references'     => 'References',
		);

		foreach ( $detail_fields as $key => $label ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( ! empty( $value ) ) {
				$html .= '<div class="psa-section">';
				$html .= '<h3>' . esc_html( $label ) . '</h3>';
				$html .= '<div class="psa-section-content">' . wp_kses_post( wpautop( $value ) ) . '</div>';
				$html .= '</div>';
			}
		}

		// Source badge.
		if ( $source && 'pending' !== $source ) {
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

			$html .= '<div class="psa-source-badge ' . esc_attr( $badge['class'] ) . '">';
			$html .= esc_html( $badge['label'] );
			if ( 'ai-generated' === $source ) {
				$html .= ' &mdash; This entry was automatically generated and may contain inaccuracies. Please verify critical information with primary sources.';
			}
			$html .= '</div>';
		}

		$html .= '</div>';

		return $content . $html;
	}
}
