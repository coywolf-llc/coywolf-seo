<?php
/**
 * OpenAI provider.
 *
 * Implements the per-provider surface that php-ai-client does not abstract —
 * model listing, vision (image / PDF → text), and the asynchronous Batch API —
 * against the OpenAI REST API. Plain text generation still routes through
 * WordPress 7.0's bundled php-ai-client via {@see provider_class()}.
 *
 * OpenAI's Batch API is file-based: a JSONL document of request lines is
 * uploaded to the Files endpoint, then referenced by a Batch job; results come
 * back as another JSONL file that must be downloaded and parsed. This class
 * therefore carries a small multipart/form-data builder for the upload step,
 * since WordPress' HTTP layer needs the raw body plus a boundary-bearing
 * content-type header.
 *
 * Every HTTP method authenticates with the $key argument it is handed — it
 * never reads the stored option directly — so the caller controls which key is
 * used (saved, wp-config constant, or environment variable).
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenAI implementation of the provider contract.
 */
final class Coywolf_SEO_AI_OpenAI extends Coywolf_SEO_AI_Provider {

	/**
	 * API base URL (no trailing slash).
	 */
	const API_BASE = 'https://api.openai.com';

	/*
	------------------------------------------------------------------ *
	 * Identity.
	 * ------------------------------------------------------------------ */

	/**
	 * {@inheritdoc}
	 */
	public function id() {
		return 'openai';
	}

	/**
	 * {@inheritdoc}
	 */
	public function label() {
		return 'OpenAI';
	}

	/**
	 * {@inheritdoc}
	 */
	public function short_label() {
		return 'OpenAI';
	}

	/**
	 * {@inheritdoc}
	 */
	public function key_option() {
		return 'ai_api_key_openai';
	}

	/**
	 * {@inheritdoc}
	 */
	public function key_constant() {
		return 'OPENAI_API_KEY';
	}

	/**
	 * {@inheritdoc}
	 */
	public function model_option() {
		return 'ai_model_openai';
	}

	/**
	 * {@inheritdoc}
	 */
	public function default_model() {
		return 'gpt-4o';
	}

	/**
	 * {@inheritdoc}
	 */
	public function provider_class() {
		return 'WordPress\\OpenAiAiProvider\\Provider\\OpenAiProvider';
	}

	/*
	------------------------------------------------------------------ *
	 * Model listing.
	 * ------------------------------------------------------------------ */

	/**
	 * {@inheritdoc}
	 */
	public function list_models( $key ) {
		$response = wp_remote_get(
			self::API_BASE . '/v1/models',
			array(
				'timeout'    => 8,
				'user-agent' => $this->user_agent(),
				'headers'    => array(
					'authorization' => 'Bearer ' . $key,
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$models = array();
		foreach ( (array) ( isset( $body['data'] ) ? $body['data'] : array() ) as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}
			$model_id = (string) $row['id'];
			// Keep the chat/vision-capable families: gpt-*, chatgpt-* (e.g.
			// chatgpt-4o-latest), and the o-series reasoning models (o1/o3/o4…).
			if ( 0 !== strpos( $model_id, 'gpt-' ) && 0 !== strpos( $model_id, 'chatgpt-' ) && ! preg_match( '/^o[0-9]/', $model_id ) ) {
				continue;
			}
			if ( $this->is_non_chat_model( $model_id ) ) {
				continue;
			}
			$models[] = array(
				'id'   => $model_id,
				'name' => $model_id,
			);
		}
		return $models;
	}

	/**
	 * Whether a model id is an obvious non-chat / non-vision endpoint that
	 * should be hidden from the chat-completions model picker.
	 *
	 * @param string $model_id Model id.
	 * @return bool
	 */
	private function is_non_chat_model( $model_id ) {
		$exclude = array( 'embedding', 'whisper', 'tts', 'dall-e', 'audio', 'realtime', 'image', 'moderation', 'transcribe' );
		foreach ( $exclude as $needle ) {
			if ( false !== strpos( $model_id, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function fallback_models() {
		return array(
			array(
				'id'   => 'gpt-4o',
				'name' => 'GPT-4o',
			),
			array(
				'id'   => 'gpt-4o-mini',
				'name' => 'GPT-4o mini',
			),
			array(
				'id'   => 'gpt-4.1',
				'name' => 'GPT-4.1',
			),
			array(
				'id'   => 'gpt-4.1-mini',
				'name' => 'GPT-4.1 mini',
			),
		);
	}

	/*
	------------------------------------------------------------------ *
	 * Vision (image / PDF → text).
	 * ------------------------------------------------------------------ */

	/**
	 * {@inheritdoc}
	 */
	public function vision_generate( $key, $model, array $payload, $system, $prompt, $max_tokens ) {
		$block      = isset( $payload['block'] ) ? (string) $payload['block'] : 'image';
		$media_type = isset( $payload['media_type'] ) ? (string) $payload['media_type'] : '';
		$data       = isset( $payload['data'] ) ? (string) $payload['data'] : '';

		if ( 'document' === $block ) {
			$file_part = array(
				'type' => 'file',
				'file' => array(
					'filename'  => 'document.pdf',
					'file_data' => 'data:application/pdf;base64,' . $data,
				),
			);
		} else {
			$file_part = array(
				'type'      => 'image_url',
				'image_url' => array(
					'url' => 'data:' . $media_type . ';base64,' . $data,
				),
			);
		}

		$body = array(
			'model'                 => $model,
			'max_completion_tokens' => max( 100, (int) $max_tokens ),
			'messages'              => array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => array(
						$file_part,
						array(
							'type' => 'text',
							'text' => $prompt,
						),
					),
				),
			),
		);

		$response = $this->chat_request( $key, $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$text = isset( $data['choices'][0]['message']['content'] ) ? (string) $data['choices'][0]['message']['content'] : '';

		return array(
			'text'   => $text,
			'input'  => (int) ( $data['usage']['prompt_tokens'] ?? 0 ),
			'output' => (int) ( $data['usage']['completion_tokens'] ?? 0 ),
		);
	}

	/**
	 * POST a chat-completions body, retrying once with the legacy 'max_tokens'
	 * field if the model rejects 'max_completion_tokens' with an HTTP 400.
	 *
	 * @param string $key  API key.
	 * @param array  $body Request body.
	 * @return array|WP_Error Successful wp_remote_post response, or WP_Error.
	 */
	private function chat_request( $key, array $body ) {
		$response = wp_remote_post(
			self::API_BASE . '/v1/chat/completions',
			array(
				'timeout'    => 90,
				'user-agent' => $this->user_agent(),
				'headers'    => array(
					'authorization' => 'Bearer ' . $key,
					'content-type'  => 'application/json',
				),
				'body'       => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return $response;
		}

		// Some models reject 'max_completion_tokens'; retry once with the
		// legacy 'max_tokens' field if that is what the 400 is complaining about.
		if ( 400 === $code && isset( $body['max_completion_tokens'] ) && false !== strpos( $this->http_error( $response ), 'max_completion_tokens' ) ) {
			$retry               = $body;
			$retry['max_tokens'] = $retry['max_completion_tokens'];
			unset( $retry['max_completion_tokens'] );

			$response = wp_remote_post(
				self::API_BASE . '/v1/chat/completions',
				array(
					'timeout'    => 90,
					'user-agent' => $this->user_agent(),
					'headers'    => array(
						'authorization' => 'Bearer ' . $key,
						'content-type'  => 'application/json',
					),
					'body'       => wp_json_encode( $retry ),
				)
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 200 && $code < 300 ) {
				return $response;
			}
		}

		return new WP_Error( 'coywolf_seo_openai', $this->http_error( $response ) );
	}

	/*
	------------------------------------------------------------------ *
	 * Batch API (file-based).
	 * ------------------------------------------------------------------ */

	/**
	 * {@inheritdoc}
	 */
	public function batch_build( $custom_id, $model, $system, $user, $max_tokens ) {
		return array(
			'custom_id' => (string) $custom_id,
			'method'    => 'POST',
			'url'       => '/v1/chat/completions',
			'body'      => array(
				'model'                 => (string) $model,
				'max_completion_tokens' => max( 100, (int) $max_tokens ),
				'messages'              => array(
					array(
						'role'    => 'system',
						'content' => $system,
					),
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
	 * Mirrors {@see vision_generate()}'s body — a file/image content part plus
	 * the text prompt — wrapped as a /v1/chat/completions batch line.
	 */
	public function batch_build_vision( $custom_id, $model, $system, array $payload, $prompt, $max_tokens ) {
		$block      = isset( $payload['block'] ) ? (string) $payload['block'] : 'image';
		$media_type = isset( $payload['media_type'] ) ? (string) $payload['media_type'] : '';
		$data       = isset( $payload['data'] ) ? (string) $payload['data'] : '';

		if ( 'document' === $block ) {
			$file_part = array(
				'type' => 'file',
				'file' => array(
					'filename'  => 'document.pdf',
					'file_data' => 'data:application/pdf;base64,' . $data,
				),
			);
		} else {
			$file_part = array(
				'type'      => 'image_url',
				'image_url' => array(
					'url' => 'data:' . $media_type . ';base64,' . $data,
				),
			);
		}

		return array(
			'custom_id' => (string) $custom_id,
			'method'    => 'POST',
			'url'       => '/v1/chat/completions',
			'body'      => array(
				'model'                 => (string) $model,
				'max_completion_tokens' => max( 100, (int) $max_tokens ),
				'messages'              => array(
					array(
						'role'    => 'system',
						'content' => (string) $system,
					),
					array(
						'role'    => 'user',
						'content' => array(
							$file_part,
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
		// 1. Build the JSONL body — one request object per line.
		$lines = array();
		foreach ( array_values( $requests ) as $request ) {
			$lines[] = wp_json_encode( $request );
		}
		$jsonl = implode( "\n", $lines );

		// 2. Upload it as a multipart/form-data "batch" file.
		$boundary = $this->multipart_boundary();
		$body     = $this->multipart_body(
			$boundary,
			array( 'purpose' => 'batch' ),
			array(
				'name'         => 'file',
				'filename'     => 'batch.jsonl',
				'content_type' => 'application/jsonl',
				'contents'     => $jsonl,
			)
		);

		$upload = wp_remote_post(
			self::API_BASE . '/v1/files',
			array(
				'timeout'    => 90,
				'user-agent' => $this->user_agent(),
				'headers'    => array(
					'authorization' => 'Bearer ' . $key,
					'content-type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'       => $body,
			)
		);
		if ( is_wp_error( $upload ) ) {
			return $upload;
		}
		$upload_code = (int) wp_remote_retrieve_response_code( $upload );
		if ( $upload_code < 200 || $upload_code >= 300 ) {
			return new WP_Error( 'coywolf_seo_openai', $this->http_error( $upload ) );
		}
		$upload_body = json_decode( wp_remote_retrieve_body( $upload ), true );
		$file_id     = isset( $upload_body['id'] ) ? (string) $upload_body['id'] : '';
		if ( '' === $file_id ) {
			return new WP_Error( 'coywolf_seo_openai', __( 'The batch input file could not be uploaded.', 'coywolf-seo' ) );
		}

		// 3. Create the batch job referencing the uploaded file.
		$create = wp_remote_post(
			self::API_BASE . '/v1/batches',
			array(
				'timeout'    => 90,
				'user-agent' => $this->user_agent(),
				'headers'    => array(
					'authorization' => 'Bearer ' . $key,
					'content-type'  => 'application/json',
				),
				'body'       => wp_json_encode(
					array(
						'input_file_id'     => $file_id,
						'endpoint'          => '/v1/chat/completions',
						'completion_window' => '24h',
					)
				),
			)
		);
		if ( is_wp_error( $create ) ) {
			return $create;
		}
		$create_code = (int) wp_remote_retrieve_response_code( $create );
		if ( $create_code < 200 || $create_code >= 300 ) {
			return new WP_Error( 'coywolf_seo_openai', $this->http_error( $create ) );
		}
		$create_body = json_decode( wp_remote_retrieve_body( $create ), true );
		$batch_id    = isset( $create_body['id'] ) ? (string) $create_body['id'] : '';
		if ( '' === $batch_id ) {
			return new WP_Error( 'coywolf_seo_openai', __( 'The batch could not be created.', 'coywolf-seo' ) );
		}
		return $batch_id;
	}

	/**
	 * {@inheritdoc}
	 */
	public function batch_poll( $key, $handle ) {
		$response = wp_remote_get(
			self::API_BASE . '/v1/batches/' . rawurlencode( $handle ),
			array(
				'timeout'    => 30,
				'user-agent' => $this->user_agent(),
				'headers'    => array(
					'authorization' => 'Bearer ' . $key,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'coywolf_seo_openai', $this->http_error( $response ) );
		}

		$body           = json_decode( wp_remote_retrieve_body( $response ), true );
		$status         = isset( $body['status'] ) ? (string) $body['status'] : '';
		$output_file_id = isset( $body['output_file_id'] ) ? (string) $body['output_file_id'] : '';
		$ended          = in_array( $status, array( 'completed', 'failed', 'expired', 'cancelled' ), true );

		return array(
			'ended'          => $ended,
			'results_handle' => $output_file_id,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function batch_results( $key, $results_handle ) {
		if ( '' === (string) $results_handle ) {
			return array();
		}

		$response = wp_remote_get(
			self::API_BASE . '/v1/files/' . rawurlencode( $results_handle ) . '/content',
			array(
				'timeout'     => 60,
				'redirection' => 0, // Never forward the API key to another host.
				'user-agent'  => $this->user_agent(),
				'headers'     => array(
					'authorization' => 'Bearer ' . $key,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'coywolf_seo_openai', $this->http_error( $response ) );
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
			$custom_id = (string) $row['custom_id'];

			$resp        = isset( $row['response'] ) ? (array) $row['response'] : array();
			$status_code = isset( $resp['status_code'] ) ? (int) $resp['status_code'] : 0;
			$resp_body   = isset( $resp['body'] ) ? (array) $resp['body'] : array();
			$text        = isset( $resp_body['choices'][0]['message']['content'] ) ? (string) $resp_body['choices'][0]['message']['content'] : '';
			$ok          = ( 200 === $status_code && '' !== $text );

			$error = '';
			if ( ! $ok ) {
				if ( isset( $row['error']['message'] ) ) {
					$error = (string) $row['error']['message'];
				} elseif ( isset( $resp_body['error']['message'] ) ) {
					$error = (string) $resp_body['error']['message'];
				} elseif ( is_string( $row['error'] ?? null ) ) {
					$error = (string) $row['error'];
				} else {
					/* translators: %d: HTTP status code. */
					$error = sprintf( __( 'The request failed (HTTP %d).', 'coywolf-seo' ), $status_code );
				}
			}

			$out[ $custom_id ] = array(
				'ok'     => $ok,
				'text'   => $text,
				'error'  => $error,
				'input'  => (int) ( $resp_body['usage']['prompt_tokens'] ?? 0 ),
				'output' => (int) ( $resp_body['usage']['completion_tokens'] ?? 0 ),
			);
		}
		return $out;
	}

	/**
	 * {@inheritdoc}
	 */
	public function batch_cancel( $key, $handle ) {
		wp_remote_post(
			self::API_BASE . '/v1/batches/' . rawurlencode( $handle ) . '/cancel',
			array(
				'timeout'    => 30,
				'user-agent' => $this->user_agent(),
				'headers'    => array(
					'authorization' => 'Bearer ' . $key,
				),
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function supports_batch() {
		return true;
	}

	/*
	------------------------------------------------------------------ *
	 * Multipart/form-data builder for the Files upload step.
	 * ------------------------------------------------------------------ */

	/**
	 * A unique multipart boundary token.
	 *
	 * @return string
	 */
	private function multipart_boundary() {
		return 'coywolfseo' . wp_generate_password( 24, false, false );
	}

	/**
	 * Build a raw multipart/form-data request body.
	 *
	 * @param string $boundary Boundary token (also goes in the content-type header).
	 * @param array  $fields   Simple name => value form fields.
	 * @param array  $file     {
	 *     The single file part.
	 *
	 *     @type string $name         Field name.
	 *     @type string $filename     Uploaded filename.
	 *     @type string $content_type File content type.
	 *     @type string $contents     Raw file contents.
	 * }
	 * @return string
	 */
	private function multipart_body( $boundary, array $fields, array $file ) {
		$eol  = "\r\n";
		$body = '';

		foreach ( $fields as $name => $value ) {
			$body .= '--' . $boundary . $eol;
			$body .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
			$body .= (string) $value . $eol;
		}

		$body .= '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="' . $file['name'] . '"; filename="' . $file['filename'] . '"' . $eol;
		$body .= 'Content-Type: ' . $file['content_type'] . $eol . $eol;
		$body .= (string) $file['contents'] . $eol;

		$body .= '--' . $boundary . '--' . $eol;

		return $body;
	}

	/*
	------------------------------------------------------------------ *
	 * Pricing ($/million tokens), longest-prefix matched on the model id.
	 * ------------------------------------------------------------------ */

	/**
	 * {@inheritdoc}
	 */
	public function standard_prices() {
		return array(
			'gpt-4o-mini'  => array( 0.15, 0.6 ),
			'gpt-4o'       => array( 2.5, 10.0 ),
			'gpt-4.1-mini' => array( 0.4, 1.6 ),
			'gpt-4.1-nano' => array( 0.1, 0.4 ),
			'gpt-4.1'      => array( 2.0, 8.0 ),
			'gpt-5-mini'   => array( 0.25, 2.0 ),
			'gpt-5'        => array( 1.25, 10.0 ),
			'gpt-'         => array( 2.5, 10.0 ),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * OpenAI's Batch API bills at 50% of the standard rate, so every entry is
	 * the standard table halved.
	 */
	public function batch_prices() {
		$batch = array();
		foreach ( $this->standard_prices() as $prefix => $price ) {
			$batch[ $prefix ] = array( $price[0] / 2, $price[1] / 2 );
		}
		return $batch;
	}
}
