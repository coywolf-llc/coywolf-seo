<?php
/**
 * Open Knowledge Format (OKF) export — a self-contained Labs feature.
 *
 * Generates an OKF v0.2 bundle of public content (a navigable Markdown graph of
 * articles, topics, authors, and the AI-enriched entities pages are about or
 * mention), serves it live at a /okf/ read endpoint, and advertises it. Disabled
 * by default — nothing is generated, written, or served until the site owner
 * enables it on the Labs page.
 *
 * The controller skeleton (settings, the four admin-post handlers, the cron
 * rebuild, the guard/redirect helpers) lives in Coywolf_SEO_Labs_Feature; this
 * class supplies only the OKF-specific parts: the /okf/ route + .md serving, the
 * absolute-cross-link rebuild, the /.well-known/okf advertiser route flush, and
 * the panel.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The OKF Labs feature.
 */
final class Coywolf_SEO_OKF extends Coywolf_SEO_Labs_Feature {

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
	 * Construct with the generator and the advertiser.
	 */
	public function __construct() {
		$this->generator  = new Coywolf_SEO_OKF_Generator();
		$this->advertiser = new Coywolf_SEO_OKF_Advertiser( $this );
	}

	/**
	 * Feature id (used by the Labs registry / nonces / message keys).
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
	 * Register hooks. The base wires the standard handlers (save/rebuild/download/
	 * cleanup); OKF additionally offers a gzipped-tarball download alongside the
	 * zip, so it registers that extra admin-post action.
	 */
	public function init() {
		parent::init();
		add_action( 'admin_post_coywolf_seo_okf_download_targz', array( $this, 'handle_download_targz' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade_rebuild' ) );
	}

	/**
	 * Re-emit the bundle after a plugin update that bumps the OKF spec version.
	 *
	 * A bundle already on disk keeps serving the previous version's files until
	 * content next changes or the admin rebuilds by hand — so a version bump
	 * alone would leave live sites serving the old format. When the feature is
	 * on, a bundle exists, and the recorded build predates the current
	 * OKF_VERSION (a pre-recording build has no version key at all), schedule
	 * the same debounced background rebuild the content-change path uses. Once
	 * it runs, the stored summary carries the new version and this stops firing.
	 */
	public function maybe_upgrade_rebuild() {
		if ( ! $this->is_enabled() || ! $this->generator->bundle_exists() ) {
			return;
		}
		$build = $this->last_build();
		$built = ( is_array( $build ) && isset( $build['okf_version'] ) ) ? (string) $build['okf_version'] : '';
		if ( Coywolf_SEO_OKF_Generator::OKF_VERSION === $built ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK );
		}
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
	 * The regex patterns add_rewrite_rules() registers, so a disable transition
	 * can drop them from the in-memory rule set before flushing.
	 *
	 * @return string[]
	 */
	protected function rewrite_patterns() {
		return array( '^okf/?$', '^okf/(.+)$' );
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
	 * Rebuild the bundle. Cross-links in the bundle are absolute against the
	 * served root URL, so the endpoint URL is passed through.
	 *
	 * @return array|WP_Error
	 */
	protected function do_rebuild() {
		return $this->generator->rebuild( $this->endpoint_base_url() );
	}

	// ---------------------------------------------------------------------
	// Save / download specifics
	// ---------------------------------------------------------------------

	/**
	 * Flush rewrite rules when the OKF endpoint OR the advertiser's routes
	 * (the /.well-known/okf alias) change availability — the advertiser registers
	 * its own route, unlike the EntityMap default.
	 *
	 * @param bool $was_enabled   Enabled before the save.
	 * @param bool $was_endpoint  Endpoint enabled before the save.
	 * @param bool $was_advertise Advertise enabled before the save.
	 * @param bool $enabled       Enabled after the save.
	 * @param bool $endpoint      Endpoint enabled after the save.
	 * @param bool $advertise     Advertise enabled after the save.
	 * @return void
	 */
	protected function flush_rewrite_on_change( $was_enabled, $was_endpoint, $was_advertise, $enabled, $endpoint, $advertise ) {
		$endpoint_before = $was_enabled && $was_endpoint;
		$endpoint_after  = $enabled && $endpoint;
		$adv_before      = $endpoint_before && $was_advertise;
		$adv_after       = $endpoint_after && $advertise;
		if ( $endpoint_before !== $endpoint_after || $adv_before !== $adv_after ) {
			// Register the rules for this request before flushing so an enable
			// persists them (init already ran by admin-post time); on a disable
			// transition remove the still-registered rules from the in-memory set
			// first, or the flush re-persists them and the routes keep serving.
			if ( $endpoint_after ) {
				$this->add_rewrite_rules();
			} elseif ( $endpoint_before ) {
				$this->unregister_rewrite_patterns( $this->rewrite_patterns() );
			}
			if ( $adv_after ) {
				$this->advertiser->add_rewrite_rules();
			} elseif ( $adv_before ) {
				$this->unregister_rewrite_patterns( $this->advertiser->rewrite_patterns() );
			}
			flush_rewrite_rules();
		}
	}

	/**
	 * Base filename for the zip download.
	 *
	 * @return string
	 */
	protected function download_basename() {
		return 'okf-bundle';
	}

	/**
	 * Stream the current bundle as a downloadable gzipped tarball (.tar.gz).
	 * Mirrors the base zip handler but uses the tar generator + a gzip mime type.
	 */
	public function handle_download_targz() {
		$this->guard( 'coywolf_seo_okf_download_targz' );
		if ( ! $this->generator->bundle_exists() ) {
			$this->redirect( 'okf-nobundle' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = wp_tempnam( 'coywolf-okf.tar.gz' );
		$res = $this->generator->build_tar_gz( $tmp );
		if ( is_wp_error( $res ) ) {
			wp_delete_file( $tmp );
			$this->redirect( 'okf-error' );
		}

		$site = sanitize_title( get_bloginfo( 'name' ) );
		$name = $this->download_basename() . ( $site ? '-' . $site : '' ) . '.tar.gz';

		nocache_headers();
		header( 'Content-Type: application/gzip' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . (string) filesize( $tmp ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a generated temp archive to the browser.
		readfile( $tmp );
		wp_delete_file( $tmp );
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
		$advertise  = ! empty( $s['advertise'] );
		$has_bundle = $this->generator->bundle_exists();
		$last       = $this->last_build();
		$zip_ok     = class_exists( 'ZipArchive' );
		?>
		<div class="coywolf-seo-labs-feature coywolf-seo-panel">
			<h2><?php echo esc_html( $this->title() ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %s: OKF spec version, e.g. 0.2 */
					esc_html__( 'Generates an Open Knowledge Format (OKF v%s) bundle of your public content — a navigable graph of Markdown concepts for articles, topics, authors, and the AI-enriched entities your pages are about or mention. Built entirely from data already on your site; no external calls.', 'coywolf-seo' ),
					esc_html( Coywolf_SEO_OKF_Generator::OKF_VERSION )
				);
				?>
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
				<?php if ( $enabled ) : ?>
					<p style="margin-left:1.75rem;">
						<label>
							<input type="checkbox" name="coywolf_seo_okf[advertise]" value="1" <?php checked( $advertise ); ?> />
							<?php esc_html_e( 'Advertise the bundle publicly (point AI agents at it via llms.txt, a page <head> link, and a robots.txt allowance)', 'coywolf-seo' ); ?>
						</label>
						<br />
						<span class="description" style="margin-left:1.75rem;display:inline-block;">
							<?php esc_html_e( 'OKF defines no automatic discovery, so this advertises the bundle in the places agents already look — it does not guarantee anything will consume it. Turn it off to keep a bundle for out-of-band (git/tarball) handoff without publishing any hints.', 'coywolf-seo' ); ?>
						</span>
					</p>
				<?php endif; ?>
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

				<?php
				if ( $advertise ) {
					$this->advertiser->render_status();
				}
				?>

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

					<?php if ( $has_bundle && class_exists( 'PharData' ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:.5rem;">
							<input type="hidden" name="action" value="coywolf_seo_okf_download_targz" />
							<?php wp_nonce_field( 'coywolf_seo_okf_download_targz' ); ?>
							<?php submit_button( __( 'Download bundle (.tar.gz)', 'coywolf-seo' ), 'secondary', 'submit', false ); ?>
						</form>
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
