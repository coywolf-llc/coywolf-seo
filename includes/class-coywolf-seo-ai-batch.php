<?php
/**
 * Bulk Batch API facade, batch pricing, and usage telemetry.
 *
 * Bulk enrichment runs through the active provider's Batch API: half-price
 * tokens in exchange for asynchronous processing (results typically within
 * the hour, guaranteed within 24). This class is a thin facade over the
 * current {@see Coywolf_SEO_AI_Provider} — it keeps the public method names
 * and return shapes the bulk state machine relies on while delegating all
 * HTTP to the provider — plus the discounted price table and the lifetime
 * usage aggregate that powers the average-cost-per-post readout.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batch API facade and cost telemetry.
 */
final class Coywolf_SEO_AI_Batch {

	/**
	 * Option holding lifetime usage: posts, input_tokens, output_tokens.
	 */
	const USAGE_OPTION = 'coywolf_seo_ai_usage';

	/**
	 * The provider that owns the Batch API transport.
	 *
	 * @var Coywolf_SEO_AI_Provider
	 */
	private $provider;

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
	 * @param Coywolf_SEO_AI_Provider $provider Active provider (owns all HTTP).
	 * @param string                  $key      API key.
	 * @param string                  $model    Model ID.
	 */
	public function __construct( Coywolf_SEO_AI_Provider $provider, $key, $model ) {
		$this->provider = $provider;
		$this->key      = (string) $key;
		$this->model    = (string) $model;
	}

	/**
	 * Build a facade for the active provider with its stored key, using either a
	 * caller-supplied model or the provider's configured one. The single place
	 * the enrichment and Image Text runs construct a batch client.
	 *
	 * @param string $model Model ID, or '' for the provider's configured model.
	 * @return self
	 */
	public static function for_current( $model = '' ) {
		$provider = Coywolf_SEO_AI_Providers::current();
		return new self( $provider, $provider->key(), '' !== (string) $model ? (string) $model : $provider->model() );
	}

	/**
	 * One batch request entry, in the active provider's shape.
	 *
	 * @param string $custom_id  Caller's correlation ID.
	 * @param string $system     System prompt.
	 * @param string $user       User prompt.
	 * @param int    $max_tokens Output token allowance. Providers may check a
	 *                           batch's MAXIMUM possible cost against the
	 *                           credit balance at submission, so this is
	 *                           sized per task, not set-and-forget high.
	 * @return array
	 */
	public function request( $custom_id, $system, $user, $max_tokens = 1000 ) {
		return $this->provider->batch_build( $custom_id, $this->model, $system, $user, $max_tokens );
	}

	/**
	 * One vision (image/PDF) batch request entry, in the active provider's
	 * shape. Mirrors {@see request()} but carries a base64 media block, so the
	 * Image Text bulk writer can run through the discounted Batch API.
	 *
	 * @param string $custom_id  Caller's correlation ID.
	 * @param string $system     System prompt.
	 * @param array  $payload     { block, media_type, data } from the image client.
	 * @param string $prompt     User prompt (the analysis instruction).
	 * @param int    $max_tokens Output token allowance.
	 * @return array
	 */
	public function request_vision( $custom_id, $system, array $payload, $prompt, $max_tokens = 1024 ) {
		return $this->provider->batch_build_vision( $custom_id, $this->model, $system, $payload, $prompt, $max_tokens );
	}

	/**
	 * Submit a batch.
	 *
	 * @param array $requests Entries from request().
	 * @return string|WP_Error Batch ID.
	 */
	public function submit( array $requests ) {
		return $this->provider->batch_submit( $this->key, $this->model, $requests );
	}

	/**
	 * Poll a batch.
	 *
	 * @param string $batch_id Batch ID.
	 * @return array|WP_Error { ended: bool, results_url: string, failed: bool, error: string }
	 */
	public function poll( $batch_id ) {
		$r = $this->provider->batch_poll( $this->key, $batch_id );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		// Map the provider's results_handle to the results_url key the bulk
		// state machine has always read, so it stays untouched. Providers that
		// can end a batch in a failed/expired/cancelled state also flag it here
		// so the run pauses rather than collecting an empty result set.
		return array(
			'ended'       => ! empty( $r['ended'] ),
			'results_url' => isset( $r['results_handle'] ) ? (string) $r['results_handle'] : '',
			'failed'      => ! empty( $r['failed'] ),
			'error'       => isset( $r['error'] ) ? (string) $r['error'] : '',
		);
	}

	/**
	 * Fetch and decode a finished batch's results.
	 *
	 * @param string $results_url The batch's results handle (from poll()).
	 * @return array|WP_Error custom_id => { ok: bool, text: string, error: string, input: int, output: int }
	 */
	public function results( $results_url ) {
		return $this->provider->batch_results( $this->key, $results_url );
	}

	/**
	 * Cancel an in-flight batch (best effort).
	 *
	 * @param string $batch_id Batch ID.
	 */
	public function cancel( $batch_id ) {
		$this->provider->batch_cancel( $this->key, $batch_id );
	}

	/**
	 * Batch-discounted prices per million tokens for the active provider —
	 * already the 50% batch rate. Filterable as models and prices move.
	 *
	 * @return array Model prefix => [ input $/MTok, output $/MTok ].
	 */
	public static function prices() {
		/**
		 * Batch-rate token prices per million, keyed by model prefix.
		 *
		 * @param array $prices Prefix => [input, output].
		 */
		return (array) apply_filters( 'coywolf_seo_ai_batch_prices', Coywolf_SEO_AI_Providers::current()->batch_prices() );
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
		return Coywolf_SEO_AI_Provider::price_lookup( self::prices(), $model, $input, $output );
	}

	/**
	 * Standard (real-time, non-discounted) prices per million tokens for the
	 * active provider. Used when a bulk run is set to real-time processing,
	 * which bypasses the Batch API and pays the full rate for immediate results.
	 *
	 * @return array Model prefix => [ input $/MTok, output $/MTok ].
	 */
	public static function standard_prices() {
		/**
		 * Standard-rate token prices per million, keyed by model prefix.
		 *
		 * @param array $prices Prefix => [input, output].
		 */
		return (array) apply_filters( 'coywolf_seo_ai_standard_prices', Coywolf_SEO_AI_Providers::current()->standard_prices() );
	}

	/**
	 * Estimated standard (real-time) cost in dollars.
	 *
	 * @param string $model  Model ID.
	 * @param int    $input  Input tokens.
	 * @param int    $output Output tokens.
	 * @return float
	 */
	public static function estimate_cost_standard( $model, $input, $output ) {
		return Coywolf_SEO_AI_Provider::price_lookup( self::standard_prices(), $model, $input, $output );
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
}
