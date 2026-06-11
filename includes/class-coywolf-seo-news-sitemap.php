<?php
/**
 * News XML sitemap for Coywolf SEO.
 *
 * Optionally serves /coywolf-news-sitemap.xml (named so it cannot clash
 * with other plugins' news sitemaps) listing articles published in the
 * last 48 hours — the window Google News considers. Posts and/or pages can
 * be included, and for posts the categories can be limited to an
 * include or exclude list.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * News sitemap endpoint.
 */
final class Coywolf_SEO_News_Sitemap {

	/**
	 * Query var marking a sitemap request.
	 */
	const QUERY_VAR = 'coywolf_seo_news_sitemap';

	/**
	 * Sitemap file name.
	 */
	const FILENAME = 'coywolf-news-sitemap.xml';

	/**
	 * Google News window, in seconds (48 hours).
	 */
	const WINDOW = 2 * DAY_IN_SECONDS;

	/**
	 * Hook everything up. Rules are registered unconditionally and checked
	 * at runtime, so toggling the setting plus a rules flush is enough.
	 */
	public function init() {
		add_action( 'init', array( $this, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 0 );
	}

	/**
	 * Whether the sitemap is enabled.
	 *
	 * @return bool
	 */
	private function enabled() {
		return (bool) Coywolf_SEO_Options::get( 'news_enabled' );
	}

	/**
	 * Route the sitemap file name to the query var.
	 */
	public function add_rewrite_rule() {
		add_rewrite_rule( '^' . preg_quote( self::FILENAME, '#' ) . '$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * Register the query var.
	 *
	 * @param array $vars Public query vars.
	 * @return array
	 */
	public function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Render the sitemap when requested.
	 */
	public function maybe_render() {
		if ( '1' !== (string) get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		if ( ! $this->enabled() ) {
			// Disabled: behave like any unknown URL.
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}

		nocache_headers();
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML document assembled below with esc_xml on every value.
		echo $this->build_xml();
		exit;
	}

	/**
	 * The articles inside the 48-hour window, per the include settings.
	 *
	 * @return WP_Post[]
	 */
	public function articles() {
		$types = array();
		if ( Coywolf_SEO_Options::get( 'news_include_posts' ) ) {
			$types[] = 'post';
		}
		if ( Coywolf_SEO_Options::get( 'news_include_pages' ) ) {
			$types[] = 'page';
		}
		if ( empty( $types ) ) {
			return array();
		}

		$args = array(
			'post_type'           => $types,
			'post_status'         => 'publish',
			'posts_per_page'      => 1000, // Google's per-sitemap limit.
			'orderby'             => 'date',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'date_query'          => array(
				array(
					'column' => 'post_date_gmt',
					'after'  => gmdate( 'Y-m-d H:i:s', time() - self::WINDOW ),
				),
			),
		);

		$mode = (string) Coywolf_SEO_Options::get( 'news_cat_mode' );
		$cats = array_map( 'intval', (array) Coywolf_SEO_Options::get( 'news_cats' ) );
		if ( ! empty( $cats ) && in_array( 'post', $types, true ) ) {
			if ( 'include' === $mode ) {
				$args['category__in'] = $cats;
			} elseif ( 'exclude' === $mode ) {
				$args['category__not_in'] = $cats;
			}
		}

		$query = new WP_Query( $args );
		return $query->posts;
	}

	/**
	 * Assemble the sitemap XML.
	 *
	 * @return string
	 */
	public function build_xml() {
		$name     = get_bloginfo( 'name' );
		$language = substr( (string) get_locale(), 0, 2 );
		$language = $language ? $language : 'en';

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";

		foreach ( $this->articles() as $post ) {
			$xml .= "\t<url>\n";
			$xml .= "\t\t<loc>" . esc_xml( get_permalink( $post ) ) . "</loc>\n";
			$xml .= "\t\t<news:news>\n";
			$xml .= "\t\t\t<news:publication>\n";
			$xml .= "\t\t\t\t<news:name>" . esc_xml( $name ) . "</news:name>\n";
			$xml .= "\t\t\t\t<news:language>" . esc_xml( $language ) . "</news:language>\n";
			$xml .= "\t\t\t</news:publication>\n";
			$xml .= "\t\t\t<news:publication_date>" . esc_xml( get_the_date( 'c', $post ) ) . "</news:publication_date>\n";
			$xml .= "\t\t\t<news:title>" . esc_xml( get_the_title( $post ) ) . "</news:title>\n";
			$xml .= "\t\t</news:news>\n";
			$xml .= "\t</url>\n";
		}

		$xml .= '</urlset>' . "\n";
		return $xml;
	}
}
