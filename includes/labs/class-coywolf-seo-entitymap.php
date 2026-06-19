<?php
/**
 * EntityMap export — a self-contained Labs feature.
 *
 * Publishes a spec-conformant EntityMap v1.0 (https://entitymap.org/spec/v1.0)
 * file set — entitymap.json plus a human/crawler-readable entitymap.html — of
 * the Wikidata-grounded entities the site's pages are about or mention, with
 * extractive, publisher-attributed evidence passages. Built entirely from data
 * already stored on the site; no new external calls.
 *
 * Owns everything for the feature: its opt-in toggle and settings, the Labs
 * page panel, the rebuild / download / cleanup actions, the public root
 * endpoints, and the debounced background regeneration. Disabled by default —
 * nothing is generated, written, or served until the site owner enables it.
 *
 * Implements the Labs feature contract: id(), title(), init(), render_panel().
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The EntityMap Labs feature.
 */
final class Coywolf_SEO_EntityMap {

	/**
	 * Option holding this feature's settings.
	 */
	const OPTION = 'coywolf_seo_entitymap';

	/**
	 * Option holding the last successful build summary (time + counts).
	 */
	const BUILD_OPTION = 'coywolf_seo_entitymap_build';

	/**
	 * Public read-endpoint query var.
	 */
	const QUERY_VAR = 'coywolf_seo_entitymap';

	/**
	 * Debounced background-rebuild cron hook.
	 */
	const CRON_HOOK = 'coywolf_seo_entitymap_rebuild';

	/**
	 * Capability required to manage the feature (matches Settings).
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * File-set generator.
	 *
	 * @var Coywolf_SEO_EntityMap_Generator
	 */
	private $generator;

	/**
	 * Discovery / advertising sub-feature.
	 *
	 * @var Coywolf_SEO_EntityMap_Advertiser
	 */
	private $advertiser;

	/**
	 * Construct with the generator and the advertiser.
	 */
	public function __construct() {
		$this->generator  = new Coywolf_SEO_EntityMap_Generator();
		$this->advertiser = new Coywolf_SEO_EntityMap_Advertiser( $this );
	}

	/**
	 * Feature id (used by the Labs registry / nonces).
	 *
	 * @return string
	 */
	public function id() {
		return 'entitymap';
	}

	/**
	 * Human title for the Labs panel.
	 *
	 * @return string
	 */
	public function title() {
		return __( 'EntityMap', 'coywolf-seo' );
	}

	/**
	 * Register hooks. Runtime hooks (endpoints, regeneration) are gated on the
	 * opt-in toggle; the admin-post handlers and cron hook register
	 * unconditionally (they only fire on their own requests and re-check the
	 * capability / enabled state).
	 */
	public function init() {
		add_action( 'admin_post_coywolf_seo_entitymap_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_coywolf_seo_entitymap_rebuild', array( $this, 'handle_rebuild' ) );
		add_action( 'admin_post_coywolf_seo_entitymap_download', array( $this, 'handle_download' ) );
		add_action( 'admin_post_coywolf_seo_entitymap_cleanup', array( $this, 'handle_cleanup' ) );

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

		// Discovery / advertising (self-gates on advertise + endpoint + public).
		$this->advertiser->init();

		// Debounced regeneration when content the file set reflects changes.
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
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return wp_parse_args(
			$saved,
			array(
				'enabled'       => false,
				'live_endpoint' => true,
				// Advertising defaults on once EntityMap is enabled (see handle_save).
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
	 * Whether the public root endpoints are enabled.
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
	 * Whether the EntityMap public advertising is actually active (enabled +
	 * advertise + endpoint + public site).
	 *
	 * @return bool
	 */
	public function advertise_active() {
		return $this->advertiser->advertise_active();
	}

	/**
	 * The llms.txt reference entry for the EntityMap (without the leading "- [").
	 *
	 * @return string
	 */
	public function llms_reference_line() {
		return $this->advertiser->llms_reference_line();
	}

	/**
	 * Generator accessor (used by the advertiser and the panel).
	 *
	 * @return Coywolf_SEO_EntityMap_Generator
	 */
	public function generator() {
		return $this->generator;
	}

	/**
	 * Advertiser accessor.
	 *
	 * @return Coywolf_SEO_EntityMap_Advertiser
	 */
	public function advertiser() {
		return $this->advertiser;
	}

	// ---------------------------------------------------------------------
	// Public read endpoints
	// ---------------------------------------------------------------------

	/**
	 * Register the root rewrite rules (entitymap.json + entitymap.html).
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule( '^entitymap\.json$', 'index.php?' . self::QUERY_VAR . '=json', 'top' );
		add_rewrite_rule( '^entitymap\.html$', 'index.php?' . self::QUERY_VAR . '=html', 'top' );
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
	 * Serve entitymap.json / entitymap.html from disk. The JSON may carry a
	 * noindex X-Robots-Tag; the HTML companion must NOT (it is meant to be
	 * indexable). Anything else (including path-traversal attempts) 404s.
	 */
	public function maybe_serve() {
		$req = (string) get_query_var( self::QUERY_VAR );
		if ( '' === $req ) {
			return;
		}
		if ( ! $this->is_enabled() || ! $this->endpoint_enabled() ) {
			return; // Let WordPress 404 normally.
		}

		if ( 'json' === $req ) {
			$file    = $this->generator->json_path();
			$ctype   = 'application/json; charset=UTF-8';
			$noindex = true;
		} elseif ( 'html' === $req ) {
			$file    = $this->generator->html_path();
			$ctype   = 'text/html; charset=UTF-8';
			$noindex = false;
		} else {
			$this->serve_404();
		}

		// Containment guard before reading (mirrors the OKF endpoint).
		$base      = $this->generator->bundle_path();
		$real_base = realpath( $base );
		$real_file = realpath( $file );
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

		header( 'Content-Type: ' . $ctype );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: public, max-age=3600' );
		if ( $noindex ) {
			header( 'X-Robots-Tag: noindex' );
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-generated, pre-escaped JSON/HTML file body served verbatim.
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

	// ---------------------------------------------------------------------
	// Regeneration
	// ---------------------------------------------------------------------

	/**
	 * Content the file set reflects changed: schedule a single, debounced
	 * background rebuild rather than blocking the request.
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
	 * Cron callback: rebuild the file set and record the summary.
	 */
	public function run_scheduled_rebuild() {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$this->rebuild_and_record();
	}

	/**
	 * Rebuild now and store the build summary; returns the generator result.
	 * After a successful rebuild, also refresh the llms.txt cache so the
	 * advertised section reflects the current state.
	 *
	 * @return array|WP_Error
	 */
	private function rebuild_and_record() {
		$result = $this->generator->rebuild();
		if ( ! is_wp_error( $result ) ) {
			update_option( self::BUILD_OPTION, $result, false );
			$this->invalidate_llms_cache();
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
	 * Save the feature settings (enable + endpoint + advertise), flushing rewrite
	 * rules on any route change, reconciling the managed robots rules, scheduling
	 * an initial build on first enable, and invalidating the llms.txt cache so
	 * the EntityMap section appears/disappears immediately.
	 */
	public function handle_save() {
		$this->guard( 'coywolf_seo_entitymap_save' );

		$was_enabled  = $this->is_enabled();
		$was_endpoint = $this->endpoint_enabled();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in guard(); only the boolean checkboxes below are read.
		$raw      = isset( $_POST['coywolf_seo_entitymap'] ) && is_array( $_POST['coywolf_seo_entitymap'] ) ? wp_unslash( $_POST['coywolf_seo_entitymap'] ) : array();
		$enabled  = ! empty( $raw['enabled'] );
		$endpoint = ! empty( $raw['live_endpoint'] );
		// The advertise checkbox is only shown once EntityMap is enabled, so on
		// the first enable honor the default-on rather than reading its absence
		// as "off"; otherwise take the submitted value.
		$advertise = ( $enabled && ! $was_enabled ) ? true : ! empty( $raw['advertise'] );

		update_option(
			self::OPTION,
			array(
				'enabled'       => $enabled,
				'live_endpoint' => $endpoint,
				'advertise'     => $advertise,
			)
		);

		// Rewrite rules change only with the serving endpoint's availability (the
		// advertiser adds no routes of its own). Flush when that changes.
		$endpoint_before = $was_enabled && $was_endpoint;
		$endpoint_after  = $enabled && $endpoint;
		if ( $endpoint_before !== $endpoint_after ) {
			if ( $endpoint_after ) {
				$this->add_rewrite_rules();
			}
			flush_rewrite_rules();
		}

		// Reconcile the managed robots.txt Allow rules with the current state.
		if ( $this->advertiser->is_configured() ) {
			$this->advertiser->add_robots_rule();
		} else {
			$this->advertiser->remove_robots_rule();
		}

		// The advertised llms.txt section toggles with this save.
		$this->invalidate_llms_cache();

		$msg = 'entitymap-saved';
		if ( $enabled && ! $was_enabled ) {
			// First enable: kick off an initial build in the background.
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_single_event( time() + 5, self::CRON_HOOK );
			}
			$msg = 'entitymap-enabled';
		} elseif ( ! $enabled && $was_enabled ) {
			// Disabled: stop the background rebuild; files remain until cleanup.
			wp_unschedule_hook( self::CRON_HOOK );
			$msg = 'entitymap-disabled';
		}

		$this->redirect( $msg );
	}

	/**
	 * Rebuild the file set now (synchronous, on explicit request).
	 */
	public function handle_rebuild() {
		$this->guard( 'coywolf_seo_entitymap_rebuild' );
		if ( ! $this->is_enabled() ) {
			$this->redirect( 'entitymap-disabled' );
		}
		$result = $this->rebuild_and_record();
		$this->redirect( is_wp_error( $result ) ? 'entitymap-error' : 'entitymap-rebuilt' );
	}

	/**
	 * Stream the current file set as a downloadable zip (json + html).
	 */
	public function handle_download() {
		$this->guard( 'coywolf_seo_entitymap_download' );
		if ( ! $this->generator->bundle_exists() ) {
			$this->redirect( 'entitymap-nobundle' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = wp_tempnam( 'coywolf-entitymap.zip' );
		$res = $this->generator->build_zip( $tmp );
		if ( is_wp_error( $res ) ) {
			wp_delete_file( $tmp );
			$this->redirect( 'entitymap-error' );
		}

		$site = sanitize_title( get_bloginfo( 'name' ) );
		$name = 'entitymap' . ( $site ? '-' . $site : '' ) . '.zip';

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
	 * Delete the generated files from disk (explicit cleanup).
	 */
	public function handle_cleanup() {
		$this->guard( 'coywolf_seo_entitymap_cleanup' );
		$ok = $this->generator->cleanup();
		delete_option( self::BUILD_OPTION );
		$this->redirect( $ok ? 'entitymap-cleaned' : 'entitymap-error' );
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

	/**
	 * Invalidate the llms.txt cache (guarded) so the EntityMap section appears /
	 * disappears immediately after a save or rebuild.
	 *
	 * @return void
	 */
	private function invalidate_llms_cache() {
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
	// Labs panel
	// ---------------------------------------------------------------------

	/**
	 * Status messages this feature can surface on the Labs page.
	 *
	 * @return array key => array( type, text )
	 */
	public function notices() {
		return array(
			'entitymap-saved'    => array( 'success', __( 'EntityMap settings saved.', 'coywolf-seo' ) ),
			'entitymap-enabled'  => array( 'success', __( 'EntityMap enabled. The files are generating in the background.', 'coywolf-seo' ) ),
			'entitymap-disabled' => array( 'success', __( 'EntityMap disabled. The endpoints no longer respond; any generated files remain until you clean them up below.', 'coywolf-seo' ) ),
			'entitymap-rebuilt'  => array( 'success', __( 'EntityMap files rebuilt.', 'coywolf-seo' ) ),
			'entitymap-cleaned'  => array( 'success', __( 'Generated EntityMap files were removed.', 'coywolf-seo' ) ),
			'entitymap-nobundle' => array( 'error', __( 'There are no generated files yet. Rebuild them first.', 'coywolf-seo' ) ),
			'entitymap-error'    => array( 'error', __( 'The EntityMap action could not be completed. Check that the uploads directory is writable.', 'coywolf-seo' ) ),
		);
	}

	/**
	 * Render this feature's panel on the Labs page.
	 */
	public function render_panel() {
		$s         = $this->settings();
		$enabled   = ! empty( $s['enabled'] );
		$endpoint  = ! empty( $s['live_endpoint'] );
		$advertise = ! empty( $s['advertise'] );
		$has_files = $this->generator->bundle_exists();
		$last      = $this->last_build();
		$zip_ok    = class_exists( 'ZipArchive' );
		$json_url  = $this->generator->public_json_url();
		$html_url  = $this->generator->public_html_url();
		?>
		<div class="coywolf-seo-labs-feature coywolf-seo-panel">
			<h2><?php echo esc_html( $this->title() ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Publishes an EntityMap v1.0 entity index (entitymap.json + a human/crawler-readable entitymap.html) of the Wikidata-grounded entities your pages are about or mention, with extractive, publisher-attributed evidence passages. Built entirely from data already on your site; no new external calls.', 'coywolf-seo' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="coywolf_seo_entitymap_save" />
				<?php wp_nonce_field( 'coywolf_seo_entitymap_save' ); ?>
				<p>
					<label>
						<input type="checkbox" name="coywolf_seo_entitymap[enabled]" value="1" <?php checked( $enabled ); ?> />
						<?php esc_html_e( 'Enable EntityMap', 'coywolf-seo' ); ?>
					</label>
				</p>
				<p style="margin-left:1.75rem;">
					<label>
						<input type="checkbox" name="coywolf_seo_entitymap[live_endpoint]" value="1" <?php checked( $endpoint ); ?> />
						<?php esc_html_e( 'Serve the files live at the domain root (entitymap.json + entitymap.html) so agents can read them directly', 'coywolf-seo' ); ?>
					</label>
				</p>
				<?php if ( $enabled ) : ?>
					<p style="margin-left:1.75rem;">
						<label>
							<input type="checkbox" name="coywolf_seo_entitymap[advertise]" value="1" <?php checked( $advertise ); ?> />
							<?php esc_html_e( 'Advertise the EntityMap publicly (point AI agents at it via llms.txt, a page <head> link, and a robots.txt allowance)', 'coywolf-seo' ); ?>
						</label>
					</p>
				<?php endif; ?>
				<?php submit_button( __( 'Save EntityMap settings', 'coywolf-seo' ) ); ?>
			</form>

			<?php if ( $enabled ) : ?>
				<hr />
				<h3><?php esc_html_e( 'Files', 'coywolf-seo' ); ?></h3>
				<?php if ( $last && isset( $last['counts'] ) ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: 1: entity count, 2: chunk count, 3: build time */
							esc_html__( 'Last build: %1$d entities, %2$d evidence passages (%3$s).', 'coywolf-seo' ),
							(int) $last['counts']['entities'],
							(int) $last['counts']['chunks'],
							esc_html( isset( $last['time'] ) ? (string) $last['time'] : '' )
						);
						?>
					</p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'No files have been generated yet.', 'coywolf-seo' ); ?></p>
				<?php endif; ?>

				<?php if ( $endpoint ) : ?>
					<p class="description">
						<?php esc_html_e( 'JSON:', 'coywolf-seo' ); ?>
						<a href="<?php echo esc_url( $json_url ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( $json_url ); ?></code></a>
						&nbsp;·&nbsp;
						<?php esc_html_e( 'HTML:', 'coywolf-seo' ); ?>
						<a href="<?php echo esc_url( $html_url ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( $html_url ); ?></code></a>
					</p>
				<?php endif; ?>

				<?php
				if ( $advertise ) {
					$this->advertiser->render_status();
				}
				?>

				<p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:.5rem;">
						<input type="hidden" name="action" value="coywolf_seo_entitymap_rebuild" />
						<?php wp_nonce_field( 'coywolf_seo_entitymap_rebuild' ); ?>
						<?php submit_button( __( 'Rebuild files now', 'coywolf-seo' ), 'secondary', 'submit', false ); ?>
					</form>

					<?php if ( $has_files && $zip_ok ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:.5rem;">
							<input type="hidden" name="action" value="coywolf_seo_entitymap_download" />
							<?php wp_nonce_field( 'coywolf_seo_entitymap_download' ); ?>
							<?php submit_button( __( 'Download files (.zip)', 'coywolf-seo' ), 'secondary', 'submit', false ); ?>
						</form>
					<?php elseif ( $has_files && ! $zip_ok ) : ?>
						<span class="description"><?php esc_html_e( '(The PHP Zip extension is unavailable, so downloads are disabled on this server.)', 'coywolf-seo' ); ?></span>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<?php if ( $has_files ) : ?>
				<hr />
				<h3><?php esc_html_e( 'Generated files', 'coywolf-seo' ); ?></h3>
				<p class="description">
					<?php
					if ( ! $enabled ) {
						esc_html_e( 'EntityMap is disabled but previously generated files are still on disk. You can remove them now.', 'coywolf-seo' );
					} else {
						esc_html_e( 'Remove every file this feature has written to the uploads directory.', 'coywolf-seo' );
					}
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete all generated EntityMap files? This cannot be undone.', 'coywolf-seo' ) ); ?>');">
					<input type="hidden" name="action" value="coywolf_seo_entitymap_cleanup" />
					<?php wp_nonce_field( 'coywolf_seo_entitymap_cleanup' ); ?>
					<?php submit_button( __( 'Clean up generated files', 'coywolf-seo' ), 'delete', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
