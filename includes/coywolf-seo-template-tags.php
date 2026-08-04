<?php
/**
 * Public template tags for Coywolf SEO.
 *
 * Global functions themes may call directly. Kept out of the class files so
 * each of those stays a single object per file, and guarded with
 * function_exists() so a theme or another plugin can override them.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'coywolf_seo_breadcrumbs' ) ) {
	/**
	 * Echo the breadcrumb trail. Template tag for classic themes:
	 * <?php if ( function_exists( 'coywolf_seo_breadcrumbs' ) ) coywolf_seo_breadcrumbs(); ?>
	 *
	 * @param array $args Optional overrides (see Coywolf_SEO_Breadcrumbs::render).
	 * @return void
	 */
	function coywolf_seo_breadcrumbs( $args = array() ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escapes every dynamic value it emits.
		echo coywolf_seo_get_breadcrumbs( $args );
	}
}

if ( ! function_exists( 'coywolf_seo_get_breadcrumbs' ) ) {
	/**
	 * Return the breadcrumb trail HTML (empty string when there is nothing to
	 * show or the feature is off).
	 *
	 * @param array $args Optional overrides (see Coywolf_SEO_Breadcrumbs::render).
	 * @return string
	 */
	function coywolf_seo_get_breadcrumbs( $args = array() ) {
		if ( ! class_exists( 'Coywolf_SEO_Breadcrumbs' ) ) {
			return '';
		}
		return Coywolf_SEO_Breadcrumbs::render( is_array( $args ) ? $args : array() );
	}
}
