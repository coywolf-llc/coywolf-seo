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
	const VERSION = '1.0.0';

	/**
	 * Singleton instance.
	 *
	 * @var Coywolf_SEO|null
	 */
	private static $instance = null;

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
	 *
	 * No modules yet — they are added here as the plugin grows.
	 */
	private function __construct() {
	}

	/**
	 * Activation hook.
	 *
	 * Nothing to set up yet; module activation steps are added here.
	 */
	public static function on_activate() {
	}

	/**
	 * Deactivation hook.
	 *
	 * Nothing to tear down yet; module deactivation steps are added here.
	 */
	public static function on_deactivate() {
	}
}
