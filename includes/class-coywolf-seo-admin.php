<?php
/**
 * Admin screens for Coywolf SEO: the Coywolf SEO menu with the Site Details
 * and Settings pages, their save handlers, and the access-rights capability
 * sync.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin pages and form handling.
 */
final class Coywolf_SEO_Admin {

	/**
	 * Capability gating the plugin's admin pages (granted per Access Rights).
	 */
	const CAPABILITY = 'coywolf_seo_manage';

	/**
	 * Menu slugs.
	 */
	const SLUG_SITE     = 'coywolf-seo';
	const SLUG_SETTINGS = 'coywolf-seo-settings';
	const SLUG_DOCS     = 'coywolf-seo-documentation';

	/**
	 * Hook everything up.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_coywolf_seo_save_site', array( $this, 'save_site_details' ) );
		add_action( 'admin_post_coywolf_seo_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_coywolf_seo_remove_ai_key', array( $this, 'remove_ai_key' ) );
		add_action( 'wp_ajax_coywolf_seo_bulk_action', array( $this, 'ajax_bulk_action' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_saved_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_activation_notice' ) );
	}

	/**
	 * Grant the plugin capability per the Access Rights setting. Runs on
	 * activation and whenever the setting changes.
	 *
	 * @param string $access_role 'administrator' or 'editor' (the minimum role).
	 * @return void
	 */
	public static function sync_capability( $access_role ) {
		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( self::CAPABILITY ) ) {
			$admin->add_cap( self::CAPABILITY );
		}
		$editor = get_role( 'editor' );
		if ( $editor ) {
			if ( 'editor' === $access_role && ! $editor->has_cap( self::CAPABILITY ) ) {
				$editor->add_cap( self::CAPABILITY );
			}
			if ( 'editor' !== $access_role && $editor->has_cap( self::CAPABILITY ) ) {
				$editor->remove_cap( self::CAPABILITY );
			}
		}
	}

	/**
	 * Remove the plugin capability from every role (uninstall cleanup).
	 *
	 * @return void
	 */
	public static function remove_capability() {
		foreach ( wp_roles()->role_objects as $role ) {
			if ( $role->has_cap( self::CAPABILITY ) ) {
				$role->remove_cap( self::CAPABILITY );
			}
		}
	}

	/**
	 * The Coywolf logomark as a data-URI menu icon. WordPress's
	 * svg-painter recolors the fill to match the admin color scheme and
	 * the menu's hover/current states.
	 *
	 * @return string
	 */
	private function menu_icon() {
		$svg = file_get_contents( COYWOLF_SEO_PATH . 'assets/menu-icon.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled plugin asset, local path.
		if ( false === $svg ) {
			return 'dashicons-search';
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- data URI for add_menu_page, the documented SVG-icon mechanism.
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}


	/**
	 * The bulk-run controls (idle / running / paused), rendered server-side
	 * so the page load and every AJAX state change share one template.
	 */
	public function render_bulk_controls() {
		$coywolf_seo_bulk = Coywolf_SEO::instance()->ai()->bulk_status();
		?>
		<?php if ( 'paused' === $coywolf_seo_bulk['status'] ) : ?>
			<div class="coywolf-seo-progress"><div class="coywolf-seo-progress-bar" style="width:<?php echo esc_attr( (string) $coywolf_seo_bulk['percent'] ); ?>%"></div></div>
			<?php if ( '' !== $coywolf_seo_bulk['paused_reason'] ) : ?>
				<p class="description" style="color:#b32d2e">
					<strong><?php esc_html_e( 'Paused automatically after repeated failures.', 'coywolf-seo' ); ?></strong>
					<?php
					printf(
						/* translators: %s: the error message from the last failed post. */
						esc_html__( 'Last error: %s', 'coywolf-seo' ),
						esc_html( $coywolf_seo_bulk['paused_reason'] )
					);
					?>
					<?php esc_html_e( 'Fix the cause (for example, top up API credits), then Resume to continue where it left off.', 'coywolf-seo' ); ?>
					<?php if ( false !== stripos( $coywolf_seo_bulk['paused_reason'], 'credit balance' ) ) : ?>
						<br />
						<?php esc_html_e( 'Note: Anthropic checks a batch\'s maximum possible cost up front — not what it will actually spend — so this can trigger even with a healthy balance. Resume submits smaller batches with tighter token allowances, which usually clears it. If it persists, check the Model setting: legacy Opus models cost several times more than the default.', 'coywolf-seo' ); ?>
					<?php endif; ?>
				</p>
			<?php endif; ?>
			<p class="description">
				<?php
				printf(
					/* translators: 1: processed count, 2: total, 3: percent, 4: failed count. */
					esc_html__( 'Paused at %1$d of %2$d (%3$d%%), %4$d failed. Posts already mid-analysis when you stopped may still finish.', 'coywolf-seo' ),
					(int) $coywolf_seo_bulk['done'],
					(int) $coywolf_seo_bulk['total'],
					(int) $coywolf_seo_bulk['percent'],
					(int) $coywolf_seo_bulk['failed']
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="coywolf-seo-inline-form coywolf-seo-bulk-op" data-op="resume">
				<input type="hidden" name="action" value="coywolf_seo_bulk_resume" />
				<?php wp_nonce_field( 'coywolf_seo_bulk_resume' ); ?>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Resume', 'coywolf-seo' ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="coywolf-seo-inline-form coywolf-seo-bulk-op" data-op="cancel">
				<input type="hidden" name="action" value="coywolf_seo_bulk_cancel" />
				<?php wp_nonce_field( 'coywolf_seo_bulk_cancel' ); ?>
				<button type="submit" class="button coywolf-seo-button-danger"><?php esc_html_e( 'Cancel', 'coywolf-seo' ); ?></button>
			</form>
		<?php elseif ( 'running' === $coywolf_seo_bulk['status'] ) : ?>
			<div id="coywolf-seo-bulk-progress" data-running="1">
				<div class="coywolf-seo-progress"><div class="coywolf-seo-progress-bar" style="width:<?php echo esc_attr( (string) $coywolf_seo_bulk['percent'] ); ?>%"></div></div>
				<p class="description coywolf-seo-bulk-text">
					<?php
					printf(
						/* translators: 1: processed count, 2: total, 3: percent, 4: current stage. */
						esc_html__( '%1$d of %2$d (%3$d%%) — %4$s Keeping this page open speeds it up.', 'coywolf-seo' ),
						(int) $coywolf_seo_bulk['done'],
						(int) $coywolf_seo_bulk['total'],
						(int) $coywolf_seo_bulk['percent'],
						esc_html( $coywolf_seo_bulk['stage_label'] )
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="coywolf-seo-bulk-op" data-op="stop">
					<input type="hidden" name="action" value="coywolf_seo_bulk_stop" />
					<?php wp_nonce_field( 'coywolf_seo_bulk_stop' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Stop', 'coywolf-seo' ); ?></button>
				</form>
			</div>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="coywolf-seo-bulk-op" data-op="start">
				<input type="hidden" name="action" value="coywolf_seo_bulk_enrich" />
				<?php wp_nonce_field( 'coywolf_seo_bulk_enrich' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Enrich all posts and pages', 'coywolf-seo' ); ?></button>
				<label class="coywolf-seo-bulk-force-label">
					<input type="checkbox" id="coywolf-seo-bulk-force" name="coywolf_seo_bulk_force" value="1" />
					<?php esc_html_e( 'Re-analyze all', 'coywolf-seo' ); ?>
				</label>
			</form>
			<?php if ( 'done' === $coywolf_seo_bulk['status'] && '' !== $coywolf_seo_bulk['finished'] ) : ?>
				<?php if ( $coywolf_seo_bulk['failed'] > 0 ) : ?>
					<p class="description" style="color:#b32d2e">
						<?php
						printf(
							/* translators: 1: failed count, 2: total, 3: the last error message. */
							esc_html__( 'Last run completed, but %1$d of %2$d items failed. Last error: %3$s — failed items retry on their next save, via Re-analyze, or in another run.', 'coywolf-seo' ),
							(int) $coywolf_seo_bulk['failed'],
							(int) $coywolf_seo_bulk['total'],
							esc_html( $coywolf_seo_bulk['last_error'] )
						);
						?>
					</p>
				<?php else : ?>
					<p class="description">
						<?php
						printf(
							/* translators: 1: number processed, 2: date. */
							esc_html__( 'Last run finished %2$s after processing %1$d items.', 'coywolf-seo' ),
							(int) $coywolf_seo_bulk['done'],
							esc_html( gmdate( 'M j, Y H:i', strtotime( $coywolf_seo_bulk['finished'] ) ) . ' UTC' )
						);
						?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * Bulk-run controls over AJAX: start, stop, resume, cancel — the page
	 * updates in place; the plain forms remain the no-JS fallback.
	 */
	public function ajax_bulk_action() {
		check_ajax_referer( 'coywolf_seo_bulk_action' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array(), 403 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above.
		$op = isset( $_POST['op'] ) ? sanitize_key( $_POST['op'] ) : '';
		$ai = Coywolf_SEO::instance()->ai();
		switch ( $op ) {
			case 'start':
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above.
				$ai->start_bulk( ! empty( $_POST['force'] ) );
				break;
			case 'stop':
				$ai->pause_bulk();
				break;
			case 'resume':
				$ai->resume_bulk();
				break;
			case 'cancel':
				$ai->cancel_bulk();
				break;
			case 'refresh':
				break;
			default:
				wp_send_json_error( array(), 400 );
		}

		ob_start();
		$this->render_bulk_controls();
		wp_send_json_success(
			array(
				'html'   => ob_get_clean(),
				'status' => $ai->bulk_status()['status'],
			)
		);
	}

	/**
	 * Register the Coywolf SEO menu and its pages.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Coywolf SEO', 'coywolf-seo' ),
			__( 'Coywolf SEO', 'coywolf-seo' ),
			self::CAPABILITY,
			self::SLUG_SITE,
			array( $this, 'render_site_details' ),
			$this->menu_icon(),
			81
		);
		add_submenu_page(
			self::SLUG_SITE,
			__( 'Site Details', 'coywolf-seo' ),
			__( 'Site Details', 'coywolf-seo' ),
			self::CAPABILITY,
			self::SLUG_SITE,
			array( $this, 'render_site_details' )
		);
		add_submenu_page(
			self::SLUG_SITE,
			__( 'Authors', 'coywolf-seo' ),
			__( 'Authors', 'coywolf-seo' ),
			self::CAPABILITY,
			Coywolf_SEO_Authors::SLUG,
			array( Coywolf_SEO::instance()->authors(), 'render' )
		);
		$pending      = Coywolf_SEO::instance()->redirects()->pending_count();
		$bubble       = $pending > 0 ? ' <span class="awaiting-mod">' . esc_html( (string) $pending ) . '</span>' : '';
		add_submenu_page(
			self::SLUG_SITE,
			__( 'Redirects', 'coywolf-seo' ),
			__( 'Redirects', 'coywolf-seo' ) . $bubble,
			self::CAPABILITY,
			Coywolf_SEO_Redirects::SLUG,
			array( Coywolf_SEO::instance()->redirects_admin(), 'render' )
		);
		add_submenu_page(
			self::SLUG_SITE,
			__( 'Settings', 'coywolf-seo' ),
			__( 'Settings', 'coywolf-seo' ),
			'manage_options',
			self::SLUG_SETTINGS,
			array( $this, 'render_settings' )
		);
		add_submenu_page(
			self::SLUG_SITE,
			__( 'Import/Export', 'coywolf-seo' ),
			__( 'Import/Export', 'coywolf-seo' ),
			'manage_options',
			Coywolf_SEO_Import_Export::SLUG,
			array( Coywolf_SEO::instance()->import_export(), 'render_page' )
		);
		add_submenu_page(
			self::SLUG_SITE,
			__( 'Documentation', 'coywolf-seo' ),
			__( 'Documentation', 'coywolf-seo' ),
			self::CAPABILITY,
			self::SLUG_DOCS,
			array( $this, 'render_documentation' )
		);
	}

	/**
	 * Render the Documentation page.
	 *
	 * The body is generated from the bundled readme.md (the canonical
	 * Markdown source — the same file GitHub shows) so the docs always
	 * match the installed version. The leading logo <img> line is
	 * dropped; everything else renders through Coywolf_SEO_Markdown.
	 */
	public function render_documentation() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$readme = COYWOLF_SEO_PATH . 'readme.md';
		$text   = is_readable( $readme ) ? (string) file_get_contents( $readme ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the plugin's own bundled, read-only text file, not a remote request.
		// Drop the leading logo <img> line; the rest is Markdown.
		$text = preg_replace( '/^\s*<img\b[^>]*>\s*$/m', '', $text );
		?>
		<div class="wrap coywolf-seo-wrap coywolf-seo-docs">
			<h1><?php esc_html_e( 'Documentation', 'coywolf-seo' ); ?></h1>
			<?php if ( '' === trim( $text ) ) : ?>
				<p><?php esc_html_e( 'Documentation is unavailable (readme.md not found).', 'coywolf-seo' ); ?></p>
			<?php else : ?>
				<div class="coywolf-seo-doc-body">
					<?php echo wp_kses_post( Coywolf_SEO_Markdown::to_html( $text ) ); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Remove the saved Anthropic API key immediately (its own endpoint so
	 * no Save round-trip is needed).
	 */
	public function remove_ai_key() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Coywolf SEO settings.', 'coywolf-seo' ) );
		}
		check_admin_referer( 'coywolf_seo_remove_ai_key' );
		Coywolf_SEO_Options::update( array( 'ai_api_key' => '' ) );
		delete_transient( 'coywolf_seo_ai_models' );
		$this->redirect_back( self::SLUG_SETTINGS, 'ai-key-removed' );
	}


	/**
	 * Enqueue admin CSS/JS on the plugin's own screens only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		// Category/Tag screens get the media picker and the field script.
		if ( in_array( $hook, array( 'edit-tags.php', 'term.php' ), true ) ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && in_array( $screen->taxonomy, array( 'category', 'post_tag' ), true ) ) {
				wp_enqueue_media();
				wp_enqueue_script(
					'coywolf-seo-admin',
					COYWOLF_SEO_URL . 'js/admin.js',
					array( 'jquery' ),
					Coywolf_SEO::VERSION,
					true
				);
			}
			return;
		}
		// The post-deletion decision notice on All Posts/Pages uses the
		// plugin styles; everything else is plugin-screen only.
		if ( 'edit.php' === $hook ) {
			wp_enqueue_style(
				'coywolf-seo-admin',
				COYWOLF_SEO_URL . 'css/admin.css',
				array(),
				Coywolf_SEO::VERSION
			);
			return;
		}
		if ( strpos( $hook, self::SLUG_SITE ) === false ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style(
			'coywolf-seo-admin',
			COYWOLF_SEO_URL . 'css/admin.css',
			array(),
			Coywolf_SEO::VERSION
		);
		wp_enqueue_script(
			'coywolf-seo-admin',
			COYWOLF_SEO_URL . 'js/admin.js',
			array( 'jquery' ),
			Coywolf_SEO::VERSION,
			true
		);
		wp_localize_script(
			'coywolf-seo-admin',
			'CoywolfSEOAdmin',
			array(
				'propertyInputs' => Coywolf_SEO_Options::property_inputs(),
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'bulkStatusNonce' => wp_create_nonce( 'coywolf_seo_bulk_status' ),
				'bulkActionNonce' => wp_create_nonce( 'coywolf_seo_bulk_action' ),
				'i18n'           => array(
					'selectImage'    => __( 'Select image', 'coywolf-seo' ),
					'pasteOrSelect'  => __( 'Paste an image URL or select one', 'coywolf-seo' ),
					'removeProperty' => __( 'Remove property', 'coywolf-seo' ),
					'confirmDelete'      => __( 'Delete this redirect?', 'coywolf-seo' ),
					'confirmBulkEnrich'  => __( 'Enrich ALL published posts and pages now? This runs in the background, can take a while, and incurs Anthropic API costs. Content already analyzed with the current settings is skipped.', 'coywolf-seo' ),
					'confirmBulkForce'   => __( 'Re-analyze EVERY published post and page now, including content that is already current? Every item makes fresh API calls, so this costs the full estimated amount each time.', 'coywolf-seo' ),
					'confirmBulkCancel'  => __( 'Cancel this run for good? The remaining queue is discarded — a new run would re-check every post from the start (already-analyzed content is skipped at no cost).', 'coywolf-seo' ),
					'estimateLine'       => __( '%POSTS% posts need enrichment (%SKIPPED% already current). Estimated cost: ~$%COST% at batch rates for %MODEL%. Your Anthropic balance (and any Workspace spend limit) must cover about $%RESERVE% for the largest batch\'s upfront check.', 'coywolf-seo' ),
					'estimateNone'       => __( 'Everything is already analyzed with the current settings — re-analyzing everything (%POSTS% posts, ~$%COST%) will incur new costs.', 'coywolf-seo' ),
					'estimateEmpty'      => __( 'There is no published content to enrich yet.', 'coywolf-seo' ),
					'estimateUnsaved'    => __( '(Previewing an unsaved model — the run uses the saved Model setting.)', 'coywolf-seo' ),
					'estimateHistory'    => __( 'Based on your measured average usage.', 'coywolf-seo' ),
					'estimateHeuristic'  => __( 'Rough estimate based on content length.', 'coywolf-seo' ),
					'testRunning'        => __( 'Testing — this makes one tiny paid call…', 'coywolf-seo' ),
					'testMessages'       => __( 'Regular API:', 'coywolf-seo' ),
					'testBatches'        => __( 'Batches API:', 'coywolf-seo' ),
					'confirmBulkDelete' => __( 'Delete the selected redirects?', 'coywolf-seo' ),
				),
			)
		);
	}

	/**
	 * One-time post-activation reminder: edge/CDN caches the plugin cannot
	 * purge keep serving pre-activation HTML without the new SEO tags.
	 * Shown once, then the transient is gone — never a recurring nag.
	 */
	public function maybe_show_activation_notice() {
		if ( ! current_user_can( self::CAPABILITY ) || ! get_transient( 'coywolf_seo_activation_notice' ) ) {
			return;
		}
		delete_transient( 'coywolf_seo_activation_notice' );
		printf(
			'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
			esc_html__( 'Coywolf SEO is active. If your site uses page or CDN caching (including host-level edge caching), purge it once so the new titles, schema, and meta tags are served to visitors.', 'coywolf-seo' )
		);
	}

	/**
	 * Success notice after a save redirect.
	 */
	public function maybe_show_saved_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag set by our own redirect.
		$saved  = isset( $_GET['coywolf-seo-saved'] ) ? sanitize_key( $_GET['coywolf-seo-saved'] ) : '';
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $saved || ! $screen || strpos( (string) $screen->id, self::SLUG_SITE ) === false ) {
			return;
		}
		if ( 'settings' === $saved ) {
			$message = __( 'Settings saved.', 'coywolf-seo' );
		} elseif ( 'author' === $saved ) {
			$message = __( 'The author details have been saved.', 'coywolf-seo' );
		} elseif ( 'import' === $saved ) {
			$message = __( 'Settings imported.', 'coywolf-seo' );
		} elseif ( 'ai-key-removed' === $saved ) {
			$message = __( 'The API key has been removed.', 'coywolf-seo' );
		} elseif ( 'import-error' === $saved ) {
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html__( 'The file could not be imported. Use an unmodified Coywolf SEO export file.', 'coywolf-seo' ) );
			return;
		} else {
			$message = __( 'Site details saved.', 'coywolf-seo' );
		}
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}

	/**
	 * Redirect back to a plugin page with the saved flag.
	 *
	 * @param string $slug Page slug.
	 * @param string $what Which form was saved.
	 */
	private function redirect_back( $slug, $what ) {
		wp_safe_redirect( add_query_arg( 'coywolf-seo-saved', $what, admin_url( 'admin.php?page=' . $slug ) ) );
		exit;
	}

	/**
	 * Handle the Site Details form.
	 */
	public function save_site_details() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Coywolf SEO.', 'coywolf-seo' ) );
		}
		check_admin_referer( 'coywolf_seo_site' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field-by-field in Coywolf_SEO_Options::sanitize().
		$raw = isset( $_POST['coywolf_seo'] ) && is_array( $_POST['coywolf_seo'] ) ? wp_unslash( $_POST['coywolf_seo'] ) : array();

		// Checkboxes are absent when unchecked: force their presence so
		// sanitize() records the off state instead of skipping the key.
		foreach ( array( 'append_site_name', 'cat_hide_prefix' ) as $key ) {
			$raw[ $key ] = ! empty( $raw[ $key ] );
		}
		// The property repeater posts indexed rows.
		$raw['org_properties'] = isset( $raw['org_rows'] ) && is_array( $raw['org_rows'] ) ? array_values( $raw['org_rows'] ) : array();

		// The Site Name and Tagline write to the core options
		// (administrators only — the inputs are disabled for everyone else).
		if ( current_user_can( 'manage_options' ) ) {
			if ( isset( $raw['site_name'] ) && '' !== trim( (string) $raw['site_name'] ) ) {
				update_option( 'blogname', sanitize_text_field( (string) $raw['site_name'] ) );
			}
			if ( isset( $raw['tagline'] ) ) {
				update_option( 'blogdescription', sanitize_text_field( (string) $raw['tagline'] ) );
			}
		}

		$prefix_before = (bool) Coywolf_SEO_Options::get( 'cat_hide_prefix' );
		Coywolf_SEO_Options::update( Coywolf_SEO_Options::sanitize( $raw ) );
		if ( (bool) Coywolf_SEO_Options::get( 'cat_hide_prefix' ) !== $prefix_before ) {
			// Category URLs just changed shape; regenerate the rewrite rules.
			flush_rewrite_rules();
		}
		$this->redirect_back( self::SLUG_SITE, 'site' );
	}

	/**
	 * Handle the Settings form.
	 */
	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Coywolf SEO settings.', 'coywolf-seo' ) );
		}
		check_admin_referer( 'coywolf_seo_settings' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field-by-field in Coywolf_SEO_Options::sanitize().
		$raw = isset( $_POST['coywolf_seo'] ) && is_array( $_POST['coywolf_seo'] ) ? wp_unslash( $_POST['coywolf_seo'] ) : array();

		foreach ( array( 'force_rewrite_titles', 'exclude_meta_desc', 'robots_index', 'robots_follow', 'robots_max_image', 'robots_max_snippet', 'robots_max_video', 'indexnow_enabled', 'sitemap_exclude_posts', 'sitemap_exclude_pages', 'sitemap_exclude_categories', 'sitemap_exclude_users', 'news_enabled', 'news_include_posts', 'news_include_pages', 'ai_enabled', 'ai_descriptions' ) as $key ) {
			$raw[ $key ] = ! empty( $raw[ $key ] );
		}
		if ( empty( $raw['news_cats'] ) ) {
			$raw['news_cats'] = array();
		}

		// The API key field is write-only: an empty submission keeps the
		// stored key (removal happens through its own Remove link).
		if ( isset( $raw['ai_api_key'] ) && '' === trim( (string) $raw['ai_api_key'] ) ) {
			unset( $raw['ai_api_key'] );
		}
		if ( isset( $raw['ai_api_key'] ) ) {
			delete_transient( 'coywolf_seo_ai_models' ); // New key: refresh the model list.
		}

		$news_before = (bool) Coywolf_SEO_Options::get( 'news_enabled' );
		$clean       = Coywolf_SEO_Options::sanitize( $raw );
		Coywolf_SEO_Options::update( $clean );
		if ( isset( $clean['access_role'] ) ) {
			self::sync_capability( $clean['access_role'] );
		}
		if ( ! empty( $clean['indexnow_enabled'] ) ) {
			Coywolf_SEO_IndexNow::ensure_key();
		}
		if ( (bool) Coywolf_SEO_Options::get( 'news_enabled' ) !== $news_before ) {
			// The sitemap URL just appeared or disappeared.
			flush_rewrite_rules();
		}
		$this->redirect_back( self::SLUG_SETTINGS, 'settings' );
	}

	/**
	 * Render the rows of a property repeater with typed inputs and indexed
	 * names ( coywolf_seo[field][i][prop] / [i][value]... ). Shared by the
	 * Site Details and Authors screens.
	 *
	 * @param string $field   Form field group ('org_rows' or 'author_rows').
	 * @param array  $rows    Saved prop/value rows.
	 * @param array  $catalog Allowed property names => labels.
	 */
	public static function render_property_rows( $field, array $rows, array $catalog ) {
		foreach ( array_values( $rows ) as $i => $row ) {
			$prop = isset( $row['prop'] ) ? (string) $row['prop'] : '';
			?>
			<tr class="coywolf-seo-prop-row">
				<td>
					<select name="coywolf_seo[<?php echo esc_attr( $field ); ?>][<?php echo esc_attr( (string) $i ); ?>][prop]" class="coywolf-seo-prop-select">
						<?php foreach ( $catalog as $name => $label ) : ?>
							<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $prop, $name ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
				<td class="coywolf-seo-prop-value">
					<?php self::render_property_value_cell( 'coywolf_seo[' . $field . '][' . $i . '][value]', $prop, isset( $row['value'] ) ? $row['value'] : '' ); ?>
				</td>
				<td><button type="button" class="button-link coywolf-seo-remove-row" aria-label="<?php esc_attr_e( 'Remove property', 'coywolf-seo' ); ?>">&times;</button></td>
			</tr>
			<?php
		}
	}

	/**
	 * Render the value input(s) for one property, matched to its type:
	 * url/email/tel/date/number inputs, an image input with a Media Library
	 * picker, or the sub-field group of a structured property.
	 *
	 * @param string $name_base Input name base ( ...[i][value] ).
	 * @param string $prop      Property name.
	 * @param mixed  $value     Saved value (string, or array for structured).
	 */
	public static function render_property_value_cell( $name_base, $prop, $value ) {
		$inputs = Coywolf_SEO_Options::property_inputs();
		$meta   = isset( $inputs[ $prop ] ) ? $inputs[ $prop ] : array( 'input' => 'text' );

		if ( isset( $meta['fields'] ) ) {
			$value = is_array( $value ) ? $value : array();
			echo '<div class="coywolf-seo-subfields">';
			foreach ( $meta['fields'] as $sub => $sub_meta ) {
				$sub_value = isset( $value[ $sub ] ) ? (string) $value[ $sub ] : '';
				printf(
					'<label><span>%s</span><input type="%s" name="%s" value="%s" /></label>',
					esc_html( $sub_meta['label'] ),
					esc_attr( $sub_meta['input'] ),
					esc_attr( $name_base . '[' . $sub . ']' ),
					esc_attr( $sub_value )
				);
			}
			echo '</div>';
			return;
		}

		$value = is_array( $value ) ? '' : (string) $value;
		$type  = isset( $meta['input'] ) ? $meta['input'] : 'text';

		if ( 'image' === $type ) {
			printf(
				'<input type="url" class="regular-text" name="%s" value="%s" placeholder="%s" /> <button type="button" class="button coywolf-seo-media-btn">%s</button>',
				esc_attr( $name_base ),
				esc_attr( $value ),
				esc_attr__( 'Paste an image URL or select one', 'coywolf-seo' ),
				esc_html__( 'Select image', 'coywolf-seo' )
			);
			return;
		}

		printf(
			'<input type="%s" class="regular-text" name="%s" value="%s" />',
			esc_attr( $type ),
			esc_attr( $name_base ),
			esc_attr( $value )
		);
	}

	/**
	 * Render the Site Details page.
	 */
	public function render_site_details() {
		$o          = Coywolf_SEO_Options::all();
		$page_types = Coywolf_SEO_Options::page_types();
		$art_types  = Coywolf_SEO_Options::article_types();
		$org_props  = Coywolf_SEO_Options::organization_properties();

		$rows = $o['org_properties'];
		if ( empty( $rows ) ) {
			// First run: seed the typical Organization defaults.
			$rows = array(
				array(
					'prop'  => '@id',
					'value' => home_url( '/' ) . '#organization',
				),
				array(
					'prop'  => 'name',
					'value' => get_bloginfo( 'name' ),
				),
				array(
					'prop'  => 'url',
					'value' => home_url( '/' ),
				),
				array(
					'prop'  => 'logo',
					'value' => '',
				),
				array(
					'prop'  => 'sameAs',
					'value' => '',
				),
			);
		} else {
			// Always show the @id used in the output so it can be edited
			// (or removed, which falls back to the default anchor).
			$has_id = false;
			foreach ( $rows as $row ) {
				if ( isset( $row['prop'] ) && '@id' === $row['prop'] ) {
					$has_id = true;
					break;
				}
			}
			if ( ! $has_id ) {
				array_unshift(
					$rows,
					array(
						'prop'  => '@id',
						'value' => home_url( '/' ) . '#organization',
					)
				);
			}
		}

		$og_image_url = $o['og_image_id'] ? wp_get_attachment_image_url( (int) $o['og_image_id'], 'medium' ) : '';
		?>
		<div class="wrap coywolf-seo-wrap">
			<h1><?php esc_html_e( 'Site Details', 'coywolf-seo' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="coywolf_seo_save_site" />
				<?php wp_nonce_field( 'coywolf_seo_site' ); ?>

				<?php $can_edit_identity = current_user_can( 'manage_options' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="coywolf-seo-site-name"><?php esc_html_e( 'Site Name', 'coywolf-seo' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="coywolf-seo-site-name" name="coywolf_seo[site_name]" value="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" <?php disabled( ! $can_edit_identity ); ?> />
							<p class="description"><?php esc_html_e( 'The WordPress Site Name — saving here updates it everywhere.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="coywolf-seo-tagline"><?php esc_html_e( 'Tagline', 'coywolf-seo' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="coywolf-seo-tagline" name="coywolf_seo[tagline]" value="<?php echo esc_attr( get_bloginfo( 'description' ) ); ?>" <?php disabled( ! $can_edit_identity ); ?> />
							<p class="description"><?php esc_html_e( 'The WordPress Tagline — saving here updates it everywhere.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Append site name', 'coywolf-seo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="coywolf_seo[append_site_name]" value="1" <?php checked( $o['append_site_name'] ); ?> />
								<?php esc_html_e( 'Append the site name to post, page, category, and tag titles, separated with an em dash', 'coywolf-seo' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="coywolf-seo-og-image"><?php esc_html_e( 'Open Graph Image', 'coywolf-seo' ); ?></label></th>
						<td>
							<div class="coywolf-seo-og-preview" id="coywolf-seo-og-preview">
								<?php if ( $og_image_url ) : ?>
									<img src="<?php echo esc_url( $og_image_url ); ?>" alt="" />
								<?php endif; ?>
							</div>
							<input type="hidden" id="coywolf-seo-og-image" name="coywolf_seo[og_image_id]" value="<?php echo esc_attr( (string) $o['og_image_id'] ); ?>" />
							<button type="button" class="button" id="coywolf-seo-og-select"><?php esc_html_e( 'Select image', 'coywolf-seo' ); ?></button>
							<button type="button" class="button" id="coywolf-seo-og-remove" <?php echo $o['og_image_id'] ? '' : 'style="display:none"'; ?>><?php esc_html_e( 'Remove', 'coywolf-seo' ); ?></button>
							<p class="description"><?php esc_html_e( 'Default Open Graph image, used on any page that does not have one of its own. Recommended size: 1200 × 675 pixels.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Organization or Person', 'coywolf-seo' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="coywolf_seo[entity_type]" value="organization" <?php checked( $o['entity_type'], 'organization' ); ?> class="coywolf-seo-entity-toggle" />
									<?php esc_html_e( 'Organization', 'coywolf-seo' ); ?>
								</label>
								&nbsp;&nbsp;
								<label>
									<input type="radio" name="coywolf_seo[entity_type]" value="person" <?php checked( $o['entity_type'], 'person' ); ?> class="coywolf-seo-entity-toggle" />
									<?php esc_html_e( 'Person', 'coywolf-seo' ); ?>
								</label>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Whether this site represents an organization or a person. Used as the publisher in schema markup.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					<tr class="coywolf-seo-person-row" <?php echo ( 'person' === $o['entity_type'] ) ? '' : 'style="display:none"'; ?>>
						<th scope="row"><label for="coywolf-seo-person"><?php esc_html_e( 'Person', 'coywolf-seo' ); ?></label></th>
						<td>
							<select id="coywolf-seo-person" name="coywolf_seo[person_user_id]">
								<option value="0"><?php esc_html_e( '— Select a user —', 'coywolf-seo' ); ?></option>
								<?php foreach ( get_users( array( 'fields' => array( 'ID', 'display_name' ) ) ) as $user ) : ?>
									<option value="<?php echo esc_attr( (string) $user->ID ); ?>" <?php selected( (int) $o['person_user_id'], (int) $user->ID ); ?>>
										<?php echo esc_html( $user->display_name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr class="coywolf-seo-org-row" <?php echo ( 'organization' === $o['entity_type'] ) ? '' : 'style="display:none"'; ?>>
						<th scope="row"><?php esc_html_e( 'Organization properties', 'coywolf-seo' ); ?></th>
						<td>
							<table class="coywolf-seo-props" id="coywolf-seo-org-props" data-field="org_rows" data-next-index="<?php echo esc_attr( (string) count( $rows ) ); ?>">
								<tbody>
									<?php self::render_property_rows( 'org_rows', $rows, $org_props ); ?>
								</tbody>
							</table>
							<select class="coywolf-seo-add-select" data-target="coywolf-seo-org-props" aria-label="<?php esc_attr_e( 'Add a property', 'coywolf-seo' ); ?>">
								<option value=""><?php esc_html_e( '— select property —', 'coywolf-seo' ); ?></option>
								<?php foreach ( $org_props as $prop => $label ) : ?>
									<option value="<?php echo esc_attr( $prop ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Schema.org Organization properties — selecting a property adds it, and each value input matches the property type. Add a property more than once (sameAs, for example) to output multiple values. Empty rows are not saved; removing @id falls back to the default.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Homepage', 'coywolf-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="coywolf-seo-home-title"><?php esc_html_e( 'Title', 'coywolf-seo' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="coywolf-seo-home-title" name="coywolf_seo[homepage_title]" value="<?php echo esc_attr( $o['homepage_title'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Used for the homepage page title and Open Graph title. Defaults to the Site Name.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="coywolf-seo-home-desc"><?php esc_html_e( 'Description', 'coywolf-seo' ); ?></label></th>
						<td>
							<input type="text" class="large-text" id="coywolf-seo-home-desc" name="coywolf_seo[homepage_description]" value="<?php echo esc_attr( $o['homepage_description'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'description' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Used for the homepage meta description and Open Graph description. Defaults to the Tagline.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Posts', 'coywolf-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="coywolf-seo-post-page-type"><?php esc_html_e( 'Page', 'coywolf-seo' ); ?></label></th>
						<td>
							<select id="coywolf-seo-post-page-type" name="coywolf_seo[post_page_type]">
								<?php foreach ( $page_types as $type => $label ) : ?>
									<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $o['post_page_type'], $type ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Default schema page type for posts.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="coywolf-seo-post-article-type"><?php esc_html_e( 'Article', 'coywolf-seo' ); ?></label></th>
						<td>
							<select id="coywolf-seo-post-article-type" name="coywolf_seo[post_article_type]">
								<option value="none" <?php selected( $o['post_article_type'], 'none' ); ?>><?php esc_html_e( 'None', 'coywolf-seo' ); ?></option>
								<?php foreach ( $art_types as $type => $label ) : ?>
									<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $o['post_article_type'], $type ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Default schema article type for posts.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Pages', 'coywolf-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="coywolf-seo-page-page-type"><?php esc_html_e( 'Page', 'coywolf-seo' ); ?></label></th>
						<td>
							<select id="coywolf-seo-page-page-type" name="coywolf_seo[page_page_type]">
								<?php foreach ( $page_types as $type => $label ) : ?>
									<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $o['page_page_type'], $type ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Default schema page type for pages.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="coywolf-seo-page-article-type"><?php esc_html_e( 'Article', 'coywolf-seo' ); ?></label></th>
						<td>
							<select id="coywolf-seo-page-article-type" name="coywolf_seo[page_article_type]">
								<option value="none" <?php selected( $o['page_article_type'], 'none' ); ?>><?php esc_html_e( 'None', 'coywolf-seo' ); ?></option>
								<?php foreach ( $art_types as $type => $label ) : ?>
									<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $o['page_article_type'], $type ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Default schema article type for pages. Pages usually do not need one.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Categories', 'coywolf-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Hide category prefix in slug', 'coywolf-seo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="coywolf_seo[cat_hide_prefix]" value="1" <?php checked( $o['cat_hide_prefix'] ); ?> />
								<?php esc_html_e( 'Remove the category prefix (usually /category/) from category URLs', 'coywolf-seo' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Old prefixed URLs are 301 redirected to the clean ones. Requires pretty permalinks. A category sharing a slug with a page will take precedence over that page.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Site Details', 'coywolf-seo' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the Settings page.
	 */
	public function render_settings() {
		$o = Coywolf_SEO_Options::all();
		?>
		<div class="wrap coywolf-seo-wrap">
			<h1><?php esc_html_e( 'Settings', 'coywolf-seo' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="coywolf_seo_save_settings" />
				<?php wp_nonce_field( 'coywolf_seo_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="coywolf-seo-access"><?php esc_html_e( 'Access Rights', 'coywolf-seo' ); ?></label></th>
						<td>
							<select id="coywolf-seo-access" name="coywolf_seo[access_role]">
								<option value="administrator" <?php selected( $o['access_role'], 'administrator' ); ?>><?php esc_html_e( 'Administrators', 'coywolf-seo' ); ?></option>
								<option value="editor" <?php selected( $o['access_role'], 'editor' ); ?>><?php esc_html_e( 'Administrators and Editors', 'coywolf-seo' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Who can manage Site Details and Authors. This Settings page is always administrator-only.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Force rewrite titles', 'coywolf-seo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="coywolf_seo[force_rewrite_titles]" value="1" <?php checked( $o['force_rewrite_titles'] ); ?> />
								<?php esc_html_e( 'Force rewrite the page title with this plugin’s settings', 'coywolf-seo' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Only needed when the theme builds its own title tag and ignores the WordPress document title. Leaves the markup untouched otherwise.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Exclude meta description', 'coywolf-seo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" id="coywolf-seo-exclude-desc" name="coywolf_seo[exclude_meta_desc]" value="1" <?php checked( $o['exclude_meta_desc'] ); ?> />
								<?php esc_html_e( 'Do not output a meta description on any page', 'coywolf-seo' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Google usually generates a snippet from the content, so a meta description is no longer necessary.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Robots', 'coywolf-seo' ); ?></th>
						<td>
							<fieldset>
								<label><input type="checkbox" name="coywolf_seo[robots_index]" value="1" <?php checked( $o['robots_index'] ); ?> /> <code>index</code></label><br />
								<label><input type="checkbox" name="coywolf_seo[robots_follow]" value="1" <?php checked( $o['robots_follow'] ); ?> /> <code>follow</code></label><br />
								<label><input type="checkbox" name="coywolf_seo[robots_max_image]" value="1" <?php checked( $o['robots_max_image'] ); ?> /> <code>max-image-preview:large</code></label><br />
								<label><input type="checkbox" name="coywolf_seo[robots_max_snippet]" value="1" <?php checked( $o['robots_max_snippet'] ); ?> /> <code>max-snippet:-1</code></label><br />
								<label><input type="checkbox" name="coywolf_seo[robots_max_video]" value="1" <?php checked( $o['robots_max_video'] ); ?> /> <code>max-video-preview:-1</code></label>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Directives included in the robots meta tag. Per-post Noindex and Nofollow override index and follow.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'AI enrichment', 'coywolf-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Entity detection', 'coywolf-seo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" id="coywolf-seo-ai-enabled" name="coywolf_seo[ai_enabled]" value="1" <?php checked( $o['ai_enabled'] ); ?> />
								<?php esc_html_e( 'Analyze posts and pages with Claude when they are published or updated, and add the detected entities to their Article schema', 'coywolf-seo' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Main subjects become the about property and passing references become mentions. Every entity is grounded against Wikidata — Claude only extracts names, real items are looked up on Wikidata, and the chosen item is type-checked — so identifiers are never invented. Runs in the background after publishing.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					<tbody id="coywolf-seo-ai-desc-row" <?php echo $o['exclude_meta_desc'] ? 'style="display:none"' : ''; ?>>
					<tr>
						<th scope="row"><?php esc_html_e( 'Meta descriptions', 'coywolf-seo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" id="coywolf-seo-ai-descriptions" name="coywolf_seo[ai_descriptions]" value="1" <?php checked( $o['ai_descriptions'] ); ?> />
								<?php esc_html_e( 'Automatically write a meta description when a post or page is published or updated', 'coywolf-seo' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Claude summarizes the content in under 200 characters, using your API key below. The summary replaces the excerpt as the meta description, the Open Graph description, and the Article schema description. Nothing is generated while meta descriptions are excluded.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					</tbody>
					<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enrich all content', 'coywolf-seo' ); ?></th>
						<td>
							<div id="coywolf-seo-bulk-area" data-status="<?php echo esc_attr( Coywolf_SEO::instance()->ai()->bulk_status()['status'] ); ?>">
								<?php $this->render_bulk_controls(); ?>
							</div>
							<p class="description" id="coywolf-seo-bulk-estimate" data-loading="<?php esc_attr_e( 'Calculating the estimated cost…', 'coywolf-seo' ); ?>"><em><?php esc_html_e( 'Calculating the estimated cost…', 'coywolf-seo' ); ?></em></p>
							<p>
								<button type="button" class="button" id="coywolf-seo-ai-test"><?php esc_html_e( 'Test API access', 'coywolf-seo' ); ?></button>
								<span class="description" id="coywolf-seo-ai-test-result"></span>
							</p>
							<p class="description"><strong><?php esc_html_e( 'Use sparingly:', 'coywolf-seo' ); ?></strong> <?php esc_html_e( 'this runs the enabled AI features over every published post and page through Anthropic\'s Batches API at 50% of standard token prices. Results can take up to an hour (occasionally longer) and incur API costs each run — content already analyzed with the current settings is skipped automatically.', 'coywolf-seo' ); ?></p>
							<?php $coywolf_seo_usage = Coywolf_SEO_AI_Batch::usage_summary( Coywolf_SEO::instance()->ai()->model() ); ?>
							<?php if ( ! empty( $coywolf_seo_usage ) ) : ?>
								<p class="description">
									<?php
									if ( $coywolf_seo_usage['avg_cost'] > 0 ) {
										printf(
											/* translators: 1: posts enriched, 2: avg input tokens, 3: avg output tokens, 4: estimated dollars. */
											esc_html__( 'Lifetime: %1$d posts enriched · average %2$s input + %3$s output tokens ≈ $%4$s per post at batch rates (estimate).', 'coywolf-seo' ),
											(int) $coywolf_seo_usage['posts'],
											esc_html( number_format_i18n( $coywolf_seo_usage['avg_input'] ) ),
											esc_html( number_format_i18n( $coywolf_seo_usage['avg_output'] ) ),
											esc_html( number_format( $coywolf_seo_usage['avg_cost'], 4 ) )
										);
									} else {
										printf(
											/* translators: 1: posts enriched, 2: avg input tokens, 3: avg output tokens. */
											esc_html__( 'Lifetime: %1$d posts enriched · average %2$s input + %3$s output tokens per post (no price data for this model).', 'coywolf-seo' ),
											(int) $coywolf_seo_usage['posts'],
											esc_html( number_format_i18n( $coywolf_seo_usage['avg_input'] ) ),
											esc_html( number_format_i18n( $coywolf_seo_usage['avg_output'] ) )
										);
									}
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					</tbody>
					<tbody id="coywolf-seo-ai-fields" <?php echo ( $o['ai_enabled'] || $o['ai_descriptions'] ) ? '' : 'style="display:none"'; ?>>
					<tr>
						<th scope="row"><label for="coywolf-seo-ai-key"><?php esc_html_e( 'Claude API key', 'coywolf-seo' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="coywolf-seo-ai-key" name="coywolf_seo[ai_api_key]" value="" autocomplete="off" placeholder="<?php echo esc_attr( '' !== (string) $o['ai_api_key'] ? __( 'Saved — enter a new key to replace it', 'coywolf-seo' ) : 'sk-ant-…' ); ?>" />
							<?php if ( '' !== (string) $o['ai_api_key'] ) : ?>
								<a class="button-link button-link-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=coywolf_seo_remove_ai_key' ), 'coywolf_seo_remove_ai_key' ) ); ?>"><?php esc_html_e( 'Remove', 'coywolf-seo' ); ?></a>
							<?php endif; ?>
							<p class="description">
								<?php
								printf(
									/* translators: 1: Anthropic console URL, 2: constant name. */
									esc_html__( 'Your own Anthropic API key, created at %1$s. Stored server-side and never shown again. Remove deletes the saved key immediately. You can define %2$s in wp-config.php instead.', 'coywolf-seo' ),
									'<a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener noreferrer">console.anthropic.com</a>',
									'<code>ANTHROPIC_API_KEY</code>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="coywolf-seo-ai-model"><?php esc_html_e( 'Model', 'coywolf-seo' ); ?></label></th>
						<td>
							<?php
							$coywolf_seo_models = Coywolf_SEO::instance()->ai()->available_models();
							if ( empty( $coywolf_seo_models ) ) {
								$coywolf_seo_models = array(
									array( 'id' => 'claude-opus-4-8', 'name' => 'Claude Opus 4.8' ),
									array( 'id' => 'claude-sonnet-4-6', 'name' => 'Claude Sonnet 4.6' ),
									array( 'id' => 'claude-haiku-4-5-20251001', 'name' => 'Claude Haiku 4.5' ),
								);
							}
							$coywolf_seo_model_saved = (string) $o['ai_model'];
							$coywolf_seo_model_known = '' === $coywolf_seo_model_saved;
							?>
							<select id="coywolf-seo-ai-model" name="coywolf_seo[ai_model]">
								<option value=""><?php esc_html_e( 'Default (Claude Opus 4.8)', 'coywolf-seo' ); ?></option>
								<?php foreach ( $coywolf_seo_models as $coywolf_seo_model ) : ?>
									<?php $coywolf_seo_model_known = $coywolf_seo_model_known || $coywolf_seo_model['id'] === $coywolf_seo_model_saved; ?>
									<option value="<?php echo esc_attr( $coywolf_seo_model['id'] ); ?>" <?php selected( $coywolf_seo_model_saved, $coywolf_seo_model['id'] ); ?>><?php echo esc_html( $coywolf_seo_model['name'] . ' — ' . $coywolf_seo_model['id'] ); ?></option>
								<?php endforeach; ?>
								<?php if ( ! $coywolf_seo_model_known ) : ?>
									<option value="<?php echo esc_attr( $coywolf_seo_model_saved ); ?>" selected><?php echo esc_html( $coywolf_seo_model_saved ); ?></option>
								<?php endif; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Used for entity detection and meta descriptions. The list comes from the models available to your key. Changing the model re-analyzes each post on its next save (or via Re-analyze).', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'IndexNow', 'coywolf-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'IndexNow', 'coywolf-seo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="coywolf_seo[indexnow_enabled]" value="1" <?php checked( $o['indexnow_enabled'] ); ?> />
								<?php esc_html_e( 'Ping Bing via IndexNow whenever a post or page is published, updated, or deleted', 'coywolf-seo' ); ?>
							</label>
							<?php if ( '' !== (string) $o['indexnow_key'] ) : ?>
								<p class="description">
									<?php esc_html_e( 'Key file:', 'coywolf-seo' ); ?>
									<a href="<?php echo esc_url( home_url( '/' . $o['indexnow_key'] . '.txt' ) ); ?>" target="_blank" rel="noopener noreferrer"><code><?php echo esc_html( '/' . $o['indexnow_key'] . '.txt' ); ?></code></a>
									<?php esc_html_e( '(served by the plugin — no file is written).', 'coywolf-seo' ); ?>
								</p>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'A site key is generated automatically when you enable this.', 'coywolf-seo' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Sitemaps', 'coywolf-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Native XML sitemap', 'coywolf-seo' ); ?></th>
						<td>
							<fieldset>
								<label><input type="checkbox" name="coywolf_seo[sitemap_exclude_posts]" value="1" <?php checked( $o['sitemap_exclude_posts'] ); ?> /> <?php esc_html_e( 'Exclude the Posts sitemap', 'coywolf-seo' ); ?></label><br />
								<label><input type="checkbox" name="coywolf_seo[sitemap_exclude_pages]" value="1" <?php checked( $o['sitemap_exclude_pages'] ); ?> /> <?php esc_html_e( 'Exclude the Pages sitemap', 'coywolf-seo' ); ?></label><br />
								<label><input type="checkbox" name="coywolf_seo[sitemap_exclude_categories]" value="1" <?php checked( $o['sitemap_exclude_categories'] ); ?> /> <?php esc_html_e( 'Exclude the Categories sitemap', 'coywolf-seo' ); ?></label><br />
								<label><input type="checkbox" name="coywolf_seo[sitemap_exclude_users]" value="1" <?php checked( $o['sitemap_exclude_users'] ); ?> /> <?php esc_html_e( 'Exclude the Users sitemap', 'coywolf-seo' ); ?></label>
							</fieldset>
							<p class="description">
								<?php
								printf(
									/* translators: %s: the sitemap file name. */
									esc_html__( 'WordPress serves its own sitemap at %s. Anything excluded here disappears from it; everything else stays exactly as WordPress generates it.', 'coywolf-seo' ),
									'<code>/wp-sitemap.xml</code>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'News', 'coywolf-seo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="coywolf_seo[news_enabled]" value="1" <?php checked( $o['news_enabled'] ); ?> />
								<?php
								printf(
									/* translators: %s: the sitemap file name. */
									esc_html__( 'Serve a News XML sitemap at %s with articles from the last 48 hours', 'coywolf-seo' ),
									'<code>/' . esc_html( Coywolf_SEO_News_Sitemap::FILENAME ) . '</code>'
								);
								?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Include', 'coywolf-seo' ); ?></th>
						<td>
							<label><input type="checkbox" name="coywolf_seo[news_include_posts]" value="1" <?php checked( $o['news_include_posts'] ); ?> /> <?php esc_html_e( 'Posts', 'coywolf-seo' ); ?></label>
							&nbsp;&nbsp;
							<label><input type="checkbox" name="coywolf_seo[news_include_pages]" value="1" <?php checked( $o['news_include_pages'] ); ?> /> <?php esc_html_e( 'Pages', 'coywolf-seo' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="coywolf-seo-news-cat-mode"><?php esc_html_e( 'Categories', 'coywolf-seo' ); ?></label></th>
						<td>
							<select id="coywolf-seo-news-cat-mode" name="coywolf_seo[news_cat_mode]">
								<option value="all" <?php selected( $o['news_cat_mode'], 'all' ); ?>><?php esc_html_e( 'All categories', 'coywolf-seo' ); ?></option>
								<option value="include" <?php selected( $o['news_cat_mode'], 'include' ); ?>><?php esc_html_e( 'Only these categories', 'coywolf-seo' ); ?></option>
								<option value="exclude" <?php selected( $o['news_cat_mode'], 'exclude' ); ?>><?php esc_html_e( 'All except these categories', 'coywolf-seo' ); ?></option>
							</select>
							<div id="coywolf-seo-news-cats" class="coywolf-seo-cat-list" <?php echo ( 'all' === $o['news_cat_mode'] ) ? 'style="display:none"' : ''; ?>>
								<?php foreach ( get_categories( array( 'hide_empty' => false ) ) as $cat ) : ?>
									<label>
										<input type="checkbox" name="coywolf_seo[news_cats][]" value="<?php echo esc_attr( (string) $cat->term_id ); ?>" <?php checked( in_array( $cat->term_id, array_map( 'intval', (array) $o['news_cats'] ), true ) ); ?> />
										<?php echo esc_html( $cat->name ); ?>
									</label><br />
								<?php endforeach; ?>
							</div>
							<p class="description"><?php esc_html_e( 'Applies to posts; pages have no categories.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'coywolf-seo' ) ); ?>
			</form>
		</div>
		<?php
	}
}
