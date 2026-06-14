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
wp_unschedule_hook( 'coywolf_seo_ai_bulk' );
delete_option( 'coywolf_seo_bulk_enrich' );
delete_option( 'coywolf_seo_ai_usage' );
delete_metadata( 'post', 0, '_coywolf_seo_bulk_data', '', true );
wp_unschedule_hook( 'coywolf_seo_redirects_prune' );

// Redirect manager tables.
delete_option( 'coywolf_seo_db_version' );
delete_option( 'coywolf_seo_import_dismissed' );
// AI model-list caches: the legacy single key plus the per-service caches.
delete_transient( 'coywolf_seo_ai_models' );
foreach ( array( 'anthropic', 'openai', 'google' ) as $coywolf_seo_ai_service ) {
	delete_transient( 'coywolf_seo_ai_models_' . $coywolf_seo_ai_service );
}

// Image Text batch state + model-list cache + background worker.
delete_option( 'coywolf_seo_image_text_batch' );
wp_unschedule_hook( 'coywolf_seo_image_text_bulk' );
delete_option( 'coywolf_seo_image_id_fix' );
delete_transient( 'coywolf_seo_image_models' );
foreach ( array( 'coywolf_seo_redirects', 'coywolf_seo_404s', 'coywolf_seo_deleted' ) as $coywolf_seo_redirect_table ) {
	$coywolf_seo_table_name = $wpdb->prefix . $coywolf_seo_redirect_table;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from $wpdb->prefix and a literal; %i needs WP 6.2+.
	$wpdb->query( "DROP TABLE IF EXISTS `{$coywolf_seo_table_name}`" );
}

// Link Manager tables, options, and the background re-check cron.
foreach ( array( 'coywolf_seo_lm_links', 'coywolf_seo_lm_occurrences' ) as $coywolf_seo_lm_table ) {
	$coywolf_seo_table_name = $wpdb->prefix . $coywolf_seo_lm_table;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from $wpdb->prefix and a literal; %i needs WP 6.2+.
	$wpdb->query( "DROP TABLE IF EXISTS `{$coywolf_seo_table_name}`" );
}
delete_option( 'coywolf_seo_lm_state' );
delete_option( 'coywolf_seo_lm_ignores' );
delete_option( 'coywolf_seo_lm_url_cache' );
delete_option( 'coywolf_seo_lm_cancel' );
delete_option( 'coywolf_seo_lm_analyzed' );
delete_option( 'coywolf_seo_lm_recheck_queue' );
delete_option( 'coywolf_seo_lm_db_version' );
wp_unschedule_hook( 'coywolf_seo_lm_drain_recheck' );

// Robots.txt Manager options + legacy bot-update schedule. The robots.txt file
// itself is left in place (deactivation already unwrapped the managed block).
foreach (
	array(
		'coywolf_seo_robots_rules',
		'coywolf_seo_robots_sitemaps',
		'coywolf_seo_robots_mode',
		'coywolf_seo_robots_omit_sitemaps',
		'coywolf_seo_robots_omit_comments',
		'coywolf_seo_robots_backup',
		'coywolf_seo_robots_imported',
		'coywolf_seo_robots_wp_base_merged',
		'coywolf_seo_robots_sitemaps_migrated',
		'coywolf_seo_robots_renames_applied',
		'coywolf_seo_robots_legacy_cleared',
		'coywolf_seo_robots_update_freq',
		'coywolf_seo_robots_update_day',
		'coywolf_seo_robots_update_week',
		'coywolf_seo_robots_update_time',
		'coywolf_seo_robots_update_email',
		'coywolf_seo_robots_update_email_to',
	) as $coywolf_seo_robots_option
) {
	delete_option( $coywolf_seo_robots_option );
}
wp_unschedule_hook( 'coywolf_seo_robots_update_bots_event' );

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
