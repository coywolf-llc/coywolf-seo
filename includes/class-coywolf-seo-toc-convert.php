<?php
/**
 * Yoast Table of Contents → Coywolf Table of Contents converter.
 *
 * Rewrites every `yoast-seo/table-of-contents` block in the site's posts and
 * pages into the Coywolf SEO Table of Contents block, carrying the Yoast
 * block's per-post settings across so the result is equivalent:
 *
 *   - the title text and its heading level,
 *   - the range of heading levels the table lists (Yoast's "maximum heading
 *     level" becomes Coywolf's H2…max level set).
 *
 * Yoast saves a static heading list into the block; Coywolf's block is dynamic
 * and rebuilds the list from the page's real headings at view time, so the
 * saved list is dropped — the converted table can never drift out of sync.
 *
 * Two entry points, both landing here: a dismissible banner shown after
 * activation when Yoast tables of contents are found, and a one-off operation
 * at the bottom of the Settings page. The operation runs in chunks over
 * admin-ajax (Preview is a dry run; Replace applies), mirroring the image-ID
 * fixer, so it survives large sites.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts Yoast tables of contents to the Coywolf block.
 */
final class Coywolf_SEO_TOC_Convert {

	/**
	 * Run-state option (status, counters, cursor).
	 */
	const STATE_OPTION = 'coywolf_seo_toc_convert';

	/**
	 * Option recording that the after-activation banner was dismissed.
	 */
	const DISMISSED_OPTION = 'coywolf_seo_toc_convert_dismissed';

	/**
	 * The Yoast block being replaced.
	 */
	const YOAST_BLOCK = 'yoast-seo/table-of-contents';

	/**
	 * The Coywolf block it becomes.
	 */
	const COYWOLF_BLOCK = 'coywolf-seo/table-of-contents';

	/**
	 * Content marker (the block delimiter) used to prefilter candidates.
	 */
	const MARKER = 'wp:yoast-seo/table-of-contents';

	/**
	 * Yoast's default title — when a block still carries it, we let Coywolf's
	 * own translatable default stand instead of baking the English literal in.
	 */
	const YOAST_DEFAULT_TITLE = 'Table of contents';

	/**
	 * Posts processed per step.
	 */
	const CHUNK = 25;

	/**
	 * How many example conversions to surface in the result.
	 */
	const MAX_SAMPLES = 3;

	/**
	 * Per-request cache of the candidate count (for the banner).
	 *
	 * @var int|null
	 */
	private $candidate_cache = null;

	/**
	 * Hooks.
	 */
	public function init() {
		add_action( 'wp_ajax_coywolf_seo_toc_convert', array( $this, 'ajax' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_banner' ) );
		add_action( 'admin_post_coywolf_seo_dismiss_toc_convert', array( $this, 'handle_dismiss' ) );
	}

	/* --------------------------------------------------------------------- *
	 * After-activation banner
	 * --------------------------------------------------------------------- */

	/**
	 * Offer the conversion when Yoast tables of contents are present. Scoped to
	 * this plugin's own screens (like the redirect-import banner) so it never
	 * nags site-wide, and hidden once dismissed or once none remain.
	 */
	public function maybe_show_banner() {
		if ( ! current_user_can( Coywolf_SEO_Admin::CAPABILITY ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'coywolf-seo' ) ) {
			return;
		}
		if ( ! empty( get_option( self::DISMISSED_OPTION, false ) ) ) {
			return;
		}
		$count = $this->candidate_count();
		if ( $count < 1 ) {
			return;
		}

		$url = admin_url( 'admin.php?page=' . Coywolf_SEO_Admin::SLUG_SETTINGS ) . '#coywolf-seo-section-toc-convert';
		?>
		<div class="notice notice-info coywolf-seo-import-banner">
			<p>
				<strong>
				<?php
				printf(
					/* translators: %d: number of posts and pages that use a Yoast table of contents. */
					esc_html( _n( '%d post or page uses a Yoast SEO table of contents. Replace it with the Coywolf SEO table of contents?', '%d posts and pages use a Yoast SEO table of contents. Replace them with the Coywolf SEO table of contents?', $count, 'coywolf-seo' ) ),
					(int) $count
				);
				?>
				</strong>
			</p>
			<p>
				<a href="<?php echo esc_url( $url ); ?>" class="button button-primary"><?php esc_html_e( 'Review and replace', 'coywolf-seo' ); ?></a>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="coywolf-seo-inline-form">
					<input type="hidden" name="action" value="coywolf_seo_dismiss_toc_convert" />
					<?php wp_nonce_field( 'coywolf_seo_dismiss_toc_convert' ); ?>
					<button type="submit" class="button-link"><?php esc_html_e( 'Dismiss', 'coywolf-seo' ); ?></button>
				</form>
			</p>
		</div>
		<?php
	}

	/**
	 * Remember the banner dismissal.
	 */
	public function handle_dismiss() {
		if ( ! current_user_can( Coywolf_SEO_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'coywolf-seo' ) );
		}
		check_admin_referer( 'coywolf_seo_dismiss_toc_convert' );
		update_option( self::DISMISSED_OPTION, 1, false );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/* --------------------------------------------------------------------- *
	 * AJAX (Settings page)
	 * --------------------------------------------------------------------- */

	/**
	 * Single AJAX entry point: op = start | step | cancel | status.
	 */
	public function ajax() {
		check_ajax_referer( 'coywolf_seo_toc_convert' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array(), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked above.
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		switch ( $op ) {
			case 'start':
				wp_send_json_success( $this->start( ! empty( $_POST['dry_run'] ) ) );
				break;
			case 'step':
				wp_send_json_success( $this->step() );
				break;
			case 'cancel':
				delete_option( self::STATE_OPTION );
				wp_send_json_success( array( 'status' => 'idle' ) );
				break;
			default:
				wp_send_json_success( $this->get_state() );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Current run state.
	 *
	 * @return array
	 */
	public function get_state() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) && ! empty( $state ) ? $state : array( 'status' => 'idle' );
	}

	/**
	 * Begin a run (dry-run preview or a real apply).
	 *
	 * @param bool $dry_run Count what would change without saving.
	 * @return array State.
	 */
	private function start( $dry_run ) {
		$total = $this->candidate_count();
		$state = array(
			'status'          => $total > 0 ? 'running' : 'done',
			'dry_run'         => (bool) $dry_run,
			'last_id'         => 0,
			'total'           => $total,
			'posts_scanned'   => 0,
			'posts_updated'   => 0,
			'blocks_replaced' => 0,
			'samples'         => array(),
			'started'         => gmdate( 'c' ),
			'finished'        => $total > 0 ? '' : gmdate( 'c' ),
		);
		update_option( self::STATE_OPTION, $state, false );
		return $state;
	}

	/**
	 * Process the next chunk of posts.
	 *
	 * @return array State.
	 */
	public function step() {
		$state = $this->get_state();
		if ( empty( $state['status'] ) || 'running' !== $state['status'] ) {
			return $state;
		}
		$ids = $this->next_posts( (int) $state['last_id'], self::CHUNK );
		if ( empty( $ids ) ) {
			$state['status']   = 'done';
			$state['finished'] = gmdate( 'c' );
			update_option( self::STATE_OPTION, $state, false );
			return $state;
		}
		if ( ! isset( $state['samples'] ) || ! is_array( $state['samples'] ) ) {
			$state['samples'] = array();
		}
		foreach ( $ids as $post_id ) {
			$post_id          = (int) $post_id;
			$state['last_id'] = $post_id;
			$result           = $this->convert_post( $post_id, ! empty( $state['dry_run'] ) );
			++$state['posts_scanned'];
			$state['blocks_replaced'] += $result['replaced'];
			if ( $result['changed'] ) {
				++$state['posts_updated'];
				if ( count( $state['samples'] ) < self::MAX_SAMPLES ) {
					$sample = $this->build_sample( $post_id, (int) $result['replaced'] );
					if ( $sample ) {
						$state['samples'][] = $sample;
					}
				}
			}
		}
		update_option( self::STATE_OPTION, $state, false );
		return $state;
	}

	/* --------------------------------------------------------------------- *
	 * Candidate queries
	 * --------------------------------------------------------------------- */

	/**
	 * How many posts/pages carry a Yoast table of contents (the work set).
	 *
	 * @return int
	 */
	public function candidate_count() {
		if ( null !== $this->candidate_cache ) {
			return $this->candidate_cache;
		}
		global $wpdb;
		$like = '%' . $wpdb->esc_like( self::MARKER ) . '%';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- maintenance scan, not cacheable.
		$this->candidate_cache = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('post','page') AND post_status NOT IN ('trash','auto-draft','inherit') AND post_content LIKE %s",
				$like
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $this->candidate_cache;
	}

	/**
	 * Next chunk of candidate post IDs after the cursor.
	 *
	 * @param int $after Cursor (last processed ID).
	 * @param int $limit Chunk size.
	 * @return int[]
	 */
	private function next_posts( $after, $limit ) {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( self::MARKER ) . '%';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- maintenance scan, not cacheable.
		$ids = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('post','page') AND post_status NOT IN ('trash','auto-draft','inherit') AND post_content LIKE %s AND ID > %d ORDER BY ID ASC LIMIT %d",
					$like,
					(int) $after,
					(int) $limit
				)
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $ids;
	}

	/* --------------------------------------------------------------------- *
	 * Per-post conversion
	 * --------------------------------------------------------------------- */

	/**
	 * Replace the Yoast tables of contents in one post.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $dry_run Count only; do not save.
	 * @return array { replaced:int, changed:bool }
	 */
	private function convert_post( $post_id, $dry_run ) {
		$out  = array(
			'replaced' => 0,
			'changed'  => false,
		);
		$post = get_post( $post_id );
		if ( ! $post || false === strpos( (string) $post->post_content, self::MARKER ) ) {
			return $out;
		}

		$state  = array(
			'replaced' => 0,
			'changed'  => false,
		);
		$blocks = $this->convert_blocks( parse_blocks( (string) $post->post_content ), $state );

		$out['replaced'] = $state['replaced'];
		$out['changed']  = $state['changed'];

		if ( $state['changed'] && ! $dry_run ) {
			$new = serialize_blocks( $blocks );
			if ( $new !== (string) $post->post_content ) {
				global $wpdb;
				// Write post_content directly rather than via wp_update_post():
				// this is a mechanical block swap, and the normal save path would
				// fire transition_post_status / wp_after_insert_post on every post
				// — re-queuing AI analysis, pinging IndexNow, re-indexing links,
				// creating revisions, and bumping the modified date. None of that
				// belongs to a table-of-contents swap, so bypass it and just flush
				// the post cache — the same approach as the image-ID fixer.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk content maintenance; post cache cleared immediately below.
				$wpdb->update( $wpdb->posts, array( 'post_content' => $new ), array( 'ID' => $post_id ) );
				clean_post_cache( $post_id );
			}
		}
		return $out;
	}

	/**
	 * Walk parsed blocks and replace each Yoast table of contents with the
	 * Coywolf block, mapping the Yoast settings across. Mutates $state counters.
	 * Recurses into innerBlocks (a TOC can live inside columns/groups).
	 *
	 * @param array $blocks Parsed blocks.
	 * @param array $state  { replaced, changed }.
	 * @return array Modified blocks.
	 */
	private function convert_blocks( array $blocks, array &$state ) {
		return Coywolf_SEO_Block_Walker::map(
			$blocks,
			function ( $block ) use ( &$state ) {
				if ( isset( $block['blockName'] ) && self::YOAST_BLOCK === $block['blockName'] ) {
					$attrs = $this->map_yoast_toc( $block );
					// A dynamic (server-rendered) block: no saved markup, so the
					// list is rebuilt live. serialize_blocks() emits the void form
					// <!-- wp:coywolf-seo/table-of-contents {…} /-->.
					$block = array(
						'blockName'    => self::COYWOLF_BLOCK,
						'attrs'        => $attrs,
						'innerBlocks'  => array(),
						'innerHTML'    => '',
						'innerContent' => array(),
					);
					++$state['replaced'];
					$state['changed'] = true;
				}
				return $block;
			}
		);
	}

	/**
	 * Map a Yoast table-of-contents block's settings to Coywolf block attributes.
	 *
	 * Yoast's attributes (from its block.json): `title` (sourced from the saved
	 * <h1..h6>), `level` (that heading's level), `maxHeadingLevel` (headings are
	 * listed from H2 up to this), and the saved `headings` list (dropped — the
	 * Coywolf block regenerates it). We read the title and its level from the
	 * saved markup (the source of truth), falling back to the `title`/`level`
	 * attributes, and turn `maxHeadingLevel` into Coywolf's explicit level set.
	 *
	 * @param array $block Parsed Yoast block.
	 * @return array Coywolf block attributes.
	 */
	private function map_yoast_toc( $block ) {
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$html  = isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';

		// Title text + level: prefer the saved heading element.
		$title       = '';
		$title_level = isset( $attrs['level'] ) ? (int) $attrs['level'] : 0;
		if ( preg_match( '/<h([1-6])\b[^>]*>(.*?)<\/h\1\s*>/is', $html, $m ) ) {
			$title_level = (int) $m[1];
			$title       = trim( wp_strip_all_tags( $m[2] ) );
		}
		if ( '' === $title && isset( $attrs['title'] ) ) {
			$title = trim( wp_strip_all_tags( (string) $attrs['title'] ) );
		}
		if ( $title_level < 2 || $title_level > 6 ) {
			$title_level = 2;
		}

		// maxHeadingLevel → the H2…max level set.
		$max    = isset( $attrs['maxHeadingLevel'] ) ? (int) $attrs['maxHeadingLevel'] : 3;
		$max    = max( 2, min( 6, $max ) );
		$levels = range( 2, $max );

		$out = array(
			'titleLevel' => $title_level,
			'levels'     => $levels,
		);
		// Only carry a genuinely custom title across; a default one is left to
		// the Coywolf block's own (translatable) default.
		if ( '' !== $title && 0 !== strcasecmp( $title, self::YOAST_DEFAULT_TITLE ) ) {
			$out['title'] = $title;
		}
		return $out;
	}

	/**
	 * Build one preview sample: a converted post's title, edit link, and how
	 * many tables it has.
	 *
	 * @param int $post_id  Post converted.
	 * @param int $replaced Tables replaced in it.
	 * @return array|false { title, edit, post, count }
	 */
	private function build_sample( $post_id, $replaced ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return false;
		}
		$title = get_the_title( $post_id );
		$edit  = get_edit_post_link( $post_id, 'raw' );
		return array(
			'title' => '' !== (string) $title ? (string) $title : __( '(no title)', 'coywolf-seo' ),
			'edit'  => $edit ? (string) $edit : '',
			'post'  => $post_id,
			'count' => (int) $replaced,
		);
	}
}
