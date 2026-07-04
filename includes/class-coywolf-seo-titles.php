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
	 * The document title computed before the force-rewrite buffer opens.
	 * Computing it inside the output-buffer display handler would run other
	 * plugins' title filters there, where any filter that buffers or echoes
	 * fatals the whole page at shutdown.
	 *
	 * @var string
	 */
	private $forced_title = '';

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
			return (string) single_post_title( '', false );
		}
		if ( is_singular( array( 'post', 'page' ) ) ) {
			// A per-post Title override wins over the post's own title. Read
			// through sanitize_text_field so a stored value can never inject
			// markup into the <title> tag (core does not escape it there).
			$override = sanitize_text_field( (string) Coywolf_SEO_Options::post_meta( get_queried_object_id() )['title'] );
			if ( '' !== $override ) {
				return $override;
			}
			return (string) single_post_title( '', false );
		}
		if ( is_category() || is_tag() ) {
			$custom = Coywolf_SEO_Terms::custom_title();
			return ( '' !== $custom ) ? $custom : (string) single_term_title( '', false );
		}
		return '';
	}

	/**
	 * Whether the current context appends the site name. One site-wide
	 * setting covers posts, pages, categories, and tags; the homepage
	 * title always stands alone.
	 *
	 * @return bool
	 */
	public function appends_site_name() {
		if ( is_front_page() ) {
			return false;
		}
		return (bool) Coywolf_SEO_Options::get( 'append_site_name' );
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
	 * Register the force-rewrite title filter when enabled.
	 *
	 * Uses WordPress 7.0's template enhancement output buffer (the
	 * wp_template_enhancement_output_buffer filter) instead of opening our own
	 * output buffer: core owns the buffer's lifecycle — it only starts one
	 * because we add the filter, and it always closes it — so no open buffer is
	 * ever left for other components to misalign with.
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
		// The query is fully resolved at this point; compute the title now
		// so no filters run inside the buffer's filter handler.
		$this->forced_title = wp_get_document_title();
		add_filter( 'wp_template_enhancement_output_buffer', array( $this, 'rewrite_title_tag' ) );
	}

	/**
	 * Replace the first <title> tag in the buffered template output with the
	 * document title WordPress (and our filters) computed.
	 *
	 * @param string $html Buffered page output.
	 * @return string
	 */
	public function rewrite_title_tag( $html ) {
		$title = $this->forced_title;
		if ( '' === $title || false === stripos( $html, '<title' ) ) {
			return $html;
		}
		// preg_replace_callback so '$' and '\' in the title are literal — in a
		// plain preg_replace replacement, "$25" would be eaten as a
		// backreference and "$0" would re-inject the matched theme <title>.
		$replaced = preg_replace_callback(
			'#<title[^>]*>.*?</title>#is',
			static function () use ( $title ) {
				return '<title>' . esc_html( $title ) . '</title>';
			},
			$html,
			1
		);
		return ( null === $replaced ) ? $html : $replaced;
	}
}
