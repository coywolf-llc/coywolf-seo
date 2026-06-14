<?php
/**
 * Registry of AI service providers and the currently selected one.
 *
 * Coywolf SEO supports Claude (Anthropic), OpenAI, and Google Gemini, with one
 * active at a time chosen on the Settings page. Everything provider-specific is
 * resolved through here so the rest of the plugin only ever talks to "the
 * current provider".
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider registry (static).
 */
class Coywolf_SEO_AI_Providers {

	/**
	 * Default service when none is saved (preserves pre-multi-provider installs,
	 * which were Anthropic-only).
	 */
	const DEFAULT_SERVICE = 'anthropic';

	/**
	 * Instantiated providers, keyed by id.
	 *
	 * @var array<string,Coywolf_SEO_AI_Provider>
	 */
	private static $instances = array();

	/**
	 * Map of service id => concrete class.
	 *
	 * @return array<string,string>
	 */
	private static function classes() {
		return array(
			'anthropic' => 'Coywolf_SEO_AI_Anthropic',
			'openai'    => 'Coywolf_SEO_AI_OpenAI',
			'google'    => 'Coywolf_SEO_AI_Google',
		);
	}

	/**
	 * Valid service ids.
	 *
	 * @return string[]
	 */
	public static function ids() {
		return array_keys( self::classes() );
	}

	/**
	 * Get a provider instance by id (falls back to the default for unknown ids).
	 *
	 * @param string $id Service id.
	 * @return Coywolf_SEO_AI_Provider
	 */
	public static function get( $id ) {
		$classes = self::classes();
		if ( ! isset( $classes[ $id ] ) ) {
			$id = self::DEFAULT_SERVICE;
		}
		if ( ! isset( self::$instances[ $id ] ) ) {
			$class                  = $classes[ $id ];
			self::$instances[ $id ] = new $class();
		}
		return self::$instances[ $id ];
	}

	/**
	 * The active service id (validated), from the 'ai_service' option.
	 *
	 * @return string
	 */
	public static function current_id() {
		$id = (string) Coywolf_SEO_Options::get( 'ai_service' );
		return in_array( $id, self::ids(), true ) ? $id : self::DEFAULT_SERVICE;
	}

	/**
	 * The active provider instance.
	 *
	 * @return Coywolf_SEO_AI_Provider
	 */
	public static function current() {
		return self::get( self::current_id() );
	}

	/**
	 * All provider instances (for the Settings page, which renders a key field
	 * per service).
	 *
	 * @return array<string,Coywolf_SEO_AI_Provider>
	 */
	public static function all() {
		$out = array();
		foreach ( self::ids() as $id ) {
			$out[ $id ] = self::get( $id );
		}
		return $out;
	}

	/**
	 * Service id => label, for the selector.
	 *
	 * @return array<string,string>
	 */
	public static function choices() {
		$out = array();
		foreach ( self::all() as $id => $provider ) {
			$out[ $id ] = $provider->label();
		}
		return $out;
	}

	/**
	 * Transient-key prefixes for the two model lists (text/enrichment and
	 * vision/Image Text). Each is suffixed with the provider id, so switching
	 * providers never surfaces another's list.
	 */
	const MODELS_CACHE_TEXT   = 'coywolf_seo_ai_models';
	const MODELS_CACHE_VISION = 'coywolf_seo_image_models';

	/**
	 * The shared "list models, cached per provider for 12 hours" routine behind
	 * both the enrichment and Image Text model dropdowns. The caller supplies the
	 * key prefix, a fetch callback (the live list), and an optional fallback
	 * callback used (uncached) when the live fetch returns nothing.
	 *
	 * @param string        $prefix   One of the MODELS_CACHE_* prefixes.
	 * @param callable      $fetch    Returns the live model list (id/name rows).
	 * @param callable|null $fallback Returns a fallback list when $fetch is empty.
	 * @return array
	 */
	public static function cached_models( $prefix, callable $fetch, ?callable $fallback = null ) {
		$cache_key = $prefix . '_' . self::current_id();
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$models = call_user_func( $fetch );
		if ( ! is_array( $models ) || empty( $models ) ) {
			return null !== $fallback ? (array) call_user_func( $fallback ) : array();
		}
		set_transient( $cache_key, $models, 12 * HOUR_IN_SECONDS );
		return $models;
	}

	/**
	 * Drop every cached model list — both the enrichment and Image Text caches,
	 * for every provider — plus the legacy single-key cache. This is the single
	 * place model caches are invalidated (whenever an API key or the active
	 * service changes, and on uninstall), so neither dropdown serves a stale list
	 * after a key change.
	 */
	public static function flush_model_caches() {
		foreach ( self::ids() as $id ) {
			delete_transient( self::MODELS_CACHE_TEXT . '_' . $id );
			delete_transient( self::MODELS_CACHE_VISION . '_' . $id );
		}
		// Legacy single-key cache from the pre-multi-provider era.
		delete_transient( self::MODELS_CACHE_TEXT );
	}
}
