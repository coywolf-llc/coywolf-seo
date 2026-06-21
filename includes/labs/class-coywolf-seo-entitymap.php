<?php
/**
 * EntityMap export — a self-contained Labs feature.
 *
 * Publishes a spec-conformant EntityMap v1.0 (https://entitymap.org/spec/v1.0)
 * file set — entitymap.json plus a human/crawler-readable entitymap.html — of
 * the Wikidata-grounded entities the site's pages are about or mention, with
 * extractive, publisher-attributed evidence passages. Built entirely from data
 * already stored on the site; no new external calls. Disabled by default.
 *
 * The controller skeleton lives in Coywolf_SEO_Labs_Feature; this class supplies
 * the EntityMap-specific parts: the root entitymap.json/.html routes + serving
 * (noindex JSON, indexable HTML), and the llms.txt cache invalidation on save /
 * rebuild so the advertised section toggles immediately.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The EntityMap Labs feature.
 */
final class Coywolf_SEO_EntityMap extends Coywolf_SEO_Labs_Feature {

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
	 * Construct with the generator and the advertiser.
	 */
	public function __construct() {
		$this->generator  = new Coywolf_SEO_EntityMap_Generator();
		$this->advertiser = new Coywolf_SEO_EntityMap_Advertiser( $this );
	}

	/**
	 * Feature id (used by the Labs registry / nonces / message keys).
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

	// ---------------------------------------------------------------------
	// Regeneration
	// ---------------------------------------------------------------------

	/**
	 * Rebuild the file set.
	 *
	 * @return array|WP_Error
	 */
	protected function do_rebuild() {
		return $this->generator->rebuild();
	}

	/**
	 * After a successful rebuild, refresh the llms.txt cache so the advertised
	 * section reflects the current state.
	 *
	 * @return void
	 */
	protected function on_built() {
		$this->invalidate_llms_cache();
	}

	/**
	 * The advertised llms.txt section toggles with a settings save.
	 *
	 * @return void
	 */
	protected function on_settings_saved() {
		$this->invalidate_llms_cache();
	}

	/**
	 * Base filename for the zip download.
	 *
	 * @return string
	 */
	protected function download_basename() {
		return 'entitymap';
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
