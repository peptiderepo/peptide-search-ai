<?php
/**
 * Echo Knowledge Base article builder for generated peptide content.
 *
 * What: Creates structured KB articles from AI-generated peptide data with proper
 *       HTML formatting, category assignment, and cross-referencing to the source peptide post.
 * Who calls it: PSA_AI_Generator::background_generate() after successful content generation.
 * Dependencies: WordPress post API, Echo Knowledge Base CPT (epkb_post_type_1).
 *
 * @package PeptideSearchAI
 * @see     includes/class-psa-ai-generator.php
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSA_KB_Builder {

	/**
	 * Category slug mapping for Echo Knowledge Base taxonomy.
	 */
	const CATEGORY_MAP = array(
		'GH Secretagogues'            => 'gh-secretagogues',
		'Healing & Repair'            => 'healing-repair',
		'Melanocortin Peptides'       => 'melanocortin-peptides',
		'Metabolic & Anti-Aging'      => 'metabolic-anti-aging',
		'Nootropic & Neuroprotective' => 'nootropic-neuroprotective',
	);

	/**
	 * Create a matching Echo Knowledge Base article with full structured content.
	 *
	 * Checks for Echo KB plugin, avoids duplicates, builds structured HTML with
	 * h2/h3 headings, assigns KB category, and cross-references the peptide post.
	 *
	 * @param int    $peptide_post_id The peptide post ID to cross-reference.
	 * @param array  $ai_data         The parsed AI data with all content fields.
	 * @param string $peptide_name    The peptide name for logging and fallback title.
	 * @param string $post_status     The post status ('draft' or 'publish').
	 * @return void
	 */
	public static function create_article( int $peptide_post_id, array $ai_data, string $peptide_name, string $post_status = 'draft' ): void {
		if ( ! post_type_exists( 'epkb_post_type_1' ) ) {
			error_log( 'PSA: Echo Knowledge Base not active — skipping KB article.' );
			return;
		}

		$title = sanitize_text_field( $ai_data['name'] ?? $peptide_name );
		$existing = get_posts(
			array(
				'post_type'   => 'epkb_post_type_1',
				'title'       => $title,
				'post_status' => 'any',
				'numberposts' => 1,
			)
		);

		if ( ! empty( $existing ) ) {
			error_log( 'PSA: KB article already exists for "' . $peptide_name . '".' );
			return;
		}

		$content    = self::build_article_content( $ai_data );
		$kb_post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => wp_kses_post( $content ),
				'post_type'    => 'epkb_post_type_1',
				'post_status'  => $post_status,
			),
			true
		);

		if ( is_wp_error( $kb_post_id ) ) {
			error_log( 'PSA: Failed to create KB article: ' . $kb_post_id->get_error_message() );
			return;
		}

		self::assign_category( $kb_post_id, $ai_data );

		// Cross-reference both posts.
		update_post_meta( $peptide_post_id, 'psa_kb_article_id', $kb_post_id );
		update_post_meta( $kb_post_id, 'psa_peptide_post_id', $peptide_post_id );

		error_log( 'PSA: Created KB article #' . $kb_post_id . ' for "' . $peptide_name . '"' );
	}

	/**
	 * Build the full article HTML content from AI data.
	 *
	 * @param array $ai_data The parsed AI data.
	 * @return string HTML content for the KB article.
	 */
	private static function build_article_content( array $ai_data ): string {
		// Build header meta line.
		$cat_label = ! empty( $ai_data['category_label'] ) ? $ai_data['category_label'] : '';
		$origin    = ! empty( $ai_data['origin'] ) ? $ai_data['origin'] : '';
		$sequence  = ! empty( $ai_data['sequence'] ) ? $ai_data['sequence'] : '';

		$header = '';
		if ( $cat_label ) {
			$header .= '<strong>Category:</strong> ' . esc_html( $cat_label );
		}
		if ( $origin ) {
			$header .= ' | <strong>Origin:</strong> ' . esc_html( $origin );
		}
		if ( $sequence ) {
			$header .= ' | <strong>Sequence:</strong> ' . esc_html( $sequence );
		}

		$c = $header ? '<p>' . $header . '</p>' . "\n\n" : '';

		// Overview.
		$c .= '<h2>Overview</h2>' . "\n";
		$c .= self::paragraphs( $ai_data['overview'] ?? $ai_data['description'] ?? '' );

		// Mechanism of Action.
		if ( ! empty( $ai_data['mechanism'] ) ) {
			$c .= '<h2>Mechanism of Action</h2>' . "\n";
			$c .= self::paragraphs( $ai_data['mechanism'] );
		}

		// Research & Potential Benefits (with h3 subheadings).
		if ( ! empty( $ai_data['research_benefits'] ) || ! empty( $ai_data['research_applications'] ) ) {
			$c .= '<h2>Research &amp; Potential Benefits</h2>' . "\n";
			$c .= '<p><em>The following potential benefits are based on animal studies and preclinical research. Human clinical trial data is extremely limited.</em></p>' . "\n";
			$research = $ai_data['research_benefits'] ?? $ai_data['research_applications'] ?? '';
			$c .= self::research_with_subheadings( $research );
		}

		// Administration & Dosing.
		if ( ! empty( $ai_data['administration_dosing'] ) || ! empty( $ai_data['dosage_info'] ) ) {
			$c .= '<h2>Administration &amp; Dosing (Research Context)</h2>' . "\n";
			$c .= '<p><strong>Important:</strong> The following dosing information is derived from animal studies and extrapolated research. This peptide is not approved for human use. This information is provided for educational purposes only.</p>' . "\n";
			$c .= self::paragraphs( $ai_data['administration_dosing'] ?? $ai_data['dosage_info'] ?? '' );
		}

		// Safety & Side Effects.
		if ( ! empty( $ai_data['safety_side_effects'] ) || ! empty( $ai_data['safety_profile'] ) ) {
			$c .= '<h2>Safety &amp; Side Effects</h2>' . "\n";
			$c .= self::paragraphs( $ai_data['safety_side_effects'] ?? $ai_data['safety_profile'] ?? '' );
		}

		// Legal & Regulatory Status.
		if ( ! empty( $ai_data['legal_regulatory'] ) ) {
			$c .= '<h2>Legal &amp; Regulatory Status</h2>' . "\n";
			$c .= self::paragraphs( $ai_data['legal_regulatory'] );
		}

		// References.
		if ( ! empty( $ai_data['references'] ) ) {
			$c .= '<h2>Notable Research References</h2>' . "\n";
			$c .= self::paragraphs( $ai_data['references'] );
		}

		// Medical Disclaimer.
		$c .= "\n" . '<p><strong>Medical Disclaimer:</strong> The information on this page is provided for educational and research purposes only. This peptide is not approved for human therapeutic use. Nothing on this page constitutes medical advice. Always consult a licensed healthcare professional before using any peptide, supplement, or research chemical.</p>';

		return $c;
	}

	/**
	 * Assign Echo KB category to a KB article based on AI-classified category.
	 *
	 * @param int   $kb_post_id The KB article post ID.
	 * @param array $ai_data    The parsed AI data containing 'category' field.
	 * @return void
	 */
	private static function assign_category( int $kb_post_id, array $ai_data ): void {
		$cat_name = trim( $ai_data['category'] ?? '' );
		if ( isset( self::CATEGORY_MAP[ $cat_name ] ) ) {
			$term = get_term_by( 'slug', self::CATEGORY_MAP[ $cat_name ], 'epkb_post_type_1_category' );
			if ( $term ) {
				wp_set_object_terms( $kb_post_id, array( (int) $term->term_id ), 'epkb_post_type_1_category' );
			}
		}
	}

	/**
	 * Convert text to HTML paragraphs.
	 *
	 * Splits on double newlines and wraps each chunk in <p> tags.
	 *
	 * @param string $text Raw text content.
	 * @return string HTML paragraphs.
	 */
	public static function paragraphs( string $text ): string {
		if ( empty( $text ) ) {
			return '';
		}
		$text  = str_replace( "\r\n", "\n", $text );
		$parts = preg_split( '/\n{2,}/', trim( $text ) );
		$out   = '';
		foreach ( $parts as $p ) {
			$p = trim( $p );
			if ( '' === $p ) {
				continue;
			}
			$out .= '<p>' . wp_kses_post( $p ) . '</p>' . "\n";
		}
		return $out;
	}

	/**
	 * Parse research text into h3 subheadings + paragraphs.
	 *
	 * Short lines (<80 chars, no period) become h3 subheadings.
	 * Longer blocks become regular paragraphs.
	 *
	 * @param string $text Research benefits text with embedded subheadings.
	 * @return string HTML with h3 subheadings and paragraphs.
	 */
	public static function research_with_subheadings( string $text ): string {
		if ( empty( $text ) ) {
			return '';
		}
		$text   = str_replace( "\r\n", "\n", trim( $text ) );
		$blocks = preg_split( '/\n{2,}/', $text );
		$out    = '';
		foreach ( $blocks as $block ) {
			$block = trim( $block );
			if ( '' === $block ) {
				continue;
			}
			// If block is short (under 80 chars) and has no period, treat as a subheading.
			if ( strlen( $block ) < 80 && strpos( $block, '.' ) === false ) {
				$out .= '<h3>' . esc_html( $block ) . '</h3>' . "\n";
			} else {
				// Check if first line is a subheading followed by content.
				$first_nl = strpos( $block, "\n" );
				if ( false !== $first_nl ) {
					$first_line = trim( substr( $block, 0, $first_nl ) );
					$rest       = trim( substr( $block, $first_nl + 1 ) );
					if ( strlen( $first_line ) < 80 && strpos( $first_line, '.' ) === false ) {
						$out .= '<h3>' . esc_html( $first_line ) . '</h3>' . "\n";
						$out .= '<p>' . wp_kses_post( $rest ) . '</p>' . "\n";
						continue;
					}
				}
				$out .= '<p>' . wp_kses_post( $block ) . '</p>' . "\n";
			}
		}
		return $out;
	}
}
