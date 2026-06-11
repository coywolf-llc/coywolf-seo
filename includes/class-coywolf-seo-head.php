<?php
/**
 * Front-end head output for Coywolf SEO: the meta description, the robots
 * directives, and the canonical link.
 *
 * - Meta description: homepage uses the Site Details description (default:
 *   the Tagline); posts and pages use their manual excerpt when one exists;
 *   categories and tags use the term description. Nothing is invented from
 *   content, and the "Exclude meta description" setting turns it all off.
 * - Robots: the directives checked on the Settings screen are merged into
 *   WordPress's robots meta via wp_robots; per-post Noindex/Nofollow
 *   replace index/follow.
 * - Canonical: WordPress core's rel_canonical (singular-only) is replaced
 *   so the homepage and term archives get canonicals too, and so a per-post
 *   canonical override is honored.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta description, robots, canonical.
 */
final class Coywolf_SEO_Head {

	/**
	 * Hook everything up.
	 */
	public function init() {
		add_filter( 'wp_robots', array( $this, 'robots' ), 20 );
		// Core's max-image-preview:large is one of our toggles; don't let the
		// default re-add it when unchecked.
		remove_filter( 'wp_robots', 'wp_robots_max_image_preview_large' );

		// Render the robots meta ourselves: same directives pipeline as
		// core's wp_robots(), but with double-quoted attributes.
		remove_action( 'wp_head', 'wp_robots', 1 );
		add_action( 'wp_head', array( $this, 'output_robots' ), 1 );

		add_action( 'template_redirect', array( $this, 'swap_canonical' ), 9 );
		add_action( 'wp_head', array( $this, 'output_meta_description' ), 1 );
	}

	/**
	 * Print the robots meta tag. Mirrors core's wp_robots() composition —
	 * the full wp_robots filter chain still applies — with double-quoted
	 * attributes instead of core's single quotes.
	 */
	public function output_robots() {
		/** This filter is documented in wp-includes/robots-template.php */
		$robots = apply_filters( 'wp_robots', array() );

		$parts = array();
		foreach ( $robots as $directive => $value ) {
			if ( is_string( $value ) ) {
				$parts[] = $directive . ':' . $value;
			} elseif ( $value ) {
				$parts[] = $directive;
			}
		}
		if ( empty( $parts ) ) {
			return;
		}
		printf( '<meta name="robots" content="%s" />' . "\n", esc_attr( implode( ', ', $parts ) ) );
	}

	/**
	 * Replace core's singular-only canonical with ours.
	 */
	public function swap_canonical() {
		remove_action( 'wp_head', 'rel_canonical' );
		add_action( 'wp_head', array( $this, 'output_canonical' ), 1 );
	}

	/**
	 * The canonical URL for the current main query, override included.
	 *
	 * @return string '' when none applies.
	 */
	public function canonical_url() {
		if ( is_front_page() ) {
			$paged = (int) get_query_var( 'paged' );
			return ( $paged > 1 ) ? get_pagenum_link( $paged ) : home_url( '/' );
		}
		if ( is_home() ) {
			// The blog posts index (when a static front page is set).
			$paged = (int) get_query_var( 'paged' );
			if ( $paged > 1 ) {
				return get_pagenum_link( $paged );
			}
			$posts_page = (int) get_option( 'page_for_posts' );
			return $posts_page ? (string) get_permalink( $posts_page ) : home_url( '/' );
		}
		if ( is_singular() ) {
			$meta = Coywolf_SEO_Options::post_meta( get_queried_object_id() );
			if ( '' !== $meta['canonical'] ) {
				return $meta['canonical'];
			}
			$canonical = wp_get_canonical_url();
			return $canonical ? $canonical : '';
		}
		if ( is_category() || is_tag() ) {
			$link = get_term_link( get_queried_object() );
			if ( is_wp_error( $link ) ) {
				return '';
			}
			$paged = (int) get_query_var( 'paged' );
			if ( $paged > 1 ) {
				global $wp_rewrite;
				$link = $wp_rewrite->using_permalinks()
					? trailingslashit( $link ) . user_trailingslashit( $wp_rewrite->pagination_base . '/' . $paged, 'paged' )
					: add_query_arg( 'paged', $paged, $link );
			}
			return $link;
		}
		return '';
	}

	/**
	 * Print the canonical link.
	 */
	public function output_canonical() {
		$url = $this->canonical_url();
		if ( '' !== $url ) {
			printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $url ) );
		}
	}

	/**
	 * The meta description for the current main query, honoring the
	 * "Exclude meta description" setting.
	 *
	 * @return string '' when none applies.
	 */
	public function description() {
		if ( Coywolf_SEO_Options::get( 'exclude_meta_desc' ) ) {
			return '';
		}
		return $this->source_description();
	}

	/**
	 * The description for the current main query regardless of the exclude
	 * setting — Open Graph and schema descriptions use this directly, since
	 * the exclude setting only governs the meta description tag.
	 *
	 * @return string '' when none applies.
	 */
	public function source_description() {
		$text = '';
		if ( is_front_page() ) {
			$custom = (string) Coywolf_SEO_Options::get( 'homepage_description' );
			$text   = ( '' !== $custom ) ? $custom : get_bloginfo( 'description' );
		} elseif ( is_singular( array( 'post', 'page' ) ) ) {
			$post = get_queried_object();
			if ( $post && '' !== $post->post_excerpt ) {
				$text = $post->post_excerpt;
			}
		} elseif ( is_category() || is_tag() ) {
			$text = term_description();
		}
		$text = trim( wp_strip_all_tags( (string) $text, true ) );
		if ( '' === $text ) {
			return '';
		}
		// Keep it to snippet length without cutting a word in half.
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > 300 ) {
			$text = wp_html_excerpt( $text, 300, '…' );
		}
		return $text;
	}

	/**
	 * Print the meta description.
	 */
	public function output_meta_description() {
		$text = $this->description();
		if ( '' !== $text ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $text ) );
		}
	}

	/**
	 * Merge the configured directives into WordPress's robots meta.
	 *
	 * @param array $robots Directives from core and other plugins.
	 * @return array
	 */
	public function robots( $robots ) {
		$o = Coywolf_SEO_Options::all();

		$noindex  = false;
		$nofollow = false;
		if ( is_singular( array( 'post', 'page' ) ) ) {
			$meta     = Coywolf_SEO_Options::post_meta( get_queried_object_id() );
			$noindex  = (bool) $meta['noindex'];
			$nofollow = (bool) $meta['nofollow'];
		}

		if ( $noindex ) {
			unset( $robots['index'] );
			$robots['noindex'] = true;
		} elseif ( $o['robots_index'] && empty( $robots['noindex'] ) ) {
			$robots['index'] = true;
		}

		if ( $nofollow ) {
			unset( $robots['follow'] );
			$robots['nofollow'] = true;
		} elseif ( $o['robots_follow'] && empty( $robots['nofollow'] ) ) {
			$robots['follow'] = true;
		}

		if ( $o['robots_max_image'] ) {
			$robots['max-image-preview'] = 'large';
		}
		if ( $o['robots_max_snippet'] ) {
			$robots['max-snippet'] = '-1';
		}
		if ( $o['robots_max_video'] ) {
			$robots['max-video-preview'] = '-1';
		}

		return $robots;
	}
}
