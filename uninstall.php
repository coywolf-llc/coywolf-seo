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

// Per-term SEO fields.
delete_metadata( 'term', 0, '_coywolf_seo_title', '', true );
delete_metadata( 'term', 0, '_coywolf_seo_og_image_id', '', true );

// Queued AI analysis events (plus the legacy 404-log prune, if scheduled).
wp_unschedule_hook( 'coywolf_seo_ai_analyze' );
wp_unschedule_hook( 'coywolf_seo_redirects_prune' );

// Redirect manager tables.
delete_option( 'coywolf_seo_db_version' );
delete_option( 'coywolf_seo_import_dismissed' );
delete_transient( 'coywolf_seo_ai_models' );
foreach ( array( 'coywolf_seo_redirects', 'coywolf_seo_404s', 'coywolf_seo_deleted' ) as $coywolf_seo_redirect_table ) {
	$coywolf_seo_table_name = $wpdb->prefix . $coywolf_seo_redirect_table;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from $wpdb->prefix and a literal; %i needs WP 6.2+.
	$wpdb->query( "DROP TABLE IF EXISTS `{$coywolf_seo_table_name}`" );
}

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
