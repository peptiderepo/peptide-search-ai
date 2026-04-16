<?php
/**
 * AI prompt templates and post-generation data handlers for peptide entries.
 *
 * What: Prompt construction (validation + generation), meta field saving, category assignment.
 * Who calls it: PSA_AI_Generator during validate/generate workflows.
 * Dependencies: PSA_Config, PSA_Post_Type (for DEFAULT_CATEGORIES).
 *
 * @package PeptideSearchAI
 * @since   4.5.0
 * @see     includes/class-psa-ai-generator.php — Orchestration that calls these methods.
 * @see     includes/class-psa-post-type.php    — DEFAULT_CATEGORIES used in assign_category_term.
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSA_AI_Content {

	/**
	 * Build the lightweight validation prompt.
	 *
	 * @param string $name The peptide name to validate.
	 * @return string Prompt text for the OpenRouter API.
	 */
	public static function build_validation_prompt( string $name ): string {
		$json_name = wp_json_encode( $name );
		return <<<PROMPT
You are a peptide and biochemistry expert. Determine if the peptide name given in the following JSON-encoded string is a real, recognized peptide, protein fragment, or peptide-based compound.

Peptide name: {$json_name}

Return ONLY valid JSON (no markdown fences) with exactly these fields:

{
  "is_valid": true or false,
  "canonical_name": "The standard/official name for this peptide (correcting capitalization or common abbreviations)",
  "reason": "Brief explanation of why this is or isn't a recognized peptide"
}

Rules:
- Return is_valid: true only for real peptides, peptide hormones, peptide-based drugs, neuropeptides, antimicrobial peptides, synthetic peptides used in research, or well-known protein fragments.
- Return is_valid: false for random words, made-up names, non-peptide drugs, non-peptide supplements, general proteins (unless commonly referred to as peptides), or anything that is clearly not a peptide.
- Be generous with research peptides and experimental compounds that are known in the scientific literature.
- Common examples that ARE valid: BPC-157, Thymosin Beta-4, TB-500, GHK-Cu, Melanotan II, Ipamorelin, CJC-1295, Semaglutide, Tirzepatide, AOD-9604, PT-141, DSIP, Epitalon, LL-37, Oxytocin, Vasopressin, Substance P, etc.
PROMPT;
	}

	/**
	 * Build the comprehensive generation prompt (v4.3.0: includes extended fields).
	 *
	 * @param string $peptide_name The peptide to research.
	 * @return string Prompt text for the OpenRouter API.
	 */
	public static function build_generation_prompt( string $peptide_name ): string {
		$json_name = wp_json_encode( $peptide_name );
		return <<<PROMPT
You are a scientific writer specializing in peptide research. Generate a comprehensive database entry for the peptide given in the following JSON-encoded string.

Peptide name: {$json_name}

Return ONLY valid JSON (no markdown fences, no commentary) with exactly these fields:

{
  "name": "Official peptide name",
  "aliases": "Comma-separated list of alternative names, abbreviations, or synonyms",
  "category_label": "e.g. Tissue Healing / Cytoprotective, or GH Secretagogue, etc.",
  "origin": "Origin description, e.g. Synthetic (derived from human gastric juice protein)",
  "sequence": "Full amino acid sequence in three-letter code with dashes, e.g. Gly-Glu-Pro-Pro-Pro (N amino acids). Use single-letter if three-letter not standard.",
  "molecular_weight": "Molecular weight in Daltons (e.g. 1419.53 Da)",
  "molecular_formula": "Chemical formula (e.g. C62H98N16O22)",
  "overview": "2-3 paragraphs: what this peptide is, its origin, discovery, and significance. Written for a scientifically literate audience.",
  "mechanism": "Detailed mechanism of action. Start with an intro paragraph, then describe each pathway as a labeled item like: Pathway name: Description. Separate items with double newlines.",
  "research_benefits": "Detailed research applications organized by therapeutic area. Use area names as subheadings followed by a paragraph.",
  "administration_dosing": "Include a disclaimer that this is from research only. Then describe: typical research dose range, routes of administration, frequency, and duration from studies.",
  "safety_side_effects": "Known safety profile from animal studies. Include any anecdotally reported side effects. Note theoretical concerns.",
  "legal_regulatory": "Current regulatory and legal status. Mention FDA status, WADA status if applicable, classification as research chemical.",
  "references": "5-8 real published scientific references with authors, title, journal, year. Use real studies only.",
  "category": "Classify into exactly ONE of: Tissue Repair, Lipid Metabolism, Aging Research, Dermatological, Metabolic, Growth Hormone, Immunology, Endocrine",
  "half_life": "Approximate half-life (e.g. '~4-6 hours', '15-20 minutes'). Write 'Unknown' if not established.",
  "stability": "Stability information (e.g. '28 Days at 2-8°C after reconstitution'). Include lyophilized and reconstituted if available.",
  "solubility": "Recommended reconstitution solvent (e.g. 'Bacteriostatic Water', 'Sterile Water')",
  "vial_size_mg": "Most common research vial size in mg as a number (e.g. 5, 10, 2). Use 0 if not applicable.",
  "storage_lyophilized": "Storage conditions for lyophilized/powder form (e.g. '-20°C, protect from light')",
  "storage_reconstituted": "Storage conditions after reconstitution (e.g. '2-8°C, use within 28 days')",
  "typical_dose_mcg": "Typical research dose range (e.g. '200-300 mcg', '100-200 mcg/kg'). Include units.",
  "cycle_parameters": "Typical research cycle/protocol info (e.g. '4-6 weeks on, 2 weeks off', 'Daily subcutaneous injection')"
}

Important:
- Use factual, evidence-based information only.
- If information is uncertain or unknown, say so explicitly rather than fabricating data.
- All content is for research and educational purposes.
- Write in clean paragraphs. Do not use markdown formatting symbols like # or ** or *.
- For the "category" field, use EXACTLY one of the eight listed categories.
PROMPT;
	}

	/**
	 * Save all meta fields for a peptide post (core + extended).
	 * Prefers PubChem data for molecular properties when available.
	 *
	 * @param int        $post_id      The post ID.
	 * @param array      $ai_data      AI-generated data.
	 * @param array|null $pubchem_data PubChem data (or null).
	 */
	public static function save_peptide_meta( int $post_id, array $ai_data, ?array $pubchem_data ): void {
		$amino_acid_count = self::derive_amino_acid_count( $ai_data );

		$meta = array(
			'psa_sequence'              => $ai_data['sequence'] ?? '',
			'psa_molecular_weight'      => ! empty( $pubchem_data['molecular_weight'] ) ? $pubchem_data['molecular_weight'] : ( $ai_data['molecular_weight'] ?? '' ),
			'psa_molecular_formula'     => ! empty( $pubchem_data['molecular_formula'] ) ? $pubchem_data['molecular_formula'] : ( $ai_data['molecular_formula'] ?? '' ),
			'psa_aliases'               => $ai_data['aliases'] ?? '',
			'psa_mechanism'             => $ai_data['mechanism'] ?? '',
			'psa_overview'              => $ai_data['overview'] ?? $ai_data['description'] ?? '',
			'psa_category_label'        => $ai_data['category_label'] ?? '',
			'psa_origin'                => $ai_data['origin'] ?? '',
			'psa_research_apps'         => $ai_data['research_benefits'] ?? ( $ai_data['research_applications'] ?? '' ),
			'psa_safety_profile'        => $ai_data['safety_side_effects'] ?? ( $ai_data['safety_profile'] ?? '' ),
			'psa_dosage_info'           => $ai_data['administration_dosing'] ?? ( $ai_data['dosage_info'] ?? '' ),
			'psa_legal_regulatory'      => $ai_data['legal_regulatory'] ?? '',
			'psa_references'            => $ai_data['references'] ?? '',
			'psa_category'              => $ai_data['category'] ?? '',
			'psa_source'                => ! empty( $pubchem_data ) ? 'pubchem' : 'ai-generated',
			'psa_pubchem_cid'           => $pubchem_data['cid'] ?? '',
			'psa_half_life'             => $ai_data['half_life'] ?? '',
			'psa_stability'             => $ai_data['stability'] ?? '',
			'psa_solubility'            => $ai_data['solubility'] ?? '',
			'psa_vial_size_mg'          => $ai_data['vial_size_mg'] ?? '',
			'psa_storage_lyophilized'   => $ai_data['storage_lyophilized'] ?? '',
			'psa_storage_reconstituted' => $ai_data['storage_reconstituted'] ?? '',
			'psa_typical_dose_mcg'      => $ai_data['typical_dose_mcg'] ?? '',
			'psa_cycle_parameters'      => $ai_data['cycle_parameters'] ?? '',
			'psa_amino_acid_count'      => $amino_acid_count,
		);

		$meta = apply_filters( 'psa_peptide_meta', $meta, $post_id, $ai_data, $pubchem_data );

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
	}

	/**
	 * Auto-assign a peptide_category taxonomy term based on AI-returned category.
	 *
	 * @param int   $post_id The peptide post ID.
	 * @param array $ai_data AI-generated data containing 'category' or 'category_label'.
	 */
	public static function assign_category_term( int $post_id, array $ai_data ): void {
		$ai_category = $ai_data['category'] ?? $ai_data['category_label'] ?? '';
		if ( empty( $ai_category ) ) {
			return;
		}

		// Why: AI returns varied labels ("GH Secretagogues", "Healing & Repair") that need
		// to map to our controlled vocabulary of taxonomy terms.
		$category_map = array(
			'gh secretagogues'            => 'growth-hormone',
			'growth hormone'              => 'growth-hormone',
			'healing & repair'            => 'tissue-repair',
			'healing and repair'          => 'tissue-repair',
			'tissue repair'               => 'tissue-repair',
			'tissue healing'              => 'tissue-repair',
			'cytoprotective'              => 'tissue-repair',
			'melanocortin peptides'       => 'dermatological',
			'melanocortin'                => 'dermatological',
			'dermatological'              => 'dermatological',
			'metabolic & anti-aging'      => 'metabolic',
			'metabolic and anti-aging'    => 'metabolic',
			'metabolic'                   => 'metabolic',
			'anti-aging'                  => 'aging-research',
			'aging research'              => 'aging-research',
			'nootropic & neuroprotective' => 'immunology',
			'nootropic'                   => 'immunology',
			'neuroprotective'             => 'immunology',
			'immunology'                  => 'immunology',
			'immune'                      => 'immunology',
			'lipid metabolism'            => 'lipid-metabolism',
			'endocrine'                   => 'endocrine',
		);

		$lower = strtolower( trim( $ai_category ) );
		$slug  = $category_map[ $lower ] ?? '';

		// Fuzzy fallback: check if AI category contains any known slug keyword.
		if ( empty( $slug ) ) {
			foreach ( PSA_Post_Type::DEFAULT_CATEGORIES as $cat_slug => $cat_name ) {
				if ( stripos( $ai_category, $cat_name ) !== false || stripos( $ai_category, str_replace( '-', ' ', $cat_slug ) ) !== false ) {
					$slug = $cat_slug;
					break;
				}
			}
		}

		if ( ! empty( $slug ) ) {
			wp_set_object_terms( $post_id, $slug, 'peptide_category' );
		}
	}

	/**
	 * Derive amino acid count from sequence data or explicit AI field.
	 *
	 * @param array $ai_data AI-generated data.
	 * @return string Amino acid count as string, or empty.
	 */
	private static function derive_amino_acid_count( array $ai_data ): string {
		$sequence = $ai_data['sequence'] ?? '';
		$count    = '';

		if ( ! empty( $sequence ) ) {
			// Three-letter codes use dashes; single-letter codes are uppercase chars.
			$count = ( strpos( $sequence, '-' ) !== false )
				? (string) count( explode( '-', $sequence ) )
				: (string) strlen( preg_replace( '/[^A-Z]/', '', $sequence ) );
		}

		// AI may return this directly — prefer explicit value.
		if ( ! empty( $ai_data['amino_acid_count'] ) ) {
			$count = (string) intval( $ai_data['amino_acid_count'] );
		}

		return $count;
	}
}
