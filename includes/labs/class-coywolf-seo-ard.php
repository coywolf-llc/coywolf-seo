<?php
/**
 * Agentic Resource Discovery (ARD) — a self-contained Labs feature.
 *
 * Publishes an ARD `ai-catalog.json` manifest (spec at
 * https://agenticresourcediscovery.org/) at /.well-known/ai-catalog.json — a
 * catalog of this site's machine-usable resources (the WP REST API, the
 * plugin's own agent-readable OKF / EntityMap / llms.txt outputs when enabled,
 * an optional MCP server card, and any custom entries) so AI clients and
 * registries can discover what the site offers. Disabled by default.
 *
 * Unlike OKF/EntityMap this writes NO file to disk: the manifest is a single
 * small JSON document served dynamically from a rewrite route, which keeps the
 * site root clean and is both WordPress.org-safe and spec-compatible. The
 * controller skeleton lives in Coywolf_SEO_Labs_Feature; this class supplies the
 * well-known route + JSON serving (with the spec's CORS header), the resource
 * configuration form, and the panel. ARD is a capability-discovery manifest, not
 * an llms.txt content channel, so it is intentionally NOT folded into llms.txt.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ARD Labs feature.
 */
final class Coywolf_SEO_ARD extends Coywolf_SEO_Labs_Feature {

	/**
	 * Option holding this feature's enable/endpoint/advertise settings.
	 */
	const OPTION = 'coywolf_seo_ard';

	/**
	 * Option holding the last successful build summary (time + counts).
	 */
	const BUILD_OPTION = 'coywolf_seo_ard_build';

	/**
	 * Public read-endpoint query var.
	 */
	const QUERY_VAR = 'coywolf_seo_ard';

	/**
	 * Debounced background-rebuild cron hook.
	 */
	const CRON_HOOK = 'coywolf_seo_ard_rebuild';

	/**
	 * Option holding the host + sources + custom-entries configuration (separate
	 * from the base enable/endpoint/advertise option so the base save handler,
	 * which rewrites that option wholesale, never drops it).
	 */
	const CONFIG_OPTION = 'coywolf_seo_ard_config';

	/**
	 * Prefix for the short-lived per-user transient that preserves the raw
	 * custom-entries JSON draft across an invalid-JSON redirect (the current user
	 * id is appended).
	 */
	const ENTRIES_DRAFT_TRANSIENT = 'coywolf_seo_ard_entries_draft_';

	/**
	 * Construct with the generator and the advertiser.
	 */
	public function __construct() {
		$this->generator  = new Coywolf_SEO_ARD_Generator( $this );
		$this->advertiser = new Coywolf_SEO_ARD_Advertiser( $this );
	}

	/**
	 * Feature id (used by the Labs registry / nonces / message keys).
	 *
	 * @return string
	 */
	public function id() {
		return 'ard';
	}

	/**
	 * Human title for the Labs panel.
	 *
	 * @return string
	 */
	public function title() {
		return __( 'Agentic Resource Discovery (ARD)', 'coywolf-seo' );
	}

	/**
	 * Register hooks. The base wires the standard handlers; ARD adds the resource
	 * configuration form handler.
	 */
	public function init() {
		parent::init();
		add_action( 'admin_post_coywolf_seo_ard_config', array( $this, 'handle_save_config' ) );
	}

	// ---------------------------------------------------------------------
	// Configuration (host + sources + custom entries)
	// ---------------------------------------------------------------------

	/**
	 * The ARD resource configuration merged over defaults.
	 *
	 * @return array
	 */
	public function config() {
		$saved = get_option( self::CONFIG_OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return wp_parse_args(
			$saved,
			array(
				'host_name'         => '',
				'doc_url'           => '',
				'logo_url'          => '',
				'include_rest'      => true,
				'include_okf'       => true,
				'include_entitymap' => true,
				'include_llms'      => true,
				'mcp_url'           => '',
				'entries'           => array(),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Public read endpoint
	// ---------------------------------------------------------------------

	/**
	 * Register the well-known manifest rewrite.
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule( '^\.well-known/ai-catalog\.json$', 'index.php?' . self::QUERY_VAR . '=catalog', 'top' );
	}

	/**
	 * The regex patterns add_rewrite_rules() registers, so a disable transition
	 * can drop them from the in-memory rule set before flushing.
	 *
	 * @return string[]
	 */
	protected function rewrite_patterns() {
		return array( '^\.well-known/ai-catalog\.json$' );
	}

	/**
	 * Serve ai-catalog.json. Sends the spec-required CORS + JSON headers so
	 * browser-based agents can fetch it. Anything else 404s.
	 */
	public function maybe_serve() {
		$req = (string) get_query_var( self::QUERY_VAR );
		if ( '' === $req ) {
			return;
		}
		if ( ! $this->is_enabled() || ! $this->endpoint_enabled() ) {
			return; // Let WordPress 404 normally.
		}
		if ( 'catalog' !== $req ) {
			$this->serve_404();
		}

		$json = $this->generator->catalog_json();
		if ( '' === $json ) {
			$this->serve_404();
		}

		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'X-Robots-Tag: noindex' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-generated, validated JSON manifest served verbatim.
		echo $json;
		exit;
	}

	/**
	 * The canonical manifest URL.
	 *
	 * @return string
	 */
	public function catalog_url() {
		return home_url( '/.well-known/ai-catalog.json' );
	}

	// ---------------------------------------------------------------------
	// Regeneration
	// ---------------------------------------------------------------------

	/**
	 * Rebuild the manifest.
	 *
	 * @return array|WP_Error
	 */
	protected function do_rebuild() {
		return $this->generator->rebuild();
	}

	/**
	 * Base filename for a download (the manifest streams as ai-catalog.json via
	 * the overridden handle_download; this satisfies the abstract contract).
	 *
	 * @return string
	 */
	protected function download_basename() {
		return 'ai-catalog';
	}

	// ---------------------------------------------------------------------
	// Admin-post: configuration form
	// ---------------------------------------------------------------------

	/**
	 * Save the host + sources + custom-entries configuration, then refresh the
	 * stored manifest so the served catalog reflects it immediately. Custom
	 * entries are validated as a JSON array up front; an invalid array aborts the
	 * save so nothing is lost silently.
	 */
	public function handle_save_config() {
		$this->guard( 'coywolf_seo_ard_config' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in guard(); each field is individually sanitized below.
		$post = wp_unslash( $_POST );

		// Custom entries: validate the JSON before touching anything.
		$entries_json = isset( $post['entries_json'] ) ? trim( (string) $post['entries_json'] ) : '';
		$entries      = array();
		if ( '' !== $entries_json ) {
			$decoded = json_decode( $entries_json, true );
			if ( ! is_array( $decoded ) ) {
				// Preserve the user's typed draft so they can fix it rather than
				// retype the whole array (render_panel reloads it below), and land
				// them at the field.
				set_transient( self::ENTRIES_DRAFT_TRANSIENT . get_current_user_id(), $entries_json, 5 * MINUTE_IN_SECONDS );
				$this->redirect_to_entries( 'ard-config-error' );
			}
			foreach ( $decoded as $e ) {
				$clean = $this->sanitize_entry( is_array( $e ) ? $e : array() );
				if ( null !== $clean ) {
					$entries[] = $clean;
				}
			}
		}

		$config = array(
			'host_name'         => isset( $post['host_name'] ) ? sanitize_text_field( (string) $post['host_name'] ) : '',
			'doc_url'           => isset( $post['doc_url'] ) ? esc_url_raw( (string) $post['doc_url'] ) : '',
			'logo_url'          => isset( $post['logo_url'] ) ? esc_url_raw( (string) $post['logo_url'] ) : '',
			'include_rest'      => ! empty( $post['include_rest'] ),
			'include_okf'       => ! empty( $post['include_okf'] ),
			'include_entitymap' => ! empty( $post['include_entitymap'] ),
			'include_llms'      => ! empty( $post['include_llms'] ),
			'mcp_url'           => isset( $post['mcp_url'] ) ? esc_url_raw( (string) $post['mcp_url'] ) : '',
			'entries'           => $entries,
		);

		update_option( self::CONFIG_OPTION, $config );

		// Refresh the served manifest so the change takes effect immediately.
		if ( $this->is_enabled() ) {
			$this->rebuild_and_record();
		}

		$this->redirect( 'ard-config-saved' );
	}

	/**
	 * Redirect back to the Labs page with a status message key AND a fragment that
	 * lands the browser on the custom-entries field, so a keyboard/screen-reader
	 * user is taken straight to the input to fix rather than hunting a notice at
	 * the top of the multi-panel page.
	 *
	 * @param string $msg Message key read by the Labs page notice.
	 * @return void
	 */
	private function redirect_to_entries( $msg ) {
		$url = add_query_arg(
			array(
				'page'                 => Coywolf_SEO_Labs::SLUG,
				'coywolf-seo-labs-msg' => $msg,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url . '#coywolf-seo-ard-entries' );
		exit;
	}

	/**
	 * Sanitize one custom entry from the JSON textarea, or null if it lacks the
	 * required displayName / type / url.
	 *
	 * @param array $e Raw entry.
	 * @return array|null
	 */
	private function sanitize_entry( array $e ) {
		$display = isset( $e['displayName'] ) ? sanitize_text_field( (string) $e['displayName'] ) : '';
		$type    = isset( $e['type'] ) ? sanitize_text_field( (string) $e['type'] ) : '';
		$url     = isset( $e['url'] ) ? esc_url_raw( (string) $e['url'] ) : '';
		if ( '' === $display || '' === $type || '' === $url ) {
			return null;
		}
		$clean = array(
			'displayName' => $display,
			'type'        => $type,
			'url'         => $url,
		);
		if ( ! empty( $e['name'] ) ) {
			$clean['name'] = sanitize_title( (string) $e['name'] );
		}
		if ( ! empty( $e['description'] ) ) {
			$clean['description'] = sanitize_textarea_field( (string) $e['description'] );
		}
		if ( ! empty( $e['representativeQueries'] ) && is_array( $e['representativeQueries'] ) ) {
			$queries = array();
			foreach ( $e['representativeQueries'] as $q ) {
				$q = sanitize_text_field( (string) $q );
				if ( '' !== $q ) {
					$queries[] = $q;
				}
			}
			if ( ! empty( $queries ) ) {
				$clean['representativeQueries'] = $queries;
			}
		}
		return $clean;
	}

	// ---------------------------------------------------------------------
	// Admin-post: download (stream the JSON, not a zip)
	// ---------------------------------------------------------------------

	/**
	 * Stream the manifest as a downloadable ai-catalog.json (overrides the base
	 * zip download — a single JSON document does not warrant an archive).
	 */
	public function handle_download() {
		$this->guard( 'coywolf_seo_ard_download' );
		$json = $this->generator->catalog_json();
		if ( '' === $json ) {
			$this->redirect( 'ard-nobundle' );
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="ai-catalog.json"' );
		header( 'Content-Length: ' . (string) strlen( $json ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-generated, validated JSON manifest streamed verbatim.
		echo $json;
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
			'ard-saved'        => array( 'success', __( 'ARD settings saved.', 'coywolf-seo' ) ),
			'ard-enabled'      => array( 'success', __( 'Agentic Resource Discovery enabled. The manifest is generating in the background.', 'coywolf-seo' ) ),
			'ard-disabled'     => array( 'success', __( 'Agentic Resource Discovery disabled. The manifest endpoint no longer responds.', 'coywolf-seo' ) ),
			'ard-rebuilt'      => array( 'success', __( 'ai-catalog.json rebuilt.', 'coywolf-seo' ) ),
			'ard-cleaned'      => array( 'success', __( 'The generated manifest was removed.', 'coywolf-seo' ) ),
			'ard-config-saved' => array( 'success', __( 'ARD resources saved and the manifest was refreshed.', 'coywolf-seo' ) ),
			'ard-config-error' => array( 'error', __( 'Custom entries must be a valid JSON array. Nothing was saved — fix the JSON and try again.', 'coywolf-seo' ) ),
			'ard-nobundle'     => array( 'error', __( 'There is no manifest yet. Rebuild it first.', 'coywolf-seo' ) ),
			'ard-error'        => array( 'error', __( 'The ARD action could not be completed.', 'coywolf-seo' ) ),
		);
	}

	/**
	 * Render this feature's panel on the Labs page.
	 */
	public function render_panel() {
		$s            = $this->settings();
		$enabled      = ! empty( $s['enabled'] );
		$endpoint     = ! empty( $s['live_endpoint'] );
		$advertise    = ! empty( $s['advertise'] );
		$last         = $this->last_build();
		$config       = $this->config();
		$entries_json = ! empty( $config['entries'] )
			? (string) wp_json_encode( $config['entries'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: '';

		// If the last save rejected invalid custom-entries JSON, refill the field
		// with the user's draft (not the stored config) so their edit is not lost,
		// and flag the field as invalid for assistive tech.
		$entries_error = false;
		$draft_key     = self::ENTRIES_DRAFT_TRANSIENT . get_current_user_id();
		$draft         = get_transient( $draft_key );
		if ( false !== $draft ) {
			delete_transient( $draft_key );
			$entries_json  = (string) $draft;
			$entries_error = true;
		}
		?>
		<div class="coywolf-seo-labs-feature coywolf-seo-panel">
			<h2><?php echo esc_html( $this->title() ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Publishes an Agentic Resource Discovery manifest (ai-catalog.json) at /.well-known/ so AI clients and registries can discover the machine-usable resources this site offers — your WP REST API, the plugin\'s own OKF / EntityMap / llms.txt outputs, an MCP server, or anything you add. Served dynamically; no file is written to your site root.', 'coywolf-seo' ); ?>
			</p>
			<p class="description">
				<em><?php esc_html_e( 'ARD describes invocable / connectable resources, not page content. It is genuinely useful only if the listed resources are things an agent can use — and registry adoption is still early.', 'coywolf-seo' ); ?></em>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="coywolf_seo_ard_save" />
				<?php wp_nonce_field( 'coywolf_seo_ard_save' ); ?>
				<p>
					<label>
						<input type="checkbox" name="coywolf_seo_ard[enabled]" value="1" <?php checked( $enabled ); ?> />
						<?php esc_html_e( 'Enable Agentic Resource Discovery (ai-catalog.json)', 'coywolf-seo' ); ?>
					</label>
				</p>
				<p style="margin-left:1.75rem;">
					<label>
						<input type="checkbox" name="coywolf_seo_ard[live_endpoint]" value="1" <?php checked( $endpoint ); ?> />
						<?php esc_html_e( 'Serve the manifest live at /.well-known/ai-catalog.json', 'coywolf-seo' ); ?>
					</label>
				</p>
				<?php if ( $enabled ) : ?>
					<p style="margin-left:1.75rem;">
						<label>
							<input type="checkbox" name="coywolf_seo_ard[advertise]" value="1" <?php checked( $advertise ); ?> />
							<?php esc_html_e( 'Advertise the manifest (a page <head> link and a robots.txt allowance)', 'coywolf-seo' ); ?>
						</label>
					</p>
				<?php endif; ?>
				<?php submit_button( __( 'Save ARD settings', 'coywolf-seo' ) ); ?>
			</form>

			<?php if ( $enabled ) : ?>
				<hr />
				<h3><?php esc_html_e( 'Manifest', 'coywolf-seo' ); ?></h3>
				<?php if ( $last && isset( $last['counts']['entries'] ) ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: 1: entry count, 2: build time */
							esc_html__( 'Last build: %1$d entries (%2$s).', 'coywolf-seo' ),
							(int) $last['counts']['entries'],
							esc_html( isset( $last['time'] ) ? (string) $last['time'] : '' )
						);
						?>
					</p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'No manifest has been generated yet.', 'coywolf-seo' ); ?></p>
				<?php endif; ?>
				<?php if ( $endpoint ) : ?>
					<p class="description">
						<?php esc_html_e( 'Manifest:', 'coywolf-seo' ); ?>
						<a href="<?php echo esc_url( $this->catalog_url() ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( $this->catalog_url() ); ?></code></a>
					</p>
				<?php endif; ?>

				<hr />
				<h3><?php esc_html_e( 'Resources advertised', 'coywolf-seo' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="coywolf_seo_ard_config" />
					<?php wp_nonce_field( 'coywolf_seo_ard_config' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="coywolf-seo-ard-host-name"><?php esc_html_e( 'Host display name', 'coywolf-seo' ); ?></label></th>
							<td><input type="text" id="coywolf-seo-ard-host-name" name="host_name" class="regular-text" value="<?php echo esc_attr( $config['host_name'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="coywolf-seo-ard-doc-url"><?php esc_html_e( 'Documentation URL', 'coywolf-seo' ); ?></label></th>
							<td><input type="url" id="coywolf-seo-ard-doc-url" name="doc_url" class="regular-text" value="<?php echo esc_attr( $config['doc_url'] ); ?>" placeholder="https://" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="coywolf-seo-ard-logo-url"><?php esc_html_e( 'Logo URL', 'coywolf-seo' ); ?></label></th>
							<td><input type="url" id="coywolf-seo-ard-logo-url" name="logo_url" class="regular-text" value="<?php echo esc_attr( $config['logo_url'] ); ?>" placeholder="https://" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Built-in resources', 'coywolf-seo' ); ?></th>
							<td>
								<p><label><input type="checkbox" name="include_rest" value="1" <?php checked( ! empty( $config['include_rest'] ) ); ?> /> <?php esc_html_e( 'WordPress REST API (/wp-json/)', 'coywolf-seo' ); ?></label></p>
								<p><label><input type="checkbox" name="include_okf" value="1" <?php checked( ! empty( $config['include_okf'] ) ); ?> /> <?php esc_html_e( 'OKF bundle — listed only when the OKF Labs feature is enabled with a live endpoint', 'coywolf-seo' ); ?></label></p>
								<p><label><input type="checkbox" name="include_entitymap" value="1" <?php checked( ! empty( $config['include_entitymap'] ) ); ?> /> <?php esc_html_e( 'EntityMap — listed only when the EntityMap Labs feature is enabled with a live endpoint', 'coywolf-seo' ); ?></label></p>
								<p><label><input type="checkbox" name="include_llms" value="1" <?php checked( ! empty( $config['include_llms'] ) ); ?> /> <?php esc_html_e( 'llms.txt — listed only when the LLMs.txt feature is enabled', 'coywolf-seo' ); ?></label></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="coywolf-seo-ard-mcp-url"><?php esc_html_e( 'MCP server card URL', 'coywolf-seo' ); ?></label></th>
							<td>
								<input type="url" id="coywolf-seo-ard-mcp-url" name="mcp_url" class="regular-text" value="<?php echo esc_attr( $config['mcp_url'] ); ?>" placeholder="https://" />
								<p class="description"><?php esc_html_e( 'Optional. The URL of an MCP server card this site exposes. Leave blank if you do not run one.', 'coywolf-seo' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="coywolf-seo-ard-entries"><?php esc_html_e( 'Custom entries (JSON)', 'coywolf-seo' ); ?></label></th>
							<td>
								<textarea id="coywolf-seo-ard-entries" name="entries_json" rows="8" class="large-text code" placeholder='[{"displayName":"My Agent","type":"application/a2a-agent-card+json","url":"https://example.com/agent.json","description":"…","representativeQueries":["…"]}]'<?php echo $entries_error ? ' aria-invalid="true" aria-describedby="coywolf-seo-ard-entries-error"' : ''; ?>><?php echo esc_textarea( $entries_json ); ?></textarea>
								<?php if ( $entries_error ) : ?>
									<p id="coywolf-seo-ard-entries-error" class="description" role="alert" style="color:#b32d2e;">
										<?php esc_html_e( 'That was not a valid JSON array, so nothing was saved. Your input is kept below — fix the JSON (a trailing comma or a smart quote is a common cause) and save again.', 'coywolf-seo' ); ?>
									</p>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'Optional. A JSON array of additional catalog entries. Each needs at least displayName, type (an IANA media type), and url; description and representativeQueries are optional. Invalid JSON is rejected without saving.', 'coywolf-seo' ); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save resources', 'coywolf-seo' ) ); ?>
				</form>

				<?php
				if ( $advertise ) {
					$this->advertiser->render_status();
				}
				?>

				<hr />
				<p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:.5rem;">
						<input type="hidden" name="action" value="coywolf_seo_ard_rebuild" />
						<?php wp_nonce_field( 'coywolf_seo_ard_rebuild' ); ?>
						<?php submit_button( __( 'Rebuild manifest now', 'coywolf-seo' ), 'secondary', 'submit', false ); ?>
					</form>
					<?php if ( $this->generator->bundle_exists() ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:.5rem;">
							<input type="hidden" name="action" value="coywolf_seo_ard_download" />
							<?php wp_nonce_field( 'coywolf_seo_ard_download' ); ?>
							<?php submit_button( __( 'Download ai-catalog.json', 'coywolf-seo' ), 'secondary', 'submit', false ); ?>
						</form>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<?php if ( $this->generator->bundle_exists() ) : ?>
				<hr />
				<h3><?php esc_html_e( 'Generated manifest', 'coywolf-seo' ); ?></h3>
				<p class="description">
					<?php
					if ( ! $enabled ) {
						esc_html_e( 'ARD is disabled but a previously generated manifest is still stored. You can remove it now.', 'coywolf-seo' );
					} else {
						esc_html_e( 'Remove the stored manifest.', 'coywolf-seo' );
					}
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete the generated ARD manifest? This cannot be undone.', 'coywolf-seo' ) ); ?>');">
					<input type="hidden" name="action" value="coywolf_seo_ard_cleanup" />
					<?php wp_nonce_field( 'coywolf_seo_ard_cleanup' ); ?>
					<?php submit_button( __( 'Clean up stored manifest', 'coywolf-seo' ), 'delete', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
