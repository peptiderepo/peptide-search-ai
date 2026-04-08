<?php
/**
 * Handles AI content generation for new peptide entries.
 * Uses OpenRouter API to access a wide range of open-source and commercial models.
 * Includes peptide name validation and background generation.
 *
 * @package PeptideSearchAI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PSA_AI_Generator {

    /**
     * OpenRouter API base URL.
     */
    const API_URL = 'https://openrouter.ai/api/v1/chat/completions';

    public static function init() {
        add_action( 'psa_generate_peptide_background', array( __CLASS__, 'background_generate' ), 10, 2 );
    }

    /**
     * Validate whether a search term is a legitimate peptide name.
     * Uses a lightweight AI call to check before committing to full generation.
     *
     * @param string $name The name to validate.
     * @return array|WP_Error { is_valid: bool, canonical_name: string, reason: string }
     */
    public static function validate_peptide_name( $name ) {
        $options = self::get_settings();
        $api_key = $options['api_key'];
        $model   = $options['validation_model'];

        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', 'OpenRouter API key is not configured.' );
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
        $response = self::call_openrouter(
			$api_key,
			$model ?: 'google/gemini-2.0-flash-001',
			$prompt,
			PSA_Config::VALIDATION_MAX_TOKENS
		);

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $data = self::parse_ai_response( $response );
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
        set_transient( $cache_key, $result, DAY_IN_SECONDS ); // Validation cache TTL

        return $result;
    }

    /**
     * Background generation handler (called via WP-Cron or synchronously).
     *
     * @param int    $post_id      The placeholder post ID.
     * @param string $peptide_name The peptide to research.
     */
    public static function background_generate( $post_id, $peptide_name ) {
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

        error_log( 'PSA: Generating content for "' . $peptide_name . '" (post ' . $post_id . ') using model: ' . ( $options['ai_model'] ?: 'google/gemini-2.5-flash' ) );

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
                // Log PubChem API failures.
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

        // Create a matching Knowledge Base article.
        self::create_kb_article( $post_id, $result, $peptide_name );

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
    public static function generate_peptide_content( $peptide_name ) {
        $options = self::get_settings();
        $api_key = $options['api_key'];
        $model   = $options['ai_model'];

        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', 'OpenRouter API key is not configured.' );
        }

        $prompt   = self::build_generation_prompt( $peptide_name );
        // Allow filtering of generation prompt before sending.
        $prompt = apply_filters( 'psa_generation_prompt', $prompt, $peptide_name );
        $response = self::call_openrouter(
			$api_key,
			$model ?: 'google/gemini-2.5-flash',
			$prompt,
			PSA_Config::GENERATION_MAX_TOKENS
		);

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return self::parse_ai_response( $response );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Get plugin settings with defaults.
     *
     * @return array
     */
    private static function get_settings() {
        $defaults = array(
            'api_key'          => '',
            'ai_model'         => '',
            'validation_model' => '',
            'auto_publish'     => 'draft',
            'use_pubchem'      => '1',
        );

        $settings = wp_parse_args( get_option( 'psa_settings', array() ), $defaults );

        // Prefer wp-config.php constant over database value for security.
        if ( defined( 'PSA_OPENROUTER_KEY' ) && PSA_OPENROUTER_KEY ) {
            $settings['api_key'] = PSA_OPENROUTER_KEY;
        }

        // Allow filtering of settings before use.
        $settings = apply_filters( 'psa_ai_settings', $settings );

        return $settings;
    }

    /**
     * Save meta fields for a peptide post.
     *
     * @param int        $post_id      The post ID.
     * @param array      $ai_data      AI-generated data.
     * @param array|null $pubchem_data PubChem data (or null).
     */
    private static function save_peptide_meta( $post_id, $ai_data, $pubchem_data ) {
        $meta = array(
            'psa_sequence'          => $ai_data['sequence'] ?? '',
            'psa_molecular_weight'  => ! empty( $pubchem_data['molecular_weight'] )
                ? $pubchem_data['molecular_weight']
                : ( $ai_data['molecular_weight'] ?? '' ),
            'psa_molecular_formula' => ! empty( $pubchem_data['molecular_formula'] )
                ? $pubchem_data['molecular_formula']
                : ( $ai_data['molecular_formula'] ?? '' ),
            'psa_aliases'           => $ai_data['aliases'] ?? '',
            'psa_mechanism'         => $ai_data['mechanism'] ?? '',
            'psa_overview'          => $ai_data['overview'] ?? $ai_data['description'] ?? '',
            'psa_category_label'    => $ai_data['category_label'] ?? '',
            'psa_origin'            => $ai_data['origin'] ?? '',
            'psa_research_apps'     => $ai_data['research_benefits'] ?? ( $ai_data['research_applications'] ?? '' ),
            'psa_safety_profile'    => $ai_data['safety_side_effects'] ?? ( $ai_data['safety_profile'] ?? '' ),
            'psa_dosage_info'       => $ai_data['administration_dosing'] ?? ( $ai_data['dosage_info'] ?? '' ),
            'psa_legal_regulatory'  => $ai_data['legal_regulatory'] ?? '',
            'psa_references'        => $ai_data['references'] ?? '',
            'psa_category'          => $ai_data['category'] ?? '',
            'psa_source'            => ! empty( $pubchem_data ) ? 'pubchem' : 'ai-generated',
            'psa_pubchem_cid'       => $pubchem_data['cid'] ?? '',
        );

        // Allow filtering of meta before save.
        $meta = apply_filters( 'psa_peptide_meta', $meta, $post_id, $ai_data, $pubchem_data );

        foreach ( $meta as $key => $value ) {
            update_post_meta( $post_id, $key, $value );
        }
    }

    /**
     * Build the lightweight validation prompt.
     *
     * Uses wp_json_encode() to safely encode the peptide name within the prompt.
     * This ensures special characters and quotes are properly escaped before sending to the AI.
     * The JSON-encoded format also helps the AI parser recognize the input clearly.
     *
     * @param string $name The peptide name to validate.
     * @return string Prompt text ready to send to OpenRouter API.
     */
    private static function build_validation_prompt( $name ) {
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
     * Build the comprehensive generation prompt.
     *
     * Constructs a detailed prompt that instructs the AI to generate structured JSON output
     * with all required fields for a complete peptide database entry (name, sequence, mechanism,
     * research benefits, dosing, safety, legal status, references, etc).
     *
     * The prompt is designed to ensure the AI returns clean, factual information suitable for
     * scientific audiences. Uses JSON encoding for the peptide name to properly escape special characters.
     *
     * @param string $peptide_name The peptide to research.
     * @return string Prompt text ready to send to OpenRouter API.
     */
    private static function build_generation_prompt( $peptide_name ) {
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
  "research_benefits": "Detailed research applications organized by therapeutic area. Use area names as subheadings followed by a paragraph, e.g.:\n\nMusculoskeletal Healing\nParagraph about musculoskeletal research...\n\nGastrointestinal Protection\nParagraph about GI research...\n\nInclude 3-5 areas depending on the peptide.",
  "administration_dosing": "Include a disclaimer that this is from research only. Then describe: typical research dose range, routes of administration, frequency, and duration from studies.",
  "safety_side_effects": "Known safety profile from animal studies. Include any anecdotally reported side effects. Note theoretical concerns. Mention that long-term human safety data is limited.",
  "legal_regulatory": "Current regulatory and legal status. Mention FDA status, WADA status if applicable, classification as research chemical.",
  "references": "5-8 real published scientific references with authors, title, journal, year. Use real studies only.",
  "category": "Classify into exactly ONE: GH Secretagogues, Healing & Repair, Melanocortin Peptides, Metabolic & Anti-Aging, Nootropic & Neuroprotective"
}

Important:
- Use factual, evidence-based information only.
- If information is uncertain or unknown, say so explicitly rather than fabricating data.
- All content is for research and educational purposes.
- Write in clean paragraphs. Do not use markdown formatting symbols like # or ** or *.
- For research_benefits, use the therapeutic area name on its own line as a subheading, followed by a descriptive paragraph.
PROMPT;
    }

    // -------------------------------------------------------------------------
    // OpenRouter API Call
    // -------------------------------------------------------------------------

    /**
     * Call OpenRouter API with automatic retry on rate limits.
     *
     * Implements exponential backoff retry strategy for rate limit (429) errors.
     * On first rate limit: waits 5s before retry.
     * On second rate limit: waits 10s before retry.
     * On third rate limit: waits 20s before final attempt.
     * Other errors are returned immediately without retry.
     *
     * @param string $api_key    The OpenRouter API key.
     * @param string $model      The model identifier.
     * @param string $prompt     The prompt text.
     * @param int    $max_tokens Maximum tokens to generate.
     * @return string|WP_Error Response text or error.
     */
    private static function call_openrouter( $api_key, $model, $prompt, $max_tokens = PSA_Config::GENERATION_MAX_TOKENS ) {
        $max_retries = PSA_Config::API_RETRY_MAX;
        $base_delay  = PSA_Config::API_RETRY_BASE_DELAY; // seconds

        for ( $attempt = 1; $attempt <= $max_retries; $attempt++ ) {
            $result = self::call_openrouter_once( $api_key, $model, $prompt, $max_tokens );

            // If not a rate limit error, return immediately.
            if ( ! is_wp_error( $result ) || 'rate_limited' !== $result->get_error_code() ) {
                return $result;
            }

            // Rate limited — retry with exponential backoff.
            if ( $attempt < $max_retries ) {
                $delay = $base_delay * pow( 2, $attempt - 1 ); // 5s, 10s, 20s
                error_log( 'PSA: Rate limited on attempt ' . $attempt . '/' . $max_retries . '. Retrying in ' . $delay . 's...' );
                sleep( $delay );
            }
        }

        error_log( 'PSA: All ' . $max_retries . ' attempts failed due to rate limiting.' );
        return new WP_Error( 'rate_limited', 'OpenRouter rate limit reached after ' . $max_retries . ' retries. Consider adding credits at openrouter.ai or switching to a non-free model.' );
    }

    /**
     * Single OpenRouter API call (no retry).
     *
     * @param string $api_key    The OpenRouter API key.
     * @param string $model      The model identifier.
     * @param string $prompt     The prompt text.
     * @param int    $max_tokens Maximum tokens to generate.
     * @return string|WP_Error Response text or error.
     */
    private static function call_openrouter_once( $api_key, $model, $prompt, $max_tokens = 4096 ) {
        $site_url  = get_bloginfo( 'url' );
        $site_name = get_bloginfo( 'name' );

        $response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 120,
				'headers' => array(
					'Content-Type'    => 'application/json',
					'Authorization'   => 'Bearer ' . $api_key,
					'HTTP-Referer'    => $site_url,
					'X-Title'         => $site_name,
				),
				'body' => wp_json_encode(
					array(
						'model'       => $model,
						'messages'    => array(
							array( 'role' => 'system', 'content' => 'You are a scientific database assistant. Return only valid JSON.' ),
							array( 'role' => 'user', 'content' => $prompt ),
						),
						'max_tokens'  => $max_tokens,
						'temperature' => 0.3,
					)
				),
			)
		);

        if ( is_wp_error( $response ) ) {
            error_log( 'PSA: OpenRouter connection error: ' . $response->get_error_message() );
            return new WP_Error( 'api_error', 'Failed to connect to OpenRouter API: ' . $response->get_error_message() );
        }

        $code     = wp_remote_retrieve_response_code( $response );
        $raw_body = wp_remote_retrieve_body( $response );
        $body     = json_decode( $raw_body, true );

        error_log( 'PSA: OpenRouter response code: ' . $code . ' for model: ' . $model );

        if ( 200 !== $code ) {
            if ( 429 === $code ) {
                error_log( 'PSA: OpenRouter rate limited (429)' );
                return new WP_Error( 'rate_limited', 'OpenRouter rate limit reached. Please try again in a few moments.' );
            }
            if ( 402 === $code ) {
                error_log( 'PSA: OpenRouter insufficient credits (402)' );
                return new WP_Error( 'insufficient_credits', 'OpenRouter account has insufficient credits. Please add funds at openrouter.ai.' );
            }
            $msg = $body['error']['message'] ?? ( 'Unknown error (HTTP ' . $code . ')' );
            error_log( 'PSA: OpenRouter API error (' . $code . '): ' . $msg );
            error_log( 'PSA: OpenRouter raw response: ' . substr( $raw_body, 0, 500 ) );
            return new WP_Error( 'api_error', 'OpenRouter API error: ' . $msg );
        }

        if ( empty( $body['choices'][0]['message']['content'] ) ) {
            error_log( 'PSA: OpenRouter returned empty content. Raw: ' . substr( $raw_body, 0, 500 ) );
            return new WP_Error( 'api_error', 'OpenRouter API returned an empty response.' );
        }

        return $body['choices'][0]['message']['content'];
    }

    /**
     * Parse AI response text into structured data.
     *
     * @param string $response_text Raw response text.
     * @return array|WP_Error Parsed data or error.
     */
    private static function parse_ai_response( $response_text ) {
        // Strip any <think>...</think> tags (some models like DeepSeek include reasoning).
        $cleaned = preg_replace( '/<think>.*?<\/think>/s', '', $response_text );

        // Strip markdown code fences if present.
        $cleaned = preg_replace( '/^```(?:json)?\s*/m', '', $cleaned );
        $cleaned = preg_replace( '/\s*```\s*$/m', '', $cleaned );
        $cleaned = trim( $cleaned );

        $data = json_decode( $cleaned, true );
        if ( JSON_ERROR_NONE !== json_last_error() ) {
            error_log( 'PSA: JSON parse error: ' . json_last_error_msg() . '. Raw (first 500 chars): ' . substr( $response_text, 0, 500 ) );
            return new WP_Error(
                'parse_error',
                'Failed to parse AI response as JSON: ' . json_last_error_msg()
            );
        }

        return $data;
    }

    /**
     * Create a matching Echo Knowledge Base article with full structured content.
     *
     * @param int   $peptide_post_id The peptide post ID.
     * @param array $ai_data         The parsed AI data.
     * @param string $peptide_name   The peptide name.
     */
    private static function create_kb_article( $peptide_post_id, $ai_data, $peptide_name ) {
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

        // Build header meta line.
        $cat_label = ! empty( $ai_data['category_label'] ) ? $ai_data['category_label'] : '';
        $origin    = ! empty( $ai_data['origin'] )         ? $ai_data['origin']         : '';
        $sequence  = ! empty( $ai_data['sequence'] )       ? $ai_data['sequence']       : '';

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

        // Build full article content.
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

        $kb_post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => wp_kses_post( $c ),
				'post_type'    => 'epkb_post_type_1',
				'post_status'  => 'publish',
			),
			true
		);

        if ( is_wp_error( $kb_post_id ) ) {
            error_log( 'PSA: Failed to create KB article: ' . $kb_post_id->get_error_message() );
            return;
        }

        // Assign KB category.
        $category_map = array(
            'GH Secretagogues'          => 'gh-secretagogues',
            'Healing & Repair'          => 'healing-repair',
            'Melanocortin Peptides'     => 'melanocortin-peptides',
            'Metabolic & Anti-Aging'    => 'metabolic-anti-aging',
            'Nootropic & Neuroprotective' => 'nootropic-neuroprotective',
        );

        $cat_name = trim( $ai_data['category'] ?? '' );
        if ( isset( $category_map[ $cat_name ] ) ) {
            $term = get_term_by( 'slug', $category_map[ $cat_name ], 'epkb_post_type_1_category' );
            if ( $term ) {
                wp_set_object_terms( $kb_post_id, array( (int) $term->term_id ), 'epkb_post_type_1_category' );
            }
        }

        update_post_meta( $peptide_post_id, 'psa_kb_article_id', $kb_post_id );
        update_post_meta( $kb_post_id, 'psa_peptide_post_id', $peptide_post_id );

        error_log( 'PSA: Created KB article #' . $kb_post_id . ' for "' . $peptide_name . '"' );
    }

    /**
     * Convert text to HTML paragraphs.
     */
    private static function paragraphs( $text ) {
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
     */
    private static function research_with_subheadings( $text ) {
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
                if ( $first_nl !== false ) {
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
