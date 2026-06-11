<?php
/**
 * Uninstall cleanup for Coywolf SEO.
 *
 * Runs when the plugin is deleted from the Plugins screen. Removes everything
 * the plugin stores; as features land, their options, transients, and any
 * other persistence get cleaned up here.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Self-updater caches.
delete_site_transient( 'coywolf_seo_gh_release' );
delete_site_transient( 'coywolf_seo_gh_release_neg' );
delete_site_transient( 'coywolf_seo_gh_release_err' );
