<?php
/**
 * Abstract base for an AI service provider (Claude, OpenAI, Gemini).
 *
 * Coywolf SEO routes all AI work through one active provider at a time. Plain
 * text generation (entity extraction, meta descriptions) runs through WordPress
 * 7.0's bundled php-ai-client via {@see provider_class()} / {@see provider_id()}.
 * The provider-specific parts that the SDK does not abstract — listing models,
 * vision (image → text), and the bulk Batch API — are implemented per provider
 * by the concrete subclasses and exposed through this common shape so the rest
 * of the plugin stays provider-agnostic.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Common provider contract + shared key/model/status resolution.
 */
abstract class Coywolf_SEO_AI_Provider {

	/* ------------------------------------------------------------------ *
	 * Identity — every concrete provider defines these.
	 * ------------------------------------------------------------------ */

	/**
	 * Service id used in settings and php-ai-client (e.g. 'anthropic').
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Human label for the service selector (e.g. 'Claude (Anthropic)').
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Short provider name for compact UI such as a button ("Generate with
	 * Claude"). Defaults to label(); concrete providers give a terser name.
	 *
	 * @return string
	 */
	public function short_label() {
		return $this->label();
	}

	/**
	 * Settings option key that stores this provider's API key.
	 *
	 * @return string
	 */
	abstract public function key_option();

	/**
	 * wp-config.php constant / environment variable name for the API key.
	 *
	 * @return string
	 */
	abstract public function key_constant();

	/**
	 * Settings option key that stores this provider's chosen model.
	 *
	 * @return string
	 */
	abstract public function model_option();

	/**
	 * Default model id used when none is chosen.
	 *
	 * @return string
	 */
	abstract public function default_model();

	/**
	 * Fully-qualified php-ai-client provider class for text generation.
	 *
	 * @return string
	 */
	abstract public function provider_class();

	/**
	 * php-ai-client provider id passed to usingProvider() for text generation.
	 * Defaults to {@see id()}; override only if they differ.
	 *
	 * @return string
	 */
	public function provider_id() {
		return $this->id();
	}

	/* ------------------------------------------------------------------ *
	 * Shared key / model / status resolution (option > constant > env).
	 * ------------------------------------------------------------------ */

	/**
	 * Resolve this provider's API key. Honours the master AI feature gate: when
	 * AI enrichment is off, no key is reported (the wp-config constant/env are
	 * ignored too).
	 *
	 * @return string
	 */
	public function key() {
		if ( ! Coywolf_SEO_Options::feature_enabled( 'ai' ) ) {
			return '';
		}
		$saved = (string) Coywolf_SEO_Options::get( $this->key_option() );
		if ( '' !== $saved ) {
			return $saved;
		}
		$const = $this->key_constant();
		if ( defined( $const ) && '' !== (string) constant( $const ) ) {
			return (string) constant( $const );
		}
		$env = getenv( $const );
		return ( is_string( $env ) && '' !== $env ) ? $env : '';
	}

	/**
	 * Where this provider's key comes from, for the Settings status row.
	 *
	 * @return array{source:string,saved:bool,constant:bool,env:bool}
	 */
	public function key_status() {
		$const    = $this->key_constant();
		$saved    = '' !== (string) Coywolf_SEO_Options::get( $this->key_option() );
		$constant = defined( $const ) && '' !== (string) constant( $const );
		$env_val  = getenv( $const );
		$env      = is_string( $env_val ) && '' !== $env_val;

		if ( $saved ) {
			$source = 'saved';
		} elseif ( $constant ) {
			$source = 'constant';
		} elseif ( $env ) {
			$source = 'env';
		} else {
			$source = '';
		}
		return array(
			'source'   => $source,
			'saved'    => $saved,
			'constant' => $constant,
			'env'      => $env,
		);
	}

	/**
	 * The chosen model for this provider (saved value, else the default),
	 * filterable via 'coywolf_seo_ai_model'.
	 *
	 * @return string
	 */
	public function model() {
		$saved = trim( (string) Coywolf_SEO_Options::get( $this->model_option() ) );
		$model = '' !== $saved ? $saved : $this->default_model();
		/** This filter is documented in includes/class-coywolf-seo-ai.php */
		return (string) apply_filters( 'coywolf_seo_ai_model', $model, $this->id() );
	}

	/* ------------------------------------------------------------------ *
	 * Model listing.
	 * ------------------------------------------------------------------ */

	/**
	 * Live list of selectable models for the Settings dropdown. Returns an array
	 * of array{id:string,name:string}; an empty array falls back to the curated
	 * defaults in {@see fallback_models()}.
	 *
	 * @param string $key API key to authenticate the listing request.
	 * @return array<int,array{id:string,name:string}>
	 */
	abstract public function list_models( $key );

	/**
	 * Curated model list shown when a live fetch is unavailable.
	 *
	 * @return array<int,array{id:string,name:string}>
	 */
	abstract public function fallback_models();

	/* ------------------------------------------------------------------ *
	 * Vision (image/PDF → JSON text fields). Implemented with raw HTTP per
	 * provider; php-ai-client's providers do not accept image input.
	 * ------------------------------------------------------------------ */

	/**
	 * Generate accessibility text for one media file.
	 *
	 * @param string $key       API key.
	 * @param string $model     Model id.
	 * @param array  $payload   { block:'image'|'document', media_type:string, data:base64 }.
	 * @param string $system    System instruction.
	 * @param string $prompt    User prompt.
	 * @param int    $max_tokens Output cap.
	 * @return array|WP_Error Raw assistant text under key 'text' plus 'input'/'output' token
	 *                        counts, or WP_Error. (Parsing into fields is done by the caller.)
	 */
	abstract public function vision_generate( $key, $model, array $payload, $system, $prompt, $max_tokens );

	/* ------------------------------------------------------------------ *
	 * Bulk Batch API. The state machine in Coywolf_SEO_AI calls these in the
	 * order submit() -> poll() (until ended) -> results() and (on abort)
	 * cancel(). Handles are opaque strings the provider round-trips.
	 * ------------------------------------------------------------------ */

	/**
	 * Build one provider-shaped batch request line.
	 *
	 * @param string $custom_id  Correlation id (round-tripped to results()).
	 * @param string $model      Model id.
	 * @param string $system     System instruction.
	 * @param string $user       User prompt.
	 * @param int    $max_tokens Output cap.
	 * @return array
	 */
	abstract public function batch_build( $custom_id, $model, $system, $user, $max_tokens );

	/**
	 * Submit a batch of requests built by {@see batch_build()}.
	 *
	 * @param string $key      API key.
	 * @param string $model    Model id (some providers need it at batch level).
	 * @param array  $requests Request lines.
	 * @return string|WP_Error Opaque batch handle.
	 */
	abstract public function batch_submit( $key, $model, array $requests );

	/**
	 * Poll a batch's status.
	 *
	 * @param string $key    API key.
	 * @param string $handle Batch handle from {@see batch_submit()}.
	 * @return array|WP_Error { ended:bool, results_handle:string }.
	 */
	abstract public function batch_poll( $key, $handle );

	/**
	 * Fetch and normalise batch results.
	 *
	 * @param string $key            API key.
	 * @param string $results_handle Handle from {@see batch_poll()}.
	 * @return array|WP_Error custom_id => { ok:bool, text:string, error:string, input:int, output:int }.
	 */
	abstract public function batch_results( $key, $results_handle );

	/**
	 * Best-effort cancel of a running batch.
	 *
	 * @param string $key    API key.
	 * @param string $handle Batch handle.
	 * @return void
	 */
	abstract public function batch_cancel( $key, $handle );

	/**
	 * Whether this provider offers a discounted asynchronous Batch API. When
	 * false the bulk runner falls back to real-time calls at standard price.
	 *
	 * @return bool
	 */
	public function supports_batch() {
		return true;
	}

	/* ------------------------------------------------------------------ *
	 * Pricing ($/million tokens), longest-prefix matched on the model id.
	 * ------------------------------------------------------------------ */

	/**
	 * Standard text/vision price table: model-prefix => [ input, output ].
	 *
	 * @return array<string,array{0:float,1:float}>
	 */
	abstract public function standard_prices();

	/**
	 * Batch (discounted) price table: model-prefix => [ input, output ].
	 *
	 * @return array<string,array{0:float,1:float}>
	 */
	abstract public function batch_prices();

	/* ------------------------------------------------------------------ *
	 * Settings UI copy.
	 * ------------------------------------------------------------------ */

	/**
	 * wp-config.php instructions for this provider's key.
	 *
	 * @return array{define:string,help:string}
	 */
	public function wpconfig_help() {
		return array(
			'define' => "define( '" . $this->key_constant() . "', 'YOUR_API_KEY' );",
			/* translators: %s: the wp-config.php constant name. */
			'help'   => sprintf( __( 'Add %s to wp-config.php to keep the key out of the database.', 'coywolf-seo' ), $this->key_constant() ),
		);
	}

	/**
	 * Trailing clause for the bulk-enrich cost estimate, naming what the run
	 * will draw against. The base note is generic; Anthropic overrides it with
	 * its upfront-reserve mechanics (and a %RESERVE% placeholder the estimate
	 * JS fills in).
	 *
	 * @return string
	 */
	public function bulk_cost_note() {
		/* translators: %s: the active AI service name (Claude, OpenAI, Gemini). */
		return sprintf( __( 'Make sure your %s account has enough credit to cover the run.', 'coywolf-seo' ), $this->short_label() );
	}

	/**
	 * Extra note shown when a bulk run pauses on a billing error. Provider-
	 * specific mechanics (e.g. Anthropic's upfront cost check) live in the
	 * concrete class; the base returns nothing.
	 *
	 * @param string $paused_reason The error message that paused the run.
	 * @return string
	 */
	public function bulk_billing_note( $paused_reason = '' ) {
		unset( $paused_reason );
		return '';
	}

	/**
	 * Hint shown by the connection test when real-time calls succeed but a
	 * batch submission is rejected on billing grounds. Provider-specific
	 * (Console paths, spend limits) so the base returns nothing.
	 *
	 * @param string $batch_error The batch submission error message.
	 * @return string
	 */
	public function bulk_billing_hint( $batch_error = '' ) {
		unset( $batch_error );
		return '';
	}

	/* ------------------------------------------------------------------ *
	 * Shared helpers for the concrete providers.
	 * ------------------------------------------------------------------ */

	/**
	 * Longest-prefix cost lookup against a price table.
	 *
	 * @param array  $prices Price table.
	 * @param string $model  Model id.
	 * @param int    $input  Input tokens.
	 * @param int    $output Output tokens.
	 * @return float
	 */
	public static function price_lookup( array $prices, $model, $input, $output ) {
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
	 * User-Agent string for outbound requests.
	 *
	 * @return string
	 */
	protected function user_agent() {
		return 'CoywolfSEO/' . Coywolf_SEO::VERSION . '; ' . home_url( '/' );
	}

	/**
	 * Extract a human-readable error message from a wp_remote_* response.
	 *
	 * @param array|WP_Error $response Response.
	 * @return string
	 */
	protected function http_error( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $body['error']['message'] ) ) {
			return (string) $body['error']['message'];
		}
		if ( isset( $body['error'] ) && is_string( $body['error'] ) ) {
			return (string) $body['error'];
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		/* translators: %d: HTTP status code. */
		return sprintf( __( 'The AI service returned an error (HTTP %d).', 'coywolf-seo' ), $code );
	}
}
