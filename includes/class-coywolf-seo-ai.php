<?php
/**
 * AI enrichment for Coywolf SEO.
 *
 * When enabled (with the site owner's own API key), published posts and pages
 * are analyzed in the background and the entities they discuss are added to
 * their Article schema — primary subjects as `about`, passing references as
 * `mentions`, each grounded to a real Wikidata item. All AI work routes through
 * the active provider ({@see Coywolf_SEO_AI_Providers::current()}: Claude,
 * OpenAI, or Gemini), so the engine here stays provider-agnostic.
 *
 * Hallucinated identifiers are designed out with retrieve-and-verify:
 *
 * 1. The model extracts entity MENTIONS only — surface form, normalized name,
 *    type (Person/Organization/Place/Thing), a one-line description, and a
 *    primary/secondary split. It is explicitly instructed not to produce
 *    QIDs or URLs.
 * 2. A deterministic lookup against Wikidata's public wbsearchentities API
 *    (no key required) returns real candidate items for each name.
 * 3. When several candidates exist, the model chooses among them — safe,
 *    because it is selecting from actual options (with their Wikidata
 *    descriptions) rather than generating an ID from memory. A chosen QID
 *    that is not in the candidate list is discarded.
 * 4. The chosen items' `instance of` (P31) claims are checked roughly
 *    against the expected type: disambiguation pages are dropped, a Person
 *    must be a human (Q5), and a non-Person must not be one.
 *
 * Text generation goes through the WordPress PHP AI Client SDK with the active
 * provider's php-ai-client provider class; the parts the SDK does not abstract
 * (model listing, the Batch API) are delegated to the provider.
 *
 * @package CoywolfSEO
 */

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Entity extraction, grounding, and storage.
 */
final class Coywolf_SEO_AI {

	/**
	 * Post meta holding the grounded entities.
	 */
	const META_KEY = '_coywolf_seo_entities';

	/**
	 * Single-event cron hook that analyzes one post.
	 */
	const CRON_HOOK = 'coywolf_seo_ai_analyze';

	/**
	 * Cron hook driving the bulk enrich-all queue.
	 */
	const BULK_HOOK = 'coywolf_seo_ai_bulk';

	/**
	 * Option holding the bulk run's state.
	 */
	const BULK_OPTION = 'coywolf_seo_bulk_enrich';

	/**
	 * Per-post staging meta during a batch run (cleaned at finalize).
	 */
	const BULK_META = '_coywolf_seo_bulk_data';

	/**
	 * Default Claude model. Filterable via coywolf_seo_ai_model.
	 */
	const DEFAULT_MODEL = 'claude-opus-4-8';

	/**
	 * Wikidata API endpoint.
	 */
	const WIKIDATA_API = 'https://www.wikidata.org/w/api.php';

	/**
	 * Post types that get analyzed.
	 *
	 * @var string[]
	 */
	private $post_types = array( 'post', 'page' );


	/**
	 * Hook everything up.
	 */
	public function init() {
		add_action( self::CRON_HOOK, array( $this, 'analyze_post' ) );
		add_action( 'transition_post_status', array( $this, 'maybe_queue' ), 10, 3 );
		add_filter( 'http_request_args', array( $this, 'extend_anthropic_timeout' ), 10, 2 );
		add_action( 'wp_ajax_coywolf_seo_reanalyze', array( $this, 'ajax_reanalyze' ) );

		add_action( self::BULK_HOOK, array( $this, 'bulk_worker' ) );
		add_action( 'admin_post_coywolf_seo_bulk_enrich', array( $this, 'handle_bulk_start' ) );
		add_action( 'admin_post_coywolf_seo_bulk_stop', array( $this, 'handle_bulk_stop' ) );
		add_action( 'admin_post_coywolf_seo_bulk_resume', array( $this, 'handle_bulk_resume' ) );
		add_action( 'admin_post_coywolf_seo_bulk_cancel', array( $this, 'handle_bulk_cancel' ) );
		add_action( 'wp_ajax_coywolf_seo_bulk_status', array( $this, 'ajax_bulk_status' ) );
		add_action( 'wp_ajax_coywolf_seo_bulk_estimate', array( $this, 'ajax_bulk_estimate' ) );
		add_action( 'wp_ajax_coywolf_seo_ai_test', array( $this, 'ajax_ai_test' ) );
	}

	/**
	 * Start enriching every published post and page: build the queue and
	 * kick the background chain. Existing analyses that already match the
	 * current content and configuration are skipped by the usual hash, so
	 * only stale or missing ones cost API calls.
	 */
	public function handle_bulk_start() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to run bulk enrichment.', 'coywolf-seo' ) );
		}
		check_admin_referer( 'coywolf_seo_bulk_enrich' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above.
		$this->start_bulk( ! empty( $_POST['coywolf_seo_bulk_force'] ) );

		wp_safe_redirect( admin_url( 'admin.php?page=coywolf-seo-settings&bulk-started=1' ) );
		exit;
	}

	/**
	 * Start a fresh bulk run: clean up any live run (including its remote
	 * batch), pre-screen the content, seed the state machine, and submit
	 * the first batch.
	 *
	 * @param bool $force Re-analyze everything: skip the unchanged-content
	 *                    check so every published post and page gets fresh
	 *                    entities and descriptions.
	 */
	public function start_bulk( $force = false ) {
		// A stale tab or double-submit must not orphan an in-flight run:
		// clean up (including the remote batch) before starting fresh.
		$existing = $this->bulk_state_fresh();
		if ( isset( $existing['status'] ) && in_array( $existing['status'], array( 'running', 'paused' ), true ) ) {
			$this->cancel_bulk();
		}

		if ( $this->enabled() || $this->descriptions_on() ) {
			$ids = get_posts(
				array(
					'post_type'      => $this->post_types,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);

			// Pre-screen: posts already analyzed with the current content
			// and configuration never enter the batch at all.
			$queue   = array();
			$skipped = 0;
			foreach ( array_chunk( $ids, 200 ) as $chunk ) {
				_prime_post_caches( $chunk, false, true ); // prime post + meta caches; term cache not needed.
				foreach ( $chunk as $post_id ) {
					$post = get_post( $post_id );
					if ( ! $post ) {
						continue;
					}
					$content = $this->plain_content( $post );
					if ( '' === trim( $content ) ) {
						++$skipped;
						continue;
					}
					if ( ! $force ) {
						$hash  = md5( get_the_title( $post ) . "\n" . $content . "\n" . $this->config_signature() );
						$saved = get_post_meta( $post_id, self::META_KEY, true );
						if ( is_array( $saved ) && isset( $saved['hash'] ) && $saved['hash'] === $hash ) {
							++$skipped;
							continue;
						}
					}
					$queue[] = (int) $post_id;
				}
			}

			update_option(
				self::BULK_OPTION,
				array(
					'status'        => empty( $queue ) ? 'done' : 'running',
					'stage'         => $this->enabled() ? 'extract_submit' : 'describe_submit',
					'total'         => count( $queue ),
					'done'          => 0,
					'failed'        => 0,
					'skipped'       => $skipped,
					'pipeline'      => $queue,
					'queue'         => array(),
					'batch_id'      => '',
					'usage_in'      => 0,
					'usage_out'     => 0,
					'last_error'    => '',
					'paused_reason' => '',
					'started'       => gmdate( 'c' ),
					'finished'      => empty( $queue ) ? gmdate( 'c' ) : '',
				),
				false
			);

			if ( ! empty( $queue ) ) {
				// Submit the first batch right away, then let cron and the
				// status polling advance the run.
				$this->bulk_advance( 15 );
				if ( ! wp_next_scheduled( self::BULK_HOOK ) ) {
					wp_schedule_single_event( time() + 30, self::BULK_HOOK );
				}
				spawn_cron();
			}
		}
	}

	/**
	 * Stop button: pause the run, keeping the queue for Resume.
	 */
	public function handle_bulk_stop() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to run bulk enrichment.', 'coywolf-seo' ) );
		}
		check_admin_referer( 'coywolf_seo_bulk_stop' );
		$this->pause_bulk();
		wp_safe_redirect( admin_url( 'admin.php?page=coywolf-seo-settings' ) );
		exit;
	}

	/**
	 * Resume button: continue a paused run.
	 */
	public function handle_bulk_resume() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to run bulk enrichment.', 'coywolf-seo' ) );
		}
		check_admin_referer( 'coywolf_seo_bulk_resume' );
		$this->resume_bulk();
		wp_safe_redirect( admin_url( 'admin.php?page=coywolf-seo-settings' ) );
		exit;
	}

	/**
	 * Cancel button: kill the run for good.
	 */
	public function handle_bulk_cancel() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to run bulk enrichment.', 'coywolf-seo' ) );
		}
		check_admin_referer( 'coywolf_seo_bulk_cancel' );
		$this->cancel_bulk();
		wp_safe_redirect( admin_url( 'admin.php?page=coywolf-seo-settings' ) );
		exit;
	}

	/**
	 * Pause: workers stop claiming immediately (a post already mid-API
	 * call finishes), the queue and counters stay for Resume.
	 */
	public function pause_bulk() {
		$state = $this->bulk_state_fresh();
		if ( isset( $state['status'] ) && 'running' === $state['status'] ) {
			$state['status'] = 'paused';
			update_option( self::BULK_OPTION, $state, false );
		}
		wp_unschedule_hook( self::BULK_HOOK );
	}

	/**
	 * Resume a paused run: fresh token, fresh worker fleet, watchdog back.
	 */
	public function resume_bulk() {
		$state = $this->bulk_state_fresh();
		if ( ! isset( $state['status'] ) || 'paused' !== $state['status'] || empty( $state['pipeline'] ) ) {
			return;
		}
		$state['status']        = 'running';
		$state['paused_reason'] = '';
		update_option( self::BULK_OPTION, $state, false );

		$this->bulk_advance( 10 );
		if ( ! wp_next_scheduled( self::BULK_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::BULK_HOOK );
		}
		spawn_cron();
	}

	/**
	 * Cancel: delete the run state — the token dies with it, so stray
	 * runner chains are rejected, claims return nothing, and the
	 * watchdog is unscheduled. Only a post already mid-API call in a
	 * live worker still lands.
	 */
	public function cancel_bulk() {
		$state = $this->bulk_state_fresh();
		if ( ! empty( $state['batch_id'] ) ) {
			$this->batch()->cancel( (string) $state['batch_id'] );
		}
		// Tokens already billed stay in the lifetime telemetry.
		$cancel_successes = max( 0, (int) ( $state['done'] ?? 0 ) - (int) ( $state['failed'] ?? 0 ) );
		if ( $cancel_successes > 0 && ( (int) ( $state['usage_in'] ?? 0 ) > 0 || (int) ( $state['usage_out'] ?? 0 ) > 0 ) ) {
			Coywolf_SEO_AI_Batch::record_usage( (int) $state['usage_in'], (int) $state['usage_out'], $cancel_successes );
		}
		delete_metadata( 'post', 0, self::BULK_META, '', true );
		delete_option( self::BULK_OPTION );
		wp_unschedule_hook( self::BULK_HOOK );
	}

	/**
	 * Process the queue inside a time budget, then hand off to the next
	 * cron tick. The settings page's status polling keeps WP-Cron firing,
	 * so progress continues while the page is open.
	 */
	public function bulk_worker() {
		$this->bulk_advance( 25 );
		$state = $this->bulk_state_fresh();
		if ( isset( $state['status'] ) && 'running' === $state['status'] && ! wp_next_scheduled( self::BULK_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::BULK_HOOK );
		}
	}





	/**
	 * The Batch client for the active provider, key, and model.
	 *
	 * @return Coywolf_SEO_AI_Batch
	 */
	private function batch() {
		$provider = Coywolf_SEO_AI_Providers::current();
		return new Coywolf_SEO_AI_Batch( $provider, $provider->key(), $provider->model() );
	}

	/**
	 * Advance the staged batch state machine inside a time budget. A
	 * non-blocking named lock keeps cron ticks and status polls from
	 * advancing concurrently.
	 *
	 * Stages: extract_submit → extract_wait → ground → disambig_submit →
	 * disambig_wait → verify → describe_submit → describe_wait → finalize.
	 * Entities-only runs skip the describe stages; descriptions-only runs
	 * start at describe_submit.
	 *
	 * @param int $budget Seconds to work.
	 */
	public function bulk_advance( $budget ) {
		global $wpdb;
		$lock = 'coywolf_seo_bulk_' . get_current_blog_id();
		// Advisory: on MySQL an explicit 0 means another process is
		// advancing; non-MySQL backends return other values and proceed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- non-blocking named lock; one advancer at a time.
		if ( '0' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return;
		}

		$deadline = time() + max( 3, (int) $budget );
		while ( time() < $deadline ) {
			$state = $this->bulk_state_fresh();
			if ( ! isset( $state['status'] ) || 'running' !== $state['status'] ) {
				break;
			}
			if ( ! isset( $state['stage'] ) ) {
				// A run left over from a pre-batch plugin version: its
				// state shape is unknown to this machine. Clear it.
				delete_option( self::BULK_OPTION );
				wp_unschedule_hook( self::BULK_HOOK );
				break;
			}
			if ( ! $this->bulk_step( $state, $deadline ) ) {
				break; // Waiting on Anthropic, paused, or finished.
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- releases the named lock above.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}

	/**
	 * One state-machine step.
	 *
	 * @param array $state    Fresh run state.
	 * @param int   $deadline Unix time budget limit for drains.
	 * @return bool Whether another step can run immediately.
	 */
	private function bulk_step( array $state, $deadline ) {
		switch ( $state['stage'] ) {
			case 'extract_submit':
				return $this->bulk_submit_stage( $state, 'extract_wait', $this->extract_system(), false, 2000 );

			case 'describe_submit':
				return $this->bulk_submit_stage( $state, 'describe_wait', $this->describe_system(), false, 300 );

			case 'disambig_submit':
				return $this->bulk_submit_stage( $state, 'disambig_wait', $this->disambiguate_system(), true, 1000 );

			case 'extract_wait':
				return $this->bulk_collect_stage(
					$state,
					function ( $post_id, $text, &$row ) {
						$row['mentions'] = $this->parse_mentions( $text );
					},
					'ground'
				);

			case 'disambig_wait':
				return $this->bulk_collect_stage(
					$state,
					function ( $post_id, $text, &$row ) {
						$decoded        = $this->decode_json( $text );
						$row['choices'] = is_array( $decoded ) ? $decoded : array();
					},
					'verify'
				);

			case 'describe_wait':
				return $this->bulk_collect_stage(
					$state,
					function ( $post_id, $text, &$row ) {
						$row['description'] = $this->clean_description( $text );
					},
					'finalize'
				);

			case 'ground':
				while ( time() < $deadline && ! empty( $state['queue'] ) ) {
					$post_id          = (int) array_shift( $state['queue'] );
					$row              = (array) get_post_meta( $post_id, self::BULK_META, true );
					$result           = $this->ground_candidates( isset( $row['mentions'] ) ? (array) $row['mentions'] : array() );
					$row['mentions']  = $result[0];
					$row['ambiguous'] = $result[1];
					update_post_meta( $post_id, self::BULK_META, $row );
					if ( ! $this->bulk_persist( $state ) ) {
						return false;
					}
				}
				if ( empty( $state['queue'] ) ) {
					$needs_model = array();
					foreach ( (array) $state['pipeline'] as $post_id ) {
						$row = (array) get_post_meta( (int) $post_id, self::BULK_META, true );
						if ( ! empty( $row['ambiguous'] ) ) {
							$needs_model[] = (int) $post_id;
						}
					}
					$state['queue'] = $needs_model;
					$state['stage'] = ! empty( $needs_model ) ? 'disambig_submit' : 'verify';
					if ( 'verify' === $state['stage'] ) {
						$state['queue'] = array_values( (array) $state['pipeline'] );
					}
					return $this->bulk_persist( $state );
				}
				return true;

			case 'verify':
				while ( time() < $deadline && ! empty( $state['queue'] ) ) {
					$post_id  = (int) array_shift( $state['queue'] );
					$row      = (array) get_post_meta( $post_id, self::BULK_META, true );
					$mentions = isset( $row['mentions'] ) ? (array) $row['mentions'] : array();
					if ( ! empty( $row['ambiguous'] ) && ! empty( $row['choices'] ) ) {
						$mentions = $this->apply_choices( $mentions, (array) $row['ambiguous'], (array) $row['choices'] );
					}
					$row['entities'] = $this->verify_resolved( $mentions );
					update_post_meta( $post_id, self::BULK_META, $row );
					if ( ! $this->bulk_persist( $state ) ) {
						return false;
					}
				}
				if ( empty( $state['queue'] ) ) {
					$state['stage'] = $this->descriptions_on() ? 'describe_submit' : 'finalize';
					$state['queue'] = array_values( (array) $state['pipeline'] );
					return $this->bulk_persist( $state );
				}
				return true;

			case 'finalize':
				while ( time() < $deadline && ! empty( $state['queue'] ) ) {
					$post_id = (int) array_shift( $state['queue'] );
					$this->bulk_finalize_post( $post_id );
					$state['done'] = (int) $state['done'] + 1;
					if ( ! $this->bulk_persist( $state ) ) {
						return false;
					}
				}
				if ( empty( $state['queue'] ) ) {
					$state['status']   = 'done';
					$state['finished'] = gmdate( 'c' );
					if ( ! $this->bulk_persist( $state ) ) {
						return false;
					}
					$successes = max( 0, (int) $state['done'] - (int) $state['failed'] );
					if ( $successes > 0 ) {
						Coywolf_SEO_AI_Batch::record_usage( (int) $state['usage_in'], (int) $state['usage_out'], $successes );
					}
					return false;
				}
				return true;
		}
		return false;
	}

	/**
	 * Submit a model batch for the current pipeline (or the queued
	 * subset for disambiguation).
	 *
	 * @param array  $state      Run state.
	 * @param string $next_stage Wait stage on success.
	 * @param string $system     System prompt for every request.
	 * @param bool   $queued     Build from state.queue (disambiguation)
	 *                           instead of the whole pipeline.
	 * @param int    $max_tokens Per-request output allowance — kept tight
	 *                           because Anthropic validates the batch's
	 *                           worst-case cost against the credit balance.
	 * @return bool
	 */
	private function bulk_submit_stage( array $state, $next_stage, $system, $queued, $max_tokens = 1000 ) {
		// First entry into the stage seeds its submission queue.
		if ( ! isset( $state['stage_queue'] ) || ! is_array( $state['stage_queue'] ) ) {
			$state['stage_queue'] = $queued ? array_values( (array) $state['queue'] ) : array_values( (array) $state['pipeline'] );
		}

		/**
		 * Posts per submitted batch — Anthropic caps batches at 100k
		 * requests / 256 MB; chunking keeps payloads well clear.
		 *
		 * Smaller batches also keep the upfront worst-case cost check
		 * (sum of every request's max_tokens) well under modest balances.
		 *
		 * @param int $size Default 100.
		 */
		$chunk_size = max( 1, (int) apply_filters( 'coywolf_seo_bulk_batch_size', 100 ) );
		$batch      = $this->batch();
		$requests   = array();
		$bytes      = 0;
		$count      = 0;
		while ( ! empty( $state['stage_queue'] ) && $count < $chunk_size && $bytes < 32 * MB_IN_BYTES ) {
			$post_id = (int) array_shift( $state['stage_queue'] );
			$post    = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			$title   = get_the_title( $post );
			$content = $this->plain_content( $post );
			if ( $queued ) {
				$row  = (array) get_post_meta( $post_id, self::BULK_META, true );
				$user = $this->disambiguate_user( (array) ( $row['ambiguous'] ?? array() ), $title, $content );
			} else {
				$user = $this->prompt_user( $title, $content );
			}
			$bytes     += strlen( $user );
			$requests[] = $batch->request( 'p' . $post_id, $system, $user, $max_tokens );
			++$count;
		}

		if ( empty( $requests ) ) {
			// Nothing left to ask the model in this stage.
			$state['stage']       = $next_stage;
			$state['batch_id']    = '';
			$state['stage_queue'] = null;
			return $this->bulk_persist( $state );
		}

		$batch_id = $batch->submit( $requests );
		if ( is_wp_error( $batch_id ) ) {
			$this->bulk_pause_with( $state, $batch_id->get_error_message() );
			return false;
		}
		$state['stage']    = $next_stage;
		$state['batch_id'] = $batch_id;
		$this->bulk_persist( $state );
		return false; // Wait for Anthropic.
	}

	/**
	 * Poll the in-flight batch; when it has ended, hand each post's text
	 * to the collector and move to the next stage.
	 *
	 * @param array    $state     Run state.
	 * @param callable $collector function( $post_id, $text, &$row ).
	 * @param string   $next_stage Stage once collected.
	 * @return bool
	 */
	private function bulk_collect_stage( array $state, $collector, $next_stage ) {
		if ( '' === (string) $state['batch_id'] ) {
			// The submit stage had nothing to send.
			$state['stage'] = $next_stage;
			$state['queue'] = array_values( (array) $state['pipeline'] );
			return $this->bulk_persist( $state );
		}

		$batch = $this->batch();
		$poll  = $batch->poll( (string) $state['batch_id'] );
		if ( is_wp_error( $poll ) ) {
			$this->bulk_pause_with( $state, $poll->get_error_message() );
			return false;
		}
		if ( empty( $poll['ended'] ) ) {
			return false; // Still processing at Anthropic.
		}

		$results = $batch->results( (string) $poll['results_url'] );
		if ( is_wp_error( $results ) ) {
			$this->bulk_pause_with( $state, $results->get_error_message() );
			return false;
		}

		$survivors = array();
		foreach ( (array) $state['pipeline'] as $post_id ) {
			$post_id = (int) $post_id;
			$key     = 'p' . $post_id;
			if ( ! isset( $results[ $key ] ) ) {
				$survivors[] = $post_id; // Not part of this batch (e.g. unambiguous posts during disambiguation).
				continue;
			}
			$result             = $results[ $key ];
			$state['usage_in']  = (int) $state['usage_in'] + (int) $result['input'];
			$state['usage_out'] = (int) $state['usage_out'] + (int) $result['output'];
			if ( empty( $result['ok'] ) ) {
				$this->bulk_fail_post( $post_id, (string) $result['error'], $state );
				continue;
			}
			$row = (array) get_post_meta( $post_id, self::BULK_META, true );
			call_user_func_array( $collector, array( $post_id, (string) $result['text'], &$row ) );
			update_post_meta( $post_id, self::BULK_META, $row );
			$survivors[] = $post_id;
		}

		$state['pipeline'] = $survivors;
		$state['batch_id'] = '';
		if ( ! empty( $state['stage_queue'] ) ) {
			// More chunks to submit in this stage.
			$state['stage'] = str_replace( '_wait', '_submit', (string) $state['stage'] );
		} else {
			$state['stage']       = $next_stage;
			$state['queue']       = array_values( $survivors );
			$state['stage_queue'] = null;
		}
		return $this->bulk_persist( $state );
	}

	/**
	 * Store the final analysis for one post and clean its staging meta.
	 *
	 * @param int $post_id Post ID.
	 */
	private function bulk_finalize_post( $post_id ) {
		$post = get_post( $post_id );
		$raw  = get_post_meta( $post_id, self::BULK_META, true );
		if ( ! $post || ! is_array( $raw ) || array() === $raw ) {
			// No staging data (already finalized, or the post vanished):
			// never overwrite a stored analysis with an empty one. The
			// staging row is only deleted after the final write below, so
			// a crashed finalize simply repeats safely.
			delete_post_meta( $post_id, self::BULK_META );
			return;
		}
		$row = (array) $raw;

		$saved         = get_post_meta( $post_id, self::META_KEY, true );
		$previous      = ( is_array( $saved ) && isset( $saved['entities'] ) && is_array( $saved['entities'] ) ) ? $saved['entities'] : array();
		$previous_desc = ( is_array( $saved ) && isset( $saved['description'] ) ) ? (string) $saved['description'] : '';

		$entities    = $this->enabled() ? ( isset( $row['entities'] ) ? (array) $row['entities'] : array() ) : $previous;
		$description = $this->descriptions_on() ? ( isset( $row['description'] ) ? (string) $row['description'] : '' ) : '';

		$title   = get_the_title( $post );
		$content = $this->plain_content( $post );
		update_post_meta(
			$post_id,
			self::META_KEY,
			array(
				'hash'        => md5( $title . "\n" . $content . "\n" . $this->config_signature() ),
				'time'        => gmdate( 'c' ),
				'status'      => 'ok',
				'error'       => '',
				'entities'    => $entities,
				'description' => $description,
			)
		);
		if ( wp_json_encode( $entities ) !== wp_json_encode( $previous ) || $description !== $previous_desc ) {
			$this->purge_post_cache( $post_id );
		}
		delete_post_meta( $post_id, self::BULK_META );
	}

	/**
	 * Drop a post from the run with its error recorded (mirrors the
	 * real-time error path: empty hash retries on the next save).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $error   Error message.
	 * @param array  $state   Run state, mutated in place.
	 */
	private function bulk_fail_post( $post_id, $error, array &$state ) {
		$row = (array) get_post_meta( $post_id, self::BULK_META, true );
		delete_post_meta( $post_id, self::BULK_META );
		$saved         = get_post_meta( $post_id, self::META_KEY, true );
		$previous      = ( is_array( $saved ) && isset( $saved['entities'] ) && is_array( $saved['entities'] ) ) ? $saved['entities'] : array();
		$previous_desc = ( is_array( $saved ) && isset( $saved['description'] ) ) ? (string) $saved['description'] : '';
		// Salvage work this run already paid for: entities verified in an
		// earlier stage survive even when a later call failed.
		$entities = ( ! empty( $row['entities'] ) && is_array( $row['entities'] ) ) ? $row['entities'] : $previous;
		update_post_meta(
			$post_id,
			self::META_KEY,
			array(
				'hash'        => '',
				'time'        => gmdate( 'c' ),
				'status'      => 'error',
				'error'       => $error,
				'entities'    => $entities,
				'description' => $previous_desc,
			)
		);
		$state['failed']     = (int) $state['failed'] + 1;
		$state['done']       = (int) $state['done'] + 1;
		$state['last_error'] = $error;
	}

	/**
	 * Pause the run with a reason (batch-level failures: submit, poll,
	 * results fetch). Resume retries the same stage.
	 *
	 * @param array  $state  Run state.
	 * @param string $reason Error message.
	 */
	private function bulk_pause_with( array $state, $reason ) {
		$state['status']        = 'paused';
		$state['paused_reason'] = $reason;
		$state['last_error']    = $reason;
		update_option( self::BULK_OPTION, $state, false );
		wp_unschedule_hook( self::BULK_HOOK );
	}

	/**
	 * What a bulk run would process and roughly cost.
	 *
	 * @param string $model Model to price ('' = the saved/default model).
	 * @param bool   $force Price a re-analyze-all run (no unchanged skip).
	 * @return array posts, skipped, est_cost, reserve_cost, model, from_history.
	 */
	public function bulk_estimate( $model = '', $force = false ) {
		$model = '' !== $model ? $model : $this->model();

		$scan = get_transient( 'coywolf_seo_bulk_scan' );
		if ( ! is_array( $scan ) ) {
			$ids       = get_posts(
				array(
					'post_type'      => $this->post_types,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);
			$stale     = 0;
			$chars     = 0;
			$eligible  = 0;
			$chars_all = 0;
			foreach ( array_chunk( $ids, 200 ) as $chunk ) {
				_prime_post_caches( $chunk, false, true ); // prime post + meta caches; term cache not needed.
				foreach ( $chunk as $post_id ) {
					$post = get_post( $post_id );
					if ( ! $post ) {
						continue;
					}
					$content = $this->plain_content( $post );
					if ( '' === trim( $content ) ) {
						continue;
					}
					++$eligible;
					$chars_all += strlen( $content );
					$hash       = md5( get_the_title( $post ) . "\n" . $content . "\n" . $this->config_signature() );
					$saved      = get_post_meta( $post_id, self::META_KEY, true );
					if ( is_array( $saved ) && isset( $saved['hash'] ) && $saved['hash'] === $hash ) {
						continue;
					}
					++$stale;
					$chars += strlen( $content );
				}
			}
			$scan = array(
				'stale'     => $stale,
				'chars'     => $chars,
				'eligible'  => $eligible,
				'chars_all' => $chars_all,
				'total'     => count( $ids ),
			);
			set_transient( 'coywolf_seo_bulk_scan', $scan, 10 * MINUTE_IN_SECONDS );
		}

		$stale = $force ? (int) ( $scan['eligible'] ?? $scan['stale'] ) : (int) $scan['stale'];
		$chars = $force ? (int) ( $scan['chars_all'] ?? $scan['chars'] ) : (int) $scan['chars'];
		if ( $stale < 1 ) {
			// Nothing stale — but a forced Re-analyze-all run still costs;
			// give the UI those figures so it never claims "free".
			$eligible   = (int) ( $scan['eligible'] ?? 0 );
			$force_cost = 0.0;
			if ( $eligible > 0 ) {
				$passes      = 1 + ( $this->descriptions_on() ? 1 : 0 );
				$avg_in_post = (int) ceil( ( ( (int) ( $scan['chars_all'] ?? 0 ) / $eligible ) / 4 + 400 ) * $passes );
				$avg_out     = 250 + ( $this->descriptions_on() ? 80 : 0 ) + 50;
				$history     = Coywolf_SEO_AI_Batch::usage_summary( $model );
				if ( ! empty( $history ) && $history['posts'] >= 3 ) {
					$avg_in_post = (int) $history['avg_input'];
					$avg_out     = (int) $history['avg_output'];
				}
				$force_cost = round( Coywolf_SEO_AI_Batch::estimate_cost( $model, $avg_in_post * $eligible, $avg_out * $eligible ), 2 );
			}
			return array(
				'posts'        => 0,
				'skipped'      => (int) $scan['total'],
				'est_cost'     => 0.0,
				'reserve_cost' => 0.0,
				'model'        => $model,
				'from_history' => false,
				'force_posts'  => $eligible,
				'force_cost'   => $force_cost,
			);
		}

		// Content makes one model pass for extraction and another for
		// descriptions; ~4 characters per token plus prompt overhead.
		$passes      = 1 + ( $this->descriptions_on() ? 1 : 0 );
		$avg_in_post = (int) ceil( ( ( $chars / max( 1, $stale ) ) / 4 + 400 ) * $passes );
		$avg_out     = 250 + ( $this->descriptions_on() ? 80 : 0 ) + 50;

		// Prefer the measured lifetime averages once there is real data.
		$history      = Coywolf_SEO_AI_Batch::usage_summary( $model );
		$from_history = ! empty( $history ) && $history['posts'] >= 3;
		if ( $from_history ) {
			$avg_in_post = (int) $history['avg_input'];
			$avg_out     = (int) $history['avg_output'];
		}

		$est_cost = Coywolf_SEO_AI_Batch::estimate_cost( $model, $avg_in_post * $stale, $avg_out * $stale );

		// What Anthropic's upfront check needs to clear: the worst case of
		// the largest single batch (the extraction chunk).
		$chunk        = min( $stale, max( 1, (int) apply_filters( 'coywolf_seo_bulk_batch_size', 100 ) ) );
		$reserve_cost = Coywolf_SEO_AI_Batch::estimate_cost( $model, (int) ( $avg_in_post * $chunk ), 2000 * $chunk );

		return array(
			'posts'        => $stale,
			'skipped'      => (int) $scan['total'] - $stale,
			'est_cost'     => round( $est_cost, 2 ),
			'reserve_cost' => round( $reserve_cost, 2 ),
			'model'        => $model,
			'from_history' => $from_history,
		);
	}

	/**
	 * Estimator endpoint - recalculates when the Model dropdown changes.
	 */
	public function ajax_bulk_estimate() {
		check_ajax_referer( 'coywolf_seo_bulk_status' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array(), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked above.
		$model = isset( $_POST['model'] ) ? preg_replace( '/[^a-z0-9.\-]/', '', strtolower( (string) wp_unslash( $_POST['model'] ) ) ) : '';
		$force = ! empty( $_POST['force'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		wp_send_json_success( $this->bulk_estimate( $model, $force ) );
	}

	/**
	 * Connection test: a tiny real-time call and a tiny batch submission
	 * (cancelled immediately). Their success/failure pattern pinpoints
	 * whether a billing error is account-wide or batch-specific (a
	 * Workspace spend limit).
	 */
	public function ajax_ai_test() {
		check_ajax_referer( 'coywolf_seo_bulk_status' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array(), 403 );
		}

		$messages_ok    = false;
		$messages_error = '';
		try {
			$reply       = (string) $this->generate( 'Reply with exactly: OK', 'ping' );
			$messages_ok = '' !== trim( $reply );
		} catch ( \Throwable $e ) {
			$messages_error = $e->getMessage();
		}

		$batch       = $this->batch();
		$batch_ok    = false;
		$batch_error = '';
		$batch_id    = $batch->submit( array( $batch->request( 'ping', 'Reply with exactly: OK', 'ping', 100 ) ) );
		if ( is_wp_error( $batch_id ) ) {
			$batch_error = $batch_id->get_error_message();
		} else {
			$batch_ok = true;
			$batch->cancel( $batch_id );
		}

		$hint = '';
		if ( $messages_ok && ! $batch_ok ) {
			$hint = Coywolf_SEO_AI_Providers::current()->bulk_billing_hint( $batch_error );
		}

		wp_send_json_success(
			array(
				'messages_ok'    => $messages_ok,
				'messages_error' => $messages_error,
				'batch_ok'       => $batch_ok,
				'batch_error'    => $batch_error,
				'hint'           => $hint,
			)
		);
	}

	/**
	 * Persist worker progress ONLY while the run is still running: a
	 * Stop/Cancel that landed since our last read always wins, instead of
	 * being clobbered by a stale in-memory state.
	 *
	 * @param array $state Worker state to persist.
	 * @return bool False when the run was paused/cancelled meanwhile.
	 */
	private function bulk_persist( array $state ) {
		$fresh = $this->bulk_state_fresh();
		if ( ! isset( $fresh['status'] ) || 'running' !== $fresh['status'] ) {
			return false; // Paused or cancelled: drop our stale write.
		}
		update_option( self::BULK_OPTION, $state, false );
		return true;
	}

	/**
	 * The bulk state, bypassing the options cache — parallel runners
	 * mutate it between this process's reads.
	 *
	 * @return array
	 */
	private function bulk_state_fresh() {
		wp_cache_delete( self::BULK_OPTION, 'options' );
		return (array) get_option( self::BULK_OPTION, array() );
	}


	/**
	 * The bulk run's state, for the settings page and its polling.
	 *
	 * @return array status/total/done/percent.
	 */
	public function bulk_status() {
		$state   = (array) get_option( self::BULK_OPTION, array() );
		$total   = isset( $state['total'] ) ? (int) $state['total'] : 0;
		$done    = isset( $state['done'] ) ? (int) $state['done'] : 0;
		$percent = $total > 0 ? (int) floor( ( $done / $total ) * 100 ) : 0;
		$service = Coywolf_SEO_AI_Providers::current()->short_label();
		$labels  = array(
			'extract_submit'  => __( 'Submitting the entity-extraction batch…', 'coywolf-seo' ),
			/* translators: %s: the active AI service name (Claude, OpenAI, Gemini). */
			'extract_wait'    => sprintf( __( 'Entity extraction processing at %s (batch rates)…', 'coywolf-seo' ), $service ),
			'ground'          => __( 'Grounding entities against Wikidata…', 'coywolf-seo' ),
			'disambig_submit' => __( 'Submitting the disambiguation batch…', 'coywolf-seo' ),
			/* translators: %s: the active AI service name (Claude, OpenAI, Gemini). */
			'disambig_wait'   => sprintf( __( 'Disambiguation processing at %s (batch rates)…', 'coywolf-seo' ), $service ),
			'verify'          => __( 'Verifying entities…', 'coywolf-seo' ),
			'describe_submit' => __( 'Submitting the meta-description batch…', 'coywolf-seo' ),
			/* translators: %s: the active AI service name (Claude, OpenAI, Gemini). */
			'describe_wait'   => sprintf( __( 'Meta descriptions processing at %s (batch rates)…', 'coywolf-seo' ), $service ),
			'finalize'        => __( 'Saving results…', 'coywolf-seo' ),
		);
		$stage   = isset( $state['stage'] ) ? (string) $state['stage'] : '';
		return array(
			'status'        => isset( $state['status'] ) ? (string) $state['status'] : '',
			'total'         => $total,
			'done'          => $done,
			'percent'       => $percent,
			'stage_label'   => isset( $labels[ $stage ] ) ? $labels[ $stage ] : '',
			'skipped'       => isset( $state['skipped'] ) ? (int) $state['skipped'] : 0,
			'finished'      => isset( $state['finished'] ) ? (string) $state['finished'] : '',
			'failed'        => isset( $state['failed'] ) ? (int) $state['failed'] : 0,
			'last_error'    => isset( $state['last_error'] ) ? (string) $state['last_error'] : '',
			'paused_reason' => isset( $state['paused_reason'] ) ? (string) $state['paused_reason'] : '',
		);
	}

	/**
	 * Status endpoint for the settings page's progress polling.
	 */
	public function ajax_bulk_status() {
		check_ajax_referer( 'coywolf_seo_bulk_status' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array(), 403 );
		}
		// Each poll gives the state machine a brief push, so progress
		// continues while the settings page is open.
		$this->bulk_advance( 4 );
		wp_send_json_success( $this->bulk_status() );
	}

	/**
	 * Queue a fresh analysis from the editor's Re-analyze button, ignoring
	 * the unchanged-content skip.
	 */
	public function ajax_reanalyze() {
		check_ajax_referer( 'coywolf_seo_reanalyze' );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'coywolf-seo' ) ), 403 );
		}
		if ( ! $this->enabled() ) {
			wp_send_json_error( array( 'message' => __( 'Entity detection is not enabled in Settings.', 'coywolf-seo' ) ), 400 );
		}

		// Clear the stored hash so the analysis runs even when the content
		// has not changed.
		$saved = get_post_meta( $post_id, self::META_KEY, true );
		if ( is_array( $saved ) ) {
			$saved['hash'] = '';
			update_post_meta( $post_id, self::META_KEY, $saved );
		}

		$args = array( $post_id );
		if ( ! wp_next_scheduled( self::CRON_HOOK, $args ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK, $args );
		}
		spawn_cron();

		wp_send_json_success( array( 'message' => __( 'Analysis queued — reload in a minute to see the result.', 'coywolf-seo' ) ) );
	}

	/**
	 * A fingerprint of the configuration that shapes analysis results.
	 * Folding it into the content hash means changing the configuration
	 * (switching models) re-analyzes posts
	 * on their next save instead of being skipped as "unchanged".
	 *
	 * @return string
	 */
	private function config_signature() {
		return 'model:' . $this->model()
			. '|ent:' . ( $this->enabled() ? '1' : '0' )
			. '|desc:' . ( $this->descriptions_on() ? '1' : '0' );
	}

	/**
	 * The timeout for AI generation calls, in seconds. Model generation takes
	 * far longer than WordPress's 5-second HTTP default.
	 *
	 * @return float
	 */
	private function timeout() {
		/**
		 * Filters the AI request timeout (seconds).
		 *
		 * @param int $timeout Timeout in seconds.
		 */
		return (float) apply_filters( 'coywolf_seo_ai_timeout', 180 );
	}

	/**
	 * Floor the WordPress HTTP timeout for AI provider API requests.
	 *
	 * WordPress 7.0's bundled AI transport sends through the WordPress HTTP
	 * API with its 5-second default — every generation call times out. The
	 * SDK request options set the timeout too, but this filter guarantees
	 * it for any call the SDK makes on its own (model discovery included).
	 * Matches the API hosts of all supported providers (Anthropic, OpenAI,
	 * and Google Gemini).
	 *
	 * @param array  $args Request arguments.
	 * @param string $url  Request URL.
	 * @return array
	 */
	public function extend_anthropic_timeout( $args, $url ) {
		if ( is_string( $url ) && (
			false !== strpos( $url, 'api.anthropic.com' )
			|| false !== strpos( $url, 'api.openai.com' )
			|| false !== strpos( $url, 'generativelanguage.googleapis.com' )
		) ) {
			$timeout         = $this->timeout();
			$args['timeout'] = isset( $args['timeout'] ) ? max( (float) $args['timeout'], $timeout ) : $timeout;
		}
		return $args;
	}

	/**
	 * Whether enrichment is enabled and an API key is available.
	 *
	 * @return bool
	 */
	public function enabled() {
		// Entity detection also requires Schema.org markup to be on: with no
		// schema output there is nowhere to surface entities, so turning Schema
		// off turns Entity detection off too (the ai_enabled preference is kept).
		// api_key() already encodes the AI master toggle.
		return Coywolf_SEO_Options::feature_enabled( 'schema' )
			&& (bool) Coywolf_SEO_Options::get( 'ai_enabled' )
			&& '' !== $this->api_key();
	}

	/**
	 * The model in use for the active provider: the saved setting (or the
	 * provider's default), still overridable in code via the
	 * coywolf_seo_ai_model filter (applied inside the provider base).
	 *
	 * @return string
	 */
	public function model() {
		/**
		 * Filters the model used for schema enrichment and meta descriptions.
		 *
		 * @param string $model   Model ID.
		 * @param string $service Active provider id (e.g. 'anthropic').
		 */
		return Coywolf_SEO_AI_Providers::current()->model();
	}

	/**
	 * Models available to the saved key for the active provider — cached for
	 * 12 hours, per service; empty without a key or on failure (the settings
	 * page falls back to the provider's curated fallback_models()).
	 *
	 * @return array Rows of id/name.
	 */
	public function available_models() {
		$key = $this->api_key();
		if ( '' === $key ) {
			return array();
		}
		$cache_key = 'coywolf_seo_ai_models_' . Coywolf_SEO_AI_Providers::current_id();
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$models = Coywolf_SEO_AI_Providers::current()->list_models( $key );
		if ( ! is_array( $models ) || empty( $models ) ) {
			return array();
		}
		set_transient( $cache_key, $models, 12 * HOUR_IN_SECONDS );
		return $models;
	}

	/**
	 * Whether AI meta descriptions are on: the option is enabled, meta
	 * descriptions are not excluded site-wide, and a key is available.
	 *
	 * @return bool
	 */
	public function descriptions_on() {
		return (bool) Coywolf_SEO_Options::get( 'ai_descriptions' )
			&& ! (bool) Coywolf_SEO_Options::get( 'exclude_meta_desc' )
			&& '' !== $this->api_key();
	}

	/**
	 * The stored AI-written meta description for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string '' when the feature is off or nothing is stored.
	 */
	public function description_for( $post_id ) {
		if ( ! $this->descriptions_on() ) {
			return '';
		}
		$saved = get_post_meta( $post_id, self::META_KEY, true );
		return ( is_array( $saved ) && isset( $saved['description'] ) ) ? (string) $saved['description'] : '';
	}

	/**
	 * The active provider's API key: the saved setting, or its wp-config
	 * constant/environment fallback. The provider base already honours the
	 * 'ai' master feature gate.
	 *
	 * @return string
	 */
	private function api_key() {
		return Coywolf_SEO_AI_Providers::current()->key();
	}

	/**
	 * Where the active provider's API key comes from, for the Settings
	 * indicator: a saved key wins over the wp-config constant, which wins over
	 * the environment variable.
	 *
	 * @return array {
	 *     @type string $source   Active source: 'saved'|'constant'|'env'|'' (none).
	 *     @type bool   $saved    A key is saved in the database.
	 *     @type bool   $constant The provider's key constant is defined in wp-config.php.
	 *     @type bool   $env      The provider's key is set as an environment variable.
	 * }
	 */
	public function key_status() {
		return Coywolf_SEO_AI_Providers::current()->key_status();
	}

	/**
	 * Queue analysis when a post is published or updated.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status (unused).
	 * @param WP_Post $post       Post.
	 */
	public function maybe_queue( $new_status, $old_status, $post ) {
		unset( $old_status );
		if ( 'publish' !== $new_status || ( ! $this->enabled() && ! $this->descriptions_on() ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, $this->post_types, true ) || wp_is_post_revision( $post->ID ) ) {
			return;
		}
		$args = array( (int) $post->ID );
		if ( ! wp_next_scheduled( self::CRON_HOOK, $args ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK, $args );
			// Kick cron now so the analysis starts within seconds even on
			// low-traffic sites, instead of waiting for the next visit.
			if ( ! defined( 'DOING_CRON' ) || ! DOING_CRON ) {
				spawn_cron();
			}
		}
	}

	/**
	 * Analyze one post: extract, ground, verify, store.
	 *
	 * Runs on WP-Cron, so latency is invisible to editors and visitors.
	 *
	 * @param int $post_id Post ID.
	 * @return string ok | error | skipped | disabled — bulk runs use this
	 *                to track failures; other callers ignore it.
	 */
	public function analyze_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || ( ! $this->enabled() && ! $this->descriptions_on() ) ) {
			return 'disabled';
		}

		$title   = get_the_title( $post );
		$content = $this->plain_content( $post );
		if ( '' === trim( $content ) ) {
			return 'skipped';
		}

		// Unchanged since the last successful run (with the same
		// configuration)? Done.
		$hash  = md5( $title . "\n" . $content . "\n" . $this->config_signature() );
		$saved = get_post_meta( $post_id, self::META_KEY, true );
		if ( is_array( $saved ) && isset( $saved['hash'] ) && $saved['hash'] === $hash ) {
			return 'skipped';
		}

		$previous      = ( is_array( $saved ) && isset( $saved['entities'] ) && is_array( $saved['entities'] ) ) ? $saved['entities'] : array();
		$previous_desc = ( is_array( $saved ) && isset( $saved['description'] ) ) ? (string) $saved['description'] : '';

		try {
			if ( $this->enabled() ) {
				$mentions = $this->extract_mentions( $title, $content );
				$entities = empty( $mentions ) ? array() : $this->ground_mentions( $mentions, $title, $content );
			} else {
				$entities = $previous; // Entity detection off: keep what's stored.
			}
			$description = $this->descriptions_on() ? $this->write_description( $title, $content ) : '';
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug-mode only.
				error_log( 'Coywolf SEO AI enrichment failed for post ' . $post_id . ': ' . $e->getMessage() );
			}
			// Record the failure (with an empty hash so the next save
			// retries) and surface it in the editor's SEO panel.
			update_post_meta(
				$post_id,
				self::META_KEY,
				array(
					'hash'        => '',
					'time'        => gmdate( 'c' ),
					'status'      => 'error',
					'error'       => $e->getMessage(),
					'entities'    => $previous,
					'description' => $previous_desc,
				)
			);
			return 'error';
		}

		update_post_meta(
			$post_id,
			self::META_KEY,
			array(
				'hash'        => $hash,
				'time'        => gmdate( 'c' ),
				'status'      => 'ok',
				'error'       => '',
				'entities'    => $entities,
				'description' => $description,
			)
		);

		// The page was (re)cached before this background run finished:
		// when the schema or description changed, purge it so the new
		// output is served.
		if ( wp_json_encode( $entities ) !== wp_json_encode( $previous ) || $description !== $previous_desc ) {
			$this->purge_post_cache( $post_id );
		}
		return 'ok';
	}

	/**
	 * Purge a single post's page caches after its schema changed —
	 * including the Rocket.net edge cache via the host's mu-plugin, the
	 * common cache plugins, and an action for anything else.
	 *
	 * @param int $post_id Post ID.
	 */
	private function purge_post_cache( $post_id ) {
		clean_post_cache( $post_id );
		if ( class_exists( 'CDN_Clear_Cache_Hooks' ) && method_exists( 'CDN_Clear_Cache_Hooks', 'get_instance' ) ) {
			// Rocket.net edge cache; force past its per-request counter.
			CDN_Clear_Cache_Hooks::get_instance()->purge_cache_queue( $post_id, true );
		}
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id ); // WP Rocket.
		}
		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( $post_id ); // W3 Total Cache.
		}
		if ( function_exists( 'wp_cache_post_change' ) ) {
			wp_cache_post_change( $post_id ); // WP Super Cache.
		}
		do_action( 'litespeed_purge_post', $post_id ); // LiteSpeed (no-op without it).

		/**
		 * Fires after AI-detected entities changed a post's schema, so any
		 * other cache layer can purge the page.
		 *
		 * @param int    $post_id Post ID.
		 * @param string $url     The post's permalink.
		 */
		do_action( 'coywolf_seo_entities_updated', $post_id, (string) get_permalink( $post_id ) );
	}

	/**
	 * Human-readable analysis status for a post, shown in the editor's SEO
	 * panel so a silent background failure is never invisible.
	 *
	 * @param int $post_id Post ID.
	 * @return string '' when enrichment is disabled.
	 */
	public function status_text( $post_id ) {
		if ( ! $this->enabled() && ! $this->descriptions_on() ) {
			return '';
		}
		$saved = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_array( $saved ) ) {
			return $this->enabled()
				? __( 'Entities: analyzed in the background after publishing.', 'coywolf-seo' )
				: __( 'Meta description: written in the background after publishing.', 'coywolf-seo' );
		}
		if ( isset( $saved['status'] ) && 'error' === $saved['status'] ) {
			/* translators: %s: error message. */
			return sprintf( __( 'Entity analysis failed: %s — retried on the next save.', 'coywolf-seo' ), (string) $saved['error'] );
		}
		$about    = 0;
		$mentions = 0;
		if ( ! empty( $saved['entities'] ) && is_array( $saved['entities'] ) ) {
			foreach ( $saved['entities'] as $entity ) {
				if ( ! empty( $entity['primary'] ) ) {
					++$about;
				} else {
					++$mentions;
				}
			}
		}
		$text = $this->enabled() ? sprintf(
			/* translators: 1: number of about entities, 2: number of mention entities. */
			__( 'Entities in schema: %1$d about, %2$d mentions.', 'coywolf-seo' ),
			$about,
			$mentions
		) : '';
		if ( $this->descriptions_on() && ! empty( $saved['description'] ) ) {
			$text = trim( $text . ' ' . __( 'Meta description written.', 'coywolf-seo' ) );
		}
		return $text;
	}

	/**
	 * Schema nodes for a post's stored entities.
	 *
	 * @param int $post_id Post ID.
	 * @return array { about: array[], mentions: array[] }
	 */
	public static function schema_nodes( $post_id ) {
		$out = array(
			'about'    => array(),
			'mentions' => array(),
		);
		// Entity detection off (or AI/Schema master off): stored entities stay
		// in the database but leave the schema output.
		if ( ! Coywolf_SEO_Options::feature_enabled( 'schema' )
			|| ! Coywolf_SEO_Options::feature_enabled( 'ai' )
			|| ! (bool) Coywolf_SEO_Options::get( 'ai_enabled' ) ) {
			return $out;
		}
		$saved = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_array( $saved ) || empty( $saved['entities'] ) || ! is_array( $saved['entities'] ) ) {
			return $out;
		}
		foreach ( $saved['entities'] as $entity ) {
			if ( ! is_array( $entity ) || empty( $entity['name'] ) || empty( $entity['qid'] ) ) {
				continue;
			}
			$same_as = array( 'https://www.wikidata.org/wiki/' . $entity['qid'] );
			if ( ! empty( $entity['wikipedia'] ) ) {
				$same_as[] = (string) $entity['wikipedia'];
			}
			$node = array(
				'@type'  => isset( $entity['type'] ) && in_array( $entity['type'], array( 'Person', 'Organization', 'Place' ), true ) ? $entity['type'] : 'Thing',
				'name'   => (string) $entity['name'],
				'sameAs' => count( $same_as ) > 1 ? $same_as : $same_as[0],
			);
			if ( ! empty( $entity['description'] ) ) {
				$node['description'] = (string) $entity['description'];
			}
			if ( ! empty( $entity['primary'] ) ) {
				$out['about'][] = $node;
			} else {
				$out['mentions'][] = $node;
			}
		}
		return $out;
	}

	/**
	 * The post's content as plain text, bounded for the prompt.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private function plain_content( $post ) {
		$content = (string) $post->post_content;
		$content = strip_shortcodes( $content );
		$content = excerpt_remove_blocks( $content );
		$content = wp_strip_all_tags( $content, true );
		$content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $content, 0, 24000 );
		}
		return substr( $content, 0, 24000 );
	}

	/**
	 * Claude writes the meta description: a faithful summary of the
	 * content in under 200 characters.
	 *
	 * @param string $title   Post title.
	 * @param string $content Plain post content.
	 * @return string '' when generation produced nothing usable.
	 */
	private function write_description( $title, $content ) {
		$text = (string) $this->generate( $this->describe_system(), $this->prompt_user( $title, $content ) );
		return $this->clean_description( $text );
	}

	/**
	 * The meta-description system prompt (shared by the real-time and
	 * batch paths).
	 *
	 * @return string
	 */
	private function describe_system() {
		return 'You write the meta description for a web article. '
			. 'Respond with ONLY the meta description itself — plain text, a single line, no quotation marks around it, no markdown, no preamble, no explanation. '
			. 'It must be a faithful summary of the article content in one or two sentences, written for a search result snippet, '
			. 'and it must be STRICTLY UNDER 200 characters.';
	}

	/**
	 * The title + content user prompt (extraction and description).
	 *
	 * @param string $title   Post title.
	 * @param string $content Plain content.
	 * @return string
	 */
	private function prompt_user( $title, $content ) {
		return 'Title: ' . $title . "\n\n" . $content;
	}

	/**
	 * Normalize and cap a generated meta description.
	 *
	 * @param string $text Raw model output.
	 * @return string
	 */
	private function clean_description( $text ) {
		$text = trim( wp_strip_all_tags( (string) $text, true ) );
		// Strip wrapping quotes the model might add (Unicode-aware — a
		// byte-based trim would corrupt multibyte characters).
		$text = (string) preg_replace( '/^["\'\x{201C}\x{2018}]+|["\'\x{201D}\x{2019}]+$/u', '', $text );
		$text = (string) preg_replace( '/\s+/u', ' ', $text );
		$text = trim( $text );

		// Hard cap: never longer than 200 characters, cut on a word.
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > 200 ) {
			$text = wp_html_excerpt( $text, 199, '…' );
		} elseif ( strlen( $text ) > 200 && ! function_exists( 'mb_strlen' ) ) {
			$text = wp_html_excerpt( $text, 199, '…' );
		}
		return sanitize_text_field( $text );
	}

	/**
	 * Stage 1 — Claude extracts entity mentions (no identifiers).
	 *
	 * @param string $title   Post title.
	 * @param string $content Plain post content.
	 * @return array Rows of surface/name/type/description/primary.
	 */
	private function extract_mentions( $title, $content ) {
		$text = $this->generate( $this->extract_system(), $this->prompt_user( $title, $content ) );
		return $this->parse_mentions( $text );
	}

	/**
	 * The extraction system prompt (shared by the real-time and batch
	 * paths).
	 *
	 * @return string
	 */
	private function extract_system() {
		return 'You extract named entities from an article for SEO schema markup. '
			. 'Respond with ONLY a JSON array — no prose, no code fences. Each element is an object with exactly these keys: '
			. '"surface" (the entity exactly as written in the article), '
			. '"name" (the canonical, normalized name), '
			. '"type" (one of "Person", "Organization", "Place", "Thing"), '
			. '"description" (one short line stating what the entity is), '
			. '"primary" (true when the entity is a main subject of the article, false when it is mentioned in passing). '
			. 'Rules: never output identifiers, QIDs, database IDs, or URLs of any kind. '
			. 'Only include real-world entities a reader could look up — people, organizations, places, products, published works, well-defined concepts. '
			. 'Skip the article author and the publishing website itself. At most 12 entities. If there are none, return [].';
	}

	/**
	 * Parse and sanitize the extraction response.
	 *
	 * @param string $text Raw model output.
	 * @return array Rows of surface/name/type/description/primary.
	 */
	private function parse_mentions( $text ) {
		$rows = $this->decode_json( $text );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$clean = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['name'] ) || empty( $row['type'] ) ) {
				continue;
			}
			$type = (string) $row['type'];
			if ( ! in_array( $type, array( 'Person', 'Organization', 'Place', 'Thing' ), true ) ) {
				$type = 'Thing';
			}
			$clean[] = array(
				'surface'     => isset( $row['surface'] ) ? sanitize_text_field( (string) $row['surface'] ) : '',
				'name'        => sanitize_text_field( (string) $row['name'] ),
				'type'        => $type,
				'description' => isset( $row['description'] ) ? sanitize_text_field( (string) $row['description'] ) : '',
				'primary'     => ! empty( $row['primary'] ),
			);
			if ( count( $clean ) >= 12 ) {
				break;
			}
		}
		return $clean;
	}

	/**
	 * Stages 2–4 — resolve each mention to a verified Wikidata item.
	 *
	 * @param array  $mentions Extracted mentions.
	 * @param string $title    Post title (disambiguation context).
	 * @param string $content  Plain content (disambiguation context).
	 * @return array Grounded entities (name/type/description/qid/primary).
	 */
	private function ground_mentions( array $mentions, $title, $content ) {
		list( $mentions, $ambiguous ) = $this->ground_candidates( $mentions );

		// Stage 3: the model chooses among the real candidates.
		if ( ! empty( $ambiguous ) ) {
			$choices  = $this->disambiguate( $ambiguous, $title, $content );
			$mentions = $this->apply_choices( $mentions, $ambiguous, $choices );
		}

		return $this->verify_resolved( $mentions );
	}

	/**
	 * Stage 2 — deterministic candidate lookup per mention. Single
	 * candidates resolve immediately; multiple candidates queue for
	 * disambiguation.
	 *
	 * @param array $mentions Extracted mentions.
	 * @return array { mentions with qid/candidates, ambiguous subset }
	 */
	private function ground_candidates( array $mentions ) {
		$ambiguous = array();
		foreach ( $mentions as $i => $mention ) {
			$candidates                   = $this->wikidata_search( $mention['name'] );
			$mentions[ $i ]['qid']        = '';
			$mentions[ $i ]['candidates'] = $candidates;
			if ( empty( $candidates ) ) {
				continue; // No real item: the mention is dropped, never invented.
			}
			if ( 1 === count( $candidates ) ) {
				$mentions[ $i ]['qid'] = $candidates[0]['id'];
			} else {
				$ambiguous[ $i ] = $mentions[ $i ];
			}
		}
		return array( $mentions, $ambiguous );
	}

	/**
	 * Stage 3 application — trust a choice only when it is one of the
	 * real candidates.
	 *
	 * @param array $mentions  All mentions (with candidates).
	 * @param array $ambiguous The subset that was disambiguated, by index.
	 * @param array $choices   Name => QID map from the model.
	 * @return array Mentions with chosen qids filled in.
	 */
	private function apply_choices( array $mentions, array $ambiguous, array $choices ) {
		foreach ( $ambiguous as $i => $mention ) {
			$name = $mention['name'];
			if ( ! isset( $choices[ $name ] ) || ! is_string( $choices[ $name ] ) ) {
				continue;
			}
			$chosen = strtoupper( trim( $choices[ $name ] ) );
			foreach ( $mention['candidates'] as $candidate ) {
				if ( $candidate['id'] === $chosen ) {
					$mentions[ $i ]['qid'] = $chosen;
					break;
				}
			}
		}
		return $mentions;
	}

	/**
	 * Stage 4 — P31 type verification plus the Wikipedia sitelink, for
	 * every mention that resolved to a QID.
	 *
	 * @param array $mentions Mentions, some with qids.
	 * @return array Final entity rows.
	 */
	private function verify_resolved( array $mentions ) {
		$resolved = array();
		foreach ( $mentions as $mention ) {
			if ( '' !== $mention['qid'] ) {
				$resolved[] = $mention;
			}
		}
		if ( empty( $resolved ) ) {
			return array();
		}

		$details = $this->wikidata_details( wp_list_pluck( $resolved, 'qid' ) );
		$final   = array();
		foreach ( $resolved as $mention ) {
			$info = isset( $details[ $mention['qid'] ] ) ? $details[ $mention['qid'] ] : array(
				'p31'       => array(),
				'wikipedia' => '',
			);
			$p31  = $info['p31'];
			if ( in_array( 'Q4167410', $p31, true ) ) {
				continue; // Wikimedia disambiguation page — never a real entity.
			}
			$is_human = in_array( 'Q5', $p31, true );
			if ( 'Person' === $mention['type'] && ! empty( $p31 ) && ! $is_human ) {
				continue; // Expected a person; the item is something else.
			}
			if ( 'Person' !== $mention['type'] && $is_human ) {
				continue; // Expected a non-person; the item is a human.
			}
			$final[] = array(
				'name'        => $mention['name'],
				'type'        => $mention['type'],
				'description' => $mention['description'],
				'qid'         => $mention['qid'],
				'wikipedia'   => $info['wikipedia'],
				'primary'     => $mention['primary'],
			);
		}

		return $final;
	}


	/**
	 * Stage 3 prompt — choose among real Wikidata candidates.
	 *
	 * @param array  $ambiguous Mentions with their candidate lists.
	 * @param string $title     Post title.
	 * @param string $content   Plain content.
	 * @return array Map of entity name => QID string or null.
	 */
	private function disambiguate( array $ambiguous, $title, $content ) {
		$text    = $this->generate( $this->disambiguate_system(), $this->disambiguate_user( $ambiguous, $title, $content ) );
		$decoded = $this->decode_json( $text );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * The disambiguation system prompt (shared by the real-time and batch
	 * paths).
	 *
	 * @return string
	 */
	private function disambiguate_system() {
		return 'You match entities from an article to Wikidata items. '
			. 'Respond with ONLY a JSON object — no prose, no code fences — mapping each entity name to the QID string of the best-matching candidate, or null when no candidate clearly matches. '
			. 'Choose strictly among the provided candidates. Never produce a QID that is not listed. '
			. 'Use the article context, the entity type, and each candidate\'s Wikidata description to decide. If the choice is unclear, use null.';
	}

	/**
	 * The disambiguation user prompt: article context plus each entity's
	 * real candidates.
	 *
	 * @param array  $ambiguous Mentions with their candidate lists.
	 * @param string $title     Post title.
	 * @param string $content   Plain content.
	 * @return string
	 */
	private function disambiguate_user( array $ambiguous, $title, $content ) {
		$context = function_exists( 'mb_substr' ) ? mb_substr( $content, 0, 600 ) : substr( $content, 0, 600 );
		$lines   = array( 'Article: ' . $title . ' — ' . $context, '', 'Entities and their candidates:' );
		foreach ( $ambiguous as $mention ) {
			$lines[] = $mention['name'] . ' (' . $mention['type'] . ( '' !== $mention['description'] ? ' — ' . $mention['description'] : '' ) . '):';
			foreach ( $mention['candidates'] as $candidate ) {
				$lines[] = '  ' . $candidate['id'] . ': ' . $candidate['label'] . ( '' !== $candidate['description'] ? ' — ' . $candidate['description'] : '' );
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Whether the SDK has been bootstrapped this request, and which copy of
	 * the PHP AI Client is in play.
	 *
	 * @var bool
	 */
	private static $sdk_loaded = false;

	/**
	 * True when the site itself provides the PHP AI Client (WordPress 7.0+
	 * bundles it in core; the php-ai-client plugin provides it earlier).
	 *
	 * @var bool
	 */
	private static $site_provides_client = false;

	/**
	 * Bootstrap the SDK without ever shadowing a copy the site already has.
	 *
	 * WordPress 7.0 bundles the PHP AI Client in core (wp-settings.php
	 * registers its autoloader), with its third-party internals scoped.
	 * Loading our vendored copy of the same classes alongside it mixes the
	 * two builds (Composer's autoloader prepends) and fatals deep inside
	 * the SDK. So: when the client already exists, use it and only autoload
	 * the vendored provider classes (Anthropic, OpenAI, Google) from our
	 * vendor directory; the full vendored stack loads only on sites with no
	 * client at all (WP < 7.0).
	 */
	private function load_sdk() {
		if ( self::$sdk_loaded ) {
			return;
		}
		self::$sdk_loaded = true;

		if ( class_exists( 'WordPress\\AiClient\\AiClient' ) ) {
			self::$site_provides_client = true;
			spl_autoload_register(
				static function ( $class_name ) {
					// Map each vendored provider namespace to its src/ dir, so
					// whichever provider the site has not already supplied is
					// loaded from our vendor copy.
					$map = array(
						'WordPress\\AnthropicAiProvider\\' => 'vendor/wordpress/ai-provider-for-anthropic/src/',
						'WordPress\\OpenAiAiProvider\\'    => 'vendor/wordpress/ai-provider-for-openai/src/',
						'WordPress\\GoogleAiProvider\\'    => 'vendor/wordpress/ai-provider-for-google/src/',
					);
					foreach ( $map as $prefix => $dir ) {
						if ( 0 !== strpos( $class_name, $prefix ) ) {
							continue;
						}
						$file = COYWOLF_SEO_PATH . $dir
							. str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
						if ( file_exists( $file ) ) {
							require $file;
						}
						return;
					}
				}
			);
			return;
		}

		self::$site_provides_client = false;
		require_once COYWOLF_SEO_PATH . 'vendor/autoload.php';
	}

	/**
	 * Generate text through the PHP AI Client with the active provider.
	 *
	 * @param string $system System instruction.
	 * @param string $prompt User prompt.
	 * @return string Generated text.
	 */
	private function generate( $system, $prompt ) {
		$this->load_sdk();

		$provider    = Coywolf_SEO_AI_Providers::current();
		$provider_id = $provider->provider_id();

		$registry = AiClient::defaultRegistry();
		if ( ! self::$site_provides_client ) {
			// Our vendored stack ships no PSR-18 client for the SDK's
			// discovery to find: route it through the WordPress HTTP API.
			// (A site-provided client comes with its own transport wiring.)
			require_once COYWOLF_SEO_PATH . 'includes/class-coywolf-seo-http-transporter.php';
			$registry->setHttpTransporter( new Coywolf_SEO_Http_Transporter() );
		}
		if ( ! $registry->hasProvider( $provider_id ) ) {
			$registry->registerProvider( $provider->provider_class() );
		}
		$key = $provider->key();
		if ( '' !== $key ) {
			$registry->setProviderRequestAuthentication( $provider_id, new ApiKeyRequestAuthentication( $key ) );
		}

		$model   = $provider->model();
		$options = new RequestOptions();
		$options->setTimeout( $this->timeout() );

		try {
			return AiClient::prompt( $prompt, $registry )
				->usingProvider( $provider_id )
				->usingModelPreference( $model )
				->usingSystemInstruction( $system )
				->usingMaxTokens( 4000 )
				->usingRequestOptions( $options )
				->generateText();
		} catch ( \Throwable $e ) {
			// The pinned model may not be available to this key yet; let the
			// provider pick its preferred model instead.
			return AiClient::prompt( $prompt, $registry )
				->usingProvider( $provider_id )
				->usingSystemInstruction( $system )
				->usingMaxTokens( 4000 )
				->usingRequestOptions( $options )
				->generateText();
		}
	}

	/**
	 * Decode a model response that should be bare JSON, tolerating fences.
	 *
	 * @param string $text Model output.
	 * @return mixed Decoded value, or null.
	 */
	private function decode_json( $text ) {
		$text  = trim( (string) $text );
		$text  = preg_replace( '/^```(?:json)?\s*/i', '', $text );
		$text  = preg_replace( '/\s*```$/', '', (string) $text );
		$start = strpbrk( $text, '[{' );
		if ( false === $start ) {
			return null;
		}
		return json_decode( $start, true );
	}

	/**
	 * Wikidata wbsearchentities lookup — real candidates only.
	 *
	 * @param string $name Entity name.
	 * @return array Candidates as array( id, label, description ).
	 */
	private function wikidata_search( $name ) {
		$language = substr( (string) get_locale(), 0, 2 );
		$language = $language ? $language : 'en';
		$url      = add_query_arg(
			array(
				'action'   => 'wbsearchentities',
				'format'   => 'json',
				'language' => $language,
				'uselang'  => $language,
				'type'     => 'item',
				'limit'    => 5,
				'search'   => rawurlencode( $name ),
			),
			self::WIKIDATA_API
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => $this->user_agent(),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['search'] ) || ! is_array( $body['search'] ) ) {
			return array();
		}

		$candidates = array();
		foreach ( $body['search'] as $hit ) {
			if ( empty( $hit['id'] ) ) {
				continue;
			}
			$candidates[] = array(
				'id'          => (string) $hit['id'],
				'label'       => isset( $hit['label'] ) ? (string) $hit['label'] : '',
				'description' => isset( $hit['description'] ) ? (string) $hit['description'] : '',
			);
		}
		return $candidates;
	}

	/**
	 * Batched details for a set of Wikidata items: P31 (instance of)
	 * claims for type verification, and the Wikipedia sitelink for the
	 * site's language.
	 *
	 * @param string[] $qids Item IDs.
	 * @return array Map of QID => array( p31: string[], wikipedia: string ).
	 */
	private function wikidata_details( array $qids ) {
		$qids = array_values( array_unique( array_filter( $qids ) ) );
		if ( empty( $qids ) ) {
			return array();
		}
		$language = substr( (string) get_locale(), 0, 2 );
		$language = $language ? $language : 'en';
		$wiki     = $language . 'wiki';

		$url = add_query_arg(
			array(
				'action'     => 'wbgetentities',
				'format'     => 'json',
				'props'      => 'claims|sitelinks',
				'sitefilter' => $wiki,
				'ids'        => implode( '|', array_slice( $qids, 0, 50 ) ),
			),
			self::WIKIDATA_API
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => $this->user_agent(),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['entities'] ) || ! is_array( $body['entities'] ) ) {
			return array();
		}

		$map = array();
		foreach ( $body['entities'] as $qid => $entity ) {
			$values = array();
			if ( isset( $entity['claims']['P31'] ) && is_array( $entity['claims']['P31'] ) ) {
				foreach ( $entity['claims']['P31'] as $claim ) {
					if ( isset( $claim['mainsnak']['datavalue']['value']['id'] ) ) {
						$values[] = (string) $claim['mainsnak']['datavalue']['value']['id'];
					}
				}
			}
			$wikipedia = '';
			if ( isset( $entity['sitelinks'][ $wiki ]['title'] ) ) {
				$wikipedia = 'https://' . $language . '.wikipedia.org/wiki/'
					. rawurlencode( str_replace( ' ', '_', (string) $entity['sitelinks'][ $wiki ]['title'] ) );
			}
			$map[ (string) $qid ] = array(
				'p31'       => $values,
				'wikipedia' => $wikipedia,
			);
		}
		return $map;
	}

	/**
	 * Descriptive user agent per the Wikimedia API etiquette.
	 *
	 * @return string
	 */
	private function user_agent() {
		return 'CoywolfSEO/' . Coywolf_SEO::VERSION . ' (WordPress plugin; ' . home_url( '/' ) . ')';
	}
}
