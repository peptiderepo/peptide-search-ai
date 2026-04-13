<?php
declare( strict_types=1 );
/**
 * OpenRouter API communication layer.
 *
 * What: Handles HTTP requests to OpenRouter API with retry logic, rate limit handling,
 *       and response parsing. Logs all calls via PSA_Cost_Tracker.
 * Who calls it: PSA_AI_Generator for validation and generation API calls.
 * Dependencies: WordPress HTTP API (wp_remote_post), PSA_Config, PSA_Cost_Tracker.
 *
 * @package PeptideSearchAI
 * @see     includes/class-psa-ai-generator.php
 * @see     includes/class-psa-cost-tracker.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSA_OpenRouter {

	/**
	 * OpenRouter API base URL.
	 */
	const API_URL = 'https://openrouter.ai/api/v1/chat/completions';

	/**
	 * Call OpenRouter API with automatic retry on rate limits.
	 *
	 * Implements exponential backoff retry strategy for rate limit (429) errors.
	 * On first rate limit: waits 5s before retry.
	 * On second rate limit: waits 10s before retry.
	 * On third rate limit: waits 20s before final attempt.
	 * Other errors are returned immediately without retry.
	 *
	 * @param string $api_key      The OpenRouter API key.
	 * @param string $model        The model identifier.
	 * @param string $prompt       The prompt text.
	 * @param int    $max_tokens   Maximum tokens to generate.
	 * @param string $request_type 'validation' or 'generation' (default).
	 * @param string $peptide_name Peptide name for logging.
	 * @return string|WP_Error Response text or error.
	 */
	public static function send_request( string $api_key, string $model, string $prompt, int $max_tokens = PSA_Config::GENERATION_MAX_TOKENS, string $request_type = 'generation', string $peptide_name = '' ) {
		$max_retries = PSA_Config::API_RETRY_MAX;
		$base_delay  = PSA_Config::API_RETRY_BASE_DELAY; // seconds

		for ( $attempt = 1; $attempt <= $max_retries; $attempt++ ) {
			$result = self::send_request_once( $api_key, $model, $prompt, $max_tokens, $request_type, $peptide_name );

			// If not a rate limit error, return immediately.
			if ( ! is_wp_error( $result ) || 'rate_limited' !== $result->get_error_code() ) {
				return $result;
			}

			// Rate limited — retry with exponential backoff.
			if ( $attempt < $max_retries ) {
				$delay = $base_delay * pow( 2, $attempt - 1 ); // 5s, 10s, 20s
				// Avoid blocking PHP workers during WP-Cron execution.
				if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
					error_log( 'PSA: Rate limited during cron on attempt ' . $attempt . '/' . $max_retries . '. Returning to free PHP worker.' );
					return new WP_Error( 'rate_limited', 'Rate limited during background generation. Will retry on next cron trigger.' );
				}
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
	 * @param string $api_key      The OpenRouter API key.
	 * @param string $model        The model identifier.
	 * @param string $prompt       The prompt text.
	 * @param int    $max_tokens   Maximum tokens to generate.
	 * @param string $request_type 'validation' or 'generation' (default).
	 * @param string $peptide_name Peptide name for logging.
	 * @return string|WP_Error Response text or error.
	 */
	private static function send_request_once( string $api_key, string $model, string $prompt, int $max_tokens = 4096, string $request_type = 'generation', string $peptide_name = '' ) {
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
							array(
								'role'    => 'system',
								'content' => 'You are a scientific database assistant. Return only valid JSON.',
							),
							array(
								'role'    => 'user',
								'content' => $prompt,
							),
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

		// Log API call with token usage.
		$prompt_tokens = (int) ( $body['usage']['prompt_tokens'] ?? 0 );
		$completion_tokens = (int) ( $body['usage']['completion_tokens'] ?? 0 );

		PSA_Cost_Tracker::log_api_call(
			array(
				'provider'       => 'openrouter',
				'model'          => $model,
				'prompt_tokens'  => $prompt_tokens,
				'completion_tokens' => $completion_tokens,
				'request_type'   => $request_type,
				'peptide_name'   => $peptide_name,
				'success'        => true,
			)
		);

		return $body['choices'][0]['message']['content'];
	}

	/**
	 * Parse AI response text into structured data.
	 *
	 * Strips thinking tags (e.g., DeepSeek) and markdown code fences,
	 * then decodes the JSON response.
	 *
	 * @param string $response_text Raw response text from OpenRouter.
	 * @return array|WP_Error Parsed data or error.
	 */
	public static function parse_response( string $response_text ) {
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
}
