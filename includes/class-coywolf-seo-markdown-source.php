<?php
/**
 * Per-page Markdown source endpoints (Discovery / LLMs.txt feature).
 *
 * Exposes a Markdown representation of every public page/post at the
 * llmstxt.org-convention URL `<permalink>index.html.md`, generated from the
 * SAME render path as the HTML (`the_content`) so the two never drift. Also
 * implements `Accept: text/markdown` content negotiation on the canonical URL,
 * a `<head>` discovery link, and the `X-Markdown-Tokens` header.
 *
 * All of it is gated on the LLMs.txt feature being enabled AND the per-page
 * `.md` sub-option; when either is off nothing here registers (no route, no
 * head link, no headers).
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Markdown source endpoints.
 */
final class Coywolf_SEO_Markdown_Source {

	/**
	 * Query var holding the requested permalink path for an .md request.
	 */
	const QUERY_VAR = 'coywolf_seo_md';

	/**
	 * Sentinel path for the site root / static front page .md request.
	 */
	const HOME_TOKEN = '__home__';

	/**
	 * Post meta caching the rendered Markdown + token count, keyed by a content
	 * hash so it self-invalidates when the source changes.
	 */
	const META = '_coywolf_seo_md';

	/**
	 * Cached converter for the current request.
	 *
	 * @var \League\HTMLToMarkdown\HtmlConverter|null|false
	 */
	private static $converter = null;

	/**
	 * Whether the LLMs.txt feature is on AND the per-page .md sub-option is on.
	 *
	 * @return bool
	 */
	public function enabled() {
		return (bool) Coywolf_SEO_Options::get( 'llms_enabled' )
			&& (bool) Coywolf_SEO_Options::get( 'llms_md_endpoints' );
	}

	/**
	 * Register hooks. No-op unless enabled.
	 */
	public function init() {
		if ( ! $this->enabled() ) {
			return;
		}
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		// Priority 0: the .md route. Priority 1: Accept-based negotiation on the
		// canonical HTML URL.
		add_action( 'template_redirect', array( $this, 'maybe_serve' ), 0 );
		add_action( 'template_redirect', array( $this, 'maybe_negotiate' ), 1 );
		add_filter( 'wp_headers', array( $this, 'filter_headers' ) );
		add_action( 'wp_head', array( $this, 'output_head_link' ), 2 );
	}

	// ---------------------------------------------------------------------
	// Routing
	// ---------------------------------------------------------------------

	/**
	 * Register the `<permalink>index.html.md` rewrite rules. The suffix is
	 * specific enough that it never collides with real `index.html`, feeds, or
	 * pagination (those lack the `.md` tail).
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule( '^index\.html\.md/?$', 'index.php?' . self::QUERY_VAR . '=' . self::HOME_TOKEN, 'top' );
		add_rewrite_rule( '^(.+?)/index\.html\.md/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Register the .md query var.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Serve the .md representation for an `index.html.md` request.
	 */
	public function maybe_serve() {
		$path = (string) get_query_var( self::QUERY_VAR );
		if ( '' === $path ) {
			return;
		}
		$post = $this->resolve_post( $path );
		if ( ! $post ) {
			$this->not_found();
		}
		$this->serve( $post, false );
	}

	/**
	 * Content negotiation: when the canonical HTML URL is requested with an
	 * `Accept: text/markdown` preference, return the Markdown instead.
	 */
	public function maybe_negotiate() {
		if ( is_admin() || ! is_singular() || ! $this->accepts_markdown() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || ! self::is_public_post( $post ) ) {
			return;
		}
		$this->serve( $post, true );
	}

	/**
	 * Resolve a permalink path (or the home sentinel) to a public post.
	 *
	 * @param string $path Captured permalink path, or the home sentinel.
	 * @return WP_Post|null
	 */
	private function resolve_post( $path ) {
		if ( self::HOME_TOKEN === $path ) {
			$front = (int) get_option( 'page_on_front' );
			$post  = $front ? get_post( $front ) : null;
		} else {
			$id = url_to_postid( home_url( '/' . trailingslashit( $path ) ) );
			if ( ! $id ) {
				$id = url_to_postid( home_url( '/' . $path ) );
			}
			$post = $id ? get_post( $id ) : null;
		}
		return ( $post && self::is_public_post( $post ) ) ? $post : null;
	}

	/**
	 * Whether the request prefers Markdown via its Accept header.
	 *
	 * @return bool
	 */
	private function accepts_markdown() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- read-only substring check on a request header.
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? strtolower( (string) $_SERVER['HTTP_ACCEPT'] ) : '';
		return false !== strpos( $accept, 'text/markdown' );
	}

	// ---------------------------------------------------------------------
	// Serving + headers
	// ---------------------------------------------------------------------

	/**
	 * Emit the Markdown body and its headers, then stop.
	 *
	 * @param WP_Post $post       Post.
	 * @param bool    $negotiated Whether this is an Accept-negotiated response
	 *                            on the canonical URL (adds Content-Location +
	 *                            Vary).
	 * @return void
	 */
	private function serve( WP_Post $post, $negotiated ) {
		$data = $this->markdown_for( $post );

		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'X-Markdown-Tokens: ' . (int) $data['tokens'] );
		if ( $negotiated ) {
			header( 'Content-Location: ' . esc_url_raw( $this->md_url( $post ) ) );
			header( 'Vary: Accept' );
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markdown body served as text/markdown, not HTML.
		echo $data['md'];
		exit;
	}

	/**
	 * Send a 404 for an .md request and stop.
	 */
	private function not_found() {
		status_header( 404 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'Not found.';
		exit;
	}

	/**
	 * Add `Vary: Accept` to singular public HTML responses so caches keep the
	 * HTML and Markdown representations distinct.
	 *
	 * @param array $headers Response headers.
	 * @return array
	 */
	public function filter_headers( $headers ) {
		if ( is_singular() ) {
			$obj = get_queried_object();
			if ( $obj instanceof WP_Post && self::is_public_post( $obj ) ) {
				$headers['Vary'] = empty( $headers['Vary'] ) ? 'Accept' : $headers['Vary'] . ', Accept';
			}
		}
		return $headers;
	}

	/**
	 * Emit the `<head>` discovery link to the page's Markdown source.
	 */
	public function output_head_link() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || ! self::is_public_post( $post ) ) {
			return;
		}
		printf(
			'<link rel="alternate" type="text/markdown" href="%1$s" title="%2$s" />' . "\n",
			esc_url( $this->md_url( $post ) ),
			/* translators: %s: page title. */
			esc_attr( sprintf( __( '%s — Markdown source', 'coywolf-seo' ), get_the_title( $post ) ) )
		);
	}

	// ---------------------------------------------------------------------
	// Rendering
	// ---------------------------------------------------------------------

	/**
	 * The .md URL for a post: the trailing-slash permalink + index.html.md.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public function md_url( WP_Post $post ) {
		return trailingslashit( get_permalink( $post ) ) . 'index.html.md';
	}

	/**
	 * The rendered Markdown + token count for a post, cached by a content hash.
	 *
	 * @param WP_Post $post Post.
	 * @return array { string md, int tokens }
	 */
	public function markdown_for( WP_Post $post ) {
		$front = $this->frontmatter_data( $post );
		$hash  = md5( (string) $post->post_modified_gmt . '|' . (string) $post->post_content . '|' . (string) wp_json_encode( $front ) );

		$cached = get_post_meta( $post->ID, self::META, true );
		if ( is_array( $cached ) && isset( $cached['hash'], $cached['md'], $cached['tokens'] ) && $cached['hash'] === $hash ) {
			return $cached;
		}

		$body     = $this->render_body( $post );
		$markdown = $this->frontmatter_block( $front ) . "\n" . $body;
		$tokens   = $this->estimate_tokens( $markdown );

		$data = array(
			'hash'   => $hash,
			'md'     => $markdown,
			'tokens' => $tokens,
		);
		update_post_meta( $post->ID, self::META, $data );
		return $data;
	}

	/**
	 * Render a post's content to Markdown via the canonical the_content path
	 * (do_blocks, shortcodes, the plugin's own content filters), so the Markdown
	 * matches the HTML exactly. The global post is set up and restored so
	 * context-dependent filters behave as on the front end.
	 *
	 * @param WP_Post $target Post to render.
	 * @return string
	 */
	private function render_body( WP_Post $target ) {
		global $post;
		$saved = $post;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- set the loop context to render $target; the previous global is restored below.
		$post = $target;
		setup_postdata( $post );
		// Core's the_content filter renders the canonical content (blocks,
		// shortcodes, the plugin's own content filters) — the same source as
		// the HTML, so the two cannot drift.
		$html = apply_filters( 'the_content', $target->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- applying core's documented the_content filter.
		wp_reset_postdata();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restore the previous global post.
		$post = $saved;
		return self::html_to_markdown( (string) $html );
	}

	/**
	 * Agent-useful frontmatter fields (also the cache-hash input).
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	private function frontmatter_data( WP_Post $post ) {
		$url     = (string) get_permalink( $post );
		$data    = array(
			'title'   => self::decode_text( get_the_title( $post ) ),
			'url'     => $url,
			'updated' => (string) get_post_modified_time( 'c', true, $post ),
			'sources' => array( $url ),
		);
		$licence = trim( (string) Coywolf_SEO_Options::get( 'llms_licence' ) );
		if ( '' !== $licence ) {
			$data['licence'] = $licence;
		}
		if ( (bool) Coywolf_SEO_Options::get( 'llms_entities' ) ) {
			$entities = Coywolf_SEO_AI::stored_entities( $post->ID );
			if ( ! empty( $entities ) ) {
				$data['entities'] = array();
				foreach ( $entities as $e ) {
					$data['entities'][] = array(
						'name'     => self::decode_text( $e['name'] ),
						'relation' => $e['bucket'], // about | mentions.
						'type'     => $e['type'],
						'sameAs'   => $e['same_as'],
					);
				}
			}
		}
		return $data;
	}

	/**
	 * A rough token-count estimate. Dependency-free heuristic: ~4 characters per
	 * token (the cl100k_base average for English prose). Recomputed whenever the
	 * Markdown changes (via the content hash) — never hardcoded.
	 *
	 * @param string $text Markdown body.
	 * @return int
	 */
	private function estimate_tokens( $text ) {
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		return (int) max( 1, (int) ceil( $len / 4 ) );
	}

	// ---------------------------------------------------------------------
	// Shared visibility (mirrors the sitemap/OKF inclusion rules)
	// ---------------------------------------------------------------------

	/**
	 * Whether a post is part of the public surface: published, not password
	 * protected, a viewable public type, not per-post noindex. Mirrors the
	 * sitemap visibility so .md, llms.txt, and sitemaps agree.
	 *
	 * @param WP_Post|null $post Post.
	 * @return bool
	 */
	public static function is_public_post( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		if ( 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
			return false;
		}
		if ( ! is_post_type_viewable( $post->post_type ) || 'attachment' === $post->post_type ) {
			return false;
		}
		$meta = Coywolf_SEO_Options::post_meta( $post->ID );
		if ( ! empty( $meta['noindex'] ) ) {
			return false;
		}
		return true;
	}

	// ---------------------------------------------------------------------
	// Markdown helpers
	// ---------------------------------------------------------------------

	/**
	 * Normalize source-derived text (titles, names, labels) for the plain-text
	 * llms.txt and the .md frontmatter: fully decode HTML entities to real
	 * UTF-8 (so `Here&#8217;s` becomes `Here's`), collapse internal whitespace
	 * runs, and trim. Multibyte-safe; never written back to the post. The single
	 * helper both outputs route titles through so they cannot drift.
	 *
	 * @param string $text Source text (may contain HTML entities).
	 * @return string
	 */
	public static function decode_text( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// preg_replace() with the /u flag returns null when the subject is not
		// valid UTF-8; fall back to the un-collapsed string so a malformed title
		// (e.g. a truncated multibyte sequence) is never silently blanked.
		$collapsed = preg_replace( '/\s+/u', ' ', $text );
		return trim( null === $collapsed ? $text : $collapsed );
	}

	/**
	 * Convert rendered HTML to Markdown via league/html-to-markdown, loading the
	 * bundled autoloader lazily (like the AI client). Returns '' if the library
	 * is unavailable.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function html_to_markdown( $html ) {
		$converter = self::converter();
		if ( ! $converter ) {
			return trim( wp_strip_all_tags( (string) $html ) );
		}
		$previous = libxml_use_internal_errors( true );
		$markdown = (string) $converter->convert( (string) $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return trim( $markdown );
	}

	/**
	 * Build (once) the HTML→Markdown converter with table support.
	 *
	 * @return \League\HTMLToMarkdown\HtmlConverter|false
	 */
	private static function converter() {
		if ( null !== self::$converter ) {
			return self::$converter;
		}
		if ( ! class_exists( '\League\HTMLToMarkdown\HtmlConverter' ) ) {
			$autoload = COYWOLF_SEO_PATH . 'vendor/autoload.php';
			if ( is_readable( $autoload ) ) {
				require_once $autoload;
			}
		}
		if ( ! class_exists( '\League\HTMLToMarkdown\HtmlConverter' ) ) {
			self::$converter = false;
			return false;
		}
		// Build with the array config (its default converter set handles
		// emphasis/links/lists/code/etc.), then add table support to that same
		// environment. Building the environment manually drops the emphasis
		// converters, so do NOT use createDefaultEnvironment here.
		$converter = new \League\HTMLToMarkdown\HtmlConverter(
			array(
				'header_style' => 'atx',
				'strip_tags'   => true,
				'remove_nodes' => 'script style',
				'hard_break'   => true,
			)
		);
		$converter->getEnvironment()->addConverter( new \League\HTMLToMarkdown\Converter\TableConverter() );
		self::$converter = $converter;
		return self::$converter;
	}

	/**
	 * Emit a YAML frontmatter block for the given fields.
	 *
	 * @param array $fields Field => scalar|array.
	 * @return string
	 */
	private function frontmatter_block( array $fields ) {
		$lines = array( '---' );
		foreach ( $fields as $key => $value ) {
			if ( 'entities' === $key && is_array( $value ) ) {
				$lines[] = 'entities:';
				foreach ( $value as $entity ) {
					$lines[] = '  - name: ' . $this->yaml( $entity['name'] );
					$lines[] = '    relation: ' . $this->yaml( $entity['relation'] );
					$lines[] = '    type: ' . $this->yaml( $entity['type'] );
					if ( ! empty( $entity['sameAs'] ) ) {
						$lines[] = '    sameAs:';
						foreach ( $entity['sameAs'] as $url ) {
							$lines[] = '      - ' . $this->yaml( $url );
						}
					}
				}
				continue;
			}
			if ( is_array( $value ) ) {
				if ( empty( $value ) ) {
					continue;
				}
				$lines[] = $key . ':';
				foreach ( $value as $item ) {
					$lines[] = '  - ' . $this->yaml( $item );
				}
				continue;
			}
			if ( '' === trim( (string) $value ) ) {
				continue;
			}
			$lines[] = $key . ': ' . $this->yaml( $value );
		}
		$lines[] = '---';
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Quote/escape a scalar for a YAML double-quoted string.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function yaml( $value ) {
		$value = str_replace( array( "\r\n", "\r", "\n" ), ' ', (string) $value );
		$value = str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value );
		return '"' . $value . '"';
	}
}
