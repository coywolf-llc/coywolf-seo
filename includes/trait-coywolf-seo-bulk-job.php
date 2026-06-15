<?php
/**
 * Shared plumbing for chunked background jobs that advance on WP-Cron.
 *
 * The "Enrich all content" worker (Coywolf_SEO_AI) and the bulk Image Text
 * worker (Coywolf_SEO_Image_Text) run the same way: a run-state option, a
 * non-blocking advisory lock so only one process advances at a time, a
 * cache-bypassing state read, a write-guard that drops a stale write when the
 * run was Stopped/replaced mid-flight, and a self-rescheduling cron event. This
 * trait owns that machinery once; each worker supplies its option name, cron
 * hook, and lock name, keeps its own step logic, and (optionally) opts into the
 * per-run-token guard.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run-state lifecycle + scheduling for a chunked WP-Cron job.
 */
trait Coywolf_SEO_Bulk_Job {

	/**
	 * The run-state option name.
	 *
	 * @return string
	 */
	abstract protected function bulk_job_option();

	/**
	 * The cron hook that advances the run.
	 *
	 * @return string
	 */
	abstract protected function bulk_job_hook();

	/**
	 * The advisory-lock name (kept per worker so the two never contend).
	 *
	 * @return string
	 */
	abstract protected function bulk_job_lock();

	/**
	 * Whether bulk_persist() also requires the run's `run_id` token to still
	 * match before writing — so a fresh Start that replaced the run can't be
	 * clobbered by an in-flight advancer. Off by default; a worker that seeds a
	 * `run_id` overrides this to true.
	 *
	 * @return bool
	 */
	protected function bulk_job_match_run_id() {
		return false;
	}

	/**
	 * Re-read the run state bypassing the options cache, so a write decision
	 * sees a Stop/Cancel/restart made by a concurrent request rather than this
	 * process's stale copy.
	 *
	 * @return array
	 */
	protected function bulk_state_fresh() {
		wp_cache_delete( $this->bulk_job_option(), 'options' );
		return (array) get_option( $this->bulk_job_option(), array() );
	}

	/**
	 * Persist worker state ONLY while the run we are advancing is still the live
	 * running run — drop the write if a concurrent request paused, cancelled, or
	 * (with the run_id guard on) replaced it, so the worker can't resurrect a
	 * stopped run or corrupt a new one.
	 *
	 * @param array $state Worker state to write.
	 * @return bool True if written; false when the run was stopped/replaced.
	 */
	protected function bulk_persist( array $state ) {
		$fresh = $this->bulk_state_fresh();
		if ( empty( $fresh['status'] ) || 'running' !== $fresh['status'] ) {
			return false;
		}
		if ( $this->bulk_job_match_run_id()
			&& (string) ( $fresh['run_id'] ?? '' ) !== (string) ( $state['run_id'] ?? '' ) ) {
			return false;
		}
		update_option( $this->bulk_job_option(), $state, false );
		return true;
	}

	/**
	 * Run a callback while holding the non-blocking advisory lock, so a cron
	 * tick and a status-poll advance can't process the same chunk twice. On
	 * MySQL an explicit 0 means another process holds the lock; other backends
	 * return non-0 and proceed.
	 *
	 * @param callable $advance The advance body.
	 * @return void
	 */
	protected function bulk_with_lock( callable $advance ) {
		global $wpdb;
		$lock = $this->bulk_job_lock();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- non-blocking named lock; one advancer at a time.
		if ( '0' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return;
		}
		try {
			call_user_func( $advance );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- releases the named lock above.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Schedule the worker once for $delay seconds out, if not already scheduled.
	 *
	 * @param int $delay Seconds from now.
	 * @return void
	 */
	protected function bulk_schedule( $delay = 0 ) {
		if ( ! wp_next_scheduled( $this->bulk_job_hook() ) ) {
			wp_schedule_single_event( time() + (int) $delay, $this->bulk_job_hook() );
		}
	}

	/**
	 * Schedule the worker (if needed) and nudge WP-Cron, so a freshly started or
	 * resumed run begins without waiting for a natural tick.
	 *
	 * @param int $delay Seconds from now.
	 * @return void
	 */
	protected function bulk_kick( $delay = 0 ) {
		$this->bulk_schedule( $delay );
		spawn_cron();
	}

	/**
	 * While the run is still running, make sure the next worker tick is
	 * scheduled (call after advancing).
	 *
	 * @param int $delay Seconds from now.
	 * @return void
	 */
	protected function bulk_keep_alive( $delay ) {
		$state = $this->bulk_state_fresh();
		if ( ! empty( $state['status'] ) && 'running' === $state['status'] ) {
			$this->bulk_schedule( $delay );
		}
	}

	/**
	 * Cancel any pending worker event.
	 *
	 * @return void
	 */
	protected function bulk_unschedule() {
		wp_unschedule_hook( $this->bulk_job_hook() );
	}
}
