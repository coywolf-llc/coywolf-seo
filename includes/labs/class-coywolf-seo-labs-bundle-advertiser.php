<?php
/**
 * Shared base for Labs feature advertisers (discovery hints).
 *
 * Holds the gating and helper logic identical across advertisers: the
 * configured/public gates (enabled + advertise + endpoint, then blog_public for
 * actual output), the indexable-response test that mirrors the generator's
 * visibility rules, and the guarded Robots.txt Manager accessor used to
 * contribute managed (idempotent, replace-by-id) rules without ever overwriting
 * a foreign robots.txt. The actual hints each feature emits — the <head> link,
 * the specific managed rule(s)/directive, any well-known alias, the panel copy —
 * stay in the concrete subclass because they differ per feature.
 *
 * A subclass implements feature() (returning its owning Coywolf_SEO_Labs_Feature)
 * so these shared gates can read the feature's toggles.
 *
 * Stripped from the WordPress.org build with the rest of includes/labs/.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract advertiser with shared gating + robots plumbing.
 */
abstract class Coywolf_SEO_Labs_Bundle_Advertiser {

	/**
	 * The owning Labs feature (settings + canonical URLs).
	 *
	 * @return Coywolf_SEO_Labs_Feature
	 */
	abstract protected function feature();

	/**
	 * Whether advertising is configured on: the feature enabled + its advertise
	 * sub-setting + the live endpoint (no reachable URL to point at otherwise).
	 * Independent of blog_public, which gates the actual public output (so
	 * toggling site visibility needs no rewrite flush).
	 *
	 * @return bool
	 */
	public function is_configured() {
		$f = $this->feature();
		return $f->is_enabled() && $f->advertise_enabled() && $f->endpoint_enabled();
	}

	/**
	 * Whether public output should actually be emitted: configured AND the site
	 * is public.
	 *
	 * @return bool
	 */
	protected function is_public() {
		return $this->is_configured() && (bool) get_option( 'blog_public', 1 );
	}

	/**
	 * Whether the public advertising is active (used by the llms.txt owner to
	 * decide whether to include this feature's section).
	 *
	 * @return bool
	 */
	public function advertise_active() {
		return $this->is_public();
	}

	/**
	 * Whether the current response is a public, indexable HTML page (mirrors the
	 * generator's visibility rules: no admin/feed/robots/404/search/preview, no
	 * password-protected or per-post noindex singular).
	 *
	 * @return bool
	 */
	protected function is_indexable_response() {
		if ( is_admin() || is_feed() || is_robots() || is_404() || is_search() || is_preview() ) {
			return false;
		}
		if ( is_singular() ) {
			if ( post_password_required() ) {
				return false;
			}
			$meta = Coywolf_SEO_Options::post_meta( get_queried_object_id() );
			if ( ! empty( $meta['noindex'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The Robots.txt Manager instance, or null if unavailable.
	 *
	 * @return Coywolf_SEO_Robots|null
	 */
	protected function robots_manager() {
		if ( ! class_exists( 'Coywolf_SEO_Robots' ) ) {
			return null;
		}
		$inst = Coywolf_SEO::instance();
		return method_exists( $inst, 'robots' ) ? $inst->robots() : null;
	}
}
