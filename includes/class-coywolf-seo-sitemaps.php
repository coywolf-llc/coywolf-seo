<?php
/**
 * Native XML sitemap exclusions.
 *
 * WordPress serves /wp-sitemap.xml with posts, pages, taxonomies, and
 * users. These options switch off the parts a site doesn't want listed —
 * via core's own filters, so anything not excluded behaves exactly as
 * stock WordPress.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core sitemap exclusion filters.
 */
final class Coywolf_SEO_Sitemaps {

	/**
	 * Hook everything up.
	 */
	public function init() {
		add_filter( 'wp_sitemaps_post_types', array( $this, 'filter_post_types' ) );
		add_filter( 'wp_sitemaps_taxonomies', array( $this, 'filter_taxonomies' ) );
		add_filter( 'wp_sitemaps_add_provider', array( $this, 'filter_providers' ), 10, 2 );
	}

	/**
	 * Drop excluded post types from the core sitemap.
	 *
	 * @param array $post_types Post type objects, keyed by name.
	 * @return array
	 */
	public function filter_post_types( $post_types ) {
		if ( ! Coywolf_SEO_Options::feature_enabled( 'sitemaps' ) ) {
			return $post_types;
		}
		if ( Coywolf_SEO_Options::get( 'sitemap_exclude_posts' ) ) {
			unset( $post_types['post'] );
		}
		if ( Coywolf_SEO_Options::get( 'sitemap_exclude_pages' ) ) {
			unset( $post_types['page'] );
		}
		return $post_types;
	}

	/**
	 * Drop excluded taxonomies from the core sitemap.
	 *
	 * @param array $taxonomies Taxonomy objects, keyed by name.
	 * @return array
	 */
	public function filter_taxonomies( $taxonomies ) {
		if ( ! Coywolf_SEO_Options::feature_enabled( 'sitemaps' ) ) {
			return $taxonomies;
		}
		if ( Coywolf_SEO_Options::get( 'sitemap_exclude_categories' ) ) {
			unset( $taxonomies['category'] );
		}
		return $taxonomies;
	}

	/**
	 * Drop excluded whole providers from the core sitemap.
	 *
	 * @param WP_Sitemaps_Provider|false $provider Provider.
	 * @param string                     $name     Provider name.
	 * @return WP_Sitemaps_Provider|false
	 */
	public function filter_providers( $provider, $name ) {
		if ( ! Coywolf_SEO_Options::feature_enabled( 'sitemaps' ) ) {
			return $provider;
		}
		if ( 'users' === $name && Coywolf_SEO_Options::get( 'sitemap_exclude_users' ) ) {
			return false;
		}
		return $provider;
	}
}
