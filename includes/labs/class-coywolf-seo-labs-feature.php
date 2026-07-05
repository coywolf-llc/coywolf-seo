<?php
/**
 * Shared base for self-contained Labs features (OKF, EntityMap, ARD, …).
 *
 * Each concrete feature owns a self-contained option, a public read endpoint, a
 * debounced background rebuild, an "advertise" sub-feature, and a Labs-page
 * panel. The controller skeleton — settings accessors, the four admin-post
 * action handlers, the capability/nonce guard, the redirect-with-message
 * pattern, the content-change debounce, and the cron rebuild plumbing — is
 * identical across features and lives here. Late static binding (static::OPTION
 * etc.) keeps every deployed option key, query var, cron hook, nonce, and
 * admin-post action name byte-for-byte the same as before this base existed, so
 * the refactor is invisible to installs already running the features.
 *
 * A concrete subclass MUST:
 *   - define the consts OPTION, BUILD_OPTION, QUERY_VAR, CRON_HOOK;
 *   - assign $this->generator and $this->advertiser in its constructor;
 *   - implement id(), title(), add_rewrite_rules(), maybe_serve(), notices(),
 *     render_panel(), do_rebuild(), download_basename().
 * It MAY override flush_rewrite_on_change(), on_settings_saved(), on_built().
 *
 * The whole includes/labs/ tree is stripped from the WordPress.org build (see
 * .github/build-wporg.sh), so this — like every Labs class — ships only in the
 * GitHub distribution and is referenced behind class_exists() guards.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract controller for a Labs bundle/file-set feature.
 */
abstract class Coywolf_SEO_Labs_Feature {

	/**
	 * Capability required to manage the feature (matches Settings).
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Bundle / file-set generator. Assigned by the subclass constructor.
	 *
	 * @var object
	 */
	protected $generator;

	/**
	 * Discovery / advertising sub-feature. Assigned by the subclass constructor.
	 *
	 * @var object
	 */
	protected $advertiser;

	// ---------------------------------------------------------------------
	// Identity (subclass-specific)
	// ---------------------------------------------------------------------

	/**
	 * Feature id (used by the Labs registry, message keys, and derived names).
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Human title for the Labs panel.
	 *
	 * @return string
	 */
	abstract public function title();

	// ---------------------------------------------------------------------
	// Accessors
	// ---------------------------------------------------------------------

	/**
	 * Generator accessor (used by the advertiser and the panel).
	 *
	 * @return object
	 */
	public function generator() {
		return $this->generator;
	}

	/**
	 * Advertiser accessor.
	 *
	 * @return object
	 */
	public function advertiser() {
		return $this->advertiser;
	}

	// ---------------------------------------------------------------------
	// Hooks
	// ---------------------------------------------------------------------

	/**
	 * Register hooks. Runtime hooks (endpoint, regeneration) are gated on the
	 * opt-in toggle; the admin-post handlers and cron hook register
	 * unconditionally (they only fire on their own requests and re-check the
	 * capability / enabled state). The admin-post action names are derived from
	 * the OPTION const so they stay identical to the hand-written originals.
	 */
	public function init() {
		add_action( 'admin_post_' . static::OPTION . '_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . static::OPTION . '_rebuild', array( $this, 'handle_rebuild' ) );
		add_action( 'admin_post_' . static::OPTION . '_download', array( $this, 'handle_download' ) );
		add_action( 'admin_post_' . static::OPTION . '_cleanup', array( $this, 'handle_cleanup' ) );

		add_action( static::CRON_HOOK, array( $this, 'run_scheduled_rebuild' ) );

		// Everything below this point only matters while the feature is on.
		if ( ! $this->is_enabled() ) {
			return;
		}

		if ( $this->endpoint_enabled() ) {
			add_action( 'init', array( $this, 'add_rewrite_rules' ) );
			add_filter( 'query_vars', array( $this, 'register_query_var' ) );
			add_action( 'template_redirect', array( $this, 'maybe_serve' ), 0 );
		}

		// Discovery / advertising (self-gates on advertise + endpoint + public).
		$this->advertiser->init();

		// Debounced regeneration when content the output reflects changes.
		add_action( 'save_post', array( $this, 'on_content_changed' ), 10, 0 );
		add_action( 'deleted_post', array( $this, 'on_content_changed' ), 10, 0 );
		add_action( 'edited_term', array( $this, 'on_content_changed' ), 10, 0 );
		add_action( 'created_term', array( $this, 'on_content_changed' ), 10, 0 );
		add_action( 'delete_term', array( $this, 'on_content_changed' ), 10, 0 );
	}

	// ---------------------------------------------------------------------
	// Settings
	// ---------------------------------------------------------------------

	/**
	 * Feature settings merged over defaults.
	 *
	 * @return array { bool enabled, bool live_endpoint, bool advertise }
	 */
	public function settings() {
		$saved = get_option( static::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return wp_parse_args(
			$saved,
			array(
				'enabled'       => false,
				'live_endpoint' => true,
				// Advertising defaults on once the feature is enabled (handle_save).
				'advertise'     => true,
			)
		);
	}

	/**
	 * Whether the feature is enabled (opt-in; false by default).
	 *
	 * @return bool
	 */
	public function is_enabled() {
		$s = $this->settings();
		return ! empty( $s['enabled'] );
	}

	/**
	 * Whether the public read endpoint is enabled.
	 *
	 * @return bool
	 */
	public function endpoint_enabled() {
		$s = $this->settings();
		return ! empty( $s['live_endpoint'] );
	}

	/**
	 * Whether public discovery/advertising is enabled.
	 *
	 * @return bool
	 */
	public function advertise_enabled() {
		$s = $this->settings();
		return ! empty( $s['advertise'] );
	}

	/**
	 * Whether the public advertising is actually active (enabled + advertise +
	 * endpoint + public site). Used by the llms.txt owner.
	 *
	 * @return bool
	 */
	public function advertise_active() {
		return $this->advertiser->advertise_active();
	}

	/**
	 * The llms.txt reference entry for this feature (without the leading "- [").
	 *
	 * @return string
	 */
	public function llms_reference_line() {
		return $this->advertiser->llms_reference_line();
	}

	// ---------------------------------------------------------------------
	// Public read endpoint (shared plumbing; add_rewrite_rules + maybe_serve
	// are feature-specific)
	// ---------------------------------------------------------------------

	/**
	 * Register the rewrite rules for the read endpoint.
	 */
	abstract public function add_rewrite_rules();

	/**
	 * The regex patterns this feature registers via add_rewrite_rules(), so a
	 * disable transition can remove them from the in-memory rule set before
	 * flushing (otherwise flush_rewrite_rules() re-persists the still-registered
	 * rules and the disabled routes keep serving the homepage with HTTP 200).
	 * Must match the first argument of every add_rewrite_rule() call exactly.
	 *
	 * @return string[]
	 */
	protected function rewrite_patterns() {
		return array();
	}

	/**
	 * Serve a request to the read endpoint (or 404).
	 */
	abstract public function maybe_serve();

	/**
	 * Register the endpoint query var.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function register_query_var( $vars ) {
		$vars[] = static::QUERY_VAR;
		return $vars;
	}

	/**
	 * Send a 404 for an endpoint request and stop.
	 */
	protected function serve_404() {
		status_header( 404 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=UTF-8' );
		echo 'Not found.';
		exit;
	}

	// ---------------------------------------------------------------------
	// Regeneration
	// ---------------------------------------------------------------------

	/**
	 * Content the output reflects changed: schedule a single, debounced
	 * background rebuild rather than blocking the request.
	 */
	public function on_content_changed() {
		if ( ! $this->is_enabled() ) {
			return;
		}
		if ( ! wp_next_scheduled( static::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, static::CRON_HOOK );
		}
	}

	/**
	 * Cron callback: rebuild and record the summary.
	 */
	public function run_scheduled_rebuild() {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$this->rebuild_and_record();
	}

	/**
	 * Rebuild now and store the build summary; returns the generator result.
	 *
	 * @return array|WP_Error
	 */
	protected function rebuild_and_record() {
		$result = $this->do_rebuild();
		if ( ! is_wp_error( $result ) ) {
			update_option( static::BUILD_OPTION, $result, false );
			$this->on_built();
		}
		return $result;
	}

	/**
	 * Run the generator rebuild. Subclasses supply the exact call (some pass the
	 * served endpoint URL so cross-links are absolute).
	 *
	 * @return array|WP_Error
	 */
	abstract protected function do_rebuild();

	/**
	 * Hook fired after a successful rebuild (e.g. invalidate the llms.txt cache).
	 * No-op by default.
	 *
	 * @return void
	 */
	protected function on_built() {}

	/**
	 * Last successful build summary, or null.
	 *
	 * @return array|null
	 */
	public function last_build() {
		$build = get_option( static::BUILD_OPTION, null );
		return is_array( $build ) ? $build : null;
	}

	// ---------------------------------------------------------------------
	// Admin-post action handlers
	// ---------------------------------------------------------------------

	/**
	 * Save the feature settings (enable + endpoint + advertise), flushing rewrite
	 * rules on any route change, reconciling the managed robots rule(s), and
	 * scheduling an initial build on first enable.
	 */
	public function handle_save() {
		$this->guard( static::OPTION . '_save' );

		$was_enabled = $this->is_enabled();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in guard(); only the boolean checkboxes below are read.
		$raw      = isset( $_POST[ static::OPTION ] ) && is_array( $_POST[ static::OPTION ] ) ? wp_unslash( $_POST[ static::OPTION ] ) : array();
		$enabled  = ! empty( $raw['enabled'] );
		$endpoint = ! empty( $raw['live_endpoint'] );
		// The advertise checkbox is only shown once the feature is enabled, so on
		// the first enable honor the default-on rather than reading its absence as
		// "off"; otherwise take the submitted value.
		$advertise = ( $enabled && ! $was_enabled ) ? true : ! empty( $raw['advertise'] );

		$this->redirect( $this->apply_settings( $enabled, $endpoint, $advertise ) );
	}

	/**
	 * Persist the enable/endpoint/advertise state and run every side effect a
	 * change implies: flush rewrite rules on a route change, reconcile the managed
	 * robots rule(s), fire the on-settings-saved hook, and schedule (or cancel)
	 * the background build on a first enable / disable. Extracted from handle_save
	 * so an orchestrating feature (AI Discovery) can drive the same transitions
	 * from a combined form. Returns the status-message key.
	 *
	 * @param bool $enabled   Whether the feature is on.
	 * @param bool $endpoint  Whether the live endpoint is on.
	 * @param bool $advertise Whether advertising is on.
	 * @return string Message key for the Labs-page notice.
	 */
	public function apply_settings( $enabled, $endpoint, $advertise ) {
		$was_enabled   = $this->is_enabled();
		$was_endpoint  = $this->endpoint_enabled();
		$was_advertise = $this->advertise_enabled();

		update_option(
			static::OPTION,
			array(
				'enabled'       => (bool) $enabled,
				'live_endpoint' => (bool) $endpoint,
				'advertise'     => (bool) $advertise,
			)
		);

		$this->flush_rewrite_on_change( $was_enabled, $was_endpoint, $was_advertise, $enabled, $endpoint, $advertise );

		// Reconcile the managed robots.txt rule(s) with the current state.
		if ( $this->advertiser->is_configured() ) {
			$this->advertiser->add_robots_rule();
		} else {
			$this->advertiser->remove_robots_rule();
		}

		$this->on_settings_saved();

		if ( $enabled && ! $was_enabled ) {
			// First enable: kick off an initial build in the background.
			if ( ! wp_next_scheduled( static::CRON_HOOK ) ) {
				wp_schedule_single_event( time() + 5, static::CRON_HOOK );
			}
			return $this->id() . '-enabled';
		}
		if ( ! $enabled && $was_enabled ) {
			// Disabled: stop the background rebuild; files remain until cleanup.
			wp_unschedule_hook( static::CRON_HOOK );
			return $this->id() . '-disabled';
		}
		return $this->id() . '-saved';
	}

	/**
	 * Flush rewrite rules when the routes this feature owns change availability.
	 * Default: only the serving endpoint changes routes (the common case). A
	 * feature whose advertiser also registers routes (e.g. a /.well-known alias)
	 * overrides this to account for that.
	 *
	 * @param bool $was_enabled   Enabled before the save.
	 * @param bool $was_endpoint  Endpoint enabled before the save.
	 * @param bool $was_advertise Advertise enabled before the save.
	 * @param bool $enabled       Enabled after the save.
	 * @param bool $endpoint      Endpoint enabled after the save.
	 * @param bool $advertise     Advertise enabled after the save.
	 * @return void
	 */
	protected function flush_rewrite_on_change( $was_enabled, $was_endpoint, $was_advertise, $enabled, $endpoint, $advertise ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the full signature is the shared override contract; subclasses use different subsets of it.
		$endpoint_before = $was_enabled && $was_endpoint;
		$endpoint_after  = $enabled && $endpoint;
		if ( $endpoint_before !== $endpoint_after ) {
			// Register the rules for this request before flushing so an enable
			// persists them (init already ran by admin-post time).
			if ( $endpoint_after ) {
				$this->add_rewrite_rules();
			} else {
				// Disable: init() already registered the rules earlier this
				// request (under the old, enabled state), so remove them from the
				// in-memory set before flushing — otherwise flush_rewrite_rules()
				// re-persists them and the routes keep serving the homepage.
				$this->unregister_rewrite_patterns( $this->rewrite_patterns() );
			}
			flush_rewrite_rules();
		}
	}

	/**
	 * Remove the given regex patterns from the in-memory rewrite rule set so a
	 * subsequent flush_rewrite_rules() drops them from the persisted rules.
	 *
	 * @param string[] $patterns Regex patterns registered via add_rewrite_rule().
	 * @return void
	 */
	protected function unregister_rewrite_patterns( array $patterns ) {
		if ( empty( $patterns ) ) {
			return;
		}
		global $wp_rewrite;
		if ( ! $wp_rewrite || ! is_array( $wp_rewrite->extra_rules_top ) ) {
			return;
		}
		foreach ( $patterns as $pattern ) {
			unset( $wp_rewrite->extra_rules_top[ $pattern ] );
		}
	}

	/**
	 * Hook fired at the end of a settings save (e.g. invalidate the llms.txt
	 * cache so an advertised section appears/disappears immediately). No-op by
	 * default.
	 *
	 * @return void
	 */
	protected function on_settings_saved() {}

	/**
	 * Rebuild the output now (synchronous, on explicit request).
	 */
	public function handle_rebuild() {
		$this->guard( static::OPTION . '_rebuild' );
		if ( ! $this->is_enabled() ) {
			$this->redirect( $this->id() . '-disabled' );
		}
		$result = $this->rebuild_and_record();
		$this->redirect( is_wp_error( $result ) ? $this->id() . '-error' : $this->id() . '-rebuilt' );
	}

	/**
	 * Stream the current output as a downloadable zip.
	 */
	public function handle_download() {
		$this->guard( static::OPTION . '_download' );
		if ( ! $this->generator->bundle_exists() ) {
			$this->redirect( $this->id() . '-nobundle' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = wp_tempnam( 'coywolf-' . $this->id() . '.zip' );
		$res = $this->generator->build_zip( $tmp );
		if ( is_wp_error( $res ) ) {
			wp_delete_file( $tmp );
			$this->redirect( $this->id() . '-error' );
		}

		$site = sanitize_title( get_bloginfo( 'name' ) );
		$name = $this->download_basename() . ( $site ? '-' . $site : '' ) . '.zip';

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . (string) filesize( $tmp ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a generated temp archive to the browser.
		readfile( $tmp );
		wp_delete_file( $tmp );
		exit;
	}

	/**
	 * The base filename (without site suffix or extension) for the zip download.
	 *
	 * @return string
	 */
	abstract protected function download_basename();

	/**
	 * Delete the generated output from disk (explicit cleanup).
	 */
	public function handle_cleanup() {
		$this->guard( static::OPTION . '_cleanup' );
		$ok = $this->generator->cleanup();
		delete_option( static::BUILD_OPTION );
		$this->redirect( $ok ? $this->id() . '-cleaned' : $this->id() . '-error' );
	}

	/**
	 * Capability + nonce guard shared by every action handler.
	 *
	 * @param string $nonce_action Nonce action name.
	 * @return void
	 */
	protected function guard( $nonce_action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Coywolf SEO Labs features.', 'coywolf-seo' ) );
		}
		check_admin_referer( $nonce_action );
	}

	/**
	 * Redirect back to the Labs page with a status message key.
	 *
	 * @param string $msg Message key read by the Labs page notice.
	 * @return void
	 */
	protected function redirect( $msg ) {
		$url = add_query_arg(
			array(
				'page'                 => Coywolf_SEO_Labs::SLUG,
				'coywolf-seo-labs-msg' => $msg,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Invalidate the llms.txt cache (guarded) so an advertised section appears /
	 * disappears immediately after a save or rebuild.
	 *
	 * @return void
	 */
	protected function invalidate_llms_cache() {
		if ( ! class_exists( 'Coywolf_SEO' ) ) {
			return;
		}
		$inst = Coywolf_SEO::instance();
		if ( $inst && method_exists( $inst, 'llms_txt' ) ) {
			$llms = $inst->llms_txt();
			if ( $llms && method_exists( $llms, 'clear_cache' ) ) {
				$llms->clear_cache();
			}
		}
	}

	// ---------------------------------------------------------------------
	// Labs panel (subclass-specific)
	// ---------------------------------------------------------------------

	/**
	 * Status messages this feature can surface on the Labs page.
	 *
	 * @return array key => array( type, text )
	 */
	abstract public function notices();

	/**
	 * Render this feature's panel on the Labs page.
	 */
	abstract public function render_panel();
}
