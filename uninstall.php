<?php
/**
 * Uninstall cleanup for Coywolf SEO.
 *
 * Runs when the plugin is deleted from the Plugins screen. Removes everything
 * the plugin stores: settings, per-post SEO meta, the admin capability, and
 * the self-updater caches.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Options.
delete_option( 'coywolf_seo_settings' );
delete_option( 'coywolf_seo_authors' );

// Per-post SEO meta and AI-detected entities.
delete_post_meta_by_key( '_coywolf_seo' );
delete_post_meta_by_key( '_coywolf_seo_entities' );

// Queued AI analysis events.
wp_unschedule_hook( 'coywolf_seo_ai_analyze' );

// The admin capability, from every role that has it.
foreach ( wp_roles()->role_objects as $coywolf_seo_role ) {
	if ( $coywolf_seo_role->has_cap( 'coywolf_seo_manage' ) ) {
		$coywolf_seo_role->remove_cap( 'coywolf_seo_manage' );
	}
}

// Self-updater caches.
delete_site_transient( 'coywolf_seo_gh_release' );
delete_site_transient( 'coywolf_seo_gh_release_neg' );
delete_site_transient( 'coywolf_seo_gh_release_err' );
