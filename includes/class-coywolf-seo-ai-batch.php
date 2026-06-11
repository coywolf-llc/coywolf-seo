<?php
/**
 * Anthropic Message Batches client, batch pricing, and usage telemetry.
 *
 * Bulk enrichment runs exclusively through the Batches API: half-price
 * tokens in exchange for asynchronous processing (results typically
 * within the hour, guaranteed within 24). This class owns the HTTP
 * surface — submit, poll, fetch results, cancel — plus the discounted
 * price table and the lifetime usage aggregate that powers the
 * average-cost-per-post readout.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batches API client and cost telemetry.
 */
final class Coywolf_SEO_AI_Batch {

	/**
	 * Option holding lifetime usage: posts, input_tokens, output_tokens.
	 */
	const USAGE_OPTION = 'coywolf_seo_ai_usage';

	/**
	 * Batches endpoint.
	 */
	const ENDPOINT = 'https://api.anthropic.com/v1/messages/batches';

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Model ID for requests and pricing.
	 *
	 * @var string
	 */
	private $model;

	/**
	 * Wire up.
	 *
	 * @param string $key   Anthropic API key.
	 * @param string $model Model ID.
	 */
	public function __construct( $key, $model ) {
		$this->key   = (string) $key;
		$this->model = (string) $model;
	}

	/**
	 * One batch request entry.
	 *
	 * @param string $custom_id  Caller's correlation ID.
	 * @param string $system     System prompt.
	 * @param string $user       User prompt.
	 * @param int    $max_tokens Output token allowance. Anthropic checks a
	 *                           batch's MAXIMUM possible cost against the
	 *                           credit balance at submission, so this is
	 *                           sized per task, not set-and-forget high.
	 * @return array
	 */
	public function request( $custom_id, $system, $user, $max_tokens = 1000 ) {
		return array(
			'custom_id' => (string) $custom_id,
			'params'    => array(
				'model'      => $this->model,
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
	 * Submit a batch.
	 *
	 * @param array $requests Entries from request().
	 * @return string|WP_Error Batch ID.
	 */
	public function submit( array $requests ) {
		$response = $this->http( 'POST', self::ENDPOINT, array( 'requests' => array_values( $requests ) ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( empty( $response['id'] ) ) {
			return new WP_Error( 'coywolf_seo_batch', __( 'The batch could not be created.', 'coywolf-seo' ) );
		}
		return (string) $response['id'];
	}

	/**
	 * Poll a batch.
	 *
	 * @param string $batch_id Batch ID.
	 * @return array|WP_Error { ended: bool, results_url: string }
	 */
	public function poll( $batch_id ) {
		$response = $this->http( 'GET', self::ENDPOINT . '/' . rawurlencode( $batch_id ), null );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return array(
			'ended'       => isset( $response['processing_status'] ) && 'ended' === $response['processing_status'],
			'results_url' => isset( $response['results_url'] ) ? (string) $response['results_url'] : '',
		);
	}

	/**
	 * Fetch and decode a finished batch's results.
	 *
	 * @param string $results_url The batch's results_url.
	 * @return array|WP_Error custom_id => { ok: bool, text: string, error: string, input: int, output: int }
	 */
	public function results( $results_url ) {
		$response = wp_remote_get(
			$results_url,
			array(
				'timeout'     => 60,
				'redirection' => 0, // Never forward the API key to another host.
				'headers'     => $this->headers(),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'coywolf_seo_batch', $this->api_error( $response ) );
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
				$error = isset( $result['error']['error']['message'] ) ? (string) $result['error']['error']['message']
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
	 * Cancel an in-flight batch (best effort).
	 *
	 * @param string $batch_id Batch ID.
	 */
	public function cancel( $batch_id ) {
		$this->http( 'POST', self::ENDPOINT . '/' . rawurlencode( $batch_id ) . '/cancel', null );
	}

	/**
	 * Batch-discounted prices per million tokens — already the 50% batch
	 * rate. Filterable as models and prices move.
	 *
	 * @return array Model prefix => [ input $/MTok, output $/MTok ].
	 */
	public static function prices() {
		/**
		 * Batch-rate token prices per million, keyed by model prefix.
		 *
		 * @param array $prices Prefix => [input, output].
		 */
		return (array) apply_filters(
			'coywolf_seo_ai_batch_prices',
			array(
				// Legacy Opus 4.0/4.1 kept their higher standard rates.
				'claude-opus-4-1'        => array( 7.5, 37.5 ),
				'claude-opus-4-20250514' => array( 7.5, 37.5 ),
				'claude-opus-4'          => array( 2.5, 12.5 ),
				'claude-sonnet-4'        => array( 1.5, 7.5 ),
				'claude-haiku-4'         => array( 0.5, 2.5 ),
			)
		);
	}

	/**
	 * Estimated batch-rate cost in dollars.
	 *
	 * @param string $model  Model ID.
	 * @param int    $input  Input tokens.
	 * @param int    $output Output tokens.
	 * @return float
	 */
	public static function estimate_cost( $model, $input, $output ) {
		$prices = self::prices();
		// Longest prefix first, so specific entries always beat generic
		// ones regardless of insertion order.
		uksort(
			$prices,
			static function ( $a, $b ) {
				return strlen( (string) $b ) - strlen( (string) $a );
			}
		);
		foreach ( $prices as $prefix => $price ) {
			if ( 0 === strpos( (string) $model, (string) $prefix ) ) {
				return ( $input / 1000000 ) * (float) $price[0] + ( $output / 1000000 ) * (float) $price[1];
			}
		}
		return 0.0;
	}

	/**
	 * Add a run's usage to the lifetime aggregate.
	 *
	 * @param int $input  Input tokens.
	 * @param int $output Output tokens.
	 * @param int $posts  Successfully enriched posts.
	 */
	public static function record_usage( $input, $output, $posts ) {
		$usage                  = (array) get_option( self::USAGE_OPTION, array() );
		$usage['input_tokens']  = (int) ( $usage['input_tokens'] ?? 0 ) + max( 0, (int) $input );
		$usage['output_tokens'] = (int) ( $usage['output_tokens'] ?? 0 ) + max( 0, (int) $output );
		$usage['posts']         = (int) ( $usage['posts'] ?? 0 ) + max( 0, (int) $posts );
		update_option( self::USAGE_OPTION, $usage, false );
	}

	/**
	 * Lifetime usage with per-post averages and the batch-rate estimate.
	 *
	 * @param string $model Model ID for pricing.
	 * @return array { posts, avg_input, avg_output, avg_cost } or empty when nothing recorded.
	 */
	public static function usage_summary( $model ) {
		$usage = (array) get_option( self::USAGE_OPTION, array() );
		$posts = (int) ( $usage['posts'] ?? 0 );
		if ( $posts < 1 ) {
			return array();
		}
		$avg_in  = (int) round( (int) ( $usage['input_tokens'] ?? 0 ) / $posts );
		$avg_out = (int) round( (int) ( $usage['output_tokens'] ?? 0 ) / $posts );
		return array(
			'posts'      => $posts,
			'avg_input'  => $avg_in,
			'avg_output' => $avg_out,
			'avg_cost'   => self::estimate_cost( $model, $avg_in, $avg_out ),
		);
	}

	/**
	 * Authenticated JSON request to the Batches API.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $url    URL.
	 * @param array|null $body   JSON body, null for none.
	 * @return array|WP_Error Decoded response.
	 */
	private function http( $method, $url, $body ) {
		$args = array(
			'method'  => $method,
			'timeout' => 90,
			'headers' => $this->headers(),
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
			return new WP_Error( 'coywolf_seo_batch', $this->api_error( $response ) );
		}
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * A readable error from an API response.
	 *
	 * @param array $response WP HTTP response.
	 * @return string
	 */
	private function api_error( $response ) {
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$msg  = isset( $body['error']['message'] ) ? (string) $body['error']['message'] : (string) wp_remote_retrieve_response_message( $response );
		/* translators: 1: HTTP status code, 2: API error message. */
		return sprintf( __( 'Anthropic Batches API returned HTTP %1$d: %2$s', 'coywolf-seo' ), $code, $msg );
	}

	/**
	 * Common request headers.
	 *
	 * @return array
	 */
	private function headers() {
		return array(
			'x-api-key'         => $this->key,
			'anthropic-version' => '2023-06-01',
		);
	}
}
