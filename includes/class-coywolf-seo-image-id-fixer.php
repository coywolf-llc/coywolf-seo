<?php
/**
 * Attachment-ID fixer.
 *
 * Older posts — and images inserted by URL — often have a core/image block with
 * no attachment id: just an <img> served from the uploads folder. That leaves
 * them without the Media Library link that Image Text, alt/caption propagation,
 * and the front-end responsive `srcset` all rely on. This tool scans posts and
 * pages, matches each id-less uploads image to its Media Library attachment by
 * URL, and writes the missing id (and the `wp-image-<id>` class) back into the
 * block.
 *
 * Only images served from THIS site's uploads folder that resolve to an exact
 * attachment are touched. The change is additive (it only adds the id and the
 * class) and idempotent (an image that already has an id is skipped), and a
 * dry-run Preview reports what would change before anything is saved.
 *
 * Driven from the Settings page (admin-ajax) in chunks, so it survives large
 * libraries and slow servers.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds missing Media Library attachment ids to in-content images.
 */
final class Coywolf_SEO_Image_ID_Fixer {

	/**
	 * Run-state option (status, counters, cursor).
	 */
	const STATE_OPTION = 'coywolf_seo_image_id_fix';

	/**
	 * Posts processed per step.
	 */
	const CHUNK = 25;

	/**
	 * How many example fixes to surface in the result.
	 */
	const MAX_SAMPLES = 3;

	/**
	 * Hooks.
	 */
	public function init() {
		add_action( 'wp_ajax_coywolf_seo_idfix', array( $this, 'ajax' ) );
	}

	/* --------------------------------------------------------------------- *
	 * AJAX (Settings page)
	 * --------------------------------------------------------------------- */

	/**
	 * Single AJAX entry point: op = start | step | cancel | status.
	 */
	public function ajax() {
		check_ajax_referer( 'coywolf_seo_idfix' );
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
			'status'        => $total > 0 ? 'running' : 'done',
			'dry_run'       => (bool) $dry_run,
			'last_id'       => 0,
			'total'         => $total,
			'posts_scanned' => 0,
			'posts_updated' => 0,
			'images_fixed'  => 0,
			'unmatched'     => 0,
			'samples'       => array(),
			'started'       => gmdate( 'c' ),
			'finished'      => $total > 0 ? '' : gmdate( 'c' ),
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
			$result           = $this->fix_post( $post_id, ! empty( $state['dry_run'] ) );
			++$state['posts_scanned'];
			$state['images_fixed'] += $result['fixed'];
			$state['unmatched']    += $result['unmatched'];
			if ( $result['changed'] ) {
				++$state['posts_updated'];
			}
			foreach ( $result['fixed_ids'] as $attachment_id ) {
				if ( count( $state['samples'] ) >= self::MAX_SAMPLES ) {
					break;
				}
				$sample = $this->build_sample( $post_id, (int) $attachment_id );
				if ( $sample ) {
					$state['samples'][] = $sample;
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
	 * The site-relative uploads path (e.g. "/wp-content/uploads"), used as a
	 * cheap content prefilter.
	 *
	 * @return string
	 */
	private function uploads_path() {
		$base = (string) wp_upload_dir()['baseurl'];
		$path = wp_parse_url( $base, PHP_URL_PATH );
		return $path ? $path : '/wp-content/uploads';
	}

	/**
	 * Public post types whose content may carry images (excludes attachments).
	 *
	 * @return string[]
	 */
	private function post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return array_values( $types );
	}

	/**
	 * How many posts/pages reference the uploads folder (the work set).
	 *
	 * @return int
	 */
	private function candidate_count() {
		global $wpdb;
		$types = $this->post_types();
		if ( empty( $types ) ) {
			return 0;
		}
		$type_ph = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$like    = '%' . $wpdb->esc_like( $this->uploads_path() ) . '%';
		$args    = array_merge( $types, array( $like ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN() placeholders from array_fill; args passed as one array; maintenance scan, not cacheable.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$type_ph}) AND post_status NOT IN ('trash','auto-draft','inherit') AND post_content LIKE %s", $args ) );
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
		$types = $this->post_types();
		if ( empty( $types ) ) {
			return array();
		}
		$type_ph = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$like    = '%' . $wpdb->esc_like( $this->uploads_path() ) . '%';
		$args    = array_merge( $types, array( $like, (int) $after, (int) $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN() placeholders from array_fill; args passed as one array; maintenance scan, not cacheable.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$type_ph}) AND post_status NOT IN ('trash','auto-draft','inherit') AND post_content LIKE %s AND ID > %d ORDER BY ID ASC LIMIT %d", $args ) ) );
	}

	/* --------------------------------------------------------------------- *
	 * Per-post fix
	 * --------------------------------------------------------------------- */

	/**
	 * Add missing ids to one post's images.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $dry_run Count only; do not save.
	 * @return array { fixed:int, unmatched:int, changed:bool, fixed_ids:int[] }
	 */
	private function fix_post( $post_id, $dry_run ) {
		$out  = array(
			'fixed'     => 0,
			'unmatched' => 0,
			'changed'   => false,
			'fixed_ids' => array(),
		);
		$post = get_post( $post_id );
		if ( ! $post || '' === (string) $post->post_content || false === strpos( (string) $post->post_content, $this->uploads_path() ) ) {
			return $out;
		}

		$state  = array(
			'fixed'     => 0,
			'unmatched' => 0,
			'changed'   => false,
			'fixed_ids' => array(),
		);
		$blocks = $this->walk_fix( parse_blocks( (string) $post->post_content ), $state );

		$out['fixed']     = $state['fixed'];
		$out['unmatched'] = $state['unmatched'];
		$out['changed']   = $state['changed'];
		$out['fixed_ids'] = $state['fixed_ids'];

		if ( $state['changed'] && ! $dry_run ) {
			$new = serialize_blocks( $blocks );
			if ( $new !== (string) $post->post_content ) {
				global $wpdb;
				// Write post_content directly rather than via wp_update_post(): this
				// is a purely additive maintenance fix, and going through the normal
				// save would fire transition_post_status / wp_after_insert_post on
				// every post — re-queuing AI analysis, pinging IndexNow, re-indexing
				// links, creating revisions, and bumping the modified date. None of
				// that is wanted for adding an attachment id, so bypass it and just
				// flush the post cache.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk content maintenance; post cache cleared immediately below.
				$wpdb->update( $wpdb->posts, array( 'post_content' => $new ), array( 'ID' => $post_id ) );
				clean_post_cache( $post_id );
			}
		}
		return $out;
	}

	/**
	 * Build one preview sample: a fixed image's thumbnail plus its post's title
	 * and edit-screen link.
	 *
	 * @param int $post_id       Post the image lives in.
	 * @param int $attachment_id Attachment that was (or would be) linked.
	 * @return array|false { thumb, title, edit, post }
	 */
	private function build_sample( $post_id, $attachment_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return false;
		}
		$thumb = wp_get_attachment_image_url( (int) $attachment_id, 'thumbnail' );
		if ( ! $thumb ) {
			$thumb = wp_get_attachment_image_url( (int) $attachment_id, 'full' );
		}
		$title = get_the_title( $post_id );
		$edit  = get_edit_post_link( $post_id, 'raw' );
		return array(
			'thumb' => $thumb ? (string) $thumb : '',
			'title' => '' !== (string) $title ? (string) $title : __( '(no title)', 'coywolf-seo' ),
			'edit'  => $edit ? (string) $edit : '',
			'post'  => $post_id,
		);
	}

	/**
	 * Walk parsed blocks, adding ids to id-less core/image blocks whose src is an
	 * uploads URL that resolves to an attachment. Mutates $state counters.
	 *
	 * @param array $blocks Parsed blocks.
	 * @param array $state  { fixed, unmatched, changed }.
	 * @return array Modified blocks.
	 */
	private function walk_fix( array $blocks, array &$state ) {
		foreach ( $blocks as $i => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
			if ( 'core/image' === $name && empty( $block['attrs']['id'] ) ) {
				$html = isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';
				$src  = $this->img_src( $html );
				if ( '' !== $src && $this->is_uploads_url( $src ) ) {
					$id = $this->resolve( $src );
					if ( $id > 0 ) {
						$new_html             = $this->set_image_class( $html, $id );
						$block['attrs']['id'] = $id;
						$block['innerHTML']   = $new_html;
						// core/image is a leaf: innerContent is the single chunk.
						$block['innerContent'] = array( $new_html );
						$blocks[ $i ]          = $block;
						++$state['fixed'];
						$state['fixed_ids'][] = $id;
						$state['changed']     = true;
					} else {
						++$state['unmatched'];
					}
				}
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$blocks[ $i ]['innerBlocks'] = $this->walk_fix( $block['innerBlocks'], $state );
			}
		}
		return $blocks;
	}

	/* --------------------------------------------------------------------- *
	 * URL → attachment matching
	 * --------------------------------------------------------------------- */

	/**
	 * The real `src` of the first <img> in a block's HTML. Uses the HTML API so
	 * it reads the `src` attribute specifically — never a lazy-load `data-src`,
	 * which a looser regex could capture and resolve to the wrong attachment.
	 *
	 * @param string $html Block innerHTML.
	 * @return string
	 */
	private function img_src( $html ) {
		if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
			$tags = new WP_HTML_Tag_Processor( (string) $html );
			if ( $tags->next_tag( 'img' ) ) {
				$src = $tags->get_attribute( 'src' );
				return is_string( $src ) ? $src : '';
			}
			return '';
		}
		// Fallback (pre-6.2): anchor to a real `src`, not `data-src`.
		if ( preg_match( '/<img\b[^>]*?(?<![-\w])src\s*=\s*("|\')(.*?)\1/i', $html, $m ) ) {
			return (string) $m[2];
		}
		return '';
	}

	/**
	 * Whether a src points at THIS site's uploads folder (not an external site).
	 *
	 * @param string $src Image src.
	 * @return bool
	 */
	private function is_uploads_url( $src ) {
		$src = html_entity_decode( $src, ENT_QUOTES );
		if ( false === strpos( $src, $this->uploads_path() ) ) {
			return false;
		}
		$host = wp_parse_url( $src, PHP_URL_HOST );
		if ( $host ) {
			$home = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( strtolower( $host ) !== strtolower( (string) $home ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Resolve an uploads image URL to its IMAGE attachment id. Returns 0 unless
	 * there is a confident, exact match:
	 *
	 * - the full URL is the attachment's own file, or
	 * - the URL is a -WxH size variant that is a REGISTERED size of that exact
	 *   attachment (verified against its metadata, never just a shared base name).
	 *
	 * Non-image attachments (e.g. a PDF) never match. This deliberately favours
	 * skipping (reported as unmatched) over writing a wrong id to live content.
	 *
	 * @param string $src Image src.
	 * @return int
	 */
	private function resolve( $src ) {
		$url = html_entity_decode( $src, ENT_QUOTES );
		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		} elseif ( 0 === strpos( $url, '/' ) ) {
			$url = home_url( $url );
		}
		$url = (string) preg_replace( '/[?#].*$/', '', $url ); // Drop query/fragment.
		if ( '' === $url ) {
			return 0;
		}

		// Exact full-file match — the strongest signal.
		$id = (int) attachment_url_to_postid( $url );
		if ( $id > 0 && $this->is_image( $id ) ) {
			return $id;
		}

		// A displayed size variant (image-300x200.jpg): accept ONLY when it is a
		// genuine registered size of the attachment its base name resolves to.
		$base = (string) preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $url );
		if ( $base !== $url ) {
			$bid = (int) attachment_url_to_postid( $base );
			if ( $bid > 0 && $this->is_image( $bid ) && $this->is_registered_size( $bid, $url ) ) {
				return $bid;
			}
		}
		return 0;
	}

	/**
	 * Whether an attachment is a raster image (so an id is never written into a
	 * core/image block for a PDF or other non-image file).
	 *
	 * @param int $id Attachment ID.
	 * @return bool
	 */
	private function is_image( $id ) {
		return 0 === strpos( (string) get_post_mime_type( $id ), 'image/' );
	}

	/**
	 * Whether a URL is one of an attachment's own generated sizes — confirmed
	 * against its metadata, so a -WxH URL that merely shares a base name with an
	 * unrelated attachment is rejected.
	 *
	 * @param int    $id  Attachment ID.
	 * @param string $url Candidate size-variant URL.
	 * @return bool
	 */
	private function is_registered_size( $id, $url ) {
		$file = wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( '' === $file ) {
			return false;
		}
		$meta = wp_get_attachment_metadata( $id );
		if ( ! is_array( $meta ) || empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
			return false;
		}
		foreach ( $meta['sizes'] as $size ) {
			if ( isset( $size['file'] ) && (string) $size['file'] === $file ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Set the canonical wp-image-<id> class on the first <img>, removing any
	 * stale wp-image-N tokens first so the class always agrees with attrs.id.
	 * Uses the HTML API, which preserves the rest of the tag verbatim.
	 *
	 * @param string $html Block innerHTML.
	 * @param int    $id   Attachment ID.
	 * @return string
	 */
	private function set_image_class( $html, $id ) {
		$want = 'wp-image-' . (int) $id;

		if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
			$tags = new WP_HTML_Tag_Processor( (string) $html );
			if ( ! $tags->next_tag( 'img' ) ) {
				return $html;
			}
			$current = $tags->get_attribute( 'class' );
			if ( is_string( $current ) && '' !== $current ) {
				foreach ( preg_split( '/\s+/', $current ) as $token ) {
					if ( '' !== $token && preg_match( '/^wp-image-\d+$/', $token ) && $token !== $want ) {
						$tags->remove_class( $token );
					}
				}
			}
			$tags->add_class( $want );
			return $tags->get_updated_html();
		}

		// Fallback (pre-6.2): append if not already present.
		if ( ! preg_match( '/<img\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		$tag    = $m[0][0];
		$offset = $m[0][1];
		if ( preg_match( '/\sclass\s*=\s*("|\')(.*?)\1/i', $tag, $cm ) ) {
			$value   = trim( (string) preg_replace( '/(^|\s)wp-image-\d+(?=\s|$)/', '', $cm[2] ) . ' ' . $want );
			$new_tag = preg_replace_callback(
				'/\sclass\s*=\s*("|\')(.*?)\1/i',
				static function () use ( $value ) {
					return ' class="' . esc_attr( $value ) . '"';
				},
				$tag,
				1
			);
		} else {
			$new_tag = preg_replace_callback(
				'/<img\b/i',
				static function () use ( $want ) {
					return '<img class="' . esc_attr( $want ) . '"';
				},
				$tag,
				1
			);
		}
		if ( null === $new_tag || $new_tag === $tag ) {
			return $html;
		}
		return substr_replace( $html, $new_tag, $offset, strlen( $tag ) );
	}
}
