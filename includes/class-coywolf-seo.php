<?php
/**
 * Main plugin loader for Coywolf SEO.
 *
 * Composition root: feature modules are instantiated and wired here as they
 * are added to the plugin, and the shared activation/deactivation lifecycle
 * lives here.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin bootstrap singleton.
 */
final class Coywolf_SEO {

	/**
	 * Plugin version. Kept in sync with the main-file "Version:" header by the
	 * release workflow (it bumps both).
	 */
	const VERSION = '1.0.79';

	/**
	 * Singleton instance.
	 *
	 * @var Coywolf_SEO|null
	 */
	private static $instance = null;

	/**
	 * Admin screens module (admin only).
	 *
	 * @var Coywolf_SEO_Admin|null
	 */
	private $admin = null;

	/**
	 * Post/Page SEO meta box and editor panel (meta registration runs on
	 * every request — the block editor saves through REST).
	 *
	 * @var Coywolf_SEO_Metabox|null
	 */
	private $metabox = null;

	/**
	 * Document titles module.
	 *
	 * @var Coywolf_SEO_Titles
	 */
	private $titles;

	/**
	 * Head output module (meta description, robots, canonical).
	 *
	 * @var Coywolf_SEO_Head
	 */
	private $head;

	/**
	 * Schema markup module.
	 *
	 * @var Coywolf_SEO_Schema
	 */
	private $schema;

	/**
	 * Open Graph module.
	 *
	 * @var Coywolf_SEO_OpenGraph
	 */
	private $opengraph;

	/**
	 * Authors page module.
	 *
	 * @var Coywolf_SEO_Authors
	 */
	private $authors;

	/**
	 * Category/tag term fields module.
	 *
	 * @var Coywolf_SEO_Terms
	 */
	private $terms;

	/**
	 * Other-SEO-plugin suppression module.
	 *
	 * @var Coywolf_SEO_Compat
	 */
	private $compat;

	/**
	 * Category prefix removal module.
	 *
	 * @var Coywolf_SEO_Category_Base
	 */
	private $category_base;

	/**
	 * IndexNow module.
	 *
	 * @var Coywolf_SEO_IndexNow
	 */
	private $indexnow;

	/**
	 * News sitemap module.
	 *
	 * @var Coywolf_SEO_News_Sitemap
	 */
	private $news_sitemap;

	/**
	 * Native sitemap exclusions module.
	 *
	 * @var Coywolf_SEO_Sitemaps
	 */
	private $sitemaps;

	/**
	 * AI schema enrichment module.
	 *
	 * @var Coywolf_SEO_AI
	 */
	private $ai;

	/**
	 * Import/Export module.
	 *
	 * @var Coywolf_SEO_Import_Export
	 */
	private $import_export;

	/**
	 * Redirect manager engine.
	 *
	 * @var Coywolf_SEO_Redirects
	 */
	private $redirects;

	/**
	 * Table of Contents block module.
	 *
	 * @var Coywolf_SEO_TOC
	 */
	private $toc;

	/**
	 * Core Image block mobile-alternative module.
	 *
	 * @var Coywolf_SEO_Mobile_Image
	 */
	private $mobile_image;

	/**
	 * Duplicate Post row action (admin only).
	 *
	 * @var Coywolf_SEO_Duplicate|null
	 */
	private $duplicate = null;

	/**
	 * Redirects admin screen (admin only).
	 *
	 * @var Coywolf_SEO_Redirects_Admin|null
	 */
	private $redirects_admin = null;

	/**
	 * Redirect importers (admin only).
	 *
	 * @var Coywolf_SEO_Redirects_Import|null
	 */
	private $redirects_import = null;

	/**
	 * Image Text module (admin page, REST endpoints, block-editor panel).
	 *
	 * @var Coywolf_SEO_Image_Text
	 */
	private $image_text;

	/**
	 * Attachment-ID fixer (Settings-page tool).
	 *
	 * @var Coywolf_SEO_Image_ID_Fixer
	 */
	private $image_id_fixer;

	/**
	 * Link Manager module (admin page, AJAX endpoints, event-driven indexing,
	 * background re-check cron). Constructed unconditionally so its non-admin
	 * hooks (AJAX worker, cron, indexing) are available; init() no-ops its
	 * runtime hooks when the Link Manager feature is turned off.
	 *
	 * @var Coywolf_SEO_Link_Manager
	 */
	private $link_manager;

	/**
	 * Robots.txt Manager (rules table, virtual/physical robots.txt). Like the
	 * Link Manager it also runs on non-admin requests (the robots_txt filter),
	 * and init() no-ops every hook when the Robots.txt feature is turned off.
	 *
	 * @var Coywolf_SEO_Robots
	 */
	private $robots;

	/**
	 * Create (once) and return the plugin instance.
	 *
	 * @return Coywolf_SEO
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Instantiate and wire feature modules.
	 */
	private function __construct() {
		$this->titles = new Coywolf_SEO_Titles();
		$this->titles->init();

		$this->head = new Coywolf_SEO_Head();
		$this->head->init();

		$this->schema = new Coywolf_SEO_Schema();
		$this->schema->init();

		$this->opengraph = new Coywolf_SEO_OpenGraph();
		$this->opengraph->init();

		$this->authors = new Coywolf_SEO_Authors();
		$this->authors->init();

		$this->terms = new Coywolf_SEO_Terms();
		$this->terms->init();

		$this->compat = new Coywolf_SEO_Compat();
		$this->compat->init();

		$this->category_base = new Coywolf_SEO_Category_Base();
		$this->category_base->init();

		$this->indexnow = new Coywolf_SEO_IndexNow();
		$this->indexnow->init();

		$this->news_sitemap = new Coywolf_SEO_News_Sitemap();
		$this->news_sitemap->init();

		$this->sitemaps = new Coywolf_SEO_Sitemaps();
		$this->sitemaps->init();

		$this->ai = new Coywolf_SEO_AI();
		$this->ai->init();

		$this->import_export = new Coywolf_SEO_Import_Export();
		$this->import_export->init();

		// The object is always constructed so accessors (e.g. pending_count())
		// never fatal, but the redirect-serving hooks register only while the
		// Redirects feature is on — turning it off lets another redirect plugin
		// handle the requests. The stored rules are kept either way.
		$this->redirects = new Coywolf_SEO_Redirects();
		if ( Coywolf_SEO_Options::feature_enabled( 'redirects' ) ) {
			$this->redirects->init();
		}

		$this->toc = new Coywolf_SEO_TOC();
		$this->toc->init();

		$this->mobile_image = new Coywolf_SEO_Mobile_Image();
		$this->mobile_image->init();

		// Not admin-gated: the block editor reads and saves the SEO meta
		// through the REST API, where is_admin() is false — the meta must
		// be registered on every request.
		$this->metabox = new Coywolf_SEO_Metabox();
		$this->metabox->init();

		// Not admin-gated: the per-image generate endpoint and the block-editor
		// panel run through the REST API (where is_admin() is false), so the
		// routes and editor integration must register on every request.
		$this->image_text = new Coywolf_SEO_Image_Text();
		$this->image_text->init();

		// Attachment-ID fixer: an admin-ajax maintenance tool on the Settings page.
		$this->image_id_fixer = new Coywolf_SEO_Image_ID_Fixer();
		$this->image_id_fixer->init();

		// Not admin-gated: the Link Manager runs AJAX workers, WP-Cron re-checks,
		// and event-driven indexing on non-admin requests too. init() no-ops its
		// runtime hooks when the Link Manager feature is turned off.
		$this->link_manager = new Coywolf_SEO_Link_Manager();
		$this->link_manager->init();

		// Not admin-gated: the Robots.txt Manager filters robots_txt on the front
		// end (virtual mode). init() no-ops every hook when the feature is off.
		$this->robots = Coywolf_SEO_Robots::instance();
		$this->robots->init();

		if ( is_admin() ) {
			$this->admin = new Coywolf_SEO_Admin();
			$this->admin->init();

			$this->duplicate = new Coywolf_SEO_Duplicate();
			$this->duplicate->init();

			$this->redirects_admin = new Coywolf_SEO_Redirects_Admin( $this->redirects );
			$this->redirects_admin->init();

			$this->redirects_import = new Coywolf_SEO_Redirects_Import( $this->redirects );
			$this->redirects_import->init();
		}
	}

	/**
	 * Titles module accessor (other modules read the managed title).
	 *
	 * @return Coywolf_SEO_Titles
	 */
	public function titles() {
		return $this->titles;
	}

	/**
	 * Head module accessor (other modules read the description/canonical).
	 *
	 * @return Coywolf_SEO_Head
	 */
	public function head() {
		return $this->head;
	}

	/**
	 * Authors module accessor (the Admin menu renders its page).
	 *
	 * @return Coywolf_SEO_Authors
	 */
	public function authors() {
		return $this->authors;
	}

	/**
	 * Image Text module accessor (the Admin menu renders its page).
	 *
	 * @return Coywolf_SEO_Image_Text
	 */
	public function image_text() {
		return $this->image_text;
	}

	/**
	 * Link Manager module accessor (the Admin menu renders its page).
	 *
	 * @return Coywolf_SEO_Link_Manager
	 */
	public function link_manager() {
		return $this->link_manager;
	}

	/**
	 * Robots.txt Manager accessor (the Admin menu renders its All Rules / Add
	 * Rule / Robots.txt pages; the Settings and Import/Export pages render its
	 * sections).
	 *
	 * @return Coywolf_SEO_Robots
	 */
	public function robots() {
		return $this->robots;
	}

	/**
	 * Import/Export module accessor (the Settings page renders its section).
	 *
	 * @return Coywolf_SEO_Import_Export
	 */
	public function import_export() {
		return $this->import_export;
	}

	/**
	 * AI module accessor (the editor panel shows the analysis status).
	 *
	 * @return Coywolf_SEO_AI
	 */
	public function ai() {
		return $this->ai;
	}

	/**
	 * Redirect engine accessor.
	 *
	 * @return Coywolf_SEO_Redirects
	 */
	public function redirects() {
		return $this->redirects;
	}

	/**
	 * Redirects admin accessor (the menu renders its page).
	 *
	 * @return Coywolf_SEO_Redirects_Admin|null
	 */
	public function redirects_admin() {
		return $this->redirects_admin;
	}

	/**
	 * Redirect importers accessor (the Import/Export page renders its
	 * section).
	 *
	 * @return Coywolf_SEO_Redirects_Import|null
	 */
	public function redirects_import() {
		return $this->redirects_import;
	}

	/**
	 * Activation hook: grant the admin capability per the saved setting,
	 * regenerate rewrite rules (category prefix removal adds its own), and
	 * purge known page caches so the new head output is served immediately
	 * instead of pre-activation HTML from a page or CDN cache.
	 */
	public static function on_activate() {
		Coywolf_SEO_Admin::sync_capability( (string) Coywolf_SEO_Options::get( 'access_role' ) );
		Coywolf_SEO_Redirects::install();
		Coywolf_SEO_Link_Manager::install_tables();
		// Robots.txt takeover: backs up the existing robots.txt, seeds the mode,
		// and imports current rules (no-op data-wise if already set up).
		Coywolf_SEO_Robots::on_activate();
		flush_rewrite_rules();
		self::purge_known_caches();
		// One-time reminder for caches this plugin cannot purge itself.
		set_transient( 'coywolf_seo_activation_notice', 1, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Best-effort purge of the page caches this plugin can reach. Caches it
	 * cannot reach (host/CDN edge caches) are covered by the activation
	 * notice instead.
	 */
	public static function purge_known_caches() {
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain(); // WP Rocket.
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all(); // W3 Total Cache.
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache(); // WP Super Cache.
		}
		do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- firing LiteSpeed Cache's own purge hook (no-op without it).
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache(); // SiteGround Optimizer.
		}
		if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
			autoptimizeCache::clearall();
		}
	}

	/**
	 * Deactivation hook: drop this plugin's rewrite rules and any queued
	 * AI analysis events.
	 *
	 * Capabilities are left in place on deactivate; they are removed on
	 * uninstall.
	 */
	public static function on_deactivate() {
		flush_rewrite_rules();
		wp_unschedule_hook( Coywolf_SEO_AI::CRON_HOOK );
		wp_unschedule_hook( Coywolf_SEO_AI::BULK_HOOK );
		wp_unschedule_hook( Coywolf_SEO_Image_Text::BULK_HOOK );
		// Unwrap the managed robots.txt block so the file survives as a plain,
		// unmanaged robots.txt (physical mode); virtual mode just stops serving.
		Coywolf_SEO_Robots::on_deactivate();
	}
}
