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
	const VERSION = '1.0.2';

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
	 * Post/Page SEO meta box (admin only).
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

		if ( is_admin() ) {
			$this->admin = new Coywolf_SEO_Admin();
			$this->admin->init();

			$this->metabox = new Coywolf_SEO_Metabox();
			$this->metabox->init();
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
	 * Activation hook: grant the admin capability per the saved setting.
	 */
	public static function on_activate() {
		Coywolf_SEO_Admin::sync_capability( (string) Coywolf_SEO_Options::get( 'access_role' ) );
	}

	/**
	 * Deactivation hook.
	 *
	 * Capabilities are left in place on deactivate; they are removed on
	 * uninstall.
	 */
	public static function on_deactivate() {
	}
}
