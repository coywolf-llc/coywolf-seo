<?php
/**
 * AI enrichment for Coywolf SEO.
 *
 * When enabled (with the site owner's own Anthropic API key), published
 * posts and pages are analyzed in the background and the entities they
 * discuss are added to their Article schema — primary subjects as `about`,
 * passing references as `mentions`, each grounded to a real Wikidata item.
 *
 * Hallucinated identifiers are designed out with retrieve-and-verify:
 *
 * 1. Claude extracts entity MENTIONS only — surface form, normalized name,
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
 * Calls go through the WordPress PHP AI Client SDK with the Anthropic
 * provider (both vendored via Composer), per the project requirements.
 *
 * @package CoywolfSEO
 */

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AnthropicAiProvider\Provider\AnthropicProvider;

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
	 * AJAX action for the parallel loopback runners.
	 */
	const BULK_RUNNER = 'coywolf_seo_bulk_runner';

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
		add_action( 'wp_ajax_' . self::BULK_RUNNER, array( $this, 'bulk_runner' ) );
		add_action( 'wp_ajax_nopriv_' . self::BULK_RUNNER, array( $this, 'bulk_runner' ) );
		add_action( 'admin_post_coywolf_seo_bulk_enrich', array( $this, 'handle_bulk_start' ) );
		add_action( 'admin_post_coywolf_seo_bulk_stop', array( $this, 'handle_bulk_stop' ) );
		add_action( 'admin_post_coywolf_seo_bulk_resume', array( $this, 'handle_bulk_resume' ) );
		add_action( 'admin_post_coywolf_seo_bulk_cancel', array( $this, 'handle_bulk_cancel' ) );
		add_action( 'wp_ajax_coywolf_seo_bulk_status', array( $this, 'ajax_bulk_status' ) );
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
			update_option(
				self::BULK_OPTION,
				array(
					'status'    => 'running',
					'total'     => count( $ids ),
					'done'      => 0,
					'remaining' => array_map( 'intval', $ids ),
					'started'   => gmdate( 'c' ),
					'finished'  => '',
					'token'     => wp_generate_password( 64, false ),
				),
				false
			);
			// Parallel loopback workers do the heavy lifting; the cron
			// chain stays as a watchdog for hosts that block loopbacks.
			$this->spawn_bulk_runners();
			if ( ! wp_next_scheduled( self::BULK_HOOK ) ) {
				wp_schedule_single_event( time() + 30, self::BULK_HOOK );
			}
			spawn_cron();
		}

		wp_safe_redirect( admin_url( 'admin.php?page=coywolf-seo-settings&bulk-started=1' ) );
		exit;
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
		if ( ! isset( $state['status'] ) || 'paused' !== $state['status'] || empty( $state['remaining'] ) ) {
			return;
		}
		$state['status'] = 'running';
		$state['token']  = wp_generate_password( 64, false );
		update_option( self::BULK_OPTION, $state, false );

		$this->spawn_bulk_runners();
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
		delete_option( self::BULK_OPTION );
		wp_unschedule_hook( self::BULK_HOOK );
	}

	/**
	 * Process the queue inside a time budget, then hand off to the next
	 * cron tick. The settings page's status polling keeps WP-Cron firing,
	 * so progress continues while the page is open.
	 */
	public function bulk_worker() {
		$state = $this->bulk_state_fresh();
		if ( ! isset( $state['status'] ) || 'running' !== $state['status'] ) {
			return;
		}

		// Watchdog: make sure the parallel runners are alive, then drain
		// what we can in this tick too.
		$this->spawn_bulk_runners();
		$this->bulk_drain( 25 );

		$state = $this->bulk_state_fresh();
		if ( isset( $state['status'] ) && 'running' === $state['status'] && ! wp_next_scheduled( self::BULK_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::BULK_HOOK );
		}
	}

	/**
	 * A parallel loopback runner: claims and analyzes posts within a time
	 * budget, then chains a fresh runner and exits. Authenticated by the
	 * run's token, not a user — the loopback request carries no cookies.
	 */
	public function bulk_runner() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- token-authenticated below; loopback requests have no user/nonce context.
		$token = isset( $_POST['token'] ) ? (string) wp_unslash( $_POST['token'] ) : '';
		$state = $this->bulk_state_fresh();
		if ( '' === $token || empty( $state['token'] ) || ! hash_equals( (string) $state['token'], $token ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		if ( ! isset( $state['status'] ) || 'running' !== $state['status'] ) {
			wp_die( '', '', array( 'response' => 200 ) );
		}

		// Let the runner outlive the HTTP client that spawned it.
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best effort on restricted hosts.
		}

		$worked = $this->bulk_drain( 40 );

		// More to do and we actually processed something: chain a runner.
		$state = $this->bulk_state_fresh();
		if ( $worked > 0 && isset( $state['status'] ) && 'running' === $state['status'] && ! empty( $state['remaining'] ) ) {
			$this->spawn_bulk_runners( 1 );
		}
		wp_die( '', '', array( 'response' => 200 ) );
	}

	/**
	 * Claim and analyze posts until the queue or the time budget runs out.
	 *
	 * @param int $budget Seconds to work.
	 * @return int Posts processed.
	 */
	private function bulk_drain( $budget ) {
		$deadline = time() + max( 5, (int) $budget );
		$worked   = 0;
		while ( time() < $deadline ) {
			$post_id = $this->bulk_claim();
			if ( ! $post_id ) {
				break;
			}
			$this->analyze_post( $post_id );
			$this->bulk_record_done();
			$worked++;
		}
		return $worked;
	}

	/**
	 * Atomically claim the next queued post. A MySQL named lock guards
	 * the read-modify-write so parallel runners never claim the same ID.
	 *
	 * @return int Post ID, 0 when the queue is empty or the run stopped.
	 */
	private function bulk_claim() {
		global $wpdb;
		$lock = 'coywolf_seo_bulk_' . get_current_blog_id();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- named lock for atomic queue claims.
		$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) );

		$post_id = 0;
		$state   = $this->bulk_state_fresh();
		if ( isset( $state['status'] ) && 'running' === $state['status'] && ! empty( $state['remaining'] ) ) {
			$post_id = (int) array_shift( $state['remaining'] );
			update_option( self::BULK_OPTION, $state, false );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- releases the named lock above.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		return $post_id;
	}

	/**
	 * Count a processed post and close the run when the queue is empty.
	 */
	private function bulk_record_done() {
		global $wpdb;
		$lock = 'coywolf_seo_bulk_' . get_current_blog_id();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- named lock for atomic state updates.
		$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) );

		$state = $this->bulk_state_fresh();
		if ( isset( $state['status'] ) && 'running' === $state['status'] ) {
			$state['done'] = (int) $state['done'] + 1;
			if ( empty( $state['remaining'] ) ) {
				$state['status']   = 'done';
				$state['finished'] = gmdate( 'c' );
			}
			update_option( self::BULK_OPTION, $state, false );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- releases the named lock above.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
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
	 * Fire-and-forget loopback requests that become parallel runners.
	 *
	 * @param int $count Runners to spawn; 0 = the configured concurrency.
	 */
	private function spawn_bulk_runners( $count = 0 ) {
		$state = $this->bulk_state_fresh();
		if ( empty( $state['token'] ) || empty( $state['remaining'] ) || ! isset( $state['status'] ) || 'running' !== $state['status'] ) {
			return;
		}
		if ( $count < 1 ) {
			/**
			 * How many posts are enriched in parallel during a bulk run.
			 *
			 * @param int $concurrency Default 3.
			 */
			$count = max( 1, min( 10, (int) apply_filters( 'coywolf_seo_bulk_concurrency', 3 ) ) );
		}
		for ( $i = 0; $i < $count; $i++ ) {
			wp_remote_post(
				admin_url( 'admin-ajax.php' ),
				array(
					'timeout'   => 0.01,
					'blocking'  => false,
					'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
					'body'      => array(
						'action' => self::BULK_RUNNER,
						'token'  => (string) $state['token'],
					),
				)
			);
		}
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
		return array(
			'status'   => isset( $state['status'] ) ? (string) $state['status'] : '',
			'total'    => $total,
			'done'     => $done,
			'percent'  => $percent,
			'finished' => isset( $state['finished'] ) ? (string) $state['finished'] : '',
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
	 * The timeout for Anthropic generation calls, in seconds. Model
	 * generation takes far longer than WordPress's 5-second HTTP default.
	 *
	 * @return float
	 */
	private function timeout() {
		/**
		 * Filters the Anthropic request timeout (seconds).
		 *
		 * @param int $timeout Timeout in seconds.
		 */
		return (float) apply_filters( 'coywolf_seo_ai_timeout', 180 );
	}

	/**
	 * Floor the WordPress HTTP timeout for Anthropic API requests.
	 *
	 * WordPress 7.0's bundled AI transport sends through the WordPress HTTP
	 * API with its 5-second default — every generation call times out. The
	 * SDK request options set the timeout too, but this filter guarantees
	 * it for any call the SDK makes on its own (model discovery included).
	 *
	 * @param array  $args Request arguments.
	 * @param string $url  Request URL.
	 * @return array
	 */
	public function extend_anthropic_timeout( $args, $url ) {
		if ( is_string( $url ) && false !== strpos( $url, 'api.anthropic.com' ) ) {
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
		return (bool) Coywolf_SEO_Options::get( 'ai_enabled' ) && '' !== $this->api_key();
	}

	/**
	 * The Claude model in use: the saved setting (or the default), still
	 * overridable in code via the coywolf_seo_ai_model filter.
	 *
	 * @return string
	 */
	public function model() {
		$saved = trim( (string) Coywolf_SEO_Options::get( 'ai_model' ) );
		return (string) apply_filters( 'coywolf_seo_ai_model', '' !== $saved ? $saved : self::DEFAULT_MODEL );
	}

	/**
	 * Models available to the saved key, from Anthropic's model list —
	 * cached for 12 hours; empty without a key or on failure (the
	 * settings page falls back to a curated list).
	 *
	 * @return array Rows of id/name.
	 */
	public function available_models() {
		$key = $this->api_key();
		if ( '' === $key ) {
			return array();
		}
		$cached = get_transient( 'coywolf_seo_ai_models' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$response = wp_remote_get(
			'https://api.anthropic.com/v1/models?limit=100',
			array(
				'timeout'    => 8,
				'user-agent' => $this->user_agent(),
				'headers'    => array(
					'x-api-key'         => $key,
					'anthropic-version' => '2023-06-01',
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$models = array();
		foreach ( (array) ( $body['data'] ?? array() ) as $row ) {
			if ( is_array( $row ) && ! empty( $row['id'] ) && 0 === strpos( (string) $row['id'], 'claude-' ) ) {
				$models[] = array(
					'id'   => (string) $row['id'],
					'name' => ! empty( $row['display_name'] ) ? (string) $row['display_name'] : (string) $row['id'],
				);
			}
		}
		if ( ! empty( $models ) ) {
			set_transient( 'coywolf_seo_ai_models', $models, 12 * HOUR_IN_SECONDS );
		}
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
	 * The Anthropic API key: the saved setting, or the ANTHROPIC_API_KEY
	 * constant/environment as a wp-config-style fallback.
	 *
	 * @return string
	 */
	private function api_key() {
		$key = (string) Coywolf_SEO_Options::get( 'ai_api_key' );
		if ( '' !== $key ) {
			return $key;
		}
		if ( defined( 'ANTHROPIC_API_KEY' ) ) {
			return (string) ANTHROPIC_API_KEY;
		}
		$env = getenv( 'ANTHROPIC_API_KEY' );
		return $env ? (string) $env : '';
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
	 */
	public function analyze_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || ( ! $this->enabled() && ! $this->descriptions_on() ) ) {
			return;
		}

		$title   = get_the_title( $post );
		$content = $this->plain_content( $post );
		if ( '' === trim( $content ) ) {
			return;
		}

		// Unchanged since the last successful run (with the same
		// configuration)? Done.
		$hash  = md5( $title . "\n" . $content . "\n" . $this->config_signature() );
		$saved = get_post_meta( $post_id, self::META_KEY, true );
		if ( is_array( $saved ) && isset( $saved['hash'] ) && $saved['hash'] === $hash ) {
			return;
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
			return;
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
					$about++;
				} else {
					$mentions++;
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
		// Entity detection off: stored entities stay in the database but
		// leave the schema output.
		if ( ! (bool) Coywolf_SEO_Options::get( 'ai_enabled' ) ) {
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
		$system = 'You write the meta description for a web article. '
			. 'Respond with ONLY the meta description itself — plain text, a single line, no quotation marks around it, no markdown, no preamble, no explanation. '
			. 'It must be a faithful summary of the article content in one or two sentences, written for a search result snippet, '
			. 'and it must be STRICTLY UNDER 200 characters.';

		$text = (string) $this->generate( $system, 'Title: ' . $title . "\n\n" . $content );
		$text = trim( wp_strip_all_tags( $text, true ) );
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
		$system = 'You extract named entities from an article for SEO schema markup. '
			. 'Respond with ONLY a JSON array — no prose, no code fences. Each element is an object with exactly these keys: '
			. '"surface" (the entity exactly as written in the article), '
			. '"name" (the canonical, normalized name), '
			. '"type" (one of "Person", "Organization", "Place", "Thing"), '
			. '"description" (one short line stating what the entity is), '
			. '"primary" (true when the entity is a main subject of the article, false when it is mentioned in passing). '
			. 'Rules: never output identifiers, QIDs, database IDs, or URLs of any kind. '
			. 'Only include real-world entities a reader could look up — people, organizations, places, products, published works, well-defined concepts. '
			. 'Skip the article author and the publishing website itself. At most 12 entities. If there are none, return [].';

		$text = $this->generate( $system, 'Title: ' . $title . "\n\n" . $content );
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
		// Stage 2: deterministic candidate lookup per mention.
		$ambiguous = array();
		foreach ( $mentions as $i => $mention ) {
			$candidates                  = $this->wikidata_search( $mention['name'] );
			$mentions[ $i ]['qid']       = '';
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

		// Stage 3: the model chooses among the real candidates.
		if ( ! empty( $ambiguous ) ) {
			$choices = $this->disambiguate( $ambiguous, $title, $content );
			foreach ( $ambiguous as $i => $mention ) {
				$name = $mention['name'];
				if ( ! isset( $choices[ $name ] ) || ! is_string( $choices[ $name ] ) ) {
					continue;
				}
				$chosen = strtoupper( trim( $choices[ $name ] ) );
				// Trust the choice only if it is one of the real candidates.
				foreach ( $mention['candidates'] as $candidate ) {
					if ( $candidate['id'] === $chosen ) {
						$mentions[ $i ]['qid'] = $chosen;
						break;
					}
				}
			}
		}

		$resolved = array();
		foreach ( $mentions as $mention ) {
			if ( '' !== $mention['qid'] ) {
				$resolved[] = $mention;
			}
		}
		if ( empty( $resolved ) ) {
			return array();
		}

		// Stage 4: rough P31 (instance of) type verification, plus the
		// Wikipedia sitelink that rides along in the same call.
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
		$system = 'You match entities from an article to Wikidata items. '
			. 'Respond with ONLY a JSON object — no prose, no code fences — mapping each entity name to the QID string of the best-matching candidate, or null when no candidate clearly matches. '
			. 'Choose strictly among the provided candidates. Never produce a QID that is not listed. '
			. 'Use the article context, the entity type, and each candidate\'s Wikidata description to decide. If the choice is unclear, use null.';

		$context = function_exists( 'mb_substr' ) ? mb_substr( $content, 0, 600 ) : substr( $content, 0, 600 );
		$lines   = array( 'Article: ' . $title . ' — ' . $context, '', 'Entities and their candidates:' );
		foreach ( $ambiguous as $mention ) {
			$lines[] = $mention['name'] . ' (' . $mention['type'] . ( '' !== $mention['description'] ? ' — ' . $mention['description'] : '' ) . '):';
			foreach ( $mention['candidates'] as $candidate ) {
				$lines[] = '  ' . $candidate['id'] . ': ' . $candidate['label'] . ( '' !== $candidate['description'] ? ' — ' . $candidate['description'] : '' );
			}
		}

		$text    = $this->generate( $system, implode( "\n", $lines ) );
		$decoded = $this->decode_json( $text );
		return is_array( $decoded ) ? $decoded : array();
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
	 * the SDK. So: when the client already exists, use it and only
	 * autoload the Anthropic provider from our vendor directory; the full
	 * vendored stack loads only on sites with no client at all (WP < 7.0).
	 */
	private function load_sdk() {
		if ( self::$sdk_loaded ) {
			return;
		}
		self::$sdk_loaded = true;

		if ( class_exists( 'WordPress\\AiClient\\AiClient' ) ) {
			self::$site_provides_client = true;
			if ( ! class_exists( 'WordPress\\AnthropicAiProvider\\Provider\\AnthropicProvider' ) ) {
				spl_autoload_register(
					static function ( $class_name ) {
						$prefix = 'WordPress\\AnthropicAiProvider\\';
						if ( 0 !== strpos( $class_name, $prefix ) ) {
							return;
						}
						$file = COYWOLF_SEO_PATH . 'vendor/wordpress/ai-provider-for-anthropic/src/'
							. str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
						if ( file_exists( $file ) ) {
							require $file;
						}
					}
				);
			}
			return;
		}

		self::$site_provides_client = false;
		require_once COYWOLF_SEO_PATH . 'vendor/autoload.php';
	}

	/**
	 * Call Claude through the PHP AI Client with the Anthropic provider.
	 *
	 * @param string $system System instruction.
	 * @param string $prompt User prompt.
	 * @return string Generated text.
	 */
	private function generate( $system, $prompt ) {
		$this->load_sdk();

		$registry = AiClient::defaultRegistry();
		if ( ! self::$site_provides_client ) {
			// Our vendored stack ships no PSR-18 client for the SDK's
			// discovery to find: route it through the WordPress HTTP API.
			// (A site-provided client comes with its own transport wiring.)
			require_once COYWOLF_SEO_PATH . 'includes/class-coywolf-seo-http-transporter.php';
			$registry->setHttpTransporter( new Coywolf_SEO_Http_Transporter() );
		}
		if ( ! $registry->hasProvider( 'anthropic' ) ) {
			$registry->registerProvider( AnthropicProvider::class );
		}
		$key = (string) Coywolf_SEO_Options::get( 'ai_api_key' );
		if ( '' !== $key ) {
			$registry->setProviderRequestAuthentication( 'anthropic', new ApiKeyRequestAuthentication( $key ) );
		}

		/**
		 * Filters the Claude model used for schema enrichment.
		 *
		 * @param string $model Model ID.
		 */
		$model = $this->model();

		$options = new RequestOptions();
		$options->setTimeout( $this->timeout() );

		try {
			return AiClient::prompt( $prompt, $registry )
				->usingProvider( 'anthropic' )
				->usingModelPreference( $model )
				->usingSystemInstruction( $system )
				->usingMaxTokens( 4000 )
				->usingRequestOptions( $options )
				->generateText();
		} catch ( \Throwable $e ) {
			// The pinned model may not be available to this key yet; let the
			// provider pick its preferred Claude model instead.
			return AiClient::prompt( $prompt, $registry )
				->usingProvider( 'anthropic' )
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
		$text = trim( (string) $text );
		$text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
		$text = preg_replace( '/\s*```$/', '', (string) $text );
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
