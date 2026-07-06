<?php
/**
 * Queue worker. Single entry point `tick()` is invoked by both the cron
 * handler and the AJAX kick endpoint.
 *
 * Per-tick sequence:
 *   1. Record tick timestamp on the bulk session (diagnostic).
 *   2. recover_stale() — reap dead claims.
 *   3. If a bulk run is active, attempt the feed phase under an
 *      exclusive wp_cache_add lock; pagination of source rows happens
 *      here.
 *   4. Claim and process rows ONE AT A TIME (each via
 *      Slash_Image_Media_Handler::reprocess_attachment()), up to the
 *      `slash_image_concurrency` ceiling, stopping early when a reactive
 *      time/memory bound trips (see drain() / should_stop_before_next_row()).
 *   5. Translate per-row outcome into Slash_Image_Queue::complete or
 *      Slash_Image_Queue::fail.
 *
 * Per design notes: drainage is parallel-safe (atomic claim with token);
 * the feed phase is exclusive via wp_cache_add to prevent two
 * concurrent workers from double-enqueueing the same source page.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Worker {

	const CRON_HOOK             = 'slash_image_worker_tick';
	const CRON_SCHEDULE         = 'slash_image_one_minute';
	const FEED_LOCK_KEY         = 'slash_image_feeding';
	const FEED_LOCK_GROUP       = 'slash-image';
	const FEED_LOCK_TTL_SECONDS = 60;
	const BULK_SESSION_OPTION   = 'slash_image_bulk_session';

	/**
	 * Reactive per-tick bounds (replace the old predictive memory multiplier and
	 * the fixed batch-claim count). drain() claims
	 * and processes rows one at a time and stops before claiming the NEXT row once
	 * either bound trips; the un-claimed rows stay `waiting` for the next tick, so
	 * the blast radius is a single in-flight attachment.
	 *
	 * TIME_BUDGET_SEC: stop starting new rows after this much wall-time in a tick.
	 * Does not abort an in-flight attachment — a single large attachment may run
	 * longer, which is fine; the bound gates *starting* more work.
	 *
	 * MEMORY_THRESHOLD: stop starting new rows when memory_get_usage(true) exceeds
	 * this fraction of memory_limit. Disabled when memory_limit is unlimited (-1).
	 */
	const TIME_BUDGET_SEC  = 25;
	const MEMORY_THRESHOLD = 0.9;

	/**
	 * Best-effort removal of the PHP execution time limit for a long-running
	 * background image-processing tick (loopback chain, AJAX kick, bulk/admin
	 * AJAX). Guarded against hosts where set_time_limit is unavailable or
	 * disabled — mirrors the standard bulk-processor pattern.
	 *
	 * @return void
	 */
	public static function remove_time_limit() {
		if (
			function_exists( 'set_time_limit' )
			&& false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' )
		) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Extends max_execution_time for a long-running background image-processing tick; guarded against hosts where the function is disabled. Standard for bulk processors.
		}
	}

	public function __construct() {
		// Cron drives cron_tick() (the healthcheck/restarter + loopback-blocked
		// fallback), NOT tick() directly — cron_tick adds the chain-running guard
		// that tick() must NOT have (tick is shared by the interactive JS kick and
		// the loopback chain, neither of which may be chain-guarded).
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_tick' ) );
	}

	public static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	public static function clear_cron() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * One worker tick. Safe to call concurrently — drainage path is
	 * atomic via Slash_Image_Queue::claim(); the feed path is guarded
	 * by an exclusive cache lock so only one worker paginates at a time.
	 *
	 * Returns ['processed' => int, 'failed' => int, 'claimed' => int].
	 *
	 * @param int|null $max_rows Optional cap on how many rows this tick claims.
	 *                           null (cron path) = full `slash_image_concurrency`
	 *                           batch. The interactive kick passes a small value
	 *                           (1) so a single foreground admin-ajax request
	 *                           processes one attachment (~seconds) and returns,
	 *                           freeing its PHP-FPM worker instead of monopolizing
	 *                           it for the whole ~94s batch. The JS
	 *                           kick chain re-dispatches while rows remain. The
	 *                           atomic claim makes this safe with no lock.
	 */
	public static function tick( $max_rows = null ) {
		self::touch_session();

		// Dead-key gate: while the connection status is 'invalid', do no work —
		// claim nothing, so queued rows stay 'waiting' and reconnecting drains them
		// cleanly instead of mass-failing the queue. A bulk run is left paused by
		// process_row(); upload/manual rows resume on the next tick after recovery.
		if ( self::should_skip_tick( Slash_Image_Connection::current_status() ) ) {
			return array(
				'processed'       => 0,
				'failed'          => 0,
				'claimed'         => 0,
				'skipped_invalid' => true,
			);
		}

		Slash_Image_Queue::recover_stale();

		// No-key safety net: optimization is gated at the enqueue sites, but as
		// insurance for any stray/legacy optimize row the worker must NOT
		// claim-then-fail one while no API key is configured. Restore jobs are
		// local (no API) and must keep draining — so scope this tick to
		// restore-only (feed + claim), instead of skipping the tick wholesale.
		$restore_only = ! Slash_Image_Connection::has_api_key();

		self::feed_if_needed( $restore_only );

		return self::drain( $max_rows, $restore_only );
	}

	/**
	 * Pure: whether the tick should no-op for this connection status. True only
	 * for 'invalid' (a confirmed-dead key). 'disconnected' still runs — the
	 * per-row no_api_key path handles a missing key without mass-failing.
	 *
	 * @param string $status Slash_Image_Connection status.
	 * @return bool
	 */
	public static function should_skip_tick( $status ) {
		return 'invalid' === (string) $status;
	}

	/**
	 * Cron entry point: healthcheck/restarter for the loopback chain, and the
	 * fallback driver on loopback-blocked hosts.
	 *
	 * - If a chain is running (lock held + fresh), do nothing — the chain is
	 *   driving; don't double-drive.
	 * - Otherwise run one budgeted tick directly (so loopback-blocked hosts
	 *   still advance one budget per cron fire), then try to (re)start the chain
	 *   — which self-sustains on loopback-capable hosts and silently fails on
	 *   blocked ones (the direct tick already made progress).
	 *
	 * Unlike tick(), this is chain-guarded — which is why the cron action points
	 * here and not at tick() (tick is also called by the JS kick and the chain
	 * itself, neither of which may be guarded).
	 */
	public static function cron_tick() {
		if ( Slash_Image_Loopback::is_chain_running() ) {
			return;
		}

		self::tick();

		Slash_Image_Loopback::maybe_start_chain();
	}

	/* ── Drain ─────────────────────────────────────────────────── */

	private static function drain( $max_rows = null, $restore_only = false ) {
		// No-key safety net: claim restore rows only, so a stray/legacy optimize
		// row is never claimed-then-failed while no API key is configured (see
		// tick()). NULL = claim any job_type (the normal, key-present path).
		$claim_type = $restore_only ? Slash_Image_Queue::JOB_TYPE_RESTORE : null;

		// `slash_image_concurrency` is the per-tick iteration ceiling (a soft cap),
		// not a batch-claim count: rows are claimed and processed one at a time and
		// the loop may stop earlier once a reactive bound (time/memory) trips.
		$limit = max( 1, (int) apply_filters( 'slash_image_concurrency', 5 ) );

		// Budgeted kick clamp. Cron passes null (full ceiling); the interactive
		// kick passes 1 so a single foreground request does exactly one attachment.
		if ( null !== $max_rows ) {
			$limit = max( 1, min( $limit, (int) $max_rows ) );
		}

		$tick_start = microtime( true );

		// Resolve the memory ceiling once. Unlimited (-1) disables the check for
		// this tick (e.g. memory_limit = -1 on dev/CLI).
		$limit_bytes    = self::parse_memory_limit( ini_get( 'memory_limit' ) );
		$memory_enabled = ( -1 !== (int) $limit_bytes );

		$token  = wp_generate_uuid4();
		$result = array(
			'processed' => 0,
			'failed'    => 0,
			'claimed'   => 0,
		);

		for ( $i = 0; $i < $limit; $i++ ) {
			// Reactive bounds, checked BEFORE claiming the next row. Gated on
			// $i > 0 so the first row is always attempted — a tick never does
			// zero work, and a budget break leaves the un-claimed rows `waiting`.
			if ( self::should_stop_before_next_row(
				$i,
				microtime( true ) - $tick_start,
				$memory_enabled ? memory_get_usage( true ) : 0,
				$memory_enabled ? (int) $limit_bytes : -1
			) ) {
				break;
			}

			$rows = Slash_Image_Queue::claim( $token, 1, $claim_type );
			if ( empty( $rows ) ) {
				break; // Queue drained.
			}

			$row           = $rows[0];
			$attachment_id = (int) ( $row['attachment_id'] ?? 0 );
			++$result['claimed'];

			// Mark the transitional badge BEFORE the work so the Media Library
			// column shows the Optimizing/Restoring pill for its full duration.
			if ( $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) ) {
				$badge = ( Slash_Image_Queue::JOB_TYPE_RESTORE === ( $row['job_type'] ?? '' ) )
					? Slash_Image_Bulk_Processor::STATUS_RESTORING
					: Slash_Image_Bulk_Processor::STATUS_PROCESSING;
				update_post_meta(
					$attachment_id,
					Slash_Image_Bulk_Processor::STATUS_META_KEY,
					$badge
				);
			}

			$outcome = self::process_row( $row );
			if ( 'success' === $outcome ) {
				++$result['processed'];
			} elseif ( 'failed' === $outcome ) {
				++$result['failed'];
			} elseif ( 'halt' === $outcome ) {
				// Dead key flipped status mid-tick — stop now; the released row and
				// all remaining rows stay 'waiting' for after reconnect.
				break;
			}
		}

		return $result;
	}

	/**
	 * Pure stop predicate for the drain loop (no runtime reads — unit-testable).
	 * Returns true when the worker should stop BEFORE claiming the next row.
	 *
	 * The first row of a tick ($iteration === 0) is never stopped, so a tick
	 * always attempts at least one attachment and the blast radius of a trip is
	 * a single in-flight image.
	 *
	 * @param int       $iteration   0-based index of the row about to be claimed.
	 * @param float     $elapsed     Wall-seconds since the tick started.
	 * @param int       $usage_bytes Current memory usage; ignored when $limit_bytes is -1.
	 * @param int       $limit_bytes memory_limit in bytes, or -1 for unlimited (memory check off).
	 * @return bool
	 */
	public static function should_stop_before_next_row( $iteration, $elapsed, $usage_bytes, $limit_bytes ) {
		if ( (int) $iteration <= 0 ) {
			return false;
		}

		if ( (float) $elapsed > self::TIME_BUDGET_SEC ) {
			return true;
		}

		if ( -1 !== (int) $limit_bytes
			&& (float) $usage_bytes > self::MEMORY_THRESHOLD * (int) $limit_bytes ) {
			return true;
		}

		return false;
	}

	/**
	 * Parse a PHP `memory_limit` ini string (e.g. "256M", "1G", "-1") to bytes.
	 * Returns -1 for unlimited/unset. Lives here because per-tick memory
	 * awareness is now a worker concern (the predictive API-client guard it was
	 * extracted from is gone).
	 */
	private static function parse_memory_limit( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw || '-1' === $raw ) {
			return -1;
		}

		$last = strtolower( substr( $raw, -1 ) );
		$num  = (int) $raw;

		switch ( $last ) {
			case 'g':
				return $num * 1024 * 1024 * 1024;
			case 'm':
				return $num * 1024 * 1024;
			case 'k':
				return $num * 1024;
			default:
				if ( ctype_digit( $raw ) ) {
					return (int) $raw;
				}
				return -1;
		}
	}

	/**
	 * Process a single claimed row. Returns 'success' | 'failed' | 'silent'.
	 *
	 * - Silent: deleted attachment, file outside uploads — row is
	 *   marked done with an informational code, not counted as a
	 *   failure.
	 * - Failed: API error, file unreadable, etc. Goes through the
	 *   queue's retry/failure logic.
	 */
	private static function process_row( array $row ) {
		$row_id        = (int) $row['id'];
		$attachment_id = (int) $row['attachment_id'];
		$job_type      = isset( $row['job_type'] ) ? (string) $row['job_type'] : Slash_Image_Queue::JOB_TYPE_OPTIMIZE;
		$force         = ( Slash_Image_Queue::SOURCE_RETRY === ( $row['source'] ?? '' ) );

		// Silent skip: attachment was deleted between enqueue and processing.
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			delete_post_meta( $attachment_id, Slash_Image_Bulk_Processor::STATUS_META_KEY );
			Slash_Image_Queue::complete( $row_id, 'attachment_missing' );
			return 'silent';
		}

		// Restore job: run the synchronous, retry-safe engine and map its
		// result onto the queue's complete/fail model. (The optimize-only MIME
		// gate and exclusion checks below do not apply to a restore.)
		if ( Slash_Image_Queue::JOB_TYPE_RESTORE === $job_type ) {
			return self::process_restore_row( $row_id, $attachment_id );
		}

		// Pre-flight MIME gate: for a format the API can't process (SVG, PDF,
		// TIFF, BMP, …) skip permanently without an API round-trip. Marked
		// 'done' (not 'failed') because an unsupported type is an expected
		// skip, not an error — it must not inflate the failed/error counts.
		// The Media Library column surfaces it as "Not supported" directly
		// from the MIME, independent of this row's terminal code.
		$mime = get_post_mime_type( $attachment_id );
		if ( ! Slash_Image_Api_Client::is_supported_mime( $mime ) ) {
			delete_post_meta( $attachment_id, Slash_Image_Bulk_Processor::STATUS_META_KEY );
			Slash_Image_Queue::complete( $row_id, 'not_processable_format' );
			return 'silent';
		}

		// Custom-exclusions gate. Filter precedes setting check so power
		// users with regex needs can fully override.
		$exclusion = Slash_Image_Exclusions::evaluate_attachment( $attachment_id );
		if ( $exclusion ) {
			Slash_Image_Exclusions::mark_attachment_excluded( $attachment_id, $exclusion );
			delete_post_meta( $attachment_id, Slash_Image_Bulk_Processor::STATUS_META_KEY );
			Slash_Image_Queue::complete( $row_id, 'excluded' );
			return 'silent';
		}

		// Status meta drives the Media Library column's transitional badge.
		// drain() already wrote 'processing' for the whole batch upfront — this
		// is the per-row safety net for cases where drain() may be skipped
		// (e.g. test harnesses calling process_row directly).
		update_post_meta(
			$attachment_id,
			Slash_Image_Bulk_Processor::STATUS_META_KEY,
			Slash_Image_Bulk_Processor::STATUS_PROCESSING
		);

		$result = Slash_Image_Media_Handler::reprocess_attachment( $attachment_id, $force );
		if ( ! is_array( $result ) ) {
			update_post_meta(
				$attachment_id,
				Slash_Image_Bulk_Processor::STATUS_META_KEY,
				Slash_Image_Bulk_Processor::STATUS_FAILED
			);
			Slash_Image_Queue::fail( $row_id, 'unexpected', __( 'Unknown error.', 'slashimage-image-optimizer' ) );
			return 'failed';
		}

		$category = self::categorize_result( $result );

		if ( 'success' === $category ) {
			// Recovery boundary net: a 200 proves the key works again. No-op when
			// not currently invalid (the tick gate normally prevents reaching here
			// while invalid; this just covers the boundary cleanly).
			Slash_Image_Connection::clear_invalid();
			update_post_meta(
				$attachment_id,
				Slash_Image_Bulk_Processor::STATUS_META_KEY,
				Slash_Image_Bulk_Processor::STATUS_COMPLETED
			);
			Slash_Image_Queue::complete( $row_id, isset( $result['code'] ) ? (string) $result['code'] : null );
			return 'success';
		}

		if ( 'silent' === $category ) {
			delete_post_meta( $attachment_id, Slash_Image_Bulk_Processor::STATUS_META_KEY );
			Slash_Image_Queue::complete( $row_id, isset( $result['code'] ) ? (string) $result['code'] : null );
			return 'silent';
		}

		// Genuine dead key — raw API `invalid_key` ONLY (the domain codes
		// origin_required / domain_not_allowed / domain_blocked collapse into the
		// same internal invalid_key but must NOT flip status; they fall through to
		// the permanent path below, unchanged). Flip the connection to 'invalid',
		// keep the existing bulk-session pause + admin notice, and release THIS row
		// back to 'waiting' (do NOT fail it) so reconnecting drains it cleanly. The
		// tick gate then no-ops until recovery — no per-image mass-fail.
		$api_code = isset( $result['api_code'] ) ? (string) $result['api_code'] : '';
		if ( 'permanent' === $category && Slash_Image_Connection::is_key_dead_code( $api_code ) ) {
			Slash_Image_Connection::mark_invalid();
			self::maybe_halt_on_account_error( 'invalid_key' );
			Slash_Image_Queue::reset_for_retry( $row_id );
			update_post_meta(
				$attachment_id,
				Slash_Image_Bulk_Processor::STATUS_META_KEY,
				Slash_Image_Bulk_Processor::STATUS_QUEUED
			);
			return 'halt';
		}

		// 'permanent' results (file_too_large, unsupported_mime, invalid_key,
		// …) skip straight to 'failed' with no retry — they are identical on
		// every attempt. 'transient' results go through fail(), which the
		// queue backoff-and-retries up to MAX_ATTEMPTS.
		$code        = isset( $result['code'] ) ? (string) $result['code'] : 'unexpected';
		$api_message = isset( $result['message'] ) ? (string) $result['message'] : '';
		$retry_after = isset( $result['retry_after'] ) ? (int) $result['retry_after'] : 0;
		$plugin_copy = self::message_for_code( $code );
		$message     = self::select_error_message( $category, $api_message, $plugin_copy );

		update_post_meta(
			$attachment_id,
			Slash_Image_Bulk_Processor::STATUS_META_KEY,
			Slash_Image_Bulk_Processor::STATUS_FAILED
		);

		if ( 'permanent' === $category ) {
			Slash_Image_Queue::fail_permanent( $row_id, $code, $message );

			// A key/billing failure dooms every remaining row identically —
			// pause the bulk run and raise a single admin notice rather than
			// churning a silent wall of per-image failures.
			self::maybe_halt_on_account_error( $code );
		} else {
			// Transient (network / 5xx / 429 / 503): honor a Retry-After backoff
			// hint (e.g. 503 service_busy) when the API supplied one.
			Slash_Image_Queue::fail( $row_id, $code, $message, $retry_after > 0 ? $retry_after : null );
		}
		return 'failed';
	}

	/**
	 * Process a claimed restore row: run the synchronous, retry-safe
	 * engine and map its result onto the queue's complete/fail model.
	 *
	 * Idempotent on re-claim: the engine keeps the backup on any failure, so a
	 * transient fail + re-claim re-runs harmlessly (re-swap + re-regenerate); a
	 * re-claim AFTER teardown sees no_backup → a silent done. A persistent
	 * regenerate failure retries to the cap, then lands 'failed' with the backup
	 * preserved.
	 */
	private static function process_restore_row( $row_id, $attachment_id ) {
		update_post_meta(
			$attachment_id,
			Slash_Image_Bulk_Processor::STATUS_META_KEY,
			Slash_Image_Bulk_Processor::STATUS_RESTORING
		);

		$result = Slash_Image_Restore::restore_attachment( $attachment_id );
		$code   = isset( $result['code'] ) ? (string) $result['code'] : 'unexpected';

		// Success — the attachment is de-optimized (the restore dropped the optimize
		// blob + stats), so clear the badge; the column reverts to "not optimized".
		if ( ! empty( $result['ok'] ) ) {
			delete_post_meta( $attachment_id, Slash_Image_Bulk_Processor::STATUS_META_KEY );
			Slash_Image_Queue::complete( $row_id, $code );
			return 'success';
		}

		// Nothing to restore — a benign skip, not an error (e.g. a re-claim after
		// teardown, or the backup was removed out from under us).
		if ( in_array( $code, array( 'no_backup', 'attachment_missing', 'invalid_id' ), true ) ) {
			delete_post_meta( $attachment_id, Slash_Image_Bulk_Processor::STATUS_META_KEY );
			Slash_Image_Queue::complete( $row_id, $code );
			return 'silent';
		}

		// Everything else (regenerate_failed, copy_failed, swap_failed,
		// backup_unreadable) — transient: backoff + retry up to MAX_ATTEMPTS. The
		// backup is preserved, so retries are safe; the cap terminates a
		// persistent failure as 'failed'.
		update_post_meta(
			$attachment_id,
			Slash_Image_Bulk_Processor::STATUS_META_KEY,
			Slash_Image_Bulk_Processor::STATUS_FAILED
		);
		Slash_Image_Queue::fail( $row_id, $code, self::message_for_code( $code ) );
		return 'failed';
	}

	/**
	 * Pure: choose the error message stored on a failed queue row.
	 *
	 * A permanent failure often carries a specific human `error` from the API
	 * ("Monthly image limit reached", "Image exceeds plan size limit") that's
	 * more useful than the generic plugin copy, so it's preferred when present.
	 * A transient failure (network / 5xx / 429 / 503) keeps the plugin copy —
	 * its raw API text ("An unexpected error occurred", a cURL string) isn't
	 * useful and must not leak into the Media Library column.
	 *
	 * @param string $category    'permanent' | 'transient' (others → plugin copy).
	 * @param string $api_message API `error` string ('' when none).
	 * @param string $plugin_copy Plugin's own message_for_code() string.
	 * @return string
	 */
	public static function select_error_message( $category, $api_message, $plugin_copy ) {
		if ( 'permanent' === (string) $category && '' !== (string) $api_message ) {
			return (string) $api_message;
		}
		return (string) $plugin_copy;
	}

	/**
	 * Pure: whether a terminal error code should pause the whole bulk run. True
	 * only for the account-level failures that recur identically on every row —
	 * invalid_key (401) and payment_required (402). Transient codes never halt.
	 *
	 * @param string $code Plugin error code.
	 * @return bool
	 */
	public static function should_halt_run( $code ) {
		return in_array( (string) $code, array( 'invalid_key', 'payment_required' ), true );
	}

	/**
	 * Pause the bulk run and raise an admin notice on a key/billing failure.
	 *
	 * A 401 (invalid_key) or 402 (payment_required) recurs identically on every
	 * remaining row, so without this the run churns one permanent failure after
	 * another with no user-facing signal. We pause the bulk session (a no-op
	 * when no run is active, and it leaves the worker cron scheduled so queued
	 * uploads still drain) and store a dismissible admin notice for the next
	 * admin page load. Transient failures (server_error / rate_limited) are NOT
	 * halted — the queue retries them with backoff.
	 *
	 * @param string $code Plugin error code from the failed row.
	 */
	private static function maybe_halt_on_account_error( $code ) {
		if ( ! self::should_halt_run( $code ) ) {
			return;
		}
		// Only halt a GENUINELY-active bulk run. A finished run lingers in the
		// session as stored-'running' (progress() derives 'completed' but never
		// persists it), so we check the DERIVED status from progress() — the raw
		// stored status can't tell a finished run from an active one. This stops
		// an upload 401/402 that arrives after a run has drained from
		// retroactively pausing that finished run, while still pausing a run that
		// is actually in progress.
		if ( 'running' !== ( Slash_Image_Bulk_Processor::progress()['status'] ?? '' ) ) {
			return;
		}
		Slash_Image_Bulk_Processor::pause();
		if ( class_exists( 'Slash_Image_Admin_Notice' ) ) {
			Slash_Image_Admin_Notice::set_account_error( $code );
		}
	}

	/* ── Feed ──────────────────────────────────────────────────── */

	/**
	 * Feed phase. Only runs when a bulk session is 'running' and
	 * source_done is false. Wrapped in an exclusive cache lock so
	 * only one worker paginates at a time.
	 */
	private static function feed_if_needed( $restore_only = false ) {
		$session = self::get_session();
		if ( 'running' !== ( $session['status'] ?? '' ) ) {
			return;
		}
		if ( ! empty( $session['source_done'] ) ) {
			return;
		}
		// No-key safety net: only a restore run may feed while no API key is
		// configured — an optimize feed is suppressed (its rows would never be
		// claimed; see drain()/Slash_Image_Queue::claim()).
		if ( $restore_only
			&& Slash_Image_Queue::JOB_TYPE_RESTORE !== ( $session['action'] ?? Slash_Image_Queue::JOB_TYPE_OPTIMIZE ) ) {
			return;
		}

		// Non-blocking exclusive lock. wp_cache_add fails (returns false)
		// if the key already exists — that's our "another worker is
		// feeding right now, skip" signal.
		if ( ! wp_cache_add( self::FEED_LOCK_KEY, 1, self::FEED_LOCK_GROUP, self::FEED_LOCK_TTL_SECONDS ) ) {
			return;
		}

		try {
			self::feed_chunk( $session );
		} catch ( Exception $exception ) {
			// Defensive: any unexpected failure releases the lock and re-throws.
			wp_cache_delete( self::FEED_LOCK_KEY, self::FEED_LOCK_GROUP );
			throw $exception;
		}

		wp_cache_delete( self::FEED_LOCK_KEY, self::FEED_LOCK_GROUP );
	}

	private static function feed_chunk( array $session ) {
		global $wpdb;

		$chunk_size = (int) apply_filters( 'slash_image_bulk_enqueue_chunk_size', 500 );
		$chunk_size = max( 50, $chunk_size );

		$cursor = (int) ( $session['source_cursor'] ?? 0 );

		// Restore run: feed attachments-with-a-backup (cursor by ID), enqueuing a
		// restore job each. Cursoring by ID — not OFFSET — is robust to a backup
		// being deleted mid-run: a row below the cursor was already enqueued and
		// drains to a silent no_backup; one above the cursor simply isn't
		// discovered (no shifting the window the way OFFSET would).
		if ( Slash_Image_Queue::JOB_TYPE_RESTORE === ( $session['action'] ?? Slash_Image_Queue::JOB_TYPE_OPTIMIZE ) ) {
			$ids = self::next_restore_ids( $cursor, $chunk_size );
			if ( empty( $ids ) ) {
				$session['source_done']  = true;
				$session['last_tick_at'] = time();
				self::save_session( $session );
				return;
			}
			$deferred = 0;
			foreach ( $ids as $attachment_id ) {
				$r = Slash_Image_Queue::enqueue( $attachment_id, Slash_Image_Queue::SOURCE_BULK, Slash_Image_Queue::PRIORITY_BULK, Slash_Image_Queue::JOB_TYPE_RESTORE );
				if ( Slash_Image_Queue::ENQUEUE_IN_FLIGHT === $r ) {
					// In-flight optimize on this attachment — skip-and-report (it
					// keeps its backup; the run reports the count so the user can
					// re-run to catch it).
					++$deferred;
				}
			}
			$session['source_cursor']      = (int) max( $ids );
			$session['deferred_in_flight'] = (int) ( $session['deferred_in_flight'] ?? 0 ) + $deferred;
			$session['last_tick_at']       = time();
			self::save_session( $session );
			return;
		}

		$force = ! empty( $session['force_redo'] );

		// Pull the next chunk of attachment IDs that aren't already
		// optimized (or all of them, when force_redo is on).
		$ids = self::next_source_ids( $cursor, $chunk_size, $force );

		if ( empty( $ids ) ) {
			$session['source_done']  = true;
			$session['last_tick_at'] = time();
			self::save_session( $session );
			return;
		}

		foreach ( $ids as $attachment_id ) {
			Slash_Image_Queue::enqueue( $attachment_id, Slash_Image_Queue::SOURCE_BULK, Slash_Image_Queue::PRIORITY_BULK );
		}

		$session['source_cursor'] = (int) max( $ids );
		$session['last_tick_at']  = time();
		self::save_session( $session );
	}

	/**
	 * Source query for the bulk feed. Returns up to $limit attachment IDs
	 * with id > $after, JPEG/PNG only. When $force is false, also requires
	 * absence of the optimized _slash_image_data marker.
	 */
	private static function next_source_ids( $after, $limit, $force ) {
		global $wpdb;

		$after = (int) $after;
		$limit = max( 1, (int) $limit );

		$mimes        = Slash_Image_Api_Client::SUPPORTED_MIME_TYPES;
		$mime_holders = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );

		// $mime_holders is built from %s tokens only — safe to interpolate.
		// The dynamic IN(...) list defeats the placeholder-count sniff, so it
		// is disabled here too; the merged args line up with the tokens.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		if ( $force ) {
			return array_map(
				'intval',
				$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts}
						  WHERE post_type = 'attachment'
						    AND post_status = 'inherit'
						    AND post_mime_type IN ( {$mime_holders} )
						    AND ID > %d
						  ORDER BY ID ASC
						  LIMIT %d",
						array_merge( $mimes, array( $after, $limit ) )
					)
				)
			);
		}

		// Skip rows that already have _slash_image_data['optimized'] set.
		// LEFT JOIN + IS NULL is the standard "anti-join" pattern.
		return array_map(
			'intval',
			$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT p.ID
					   FROM {$wpdb->posts} p
					   LEFT JOIN {$wpdb->postmeta} m
					     ON m.post_id = p.ID
					    AND m.meta_key = %s
					  WHERE p.post_type = 'attachment'
					    AND p.post_status = 'inherit'
					    AND p.post_mime_type IN ( {$mime_holders} )
					    AND p.ID > %d
					    AND m.meta_id IS NULL
					  ORDER BY p.ID ASC
					  LIMIT %d",
					array_merge( array( Slash_Image_Media_Handler::META_DATA_KEY ), $mimes, array( $after, $limit ) )
				)
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	}

	/**
	 * Source query for the restore feed: up to $limit attachment IDs that have a
	 * backup record and id > $after, ordered by id. Bounded + indexed (the
	 * postmeta meta_key index); the id cursor makes it stable under concurrent
	 * backup deletion.
	 */
	private static function next_restore_ids( $after, $limit ) {
		return Slash_Image_Restore::next_backed_up_ids( $after, $limit );
	}

	/* ── Bulk session helpers ──────────────────────────────────── */

	public static function get_session() {
		$s = get_option( self::BULK_SESSION_OPTION, array() );
		if ( ! is_array( $s ) ) {
			$s = array();
		}
		return array_merge(
			array(
				'status'             => 'idle',
				'action'             => Slash_Image_Queue::JOB_TYPE_OPTIMIZE,
				'started_at'         => 0,
				'finished_at'        => null,
				'source_cursor'      => 0,
				'source_done'        => false,
				'force_redo'         => false,
				'total_target'       => null,
				'deferred_in_flight' => 0,
				'last_tick_at'       => null,
			),
			$s
		);
	}

	public static function save_session( array $session ) {
		update_option( self::BULK_SESSION_OPTION, $session, false );
	}

	private static function touch_session() {
		$session = self::get_session();
		// Only persist the touch when there's something interesting going on.
		if ( in_array( $session['status'], array( 'running', 'paused' ), true ) ) {
			$session['last_tick_at'] = time();
			self::save_session( $session );
		}
	}

	/* ── Result categorization ─────────────────────────────────── */

	/** Returns 'success' | 'silent' | 'permanent' | 'transient'. */
	private static function categorize_result( array $result ) {
		if ( ! empty( $result['ok'] ) ) {
			return 'success';
		}
		$code = isset( $result['code'] ) ? (string) $result['code'] : 'unexpected';

		if ( in_array( $code, array( 'attachment_missing', 'file_outside_uploads' ), true ) ) {
			return 'silent';
		}

		if ( in_array(
			$code,
			array(
				'unsupported_mime',
				'not_processable_format',
				'gif_too_large',
				'file_too_large',
				'size_exceeded_for_plan',
				'size_exceeded_server',
				'file_corrupt',
				'file_unreadable',
				'no_api_key',
				// Misconfigured non-https API base URL — the key was refused, not
				// sent (Slash_Image_Api_Client::endpoint_is_secure). Terminal: a
				// re-send can't fix a configuration problem.
				'insecure_endpoint',
				'invalid_key',
				'payment_required',
				'invalid_format',
				// Timeout is terminal — one attempt, then error UI, no auto-retry.
				// WHY (the choice is coupled to our SYNCHRONOUS API model): an
				// auto-retry here is another full blocking request — it holds a
				// PHP-FPM worker for ~45 s, re-risks the reverse-proxy timeout,
				// will most likely time out again (the image/server is the same),
				// and piles more load onto an already-busy server. So in a sync
				// client one-attempt-then-error is the coherent choice.
				// Re-attempts come on demand instead — a manual Retry, or
				// the next bulk run (which re-selects un-optimized attachments via
				// the optimized-marker anti-join). Auto-retry-with-cap
				// only pays off with an ASYNC API, where a retry is a cheap poll,
				// not a blocking optimize — revisit this if/when we go async.
				'timeout_exceeded',
			),
			true
		) ) {
			return 'permanent';
		}

		// Transient — a later attempt often genuinely recovers: a network blip
		// clears, an overloaded/5xx server recovers, a 429 window passes. The
		// queue requeues these with backoff (60 s / 5 min / 15 min) up to
		// MAX_ATTEMPTS, then marks the row failed (error + Retry).
		return 'transient';
	}

	private static function message_for_code( $code ) {
		// Delegate to the bulk processor's existing message catalog so
		// error copy stays in one place.
		if ( method_exists( 'Slash_Image_Bulk_Processor', 'message_for_code' ) ) {
			return Slash_Image_Bulk_Processor::message_for_code( $code );
		}
		return (string) $code;
	}
}
