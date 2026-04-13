<?php
/**
 * Handles AI content generation for new peptide entries.
 *
 * What: Orchestrates the full AI pipeline — validation, generation, PubChem enrichment, KB article creation.
 * Who calls it: PSA_Search (validation + generation scheduling), WP-Cron (background_generate).
 * Dependencies: PSA_Config, PSA_Cost_Tracker, PSA_Encryption, PSA_PubChem, PSA_Post_Type, OpenRouter API.
 *
 * @package PeptideSearchAI
 * @see     includes/class-psa-search.php
 * @see     includes/class-psa-cost-tracker.php
 * @see     includes/class-psa-pubchem.php
 * @see     includes/class-psa-post-type.php
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSA_AI_Generator {

	public static function init(): void {
		add_action( 'psa_generate_peptide_background', array( __CLASS__, 'background_generate' ), 10, 2 );
	}

	/**
	 * Validate whether a search term is a legitimate peptide name.
	 * Uses a lightweight AI call to check before committing to full generation.
	 *
	 * @param string $name The name to validate.
	 * @return array|WP_Error { is_valid: bool, canonical_name: string, reason: string }
	 */
	public static function validate_peptide_name( string $name ) {
		// Input character validation — block injection attempts before API call.
		if ( ! self::validate_peptide_input( $name ) ) {
			return array(
				'is_valid'       => false,
				'canonical_name' => $name,
				'reason'         => 'Invalid characters in peptide name.',
			);
		}

		$options = self::get_settings();
		$api_key = $options['api_key'];
		$model   = $options['validation_model'];

		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', 'OpenRouter API key is not configured.' );
		}

		// Check monthly budget before validation.
		if ( PSA_Cost_Tracker::is_budget_exceeded() ) {
			return new WP_Error( 'budget_exceeded', 'Monthly API budget reached.' );
		}

		// Check transient cache first (avoid repeated API calls for same term).
		$cache_key = 'psa_validate_' . md5( strtolower( trim( $name ) ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$prompt   = self::build_validation_prompt( $name );
		// Allow filtering of validation prompt before sending.
		$prompt = apply_filters( 'psa_validation_prompt', $prompt, $name );
		$response = PSA_OpenRouter::send_request(
			$api_key,
			$model ? $model : 'google/gemini-2.0-flash-001',
			$prompt,
			PSA_Config::VALIDATION_MAX_TOKENS,
			'validation',
			$name
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = PSA_OpenRouter::parse_response( $response );
		if ( is_wp_error( $data ) ) {
			return array(
				'is_valid'       => false,
				'canonical_name' => $name,
				'reason'         => 'Could not verify this peptide name.',
			);
		}

		$result = array(
			'is_valid'       => ! empty( $data['is_valid'] ) && true === $data['is_valid'],
			'canonical_name' => sanitize_text_field( $data['canonical_name'] ?? $name ),
			'reason'         => sanitize_text_field( $data['reason'] ?? '' ),
		);

		// Cache for 24 hours (using PSA_Config for consistency).
		set_transient( $cache_key, $result, DAY_IN_SECONDS );

		return $result;
	}

	/**
	 * Background generation handler (called via WP-Cron or synchronously).
	 *
	 * @param int    $post_id      The placeholder post ID.
	 * @param string $peptide_name The peptide to research.
	 */
	public static function background_generate( int $post_id, string $peptide_name ): void {
		$post = get_post( $post_id );
		if ( ! $post || 'draft' !== $post->post_status || 'peptide' !== $post->post_type ) {
			error_log( 'PSA: background_generate skipped — post ' . $post_id . ' not a draft peptide.' );
			return;
		}

		// Only process posts that are still in 'pending' state.
		$source = get_post_meta( $post_id, 'psa_source', true );
		if ( 'pending' !== $source ) {
			error_log( 'PSA: background_generate skipped — post ' . $post_id . ' source is "' . $source . '", not "pending".' );
			return;
		}

		$options      = self::get_settings();
		$auto_publish = $options['auto_publish'];

		error_log( 'PSA: Generating content for "' . $peptide_name . '" (post ' . $post_id . ') using model: ' . ( $options['ai_model'] ? $options['ai_model'] : 'google/gemini-2.5-flash' ) );

		// Allow developers to hook before generation starts.
		do_action( 'psa_before_generation', $post_id, $peptide_name );

		// Generate the full content.
		$result = self::generate_peptide_content( $peptide_name );
		if ( is_wp_error( $result ) ) {
			$err_msg = $result->get_error_message();
			error_log( 'PSA: Generation FAILED for "' . $peptide_name . '": ' . $err_msg );
			update_post_meta( $post_id, 'psa_generation_error', $err_msg );
			return;
		}

		error_log( 'PSA: AI content generated successfully for "' . $peptide_name . '". Fields: ' . implode( ', ', array_keys( $result ) ) );

		// FIX: Use 'overview' field (which the prompt returns) with fallback to 'description'.
		$post_content = $result['overview'] ?? $result['description'] ?? '';

		// Safeguard: if post_content is still empty after generation, log a warning.
		if ( empty( trim( $post_content ) ) ) {
			error_log( 'PSA: WARNING — AI returned valid JSON but post content (overview/description) is empty for "' . $peptide_name . '". Available keys: ' . implode( ', ', array_keys( $result ) ) );
			update_post_meta( $post_id, 'psa_generation_error', 'AI returned empty overview/description content. Available fields: ' . implode( ', ', array_keys( $result ) ) );
			return;
		}

		// Try PubChem enrichment.
		$pubchem_data = null;
		if ( ! empty( $options['use_pubchem'] ) ) {
			$pubchem_result = PSA_PubChem::lookup( $peptide_name );
			if ( is_wp_error( $pubchem_result ) ) {
				error_log( 'PSA: PubChem lookup failed for "' . $peptide_name . '": ' . $pubchem_result->get_error_message() );
			} elseif ( is_array( $pubchem_result ) ) {
				$pubchem_data = $pubchem_result;
				error_log( 'PSA: PubChem data found for "' . $peptide_name . '" (CID: ' . ( $pubchem_data['cid'] ?? 'unknown' ) . ')' );
			}
		}

		// Update the placeholder with real content.
		$post_status = ( 'publish' === $auto_publish ) ? 'publish' : 'draft';

		$update_result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_title'   => sanitize_text_field( $result['name'] ?? $peptide_name ),
				'post_content' => wp_kses_post( $post_content ),
				'post_status'  => $post_status,
			),
			true
		);

		if ( is_wp_error( $update_result ) ) {
			error_log( 'PSA: wp_update_post FAILED for post ' . $post_id . ': ' . $update_result->get_error_message() );
			update_post_meta( $post_id, 'psa_generation_error', 'Failed to update post: ' . $update_result->get_error_message() );
			return;
		}

		// Save all meta fields — prefer PubChem data for molecular properties.
		self::save_peptide_meta( $post_id, $result, $pubchem_data );

		// Auto-assign peptide_category taxonomy term based on AI category.
		self::assign_category_term( $post_id, $result );

		// Create a matching Knowledge Base article.
		PSA_KB_Builder::create_article( $post_id, $result, $peptide_name, $post_status );

		// Clean up generation tracking.
		delete_post_meta( $post_id, 'psa_generation_started' );
		delete_post_meta( $post_id, 'psa_generation_error' );
		update_post_meta( $post_id, 'psa_generation_completed', time() );

		// Allow developers to hook after generation completes.
		do_action( 'psa_after_generation', $post_id, $result, $peptide_name );

		error_log( 'PSA: Successfully completed generation for "' . $peptide_name . '" (post ' . $post_id . ', status: ' . $post_status . ')' );
	}

	/**
	 * Generate full peptide content via AI.
	 *
	 * @param string $peptide_name The peptide to research.
	 * @return array|WP_Error Parsed peptide data or error.
	 */
	public static function generate_peptide_content( string $peptide_name ) {
		$options = self::get_settings();
		$api_key = $options['api_key'];
		$model   = $options['ai_model'];

		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', 'OpenRouter API key is not configured.' );
		}

		// Check monthly budget before generation.
		if ( PSA_Cost_Tracker::is_budget_exceeded() ) {
			return new WP_Error( 'budget_exceeded', 'Monthly API budget reached.' );
		}

		$prompt   = self::build_generation_prompt( $peptide_name );
		// Allow filtering of generation prompt before sending.
		$prompt = apply_filters( 'psa_generation_prompt', $prompt, $peptide_name );
		$response = PSA_OpenRouter::send_request(
			$api_key,
			$model ? $model : 'google/gemini-2.5-flash',
			$prompt,
			PSA_Config::GENERATION_MAX_TOKENS,
			'generation',
			$peptide_name
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return PSA_OpenRouter::parse_response( $response );
	}

	/**
	 * Perform a dry-run to estimate costs WITHOUT making API calls.
	 *
	 * @param string $peptide_name The peptide name to estimate.
	 * @return array {
	 *     'validation' => { tokens: int, estimated_cost_usd: float },
	 *     'generation' => { tokens: int, estimated_cost_usd: float },
	 *     'total_estimated_cost_usd' => float,
	 * }
	 */
	public static function dry_run( string $peptide_name ): array {
		$options          = self::get_settings();
		$validation_model = $options['validation_model'] ? $options['validation_model'] : 'google/gemini-2.0-flash-001';
		$generation_model = $options['ai_model'] ? $options['ai_model'] : 'google/gemini-2.5-flash';

		$validation_prompt        = self::build_validation_prompt( $peptide_name );
		$validation_prompt_tokens = (int) ceil( strlen( $validation_prompt ) / 4 );
		$validation_completion    = PSA_Config::VALIDATION_MAX_TOKENS;
		$validation_cost          = PSA_Cost_Tracker::estimate_cost( $validation_model, $validation_prompt_tokens, $validation_completion );

		$generation_prompt        = self::build_generation_prompt( $peptide_name );
		$generation_prompt_tokens = (int) ceil( strlen( $generation_prompt ) / 4 );
		$generation_completion    = PSA_Config::GENERATION_MAX_TOKENS;
		$generation_cost          = PSA_Cost_Tracker::estimate_cost( $generation_model, $generation_prompt_tokens, $generation_completion );

		return array(
			'validation'              => array(
				'tokens'             => $validation_prompt_tokens + $validation_completion,
				'estimated_cost_usd' => $validation_cost,
			),
			'generation'              => array(
				'tokens'             => $generation_prompt_tokens + $generation_completion,
				'estimated_cost_usd' => $generation_cost,
			),
			'total_estimated_cost_usd' => $validation_cost + $generation_cost,
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Get plugin settings with defaults.
	 *
	 * @return array
	 */
	private static function get_settings(): array {
		$defaults = array(
			'api_key'          => '',
			'ai_model'         => '',
			'validation_model' => '',
			'auto_publish'     => 'draft',
			'use_pubchem'      => '1',
		);

		$settings = wp_parse_args( get_option( 'psa_settings', array() ), $defaults );

		// Decrypt API key if it's encrypted and constant is not defined.
		if ( ! empty( $settings['api_key'] ) && ! ( defined( 'PSA_OPENROUTER_KEY' ) && PSA_OPENROUTER_KEY ) ) {
			$decrypted = PSA_Encryption::decrypt( $settings['api_key'] );
			if ( false !== $decrypted ) {
				$settings['api_key'] = $decrypted;
			}
		}

		// Prefer wp-config.php constant over database value for security.
		if ( defined( 'PSA_OPENROUTER_KEY' ) && PSA_OPENROUTER_KEY ) {
			$settings['api_key'] = PSA_OPENROUTER_KEY;
		}

		$settings = apply_filters( 'psa_ai_settings', $settings );

		return $settings;
	}

	/**
	 * Save meta fields for a peptide post (core + extended).
	 *
	 * @param int        $post_id      The post ID.
	 * @param array      $ai_data      AI-generated data.
	 * @param array|null $pubchem_data PubChem data (or null).
	 */
	private static function save_peptide_meta( int $post_id, array $ai_data, ?array $pubchem_data ): void {
		// Derive amino acid count from sequence if available.
		$amino_acid_count = '';
		$sequence         = $ai_data['sequence'] ?? '';
		if ( ! empty( $sequence ) ) {
			// Count residues: split on dashes for three-letter codes, or count chars for single-letter.
			if ( strpos( $sequence, '-' ) !== false ) {
				$amino_acid_count = (string) count( explode( '-', $sequence ) );
			} else {
				$amino_acid_count = (string) strlen( preg_replace( '/[^A-Z]/', '', $sequence ) );
			}
		}
		// AI may also return this directly.
		if ( ! empty( $ai_data['amino_acid_count'] ) ) {
			$amino_acid_count = (string) intval( $ai_data['amino_acid_count'] );
		}

		$meta = array(
			'psa_sequence'              => $ai_data['sequence'] ?? '',
			'psa_molecular_weight'      => ! empty( $pubchem_data['molecular_weight'] )
				? $pubchem_data['molecular_weight']
				: ( $ai_data['molecular_weight'] ?? '' ),
			'psa_molecular_formula'     => ! empty( $pubchem_data['molecular_formula'] )
				? $pubchem_data['molecular_formula']
				: ( $ai_data['molecular_formula'] ?? '' ),
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
			// Extended fields (v4.3.0).
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
	 * Maps the AI's free-text category to the registered taxonomy terms.
	 * Falls back to fuzzy matching if no exact match found.
	 *
	 * @param int   $post_id The peptide post ID.
	 * @param array $ai_data AI-generated data containing 'category' or 'category_label'.
	 */
	private static function assign_category_term( int $post_id, array $ai_data ): void {
		$ai_category = $ai_data['category'] ?? $ai_data['category_label'] ?? '';
		if ( empty( $ai_category ) ) {
			return;
		}

		// Mapping from AI free-text categories to taxonomy slugs.
		// Why: AI returns varied labels ("GH Secretagogues", "Healing & Repair") that need
		// to map to our controlled vocabulary of taxonomy terms.
		$category_map = array(
			'gh secretagogues'          => 'growth-hormone',
			'growth hormone'            => 'growth-hormone',
			'healing & repair'          => 'tissue-repair',
			'healing and repair'        => 'tissue-repair',
			'tissue repair'             => 'tissue-repair',
			'tissue healing'            => 'tissue-repair',
			'cytoprotective'            => 'tissue-repair',
			'melanocortin peptides'     => 'dermatological',
			'melanocortin'              => 'dermatological',
			'dermatological'            => 'dermatological',
			'metabolic & anti-aging'    => 'metabolic',
			'metabolic and anti-aging'  => 'metabolic',
			'metabolic'                 => 'metabolic',
			'anti-aging'                => 'aging-research',
			'aging research'            => 'aging-research',
			'nootropic & neuroprotective' => 'immunology',
			'nootropic'                 => 'immunology',
			'neuroprotective'           => 'immunology',
			'immunology'                => 'immunology',
			'immune'                    => 'immunology',
			'lipid metabolism'          => 'lipid-metabolism',
			'endocrine'                 => 'endocrine',
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
	 * Validate peptide name input characters and block injection patterns.
	 *
	 * @param string $name The peptide name to validate.
	 * @return bool True if input looks legitimate.
	 */
	private static function validate_peptide_input( string $name ): bool {
		if ( ! preg_match( '/^[\p{L}\d\s\-\(\),\.\/\+\[\]]+$/u', $name ) ) {
			return false;
		}
		$blocked = array( 'ignore', 'instruction', 'override', 'system prompt', 'forget', 'disregard', 'pretend' );
		$lower   = strtolower( $name );
		foreach ( $blocked as $word ) {
			if ( strpos( $lower, $word ) !== false ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Build the lightweight validation prompt.
	 *
	 * @param string $name The peptide name to validate.
	 * @return string Prompt text ready to send to OpenRouter API.
	 */
	private static function build_validation_prompt( string $name ): string {
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
	 * @return string Prompt text ready to send to OpenRouter API.
	 */
	private static function build_generation_prompt( string $peptide_name ): string {
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

}
