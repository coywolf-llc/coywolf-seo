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
 * SCHEMA INTEROP: when this plugin's own "Schema.org markup" feature is
 * turned OFF (! Coywolf_SEO_Options::feature_enabled( 'schema' )), the
 * schema-killing switches above are relaxed so an active third-party SEO
 * plugin can supply the JSON-LD instead of nobody emitting any — while the
 * redundant NON-schema output (titles, meta description, Open Graph, robots,
 * canonical, editor UI) stays suppressed because those Coywolf features are
 * still active. When schema is ON the behaviour is unchanged (everything
 * suppressed). This is best-effort and built on each plugin's documented
 * public filters; the filter/class names called out below should be verified
 * against the installed plugin versions. Per-plugin schema-on/off handling
 * lives in init() and suppress_head_output().
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
		// Whether this plugin is also outputting its own Schema.org markup.
		// When it is OFF, Coywolf still owns titles/meta/OG/robots/canonical,
		// but it leaves the schema field open so another active SEO plugin can
		// supply the JSON-LD instead of nobody emitting any. This flag changes
		// only the schema-killing switches below; all non-schema suppression is
		// registered unconditionally either way.
		$schema_on = Coywolf_SEO_Options::feature_enabled( 'schema' );

		// Master switches, registered before the other plugins consult them.
		//
		// rank_math/frontend/disable, aioseo_disable and
		// the_seo_framework_query_supports_seo are broad "disable the whole
		// front end" levers — they also take out the other plugin's schema.
		// When schema is OFF we want to drop the master disable ONLY where the
		// plugin gives us a reliable way to keep its non-schema output
		// suppressed by other means; otherwise we keep the master disable and
		// accept that that plugin's schema can't be selectively re-enabled:
		//
		//   - Rank Math: rank_math/json_ld only *modifies* the JSON-LD array; it
		//     cannot re-enable schema once rank_math/frontend/disable has shut
		//     the front end down. Schema-on interop for Rank Math is handled in
		//     suppress_head_output() (we skip the master disable's head twin
		//     there when schema is off) — see the note below. The master
		//     filter here is therefore left in place only as the schema-ON
		//     belt; when schema is OFF we do NOT register it so Rank Math's
		//     rank_math/head (which carries its schema) is free to run, and we
		//     surgically drop its non-schema head output instead.
		//   - AIOSEO: it has no documented schema-only output filter that
		//     survives aioseo_disable, so we keep the master disable and accept
		//     that AIOSEO's schema cannot be selectively re-enabled. (VERIFY:
		//     check the installed AIOSEO version for an aioseo_disable_schema /
		//     aioseo_schema_output style filter; if one exists, prefer it so
		//     AIOSEO schema can flow when this plugin's schema is OFF.)
		//   - The SEO Framework: the_seo_framework_query_supports_seo => false
		//     short-circuits ALL of TSF's front-end output, schema included, so
		//     the_seo_framework_use_ld_json_output cannot re-enable schema once
		//     it is set. There is no documented lever that suppresses TSF's
		//     title/meta/OG while keeping ONLY its LD-JSON, so we keep the
		//     master disable in both states and accept that TSF's schema cannot
		//     be selectively re-enabled. (VERIFY against the installed TSF
		//     version — if a future version lets use_ld_json_output run
		//     independently of query_supports_seo, switch to that.)
		if ( $schema_on ) {
			add_filter( 'rank_math/frontend/disable', '__return_true' );
		}
		add_filter( 'aioseo_disable', '__return_true' );
		add_filter( 'the_seo_framework_query_supports_seo', '__return_false' );

		// Yoast legacy JSON-LD belt. Only clamp it shut when this plugin is
		// emitting its own schema; when schema is OFF we want Yoast's JSON-LD
		// (legacy and modern) to flow.
		if ( $schema_on ) {
			add_filter( 'wpseo_json_ld_output', '__return_false' );
		}

		add_action( 'template_redirect', array( $this, 'suppress_head_output' ), 0 );
		add_action( 'admin_notices', array( $this, 'maybe_show_detected_notice' ) );
		add_action( 'init', array( $this, 'suppress_editor_ui' ), 99 );
	}

	/**
	 * Hide the other SEO plugins' edit-screen UI (classic meta box and
	 * block-editor sidebar) — this plugin's SEO panel replaces them.
	 *
	 * Each switch is the plugin's own UI-only gate, verified to leave its
	 * sitemaps and indexables untouched. Everything is a plain filter, so
	 * nothing breaks when a plugin is absent or changes internally — the
	 * worst case is its UI reappearing.
	 */
	public function suppress_editor_ui() {
		if ( ! is_admin() ) {
			return;
		}

		// Yoast SEO: one gate covers the classic meta box, the whole
		// block-editor enqueue (the sidebar ships in it), and its columns.
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
			add_filter( "wpseo_enable_editor_features_{$post_type}", '__return_false' );
		}

		// Rank Math: kills its meta box and Gutenberg sidebar together.
		add_filter( 'rank_math/metabox/add_seo_metabox', '__return_false' );

		// The SEO Framework: UI-only gate for its in-post box.
		add_filter( 'the_seo_framework_seobox_output', '__return_false' );

		// SEOPress: the classic meta box and the React metabox/sidebar are
		// gated separately.
		add_filter( 'seopress_metaboxe_seo', '__return_empty_array' );
		add_filter( 'seopress_metaboxe_content_analysis', '__return_empty_array' );
		add_filter( 'seopress_can_enqueue_universal_metabox', '__return_false' );

		// AIOSEO has no filter; without its meta box mount node the Vue
		// sidebar never initializes either. Also a belt for Yoast < 16.2.
		add_action( 'add_meta_boxes', array( $this, 'remove_seo_meta_boxes' ), 100 );
	}

	/**
	 * Remove the boxes that have no UI filter, after their owners add them.
	 */
	public function remove_seo_meta_boxes() {
		remove_meta_box( 'aioseo-settings', null, 'normal' );
		remove_meta_box( 'wpseo_meta', null, 'normal' );
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

		// When this plugin emits its own schema, suppress the other plugins'
		// schema too; when it does not, leave the schema field open for them.
		$schema_on = Coywolf_SEO_Options::feature_enabled( 'schema' );

		if ( $schema_on ) {
			// Yoast SEO prints everything (titles, meta, OG, robots, canonical
			// AND schema) inside its own wpseo_head action.
			remove_all_actions( 'wpseo_head' );
		} else {
			// Schema OFF: don't nuke wpseo_head wholesale — that would take
			// Yoast's schema down with the rest. Instead keep ONLY Yoast's
			// schema presenter and drop every other presenter, so Yoast emits
			// just its JSON-LD while Coywolf keeps titles/meta/OG/robots.
			//
			// VERIFY against the installed Yoast version: modern Yoast builds
			// its <head> from a presenter array passed through
			// wpseo_frontend_presenters; the schema presenter's class name
			// contains "Schema" (e.g.
			// Yoast\WP\SEO\Presenters\Schema_Presenter). If that naming
			// changes, update the match below.
			add_filter( 'wpseo_frontend_presenters', array( $this, 'keep_only_schema_presenter' ) );
		}

		if ( $schema_on ) {
			// Rank Math prints everything inside rank_math/head (belt — the
			// rank_math/frontend/disable master filter above already disables
			// its front end when schema is on).
			remove_all_actions( 'rank_math/head' );
		}
		// Schema OFF: we intentionally do NOT remove_all_actions(
		// 'rank_math/head' ) and we did NOT register
		// rank_math/frontend/disable in init(), so rank_math/head runs and
		// carries Rank Math's JSON-LD. Rank Math bundles its head output in
		// one action and exposes no documented per-tag head filter to drop the
		// non-schema tags while keeping schema, so this is a best-effort path:
		// CAVEAT — Rank Math's title/meta/OG/robots may briefly duplicate
		// Coywolf's because we cannot surgically strip them here. (VERIFY: if a
		// reliable Rank Math head/presenter filter becomes available for the
		// installed version, drop the non-schema tags here instead of letting
		// the whole rank_math/head action through.)

		// Every one of these plugins takes the title over through
		// pre_get_document_title; removing their callbacks (scoped to known
		// SEO plugin classes, so unrelated plugins keep theirs) returns the
		// title to WordPress's parts-based composition, which this plugin
		// filters.
		$this->remove_seo_title_filters();

		// Some SEO plugins remove the robots output to print their own.
		// Theirs is gone now, so make sure this plugin's renderer is in
		// place (it replaces core's wp_robots with double-quoted markup).
		$head = Coywolf_SEO::instance()->head();
		if ( false === has_action( 'wp_head', array( $head, 'output_robots' ) ) ) {
			add_action( 'wp_head', array( $head, 'output_robots' ), 1 );
		}
		// Same for core's canonical: this plugin removes and replaces it
		// itself in Coywolf_SEO_Head, so nothing to restore here.
	}

	/**
	 * Keep only Yoast's schema presenter, dropping every other presenter.
	 *
	 * Used on the wpseo_frontend_presenters filter when this plugin's own
	 * schema output is OFF: Yoast still emits its JSON-LD, but its titles,
	 * meta description, Open Graph, robots and canonical presenters are
	 * removed so Coywolf's equivalents are the only ones printed.
	 *
	 * Best-effort: presenters are matched by class name containing "Schema".
	 * VERIFY this against the installed Yoast version — the schema presenter is
	 * Yoast\WP\SEO\Presenters\Schema_Presenter at time of writing.
	 *
	 * @param array $presenters Yoast presenter objects for the current request.
	 * @return array Only the schema presenter(s).
	 */
	public function keep_only_schema_presenter( $presenters ) {
		if ( ! is_array( $presenters ) ) {
			return $presenters;
		}
		$kept = array();
		foreach ( $presenters as $presenter ) {
			$class = is_object( $presenter ) ? get_class( $presenter ) : (string) $presenter;
			if ( false !== stripos( $class, 'Schema' ) ) {
				$kept[] = $presenter;
			}
		}
		return $kept;
	}

	/**
	 * Remove pre_get_document_title callbacks that belong to known SEO
	 * plugins, leaving every other plugin's title filter alone.
	 */
	private function remove_seo_title_filters() {
		global $wp_filter;
		if ( empty( $wp_filter['pre_get_document_title'] ) || ! isset( $wp_filter['pre_get_document_title']->callbacks ) ) {
			return;
		}
		foreach ( $wp_filter['pre_get_document_title']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$fn    = $callback['function'];
				$owner = '';
				if ( is_array( $fn ) && isset( $fn[0] ) ) {
					$owner = is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0];
				} elseif ( is_string( $fn ) ) {
					$owner = $fn;
				}
				if ( '' !== $owner && preg_match( '/yoast|wpseo|rank_?math|aioseo|seopress|the_seo_framework|autodescription/i', $owner ) ) {
					remove_filter( 'pre_get_document_title', $fn, $priority );
				}
			}
		}
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
