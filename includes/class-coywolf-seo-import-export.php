<?php
/**
 * Settings import/export for Coywolf SEO.
 *
 * Exports the plugin settings and saved author properties as a JSON file,
 * and imports the same file back — everything sanitized through the same
 * whitelists as the admin forms. The Anthropic API key is never exported.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import/Export handlers and admin page.
 */
final class Coywolf_SEO_Import_Export {

	/**
	 * Menu slug.
	 */
	const SLUG = 'coywolf-seo-import-export';

	/**
	 * Hook everything up.
	 */
	public function init() {
		add_action( 'admin_post_coywolf_seo_export', array( $this, 'export' ) );
		add_action( 'admin_post_coywolf_seo_import', array( $this, 'import' ) );
	}

	/**
	 * Stream the settings JSON download.
	 */
	public function export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export Coywolf SEO settings.', 'coywolf-seo' ) );
		}
		check_admin_referer( 'coywolf_seo_export' );

		$settings = Coywolf_SEO_Options::all();
		unset( $settings['ai_api_key'] ); // Secrets never leave the site.

		$redirects = array();
		foreach ( Coywolf_SEO::instance()->redirects()->all_rules() as $rule ) {
			$redirects[] = array(
				'source'     => $rule->source,
				'target'     => $rule->target,
				'type'       => (int) $rule->type,
				'is_regex'   => (bool) $rule->is_regex,
				'query_mode' => $rule->query_mode,
				'enabled'    => (bool) $rule->enabled,
				'note'       => $rule->note,
			);
		}

		$payload = array(
			'plugin'    => 'coywolf-seo',
			'version'   => Coywolf_SEO::VERSION,
			'exported'  => gmdate( 'c' ),
			'settings'  => $settings,
			'authors'   => Coywolf_SEO_Options::authors_all(),
			'redirects' => $redirects,
		);

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=coywolf-seo-settings-' . gmdate( 'Ymd' ) . '.json' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON file download built by wp_json_encode.
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Import a previously exported settings file.
	 */
	public function import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to import Coywolf SEO settings.', 'coywolf-seo' ) );
		}
		check_admin_referer( 'coywolf_seo_import' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- tmp_name is a server-generated path, validated with is_uploaded_file().
		$tmp = isset( $_FILES['coywolf_seo_import_file']['tmp_name'] ) ? (string) $_FILES['coywolf_seo_import_file']['tmp_name'] : '';
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			$this->redirect_back( 'import-error' );
		}
		if ( filesize( $tmp ) > MB_IN_BYTES ) {
			$this->redirect_back( 'import-error' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the just-uploaded temp file.
		$decoded = json_decode( (string) file_get_contents( $tmp ), true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['plugin'] ) || 'coywolf-seo' !== $decoded['plugin'] ) {
			$this->redirect_back( 'import-error' );
		}

		if ( isset( $decoded['settings'] ) && is_array( $decoded['settings'] ) ) {
			Coywolf_SEO_Options::update( Coywolf_SEO_Options::sanitize( $decoded['settings'] ) );
		}

		if ( isset( $decoded['authors'] ) && is_array( $decoded['authors'] ) ) {
			$authors = array();
			foreach ( $decoded['authors'] as $user_id => $rows ) {
				$user_id = absint( $user_id );
				if ( ! $user_id || ! is_array( $rows ) ) {
					continue;
				}
				$authors[ $user_id ] = Coywolf_SEO_Options::sanitize_properties( $rows, Coywolf_SEO_Options::person_properties() );
			}
			update_option( Coywolf_SEO_Options::AUTHORS_OPTION, $authors );
		}

		if ( isset( $decoded['redirects'] ) && is_array( $decoded['redirects'] ) ) {
			$redirects = Coywolf_SEO::instance()->redirects();
			foreach ( $redirects->all_rules() as $existing ) {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- import replaces the rule set.
				$wpdb->delete( Coywolf_SEO_Redirects::table( 'rules' ), array( 'id' => (int) $existing->id ), array( '%d' ) );
			}
			foreach ( $decoded['redirects'] as $rule ) {
				if ( is_array( $rule ) ) {
					$redirects->save_rule( $rule );
				}
			}
		}

		// Imported settings may change capabilities and URL shapes.
		Coywolf_SEO_Admin::sync_capability( (string) Coywolf_SEO_Options::get( 'access_role' ) );
		Coywolf_SEO_Redirects::flush_regex_cache();
		flush_rewrite_rules();

		$this->redirect_back( 'import' );
	}

	/**
	 * Back to the Settings page with a notice flag.
	 *
	 * @param string $what Notice key.
	 */
	private function redirect_back( $what ) {
		wp_safe_redirect( add_query_arg( 'coywolf-seo-saved', $what, admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}

	/**
	 * Render the Import/Export page.
	 */
	public function render_page() {
		?>
		<div class="wrap coywolf-seo-wrap">
		<h1><?php esc_html_e( 'Import/Export', 'coywolf-seo' ); ?></h1>
		<div class="coywolf-seo-panel">
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Export', 'coywolf-seo' ); ?></th>
				<td>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="coywolf_seo_export" />
						<?php wp_nonce_field( 'coywolf_seo_export' ); ?>
						<?php submit_button( __( 'Export settings', 'coywolf-seo' ), 'secondary', 'submit', false ); ?>
					</form>
					<p class="description"><?php esc_html_e( 'Downloads the plugin settings and saved author properties as a JSON file. API keys are never exported.', 'coywolf-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Import', 'coywolf-seo' ); ?></th>
				<td>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
						<input type="hidden" name="action" value="coywolf_seo_import" />
						<?php wp_nonce_field( 'coywolf_seo_import' ); ?>
						<input type="file" name="coywolf_seo_import_file" id="coywolf-seo-import-file" accept=".json,application/json" required aria-label="<?php esc_attr_e( 'Import file (JSON)', 'coywolf-seo' ); ?>" />
						<?php submit_button( __( 'Import settings', 'coywolf-seo' ), 'secondary', 'submit', false ); ?>
					</form>
					<p class="description"><?php esc_html_e( 'Replaces the current settings and author properties with the contents of an export file.', 'coywolf-seo' ); ?></p>
				</td>
			</tr>
		</table>
		</div>
		<?php
		$importers = Coywolf_SEO::instance()->redirects_import();
		if ( $importers ) {
			$importers->render_section();
		}
		// Robots.txt rules import/export (own JSON format, separate from the
		// settings export above).
		if ( Coywolf_SEO_Options::feature_enabled( 'robots' ) ) {
			Coywolf_SEO::instance()->robots()->render_import_export_section();
		}
		?>
		</div>
		<?php
	}
}
