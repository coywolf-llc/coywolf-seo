<?php
/**
 * Breadcrumb navigation for Coywolf SEO.
 *
 * An accessible breadcrumb trail for posts, pages, and term archives, exposed
 * three ways so it fits any theme:
 *
 *   - a template tag, coywolf_seo_breadcrumbs(), for classic themes;
 *   - a coywolf-seo/breadcrumbs block for block/FSE themes and the editor;
 *   - a [coywolf_seo_breadcrumbs] shortcode for widgets and page content.
 *
 * The markup follows the WAI-ARIA Authoring Practices breadcrumb pattern: a
 * <nav aria-label="Breadcrumb"> wrapping an ordered list, links for the
 * ancestors and a plain (non-link) current crumb carrying aria-current="page".
 * Separators are drawn with a CSS ::before pseudo-element whose accessible
 * alternative text is empty, so screen readers never announce them.
 *
 * Posts use their category structure (Home › parent cat › category › post);
 * pages and other hierarchical types use their parent/child structure; term
 * archives use the term's ancestors. The visible trail feeds the
 * BreadcrumbList node the Schema module adds to its @graph. The two match,
 * except the structured data always ends at the current page even when the
 * visible trail is set to hide that last crumb — search engines expect the
 * full path to the page (see schema_trail()).
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Breadcrumb trail builder, renderer, and block/shortcode/template surface.
 */
final class Coywolf_SEO_Breadcrumbs {

	/**
	 * The block name.
	 */
	const BLOCK = 'coywolf-seo/breadcrumbs';

	/**
	 * Preset separator characters, keyed by the value stored in settings. A
	 * custom separator (breadcrumbs_separator_custom) overrides the preset.
	 *
	 * @var array<string,string>
	 */
	const SEPARATORS = array(
		'slash'     => '/',
		'chevron'   => '›',
		'guillemet' => '»',
		'bullet'    => '•',
		'arrow'     => '→',
		'gt'        => '>',
	);

	/**
	 * Hook everything up.
	 */
	public function init() {
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		add_shortcode( 'coywolf_seo_breadcrumbs', array( $this, 'shortcode' ) );
	}

	/**
	 * Register the front-end style and the editor block.
	 */
	public function register_block() {
		wp_register_style( 'coywolf-seo-breadcrumbs', COYWOLF_SEO_URL . 'css/breadcrumbs.css', array(), Coywolf_SEO::VERSION );

		// The block is registered even when the feature is off so it stays
		// insertable; its render callback is what no-ops until enabled.
		if ( function_exists( 'register_block_type' ) ) {
			wp_register_script(
				'coywolf-seo-breadcrumbs-block',
				COYWOLF_SEO_URL . 'js/breadcrumbs-block.js',
				array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
				Coywolf_SEO::VERSION,
				true
			);
			wp_set_script_translations( 'coywolf-seo-breadcrumbs-block', 'coywolf-seo' );

			register_block_type(
				COYWOLF_SEO_PATH . 'blocks/breadcrumbs/block.json',
				array( 'render_callback' => array( $this, 'render_block' ) )
			);
		}
	}

	/**
	 * Load the stylesheet in the document head on views that can show a trail,
	 * so a template tag placed after wp_head() is still styled without a flash
	 * of unstyled content. A no-op when the feature is off.
	 */
	public function maybe_enqueue() {
		if ( ! self::is_enabled() ) {
			return;
		}
		if ( is_singular() || is_category() || is_tag() || is_tax() ) {
			$this->enqueue_style();
		}
	}

	/**
	 * Enqueue the stylesheet and, once per request, the custom-separator inline
	 * style. Safe to call repeatedly (WordPress de-duplicates).
	 */
	private function enqueue_style() {
		wp_enqueue_style( 'coywolf-seo-breadcrumbs' );

		static $sep_added = false;
		if ( $sep_added ) {
			return;
		}
		$sep_added = true;

		// sanitize_text_field() entity-encodes a literal "<" ( &lt; ) at save
		// time; decode it back so the real glyph reaches the CSS. The 8-char
		// cap on the stored (still-encoded) value keeps the decoded result far
		// too short to form a "</style" breakout sequence.
		$custom = trim( wp_specialchars_decode( (string) Coywolf_SEO_Options::get( 'breadcrumbs_separator_custom' ), ENT_QUOTES ) );
		if ( '' !== $custom ) {
			// The custom string becomes CSS `content`, so escape the two
			// characters that can break out of a CSS string. sanitize_text_field
			// already stripped tags and newlines at save time.
			$escaped = addcslashes( $custom, '"\\' );
			wp_add_inline_style(
				'coywolf-seo-breadcrumbs',
				'.coywolf-seo-breadcrumbs--sep-custom{--coywolf-seo-breadcrumb-sep:"' . $escaped . '";}'
			);
		}
	}

	/**
	 * Whether the breadcrumbs feature is turned on.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) Coywolf_SEO_Options::get( 'breadcrumbs_enabled' );
	}

	/**
	 * Render the trail for the current query as accessible HTML.
	 *
	 * @param array $args Optional per-call overrides: home_label, show_home,
	 *                    show_current, separator, class, aria_label.
	 * @return string Empty string when there is nothing to show.
	 */
	public static function render( $args = array() ) {
		// Nothing to show on the front page (the trail would be a lone,
		// self-referential Home crumb) or in a feed reader.
		if ( ! self::is_enabled() || is_feed() || is_front_page() ) {
			return '';
		}

		$opts  = self::resolve_options( $args );
		$items = self::trail( null, $opts );
		if ( count( $items ) < 2 ) {
			// A lone "Home" crumb (or nothing) is not a useful trail.
			return '';
		}

		return self::render_list( $items, $opts );
	}

	/**
	 * Block render callback: the same trail, enqueuing the style on demand.
	 *
	 * @param array $attributes Block attributes (unused; the block reads global
	 *                          settings so one place configures every trail).
	 * @return string
	 */
	public function render_block( $attributes = array() ) {
		unset( $attributes );
		$html = self::render();
		if ( '' !== $html ) {
			$this->enqueue_style();
		}
		return $html;
	}

	/**
	 * Shortcode handler.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'home_label'   => null,
				'show_home'    => null,
				'show_current' => null,
				'separator'    => null,
				'class'        => null,
			),
			is_array( $atts ) ? $atts : array(),
			'coywolf_seo_breadcrumbs'
		);

		$args = array();
		foreach ( array( 'home_label', 'separator', 'class' ) as $key ) {
			if ( null !== $atts[ $key ] ) {
				$args[ $key ] = $atts[ $key ];
			}
		}
		foreach ( array( 'show_home', 'show_current' ) as $key ) {
			if ( null !== $atts[ $key ] ) {
				$args[ $key ] = filter_var( $atts[ $key ], FILTER_VALIDATE_BOOLEAN );
			}
		}

		$html = self::render( $args );
		if ( '' !== $html ) {
			$this->enqueue_style();
		}
		return $html;
	}

	/**
	 * Merge stored settings with per-call overrides into a resolved option set.
	 *
	 * @param array $args Overrides.
	 * @return array{home_label:string,show_home:bool,show_current:bool,separator:string,class:string,aria_label:string}
	 */
	private static function resolve_options( array $args ) {
		$home_label = isset( $args['home_label'] ) ? (string) $args['home_label'] : (string) Coywolf_SEO_Options::get( 'breadcrumbs_home_label' );
		if ( '' === trim( $home_label ) ) {
			$home_label = (string) get_bloginfo( 'name' );
		}

		$separator = isset( $args['separator'] ) ? (string) $args['separator'] : (string) Coywolf_SEO_Options::get( 'breadcrumbs_separator' );

		// Resolve the separator modifier class here, where it is known whether
		// the caller passed an explicit preset. A per-call preset override wins
		// over a stored custom separator; otherwise a stored custom separator
		// wins over the stored preset.
		$custom = trim( (string) Coywolf_SEO_Options::get( 'breadcrumbs_separator_custom' ) );
		if ( isset( $args['separator'] ) && isset( self::SEPARATORS[ $separator ] ) ) {
			$sep_class = 'coywolf-seo-breadcrumbs--sep-' . $separator;
		} elseif ( '' !== $custom ) {
			$sep_class = 'coywolf-seo-breadcrumbs--sep-custom';
		} else {
			$sep_key   = isset( self::SEPARATORS[ $separator ] ) ? $separator : 'slash';
			$sep_class = 'coywolf-seo-breadcrumbs--sep-' . $sep_key;
		}

		return array(
			'home_label'   => $home_label,
			'show_home'    => isset( $args['show_home'] ) ? (bool) $args['show_home'] : (bool) Coywolf_SEO_Options::get( 'breadcrumbs_show_home' ),
			'show_current' => isset( $args['show_current'] ) ? (bool) $args['show_current'] : (bool) Coywolf_SEO_Options::get( 'breadcrumbs_show_current' ),
			'separator'    => $separator,
			'sep_class'    => $sep_class,
			'class'        => isset( $args['class'] ) ? (string) $args['class'] : (string) Coywolf_SEO_Options::get( 'breadcrumbs_class' ),
			'aria_label'   => isset( $args['aria_label'] ) ? (string) $args['aria_label'] : __( 'Breadcrumb', 'coywolf-seo' ),
		);
	}

	/**
	 * The breadcrumb trail for structured data. Identical to the visible trail,
	 * except the current page is ALWAYS the final crumb — even when the visible
	 * nav is set to hide it — because search engines expect the full path to
	 * the current page in a BreadcrumbList.
	 *
	 * @param int|null $post_id Post to build for; null uses the queried object.
	 * @return array<int,array<string,mixed>>
	 */
	public static function schema_trail( $post_id = null ) {
		return self::trail( $post_id, self::resolve_options( array( 'show_current' => true ) ) );
	}

	/**
	 * Build the ordered trail for a post/page/term as a flat item list.
	 *
	 * Each item is array( 'label' => string, 'url' => string, 'current' => bool,
	 * 'home' => bool ). The last item is the current page/term (when shown).
	 *
	 * @param int|null   $post_id Post to build for; null uses the queried object.
	 * @param array|null $opts    Resolved options; built from settings when null.
	 * @return array<int,array<string,mixed>>
	 */
	public static function trail( $post_id = null, $opts = null ) {
		if ( null === $opts ) {
			$opts = self::resolve_options( array() );
		}

		$home = $opts['show_home']
			? array(
				'label' => $opts['home_label'],
				'url'   => home_url( '/' ),
			)
			: null;

		$ancestors = array();
		$current   = null;

		if ( null !== $post_id || is_singular() ) {
			$post = $post_id ? get_post( $post_id ) : get_queried_object();
			if ( $post instanceof WP_Post ) {
				$ancestors = self::singular_ancestors( $post );
				$current   = array(
					'label' => wp_strip_all_tags( get_the_title( $post ) ),
					'url'   => (string) get_permalink( $post ),
				);
			}
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				$ancestors = self::term_ancestors( $term );
				$link      = get_term_link( $term );
				$current   = array(
					'label' => wp_strip_all_tags( $term->name ),
					'url'   => is_wp_error( $link ) ? '' : (string) $link,
				);
			}
		}

		$items = self::assemble( $home, $ancestors, $current, $opts );

		/**
		 * Filter the breadcrumb trail items before they are rendered.
		 *
		 * Lets themes and add-ons reshape the trail — e.g. give a custom post
		 * type its own path, or swap the chosen primary category.
		 *
		 * @param array    $items   Ordered items (label/url/current/home).
		 * @param int|null $post_id The post id when building for a post.
		 * @param array    $opts    Resolved options.
		 */
		return (array) apply_filters( 'coywolf_seo_breadcrumb_items', $items, $post_id, $opts );
	}

	/**
	 * Ancestor crumbs for a singular object: the category path for posts, the
	 * parent path for pages and other hierarchical types.
	 *
	 * @param WP_Post $post Post.
	 * @return array<int,array<string,string>> Top-most first.
	 */
	private static function singular_ancestors( WP_Post $post ) {
		$crumbs = array();

		if ( 'post' === $post->post_type ) {
			$category = self::primary_category( $post );
			if ( $category instanceof WP_Term ) {
				$chain   = array_reverse( get_ancestors( $category->term_id, 'category' ) );
				$chain[] = $category->term_id;
				foreach ( $chain as $term_id ) {
					$term = get_term( $term_id, 'category' );
					if ( ! $term instanceof WP_Term ) {
						continue;
					}
					$link = get_term_link( $term );
					if ( is_wp_error( $link ) ) {
						continue;
					}
					$crumbs[] = array(
						'label' => wp_strip_all_tags( $term->name ),
						'url'   => (string) $link,
					);
				}
			}
			return $crumbs;
		}

		if ( is_post_type_hierarchical( $post->post_type ) ) {
			$front_id = (int) get_option( 'page_on_front' );
			$parents  = array_reverse( get_post_ancestors( $post ) );
			foreach ( $parents as $parent_id ) {
				// The static front page is already the Home crumb — don't repeat
				// it as an ancestor.
				if ( $front_id && (int) $parent_id === $front_id ) {
					continue;
				}
				// Skip ancestors that aren't publicly viewable (draft, pending,
				// trashed, or private): they would be dead links, and a private
				// ancestor's title must not leak into the trail or its schema.
				if ( ! is_post_publicly_viewable( $parent_id ) ) {
					continue;
				}
				$crumbs[] = array(
					'label' => wp_strip_all_tags( get_the_title( $parent_id ) ),
					'url'   => (string) get_permalink( $parent_id ),
				);
			}
		}

		return $crumbs;
	}

	/**
	 * Ancestor crumbs for a term archive: the term's parent terms, top-most
	 * first. The current term itself is added separately.
	 *
	 * @param WP_Term $term Term.
	 * @return array<int,array<string,string>>
	 */
	private static function term_ancestors( WP_Term $term ) {
		$crumbs  = array();
		$parents = array_reverse( get_ancestors( $term->term_id, $term->taxonomy ) );
		foreach ( $parents as $parent_id ) {
			$parent = get_term( $parent_id, $term->taxonomy );
			if ( ! $parent instanceof WP_Term ) {
				continue;
			}
			$link = get_term_link( $parent );
			if ( is_wp_error( $link ) ) {
				continue;
			}
			$crumbs[] = array(
				'label' => wp_strip_all_tags( $parent->name ),
				'url'   => (string) $link,
			);
		}
		return $crumbs;
	}

	/**
	 * The primary category for a post, in order of preference: the plugin's own
	 * per-post "Primary category" control; then a Yoast or Rank Math primary
	 * category (so a site migrating from them keeps its curated path); then the
	 * first assigned category. A stored id no longer among the post's categories
	 * is ignored, so the choice self-heals. Filterable.
	 *
	 * @param WP_Post $post Post.
	 * @return WP_Term|null
	 */
	private static function primary_category( WP_Post $post ) {
		$categories = get_the_category( $post->ID );
		$chosen     = null;

		if ( ! empty( $categories ) ) {
			// Coywolf SEO's own choice first, then a Yoast/Rank Math value.
			$primary_id = (int) Coywolf_SEO_Options::post_meta( $post->ID )['primary_category'];
			if ( ! $primary_id ) {
				$primary_id = (int) get_post_meta( $post->ID, '_yoast_wpseo_primary_category', true );
			}
			if ( ! $primary_id ) {
				$primary_id = (int) get_post_meta( $post->ID, 'rank_math_primary_category', true );
			}
			if ( $primary_id ) {
				foreach ( $categories as $category ) {
					if ( (int) $category->term_id === $primary_id ) {
						$chosen = $category;
						break;
					}
				}
			}
			if ( null === $chosen ) {
				$chosen = $categories[0];
			}
		}

		/**
		 * Filter the category a post's breadcrumb path is built from.
		 *
		 * @param WP_Term|null $chosen The chosen primary category.
		 * @param WP_Post      $post   The post.
		 */
		$chosen = apply_filters( 'coywolf_seo_breadcrumb_primary_category', $chosen, $post );
		return $chosen instanceof WP_Term ? $chosen : null;
	}

	/**
	 * Assemble the final ordered item list from its parts. Pure — no WordPress
	 * calls — so it is unit-testable and the show-home/show-current rules live
	 * in one place.
	 *
	 * @param array|null $home      Home crumb (label/url) or null.
	 * @param array      $ancestors Ancestor crumbs, top-most first.
	 * @param array|null $current   Current crumb (label/url) or null.
	 * @param array      $opts      Resolved options (show_current honored here).
	 * @return array<int,array<string,mixed>>
	 */
	public static function assemble( $home, array $ancestors, $current, array $opts ) {
		$items = array();

		if ( is_array( $home ) && '' !== trim( (string) $home['label'] ) ) {
			$items[] = array(
				'label'   => (string) $home['label'],
				'url'     => (string) $home['url'],
				'current' => false,
				'home'    => true,
			);
		}

		foreach ( $ancestors as $crumb ) {
			if ( '' === trim( (string) $crumb['label'] ) ) {
				continue;
			}
			$items[] = array(
				'label'   => (string) $crumb['label'],
				'url'     => (string) $crumb['url'],
				'current' => false,
				'home'    => false,
			);
		}

		if ( ! empty( $opts['show_current'] ) && is_array( $current ) && '' !== trim( (string) $current['label'] ) ) {
			$items[] = array(
				'label'   => (string) $current['label'],
				'url'     => (string) $current['url'],
				'current' => true,
				'home'    => false,
			);
		}

		return $items;
	}

	/**
	 * Render an item list as an accessible breadcrumb <nav>. Pure string work —
	 * every dynamic value is escaped here.
	 *
	 * @param array $items Ordered items from assemble()/trail().
	 * @param array $opts  Resolved options (separator, class, aria_label).
	 * @return string
	 */
	public static function render_list( array $items, array $opts ) {
		if ( count( $items ) < 2 ) {
			return '';
		}

		$sep_class = isset( $opts['sep_class'] ) ? $opts['sep_class'] : 'coywolf-seo-breadcrumbs--sep-slash';
		$classes   = array( 'coywolf-seo-breadcrumbs', $sep_class );
		$extra     = self::sanitize_classes( isset( $opts['class'] ) ? $opts['class'] : '' );
		if ( '' !== $extra ) {
			$classes[] = $extra;
		}

		$out  = '<nav class="' . esc_attr( implode( ' ', $classes ) ) . '" aria-label="' . esc_attr( $opts['aria_label'] ) . '">';
		$out .= '<ol class="coywolf-seo-breadcrumbs__list">';

		foreach ( $items as $item ) {
			$li_class = 'coywolf-seo-breadcrumbs__item';
			if ( ! empty( $item['home'] ) ) {
				$li_class .= ' coywolf-seo-breadcrumbs__item--home';
			}
			if ( ! empty( $item['current'] ) ) {
				$li_class .= ' coywolf-seo-breadcrumbs__item--current';
			}
			$out .= '<li class="' . esc_attr( $li_class ) . '">';

			$label = esc_html( $item['label'] );
			if ( empty( $item['current'] ) && '' !== (string) $item['url'] ) {
				$out .= '<a class="coywolf-seo-breadcrumbs__link" href="' . esc_url( $item['url'] ) . '">' . $label . '</a>';
			} else {
				// The current page is not a link; aria-current marks it for AT.
				$out .= '<span class="coywolf-seo-breadcrumbs__current"' . ( ! empty( $item['current'] ) ? ' aria-current="page"' : '' ) . '>' . $label . '</span>';
			}

			$out .= '</li>';
		}

		$out .= '</ol></nav>';
		return $out;
	}

	/**
	 * Build the BreadcrumbList graph node for the Schema module from a trail.
	 *
	 * @param array  $items Ordered items from trail().
	 * @param string $id    The node @id (e.g. permalink . '#breadcrumb').
	 * @return array|null Null when there are fewer than two crumbs.
	 */
	public static function schema_node( array $items, $id ) {
		if ( count( $items ) < 2 ) {
			return null;
		}

		$list     = array();
		$position = 1;
		foreach ( $items as $item ) {
			$element = array(
				'@type'    => 'ListItem',
				'position' => $position,
				'name'     => (string) $item['label'],
			);
			if ( '' !== (string) $item['url'] ) {
				$element['item'] = (string) $item['url'];
			}
			$list[] = $element;
			++$position;
		}

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => (string) $id,
			'itemListElement' => $list,
		);
	}

	/**
	 * Sanitize a space-separated list of CSS class names.
	 *
	 * @param string $classes Raw class string.
	 * @return string
	 */
	private static function sanitize_classes( $classes ) {
		$clean = array();
		foreach ( preg_split( '/\s+/', (string) $classes, -1, PREG_SPLIT_NO_EMPTY ) as $class ) {
			$class = sanitize_html_class( $class );
			if ( '' !== $class ) {
				$clean[] = $class;
			}
		}
		return implode( ' ', $clean );
	}
}
