<?php
/**
 * Open Graph metadata for Coywolf SEO.
 *
 * Output on the homepage, posts, pages, categories, and tags, built from
 * the same sources as the titles and descriptions: the Site Details
 * settings and each post's own data. Images come from the featured image
 * with the default Open Graph image as the fallback. No Twitter/X tags —
 * X reads Open Graph.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Open Graph tags.
 */
final class Coywolf_SEO_OpenGraph {

	/**
	 * Hook everything up.
	 */
	public function init() {
		add_action( 'wp_head', array( $this, 'output' ), 4 );
	}

	/**
	 * Print the Open Graph tags for managed contexts.
	 */
	public function output() {
		$titles = Coywolf_SEO::instance()->titles();
		$head   = Coywolf_SEO::instance()->head();

		$title = $titles->managed_title();
		if ( '' === $title ) {
			return;
		}
		// The Open Graph title follows the document title: when "Append
		// site name" is on, shares carry it too (never on the homepage).
		if ( $titles->appends_site_name() ) {
			$title .= ' ' . Coywolf_SEO_Options::separator() . ' ' . get_bloginfo( 'name' );
		}

		$singular = is_singular( array( 'post', 'page' ) ) && ! is_front_page();

		$tags = array(
			'og:site_name' => get_bloginfo( 'name' ),
			'og:locale'    => get_locale(),
			'og:type'      => $singular ? 'article' : 'website',
			'og:title'     => $title,
		);

		$desc = $head->source_description();
		if ( '' !== $desc ) {
			$tags['og:description'] = $desc;
		}

		$url = $head->canonical_url();
		if ( '' !== $url ) {
			$tags['og:url'] = $url;
		}

		foreach ( $tags as $property => $content ) {
			printf(
				'<meta property="%s" content="%s" />' . "\n",
				esc_attr( $property ),
				esc_attr( $content )
			);
		}

		$this->output_image( $singular ? get_queried_object_id() : 0 );

		if ( $singular ) {
			$post = get_queried_object();
			printf( '<meta property="article:published_time" content="%s" />' . "\n", esc_attr( get_the_date( 'c', $post ) ) );
			printf( '<meta property="article:modified_time" content="%s" />' . "\n", esc_attr( get_the_modified_date( 'c', $post ) ) );
		}
	}

	/**
	 * Print the og:image tags: the featured image when there is one, the
	 * default Open Graph image otherwise.
	 *
	 * @param int $post_id Post ID, 0 outside singular contexts.
	 */
	private function output_image( $post_id ) {
		$thumb_id = $post_id ? get_post_thumbnail_id( $post_id ) : 0;
		if ( ! $thumb_id ) {
			$thumb_id = Coywolf_SEO_Terms::og_image_id(); // Category/tag image.
		}
		if ( ! $thumb_id ) {
			$thumb_id = (int) Coywolf_SEO_Options::get( 'og_image_id' );
		}
		if ( ! $thumb_id ) {
			return;
		}
		$src = wp_get_attachment_image_src( $thumb_id, 'full' );
		if ( ! $src ) {
			return;
		}
		printf( '<meta property="og:image" content="%s" />' . "\n", esc_url( $src[0] ) );
		if ( ! empty( $src[1] ) && ! empty( $src[2] ) ) {
			printf( '<meta property="og:image:width" content="%d" />' . "\n", (int) $src[1] );
			printf( '<meta property="og:image:height" content="%d" />' . "\n", (int) $src[2] );
		}
		$alt = get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
		if ( '' !== (string) $alt ) {
			printf( '<meta property="og:image:alt" content="%s" />' . "\n", esc_attr( (string) $alt ) );
		}
	}
}
