<?php
/**
 * GitHub Releases-based plugin updater.
 *
 * Teaches WordPress's built-in plugin update flow to pull this plugin's
 * updates from the project's GitHub releases. When a release exists whose
 * tag (with optional "v" prefix stripped) is newer than the installed
 * version, the plugin shows up in Dashboard → Updates and the standard
 * one-click "Update Now" works.
 *
 * Requires no auth (the repo is public). The latest release is cached in
 * a transient for {@see self::CACHE_HOURS} so the GitHub API is not hit
 * on every admin pageload.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Coywolf_SEO_GitHub_Updater {

	const REPO          = 'coywolf-llc/coywolf-seo';
	const TRANSIENT_KEY = 'coywolf_seo_gh_release';
	const CACHE_HOURS   = 6;

	/**
	 * WP-Cron hook that refreshes the cached release off the request path.
	 * Per-plugin unique so each Coywolf plugin schedules its own refresh.
	 */
	const CRON_HOOK = 'coywolf_seo_refresh_release';

	/**
	 * Timeout (seconds) for the GitHub HTTP calls. Kept short: the refresh
	 * now runs in a background WP-Cron tick, but a tight cap also bounds the
	 * user-initiated "View details" fetch and protects hosts that happen to
	 * run cron inline with the page request.
	 */
	const HTTP_TIMEOUT = 3;

	/**
	 * Plugin file relative to wp-content/plugins, e.g.
	 * "coywolf-broken-link-checker/coywolf-broken-link-checker.php".
	 *
	 * @var string
	 */
	private $plugin_basename;

	/**
	 * Plugin slug (the containing folder name).
	 *
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * Installed plugin version.
	 *
	 * @var string
	 */
	private $current_version;

	/**
	 * @param string $plugin_file     Absolute path to the main plugin file.
	 * @param string $current_version Currently installed version.
	 */
	public function __construct( $plugin_file, $current_version ) {
		$this->plugin_basename = plugin_basename( $plugin_file );
		$this->plugin_slug     = dirname( $this->plugin_basename );
		if ( '.' === $this->plugin_slug ) {
			$this->plugin_slug = basename( $plugin_file, '.php' );
		}
		$this->current_version = (string) $current_version;
	}

	/**
	 * Wire into the WordPress update flow.
	 */
	public function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dirname' ), 10, 4 );
		add_filter( 'plugin_row_meta', array( $this, 'override_view_details' ), 10, 2 );
		add_filter( 'upgrader_pre_download', array( $this, 'guard_pre_download' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'flush_after_update' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'maybe_show_update_error' ) );
		add_action( self::CRON_HOOK, array( $this, 'refresh_release_cache' ) );
	}

	/**
	 * Surface a GitHub-check failure on the Plugins / Updates screens instead
	 * of silently showing no update. Shown only to users who can update
	 * plugins, only when the last check failed (the `_err` record is set).
	 */
	public function maybe_show_update_error() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = ( $screen && isset( $screen->id ) ) ? $screen->id : '';
		if ( ! in_array( $id, array( 'update-core', 'plugins', 'plugins-network', 'update-core-network' ), true ) ) {
			return;
		}
		$err = get_site_transient( self::TRANSIENT_KEY . '_err' );
		if ( ! $err ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Coywolf SEO:', 'coywolf-seo' ),
			esc_html(
				sprintf(
					/* translators: %s: failure reason */
					__( 'could not check GitHub for updates (%s). This is usually temporary GitHub API rate-limiting on your host; the check retries automatically. If it persists, install the latest release manually from the plugin\'s GitHub Releases page.', 'coywolf-seo' ),
					$err
				)
			)
		);
	}

	/**
	 * After WordPress finishes installing this plugin's update, refresh
	 * our installed-version cache and clear the GitHub-release + WP
	 * `update_plugins` site transients (and every layer of the plugins
	 * cache).
	 *
	 * The version-refresh is the critical part. The OLD plugin code is
	 * still loaded in this PHP request (PHP doesn't re-`include` a file
	 * just because it changed on disk), so `$this->current_version` is
	 * the pre-upgrade version constant. Anything that calls
	 * `wp_update_plugins()` later in the *same* request — admin notices,
	 * an auto-update tick, the Updates page itself when it renders the
	 * post-install state — would otherwise run our `inject_update`
	 * filter, compare the just-fetched GitHub release against the stale
	 * in-memory version, and helpfully re-inject the now-installed
	 * release as "an update is available." That's the second update
	 * prompt the user kept seeing.
	 *
	 * Reading the version off disk via `get_plugin_data()` returns the
	 * NEW header (it parses the file as text, not the cached opcode), so
	 * the comparison from this point on uses the correct installed
	 * version.
	 *
	 * Scoped to runs where WP reports this plugin's basename in the
	 * upgrader's hook_extra, so unrelated plugin/theme/core updates
	 * don't trigger the flush.
	 *
	 * @param WP_Upgrader $upgrader   Upgrader instance (unused).
	 * @param array       $hook_extra { action, type, plugins, ... }
	 */
	public function flush_after_update( $upgrader, $hook_extra ) {
		unset( $upgrader );
		if ( ! is_array( $hook_extra ) ) {
			return;
		}
		if ( ( $hook_extra['action'] ?? '' ) !== 'update' ) {
			return;
		}
		if ( ( $hook_extra['type'] ?? '' ) !== 'plugin' ) {
			return;
		}
		$plugins = isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] )
			? $hook_extra['plugins']
			: array();
		// Some upgrader paths use 'plugin' (singular) when only one is updated.
		if ( empty( $plugins ) && ! empty( $hook_extra['plugin'] ) ) {
			$plugins = array( (string) $hook_extra['plugin'] );
		}
		if ( ! in_array( $this->plugin_basename, $plugins, true ) ) {
			return;
		}

		// 1. Refresh `current_version` from the upgraded plugin file on disk.
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$main = WP_PLUGIN_DIR . '/' . $this->plugin_basename;
		if ( file_exists( $main ) ) {
			$data = get_plugin_data( $main, false, false );
			if ( ! empty( $data['Version'] ) ) {
				$this->current_version = (string) $data['Version'];
			}
		}

		// 2. Clear our cached GitHub release.
		delete_site_transient( self::TRANSIENT_KEY );
		delete_site_transient( self::TRANSIENT_KEY . '_neg' );

		// 3. Clear every layer of the plugins update cache — site
		//    transient (option + object cache) and the `plugins` cache
		//    group `get_plugins()` reads from. `wp_clean_plugins_cache(
		//    true )` does both: deletes the update_plugins transient
		//    and the `plugins` cache group entry.
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( true );
		} else {
			delete_site_transient( 'update_plugins' );
		}
	}

	/**
	 * Last-line defence before WordPress actually downloads the update
	 * package: refuse to fetch a URL that is not on the GitHub-owned host
	 * allowlist. Catches a transient cache that was tampered with after
	 * inject_update() ran, or any other plugin that filters the package
	 * URL between the update check and the download step.
	 *
	 * Returning a WP_Error makes WP abort this upgrade and surface the
	 * message in the admin notice; returning $reply (false by default)
	 * lets WP proceed with its normal download.
	 *
	 * Manual zip uploads (Plugins → Add New → Upload Plugin) pass a local
	 * filesystem path rather than a URL; those are left untouched so an
	 * upload isn't mistaken for an untrusted remote download.
	 *
	 * @param mixed         $reply      Filtered reply (default false → "go ahead").
	 * @param string        $package    Package URL (or local path) WP is about to fetch.
	 * @param WP_Upgrader   $upgrader   Upgrader instance (unused).
	 * @param array         $hook_extra Upgrade context; includes 'plugin' on plugin updates (WP 5.5+).
	 * @return mixed
	 */
	public function guard_pre_download( $reply, $package, $upgrader, $hook_extra = array() ) {
		unset( $upgrader );

		// upgrader_pre_download also fires for manual zip uploads from
		// Plugins → Add New → Upload Plugin, where `$package` is a LOCAL
		// filesystem path, not a URL. The GitHub host allowlist only makes
		// sense for remote downloads, and a local upload whose filename
		// contains our slug would otherwise satisfy the "looks like ours"
		// check below and then fail the allowlist — blocking the upload
		// with "Refusing to download a plugin update from an untrusted
		// host". Leave non-URL packages be.
		if ( ! is_string( $package ) || ! preg_match( '#^https?://#i', $package ) ) {
			return $reply;
		}

		// Prefer WP's authoritative signal: on a plugin update, hook_extra
		// names the plugin basename being downloaded (WP 5.5+). When it
		// says the download is ours, enforce the allowlist regardless of
		// what the URL looks like. This closes the gap where a tampered
		// update_plugins transient pointed our package at a non-GitHub
		// host whose path matched neither the repo nor the slug — the old
		// "looks like ours" heuristic would fail open and let it through.
		$is_ours = is_array( $hook_extra )
			&& ! empty( $hook_extra['plugin'] )
			&& $hook_extra['plugin'] === $this->plugin_basename;

		if ( ! $is_ours ) {
			// No authoritative signal (older WP, or another plugin's
			// download): only intervene when the package URL path looks
			// like ours, otherwise stay out of WP's way entirely.
			$parts = is_string( $package ) ? wp_parse_url( $package ) : false;
			$path  = ( is_array( $parts ) && ! empty( $parts['path'] ) ) ? $parts['path'] : '';
			$looks_like_ours = ( '' !== $path )
				&& ( false !== stripos( $path, self::REPO ) || false !== stripos( $path, $this->plugin_slug ) );
			if ( ! $looks_like_ours ) {
				return $reply;
			}
		}

		// Ours (or looks like it): fail closed unless the package URL is on
		// the GitHub host allowlist.
		if ( ! is_string( $package ) || '' === $this->validate_package_url( $package ) ) {
			return new WP_Error(
				'coywolf_seo_untrusted_package',
				__( 'Refusing to download a plugin update from an untrusted host.', 'coywolf-seo' )
			);
		}
		return $reply;
	}

	/**
	 * Replace the auto-generated "View details" link on the Plugins list row
	 * (which would otherwise open the plugins_api thickbox iframe) with a
	 * direct link to the project's GitHub repository.
	 *
	 * The {@see plugin_info()} handler is kept registered because WordPress
	 * still calls plugins_api during the update flow itself; only the row
	 * meta link is rerouted.
	 *
	 * @param string[] $plugin_meta Row meta links.
	 * @param string   $plugin_file Plugin basename being rendered.
	 * @return string[]
	 */
	public function override_view_details( $plugin_meta, $plugin_file ) {
		if ( $plugin_file !== $this->plugin_basename || ! is_array( $plugin_meta ) ) {
			return $plugin_meta;
		}
		$repo_url = 'https://github.com/' . self::REPO;
		foreach ( $plugin_meta as $i => $item ) {
			// WP's "View details" entry uses the plugin-install thickbox iframe.
			// Match on either the URL or the thickbox class so a different
			// translation of "View details" still gets caught.
			if ( false !== strpos( $item, 'plugin-install.php?tab=plugin-information' )
				|| false !== strpos( $item, 'class="thickbox' )
				|| false !== strpos( $item, "class='thickbox" ) ) {
				$plugin_meta[ $i ] = sprintf(
					'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
					esc_url( $repo_url ),
					esc_html__( 'View details', 'coywolf-seo' )
				);
			}
		}
		return $plugin_meta;
	}

	/**
	 * If a newer GitHub release exists, append this plugin to the list of
	 * available updates so WordPress shows it on the Updates screen.
	 *
	 * @param object $transient The update_plugins transient.
	 * @return object
	 */
	public function inject_update( $transient ) {
		if ( empty( $transient ) || ! is_object( $transient ) ) {
			$transient = new stdClass();
		}

		// Keep the GitHub request OFF the page-render path. This filter runs
		// during wp_update_plugins() — including the synchronous force-check
		// that the "Reset Updates" button triggers — so a blocking fetch here
		// makes WordPress hang on api.github.com while the user waits (and,
		// with several self-updating plugins active, hang once per plugin).
		// Instead: serve the cached release if warm; otherwise schedule a
		// one-off background refresh and inject nothing this round. The newer
		// version surfaces on a later load, once the cache is populated.
		$release = $this->get_cached_release();
		if ( ! is_array( $release ) ) {
			$this->maybe_schedule_refresh();
			return $transient;
		}

		$remote_version = $this->normalize_version( $release['tag_name'] );
		if ( '' === $remote_version ) {
			return $transient;
		}

		$update_obj = $this->build_update_obj( $release, $remote_version );

		// Refuse to advertise an update we wouldn't actually install: if
		// the release's package URL didn't survive validate_package_url
		// (host not on the GitHub allowlist), there's nothing safe to
		// download — leave the transient untouched so WP doesn't show
		// a broken Update Now button.
		if ( empty( $update_obj->package ) ) {
			return $transient;
		}

		// Only offer an update when the remote version is strictly newer.
		if ( version_compare( $remote_version, $this->current_version, '<=' ) ) {
			if ( isset( $transient->no_update ) ) {
				$transient->no_update[ $this->plugin_basename ] = $update_obj;
			}
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		$transient->response[ $this->plugin_basename ] = $update_obj;

		return $transient;
	}

	/**
	 * Build the update object WordPress expects in the update_plugins
	 * transient.
	 */
	private function build_update_obj( $release, $remote_version ) {
		$obj                = new stdClass();
		$obj->id            = self::REPO;
		$obj->slug          = $this->plugin_slug;
		$obj->plugin        = $this->plugin_basename;
		$obj->new_version   = $remote_version;
		$obj->url           = 'https://github.com/' . self::REPO;
		$obj->package       = $this->pick_package_url( $release );
		$obj->icons         = $this->icon_urls();
		$obj->banners       = array();
		$obj->banners_rtl   = array();
		$obj->tested        = '';
		$obj->requires_php  = '';
		$obj->compatibility = new stdClass();
		return $obj;
	}

	/**
	 * Populate the "View details" modal on the Plugins / Updates screen.
	 *
	 * @param mixed  $result Filtered value (false by default).
	 * @param string $action Requested action.
	 * @param object $args   Request args.
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( empty( $args->slug ) || $args->slug !== $this->plugin_slug ) {
			return $result;
		}

		// Prefer the warm cache. "View details" is a deliberate, user-initiated
		// click, so a bounded synchronous fetch here is fine — this is the one
		// place we'll touch the network on a real request (never on an ordinary
		// page render).
		$release = $this->get_cached_release();
		if ( ! is_array( $release ) ) {
			$release = $this->fetch_release_now();
		}
		if ( ! is_array( $release ) ) {
			return $result;
		}

		// The Atom feed carries no release body, so when the cached release
		// came from it the changelog is empty. Fetch the body from the REST API
		// just for this modal (still bounded, still user-initiated).
		$body = isset( $release['body'] ) ? (string) $release['body'] : '';
		if ( '' === $body ) {
			$reason = '';
			$rest   = $this->fetch_via_api( $reason );
			if ( is_array( $rest ) && ! empty( $rest['body'] ) ) {
				$body = (string) $rest['body'];
			}
		}

		$info                = new stdClass();
		$info->name          = 'Coywolf SEO';
		$info->slug          = $this->plugin_slug;
		$info->version       = $this->normalize_version( $release['tag_name'] );
		$info->author        = '<a href="https://coywolf.com/">Coywolf</a>';
		$info->homepage      = 'https://github.com/' . self::REPO;
		$info->download_link = $this->pick_package_url( $release );
		$info->last_updated  = isset( $release['published_at'] ) ? $release['published_at'] : '';
		$info->sections      = array(
			'description' => 'An SEO plugin that has exactly what you need, and nothing more.',
			'changelog'   => $this->render_changelog( $body ),
		);
		$info->icons         = $this->icon_urls();
		return $info;
	}

	/**
	 * Icon URLs for the Plugins / Updates / View-details screens.
	 *
	 * WordPress's plugin-update UI reads the icon URLs straight from the
	 * `icons` array on the plugin object in the `update_plugins` transient
	 * (and from the same key on the `plugins_api` "plugin_information"
	 * response). Without this, WP falls back to its generic plug icon — the
	 * `.wordpress-org/icon-*.png` files in the repo are never picked up,
	 * partly because they aren't shipped in the release zip (the release
	 * workflow excludes the `.wordpress-org` directory) and partly because
	 * even if they were, WP doesn't know to look for them by path.
	 *
	 * Point at the canonical PNGs on `raw.githubusercontent.com` so the
	 * Coywolf logo renders on the Updates row and the View-details modal.
	 *
	 * @return array<string,string>
	 */
	private function icon_urls() {
		$base = 'https://raw.githubusercontent.com/' . self::REPO . '/main/.wordpress-org/';
		return array(
			'1x'      => $base . 'icon-128x128.png',
			'2x'      => $base . 'icon-256x256.png',
			'default' => $base . 'icon-256x256.png',
		);
	}

	/**
	 * Convert the release notes (GitHub-flavored markdown) into very simple
	 * HTML for the "View details" modal. We avoid a full markdown parser —
	 * just turn headings, lists, code spans, and links into HTML.
	 */
	private function render_changelog( $markdown ) {
		$md = trim( (string) $markdown );
		if ( '' === $md ) {
			return '<p>See the GitHub release for changelog details.</p>';
		}
		$lines = preg_split( '/\r\n|\r|\n/', $md );
		$html  = '';
		$in_ul = false;
		foreach ( $lines as $line ) {
			$line = rtrim( $line );
			if ( preg_match( '/^(#{1,6})\s+(.*)$/', $line, $m ) ) {
				if ( $in_ul ) { $html .= '</ul>'; $in_ul = false; }
				$level = strlen( $m[1] );
				$html .= '<h' . $level . '>' . esc_html( $m[2] ) . '</h' . $level . '>';
			} elseif ( preg_match( '/^[*-]\s+(.*)$/', $line, $m ) ) {
				if ( ! $in_ul ) { $html .= '<ul>'; $in_ul = true; }
				$html .= '<li>' . $this->inline_md( $m[1] ) . '</li>';
			} elseif ( '' === $line ) {
				if ( $in_ul ) { $html .= '</ul>'; $in_ul = false; }
			} else {
				if ( $in_ul ) { $html .= '</ul>'; $in_ul = false; }
				$html .= '<p>' . $this->inline_md( $line ) . '</p>';
			}
		}
		if ( $in_ul ) { $html .= '</ul>'; }
		return $html;
	}

	/**
	 * Inline markdown: backtick code, [text](url), and entity-safe escaping.
	 */
	private function inline_md( $text ) {
		$text = esc_html( $text );
		$text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
			function ( $m ) {
				return '<a href="' . esc_url( $m[2] ) . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
			},
			$text
		);
		return $text;
	}

	/**
	 * After WordPress extracts the downloaded zip but before it moves it
	 * into place, rename the source directory to match this plugin's slug.
	 *
	 * GitHub's auto-generated zipballs unpack to "<owner>-<repo>-<sha>/",
	 * which would otherwise be installed as a different plugin. A zip
	 * asset whose top-level folder is already correct is left untouched.
	 *
	 * @param string       $source        Extracted source path.
	 * @param string       $remote_source Remote source path.
	 * @param WP_Upgrader  $upgrader      Upgrader instance.
	 * @param array        $hook_extra    Extra data; includes 'plugin' on plugin upgrades.
	 * @return string|WP_Error
	 */
	public function fix_source_dirname( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
			return $source;
		}

		global $wp_filesystem;
		$expected = trailingslashit( dirname( $source ) ) . $this->plugin_slug;
		$source   = untrailingslashit( $source );

		if ( $source === $expected ) {
			return trailingslashit( $source );
		}

		if ( $wp_filesystem && $wp_filesystem->move( $source, $expected, true ) ) {
			return trailingslashit( $expected );
		}

		return new WP_Error(
			'coywolf_seo_rename_failed',
			__( 'Could not rename the downloaded update folder to match the plugin slug.', 'coywolf-seo' )
		);
	}

	/**
	 * Return the cached release WITHOUT ever touching the network. This is the
	 * only release accessor used on the normal request path — see
	 * {@see inject_update()} for why the GitHub fetch is kept off it.
	 *
	 * @return array|null Shaped release array, or null when the cache is cold.
	 */
	private function get_cached_release() {
		$cached = get_site_transient( self::TRANSIENT_KEY );
		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Schedule a one-off background refresh of the release cache so the actual
	 * GitHub request runs in a WP-Cron tick instead of blocking the page the
	 * visitor is waiting on. No-op when a refresh is already queued or a recent
	 * fetch failed (the negative cache is still warm), so a cold cache can't
	 * stack up duplicate jobs or hammer a rate-limited API.
	 */
	private function maybe_schedule_refresh() {
		if ( false !== get_site_transient( self::TRANSIENT_KEY . '_neg' ) ) {
			return;
		}
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time() + 1, self::CRON_HOOK );
	}

	/**
	 * WP-Cron callback: fetch the latest release off the request path and, on
	 * success, drop the `update_plugins` transient so the next admin page load
	 * re-runs the update check with a warm cache and {@see inject_update()} can
	 * actually inject the release. (While the cache was cold, inject_update
	 * stored update_plugins without our entry; deleting it forces the re-check.)
	 */
	public function refresh_release_cache() {
		if ( is_array( $this->get_cached_release() ) ) {
			return;
		}
		if ( is_array( $this->fetch_release_now() ) ) {
			delete_site_transient( 'update_plugins' );
		}
	}

	/**
	 * Fetch the latest release synchronously, honouring the cache and negative
	 * cache. Tries the fast, rate-limit-free GitHub releases Atom feed FIRST
	 * (it's all that's needed to detect a newer version), and only falls back
	 * to the REST API — capped at 60 unauthenticated requests/hour per IP
	 * (shared on managed hosts) and prone to multi-second responses — when the
	 * feed is unavailable.
	 *
	 * Called only off the request path (WP-Cron) or on a deliberate user action
	 * ("View details"), never on an ordinary page render.
	 *
	 * @return array|null Shaped release array, or null on failure.
	 */
	private function fetch_release_now() {
		$cached = $this->get_cached_release();
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( false !== get_site_transient( self::TRANSIENT_KEY . '_neg' ) ) {
			return null;
		}

		$reason_atom = '';
		$reason_api  = '';
		$release     = $this->fetch_via_atom( $reason_atom );
		if ( ! is_array( $release ) ) {
			$release = $this->fetch_via_api( $reason_api );
		}

		if ( ! is_array( $release ) ) {
			$reason = $reason_atom;
			if ( '' !== $reason_api ) {
				$reason = ( '' !== $reason ) ? $reason . '; ' . $reason_api : $reason_api;
			}
			if ( '' === $reason ) {
				$reason = 'unknown error';
			}
			// Short negative cache to avoid hammering GitHub, plus a longer
			// "last error" record the Updates screen can show so the failure
			// isn't silent.
			set_site_transient( self::TRANSIENT_KEY . '_neg', $reason, 15 * MINUTE_IN_SECONDS );
			set_site_transient( self::TRANSIENT_KEY . '_err', $reason, self::CACHE_HOURS * HOUR_IN_SECONDS );
			return null;
		}

		// Success — clear any stale error note and cache the result.
		delete_site_transient( self::TRANSIENT_KEY . '_err' );
		set_site_transient( self::TRANSIENT_KEY, $release, self::CACHE_HOURS * HOUR_IN_SECONDS );
		return $release;
	}

	/**
	 * Fetch the latest release from the GitHub REST API.
	 *
	 * @param string $reason Out-param: failure reason ('' on success).
	 * @return array|null Shaped release array, or null on failure.
	 */
	private function fetch_via_api( &$reason ) {
		$reason = '';
		$url    = 'https://api.github.com/repos/' . self::REPO . '/releases/latest';
		$res    = wp_remote_get(
			$url,
			array(
				'timeout' => self::HTTP_TIMEOUT,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			$reason = 'GitHub API: ' . $res->get_error_message();
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			$reason = ( 403 === $code )
				? 'GitHub API rate limit reached (HTTP 403)'
				: sprintf( 'GitHub API returned HTTP %d', $code );
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			$reason = 'GitHub API: unexpected response';
			return null;
		}
		return $this->shape_release(
			(string) $data['tag_name'],
			isset( $data['name'] ) ? (string) $data['name'] : '',
			isset( $data['body'] ) ? (string) $data['body'] : '',
			isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
			isset( $data['html_url'] ) ? (string) $data['html_url'] : '',
			isset( $data['zipball_url'] ) ? (string) $data['zipball_url'] : '',
			( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) ? $data['assets'] : array()
		);
	}

	/**
	 * Fetch the latest release from the GitHub releases Atom feed
	 * (github.com/<repo>/releases.atom), which is not subject to the REST API
	 * rate limit and is now the primary source for the version check. The feed
	 * has no asset URLs, so the package URL is reconstructed from the release
	 * workflow's "<build-slug>.zip" naming — where the build slug is the main
	 * plugin file's basename (e.g. coywolf-reset-plugin-update), NOT the
	 * installed folder slug, which can differ. The codeload zipball is kept on
	 * the release as a fallback (resolved by pick_package_url() /
	 * fix_source_dirname()).
	 *
	 * @param string $reason Out-param: failure reason ('' on success).
	 * @return array|null Shaped release array, or null on failure.
	 */
	private function fetch_via_atom( &$reason ) {
		$reason = '';
		$url    = 'https://github.com/' . self::REPO . '/releases.atom';
		$res    = wp_remote_get(
			$url,
			array(
				'timeout' => self::HTTP_TIMEOUT,
				'headers' => array(
					'Accept'     => 'application/atom+xml',
					'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			$reason = 'GitHub Atom feed: ' . $res->get_error_message();
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			$reason = sprintf( 'GitHub Atom feed returned HTTP %d', $code );
			return null;
		}
		$body = (string) wp_remote_retrieve_body( $res );
		if ( ! preg_match( '#<entry>(.*?)</entry>#s', $body, $m ) ) {
			$reason = 'GitHub Atom feed: no releases found';
			return null;
		}
		$entry = $m[1];

		// The tag is the trailing segment of the release link or the entry id.
		$tag = '';
		if ( preg_match( '#/releases/tag/([^"\'<>\s]+)#', $entry, $tm ) ) {
			$tag = html_entity_decode( $tm[1], ENT_QUOTES, 'UTF-8' );
		} elseif ( preg_match( '#<id>[^<]*/([^/<]+)</id>#', $entry, $tm ) ) {
			$tag = $tm[1];
		}
		if ( '' === $tag ) {
			$reason = 'GitHub Atom feed: could not determine the release tag';
			return null;
		}

		$title = $tag;
		if ( preg_match( '#<title>(.*?)</title>#s', $entry, $ttl ) ) {
			$decoded = trim( html_entity_decode( wp_strip_all_tags( $ttl[1] ), ENT_QUOTES, 'UTF-8' ) );
			if ( '' !== $decoded ) {
				$title = $decoded;
			}
		}
		$updated = preg_match( '#<updated>(.*?)</updated>#s', $entry, $up ) ? $up[1] : '';

		// The release zip is named after the build slug (the main plugin file's
		// basename), which the workflow uses and which may differ from the
		// installed folder slug.
		$build_slug = basename( $this->plugin_basename, '.php' );
		$asset      = array(
			array(
				'name'                 => $build_slug . '.zip',
				'browser_download_url' => 'https://github.com/' . self::REPO . '/releases/download/' . $tag . '/' . $build_slug . '.zip',
				'content_type'         => 'application/zip',
			),
		);
		$zipball = 'https://codeload.github.com/' . self::REPO . '/zip/refs/tags/' . $tag;

		return $this->shape_release(
			$tag,
			$title,
			'',
			$updated,
			'https://github.com/' . self::REPO . '/releases/tag/' . $tag,
			$zipball,
			$asset
		);
	}

	/**
	 * Reduce a release (from either source) to the small set of fields the
	 * updater stores in its transient.
	 *
	 * @param string $tag       Release tag.
	 * @param string $name      Release name.
	 * @param string $body      Release notes (markdown).
	 * @param string $published Published timestamp.
	 * @param string $html_url  Human URL for the release.
	 * @param string $zipball   Fallback zipball URL.
	 * @param array  $assets    Raw asset records.
	 * @return array
	 */
	private function shape_release( $tag, $name, $body, $published, $html_url, $zipball, $assets ) {
		$keep = array(
			'tag_name'     => (string) $tag,
			'name'         => (string) $name,
			'body'         => (string) $body,
			'published_at' => (string) $published,
			'html_url'     => (string) $html_url,
			'zipball_url'  => (string) $zipball,
			'assets'       => array(),
		);
		foreach ( (array) $assets as $a ) {
			if ( ! is_array( $a ) ) {
				continue;
			}
			$keep['assets'][] = array(
				'name'                 => isset( $a['name'] ) ? (string) $a['name'] : '',
				'browser_download_url' => isset( $a['browser_download_url'] ) ? (string) $a['browser_download_url'] : '',
				'content_type'         => isset( $a['content_type'] ) ? (string) $a['content_type'] : '',
			);
		}
		return $keep;
	}

	/**
	 * Pick the best zip URL from the release: prefer a .zip asset (which
	 * we control the layout of); fall back to GitHub's auto-zipball.
	 */
	private function pick_package_url( $release ) {
		$candidate = '';
		if ( ! empty( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( ! empty( $asset['name'] ) && '.zip' === strtolower( substr( $asset['name'], -4 ) ) ) {
					$candidate = isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';
					break;
				}
			}
		}
		if ( '' === $candidate ) {
			$candidate = isset( $release['zipball_url'] ) ? (string) $release['zipball_url'] : '';
		}
		return $this->validate_package_url( $candidate );
	}

	/**
	 * Reject anything we wouldn't recognise as a GitHub-served release
	 * artefact. The Releases API can in principle return any URL (the
	 * cached transient is also writable by other privileged code), so
	 * before WordPress's upgrader downloads and executes whatever is at
	 * the other end, confirm the host is one GitHub actually uses for
	 * release zips and auto-zipballs.
	 *
	 * Returns an empty string when the URL is unacceptable, which causes
	 * WP to skip the update gracefully rather than installing it from a
	 * stranger.
	 *
	 * @param string $url Candidate package URL.
	 * @return string Validated URL, or '' if not trusted.
	 */
	private function validate_package_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
			return '';
		}
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		$host = strtolower( $parts['host'] );
		$allowed = array(
			'github.com',
			'api.github.com',
			'codeload.github.com',
			'objects.githubusercontent.com',
			'release-assets.githubusercontent.com',
		);
		if ( ! in_array( $host, $allowed, true ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * Strip a leading "v" from a tag and return a clean semver-ish string.
	 */
	private function normalize_version( $tag ) {
		$tag = trim( (string) $tag );
		if ( '' !== $tag && ( 'v' === $tag[0] || 'V' === $tag[0] ) ) {
			$tag = substr( $tag, 1 );
		}
		return $tag;
	}
}
