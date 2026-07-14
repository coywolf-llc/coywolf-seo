<?php
/**
 * Heading anchors.
 *
 * Gives every content heading a stable `id` so it can be linked to directly
 * (and so the Table of Contents block has an anchor to point at). Runs as a
 * late `the_content` filter — before the TOC's own pass — and injects a
 * `jump-`-prefixed slug into any heading that does not already carry an id,
 * leaving manually set HTML anchors untouched.
 *
 * The id logic lives here so the TOC block reuses the exact same slugs: a
 * heading and the table entry that links to it always agree.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Automatic heading ids.
 */
final class Coywolf_SEO_Headings {

	/**
	 * Prefix for generated heading ids (e.g. "jump-best-watches"). Manually
	 * set anchors keep their own value and never get the prefix.
	 */
	const ID_PREFIX = 'jump-';

	/**
	 * Hook the site-wide pass.
	 */
	public function init() {
		// Priority 30: after do_blocks/shortcodes, and before the TOC's own
		// filter at 32 — so the table reads ids this pass has already injected.
		add_filter( 'the_content', array( $this, 'filter_content' ), 30 );
	}

	/**
	 * Inject ids into every heading of the current singular content.
	 *
	 * @param string $content Post content HTML.
	 * @return string
	 */
	public function filter_content( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return $content;
		}
		if ( ! Coywolf_SEO_Options::get( 'heading_ids' ) ) {
			return $content;
		}
		// Only the primary content of a singular view: that is where in-page
		// anchors are meaningful, and it keeps ids off archive/loop excerpts
		// where two posts could otherwise mint the same slug.
		if ( is_feed() || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		self::inject( $content, array( 2, 3, 4, 5, 6 ) );
		return $content;
	}

	/**
	 * Find the headings in the HTML and inject ids (and an optional scroll
	 * offset) where needed, returning the items in document order.
	 *
	 * The regex pairs an opening h-tag with its matching close: headings
	 * cannot nest, and quoted attribute values are skipped so a ">" inside one
	 * cannot end the tag early. Shared by the site-wide pass (all levels, no
	 * exclusions) and the TOC block (its levels, its exclude class, a scroll
	 * offset) so both mint identical slugs.
	 *
	 * @param string $content       Post HTML. By reference: ids are injected in place.
	 * @param array  $levels        Heading levels to process (1-6).
	 * @param string $exclude_class A class that opts a heading out entirely (''=none).
	 * @param int    $scroll_offset scroll-margin-top px to set on processed headings (0=none).
	 * @return array Items: level (int), id (string), text (string).
	 */
	public static function inject( &$content, $levels, $exclude_class = '', $scroll_offset = 0 ) {
		$pattern = '/<h([1-6])((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>(.*?)<\/h\1\s*>/is';
		if ( ! preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			return array();
		}

		$taken  = self::existing_ids( $content );
		$items  = array();
		$offset = 0; // Cumulative length drift from injected attributes.

		foreach ( $matches as $match ) {
			$level = (int) $match[1][0];
			$attrs = $match[2][0];
			$inner = $match[3][0];

			if ( ! in_array( $level, $levels, true ) ) {
				continue;
			}

			// A heading carrying the exclude class is left out entirely — no id
			// injected here, not listed — matching the TOC opt-out toggle.
			if ( '' !== $exclude_class
				&& preg_match( '/\sclass\s*=\s*("[^"]*"|\'[^\']*\')/i', $attrs, $class_match )
				&& false !== strpos( $class_match[1], $exclude_class ) ) {
				continue;
			}

			$text = trim( wp_strip_all_tags( $inner ) );
			if ( '' === $text ) {
				continue;
			}

			$id = self::attr_id( $attrs );

			$new_attrs = $attrs;
			if ( '' === $id ) {
				$id         = self::unique_slug( $text, $taken );
				$new_attrs .= ' id="' . esc_attr( $id ) . '"';
			}
			if ( $scroll_offset > 0 && false === stripos( $new_attrs, 'scroll-margin' ) ) {
				if ( preg_match( '/\sstyle\s*=\s*("[^"]*"|\'[^\']*\')/i', $new_attrs, $style_match ) ) {
					$quoted    = $style_match[1];
					$style     = substr( $quoted, 1, -1 );
					$new_attrs = str_replace(
						$quoted,
						$quoted[0] . rtrim( $style, '; ' ) . ';scroll-margin-top:' . $scroll_offset . 'px' . $quoted[0],
						$new_attrs
					);
				} else {
					$new_attrs .= ' style="scroll-margin-top:' . $scroll_offset . 'px"';
				}
			}

			if ( $new_attrs !== $attrs ) {
				$old_open = '<h' . $level . $attrs . '>';
				$new_open = '<h' . $level . $new_attrs . '>';
				$position = $match[0][1] + $offset;
				$content  = substr_replace( $content, $new_open, $position, strlen( $old_open ) );
				$offset  += strlen( $new_open ) - strlen( $old_open );
			}

			$items[] = array(
				'level' => $level,
				'id'    => $id,
				'text'  => $text,
			);
		}

		return $items;
	}

	/**
	 * Every id already present in the HTML, so generated slugs never collide.
	 *
	 * @param string $content HTML to scan.
	 * @return array Map of id => true.
	 */
	private static function existing_ids( $content ) {
		$taken = array();
		if ( preg_match_all( '/\sid\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>"\'=`]+))/i', $content, $id_matches ) ) {
			foreach ( $id_matches[2] as $i => $double_quoted ) {
				if ( '' !== $double_quoted ) {
					$existing_id = $double_quoted;
				} elseif ( '' !== $id_matches[3][ $i ] ) {
					$existing_id = $id_matches[3][ $i ];
				} else {
					$existing_id = $id_matches[4][ $i ];
				}
				if ( '' !== $existing_id ) {
					$taken[ $existing_id ] = true;
				}
			}
		}
		return $taken;
	}

	/**
	 * The id already on a tag's attribute string, or '' when it has none.
	 *
	 * @param string $attrs The tag's attributes.
	 * @return string
	 */
	private static function attr_id( $attrs ) {
		if ( preg_match( '/\sid\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>"\'=`]+))/i', $attrs, $id_match ) ) {
			if ( '' !== $id_match[2] ) {
				return $id_match[2];
			}
			if ( isset( $id_match[3] ) && '' !== $id_match[3] ) {
				return $id_match[3];
			}
			if ( isset( $id_match[4] ) ) {
				return $id_match[4];
			}
		}
		return '';
	}

	/**
	 * A `jump-`-prefixed slug for a heading's text, unique within the document.
	 *
	 * @param string $text  Heading text.
	 * @param array  $taken Ids already used (by reference; the result is added).
	 * @return string
	 */
	public static function unique_slug( $text, &$taken ) {
		$base = sanitize_title( $text );
		if ( '' === $base ) {
			$base = 'section';
		}
		$base = self::ID_PREFIX . $base;
		$slug = $base;
		for ( $i = 2; isset( $taken[ $slug ] ); $i++ ) {
			$slug = $base . '-' . $i;
		}
		$taken[ $slug ] = true;
		return $slug;
	}
}
