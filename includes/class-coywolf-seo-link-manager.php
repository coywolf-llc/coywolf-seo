<?php
/**
 * Coywolf SEO — Link Manager module.
 *
 * Build and maintain an inventory of every internal and external link across
 * posts and pages. Analyze once, then keep it current automatically — and edit
 * a link's URL, rel attributes, and anchor text across every place it appears
 * from one screen.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Link Manager module class.
 *
 * The scan is started by a button (no WP-Cron, no scheduled task). When the
 * button is clicked the server builds a queue of published posts and fires a
 * non-blocking "loopback" request to itself. Each loopback request processes a
 * small batch of posts and then dispatches the next batch, chaining itself
 * server-side until the queue is empty. Because the work happens on the server,
 * it keeps running even if the admin closes the tab or navigates away. The
 * admin screen simply polls a status endpoint to draw the progress bar and the
 * results table.
 */
final class Coywolf_SEO_Link_Manager {

	const VERSION          = '2.5.0';
	const SLUG             = 'coywolf-seo-link-manager'; // All Links admin page slug.
	const STATE_OPTION     = 'coywolf_seo_lm_state';
	const IGNORES_OPTION   = 'coywolf_seo_lm_ignores';
	const URL_CACHE_OPTION = 'coywolf_seo_lm_url_cache';
	const CANCEL_FLAG      = 'coywolf_seo_lm_cancel';

	// Link Manager (persistent inventory) constants.
	const EDIT_SLUG            = 'coywolf-seo-lm-edit';          // hidden Edit Link admin page
	const DB_VERSION_OPTION    = 'coywolf_seo_lm_db_version';
	const ANALYZED_OPTION      = 'coywolf_seo_lm_analyzed';      // '1' once the initial analysis has finished
	const RECHECK_QUEUE_OPTION = 'coywolf_seo_lm_recheck_queue'; // url_hash list awaiting a network re-check
	const RECHECK_HOOK         = 'coywolf_seo_lm_drain_recheck'; // wp-cron safety net for the re-check queue
	const DB_VERSION           = 3;

	// Allowed values for the scope option (Settings → Links to check).
	const SCOPE_ALL      = 'all';
	const SCOPE_EXTERNAL = 'external';
	const SCOPE_INTERNAL = 'internal';

	// Allowed values for the scan speed option (Settings → Scan speed).
	// Each profile maps to a (batch_size, concurrency) pair below.
	const SPEED_POLITE  = 'polite';
	const SPEED_DEFAULT = 'default';
	const SPEED_FAST    = 'fast';
	const SPEED_FASTER  = 'faster';

	const BATCH_SIZE      = 5;   // Posts processed per loopback request (default profile).
	const CONCURRENCY     = 8;   // Links checked in parallel (default profile, filterable).
	const HTTP_TIMEOUT    = 8;   // Seconds allowed per link request.
	const STALE_AFTER     = 90;  // Seconds before a running scan is treated as stalled.
	const CONCURRENCY_MAX = 32; // Upper bound for the `coywolf_seo_lm_concurrency` filter.

	// Identify as a current Chrome on Windows. Many sites (LinkedIn, Cloudflare,
	// etc.) return 403/429/999 to non-browser agents, so presenting a real
	// browser UA — together with matching browser headers — reduces false
	// "broken" results. Filterable via 'coywolf_seo_lm_user_agent'.
	const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36';

	/**
	 * Per-request memo of the ignore-rule list so is_ignored() does not read
	 * the option once per link during a scan batch. Null until first read;
	 * refreshed by save_ignores(). Request-scoped, so a worker that mutates
	 * ignores via save_ignores() still sees the update within the same call.
	 *
	 * @var array|null
	 */
	private $ignores_cache = null;

	/**
	 * Per-request memo of whether the initial analysis has completed, so the
	 * save hook does not re-read the option once per post on bulk edits.
	 *
	 * @var bool|null
	 */
	private $lm_analyzed = null;

	/**
	 * Register every runtime hook for the module.
	 *
	 * Called once by Coywolf SEO during bootstrap:
	 *   ( new Coywolf_SEO_Link_Manager() )->init();
	 *
	 * The All Links menu item itself is registered by Coywolf SEO; this module
	 * only wires the screen renderers, assets, AJAX endpoints, indexing hooks,
	 * and the schema self-heal.
	 */
	public function init() {
		// Dormant when the Link Manager feature is turned off: the page is hidden
		// and link scanning, indexing, AJAX, and the re-check cron all stop. The
		// object is still constructed (so accessors do not fatal) and the saved
		// link data is kept.
		if ( ! Coywolf_SEO_Options::feature_enabled( 'links' ) ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'lm_maybe_upgrade_db' ) );

		// Operator-facing endpoints (require capability + nonce).
		add_action( 'wp_ajax_coywolf_seo_lm_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_coywolf_seo_lm_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_coywolf_seo_lm_cancel', array( $this, 'ajax_cancel' ) );
		add_action( 'wp_ajax_coywolf_seo_lm_links', array( $this, 'ajax_lm_links' ) );
		add_action( 'wp_ajax_coywolf_seo_lm_remove_link', array( $this, 'ajax_lm_remove_link' ) );
		add_action( 'wp_ajax_coywolf_seo_lm_remove_links_bulk', array( $this, 'ajax_lm_remove_links_bulk' ) );
		add_action( 'wp_ajax_coywolf_seo_lm_replace_link', array( $this, 'ajax_lm_replace_link' ) );
		add_action( 'wp_ajax_coywolf_seo_lm_replace_links_bulk', array( $this, 'ajax_lm_replace_links_bulk' ) );
		add_action( 'wp_ajax_coywolf_seo_lm_ignore_add', array( $this, 'ajax_ignore_add' ) );
		add_action( 'wp_ajax_coywolf_seo_lm_unignore', array( $this, 'ajax_lm_unignore' ) );
		add_action( 'wp_ajax_coywolf_seo_lm_unignore_bulk', array( $this, 'ajax_lm_unignore_bulk' ) );

		// Edit Link page save handler and the post-list drill-down filter.
		add_action( 'admin_post_coywolf_seo_lm_save_edit', array( $this, 'lm_save_edit' ) );
		add_action( 'pre_get_posts', array( $this, 'lm_filter_post_list' ) );

		// Background worker. It is dispatched as a non-blocking request that may
		// arrive without the auth cookie, so it is also registered for nopriv and
		// authorised by a one-off token stored in the scan state.
		add_action( 'wp_ajax_coywolf_seo_lm_process', array( $this, 'ajax_process' ) );
		add_action( 'wp_ajax_nopriv_coywolf_seo_lm_process', array( $this, 'ajax_process' ) );

		// Event-driven indexing — keeps the inventory current after the initial
		// analysis. lm_index_post() no-ops until then.
		add_action( 'wp_after_insert_post', array( $this, 'lm_index_post' ), 20 );
		add_action( 'untrashed_post', array( $this, 'lm_index_post' ) );
		add_action( 'wp_trash_post', array( $this, 'lm_on_remove' ) );
		add_action( 'before_delete_post', array( $this, 'lm_on_remove' ) );

		// Background response re-check drain (WP-Cron single events).
		add_action( self::RECHECK_HOOK, array( $this, 'lm_drain_recheck' ) );
	}

	/* ---------------------------------------------------------------------
	 * Admin screen
	 * ------------------------------------------------------------------- */

	/**
	 * Load CSS/JS only on the Link Manager screens (All Links + hidden Edit Link).
	 *
	 * Gated on the current admin page slug rather than a stored hook suffix,
	 * because Coywolf SEO registers the All Links menu item.
	 *
	 * @param string $hook Current admin page hook (unused; kept for the hook signature).
	 */
	public function enqueue_assets( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection, no state change.

		$is_all  = ( self::SLUG === $page );
		$is_edit = ( self::EDIT_SLUG === $page );
		if ( ! $is_all && ! $is_edit ) {
			return;
		}

		$page_kind = $is_edit ? 'edit' : 'all';

		wp_enqueue_style(
			'coywolf-seo-link-manager',
			COYWOLF_SEO_URL . 'css/link-manager.css',
			array( 'dashicons' ),
			Coywolf_SEO::VERSION
		);

		wp_enqueue_script(
			'coywolf-seo-link-manager',
			COYWOLF_SEO_URL . 'js/link-manager.js',
			array(),
			Coywolf_SEO::VERSION,
			true
		);

		wp_localize_script(
			'coywolf-seo-link-manager',
			'coywolf_seo_lm',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'coywolf_seo_lm' ),
				'pollMs'   => 2000,
				'perPage'  => 20,
				'page'     => $page_kind,
				'analyzed' => $this->lm_is_analyzed(),
				'editBase' => admin_url( 'admin.php?page=' . self::EDIT_SLUG ),
				'i18n'     => array(
					'starting'              => __( 'Starting analysis…', 'coywolf-seo' ),
					'analyzeBtn'            => __( 'Analyze all links', 'coywolf-seo' ),
					'reanalyzeBtn'          => __( 'Re-analyze all links', 'coywolf-seo' ),
					'reanalyzing'           => __( 'Re-analyzing…', 'coywolf-seo' ),
					/* translators: %s: number of selected links. */
					'confirmRemoveBulk'     => __( 'Remove %s selected link(s) from every post and page? The link text is kept.', 'coywolf-seo' ),
					/* translators: %s: number of selected links. */
					'confirmReplaceBulk'    => __( 'Replace %s selected redirected link(s) with their final destination URLs, on every post and page?', 'coywolf-seo' ),
					'replaceNeedsRedirect'  => __( 'Replace only applies to links that redirect. Deselect the links that are not redirects and try again.', 'coywolf-seo' ),
					'internal'              => __( 'Internal', 'coywolf-seo' ),
					'external'              => __( 'External', 'coywolf-seo' ),
					'noLinks'               => __( 'No links found yet. Click “Analyze all links” to build the inventory.', 'coywolf-seo' ),
					'noResponse'            => __( 'No response', 'coywolf-seo' ),
					'removeEverywhere'      => __( 'Remove this link from every post and page it appears on? The link text is kept.', 'coywolf-seo' ),
					/* translators: %s: final destination URL. */
					'replaceEverywhere'     => __( 'Replace this link with its redirect destination (%s) on every post and page?', 'coywolf-seo' ),
					/* translators: %1$s: number of links; %2$s: number of posts/pages processed. */
					'analyzedSummary'       => __( 'Indexed %1$s links across %2$s posts and pages.', 'coywolf-seo' ),
					/* translators: %s: number of posts being scanned. */
					'initiating'            => __( 'Initiating the scan of %s posts — this can take up to a minute before the first results appear.', 'coywolf-seo' ),
					/* translators: %1$s: current item number; %2$s: total posts and pages. */
					'scanning'              => __( 'Collecting and analyzing links from %1$s of %2$s posts and pages', 'coywolf-seo' ),
					'cancelling'            => __( 'Cancelling…', 'coywolf-seo' ),
					'cancelled'             => __( 'Scan cancelled.', 'coywolf-seo' ),
					'noBroken'              => __( 'No broken links or redirects found.', 'coywolf-seo' ),
					'error'                 => __( 'Something went wrong. Please try again.', 'coywolf-seo' ),
					'edit'                  => __( 'Edit', 'coywolf-seo' ),
					'view'                  => __( 'View', 'coywolf-seo' ),
					'connErr'               => __( 'No response', 'coywolf-seo' ),
					/* translators: %1$s: posts scanned; %2$s: links scanned; %3$s: broken count; %4$s: redirected count. */
					'summary'               => __( 'Scanned %1$s posts and %2$s links. Found %3$s broken and %4$s redirected.', 'coywolf-seo' ),
					'redirectTo'            => __( 'Redirects to:', 'coywolf-seo' ),
					'ignore'                => __( 'Ignore this domain', 'coywolf-seo' ),
					'ignoreUrl'             => __( 'Ignore this URL', 'coywolf-seo' ),
					'ignoreDomainLink'      => __( 'Ignore domain', 'coywolf-seo' ),
					'ignoreUrlLink'         => __( 'Ignore URL', 'coywolf-seo' ),
					'wildcardIgnoreLink'    => __( 'Wildcard ignore', 'coywolf-seo' ),
					'wildcardEmpty'         => __( 'Enter a pattern.', 'coywolf-seo' ),
					'wildcardSaving'        => __( 'Saving…', 'coywolf-seo' ),
					'remove'                => __( 'Remove', 'coywolf-seo' ),
					'noneShown'             => __( 'No results match your filter.', 'coywolf-seo' ),
					'noSelection'           => __( 'Select one or more rows first.', 'coywolf-seo' ),
					'pickAction'            => __( 'Choose a bulk action first.', 'coywolf-seo' ),
					/* translators: %s: number of domains. */
					'confirmDom'            => __( 'Ignore %s domain(s) from future scans? Matching results will be removed from this list.', 'coywolf-seo' ),
					/* translators: %s: number of URLs. */
					'confirmUrl'            => __( 'Ignore %s URL(s) from future scans? Matching results will be removed from this list.', 'coywolf-seo' ),
					/* translators: %1$s: links shown; %2$s: total broken links. */
					'filtered'              => __( 'Showing %1$s of %2$s broken links.', 'coywolf-seo' ),
					'allCodes'              => __( 'All response codes', 'coywolf-seo' ),
					'blocked'               => __( 'Blocked', 'coywolf-seo' ),
					'editLink'              => __( 'Edit', 'coywolf-seo' ),
					'modalTitle'            => __( 'Edit link URL', 'coywolf-seo' ),
					'modalHelp'             => __( 'Update the link in the post. This changes the post content immediately.', 'coywolf-seo' ),
					'save'                  => __( 'Save', 'coywolf-seo' ),
					'cancel'                => __( 'Cancel', 'coywolf-seo' ),
					'saving'                => __( 'Saving…', 'coywolf-seo' ),
					'invalidUrl'            => __( 'Please enter a valid http or https URL.', 'coywolf-seo' ),
					'sameUrl'               => __( 'The URL is unchanged.', 'coywolf-seo' ),
					'notFound'              => __( 'That URL was no longer found in the post — it may have already been changed.', 'coywolf-seo' ),
					/* translators: %s: number of occurrences updated. */
					'updated'               => __( 'Link updated (%s occurrence(s)).', 'coywolf-seo' ),
					'removeLink'            => __( 'Remove', 'coywolf-seo' ),
					'confirmTitle'          => __( 'Remove link', 'coywolf-seo' ),
					'confirmMsg'            => __( 'Are you sure you want to remove the link?', 'coywolf-seo' ),
					'confirmHelp'           => __( 'The link will be removed from the post but its text will be kept.', 'coywolf-seo' ),
					'removing'              => __( 'Removing…', 'coywolf-seo' ),
					'removeBulk'            => __( 'Remove links', 'coywolf-seo' ),
					/* translators: %s: number of selected links. */
					'confirmBulkMsg'        => __( 'Are you sure you want to remove %s selected link(s)?', 'coywolf-seo' ),
					/* translators: %1$s: current page number; %2$s: total pages. */
					'pageOf'                => __( '%1$s of %2$s', 'coywolf-seo' ),
					'currentPage'           => __( 'Current Page', 'coywolf-seo' ),
					'firstPage'             => __( 'First page', 'coywolf-seo' ),
					'prevPage'              => __( 'Previous page', 'coywolf-seo' ),
					'nextPage'              => __( 'Next page', 'coywolf-seo' ),
					'lastPage'              => __( 'Last page', 'coywolf-seo' ),
					/* translators: %s: number of items. */
					'nItems'                => __( '%s items', 'coywolf-seo' ),
					'noIgnores'             => __( 'No ignored domains or URLs yet.', 'coywolf-seo' ),
					/* translators: %s: number of ignore rules. */
					'confirmRemoveIgnores'  => __( 'Remove %s ignore rule(s)?', 'coywolf-seo' ),
					'typeDomain'            => __( 'Domain', 'coywolf-seo' ),
					'typeUrl'               => __( 'URL', 'coywolf-seo' ),
					'typeWildcard'          => __( 'Wildcard', 'coywolf-seo' ),
					'viewAll'               => __( 'All', 'coywolf-seo' ),
					'viewIgnored'           => __( 'Ignored', 'coywolf-seo' ),
					'unignore'              => __( 'Unignore', 'coywolf-seo' ),
					'unignoreBulk'          => __( 'Unignore', 'coywolf-seo' ),
					/* translators: %s: number of selected links. */
					'confirmUnignoreBulk'   => __( 'Stop ignoring %s selected link(s)? Any domain or wildcard rule that also matches other links is removed, so those links return to the list too.', 'coywolf-seo' ),
					'confirmUnignoreOne'    => __( 'Stop ignoring this link? If it was ignored by a domain or wildcard rule, that rule is removed, so other links it matches return to the list too.', 'coywolf-seo' ),
					'noIgnoredLinks'        => __( 'No ignored links yet. Use “Add ignore rule” above, or a link’s Ignore actions, to ignore links.', 'coywolf-seo' ),
					'replaceLink'           => __( 'Replace', 'coywolf-seo' ),
					'replaceTitle'          => __( 'Replace link', 'coywolf-seo' ),
					'replaceMsg'            => __( 'Replace this link with its redirect destination?', 'coywolf-seo' ),
					'replaceHelp'           => __( 'The link in the post will be updated to point directly at the final URL. The row will be removed from this list.', 'coywolf-seo' ),
					'replaceFrom'           => __( 'From:', 'coywolf-seo' ),
					'replaceTo'             => __( 'To:', 'coywolf-seo' ),
					'replacing'             => __( 'Replacing…', 'coywolf-seo' ),
					'replaceBulk'           => __( 'Replace links', 'coywolf-seo' ),
					/* translators: %s: number of redirected links. */
					'confirmReplaceBulkMsg' => __( 'Replace %s redirected link(s) with their final destination URLs?', 'coywolf-seo' ),
					'noRedirects'           => __( 'None of the selected rows have a redirect destination to replace.', 'coywolf-seo' ),
				),
			)
		);
	}

	/**
	 * Render the "All Links" subpage — the persistent link inventory plus the
	 * one-time "Analyze all links" control. Rows are drawn client-side from the
	 * inventory endpoint; the progress bar is reused for the analysis run.
	 */
	public function render_page() {
		if ( ! current_user_can( Coywolf_SEO_Admin::CAPABILITY ) ) {
			return;
		}
		?>
		<div class="wrap coywolf-seo-lm">
			<div class="coywolf-seo-lm-heading-row">
				<h1 class="wp-heading-inline"><?php echo esc_html__( 'All Links', 'coywolf-seo' ); ?></h1>
				<button type="button" class="button button-primary coywolf-seo-lm-title-action" id="coywolf-seo-lm-analyze" style="display:none;">
					<?php echo esc_html__( 'Analyze all links', 'coywolf-seo' ); ?>
				</button>
				<button type="button" class="button coywolf-seo-lm-title-action" id="coywolf-seo-lm-cancel" style="display:none;">
					<?php echo esc_html__( 'Cancel', 'coywolf-seo' ); ?>
				</button>
				<div class="coywolf-seo-lm-ignore-add">
					<label for="coywolf-seo-lm-ignore-type" class="screen-reader-text">
						<?php echo esc_html__( 'Rule type', 'coywolf-seo' ); ?>
					</label>
					<select id="coywolf-seo-lm-ignore-type">
						<option value="domain"><?php echo esc_html__( 'Domain', 'coywolf-seo' ); ?></option>
						<option value="url"><?php echo esc_html__( 'Exact URL', 'coywolf-seo' ); ?></option>
						<option value="wildcard"><?php echo esc_html__( 'Wildcard', 'coywolf-seo' ); ?></option>
					</select>
					<label for="coywolf-seo-lm-ignore-value" class="screen-reader-text">
						<?php echo esc_html__( 'Rule value', 'coywolf-seo' ); ?>
					</label>
					<input type="text" id="coywolf-seo-lm-ignore-value" class="regular-text"
						placeholder="<?php echo esc_attr__( 'example.com, https://example.com/page, or https://example.com/visit/*', 'coywolf-seo' ); ?>" />
					<button type="button" class="button" id="coywolf-seo-lm-ignore-add-btn">
						<?php echo esc_html__( 'Add ignore rule', 'coywolf-seo' ); ?>
					</button>
				</div>
			</div>

			<hr class="wp-header-end" />

			<div id="coywolf-seo-lm-progress" class="coywolf-seo-lm-progress" style="display:none;">
				<div class="coywolf-seo-lm-progress-head">
					<span class="spinner is-active"></span>
					<span id="coywolf-seo-lm-status-text"></span>
				</div>
				<div class="coywolf-seo-lm-bar-track">
					<div class="coywolf-seo-lm-bar-fill" id="coywolf-seo-lm-bar"></div>
				</div>
				<div class="coywolf-seo-lm-percent" id="coywolf-seo-lm-percent">0%</div>
				<p class="coywolf-seo-lm-bg-note"><?php echo esc_html__( 'This runs in the background — you can navigate away and come back later. Large sites with hundreds or thousands of posts and pages can take a long time to analyze. After this initial analysis, the links update automatically whenever posts and pages are created, updated, or deleted.', 'coywolf-seo' ); ?></p>
			</div>

			<div class="coywolf-seo-lm-results-header" id="coywolf-seo-lm-results-header" style="display:none;">
				<p id="coywolf-seo-lm-summary" class="coywolf-seo-lm-summary" style="display:none;"></p>
				<p class="search-box coywolf-seo-lm-search-box" id="coywolf-seo-lm-search-box" style="display:none;">
					<label class="screen-reader-text" for="coywolf-seo-lm-search">
						<?php echo esc_html__( 'Search results:', 'coywolf-seo' ); ?>
					</label>
					<input type="search" id="coywolf-seo-lm-search" name="s" value="" />
					<button type="button" class="button" id="coywolf-seo-lm-search-btn">
						<?php echo esc_html__( 'Search results', 'coywolf-seo' ); ?>
					</button>
				</p>
			</div>

			<ul class="subsubsub coywolf-seo-lm-views" id="coywolf-seo-lm-views" style="display:none;">
				<li class="all">
					<a href="#" data-view="all" class="current" aria-current="page"><?php echo esc_html__( 'All', 'coywolf-seo' ); ?>
						<span class="count">(<span data-role="count-all">0</span>)</span></a> <span class="coywolf-seo-lm-view-sep" aria-hidden="true">|</span>
				</li>
				<li class="ignored">
					<a href="#" data-view="ignored"><?php echo esc_html__( 'Ignored', 'coywolf-seo' ); ?>
						<span class="count">(<span data-role="count-ignored">0</span>)</span></a>
				</li>
			</ul>

			<div id="coywolf-seo-lm-toolbar" class="tablenav top coywolf-seo-lm-toolbar" style="display:none;">
				<div class="alignleft actions bulkactions">
					<label for="coywolf-seo-lm-bulk-action" class="screen-reader-text">
						<?php echo esc_html__( 'Select bulk action', 'coywolf-seo' ); ?>
					</label>
					<select id="coywolf-seo-lm-bulk-action">
						<option value="-1"><?php echo esc_html__( 'Bulk actions', 'coywolf-seo' ); ?></option>
						<option value="remove-links"><?php echo esc_html__( 'Remove links', 'coywolf-seo' ); ?></option>
						<option value="replace-links"><?php echo esc_html__( 'Replace links', 'coywolf-seo' ); ?></option>
					</select>
					<button type="button" class="button action" id="coywolf-seo-lm-bulk-apply">
						<?php echo esc_html__( 'Apply', 'coywolf-seo' ); ?>
					</button>
				</div>
				<div class="alignleft actions">
					<label for="coywolf-seo-lm-code-filter" class="screen-reader-text">
						<?php echo esc_html__( 'Filter by response code', 'coywolf-seo' ); ?>
					</label>
					<select id="coywolf-seo-lm-code-filter">
						<option value=""><?php echo esc_html__( 'All response codes', 'coywolf-seo' ); ?></option>
					</select>
				</div>
				<div class="alignleft actions">
					<label for="coywolf-seo-lm-type-filter" class="screen-reader-text">
						<?php echo esc_html__( 'Filter by type', 'coywolf-seo' ); ?>
					</label>
					<select id="coywolf-seo-lm-type-filter">
						<option value=""><?php echo esc_html__( 'All Types', 'coywolf-seo' ); ?></option>
						<option value="internal"><?php echo esc_html__( 'Internal', 'coywolf-seo' ); ?></option>
						<option value="external"><?php echo esc_html__( 'External', 'coywolf-seo' ); ?></option>
					</select>
				</div>
				<div class="alignright coywolf-seo-lm-toolbar-right">
					<?php $this->render_pagination_nav( 'coywolf-seo-lm-pagination-top' ); ?>
				</div>
				<br class="clear" />
			</div>

			<table class="wp-list-table widefat striped coywolf-seo-lm-table" id="coywolf-seo-lm-results" style="display:none;">
				<thead>
					<tr>
						<td class="manage-column column-cb check-column">
							<label class="screen-reader-text" for="coywolf-seo-lm-cb-all"><?php echo esc_html__( 'Select all', 'coywolf-seo' ); ?></label>
							<input type="checkbox" id="coywolf-seo-lm-cb-all" />
						</td>
						<th class="manage-column column-url"><?php echo esc_html__( 'URL', 'coywolf-seo' ); ?></th>
						<th class="manage-column column-code sortable desc" id="coywolf-seo-lm-th-code">
							<a href="#"><span><?php echo esc_html__( 'Response', 'coywolf-seo' ); ?></span><span class="sorting-indicator"></span></a>
						</th>
						<th class="manage-column column-type sortable desc" id="coywolf-seo-lm-th-type">
							<a href="#"><span><?php echo esc_html__( 'Type', 'coywolf-seo' ); ?></span><span class="sorting-indicator"></span></a>
						</th>
						<th class="manage-column column-posts"><?php echo esc_html__( 'Posts', 'coywolf-seo' ); ?></th>
						<th class="manage-column column-pages"><?php echo esc_html__( 'Pages', 'coywolf-seo' ); ?></th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>

			<p id="coywolf-seo-lm-empty" class="coywolf-seo-lm-empty" style="display:none;"></p>

			<div id="coywolf-seo-lm-toolbar-bottom" class="tablenav bottom coywolf-seo-lm-toolbar" style="display:none;">
				<div class="alignright coywolf-seo-lm-toolbar-right">
					<?php $this->render_pagination_nav( 'coywolf-seo-lm-pagination-bottom' ); ?>
				</div>
				<br class="clear" />
			</div>
		</div>

		<div id="coywolf-seo-lm-wildcard" class="coywolf-seo-lm-modal" style="display:none;" role="dialog"
			aria-modal="true" aria-labelledby="coywolf-seo-lm-wildcard-title">
			<div class="coywolf-seo-lm-modal-backdrop" data-close="1"></div>
			<div class="coywolf-seo-lm-modal-box" role="document">
				<h2 id="coywolf-seo-lm-wildcard-title">
					<?php echo esc_html__( 'Add wildcard ignore rule', 'coywolf-seo' ); ?>
				</h2>
				<p class="description">
					<?php echo esc_html__( 'Edit the URL into a wildcard pattern. Use', 'coywolf-seo' ); ?>
					<code>*</code>
					<?php echo esc_html__( 'to match any run of characters (including slashes and the empty string). The match is anchored to the whole URL and case-insensitive.', 'coywolf-seo' ); ?>
				</p>
				<p class="description coywolf-seo-lm-wildcard-examples">
					<strong><?php echo esc_html__( 'Examples:', 'coywolf-seo' ); ?></strong><br />
					<code>https://example.com/visit/*</code> — <?php echo esc_html__( 'every URL under /visit/', 'coywolf-seo' ); ?><br />
					<code>https://*.example.com/api</code> — <?php echo esc_html__( '/api on any subdomain', 'coywolf-seo' ); ?><br />
					<code>*?utm=*</code> — <?php echo esc_html__( 'every URL with a utm= parameter', 'coywolf-seo' ); ?><br />
					<code>*/track/*</code> — <?php echo esc_html__( 'every /track/ path on any host', 'coywolf-seo' ); ?>
				</p>
				<label for="coywolf-seo-lm-wildcard-input" class="screen-reader-text">
					<?php echo esc_html__( 'Wildcard pattern', 'coywolf-seo' ); ?>
				</label>
				<input type="text" id="coywolf-seo-lm-wildcard-input" class="large-text code"
					autocomplete="off" spellcheck="false" />
				<p class="coywolf-seo-lm-modal-error" id="coywolf-seo-lm-wildcard-error" style="display:none;"></p>
				<div class="coywolf-seo-lm-modal-actions">
					<button type="button" class="button" id="coywolf-seo-lm-wildcard-cancel">
						<?php echo esc_html__( 'Cancel', 'coywolf-seo' ); ?>
					</button>
					<button type="button" class="button button-primary" id="coywolf-seo-lm-wildcard-save">
						<?php echo esc_html__( 'Save', 'coywolf-seo' ); ?>
					</button>
				</div>
			</div>
		</div>

		<div id="coywolf-seo-lm-confirm" class="coywolf-seo-lm-modal" style="display:none;" role="dialog"
			aria-modal="true" aria-labelledby="coywolf-seo-lm-confirm-title">
			<div class="coywolf-seo-lm-modal-backdrop" data-close="1"></div>
			<div class="coywolf-seo-lm-modal-box" role="document">
				<h2 id="coywolf-seo-lm-confirm-title"><?php echo esc_html__( 'Remove link', 'coywolf-seo' ); ?></h2>
				<p id="coywolf-seo-lm-confirm-msg"><?php echo esc_html__( 'Are you sure you want to remove the link?', 'coywolf-seo' ); ?></p>
				<div id="coywolf-seo-lm-confirm-detail" class="coywolf-seo-lm-confirm-detail" style="display:none;"></div>
				<p class="description" id="coywolf-seo-lm-confirm-help"></p>
				<p class="coywolf-seo-lm-modal-error" id="coywolf-seo-lm-confirm-error" style="display:none;"></p>
				<div class="coywolf-seo-lm-modal-actions">
					<button type="button" class="button" id="coywolf-seo-lm-confirm-cancel">
						<?php echo esc_html__( 'Cancel', 'coywolf-seo' ); ?>
					</button>
					<button type="button" class="button" id="coywolf-seo-lm-confirm-remove">
						<?php echo esc_html__( 'Remove', 'coywolf-seo' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Output the markup for a WP-core-styled pagination nav. JS fills in the
	 * counts, page number, and button-disabled states; the structure mirrors
	 * the markup WP_List_Table renders so core admin styles apply directly.
	 *
	 * @param string $id Container id (used to scope the inputs and to allow
	 *                   top / bottom pairs on the same screen).
	 */
	private function render_pagination_nav( $id ) {
		?>
		<div class="tablenav-pages coywolf-seo-lm-pagination" id="<?php echo esc_attr( $id ); ?>" style="display:none;">
			<span class="displaying-num" data-role="total"></span>
			<span class="pagination-links">
				<button type="button" class="first-page button" data-page="first" disabled>
					<span class="screen-reader-text"><?php echo esc_html__( 'First page', 'coywolf-seo' ); ?></span>
					<span aria-hidden="true">&laquo;</span>
				</button>
				<button type="button" class="prev-page button" data-page="prev" disabled>
					<span class="screen-reader-text"><?php echo esc_html__( 'Previous page', 'coywolf-seo' ); ?></span>
					<span aria-hidden="true">&lsaquo;</span>
				</button>
				<span class="paging-input">
					<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>-current">
						<?php echo esc_html__( 'Current Page', 'coywolf-seo' ); ?>
					</label>
					<input class="current-page" id="<?php echo esc_attr( $id ); ?>-current" type="text"
						inputmode="numeric" value="1" size="2" data-role="current" />
					<span class="tablenav-paging-text">
						<?php echo esc_html__( 'of', 'coywolf-seo' ); ?>
						<span class="total-pages" data-role="total-pages">1</span>
					</span>
				</span>
				<button type="button" class="next-page button" data-page="next" disabled>
					<span class="screen-reader-text"><?php echo esc_html__( 'Next page', 'coywolf-seo' ); ?></span>
					<span aria-hidden="true">&rsaquo;</span>
				</button>
				<button type="button" class="last-page button" data-page="last" disabled>
					<span class="screen-reader-text"><?php echo esc_html__( 'Last page', 'coywolf-seo' ); ?></span>
					<span aria-hidden="true">&raquo;</span>
				</button>
			</span>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * AJAX: operator endpoints
	 * ------------------------------------------------------------------- */

	/**
	 * Verify nonce + capability for operator requests.
	 */
	private function authorise_operator() {
		check_ajax_referer( 'coywolf_seo_lm', 'nonce' );
		if ( ! current_user_can( Coywolf_SEO_Admin::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'coywolf-seo' ) ), 403 );
		}
	}

	/**
	 * Start a new scan from the admin UI.
	 */
	public function ajax_start() {
		$this->authorise_operator();
		$state = $this->start_scan();
		wp_send_json_success( $this->public_state( $state ) );
	}

	/**
	 * Build the post queue, fire the first worker, and return the resulting
	 * scan state.
	 *
	 * @return array Internal scan state (caller decides how to expose it).
	 */
	private function start_scan() {
		$post_ids = get_posts(
			array(
				'post_type'      => $this->get_post_types_to_scan(),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		delete_option( self::CANCEL_FLAG );
		$this->delete_url_cache();

		// Fresh rebuild: clear the inventory and pause event indexing until the
		// analysis completes (ANALYZED is re-set in ajax_process()).
		update_option( self::ANALYZED_OPTION, '', false );
		$this->lm_truncate_tables();

		$state                = $this->default_state();
		$state['status']      = 'running';
		$state['queue']       = array_values( array_map( 'intval', $post_ids ) );
		$state['total_posts'] = count( $post_ids );
		$state['token']       = wp_generate_password( 32, false );
		$state['started']     = time();
		$this->save_state( $state );

		if ( empty( $state['queue'] ) ) {
			$state['status'] = 'complete';
			$this->save_state( $state );
			// No posts/pages — the (empty) inventory is still "analyzed".
			update_option( self::ANALYZED_OPTION, '1', false );
			return $state;
		}

		$this->dispatch_worker( $state['token'] );
		return $state;
	}

	/**
	 * Return current scan status (polled by the browser).
	 *
	 * Also self-heals: if a scan is "running" but the worker chain appears to
	 * have stalled (e.g. a dropped loopback request), it re-dispatches a worker.
	 */
	public function ajax_status() {
		$this->authorise_operator();

		$state = $this->get_state();

		if ( 'running' === $state['status'] && ! empty( $state['queue'] ) ) {
			$age = time() - (int) $state['updated'];
			if ( $age > self::STALE_AFTER ) {
				$this->dispatch_worker( $state['token'] );
			}
		}

		// The browser sends its last-seen results version; public_state()
		// returns resultsUnchanged instead of the full payload when it matches.
		$since = isset( $_POST['since'] ) ? (int) $_POST['since'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce + capability verified in authorise_operator() (check_ajax_referer) before this AJAX handler reads input.
		wp_send_json_success( $this->public_state( $state, $since ) );
	}

	/**
	 * Request cancellation. The running worker checks this flag after each batch.
	 */
	public function ajax_cancel() {
		$this->authorise_operator();
		update_option( self::CANCEL_FLAG, '1', false );
		wp_send_json_success();
	}

	/**
	 * Add one or more ignore rules, then prune any matching rows from the
	 * stored results so they disappear immediately and never reappear.
	 */
	public function ajax_ignore_add() {
		$this->authorise_operator();

		$raw     = isset( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- Raw JSON payload validated per-field after decode; nonce + capability verified in authorise_operator().
		$decoded = json_decode( (string) $raw, true );
		if ( ! is_array( $decoded ) ) {
			$decoded = array();
		}

		$ignores = $this->get_ignores();
		foreach ( $decoded as $rule ) {
			$type  = isset( $rule['type'] ) ? sanitize_key( $rule['type'] ) : '';
			$value = isset( $rule['value'] ) ? sanitize_text_field( $rule['value'] ) : '';
			$norm  = $this->normalize_rule( $type, $value );
			if ( null === $norm ) {
				continue;
			}
			$key             = $norm['type'] . '|' . $norm['value'];
			$ignores[ $key ] = $norm;
		}
		$this->save_ignores( $ignores );

		// Newly ignored links stay in the inventory (for the "Ignored" view) but
		// must not be response-checked, so drop any pending checks for them.
		$this->lm_dequeue_ignored();

		wp_send_json_success( $this->public_state( $this->get_state() ) );
	}

	/**
	 * AJAX: stop ignoring a single link. Removes every ignore rule that matches
	 * the link's URL, then re-queues the now-visible links for a response check.
	 */
	public function ajax_lm_unignore() {
		$this->authorise_operator();
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce + capability verified in authorise_operator().
		if ( ! $link_id ) {
			wp_send_json_error( array( 'message' => __( 'Link not found.', 'coywolf-seo' ) ), 404 );
		}
		$this->lm_unignore_links( array( $link_id ) );
		wp_send_json_success();
	}

	/**
	 * AJAX: stop ignoring several links at once. Accepts a JSON array of link ids.
	 */
	public function ajax_lm_unignore_bulk() {
		$this->authorise_operator();
		$raw = isset( $_POST['link_ids'] ) ? wp_unslash( $_POST['link_ids'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- JSON of ints validated per element below; nonce + capability verified in authorise_operator().
		$ids = json_decode( (string) $raw, true );
		if ( ! is_array( $ids ) ) {
			$ids = array();
		}
		$ids = array_filter( array_map( 'absint', $ids ) );
		$this->lm_unignore_links( $ids );
		wp_send_json_success( array( 'unignored' => count( $ids ) ) );
	}

	/**
	 * Replace the href of matching <a> tags in HTML content.
	 *
	 * Matches by the entity-decoded href value so URLs containing "&" (stored as
	 * "&amp;") are found correctly, and rewrites only the attribute value. Other
	 * markup is preserved byte-for-byte.
	 *
	 * @param string $content  Post content (HTML, possibly with block comments).
	 * @param string $old_url  URL to find (decoded form).
	 * @param string $new_url  Replacement URL.
	 * @param int    $replaced Out-param: number of hrefs replaced.
	 * @return string Updated content.
	 */
	private function replace_link_url( $content, $old_url, $new_url, &$replaced, $base = '' ) {
		$replaced = 0;
		$target   = trim( $old_url );

		// Match: <a ... href = "..."| '...' , capturing the prefix, quote, value.
		$pattern = '/(<a\b[^>]*?\bhref\s*=\s*)(["\'])(.*?)\2/i';

		$content = preg_replace_callback(
			$pattern,
			function ( $m ) use ( $target, $new_url, $base, &$replaced ) {
				$decoded = trim( html_entity_decode( $m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				if ( ! $this->lm_href_matches( $decoded, $target, $base ) ) {
					return $m[0];
				}
				$replaced++;
				// esc_url() produces an attribute-safe, entity-encoded URL and
				// strips disallowed schemes.
				return $m[1] . $m[2] . esc_url( $new_url ) . $m[2];
			},
			$content
		);

		return $content;
	}

	/**
	 * Unwrap matching <a> elements, keeping their inner content.
	 *
	 * Matches a complete anchor element whose (decoded) href equals the target
	 * and replaces the element with its inner HTML. Anchors cannot legally nest,
	 * so a non-greedy match of the element body is safe. Non-matching anchors
	 * and all other markup are left intact.
	 *
	 * @param string $content Post content.
	 * @param string $old_url URL to find (decoded form).
	 * @param int    $removed Out-param: number of anchors unwrapped.
	 * @return string Updated content.
	 */
	private function remove_link_url( $content, $old_url, &$removed, $base = '' ) {
		$removed = 0;
		$target  = trim( $old_url );

		// Capture the full <a …>…</a> element: attributes, then inner content.
		$pattern = '/<a\b([^>]*)>(.*?)<\/a>/is';

		$content = preg_replace_callback(
			$pattern,
			function ( $m ) use ( $target, $base, &$removed ) {
				// Pull the href value out of the captured attribute string.
				if ( ! preg_match( '/\bhref\s*=\s*("|\')(.*?)\1/i', $m[1], $h ) ) {
					return $m[0];
				}
				$decoded = trim( html_entity_decode( $h[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				if ( ! $this->lm_href_matches( $decoded, $target, $base ) ) {
					return $m[0];
				}
				$removed++;
				return $m[2]; // Keep inner content only.
			},
			$content
		);

		return $content;
	}

	/* ---------------------------------------------------------------------
	 * Link Manager rewrite engine
	 *
	 * One regex anchor-walker drives both indexing (read) and editing (write),
	 * so the per-anchor "instance" ordinal means the same thing in both. All
	 * writes rebuild only the matched <a> element, leaving every other byte —
	 * including Gutenberg block comments and entity encoding — untouched.
	 * ------------------------------------------------------------------- */

	/**
	 * Walk every <a>…</a> element in document order.
	 *
	 * Anchors cannot legally nest, so the non-greedy body match is safe. The
	 * callback receives ( int $ordinal, string $attrs, string $inner, string
	 * $whole ) and returns a replacement for the whole element, or null to
	 * leave it unchanged. The ordinal counts every anchor, matched or not, so
	 * it stays in lockstep with the indexer.
	 *
	 * @param string   $content Post content.
	 * @param callable $fn      Per-anchor callback.
	 * @return string Rewritten content.
	 */
	private function lm_walk_anchors( $content, $fn ) {
		$ordinal = 0;
		return (string) preg_replace_callback(
			'/<a\b([^>]*)>(.*?)<\/a>/is',
			function ( $m ) use ( &$ordinal, $fn ) {
				$i = $ordinal;
				$ordinal++;
				$out = call_user_func( $fn, $i, $m[1], $m[2], $m[0] );
				return ( null === $out ) ? $m[0] : $out;
			},
			$content
		);
	}

	/**
	 * Decoded href value from a start-tag attribute string ('' if none).
	 *
	 * @param string $attrs Attribute string captured by {@see lm_walk_anchors}.
	 * @return string
	 */
	private function lm_attr_href( $attrs ) {
		if ( preg_match( '/\bhref\s*=\s*("|\')(.*?)\1/i', $attrs, $h ) ) {
			return trim( html_entity_decode( $h[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}
		return '';
	}

	/**
	 * Base URL used to resolve a post's relative hrefs — the same base the
	 * extractor uses, so resolved hrefs match the stored (absolute) link URLs.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function lm_post_base( $post_id ) {
		$base = (string) get_permalink( $post_id );
		return '' !== $base ? $base : (string) home_url( '/' );
	}

	/**
	 * Whether a content href refers to the target link. Hrefs are stored as
	 * absolute URLs, but content may hold relative / root-relative / protocol-
	 * relative forms, so resolve against the post base before comparing.
	 *
	 * @param string $href   Decoded href from the content.
	 * @param string $target Stored (absolute) link URL.
	 * @param string $base   Post base URL ('' compares literally only).
	 * @return bool
	 */
	private function lm_href_matches( $href, $target, $base ) {
		if ( $href === $target ) {
			return true;
		}
		if ( '' === $base ) {
			return false;
		}
		$abs = $this->resolve_url( $href, $base );
		return '' !== $abs && $abs === $target;
	}

	/**
	 * The rel tokens this plugin manages via the Edit Link page.
	 *
	 * @return string[]
	 */
	private function lm_managed_rel() {
		return array( 'nofollow', 'sponsored', 'ugc', 'noopener', 'noreferrer', 'me' );
	}

	/**
	 * Parse the rel attribute into a token list (decoded, order preserved).
	 *
	 * @param string $attrs Attribute string.
	 * @return string[]
	 */
	private function lm_parse_rel( $attrs ) {
		if ( preg_match( '/\brel\s*=\s*("|\')(.*?)\1/i', $attrs, $r ) ) {
			$tokens = preg_split( '/\s+/', trim( html_entity_decode( $r[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
			return array_values( array_filter( (array) $tokens ) );
		}
		return array();
	}

	/**
	 * Human anchor label for a link occurrence: collapsed text, or an image
	 * placeholder when the anchor wraps an <img> (so text edits can be
	 * disabled for it and the image preserved).
	 *
	 * @param string $inner Inner HTML of the anchor.
	 * @return string
	 */
	private function lm_anchor_text( $inner ) {
		if ( preg_match( '/<img\b[^>]*>/i', $inner ) ) {
			if ( preg_match( '/<img\b[^>]*\balt\s*=\s*("|\')(.*?)\1/i', $inner, $m ) ) {
				$alt = trim( html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				return '' !== $alt ? '[image] ' . $alt : '[image link]';
			}
			return '[image link]';
		}
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $inner ) ) );
		return '' !== $text ? $text : '(no anchor text)';
	}

	/**
	 * Extract every http(s) link from content with the metadata the inventory
	 * needs: absolute URL, anchor text, internal/external type, rel tokens,
	 * inline JS, and the document-order instance ordinal.
	 *
	 * Generalises the old scope-filtered extractor: it always returns both
	 * internal and external links and is built on {@see lm_walk_anchors} so the
	 * instance numbers match what the editor will rewrite later.
	 *
	 * @param string $content Post content.
	 * @param int    $post_id Post ID (for resolving relative URLs).
	 * @return array<int,array> List of link records.
	 */
	private function lm_extract_links( $content, $post_id = 0 ) {
		$links = array();
		if ( '' === trim( (string) $content ) ) {
			return $links;
		}

		$base = $post_id ? (string) get_permalink( $post_id ) : '';
		if ( '' === $base ) {
			$base = (string) home_url( '/' );
		}
		$home_host = $this->normalize_domain( (string) home_url() );

		$this->lm_walk_anchors(
			$content,
			function ( $i, $attrs, $inner, $whole ) use ( &$links, $base, $home_host ) {
				$href = $this->lm_attr_href( $attrs );
				if ( '' === $href ) {
					return null;
				}
				// Skip non-navigational and in-page schemes.
				if ( preg_match( '~^(mailto:|tel:|sms:|javascript:|data:|#)~i', $href ) ) {
					return null;
				}
				$abs = $this->resolve_url( $href, $base );
				if ( '' === $abs ) {
					return null;
				}
				$scheme = wp_parse_url( $abs, PHP_URL_SCHEME );
				if ( ! $scheme || ! in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ) {
					return null;
				}
				$link_host   = $this->normalize_domain( $abs );
				$is_internal = ( '' !== $home_host && $link_host === $home_host );

				$links[] = array(
					'url'      => $abs,
					'anchor'   => $this->lm_anchor_text( $inner ),
					'type'     => $is_internal ? 'internal' : 'external',
					'rel'      => implode( ' ', $this->lm_parse_rel( $attrs ) ),
					'instance' => $i,
				);
				return null; // Read-only walk.
			}
		);

		return $links;
	}

	/**
	 * Set (or insert) the href value in a start-tag attribute string.
	 *
	 * @param string $attrs   Attribute string.
	 * @param string $new_url Replacement URL (passed through esc_url()).
	 * @return string
	 */
	private function lm_attrs_set_href( $attrs, $new_url ) {
		$done = false;
		$out  = preg_replace_callback(
			'/(\bhref\s*=\s*)("|\')(.*?)\2/i',
			function ( $a ) use ( $new_url, &$done ) {
				$done = true;
				return $a[1] . $a[2] . esc_url( $new_url ) . $a[2];
			},
			$attrs,
			1
		);
		if ( ! $done ) {
			$out = ' href="' . esc_url( $new_url ) . '"' . $attrs;
		}
		return $out;
	}

	/**
	 * Apply the desired managed rel tokens to a start-tag attribute string.
	 *
	 * Unmanaged tokens already present are preserved; the six managed tokens
	 * are forced to exactly the desired set. An empty result drops the rel
	 * attribute entirely.
	 *
	 * @param string   $attrs   Attribute string.
	 * @param string[] $desired Managed tokens that should be present.
	 * @return string
	 */
	private function lm_attrs_set_rel( $attrs, $desired ) {
		$managed = $this->lm_managed_rel();
		$desired = array_values( array_intersect( $managed, array_map( 'strtolower', (array) $desired ) ) );

		$kept = array();
		foreach ( $this->lm_parse_rel( $attrs ) as $tok ) {
			if ( ! in_array( strtolower( $tok ), $managed, true ) ) {
				$kept[] = $tok;
			}
		}
		$final = array_values( array_unique( array_merge( $kept, $desired ) ) );

		// Strip any existing rel attribute first.
		$attrs = preg_replace( '/\s*\brel\s*=\s*("|\').*?\1/i', '', $attrs );
		if ( empty( $final ) ) {
			return $attrs;
		}

		$rel_attr = ' rel="' . esc_attr( implode( ' ', $final ) ) . '"';
		return $this->lm_attrs_inject_after_href( $attrs, $rel_attr );
	}

	/**
	 * Insert an already-rendered ` name="value"` attribute right after the
	 * href (stable position), or at the end if there is no href.
	 *
	 * @param string $attrs Attribute string.
	 * @param string $attr  Attribute to insert (leading space included).
	 * @return string
	 */
	private function lm_attrs_inject_after_href( $attrs, $attr ) {
		$done = false;
		$out  = preg_replace_callback(
			'/\bhref\s*=\s*("|\').*?\1/i',
			function ( $a ) use ( $attr, &$done ) {
				$done = true;
				return $a[0] . $attr;
			},
			$attrs,
			1
		);
		return $done ? $out : $attrs . $attr;
	}

	/**
	 * Apply an Edit Link save to one post's content in a single anchor walk.
	 *
	 * Every anchor whose href equals $old_url is treated as an occurrence of
	 * the link. Global edits (URL, rel) apply to all of them; the
	 * per-instance map keys removals and anchor-text overrides by ordinal.
	 * Because one walk assigns ordinals over the original content, removals do
	 * not shift the ordinals of later occurrences — no ordering dance needed.
	 *
	 * @param string $content Post content.
	 * @param string $old_url The link's current URL (occurrence selector).
	 * @param array  $spec    { new_url, rel[], instances[ord => [remove,anchor]] }.
	 * @return array{0:string,1:array{changed:int,removed:int}}
	 */
	private function lm_apply_link_edit( $content, $old_url, $spec, $base = '' ) {
		$old   = trim( (string) $old_url );
		$stats = array(
			'changed' => 0,
			'removed' => 0,
		);

		$content = $this->lm_walk_anchors(
			$content,
			function ( $i, $attrs, $inner, $whole ) use ( $old, $spec, $base, &$stats ) {
				if ( ! $this->lm_href_matches( $this->lm_attr_href( $attrs ), $old, $base ) ) {
					return null; // Not an occurrence of this link.
				}
				$inst = isset( $spec['instances'][ $i ] ) ? $spec['instances'][ $i ] : null;

				if ( $inst && ! empty( $inst['remove'] ) ) {
					$stats['removed']++;
					return $inner; // Unwrap, keeping inner content.
				}

				$new_attrs = $attrs;
				if ( isset( $spec['new_url'] ) && '' !== $spec['new_url'] && $spec['new_url'] !== $old ) {
					$new_attrs = $this->lm_attrs_set_href( $new_attrs, $spec['new_url'] );
				}
				if ( array_key_exists( 'rel', $spec ) ) {
					$new_attrs = $this->lm_attrs_set_rel( $new_attrs, $spec['rel'] );
				}

				$new_inner = $inner;
				if ( $inst && isset( $inst['anchor'] ) && null !== $inst['anchor'] && ! preg_match( '/<img\b[^>]*>/i', $inner ) ) {
					$new_inner = esc_html( $inst['anchor'] );
				}

				$stats['changed']++;
				return '<a' . $new_attrs . '>' . $new_inner . '</a>';
			}
		);

		return array( $content, $stats );
	}

	/**
	 * Process one batch of posts, then chain the next worker.
	 *
	 * Authorised by the per-scan token rather than the auth cookie, because the
	 * loopback request is fired non-blocking and may not carry the cookie.
	 */
	public function ajax_process() {
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nopriv loopback worker; authorized just below by the one-off per-scan token compared with hash_equals (the non-blocking loopback request carries no auth cookie/nonce).
		$state = $this->get_state();

		if ( empty( $state['token'] ) || ! hash_equals( (string) $state['token'], $token ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		if ( 'running' !== $state['status'] ) {
			wp_die();
		}

		// Keep working even if the request that triggered us goes away.
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- Best-effort time-limit lift for the long-running scan worker; silenced as some hosts disable it.
		}

		// Honour a cancellation requested before this batch began.
		if ( '1' === get_option( self::CANCEL_FLAG ) ) {
			$state['status'] = 'cancelled';
			$state['queue']  = array();
			$state['token']  = '';
			$this->save_state( $state );
			$this->delete_url_cache();
			delete_option( self::CANCEL_FLAG );
			wp_die();
		}

		// The URL check cache lives in its own option (kept off the polled
		// scan state). Load it for cross-batch dedupe, and persist it below
		// only if this batch added entries.
		$url_cache    = $this->get_url_cache();
		$cache_before = count( $url_cache );

		$profile = $this->get_speed_profile();
		$batch   = array_splice( $state['queue'], 0, $profile['batch'] );
		$this->process_batch( $batch, $state, $url_cache );
		$state['processed'] += count( $batch );

		// Cancellation may have arrived while this batch was running.
		if ( '1' === get_option( self::CANCEL_FLAG ) ) {
			$state['status'] = 'cancelled';
			$state['queue']  = array();
			$state['token']  = '';
			$this->save_state( $state );
			$this->delete_url_cache();
			delete_option( self::CANCEL_FLAG );
			wp_die();
		}

		// If a newer scan was started while this batch ran, it now owns the
		// stored state. Abort without saving so this stale run cannot clobber it.
		$current = $this->get_state();
		if ( ! hash_equals( (string) $current['token'], (string) $state['token'] ) ) {
			wp_die();
		}

		if ( ! empty( $state['queue'] ) ) {
			$this->save_state( $state );
			if ( count( $url_cache ) !== $cache_before ) {
				$this->save_url_cache( $url_cache );
			}
			$this->dispatch_worker( $state['token'] );
		} else {
			$state['status']       = 'complete';
			$state['current_post'] = '';
			$state['token']        = '';
			$this->save_state( $state );
			// Analysis finished — nothing reads the cache after this; mark the
			// inventory ready so event-driven indexing takes over.
			$this->delete_url_cache();
			update_option( self::ANALYZED_OPTION, '1', false );
		}

		wp_die();
	}

	/**
	 * Fire a non-blocking loopback request that runs the next worker batch.
	 *
	 * @param string $token The current scan's authorisation token.
	 */
	private function dispatch_worker( $token ) {
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Applying WordPress core's https_local_ssl_verify filter, not defining a plugin hook.
				'cookies'   => array(),
				'body'      => array(
					'action' => 'coywolf_seo_lm_process',
					'token'  => $token,
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Scanning
	 * ------------------------------------------------------------------- */

	/**
	 * Scan a batch of posts, checking their external links concurrently.
	 *
	 * Links are gathered across all posts in the batch, de-duplicated, and
	 * checked in parallel (see check_urls()). Results are then attributed back
	 * to every occurrence so the table still shows one row per (post, link).
	 *
	 * @param int[] $post_ids Post IDs in this batch.
	 * @param array $state    Scan state, passed by reference and mutated.
	 */
	private function process_batch( $post_ids, &$state, &$url_cache ) {
		$to_check = array(); // url => true (needs a network check this run).

		foreach ( $post_ids as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post ) {
				continue;
			}

			$state['current_post'] = $post->post_title;
			$ptype                 = (string) $post->post_type;

			// Per-post-type processed counter (drives the progress summary).
			if ( ! isset( $state['processed_by_type'][ $ptype ] ) ) {
				$state['processed_by_type'][ $ptype ] = 0;
			}
			++$state['processed_by_type'][ $ptype ];

			// Record this post's link occurrences (this upserts the link rows).
			// Ignored links are kept in the inventory but never response-checked,
			// so they are excluded from the check queue below.
			$records = $this->lm_records_for_post( $post );
			$this->lm_set_occurrences( (int) $post_id, $records );

			foreach ( $records as $r ) {
				if ( ! isset( $url_cache[ $r['url'] ] ) && ! $this->is_ignored( $r['url'] ) ) {
					$to_check[ $r['url'] ] = true;
				}
			}
		}

		// Check every not-yet-seen URL in this run in parallel, then persist the
		// response onto its link row.
		if ( ! empty( $to_check ) ) {
			$checked = $this->check_urls( array_keys( $to_check ) );
			foreach ( $checked as $url => $result ) {
				$url_cache[ $url ] = $result;
				$this->lm_write_response( sha1( $url ), $result );
				++$state['links_checked'];
			}
		}

		// Bump the version each batch so the progress poll keeps updating.
		$this->bump_version( $state );
	}

	/**
	 * Increment the results version token. Called wherever 'results' changes
	 * so the status poll can detect "nothing new" and skip the heavy payload.
	 *
	 * @param array $state Scan state, mutated in place.
	 */
	private function bump_version( &$state ) {
		$state['version'] = (int) ( isset( $state['version'] ) ? $state['version'] : 0 ) + 1;
	}

	/**
	 * Extract external (off-site) hyperlinks from post content.
	 *
	 * @param string $content Raw post content.
	 * @param int    $post_id Post the content belongs to (used as the base
	 *                        URL when resolving relative hrefs). 0 falls
	 *                        back to home_url().
	 * @return array[] List of [ 'url' => string, 'anchor' => string ].
	 */
	private function extract_external_links( $content, $post_id = 0 ) {
		$links = array();

		if ( '' === trim( (string) $content ) || ! class_exists( 'DOMDocument' ) ) {
			return $links;
		}

		// Base URL for resolving root-relative ("/about") and relative
		// ("../faq", "page.html") hrefs in the post content.
		$base = '';
		if ( $post_id ) {
			$base = (string) get_permalink( $post_id );
		}
		if ( '' === $base ) {
			$base = (string) home_url( '/' );
		}

		$scope     = $this->get_scope();
		$home_host = $this->normalize_domain( (string) home_url() );

		$prev = libxml_use_internal_errors( true );
		$dom  = new DOMDocument();
		$dom->loadHTML(
			'<?xml encoding="utf-8"?><div>' . $content . '</div>',
			LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		foreach ( $dom->getElementsByTagName( 'a' ) as $a ) {
			$href = trim( $a->getAttribute( 'href' ) );
			if ( '' === $href ) {
				continue;
			}

			// Skip non-navigational and in-page schemes.
			if ( preg_match( '~^(mailto:|tel:|sms:|javascript:|data:|#)~i', $href ) ) {
				continue;
			}

			$abs = $this->resolve_url( $href, $base );
			if ( '' === $abs ) {
				continue;
			}

			$scheme = wp_parse_url( $abs, PHP_URL_SCHEME );
			if ( ! $scheme || ! in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ) {
				continue;
			}

			// Apply the Settings → "Links to check" scope filter.
			$link_host   = $this->normalize_domain( $abs );
			$is_internal = ( '' !== $home_host && $link_host === $home_host );
			if ( self::SCOPE_EXTERNAL === $scope && $is_internal ) {
				continue;
			}
			if ( self::SCOPE_INTERNAL === $scope && ! $is_internal ) {
				continue;
			}

			$anchor = trim( preg_replace( '/\s+/', ' ', (string) $a->textContent ) );
			if ( '' === $anchor ) {
				$imgs = $a->getElementsByTagName( 'img' );
				if ( $imgs->length ) {
					$alt    = trim( $imgs->item( 0 )->getAttribute( 'alt' ) );
					$anchor = '' !== $alt ? '[image] ' . $alt : '[image link]';
				} else {
					$anchor = '(no anchor text)';
				}
			}

			$links[] = array(
				'url'    => $abs,
				'anchor' => $anchor,
			);
		}

		return $links;
	}

	/**
	 * Resolve a possibly-relative href against a base URL into an absolute
	 * http(s) URL. Returns '' if it cannot be resolved.
	 *
	 * Handles four cases:
	 *   - "http(s)://…" — already absolute.
	 *   - "//host/…"    — protocol-relative; gets the base's scheme.
	 *   - "/path"       — root-relative; gets the base's scheme+host.
	 *   - "path", "../path", "./path" — relative to the base's directory.
	 *
	 * Does not implement the full RFC 3986 algorithm, but covers every
	 * shape WordPress and content editors actually produce.
	 */
	private function resolve_url( $href, $base ) {
		$href = trim( (string) $href );
		if ( '' === $href ) {
			return '';
		}

		if ( preg_match( '~^https?://~i', $href ) ) {
			return $href;
		}
		if ( 0 === strpos( $href, '//' ) ) {
			return ( is_ssl() ? 'https:' : 'http:' ) . $href;
		}

		$bp = wp_parse_url( $base );
		if ( empty( $bp['scheme'] ) || empty( $bp['host'] ) ) {
			return '';
		}
		$origin = $bp['scheme'] . '://' . $bp['host'];
		if ( ! empty( $bp['port'] ) ) {
			$origin .= ':' . $bp['port'];
		}

		if ( 0 === strpos( $href, '/' ) ) {
			return $origin . $href;
		}

		// Relative to base path's directory.
		$path = isset( $bp['path'] ) ? $bp['path'] : '/';
		// Strip the filename (everything after the last "/").
		$dir = preg_replace( '~/[^/]*$~', '/', $path );
		if ( '' === $dir ) {
			$dir = '/';
		}

		$combined = $dir . $href;

		// Normalise "." / ".." segments. Query string and fragment are
		// preserved unchanged.
		$query_frag = '';
		$cut        = strcspn( $combined, '?#' );
		if ( $cut < strlen( $combined ) ) {
			$query_frag = substr( $combined, $cut );
			$combined   = substr( $combined, 0, $cut );
		}
		$trailing = ( '' !== $combined && '/' === substr( $combined, -1 ) ) ? '/' : '';
		$segments = explode( '/', $combined );
		$out      = array();
		foreach ( $segments as $seg ) {
			if ( '..' === $seg ) {
				array_pop( $out );
			} elseif ( '.' !== $seg && '' !== $seg ) {
				$out[] = $seg;
			}
		}
		$path = '/' . implode( '/', $out );
		// Avoid emitting "//" when the segment list was empty (e.g. "/").
		if ( '' !== $trailing && '/' !== substr( $path, -1 ) ) {
			$path .= '/';
		}
		return $origin . $path . $query_frag;
	}

	/**
	 * Check many URLs concurrently and return a map of url => result.
	 *
	 * Uses the WordPress-bundled Requests library's parallel transport
	 * (curl_multi under the hood) so several links are in flight at once. Each
	 * URL is checked HEAD-first; any that fail, return nothing, or are rejected
	 * (405 / 4xx+) are retried with GET in a second parallel pass, since some
	 * servers mishandle HEAD. Falls back to sequential checks if the parallel
	 * transport is unavailable.
	 *
	 * @param string[] $urls Unique URLs to check.
	 * @return array<string,array> Map of url => { broken, code, short, label }.
	 */
	private function check_urls( $urls ) {
		$out  = array();
		$safe = array();

		// SSRF guard on the initial URL: never request internal hosts.
		foreach ( $urls as $url ) {
			if ( 'unsafe' === $this->url_safety( $url ) ) {
				$out[ $url ] = array(
					'broken'        => true,
					'blocked'       => false,
					'code'          => 0,
					'short'         => __( 'Skipped', 'coywolf-seo' ),
					'label'         => __( 'Skipped — host resolves to a non-public address', 'coywolf-seo' ),
					'redirect'      => false,
					'redirect_code' => 0,
					'final_url'     => '',
				);
			} else {
				$safe[] = $url;
			}
		}

		if ( empty( $safe ) ) {
			return $out;
		}

		// No parallel transport available — check sequentially.
		if ( ! $this->requests_class() ) {
			foreach ( $safe as $url ) {
				$out[ $url ] = $this->check_url( $url );
			}
			return $out;
		}

		$head = $this->multi_request( $safe, 'HEAD' );

		// Decide which URLs need a GET retry.
		$need_get = array();
		foreach ( $safe as $url ) {
			$r = isset( $head[ $url ] ) ? $head[ $url ] : null;
			if ( null === $r || 0 === $r['code'] || 405 === $r['code'] || $r['code'] >= 400 ) {
				$need_get[] = $url;
			}
		}

		$get = empty( $need_get ) ? array() : $this->multi_request( $need_get, 'GET' );

		$blank = array(
			'code'          => 0,
			'error'         => null,
			'redirect_code' => 0,
			'final_url'     => '',
		);
		foreach ( $safe as $url ) {
			$h      = isset( $head[ $url ] ) ? $head[ $url ] : $blank;
			$g      = isset( $get[ $url ] ) ? $get[ $url ] : null;
			$source = $h; // Response whose status/redirect info we report.
			$code   = $h['code'];
			$error  = isset( $h['error'] ) ? $h['error'] : null;

			if ( null !== $g && $g['code'] > 0 ) {
				$code   = $g['code'];
				$error  = null;
				$source = $g;
			} elseif ( 0 === $code && null !== $g && ! empty( $g['error'] ) ) {
				$error = $g['error'];
			}

			$out[ $url ] = $this->format_result(
				$code,
				$error,
				isset( $source['redirect_code'] ) ? (int) $source['redirect_code'] : 0,
				isset( $source['final_url'] ) ? (string) $source['final_url'] : '',
				$url
			);
		}

		return $out;
	}

	/**
	 * Run a single parallel pass over a list of URLs.
	 *
	 * Requests are issued in chunks of CONCURRENCY so no more than that many
	 * connections are open at once. A before-redirect hook re-applies the SSRF
	 * guard to every redirect hop, aborting any that points at a non-public
	 * host (this replaces the redirect validation that wp_safe_remote_* gave us
	 * in the sequential path).
	 *
	 * @param string[] $urls   URLs to request.
	 * @param string   $method 'HEAD' or 'GET'.
	 * @return array<string,array> Map of url => { code:int, error:?string }.
	 */
	private function multi_request( $urls, $method ) {
		$out     = array();
		$class   = $this->requests_class();
		$is_head = ( 'HEAD' === $method );
		$type    = $is_head ? $class::HEAD : $class::GET;

		$profile     = $this->get_speed_profile();
		$concurrency = (int) apply_filters( 'coywolf_seo_lm_concurrency', $profile['concurrency'] );
		$concurrency = max( 1, min( self::CONCURRENCY_MAX, $concurrency ) );

		// Reorder URLs so consecutive entries are from different hosts. With
		// the default sequential ordering, many consecutive URLs often belong
		// to the same external site (e.g. 30 footer links to twitter.com) and
		// end up in the same concurrent window, hammering that one host. The
		// interleave spreads them out without lowering total throughput.
		$urls = $this->interleave_by_host( $urls );

		foreach ( array_chunk( $urls, $concurrency ) as $chunk ) {
			$options = array(
				'timeout'          => self::HTTP_TIMEOUT,
				'connect_timeout'  => self::HTTP_TIMEOUT,
				'redirects'        => 5,
				'follow_redirects' => true,
				'verify'           => true,
				'useragent'        => $this->user_agent(),
				'hooks'            => $this->redirect_guard_hooks(),
			);

			$requests = array();
			foreach ( $chunk as $url ) {
				$headers = $this->browser_headers();
				if ( ! $is_head ) {
					// Ask servers to send only the first 128 KB on the GET fallback.
					$headers['Range'] = 'bytes=0-131071';
				}
				$requests[ $url ] = array(
					'url'     => $url,
					'type'    => $type,
					'headers' => $headers,
				);
			}

			try {
				$responses = call_user_func( array( $class, 'request_multiple' ), $requests, $options );
			} catch ( \Exception $e ) {
				// Whole-chunk failure: record an error for each and continue.
				foreach ( $chunk as $url ) {
					$out[ $url ] = array(
						'code'          => 0,
						'error'         => $e->getMessage(),
						'redirect_code' => 0,
						'final_url'     => '',
					);
				}
				continue;
			}

			foreach ( $chunk as $url ) {
				$resp = isset( $responses[ $url ] ) ? $responses[ $url ] : null;
				if ( is_object( $resp ) && isset( $resp->status_code ) && ! ( $resp instanceof \Exception ) ) {
					$redirect_code = 0;
					$final_url     = isset( $resp->url ) ? (string) $resp->url : '';
					if ( ! empty( $resp->history ) && is_array( $resp->history ) && isset( $resp->history[0]->status_code ) ) {
						$redirect_code = (int) $resp->history[0]->status_code;
					}
					$out[ $url ] = array(
						'code'          => (int) $resp->status_code,
						'error'         => null,
						'redirect_code' => $redirect_code,
						'final_url'     => $final_url,
					);
				} elseif ( $resp instanceof \Exception ) {
					$out[ $url ] = array(
						'code'          => 0,
						'error'         => $resp->getMessage(),
						'redirect_code' => 0,
						'final_url'     => '',
					);
				} else {
					$out[ $url ] = array(
						'code'          => 0,
						'error'         => __( 'No response', 'coywolf-seo' ),
						'redirect_code' => 0,
						'final_url'     => '',
					);
				}
			}
		}

		return $out;
	}

	/**
	 * Build a Hooks object that re-applies the SSRF guard on each redirect hop.
	 *
	 * @return object|null A Requests Hooks instance, or null if unavailable.
	 */
	private function redirect_guard_hooks() {
		$hooks_class = null;
		if ( class_exists( '\WpOrg\Requests\Hooks' ) ) {
			$hooks_class = '\WpOrg\Requests\Hooks';
		} elseif ( class_exists( 'Requests_Hooks' ) ) {
			$hooks_class = 'Requests_Hooks';
		} else {
			return null;
		}

		$exception_class = class_exists( '\WpOrg\Requests\Exception' )
			? '\WpOrg\Requests\Exception'
			: 'Requests_Exception';

		$self  = $this;
		$hooks = new $hooks_class();
		$hooks->register(
			'requests.before_redirect',
			function ( $location ) use ( $self, $exception_class ) {
				// $location is already absolutised by the Requests library.
				if ( 'unsafe' === $self->url_safety( $location ) ) {
					throw new $exception_class(
						'Redirect to a non-public host was blocked.',
						'coywolf_seo_lm.ssrf_redirect'
					);
				}
			}
		);

		return $hooks;
	}

	/**
	 * Resolve the available Requests class name, or null if none.
	 *
	 * @return string|null
	 */
	private function requests_class() {
		if ( class_exists( '\WpOrg\Requests\Requests' ) ) {
			return '\WpOrg\Requests\Requests';
		}
		if ( class_exists( 'Requests' ) ) {
			return 'Requests';
		}
		return null;
	}

	/**
	 * The User-Agent string to present. Order of precedence:
	 *   1. The Coywolf SEO Settings override ('lm_user_agent', if non-empty).
	 *   2. The 'coywolf_seo_lm_user_agent' filter (legacy hook for site code).
	 *   3. The built-in current Chrome / Windows UA.
	 *
	 * @return string
	 */
	private function user_agent() {
		// Defence in depth: strip control chars at read time too, in case
		// the option was written by something other than the settings save path.
		$override = preg_replace( '/[\r\n\t\0]+/', ' ', (string) Coywolf_SEO_Options::get( 'lm_user_agent' ) );
		$override = trim( (string) $override );
		if ( '' !== $override ) {
			return $override;
		}
		$ua = (string) apply_filters( 'coywolf_seo_lm_user_agent', self::BROWSER_UA );
		$ua = trim( (string) preg_replace( '/[\r\n\t\0]+/', ' ', $ua ) );
		return '' !== $ua ? $ua : self::BROWSER_UA;
	}

	/**
	 * Browser-like request headers to accompany the Chrome User-Agent.
	 *
	 * A real browser sends an Accept/Accept-Language pair plus Chrome client
	 * hints (Sec-CH-UA*) and fetch metadata (Sec-Fetch-*). Sending the UA alone
	 * is itself a bot signal, so these are included to better avoid the 403 /
	 * 429 / 999 blocks the UA change is meant to reduce.
	 *
	 * @return array<string,string>
	 */
	private function browser_headers() {
		return array(
			'Accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
			'Accept-Language'           => 'en-US,en;q=0.9',
			'Sec-CH-UA'                 => '"Chromium";v="137", "Google Chrome";v="137", "Not/A)Brand";v="24"',
			'Sec-CH-UA-Mobile'          => '?0',
			'Sec-CH-UA-Platform'        => '"Windows"',
			'Sec-Fetch-Dest'            => 'document',
			'Sec-Fetch-Mode'            => 'navigate',
			'Sec-Fetch-Site'            => 'none',
			'Sec-Fetch-User'            => '?1',
			'Upgrade-Insecure-Requests' => '1',
		);
	}

	/**
	 * Build a result record from a status code, optional error, and redirect
	 * data. Classifies the link as broken (error / 4xx / 5xx), a redirect
	 * (resolved via 3xx to a non-error final status), or neither.
	 *
	 * @param int         $code          Final HTTP status code (0 if no response).
	 * @param string|null $error         Error message, if the request failed.
	 * @param int         $redirect_code Status of the first redirect hop, or 0.
	 * @param string      $final_url     URL the request ultimately landed on.
	 * @param string      $original_url  URL originally requested.
	 * @return array { broken, code, short, label, redirect, redirect_code, final_url }
	 */
	private function format_result( $code, $error = null, $redirect_code = 0, $final_url = '', $original_url = '' ) {
		$code          = (int) $code;
		$redirect_code = (int) $redirect_code;

		// A redirect only "counts" if it actually changed the destination.
		$redirected = ( $redirect_code >= 300 && $redirect_code < 400 )
			&& '' !== $final_url
			&& ! $this->same_url( $final_url, $original_url );

		// No response / transport error.
		if ( 0 === $code ) {
			return array(
				'broken'        => true,
				'blocked'       => false,
				'code'          => 0,
				'short'         => __( 'Error', 'coywolf-seo' ),
				'label'         => null !== $error ? $this->shorten( $error ) : __( 'No response', 'coywolf-seo' ),
				'redirect'      => false,
				'redirect_code' => 0,
				'final_url'     => '',
			);
		}

		// Anti-bot block (429 rate-limit or 999 from LinkedIn/Yandex): the link
		// is almost certainly fine, it just cannot be verified from a server.
		// Classified on its own so it is never reported or counted as broken.
		// Checked before the >= 400 test so real 4xx still fall through.
		if ( $this->is_blocked( $code ) ) {
			return array(
				'broken'        => false,
				'blocked'       => true,
				'code'          => $code,
				'short'         => __( 'Blocked', 'coywolf-seo' ),
				'label'         => sprintf(
					/* translators: %d: HTTP status code returned by the blocking host. */
					__( 'Blocked by the destination (HTTP %d) — the link is likely fine but cannot be verified from a server.', 'coywolf-seo' ),
					$code
				),
				'redirect'      => false,
				'redirect_code' => 0,
				'final_url'     => '',
			);
		}

		$broken = ( $code >= 400 );

		// Broken link that was reached via a redirect: note it in the tooltip.
		if ( $broken ) {
			$label = $this->status_label( $code );
			if ( $redirected ) {
				/* translators: 1: redirect status code, 2: final status label. */
				$label = sprintf( __( 'Via %1$d redirect, final %2$s', 'coywolf-seo' ), $redirect_code, $this->status_label( $code ) );
			}
			return array(
				'broken'        => true,
				'blocked'       => false,
				'code'          => $code,
				'short'         => (string) $code,
				'label'         => $label,
				'redirect'      => $redirected,
				'redirect_code' => $redirected ? $redirect_code : 0,
				'final_url'     => $redirected ? $final_url : '',
			);
		}

		// Working link reached through a redirect: report it (not broken).
		if ( $redirected ) {
			/* translators: 1: redirect status label, 2: final URL, 3: final status code. */
			$label = sprintf(
				/* translators: %1$s: source URL; %2$s: redirect destination; %3$d: final HTTP status code. */
				__( '%1$s → %2$s (final %3$d)', 'coywolf-seo' ),
				$this->status_label( $redirect_code ),
				$final_url,
				$code
			);
			return array(
				'broken'        => false,
				'blocked'       => false,
				'code'          => $code,
				'short'         => (string) $redirect_code,
				'label'         => $label,
				'redirect'      => true,
				'redirect_code' => $redirect_code,
				'final_url'     => $final_url,
			);
		}

		// Plain success.
		return array(
			'broken'        => false,
			'blocked'       => false,
			'code'          => $code,
			'short'         => (string) $code,
			'label'         => $this->status_label( $code ),
			'redirect'      => false,
			'redirect_code' => 0,
			'final_url'     => '',
		);
	}

	/**
	 * Compare two URLs, ignoring only a trailing slash, so a slash-only redirect
	 * is not reported. Scheme differences (http→https) and any path/host change
	 * are treated as real redirects worth surfacing.
	 *
	 * @param string $a First URL.
	 * @param string $b Second URL.
	 * @return bool
	 */
	private function same_url( $a, $b ) {
		$norm = function ( $u ) {
			return rtrim( (string) $u, '/' );
		};
		return 0 === strcasecmp( $norm( $a ), $norm( $b ) );
	}

	/**
	 * Check a single URL sequentially (fallback when the parallel transport is
	 * unavailable). Tries HEAD first, then GET, since some servers reject HEAD.
	 *
	 * @param string $url URL to check.
	 * @return array { broken: bool, code: int, short: string, label: string }
	 */
	private function check_url( $url ) {
		// SSRF guard: never let post content steer requests at internal hosts.
		if ( 'unsafe' === $this->url_safety( $url ) ) {
			return array(
				'broken'        => true,
				'blocked'       => false,
				'code'          => 0,
				'short'         => __( 'Skipped', 'coywolf-seo' ),
				'label'         => __( 'Skipped — host resolves to a non-public address', 'coywolf-seo' ),
				'redirect'      => false,
				'redirect_code' => 0,
				'final_url'     => '',
			);
		}

		$args = array(
			'timeout'             => self::HTTP_TIMEOUT,
			'redirection'         => 5,
			'sslverify'           => true,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => 131072, // 128 KB is plenty for a status check.
			'user-agent'          => $this->user_agent(),
			'headers'             => $this->browser_headers(),
		);

		// wp_safe_remote_* validate the host (and redirect hops) against private
		// ranges. The url_safety() check above additionally blocks link-local
		// and IPv6 ranges that wp_http_validate_url() does not cover.
		$response = wp_safe_remote_head( $url, $args );
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		// Retry with GET if HEAD failed, returned nothing, or was rejected.
		if ( is_wp_error( $response ) || 0 === $code || 405 === $code || $code >= 400 ) {
			$get = wp_safe_remote_get( $url, $args );
			if ( ! is_wp_error( $get ) ) {
				$get_code = (int) wp_remote_retrieve_response_code( $get );
				if ( $get_code > 0 ) {
					$response = $get;
					$code     = $get_code;
				}
			} elseif ( is_wp_error( $response ) ) {
				$response = $get; // Keep an error to report.
			}
		}

		if ( is_wp_error( $response ) ) {
			return $this->format_result( 0, $response->get_error_message() );
		}

		// Best-effort redirect detection from the underlying Requests response.
		$redirect_code = 0;
		$final_url     = '';
		if ( isset( $response['http_response'] ) && is_object( $response['http_response'] )
			&& method_exists( $response['http_response'], 'get_response_object' ) ) {
			$obj = $response['http_response']->get_response_object();
			if ( is_object( $obj ) ) {
				if ( isset( $obj->url ) ) {
					$final_url = (string) $obj->url;
				}
				if ( ! empty( $obj->history ) && is_array( $obj->history ) && isset( $obj->history[0]->status_code ) ) {
					$redirect_code = (int) $obj->history[0]->status_code;
				}
			}
		}

		return $this->format_result( $code, null, $redirect_code, $final_url, $url );
	}

	/**
	 * Decide whether a URL is safe to request (SSRF protection).
	 *
	 * Returns 'unsafe' when the scheme/port is disallowed, when credentials are
	 * embedded, or when the host resolves to any private, reserved, loopback or
	 * link-local address (IPv4 or IPv6 — including cloud metadata at
	 * 169.254.169.254, which WordPress core's own validator does not block).
	 * Hosts that cannot be resolved return 'unresolved' and are left to the HTTP
	 * request to report as a normal dead link.
	 *
	 * @param string $url URL to evaluate.
	 * @return string 'unsafe' | 'unresolved' | 'ok'
	 */
	private function url_safety( $url ) {
		$parts = wp_parse_url( $url );

		if ( empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return 'unsafe';
		}
		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			return 'unsafe'; // Embedded credentials.
		}
		if ( empty( $parts['host'] ) ) {
			return 'unsafe';
		}
		if ( ! empty( $parts['port'] ) && ! in_array( (int) $parts['port'], array( 80, 443, 8080 ), true ) ) {
			return 'unsafe'; // Non-web ports.
		}

		$host = trim( $parts['host'], '[]' ); // Strip IPv6 literal brackets.

		$home_host    = $this->normalize_domain( (string) home_url() );
		$is_home_link = ( '' !== $home_host && $home_host === $this->normalize_domain( $host ) );

		$ips = array();
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips[] = $host;
		} else {
			$v4 = gethostbynamel( $host );
			if ( is_array( $v4 ) ) {
				$ips = array_merge( $ips, $v4 );
			}
			if ( function_exists( 'dns_get_record' ) && defined( 'DNS_AAAA' ) ) {
				$v6 = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( is_array( $v6 ) ) {
					foreach ( $v6 as $rec ) {
						if ( ! empty( $rec['ipv6'] ) ) {
							$ips[] = $rec['ipv6'];
						}
					}
				}
			}
		}

		if ( empty( $ips ) ) {
			return 'unresolved';
		}

		// Same-site links to home_url() are allowed even when the host
		// resolves to a loopback address (typical on local-dev installs),
		// but they must NOT resolve to a private/link-local/reserved range
		// that isn't loopback — e.g. cloud-metadata 169.254.169.254. A
		// compromised internal resolver could otherwise turn a benign
		// home-host link into an SSRF vector via split-horizon DNS.
		if ( $is_home_link ) {
			foreach ( $ips as $ip ) {
				if ( ! $this->is_public_ip( $ip ) && ! $this->is_loopback_ip( $ip ) ) {
					return 'unsafe';
				}
			}
			return 'ok';
		}

		foreach ( $ips as $ip ) {
			if ( ! $this->is_public_ip( $ip ) ) {
				return 'unsafe';
			}
		}
		return 'ok';
	}

	/**
	 * Whether an IP address is a routable public address.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 * @return bool
	 */
	private function is_public_ip( $ip ) {
		return (bool) filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	/**
	 * Whether an IP address is in the loopback range (127.0.0.0/8 for
	 * IPv4, ::1 for IPv6). Used by the same-site SSRF carve-out so
	 * local-dev installs still work without opening up RFC1918 and
	 * link-local ranges.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 * @return bool
	 */
	private function is_loopback_ip( $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return 0 === strpos( $ip, '127.' );
		}
		// IPv6: normalise via inet_pton/inet_ntop so "::0001" → "::1".
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $packed ) {
			return false;
		}
		return '::1' === inet_ntop( $packed );
	}

	/* ---------------------------------------------------------------------
	 * Ignore list
	 * ------------------------------------------------------------------- */

	/**
	 * Read the ignore rules.
	 *
	 * @return array Map of "type|value" => [ 'type' => string, 'value' => string ].
	 */
	private function get_ignores() {
		if ( null !== $this->ignores_cache ) {
			return $this->ignores_cache;
		}
		$ignores             = get_option( self::IGNORES_OPTION );
		$this->ignores_cache = is_array( $ignores ) ? $ignores : array();
		return $this->ignores_cache;
	}

	/**
	 * Persist the ignore rules (non-autoloaded) and refresh the per-request
	 * memo so a subsequent get_ignores() in the same request sees the change.
	 *
	 * @param array $ignores Rule map.
	 */
	private function save_ignores( $ignores ) {
		update_option( self::IGNORES_OPTION, $ignores, false );
		$this->ignores_cache = is_array( $ignores ) ? $ignores : array();
	}

	/**
	 * Normalise a raw rule into a stored form, or null if invalid.
	 *
	 * Domain rules are reduced to a bare host (scheme/path/leading "www."
	 * stripped). URL rules keep scheme + host + path, with any trailing slash
	 * removed so "/a" and "/a/" compare equal.
	 *
	 * @param string $type  'domain' | 'url' | 'wildcard'.
	 * @param string $value Raw value.
	 * @return array|null
	 */
	private function normalize_rule( $type, $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}

		if ( 'domain' === $type ) {
			$host = $this->normalize_domain( $value );
			if ( '' === $host ) {
				return null;
			}
			return array(
				'type'  => 'domain',
				'value' => $host,
			);
		}

		if ( 'url' === $type ) {
			if ( ! preg_match( '~^https?://~i', $value ) ) {
				$value = 'http://' . $value;
			}
			$parts = wp_parse_url( $value );
			if ( empty( $parts['host'] ) ) {
				return null;
			}
			$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'http';
			$host   = strtolower( $parts['host'] );
			$path   = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
			$query  = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
			return array(
				'type'  => 'url',
				'value' => $scheme . '://' . $host . $path . $query,
			);
		}

		if ( 'wildcard' === $type ) {
			// Preserve the pattern as the operator typed it, with two
			// conveniences: a leading scheme is auto-added when missing
			// (so "coywolf.com/visit/*" still works) and surrounding
			// whitespace is trimmed. Case is preserved; matching is
			// case-insensitive.
			if ( ! preg_match( '~^https?://~i', $value ) && false === strpos( $value, '://' ) ) {
				$value = 'http://' . $value;
			}
			return array(
				'type'  => 'wildcard',
				'value' => $value,
			);
		}

		return null;
	}

	/**
	 * Reduce a domain (or pasted URL) to a bare lowercase host without "www.".
	 *
	 * @param string $value Raw domain or URL.
	 * @return string
	 */
	private function normalize_domain( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '~^https?://~', $value ) ) {
			$value = (string) wp_parse_url( $value, PHP_URL_HOST );
		} else {
			// Strip any stray path/query the user may have pasted.
			$value = preg_replace( '~[/?#].*$~', '', $value );
		}
		$value = preg_replace( '~^www\.~', '', $value );
		return trim( (string) $value );
	}

	/**
	 * Whether an HTTP status should be treated as an anti-bot "blocked" result
	 * rather than a broken link.
	 *
	 * 429 (Too Many Requests / rate-limited) and 999 (the non-standard code
	 * LinkedIn and Yandex answer) are, in practice, almost always an anti-bot
	 * block rather than a dead link: the destination is refusing automated or
	 * datacenter requests, which is unavoidable from a server IP and does not
	 * mean the link is broken. They are classified as "Blocked" so they are
	 * never reported or counted as broken. Every other code, including 403,
	 * 404, and 410, returns false and keeps its normal handling.
	 *
	 * @param int $code HTTP status code.
	 * @return bool
	 */
	private function is_blocked( $code ) {
		$code = (int) $code;
		return ( 429 === $code || 999 === $code );
	}

	/**
	 * Whether a URL matches any ignore rule.
	 *
	 * @param string $url URL to test.
	 * @return bool
	 */
	private function is_ignored( $url ) {
		$ignores = $this->get_ignores();
		if ( empty( $ignores ) ) {
			return false;
		}
		$host     = $this->normalize_domain( $url );
		$norm_url = $this->normalize_url_for_match( $url );
		foreach ( $ignores as $rule ) {
			if ( $this->rule_matches( $rule, $url, $host, $norm_url ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Keys of every ignore rule that matches a URL. Used to "unignore" a link by
	 * dropping the rule(s) responsible for hiding it.
	 *
	 * @param string $url Link URL.
	 * @return string[] Rule keys (as stored in the ignores option).
	 */
	private function matching_ignore_keys( $url ) {
		$ignores = $this->get_ignores();
		if ( empty( $ignores ) ) {
			return array();
		}
		$host     = $this->normalize_domain( $url );
		$norm_url = $this->normalize_url_for_match( $url );
		$keys     = array();
		foreach ( $ignores as $key => $rule ) {
			if ( $this->rule_matches( $rule, $url, $host, $norm_url ) ) {
				$keys[] = (string) $key;
			}
		}
		return $keys;
	}

	/**
	 * Whether a single ignore rule matches a URL.
	 *
	 * @param array  $rule     Rule with 'type' and 'value'.
	 * @param string $url      Raw URL (used for wildcard matching).
	 * @param string $host     Normalized host of $url (from normalize_domain()).
	 * @param string $norm_url Normalized URL of $url (from normalize_url_for_match()).
	 * @return bool
	 */
	private function rule_matches( $rule, $url, $host, $norm_url ) {
		if ( 'domain' === $rule['type'] ) {
			return '' !== $host && ( $host === $rule['value'] || $this->ends_with( $host, '.' . $rule['value'] ) );
		}
		if ( 'url' === $rule['type'] ) {
			return '' !== $norm_url && 0 === strcasecmp( $norm_url, $rule['value'] );
		}
		if ( 'wildcard' === $rule['type'] ) {
			return $this->wildcard_matches( (string) $rule['value'], (string) $url );
		}
		return false;
	}

	/**
	 * Normalize a URL the same way exact-URL ignore rules are stored, so the two
	 * can be compared case-insensitively (scheme + host + trailing-slash-trimmed
	 * path + query).
	 *
	 * @param string $url URL.
	 * @return string Normalized URL, or '' when the host cannot be parsed.
	 */
	private function normalize_url_for_match( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		$scheme  = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'http';
		$u_host  = strtolower( $parts['host'] );
		$u_path  = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
		$u_query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		return $scheme . '://' . $u_host . $u_path . $u_query;
	}

	/**
	 * Whether a URL matches a "*"-wildcard pattern. The pattern is treated
	 * as a literal string with one extension: each "*" matches any run of
	 * characters (including "/" and the empty string). The match is
	 * anchored to the whole URL and case-insensitive.
	 *
	 * @param string $pattern Operator's pattern, e.g. "https://coywolf.com/visit/*".
	 * @param string $url     URL to test.
	 * @return bool
	 */
	private function wildcard_matches( $pattern, $url ) {
		if ( '' === $pattern ) {
			return false;
		}
		// Hard cap on input length and collapse consecutive "*"s — both
		// guard against catastrophic-backtracking patterns like
		// "**********a" which would otherwise expand into ".*.*.*.*.*…a"
		// and burn CPU inside the scan worker.
		if ( strlen( $pattern ) > 1024 ) {
			return false;
		}
		$pattern = preg_replace( '/\*+/', '*', $pattern );
		$parts   = array();
		foreach ( explode( '*', $pattern ) as $piece ) {
			$parts[] = preg_quote( $piece, '/' );
		}
		$regex = '/^' . implode( '.*', $parts ) . '$/i';
		return (bool) preg_match( $regex, $url );
	}

	/**
	 * Polyfill-safe "string ends with" check.
	 *
	 * @param string $haystack Subject.
	 * @param string $needle   Suffix.
	 * @return bool
	 */
	private function ends_with( $haystack, $needle ) {
		$len = strlen( $needle );
		if ( 0 === $len ) {
			return true;
		}
		return substr( $haystack, -$len ) === $needle;
	}

	/* ---------------------------------------------------------------------
	 * State helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Default (empty) scan state.
	 *
	 * @return array
	 */
	private function default_state() {
		return array(
			'status'                => 'idle', // idle | running | complete | cancelled.
			'queue'                 => array(),
			'total_posts'           => 0,
			'processed'             => 0,
			'links_checked'         => 0,
			'results'               => array(),
			'token'                 => '',
			'started'               => 0,
			'updated'               => 0,
			'current_post'          => '',
			'processed_by_type'     => array(),
			'links_checked_by_type' => array(),
			// Bumped whenever 'results' changes, so the status poll can skip
			// re-sending the full results payload when nothing has changed.
			'version'               => 0,
		);
	}

	/**
	 * Read scan state from the database.
	 *
	 * @return array
	 */
	private function get_state() {
		$state = get_option( self::STATE_OPTION );
		if ( ! is_array( $state ) ) {
			return $this->default_state();
		}
		$state = wp_parse_args( $state, $this->default_state() );
		// Drop the legacy in-state URL cache if an option saved by an older
		// version still carries it; the cache now lives in its own option so
		// it never rides along on the frequently-polled scan state.
		unset( $state['url_cache'] );
		return $state;
	}

	/* ---------------------------------------------------------------------
	 * URL check cache (separate option, not part of the polled scan state)
	 *
	 * Maps url => check result. Lets the worker dedupe network checks for a
	 * URL that appears across many posts/batches. Kept out of STATE_OPTION
	 * so the 2-second status poll never has to read or unserialize it, and
	 * deleted when a scan ends since nothing reads it afterward.
	 * ------------------------------------------------------------------- */

	/**
	 * Read the URL check cache.
	 *
	 * @return array<string,array>
	 */
	private function get_url_cache() {
		$cache = get_option( self::URL_CACHE_OPTION );
		return is_array( $cache ) ? $cache : array();
	}

	/**
	 * Persist the URL check cache (non-autoloaded).
	 *
	 * @param array $cache url => result map.
	 */
	private function save_url_cache( $cache ) {
		update_option( self::URL_CACHE_OPTION, $cache, false );
	}

	/**
	 * Delete the URL check cache (called when a scan ends — it is only
	 * consumed by a running scan's worker).
	 */
	private function delete_url_cache() {
		delete_option( self::URL_CACHE_OPTION );
	}

	/**
	 * Forget cached results for specific URLs, so they are re-checked if a
	 * running scan encounters them again (e.g. after the operator edits or
	 * removes a link mid-scan). No-op when nothing is cached — which is the
	 * common case outside a running scan, since the cache is deleted on
	 * completion — so this stays cheap for post-scan row actions.
	 *
	 * @param string[] $urls URLs to drop from the cache.
	 */
	private function forget_cached_urls( $urls ) {
		$cache = $this->get_url_cache();
		if ( empty( $cache ) ) {
			return;
		}
		$changed = false;
		foreach ( (array) $urls as $url ) {
			if ( isset( $cache[ $url ] ) ) {
				unset( $cache[ $url ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			$this->save_url_cache( $cache );
		}
	}

	/**
	 * Persist scan state (non-autoloaded).
	 *
	 * @param array $state State to save.
	 */
	private function save_state( $state ) {
		$state['updated'] = time();
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Build the browser-facing view of the state.
	 *
	 * Internal fields (queue, token, url_cache) are removed. Edit/view links are
	 * generated here, in the authenticated operator request, because the
	 * background worker runs without a current user and could not produce a
	 * correct edit link.
	 *
	 * @param array $state Internal state.
	 * @return array
	 */
	private function public_state( $state, $since = null ) {
		$version = (int) ( isset( $state['version'] ) ? $state['version'] : 0 );

		// Counts are a cheap integer walk and feed the summary line, so they
		// are always computed — even when the heavy results payload is skipped.
		$broken_count = 0;
		$redir_count  = 0;
		foreach ( $state['results'] as $r ) {
			if ( ! empty( $r['blocked'] ) ) {
				continue; // Blocked != broken; excluded from both tallies.
			}
			if ( 0 === (int) $r['code'] || (int) $r['code'] >= 400 ) {
				++$broken_count;
			} elseif ( ! empty( $r['redirect'] ) ) {
				++$redir_count;
			}
		}

		// If the caller passed its last-seen version and nothing has changed,
		// skip the heavy results payload: the per-row link generation, the
		// JSON serialize, and the transfer. The browser keeps the rows it
		// already rendered. Progress counters above are always fresh.
		if ( null !== $since && (int) $since === $version ) {
			return array(
				'status'           => $state['status'],
				'totalPosts'       => (int) $state['total_posts'],
				'processed'        => (int) $state['processed'],
				'linksChecked'     => (int) $state['links_checked'],
				'brokenCount'      => $broken_count,
				'redirectCount'    => $redir_count,
				'currentPost'      => $state['current_post'],
				'version'          => $version,
				'ignores'          => $this->ignores_payload(),
				'resultsUnchanged' => true,
			);
		}

		$rows = array();

		// Prime the post cache for every distinct post in the results in a
		// single query, so the get_edit_post_link() / get_permalink() calls
		// below hit the object cache instead of firing one DB query per row.
		// The browser polls this endpoint every couple of seconds during a
		// scan, so on a site without a persistent object cache this turns an
		// N-query-per-poll cost into one.
		if ( ! empty( $state['results'] ) && function_exists( '_prime_post_caches' ) ) {
			$post_ids = array();
			foreach ( $state['results'] as $r ) {
				$pid = (int) $r['post_id'];
				if ( $pid > 0 ) {
					$post_ids[ $pid ] = true;
				}
			}
			if ( ! empty( $post_ids ) ) {
				_prime_post_caches( array_keys( $post_ids ), false, false );
			}
		}

		foreach ( $state['results'] as $r ) {
			$is_redirect = ! empty( $r['redirect'] );

			$rows[] = array(
				'anchor'       => $r['anchor'],
				'url'          => $r['url'],
				'code'         => (int) $r['code'],
				'short'        => isset( $r['short'] ) ? $r['short'] : (string) $r['code'],
				'label'        => $r['label'],
				'redirect'     => $is_redirect,
				'blocked'      => ! empty( $r['blocked'] ),
				'redirectCode' => isset( $r['redirect_code'] ) ? (int) $r['redirect_code'] : 0,
				'finalUrl'     => isset( $r['final_url'] ) ? (string) $r['final_url'] : '',
				'postId'       => (int) $r['post_id'],
				'post_title'   => $r['post_title'],
				'edit'         => get_edit_post_link( $r['post_id'], 'raw' ),
				'view'         => get_permalink( $r['post_id'] ),
			);
		}

		return array(
			'status'        => $state['status'],
			'totalPosts'    => (int) $state['total_posts'],
			'processed'     => (int) $state['processed'],
			'linksChecked'  => (int) $state['links_checked'],
			'brokenCount'   => $broken_count,
			'redirectCount' => $redir_count,
			'currentPost'   => $state['current_post'],
			'version'       => $version,
			'results'       => $rows,
			'ignores'       => $this->ignores_payload(),
		);
	}

	/**
	 * Build the ignore-rule list for the browser payload.
	 *
	 * @return array[]
	 */
	private function ignores_payload() {
		$ignores = array();
		foreach ( $this->get_ignores() as $key => $rule ) {
			$ignores[] = array(
				'key'   => (string) $key,
				'type'  => $rule['type'],
				'value' => $rule['value'],
			);
		}
		return $ignores;
	}

	/* ---------------------------------------------------------------------
	 * Small utilities
	 * ------------------------------------------------------------------- */

	/**
	 * Human-readable label for an HTTP status code.
	 *
	 * @param int $code HTTP status code.
	 * @return string
	 */
	private function status_label( $code ) {
		$code = (int) $code;
		if ( 0 === $code ) {
			return __( 'No response', 'coywolf-seo' );
		}
		$desc = get_status_header_desc( $code );
		return $desc ? $code . ' ' . $desc : (string) $code;
	}

	/**
	 * Truncate a long error message for display.
	 *
	 * @param string $text Message.
	 * @return string
	 */
	private function shorten( $text ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );
		if ( strlen( $text ) > 80 ) {
			$text = substr( $text, 0, 77 ) . '…';
		}
		return '' === $text ? __( 'Connection failed', 'coywolf-seo' ) : $text;
	}

	/* ---------------------------------------------------------------------
	 * Settings (relocated to Coywolf SEO's Settings page)
	 * ------------------------------------------------------------------- */

	/**
	 * Read the scope setting ("all" | "external" | "internal"), defaulting
	 * to "external" and falling back to the default on any unknown value.
	 * Stored in Coywolf SEO's options as 'lm_scope'.
	 *
	 * @return string One of the self::SCOPE_* constants.
	 */
	private function get_scope() {
		$stored = (string) Coywolf_SEO_Options::get( 'lm_scope' );
		$valid  = array( self::SCOPE_ALL, self::SCOPE_EXTERNAL, self::SCOPE_INTERNAL );
		return in_array( $stored, $valid, true ) ? $stored : self::SCOPE_EXTERNAL;
	}

	/**
	 * The set of post types each scan walks. The link inventory always covers
	 * posts and pages (the All Links table reports a per-link count for each).
	 *
	 * @return string[]
	 */
	private function get_post_types_to_scan() {
		return array( 'post', 'page' );
	}

	/**
	 * Map of scope values to their UI labels.
	 *
	 * @return array<string,string>
	 */
	private function scope_labels() {
		return array(
			self::SCOPE_ALL      => __( 'All links', 'coywolf-seo' ),
			self::SCOPE_EXTERNAL => __( 'External links only', 'coywolf-seo' ),
			self::SCOPE_INTERNAL => __( 'Internal links only', 'coywolf-seo' ),
		);
	}

	/**
	 * Speed-profile presets. Each maps to a (batch, concurrency) pair —
	 * "batch" is posts processed per loopback request, "concurrency" is
	 * links checked in parallel per batch. Higher values mean faster
	 * scans at the cost of more outbound traffic and memory per worker.
	 *
	 * @return array<string,array{batch:int,concurrency:int}>
	 */
	private function speed_profiles() {
		return array(
			self::SPEED_POLITE  => array(
				'batch'       => 3,
				'concurrency' => 4,
			),
			self::SPEED_DEFAULT => array(
				'batch'       => 5,
				'concurrency' => 8,
			),
			self::SPEED_FAST    => array(
				'batch'       => 15,
				'concurrency' => 16,
			),
			self::SPEED_FASTER  => array(
				'batch'       => 30,
				'concurrency' => 24,
			),
		);
	}

	/**
	 * Display labels for the speed-profile dropdown.
	 *
	 * @return array<string,string>
	 */
	private function speed_labels() {
		return array(
			self::SPEED_POLITE  => __( 'Polite (3 posts / 4 parallel)', 'coywolf-seo' ),
			self::SPEED_DEFAULT => __( 'Default (5 posts / 8 parallel)', 'coywolf-seo' ),
			self::SPEED_FAST    => __( 'Fast (15 posts / 16 parallel)', 'coywolf-seo' ),
			self::SPEED_FASTER  => __( 'Faster (30 posts / 24 parallel)', 'coywolf-seo' ),
		);
	}

	/**
	 * Resolve the currently-selected speed profile, falling back to the
	 * Default profile on any unknown value. Stored in Coywolf SEO's options
	 * as 'lm_speed'.
	 *
	 * @return array{batch:int,concurrency:int}
	 */
	private function get_speed_profile() {
		$stored   = (string) Coywolf_SEO_Options::get( 'lm_speed' );
		$profiles = $this->speed_profiles();
		if ( ! isset( $profiles[ $stored ] ) ) {
			$stored = self::SPEED_DEFAULT;
		}
		return $profiles[ $stored ];
	}

	/**
	 * Reorder a list of URLs round-robin by host, so consecutive entries
	 * are from different hosts. Lets array_chunk() in multi_request build
	 * concurrency-windows that hit many hosts at once rather than piling
	 * up on whichever host happens to come first in the source order.
	 *
	 * @param string[] $urls
	 * @return string[]
	 */
	private function interleave_by_host( $urls ) {
		$by_host = array();
		foreach ( $urls as $url ) {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( '' === $host ) {
				$host = '__no_host__';
			}
			$by_host[ $host ][] = $url;
		}
		$out = array();
		while ( ! empty( $by_host ) ) {
			foreach ( $by_host as $host => $queue ) {
				$out[] = array_shift( $by_host[ $host ] );
				if ( empty( $by_host[ $host ] ) ) {
					unset( $by_host[ $host ] );
				}
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Database — persistent link inventory
	 *
	 * Two custom tables replace the old ephemeral scan-results array:
	 *   {prefix}coywolf_seo_lm_links       — one row per unique URL
	 *   {prefix}coywolf_seo_lm_occurrences — one row per (link, post, instance)
	 * ------------------------------------------------------------------- */

	/**
	 * Fully-qualified name of the links table.
	 *
	 * @return string
	 */
	public static function lm_links_table() {
		global $wpdb;
		return $wpdb->prefix . 'coywolf_seo_lm_links';
	}

	/**
	 * Fully-qualified name of the occurrences table.
	 *
	 * @return string
	 */
	public static function lm_occurrences_table() {
		global $wpdb;
		return $wpdb->prefix . 'coywolf_seo_lm_occurrences';
	}

	/**
	 * Create or upgrade the custom tables with dbDelta and stamp the schema
	 * version option. Idempotent — safe to call on every activation and from
	 * the admin_init version self-heal. Coywolf SEO's on_activate() calls this.
	 *
	 * dbDelta is whitespace/keyword sensitive: two spaces after PRIMARY KEY /
	 * KEY, one column per line, no backticks around the table name, and TEXT
	 * columns cannot carry an inline DEFAULT (hence the NULL date columns).
	 */
	public static function install_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$links           = self::lm_links_table();
		$occurrences     = self::lm_occurrences_table();

		$sql_links = "CREATE TABLE $links (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url_hash char(40) NOT NULL,
			url text NOT NULL,
			type varchar(10) NOT NULL DEFAULT 'external',
			response_code smallint(5) NOT NULL DEFAULT 0,
			response_short varchar(20) NOT NULL DEFAULT '',
			response_label varchar(255) NOT NULL DEFAULT '',
			is_redirect tinyint(1) NOT NULL DEFAULT 0,
			redirect_code smallint(5) NOT NULL DEFAULT 0,
			final_url text NULL,
			last_checked datetime NULL DEFAULT NULL,
			updated_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY type (type),
			KEY response_code (response_code)
		) $charset_collate;";

		$sql_occurrences = "CREATE TABLE $occurrences (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			link_id bigint(20) unsigned NOT NULL,
			post_id bigint(20) unsigned NOT NULL,
			instance int(10) unsigned NOT NULL DEFAULT 0,
			anchor text NOT NULL,
			rel varchar(191) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY link_id (link_id),
			KEY post_id (post_id),
			KEY link_post (link_id,post_id),
			KEY post_link (post_id,link_id)
		) $charset_collate;";

		dbDelta( $sql_links );
		dbDelta( $sql_occurrences );

		// The inline-JS feature was removed; drop the legacy column if present
		// (dbDelta never drops columns). Wrapped so it is a safe no-op on fresh
		// installs (no such column) and on engines that throw on / lack DROP
		// COLUMN — errors are suppressed and any exception is swallowed.
		try {
			$suppress = $wpdb->suppress_errors( true );
			$wpdb->query( "ALTER TABLE $occurrences DROP COLUMN inline_js" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time schema cleanup; table name from $wpdb->prefix, no user input.
			$wpdb->suppress_errors( $suppress );
		} catch ( \Throwable $e ) {
			$wpdb->suppress_errors( false );
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Run the installer when the stored schema version is behind the code.
	 * Hooked on admin_init so a folder-rename upgrade self-heals.
	 */
	public function lm_maybe_upgrade_db() {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) < self::DB_VERSION ) {
			self::install_tables();
		}
	}

	/* ---------------------------------------------------------------------
	 * Inventory data access
	 *
	 * Direct custom-table queries. Every value is passed through
	 * $wpdb->prepare(); table names are interpolated from the trusted
	 * $wpdb->prefix. The sniffs below always fire for legitimate custom-table
	 * work, so they are scoped-disabled for this section.
	 * ------------------------------------------------------------------- */
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

	/**
	 * Persist a response-check result onto a link row (matched by url_hash).
	 *
	 * @param string $url_hash sha1 of the URL.
	 * @param array  $check    A {@see format_result()} array.
	 */
	private function lm_write_response( $url_hash, $check ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->update(
			self::lm_links_table(),
			array(
				'response_code'  => (int) $check['code'],
				'response_short' => isset( $check['short'] ) ? $check['short'] : (string) $check['code'],
				'response_label' => isset( $check['label'] ) ? $check['label'] : '',
				'is_redirect'    => empty( $check['redirect'] ) ? 0 : 1,
				'redirect_code'  => isset( $check['redirect_code'] ) ? (int) $check['redirect_code'] : 0,
				'final_url'      => isset( $check['final_url'] ) ? (string) $check['final_url'] : '',
				'last_checked'   => $now,
				'updated_at'     => $now,
			),
			array( 'url_hash' => (string) $url_hash ),
			array( '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Distinct link ids referenced by a post's occurrences.
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	private function lm_link_ids_for_post( $post_id ) {
		global $wpdb;
		$occ = self::lm_occurrences_table();
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT link_id FROM $occ WHERE post_id = %d", $post_id ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Replace all occurrences recorded for a post with a fresh set, upserting
	 * the referenced links and garbage-collecting any link left orphaned.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $records {@see lm_extract_links()} records (already ignore-filtered).
	 */
	private function lm_set_occurrences( $post_id, $records ) {
		global $wpdb;
		$occ     = self::lm_occurrences_table();
		$links   = self::lm_links_table();
		$post_id = (int) $post_id;
		$old_ids = $this->lm_link_ids_for_post( $post_id );

		$wpdb->delete( $occ, array( 'post_id' => $post_id ), array( '%d' ) );

		if ( empty( $records ) ) {
			$this->lm_gc_links( $old_ids );
			return;
		}

		// Resolve a link id for every URL with a fixed, small number of queries
		// instead of a SELECT + UPSERT per link. Dedupe by url_hash first.
		$by_hash = array();
		foreach ( $records as $r ) {
			$h = sha1( $r['url'] );
			if ( ! isset( $by_hash[ $h ] ) ) {
				$by_hash[ $h ] = array(
					'url'  => $r['url'],
					'type' => $r['type'],
				);
			}
		}

		$id_by_hash = $this->lm_link_ids_by_hash( array_keys( $by_hash ) );

		// Insert links not seen before in one multi-row INSERT, then map their
		// ids. A URL's type never changes for a given hash, so existing rows
		// need no update.
		$missing = array();
		foreach ( $by_hash as $h => $info ) {
			if ( ! isset( $id_by_hash[ $h ] ) ) {
				$missing[ $h ] = $info;
			}
		}
		if ( ! empty( $missing ) ) {
			$now    = current_time( 'mysql', true );
			$rows   = array();
			$values = array();
			foreach ( $missing as $h => $info ) {
				$rows[]   = '(%s, %s, %s, %s)';
				$values[] = $h;
				$values[] = $info['url'];
				$values[] = $info['type'];
				$values[] = $now;
			}
			$wpdb->query( $wpdb->prepare( "INSERT INTO $links (url_hash, url, type, updated_at) VALUES " . implode( ', ', $rows ), $values ) );
			$id_by_hash += $this->lm_link_ids_by_hash( array_keys( $missing ) );
		}

		// Insert all of this post's occurrences in one multi-row INSERT.
		$new_ids = array();
		$rows    = array();
		$values  = array();
		foreach ( $records as $r ) {
			$h  = sha1( $r['url'] );
			$id = isset( $id_by_hash[ $h ] ) ? $id_by_hash[ $h ] : 0;
			if ( ! $id ) {
				continue;
			}
			$new_ids[ $id ] = true;
			$rows[]         = '(%d, %d, %d, %s, %s)';
			$values[]       = $id;
			$values[]       = $post_id;
			$values[]       = (int) $r['instance'];
			$values[]       = (string) $r['anchor'];
			$values[]       = (string) $r['rel'];
		}
		if ( ! empty( $rows ) ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO $occ (link_id, post_id, instance, anchor, rel) VALUES " . implode( ', ', $rows ), $values ) );
		}

		$this->lm_gc_links( array_diff( $old_ids, array_keys( $new_ids ) ) );
	}

	/**
	 * Map url_hash => link id for a set of hashes in a single query.
	 *
	 * @param string[] $hashes sha1 hashes.
	 * @return array<string,int>
	 */
	private function lm_link_ids_by_hash( $hashes ) {
		global $wpdb;
		$hashes = array_values( array_filter( array_map( 'strval', (array) $hashes ) ) );
		if ( empty( $hashes ) ) {
			return array();
		}
		$links = self::lm_links_table();
		$ph    = implode( ', ', array_fill( 0, count( $hashes ), '%s' ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, url_hash FROM $links WHERE url_hash IN ($ph)", $hashes ), ARRAY_A );
		$map   = array();
		foreach ( (array) $rows as $row ) {
			$map[ $row['url_hash'] ] = (int) $row['id'];
		}
		return $map;
	}

	/**
	 * Delete every occurrence for a post and GC any newly-orphaned links.
	 *
	 * @param int $post_id Post ID.
	 */
	private function lm_delete_post_occurrences( $post_id ) {
		global $wpdb;
		$post_id = (int) $post_id;
		$old_ids = $this->lm_link_ids_for_post( $post_id );
		$wpdb->delete( self::lm_occurrences_table(), array( 'post_id' => $post_id ), array( '%d' ) );
		$this->lm_gc_links( $old_ids );
	}

	/**
	 * Delete links (from the given candidate ids) that have no occurrences left.
	 *
	 * @param int[] $link_ids Candidate link ids.
	 */
	private function lm_gc_links( $link_ids ) {
		global $wpdb;
		$link_ids = array_filter( array_map( 'intval', (array) $link_ids ) );
		if ( empty( $link_ids ) ) {
			return;
		}
		$links = self::lm_links_table();
		$occ   = self::lm_occurrences_table();
		$in    = implode( ',', $link_ids );
		// Portable orphan delete: drop the candidate links that no longer have
		// any occurrence row (avoids MySQL-only multi-table DELETE syntax).
		$wpdb->query( "DELETE FROM $links WHERE id IN ($in) AND id NOT IN ( SELECT DISTINCT link_id FROM $occ )" );
	}

	/**
	 * Map url_hash => url for a set of hashes.
	 *
	 * @param string[] $hashes sha1 hashes.
	 * @return array<string,string>
	 */
	private function lm_urls_for_hashes( $hashes ) {
		global $wpdb;
		$hashes = array_values( array_filter( array_map( 'strval', (array) $hashes ) ) );
		if ( empty( $hashes ) ) {
			return array();
		}
		$links        = self::lm_links_table();
		$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT url_hash, url FROM $links WHERE url_hash IN ($placeholders)", $hashes ), ARRAY_A );
		$map          = array();
		foreach ( (array) $rows as $row ) {
			$map[ $row['url_hash'] ] = $row['url'];
		}
		return $map;
	}

	/**
	 * Empty both inventory tables (used at the start of a full (re)analysis).
	 */
	private function lm_truncate_tables() {
		global $wpdb;
		// DELETE (not TRUNCATE) for portability across MySQL and SQLite.
		$wpdb->query( 'DELETE FROM ' . self::lm_occurrences_table() );
		$wpdb->query( 'DELETE FROM ' . self::lm_links_table() );
	}

	// phpcs:enable

	/* ---------------------------------------------------------------------
	 * Event-driven indexing
	 *
	 * After the one-time analysis, the inventory is kept current by re-indexing
	 * each post as it is saved, trashed, untrashed, or deleted. Response checks
	 * never run inside the editor save — changed URLs are queued and drained on
	 * a WP-Cron tick so publishing is never blocked on the network.
	 * ------------------------------------------------------------------- */

	/**
	 * Link records for a post.
	 *
	 * Ignored links are kept in the inventory (so the "Ignored" view can list
	 * them) — they are merely excluded from response checks and hidden from the
	 * default "All" view. Callers that perform network checks use
	 * {@see self::is_ignored()} to skip ignored URLs.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private function lm_records_for_post( $post ) {
		return $this->lm_extract_links( $post->post_content, (int) $post->ID );
	}

	/**
	 * Whether the initial analysis has completed (memoized per request).
	 *
	 * @return bool
	 */
	private function lm_is_analyzed() {
		if ( null === $this->lm_analyzed ) {
			$this->lm_analyzed = ( '1' === get_option( self::ANALYZED_OPTION ) );
		}
		return $this->lm_analyzed;
	}

	/**
	 * Re-index a single post. No-op until the initial analysis has run. Posts
	 * that have left an indexable status (trash, auto-draft) have their
	 * occurrences removed instead.
	 *
	 * Hooked on wp_after_insert_post and untrashed_post.
	 *
	 * @param int $post_id Post ID.
	 */
	public function lm_index_post( $post_id ) {
		if ( ! $this->lm_is_analyzed() ) {
			return;
		}
		$post_id = (int) $post_id;
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}

		$indexable = array( 'publish', 'draft', 'pending', 'private', 'future' );
		if ( ! in_array( $post->post_status, $indexable, true ) ) {
			$this->lm_delete_post_occurrences( $post_id );
			return;
		}

		$records = $this->lm_records_for_post( $post );
		$this->lm_set_occurrences( $post_id, $records );

		// Ignored links are stored but never response-checked.
		$hashes = array();
		foreach ( $records as $r ) {
			if ( ! $this->is_ignored( $r['url'] ) ) {
				$hashes[] = sha1( $r['url'] );
			}
		}
		$this->lm_enqueue_recheck( $hashes );
	}

	/**
	 * Remove a post's occurrences (trash / permanent delete).
	 *
	 * Hooked on wp_trash_post and before_delete_post.
	 *
	 * @param int $post_id Post ID.
	 */
	public function lm_on_remove( $post_id ) {
		$this->lm_delete_post_occurrences( (int) $post_id );
	}

	/**
	 * Queue url_hashes for a background response re-check and make sure a drain
	 * tick is scheduled.
	 *
	 * @param string[] $hashes url_hash values.
	 */
	private function lm_enqueue_recheck( $hashes ) {
		$hashes = array_values( array_unique( array_filter( (array) $hashes ) ) );
		if ( empty( $hashes ) ) {
			return;
		}
		$queue = get_option( self::RECHECK_QUEUE_OPTION, array() );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}
		$queue = array_values( array_unique( array_merge( $queue, $hashes ) ) );
		update_option( self::RECHECK_QUEUE_OPTION, $queue, false );

		if ( ! wp_next_scheduled( self::RECHECK_HOOK ) ) {
			wp_schedule_single_event( time() + 2, self::RECHECK_HOOK );
		}
	}

	/**
	 * Drain a batch of the re-check queue: look up the URLs, check them, and
	 * write the responses back. Reschedules itself while work remains.
	 *
	 * Hooked on self::RECHECK_HOOK (WP-Cron single events).
	 */
	public function lm_drain_recheck() {
		$queue = get_option( self::RECHECK_QUEUE_OPTION, array() );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return;
		}
		$batch = array_splice( $queue, 0, 50 );
		update_option( self::RECHECK_QUEUE_OPTION, $queue, false );

		$urls = $this->lm_urls_for_hashes( $batch );
		if ( ! empty( $urls ) ) {
			$checked = $this->check_urls( array_values( $urls ) );
			foreach ( $urls as $hash => $url ) {
				if ( isset( $checked[ $url ] ) ) {
					$this->lm_write_response( $hash, $checked[ $url ] );
				}
			}
		}

		if ( ! empty( $queue ) ) {
			wp_schedule_single_event( time() + 2, self::RECHECK_HOOK );
		}
	}

	/* ---------------------------------------------------------------------
	 * Inventory queries (read side) + row actions
	 * ------------------------------------------------------------------- */
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

	/**
	 * Fetch a single link row as an associative array (null if missing).
	 *
	 * @param int $link_id Link id.
	 * @return array|null
	 */
	private function lm_get_link( $link_id ) {
		global $wpdb;
		$links = self::lm_links_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $links WHERE id = %d", (int) $link_id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Distinct post ids that contain a link.
	 *
	 * @param int $link_id Link id.
	 * @return int[]
	 */
	private function lm_post_ids_for_link( $link_id ) {
		global $wpdb;
		$occ = self::lm_occurrences_table();
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM $occ WHERE link_id = %d", (int) $link_id ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Occurrence rows for a link, ordered by post then document position.
	 *
	 * @param int $link_id Link id.
	 * @return array
	 */
	private function lm_occurrences_for_link( $link_id ) {
		global $wpdb;
		$occ = self::lm_occurrences_table();
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT id, post_id, instance, anchor, rel FROM $occ WHERE link_id = %d ORDER BY post_id ASC, instance ASC", (int) $link_id ),
			ARRAY_A
		);
	}

	/**
	 * Per-link Posts/Pages counts for a set of link ids, in one grouped query.
	 *
	 * @param int[] $link_ids Link ids.
	 * @return array<int,array{post:int,page:int}>
	 */
	private function lm_link_counts( $link_ids ) {
		global $wpdb;
		$link_ids = array_filter( array_map( 'intval', (array) $link_ids ) );
		if ( empty( $link_ids ) ) {
			return array();
		}
		$occ   = self::lm_occurrences_table();
		$posts = $wpdb->posts;
		$in    = implode( ',', $link_ids );
		$rows  = $wpdb->get_results(
			"SELECT o.link_id AS lid, p.post_type AS pt, COUNT(DISTINCT o.post_id) AS n
			 FROM $occ o JOIN $posts p ON p.ID = o.post_id
			 WHERE o.link_id IN ($in) AND p.post_type IN ('post','page')
			   AND p.post_status IN ('publish','draft','pending','private','future')
			 GROUP BY o.link_id, p.post_type",
			ARRAY_A
		);
		$map   = array();
		foreach ( (array) $rows as $row ) {
			$lid = (int) $row['lid'];
			if ( ! isset( $map[ $lid ] ) ) {
				$map[ $lid ] = array(
					'post' => 0,
					'page' => 0,
				);
			}
			$map[ $lid ][ $row['pt'] ] = (int) $row['n'];
		}
		return $map;
	}

	/**
	 * Build the full inventory payload for the All Links table (drawn
	 * client-side, mirroring the original results-array approach).
	 *
	 * @return array[]
	 */
	private function lm_links_payload() {
		global $wpdb;
		$links  = self::lm_links_table();
		$rows   = $wpdb->get_results(
			"SELECT id, url, type, response_code, response_short, response_label, is_redirect, redirect_code, final_url, last_checked
			 FROM $links ORDER BY id ASC",
			ARRAY_A
		);
		$counts = $this->lm_link_counts( wp_list_pluck( (array) $rows, 'id' ) );

		$out = array();
		foreach ( (array) $rows as $r ) {
			$id    = (int) $r['id'];
			$count = isset( $counts[ $id ] ) ? $counts[ $id ] : array(
				'post' => 0,
				'page' => 0,
			);
			$out[] = array(
				'id'           => $id,
				'url'          => $r['url'],
				'type'         => $r['type'],
				'code'         => (int) $r['response_code'],
				'short'        => '' !== $r['response_short'] ? $r['response_short'] : (string) (int) $r['response_code'],
				'label'        => $r['response_label'],
				'redirect'     => (bool) (int) $r['is_redirect'],
				'blocked'      => $this->is_blocked( (int) $r['response_code'] ),
				'redirectCode' => (int) $r['redirect_code'],
				'finalUrl'     => (string) $r['final_url'],
				'checked'      => ! empty( $r['last_checked'] ),
				'ignored'      => $this->is_ignored( $r['url'] ),
				'posts'        => (int) $count['post'],
				'pages'        => (int) $count['page'],
				'editUrl'      => admin_url( 'admin.php?page=' . self::EDIT_SLUG . '&link_id=' . $id ),
				'postsUrl'     => $count['post'] ? admin_url( 'edit.php?post_type=post&coywolf_seo_lm_link=' . $id ) : '',
				'pagesUrl'     => $count['page'] ? admin_url( 'edit.php?post_type=page&coywolf_seo_lm_link=' . $id ) : '',
			);
		}
		return $out;
	}

	/**
	 * Drop any pending response re-checks for links that now match an ignore
	 * rule. Ignored links are kept in the inventory (so the "Ignored" view can
	 * list them) but are never response-checked, so a queued check would be
	 * wasted. Called after new ignore rules are added.
	 */
	private function lm_dequeue_ignored() {
		$queue = get_option( self::RECHECK_QUEUE_OPTION, array() );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return;
		}
		$urls = $this->lm_urls_for_hashes( $queue );
		$kept = array();
		foreach ( $queue as $hash ) {
			$url = isset( $urls[ $hash ] ) ? $urls[ $hash ] : '';
			if ( '' === $url || ! $this->is_ignored( $url ) ) {
				$kept[] = $hash;
			}
		}
		if ( count( $kept ) !== count( $queue ) ) {
			update_option( self::RECHECK_QUEUE_OPTION, array_values( $kept ), false );
		}
	}

	/**
	 * Stop ignoring the given links: remove every ignore rule that matches any
	 * of their URLs, then queue the now-visible links for a response check.
	 *
	 * Removing a domain or wildcard rule also un-ignores its other matches, so
	 * after pruning the rules this re-checks every inventory link that is no
	 * longer ignored yet still lacks a response.
	 *
	 * @param int[] $link_ids Inventory link ids.
	 */
	private function lm_unignore_links( $link_ids ) {
		global $wpdb;
		$link_ids = array_filter( array_map( 'intval', (array) $link_ids ) );
		if ( empty( $link_ids ) ) {
			return;
		}
		$links = self::lm_links_table();
		$in    = implode( ',', $link_ids );
		$urls  = $wpdb->get_col( "SELECT url FROM $links WHERE id IN ($in)" );

		// Collect the rule keys responsible for hiding these links.
		$drop = array();
		foreach ( (array) $urls as $url ) {
			foreach ( $this->matching_ignore_keys( $url ) as $key ) {
				$drop[ $key ] = true;
			}
		}
		if ( empty( $drop ) ) {
			return;
		}

		$ignores = $this->get_ignores();
		foreach ( array_keys( $drop ) as $key ) {
			unset( $ignores[ $key ] );
		}
		$this->save_ignores( $ignores );

		// Re-check every link that is now visible again but has no response yet.
		$rows   = $wpdb->get_results( "SELECT url, last_checked FROM $links", ARRAY_A );
		$hashes = array();
		foreach ( (array) $rows as $r ) {
			if ( empty( $r['last_checked'] ) && ! $this->is_ignored( $r['url'] ) ) {
				$hashes[] = sha1( $r['url'] );
			}
		}
		$this->lm_enqueue_recheck( $hashes );
	}

	// phpcs:enable

	/**
	 * AJAX: return the inventory for the All Links table.
	 */
	public function ajax_lm_links() {
		$this->authorise_operator();
		wp_send_json_success(
			array(
				'analyzed' => $this->lm_is_analyzed(),
				'links'    => $this->lm_links_payload(),
			)
		);
	}

	/**
	 * AJAX: remove a link from every post and page it appears on.
	 */
	public function ajax_lm_remove_link() {
		$this->authorise_operator();
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce + capability verified in authorise_operator().
		if ( ! $link_id ) {
			wp_send_json_error( array( 'message' => __( 'Link not found.', 'coywolf-seo' ) ), 404 );
		}
		$this->lm_mutate_link_everywhere( $link_id, 'remove', '' );
		wp_send_json_success();
	}

	/**
	 * AJAX: remove several links (by id) from every post and page they appear on.
	 */
	public function ajax_lm_remove_links_bulk() {
		$this->authorise_operator();
		$raw = isset( $_POST['link_ids'] ) ? wp_unslash( $_POST['link_ids'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- JSON of ints validated per element below; nonce + capability verified in authorise_operator().
		$ids = json_decode( (string) $raw, true );
		if ( ! is_array( $ids ) ) {
			$ids = array();
		}
		$removed = 0;
		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( $id ) {
				$this->lm_mutate_link_everywhere( $id, 'remove', '' );
				++$removed;
			}
		}
		wp_send_json_success( array( 'removed' => $removed ) );
	}

	/**
	 * AJAX: replace several redirected links (by id) with their final
	 * destination on every post and page. Non-redirecting ids are skipped here
	 * as a safeguard; the UI blocks the action when any non-redirect is selected.
	 */
	public function ajax_lm_replace_links_bulk() {
		$this->authorise_operator();
		$raw = isset( $_POST['link_ids'] ) ? wp_unslash( $_POST['link_ids'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- JSON of ints validated per element below; nonce + capability verified in authorise_operator().
		$ids = json_decode( (string) $raw, true );
		if ( ! is_array( $ids ) ) {
			$ids = array();
		}
		$replaced = 0;
		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( ! $id ) {
				continue;
			}
			$link = $this->lm_get_link( $id );
			if ( $link && ! empty( $link['is_redirect'] ) && '' !== (string) $link['final_url'] ) {
				$this->lm_mutate_link_everywhere( $id, 'replace', (string) $link['final_url'] );
				++$replaced;
			}
		}
		wp_send_json_success( array( 'replaced' => $replaced ) );
	}

	/**
	 * AJAX: replace a redirected link with its final destination everywhere.
	 */
	public function ajax_lm_replace_link() {
		$this->authorise_operator();
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce + capability verified in authorise_operator().
		$link    = $this->lm_get_link( $link_id );
		if ( ! $link ) {
			wp_send_json_error( array( 'message' => __( 'Link not found.', 'coywolf-seo' ) ), 404 );
		}
		$final = (string) $link['final_url'];
		if ( empty( $link['is_redirect'] ) || '' === $final ) {
			wp_send_json_error( array( 'message' => __( 'This link has no redirect destination to replace.', 'coywolf-seo' ) ), 400 );
		}
		$this->lm_mutate_link_everywhere( $link_id, 'replace', $final );
		wp_send_json_success();
	}

	/**
	 * Apply a remove/replace to a link across every post that contains it. The
	 * wp_after_insert_post hook re-indexes each touched post afterwards, so the
	 * inventory tables update themselves.
	 *
	 * @param int    $link_id Link id.
	 * @param string $mode    'remove' | 'replace'.
	 * @param string $arg     Replacement URL for 'replace'.
	 */
	private function lm_mutate_link_everywhere( $link_id, $mode, $arg ) {
		$link = $this->lm_get_link( $link_id );
		if ( ! $link ) {
			return;
		}
		$url = $link['url'];
		foreach ( $this->lm_post_ids_for_link( $link_id ) as $pid ) {
			if ( ! current_user_can( 'edit_post', $pid ) ) {
				continue;
			}
			$post = get_post( $pid );
			if ( ! $post ) {
				continue;
			}
			$count = 0;
			$base  = $this->lm_post_base( $pid );
			if ( 'remove' === $mode ) {
				$content = $this->remove_link_url( $post->post_content, $url, $count, $base );
			} else {
				$content = $this->replace_link_url( $post->post_content, $url, $arg, $count, $base );
			}
			if ( $count > 0 ) {
				$this->lm_save_post_content( $pid, $content );
			}
		}
	}

	/**
	 * Save edited post content from a Link Manager mutation, preserving markup
	 * the edit never touched.
	 *
	 * Two reasons this can't be a bare wp_update_post():
	 *
	 * 1. kses. wp_update_post() runs the whole post through kses on save when
	 *    the acting user lacks the `unfiltered_html` capability — any
	 *    Editor/Author on single-site, any non-super-admin on multisite. kses
	 *    would delete tags off the post allow-list (e.g. an <iframe> in a
	 *    Custom HTML block, taking its wp:html wrapper with it) and normalize
	 *    inline styles, corrupting content the link edit didn't change. A link
	 *    edit only rewrites anchors the plugin builds over the post's already-
	 *    trusted stored markup, so suspend kses for the save and restore it.
	 *
	 * 2. Slashing. wp_insert_post() wp_unslash()es its input, so content must
	 *    be passed slashed; otherwise a literal backslash in the post (a path,
	 *    a regex in a code block) loses a slash on every save.
	 *
	 * @param int    $pid     Post ID.
	 * @param string $content New post content (unslashed).
	 */
	private function lm_save_post_content( $pid, $content ) {
		$kses_active = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses_active ) {
			kses_remove_filters();
		}
		wp_update_post(
			array(
				'ID'           => (int) $pid,
				'post_content' => wp_slash( $content ),
			),
			true
		);
		if ( $kses_active ) {
			kses_init_filters();
		}
	}

	/**
	 * Restrict the Posts/Pages list table to the items containing a given link
	 * when the All Links count link is followed (edit.php?...&coywolf_seo_lm_link=N).
	 *
	 * @param WP_Query $query The query being prepared.
	 */
	public function lm_filter_post_list( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		global $pagenow;
		if ( 'edit.php' !== $pagenow ) {
			return;
		}
		$link_id = isset( $_GET['coywolf_seo_lm_link'] ) ? absint( $_GET['coywolf_seo_lm_link'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter, no state change.
		if ( ! $link_id ) {
			return;
		}
		$ids = $this->lm_post_ids_for_link( $link_id );
		$query->set( 'post__in', ! empty( $ids ) ? $ids : array( 0 ) );
	}

	/* ---------------------------------------------------------------------
	 * Edit Link page
	 * ------------------------------------------------------------------- */

	/**
	 * Render the hidden Edit Link screen.
	 */
	public function render_edit_link_page() {
		if ( ! current_user_can( Coywolf_SEO_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to edit links.', 'coywolf-seo' ) );
		}
		$link_id = isset( $_GET['link_id'] ) ? absint( $_GET['link_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page load; the form below carries its own nonce.
		$link    = $this->lm_get_link( $link_id );

		echo '<div class="wrap coywolf-seo-lm-edit">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Edit Link', 'coywolf-seo' ) . '</h1>';
		echo '<hr class="wp-header-end" />';

		if ( ! $link ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'That link was not found. It may have been removed.', 'coywolf-seo' ) . '</p></div></div>';
			return;
		}

		$occurrences = $this->lm_occurrences_for_link( $link_id );

		// Normalise rel (union) across occurrences.
		$rel_union = array();
		foreach ( $occurrences as $occ ) {
			foreach ( preg_split( '/\s+/', (string) $occ['rel'] ) as $tok ) {
				$tok = strtolower( trim( $tok ) );
				if ( '' !== $tok ) {
					$rel_union[ $tok ] = true;
				}
			}
		}

		$managed = $this->lm_managed_rel();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="coywolf-seo-lm-edit-form">
			<input type="hidden" name="action" value="coywolf_seo_lm_save_edit" />
			<input type="hidden" name="link_id" value="<?php echo esc_attr( $link_id ); ?>" />
			<?php wp_nonce_field( 'coywolf_seo_lm_save_edit_' . $link_id ); ?>

			<div class="coywolf-seo-panel">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="coywolf-seo-lm-edit-url"><?php esc_html_e( 'URL', 'coywolf-seo' ); ?></label></th>
					<td>
						<input name="url" id="coywolf-seo-lm-edit-url" type="url" class="large-text code" value="<?php echo esc_attr( $link['url'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Changing the URL updates it on every post and page this link appears on.', 'coywolf-seo' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Relationship', 'coywolf-seo' ); ?></th>
					<td>
						<fieldset class="coywolf-seo-lm-rel">
							<?php foreach ( $managed as $tok ) : ?>
								<label><input type="checkbox" name="rel[]" value="<?php echo esc_attr( $tok ); ?>" <?php checked( isset( $rel_union[ $tok ] ) ); ?> /> <code><?php echo esc_html( $tok ); ?></code></label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'The checked set is applied to the link on every post and page it appears on.', 'coywolf-seo' ); ?></p>
					</td>
				</tr>
			</table>

			<table class="form-table" role="presentation">
			<tr>
			<th scope="row"><?php esc_html_e( 'Occurrences', 'coywolf-seo' ); ?></th>
			<td>
			<table class="wp-list-table widefat striped coywolf-seo-lm-occ">
				<thead>
					<tr>
						<th class="column-anchor"><?php esc_html_e( 'Anchor text', 'coywolf-seo' ); ?></th>
						<th class="column-post"><?php esc_html_e( 'Post/Page', 'coywolf-seo' ); ?></th>
						<th class="column-remove"><?php esc_html_e( 'Remove', 'coywolf-seo' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $occurrences ) ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'This link has no recorded occurrences.', 'coywolf-seo' ); ?></td></tr>
				<?php else : ?>
					<?php
					foreach ( $occurrences as $occ ) :
						$oid       = (int) $occ['id'];
						$pid       = (int) $occ['post_id'];
						$is_image  = ( 0 === strpos( (string) $occ['anchor'], '[image' ) );
						$title     = get_the_title( $pid );
						$title     = '' !== $title ? $title : __( '(no title)', 'coywolf-seo' );
						$edit_link = get_edit_post_link( $pid );
						$view_link = get_permalink( $pid );
						?>
						<tr>
							<td class="column-anchor">
								<input type="text" class="regular-text" name="anchor[<?php echo esc_attr( $oid ); ?>]" value="<?php echo esc_attr( $occ['anchor'] ); ?>" <?php disabled( $is_image ); ?> />
							</td>
							<td class="column-post">
								<?php if ( $edit_link ) : ?>
									<strong><a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( $title ); ?></a></strong>
								<?php else : ?>
									<strong><?php echo esc_html( $title ); ?></strong>
								<?php endif; ?>
								<?php if ( $edit_link || $view_link ) : ?>
									<div class="row-actions">
										<?php
										if ( $edit_link ) :
											?>
											<span class="edit"><a href="<?php echo esc_url( $edit_link ); ?>"><?php esc_html_e( 'Edit', 'coywolf-seo' ); ?></a></span><?php endif; ?>
										<?php
										if ( $edit_link && $view_link ) :
											?>
											| <?php endif; ?>
										<?php
										if ( $view_link ) :
											?>
											<span class="view"><a href="<?php echo esc_url( $view_link ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'coywolf-seo' ); ?></a></span><?php endif; ?>
									</div>
								<?php endif; ?>
							</td>
							<td class="column-remove"><input type="checkbox" name="remove[<?php echo esc_attr( $oid ); ?>]" value="1" /></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
			</td>
			</tr>
			</table>
			</div>

			<p class="submit coywolf-seo-lm-edit-actions">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save link', 'coywolf-seo' ); ?></button>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'coywolf-seo' ); ?></a>
				<button type="button" id="coywolf-seo-lm-edit-remove" class="button coywolf-seo-lm-danger coywolf-seo-lm-edit-remove"><?php esc_html_e( 'Remove', 'coywolf-seo' ); ?></button>
			</p>

			<div id="coywolf-seo-lm-edit-confirm" class="coywolf-seo-lm-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="coywolf-seo-lm-edit-confirm-title">
				<div class="coywolf-seo-lm-modal-backdrop" data-close="1"></div>
				<div class="coywolf-seo-lm-modal-box" role="document">
					<h2 id="coywolf-seo-lm-edit-confirm-title"><?php esc_html_e( 'Remove link', 'coywolf-seo' ); ?></h2>
					<p><?php esc_html_e( 'This link will be removed from every post and page it appears on. The link text is kept.', 'coywolf-seo' ); ?></p>
					<div class="coywolf-seo-lm-modal-actions">
						<button type="button" class="button" id="coywolf-seo-lm-edit-confirm-cancel"><?php esc_html_e( 'Cancel', 'coywolf-seo' ); ?></button>
						<button type="submit" name="coywolf_seo_lm_remove" value="1" class="button coywolf-seo-lm-danger"><?php esc_html_e( 'Remove', 'coywolf-seo' ); ?></button>
					</div>
				</div>
			</div>
		</form>
		</div>
		<?php
	}

	/**
	 * Handle the Edit Link form submission (admin-post).
	 *
	 * Applies the URL / rel edits to every occurrence and the
	 * per-occurrence anchor-text changes and removals, then lets the save hook
	 * re-index each touched post.
	 */
	public function lm_save_edit() {
		if ( ! current_user_can( Coywolf_SEO_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to edit links.', 'coywolf-seo' ) );
		}
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- link_id forms part of the nonce action verified on the next line.
		check_admin_referer( 'coywolf_seo_lm_save_edit_' . $link_id );

		$link = $this->lm_get_link( $link_id );
		if ( ! $link ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
			exit;
		}
		$old_url = $link['url'];

		// "Remove" button: unwrap the link everywhere, then return to All Links.
		if ( ! empty( $_POST['coywolf_seo_lm_remove'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_admin_referer().
			$this->lm_mutate_link_everywhere( $link_id, 'remove', '' );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
			exit;
		}

		$new_url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ), array( 'http', 'https' ) ) : '';
		if ( '' === $new_url ) {
			$new_url = $old_url;
		}

		$managed = $this->lm_managed_rel();
		$rel_in  = isset( $_POST['rel'] ) && is_array( $_POST['rel'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['rel'] ) )
			: array();
		$rel     = array_values( array_intersect( $managed, array_map( 'strtolower', $rel_in ) ) );

		$anchor_in = isset( $_POST['anchor'] ) ? (array) wp_unslash( $_POST['anchor'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per-value below.
		$remove_in = isset( $_POST['remove'] ) ? (array) wp_unslash( $_POST['remove'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Booleans read by key below.

		// Map occurrence id -> (post_id, instance) and build per-post specs.
		$by_post = array();
		foreach ( $this->lm_occurrences_for_link( $link_id ) as $o ) {
			$oid                      = (int) $o['id'];
			$pid                      = (int) $o['post_id'];
			$inst                     = (int) $o['instance'];
			$entry                    = array(
				'remove' => ! empty( $remove_in[ $oid ] ),
				'anchor' => isset( $anchor_in[ $oid ] ) ? sanitize_text_field( $anchor_in[ $oid ] ) : null,
			);
			$by_post[ $pid ][ $inst ] = $entry;
		}

		foreach ( $this->lm_post_ids_for_link( $link_id ) as $pid ) {
			if ( ! current_user_can( 'edit_post', $pid ) ) {
				continue;
			}
			$post = get_post( $pid );
			if ( ! $post ) {
				continue;
			}
			$spec = array(
				'new_url'   => $new_url,
				'rel'       => $rel,
				'instances' => isset( $by_post[ $pid ] ) ? $by_post[ $pid ] : array(),
			);

			list( $content, $stats ) = $this->lm_apply_link_edit( $post->post_content, $old_url, $spec, $this->lm_post_base( $pid ) );
			if ( $stats['changed'] > 0 || $stats['removed'] > 0 ) {
				$this->lm_save_post_content( $pid, $content );
			}
		}

		// Always return to All Links after a save.
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&updated=1' ) );
		exit;
	}
}
