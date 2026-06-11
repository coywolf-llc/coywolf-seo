<?php
/**
 * Suppression of other SEO plugins' front-end output.
 *
 * While Coywolf SEO is active it owns the SEO output — titles, meta
 * description, robots, canonical, Open Graph, and schema markup. When
 * another SEO plugin is also active, its overlapping output is switched
 * off through each plugin's own documented switches, so the two never
 * print duplicate or conflicting tags:
 *
 * - Yoast SEO: the wpseo_head presenter pipeline is unhooked (titles come
 *   through pre_get_document_title, removed below), and the legacy
 *   wpseo_json_ld_output filter is kept off as a belt.
 * - Rank Math: the rank_math/frontend/disable master filter, plus the
 *   rank_math/head action unhooked.
 * - All in One SEO: the aioseo_disable master filter.
 * - The SEO Framework: the the_seo_framework_query_supports_seo filter.
 * - SEOPress: no master switch exists, so it is flagged in the admin
 *   notice for manual deactivation (its title filter is still neutralized
 *   with the rest).
 *
 * Features outside this plugin's scope (XML sitemaps, redirects) are left
 * untouched — suppressing them would remove functionality nothing here
 * replaces.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Other-SEO-plugin suppression.
 */
final class Coywolf_SEO_Compat {

	/**
	 * Hook everything up.
	 */
	public function init() {
		// Master switches, registered before the other plugins consult them.
		add_filter( 'rank_math/frontend/disable', '__return_true' );
		add_filter( 'aioseo_disable', '__return_true' );
		add_filter( 'the_seo_framework_query_supports_seo', '__return_false' );
		add_filter( 'wpseo_json_ld_output', '__return_false' );

		add_action( 'template_redirect', array( $this, 'suppress_head_output' ), 0 );
		add_action( 'admin_notices', array( $this, 'maybe_show_detected_notice' ) );
	}

	/**
	 * The active SEO plugins this plugin knows how to talk about.
	 *
	 * @return array Plugin name => fully suppressed?
	 */
	public function detected_plugins() {
		$found = array();
		if ( defined( 'WPSEO_VERSION' ) ) {
			$found['Yoast SEO'] = true;
		}
		if ( class_exists( 'RankMath' ) ) {
			$found['Rank Math'] = true;
		}
		if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			$found['All in One SEO'] = true;
		}
		if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) {
			$found['The SEO Framework'] = true;
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			$found['SEOPress'] = false;
		}
		return $found;
	}

	/**
	 * Unhook the other plugins' head output on the front end, right before
	 * the template renders (after they have all registered).
	 */
	public function suppress_head_output() {
		if ( empty( $this->detected_plugins() ) ) {
			return;
		}

		// Yoast SEO prints everything inside its own wpseo_head action.
		remove_all_actions( 'wpseo_head' );

		// Rank Math prints everything inside rank_math/head (belt — the
		// master filter above already disables its front end).
		remove_all_actions( 'rank_math/head' );

		// Every one of these plugins takes the title over through
		// pre_get_document_title; clearing it returns the title to
		// WordPress's parts-based composition, which this plugin filters.
		remove_all_filters( 'pre_get_document_title' );

		// Some SEO plugins remove core's robots output to print their own.
		// Theirs is gone now, so make sure core's (which this plugin
		// filters) is back.
		if ( false === has_action( 'wp_head', 'wp_robots' ) ) {
			add_action( 'wp_head', 'wp_robots', 1 );
		}
		// Same for core's canonical: this plugin removes and replaces it
		// itself in Coywolf_SEO_Head, so nothing to restore here.
	}

	/**
	 * On this plugin's own screens, note which SEO plugins are being
	 * suppressed so their deactivation is an informed choice. Nowhere else
	 * — no site-wide nagging.
	 */
	public function maybe_show_detected_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'coywolf-seo' ) ) {
			return;
		}
		$found = $this->detected_plugins();
		if ( empty( $found ) ) {
			return;
		}

		$full    = array_keys( array_filter( $found ) );
		$partial = array_keys( array_diff_key( $found, array_filter( $found ) ) );

		echo '<div class="notice notice-info"><p>';
		if ( ! empty( $full ) ) {
			printf(
				/* translators: %s: comma-separated list of plugin names. */
				esc_html__( 'Coywolf SEO has taken over the SEO output from: %s. Their titles, descriptions, schema, Open Graph, robots, and canonical tags are suppressed while this plugin is active — you can deactivate them.', 'coywolf-seo' ),
				'<strong>' . esc_html( implode( ', ', $full ) ) . '</strong>'
			);
		}
		if ( ! empty( $partial ) ) {
			if ( ! empty( $full ) ) {
				echo '<br />';
			}
			printf(
				/* translators: %s: comma-separated list of plugin names. */
				esc_html__( '%s offers no reliable way to suppress its output — deactivate it to avoid duplicate tags.', 'coywolf-seo' ),
				'<strong>' . esc_html( implode( ', ', $partial ) ) . '</strong>'
			);
		}
		echo '</p></div>';
	}
}
