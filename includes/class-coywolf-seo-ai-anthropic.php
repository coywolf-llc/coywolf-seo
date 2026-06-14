<?php
/**
 * Anthropic (Claude) AI provider for Coywolf SEO.
 *
 * Concrete {@see Coywolf_SEO_AI_Provider} implementation for Anthropic's
 * Claude models. Plain-text generation runs through WordPress 7.0's bundled
 * php-ai-client with the Anthropic provider; the parts the SDK does not
 * abstract are implemented here with raw HTTP against the Anthropic API:
 *
 * - Model listing (GET /v1/models).
 * - Vision (image/PDF -> text) via the Messages API.
 * - The Message Batches API (submit / poll / results / cancel) used by the
 *   bulk enrichment runner for half-price asynchronous processing.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Claude (Anthropic) provider.
 */
final class Coywolf_SEO_AI_Anthropic extends Coywolf_SEO_AI_Provider {

	/**
	 * Anthropic API base URL.
	 */
	const API_BASE = 'https://api.anthropic.com';

	/**
	 * Anthropic API version header value.
	 */
	const API_VERSION = '2023-06-01';

	/**
	 * Batches endpoint.
	 */
	const BATCH_ENDPOINT = 'https://api.anthropic.com/v1/messages/batches';

	/**
	 * Timeout (seconds) for vision Messages requests.
	 */
	const REQUEST_TIMEOUT = 90;

	/* ------------------------------------------------------------------ *
	 * Identity.
	 * ------------------------------------------------------------------ */

	/**
	 * {@inheritdoc}
	 */
	public function id() {
		return 'anthropic';
	}

	/**
	 * {@inheritdoc}
	 */
	public function label() {
		return __( 'Claude (Anthropic)', 'coywolf-seo' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function short_label() {
		return __( 'Claude', 'coywolf-seo' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function key_option() {
		return 'ai_api_key';
	}

	/**
	 * {@inheritdoc}
	 */
	public function key_constant() {
		return 'ANTHROPIC_API_KEY';
	}

	/**
	 * {@inheritdoc}
	 */
	public function model_option() {
		return 'ai_model';
	}

	/**
	 * {@inheritdoc}
	 */
	public function default_model() {
		return 'claude-opus-4-8';
	}

	/**
	 * {@inheritdoc}
	 */
	public function provider_class() {
		return 'WordPress\\AnthropicAiProvider\\Provider\\AnthropicProvider';
	}

	/* ------------------------------------------------------------------ *
	 * Model listing.
	 * ------------------------------------------------------------------ */

	/**
	 * {@inheritdoc}
	 *
	 * Live list from Anthropic's models endpoint, filtered to claude-* ids.
	 * Caching is the caller's responsibility — this just fetches and returns.
	 */
	public function list_models( $key ) {
		if ( '' === (string) $key ) {
			return array();
		}
		$response = wp_remote_get(
			self::API_BASE . '/v1/models?limit=100',
			array(
				'timeout'    => 8,
				'user-agent' => $this->user_agent(),
				'headers'    => array(
					'x-api-key'         => $key,
					'anthropic-version' => self::API_VERSION,
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$models = array();
		foreach ( (array) ( $body['data'] ?? array() ) as $row ) {
			if ( is_array( $row ) && ! empty( $row['id'] ) && 0 === strpos( (string) $row['id'], 'claude-' ) ) {
				$models[] = array(
					'id'   => (string) $row['id'],
					'name' => ! empty( $row['display_name'] ) ? (string) $row['display_name'] : (string) $row['id'],
				);
			}
		}
		return $models;
	}

	/**
	 * {@inheritdoc}
	 */
	public function fallback_models() {
		return array(
			array(
				'id'   => 'claude-opus-4-8',
				'name' => 'Claude Opus 4.8',
			),
			array(
				'id'   => 'claude-sonnet-4-6',
				'name' => 'Claude Sonnet 4.6',
			),
			array(
				'id'   => 'claude-haiku-4-5-20251001',
				'name' => 'Claude Haiku 4.5',
			),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Vision (image/PDF -> text).
	 * ------------------------------------------------------------------ */

	/**
	 * {@inheritdoc}
	 *
	 * Ports the raw Messages API vision call from the legacy image-AI client:
	 * the media file is sent as a base64 image/document content block alongside
	 * the text prompt. Returns the concatenated assistant text and token usage;
	 * parsing the text into fields is the caller's job.
	 */
	public function vision_generate( $key, $model, array $payload, $system, $prompt, $max_tokens ) {
		$body = array(
			'model'      => (string) $model,
			'max_tokens' => (int) $max_tokens,
			'system'     => (string) $system,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'   => $payload['block'],
							'source' => array(
								'type'       => 'base64',
								'media_type' => $payload['media_type'],
								'data'       => $payload['data'],
							),
						),
						array(
							'type' => 'text',
							'text' => (string) $prompt,
						),
					),
				),
			),
		);

		$response = wp_remote_post(
			self::API_BASE . '/v1/messages',
			array(
				'timeout'    => self::REQUEST_TIMEOUT,
				'user-agent' => $this->user_agent(),
				'headers'    => array(
					'x-api-key'         => $key,
					'anthropic-version' => self::API_VERSION,
					'content-type'      => 'application/json',
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
		$text = '';
		foreach ( (array) ( isset( $data['content'] ) ? $data['content'] : array() ) as $block ) {
			if ( is_array( $block ) && isset( $block['type'] ) && 'text' === $block['type'] ) {
				$text .= (string) $block['text'];
			}
		}

		return array(
			'text'   => $text,
			'input'  => (int) ( $data['usage']['input_tokens'] ?? 0 ),
			'output' => (int) ( $data['usage']['output_tokens'] ?? 0 ),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Batch API.
	 * ------------------------------------------------------------------ */

	/**
	 * {@inheritdoc}
	 *
	 * Anthropic checks a batch's MAXIMUM possible cost against the credit
	 * balance at submission, so $max_tokens is sized per task, not high.
	 */
	public function batch_build( $custom_id, $model, $system, $user, $max_tokens ) {
		return array(
			'custom_id' => (string) $custom_id,
			'params'    => array(
				'model'      => (string) $model,
				'max_tokens' => max( 100, (int) $max_tokens ),
				'system'     => $system,
				'messages'   => array(
					array(
						'role'    => 'user',
						'content' => $user,
					),
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * The user turn carries a base64 image/document block plus the text prompt,
	 * exactly as {@see vision_generate()} builds it — only wrapped as a batch
	 * request line.
	 */
	public function batch_build_vision( $custom_id, $model, $system, array $payload, $prompt, $max_tokens ) {
		return array(
			'custom_id' => (string) $custom_id,
			'params'    => array(
				'model'      => (string) $model,
				'max_tokens' => max( 100, (int) $max_tokens ),
				'system'     => (string) $system,
				'messages'   => array(
					array(
						'role'    => 'user',
						'content' => array(
							array(
								'type'   => (string) $payload['block'],
								'source' => array(
									'type'       => 'base64',
									'media_type' => (string) $payload['media_type'],
									'data'       => (string) $payload['data'],
								),
							),
							array(
								'type' => 'text',
								'text' => (string) $prompt,
							),
						),
					),
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function batch_submit( $key, $model, array $requests ) {
		$response = $this->batch_http( $key, 'POST', self::BATCH_ENDPOINT, array( 'requests' => array_values( $requests ) ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( empty( $response['id'] ) ) {
			return new WP_Error( 'coywolf_seo_batch', __( 'The batch could not be created.', 'coywolf-seo' ) );
		}
		return (string) $response['id'];
	}

	/**
	 * {@inheritdoc}
	 */
	public function batch_poll( $key, $handle ) {
		$response = $this->batch_http( $key, 'GET', self::BATCH_ENDPOINT . '/' . rawurlencode( $handle ), null );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return array(
			'ended'          => isset( $response['processing_status'] ) && 'ended' === $response['processing_status'],
			'results_handle' => isset( $response['results_url'] ) ? (string) $response['results_url'] : '',
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * Ports the JSONL results parse: a finished batch's results_url returns
	 * one JSON object per line, which becomes custom_id => { ok, text, error,
	 * input, output }.
	 */
	public function batch_results( $key, $results_handle ) {
		$response = wp_remote_get(
			$results_handle,
			array(
				'timeout'     => 60,
				'redirection' => 0, // Never forward the API key to another host.
				'headers'     => $this->batch_headers( $key ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'coywolf_seo_batch', $this->http_error( $response ) );
		}

		$out = array();
		foreach ( explode( "\n", (string) wp_remote_retrieve_body( $response ) ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$row = json_decode( $line, true );
			if ( ! is_array( $row ) || empty( $row['custom_id'] ) ) {
				continue;
			}
			$result = isset( $row['result'] ) ? (array) $row['result'] : array();
			$type   = isset( $result['type'] ) ? (string) $result['type'] : 'errored';

			if ( 'succeeded' === $type ) {
				$message = isset( $result['message'] ) ? (array) $result['message'] : array();
				$text    = '';
				foreach ( (array) ( $message['content'] ?? array() ) as $block ) {
					if ( is_array( $block ) && isset( $block['type'] ) && 'text' === $block['type'] ) {
						$text .= (string) $block['text'];
					}
				}
				$out[ (string) $row['custom_id'] ] = array(
					'ok'     => true,
					'text'   => $text,
					'error'  => '',
					'input'  => (int) ( $message['usage']['input_tokens'] ?? 0 ),
					'output' => (int) ( $message['usage']['output_tokens'] ?? 0 ),
				);
			} else {
				$error                             = isset( $result['error']['error']['message'] ) ? (string) $result['error']['error']['message']
					: ( isset( $result['error']['message'] ) ? (string) $result['error']['message'] : $type );
				$out[ (string) $row['custom_id'] ] = array(
					'ok'     => false,
					'text'   => '',
					'error'  => $error,
					'input'  => 0,
					'output' => 0,
				);
			}
		}
		return $out;
	}

	/**
	 * {@inheritdoc}
	 */
	public function batch_cancel( $key, $handle ) {
		$this->batch_http( $key, 'POST', self::BATCH_ENDPOINT . '/' . rawurlencode( $handle ) . '/cancel', null );
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
	 * Standard (non-batch) vision/text token prices per million, by model-ID
	 * prefix. Most specific prefix wins (resolved by the base price_lookup()).
	 */
	public function standard_prices() {
		return array(
			'claude-fable'      => array( 10.0, 50.0 ),
			'claude-opus-4-5'   => array( 5.0, 25.0 ),
			'claude-opus-4-6'   => array( 5.0, 25.0 ),
			'claude-opus-4-7'   => array( 5.0, 25.0 ),
			'claude-opus-4-8'   => array( 5.0, 25.0 ),
			'claude-opus'       => array( 15.0, 75.0 ),
			'claude-3-opus'     => array( 15.0, 75.0 ),
			'claude-sonnet'     => array( 3.0, 15.0 ),
			'claude-3-7-sonnet' => array( 3.0, 15.0 ),
			'claude-3-5-sonnet' => array( 3.0, 15.0 ),
			'claude-haiku'      => array( 1.0, 5.0 ),
			'claude-3-5-haiku'  => array( 0.8, 4.0 ),
			'claude-3-haiku'    => array( 0.25, 1.25 ),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * Batch-discounted prices per million tokens. Derived as half the standard
	 * table so EVERY model the standard table prices (incl. Claude 3.x and
	 * fable) also resolves under the batch rate — a model that matched standard
	 * but not batch used to estimate as $0 and read as "free". The legacy
	 * Opus 4.0/4.1 entries keep their published (non-50%) batch rates.
	 */
	public function batch_prices() {
		$batch = array();
		foreach ( $this->standard_prices() as $prefix => $price ) {
			$batch[ $prefix ] = array( (float) $price[0] / 2, (float) $price[1] / 2 );
		}
		// Legacy Opus 4.0/4.1 batch rates are not a clean 50% of standard.
		$batch['claude-opus-4-1']        = array( 7.5, 37.5 );
		$batch['claude-opus-4-20250514'] = array( 7.5, 37.5 );
		$batch['claude-opus-4']          = array( 2.5, 12.5 );
		$batch['claude-sonnet-4']        = array( 1.5, 7.5 );
		$batch['claude-haiku-4']         = array( 0.5, 2.5 );

		/**
		 * Batch-rate token prices per million, keyed by model prefix.
		 *
		 * @param array $prices Prefix => [input, output].
		 */
		return (array) apply_filters( 'coywolf_seo_ai_batch_prices', $batch );
	}

	/* ------------------------------------------------------------------ *
	 * Batch HTTP transport (ports Coywolf_SEO_AI_Batch::http/headers).
	 * ------------------------------------------------------------------ */

	/**
	 * Authenticated JSON request to the Batches API.
	 *
	 * @param string     $key    API key.
	 * @param string     $method HTTP method.
	 * @param string     $url    URL.
	 * @param array|null $body   JSON body, null for none.
	 * @return array|WP_Error Decoded response.
	 */
	private function batch_http( $key, $method, $url, $body ) {
		$args = array(
			'method'  => $method,
			'timeout' => 90,
			'headers' => $this->batch_headers( $key ),
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
	 * Common Batches API request headers.
	 *
	 * @param string $key API key.
	 * @return array
	 */
	private function batch_headers( $key ) {
		return array(
			'x-api-key'         => (string) $key,
			'anthropic-version' => self::API_VERSION,
		);
	}

	/* ------------------------------------------------------------------ *
	 * Settings UI copy (Anthropic-specific batch/billing mechanics).
	 * ------------------------------------------------------------------ */

	/**
	 * {@inheritdoc}
	 *
	 * Anthropic checks a batch's MAXIMUM possible cost up front, so the run
	 * must reserve more than it will actually spend; %RESERVE% is filled in by
	 * the estimate JS.
	 */
	public function bulk_cost_note() {
		return __( 'Your Claude balance (and any Workspace spend limit) must cover about $%RESERVE% for the largest batch\'s upfront check.', 'coywolf-seo' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function bulk_billing_note( $paused_reason = '' ) {
		if ( false === stripos( (string) $paused_reason, 'credit balance' ) ) {
			return '';
		}
		return __( 'Note: Anthropic checks a batch\'s maximum possible cost up front — not what it will actually spend — so this can trigger even with a healthy balance. Resume submits smaller batches with tighter token allowances, which usually clears it. If it persists, check the Model setting: legacy Opus models cost several times more than the default.', 'coywolf-seo' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function bulk_billing_hint( $batch_error = '' ) {
		if ( false === stripos( (string) $batch_error, 'credit' ) ) {
			return '';
		}
		return __( 'Regular API calls work but batch creation is rejected on billing grounds: that is the signature of a Workspace spend limit. In the Anthropic Console open Settings -> Workspaces -> the workspace this key belongs to -> Limits, and raise or remove the monthly spend limit (or create a key in the Default workspace).', 'coywolf-seo' );
	}
}
