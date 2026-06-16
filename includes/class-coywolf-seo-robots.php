<?php
/**
 * Main controller for Coywolf Robots.txt Manager.
 *
 * Registers the admin screens (Rules list, rule editor, Settings), persists
 * rules, reads/writes robots.txt (physical file or the virtual `robots_txt`
 * filter), and powers the testing-tool AJAX endpoint.
 *
 * @package CoywolfRobotsTxtManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Coywolf_SEO_Robots {

	const VERSION = '1.0.58';

	// Admin page slugs.
	const PAGE_LIST   = 'coywolf-seo-robots';
	const PAGE_EDIT   = 'coywolf-seo-robots-edit';
	const PAGE_ROBOTS = 'coywolf-seo-robots-robots';
	// Settings and Import/Export are now sections embedded in the shared Coywolf
	// SEO Settings / Import-Export pages, so these point at those slugs. The
	// robots action handlers redirect here after saving.
	const PAGE_IMPORT_EXPORT = 'coywolf-seo-import-export';
	const PAGE_SETTINGS      = 'coywolf-seo-settings';

	// Options.
	const OPT_RULES    = 'coywolf_seo_robots_rules';
	const OPT_SITEMAPS = 'coywolf_seo_robots_sitemaps';
	const OPT_MODE     = 'coywolf_seo_robots_mode';      // 'virtual' | 'physical'.
	// Legacy options from the removed remote bot-list auto-update feature,
	// retained only so maybe_clear_legacy_schedule() can purge them on upgrade.
	const OPT_FREQ             = 'coywolf_seo_robots_update_freq'; // 'disabled'|'daily'|'weekly'|'monthly'.
	const OPT_DAY              = 'coywolf_seo_robots_update_day';   // 0 (Sun) .. 6 (Sat).
	const OPT_WEEK             = 'coywolf_seo_robots_update_week';  // 1..4, or 5 = last.
	const OPT_TIME             = 'coywolf_seo_robots_update_time';  // 'HH:MM' (site timezone).
	const OPT_LEGACY_CLEARED   = 'coywolf_seo_robots_legacy_cleared'; // '1' once the removed auto-update leftovers were purged.
	const OPT_IMPORTED         = 'coywolf_seo_robots_imported';     // '1' once existing rules were auto-imported.
	const OPT_WP_BASE          = 'coywolf_seo_robots_wp_base_merged'; // '1' once WordPress's own base rules were folded into the managed set.
	const OPT_RENAMES_APPLIED  = 'coywolf_seo_robots_renames_applied'; // Count of token-rename entries already migrated into the rules.
	const OPT_OMIT_SITEMAPS    = 'coywolf_seo_robots_omit_sitemaps';
	const OPT_OMIT_COMMENTS    = 'coywolf_seo_robots_omit_comments'; // '1' to leave the per-rule description comments out of robots.txt.
	const OPT_SITEMAP_MIGRATED = 'coywolf_seo_robots_sitemaps_migrated'; // '1' once legacy sitemap-type rules moved into OPT_SITEMAPS.
	const OPT_BACKUP           = 'coywolf_seo_robots_backup'; // Original robots.txt captured on first activation (for restore on deactivate).
	const OPT_EMAIL            = 'coywolf_seo_robots_update_email';    // Legacy (removed feature) — purged on upgrade.
	const OPT_EMAIL_TO         = 'coywolf_seo_robots_update_email_to'; // Legacy (removed feature) — purged on upgrade.

	// Legacy WP-Cron hook from the removed auto-update feature. Retained so
	// on_deactivate() and maybe_clear_legacy_schedule() can unschedule it.
	const CRON_HOOK = 'coywolf_seo_robots_update_bots_event';

	// Markers that fence the managed block inside a physical robots.txt.
	const BLOCK_START = '# BEGIN Coywolf Robots.txt Manager';
	const BLOCK_END   = '# END Coywolf Robots.txt Manager';

	/** @var Coywolf_SEO_Robots|null */
	private static $instance = null;

	/** @var array<string,string> Admin page hook suffixes. */
	private $hooks = array();

	/**
	 * When a save is blocked by a conflict, the submitted (unsaved) rule and
	 * the conflict messages are stashed here so render_edit_page() can re-show
	 * the editor with the user's input and an explanation, in the same request.
	 *
	 * @var array<string,mixed>|null
	 */
	private $pending_rule = null;

	/**
	 * Per-request cache of get_rules() (normalized + de-duplicated), plus the
	 * raw option value it was built from. The cache is reused only while the raw
	 * OPT_RULES value is unchanged, so it self-invalidates if the option is
	 * written by any path (not just persist_rules()).
	 *
	 * @var array<int,array<string,mixed>>|null
	 */
	private $rules_cache = null;

	/** @var mixed Raw OPT_RULES value behind {@see $rules_cache}. */
	private $rules_cache_src = null;

	/** @var array<int,string> Conflict messages for {@see $pending_rule}. */
	private $pending_conflicts = array();

	/** @var string|null Cached trailing-slashed site root {@see site_root_path()}. */
	private $site_root = null;

	/**
	 * @return Coywolf_SEO_Robots
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Register hooks. Called by the Coywolf SEO bootstrap. No-ops (leaving the
	 * singleton constructed but inert) when the Robots.txt feature is turned off,
	 * so accessors do not fatal and the saved rules are kept.
	 */
	public function init() {
		if ( ! Coywolf_SEO_Options::feature_enabled( 'robots' ) ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'maybe_migrate_sitemap_rules' ) );
		add_action( 'admin_init', array( $this, 'maybe_apply_token_renames' ) );
		add_action( 'admin_init', array( $this, 'maybe_auto_import_virtual' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_init', array( $this, 'maybe_clear_legacy_schedule' ) );

		// In virtual mode the plugin owns the entire robots.txt: register at the
		// highest priority so we run last and replace whatever WordPress and any
		// other plugin (e.g. Yoast) put in `$output` with ONLY our managed rules
		// (WordPress's own base rules are imported into them). See
		// filter_robots_txt() and merge_wordpress_base().
		add_filter( 'robots_txt', array( $this, 'filter_robots_txt' ), PHP_INT_MAX, 2 );

		add_action( 'wp_ajax_coywolf_seo_robots_test', array( $this, 'ajax_test_rule' ) );
		add_action( 'wp_ajax_coywolf_seo_robots_test_file', array( $this, 'ajax_test_file' ) );
	}

	/* ================================================================== *
	 * Activation / deactivation (called from the bootstrap file).
	 * ================================================================== */

	public static function on_activate() {
		$inst         = self::instance();
		$path         = $inst->robots_txt_path();
		$has_physical = file_exists( $path );

		// FIRST, before touching anything: back up the original robots.txt so
		// deactivation can offer to restore it. import_existing() also calls this
		// (so the original is captured before ANY parse/import path), but do it
		// here too to cover virtual activation, where no import runs yet.
		$inst->maybe_backup_original();

		// Seed a sensible default mode: physical if a file already exists in the
		// web root, else virtual.
		if ( false === get_option( self::OPT_MODE, false ) ) {
			// Not autoloaded: it's only read on /robots.txt and admin screens, not
			// on normal front-end page views.
			add_option( self::OPT_MODE, $has_physical ? 'physical' : 'virtual', '', false );
		}

		// When a physical robots.txt is already present, take it over right away:
		// import its rules and Sitemap links, optimize (dedupe/consolidate), and
		// rewrite the file as a managed block — rather than waiting for the first
		// admin page load. import_existing() rewrites the physical file cleanly
		// (imported directives live in the managed block, not duplicated outside).
		if ( $has_physical ) {
			update_option( self::OPT_MODE, 'physical' );
			$inst->import_existing();
			update_option( self::OPT_IMPORTED, '1', false );
		}
	}

	public static function on_deactivate() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}

		// The Plugins-screen modal appends coywolf_seo_robots_deact=restore|keep to the
		// deactivation link (WordPress's own nonce + capability already gate this
		// request). "restore" puts back the original robots.txt captured on first
		// activation; anything else keeps the plugin's rules. The deactivation
		// hook can't prompt, so the choice is made before this runs.
		$choice = isset( $_REQUEST['coywolf_seo_robots_deact'] ) ? sanitize_key( wp_unslash( $_REQUEST['coywolf_seo_robots_deact'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		self::instance()->handle_restore_choice( $choice );
	}

	/**
	 * Apply the user's restore/keep choice for the managed robots.txt. Shared by
	 * the Plugins-screen deactivation prompt ({@see on_deactivate()}) and the
	 * Settings "Turn off the Robots.txt Manager" prompt.
	 *
	 * - 'restore' puts back the original robots.txt captured on first activation.
	 * - anything else keeps the rules but, in physical mode, strips our markers
	 *   so the file survives as a plain, unmanaged robots.txt.
	 *
	 * @param string $choice 'restore' | 'keep' (default).
	 */
	public function handle_restore_choice( $choice ) {
		if ( 'restore' === $choice ) {
			$this->restore_backup();
			return;
		}
		if ( 'physical' === get_option( self::OPT_MODE, 'virtual' ) ) {
			$this->unwrap_physical_block();
		}
	}

	/**
	 * Restore the robots.txt captured on first activation (see on_activate()).
	 * If the site originally had a physical file, its exact contents are written
	 * back; if it was virtual (no file), any physical file the plugin created is
	 * removed so WordPress serves its default again. Falls back to the "keep"
	 * behaviour when no backup exists.
	 */
	private function restore_backup() {
		$backup = get_option( self::OPT_BACKUP, false );
		if ( ! is_array( $backup ) ) {
			if ( 'physical' === get_option( self::OPT_MODE, 'virtual' ) ) {
				$this->unwrap_physical_block();
			}
			return;
		}

		if ( ! empty( $backup['had_physical'] ) ) {
			$this->write_raw_physical( isset( $backup['content'] ) ? (string) $backup['content'] : '' );
		} else {
			// Originally virtual — remove any physical file we created so the
			// site returns to letting WordPress serve robots.txt.
			$this->delete_physical_file();
		}
	}

	/**
	 * Overwrite the physical robots.txt with exact content (used to restore the
	 * activation backup verbatim — no managed block, no markers).
	 *
	 * @param string $content File contents to write.
	 */
	private function write_raw_physical( $content ) {
		$path = $this->robots_txt_path();
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;
		if ( $wp_filesystem ) {
			$wp_filesystem->put_contents( $path, (string) $content, FS_CHMOD_FILE );
		}
	}

	/**
	 * Capture the original robots.txt — the physical file's raw contents, or
	 * (when there's no file) what WordPress would serve virtually — the first
	 * time it's needed, BEFORE any parsing/importing/fixing/optimizing. Called
	 * at the top of import_existing() (every import path) and on activation, and
	 * guarded so it only ever stores the very first state. Restored on
	 * deactivation if the user asks.
	 */
	private function maybe_backup_original() {
		if ( false !== get_option( self::OPT_BACKUP, false ) ) {
			return;
		}
		$path         = $this->robots_txt_path();
		$has_physical = file_exists( $path );
		if ( $has_physical ) {
			$content = is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		} else {
			$content = $this->virtual_base();
		}
		add_option(
			self::OPT_BACKUP,
			array(
				'content'      => $content,
				'had_physical' => $has_physical,
				'time'         => time(),
			),
			'',
			false
		);
	}

	/**
	 * Strip just the two managed-block marker comments from the physical
	 * robots.txt, keeping everything else (the rules and their per-rule
	 * comments) exactly as written. Used on deactivation.
	 */
	private function unwrap_physical_block() {
		$path = $this->robots_txt_path();

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;
		if ( ! $wp_filesystem || ! $wp_filesystem->exists( $path ) ) {
			return;
		}

		$content = (string) $wp_filesystem->get_contents( $path );
		// Remove only the marker lines (with the blank line each leaves behind).
		$content = preg_replace( '/^[ \t]*' . preg_quote( self::BLOCK_START, '/' ) . "[ \t]*\r?\n?/m", '', $content );
		$content = preg_replace( '/\r?\n?^[ \t]*' . preg_quote( self::BLOCK_END, '/' ) . "[ \t]*$/m", '', (string) $content );
		$content = preg_replace( "/\n{3,}/", "\n\n", (string) $content );
		$content = ltrim( (string) $content, "\n" );

		$wp_filesystem->put_contents( $path, $content, FS_CHMOD_FILE );
	}

	/* ================================================================== *
	 * Admin assets
	 * ================================================================== */

	/**
	 * Load CSS/JS only on the Robots.txt screens, plus the shared SEO Settings /
	 * Import-Export pages (whose robots sections need the sitemap-list and
	 * bot-category JS).
	 *
	 * Gated on the current admin page slug rather than a stored hook suffix,
	 * because Coywolf SEO registers the menu items now.
	 *
	 * @param string $hook Current admin page hook (unused; kept for the hook signature).
	 */
	public function enqueue_assets( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection, no state change.

		$pages = array(
			self::PAGE_LIST,
			self::PAGE_EDIT,
			self::PAGE_ROBOTS,
			self::PAGE_SETTINGS,
			self::PAGE_IMPORT_EXPORT,
		);
		if ( ! in_array( $page, $pages, true ) ) {
			return;
		}

		wp_enqueue_style(
			'coywolf-seo-robots',
			COYWOLF_SEO_URL . 'css/robots-manager.css',
			array(),
			self::VERSION
		);

		wp_enqueue_script(
			'coywolf-seo-robots',
			COYWOLF_SEO_URL . 'js/robots-manager.js',
			array(),
			self::VERSION,
			true
		);

		$data = array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'coywolf_seo_robots_ajax' ),
			'i18n'    => array(
				'allowed'         => __( 'Allowed — this URL would NOT be blocked by this rule.', 'coywolf-seo' ),
				'blocked'         => __( 'Blocked — this rule would disallow crawling of this URL.', 'coywolf-seo' ),
				'noMatch'         => __( 'No match — this rule does not apply to this URL.', 'coywolf-seo' ),
				'enterUrl'        => __( 'Enter a URL or path to test.', 'coywolf-seo' ),
				'testing'         => __( 'Testing…', 'coywolf-seo' ),
				'error'           => __( 'Could not run the test. Please try again.', 'coywolf-seo' ),
				'remove'          => __( 'Remove', 'coywolf-seo' ),
				'selectAll'       => __( 'Select all', 'coywolf-seo' ),
				'deselectAll'     => __( 'Deselect all', 'coywolf-seo' ),
				'addBotEmpty'     => __( 'Enter a user-agent token first.', 'coywolf-seo' ),
				'addSitemapEmpty' => __( 'Enter a sitemap URL first.', 'coywolf-seo' ),
				'copy'            => __( 'Copy robots.txt to clipboard', 'coywolf-seo' ),
				'copied'          => __( 'Copied', 'coywolf-seo' ),
				'copyFailed'      => __( 'Copy failed', 'coywolf-seo' ),
				'fileAllowed'     => __( 'Allowed — this crawler may fetch the URL.', 'coywolf-seo' ),
				'fileBlocked'     => __( 'Blocked — this crawler is disallowed from the URL.', 'coywolf-seo' ),
				'evaluatedAs'     => __( 'Evaluated as user-agent', 'coywolf-seo' ),
				'allRobots'       => __( 'all robots', 'coywolf-seo' ),
				'matchedLabel'    => __( 'matched', 'coywolf-seo' ),
				'onLine'          => __( 'line', 'coywolf-seo' ),
				'noRuleMatched'   => __( 'no rule matched — allowed by default', 'coywolf-seo' ),
				'agentTruncated'  => __( 'only the leading product token is used for matching', 'coywolf-seo' ),
			),
			'types'   => Coywolf_SEO_Robots_Rules::types(),
		);

		wp_localize_script( 'coywolf-seo-robots', 'CoywolfSEORobots', $data );
	}

	/* ================================================================== *
	 * Rules list page
	 * ================================================================== */

	public function render_list_page() {
		if ( ! current_user_can( Coywolf_SEO_Admin::CAPABILITY ) ) {
			return;
		}
		$rules = $this->get_rules();
		$mode  = $this->get_mode();
		?>
		<div class="wrap coywolf-seo-robots">
			<h1 class="wp-heading-inline"><?php echo esc_html__( 'Robots.txt Rules', 'coywolf-seo' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_EDIT ) ); ?>" class="page-title-action">
				<?php echo esc_html__( 'Add Rule', 'coywolf-seo' ); ?>
			</a>
			<?php // No manual import button: an existing physical robots.txt is imported, optimized, and taken over automatically on activation (see on_activate()); virtual rules are imported automatically on first load. ?>
			<hr class="wp-header-end" />

			<?php $this->render_notices(); ?>

			<p class="description">
				<?php
				printf(
					/* translators: %s: mode label */
					esc_html__( 'Mode: %s. Change this on the Settings page.', 'coywolf-seo' ),
					'<strong>' . esc_html( 'physical' === $mode ? __( 'Physical robots.txt file', 'coywolf-seo' ) : __( 'Virtual (served by WordPress)', 'coywolf-seo' ) ) . '</strong>'
				);
				?>
			</p>

			<?php
			// Virtual mode is moot while a physical robots.txt shadows it — warn
			// and offer ways to resolve the clash.
			if ( 'virtual' === $mode && $this->has_physical_robots_txt() ) {
				$this->render_physical_conflict_notice();
			}
			?>

			<?php if ( empty( $rules ) ) : ?>
				<div class="coywolf-seo-robots-empty">
					<p><?php echo esc_html__( 'No rules yet.', 'coywolf-seo' ); ?></p>
					<p><?php echo esc_html__( 'Add a rule to get started.', 'coywolf-seo' ); ?></p>
				</div>
			<?php else : ?>
				<?php
				$per_page = 20;
				$total    = count( $rules );
				$pages    = (int) max( 1, (int) ceil( $total / $per_page ) );
				$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( $paged > $pages ) {
					$paged = $pages;
				}
				$page_rules = array_slice( $rules, ( $paged - 1 ) * $per_page, $per_page );
				$pagination = '';
				if ( $total > $per_page ) {
					$links = paginate_links(
						array(
							'base'      => add_query_arg(
								array(
									'page'  => self::PAGE_LIST,
									'paged' => '%#%',
								),
								admin_url( 'admin.php' )
							),
							'format'    => '',
							'prev_text' => '&lsaquo;',
							'next_text' => '&rsaquo;',
							'total'     => $pages,
							'current'   => $paged,
						)
					);
					if ( $links ) {
						/* translators: %s: number of rules (already formatted for the locale). */
						$pagination = '<div class="tablenav-pages"><span class="displaying-num">' . esc_html( sprintf( _n( '%s rule', '%s rules', $total, 'coywolf-seo' ), number_format_i18n( $total ) ) ) . '</span><span class="pagination-links">' . $links . '</span></div>';
					}
				}
				?>
				<form method="post">
					<?php wp_nonce_field( 'coywolf_seo_robots_bulk' ); ?>
					<input type="hidden" name="coywolf_seo_robots_action" value="bulk" />
					<input type="hidden" name="paged" value="<?php echo (int) $paged; ?>" />

					<div class="tablenav top">
						<div class="alignleft actions bulkactions">
							<label for="coywolf-seo-robots-bulk-action" class="screen-reader-text"><?php echo esc_html__( 'Select bulk action', 'coywolf-seo' ); ?></label>
							<select name="bulk_action" id="coywolf-seo-robots-bulk-action">
								<option value=""><?php echo esc_html__( 'Bulk actions', 'coywolf-seo' ); ?></option>
								<option value="delete"><?php echo esc_html__( 'Delete', 'coywolf-seo' ); ?></option>
							</select>
							<button type="submit" class="button action" onclick="return confirm('<?php echo esc_js( __( 'Delete the selected rules?', 'coywolf-seo' ) ); ?>');"><?php echo esc_html__( 'Apply', 'coywolf-seo' ); ?></button>
						</div>
						<?php echo wp_kses_post( $pagination ); ?>
						<br class="clear" />
					</div>

					<table class="wp-list-table widefat fixed striped coywolf-seo-robots-table">
						<thead>
							<tr>
								<td class="manage-column column-cb check-column"><input type="checkbox" id="coywolf-seo-robots-cb-all" aria-label="<?php echo esc_attr__( 'Select all rules', 'coywolf-seo' ); ?>" /></td>
								<th scope="col" class="column-primary"><?php echo esc_html__( 'Rule', 'coywolf-seo' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Description', 'coywolf-seo' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Applies to', 'coywolf-seo' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'robots.txt', 'coywolf-seo' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $page_rules as $rule ) : ?>
								<?php
								$edit_url = admin_url( 'admin.php?page=' . self::PAGE_EDIT . '&rule=' . rawurlencode( $rule['id'] ) );
								$del_url  = wp_nonce_url(
									admin_url( 'admin.php?page=' . self::PAGE_LIST . '&coywolf_seo_robots_action=delete&rule=' . rawurlencode( $rule['id'] ) ),
									'coywolf_seo_robots_delete_' . $rule['id']
								);
								?>
								<tr>
									<th scope="row" class="check-column"><input type="checkbox" name="rule_ids[]" value="<?php echo esc_attr( $rule['id'] ); ?>" aria-label="<?php echo esc_attr( $rule['name'] ); ?>" /></th>
									<td class="column-primary" data-colname="<?php echo esc_attr__( 'Rule', 'coywolf-seo' ); ?>">
										<strong><a class="row-title" href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $rule['name'] ); ?></a></strong>
										<?php // WordPress row-actions: Edit/Delete sit under the name, hidden until the row is hovered (core admin CSS handles the reveal, sizing, and the red Delete). ?>
										<div class="row-actions">
											<span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html__( 'Edit', 'coywolf-seo' ); ?></a> | </span>
											<span class="trash"><a href="<?php echo esc_url( $del_url ); ?>" class="submitdelete" onclick="return confirm('<?php echo esc_js( __( 'Delete this rule?', 'coywolf-seo' ) ); ?>');"><?php echo esc_html__( 'Delete', 'coywolf-seo' ); ?></a></span>
										</div>
										<button type="button" class="toggle-row"><span class="screen-reader-text"><?php echo esc_html__( 'Show more details', 'coywolf-seo' ); ?></span></button>
									</td>
									<td data-colname="<?php echo esc_attr__( 'Description', 'coywolf-seo' ); ?>">
										<?php echo esc_html( $rule['description'] ); ?>
									</td>
									<td data-colname="<?php echo esc_attr__( 'Applies to', 'coywolf-seo' ); ?>">
										<?php echo esc_html( $this->agents_summary( isset( $rule['agents'] ) ? $rule['agents'] : array() ) ); ?>
									</td>
									<td data-colname="<?php echo esc_attr__( 'robots.txt', 'coywolf-seo' ); ?>">
										<div class="coywolf-seo-robots-directives">
											<?php foreach ( Coywolf_SEO_Robots_Rules::directives( $rule ) as $d ) : ?>
												<?php // Tint each directive by type: light red for Disallow, light green for Allow (an allow-exception rule shows one of each). ?>
												<code class="coywolf-seo-robots-directive <?php echo ( 'Allow' === $d['directive'] ) ? 'coywolf-seo-robots-directive--allow' : 'coywolf-seo-robots-directive--disallow'; ?>"><?php echo esc_html( $d['directive'] . ': ' . $d['value'] ); ?></code>
											<?php endforeach; ?>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<div class="tablenav bottom">
						<?php echo wp_kses_post( $pagination ); ?>
						<br class="clear" />
					</div>
				</form>
			<?php endif; ?>

			<p class="description">
				<?php
				printf(
					/* translators: %s: link to the Robots.txt page */
					esc_html__( 'View and edit the current robots.txt on the %s page.', 'coywolf-seo' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_ROBOTS ) ) . '">' . esc_html__( 'Robots.txt', 'coywolf-seo' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/* ================================================================== *
	 * Robots.txt editor page
	 * ================================================================== */

	/**
	 * Show the current robots.txt in an editable textarea. Saving re-parses,
	 * optimizes, and replaces the managed rules (and, in physical mode, rewrites
	 * the file). See {@see handle_save_robots()}.
	 */
	public function render_robots_page() {
		if ( ! current_user_can( Coywolf_SEO_Admin::CAPABILITY ) ) {
			return;
		}
		$mode = $this->get_mode();
		?>
		<div class="wrap coywolf-seo-robots">
			<h1><?php echo esc_html__( 'Robots.txt', 'coywolf-seo' ); ?></h1>

			<?php $this->render_notices(); ?>

			<p class="description">
				<?php
				printf(
					/* translators: %s: mode label */
					esc_html__( 'Mode: %s. Change this on the Settings page.', 'coywolf-seo' ),
					'<strong>' . esc_html( 'physical' === $mode ? __( 'Physical robots.txt file', 'coywolf-seo' ) : __( 'Virtual (served by WordPress)', 'coywolf-seo' ) ) . '</strong>'
				);
				?>
			</p>
			<p class="description">
				<?php echo esc_html__( 'Edit the robots.txt below and save. The plugin parses and optimizes what you enter into managed rules, replacing the current ones — so the result may be tidied (duplicates merged, deprecated lines removed, conflicts resolved).', 'coywolf-seo' ); ?>
			</p>

			<form method="post">
				<?php wp_nonce_field( 'coywolf_seo_robots_robots' ); ?>
				<input type="hidden" name="coywolf_seo_robots_action" value="save_robots" />
				<?php // JS wraps this textarea and pins a copy-to-clipboard button to its top-right (see initRobotsCopy()). ?>
				<textarea id="coywolf-seo-robots-robots" name="robots" class="large-text code coywolf-seo-robots-current" rows="20" spellcheck="false"><?php echo esc_textarea( $this->effective_robots() ); ?></textarea>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php echo esc_html__( 'Save robots.txt', 'coywolf-seo' ); ?></button>
				</p>
			</form>

			<hr class="coywolf-seo-robots-test-sep" />

			<h2><?php echo esc_html__( 'Test a URL against this robots.txt', 'coywolf-seo' ); ?></h2>
			<p class="description">
				<?php echo esc_html__( 'Check whether a crawler would be allowed or blocked, evaluated against the whole file above with a PHP port of Google\'s open-source robots.txt matcher — all groups and rules considered together, exactly as Googlebot would interpret them.', 'coywolf-seo' ); ?>
			</p>
			<div class="coywolf-seo-robots-file-test">
				<p>
					<label for="coywolf-seo-robots-file-url"><strong><?php echo esc_html__( 'URL or path', 'coywolf-seo' ); ?></strong></label><br />
					<input type="text" id="coywolf-seo-robots-file-url" class="regular-text code" placeholder="/example-page/?ref=1" />
				</p>
				<p>
					<label for="coywolf-seo-robots-file-agent"><strong><?php echo esc_html__( 'User-agent', 'coywolf-seo' ); ?></strong></label><br />
					<input type="text" id="coywolf-seo-robots-file-agent" class="regular-text" list="coywolf-seo-robots-bot-tokens" value="Googlebot" placeholder="Googlebot" autocomplete="off" />
					<datalist id="coywolf-seo-robots-bot-tokens">
						<option value="*"></option>
						<?php foreach ( $this->bot_token_list() as $token ) : ?>
							<option value="<?php echo esc_attr( $token ); ?>"></option>
						<?php endforeach; ?>
					</datalist>
					<br /><span class="description"><?php echo esc_html__( 'Type a crawler token (autocomplete from the bot catalog), or "*" for all robots.', 'coywolf-seo' ); ?></span>
				</p>
				<p>
					<button type="button" class="button" id="coywolf-seo-robots-file-test-btn"><?php echo esc_html__( 'Test URL', 'coywolf-seo' ); ?></button>
				</p>
				<div id="coywolf-seo-robots-file-result" class="coywolf-seo-robots-test-result" style="display:none;"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Sorted, de-duplicated list of every crawler token in the bundled catalog,
	 * for the URL tester's user-agent autocomplete.
	 *
	 * @return array<int,string>
	 */
	private function bot_token_list() {
		$tokens = array();
		foreach ( Coywolf_SEO_Robots_Bots::by_category() as $bots ) {
			foreach ( $bots as $bot ) {
				$token = isset( $bot['token'] ) ? (string) $bot['token'] : '';
				if ( '' !== $token ) {
					$tokens[ $token ] = true;
				}
			}
		}
		$tokens = array_keys( $tokens );
		sort( $tokens, SORT_NATURAL | SORT_FLAG_CASE );
		return $tokens;
	}

	/* ================================================================== *
	 * Rule editor page
	 * ================================================================== */

	public function render_edit_page() {
		if ( ! current_user_can( Coywolf_SEO_Admin::CAPABILITY ) ) {
			return;
		}

		// When a save was just blocked by a conflict, re-render with the
		// submitted (unsaved) values and the conflict explanation, keeping the
		// user's input. Otherwise load the stored rule (edit) or a blank one.
		if ( ! empty( $this->pending_rule ) ) {
			$rule    = $this->pending_rule;
			$is_edit = ( null !== $this->find_rule( isset( $rule['id'] ) ? $rule['id'] : '' ) );
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: selects which stored rule the editor displays; the save is nonce-verified in handle_save().
			$rule_id = isset( $_GET['rule'] ) ? sanitize_text_field( wp_unslash( $_GET['rule'] ) ) : '';
			$rule    = $rule_id ? $this->find_rule( $rule_id ) : null;
			$is_edit = (bool) $rule;
			if ( ! $rule ) {
				$rule = Coywolf_SEO_Robots_Rules::blank();
			}
		}

		$bots_by_cat = Coywolf_SEO_Robots_Bots::by_category();
		$selected    = isset( $rule['agents'] ) && is_array( $rule['agents'] ) ? $rule['agents'] : array();
		?>
		<div class="wrap coywolf-seo-robots coywolf-seo-robots-edit">
			<h1><?php echo esc_html( $is_edit ? __( 'Edit Rule', 'coywolf-seo' ) : __( 'Add Rule', 'coywolf-seo' ) ); ?></h1>

			<?php
			// A save blocked by a conflict: explain it and keep the editor open.
			// The rule is NOT saved until the conflict is resolved.
			if ( ! empty( $this->pending_conflicts ) ) {
				echo '<div class="notice notice-error coywolf-seo-robots-conflict-notice"><p><strong>' . esc_html__( 'This rule was not saved — it conflicts with or is already covered by existing rules:', 'coywolf-seo' ) . '</strong></p><ul class="coywolf-seo-robots-conflict-list">';
				foreach ( $this->pending_conflicts as $msg ) {
					echo '<li>' . esc_html( (string) $msg ) . '</li>';
				}
				echo '</ul><p>' . esc_html__( 'Adjust this rule’s target (path or directive) or the bots it applies to so it no longer conflicts, then save again.', 'coywolf-seo' ) . '</p></div>';
			}
			?>

			<form method="post" id="coywolf-seo-robots-form"
				data-rule="<?php echo esc_attr( wp_json_encode( $rule ) ); ?>">
				<?php wp_nonce_field( 'coywolf_seo_robots_save' ); ?>
				<input type="hidden" name="coywolf_seo_robots_action" value="save" />
				<input type="hidden" name="rule_id" value="<?php echo esc_attr( $is_edit ? $rule['id'] : '' ); ?>" />

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="coywolf-seo-robots-directive"><?php echo esc_html__( 'Rule Type', 'coywolf-seo' ); ?></label>
						</th>
						<td>
							<select id="coywolf-seo-robots-directive" name="directive">
								<option value="disallow" <?php selected( $rule['directive'], 'disallow' ); ?>><?php echo esc_html__( 'Disallow', 'coywolf-seo' ); ?></option>
								<option value="allow" <?php selected( $rule['directive'], 'allow' ); ?>><?php echo esc_html__( 'Allow', 'coywolf-seo' ); ?></option>
							</select>
							<p class="description"><?php echo esc_html__( 'Whether the Rule Path below is disallowed (blocked) or allowed.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="coywolf-seo-robots-type"><?php echo esc_html__( 'Rule Path', 'coywolf-seo' ); ?></label>
						</th>
						<td>
							<select id="coywolf-seo-robots-type" name="type">
								<option value=""><?php echo esc_html__( '— Select a rule path —', 'coywolf-seo' ); ?></option>
								<?php foreach ( Coywolf_SEO_Robots_Rules::types() as $key => $def ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $rule['type'], $key ); ?>>
										<?php echo esc_html( $def['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>

				<?php // Until a Rule Path is chosen the editor below stays hidden, so offer a way back to the Rules list. JS hides this once a path is selected (the editor has its own Cancel). ?>
				<p class="submit" id="coywolf-seo-robots-precancel"<?php echo $rule['type'] ? ' style="display:none;"' : ''; ?>>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_LIST ) ); ?>" class="button button-secondary">
						<?php echo esc_html__( 'Cancel', 'coywolf-seo' ); ?>
					</a>
				</p>

				<div id="coywolf-seo-robots-fields" class="coywolf-seo-robots-fields" style="display:none;">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="coywolf-seo-robots-name"><?php echo esc_html__( 'Rule Name', 'coywolf-seo' ); ?></label>
							</th>
							<td>
								<input type="text" id="coywolf-seo-robots-name" name="name" class="regular-text"
									value="<?php echo esc_attr( $rule['name'] ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="coywolf-seo-robots-description"><?php echo esc_html__( 'Rule Description', 'coywolf-seo' ); ?></label>
							</th>
							<td>
								<textarea id="coywolf-seo-robots-description" name="description" class="large-text" rows="2"><?php echo esc_textarea( $rule['description'] ); ?></textarea>
							</td>
						</tr>

						<tr class="coywolf-seo-robots-pathrow" id="coywolf-seo-robots-pathrow">
							<th scope="row"><?php echo esc_html__( 'Path', 'coywolf-seo' ); ?></th>
							<td id="coywolf-seo-robots-path-fields">
								<?php // Path inputs are built by JS from the selected rule type. ?>
							</td>
						</tr>

						<tr id="coywolf-seo-robots-botsrow">
							<th scope="row"><?php echo esc_html__( 'Bots', 'coywolf-seo' ); ?></th>
							<td>
								<?php $this->render_bots_field( $bots_by_cat, $selected ); ?>
							</td>
						</tr>


						<tr id="coywolf-seo-robots-testrow">
							<th scope="row"><?php echo esc_html__( 'Testing Tool', 'coywolf-seo' ); ?></th>
							<td>
								<p class="description">
									<?php echo esc_html__( 'Enter a URL or path to check whether this rule (as configured above) would block or allow it — before you save.', 'coywolf-seo' ); ?>
								</p>
								<p>
									<input type="text" id="coywolf-seo-robots-test-url" class="regular-text code"
										placeholder="<?php echo esc_attr( '/blog/page/?utm=1' ); ?>" />
									<button type="button" class="button" id="coywolf-seo-robots-test-btn">
										<?php echo esc_html__( 'Test URL', 'coywolf-seo' ); ?>
									</button>
								</p>
								<p id="coywolf-seo-robots-test-result" class="coywolf-seo-robots-test-result" style="display:none;"></p>
							</td>
						</tr>
					</table>

					<p class="submit">
						<button type="submit" class="button button-primary">
							<?php echo esc_html( $is_edit ? __( 'Update Rule', 'coywolf-seo' ) : __( 'Add Rule', 'coywolf-seo' ) ); ?>
						</button>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_LIST ) ); ?>" class="button button-secondary">
							<?php echo esc_html__( 'Cancel', 'coywolf-seo' ); ?>
						</a>
						<?php
						// When editing a saved rule, offer a Delete that reuses the
						// same nonce-protected delete route as the Rules table.
						if ( $is_edit ) :
							$del_url = wp_nonce_url(
								admin_url( 'admin.php?page=' . self::PAGE_LIST . '&coywolf_seo_robots_action=delete&rule=' . rawurlencode( $rule['id'] ) ),
								'coywolf_seo_robots_delete_' . $rule['id']
							);
							?>
							<a href="<?php echo esc_url( $del_url ); ?>" class="button coywolf-seo-robots-edit-delete" onclick="return confirm('<?php echo esc_js( __( 'Delete this rule?', 'coywolf-seo' ) ); ?>');">
								<?php echo esc_html__( 'Delete Rule', 'coywolf-seo' ); ?>
							</a>
						<?php endif; ?>
					</p>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the Bots field: "all robots" toggle, custom-bot adder, and a
	 * scrollable <details> disclosure per category with select-all controls.
	 *
	 * @param array $bots_by_cat Output of Coywolf_SEO_Robots_Bots::by_category().
	 * @param array $selected    Currently selected agent tokens.
	 */
	private function render_bots_field( $bots_by_cat, $selected ) {
		// Normalize each selected agent to its catalog token, so a bot saved
		// under a variant spelling — or added as a "custom" agent before the
		// importer matched against the catalog (e.g. "Bingbot", "Slurp",
		// "YandexBot") — shows up checked in its category rather than in the
		// custom list. Unknown agents and '*' pass through unchanged. The
		// checkboxes carry the catalog token, so the next save persists it.
		$normalized = array();
		foreach ( $selected as $s ) {
			$s = (string) $s;
			if ( '*' === $s ) {
				$normalized[] = '*';
				continue;
			}
			$canon        = Coywolf_SEO_Robots_Bots::canonical_token( $s );
			$normalized[] = ( '' !== $canon ) ? $canon : $s;
		}
		$selected = array_values( array_unique( $normalized ) );

		$selected_map = array();
		foreach ( $selected as $s ) {
			$selected_map[ (string) $s ] = true;
		}
		$known  = Coywolf_SEO_Robots_Bots::known_agents();
		$all_on = isset( $selected_map['*'] );

		// Custom agents = selected tokens not in the catalog and not '*'.
		$custom = array();
		foreach ( $selected as $s ) {
			$s = (string) $s;
			if ( '*' !== $s && ! isset( $known[ $s ] ) ) {
				$custom[] = $s;
			}
		}
		?>
		<div class="coywolf-seo-robots-bots">
			<p>
				<label>
					<input type="checkbox" name="agents[]" value="*" id="coywolf-seo-robots-all" <?php checked( $all_on ); ?> />
					<strong><?php echo esc_html__( 'All robots (*)', 'coywolf-seo' ); ?></strong>
				</label>
				<span class="description"><?php echo esc_html__( 'Applies the rule to every crawler.', 'coywolf-seo' ); ?></span>
			</p>

			<div class="coywolf-seo-robots-custom">
				<label for="coywolf-seo-robots-custom-input"><strong><?php echo esc_html__( 'Custom bots', 'coywolf-seo' ); ?></strong></label>
				<p>
					<input type="text" id="coywolf-seo-robots-custom-input" class="regular-text"
						placeholder="<?php echo esc_attr__( 'e.g. MyCrawler', 'coywolf-seo' ); ?>" />
					<button type="button" class="button" id="coywolf-seo-robots-custom-add"><?php echo esc_html__( 'Add bot', 'coywolf-seo' ); ?></button>
				</p>
				<ul class="coywolf-seo-robots-custom-list" id="coywolf-seo-robots-custom-list">
					<?php foreach ( $custom as $token ) : ?>
						<li>
							<code><?php echo esc_html( $token ); ?></code>
							<input type="hidden" name="agents[]" value="<?php echo esc_attr( $token ); ?>" />
							<button type="button" class="button-link coywolf-seo-robots-custom-remove"><?php echo esc_html__( 'Remove', 'coywolf-seo' ); ?></button>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>

			<p><strong><?php echo esc_html__( 'Bot categories', 'coywolf-seo' ); ?></strong>
				<span class="description"><?php echo esc_html__( 'Tick a whole category, or open it to choose individual bots.', 'coywolf-seo' ); ?></span>
			</p>

			<?php foreach ( $bots_by_cat as $label => $bots ) : ?>
				<?php
				$cat_total    = count( $bots );
				$cat_selected = 0;
				foreach ( $bots as $bot ) {
					if ( isset( $selected_map[ $bot['token'] ] ) ) {
						++$cat_selected;
					}
				}
				$cat_all = ( $cat_total > 0 && $cat_selected === $cat_total );
				?>
				<details class="coywolf-seo-robots-cat" <?php echo ( $cat_selected > 0 ? 'open' : '' ); ?>>
					<summary>
						<label class="coywolf-seo-robots-cat-toggle" onclick="event.stopPropagation();">
							<input type="checkbox" class="coywolf-seo-robots-cat-all" <?php checked( $cat_all ); ?> />
							<strong><?php echo esc_html( $label ); ?></strong>
						</label>
						<span class="coywolf-seo-robots-cat-count">(<?php echo (int) $cat_total; ?>)</span>
					</summary>
					<div class="coywolf-seo-robots-cat-actions">
						<button type="button" class="button-link coywolf-seo-robots-sel-all"><?php echo esc_html__( 'Select all', 'coywolf-seo' ); ?></button>
						&nbsp;|&nbsp;
						<button type="button" class="button-link coywolf-seo-robots-sel-none"><?php echo esc_html__( 'Deselect all', 'coywolf-seo' ); ?></button>
					</div>
					<ul class="coywolf-seo-robots-botlist">
						<?php foreach ( $bots as $bot ) : ?>
							<li>
								<label title="<?php echo esc_attr( $bot['description'] ); ?>">
									<input type="checkbox" name="agents[]" value="<?php echo esc_attr( $bot['token'] ); ?>"
										class="coywolf-seo-robots-bot" <?php checked( isset( $selected_map[ $bot['token'] ] ) ); ?> />
									<span class="coywolf-seo-robots-bot-text">
										<?php echo esc_html( $bot['name'] ); ?>
										<?php if ( '' !== $bot['operator'] ) : ?>
											<span class="coywolf-seo-robots-bot-op"><?php echo esc_html( $bot['operator'] ); ?></span>
										<?php endif; ?>
										<code><?php echo esc_html( $bot['token'] ); ?></code>
									</span>
								</label>
							</li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render the XML Sitemaps field on the Settings page: an add-a-URL input
	 * plus a list of the stored URLs, each backed by a hidden sitemaps[] input.
	 * Mirrors the custom-bot adder. Shown only while sitemaps aren't excluded.
	 *
	 * @param array<int,string> $sitemaps Stored sitemap URLs.
	 */
	private function render_sitemaps_field( $sitemaps ) {
		?>
		<div class="coywolf-seo-robots-custom coywolf-seo-robots-sitemaps">
			<p>
				<input type="text" id="coywolf-seo-robots-sitemap-input" class="regular-text code"
					placeholder="<?php echo esc_attr( home_url( '/wp-sitemap.xml' ) ); ?>" />
				<button type="button" class="button" id="coywolf-seo-robots-sitemap-add"><?php echo esc_html__( 'Add sitemap', 'coywolf-seo' ); ?></button>
			</p>
			<ul class="coywolf-seo-robots-custom-list coywolf-seo-robots-sitemap-list" id="coywolf-seo-robots-sitemap-list">
				<?php foreach ( $sitemaps as $url ) : ?>
					<li>
						<code><?php echo esc_html( $url ); ?></code>
						<input type="hidden" name="sitemaps[]" value="<?php echo esc_attr( $url ); ?>" />
						<button type="button" class="button-link coywolf-seo-robots-sitemap-remove"><?php echo esc_html__( 'Remove', 'coywolf-seo' ); ?></button>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/* ================================================================== *
	 * Settings section (embedded in the Coywolf SEO Settings page)
	 * ================================================================== */

	public function render_settings_section() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$mode     = $this->get_mode();
		$path     = $this->robots_txt_path();
		$exists   = file_exists( $path );
		$writable = $exists ? is_writable( $path ) : is_writable( $this->site_root_path() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		$count    = Coywolf_SEO_Robots_Bots::count();
		?>
		<h2 id="coywolf-seo-section-robots"><?php esc_html_e( 'Robots.txt Manager', 'coywolf-seo' ); ?></h2>

			<h3><?php echo esc_html__( 'How robots.txt is managed', 'coywolf-seo' ); ?></h3>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Mode', 'coywolf-seo' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="mode" value="virtual" <?php checked( $mode, 'virtual' ); ?> />
									<?php echo esc_html__( 'Virtual — let WordPress serve robots.txt and inject the managed rules.', 'coywolf-seo' ); ?>
								</label><br />
								<label>
									<input type="radio" name="mode" value="physical" <?php checked( $mode, 'physical' ); ?> />
									<?php echo esc_html__( 'Physical — write the managed rules into a real robots.txt file in the site root.', 'coywolf-seo' ); ?>
								</label>
							</fieldset>
							<p class="description">
								<?php
								printf(
									/* translators: 1: file path, 2: writable status */
									esc_html__( 'File: %1$s — %2$s', 'coywolf-seo' ),
									'<code>' . esc_html( $path ) . '</code>',
									$writable
										? '<span class="coywolf-seo-robots-ok">' . esc_html__( 'writable', 'coywolf-seo' ) . '</span>'
										: '<span class="coywolf-seo-robots-bad">' . esc_html__( 'not writable — physical mode will fail until the file/dir is writable', 'coywolf-seo' ) . '</span>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'XML sitemaps', 'coywolf-seo' ); ?></th>
						<td>
							<?php $omit = (bool) get_option( self::OPT_OMIT_SITEMAPS ); ?>
							<label>
								<input type="checkbox" name="omit_sitemaps" id="coywolf-seo-robots-omit-sitemaps" value="1" <?php checked( $omit ); ?> />
								<?php echo esc_html__( 'Exclude XML sitemap link(s) from robots.txt', 'coywolf-seo' ); ?>
							</label>
							<p class="description">
								<?php echo esc_html__( 'When checked, no Sitemap: lines are written — including the one WordPress adds automatically.', 'coywolf-seo' ); ?>
							</p>

							<?php // The URL list is hidden while sitemaps are excluded. ?>
							<div id="coywolf-seo-robots-sitemaps-wrap" class="coywolf-seo-robots-sitemaps-wrap" style="<?php echo $omit ? 'display:none;' : ''; ?>">
								<p class="description">
									<?php echo esc_html__( 'Sitemap URLs to list at the end of robots.txt:', 'coywolf-seo' ); ?>
								</p>
								<?php $this->render_sitemaps_field( $this->stored_sitemaps() ); ?>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Rule comments', 'coywolf-seo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="omit_comments" value="1" <?php checked( (bool) get_option( self::OPT_OMIT_COMMENTS ) ); ?> />
								<?php echo esc_html__( 'Exclude the description comment above each rule in robots.txt', 'coywolf-seo' ); ?>
							</label>
							<p class="description">
								<?php echo esc_html__( 'When checked, the “# Name: Description” lines are left out. Uncheck to add them back.', 'coywolf-seo' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h3><?php echo esc_html__( 'Bot list', 'coywolf-seo' ); ?></h3>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Catalog', 'coywolf-seo' ); ?></th>
						<td>
							<p>
								<?php
								printf(
									/* translators: %d: number of bots */
									esc_html__( '%d bots loaded.', 'coywolf-seo' ),
									(int) $count
								);
								echo ' ';
								echo esc_html__( 'The bot list is bundled with the plugin and refreshed through plugin updates.', 'coywolf-seo' );
								?>
							</p>
						</td>
					</tr>
				</table>
		<?php
	}

	/* ================================================================== *
	 * Import / Export section (embedded in the Coywolf SEO Import/Export page)
	 * ================================================================== */

	/**
	 * Robots.txt rules import/export, embedded as a section on the Coywolf SEO
	 * Import/Export page (administrator-only). Export is a nonce'd download link;
	 * import is its own multipart form. Both are handled in {@see handle_export()}
	 * / {@see handle_import()} on admin_init.
	 */
	public function render_import_export_section() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$export_url = wp_nonce_url(
			admin_url( 'admin.php?page=' . self::PAGE_IMPORT_EXPORT . '&coywolf_seo_robots_action=export' ),
			'coywolf_seo_robots_export'
		);
		?>
		<h2><?php esc_html_e( 'Robots.txt rules', 'coywolf-seo' ); ?></h2>

		<?php $this->render_notices(); ?>

		<p class="description">
				<?php echo esc_html__( 'Save all rules and settings preferences to a JSON file so you can keep it as a backup or import it into another site.', 'coywolf-seo' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( 'Export', 'coywolf-seo' ); ?></th>
					<td>
						<a href="<?php echo esc_url( $export_url ); ?>" class="button">
							<?php echo esc_html__( 'Export rules (JSON)', 'coywolf-seo' ); ?>
						</a>
						<p class="description"><?php echo esc_html__( 'Downloads a JSON file containing all current rules and your settings preferences. Sitemap URLs are not included — they are specific to each site.', 'coywolf-seo' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Import', 'coywolf-seo' ); ?></th>
					<td>
						<form method="post" enctype="multipart/form-data" class="coywolf-seo-robots-import-form">
							<?php wp_nonce_field( 'coywolf_seo_robots_import' ); ?>
							<input type="hidden" name="coywolf_seo_robots_action" value="import" />
							<input type="file" name="coywolf_seo_robots_import_file" accept="application/json,.json" required />
							<button type="submit" class="button"
								onclick="return confirm('<?php echo esc_js( __( 'Importing replaces ALL current rules with the ones in this file. Continue?', 'coywolf-seo' ) ); ?>');">
								<?php echo esc_html__( 'Import rules', 'coywolf-seo' ); ?>
							</button>
						</form>
						<p class="description">
							<?php echo esc_html__( 'Choose a JSON file exported above. Importing replaces all current rules and applies the saved settings preferences.', 'coywolf-seo' ); ?>
						</p>
					</td>
				</tr>
			</table>
		<?php
	}

	/* ================================================================== *
	 * Action handling (save / delete / import / settings / update bots)
	 * ================================================================== */

	/**
	 * One-time upgrade: sitemap URLs used to be stored as a "sitemap"-type rule;
	 * they now live in OPT_SITEMAPS (Settings → XML sitemaps). Move any such
	 * rules' URLs into the option, drop the rules, and rewrite the physical file
	 * if needed. Guarded by OPT_SITEMAP_MIGRATED so it runs once.
	 */
	public function maybe_migrate_sitemap_rules() {
		if ( ! current_user_can( Coywolf_SEO_Admin::CAPABILITY ) ) {
			return;
		}
		if ( get_option( self::OPT_SITEMAP_MIGRATED ) ) {
			return;
		}

		$rules = get_option( self::OPT_RULES, array() );
		if ( is_array( $rules ) ) {
			$sitemaps = $this->stored_sitemaps();
			$kept     = array();
			$changed  = false;
			foreach ( $rules as $rule ) {
				if ( isset( $rule['type'] ) && 'sitemap' === $rule['type'] ) {
					$urls = ( isset( $rule['sitemaps'] ) && is_array( $rule['sitemaps'] ) ) ? $rule['sitemaps'] : array();
					foreach ( $urls as $u ) {
						$u = esc_url_raw( trim( (string) $u ) );
						if ( '' !== $u && ! in_array( $u, $sitemaps, true ) ) {
							$sitemaps[] = $u;
						}
					}
					$changed = true;
					continue; // Drop the sitemap rule.
				}
				$kept[] = $rule;
			}
			if ( $changed ) {
				update_option( self::OPT_SITEMAPS, $sitemaps, false );
				$this->persist_rules( $kept );
				if ( 'physical' === $this->get_mode() ) {
					$this->write_physical();
				}
			}
		}

		update_option( self::OPT_SITEMAP_MIGRATED, '1', false );
	}

	/**
	 * On admin load in virtual mode, get the manager in sync with what the site
	 * serves and take ownership of robots.txt:
	 *
	 * 1. On a fresh install (no rules yet) seed from whatever robots.txt
	 *    currently serves, so nothing already published is lost.
	 * 2. Fold WordPress's OWN base rules (wp-admin) into the managed set and
	 *    reconcile them ({@see merge_wordpress_base()}), so we can stop emitting
	 *    WordPress's duplicate block on top of ours.
	 *
	 * Step 2 runs once (guarded by OPT_WP_BASE) and also fixes installs that
	 * predate this — where rules already existed and WordPress's block was being
	 * added on top. Physical mode is skipped (WordPress serves the file directly,
	 * so it never injects its base there).
	 */
	public function maybe_auto_import_virtual() {
		if ( ! current_user_can( Coywolf_SEO_Admin::CAPABILITY ) ) {
			return;
		}
		if ( 'virtual' !== $this->get_mode() ) {
			return;
		}
		if ( get_option( self::OPT_WP_BASE ) ) {
			return;
		}
		if ( empty( $this->get_rules() ) && ! get_option( self::OPT_IMPORTED ) ) {
			$this->import_existing();
			update_option( self::OPT_IMPORTED, '1', false );
		}
		$this->merge_wordpress_base();
		update_option( self::OPT_WP_BASE, '1', false );
	}

	/**
	 * Parse the current robots.txt (physical or virtual base) into rules and
	 * store them, replacing any existing managed rules. Shared by the manual
	 * "Import from robots.txt" action and the virtual auto-import.
	 *
	 * @return int Number of rules imported.
	 */
	private function import_existing() {
		// Back up the original BEFORE we read, parse, fix, or optimize anything —
		// no matter how import was triggered (activation, first-load auto-import,
		// or the physical/virtual resolver). No-op once a backup exists.
		$this->maybe_backup_original();

		$source = $this->importable_robots();
		$parsed = Coywolf_SEO_Robots_Rules::parse( $source );
		$rules  = $parsed['rules'];
		foreach ( $rules as $i => $r ) {
			$rules[ $i ]['id'] = $this->new_id();
		}

		// Guard against an imported source that contained the same directive
		// more than once (e.g. a re-imported managed block) producing identical
		// rules.
		$rules = $this->dedupe_rules( $rules );

		// Persist the rules and any imported Sitemap: links. Sitemaps live in
		// their own option now (edited on Settings → XML sitemaps), so store the
		// imported URLs there, de-duplicated and merged with any already set.
		$this->persist_rules( $rules );

		$imported = ( isset( $parsed['sitemaps'] ) && is_array( $parsed['sitemaps'] ) ) ? $parsed['sitemaps'] : array();
		$sitemaps = $this->stored_sitemaps();
		foreach ( $imported as $u ) {
			$u = esc_url_raw( trim( (string) $u ) );
			if ( '' !== $u && ! in_array( $u, $sitemaps, true ) ) {
				$sitemaps[] = $u;
			}
		}
		update_option( self::OPT_SITEMAPS, $sitemaps, false );

		// In physical mode, rewrite the file ourselves with a cleaned "outside"
		// (the source minus the directive/Sitemap lines we just imported) so the
		// imported rules live in the managed block ONLY — not duplicated above
		// it. save_rules()'s implicit write would keep the whole source as
		// outside and then append the same rules in the managed block.
		if ( 'physical' === $this->get_mode() ) {
			$this->write_physical( $this->strip_directive_lines( $source ) );
		}

		return count( $rules );
	}

	public function handle_actions() {
		if ( ! is_admin() || ! current_user_can( Coywolf_SEO_Admin::CAPABILITY ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Dispatch only; each action handler below verifies its own nonce via check_admin_referer() before mutating state.
		$action = isset( $_REQUEST['coywolf_seo_robots_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['coywolf_seo_robots_action'] ) ) : '';
		if ( '' === $action ) {
			return;
		}

		switch ( $action ) {
			case 'save':
				$this->handle_save();
				break;
			case 'delete':
				$this->handle_delete();
				break;
			case 'bulk':
				$this->handle_bulk();
				break;
			case 'save_robots':
				$this->handle_save_robots();
				break;
			case 'export':
				$this->handle_export();
				break;
			case 'import':
				$this->handle_import();
				break;
			case 'resolve_import':
				$this->handle_resolve_import();
				break;
			case 'resolve_overwrite':
				$this->handle_resolve_overwrite();
				break;
			case 'resolve_delete':
				$this->handle_resolve_delete();
				break;
		}
	}

	private function handle_save() {
		check_admin_referer( 'coywolf_seo_robots_save' );

		$type  = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$types = Coywolf_SEO_Robots_Rules::types();
		if ( ! isset( $types[ $type ] ) ) {
			$this->redirect_with( self::PAGE_EDIT, array( 'coywolf_seo_robots_error' => 'type' ) );
		}

		$rule = $this->rule_from_post();

		// Conflict gate: a rule is checked against the existing rules on every
		// add or update, and is NOT saved while it conflicts. Stash the
		// submitted rule + reasons so render_edit_page() re-opens the editor
		// (same request) with the input preserved and the conflict explained.
		// There is no "save anyway" — the user must resolve the conflict.
		$existing  = $this->get_rules();
		$conflicts = array_merge(
			Coywolf_SEO_Robots_Rules::conflicts( $rule, $existing ),
			Coywolf_SEO_Robots_Rules::redundancies( $rule, $existing )
		);
		if ( ! empty( $conflicts ) ) {
			$this->pending_rule      = $rule;
			$this->pending_conflicts = $conflicts;
			return;
		}

		$rules = $this->get_rules();
		$found = false;
		foreach ( $rules as $i => $existing ) {
			if ( $existing['id'] === $rule['id'] ) {
				$rules[ $i ] = $rule;
				$found       = true;
				break;
			}
		}
		if ( ! $found ) {
			$rules[] = $rule;
		}

		$this->save_rules( $rules );
		$this->redirect_with( self::PAGE_LIST, array( 'coywolf_seo_robots_msg' => $found ? 'updated' : 'added' ) );
	}

	/**
	 * Build a sanitized rule array from the editor POST data, used by
	 * {@see handle_save()} to persist and to conflict-check the submission.
	 *
	 * @return array<string,mixed>
	 */
	private function rule_from_post() {
		$types = Coywolf_SEO_Robots_Rules::types();
		$type  = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$agents = array();
		if ( isset( $_POST['agents'] ) && is_array( $_POST['agents'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			foreach ( wp_unslash( $_POST['agents'] ) as $a ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing
				$a = $this->sanitize_agent( $a );
				if ( '' !== $a ) {
					$agents[ $a ] = true;
				}
			}
		}
		$agents = array_keys( $agents );
		if ( empty( $agents ) ) {
			$agents = array( '*' );
		}
		// "*" plus specific agents is contradictory within one rule — keep the
		// specific agents (a stray "*" would otherwise block every crawler).
		$agents = $this->normalize_agents( $agents );

		$rule_id = isset( $_POST['rule_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rule_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$rule = array(
			'id'          => $rule_id ? $rule_id : $this->new_id(),
			'type'        => $type,
			'directive'   => ( isset( $_POST['directive'] ) && 'allow' === wp_unslash( $_POST['directive'] ) ) ? 'allow' : 'disallow', // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict-compared to a literal and coerced to 'allow'/'disallow'; nonce verified in handle_save().
			'name'        => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'description' => isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'path'        => isset( $_POST['path'] ) ? $this->sanitize_path( wp_unslash( $_POST['path'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by sanitize_path(); nonce verified in handle_save().
			'ext'         => isset( $_POST['ext'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_POST['ext'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Non-alphanumerics stripped by preg_replace(); nonce verified in handle_save().
			'allow'       => isset( $_POST['allow'] ) ? $this->sanitize_path( wp_unslash( $_POST['allow'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by sanitize_path(); nonce verified in handle_save().
			'strict'      => ! empty( $_POST['strict'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'agents'      => $agents,
		);

		if ( isset( $types[ $type ] ) ) {
			if ( '' === $rule['name'] ) {
				$rule['name'] = $types[ $type ]['name'];
			}
			if ( '' === $rule['description'] ) {
				$rule['description'] = $types[ $type ]['description'];
			}
		}

		return $rule;
	}

	private function handle_delete() {
		$rule_id = isset( $_GET['rule'] ) ? sanitize_text_field( wp_unslash( $_GET['rule'] ) ) : '';
		check_admin_referer( 'coywolf_seo_robots_delete_' . $rule_id );

		$rules = $this->get_rules();
		$rules = array_values(
			array_filter(
				$rules,
				static function ( $r ) use ( $rule_id ) {
					return $r['id'] !== $rule_id;
				}
			)
		);
		$this->save_rules( $rules );
		$this->redirect_with( self::PAGE_LIST, array( 'coywolf_seo_robots_msg' => 'deleted' ) );
	}

	/**
	 * Bulk action from the Rules table. Currently supports deleting the checked
	 * rules; unknown actions or an empty selection are a no-op (back to the
	 * list, preserving the page).
	 */
	private function handle_bulk() {
		check_admin_referer( 'coywolf_seo_robots_bulk' );

		$action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$ids    = ( isset( $_POST['rule_ids'] ) && is_array( $_POST['rule_ids'] ) )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['rule_ids'] ) )
			: array();
		$paged  = isset( $_POST['paged'] ) ? max( 1, (int) $_POST['paged'] ) : 1;

		if ( 'delete' !== $action || empty( $ids ) ) {
			$this->redirect_with( self::PAGE_LIST, array( 'paged' => $paged ) );
		}

		$drop    = array_fill_keys( $ids, true );
		$rules   = $this->get_rules();
		$before  = count( $rules );
		$rules   = array_values(
			array_filter(
				$rules,
				static function ( $r ) use ( $drop ) {
					return ! isset( $drop[ $r['id'] ] );
				}
			)
		);
		$removed = $before - count( $rules );
		$this->save_rules( $rules );
		$this->redirect_with(
			self::PAGE_LIST,
			array(
				'coywolf_seo_robots_msg'   => 'bulk_deleted',
				'coywolf_seo_robots_count' => $removed,
				'paged'                    => $paged,
			)
		);
	}

	/**
	 * Save the directly-edited robots.txt (Robots.txt page): parse and optimize
	 * the submitted text into managed rules + sitemaps, REPLACING the current
	 * ones, and (in physical mode) rewrite the file. The managed block in the
	 * submitted text is stripped before working out what to keep outside it, so
	 * the markers aren't duplicated.
	 */
	private function handle_save_robots() {
		check_admin_referer( 'coywolf_seo_robots_robots' );
		$this->maybe_backup_original();

		// Plain-text robots.txt content from an administrator. It's parsed below
		// (directive values are sanitized there), escaped on display, and written
		// to a text/plain file — so it's kept verbatim rather than HTML-sanitized,
		// which would corrupt things like percent-encoded paths.
		$text = isset( $_POST['robots'] ) ? (string) wp_unslash( $_POST['robots'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$parsed = Coywolf_SEO_Robots_Rules::parse( $text );
		$rules  = $parsed['rules'];
		foreach ( $rules as $i => $r ) {
			$rules[ $i ]['id'] = $this->new_id();
		}
		$rules = $this->dedupe_rules( $rules );
		$this->persist_rules( $rules );

		// The edited text is authoritative for sitemaps too (parse already made
		// them absolute, same-domain, and de-duplicated).
		$sitemaps = ( isset( $parsed['sitemaps'] ) && is_array( $parsed['sitemaps'] ) ) ? array_values( $parsed['sitemaps'] ) : array();
		update_option( self::OPT_SITEMAPS, $sitemaps, false );

		if ( 'physical' === $this->get_mode() ) {
			// Keep the non-directive lines OUTSIDE the managed block; strip our
			// old markers first so they aren't preserved and then re-added.
			$outside = $this->strip_directive_lines( $this->strip_managed_block( $text ) );
			$this->write_physical( $outside );
		}

		update_option( self::OPT_IMPORTED, '1', false );
		$this->redirect_with( self::PAGE_ROBOTS, array( 'coywolf_seo_robots_msg' => 'robots_saved' ) );
	}

	/**
	 * Resolve a virtual-vs-physical clash by switching to physical mode and
	 * importing (replacing) the table with the existing physical file's rules.
	 */
	private function handle_resolve_import() {
		check_admin_referer( 'coywolf_seo_robots_resolve_import' );
		update_option( self::OPT_MODE, 'physical' );
		$this->import_existing();
		update_option( self::OPT_IMPORTED, '1', false );
		$this->redirect_with( self::PAGE_LIST, array( 'coywolf_seo_robots_msg' => 'resolved_import' ) );
	}

	/**
	 * Resolve a virtual-vs-physical clash by switching to physical mode and
	 * overwriting the physical file with the rules currently managed here.
	 * write_physical() preserves any lines outside the managed markers.
	 */
	private function handle_resolve_overwrite() {
		check_admin_referer( 'coywolf_seo_robots_resolve_overwrite' );
		update_option( self::OPT_MODE, 'physical' );
		$res = $this->write_physical();
		if ( is_wp_error( $res ) ) {
			$this->redirect_with(
				self::PAGE_LIST,
				array(
					'coywolf_seo_robots_error'  => 'write',
					'coywolf_seo_robots_errmsg' => rawurlencode( $res->get_error_message() ),
				)
			);
		}
		$this->redirect_with( self::PAGE_LIST, array( 'coywolf_seo_robots_msg' => 'resolved_overwrite' ) );
	}

	/**
	 * Resolve a virtual-vs-physical clash by deleting the physical robots.txt
	 * so WordPress's virtual one (with our managed rules) takes over. Reports
	 * an error if the file cannot be removed (e.g. not writable).
	 */
	private function handle_resolve_delete() {
		check_admin_referer( 'coywolf_seo_robots_resolve_delete' );

		// Switching away from the physical file — drop to virtual so it isn't
		// recreated, and keep the current rules.
		update_option( self::OPT_MODE, 'virtual' );
		$this->delete_physical_file();

		if ( $this->has_physical_robots_txt() ) {
			$this->redirect_with( self::PAGE_LIST, array( 'coywolf_seo_robots_error' => 'delete_failed' ) );
		}

		// Now serving virtually: fold WordPress's own base rules in and take
		// ownership so its block isn't added on top of ours.
		$this->merge_wordpress_base();
		update_option( self::OPT_WP_BASE, '1', false );
		$this->redirect_with( self::PAGE_LIST, array( 'coywolf_seo_robots_msg' => 'resolved_delete' ) );
	}

	/**
	 * Delete the physical robots.txt in the site root, if present. Uses
	 * WP_Filesystem with a wp_delete_file() fallback. Returns true when the
	 * file no longer exists afterward.
	 *
	 * @return bool
	 */
	private function delete_physical_file() {
		$path = $this->robots_txt_path();
		if ( ! file_exists( $path ) ) {
			return true;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;
		if ( $wp_filesystem ) {
			$wp_filesystem->delete( $path );
		}
		// Fallback when WP_Filesystem couldn't initialise.
		if ( file_exists( $path ) && is_writable( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			wp_delete_file( $path );
		}

		return ! file_exists( $path );
	}

	/**
	 * Warning shown on the Rules page when the plugin is in virtual mode but a
	 * physical robots.txt exists (which shadows the virtual rules), offering
	 * three one-click resolutions.
	 */
	private function render_physical_conflict_notice() {
		?>
		<div class="notice notice-warning coywolf-seo-robots-physical-warning">
			<p>
				<strong><?php echo esc_html__( 'A physical robots.txt file exists in your site root.', 'coywolf-seo' ); ?></strong>
				<?php echo esc_html__( 'WordPress serves that file, so the virtual rules below are NOT being used. Choose how to resolve this:', 'coywolf-seo' ); ?>
			</p>
			<div class="coywolf-seo-robots-resolve-actions">
				<form method="post">
					<?php wp_nonce_field( 'coywolf_seo_robots_resolve_import' ); ?>
					<input type="hidden" name="coywolf_seo_robots_action" value="resolve_import" />
					<button type="submit" class="button"
						onclick="return confirm('<?php echo esc_js( __( 'Switch to the physical file and replace the rules here with the ones it contains?', 'coywolf-seo' ) ); ?>');">
						<?php echo esc_html__( 'Keep file and import its rules', 'coywolf-seo' ); ?>
					</button>
				</form>
				<form method="post">
					<?php wp_nonce_field( 'coywolf_seo_robots_resolve_overwrite' ); ?>
					<input type="hidden" name="coywolf_seo_robots_action" value="resolve_overwrite" />
					<button type="submit" class="button"
						onclick="return confirm('<?php echo esc_js( __( 'Switch to physical mode and overwrite the file with the rules shown here?', 'coywolf-seo' ) ); ?>');">
						<?php echo esc_html__( 'Keep file and overwrite its rules', 'coywolf-seo' ); ?>
					</button>
				</form>
				<form method="post">
					<?php wp_nonce_field( 'coywolf_seo_robots_resolve_delete' ); ?>
					<input type="hidden" name="coywolf_seo_robots_action" value="resolve_delete" />
					<button type="submit" class="button button-link-delete"
						onclick="return confirm('<?php echo esc_js( __( 'Delete the physical robots.txt file so the virtual rules take over?', 'coywolf-seo' ) ); ?>');">
						<?php echo esc_html__( 'Delete the physical file', 'coywolf-seo' ); ?>
					</button>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Persist the Robots.txt Manager settings fields and run the mode-switch side
	 * effects. The fields live in the main Coywolf SEO settings form, so the nonce
	 * and capability are already verified by that handler (Coywolf_SEO_Admin::
	 * save_settings); this re-verifies the same nonce defensively and does not
	 * redirect (the caller does).
	 */
	public function save_settings_fields() {
		// Re-verify the main settings form nonce (already checked by the caller)
		// so these reads are provably nonce-guarded on their own.
		check_admin_referer( 'coywolf_seo_settings' );

		// The serving mode is administrator-only; the main settings form is
		// already gated on manage_options, but re-check defensively.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$prev_mode = get_option( self::OPT_MODE, 'virtual' );
		$mode      = ( isset( $_POST['mode'] ) && 'physical' === wp_unslash( $_POST['mode'] ) ) ? 'physical' : 'virtual'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict-compared to a literal and coerced to 'physical'/'virtual'; nonce verified above via check_admin_referer().
		update_option( self::OPT_MODE, $mode );

		// Not autoloaded: read on /robots.txt + admin, never on plain page views.
		update_option( self::OPT_OMIT_SITEMAPS, empty( $_POST['omit_sitemaps'] ) ? '' : '1', false );
		update_option( self::OPT_OMIT_COMMENTS, empty( $_POST['omit_comments'] ) ? '' : '1', false );

		// XML sitemap URLs (sanitized, de-duplicated, first-seen order).
		$sitemaps = array();
		if ( isset( $_POST['sitemaps'] ) && is_array( $_POST['sitemaps'] ) ) {
			foreach ( wp_unslash( $_POST['sitemaps'] ) as $u ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$u = esc_url_raw( trim( (string) $u ) );
				if ( '' !== $u && ! in_array( $u, $sitemaps, true ) ) {
					$sitemaps[] = $u;
				}
			}
		}
		update_option( self::OPT_SITEMAPS, $sitemaps, false );

		// If we just switched to physical mode, write the file now.
		if ( 'physical' === $mode ) {
			$this->write_physical();
		} elseif ( 'physical' === $prev_mode ) {
			// Physical -> Virtual: delete the physical robots.txt so it no
			// longer shadows the virtual output, and KEEP the current rules
			// (do not re-import — that would risk duplicating them). Then fold
			// WordPress's own base rules in and take ownership so its block
			// isn't added on top.
			$this->delete_physical_file();
			update_option( self::OPT_IMPORTED, '1', false );
			$this->merge_wordpress_base();
			update_option( self::OPT_WP_BASE, '1', false );
		} elseif ( ! get_option( self::OPT_IMPORTED ) && empty( $this->get_rules() ) ) {
			// First time enabling virtual with no rules yet — pull in whatever
			// robots.txt currently serves so we start in sync.
			$this->import_existing();
			update_option( self::OPT_IMPORTED, '1', false );
			$this->merge_wordpress_base();
			update_option( self::OPT_WP_BASE, '1', false );
		}
	}

	/* ================================================================== *
	 * Import / Export (Settings page)
	 * ================================================================== */

	/**
	 * Stream every managed rule (plus the settings preferences) as a JSON
	 * download, so the same set can be re-imported here or carried to another
	 * site. Runs on admin_init before any page HTML, so it can send file headers
	 * and exit. Administrator-only.
	 */
	private function handle_export() {
		check_admin_referer( 'coywolf_seo_robots_export' );
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$json = wp_json_encode( $this->build_export_payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			$this->redirect_with( self::PAGE_IMPORT_EXPORT, array( 'coywolf_seo_robots_error' => 'export' ) );
		}

		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$host     = is_string( $host ) && '' !== $host ? preg_replace( '/[^A-Za-z0-9.\-]/', '', $host ) : 'site';
		$filename = 'robots-txt-manager-' . $host . '-' . gmdate( 'Ymd' ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $json ) );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A JSON file download (Content-Type: application/json), not HTML; escaping would corrupt the file.
		exit;
	}

	/**
	 * Build the portable export structure: the managed rules (normalized to the
	 * stored keys, without the per-site ids) plus the settings preferences (the
	 * "exclude sitemaps" and "exclude comments" toggles). The actual sitemap
	 * URLs are intentionally left out — they are specific to each site — as are
	 * the serving mode and the role-access list.
	 *
	 * @return array<string,mixed>
	 */
	private function build_export_payload() {
		$rules = array();
		foreach ( $this->get_rules() as $rule ) {
			$rules[] = array(
				'type'        => isset( $rule['type'] ) ? (string) $rule['type'] : '',
				'directive'   => ( isset( $rule['directive'] ) && 'allow' === $rule['directive'] ) ? 'allow' : 'disallow',
				'name'        => isset( $rule['name'] ) ? (string) $rule['name'] : '',
				'description' => isset( $rule['description'] ) ? (string) $rule['description'] : '',
				'path'        => isset( $rule['path'] ) ? (string) $rule['path'] : '',
				'ext'         => isset( $rule['ext'] ) ? (string) $rule['ext'] : '',
				'allow'       => isset( $rule['allow'] ) ? (string) $rule['allow'] : '',
				'strict'      => ! empty( $rule['strict'] ),
				'agents'      => ( isset( $rule['agents'] ) && is_array( $rule['agents'] ) ) ? array_values( array_map( 'strval', $rule['agents'] ) ) : array( '*' ),
			);
		}

		return array(
			'format'         => 'coywolf-seo-robots',
			'schema'         => 2,
			'plugin_version' => self::VERSION,
			'exported_at'    => gmdate( 'c' ),
			'site_url'       => home_url( '/' ),
			'rules'          => $rules,
			'omit_sitemaps'  => (bool) get_option( self::OPT_OMIT_SITEMAPS ),
			'omit_comments'  => (bool) get_option( self::OPT_OMIT_COMMENTS ),
		);
	}

	/**
	 * Import a JSON file produced by {@see build_export_payload()}, REPLACING the
	 * current rules with the file's (the UI warns before submitting). Each rule
	 * is re-sanitized and given a fresh id, and the settings preferences (the
	 * "exclude sitemaps" / "exclude comments" toggles) are applied when present.
	 * Sitemap URLs are NOT imported — they are site-specific, so the target keeps
	 * its own. Serving mode and role access are not touched. Administrator-only.
	 */
	private function handle_import() {
		check_admin_referer( 'coywolf_seo_robots_import' );
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Validate the upload before reading it. The nonce above covers this
		// request, so the $_FILES reads below need no separate nonce check.
		$error = isset( $_FILES['coywolf_seo_robots_import_file']['error'] ) ? (int) $_FILES['coywolf_seo_robots_import_file']['error'] : UPLOAD_ERR_NO_FILE; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cast to int.
		$size  = isset( $_FILES['coywolf_seo_robots_import_file']['size'] ) ? (int) $_FILES['coywolf_seo_robots_import_file']['size'] : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cast to int.
		if ( UPLOAD_ERR_OK !== $error || $size <= 0 || $size > 1048576 ) {
			$this->redirect_with( self::PAGE_IMPORT_EXPORT, array( 'coywolf_seo_robots_error' => 'import_file' ) );
		}
		$tmp = isset( $_FILES['coywolf_seo_robots_import_file']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['coywolf_seo_robots_import_file']['tmp_name'] ) ) : '';
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) || ! is_readable( $tmp ) ) {
			$this->redirect_with( self::PAGE_IMPORT_EXPORT, array( 'coywolf_seo_robots_error' => 'import_file' ) );
		}

		// Reading the uploaded temp file directly: WP_Filesystem's non-direct
		// methods (FTP/SSH) can't reach PHP's upload tmp dir, so file_get_contents
		// is the reliable read here (mirrors the plugin's other file reads).
		$raw  = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || ! isset( $data['format'] ) || 'coywolf-seo-robots' !== $data['format'] ) {
			$this->redirect_with( self::PAGE_IMPORT_EXPORT, array( 'coywolf_seo_robots_error' => 'import_invalid' ) );
		}

		// Rules: sanitize each, drop ones we can't read, de-duplicate, and REPLACE
		// the current set. New ids are assigned so they never collide.
		$rules = array();
		if ( isset( $data['rules'] ) && is_array( $data['rules'] ) ) {
			foreach ( $data['rules'] as $raw_rule ) {
				if ( ! is_array( $raw_rule ) ) {
					continue;
				}
				$clean = $this->sanitize_imported_rule( $raw_rule );
				if ( null !== $clean ) {
					$rules[] = $clean;
				}
			}
		}
		$rules = $this->dedupe_rules( $rules );
		$this->persist_rules( $rules );

		// Settings preferences — the two output toggles, applied when present.
		// Sitemap URLs are deliberately not part of the file (site-specific), so
		// the target site keeps whatever sitemaps it already has.
		if ( array_key_exists( 'omit_sitemaps', $data ) ) {
			update_option( self::OPT_OMIT_SITEMAPS, empty( $data['omit_sitemaps'] ) ? '' : '1', false );
		}
		if ( array_key_exists( 'omit_comments', $data ) ) {
			update_option( self::OPT_OMIT_COMMENTS, empty( $data['omit_comments'] ) ? '' : '1', false );
		}

		// Keep the served robots.txt in sync, and mark the site as imported so the
		// virtual auto-import doesn't later pull the old file back in over these.
		if ( 'physical' === $this->get_mode() ) {
			$this->write_physical();
		}
		update_option( self::OPT_IMPORTED, '1', false );

		$this->redirect_with(
			self::PAGE_IMPORT_EXPORT,
			array(
				'coywolf_seo_robots_msg'   => 'imported',
				'coywolf_seo_robots_count' => count( $rules ),
			)
		);
	}

	/**
	 * Sanitize one rule from an import file into the stored shape, mirroring
	 * {@see rule_from_post()} but reading a decoded array rather than $_POST.
	 * Returns null for a rule whose type isn't in the registry, so unreadable
	 * entries are skipped rather than stored.
	 *
	 * @param array<string,mixed> $raw Decoded rule from the import file.
	 * @return array<string,mixed>|null
	 */
	private function sanitize_imported_rule( $raw ) {
		$types = Coywolf_SEO_Robots_Rules::types();
		$type  = isset( $raw['type'] ) ? sanitize_key( (string) $raw['type'] ) : '';
		if ( ! isset( $types[ $type ] ) ) {
			return null;
		}

		$agents = array();
		if ( isset( $raw['agents'] ) && is_array( $raw['agents'] ) ) {
			foreach ( $raw['agents'] as $a ) {
				$a = $this->sanitize_agent( (string) $a );
				if ( '' !== $a ) {
					$agents[ $a ] = true;
				}
			}
		}
		$agents = array_keys( $agents );
		if ( empty( $agents ) ) {
			$agents = array( '*' );
		}
		$agents = $this->normalize_agents( $agents );

		$rule = array(
			'id'          => $this->new_id(),
			'type'        => $type,
			'directive'   => ( isset( $raw['directive'] ) && 'allow' === $raw['directive'] ) ? 'allow' : 'disallow',
			'name'        => isset( $raw['name'] ) ? sanitize_text_field( (string) $raw['name'] ) : '',
			'description' => isset( $raw['description'] ) ? sanitize_text_field( (string) $raw['description'] ) : '',
			'path'        => isset( $raw['path'] ) ? $this->sanitize_path( (string) $raw['path'] ) : '',
			'ext'         => isset( $raw['ext'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) $raw['ext'] ) : '',
			'allow'       => isset( $raw['allow'] ) ? $this->sanitize_path( (string) $raw['allow'] ) : '',
			'strict'      => ! empty( $raw['strict'] ),
			'agents'      => $agents,
		);

		if ( '' === $rule['name'] ) {
			$rule['name'] = $types[ $type ]['name'];
		}
		if ( '' === $rule['description'] ) {
			$rule['description'] = $types[ $type ]['description'];
		}

		return $rule;
	}

	/**
	 * Propagate curated-token renames to existing rules. When a bot's robots.txt
	 * token is renamed (e.g. "SSL" -> "Qualys"), a `{from,to}` entry is appended
	 * to includes/data/token-renames.json; this rewrites any stored rule agent
	 * that still uses the old token, then re-saves so the served / physical
	 * robots.txt picks up the new token.
	 *
	 * Each entry is applied once (tracked by OPT_RENAMES_APPLIED) and only when
	 * the new token is a current catalog token AND the old one is no longer one,
	 * so an agent that still names a live bot is never rewritten.
	 */
	public function maybe_apply_token_renames() {
		if ( ! current_user_can( Coywolf_SEO_Admin::CAPABILITY ) ) {
			return;
		}
		$renames = Coywolf_SEO_Robots_Bots::token_renames();
		$total   = count( $renames );
		$applied = (int) get_option( self::OPT_RENAMES_APPLIED, 0 );
		if ( $applied >= $total ) {
			return;
		}
		// Mark the whole log as seen up front (idempotent even if nothing matches).
		update_option( self::OPT_RENAMES_APPLIED, $total, false );

		$known = Coywolf_SEO_Robots_Bots::known_agents();
		$map   = array(); // strtolower(old) => new token
		foreach ( array_slice( $renames, $applied ) as $r ) {
			$from = isset( $r['from'] ) ? (string) $r['from'] : '';
			$to   = isset( $r['to'] ) ? (string) $r['to'] : '';
			if ( '' === $from || '' === $to || $from === $to ) {
				continue;
			}
			// Only migrate when the new token is real and the old token is gone,
			// so we never rewrite an agent that still names a live catalog bot.
			if ( ! isset( $known[ $to ] ) || isset( $known[ $from ] ) ) {
				continue;
			}
			$map[ strtolower( $from ) ] = $to;
		}
		if ( empty( $map ) ) {
			return;
		}

		$rules   = $this->get_rules();
		$changed = false;
		foreach ( $rules as $i => $rule ) {
			if ( empty( $rule['agents'] ) || ! is_array( $rule['agents'] ) ) {
				continue;
			}
			$new  = array();
			$seen = array();
			$hit  = false;
			foreach ( $rule['agents'] as $agent ) {
				$agent = (string) $agent;
				$key   = strtolower( $agent );
				if ( isset( $map[ $key ] ) ) {
					$agent = $map[ $key ];
					$hit   = true;
				}
				if ( ! isset( $seen[ $agent ] ) ) {
					$seen[ $agent ] = true;
					$new[]          = $agent;
				}
			}
			if ( $hit ) {
				$rules[ $i ]['agents'] = $new;
				$changed               = true;
			}
		}
		if ( $changed ) {
			$this->save_rules( $rules );
		}
	}

	/* ================================================================== *
	 * AJAX: testing tool
	 * ================================================================== */

	public function ajax_test_rule() {
		check_ajax_referer( 'coywolf_seo_robots_ajax', 'nonce' );
		if ( ! current_user_can( Coywolf_SEO_Admin::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'coywolf-seo' ) ), 403 );
		}

		$rule = array(
			'type'      => isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '',
			'path'      => isset( $_POST['path'] ) ? $this->sanitize_path( wp_unslash( $_POST['path'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by sanitize_path(); nonce verified above via check_ajax_referer().
			'ext'       => isset( $_POST['ext'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_POST['ext'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Non-alphanumerics stripped by preg_replace(); nonce verified above via check_ajax_referer().
			'allow'     => isset( $_POST['allow'] ) ? $this->sanitize_path( wp_unslash( $_POST['allow'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by sanitize_path(); nonce verified above via check_ajax_referer().
			'strict'    => ! empty( $_POST['strict'] ),
			'directive' => ( isset( $_POST['directive'] ) && 'allow' === wp_unslash( $_POST['directive'] ) ) ? 'allow' : 'disallow', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict-compared to a literal and coerced to 'allow'/'disallow'; nonce verified above via check_ajax_referer().
		);

		$url  = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
		$path = $this->url_to_path( $url );
		if ( '' === $path ) {
			wp_send_json_error( array( 'message' => __( 'Enter a URL or path to test.', 'coywolf-seo' ) ), 400 );
		}

		$result     = Coywolf_SEO_Robots_Rules::test_rule( $rule, $path );
		$directives = array();
		foreach ( Coywolf_SEO_Robots_Rules::directives( $rule ) as $d ) {
			$directives[] = $d['directive'] . ': ' . $d['value'];
		}

		wp_send_json_success(
			array(
				'effect'     => $result['effect'], // 'disallow' | 'allow' | 'none'.
				'pattern'    => $result['pattern'],
				'path'       => $path,
				'directives' => $directives,
			)
		);
	}

	/**
	 * AJAX: test a URL against the WHOLE served robots.txt for one user-agent,
	 * using the full Google REP evaluator. Unlike ajax_test_rule() (one rule in
	 * isolation), this resolves Allow/Disallow across every group the agent
	 * matches — exactly how Googlebot decides.
	 */
	public function ajax_test_file() {
		check_ajax_referer( 'coywolf_seo_robots_ajax', 'nonce' );
		if ( ! current_user_can( Coywolf_SEO_Admin::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'coywolf-seo' ) ), 403 );
		}

		// esc_url_raw keeps percent-encoding intact; GetPathParamsQuery (inside
		// the evaluator) extracts the path/query the way Googlebot does.
		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		if ( '' === trim( $url ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter a URL or path to test.', 'coywolf-seo' ) ), 400 );
		}
		$agent_raw = isset( $_POST['agent'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['agent'] ) ) ) : '';

		$robots = $this->effective_robots();

		if ( '' === $agent_raw || '*' === $agent_raw ) {
			// Generic crawler: only the global "*" group applies.
			$query      = '*';
			$normalized = '*';
			$conforming = true;
		} else {
			// Normalize the queried token exactly as the matcher normalizes each
			// group's User-agent line (Google's ExtractUserAgent), so catalog
			// tokens carrying digits/dots/spaces (Ai2Bot, MJ12bot, Site24x7,
			// Pingdom.com_bot, archive.org_bot) match their own rules instead of
			// silently never matching.
			$normalized = Coywolf_SEO_Robots_Rep::extract_user_agent( $agent_raw );
			$conforming = Coywolf_SEO_Robots_Rep::is_valid_user_agent_to_obey( $agent_raw );
			$query      = ( '' === $normalized ) ? '*' : $normalized;
		}

		$res = Coywolf_SEO_Robots_Rep::evaluate( $robots, array( $query ), $url );

		wp_send_json_success(
			array(
				'allowed'    => $res['allowed'],
				'effect'     => $res['decision'],          // allow or disallow.
				'path'       => $res['path'],
				'directive'  => $res['matched_directive'], // allow, disallow, or none.
				'pattern'    => $res['matched_value'],
				'line'       => $res['matched_line'],
				'scope'      => $res['matched_scope'],     // specific or global.
				'agentRaw'   => $agent_raw,
				'agentToken' => $query,
				'conforming' => $conforming,
			)
		);
	}

	/* ================================================================== *
	 * robots.txt reading / writing
	 * ================================================================== */

	/**
	 * Serve the virtual robots.txt entirely from the managed rules.
	 *
	 * In virtual mode the plugin OWNS the output: it discards `$output` (whatever
	 * WordPress and other plugins assembled) and emits only its managed rules
	 * plus the configured Sitemap lines. WordPress's own base rules were imported
	 * into the managed set ({@see merge_wordpress_base()}), so nothing is lost and
	 * WordPress's block is no longer duplicated on top. Each rule is its own
	 * commented section; crawlers union same-agent groups themselves.
	 *
	 * The one dynamic exception is the "discourage search engines" setting: when
	 * the site is non-public, mirror WordPress and emit a blanket Disallow so
	 * toggling that option keeps working without re-importing.
	 *
	 * @param string $output Robots.txt assembled so far (ignored in virtual mode).
	 * @param bool   $public Whether the site is crawlable.
	 * @return string
	 */
	public function filter_robots_txt( $output, $public ) {
		unset( $public );
		if ( 'virtual' !== $this->get_mode() ) {
			return $output;
		}

		if ( '0' === (string) get_option( 'blog_public', 1 ) ) {
			return "User-agent: *\nDisallow: /\n";
		}

		$sections = $this->render_rule_sections();
		return $this->relocate_sitemaps( '' !== $sections ? $sections . "\n" : '' );
	}

	/**
	 * Ensure Sitemap: directives always sit at the very end of robots.txt.
	 *
	 * WordPress (and other plugins) inject the site's Sitemap line right after
	 * the "User-agent: *" group; once we merge our wildcard rules into that
	 * group and append further agent groups, the Sitemap line would otherwise
	 * be stranded in the middle. So pull every Sitemap line out of the body and
	 * re-append them (plus any we stored on import), deduplicated, at the end.
	 * When the operator chose to exclude sitemaps, they are simply dropped.
	 *
	 * @param string $text robots.txt content assembled so far.
	 * @return string
	 */
	private function relocate_sitemaps( $text ) {
		$text = (string) $text;

		// Capture existing Sitemap lines (from WP core / other plugins / import).
		$found = array();
		if ( preg_match_all( '/^[ \t]*(Sitemap:[^\r\n]*)$/mi', $text, $m ) ) {
			foreach ( $m[1] as $sm ) {
				$found[] = trim( $sm );
			}
		}

		// Remove them from the body and tidy the blank lines left behind.
		$text = preg_replace( '/^[ \t]*Sitemap:[^\r\n]*$/mi', '', $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", (string) $text );
		$text = rtrim( (string) $text );

		if ( get_option( self::OPT_OMIT_SITEMAPS ) ) {
			return $text . "\n";
		}

		// Merge found + stored sitemaps, dedup while preserving first-seen order.
		$sitemaps = array();
		foreach ( array_merge( $found, $this->sitemap_lines() ) as $sm ) {
			$sm = trim( (string) $sm );
			if ( '' !== $sm && ! in_array( $sm, $sitemaps, true ) ) {
				$sitemaps[] = $sm;
			}
		}

		if ( ! empty( $sitemaps ) ) {
			$text .= "\n\n" . implode( "\n", $sitemaps );
		}

		return $text . "\n";
	}

	/**
	 * Render the managed rules as robots.txt text: one commented section per
	 * rule, with any Sitemap: lines at the end. Used for the physical file and
	 * the preview.
	 *
	 * @return string
	 */
	public function render_managed_rules() {
		$out = $this->render_rule_sections();

		$sitemaps = $this->sitemap_lines();
		if ( ! empty( $sitemaps ) ) {
			if ( '' !== $out ) {
				$out .= "\n\n";
			}
			$out .= implode( "\n", $sitemaps );
		}

		return rtrim( $out );
	}

	/**
	 * Render each (non-sitemap) rule as its own robots.txt section: a comment
	 * carrying the rule's name and description, the rule's User-agent line(s)
	 * (stacked when it targets several bots), then its directive line(s).
	 * Sections appear in rule order, separated by a blank line. Sitemap rules
	 * are emitted separately at the very end (see {@see sitemap_lines()}).
	 *
	 * Rendering is per-rule rather than merged-by-agent so each rule keeps its
	 * explanatory comment; crawlers union any same-agent groups themselves.
	 *
	 * @return string
	 */
	private function render_rule_sections() {
		$omit_comments = (bool) get_option( self::OPT_OMIT_COMMENTS );
		$out           = '';
		foreach ( $this->get_rules() as $rule ) {
			if ( isset( $rule['type'] ) && 'sitemap' === $rule['type'] ) {
				continue;
			}
			$lines = Coywolf_SEO_Robots_Rules::directives( $rule );
			if ( empty( $lines ) ) {
				continue;
			}

			// Agents this rule applies to, de-duplicated in first-seen order.
			$agents       = ( ! empty( $rule['agents'] ) && is_array( $rule['agents'] ) ) ? $rule['agents'] : array( '*' );
			$seen_a       = array();
			$clean_agents = array();
			foreach ( $agents as $a ) {
				$a = (string) $a;
				if ( '' !== $a && ! isset( $seen_a[ $a ] ) ) {
					$seen_a[ $a ]   = true;
					$clean_agents[] = $a;
				}
			}
			if ( empty( $clean_agents ) ) {
				$clean_agents = array( '*' );
			}

			if ( ! $omit_comments ) {
				$comment = $this->rule_comment( $rule );
				if ( '' !== $comment ) {
					$out .= '# ' . $comment . "\n";
				}
			}
			foreach ( $clean_agents as $agent ) {
				$out .= 'User-agent: ' . $agent . "\n";
			}
			// Directive lines, de-duplicated within the rule, in order.
			$seen_l = array();
			foreach ( $lines as $l ) {
				$line = $l['directive'] . ': ' . $l['value'];
				if ( isset( $seen_l[ $line ] ) ) {
					continue;
				}
				$seen_l[ $line ] = true;
				$out            .= $line . "\n";
			}
			$out .= "\n";
		}

		return rtrim( $out );
	}

	/**
	 * The comment text for a rule section: its name and description collapsed
	 * to a single line (robots.txt comments can't span lines). Empty when the
	 * rule has neither.
	 *
	 * @param array $rule Rule.
	 * @return string
	 */
	private function rule_comment( $rule ) {
		$name = isset( $rule['name'] ) ? trim( (string) preg_replace( '/\s+/', ' ', (string) $rule['name'] ) ) : '';
		$desc = isset( $rule['description'] ) ? trim( (string) preg_replace( '/\s+/', ' ', (string) $rule['description'] ) ) : '';
		if ( '' !== $name && '' !== $desc ) {
			return $name . ': ' . $desc;
		}
		return ( '' !== $name ) ? $name : $desc;
	}

	/**
	 * The configured sitemap URLs (Settings → XML sitemaps), trimmed and
	 * de-duplicated in first-seen order.
	 *
	 * @return array<int,string>
	 */
	private function stored_sitemaps() {
		$urls   = array();
		$stored = get_option( self::OPT_SITEMAPS, array() );
		if ( is_array( $stored ) ) {
			foreach ( $stored as $sm ) {
				$sm = trim( (string) $sm );
				if ( '' !== $sm && ! in_array( $sm, $urls, true ) ) {
					$urls[] = $sm;
				}
			}
		}

		// Legacy fallback: before sitemaps moved to Settings they were stored as
		// a "sitemap"-type rule. Surface those URLs too so output stays correct
		// until maybe_migrate_sitemap_rules() (admin-only) moves them across.
		$rules = get_option( self::OPT_RULES, array() );
		if ( is_array( $rules ) ) {
			foreach ( $rules as $rule ) {
				if ( ! isset( $rule['type'] ) || 'sitemap' !== $rule['type'] ) {
					continue;
				}
				$legacy = ( isset( $rule['sitemaps'] ) && is_array( $rule['sitemaps'] ) ) ? $rule['sitemaps'] : array();
				foreach ( $legacy as $sm ) {
					$sm = trim( (string) $sm );
					if ( '' !== $sm && ! in_array( $sm, $urls, true ) ) {
						$urls[] = $sm;
					}
				}
			}
		}

		return $urls;
	}

	/**
	 * Stored Sitemap lines, ready to emit (empty when sitemaps are excluded).
	 *
	 * @return array<int,string>
	 */
	private function sitemap_lines() {
		// Operator chose to keep sitemap links out of robots.txt entirely.
		if ( get_option( self::OPT_OMIT_SITEMAPS ) ) {
			return array();
		}

		$out = array();
		foreach ( $this->stored_sitemaps() as $u ) {
			$out[] = 'Sitemap: ' . $u;
		}
		return $out;
	}

	/**
	 * The robots.txt that is actually served right now (for the preview).
	 *
	 * @return string
	 */
	private function effective_robots() {
		if ( 'physical' === $this->get_mode() ) {
			$path = $this->robots_txt_path();
			if ( is_readable( $path ) ) {
				return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
			// Not written yet — show what we would write.
			return self::BLOCK_START . "\n" . $this->render_managed_rules() . "\n" . self::BLOCK_END;
		}
		// Virtual: base (WP default + other plugins) with our block applied.
		$base = $this->virtual_base();
		return $this->filter_robots_txt( $base, (bool) get_option( 'blog_public', 1 ) );
	}

	/**
	 * The robots.txt content to import from: the physical file (managed block
	 * stripped) or, in virtual mode, WordPress's base output without ours.
	 *
	 * @return string
	 */
	private function importable_robots() {
		if ( 'physical' === $this->get_mode() ) {
			$path = $this->robots_txt_path();
			$text = is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			// Strip a UTF-8 BOM and enforce the 500 KiB read limit before doing
			// anything else, so both the parser and the preserved "outside" see
			// the same bytes a crawler would.
			$text = Coywolf_SEO_Robots_Rules::preclean( $text );
			return $this->strip_managed_block( $text );
		}
		return $this->virtual_base();
	}

	/**
	 * WordPress's virtual robots.txt with OUR filter removed, so importing or
	 * previewing doesn't double-count the managed block. Mirrors core's
	 * default output and lets other plugins' `robots_txt` filters contribute.
	 *
	 * @return string
	 */
	private function virtual_base() {
		$public = get_option( 'blog_public', 1 );

		$default = "User-agent: *\n";
		if ( '0' === (string) $public ) {
			$default .= "Disallow: /\n";
		} else {
			list( $admin_path, $ajax_path ) = $this->wp_admin_paths();
			$default                       .= 'Disallow: ' . $admin_path . "\n";
			$default                       .= 'Allow: ' . $ajax_path . "\n";
		}

		remove_filter( 'robots_txt', array( $this, 'filter_robots_txt' ), PHP_INT_MAX );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Re-applies WordPress core's own 'robots_txt' filter to capture the base output; not a plugin-owned hook.
		$out = apply_filters( 'robots_txt', $default, $public );
		add_filter( 'robots_txt', array( $this, 'filter_robots_txt' ), PHP_INT_MAX, 2 );

		return (string) $out;
	}

	/**
	 * WordPress's own default robots.txt rules for a public site — the block it
	 * injects on top of ours. Used so we can fold these into the managed rules
	 * and then stop emitting WordPress's duplicate. The "discourage search
	 * engines" (non-public) "Disallow: /" is intentionally NOT imported — it's
	 * handled dynamically in {@see filter_robots_txt()} so toggling it stays live.
	 *
	 * @return string
	 */
	private function wordpress_core_base() {
		list( $admin_path, $ajax_path ) = $this->wp_admin_paths();
		return "User-agent: *\nDisallow: " . $admin_path . "\nAllow: " . $ajax_path . "\n";
	}

	/**
	 * Path components of WordPress's wp-admin directory and its admin-ajax
	 * endpoint, derived from admin_url() rather than a hardcoded "/wp-admin/…"
	 * string. The endpoint isn't guaranteed to live under /wp-admin/ on every
	 * install, so we resolve it the way the WordPress handbook recommends
	 * (admin_url) and keep only the path component for robots.txt.
	 *
	 * @return array{0:string,1:string} [ wp-admin path, admin-ajax endpoint path ]
	 */
	private function wp_admin_paths() {
		$admin_path = wp_parse_url( admin_url(), PHP_URL_PATH );
		if ( ! is_string( $admin_path ) || '' === $admin_path ) {
			$admin_path = '/wp-admin/';
		}
		$ajax_path = wp_parse_url( admin_url( 'admin-ajax.php' ), PHP_URL_PATH );
		if ( ! is_string( $ajax_path ) || '' === $ajax_path ) {
			$ajax_path = trailingslashit( $admin_path ) . 'admin-ajax.php';
		}
		return array( $admin_path, $ajax_path );
	}

	/**
	 * Fold WordPress's own base rules into the managed set and reconcile them,
	 * so the served robots.txt carries them once (from us) instead of having
	 * WordPress add a duplicate block on top. Idempotent. Also preserves any
	 * Sitemap: line the current robots.txt advertises (e.g. WordPress's
	 * wp-sitemap.xml), since we stop emitting WordPress's block.
	 */
	private function merge_wordpress_base() {
		$parsed = Coywolf_SEO_Robots_Rules::parse( $this->wordpress_core_base() );
		$wp     = isset( $parsed['rules'] ) ? $parsed['rules'] : array();
		if ( ! empty( $wp ) ) {
			foreach ( $wp as $i => $r ) {
				$wp[ $i ]['id'] = $this->new_id();
			}
			$this->persist_rules( $this->reconcile_with_wordpress( $wp, $this->get_rules() ) );
		}

		// Keep advertising whatever Sitemap the served robots.txt currently has.
		$found = Coywolf_SEO_Robots_Rules::parse( $this->virtual_base() );
		$found = isset( $found['sitemaps'] ) ? $found['sitemaps'] : array();
		if ( ! empty( $found ) ) {
			$stored = $this->stored_sitemaps();
			foreach ( $found as $u ) {
				$u = esc_url_raw( trim( (string) $u ) );
				if ( '' !== $u && ! in_array( $u, $stored, true ) ) {
					$stored[] = $u;
				}
			}
			update_option( self::OPT_SITEMAPS, $stored, false );
		}
	}

	/**
	 * Merge WordPress's base rules into the plugin's, giving WordPress
	 * preference. WordPress's rules all target "*", so any plugin rule whose
	 * directives are all paths WordPress now governs is fully absorbed by the
	 * "*" rule (specific bots are a subset of all bots) and dropped — leaving
	 * WordPress's "*" rule to stand. Existing plugin rules on other paths keep
	 * their names, descriptions, and agents untouched.
	 *
	 * Example: WP "* Disallow: /wp-admin/" absorbs a plugin
	 * "Googlebot,bingbot,… Disallow: /wp-admin/", so the result is just the
	 * "*" rule.
	 *
	 * @param array $wp_rules     WordPress's base rules (each targets "*").
	 * @param array $plugin_rules Current managed rules.
	 * @return array Reconciled rule set (WordPress rules first).
	 */
	private function reconcile_with_wordpress( $wp_rules, $plugin_rules ) {
		$wp_values = array();
		foreach ( $wp_rules as $r ) {
			foreach ( Coywolf_SEO_Robots_Rules::directives( $r ) as $d ) {
				$wp_values[ $d['value'] ] = true;
			}
		}

		$kept = array();
		foreach ( $plugin_rules as $r ) {
			$values = array();
			foreach ( Coywolf_SEO_Robots_Rules::directives( $r ) as $d ) {
				$values[] = $d['value'];
			}
			// Drop the rule only when EVERY directive it emits is a path
			// WordPress now governs (so it's fully covered by WP's "*" rule).
			$absorbed = ! empty( $values );
			foreach ( $values as $v ) {
				if ( ! isset( $wp_values[ $v ] ) ) {
					$absorbed = false;
					break;
				}
			}
			if ( ! $absorbed ) {
				$kept[] = $r;
			}
		}

		return $this->dedupe_rules( array_merge( $wp_rules, $kept ) );
	}

	/**
	 * Write the managed block into the physical robots.txt, preserving any
	 * lines outside the markers.
	 *
	 * @param string|null $outside Content to keep outside the managed block.
	 *                             When null (the default), the current file is
	 *                             re-read and everything except our managed
	 *                             block is preserved, so hand-written lines
	 *                             survive. Callers that have already computed
	 *                             the lines to preserve — e.g. import, which
	 *                             strips the directives it just turned into
	 *                             rules so they aren't duplicated — pass them in.
	 * @return true|WP_Error
	 */
	private function write_physical( $outside = null ) {
		$path = $this->robots_txt_path();

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return new WP_Error( 'coywolf_seo_robots_fs', __( 'The filesystem is not available.', 'coywolf-seo' ) );
		}

		if ( null === $outside ) {
			$existing = $wp_filesystem->exists( $path ) ? (string) $wp_filesystem->get_contents( $path ) : '';
			$outside  = $this->strip_managed_block( $existing );
		}
		$outside = trim( (string) $outside );
		$block   = self::BLOCK_START . "\n" . $this->render_managed_rules() . "\n" . self::BLOCK_END;

		$content = ( '' !== $outside ) ? $outside . "\n\n" . $block . "\n" : $block . "\n";

		if ( ! $wp_filesystem->put_contents( $path, $content, FS_CHMOD_FILE ) ) {
			return new WP_Error( 'coywolf_seo_robots_write', __( 'Could not write robots.txt. Check file permissions.', 'coywolf-seo' ) );
		}
		return true;
	}

	/**
	 * Remove the fenced managed block (and its markers) from robots.txt text.
	 *
	 * @param string $text robots.txt contents.
	 * @return string
	 */
	private function strip_managed_block( $text ) {
		$pattern = '/\n*' . preg_quote( self::BLOCK_START, '/' ) . '.*?' . preg_quote( self::BLOCK_END, '/' ) . '\n*/s';
		return (string) preg_replace( $pattern, "\n", (string) $text );
	}

	/**
	 * Compute what to preserve OUTSIDE the managed block on import: keep
	 * comments and blank lines, but drop every line {@see Coywolf_SEO_Robots_Rules::parse()}
	 * consumes (User-agent / Disallow / Allow / Sitemap, including a misspelled
	 * directive or one missing its colon) as well as the deprecated/unsupported
	 * directives the importer strips (Crawl-delay, Noindex, Nofollow, Host,
	 * Request-rate, Visit-time) — so none of them are written a second time
	 * above the block, and the cleanup the importer performs actually sticks.
	 *
	 * @param string $text robots.txt contents (managed block already stripped).
	 * @return string
	 */
	private function strip_directive_lines( $text ) {
		$drop  = array(
			'user-agent'   => true,
			'disallow'     => true,
			'allow'        => true,
			'sitemap'      => true,
			'crawl-delay'  => true,
			'noindex'      => true,
			'nofollow'     => true,
			'host'         => true,
			'request-rate' => true,
			'visit-time'   => true,
		);
		$typos = array(
			'disalow'     => 'disallow',
			'dissalow'    => 'disallow',
			'dissallow'   => 'disallow',
			'disallows'   => 'disallow',
			'alow'        => 'allow',
			'user-agents' => 'user-agent',
			'useragent'   => 'user-agent',
		);

		// Section-header comments this plugin generates for its own rules. After
		// a "keep" deactivation these comments sit alongside the (now unfenced)
		// rules; on re-import the rules move back into the managed block, so the
		// matching outside comments would be orphaned — drop them.
		$managed_comments = array();
		foreach ( $this->get_rules() as $rule ) {
			$c = $this->rule_comment( $rule );
			if ( '' !== $c ) {
				$managed_comments[ '# ' . $c ] = true;
			}
		}

		$lines = preg_split( '/\r\n|\r|\n/', (string) $text );
		$kept  = array();
		foreach ( $lines as $raw ) {
			$line = trim( (string) $raw );
			if ( '' === $line || '#' === $line[0] ) {
				if ( isset( $managed_comments[ $line ] ) ) {
					continue; // Orphaned plugin section-header comment.
				}
				$kept[] = $raw; // Keep blank lines and other comments verbatim.
				continue;
			}
			$pos = strpos( $line, ':' );
			if ( false !== $pos ) {
				$field = strtolower( trim( substr( $line, 0, $pos ) ) );
			} elseif ( preg_match( '/^([A-Za-z][A-Za-z-]*)\s+/', $line, $m ) ) {
				$field = strtolower( $m[1] ); // Missing-colon directive.
			} else {
				$kept[] = $raw; // Not a directive we recognise — preserve it.
				continue;
			}
			if ( isset( $typos[ $field ] ) ) {
				$field = $typos[ $field ];
			}
			if ( isset( $drop[ $field ] ) ) {
				continue;
			}
			$kept[] = $raw; // A directive we don't model — preserve it.
		}
		$text = implode( "\n", $kept );
		// Collapse blank-line runs left behind by the removed directives.
		$text = preg_replace( "/\n{3,}/", "\n\n", (string) $text );
		return trim( (string) $text );
	}

	/* ================================================================== *
	 * Legacy cleanup
	 * ================================================================== */

	/**
	 * One-time cleanup for sites upgrading from a version that fetched the bot
	 * list from a remote source on a schedule. That feature was removed — the
	 * catalog now ships bundled and is refreshed only through plugin updates —
	 * so clear any lingering WP-Cron event and the now-unused options it left
	 * behind. Guarded so it runs at most once per site.
	 */
	public function maybe_clear_legacy_schedule() {
		if ( ! current_user_can( Coywolf_SEO_Admin::CAPABILITY ) ) {
			return;
		}
		if ( get_option( self::OPT_LEGACY_CLEARED ) ) {
			return;
		}
		update_option( self::OPT_LEGACY_CLEARED, '1', false );

		wp_clear_scheduled_hook( self::CRON_HOOK );
		$legacy_options = array(
			self::OPT_FREQ,
			self::OPT_DAY,
			self::OPT_WEEK,
			self::OPT_TIME,
			self::OPT_EMAIL,
			self::OPT_EMAIL_TO,
			'coywolf_seo_robots_bots',          // Stored remote catalog copy (no longer used).
			'coywolf_seo_robots_bots_updated',  // Timestamp of the last remote refresh.
		);
		foreach ( $legacy_options as $opt ) {
			delete_option( $opt );
		}
	}

	/* ================================================================== *
	 * Storage + helpers
	 * ================================================================== */

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function get_rules() {
		$raw = get_option( self::OPT_RULES, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		// Per-request memo: get_rules() is called several times in one request
		// (list page, render path, find_rule, conflict checks) and each call
		// re-normalizes + de-dupes (wp_json_encode per rule). Reuse the result
		// while the underlying option is unchanged — the raw comparison is far
		// cheaper than recomputing, and it can't go stale.
		if ( null !== $this->rules_cache && $this->rules_cache_src === $raw ) {
			return $this->rules_cache;
		}

		$rules = $raw;
		// Normalise each rule's agents on read so a stored rule that mixes the
		// "*" wildcard with specific bots (e.g. from an older save where "All
		// robots" was left checked) doesn't emit its directive under BOTH the
		// "*" group and the named-bot group — which would block every crawler.
		foreach ( $rules as $i => $rule ) {
			if ( isset( $rule['agents'] ) && is_array( $rule['agents'] ) ) {
				$rules[ $i ]['agents'] = $this->normalize_agents( $rule['agents'] );
			}
		}
		// Drop exact-duplicate rules on read so a table that picked up a
		// duplicate (e.g. an earlier import re-parsing the managed block)
		// self-heals without the user having to act.
		$this->rules_cache     = $this->dedupe_rules( $rules );
		$this->rules_cache_src = $raw;
		return $this->rules_cache;
	}

	/**
	 * Remove exact-duplicate rules, keeping the first occurrence. Two rules are
	 * duplicates when their type, directive, path/ext/allow/strict, agent set,
	 * and sitemap list all match — i.e. they would render identically. Distinct
	 * rules (different target or agents) are always kept.
	 *
	 * @param array<int,array<string,mixed>> $rules Rules.
	 * @return array<int,array<string,mixed>>
	 */
	private function dedupe_rules( $rules ) {
		$seen = array();
		$out  = array();
		foreach ( $rules as $rule ) {
			$agents = ( isset( $rule['agents'] ) && is_array( $rule['agents'] ) ) ? $rule['agents'] : array();
			sort( $agents );
			$key = wp_json_encode(
				array(
					isset( $rule['type'] ) ? $rule['type'] : '',
					isset( $rule['directive'] ) ? $rule['directive'] : '',
					isset( $rule['path'] ) ? $rule['path'] : '',
					isset( $rule['ext'] ) ? $rule['ext'] : '',
					isset( $rule['allow'] ) ? $rule['allow'] : '',
					! empty( $rule['strict'] ),
					$agents,
				)
			);
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $rule;
		}
		return $out;
	}

	/**
	 * Resolve a single rule's user-agent list. Within one rule, "*" and a
	 * specific agent are contradictory (the directive would apply to every
	 * crawler AND, redundantly/conflictingly, to the named one). When both are
	 * present the specific agents win and "*" is dropped; a rule with only "*"
	 * (or none) stays as the wildcard. Order and uniqueness are preserved.
	 *
	 * Scope is intentionally a SINGLE rule — two separate rules with
	 * overlapping agents are a legitimate user choice and are left alone.
	 *
	 * @param array<int,string> $agents Raw agent tokens.
	 * @return array<int,string>
	 */
	private function normalize_agents( $agents ) {
		$clean = array();
		foreach ( (array) $agents as $a ) {
			$a = trim( (string) $a );
			if ( '' !== $a && ! in_array( $a, $clean, true ) ) {
				$clean[] = $a;
			}
		}
		$specific = array_values(
			array_filter(
				$clean,
				static function ( $a ) {
					return '*' !== $a;
				}
			)
		);
		if ( ! empty( $specific ) ) {
			return $specific;
		}
		return array( '*' );
	}

	/**
	 * Persist the rules option (not autoloaded) and clear the per-request cache.
	 * The single write path for OPT_RULES so {@see get_rules()}'s memo can never
	 * go stale.
	 *
	 * @param array $rules Rules.
	 */
	private function persist_rules( $rules ) {
		update_option( self::OPT_RULES, array_values( $rules ), false );
		$this->rules_cache = null;
	}

	/**
	 * @param array $rules Rules.
	 */
	private function save_rules( $rules ) {
		$this->persist_rules( $rules );
		// Keep the physical file in sync when in physical mode.
		if ( 'physical' === $this->get_mode() ) {
			$this->write_physical();
		}
	}

	/**
	 * @param string $id Rule id.
	 * @return array<string,mixed>|null
	 */
	private function find_rule( $id ) {
		foreach ( $this->get_rules() as $rule ) {
			if ( $rule['id'] === $id ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * @return string 'virtual' | 'physical'
	 */
	private function get_mode() {
		$mode = get_option( self::OPT_MODE, 'virtual' );
		if ( 'physical' !== $mode ) {
			return 'virtual';
		}
		// Self-heal: if the mode is physical but the file is gone (deleted via
		// the resolver, over FTP, or by another tool), fall back to virtual so
		// WordPress keeps serving the managed rules. update_option() only
		// writes when the value actually changes.
		if ( ! $this->has_physical_robots_txt() ) {
			update_option( self::OPT_MODE, 'virtual' );
			return 'virtual';
		}
		return 'physical';
	}

	/**
	 * Trailing-slashed absolute path to the site root — the directory the
	 * public /robots.txt is served from.
	 *
	 * Derived from get_home_path() rather than ABSPATH so it stays correct when
	 * WordPress lives in its own subdirectory but the site is served from the
	 * domain root (get_home_path() returns the home directory; ABSPATH would
	 * point at the WordPress install instead). For a root install the two are
	 * identical. get_home_path() is defined in wp-admin/includes/file.php, which
	 * is not loaded on the front end — and this can run there, via the
	 * `robots_txt` filter → get_mode() → has_physical_robots_txt() — so pull the
	 * admin file in on demand. Resolved once per request and cached.
	 *
	 * @return string
	 */
	private function site_root_path() {
		if ( null === $this->site_root ) {
			if ( ! function_exists( 'get_home_path' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			$this->site_root = trailingslashit( get_home_path() );
		}
		return $this->site_root;
	}

	/**
	 * Absolute path to the public robots.txt in the site root.
	 *
	 * @return string
	 */
	private function robots_txt_path() {
		return $this->site_root_path() . 'robots.txt';
	}

	/**
	 * Whether a physical robots.txt file exists in the site root. Drives the
	 * "Import from robots.txt" button (a physical file is the only thing worth
	 * importing manually; virtual rules are imported automatically).
	 *
	 * @return bool
	 */
	private function has_physical_robots_txt() {
		return file_exists( $this->robots_txt_path() );
	}

	/**
	 * @return string
	 */
	private function new_id() {
		return 'r' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 10 );
	}

	/**
	 * Sanitize a user-agent token (allow the wildcard and common UA chars).
	 *
	 * @param string $agent Raw token.
	 * @return string
	 */
	private function sanitize_agent( $agent ) {
		$agent = trim( (string) $agent );
		if ( '*' === $agent ) {
			return '*';
		}
		// User-agent product tokens: letters, digits, and a few separators.
		$agent = preg_replace( '/[^A-Za-z0-9 _.\-\/;:()]+/', '', $agent );
		return trim( (string) $agent );
	}

	/**
	 * Sanitize a robots.txt path value while keeping its wildcards/anchors.
	 *
	 * @param string $path Raw value.
	 * @return string
	 */
	private function sanitize_path( $path ) {
		$path = trim( (string) $path );
		// Drop control characters and whitespace; keep printable path chars.
		$path = preg_replace( '/[\x00-\x1F\x7F]+/', '', $path );
		$path = str_replace( array( "\n", "\r", ' ' ), '', $path );
		return $path;
	}

	/**
	 * Reduce a URL or path to the path+query used for matching.
	 *
	 * @param string $url URL or path.
	 * @return string
	 */
	private function url_to_path( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			$parts = wp_parse_url( $url );
			$path  = isset( $parts['path'] ) ? $parts['path'] : '/';
			if ( ! empty( $parts['query'] ) ) {
				$path .= '?' . $parts['query'];
			}
			return ( '' === $path ) ? '/' : $path;
		}
		return ( '/' === $url[0] ) ? $url : '/' . $url;
	}

	/**
	 * Short summary of the agents a rule applies to (for the list table).
	 *
	 * @param array $agents Agent tokens.
	 * @return string
	 */
	private function agents_summary( $agents ) {
		if ( empty( $agents ) ) {
			return '—';
		}
		if ( in_array( '*', $agents, true ) ) {
			return __( 'All robots (*)', 'coywolf-seo' );
		}
		$count = count( $agents );
		$head  = array_slice( $agents, 0, 3 );
		$text  = implode( ', ', $head );
		if ( $count > 3 ) {
			$text .= ' ' . sprintf(
				/* translators: %d: number of additional bots */
				__( '+%d more', 'coywolf-seo' ),
				$count - 3
			);
		}
		return $text;
	}

	/**
	 * Redirect to one of our admin pages with query args, then exit.
	 *
	 * @param string $page Page slug.
	 * @param array  $args Extra query args.
	 */
	private function redirect_with( $page, $args ) {
		$url = add_query_arg(
			array_merge( array( 'page' => $page ), $args ),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render admin notices from redirect query args.
	 */
	private function render_notices() {
		// Success/error notices are shown after the plugin's own nonce-verified
		// actions redirect back with a status key in the URL; reading those keys
		// for display changes no state and needs no nonce of its own.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['coywolf_seo_robots_msg'] ) ) {
			$msg = sanitize_key( wp_unslash( $_GET['coywolf_seo_robots_msg'] ) );
			$map = array(
				'added'              => __( 'Rule added.', 'coywolf-seo' ),
				'updated'            => __( 'Rule updated.', 'coywolf-seo' ),
				'deleted'            => __( 'Rule deleted.', 'coywolf-seo' ),
				'settings'           => __( 'Settings saved.', 'coywolf-seo' ),
				'robots_saved'       => __( 'Robots.txt saved — parsed and optimized into the rules below.', 'coywolf-seo' ),
				'resolved_import'    => __( 'Switched to physical mode and imported the rules from your robots.txt file.', 'coywolf-seo' ),
				'resolved_overwrite' => __( 'Switched to physical mode and wrote your rules to robots.txt.', 'coywolf-seo' ),
				'resolved_delete'    => __( 'Deleted the physical robots.txt file. Your virtual rules are now served.', 'coywolf-seo' ),
			);
			if ( 'bulk_deleted' === $msg ) {
				$count = isset( $_GET['coywolf_seo_robots_count'] ) ? max( 0, (int) $_GET['coywolf_seo_robots_count'] ) : 0;
				$text  = sprintf(
					/* translators: %d: number of rules deleted */
					_n( '%d rule deleted.', '%d rules deleted.', $count, 'coywolf-seo' ),
					$count
				);
			} elseif ( 'imported' === $msg ) {
				$count = isset( $_GET['coywolf_seo_robots_count'] ) ? max( 0, (int) $_GET['coywolf_seo_robots_count'] ) : 0;
				$text  = sprintf(
					/* translators: %d: number of rules imported */
					_n( 'Imported %d rule from the file.', 'Imported %d rules from the file.', $count, 'coywolf-seo' ),
					$count
				);
			} else {
				$text = isset( $map[ $msg ] ) ? $map[ $msg ] : '';
			}
			if ( '' !== $text ) {
				printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $text ) );
			}
		}

		if ( isset( $_GET['coywolf_seo_robots_error'] ) ) {
			$err = sanitize_key( wp_unslash( $_GET['coywolf_seo_robots_error'] ) );
			if ( 'write' === $err && isset( $_GET['coywolf_seo_robots_errmsg'] ) ) {
				$text = sanitize_text_field( wp_unslash( $_GET['coywolf_seo_robots_errmsg'] ) );
			} elseif ( 'type' === $err ) {
				$text = __( 'Please choose a rule type.', 'coywolf-seo' );
			} elseif ( 'delete_failed' === $err ) {
				$text = __( 'Could not delete robots.txt. Remove it manually or check file permissions.', 'coywolf-seo' );
			} elseif ( 'export' === $err ) {
				$text = __( 'Could not generate the export file. Please try again.', 'coywolf-seo' );
			} elseif ( 'import_file' === $err ) {
				$text = __( 'Please choose a JSON file (up to 1 MB) to import.', 'coywolf-seo' );
			} elseif ( 'import_invalid' === $err ) {
				$text = __( 'That file is not a valid Robots.txt Manager export.', 'coywolf-seo' );
			} else {
				$text = __( 'Something went wrong.', 'coywolf-seo' );
			}
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $text ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}
