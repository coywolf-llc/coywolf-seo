<?php
/**
 * Table of Contents block.
 *
 * Registers the coywolf-seo/table-of-contents block and renders it on the
 * server. Unlike static TOC blocks that save a heading list into the post
 * content (and go stale the moment a heading changes), this block renders a
 * placeholder and a late `the_content` filter builds the list from the final
 * HTML of the post — so headings coming from shortcodes, reusable blocks, and
 * patterns are included, anchors are injected where missing, and the table
 * can never drift out of sync with the content.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The coywolf-seo/table-of-contents block.
 */
class Coywolf_SEO_TOC {

	/**
	 * Class on a heading that excludes it from the table (set from the
	 * heading block's "Exclude from table of contents" toggle).
	 */
	const EXCLUDE_CLASS = 'coywolf-seo-toc-exclude';

	/**
	 * Placeholder marker the content filter swaps for the rendered table.
	 */
	const PLACEHOLDER_CLASS = 'coywolf-seo-toc-placeholder';

	/**
	 * Per-request counter so multiple tables on one page get unique
	 * title ids for aria-labelledby.
	 *
	 * @var int
	 */
	private $instance = 0;

	/**
	 * Hook everything up.
	 */
	public function init() {
		add_action( 'init', array( $this, 'register' ) );
		// After do_blocks (9), shortcodes (11), and the other default
		// filters: the placeholder is replaced in the final HTML.
		add_filter( 'the_content', array( $this, 'filter_content' ), 32 );
	}

	/**
	 * Register the block, its editor script, and its view assets.
	 */
	public function register() {
		wp_register_script(
			'coywolf-seo-toc-block',
			COYWOLF_SEO_URL . 'js/toc-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-data', 'wp-hooks', 'wp-compose' ),
			Coywolf_SEO::VERSION,
			true
		);
		wp_set_script_translations( 'coywolf-seo-toc-block', 'coywolf-seo' );

		wp_register_style( 'coywolf-seo-toc-editor', COYWOLF_SEO_URL . 'css/toc-editor.css', array(), Coywolf_SEO::VERSION );
		wp_register_style( 'coywolf-seo-toc', COYWOLF_SEO_URL . 'css/toc.css', array(), Coywolf_SEO::VERSION );
		wp_register_script(
			'coywolf-seo-toc-view',
			COYWOLF_SEO_URL . 'js/toc-view.js',
			array(),
			Coywolf_SEO::VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		register_block_type(
			COYWOLF_SEO_PATH . 'blocks/table-of-contents/block.json',
			array(
				'render_callback' => array( $this, 'render' ),
			)
		);
	}

	/**
	 * Render the block: enqueue the view assets and emit the placeholder the
	 * content filter replaces once the whole post's HTML exists.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render( $attributes ) {
		if ( is_feed() ) {
			// Fragment anchors are useless in feed readers.
			return '';
		}

		$attributes = $this->sanitize_attributes( $attributes );

		wp_enqueue_style( 'coywolf-seo-toc' );
		if ( $attributes['smoothScroll'] || $attributes['highlightCurrent'] ) {
			wp_enqueue_script( 'coywolf-seo-toc-view' );
		}

		// Global scroll-margin-top for anchor targets, so a fixed/sticky header
		// doesn't cover a jumped-to heading. Only emitted on TOC pages (this
		// runs from the block render) and only when the setting is above zero.
		// Added once per request even if several TOC blocks are present.
		static $margin_added = false;
		if ( ! $margin_added ) {
			$margin = (int) Coywolf_SEO_Options::get( 'scroll_margin_top' );
			if ( $margin > 0 ) {
				$unit = Coywolf_SEO_Options::get( 'scroll_margin_unit' );
				$unit = in_array( $unit, array( 'rem', 'px' ), true ) ? $unit : 'rem';
				wp_add_inline_style( 'coywolf-seo-toc', '[id]{scroll-margin-top:' . $margin . $unit . ';}' );
				$margin_added = true;
			}
		}

		$wrapper = get_block_wrapper_attributes( array( 'class' => self::PLACEHOLDER_CLASS ) );

		// The placeholder carries everything the content filter needs; it is
		// invisible (toc.css) in the unlikely case the filter never runs.
		return '<div ' . $wrapper . " data-coywolf-seo-toc='" .
			esc_attr( (string) wp_json_encode( $attributes ) ) . "'></div>";
	}

	/**
	 * Validate and default the block attributes.
	 *
	 * @param array $attributes Raw block attributes.
	 * @return array
	 */
	private function sanitize_attributes( $attributes ) {
		$defaults = array(
			'title'              => __( 'Table of contents', 'coywolf-seo' ),
			'showTitle'          => true,
			'titleLevel'         => 2,
			'levels'             => array( 2, 3 ),
			'listStyle'          => 'none',
			'collapsible'        => false,
			'initiallyCollapsed' => false,
			'smoothScroll'       => true,
			'highlightCurrent'   => false,
			'minHeadings'        => 2,
			'scrollOffset'       => 0,
		);

		$out = array_merge( $defaults, is_array( $attributes ) ? $attributes : array() );

		$out['title'] = sanitize_text_field( (string) $out['title'] );
		if ( '' === $out['title'] ) {
			// An untouched block stores no title; the default stays
			// translatable instead of baking English into the content.
			$out['title'] = $defaults['title'];
		}
		$out['showTitle']  = (bool) $out['showTitle'];
		$out['titleLevel'] = min( 6, max( 2, (int) $out['titleLevel'] ) );

		$levels = array();
		foreach ( (array) $out['levels'] as $level ) {
			$level = (int) $level;
			if ( $level >= 1 && $level <= 6 ) {
				$levels[] = $level;
			}
		}
		sort( $levels );
		$out['levels'] = ! empty( $levels ) ? array_values( array_unique( $levels ) ) : $defaults['levels'];

		$out['listStyle'] = in_array( $out['listStyle'], array( 'none', 'disc', 'decimal' ), true )
			? $out['listStyle']
			: 'none';

		$out['collapsible']        = (bool) $out['collapsible'];
		$out['initiallyCollapsed'] = (bool) $out['initiallyCollapsed'];
		$out['smoothScroll']       = (bool) $out['smoothScroll'];
		$out['highlightCurrent']   = (bool) $out['highlightCurrent'];
		$out['minHeadings']        = min( 10, max( 0, (int) $out['minHeadings'] ) );
		$out['scrollOffset']       = min( 500, max( 0, (int) $out['scrollOffset'] ) );

		return $out;
	}

	/*
	 * Content filter.
	 */

	/**
	 * Replace each placeholder with the table built from the post's final
	 * HTML, injecting ids into headings that lack one.
	 *
	 * @param string $content Post content HTML.
	 * @return string
	 */
	public function filter_content( $content ) {
		if ( ! is_string( $content ) || false === strpos( $content, 'data-coywolf-seo-toc=' ) ) {
			return $content;
		}

		// Pull the placeholders out first so their own markup is never
		// scanned for headings.
		$placeholders = array();
		$content      = preg_replace_callback(
			'/<div\s[^>]*data-coywolf-seo-toc=\'([^\']*)\'[^>]*><\/div>/i',
			function ( $m ) use ( &$placeholders ) {
				$key                  = "\x1A" . 'COYWOLF-SEO-TOC-' . count( $placeholders ) . "\x1A";
				$placeholders[ $key ] = array(
					'tag'   => $m[0],
					'attrs' => json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true ),
				);
				return $key;
			},
			$content
		);

		if ( empty( $placeholders ) ) {
			return $content;
		}

		// Every level any placeholder wants, plus the scroll offset to put on
		// the headings (the largest wins when blocks disagree).
		$wanted_levels = array();
		$scroll_offset = 0;
		foreach ( $placeholders as $placeholder ) {
			$attrs         = $this->sanitize_attributes( is_array( $placeholder['attrs'] ) ? $placeholder['attrs'] : array() );
			$wanted_levels = array_merge( $wanted_levels, $attrs['levels'] );
			$scroll_offset = max( $scroll_offset, $attrs['scrollOffset'] );
		}
		$wanted_levels = array_values( array_unique( $wanted_levels ) );

		$headings = $this->collect_headings( $content, $wanted_levels, $scroll_offset );

		foreach ( $placeholders as $key => $placeholder ) {
			$attrs = $this->sanitize_attributes( is_array( $placeholder['attrs'] ) ? $placeholder['attrs'] : array() );

			$items = array();
			foreach ( $headings as $heading ) {
				if ( in_array( $heading['level'], $attrs['levels'], true ) ) {
					$items[] = $heading;
				}
			}

			/**
			 * Filters the heading items a table of contents lists.
			 *
			 * @param array $items Items: level (int), id (string), text (string).
			 * @param array $attrs Sanitized block attributes.
			 */
			$items = apply_filters( 'coywolf_seo_toc_items', $items, $attrs );

			$min  = max( 1, $attrs['minHeadings'] );
			$html = count( $items ) >= $min ? $this->build_nav( $items, $attrs, $placeholder['tag'] ) : '';

			$content = str_replace( $key, $html, $content );
		}

		return $content;
	}

	/**
	 * Find the headings in the HTML, inject ids (and the scroll offset)
	 * where needed, and return the items in document order.
	 *
	 * The regex pairing an opening h-tag with its matching close is the
	 * reliable way to do this on arbitrary HTML: headings cannot nest, and
	 * quoted attribute values are skipped so a ">" inside one cannot end
	 * the tag early.
	 *
	 * @param string $content       Post HTML (placeholders already removed). Passed by reference: ids are injected.
	 * @param array  $wanted_levels Heading levels any block on the page wants.
	 * @param int    $scroll_offset scroll-margin-top px to set on listed headings (0 = none).
	 * @return array Items: level (int), id (string), text (string).
	 */
	private function collect_headings( &$content, $wanted_levels, $scroll_offset ) {
		$pattern = '/<h([1-6])((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>(.*?)<\/h\1\s*>/is';
		if ( ! preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			return array();
		}

		// Existing ids anywhere in the document; generated slugs must not
		// collide with them.
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

		$items  = array();
		$offset = 0; // Cumulative length drift from injected attributes.

		foreach ( $matches as $match ) {
			$level = (int) $match[1][0];
			$attrs = $match[2][0];
			$inner = $match[3][0];

			if ( ! in_array( $level, $wanted_levels, true ) ) {
				continue;
			}

			// The per-heading opt-out toggle adds this class.
			if ( preg_match( '/\sclass\s*=\s*("[^"]*"|\'[^\']*\')/i', $attrs, $class_match )
				&& false !== strpos( $class_match[1], self::EXCLUDE_CLASS ) ) {
				continue;
			}

			$text = trim( wp_strip_all_tags( $inner ) );
			if ( '' === $text ) {
				continue;
			}

			$id = '';
			if ( preg_match( '/\sid\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>"\'=`]+))/i', $attrs, $id_match ) ) {
				if ( '' !== $id_match[2] ) {
					$id = $id_match[2];
				} elseif ( isset( $id_match[3] ) && '' !== $id_match[3] ) {
					$id = $id_match[3];
				} elseif ( isset( $id_match[4] ) ) {
					$id = $id_match[4];
				}
			}

			$new_attrs = $attrs;
			if ( '' === $id ) {
				$id         = $this->unique_slug( $text, $taken );
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
	 * Slug for a heading's text, unique within the document.
	 *
	 * @param string $text  Heading text.
	 * @param array  $taken Ids already used (by reference; the result is added).
	 * @return string
	 */
	private function unique_slug( $text, &$taken ) {
		$base = sanitize_title( $text );
		if ( '' === $base ) {
			$base = 'section';
		}
		$slug = $base;
		for ( $i = 2; isset( $taken[ $slug ] ); $i++ ) {
			$slug = $base . '-' . $i;
		}
		$taken[ $slug ] = true;
		return $slug;
	}

	/*
	 * Markup.
	 */

	/**
	 * The rendered table of contents.
	 *
	 * @param array  $items           Heading items: level, id, text.
	 * @param array  $attrs           Sanitized block attributes.
	 * @param string $placeholder_tag The placeholder div (its wrapper attributes — alignment,
	 *                                custom class, block-support styles — are reused).
	 * @return string
	 */
	private function build_nav( $items, $attrs, $placeholder_tag ) {
		++$this->instance;
		$title_id = 'coywolf-seo-toc-title-' . $this->instance;

		// Lift the block-support wrapper attributes off the placeholder so
		// alignment/spacing/color choices land on the nav itself.
		$wrapper_attrs = '';
		if ( preg_match( '/<div\s+(.*?)\s*data-coywolf-seo-toc=/is', $placeholder_tag, $m ) ) {
			$wrapper_attrs = ' ' . trim( preg_replace( '/\s+/', ' ', str_replace( self::PLACEHOLDER_CLASS, '', $m[1] ) ) );
		}

		$classes = 'coywolf-seo-toc coywolf-seo-toc-list-' . $attrs['listStyle'];
		if ( $attrs['highlightCurrent'] ) {
			$classes .= ' coywolf-seo-toc-spy';
		}
		$wrapper_attrs = preg_replace(
			'/class\s*=\s*"\s*([^"]*?)\s*"/i',
			'class="' . esc_attr( $classes ) . ' $1"',
			$wrapper_attrs,
			1,
			$replaced
		);
		if ( ! $replaced ) {
			$wrapper_attrs .= ' class="' . esc_attr( $classes ) . '"';
		}

		$aria = $attrs['showTitle']
			? ' aria-labelledby="' . esc_attr( $title_id ) . '"'
			: ' aria-label="' . esc_attr( $attrs['title'] ) . '"';

		$config = '';
		if ( $attrs['smoothScroll'] ) {
			$config .= ' data-smooth="1"';
		}
		if ( $attrs['highlightCurrent'] ) {
			$config .= ' data-spy="1"';
		}

		$list = $this->build_list( $items, 'decimal' === $attrs['listStyle'] ? 'ol' : 'ul' );

		$title = '';
		if ( $attrs['showTitle'] ) {
			$title = '<h' . $attrs['titleLevel'] . ' id="' . esc_attr( $title_id ) . '" class="coywolf-seo-toc-title">'
				. esc_html( $attrs['title'] )
				. '</h' . $attrs['titleLevel'] . '>';
		}

		$html = '<nav' . $wrapper_attrs . $aria . $config . '>';
		if ( $attrs['collapsible'] ) {
			$html .= '<details class="coywolf-seo-toc-details"' . ( $attrs['initiallyCollapsed'] ? '' : ' open' ) . '>';
			$html .= '<summary class="coywolf-seo-toc-summary">';
			// Never place a real heading element inside <summary>: the disclosure
			// button flattens descendant heading semantics, hiding the TOC from
			// heading navigation. Use a span carrying the title id so the nav's
			// aria-labelledby still resolves.
			$html .= '<span' . ( $attrs['showTitle'] ? ' id="' . esc_attr( $title_id ) . '"' : '' )
				. ' class="coywolf-seo-toc-title">' . esc_html( $attrs['title'] ) . '</span>';
			$html .= '</summary>';
			$html .= $list;
			$html .= '</details>';
		} else {
			$html .= $title . $list;
		}
		$html .= '</nav>';

		return $html;
	}

	/**
	 * The nested list for the items, following the document's heading
	 * hierarchy (tolerant of skipped levels).
	 *
	 * @param array  $items Heading items: level, id, text.
	 * @param string $tag   ol|ul.
	 * @return string
	 */
	private function build_list( $items, $tag ) {
		if ( empty( $items ) ) {
			return '';
		}

		$html  = '<' . $tag . ' class="coywolf-seo-toc-list">';
		$stack = array( $items[0]['level'] ); // Open list nesting levels.
		$depth = 1;
		$open  = false; // An <li> is open at the current depth.

		foreach ( $items as $item ) {
			if ( $item['level'] > end( $stack ) ) {
				// Deeper: nest a list inside the open item.
				$html   .= '<' . $tag . '>';
				$stack[] = $item['level'];
				++$depth;
				$open = false;
			} else {
				while ( $depth > 1 && $item['level'] < end( $stack ) ) {
					$html .= $open ? '</li>' : '';
					$html .= '</' . $tag . '>';
					array_pop( $stack );
					--$depth;
					$open = true; // The parent item is the one now open.
				}
				if ( $open ) {
					$html .= '</li>';
				}
			}
			$html .= '<li><a href="#' . esc_attr( $item['id'] ) . '">' . esc_html( $item['text'] ) . '</a>';
			$open  = true;
		}

		while ( $depth > 0 ) {
			$html .= $open ? '</li>' : '';
			$html .= '</' . $tag . '>';
			array_pop( $stack );
			--$depth;
			$open = true;
		}

		return $html;
	}
}
