<?php
/**
 * /llms.txt generation and serving (Discovery / LLMs.txt feature).
 *
 * Serves a spec-conformant llms.txt (https://llmstxt.org/) at the site root: an
 * H1 site name, a blockquote summary, optional detail, H2 "file lists" linking
 * each public page/post's Markdown source, an entities section sourced from the
 * AI enrichment, and a `## Optional` section for the long tail.
 *
 * Ownership of /llms.txt lives here (a core feature). The Labs OKF feature no
 * longer serves llms.txt itself; instead, when its public advertising is on,
 * this file integrates an Open Knowledge Format section. So:
 *   - LLMs.txt on  -> full llms.txt (+ an OKF section when OKF advertising is on).
 *   - LLMs.txt off + OKF advertising on -> a minimal llms.txt with just the OKF
 *     bundle reference (the prior OKF behavior).
 *   - both off -> nothing served by this plugin.
 *
 * Never overwrites an llms.txt owned by another plugin/theme/static file: it
 * detects one at the site root and defers, surfacing a notice in Settings.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns /llms.txt generation and serving.
 */
final class Coywolf_SEO_Llms_Txt {

	/**
	 * Endpoint query var.
	 */
	const QUERY_VAR = 'coywolf_seo_llms';

	/**
	 * Cached, generated full body.
	 */
	const CACHE_OPTION = 'coywolf_seo_llms_cache';

	/**
	 * Debounced background-rebuild cron hook.
	 */
	const CRON_HOOK = 'coywolf_seo_llms_rebuild';

	/**
	 * Max links per post type in the main file lists; the rest go to ## Optional.
	 */
	const MAIN_LIMIT = 100;

	/**
	 * Max overflow links carried under ## Optional.
	 */
	const OPTIONAL_LIMIT = 300;

	/**
	 * Max distinct entities listed (most frequent first).
	 */
	const ENTITY_LIMIT = 100;

	/**
	 * Whether the LLMs.txt feature itself is enabled.
	 *
	 * @return bool
	 */
	public function enabled() {
		return (bool) Coywolf_SEO_Options::get( 'llms_enabled' );
	}

	/**
	 * Whether /llms.txt should be served at all: the feature is on, OR the Labs
	 * OKF feature wants its bundle advertised (so it can carry the OKF details).
	 *
	 * @return bool
	 */
	public function active() {
		return $this->enabled() || $this->okf_advertise_active();
	}

	/**
	 * Register hooks. No-op unless active.
	 */
	public function init() {
		add_action( self::CRON_HOOK, array( $this, 'run_rebuild' ) );
		add_action( 'admin_post_coywolf_seo_llms_rebuild', array( $this, 'handle_rebuild' ) );

		if ( ! $this->active() ) {
			return;
		}
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve' ), 0 );

		if ( $this->enabled() ) {
			foreach ( array( 'save_post', 'deleted_post', 'edited_term', 'created_term', 'delete_term' ) as $hook ) {
				add_action( $hook, array( $this, 'schedule_rebuild' ), 10, 0 );
			}
		}
	}

	// ---------------------------------------------------------------------
	// Routing / serving
	// ---------------------------------------------------------------------

	/**
	 * Register the /llms.txt rewrite rule.
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule( '^llms\.txt$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * Register the endpoint query var.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Serve /llms.txt (deferring to any physical file owned elsewhere).
	 */
	public function maybe_serve() {
		if ( '1' !== (string) get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		if ( $this->physical_llms_txt_exists() ) {
			return; // Never clobber a file we don't own.
		}
		$body = $this->body();
		if ( '' === $body ) {
			return;
		}
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text llms.txt body.
		echo $body;
		exit;
	}

	// ---------------------------------------------------------------------
	// Body
	// ---------------------------------------------------------------------

	/**
	 * The llms.txt body for the current state, using the cache for the full
	 * (feature-on) form.
	 *
	 * @return string
	 */
	public function body() {
		if ( $this->enabled() ) {
			$cache = get_option( self::CACHE_OPTION, '' );
			if ( is_string( $cache ) && '' !== $cache ) {
				return $cache;
			}
			return $this->rebuild_now();
		}
		if ( $this->okf_advertise_active() ) {
			return $this->minimal_okf_body();
		}
		return '';
	}

	/**
	 * Build the full llms.txt body (the expensive path; cached).
	 *
	 * @return string
	 */
	private function full_body() {
		$name    = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
		$tagline = wp_strip_all_tags( (string) get_bloginfo( 'description' ) );

		$out  = '# ' . ( '' !== $name ? $name : home_url( '/' ) ) . "\n\n";
		$out .= '> ' . ( '' !== $tagline ? $tagline : __( 'A curated, agent-readable index of this site\'s public content.', 'coywolf-seo' ) ) . "\n\n";
		$out .= __( 'Each link below points at the Markdown source of a page, so an agent can read the content directly.', 'coywolf-seo' ) . "\n";

		// Fetch the included posts once, keyed by type, and reuse them for both
		// the canonical content-type listing and the entity topic index.
		$by_type = array();
		foreach ( $this->post_types() as $post_type => $label ) {
			$posts = $this->included_posts( $post_type );
			if ( ! empty( $posts ) ) {
				$by_type[ $post_type ] = array(
					'label' => $label,
					'posts' => $posts,
				);
			}
		}

		// Canonical listing by content type — the backbone of the file.
		$optional = array();
		foreach ( $by_type as $data ) {
			$main = array_slice( $data['posts'], 0, self::MAIN_LIMIT );
			$rest = array_slice( $data['posts'], self::MAIN_LIMIT );

			$out .= "\n## " . $data['label'] . "\n\n";
			foreach ( $main as $post ) {
				$out .= $this->link_line( $post );
			}
			foreach ( $rest as $post ) {
				$optional[] = $post;
			}
		}

		$okf = $this->okf_section();
		if ( '' !== $okf ) {
			$out .= "\n" . $okf;
		}

		// Entity topic index: a plain-text H2 per significant entity, grouping
		// the site's own articles. Placed in the main body or under Optional.
		$entities  = $this->entity_sections( $by_type );
		$placement = (string) Coywolf_SEO_Options::get( 'llms_entity_placement' );
		if ( '' !== $entities && 'main' === $placement ) {
			$out .= "\n" . $entities;
		}

		$optional     = array_slice( $optional, 0, self::OPTIONAL_LIMIT );
		$entities_opt = ( '' !== $entities && 'optional' === $placement ) ? $entities : '';
		if ( ! empty( $optional ) || '' !== $entities_opt ) {
			$out .= "\n## Optional\n\n";
			foreach ( $optional as $post ) {
				$out .= $this->link_line( $post );
			}
			if ( '' !== $entities_opt ) {
				if ( ! empty( $optional ) ) {
					$out .= "\n";
				}
				$out .= __( "Topic index — the site's articles grouped by the entities they are primarily about:", 'coywolf-seo' ) . "\n\n";
				$out .= $entities_opt;
			}
		}

		return $out;
	}

	/**
	 * The minimal llms.txt served when only the OKF feature is on (no LLMs.txt
	 * feature). H1 + summary + the OKF section.
	 *
	 * @return string
	 */
	private function minimal_okf_body() {
		$name    = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
		$tagline = wp_strip_all_tags( (string) get_bloginfo( 'description' ) );

		$out = '# ' . ( '' !== $name ? $name : home_url( '/' ) ) . "\n\n";
		if ( '' !== $tagline ) {
			$out .= '> ' . $tagline . "\n\n";
		}
		$okf = $this->okf_section();
		return '' !== $okf ? $out . $okf : '';
	}

	/**
	 * The entity topic index: a plain-text H2 per "significant" entity — one
	 * that is the `about` (primary) subject of at least the configured minimum
	 * number of published articles — listing the site's OWN articles about or
	 * mentioning it, linked to their Markdown sources. No sameAs / external
	 * identifiers appear here (those stay in the .md frontmatter + OKF bundle);
	 * this is a topic index INTO the site, not a pointer at general knowledge.
	 *
	 * @param array $by_type Included posts keyed by type ( type => { label, posts } ).
	 * @return string Markdown, or '' when nothing clears the threshold.
	 */
	private function entity_sections( array $by_type ) {
		if ( ! (bool) Coywolf_SEO_Options::get( 'llms_entities' ) ) {
			return '';
		}
		$min   = max( 1, (int) Coywolf_SEO_Options::get( 'llms_entity_min' ) );
		$index = $this->entity_article_index( $by_type );

		// Promote only entities that are the primary subject of >= $min articles.
		$promoted = array();
		foreach ( $index as $entity ) {
			if ( count( $entity['about'] ) >= $min ) {
				$promoted[] = $entity;
			}
		}
		if ( empty( $promoted ) ) {
			return '';
		}
		// Most-covered entities first, then alphabetical.
		usort(
			$promoted,
			static function ( $a, $b ) {
				$diff = count( $b['about'] ) - count( $a['about'] );
				return 0 !== $diff ? $diff : strcasecmp( $a['name'], $b['name'] );
			}
		);
		$promoted = array_slice( $promoted, 0, self::ENTITY_LIMIT );

		$out = '';
		foreach ( $promoted as $entity ) {
			$out .= '## ' . $this->text( $entity['name'] ) . "\n\n";
			foreach ( $this->by_recent( $entity['about'] ) as $post ) {
				$out .= $this->entity_link_line( $post, __( 'Primary topic', 'coywolf-seo' ) );
			}
			foreach ( $this->by_recent( $entity['mentions'] ) as $post ) {
				$out .= $this->entity_link_line( $post, __( 'Mentioned', 'coywolf-seo' ) );
			}
			$out .= "\n";
		}
		return rtrim( $out ) . "\n";
	}

	/**
	 * Build the inverse entity->articles index in one pass over the already-
	 * fetched included posts. The stored data is article->entities (the same
	 * source per-article schema uses); this buckets each article under every
	 * entity it is `about` or `mentions`, deduped by Wikidata QID.
	 *
	 * @param array $by_type Included posts keyed by type ( type => { label, posts } ).
	 * @return array qid => array{ name:string, type:string, about:WP_Post[], mentions:WP_Post[] }
	 */
	private function entity_article_index( array $by_type ) {
		$index = array();
		foreach ( $by_type as $data ) {
			foreach ( $data['posts'] as $post ) {
				foreach ( Coywolf_SEO_AI::stored_entities( $post->ID ) as $entity ) {
					$qid = $entity['qid'];
					if ( '' === $qid ) {
						continue;
					}
					if ( ! isset( $index[ $qid ] ) ) {
						$index[ $qid ] = array(
							'name'     => $entity['name'],
							'type'     => $entity['type'],
							'about'    => array(),
							'mentions' => array(),
						);
					}
					if ( 'about' === $entity['bucket'] ) {
						$index[ $qid ]['about'][] = $post;
					} else {
						$index[ $qid ]['mentions'][] = $post;
					}
				}
			}
		}
		return $index;
	}

	/**
	 * A file-list link to an article's Markdown source with a fixed relation
	 * note ("Primary topic" / "Mentioned").
	 *
	 * @param WP_Post $post Post.
	 * @param string  $note Relation note.
	 * @return string
	 */
	private function entity_link_line( $post, $note ) {
		return '- [' . $this->text( get_the_title( $post ) ) . '](' . esc_url_raw( $this->page_url( $post ) ) . '): ' . $this->text( $note ) . "\n";
	}

	/**
	 * Sort posts newest first, for a stable order within an entity section.
	 *
	 * @param WP_Post[] $posts Posts.
	 * @return WP_Post[]
	 */
	private function by_recent( array $posts ) {
		usort(
			$posts,
			static function ( $a, $b ) {
				return strcmp( (string) $b->post_date, (string) $a->post_date );
			}
		);
		return $posts;
	}

	/**
	 * The Open Knowledge Format section, when the Labs OKF feature is advertising.
	 *
	 * @return string
	 */
	private function okf_section() {
		$okf = $this->okf();
		if ( ! $okf || ! $this->okf_advertise_active() || ! method_exists( $okf, 'llms_reference_line' ) ) {
			return '';
		}
		return "## Open Knowledge Format\n\n- [" . $okf->llms_reference_line() . "\n";
	}

	/**
	 * A single file-list link to a post's Markdown source (or its HTML URL when
	 * the .md sub-option is off).
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private function link_line( $post ) {
		$url  = $this->page_url( $post );
		$note = $this->note_for( $post );
		$line = '- [' . $this->text( get_the_title( $post ) ) . '](' . esc_url_raw( $url ) . ')';
		if ( '' !== $note ) {
			$line .= ': ' . $this->text( $note );
		}
		return $line . "\n";
	}

	/**
	 * The URL a file-list entry points at: the .md source when that sub-option
	 * is on, else the canonical HTML URL.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private function page_url( $post ) {
		$permalink = (string) get_permalink( $post );
		if ( (bool) Coywolf_SEO_Options::get( 'llms_md_endpoints' ) ) {
			return trailingslashit( $permalink ) . 'index.html.md';
		}
		return $permalink;
	}

	/**
	 * A concise note for a file-list entry: the manual excerpt if present.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private function note_for( $post ) {
		return trim( (string) $post->post_excerpt );
	}

	// ---------------------------------------------------------------------
	// Content selection
	// ---------------------------------------------------------------------

	/**
	 * Public post types to list, labeled, honoring the sitemap exclusion
	 * options for posts/pages.
	 *
	 * @return array post_type => label
	 */
	private function post_types() {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name || ! is_post_type_viewable( $type ) ) {
				continue;
			}
			if ( 'post' === $type->name && Coywolf_SEO_Options::get( 'sitemap_exclude_posts' ) ) {
				continue;
			}
			if ( 'page' === $type->name && Coywolf_SEO_Options::get( 'sitemap_exclude_pages' ) ) {
				continue;
			}
			$out[ $type->name ] = ! empty( $type->labels->name ) ? $type->labels->name : $type->name;
		}
		return $out;
	}

	/**
	 * Public, published, indexable posts of a type, newest first.
	 *
	 * @param string $post_type Post type.
	 * @return WP_Post[]
	 */
	private function included_posts( $post_type ) {
		$posts = get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => 'publish',
				'numberposts'      => self::MAIN_LIMIT + self::OPTIONAL_LIMIT,
				'has_password'     => false,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => true,
			)
		);
		return array_values( array_filter( $posts, array( 'Coywolf_SEO_Markdown_Source', 'is_public_post' ) ) );
	}

	// ---------------------------------------------------------------------
	// Cache / rebuild
	// ---------------------------------------------------------------------

	/**
	 * Rebuild the cached full body now and store it.
	 *
	 * @return string The generated body.
	 */
	public function rebuild_now() {
		$body = $this->full_body();
		update_option( self::CACHE_OPTION, $body, false );
		return $body;
	}

	/**
	 * Cron callback: rebuild the cache when the feature is on.
	 */
	public function run_rebuild() {
		if ( $this->enabled() ) {
			$this->rebuild_now();
		}
	}

	/**
	 * Debounced background rebuild after content changes.
	 */
	public function schedule_rebuild() {
		if ( $this->enabled() && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK );
		}
	}

	/**
	 * Drop the cached body (forces a regenerate on the next serve).
	 */
	public function clear_cache() {
		delete_option( self::CACHE_OPTION );
	}

	/**
	 * Admin-post handler for the manual "Rebuild llms.txt" action.
	 */
	public function handle_rebuild() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Coywolf SEO settings.', 'coywolf-seo' ) );
		}
		check_admin_referer( 'coywolf_seo_llms_rebuild' );
		if ( $this->enabled() ) {
			$this->rebuild_now();
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => Coywolf_SEO_Admin::SLUG_SETTINGS,
					'coywolf-seo-saved' => 'llms-rebuilt',
				),
				admin_url( 'admin.php' )
			) . '#coywolf-seo-section-llms'
		);
		exit;
	}

	// ---------------------------------------------------------------------
	// Conflict detection + URLs
	// ---------------------------------------------------------------------

	/**
	 * Whether a physical llms.txt exists at the site root (owned elsewhere).
	 *
	 * @return bool
	 */
	public function physical_llms_txt_exists() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$home = get_home_path();
		if ( ! $home || '/' === $home ) {
			return false;
		}
		return is_readable( trailingslashit( $home ) . 'llms.txt' );
	}

	/**
	 * The public /llms.txt URL.
	 *
	 * @return string
	 */
	public function llms_txt_url() {
		return home_url( '/llms.txt' );
	}

	// ---------------------------------------------------------------------
	// OKF integration (guarded; OKF/Labs is absent in the .org build)
	// ---------------------------------------------------------------------

	/**
	 * The Labs OKF feature, or null when Labs is absent. Uses the singleton, so
	 * only safe at request time (NOT during plugin construction).
	 *
	 * @return Coywolf_SEO_OKF|null
	 */
	private function okf() {
		if ( ! class_exists( 'Coywolf_SEO_Labs' ) ) {
			return null;
		}
		$labs = Coywolf_SEO::instance()->labs();
		return ( $labs && method_exists( $labs, 'okf' ) ) ? $labs->okf() : null;
	}

	/**
	 * Whether the OKF feature's public advertising is active. Reads the OKF
	 * option DIRECTLY (mirroring the OKF feature's own defaults) rather than via
	 * the singleton, so it is safe to call from init() during plugin
	 * construction — calling Coywolf_SEO::instance() there would recurse.
	 *
	 * @return bool
	 */
	private function okf_advertise_active() {
		if ( ! class_exists( 'Coywolf_SEO_OKF' ) ) {
			return false; // Labs (and thus OKF) absent — e.g. the WordPress.org build.
		}
		$okf = get_option( 'coywolf_seo_okf', array() );
		if ( ! is_array( $okf ) || empty( $okf['enabled'] ) ) {
			return false;
		}
		// Mirror Coywolf_SEO_OKF::settings() defaults (advertise + endpoint on).
		$advertise = array_key_exists( 'advertise', $okf ) ? ! empty( $okf['advertise'] ) : true;
		$endpoint  = array_key_exists( 'live_endpoint', $okf ) ? ! empty( $okf['live_endpoint'] ) : true;
		return $advertise && $endpoint && (bool) get_option( 'blog_public', 1 );
	}

	/**
	 * Flatten text to a single line and escape Markdown link-breaking chars.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function text( $text ) {
		$text = str_replace( array( "\r\n", "\r", "\n" ), ' ', (string) $text );
		$text = str_replace( array( '[', ']' ), array( '\[', '\]' ), $text );
		return trim( $text );
	}
}
