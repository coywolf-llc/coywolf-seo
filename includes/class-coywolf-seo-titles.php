<?php
/**
 * Document titles for Coywolf SEO.
 *
 * Composes titles from the Site Details settings: the homepage gets its
 * custom title (default: the Site Name, with no tagline appended), and
 * posts, pages, categories, and tags get their own name with the site name
 * appended — em dash separated — only when their "Append site name" option
 * is on. Other contexts (search, 404, date archives) keep WordPress's
 * default behavior.
 *
 * When "Force rewrite titles" is enabled, the rendered page is buffered and
 * the first <title> tag is replaced outright, for themes that build their
 * own title markup instead of using the document title.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Title composition and the optional force-rewrite buffer.
 */
final class Coywolf_SEO_Titles {

	/**
	 * Hook everything up.
	 */
	public function init() {
		add_filter( 'document_title_separator', array( $this, 'separator' ), 20 );
		add_filter( 'document_title_parts', array( $this, 'parts' ), 20 );
		add_action( 'template_redirect', array( $this, 'maybe_buffer' ), 99999 );
	}

	/**
	 * The title the plugin wants for the current main query, used both by
	 * the document-title filters and the force-rewrite buffer (and by the
	 * Open Graph module for og:title).
	 *
	 * @return string '' when this context is not one the plugin manages.
	 */
	public function managed_title() {
		if ( is_front_page() ) {
			$custom = (string) Coywolf_SEO_Options::get( 'homepage_title' );
			return ( '' !== $custom ) ? $custom : get_bloginfo( 'name' );
		}
		if ( is_home() ) {
			// Blog posts index when a static front page is set.
			return single_post_title( '', false );
		}
		if ( is_singular( array( 'post', 'page' ) ) ) {
			return single_post_title( '', false );
		}
		if ( is_category() || is_tag() ) {
			return single_term_title( '', false );
		}
		return '';
	}

	/**
	 * Whether the current context appends the site name.
	 *
	 * @return bool
	 */
	public function appends_site_name() {
		if ( is_front_page() ) {
			return false;
		}
		if ( is_singular( 'post' ) || is_home() ) {
			return (bool) Coywolf_SEO_Options::get( 'post_append_site_name' );
		}
		if ( is_singular( 'page' ) ) {
			return (bool) Coywolf_SEO_Options::get( 'page_append_site_name' );
		}
		if ( is_category() ) {
			return (bool) Coywolf_SEO_Options::get( 'cat_append_site_name' );
		}
		if ( is_tag() ) {
			return (bool) Coywolf_SEO_Options::get( 'tag_append_site_name' );
		}
		return false;
	}

	/**
	 * Use an em dash between title parts.
	 *
	 * @param string $sep Separator.
	 * @return string
	 */
	public function separator( $sep ) {
		$managed = $this->managed_title();
		return ( '' !== $managed ) ? Coywolf_SEO_Options::separator() : $sep;
	}

	/**
	 * Compose the document title parts for managed contexts.
	 *
	 * @param array $parts Title parts ( title, page, tagline, site ).
	 * @return array
	 */
	public function parts( $parts ) {
		$managed = $this->managed_title();
		if ( '' === $managed ) {
			return $parts;
		}

		$parts['title'] = $managed;
		unset( $parts['tagline'], $parts['site'] );
		if ( $this->appends_site_name() ) {
			$parts['site'] = get_bloginfo( 'name' );
		}
		return $parts;
	}

	/**
	 * Start the force-rewrite output buffer when enabled.
	 */
	public function maybe_buffer() {
		if ( ! Coywolf_SEO_Options::get( 'force_rewrite_titles' ) ) {
			return;
		}
		if ( is_admin() || is_feed() || is_robots() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( '' === $this->managed_title() ) {
			return;
		}
		ob_start( array( $this, 'rewrite_title_tag' ) );
	}

	/**
	 * Output-buffer callback: replace the first <title> tag with the
	 * document title WordPress (and our filters) computed.
	 *
	 * @param string $html Page output.
	 * @return string
	 */
	public function rewrite_title_tag( $html ) {
		$title = wp_get_document_title();
		if ( '' === $title || false === stripos( $html, '<title' ) ) {
			return $html;
		}
		$replaced = preg_replace(
			'#<title[^>]*>.*?</title>#is',
			'<title>' . esc_html( $title ) . '</title>',
			$html,
			1
		);
		return ( null === $replaced ) ? $html : $replaced;
	}
}
