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
	const VERSION = '1.0.34';

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

		$this->redirects = new Coywolf_SEO_Redirects();
		$this->redirects->init();

		// Not admin-gated: the block editor reads and saves the SEO meta
		// through the REST API, where is_admin() is false — the meta must
		// be registered on every request.
		$this->metabox = new Coywolf_SEO_Metabox();
		$this->metabox->init();

		if ( is_admin() ) {
			$this->admin = new Coywolf_SEO_Admin();
			$this->admin->init();

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
		do_action( 'litespeed_purge_all' ); // LiteSpeed Cache (no-op without it).
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
	}
}
