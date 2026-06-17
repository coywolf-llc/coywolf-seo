<?php
/**
 * Open Knowledge Format (OKF) export — a self-contained Labs feature.
 *
 * Owns everything for the feature: its opt-in toggle and settings, the Labs
 * page panel that manages it, the rebuild / download / cleanup actions, the
 * public read endpoint, and the debounced background regeneration. Disabled by
 * default — nothing is generated, written, or served until the site owner
 * enables it on the Labs page.
 *
 * Implements the Labs feature contract: id(), title(), init(), render_panel().
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The OKF Labs feature.
 */
final class Coywolf_SEO_OKF {

	/**
	 * Option holding this feature's settings (kept out of the core options
	 * store so the whole feature stays self-contained and strippable).
	 */
	const OPTION = 'coywolf_seo_okf';

	/**
	 * Option holding the last successful build summary (time + counts).
	 */
	const BUILD_OPTION = 'coywolf_seo_okf_build';

	/**
	 * Public read-endpoint query var.
	 */
	const QUERY_VAR = 'coywolf_seo_okf';

	/**
	 * Debounced background-rebuild cron hook.
	 */
	const CRON_HOOK = 'coywolf_seo_okf_rebuild';

	/**
	 * Capability required to manage the feature (matches Settings).
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Bundle generator.
	 *
	 * @var Coywolf_SEO_OKF_Generator
	 */
	private $generator;

	/**
	 * Construct with the generator.
	 */
	public function __construct() {
		$this->generator = new Coywolf_SEO_OKF_Generator();
	}

	/**
	 * Feature id (used by the Labs registry / nonces).
	 *
	 * @return string
	 */
	public function id() {
		return 'okf';
	}

	/**
	 * Human title for the Labs panel.
	 *
	 * @return string
	 */
	public function title() {
		return __( 'Open Knowledge Format (OKF) export', 'coywolf-seo' );
	}

	/**
	 * Register hooks. Runtime hooks (endpoint, regeneration) are gated on the
	 * opt-in toggle; the admin-post handlers register unconditionally (they
	 * only fire on their own admin-post requests and re-check the capability).
	 */
	public function init() {
		add_action( 'admin_post_coywolf_seo_okf_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_coywolf_seo_okf_rebuild', array( $this, 'handle_rebuild' ) );
		add_action( 'admin_post_coywolf_seo_okf_download', array( $this, 'handle_download' ) );
		add_action( 'admin_post_coywolf_seo_okf_cleanup', array( $this, 'handle_cleanup' ) );

		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_rebuild' ) );

		// Everything below this point only matters while the feature is on.
		if ( ! $this->is_enabled() ) {
			return;
		}

		if ( $this->endpoint_enabled() ) {
			add_action( 'init', array( $this, 'add_rewrite_rules' ) );
			add_filter( 'query_vars', array( $this, 'register_query_var' ) );
			add_action( 'template_redirect', array( $this, 'maybe_serve' ), 0 );
		}

		// Debounced regeneration when content that the bundle reflects changes.
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
	 * @return array { bool enabled, bool live_endpoint }
	 */
	public function settings() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return wp_parse_args(
			$saved,
			array(
				'enabled'       => false,
				'live_endpoint' => true,
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
	 * Whether the public read endpoint is enabled (only meaningful when the
	 * feature itself is on).
	 *
	 * @return bool
	 */
	public function endpoint_enabled() {
		$s = $this->settings();
		return ! empty( $s['live_endpoint'] );
	}

	// ---------------------------------------------------------------------
	// Public read endpoint
	// ---------------------------------------------------------------------

	/**
	 * Register the /okf/ rewrite rules (root index + per-concept).
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule( '^okf/?$', 'index.php?' . self::QUERY_VAR . '=index.md', 'top' );
		add_rewrite_rule( '^okf/(.+)$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Register the endpoint query var.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Serve a bundle Markdown file for an /okf/ request. Only .md files inside
	 * the bundle directory are served; anything else (including path-traversal
	 * attempts) 404s.
	 */
	public function maybe_serve() {
		$req = (string) get_query_var( self::QUERY_VAR );
		if ( '' === $req ) {
			return;
		}
		if ( ! $this->is_enabled() || ! $this->endpoint_enabled() ) {
			return; // Let WordPress 404 normally.
		}

		$rel = ltrim( $req, '/' );
		if ( '' === $rel || '/' === substr( $rel, -1 ) ) {
			$rel .= 'index.md';
		}

		// Hard constraints before touching the filesystem.
		if ( false !== strpos( $rel, '..' ) || false !== strpos( $rel, "\0" ) || ! preg_match( '/\.md$/', $rel ) ) {
			$this->serve_404();
		}

		$base      = $this->generator->bundle_path();
		$real_base = realpath( $base );
		$real_file = realpath( $base . '/' . $rel );
		if ( false === $real_base || false === $real_file
			|| 0 !== strpos( $real_file, $real_base . DIRECTORY_SEPARATOR )
			|| ! is_file( $real_file ) ) {
			$this->serve_404();
		}

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		$body = $wp_filesystem ? (string) $wp_filesystem->get_contents( $real_file ) : '';

		nocache_headers();
		header( 'Content-Type: text/markdown; charset=UTF-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw .md file body served as text/markdown, not HTML.
		echo $body;
		exit;
	}

	/**
	 * Send a 404 for an endpoint request and stop.
	 */
	private function serve_404() {
		status_header( 404 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=UTF-8' );
		echo 'Not found.';
		exit;
	}

	/**
	 * The public base URL of the read endpoint.
	 *
	 * @return string
	 */
	public function endpoint_base_url() {
		return home_url( '/okf/' );
	}

	// ---------------------------------------------------------------------
	// Regeneration
	// ---------------------------------------------------------------------

	/**
	 * Content the bundle reflects changed: schedule a single, debounced
	 * background rebuild rather than blocking the request. A full rebuild
	 * keeps the cross-link graph internally consistent.
	 */
	public function on_content_changed() {
		if ( ! $this->is_enabled() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK );
		}
	}

	/**
	 * Cron callback: rebuild the bundle and record the summary.
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
	private function rebuild_and_record() {
		$result = $this->generator->rebuild();
		if ( ! is_wp_error( $result ) ) {
			update_option( self::BUILD_OPTION, $result, false );
		}
		return $result;
	}

	/**
	 * Last successful build summary, or null.
	 *
	 * @return array|null
	 */
	public function last_build() {
		$build = get_option( self::BUILD_OPTION, null );
		return is_array( $build ) ? $build : null;
	}

	// ---------------------------------------------------------------------
	// Admin-post action handlers
	// ---------------------------------------------------------------------

	/**
	 * Save the feature settings (enable + endpoint), flushing rewrite rules on
	 * any state change and scheduling an initial build on enable.
	 */
	public function handle_save() {
		$this->guard( 'coywolf_seo_okf_save' );

		$was_enabled  = $this->is_enabled();
		$was_endpoint = $this->endpoint_enabled();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in guard(); the only two values read are cast to booleans below.
		$raw      = isset( $_POST['coywolf_seo_okf'] ) && is_array( $_POST['coywolf_seo_okf'] ) ? wp_unslash( $_POST['coywolf_seo_okf'] ) : array();
		$enabled  = ! empty( $raw['enabled'] );
		$endpoint = ! empty( $raw['live_endpoint'] );

		update_option(
			self::OPTION,
			array(
				'enabled'       => $enabled,
				'live_endpoint' => $endpoint,
			)
		);

		// Rewrite rules change with the endpoint's effective availability.
		$effective_before = $was_enabled && $was_endpoint;
		$effective_after  = $enabled && $endpoint;
		if ( $effective_before !== $effective_after ) {
			// Register the rules for this request before flushing so an enable
			// persists them (init already ran by admin-post time).
			if ( $effective_after ) {
				$this->add_rewrite_rules();
			}
			flush_rewrite_rules();
		}

		$msg = 'okf-saved';
		if ( $enabled && ! $was_enabled ) {
			// First enable: kick off an initial build in the background.
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_single_event( time() + 5, self::CRON_HOOK );
			}
			$msg = 'okf-enabled';
		} elseif ( ! $enabled && $was_enabled ) {
			// Disabled: stop the background rebuild; files are left for the
			// owner to clean up explicitly (surfaced on the panel).
			wp_unschedule_hook( self::CRON_HOOK );
			$msg = 'okf-disabled';
		}

		$this->redirect( $msg );
	}

	/**
	 * Rebuild the bundle now (synchronous, on explicit request).
	 */
	public function handle_rebuild() {
		$this->guard( 'coywolf_seo_okf_rebuild' );
		if ( ! $this->is_enabled() ) {
			$this->redirect( 'okf-disabled' );
		}
		$result = $this->rebuild_and_record();
		$this->redirect( is_wp_error( $result ) ? 'okf-error' : 'okf-rebuilt' );
	}

	/**
	 * Stream the current bundle as a downloadable zip.
	 */
	public function handle_download() {
		$this->guard( 'coywolf_seo_okf_download' );
		if ( ! $this->generator->bundle_exists() ) {
			$this->redirect( 'okf-nobundle' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = wp_tempnam( 'coywolf-okf.zip' );
		$res = $this->generator->build_zip( $tmp );
		if ( is_wp_error( $res ) ) {
			wp_delete_file( $tmp );
			$this->redirect( 'okf-error' );
		}

		$site = sanitize_title( get_bloginfo( 'name' ) );
		$name = 'okf-bundle' . ( $site ? '-' . $site : '' ) . '.zip';

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
	 * Delete the generated bundle from disk (explicit cleanup).
	 */
	public function handle_cleanup() {
		$this->guard( 'coywolf_seo_okf_cleanup' );
		$ok = $this->generator->cleanup();
		delete_option( self::BUILD_OPTION );
		$this->redirect( $ok ? 'okf-cleaned' : 'okf-error' );
	}

	/**
	 * Capability + nonce guard shared by every action handler.
	 *
	 * @param string $nonce_action Nonce action name.
	 * @return void
	 */
	private function guard( $nonce_action ) {
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
	private function redirect( $msg ) {
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

	// ---------------------------------------------------------------------
	// Labs panel
	// ---------------------------------------------------------------------

	/**
	 * Status messages this feature can surface on the Labs page.
	 *
	 * @return array key => array( type, text )
	 */
	public function notices() {
		return array(
			'okf-saved'    => array( 'success', __( 'OKF settings saved.', 'coywolf-seo' ) ),
			'okf-enabled'  => array( 'success', __( 'OKF export enabled. The bundle is generating in the background.', 'coywolf-seo' ) ),
			'okf-disabled' => array( 'success', __( 'OKF export disabled. The endpoint no longer responds; any generated files remain until you clean them up below.', 'coywolf-seo' ) ),
			'okf-rebuilt'  => array( 'success', __( 'OKF bundle rebuilt.', 'coywolf-seo' ) ),
			'okf-cleaned'  => array( 'success', __( 'Generated OKF files were removed.', 'coywolf-seo' ) ),
			'okf-nobundle' => array( 'error', __( 'There is no generated bundle yet. Rebuild it first.', 'coywolf-seo' ) ),
			'okf-error'    => array( 'error', __( 'The OKF action could not be completed. Check that the uploads directory is writable.', 'coywolf-seo' ) ),
		);
	}

	/**
	 * Render this feature's panel on the Labs page.
	 */
	public function render_panel() {
		$s          = $this->settings();
		$enabled    = ! empty( $s['enabled'] );
		$endpoint   = ! empty( $s['live_endpoint'] );
		$has_bundle = $this->generator->bundle_exists();
		$last       = $this->last_build();
		$zip_ok     = class_exists( 'ZipArchive' );
		?>
		<div class="coywolf-seo-labs-feature card" style="max-width:48rem;padding:1rem 1.25rem;margin-top:1rem;">
			<h2><?php echo esc_html( $this->title() ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Generates an Open Knowledge Format (OKF v0.1) bundle of your public content — a navigable graph of Markdown concepts for articles, topics, authors, and the AI-enriched entities your pages are about or mention. Built entirely from data already on your site; no external calls.', 'coywolf-seo' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="coywolf_seo_okf_save" />
				<?php wp_nonce_field( 'coywolf_seo_okf_save' ); ?>
				<p>
					<label>
						<input type="checkbox" name="coywolf_seo_okf[enabled]" value="1" <?php checked( $enabled ); ?> />
						<?php esc_html_e( 'Enable Open Knowledge Format (OKF) export', 'coywolf-seo' ); ?>
					</label>
				</p>
				<p style="margin-left:1.75rem;">
					<label>
						<input type="checkbox" name="coywolf_seo_okf[live_endpoint]" value="1" <?php checked( $endpoint ); ?> />
						<?php esc_html_e( 'Serve the bundle live at a /okf/ read endpoint (so agents can traverse it without downloading)', 'coywolf-seo' ); ?>
					</label>
				</p>
				<?php submit_button( __( 'Save OKF settings', 'coywolf-seo' ) ); ?>
			</form>

			<?php if ( $enabled ) : ?>
				<hr />
				<h3><?php esc_html_e( 'Bundle', 'coywolf-seo' ); ?></h3>
				<?php if ( $last && isset( $last['counts'] ) ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: 1: articles count, 2: topics, 3: authors, 4: entities, 5: build time */
							esc_html__( 'Last build: %1$d articles/pages, %2$d topics, %3$d authors, %4$d entities (%5$s).', 'coywolf-seo' ),
							(int) $last['counts']['posts'],
							(int) $last['counts']['topics'],
							(int) $last['counts']['authors'],
							(int) $last['counts']['entities'],
							esc_html( isset( $last['time'] ) ? (string) $last['time'] : '' )
						);
						?>
					</p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'No bundle has been generated yet.', 'coywolf-seo' ); ?></p>
				<?php endif; ?>

				<?php if ( $endpoint ) : ?>
					<p class="description">
						<?php esc_html_e( 'Read endpoint:', 'coywolf-seo' ); ?>
						<a href="<?php echo esc_url( $this->endpoint_base_url() ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( $this->endpoint_base_url() ); ?></code></a>
					</p>
				<?php endif; ?>

				<p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:.5rem;">
						<input type="hidden" name="action" value="coywolf_seo_okf_rebuild" />
						<?php wp_nonce_field( 'coywolf_seo_okf_rebuild' ); ?>
						<?php submit_button( __( 'Rebuild bundle now', 'coywolf-seo' ), 'secondary', 'submit', false ); ?>
					</form>

					<?php if ( $has_bundle && $zip_ok ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:.5rem;">
							<input type="hidden" name="action" value="coywolf_seo_okf_download" />
							<?php wp_nonce_field( 'coywolf_seo_okf_download' ); ?>
							<?php submit_button( __( 'Download bundle (.zip)', 'coywolf-seo' ), 'secondary', 'submit', false ); ?>
						</form>
					<?php elseif ( $has_bundle && ! $zip_ok ) : ?>
						<span class="description"><?php esc_html_e( '(The PHP Zip extension is unavailable, so downloads are disabled on this server.)', 'coywolf-seo' ); ?></span>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<?php if ( $has_bundle ) : ?>
				<hr />
				<h3><?php esc_html_e( 'Generated files', 'coywolf-seo' ); ?></h3>
				<p class="description">
					<?php
					if ( ! $enabled ) {
						esc_html_e( 'OKF is disabled but previously generated files are still on disk. You can remove them now.', 'coywolf-seo' );
					} else {
						esc_html_e( 'Remove every file this feature has written to the uploads directory.', 'coywolf-seo' );
					}
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete all generated OKF files? This cannot be undone.', 'coywolf-seo' ) ); ?>');">
					<input type="hidden" name="action" value="coywolf_seo_okf_cleanup" />
					<?php wp_nonce_field( 'coywolf_seo_okf_cleanup' ); ?>
					<?php submit_button( __( 'Clean up generated files', 'coywolf-seo' ), 'delete', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
