<?php
/**
 * Image Text module.
 *
 * Bulk-writes alternative text, title, caption, and description for every
 * image — and, optionally, title/caption/description for every PDF — in the
 * Media Library using the Claude API, as a client-driven batch of small
 * requests (one file per API call, several per REST step) with live
 * progress, resume, and a circuit breaker for repeated API failures.
 *
 * Also provides the single-image "Generate" button on the attachment
 * details/edit screens (via attachment_fields_to_edit) and the block-editor
 * integration that adds Title/Caption/Description fields and a generate
 * button to the core Image block's settings.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Image Text module.
 */
final class Coywolf_SEO_Image_Text {

	const SLUG = 'coywolf-seo-image-text';

	const BATCH_OPTION = 'coywolf_seo_image_text_batch';

	/**
	 * Seconds of work per REST step request. Each image is one Claude call,
	 * so a step usually covers one to four images.
	 */
	const TIME_BUDGET = 20;

	/**
	 * Consecutive API failures before the batch pauses itself.
	 */
	const MAX_CONSECUTIVE_ERRORS = 3;

	const FIELDS = array( 'alt_text', 'title', 'caption', 'description' );

	/**
	 * Claude client.
	 *
	 * @var Coywolf_SEO_Image_AI
	 */
	private $ai;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->ai = new Coywolf_SEO_Image_AI();
	}

	/**
	 * Hooks.
	 */
	public function init() {
		add_filter( 'attachment_fields_to_edit', array( $this, 'tools_field' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_assets' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_enqueue_media', array( $this, 'enqueue_media' ) );
		add_action( 'admin_post_coywolf_seo_save_image_instructions', array( $this, 'save_instructions' ) );
	}

	/**
	 * Default fields to write, from the "Image Text defaults" on the Settings
	 * page. Mirrors Media Manager's ai_fields(): keep only known fields and
	 * fall back to alt text so a run is never field-less.
	 *
	 * @return array
	 */
	private function default_fields() {
		$map    = array(
			'alt_text'    => 'image_text_write_alt',
			'title'       => 'image_text_write_title',
			'caption'     => 'image_text_write_caption',
			'description' => 'image_text_write_description',
		);
		$fields = array();
		foreach ( $map as $field => $option ) {
			if ( Coywolf_SEO_Options::get( $option ) ) {
				$fields[] = $field;
			}
		}
		return ! empty( $fields ) ? $fields : array( 'alt_text' );
	}

	/**
	 * Default "overwrite existing text" preference, from the Settings page.
	 *
	 * @return bool
	 */
	private function default_overwrite() {
		return (bool) Coywolf_SEO_Options::get( 'image_text_overwrite' );
	}

	/* --------------------------------------------------------------------- *
	 * Single image
	 * --------------------------------------------------------------------- */

	/**
	 * Generate the four fields for one image, optionally saving them.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $save          Write to the attachment (all four fields,
	 *                            overwriting — this is the explicit
	 *                            per-image button).
	 * @param int  $post_id       Post being edited (block editor), so its content
	 *                            is preferred for context; 0 = look it up.
	 * @return array|WP_Error { alt_text, title, caption, description, written }
	 */
	public function generate_single( $attachment_id, $save, $post_id = 0 ) {
		$texts = $this->ai->generate_image_text( $attachment_id, '', $this->context_for( (int) $attachment_id, (int) $post_id ) );
		if ( is_wp_error( $texts ) ) {
			return $texts;
		}
		$written = array();
		if ( $save ) {
			$written = $this->ai->save_image_text( $attachment_id, $texts, self::FIELDS, true );
		}
		$texts['written'] = $written;
		return $texts;
	}

	/* --------------------------------------------------------------------- *
	 * Bulk batch
	 * --------------------------------------------------------------------- */

	/**
	 * Current batch state.
	 *
	 * @return array
	 */
	public function get_batch() {
		$state = get_option( self::BATCH_OPTION, array() );
		return is_array( $state ) ? $state : array( 'status' => 'idle' );
	}

	/**
	 * Start (or restart) a bulk run.
	 *
	 * @param array  $fields       Field keys to write.
	 * @param bool   $overwrite    Replace existing text too.
	 * @param bool   $resume       Continue a paused (errored) run from its
	 *                             cursor instead of starting over.
	 * @param string $model        Model for this run (empty = Settings model).
	 * @param bool   $include_pdfs Also process PDFs (title/caption/description
	 *                             only — PDFs have no alt text).
	 * @param bool   $propagate    Also fill empty alt text and captions on
	 *                             Image blocks already placed in posts/pages
	 *                             (only for the alt/caption fields this run
	 *                             writes).
	 * @param bool   $realtime     Process each file immediately at the standard
	 *                             rate instead of through the discounted,
	 *                             asynchronous Batch API.
	 * @return array|WP_Error State.
	 */
	public function start_batch( array $fields, $overwrite, $resume = false, $model = '', $include_pdfs = false, $propagate = false, $realtime = false ) {
		if ( ! $this->ai->is_configured() ) {
			return new WP_Error( 'coywolf_seo_no_key', __( 'Add your AI service API key on the Settings page first.', 'coywolf-seo' ) );
		}

		if ( $resume ) {
			$state = $this->get_batch();
			if ( ! empty( $state['status'] ) && 'error' === $state['status'] ) {
				$state['status']             = 'running';
				$state['consecutive_errors'] = 0;
				$state['message']            = '';
				update_option( self::BATCH_OPTION, $state, false );
				return $state;
			}
		}
		$fields = array_values( array_intersect( $fields, self::FIELDS ) );
		if ( empty( $fields ) ) {
			return new WP_Error( 'coywolf_seo_no_fields', __( 'Pick at least one field to write.', 'coywolf-seo' ) );
		}

		// Bulk image text defaults to the discounted, asynchronous Batch API.
		// Real-time is opt-in (faster, full price). A provider whose vision
		// batching is unverified (Gemini) always falls back to real-time.
		$supports_batch = Coywolf_SEO_AI_Providers::current()->supports_vision_batch();
		$mode           = ( ! $realtime && $supports_batch ) ? 'batch' : 'realtime';

		$state = array(
			'status'             => 'running',
			'mode'               => $mode,
			'realtime'           => (bool) $realtime,
			'fields'             => $fields,
			'overwrite'          => (bool) $overwrite,
			'include_pdfs'       => (bool) $include_pdfs,
			// Propagation only makes sense when this run writes a field that
			// renders per-block — alt text or caption.
			'propagate'          => (bool) $propagate && ( in_array( 'alt_text', $fields, true ) || in_array( 'caption', $fields, true ) ),
			'model'              => is_string( $model ) && preg_match( '/^[a-z0-9._-]+$/i', $model ) ? $model : '',
			'last_id'            => 0,
			'total'              => $this->candidates_count( $fields, $overwrite, $include_pdfs ),
			'done'               => 0,
			'written'            => 0,
			'skipped'            => 0,
			'failed'             => 0,
			'posts_updated'      => 0,
			'errors'             => array(),
			'consecutive_errors' => 0,
			'current'            => '',
			'message'            => '',
			// Batch-mode bookkeeping (unused by the real-time path).
			'batch_id'           => '',
			'map'                => array(),
			'awaiting_batch'     => false,
			'batch_started'      => 0,
		);
		update_option( self::BATCH_OPTION, $state, false );
		return $state;
	}

	/**
	 * Work one REST step of the active run, dispatching to the real-time or
	 * Batch-API engine the run was started in.
	 *
	 * @return array State.
	 */
	public function step_batch() {
		$state = $this->get_batch();
		if ( empty( $state['status'] ) || 'running' !== $state['status'] ) {
			return $state;
		}
		if ( isset( $state['mode'] ) && 'batch' === $state['mode'] ) {
			return $this->step_async( $state );
		}
		return $this->step_realtime( $state );
	}

	/**
	 * Real-time engine: analyze each file synchronously within a time budget.
	 *
	 * @param array $state Run state.
	 * @return array State.
	 */
	private function step_realtime( array $state ) {
		$deadline = microtime( true ) + self::TIME_BUDGET;
		do {
			$id = $this->next_candidate( $state );
			if ( 0 === $id ) {
				$state['status'] = 'done';
				break;
			}
			$state['last_id'] = $id;
			$state['current'] = wp_basename( (string) get_post_meta( $id, '_wp_attached_file', true ) );

			$result = $this->ai->generate_image_text( $id, isset( $state['model'] ) ? (string) $state['model'] : '', $this->context_for( $id ) );
			++$state['done'];
			if ( is_wp_error( $result ) ) {
				++$state['failed'];
				++$state['consecutive_errors'];
				if ( count( $state['errors'] ) < 50 ) {
					$state['errors'][] = array(
						'id'      => $id,
						'name'    => $state['current'],
						'message' => $result->get_error_message(),
					);
				}
				// Unsupported/missing files are per-image problems; anything
				// else repeated MAX_CONSECUTIVE_ERRORS times in a row (bad
				// key, rate limit, outage) pauses the run instead of
				// burning an error on every remaining image.
				$per_image = in_array( $result->get_error_code(), array( 'coywolf_seo_unsupported', 'coywolf_seo_missing_file', 'coywolf_seo_read' ), true );
				if ( $per_image ) {
					$state['consecutive_errors'] = 0;
				} elseif ( $state['consecutive_errors'] >= self::MAX_CONSECUTIVE_ERRORS ) {
					$state['status']  = 'error';
					$state['message'] = $result->get_error_message();
				}
			} else {
				$state['consecutive_errors'] = 0;
				$written                     = $this->ai->save_image_text( $id, $result, $state['fields'], ! empty( $state['overwrite'] ) );
				if ( ! empty( $written ) ) {
					++$state['written'];
				} else {
					++$state['skipped'];
				}
				// Carry the file's alt text and caption into Image blocks
				// already placed in content that have none (opt-in). Each field
				// is propagated only when this run writes it, using the
				// attachment's current value — so it covers both text just
				// written and text it already had.
				if ( ! empty( $state['propagate'] ) && wp_attachment_is_image( $id ) ) {
					$alt     = in_array( 'alt_text', (array) $state['fields'], true )
						? (string) get_post_meta( $id, '_wp_attachment_image_alt', true )
						: '';
					$caption = in_array( 'caption', (array) $state['fields'], true )
						? (string) get_post_field( 'post_excerpt', $id )
						: '';
					if ( '' !== trim( $alt ) || '' !== trim( $caption ) ) {
						$state['posts_updated'] += $this->propagate_image_text( $id, $alt, $caption, ! empty( $state['overwrite'] ) );
					}
				}
			}
			update_option( self::BATCH_OPTION, $state, false );
		} while ( 'running' === $state['status'] && microtime( true ) < $deadline );

		update_option( self::BATCH_OPTION, $state, false );
		return $state;
	}

	/* --------------------------------------------------------------------- *
	 * Batch-API engine (asynchronous, discounted)
	 * --------------------------------------------------------------------- */

	/**
	 * Batch facade for the active provider, key, and the run's chosen model.
	 *
	 * @param string $model Run model ('' = the Settings/provider model).
	 * @return Coywolf_SEO_AI_Batch
	 */
	private function batch_facade( $model ) {
		$provider  = Coywolf_SEO_AI_Providers::current();
		$use_model = ( '' !== (string) $model ) ? (string) $model : $provider->model();
		return new Coywolf_SEO_AI_Batch( $provider, $this->ai->api_key(), $use_model );
	}

	/**
	 * Up to $limit candidate attachment IDs after the run's cursor.
	 *
	 * @param array $state Run state.
	 * @param int   $limit Chunk size.
	 * @return int[]
	 */
	private function next_candidates( array $state, $limit ) {
		global $wpdb;
		$where = $this->candidate_where( (array) $state['fields'], ! empty( $state['overwrite'] ), ! empty( $state['include_pdfs'] ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the WHERE fragment is assembled from literal SQL only.
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p WHERE {$where} AND p.ID > %d ORDER BY p.ID ASC LIMIT %d", (int) $state['last_id'], (int) $limit ) );
		return array_map( 'intval', (array) $rows );
	}

	/**
	 * One Batch-API step: collect a finished batch, or submit the next chunk.
	 *
	 * @param array $state Run state.
	 * @return array State.
	 */
	private function step_async( array $state ) {
		$batch = $this->batch_facade( isset( $state['model'] ) ? (string) $state['model'] : '' );

		if ( '' !== (string) ( $state['batch_id'] ?? '' ) ) {
			return $this->collect_async( $state, $batch );
		}
		return $this->submit_async( $state, $batch );
	}

	/**
	 * Submit the next chunk of files as one vision batch. Files that cannot be
	 * prepared are recorded as per-file failures and left out of the batch.
	 *
	 * @param array                $state Run state.
	 * @param Coywolf_SEO_AI_Batch $batch Batch facade.
	 * @return array State.
	 */
	private function submit_async( array $state, Coywolf_SEO_AI_Batch $batch ) {
		/**
		 * Files per submitted vision batch. Each carries a base64 image, so the
		 * chunk is kept small to bound request size and memory.
		 *
		 * @param int $size Default 10.
		 */
		$chunk = max( 1, (int) apply_filters( 'coywolf_seo_image_batch_size', 10 ) );
		$ids   = $this->next_candidates( $state, $chunk );
		if ( empty( $ids ) ) {
			$state['status']         = 'done';
			$state['awaiting_batch'] = false;
			update_option( self::BATCH_OPTION, $state, false );
			return $state;
		}

		// Snapshot the chunk's starting point and counters so a submit failure
		// can roll the WHOLE chunk back atomically — otherwise Resume would skip
		// past the cursor and silently lose the prepared files.
		$chunk_start = (int) $state['last_id'];
		$snap_done   = (int) $state['done'];
		$snap_failed = (int) $state['failed'];
		$snap_errors = (array) $state['errors'];

		$requests = array();
		$map      = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			// Advance the cursor past every file in the chunk, processed or not,
			// so the next chunk never re-queries it (rolled back on submit error).
			$state['last_id'] = $id;
			$custom_id        = 'a' . $id;
			$request          = $this->ai->build_batch_request( $batch, $custom_id, $id, $this->context_for( $id ) );
			if ( is_wp_error( $request ) ) {
				++$state['done'];
				++$state['failed'];
				$this->record_batch_error( $state, $id, $request->get_error_message() );
				continue;
			}
			$requests[]        = $request;
			$map[ $custom_id ] = $id;
		}

		if ( empty( $requests ) ) {
			// Whole chunk failed preparation; the next step picks up where the
			// cursor now points.
			update_option( self::BATCH_OPTION, $state, false );
			return $state;
		}

		$batch_id = $batch->submit( $requests );
		if ( is_wp_error( $batch_id ) ) {
			// Roll the entire chunk back so Resume re-processes it intact
			// (re-prep is local and free; nothing is silently skipped).
			$state['last_id'] = $chunk_start;
			$state['done']    = $snap_done;
			$state['failed']  = $snap_failed;
			$state['errors']  = $snap_errors;
			$state['status']  = 'error';
			$state['message'] = $batch_id->get_error_message();
			update_option( self::BATCH_OPTION, $state, false );
			return $state;
		}

		$state['batch_id']       = (string) $batch_id;
		$state['map']            = $map;
		$state['awaiting_batch'] = true;
		$state['batch_started']  = time();
		$state['current']        = '';
		update_option( self::BATCH_OPTION, $state, false );
		return $state;
	}

	/**
	 * Poll the in-flight batch; once ended, write each file's text and clear it
	 * so the next step submits the following chunk.
	 *
	 * @param array                $state Run state.
	 * @param Coywolf_SEO_AI_Batch $batch Batch facade.
	 * @return array State.
	 */
	private function collect_async( array $state, Coywolf_SEO_AI_Batch $batch ) {
		$poll = $batch->poll( (string) $state['batch_id'] );
		if ( is_wp_error( $poll ) ) {
			$state['status']  = 'error';
			$state['message'] = $poll->get_error_message();
			update_option( self::BATCH_OPTION, $state, false );
			return $state;
		}
		if ( empty( $poll['ended'] ) ) {
			// Batch APIs guarantee completion within 24h; if a batch is stuck
			// well past that, fail the run rather than poll forever so the user
			// can Resume (which retries the same batch) or cancel.
			$started = (int) ( $state['batch_started'] ?? 0 );
			if ( $started > 0 && ( time() - $started ) > 30 * HOUR_IN_SECONDS ) {
				$state['status']  = 'error';
				$state['message'] = __( 'The batch is taking unusually long at the AI service. Resume to keep waiting, or cancel and try again.', 'coywolf-seo' );
				update_option( self::BATCH_OPTION, $state, false );
				return $state;
			}
			$state['awaiting_batch'] = true; // Still processing remotely; the client paces the next poll.
			update_option( self::BATCH_OPTION, $state, false );
			return $state;
		}

		$results = $batch->results( (string) $poll['results_url'] );
		if ( is_wp_error( $results ) ) {
			$state['status']  = 'error';
			$state['message'] = $results->get_error_message();
			update_option( self::BATCH_OPTION, $state, false );
			return $state;
		}

		foreach ( (array) ( $state['map'] ?? array() ) as $custom_id => $id ) {
			$id = (int) $id;
			++$state['done'];
			$state['current'] = wp_basename( (string) get_post_meta( $id, '_wp_attached_file', true ) );

			$result = isset( $results[ $custom_id ] ) ? $results[ $custom_id ] : null;
			if ( ! is_array( $result ) || empty( $result['ok'] ) ) {
				++$state['failed'];
				$message = ( is_array( $result ) && ! empty( $result['error'] ) )
					? (string) $result['error']
					: __( 'The batch returned no result for this file.', 'coywolf-seo' );
				$this->record_batch_error( $state, $id, $message );
				continue;
			}

			$fields = $this->ai->parse_fields( (string) $result['text'] );
			if ( is_wp_error( $fields ) ) {
				++$state['failed'];
				$this->record_batch_error( $state, $id, $fields->get_error_message() );
				continue;
			}

			$written = $this->ai->save_image_text( $id, $fields, (array) $state['fields'], ! empty( $state['overwrite'] ) );
			if ( ! empty( $written ) ) {
				++$state['written'];
			} else {
				++$state['skipped'];
			}
			if ( ! empty( $state['propagate'] ) && wp_attachment_is_image( $id ) ) {
				$alt     = in_array( 'alt_text', (array) $state['fields'], true )
					? (string) get_post_meta( $id, '_wp_attachment_image_alt', true )
					: '';
				$caption = in_array( 'caption', (array) $state['fields'], true )
					? (string) get_post_field( 'post_excerpt', $id )
					: '';
				if ( '' !== trim( $alt ) || '' !== trim( $caption ) ) {
					$state['posts_updated'] += $this->propagate_image_text( $id, $alt, $caption, ! empty( $state['overwrite'] ) );
				}
			}
		}

		// Chunk done: clear it so the next step submits the following chunk (or
		// finishes when the cursor reaches the end).
		$state['batch_id']       = '';
		$state['map']            = array();
		$state['awaiting_batch'] = false;
		update_option( self::BATCH_OPTION, $state, false );
		return $state;
	}

	/**
	 * Record a per-file error in the run state, capped at 50 entries.
	 *
	 * @param array  $state   Run state (by reference).
	 * @param int    $id      Attachment ID.
	 * @param string $message Error message.
	 */
	private function record_batch_error( array &$state, $id, $message ) {
		if ( count( (array) $state['errors'] ) >= 50 ) {
			return;
		}
		$state['errors'][] = array(
			'id'      => (int) $id,
			'name'    => wp_basename( (string) get_post_meta( (int) $id, '_wp_attached_file', true ) ),
			'message' => (string) $message,
		);
	}

	/* --------------------------------------------------------------------- *
	 * Surrounding-article context (for context-aware alt text and captions)
	 * --------------------------------------------------------------------- */

	/**
	 * Plain-text marker inserted where the image sits in the article, so the AI
	 * knows its exact position.
	 */
	const CONTEXT_MARKER = '«THE IMAGE BEING DESCRIBED»';

	/**
	 * Characters of surrounding text kept on each side of the image marker —
	 * bounds the added input tokens (~450 total) so context stays cheap.
	 */
	const CONTEXT_WINDOW_CHARS = 900;

	/**
	 * Build the surrounding-article context for an image: the post it appears in,
	 * rendered to a short plain-text window with the image's position marked.
	 * Returns '' when the image is not used in any post's content (the run then
	 * falls back to filename-only context).
	 *
	 * @param int $attachment_id  Attachment ID.
	 * @param int $prefer_post_id Post to prefer (the one being edited); 0 = look up.
	 * @return string
	 */
	private function context_for( $attachment_id, $prefer_post_id = 0 ) {
		$attachment_id = (int) $attachment_id;
		$context       = '';
		if ( (int) $prefer_post_id > 0 ) {
			$context = $this->build_image_context( (int) $prefer_post_id, $attachment_id );
		}
		if ( '' === $context ) {
			$post_id = $this->primary_post_for_image( $attachment_id );
			if ( $post_id > 0 ) {
				$context = $this->build_image_context( $post_id, $attachment_id );
			}
		}
		return $context;
	}

	/**
	 * The first published post/page whose content references this image — by its
	 * `wp-image-<id>` class or by its uploads file path (so images inserted by
	 * URL, with no id/class, are still located for context). Returns 0 when none.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int
	 */
	private function primary_post_for_image( $attachment_id ) {
		global $wpdb;
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		if ( empty( $types ) ) {
			return 0;
		}
		$type_ph = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$where   = 'post_content LIKE %s';
		$args    = $types;
		$args[]  = '%' . $wpdb->esc_like( 'wp-image-' . (int) $attachment_id ) . '%';
		$token   = $this->attachment_path_token( (int) $attachment_id );
		if ( '' !== $token ) {
			$where .= ' OR post_content LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $token ) . '%';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN() placeholders built from array_fill; args passed as one array; one-off lookup.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$type_ph}) AND post_status = 'publish' AND ({$where}) ORDER BY ID ASC LIMIT 1", $args ) );
	}

	/**
	 * The attachment's uploads-relative file path, leading-slash anchored
	 * (e.g. "/2024/01/logo.png"), or ''. Anchored to the path — not the bare
	 * basename — so a generic name like "logo.png" can't match an unrelated
	 * "company-logo.png" elsewhere in a post and mislabel the context.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private function attachment_path_token( $attachment_id ) {
		$file = (string) get_post_meta( (int) $attachment_id, '_wp_attached_file', true );
		return '' !== $file ? '/' . ltrim( $file, '/' ) : '';
	}

	/**
	 * Whether a post's content references an image — by its wp-image-<id> class
	 * or its uploads file path (leading-slash anchored, never a bare basename).
	 *
	 * @param string $content       Post content.
	 * @param int    $attachment_id Attachment ID.
	 * @return bool
	 */
	private function post_references_image( $content, $attachment_id ) {
		if ( false !== strpos( $content, 'wp-image-' . (int) $attachment_id ) ) {
			return true;
		}
		$token = $this->attachment_path_token( (int) $attachment_id );
		return '' !== $token && false !== strpos( $content, $token );
	}

	/**
	 * Render a post to a short plain-text window centred on the image, with the
	 * image's block replaced by {@see self::CONTEXT_MARKER} and the nearest
	 * preceding heading captured. Returns '' when the image's block is not found.
	 *
	 * @param int $post_id       Post ID.
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private function build_image_context( $post_id, $attachment_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post || '' === (string) $post->post_content ) {
			return '';
		}
		$state = array(
			'parts'          => array(),
			'heading'        => '',
			'marker_heading' => '',
			'found'          => false,
		);
		$this->walk_blocks_for_context( parse_blocks( (string) $post->post_content ), (int) $attachment_id, $state );
		if ( ! $state['found'] ) {
			// The block couldn't be pinpointed (e.g. the image was inserted by URL
			// with no attachment id/class), but the post still references the file —
			// give the AI at least the article title for context.
			if ( $this->post_references_image( (string) $post->post_content, (int) $attachment_id ) ) {
				$title = wp_strip_all_tags( get_the_title( $post ) );
				if ( '' !== $title ) {
					return 'Article title: ' . $title;
				}
			}
			return '';
		}

		$text = implode( "\n", $state['parts'] );
		$pos  = mb_strpos( $text, self::CONTEXT_MARKER );
		if ( false !== $pos ) {
			$win    = self::CONTEXT_WINDOW_CHARS;
			$before = mb_substr( $text, 0, $pos );
			$after  = mb_substr( $text, $pos + mb_strlen( self::CONTEXT_MARKER ) );
			if ( mb_strlen( $before ) > $win ) {
				$before = '…' . ltrim( mb_substr( $before, -$win ) );
			}
			if ( mb_strlen( $after ) > $win ) {
				$after = rtrim( mb_substr( $after, 0, $win ) ) . '…';
			}
			$text = $before . self::CONTEXT_MARKER . $after;
		}

		$out = 'Article title: ' . wp_strip_all_tags( get_the_title( $post ) );
		if ( '' !== $state['marker_heading'] ) {
			$out .= "\nSection heading: " . $state['marker_heading'];
		}
		return $out . "\n\n" . trim( $text );
	}

	/**
	 * Walk parsed blocks, building a plain-text part list and replacing the
	 * target image's block with the marker (recording the nearest heading above
	 * it). Mutates $state in place.
	 *
	 * @param array $blocks        Parsed blocks.
	 * @param int   $attachment_id Attachment ID.
	 * @param array $state         { parts, heading, marker_heading, found }.
	 */
	private function walk_blocks_for_context( array $blocks, $attachment_id, array &$state ) {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
			if ( 'core/image' === $name && isset( $block['attrs']['id'] ) && (int) $block['attrs']['id'] === (int) $attachment_id ) {
				// Mark only the first occurrence; a repeated use of the same
				// image shouldn't scatter multiple markers through the context.
				if ( ! $state['found'] ) {
					$state['parts'][]        = self::CONTEXT_MARKER;
					$state['marker_heading'] = $state['heading'];
					$state['found']          = true;
				}
				continue;
			}
			$text = '';
			if ( ! empty( $block['innerHTML'] ) ) {
				$text = trim( preg_replace( '/\s+/u', ' ', (string) wp_strip_all_tags( (string) $block['innerHTML'] ) ) );
			}
			if ( '' !== $text ) {
				if ( 'core/heading' === $name ) {
					$state['heading'] = $text;
				}
				$state['parts'][] = $text;
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$this->walk_blocks_for_context( $block['innerBlocks'], $attachment_id, $state );
			}
		}
	}

	/* --------------------------------------------------------------------- *
	 * Alt-text and caption propagation
	 * --------------------------------------------------------------------- */

	/**
	 * Add alternative text and/or a caption to Image blocks already placed in
	 * posts and pages that reference this attachment and are missing them.
	 *
	 * The core Image block stores both its alt text (the img `alt` attribute)
	 * and its caption (a <figcaption>) in the block markup, parsed from the
	 * figure rather than read live from the attachment — so a bulk write never
	 * reaches images already in content. This fills that gap. By default it
	 * only fills fields the block is missing; with $overwrite it replaces
	 * existing in-content values too (mirroring the run's overwrite mode).
	 * Pass an empty string for a field to skip it.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $alt           Alt text (plain text), or ''.
	 * @param string $caption       Caption (plain text), or ''.
	 * @param bool   $overwrite     Replace existing in-content values too.
	 * @return int Posts updated.
	 */
	private function propagate_image_text( $attachment_id, $alt, $caption, $overwrite = false ) {
		global $wpdb;
		$alt     = trim( (string) $alt );
		$caption = trim( (string) $caption );
		if ( '' === $alt && '' === $caption ) {
			return 0;
		}

		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		if ( empty( $types ) ) {
			return 0;
		}
		$type_ph = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		// Cheap prefilter: posts whose markup carries this image's id class.
		// parse_blocks() then confirms the exact block id, so a wp-image-12
		// vs wp-image-123 substring overlap can never cause a wrong edit.
		$like = '%' . $wpdb->esc_like( 'wp-image-' . (int) $attachment_id ) . '%';
		$args = array_merge( $types, array( $like ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN() placeholders built from array_fill; args passed as one array; one-off content sweep, not cacheable.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type IN ({$type_ph}) AND post_status NOT IN ('auto-draft','trash','inherit') AND post_content LIKE %s", $args ) );
		if ( empty( $rows ) ) {
			return 0;
		}

		$updated = 0;
		foreach ( $rows as $row ) {
			$content = (string) $row->post_content;
			if ( false === strpos( $content, '<!-- wp:image' ) ) {
				continue; // The class matched outside an Image block (e.g. classic markup) — nothing to fill.
			}
			$result = $this->fill_block_media( parse_blocks( $content ), (int) $attachment_id, $alt, $caption, $overwrite );
			if ( ! $result['changed'] ) {
				continue;
			}
			$new_content = serialize_blocks( $result['blocks'] );
			if ( $new_content === $content ) {
				continue;
			}
			// Filling alt/caption into image blocks is image-text bookkeeping, not
			// an editorial change, so don't let it re-queue AI entity/description
			// analysis for the post.
			Coywolf_SEO_AI::suspend_queueing();
			$res = wp_update_post(
				array(
					'ID'           => (int) $row->ID,
					'post_content' => wp_slash( $new_content ),
				),
				true
			);
			Coywolf_SEO_AI::resume_queueing();
			if ( ! is_wp_error( $res ) ) {
				++$updated;
			}
		}
		return $updated;
	}

	/**
	 * Recursively fill alt text and captions on core/image blocks matching an
	 * attachment ID. Walks innerBlocks too, so images nested in galleries,
	 * columns, or groups are covered. With $overwrite, existing values are
	 * replaced; otherwise only empty ones are filled.
	 *
	 * @param array  $blocks    Parsed blocks.
	 * @param int    $id        Attachment ID.
	 * @param string $alt       Alt text (plain text), or ''.
	 * @param string $caption   Caption (plain text), or ''.
	 * @param bool   $overwrite Replace existing values too.
	 * @return array { blocks: array, changed: bool }
	 */
	private function fill_block_media( array $blocks, $id, $alt, $caption, $overwrite = false ) {
		$changed = false;
		foreach ( $blocks as $i => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
			if ( 'core/image' === $name && isset( $block['attrs']['id'] ) && (int) $block['attrs']['id'] === (int) $id ) {
				$new = $this->add_image_text( isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '', $alt, $caption, $overwrite );
				if ( null !== $new ) {
					// core/image is a leaf block: its innerContent is the single
					// HTML chunk, with no null placeholders for child blocks.
					$blocks[ $i ]['innerHTML']    = $new;
					$blocks[ $i ]['innerContent'] = array( $new );
					$changed                      = true;
				}
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$inner = $this->fill_block_media( $block['innerBlocks'], $id, $alt, $caption, $overwrite );
				if ( $inner['changed'] ) {
					$blocks[ $i ]['innerBlocks'] = $inner['blocks'];
					$changed                     = true;
				}
			}
		}
		return array(
			'blocks'  => $blocks,
			'changed' => $changed,
		);
	}

	/**
	 * Add alt text and/or a caption to one image block's markup. Each is
	 * filled only when empty, unless $overwrite replaces it. Returns the new
	 * HTML, or null when nothing changed.
	 *
	 * @param string $html      Block innerHTML (the <figure>…</figure>).
	 * @param string $alt       Alt text (plain text), or ''.
	 * @param string $caption   Caption (plain text), or ''.
	 * @param bool   $overwrite Replace existing values too.
	 * @return string|null
	 */
	private function add_image_text( $html, $alt, $caption, $overwrite = false ) {
		if ( '' === trim( $html ) ) {
			return null;
		}
		$changed = false;

		if ( '' !== $alt ) {
			list( $html, $alt_changed ) = $this->set_img_alt( $html, $alt, $overwrite );
			$changed                    = $changed || $alt_changed;
		}
		if ( '' !== $caption ) {
			$with_caption = $this->set_figcaption( $html, $caption, $overwrite );
			if ( null !== $with_caption ) {
				$html    = $with_caption;
				$changed = true;
			}
		}

		return $changed ? $html : null;
	}

	/**
	 * Set the `alt` attribute on the block's <img>. By default only an empty
	 * or missing alt is set; with $overwrite a non-empty alt is replaced. An
	 * alt that already equals the new value is left as-is.
	 *
	 * @param string $html      Block innerHTML.
	 * @param string $alt       Alt text (plain text).
	 * @param bool   $overwrite Replace a non-empty alt too.
	 * @return array { 0: string html, 1: bool changed }
	 */
	private function set_img_alt( $html, $alt, $overwrite = false ) {
		if ( ! preg_match( '/<img\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return array( $html, false );
		}
		$tag    = $m[0][0];
		$offset = $m[0][1];

		if ( preg_match( '/\salt\s*=\s*("[^"]*"|\'[^\']*\')/i', $tag, $am ) ) {
			$current = trim( html_entity_decode( substr( $am[1], 1, -1 ), ENT_QUOTES ) );
			if ( ( '' !== $current && ! $overwrite ) || $current === $alt ) {
				return array( $html, false ); // Keep existing (or already the target).
			}
			// A callback keeps the alt text literal so a "$" in it is never
			// read as a backreference.
			$new_tag = preg_replace_callback(
				'/\salt\s*=\s*("[^"]*"|\'[^\']*\')/i',
				static function () use ( $alt ) {
					return ' alt="' . esc_attr( $alt ) . '"';
				},
				$tag,
				1
			);
		} else {
			$new_tag = preg_replace_callback(
				'/<img\b/i',
				static function () use ( $alt ) {
					return '<img alt="' . esc_attr( $alt ) . '"';
				},
				$tag,
				1
			);
		}

		if ( null === $new_tag || $new_tag === $tag ) {
			return array( $html, false );
		}
		return array( substr_replace( $html, $new_tag, $offset, strlen( $tag ) ), true );
	}

	/**
	 * Set the caption on an image block's <figure>. By default a caption is
	 * only added when the figure has none; with $overwrite an existing
	 * caption is replaced. Returns the new HTML, or null when nothing changed
	 * (no figure, or the caption already matches).
	 *
	 * @param string $html      Block innerHTML (the <figure>…</figure>).
	 * @param string $caption   Caption plain text.
	 * @param bool   $overwrite Replace a non-empty caption too.
	 * @return string|null
	 */
	private function set_figcaption( $html, $caption, $overwrite = false ) {
		if ( '' === trim( $html ) || false === stripos( $html, '</figure>' ) ) {
			return null;
		}
		$figcaption = '<figcaption class="wp-element-caption">' . esc_html( $caption ) . '</figcaption>';

		if ( preg_match( '/<figcaption\b[^>]*>(.*?)<\/figcaption>/is', $html, $m ) ) {
			$current = trim( wp_strip_all_tags( $m[1] ) );
			if ( ( '' !== $current && ! $overwrite ) || $current === $caption ) {
				return null; // Keep existing (or already the target).
			}
			// Replace the figcaption's content (a callback keeps the caption
			// literal against "$" backreferences).
			return preg_replace_callback(
				'/<figcaption\b[^>]*>.*?<\/figcaption>/is',
				static function () use ( $figcaption ) {
					return $figcaption;
				},
				$html,
				1
			);
		}

		// No figcaption — insert one before the figure's closing tag.
		return preg_replace_callback(
			'/<\/figure>/i',
			static function () use ( $figcaption ) {
				return $figcaption . '</figure>';
			},
			$html,
			1
		);
	}

	/**
	 * Cancel the active run.
	 *
	 * @return array
	 */
	public function cancel_batch() {
		$state = $this->get_batch();
		if ( ! empty( $state['status'] ) && in_array( $state['status'], array( 'running', 'error' ), true ) ) {
			// Best-effort cancel of an in-flight remote batch so it stops
			// accruing cost at the provider.
			if ( ! empty( $state['batch_id'] ) ) {
				$this->batch_facade( isset( $state['model'] ) ? (string) $state['model'] : '' )->cancel( (string) $state['batch_id'] );
			}
			$state['status'] = 'cancelled';
			update_option( self::BATCH_OPTION, $state, false );
		}
		return $state;
	}

	/**
	 * SQL condition selecting attachments the run still needs to touch.
	 *
	 * PDFs only qualify when the run includes them AND at least one
	 * PDF-applicable field (anything but alt text) is selected — otherwise
	 * they would be analyzed and billed with nothing to write, on every run.
	 *
	 * @param array $fields       Field keys.
	 * @param bool  $overwrite    Replace existing text too.
	 * @param bool  $include_pdfs Also select PDFs.
	 * @return string WHERE fragment (no leading AND).
	 */
	private function candidate_where( array $fields, $overwrite, $include_pdfs = false ) {
		$images = "(p.post_mime_type LIKE 'image/%' AND p.post_mime_type <> 'image/svg+xml'";
		if ( ! $overwrite ) {
			$needs = $this->missing_fields_where( $fields );
			if ( '' !== $needs ) {
				$images .= " AND ({$needs})";
			}
		}
		$images = $images . ')';

		$types      = array( $images );
		$pdf_fields = array_values( array_diff( $fields, array( 'alt_text' ) ) );
		if ( $include_pdfs && ! empty( $pdf_fields ) ) {
			$pdfs = "(p.post_mime_type = 'application/pdf'";
			if ( ! $overwrite ) {
				$needs = $this->missing_fields_where( $pdf_fields );
				if ( '' !== $needs ) {
					$pdfs .= " AND ({$needs})";
				}
			}
			$types[] = $pdfs . ')';
		}

		return "p.post_type = 'attachment' AND p.post_status <> 'trash' AND (" . implode( ' OR ', $types ) . ')';
	}

	/**
	 * OR-joined "this field is empty" SQL for the selected field keys.
	 *
	 * @param array $fields Field keys.
	 * @return string SQL fragment, or '' when no selected field matches.
	 */
	private function missing_fields_where( array $fields ) {
		global $wpdb;
		$needs = array();
		if ( in_array( 'alt_text', $fields, true ) ) {
			$needs[] = "NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} alt WHERE alt.post_id = p.ID AND alt.meta_key = '_wp_attachment_image_alt' AND TRIM(alt.meta_value) <> '')";
		}
		if ( in_array( 'title', $fields, true ) ) {
			$needs[] = "TRIM(p.post_title) = ''";
		}
		if ( in_array( 'caption', $fields, true ) ) {
			$needs[] = "TRIM(p.post_excerpt) = ''";
		}
		if ( in_array( 'description', $fields, true ) ) {
			$needs[] = "TRIM(p.post_content) = ''";
		}
		return implode( ' OR ', $needs );
	}

	/**
	 * How many files the run will process.
	 *
	 * @param array $fields       Field keys.
	 * @param bool  $overwrite    Replace existing text too.
	 * @param bool  $include_pdfs Also count PDFs.
	 * @return int
	 */
	public function candidates_count( array $fields, $overwrite, $include_pdfs = false ) {
		global $wpdb;
		$where = $this->candidate_where( $fields, $overwrite, $include_pdfs );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the WHERE fragment is assembled from literal SQL only.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where}" );
	}

	/**
	 * Next file the run should process.
	 *
	 * @param array $state Batch state.
	 * @return int Attachment ID, or 0 when finished.
	 */
	private function next_candidate( array $state ) {
		global $wpdb;
		$where = $this->candidate_where( (array) $state['fields'], ! empty( $state['overwrite'] ), ! empty( $state['include_pdfs'] ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the WHERE fragment is assembled from literal SQL only.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p WHERE {$where} AND p.ID > %d ORDER BY p.ID ASC LIMIT 1", (int) $state['last_id'] ) );
	}

	/**
	 * Field-completeness stats for the Image Text screen.
	 *
	 * @return array { total, alt_text, title, caption, description } (counts missing).
	 */
	public function stats() {
		global $wpdb;
		$base = "FROM {$wpdb->posts} p WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND p.post_mime_type LIKE 'image/%' AND p.post_mime_type <> 'image/svg+xml'";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the WHERE fragment is assembled from literal SQL only.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) {$base}" );
		$alt   = (int) $wpdb->get_var( "SELECT COUNT(*) {$base} AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} alt WHERE alt.post_id = p.ID AND alt.meta_key = '_wp_attachment_image_alt' AND TRIM(alt.meta_value) <> '')" );
		$title = (int) $wpdb->get_var( "SELECT COUNT(*) {$base} AND TRIM(p.post_title) = ''" );
		$capt  = (int) $wpdb->get_var( "SELECT COUNT(*) {$base} AND TRIM(p.post_excerpt) = ''" );
		$desc  = (int) $wpdb->get_var( "SELECT COUNT(*) {$base} AND TRIM(p.post_content) = ''" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return array(
			'total'       => $total,
			'alt_text'    => $alt,
			'title'       => $title,
			'caption'     => $capt,
			'description' => $desc,
		);
	}

	/* --------------------------------------------------------------------- *
	 * Media Library integration
	 * --------------------------------------------------------------------- */

	/**
	 * Tools row on attachment details (media modal, grid details, and the
	 * Edit Media screen): the Claude "Generate image text" button.
	 *
	 * @param array   $form_fields Fields.
	 * @param WP_Post $post        Attachment.
	 * @return array
	 */
	public function tools_field( $form_fields, $post ) {
		if ( ! wp_attachment_is_image( $post ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $form_fields;
		}
		if ( ! $this->ai->is_configured() || 'image/svg+xml' === $post->post_mime_type ) {
			return $form_fields;
		}

		$button = '<button type="button" class="button coywolf-seo-it-generate" data-id="' . (int) $post->ID . '">'
			. esc_html__( 'Generate image text', 'coywolf-seo' ) . '</button>';

		$form_fields['coywolf_seo_image_tools'] = array(
			'label' => __( 'Coywolf SEO', 'coywolf-seo' ),
			'input' => 'html',
			'html'  => '<div class="coywolf-seo-it-tools">' . $button
				. '<span class="coywolf-seo-it-tools-status" role="status"></span></div>',
		);
		return $form_fields;
	}

	/**
	 * Block-editor integration: Title/Caption/Description fields plus the
	 * generate button on the core Image block.
	 */
	public function editor_assets() {
		// Image Text is part of AI enrichment; when that is off, don't add the
		// Image-block panel at all.
		if ( ! Coywolf_SEO_Options::feature_enabled( 'ai' ) ) {
			return;
		}
		wp_enqueue_script(
			'coywolf-seo-it-editor',
			COYWOLF_SEO_URL . 'js/image-text-editor.js',
			array( 'wp-hooks', 'wp-compose', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n' ),
			Coywolf_SEO::VERSION,
			true
		);
		// The "content" inspector group (the block settings Content tab)
		// exists since WP 7.0; asking an older editor for an unknown group
		// would silently drop the panel, so those fall back to the default
		// group (the Settings tab). Pre-release suffixes like "-RC1" are
		// stripped so 7.0 release candidates compare as 7.0.
		$wp_version = (string) preg_replace( '/[^0-9.].*$/', '', get_bloginfo( 'version' ) );
		wp_add_inline_script(
			'coywolf-seo-it-editor',
			'window.coywolfSEOImageTextEditor = ' . wp_json_encode(
				array(
					'configured' => $this->ai->is_configured(),
					'contentTab' => version_compare( $wp_version, '7.0', '>=' ),
					/* translators: %s: the active AI service name (Claude, OpenAI, Gemini). */
					'genLabel'   => sprintf( __( 'Generate with %s', 'coywolf-seo' ), Coywolf_SEO_AI_Providers::current()->short_label() ),
				)
			) . ';',
			'before'
		);

		// Note: core's redundant image "Alternative text" control is hidden in JS
		// (js/image-text-editor.js) rather than CSS, so it works regardless of the
		// browser's :has() support or Gutenberg's exact class names.
	}

	/* --------------------------------------------------------------------- *
	 * Screen
	 * --------------------------------------------------------------------- */

	/**
	 * Render the Image Text page.
	 */
	public function render_page() {
		if ( ! current_user_can( Coywolf_SEO_Admin::CAPABILITY ) ) {
			return;
		}
		$configured = $this->ai->is_configured();
		$stats      = $this->stats();
		$state      = $this->get_batch();
		$status     = isset( $state['status'] ) ? (string) $state['status'] : 'idle';
		$defaults   = $this->default_fields();

		// Model choices for this run, defaulting to the Settings model. The
		// saved/default model stays selectable even when the live list is
		// unavailable.
		$settings_model = $this->ai->model();
		$models         = $configured ? $this->ai->models() : array();
		$model_ids      = wp_list_pluck( $models, 'id' );
		foreach ( array_unique( array( $settings_model, Coywolf_SEO_AI_Providers::current()->default_model() ) ) as $must_have ) {
			if ( ! in_array( $must_have, $model_ids, true ) ) {
				$models[] = array(
					'id'   => $must_have,
					'name' => $must_have,
				);
			}
		}

		// Initial candidate counts for the cost estimate — the unanalyzed set
		// (fill empty fields) and the whole library (re-analyze all). The JS
		// re-queries both when the field/PDF selection changes.
		$initial_count     = $this->candidates_count( $defaults, false );
		$initial_count_all = $this->candidates_count( $defaults, true );

		$field_labels = array(
			'alt_text'    => __( 'Alternative text', 'coywolf-seo' ),
			'title'       => __( 'Title', 'coywolf-seo' ),
			'caption'     => __( 'Caption', 'coywolf-seo' ),
			'description' => __( 'Description', 'coywolf-seo' ),
		);
		?>
		<div class="wrap coywolf-seo-it-image-text">
			<h1><?php esc_html_e( 'Image Text', 'coywolf-seo' ); ?></h1>
			<p><?php esc_html_e( 'The AI service looks at each image and writes its alternative text, title, caption, and description following accessibility best practices (WCAG-aligned) — in your site language. Alternative text is left empty for purely decorative images; the title, caption, and description are always written. Run it across the whole library here, or use the generate button on any image in the Media Library and in the editor.', 'coywolf-seo' ); ?></p>

			<?php if ( ! $configured ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php esc_html_e( 'Add your AI service API key to enable Image Text.', 'coywolf-seo' ); ?>
						<a href="<?php echo esc_url( add_query_arg( 'page', Coywolf_SEO_Admin::SLUG_SETTINGS, admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Open Settings', 'coywolf-seo' ); ?></a>
					</p>
				</div>
				</div>
				<?php
				return;
			endif;
			?>

			<div class="coywolf-seo-it-panel">
				<h2><?php esc_html_e( 'Library status', 'coywolf-seo' ); ?></h2>
				<table class="widefat striped coywolf-seo-it-stats">
					<tbody>
						<tr>
							<td><?php esc_html_e( 'Images', 'coywolf-seo' ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></td>
						</tr>
						<?php foreach ( $field_labels as $key => $label ) : ?>
							<tr>
								<td>
								<?php
								/* translators: %s: field name */
								echo esc_html( sprintf( __( 'Missing %s', 'coywolf-seo' ), $label ) );
								?>
								</td>
								<td><?php echo esc_html( number_format_i18n( $stats[ $key ] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="coywolf-seo-it-panel" id="coywolf-seo-it-ai" data-status="<?php echo esc_attr( $status ); ?>">
				<h2><?php esc_html_e( 'Bulk write image text', 'coywolf-seo' ); ?></h2>
				<p><?php esc_html_e( 'The run processes the library in small chunks, so it survives slow servers and big libraries. You can stop at any point and resume later — finished files are never re-billed.', 'coywolf-seo' ); ?></p>

				<fieldset id="coywolf-seo-it-ai-fields">
					<legend class="screen-reader-text"><?php esc_html_e( 'Fields to write', 'coywolf-seo' ); ?></legend>
					<?php foreach ( $field_labels as $key => $label ) : ?>
						<label class="coywolf-seo-it-check">
							<input type="checkbox" name="coywolf-seo-it-ai-field" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $defaults, true ) ); ?> />
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>
				<label class="coywolf-seo-it-check">
					<input type="checkbox" id="coywolf-seo-it-ai-pdfs" />
					<?php esc_html_e( 'Include PDFs — the AI service reads each PDF and writes its title, caption, and description (PDFs have no alternative text)', 'coywolf-seo' ); ?>
				</label>
				<label class="coywolf-seo-it-check">
					<input type="checkbox" id="coywolf-seo-it-ai-overwrite" <?php checked( $this->default_overwrite() ); ?> />
					<?php esc_html_e( 'Overwrite text that already exists (default: only fill empty fields)', 'coywolf-seo' ); ?>
				</label>
				<label class="coywolf-seo-it-check">
					<input type="checkbox" id="coywolf-seo-it-ai-propagate" />
					<?php esc_html_e( 'Also add new alternative text and captions to Image blocks already placed in posts and pages that are missing them', 'coywolf-seo' ); ?>
				</label>
				<p class="description coywolf-seo-it-indent"><?php esc_html_e( 'With this on, the run also fills in empty alt text and captions for Image blocks that reference the file. Existing values are never changed unless “Overwrite text” is checked.', 'coywolf-seo' ); ?></p>

				<p>
					<label for="coywolf-seo-it-ai-model"><strong><?php esc_html_e( 'Model for this run', 'coywolf-seo' ); ?></strong></label><br />
					<select id="coywolf-seo-it-ai-model">
						<?php foreach ( $models as $model ) : ?>
							<?php
							$model_pricing       = Coywolf_SEO_Image_AI::model_pricing( $model['id'] );
							$model_pricing_batch = Coywolf_SEO_Image_AI::model_pricing( $model['id'], true );
							?>
							<option value="<?php echo esc_attr( $model['id'] ); ?>"
								data-in="<?php echo esc_attr( null !== $model_pricing ? number_format( $model_pricing['input'], 2, '.', '' ) : '' ); ?>"
								data-out="<?php echo esc_attr( null !== $model_pricing ? number_format( $model_pricing['output'], 2, '.', '' ) : '' ); ?>"
								data-batch-in="<?php echo esc_attr( null !== $model_pricing_batch ? number_format( $model_pricing_batch['input'], 2, '.', '' ) : '' ); ?>"
								data-batch-out="<?php echo esc_attr( null !== $model_pricing_batch ? number_format( $model_pricing_batch['output'], 2, '.', '' ) : '' ); ?>"
								<?php selected( $settings_model, $model['id'] ); ?>><?php echo esc_html( $model['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>

				<div class="notice notice-info inline coywolf-seo-it-estimate" id="coywolf-seo-it-ai-estimate"
					data-count="<?php echo (int) $initial_count; ?>"
					data-count-text="<?php echo esc_attr( number_format_i18n( $initial_count ) ); ?>"
					data-count-all="<?php echo (int) $initial_count_all; ?>"
					data-count-all-text="<?php echo esc_attr( number_format_i18n( $initial_count_all ) ); ?>">
					<p class="coywolf-seo-it-estimate-empty"></p>
					<p class="coywolf-seo-it-estimate-all"></p>
					<p class="coywolf-seo-it-estimate-note description"></p>
				</div>

				<?php $supports_vision_batch = Coywolf_SEO_AI_Providers::current()->supports_vision_batch(); ?>
				<p>
					<button type="button" class="button button-primary" id="coywolf-seo-it-ai-start"><?php esc_html_e( 'Start', 'coywolf-seo' ); ?></button>
					<button type="button" class="button" id="coywolf-seo-it-ai-reanalyze"><?php esc_html_e( 'Re-analyze all', 'coywolf-seo' ); ?></button>
					<label class="coywolf-seo-it-check coywolf-seo-it-inline">
						<input type="checkbox" id="coywolf-seo-it-ai-realtime" <?php checked( ! $supports_vision_batch ); ?> <?php disabled( ! $supports_vision_batch ); ?> />
						<?php esc_html_e( 'Use real-time processing', 'coywolf-seo' ); ?>
					</label>
					<button type="button" class="button hidden" id="coywolf-seo-it-ai-stop"><?php esc_html_e( 'Stop', 'coywolf-seo' ); ?></button>
					<button type="button" class="button hidden" id="coywolf-seo-it-ai-resume"><?php esc_html_e( 'Resume', 'coywolf-seo' ); ?></button>
				</p>
				<?php if ( $supports_vision_batch ) : ?>
					<p class="description"><?php esc_html_e( 'By default the run uses the cheaper background Batch API (about half price; results within the hour, up to 24 hours). Tick this to process immediately at the standard rate. Keep this page open so finished files are saved as they come back.', 'coywolf-seo' ); ?></p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'This AI service processes image text in real time (the discounted Batch API is not available for it), so each file is analyzed immediately at the standard rate.', 'coywolf-seo' ); ?></p>
				<?php endif; ?>

				<div class="coywolf-seo-it-progress hidden" id="coywolf-seo-it-ai-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="coywolf-seo-it-progress-bar"></div><span class="coywolf-seo-it-progress-text" aria-live="polite"></span></div>
				<p class="coywolf-seo-it-current hidden" id="coywolf-seo-it-ai-current" aria-live="polite"></p>
				<div class="notice notice-error inline hidden" id="coywolf-seo-it-ai-message"><p></p></div>
				<details class="hidden" id="coywolf-seo-it-ai-errors">
					<summary aria-live="polite"></summary>
					<ul></ul>
				</details>
			</div>

			<div class="coywolf-seo-it-panel">
					<h2><?php esc_html_e( 'Extra instructions', 'coywolf-seo' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="coywolf_seo_save_image_instructions" />
						<?php wp_nonce_field( 'coywolf_seo_image_instructions' ); ?>
						<label for="coywolf-seo-it-instructions" class="screen-reader-text"><?php esc_html_e( 'Extra instructions', 'coywolf-seo' ); ?></label>
						<textarea id="coywolf-seo-it-instructions" class="large-text" rows="3" name="coywolf_seo_image_instructions"><?php echo esc_textarea( (string) Coywolf_SEO_Options::get( 'image_text_instructions' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Optional. Appended to every analysis request — e.g. brand terms to use, a tone of voice, or topics to emphasize. Image text is written in your site language automatically.', 'coywolf-seo' ); ?></p>
						<?php submit_button( __( 'Save instructions', 'coywolf-seo' ) ); ?>
					</form>
				</div>

				<p class="description"><?php esc_html_e( 'Privacy note: when you use Image Text, the image (scaled down) and its filename are sent to the selected AI service for analysis. Nothing is sent unless you click a generate button or run the bulk writer.', 'coywolf-seo' ); ?></p>
		</div>
		<?php
	}

	/* --------------------------------------------------------------------- *
	 * REST routes
	 * --------------------------------------------------------------------- */

	/**
	 * Register the Image Text REST routes.
	 */
	public function register_routes() {
		register_rest_route(
			'coywolf-seo/v1',
			'/image-text/(?P<op>start|step|cancel|status|estimate)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'rest_batch' ),
			)
		);
		register_rest_route(
			'coywolf-seo/v1',
			'/image-text/generate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( $this, 'can_edit_attachment' ),
				'callback'            => array( $this, 'rest_generate' ),
				'args'                => array(
					'id'   => array(
						'type'     => 'integer',
						'required' => true,
					),
					'save' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);
	}

	/**
	 * Bulk runs require the management capability.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( Coywolf_SEO_Admin::CAPABILITY );
	}

	/**
	 * Editors may generate text for attachments they can edit.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_edit_attachment( $request ) {
		$id = (int) $request['id'];
		return $id > 0 && current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $id );
	}

	/**
	 * Bulk image-text REST operations (start/step/cancel/status/estimate).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_batch( $request ) {
		switch ( $request['op'] ) {
			case 'start':
				$fields = array_map( 'sanitize_key', (array) $request->get_param( 'fields' ) );
				$state  = $this->start_batch(
					$fields,
					rest_sanitize_boolean( $request->get_param( 'overwrite' ) ),
					rest_sanitize_boolean( $request->get_param( 'resume' ) ),
					sanitize_text_field( (string) $request->get_param( 'model' ) ),
					rest_sanitize_boolean( $request->get_param( 'include_pdfs' ) ),
					rest_sanitize_boolean( $request->get_param( 'propagate_captions' ) ),
					rest_sanitize_boolean( $request->get_param( 'realtime' ) )
				);
				break;
			case 'estimate':
				$fields       = array_values(
					array_intersect(
						array_map( 'sanitize_key', (array) $request->get_param( 'fields' ) ),
						self::FIELDS
					)
				);
				$include_pdfs = rest_sanitize_boolean( $request->get_param( 'include_pdfs' ) );
				$count        = empty( $fields ) ? 0 : $this->candidates_count( $fields, false, $include_pdfs );
				$count_all    = empty( $fields ) ? 0 : $this->candidates_count( $fields, true, $include_pdfs );
				$state        = array(
					'count'          => $count,
					'count_text'     => number_format_i18n( $count ),
					'count_all'      => $count_all,
					'count_all_text' => number_format_i18n( $count_all ),
				);
				break;
			case 'step':
				$state = $this->step_batch();
				break;
			case 'cancel':
				$state = $this->cancel_batch();
				break;
			default:
				$state = $this->get_batch();
		}
		return is_wp_error( $state ) ? $state : rest_ensure_response( $state );
	}

	/**
	 * Generate (and by default save) image text for one attachment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_generate( $request ) {
		$result = $this->generate_single( (int) $request['id'], rest_sanitize_boolean( $request['save'] ), (int) $request->get_param( 'post_id' ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/* --------------------------------------------------------------------- *
	 * Assets
	 * --------------------------------------------------------------------- */

	/**
	 * Enqueue the bulk-page script (and the media-tools script on the Edit
	 * Media screen, which does not fire wp_enqueue_media).
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'post.php' === $hook ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && 'attachment' === $screen->post_type ) {
				$this->enqueue_media();
			}
		}

		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}

		wp_enqueue_script( 'coywolf-seo-image-text', COYWOLF_SEO_URL . 'js/image-text.js', array(), Coywolf_SEO::VERSION, true );
		wp_add_inline_script(
			'coywolf-seo-image-text',
			'window.coywolfSEOImageText = ' . wp_json_encode(
				array(
					'restRoot'    => esc_url_raw( rest_url( 'coywolf-seo/v1' ) ),
					'nonce'       => wp_create_nonce( 'wp_rest' ),
					// Whether the active provider's Batch API handles vision; when
					// false the run is always real-time and the estimate prices at
					// the standard rate.
					'visionBatch' => Coywolf_SEO_AI_Providers::current()->supports_vision_batch(),
					'estTokens'   => array(
						'in'  => Coywolf_SEO_Image_AI::EST_INPUT_TOKENS,
						'out' => Coywolf_SEO_Image_AI::EST_OUTPUT_TOKENS,
					),
					'i18n'        => array(
						'starting'         => __( 'Contacting the AI service…', 'coywolf-seo' ),
						'batchWaiting'     => __( 'Processing in the background at the AI service… keep this page open to save results as they finish.', 'coywolf-seo' ),
						/* translators: 1: number of images done, 2: total images — replaced in JS. */
						'progress'         => __( '%1$s of %2$s analyzed', 'coywolf-seo' ),
						/* translators: %s: image file name — replaced in JS. */
						'analyzingFile'    => __( 'Analyzing %s…', 'coywolf-seo' ),
						'doneReload'       => __( 'Done — reloading…', 'coywolf-seo' ),
						'failed'           => __( 'failed', 'coywolf-seo' ),
						/* translators: %s: number of posts/pages updated — replaced in JS. */
						'postsUpdated'     => __( '%s posts updated', 'coywolf-seo' ),
						'errorsOf'         => __( 'errors', 'coywolf-seo' ),
						'requestErr'       => __( 'The request failed. Check your connection and try again.', 'coywolf-seo' ),
						/* translators: 1: file count, 2: estimated cost — replaced in JS. */
						'estEmpty'         => __( 'Write missing text (Start): %1$s files — about %2$s.', 'coywolf-seo' ),
						/* translators: %s: file count — replaced in JS. */
						'estEmptyUnknown'  => __( 'Write missing text (Start): %s files — no pricing data for this model.', 'coywolf-seo' ),
						/* translators: 1: file count, 2: estimated cost — replaced in JS. */
						'estAll'           => __( 'Re-analyze all: %1$s files — about %2$s.', 'coywolf-seo' ),
						/* translators: %s: file count — replaced in JS. */
						'estAllUnknown'    => __( 'Re-analyze all: %s files — no pricing data for this model.', 'coywolf-seo' ),
						'estNote'          => __( 'Assumes ~2,500 input / 250 output tokens per file (including surrounding-article context); actual cost varies with image size and text length, and multi-page PDFs cost more.', 'coywolf-seo' ),
						'estNothing'       => __( 'Write missing text (Start): nothing to do — every file already has the selected fields filled.', 'coywolf-seo' ),
						'estNoFields'      => __( 'Pick at least one field to estimate and run.', 'coywolf-seo' ),
						'confirmReanalyze' => __( 'Re-analyze every image (and PDF, if included) for the selected fields, overwriting text that already exists? This makes a fresh API call for every file and costs the full estimated amount.', 'coywolf-seo' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Enqueue the media-tools script for the per-image "Generate" button in
	 * the media modal and on the Edit Media screen.
	 */
	public function enqueue_media() {
		if ( wp_script_is( 'coywolf-seo-image-text-media', 'enqueued' ) || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		wp_enqueue_script( 'coywolf-seo-image-text-media', COYWOLF_SEO_URL . 'js/image-text-media.js', array(), Coywolf_SEO::VERSION, true );
		wp_add_inline_script(
			'coywolf-seo-image-text-media',
			'window.coywolfSEOImageTextMedia = ' . wp_json_encode(
				array(
					'restRoot' => esc_url_raw( rest_url( 'coywolf-seo/v1' ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'i18n'     => array(
						'working' => __( 'Analyzing…', 'coywolf-seo' ),
						'done'    => __( 'Done.', 'coywolf-seo' ),
						'saved'   => __( 'Image text saved.', 'coywolf-seo' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/* --------------------------------------------------------------------- *
	 * Extra instructions (relocated from Settings)
	 * --------------------------------------------------------------------- */

	/**
	 * Save the "Extra instructions" field. Stored as a partial update so it
	 * never disturbs the other Coywolf SEO settings saved on the Settings page.
	 */
	public function save_instructions() {
		if ( ! current_user_can( Coywolf_SEO_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Image Text.', 'coywolf-seo' ) );
		}
		check_admin_referer( 'coywolf_seo_image_instructions' );

		$raw = isset( $_POST['coywolf_seo_image_instructions'] )
			? wp_unslash( $_POST['coywolf_seo_image_instructions'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via Coywolf_SEO_Options::sanitize() below.
			: '';
		Coywolf_SEO_Options::update(
			Coywolf_SEO_Options::sanitize( array( 'image_text_instructions' => $raw ) )
		);

		wp_safe_redirect( add_query_arg( 'coywolf-seo-saved', 'image-text', admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}
}
