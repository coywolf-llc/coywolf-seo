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
		add_action( 'admin_head', array( $this, 'hide_hidden_pages' ) );
		add_action( 'admin_post_coywolf_seo_save_site', array( $this, 'save_site_details' ) );
		add_action( 'admin_post_coywolf_seo_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_coywolf_seo_remove_ai_key', array( $this, 'remove_ai_key' ) );
		add_action( 'wp_ajax_coywolf_seo_bulk_action', array( $this, 'ajax_bulk_action' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_saved_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_activation_notice' ) );
	}

	/**
	 * Remove menu-only entries for hidden-but-routable pages (the Link Manager
	 * "Edit Link" screen, reached from the All Links table) so the URL works
	 * while no menu item shows. Removing at admin_head — not at registration —
	 * keeps the page routable.
	 */
	public function hide_hidden_pages() {
		if ( Coywolf_SEO_Options::feature_enabled( 'links' ) ) {
			remove_submenu_page( self::SLUG_SITE, Coywolf_SEO_Link_Manager::EDIT_SLUG );
		}
		// Robots.txt Add Rule + editor pages stay routable but show no menu item;
		// both are reached from the All Rules page.
		if ( Coywolf_SEO_Options::feature_enabled( 'robots' ) ) {
			remove_submenu_page( self::SLUG_SITE, Coywolf_SEO_Robots::PAGE_EDIT );
			remove_submenu_page( self::SLUG_SITE, Coywolf_SEO_Robots::PAGE_ROBOTS );
		}
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
				<div class="coywolf-seo-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $coywolf_seo_bulk['percent'] ); ?>"><div class="coywolf-seo-progress-bar" style="width:<?php echo esc_attr( (string) $coywolf_seo_bulk['percent'] ); ?>%"></div></div>
				<p class="description coywolf-seo-bulk-text" role="status" aria-live="polite" aria-atomic="true">
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
		// Authors only matters when Schema.org markup (Person/author output) is on.
		if ( Coywolf_SEO_Options::feature_enabled( 'schema' ) ) {
			add_submenu_page(
				self::SLUG_SITE,
				__( 'Authors', 'coywolf-seo' ),
				__( 'Authors', 'coywolf-seo' ),
				self::CAPABILITY,
				Coywolf_SEO_Authors::SLUG,
				array( Coywolf_SEO::instance()->authors(), 'render' )
			);
		}
		// Image Text is part of AI enrichment.
		if ( Coywolf_SEO_Options::feature_enabled( 'ai' ) ) {
			add_submenu_page(
				self::SLUG_SITE,
				__( 'Image Text', 'coywolf-seo' ),
				__( 'Image Text', 'coywolf-seo' ),
				self::CAPABILITY,
				Coywolf_SEO_Image_Text::SLUG,
				array( Coywolf_SEO::instance()->image_text(), 'render_page' )
			);
		}
		// Link Manager — hidden when the feature is turned off.
		if ( Coywolf_SEO_Options::feature_enabled( 'links' ) ) {
			add_submenu_page(
				self::SLUG_SITE,
				__( 'Link Manager', 'coywolf-seo' ),
				__( 'Link Manager', 'coywolf-seo' ),
				self::CAPABILITY,
				Coywolf_SEO_Link_Manager::SLUG,
				array( Coywolf_SEO::instance()->link_manager(), 'render_page' )
			);
			// Hidden per-link Edit page (reached from the All Links table).
			// Registered under the real parent so admin.php routing works, then
			// removed from the menu UI on admin_head (hide_hidden_pages()).
			add_submenu_page(
				self::SLUG_SITE,
				__( 'Edit Link', 'coywolf-seo' ),
				__( 'Edit Link', 'coywolf-seo' ),
				self::CAPABILITY,
				Coywolf_SEO_Link_Manager::EDIT_SLUG,
				array( Coywolf_SEO::instance()->link_manager(), 'render_edit_link_page' )
			);
		}
		// Redirects — hidden when the feature is turned off (the pending-count
		// bubble query is skipped too).
		if ( Coywolf_SEO_Options::feature_enabled( 'redirects' ) ) {
			$pending = Coywolf_SEO::instance()->redirects()->pending_count();
			$bubble  = $pending > 0 ? ' <span class="awaiting-mod">' . esc_html( (string) $pending ) . '</span>' : '';
			add_submenu_page(
				self::SLUG_SITE,
				__( 'Redirects', 'coywolf-seo' ),
				__( 'Redirects', 'coywolf-seo' ) . $bubble,
				self::CAPABILITY,
				Coywolf_SEO_Redirects::SLUG,
				array( Coywolf_SEO::instance()->redirects_admin(), 'render' )
			);
		}
		// Robots.txt Manager — the "Robots.txt" menu item lands on the All Rules
		// page; hidden when the feature is turned off.
		if ( Coywolf_SEO_Options::feature_enabled( 'robots' ) ) {
			add_submenu_page(
				self::SLUG_SITE,
				__( 'Robots.txt', 'coywolf-seo' ),
				__( 'Robots.txt', 'coywolf-seo' ),
				self::CAPABILITY,
				Coywolf_SEO_Robots::PAGE_LIST,
				array( Coywolf_SEO::instance()->robots(), 'render_list_page' )
			);
			// Hidden Add Rule + Robots.txt editor pages (reached from All Rules).
			// Registered under the real parent so admin.php routing works, then
			// removed from the menu UI on admin_head (hide_hidden_pages()).
			add_submenu_page(
				self::SLUG_SITE,
				__( 'Add Rule', 'coywolf-seo' ),
				__( 'Add Rule', 'coywolf-seo' ),
				self::CAPABILITY,
				Coywolf_SEO_Robots::PAGE_EDIT,
				array( Coywolf_SEO::instance()->robots(), 'render_edit_page' )
			);
			add_submenu_page(
				self::SLUG_SITE,
				__( 'Edit Robots.txt', 'coywolf-seo' ),
				__( 'Edit Robots.txt', 'coywolf-seo' ),
				self::CAPABILITY,
				Coywolf_SEO_Robots::PAGE_ROBOTS,
				array( Coywolf_SEO::instance()->robots(), 'render_robots_page' )
			);
		}
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
	 * Remove one AI service's saved API key immediately (its own endpoint so
	 * no Save round-trip is needed). The service is read from the request and
	 * validated against the known provider ids, defaulting to the active one.
	 */
	public function remove_ai_key() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Coywolf SEO settings.', 'coywolf-seo' ) );
		}
		check_admin_referer( 'coywolf_seo_remove_ai_key' );
		$service = isset( $_GET['service'] ) ? sanitize_key( wp_unslash( $_GET['service'] ) ) : '';
		if ( ! in_array( $service, Coywolf_SEO_AI_Providers::ids(), true ) ) {
			$service = Coywolf_SEO_AI_Providers::current_id();
		}
		$provider = Coywolf_SEO_AI_Providers::get( $service );
		Coywolf_SEO_Options::update( array( $provider->key_option() => '' ) );
		delete_transient( 'coywolf_seo_ai_models_' . $service );
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
					array( 'jquery', 'jquery-ui-sortable' ),
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
			array( 'jquery', 'jquery-ui-sortable' ),
			Coywolf_SEO::VERSION,
			true
		);
		wp_localize_script(
			'coywolf-seo-admin',
			'CoywolfSEOAdmin',
			array(
				'propertyInputs'  => Coywolf_SEO_Options::property_inputs(),
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'bulkStatusNonce' => wp_create_nonce( 'coywolf_seo_bulk_status' ),
				'bulkActionNonce' => wp_create_nonce( 'coywolf_seo_bulk_action' ),
				'i18n'            => array(
					'selectImage'       => __( 'Select image', 'coywolf-seo' ),
					'pasteOrSelect'     => __( 'Paste an image URL or select one', 'coywolf-seo' ),
					'removeProperty'    => __( 'Remove property', 'coywolf-seo' ),
					'remove'            => __( 'Remove', 'coywolf-seo' ),
					'confirmDelete'     => __( 'Delete this redirect?', 'coywolf-seo' ),
					'confirmBulkEnrich' => __( 'Enrich ALL published posts and pages now? This runs in the background, can take a while, and incurs Anthropic API costs. Content already analyzed with the current settings is skipped.', 'coywolf-seo' ),
					'confirmBulkForce'  => __( 'Re-analyze EVERY published post and page now, including content that is already current? Every item makes fresh API calls, so this costs the full estimated amount each time.', 'coywolf-seo' ),
					'confirmBulkCancel' => __( 'Cancel this run for good? The remaining queue is discarded — a new run would re-check every post from the start (already-analyzed content is skipped at no cost).', 'coywolf-seo' ),
					'estimateLine'      => __( '%POSTS% posts need enrichment (%SKIPPED% already current). Estimated cost: ~$%COST% at batch rates for %MODEL%. Your Anthropic balance (and any Workspace spend limit) must cover about $%RESERVE% for the largest batch\'s upfront check.', 'coywolf-seo' ),
					'estimateNone'      => __( 'Everything is already analyzed with the current settings — re-analyzing everything (%POSTS% posts, ~$%COST%) will incur new costs.', 'coywolf-seo' ),
					'estimateEmpty'     => __( 'There is no published content to enrich yet.', 'coywolf-seo' ),
					'estimateUnsaved'   => __( '(Previewing an unsaved model — the run uses the saved Model setting.)', 'coywolf-seo' ),
					'estimateHistory'   => __( 'Based on your measured average usage.', 'coywolf-seo' ),
					'estimateHeuristic' => __( 'Rough estimate based on content length.', 'coywolf-seo' ),
					'testRunning'       => __( 'Testing — this makes one tiny paid call…', 'coywolf-seo' ),
					'testMessages'      => __( 'Regular API:', 'coywolf-seo' ),
					'testBatches'       => __( 'Batches API:', 'coywolf-seo' ),
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
		} elseif ( 'image-text' === $saved ) {
			$message = __( 'Extra instructions saved.', 'coywolf-seo' );
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
		// The property repeater posts indexed rows. Only write when the
		// Schema.org controls are actually shown — when Schema is off they are
		// not rendered, so a save must not wipe the stored properties.
		if ( Coywolf_SEO_Options::feature_enabled( 'schema' ) ) {
			$raw['org_properties'] = isset( $raw['org_rows'] ) && is_array( $raw['org_rows'] ) ? array_values( $raw['org_rows'] ) : array();
		}

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

		foreach ( array( 'force_rewrite_titles', 'exclude_meta_desc', 'robots_index', 'robots_follow', 'robots_max_image', 'robots_max_snippet', 'robots_max_video', 'indexnow_enabled', 'sitemap_exclude_posts', 'sitemap_exclude_pages', 'sitemap_exclude_categories', 'sitemap_exclude_users', 'news_enabled', 'news_include_posts', 'news_include_pages', 'ai_descriptions', 'image_text_write_alt', 'image_text_write_title', 'image_text_write_caption', 'image_text_write_description', 'image_text_overwrite', 'feature_ai_off', 'feature_schema_off', 'feature_sitemaps_off', 'feature_links_off', 'feature_redirects_off', 'feature_robots_off' ) as $key ) {
			$raw[ $key ] = ! empty( $raw[ $key ] );
		}
		// Entity detection: its checkbox is disabled (so omitted from POST) when
		// Schema.org markup is off, so only record it when the control was shown
		// — otherwise leave the stored preference untouched.
		if ( Coywolf_SEO_Options::feature_enabled( 'schema' ) ) {
			$raw['ai_enabled'] = ! empty( $raw['ai_enabled'] );
		} else {
			unset( $raw['ai_enabled'] );
		}
		if ( empty( $raw['news_cats'] ) ) {
			$raw['news_cats'] = array();
		}

		// Map each provider's API-key option to its service id, so the
		// write-only handling and the model-list cache stay per-service.
		$ai_key_options = array();
		foreach ( Coywolf_SEO_AI_Providers::all() as $ai_sid => $ai_provider ) {
			$ai_key_options[ $ai_provider->key_option() ] = $ai_sid;
		}

		// The API key fields are write-only: an empty submission keeps the
		// stored key (removal happens through each field's own Remove link). A
		// non-empty submission refreshes that service's cached model list.
		foreach ( $ai_key_options as $ai_key_option => $ai_sid ) {
			if ( ! isset( $raw[ $ai_key_option ] ) ) {
				continue;
			}
			if ( '' === trim( (string) $raw[ $ai_key_option ] ) ) {
				unset( $raw[ $ai_key_option ] );
				continue;
			}
			delete_transient( 'coywolf_seo_ai_models_' . $ai_sid ); // New key: refresh the model list.
		}

		$news_before = (bool) Coywolf_SEO_Options::get( 'news_enabled' );
		$clean       = Coywolf_SEO_Options::sanitize( $raw );
		// Turning AI enrichment off deletes every saved API key (keys in
		// wp-config.php are then ignored while off). The key fields are
		// write-only, so force each empty here rather than relying on the
		// empty-field path, and clear the per-service model caches.
		if ( ! empty( $clean['feature_ai_off'] ) ) {
			foreach ( $ai_key_options as $ai_key_option => $ai_sid ) {
				$clean[ $ai_key_option ] = '';
				delete_transient( 'coywolf_seo_ai_models_' . $ai_sid );
			}
		}
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
		// Robots.txt Manager fields live in this same form (no separate save), so
		// persist them here too. The nonce + capability are already verified above;
		// this also runs the mode-switch side effects (write/remove the file).
		if ( Coywolf_SEO_Options::feature_enabled( 'robots' ) ) {
			Coywolf_SEO::instance()->robots()->save_settings_fields();
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
				<td class="coywolf-seo-drag-cell"><span class="coywolf-seo-drag-handle dashicons dashicons-sort" aria-hidden="true"></span></td>
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
				<td><button type="button" class="button coywolf-seo-remove-row" aria-label="<?php esc_attr_e( 'Remove property', 'coywolf-seo' ); ?>"><?php esc_html_e( 'Remove', 'coywolf-seo' ); ?></button></td>
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
					<?php if ( Coywolf_SEO_Options::feature_enabled( 'schema' ) ) : ?>
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
					<?php endif; // End Organization / Person controls. ?>
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

			<?php
			// Jump links to each settings section. Only sections actually rendered
			// (per the same feature gates) are listed, so no link is ever dead.
			$coywolf_seo_toc = array(
				array( 'coywolf-seo-section-general', __( 'General settings', 'coywolf-seo' ), true ),
				array( 'coywolf-seo-section-ai', __( 'AI enrichment', 'coywolf-seo' ), Coywolf_SEO_Options::feature_enabled( 'ai' ) ),
				array( 'coywolf-seo-section-image-text', __( 'Image Text', 'coywolf-seo' ), Coywolf_SEO_Options::feature_enabled( 'ai' ) ),
				array( 'coywolf-seo-section-links', __( 'Link Manager', 'coywolf-seo' ), Coywolf_SEO_Options::feature_enabled( 'links' ) ),
				array( 'coywolf-seo-section-indexnow', __( 'IndexNow', 'coywolf-seo' ), true ),
				array( 'coywolf-seo-section-sitemaps', __( 'Sitemaps', 'coywolf-seo' ), Coywolf_SEO_Options::feature_enabled( 'sitemaps' ) ),
				array( 'coywolf-seo-section-robots', __( 'Robots.txt Manager', 'coywolf-seo' ), Coywolf_SEO_Options::feature_enabled( 'robots' ) ),
				array( 'coywolf-seo-section-toggles', __( 'Turn off features', 'coywolf-seo' ), true ),
				array( 'coywolf-seo-section-enrich', __( 'Enrich all content', 'coywolf-seo' ), Coywolf_SEO_Options::feature_enabled( 'ai' ) ),
			);
			?>
			<ul class="coywolf-seo-settings-toc">
				<?php foreach ( $coywolf_seo_toc as $coywolf_seo_toc_item ) : ?>
					<?php if ( $coywolf_seo_toc_item[2] ) : ?>
						<li><a href="#<?php echo esc_attr( $coywolf_seo_toc_item[0] ); ?>"><?php echo esc_html( $coywolf_seo_toc_item[1] ); ?></a></li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="coywolf_seo_save_settings" />
				<?php wp_nonce_field( 'coywolf_seo_settings' ); ?>

				<h2 id="coywolf-seo-section-general"><?php esc_html_e( 'General settings', 'coywolf-seo' ); ?></h2>
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

				<?php if ( Coywolf_SEO_Options::feature_enabled( 'ai' ) ) : ?>
				<h2 id="coywolf-seo-section-ai"><?php esc_html_e( 'AI enrichment', 'coywolf-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Entity detection', 'coywolf-seo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" id="coywolf-seo-ai-enabled" name="coywolf_seo[ai_enabled]" value="1" <?php checked( $o['ai_enabled'] ); ?> <?php disabled( Coywolf_SEO_Options::feature_enabled( 'schema' ), false ); ?> />
								<?php esc_html_e( 'Analyze posts and pages with AI when they are published or updated, and add the detected entities to their Article schema', 'coywolf-seo' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Main subjects become the about property and passing references become mentions. Every entity is grounded against Wikidata — the AI only extracts names, real items are looked up on Wikidata, and the chosen item is type-checked — so identifiers are never invented. Runs in the background after publishing.', 'coywolf-seo' ); ?></p>
							<?php if ( ! Coywolf_SEO_Options::feature_enabled( 'schema' ) ) : ?>
								<p class="description"><strong><?php esc_html_e( 'Entity detection is off because Schema.org markup is turned off.', 'coywolf-seo' ); ?></strong></p>
							<?php endif; ?>
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
							<p class="description"><?php esc_html_e( 'The AI summarizes the content in under 200 characters, using your API key below. The summary replaces the excerpt as the meta description, the Open Graph description, and the Article schema description. Nothing is generated while meta descriptions are excluded.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					</tbody>
					<tbody id="coywolf-seo-ai-fields" <?php echo ( $o['ai_enabled'] || $o['ai_descriptions'] ) ? '' : 'style="display:none"'; ?>>
						<?php $coywolf_seo_ai_current = Coywolf_SEO_AI_Providers::current_id(); ?>
						<tr>
							<th scope="row"><label for="coywolf-seo-ai-service"><?php esc_html_e( 'AI service', 'coywolf-seo' ); ?></label></th>
							<td>
								<select id="coywolf-seo-ai-service" name="coywolf_seo[ai_service]">
									<?php foreach ( Coywolf_SEO_AI_Providers::choices() as $coywolf_seo_sid => $coywolf_seo_label ) : ?>
										<option value="<?php echo esc_attr( $coywolf_seo_sid ); ?>" <?php selected( $coywolf_seo_ai_current, $coywolf_seo_sid ); ?>><?php echo esc_html( $coywolf_seo_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Only one service is used at a time. Switch services and save; configure that service’s key and model below.', 'coywolf-seo' ); ?></p>
							</td>
						</tr>
						<?php foreach ( Coywolf_SEO_AI_Providers::all() as $coywolf_seo_sid => $coywolf_seo_provider ) : ?>
							<?php
							$coywolf_seo_is_active  = ( $coywolf_seo_sid === $coywolf_seo_ai_current );
							$coywolf_seo_row_hidden = $coywolf_seo_is_active ? '' : ' style="display:none"';
							$coywolf_seo_key_option = $coywolf_seo_provider->key_option();
							$coywolf_seo_key_status = $coywolf_seo_provider->key_status();
							$coywolf_seo_key_const  = $coywolf_seo_provider->key_constant();
							$coywolf_seo_wpconfig   = $coywolf_seo_provider->wpconfig_help();
							?>
						<tr class="coywolf-seo-ai-svc" data-service="<?php echo esc_attr( $coywolf_seo_sid ); ?>"<?php echo $coywolf_seo_row_hidden; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup. ?>>
							<th scope="row"><?php /* translators: %s: AI service label. */ printf( esc_html__( '%s API key', 'coywolf-seo' ), esc_html( $coywolf_seo_provider->label() ) ); ?></th>
							<td>
								<input type="password" class="regular-text" name="coywolf_seo[<?php echo esc_attr( $coywolf_seo_key_option ); ?>]" value="" autocomplete="off" placeholder="<?php echo esc_attr( $coywolf_seo_key_status['saved'] ? __( 'Saved — enter a new key to replace it', 'coywolf-seo' ) : __( 'Enter your API key', 'coywolf-seo' ) ); ?>" />
								<?php if ( $coywolf_seo_key_status['saved'] ) : ?>
									<a class="button-link button-link-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=coywolf_seo_remove_ai_key&service=' . rawurlencode( $coywolf_seo_sid ) ), 'coywolf_seo_remove_ai_key' ) ); ?>"><?php esc_html_e( 'Remove', 'coywolf-seo' ); ?></a>
								<?php endif; ?>
								<p class="description">
									<?php
									printf(
										/* translators: 1: AI service label, 2: constant name. */
										esc_html__( 'Your own %1$s API key. Stored server-side and never shown again. Remove deletes the saved key immediately. For better security you can define %2$s in wp-config.php instead — see below.', 'coywolf-seo' ),
										esc_html( $coywolf_seo_provider->label() ),
										'<code>' . esc_html( $coywolf_seo_key_const ) . '</code>'
									);
									?>
								</p>
								<details class="coywolf-seo-wpconfig">
									<summary><?php esc_html_e( 'Add the key in wp-config.php instead (recommended)', 'coywolf-seo' ); ?></summary>
									<p><?php esc_html_e( 'Keeping the key in wp-config.php instead of the database means a database leak (for example, a stolen backup) can’t expose it. Add this line to wp-config.php, anywhere above the line that reads “/* That’s all, stop editing! Happy publishing. */”:', 'coywolf-seo' ); ?></p>
									<pre class="coywolf-seo-code"><code><?php echo esc_html( $coywolf_seo_wpconfig['define'] ); ?></code></pre>
									<p><?php echo esc_html( $coywolf_seo_wpconfig['help'] ); ?></p>
									<p>
										<?php
										printf(
											/* translators: %s: constant name. */
											esc_html__( 'The saved key field above takes precedence, so to use the wp-config value leave that field empty (or click Remove). A %s environment variable works the same way if your host lets you set one.', 'coywolf-seo' ),
											'<code>' . esc_html( $coywolf_seo_key_const ) . '</code>'
										);
										?>
									</p>
								</details>
							</td>
						</tr>
						<tr class="coywolf-seo-ai-svc" data-service="<?php echo esc_attr( $coywolf_seo_sid ); ?>"<?php echo $coywolf_seo_row_hidden; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup. ?>>
							<th scope="row"><?php esc_html_e( 'Model', 'coywolf-seo' ); ?></th>
							<td>
								<?php
								if ( $coywolf_seo_is_active ) {
									$coywolf_seo_models = Coywolf_SEO::instance()->ai()->available_models();
									if ( empty( $coywolf_seo_models ) ) {
										$coywolf_seo_models = $coywolf_seo_provider->fallback_models();
									}
								} else {
									$coywolf_seo_models = $coywolf_seo_provider->fallback_models();
								}
								$coywolf_seo_model_saved = (string) $o[ $coywolf_seo_provider->model_option() ];
								$coywolf_seo_model_known = '' === $coywolf_seo_model_saved;
								?>
								<select name="coywolf_seo[<?php echo esc_attr( $coywolf_seo_provider->model_option() ); ?>]">
									<option value=""><?php /* translators: %s: default model id. */ printf( esc_html__( 'Default (%s)', 'coywolf-seo' ), esc_html( $coywolf_seo_provider->default_model() ) ); ?></option>
									<?php foreach ( $coywolf_seo_models as $coywolf_seo_model ) : ?>
										<?php $coywolf_seo_model_known = $coywolf_seo_model_known || $coywolf_seo_model['id'] === $coywolf_seo_model_saved; ?>
										<option value="<?php echo esc_attr( $coywolf_seo_model['id'] ); ?>" <?php selected( $coywolf_seo_model_saved, $coywolf_seo_model['id'] ); ?>><?php echo esc_html( $coywolf_seo_model['name'] . ' — ' . $coywolf_seo_model['id'] ); ?></option>
									<?php endforeach; ?>
									<?php if ( ! $coywolf_seo_model_known ) : ?>
										<option value="<?php echo esc_attr( $coywolf_seo_model_saved ); ?>" selected><?php echo esc_html( $coywolf_seo_model_saved ); ?></option>
									<?php endif; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Used for entity detection and meta descriptions. For the active service the list comes from the models available to your key. Changing the model re-analyzes each post on its next save (or via Re-analyze).', 'coywolf-seo' ); ?></p>
							</td>
						</tr>
						<tr class="coywolf-seo-ai-svc" data-service="<?php echo esc_attr( $coywolf_seo_sid ); ?>"<?php echo $coywolf_seo_row_hidden; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup. ?>>
							<th scope="row"><?php esc_html_e( 'API connection', 'coywolf-seo' ); ?></th>
							<td>
								<?php if ( '' !== $coywolf_seo_key_status['source'] ) : ?>
									<p class="coywolf-seo-key-status coywolf-seo-key-status--ok">
										<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
										<?php
										if ( 'saved' === $coywolf_seo_key_status['source'] ) {
											esc_html_e( 'API key configured — using the saved key.', 'coywolf-seo' );
										} elseif ( 'constant' === $coywolf_seo_key_status['source'] ) {
											printf(
												/* translators: %s: constant name. */
												esc_html__( 'API key configured — using the %s constant from wp-config.php.', 'coywolf-seo' ),
												'<code>' . esc_html( $coywolf_seo_key_const ) . '</code>'
											);
										} else {
											printf(
												/* translators: %s: constant name. */
												esc_html__( 'API key configured — using the %s environment variable.', 'coywolf-seo' ),
												'<code>' . esc_html( $coywolf_seo_key_const ) . '</code>'
											);
										}
										?>
									</p>
									<?php if ( 'saved' === $coywolf_seo_key_status['source'] && ( $coywolf_seo_key_status['constant'] || $coywolf_seo_key_status['env'] ) ) : ?>
										<p class="description">
											<?php
											printf(
												/* translators: %s: constant name. */
												esc_html__( 'A wp-config %s is also defined, but the saved key above takes precedence. Remove the saved key to use the wp-config value.', 'coywolf-seo' ),
												'<code>' . esc_html( $coywolf_seo_key_const ) . '</code>'
											);
											?>
										</p>
									<?php endif; ?>
								<?php else : ?>
									<p class="coywolf-seo-key-status coywolf-seo-key-status--none">
										<span class="dashicons dashicons-warning" aria-hidden="true"></span>
										<?php esc_html_e( 'No API key set yet — add one above or in wp-config.php.', 'coywolf-seo' ); ?>
									</p>
								<?php endif; ?>
								<?php if ( $coywolf_seo_is_active ) : ?>
									<button type="button" class="button" id="coywolf-seo-ai-test"><?php esc_html_e( 'Test API access', 'coywolf-seo' ); ?></button>
									<span class="description" id="coywolf-seo-ai-test-result" role="status" aria-live="polite" aria-atomic="true"></span>
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
						</tbody>
				</table>

				<h2 id="coywolf-seo-section-image-text"><?php esc_html_e( 'Image Text', 'coywolf-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Fields to write', 'coywolf-seo' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Fields to write', 'coywolf-seo' ); ?></legend>
								<label>
									<input type="checkbox" name="coywolf_seo[image_text_write_alt]" value="1" <?php checked( $o['image_text_write_alt'] ); ?> />
									<?php esc_html_e( 'Alternative text', 'coywolf-seo' ); ?>
								</label><br />
								<label>
									<input type="checkbox" name="coywolf_seo[image_text_write_title]" value="1" <?php checked( $o['image_text_write_title'] ); ?> />
									<?php esc_html_e( 'Title', 'coywolf-seo' ); ?>
								</label><br />
								<label>
									<input type="checkbox" name="coywolf_seo[image_text_write_caption]" value="1" <?php checked( $o['image_text_write_caption'] ); ?> />
									<?php esc_html_e( 'Caption', 'coywolf-seo' ); ?>
								</label><br />
								<label>
									<input type="checkbox" name="coywolf_seo[image_text_write_description]" value="1" <?php checked( $o['image_text_write_description'] ); ?> />
									<?php esc_html_e( 'Description', 'coywolf-seo' ); ?>
								</label>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Preselected on the Image Text screen; each run can change them. The API key and per-run model are configured under AI enrichment above.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Existing text', 'coywolf-seo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="coywolf_seo[image_text_overwrite]" value="1" <?php checked( $o['image_text_overwrite'] ); ?> />
								<?php esc_html_e( 'Overwrite text that already exists (default: only fill empty fields)', 'coywolf-seo' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php endif; // End AI enrichment and Image Text defaults. ?>

				<?php if ( Coywolf_SEO_Options::feature_enabled( 'links' ) ) : ?>
				<h2 id="coywolf-seo-section-links"><?php esc_html_e( 'Link Manager', 'coywolf-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="coywolf-seo-lm-speed"><?php esc_html_e( 'Throughput', 'coywolf-seo' ); ?></label></th>
						<td>
							<select id="coywolf-seo-lm-speed" name="coywolf_seo[lm_speed]">
								<option value="polite" <?php selected( $o['lm_speed'], 'polite' ); ?>><?php esc_html_e( 'Polite (3 posts / 4 parallel)', 'coywolf-seo' ); ?></option>
								<option value="default" <?php selected( $o['lm_speed'], 'default' ); ?>><?php esc_html_e( 'Default (5 posts / 8 parallel)', 'coywolf-seo' ); ?></option>
								<option value="fast" <?php selected( $o['lm_speed'], 'fast' ); ?>><?php esc_html_e( 'Fast (15 posts / 16 parallel)', 'coywolf-seo' ); ?></option>
								<option value="faster" <?php selected( $o['lm_speed'], 'faster' ); ?>><?php esc_html_e( 'Faster (30 posts / 24 parallel)', 'coywolf-seo' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'How many posts are processed per batch and how many link responses are checked in parallel. Higher is faster but heavier on your server and the sites being checked.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="coywolf-seo-lm-ua"><?php esc_html_e( 'User-Agent override', 'coywolf-seo' ); ?></label></th>
						<td>
							<input type="text" class="large-text code" id="coywolf-seo-lm-ua" name="coywolf_seo[lm_user_agent]" value="<?php echo esc_attr( $o['lm_user_agent'] ); ?>" placeholder="<?php esc_attr_e( '(bundled Chrome UA)', 'coywolf-seo' ); ?>" />
							<p class="description"><?php esc_html_e( 'Sent when checking link responses. Leave blank to use the bundled Chrome User-Agent.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
				</table>
				<?php endif; // End Link Manager section. ?>

				<h2 id="coywolf-seo-section-indexnow"><?php esc_html_e( 'IndexNow', 'coywolf-seo' ); ?></h2>
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

				<?php if ( Coywolf_SEO_Options::feature_enabled( 'sitemaps' ) ) : ?>
				<h2 id="coywolf-seo-section-sitemaps"><?php esc_html_e( 'Sitemaps', 'coywolf-seo' ); ?></h2>
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
				<?php endif; // End Sitemaps section. ?>

				<?php
				// Robots.txt Manager settings are part of this form and save with
				// the single "Save Settings" button below.
				if ( Coywolf_SEO_Options::feature_enabled( 'robots' ) ) {
					Coywolf_SEO::instance()->robots()->render_settings_section();
				}
				?>

				<h2 id="coywolf-seo-section-toggles"><?php esc_html_e( 'Turn off features', 'coywolf-seo' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Turn off any feature you do not use. Its settings, pages, and saved data are kept and can be turned back on at any time.', 'coywolf-seo' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'AI enrichment', 'coywolf-seo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="coywolf_seo[feature_ai_off]" value="1" <?php checked( $o['feature_ai_off'] ); ?> />
								<?php esc_html_e( 'Turn off AI enrichment', 'coywolf-seo' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Hides Entity detection, Meta descriptions, Enrich all content, the AI service settings, and Image Text (menu and Image block). Saved image text and entities are kept and still used. Your saved API key is deleted; a key in wp-config.php is ignored while this is off.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Schema.org markup', 'coywolf-seo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="coywolf_seo[feature_schema_off]" value="1" <?php checked( $o['feature_schema_off'] ); ?> />
								<?php esc_html_e( 'Turn off Schema.org markup', 'coywolf-seo' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Stops all Schema.org (JSON-LD) output and hides the Organization/Person settings and the Authors page. This also turns off Entity detection. Stored entities and settings are kept.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sitemaps', 'coywolf-seo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="coywolf_seo[feature_sitemaps_off]" value="1" <?php checked( $o['feature_sitemaps_off'] ); ?> />
								<?php esc_html_e( 'Turn off Sitemaps', 'coywolf-seo' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Stops the News XML sitemap and the native XML sitemap exclusions. Your sitemap preferences are kept.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Link Manager', 'coywolf-seo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="coywolf_seo[feature_links_off]" value="1" <?php checked( $o['feature_links_off'] ); ?> />
								<?php esc_html_e( 'Turn off the Link Manager', 'coywolf-seo' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Hides the Link Manager page and stops link scanning. Your saved link data is kept.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Redirects', 'coywolf-seo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="coywolf_seo[feature_redirects_off]" value="1" <?php checked( $o['feature_redirects_off'] ); ?> />
								<?php esc_html_e( 'Turn off Redirects', 'coywolf-seo' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Hides the Redirects page and stops serving redirects, letting another redirect plugin handle them. Your redirect rules are kept.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Robots.txt Manager', 'coywolf-seo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="coywolf_seo[feature_robots_off]" value="1" <?php checked( $o['feature_robots_off'] ); ?> />
								<?php esc_html_e( 'Turn off the Robots.txt Manager', 'coywolf-seo' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Hides the Robots.txt pages and stops managing robots.txt (in virtual mode WordPress serves its default again; a physical robots.txt is left as-is). Your rules and settings are kept.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'coywolf-seo' ) ); ?>
			</form>

			<?php if ( Coywolf_SEO_Options::feature_enabled( 'ai' ) ) : ?>
			<h2 id="coywolf-seo-section-enrich"><?php esc_html_e( 'Enrich all content', 'coywolf-seo' ); ?></h2>
			<table class="form-table" role="presentation">
					<tbody>
					<tr>
						<td colspan="2">
							<div id="coywolf-seo-bulk-area" aria-live="polite" data-status="<?php echo esc_attr( Coywolf_SEO::instance()->ai()->bulk_status()['status'] ); ?>">
								<?php $this->render_bulk_controls(); ?>
							</div>
							<p class="description" id="coywolf-seo-bulk-estimate" role="status" aria-live="polite" aria-atomic="true" data-loading="<?php esc_attr_e( 'Calculating the estimated cost…', 'coywolf-seo' ); ?>"><em><?php esc_html_e( 'Calculating the estimated cost…', 'coywolf-seo' ); ?></em></p>
							<p class="description"><strong><?php esc_html_e( 'Use sparingly:', 'coywolf-seo' ); ?></strong>
								<?php
								printf(
									/* translators: %s: active AI service label. */
									esc_html__( 'this runs the enabled AI features over every published post and page through the selected AI service (%s) using its Batch API, at roughly half the standard token prices. Results can take up to an hour (occasionally longer) and incur API costs each run — content already analyzed with the current settings is skipped automatically.', 'coywolf-seo' ),
									esc_html( Coywolf_SEO_AI_Providers::current()->label() )
								);
								?>
							</p>
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
			</table>
			<?php endif; // End Enrich all content. ?>
		</div>
		<?php
	}
}
