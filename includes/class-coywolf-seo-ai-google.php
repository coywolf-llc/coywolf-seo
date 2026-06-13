<?php
/**
 * Google (Gemini) AI provider for Coywolf SEO.
 *
 * Concrete {@see Coywolf_SEO_AI_Provider} implementation for Google's Gemini
 * models against the Generative Language API (generativelanguage.googleapis.com,
 * v1beta). Plain-text generation runs through WordPress 7.0's bundled
 * php-ai-client with the Google provider; the parts the SDK does not abstract
 * are implemented here with raw HTTP against the Gemini API:
 *
 * - Model listing (GET /v1beta/models).
 * - Vision (image/PDF -> text) via the generateContent endpoint.
 * - Gemini Batch Mode (submit / poll / results / cancel) used by the bulk
 *   enrichment runner for ~50% off asynchronous processing.
 *
 * Authentication uses the `x-goog-api-key` request header (preferred over the
 * ?key= query parameter so the key never lands in a URL or log).
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gemini (Google) provider.
 */
final class Coywolf_SEO_AI_Google extends Coywolf_SEO_AI_Provider {

	/**
	 * Generative Language API base URL (v1beta).
	 */
	const API_BASE = 'https://generativelanguage.googleapis.com/v1beta';

	/**
	 * Timeout (seconds) for vision generateContent requests.
	 */
	const REQUEST_TIMEOUT = 90;

	/**
	 * Timeout (seconds) for batch transport requests.
	 */
	const BATCH_TIMEOUT = 90;

	/* ------------------------------------------------------------------ *
	 * Identity.
	 * ------------------------------------------------------------------ */

	/**
	 * {@inheritdoc}
	 */
	public function id() {
		return 'google';
	}

	/**
	 * {@inheritdoc}
	 */
	public function label() {
		return __( 'Gemini (Google)', 'coywolf-seo' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function key_option() {
		return 'ai_api_key_google';
	}

	/**
	 * {@inheritdoc}
	 */
	public function key_constant() {
		return 'GEMINI_API_KEY';
	}

	/**
	 * {@inheritdoc}
	 */
	public function model_option() {
		return 'ai_model_google';
	}

	/**
	 * {@inheritdoc}
	 */
	public function default_model() {
		return 'gemini-2.5-flash';
	}

	/**
	 * {@inheritdoc}
	 */
	public function provider_class() {
		return 'WordPress\\GoogleAiProvider\\Provider\\GoogleProvider';
	}

	/* ------------------------------------------------------------------ *
	 * Model listing.
	 * ------------------------------------------------------------------ */

	/**
	 * {@inheritdoc}
	 *
	 * Live list from the Gemini models endpoint, filtered to gemini-* ids that
	 * support generateContent. Caching is the caller's responsibility — this
	 * just fetches and returns.
	 */
	public function list_models( $key ) {
		if ( '' === (string) $key ) {
			return array();
		}
		$response = wp_remote_get(
			self::API_BASE . '/models',
			array(
				'timeout'    => 8,
				'user-agent' => $this->user_agent(),
				'headers'    => array(
					'x-goog-api-key' => (string) $key,
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$models = array();
		foreach ( (array) ( $body['models'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) || empty( $row['name'] ) ) {
				continue;
			}
			$methods = (array) ( $row['supportedGenerationMethods'] ?? array() );
			if ( ! in_array( 'generateContent', $methods, true ) ) {
				continue;
			}
			// Strip the leading 'models/' segment to get the short id.
			$short = preg_replace( '#^models/#', '', (string) $row['name'] );
			if ( 0 !== strpos( $short, 'gemini-' ) ) {
				continue;
			}
			$models[] = array(
				'id'   => $short,
				'name' => ! empty( $row['displayName'] ) ? (string) $row['displayName'] : $short,
			);
		}
		return $models;
	}

	/**
	 * {@inheritdoc}
	 */
	public function fallback_models() {
		return array(
			array(
				'id'   => 'gemini-2.5-flash',
				'name' => 'Gemini 2.5 Flash',
			),
			array(
				'id'   => 'gemini-2.5-pro',
				'name' => 'Gemini 2.5 Pro',
			),
			array(
				'id'   => 'gemini-2.0-flash',
				'name' => 'Gemini 2.0 Flash',
			),
			array(
				'id'   => 'gemini-1.5-pro',
				'name' => 'Gemini 1.5 Pro',
			),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Vision (image/PDF -> text).
	 * ------------------------------------------------------------------ */

	/**
	 * {@inheritdoc}
	 *
	 * Sends the media file as a base64 inline_data part alongside the text
	 * prompt to the generateContent endpoint. Returns the concatenated
	 * assistant text and token usage; parsing the text into fields is the
	 * caller's job.
	 */
	public function vision_generate( $key, $model, array $payload, $system, $prompt, $max_tokens ) {
		$body = array(
			'systemInstruction' => array(
				'parts' => array(
					array( 'text' => (string) $system ),
				),
			),
			'contents'          => array(
				array(
					'role'  => 'user',
					'parts' => array(
						array(
							'inline_data' => array(
								'mime_type' => (string) $payload['media_type'],
								'data'      => (string) $payload['data'],
							),
						),
						array(
							'text' => (string) $prompt,
						),
					),
				),
			),
			'generationConfig'  => array(
				'maxOutputTokens' => max( 100, (int) $max_tokens ),
			),
		);

		$response = wp_remote_post(
			self::API_BASE . '/models/' . rawurlencode( (string) $model ) . ':generateContent',
			array(
				'timeout'    => self::REQUEST_TIMEOUT,
				'user-agent' => $this->user_agent(),
				'headers'    => array(
					'x-goog-api-key' => (string) $key,
					'content-type'   => 'application/json',
				),
				'body'       => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'coywolf_seo_api', $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'coywolf_seo_api', $this->http_error( $response ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return array(
			'text'   => $this->candidate_text( $data ),
			'input'  => (int) ( $data['usageMetadata']['promptTokenCount'] ?? 0 ),
			'output' => (int) ( $data['usageMetadata']['candidatesTokenCount'] ?? 0 ),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Batch API (Gemini Batch Mode — asynchronous, ~50% off).
	 * ------------------------------------------------------------------ */

	/**
	 * {@inheritdoc}
	 *
	 * NOTE: Gemini Batch Mode request/response nesting is the least certain
	 * part of this provider and needs verification against a live key. Each
	 * inline request carries the correlation id under metadata.key so it can
	 * be matched back in {@see batch_results()}.
	 */
	public function batch_build( $custom_id, $model, $system, $user, $max_tokens ) {
		return array(
			'request'  => array(
				'system_instruction' => array(
					'parts' => array(
						array( 'text' => (string) $system ),
					),
				),
				'contents'           => array(
					array(
						'role'  => 'user',
						'parts' => array(
							array( 'text' => (string) $user ),
						),
					),
				),
				'generationConfig'   => array(
					'maxOutputTokens' => max( 100, (int) $max_tokens ),
				),
			),
			'metadata' => array(
				'key' => (string) $custom_id,
			),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * NOTE: needs verification against a live Gemini key. Submits inline
	 * requests via batchGenerateContent; the response is a long-running
	 * operation whose 'name' (e.g. 'batches/abc' or 'operations/...') is the
	 * opaque handle round-tripped through poll()/results().
	 */
	public function batch_submit( $key, $model, array $requests ) {
		$body     = array(
			'batch' => array(
				'display_name' => 'coywolf-seo-' . count( $requests ),
				'input_config' => array(
					'requests' => array(
						'requests' => array_values( $requests ),
					),
				),
			),
		);
		$response = $this->batch_http(
			$key,
			'POST',
			self::API_BASE . '/models/' . rawurlencode( (string) $model ) . ':batchGenerateContent',
			$body
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( empty( $response['name'] ) ) {
			return new WP_Error( 'coywolf_seo_batch', __( 'The batch could not be created.', 'coywolf-seo' ) );
		}
		return (string) $response['name'];
	}

	/**
	 * {@inheritdoc}
	 *
	 * NOTE: needs verification against a live Gemini key. The $handle already
	 * includes its resource prefix (e.g. 'batches/abc'), so it is appended to
	 * the base URL as-is. The same handle is round-tripped as results_handle so
	 * batch_results() re-GETs it.
	 */
	public function batch_poll( $key, $handle ) {
		$response = $this->batch_http( $key, 'GET', self::API_BASE . '/' . ltrim( (string) $handle, '/' ), null );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		// The GET returns a long-running Operation wrapping a GenerateContentBatch;
		// its terminal status is the inner 'state' enum. Treat the operation's
		// 'done' flag OR any terminal BATCH_STATE_*/JOB_STATE_* state as ended.
		$batch    = isset( $response['response'] ) && is_array( $response['response'] ) ? $response['response'] : $response;
		$state    = isset( $batch['state'] ) ? (string) $batch['state'] : '';
		$terminal = array(
			'BATCH_STATE_SUCCEEDED',
			'BATCH_STATE_FAILED',
			'BATCH_STATE_CANCELLED',
			'BATCH_STATE_EXPIRED',
			'JOB_STATE_SUCCEEDED',
			'JOB_STATE_FAILED',
			'JOB_STATE_CANCELLED',
			'JOB_STATE_EXPIRED',
		);
		return array(
			'ended'          => ! empty( $response['done'] ) || in_array( $state, $terminal, true ),
			'results_handle' => (string) $handle,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * NOTE: needs verification against a live Gemini key. Re-GETs the operation
	 * and reads the inlined responses. The container nesting is uncertain: the
	 * inlined-responses array may live at response.inlinedResponses.inlinedResponses
	 * or directly at response.inlinedResponses — both shapes are handled. Each
	 * entry carries its correlation id under metadata.key and either a
	 * GenerateContentResponse under 'response' or an 'error'.
	 */
	public function batch_results( $key, $results_handle ) {
		$response = $this->batch_http( $key, 'GET', self::API_BASE . '/' . ltrim( (string) $results_handle, '/' ), null );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Documented path: Operation -> response (GenerateContentBatch) -> output
		// (GenerateContentBatchOutput) -> inlinedResponses (object) -> inlinedResponses
		// (array). Try the output level first, then fall back to the batch level and
		// the flat list for forward-compat.
		$batch   = isset( $response['response'] ) && is_array( $response['response'] ) ? $response['response'] : $response;
		$out_obj = isset( $batch['output'] ) && is_array( $batch['output'] ) ? $batch['output'] : $batch;
		$entries = array();
		foreach ( array( $out_obj, $batch ) as $scope ) {
			if ( isset( $scope['inlinedResponses']['inlinedResponses'] ) && is_array( $scope['inlinedResponses']['inlinedResponses'] ) ) {
				$entries = $scope['inlinedResponses']['inlinedResponses'];
				break;
			}
			if ( isset( $scope['inlinedResponses'] ) && is_array( $scope['inlinedResponses'] ) && ! isset( $scope['inlinedResponses']['inlinedResponses'] ) ) {
				$entries = $scope['inlinedResponses'];
				break;
			}
		}

		$out = array();
		foreach ( (array) $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$custom_id = isset( $entry['metadata']['key'] ) ? (string) $entry['metadata']['key'] : '';
			if ( '' === $custom_id ) {
				continue;
			}

			if ( isset( $entry['error'] ) && ! empty( $entry['error'] ) ) {
				$err = $entry['error'];
				if ( is_array( $err ) ) {
					$message = isset( $err['message'] ) ? (string) $err['message'] : wp_json_encode( $err );
				} else {
					$message = (string) $err;
				}
				$out[ $custom_id ] = array(
					'ok'     => false,
					'text'   => '',
					'error'  => $message,
					'input'  => 0,
					'output' => 0,
				);
				continue;
			}

			$gen               = isset( $entry['response'] ) && is_array( $entry['response'] ) ? $entry['response'] : array();
			$out[ $custom_id ] = array(
				'ok'     => true,
				'text'   => $this->candidate_text( $gen ),
				'error'  => '',
				'input'  => (int) ( $gen['usageMetadata']['promptTokenCount'] ?? 0 ),
				'output' => (int) ( $gen['usageMetadata']['candidatesTokenCount'] ?? 0 ),
			);
		}
		return $out;
	}

	/**
	 * {@inheritdoc}
	 *
	 * NOTE: needs verification against a live Gemini key. Best-effort cancel of
	 * the long-running batch operation; the $handle includes its resource prefix.
	 */
	public function batch_cancel( $key, $handle ) {
		$this->batch_http( $key, 'POST', self::API_BASE . '/' . ltrim( (string) $handle, '/' ) . ':cancel', null );
	}

	/**
	 * {@inheritdoc}
	 */
	public function supports_batch() {
		return true;
	}

	/* ------------------------------------------------------------------ *
	 * Pricing.
	 * ------------------------------------------------------------------ */

	/**
	 * {@inheritdoc}
	 *
	 * Standard (non-batch) token prices per million, by model-ID prefix.
	 * Estimates; most specific prefix wins (resolved by the base price_lookup()).
	 */
	public function standard_prices() {
		return array(
			'gemini-2.5-flash-lite' => array( 0.1, 0.4 ),
			'gemini-2.5-flash'      => array( 0.3, 2.5 ),
			'gemini-2.5-pro'        => array( 1.25, 10.0 ),
			'gemini-2.0-flash'      => array( 0.1, 0.4 ),
			'gemini-1.5-flash'      => array( 0.075, 0.3 ),
			'gemini-1.5-pro'        => array( 1.25, 5.0 ),
			'gemini-'               => array( 0.3, 2.5 ),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * Batch-discounted prices per million tokens — Gemini Batch Mode is ~50%
	 * off, so this is the standard table with every value halved. Estimates.
	 */
	public function batch_prices() {
		/**
		 * Batch-rate token prices per million, keyed by model prefix.
		 *
		 * @param array $prices Prefix => [input, output].
		 */
		return (array) apply_filters(
			'coywolf_seo_ai_batch_prices',
			array(
				'gemini-2.5-flash-lite' => array( 0.05, 0.2 ),
				'gemini-2.5-flash'      => array( 0.15, 1.25 ),
				'gemini-2.5-pro'        => array( 0.625, 5.0 ),
				'gemini-2.0-flash'      => array( 0.05, 0.2 ),
				'gemini-1.5-flash'      => array( 0.0375, 0.15 ),
				'gemini-1.5-pro'        => array( 0.625, 2.5 ),
				'gemini-'               => array( 0.15, 1.25 ),
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Helpers.
	 * ------------------------------------------------------------------ */

	/**
	 * Concatenate the text parts of a GenerateContentResponse's first candidate.
	 *
	 * @param array $data Decoded GenerateContentResponse (or empty array).
	 * @return string
	 */
	private function candidate_text( $data ) {
		$text       = '';
		$candidates = ( is_array( $data ) && isset( $data['candidates'] ) ) ? (array) $data['candidates'] : array();
		$first      = isset( $candidates[0] ) && is_array( $candidates[0] ) ? $candidates[0] : array();
		$parts      = isset( $first['content']['parts'] ) ? (array) $first['content']['parts'] : array();
		foreach ( $parts as $part ) {
			if ( is_array( $part ) && isset( $part['text'] ) ) {
				$text .= (string) $part['text'];
			}
		}
		return $text;
	}

	/* ------------------------------------------------------------------ *
	 * Batch HTTP transport.
	 * ------------------------------------------------------------------ */

	/**
	 * Authenticated JSON request to the Gemini API.
	 *
	 * @param string     $key    API key.
	 * @param string     $method HTTP method.
	 * @param string     $url    URL.
	 * @param array|null $body   JSON body, null for none.
	 * @return array|WP_Error Decoded response.
	 */
	private function batch_http( $key, $method, $url, $body ) {
		$args = array(
			'method'      => $method,
			'timeout'     => self::BATCH_TIMEOUT,
			'redirection' => 0, // Never forward the API key to another host.
			'user-agent'  => $this->user_agent(),
			'headers'     => $this->batch_headers( $key ),
		);
		if ( null !== $body ) {
			$args['headers']['content-type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'coywolf_seo_batch', $this->http_error( $response ) );
		}
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Common Gemini API request headers.
	 *
	 * @param string $key API key.
	 * @return array
	 */
	private function batch_headers( $key ) {
		return array(
			'x-goog-api-key' => (string) $key,
		);
	}
}
