<?php
/**
 * Category URL prefix removal for Coywolf SEO.
 *
 * When "Hide category prefix in slug" is on, category archives live at
 * /news/ instead of /category/news/: category links are rewritten without
 * the base, rewrite rules are generated per category (nested paths, paging,
 * and feeds included), and requests for the old prefixed URLs are 301
 * redirected to the clean ones.
 *
 * Only effective with pretty permalinks; with plain permalinks category
 * URLs have no prefix to hide. A category that shares a slug with a page
 * will shadow that page — the usual tradeoff of removing the base.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Category base removal.
 */
final class Coywolf_SEO_Category_Base {

	/**
	 * Query var marking a request for an old, prefixed category URL.
	 */
	const REDIRECT_VAR = 'coywolf_seo_cat_redirect';

	/**
	 * Hook everything up.
	 *
	 * Every callback checks the option itself (rather than gating the
	 * registration) so that toggling the setting and flushing the rewrite
	 * rules in the same request regenerates them correctly.
	 */
	public function init() {
		add_filter( 'category_link', array( $this, 'strip_base' ) );
		add_filter( 'category_rewrite_rules', array( $this, 'rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'redirect_old_urls' ), 1 );

		// Keep the generated rules in step with the category tree.
		add_action( 'created_category', 'flush_rewrite_rules' );
		add_action( 'edited_category', 'flush_rewrite_rules' );
		add_action( 'delete_category', 'flush_rewrite_rules' );
	}

	/**
	 * Whether prefix removal is on.
	 *
	 * @return bool
	 */
	private function enabled() {
		return (bool) Coywolf_SEO_Options::get( 'cat_hide_prefix' );
	}

	/**
	 * The configured category base ('category' unless customized).
	 *
	 * @return string
	 */
	private function base() {
		$base = trim( (string) get_option( 'category_base' ) );
		$base = trim( $base, '/' );
		return ( '' !== $base ) ? $base : 'category';
	}

	/**
	 * Remove the category base from a category link.
	 *
	 * @param string $link Category archive URL.
	 * @return string
	 */
	public function strip_base( $link ) {
		global $wp_rewrite;
		if ( ! $this->enabled() || ! $wp_rewrite->using_permalinks() ) {
			return $link;
		}
		return str_replace( '/' . $this->base() . '/', '/', $link );
	}

	/**
	 * Replace the category rewrite section with per-category rules that
	 * match the un-prefixed paths, plus one rule that catches the old
	 * prefixed URLs for redirecting.
	 *
	 * @param array $rules Core's category rules (discarded).
	 * @return array
	 */
	public function rewrite_rules( $rules ) {
		global $wp_rewrite;
		if ( ! $this->enabled() || ! $wp_rewrite->using_permalinks() ) {
			return $rules;
		}

		$out        = array();
		$categories = get_categories( array( 'hide_empty' => false ) );
		foreach ( $categories as $category ) {
			$path = $this->category_path( $category );
			if ( '' === $path ) {
				continue;
			}
			$quoted = preg_quote( $path, '#' );

			$out[ '(' . $quoted . ')/(?:feed/)?(feed|rdf|rss|rss2|atom)/?$' ] = 'index.php?category_name=$matches[1]&feed=$matches[2]';
			$out[ '(' . $quoted . ')/' . $wp_rewrite->pagination_base . '/?([0-9]{1,})/?$' ] = 'index.php?category_name=$matches[1]&paged=$matches[2]';
			$out[ '(' . $quoted . ')/?$' ] = 'index.php?category_name=$matches[1]';
		}

		// Old prefixed URLs: mark them for the 301 below.
		$out[ preg_quote( $this->base(), '#' ) . '/(.+?)/?$' ] = 'index.php?' . self::REDIRECT_VAR . '=$matches[1]';

		return $out;
	}

	/**
	 * The full nested slug path of a category (parent/child).
	 *
	 * @param WP_Term $category Category term.
	 * @return string
	 */
	private function category_path( $category ) {
		$path     = $category->slug;
		$ancestor = (int) $category->parent;
		$guard    = 0;
		while ( $ancestor && $guard < 25 ) {
			$parent = get_term( $ancestor, 'category' );
			if ( ! $parent || is_wp_error( $parent ) ) {
				break;
			}
			$path     = $parent->slug . '/' . $path;
			$ancestor = (int) $parent->parent;
			$guard++;
		}
		return $path;
	}

	/**
	 * Register the redirect marker query var.
	 *
	 * @param array $vars Public query vars.
	 * @return array
	 */
	public function register_query_var( $vars ) {
		$vars[] = self::REDIRECT_VAR;
		return $vars;
	}

	/**
	 * 301 an old /category/… URL to its clean equivalent.
	 */
	public function redirect_old_urls() {
		if ( ! $this->enabled() ) {
			return;
		}
		// REDIRECT_VAR is a public query var, so core populates it from $_GET
		// on any URL. Only act when the request genuinely matched our old-URL
		// rewrite rule (built in rewrite_rules() with the same pattern), never
		// when the var is injected via ?coywolf_seo_cat_redirect= on an
		// unrelated path.
		$redirect_rule = preg_quote( $this->base(), '#' ) . '/(.+?)/?$';
		if ( ( isset( $GLOBALS['wp'] ) ? (string) $GLOBALS['wp']->matched_rule : '' ) !== $redirect_rule ) {
			return;
		}
		$path = get_query_var( self::REDIRECT_VAR );
		if ( '' === (string) $path ) {
			return;
		}
		wp_safe_redirect( home_url( user_trailingslashit( (string) $path, 'category' ) ), 301 );
		exit;
	}
}
