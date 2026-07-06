<?php
/**
 * Bulk-optimization façade.
 *
 * After Phases 1–6 of the queue refactor, this class is a thin wrapper over
 * Slash_Image_Worker + Slash_Image_Queue. It owns the public API used by the
 * bulk page, Media Library, and upload pipeline: start / pause / resume /
 * cancel / retry_failed / clear_failed / enqueue_new_upload / process_batch
 * + the snapshot/progress/failed/queue readers (all derived from the
 * worker's bulk session and the queue table — no legacy options).
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Bulk_Processor {

	const STATUS_META_KEY   = '_slash_image_status';
	const STATUS_QUEUED     = 'queued';
	const STATUS_PROCESSING = 'processing';
	const STATUS_RESTORING  = 'restoring';
	const STATUS_COMPLETED  = 'completed';
	const STATUS_FAILED     = 'failed';

	const TRANSIENT_RECENT      = 'slash_image_bulk_recent_completions';
	const RECENT_WINDOW_SECONDS = 60;

	// The library counts + size totals are recomputed at most once
	// per this short TTL and served from a transient, so the 1 s bulk-page poll
	// (which calls both via snapshot()) doesn't re-run the aggregates every
	// second. Time-expiry only — NEVER event-busted (a few seconds of staleness
	// on the slow-moving saved-bytes total is acceptable; the live progress bar
	// is a separate, uncached path).
	const STATS_CACHE_TRANSIENT = 'slash_image_stats_bundle';
	const STATS_CACHE_TTL       = 15;

	public function __construct() {}

	/* ── Public API ──────────────────────────────────────── */

	/**
	 * Start a full-library bulk run. Sets the worker's bulk session to
	 * 'running' and lets the worker's feed phase paginate the source
	 * query — no upfront materialization.
	 */
	public static function start( $force_redo = false ) {
		// Mutual exclusion: one bulk run at a time. Refuse to start an optimize
		// run while a restore run is active.
		if ( Slash_Image_Queue::JOB_TYPE_RESTORE === self::active_run() ) {
			$snap            = self::snapshot();
			$snap['refused'] = 'restore_running';
			return $snap;
		}

		// No-key gate: refuse to start an optimize run with no API key configured
		// (no rows enqueued, session untouched). The caller surfaces the prompt.
		if ( ! Slash_Image_Connection::has_api_key() ) {
			$snap            = self::snapshot();
			$snap['refused'] = 'no_key';
			return $snap;
		}

		$force_redo = (bool) $force_redo;
		$total      = self::count_eligible_attachments( $force_redo );

		$session                  = Slash_Image_Worker::get_session();
		$session['action']        = Slash_Image_Queue::JOB_TYPE_OPTIMIZE;
		$session['status']        = $total > 0 ? 'running' : 'completed';
		$session['started_at']    = time();
		$session['finished_at']   = $total > 0 ? null : time();
		$session['source_cursor'] = 0;
		$session['source_done']   = false;
		$session['force_redo']    = $force_redo;
		$session['total_target']  = $total;
		$session['last_tick_at']  = time();
		Slash_Image_Worker::save_session( $session );

		if ( $total > 0 ) {
			Slash_Image_Worker::schedule_cron();
			// Make the bulk run a tab-closeable background chain. Lock-guarded.
			Slash_Image_Loopback::maybe_start_chain();
		}

		return self::snapshot();
	}

	/**
	 * Start an async "Restore all" run: a typed bulk session (action=restore)
	 * whose feed enqueues a restore job for every attachment with a backup,
	 * drained by the worker under the same per-tick budget as optimize. Mutually
	 * exclusive with an optimize run. Returns the snapshot; on refusal the
	 * snapshot carries `refused => 'optimize_running'` and the session is unchanged.
	 */
	public static function start_restore() {
		if ( Slash_Image_Queue::JOB_TYPE_OPTIMIZE === self::active_run() ) {
			$snap            = self::snapshot();
			$snap['refused'] = 'optimize_running';
			return $snap;
		}

		$total = self::count_backed_up_attachments();

		$session                       = Slash_Image_Worker::get_session();
		$session['action']             = Slash_Image_Queue::JOB_TYPE_RESTORE;
		$session['status']             = $total > 0 ? 'running' : 'completed';
		$session['started_at']         = time();
		$session['finished_at']        = $total > 0 ? null : time();
		$session['source_cursor']      = 0;
		$session['source_done']        = false;
		$session['force_redo']         = false;
		$session['total_target']       = $total;
		$session['deferred_in_flight'] = 0;
		$session['last_tick_at']        = time();
		Slash_Image_Worker::save_session( $session );

		if ( $total > 0 ) {
			Slash_Image_Worker::schedule_cron();
			Slash_Image_Loopback::maybe_start_chain();
		}

		return self::snapshot();
	}

	/**
	 * job_type of the currently-active bulk run ('optimize' / 'restore'), or null
	 * when none is active. "Active" = the derived display status is running or
	 * paused; a drained/idle/completed run does not block a new one.
	 */
	private static function active_run() {
		$session = Slash_Image_Worker::get_session();
		$status  = self::decide_run_status(
			(string) ( $session['status'] ?? 'idle' ),
			! empty( $session['source_done'] ),
			self::bulk_pending_count()
		);
		if ( ! in_array( $status, array( 'running', 'paused' ), true ) ) {
			return null;
		}
		return (string) ( $session['action'] ?? Slash_Image_Queue::JOB_TYPE_OPTIMIZE );
	}

	/**
	 * Bounded COUNT of attachments that have a backup record — the restore run's
	 * total_target.
	 */
	private static function count_backed_up_attachments() {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				   FROM {$wpdb->posts} p
				   INNER JOIN {$wpdb->postmeta} m
				     ON m.post_id = p.ID AND m.meta_key = %s
				  WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'",
				Slash_Image_Restore::BACKUP_META_KEY
			)
		);
	}

	/**
	 * Seed the queue with a specific set of attachment IDs (e.g. from a
	 * Media Library bulk-action selection). Filters to JPEG/PNG and skips
	 * already-optimized attachments unless $force_redo is true.
	 *
	 * source_done is set true on the session — no DB pagination, the worker
	 * just drains the rows we enqueue here.
	 */
	public static function start_with_ids( array $ids, $force_redo = false ) {
		$force_redo = (bool) $force_redo;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return self::snapshot();
		}

		// No-key gate: don't enqueue a supplied-IDs run with no API key configured.
		if ( ! Slash_Image_Connection::has_api_key() ) {
			$snap            = self::snapshot();
			$snap['refused'] = 'no_key';
			return $snap;
		}

		$filtered = array();
		foreach ( $ids as $id ) {
			$mime = get_post_mime_type( $id );
			if ( ! Slash_Image_Api_Client::is_supported_mime( $mime ) ) {
				continue;
			}
			if ( ! $force_redo ) {
				$existing = get_post_meta( $id, Slash_Image_Media_Handler::META_DATA_KEY, true );
				if ( is_array( $existing ) && ! empty( $existing['optimized'] ) ) {
					continue;
				}
			}
			$filtered[] = $id;
		}

		foreach ( $filtered as $id ) {
			Slash_Image_Queue::enqueue(
				$id,
				Slash_Image_Queue::SOURCE_BULK,
				Slash_Image_Queue::PRIORITY_BULK
			);
		}

		$total                    = count( $filtered );
		$session                  = Slash_Image_Worker::get_session();
		$session['action']        = Slash_Image_Queue::JOB_TYPE_OPTIMIZE;
		$session['status']        = $total > 0 ? 'running' : 'idle';
		$session['started_at']    = time();
		$session['finished_at']   = $total > 0 ? null : time();
		$session['source_cursor'] = 0;
		$session['source_done']   = true; // IDs supplied — no source pagination needed.
		$session['force_redo']    = $force_redo;
		$session['total_target']  = $total;
		$session['last_tick_at']  = time();
		Slash_Image_Worker::save_session( $session );

		if ( $total > 0 ) {
			Slash_Image_Worker::schedule_cron();
			// Make the bulk run a tab-closeable background chain. Lock-guarded.
			Slash_Image_Loopback::maybe_start_chain();
		}

		return self::snapshot();
	}

	/**
	 * Remove specific failed-row attachments from the queue table.
	 */
	public static function clear_failed_for( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return;
		}
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$args         = array_merge( array( Slash_Image_Queue::STATUS_FAILED ), $ids );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}slash_image_queue
				  WHERE status = %s AND attachment_id IN ( {$placeholders} )",
				$args
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function pause() {
		$session = Slash_Image_Worker::get_session();
		if ( 'running' !== ( $session['status'] ?? '' ) ) {
			return self::snapshot();
		}
		$session['status']       = 'paused';
		$session['last_tick_at'] = time();
		Slash_Image_Worker::save_session( $session );

		// Don't clear the worker cron — uploads still need to drain. The
		// worker's feed phase keys off session.status='running' so paused
		// bulk feeds stop on their own.
		return self::snapshot();
	}

	public static function resume() {
		$session = Slash_Image_Worker::get_session();
		if ( 'paused' !== ( $session['status'] ?? '' ) ) {
			return self::snapshot();
		}
		$session['status']       = 'running';
		$session['last_tick_at'] = time();
		Slash_Image_Worker::save_session( $session );

		Slash_Image_Worker::schedule_cron();
		return self::snapshot();
	}

	public static function cancel() {
		$session = Slash_Image_Worker::get_session();
		$action  = (string) ( $session['action'] ?? Slash_Image_Queue::JOB_TYPE_OPTIMIZE );

		// Wipe THIS run's waiting bulk/retry rows; uploads keep flowing. Scope the
		// delete by the active job_type so an optimize cancel can never nuke a
		// restore row and vice versa (the runs are mutually exclusive, but the
		// scoping makes that explicit). Upload/manual rows are preserved by the
		// source filter.
		Slash_Image_Queue::delete_waiting_by_source(
			array(
				Slash_Image_Queue::SOURCE_BULK,
				Slash_Image_Queue::SOURCE_RETRY,
			),
			$action
		);

		$session['status']       = 'idle';
		$session['source_done']  = true;
		$session['finished_at']  = time();
		$session['last_tick_at'] = time();
		Slash_Image_Worker::save_session( $session );
		return self::snapshot();
	}

	public static function retry_failed() {
		$rows = Slash_Image_Queue::failed_rows( 1000 );
		if ( empty( $rows ) ) {
			return self::snapshot();
		}

		$count = 0;
		foreach ( $rows as $row ) {
			if ( Slash_Image_Queue::reset_for_retry( (int) $row['id'] ) ) {
				++$count;
			}
		}

		$session                  = Slash_Image_Worker::get_session();
		$session['action']        = Slash_Image_Queue::JOB_TYPE_OPTIMIZE;
		$session['status']        = $count > 0 ? 'running' : 'idle';
		$session['started_at']    = time();
		$session['finished_at']   = $count > 0 ? null : time();
		$session['source_cursor'] = 0;
		$session['source_done']   = true; // Reset rows already exist; no source pagination.
		$session['force_redo']    = true;
		$session['total_target']  = $count;
		$session['last_tick_at']  = time();
		Slash_Image_Worker::save_session( $session );

		Slash_Image_Worker::schedule_cron();
		return self::snapshot();
	}

	public static function clear_failed() {
		Slash_Image_Queue::clear_failed_rows();
		return self::snapshot();
	}

	public static function snapshot() {
		$progress = self::progress();
		$queue    = self::queue();
		$counts   = self::library_counts();
		$totals   = self::aggregate_size_totals();

		$progress['queue_remaining'] = count( $queue );
		$progress['recent_rate']     = self::recent_completions_count();
		$progress['library']         = $counts;
		$progress['totals']          = $totals;

		// Bulk Optimize page redesign (presentation-only) additions.
		$progress['total_thumbnails']   = self::total_thumbnails_estimate();
		$progress['credits_estimate']   = self::credits_estimate();
		$progress['recent_completions'] = self::recent_completions();
		// Alias of failed_count under a clearer name for the running-state stat row.
		$progress['skipped'] = (int) ( $progress['failed_count'] ?? 0 );

		// The run type ('optimize'|'restore') so the UI picks labels, and
		// the count of restore rows skipped because the image was mid-optimize
		// (surfaced as a completion note so the user knows the run wasn't 100%).
		$session                        = Slash_Image_Worker::get_session();
		$progress['mode']               = (string) ( $session['action'] ?? Slash_Image_Queue::JOB_TYPE_OPTIMIZE );
		$progress['deferred_in_flight'] = (int) ( $session['deferred_in_flight'] ?? 0 );

		return $progress;
	}

	/**
	 * Site-wide aggregate of original + optimized bytes across all optimized
	 * attachments. Used by the Bulk Optimize Overview card + the dashboard
	 * widget. Served from the short-TTL stats bundle (see stats_bundle()).
	 *
	 * Returns:
	 *   ['original_bytes' => int, 'optimized_bytes' => int, 'attachments' => int]
	 */
	public static function aggregate_size_totals() {
		return self::stats_bundle()['totals'];
	}

	/**
	 * The short-TTL-cached stats bundle: { counts, totals }. Both public readers
	 * (library_counts / aggregate_size_totals) and the bulk snapshot() draw from
	 * this, so the 1 s poll recomputes at most once per STATS_CACHE_TTL.
	 */
	private static function stats_bundle() {
		$cached = get_transient( self::STATS_CACHE_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['counts'], $cached['totals'] ) ) {
			return $cached;
		}
		$bundle = self::compute_stats_bundle();
		set_transient( self::STATS_CACHE_TRANSIENT, $bundle, self::STATS_CACHE_TTL );
		return $bundle;
	}

	/**
	 * Compute the stats bundle — SQL aggregates over the flat per-attachment
	 * fields. No per-row unserialize, constant PHP memory. EVERY
	 * count/sum shares the SAME gate — post_type=attachment,
	 * post_status=inherit, post_mime_type IN (allowlist) — so trashed
	 * attachments' surviving postmeta is excluded from BOTH the counts and the
	 * byte sums. The flat fields are written from the first optimize on every
	 * install, so this SQL path is correct unconditionally — there is no
	 * pre-fill migration / blob fallback.
	 */
	private static function compute_stats_bundle() {
		global $wpdb;

		$mimes        = Slash_Image_Api_Client::SUPPORTED_MIME_TYPES;
		$mime_holders = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );
		$saved_key    = Slash_Image_Media_Handler::META_SAVED_BYTES_KEY;
		$orig_key     = Slash_Image_Media_Handler::META_ORIGINAL_BYTES_KEY;
		$thumb_key    = Slash_Image_Media_Handler::META_THUMB_COUNT_KEY;
		$best_key     = Slash_Image_Media_Handler::META_BEST_FORMAT_BYTES_KEY;
		$data_key     = Slash_Image_Media_Handler::META_DATA_KEY;

		// $mime_holders is %s tokens only (array_fill/count over the MIME allowlist
		// constant); table names are $wpdb properties; all values bound via prepare().
		// The dynamic IN() defeats the placeholder-count sniffs (Replacements/Unfinished).
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		// total = allowlist attachments.
		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				  WHERE p.post_type     = 'attachment'
				    AND p.post_status   = 'inherit'
				    AND p.post_mime_type IN ( {$mime_holders} )",
				$mimes
			)
		);

		// optimized count + saved/original/thumb sums in one pass. The INNER JOIN
		// on the saved-bytes key makes its EXISTENCE the optimized marker (a
		// 0-saved pre_optimized row still counts); the two LEFT JOINs are 1:1
		// (unique meta_key per post) so there is no row multiplication.
		$opt               = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT( sb.post_id )                 AS optimized,
				        COALESCE( SUM( sb.meta_value ), 0 ) AS saved_bytes,
				        COALESCE( SUM( ob.meta_value ), 0 ) AS original_bytes,
				        COALESCE( SUM( tc.meta_value ), 0 ) AS thumb_count,
				        COALESCE( SUM( bf.meta_value ), 0 ) AS best_format_bytes
				   FROM {$wpdb->posts} p
				   INNER JOIN {$wpdb->postmeta} sb ON sb.post_id = p.ID AND sb.meta_key = %s
				   LEFT  JOIN {$wpdb->postmeta} ob ON ob.post_id = p.ID AND ob.meta_key = %s
				   LEFT  JOIN {$wpdb->postmeta} tc ON tc.post_id = p.ID AND tc.meta_key = %s
				   LEFT  JOIN {$wpdb->postmeta} bf ON bf.post_id = p.ID AND bf.meta_key = %s
				  WHERE p.post_type     = 'attachment'
				    AND p.post_status   = 'inherit'
				    AND p.post_mime_type IN ( {$mime_holders} )",
				array_merge( array( $saved_key, $orig_key, $thumb_key, $best_key ), $mimes )
			),
			ARRAY_A
		);
		$optimized         = (int) ( $opt['optimized'] ?? 0 );
		$saved_bytes       = (int) ( $opt['saved_bytes'] ?? 0 );
		$original_bytes    = (int) ( $opt['original_bytes'] ?? 0 );
		$thumb_count       = (int) ( $opt['thumb_count'] ?? 0 );
		$best_format_bytes = (int) ( $opt['best_format_bytes'] ?? 0 );

		// with_blob = optimized ∪ excluded (disjoint — excluded blobs are
		// optimized=false and have no flat field). So excluded = with_blob -
		// optimized, matching the blob loop's continue-on-excluded.
		$with_blob = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*)
				   FROM {$wpdb->posts} p
				   INNER JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = %s
				  WHERE p.post_type     = 'attachment'
				    AND p.post_status   = 'inherit'
				    AND p.post_mime_type IN ( {$mime_holders} )",
				array_merge( array( $data_key ), $mimes )
			)
		);
		$excluded  = max( 0, $with_blob - $optimized );

		// errors = allowlist attachments with a failed queue row AND no blob (the
		// blob loop counted optimized/excluded BEFORE the failed check, so a row
		// with a blob is never an error). COUNT(DISTINCT) dedupes multiple failed
		// rows per attachment and is uncapped (replaces the failed_rows(1000)
		// scan + PHP intersection).
		$errors = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT( DISTINCT p.ID )
				   FROM {$wpdb->posts} p
				   INNER JOIN {$wpdb->prefix}slash_image_queue q ON q.attachment_id = p.ID AND q.status = %s
				   LEFT  JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = %s
				  WHERE p.post_type     = 'attachment'
				    AND p.post_status   = 'inherit'
				    AND p.post_mime_type IN ( {$mime_holders} )
				    AND d.meta_id IS NULL",
				array_merge( array( Slash_Image_Queue::STATUS_FAILED, $data_key ), $mimes )
			)
		);

		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return array(
			'counts' => self::compose_library_counts( $total, $optimized, $excluded, $errors, $thumb_count ),
			'totals' => array_merge(
				self::compose_size_totals( $original_bytes, $saved_bytes, $optimized ),
				array( 'best_format_bytes' => $best_format_bytes )
			),
		);
	}

	/**
	 * Enqueue a freshly uploaded attachment for optimization. Writes
	 * directly to the wp_slash_image_queue table at PRIORITY_UPLOAD so the
	 * worker's claim ordering puts new uploads ahead of bulk rows.
	 *
	 * Idempotent — Slash_Image_Queue::enqueue dedupes against existing
	 * waiting/claimed rows for the same attachment_id.
	 */
	public static function enqueue_new_upload( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return false;
		}

		// No-key gate: with no API key configured, don't enqueue. This is the one
		// chokepoint for auto-optimize-on-upload, the per-row [Optimize], and the
		// Media Library bulk-optimize action — so a keyless upload stays a normal
		// un-optimized image (never a queued-then-failed row). The keyless prompt
		// is the global "needs an API key" admin notice.
		if ( ! Slash_Image_Connection::has_api_key() ) {
			return false;
		}

		Slash_Image_Queue::enqueue(
			$attachment_id,
			Slash_Image_Queue::SOURCE_UPLOAD,
			Slash_Image_Queue::PRIORITY_UPLOAD
		);

		update_post_meta( $attachment_id, self::STATUS_META_KEY, self::STATUS_QUEUED );

		Slash_Image_Worker::schedule_cron();

		// Start the tab-closed background chain so an upload from ANY screen
		// (Library, Add New, the post-editor "Add Media" modal, or programmatic)
		// drains without a tab open. Lock-guarded — N uploads = 1 chain start.
		// The interactive JS kick still fires separately for instant feedback.
		Slash_Image_Loopback::maybe_start_chain();
		return true;
	}

	/**
	 * Enqueue an attachment for an async restore (the Media Library bulk-restore
	 * action). Mirrors enqueue_new_upload(): source=upload, so it is a prompt,
	 * column-monitored ad-hoc action that NEVER counts toward a bulk session's
	 * progress (the source-based bulk count excludes 'upload', so a concurrent
	 * optimize run's bar is never inflated). job_type=restore routes the worker to
	 * the restore engine. Returns false (skips) when the attachment has no backup.
	 */
	public static function enqueue_restore( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return false;
		}
		if ( ! is_array( get_post_meta( $attachment_id, Slash_Image_Restore::BACKUP_META_KEY, true ) ) ) {
			return false; // No backup — nothing to restore.
		}

		Slash_Image_Queue::enqueue(
			$attachment_id,
			Slash_Image_Queue::SOURCE_UPLOAD,
			Slash_Image_Queue::PRIORITY_UPLOAD,
			Slash_Image_Queue::JOB_TYPE_RESTORE
		);

		update_post_meta( $attachment_id, self::STATUS_META_KEY, self::STATUS_RESTORING );

		Slash_Image_Worker::schedule_cron();
		Slash_Image_Loopback::maybe_start_chain();
		return true;
	}

	/**
	 * Run one worker tick for the bulk-page driver. It passes
	 * $max_rows = 1 (budgeted: process one attachment and return so the
	 * foreground request frees its PHP-FPM worker fast); the cron path uses the
	 * full default batch via Slash_Image_Worker::tick() directly. Returns
	 * 'claimed' so the driver knows whether to re-dispatch.
	 *
	 * @param int|null $max_rows Optional per-tick row cap (see Slash_Image_Worker::tick()).
	 */
	public static function process_batch( $max_rows = null ) {
		$result    = Slash_Image_Worker::tick( $max_rows );
		$processed = (int) ( $result['processed'] ?? 0 );

		// Track recent completion timestamps for the per-minute throughput
		// indicator on the bulk page.
		for ( $i = 0; $i < $processed; $i++ ) {
			self::record_recent_completion();
		}

		return array(
			'processed' => $processed,
			'failed'    => (int) ( $result['failed'] ?? 0 ),
			'claimed'   => (int) ( $result['claimed'] ?? 0 ),
		);
	}

	/**
	 * Count of waiting+claimed rows whose source belongs to the active bulk
	 * run. Includes 'bulk' and 'retry' (retry-failed re-runs); excludes
	 * 'upload' so new uploads don't inflate the bulk-progress denominator.
	 */
	private static function bulk_pending_count() {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}slash_image_queue
				  WHERE status IN ( %s, %s )
				    AND source IN ( %s, %s )",
				Slash_Image_Queue::STATUS_WAITING,
				Slash_Image_Queue::STATUS_CLAIMED,
				Slash_Image_Queue::SOURCE_BULK,
				Slash_Image_Queue::SOURCE_RETRY
			)
		);
	}

	/**
	 * Count bulk/retry rows that reached a terminal 'done' state in the current
	 * run — i.e. finished at or after the run's start. Scoping by finished_at
	 * keeps prior runs' done rows (which linger up to 30 days before purge) out
	 * of this run's progress. $started_unix is the session 'started_at' (UTC
	 * unix); finished_at is stored as a UTC mysql datetime, so both sides are
	 * UTC and directly comparable.
	 *
	 * NOTE: at the 1 s active poll cadence this COUNT runs every second; cheap
	 * on a healthy queue, but on very large libraries it joins the set of
	 * per-poll counts that the bulk-stats SQL-efficiency optimization targets.
	 */
	private static function bulk_done_count_since( $started_unix ) {
		global $wpdb;

		$since = gmdate( 'Y-m-d H:i:s', (int) $started_unix );

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}slash_image_queue
				  WHERE status = %s
				    AND source IN ( %s, %s )
				    AND finished_at >= %s",
				Slash_Image_Queue::STATUS_DONE,
				Slash_Image_Queue::SOURCE_BULK,
				Slash_Image_Queue::SOURCE_RETRY,
				$since
			)
		);
	}

	/**
	 * Count attachments eligible for a bulk run. Mirrors the worker's feed
	 * query so start()'s total_target matches what the worker will actually
	 * process.
	 */
	private static function count_eligible_attachments( $force_redo ) {
		global $wpdb;

		$mimes        = Slash_Image_Api_Client::SUPPORTED_MIME_TYPES;
		$mime_holders = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );

		// $mime_holders is built from %s tokens only (array_fill/count over the MIME
		// allowlist constant), bound via prepare(); the dynamic IN() defeats the
		// placeholder-count sniff.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		if ( $force_redo ) {
			return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts}
					  WHERE post_type = 'attachment'
					    AND post_status = 'inherit'
					    AND post_mime_type IN ( {$mime_holders} )",
					$mimes
				)
			);
		}

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				   LEFT JOIN {$wpdb->postmeta} m
				     ON m.post_id = p.ID AND m.meta_key = %s
				  WHERE p.post_type = 'attachment'
				    AND p.post_status = 'inherit'
				    AND p.post_mime_type IN ( {$mime_holders} )
				    AND m.meta_id IS NULL",
				array_merge( array( Slash_Image_Media_Handler::META_DATA_KEY ), $mimes )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * Pure: derive the displayed run status from the stored status + queue state.
	 *
	 * A run whose source feed is exhausted (`source_done`) AND whose bulk queue
	 * has fully drained (0 waiting/claimed bulk rows) is complete. We surface
	 * that for a 'paused' run as well as a 'running' one: a 401/402 account
	 * failure pauses the session via maybe_halt_on_account_error(), and progress()
	 * never persists 'completed', so without the 'paused' case a drained-but-
	 * paused run would sit at 'paused' forever (the stuck-at-completion bug). A
	 * pause taken mid-feed keeps `source_done` false, so a genuinely-paused run
	 * with rows still to enqueue on resume is never prematurely completed.
	 *
	 * @param string $stored_status Session status: idle|running|paused|completed.
	 * @param bool   $source_done   Whether the bulk feed has exhausted the source.
	 * @param int    $bulk_pending  Count of waiting/claimed bulk+retry rows.
	 * @return string Display status.
	 */
	public static function decide_run_status( $stored_status, $source_done, $bulk_pending ) {
		$stored_status = (string) $stored_status;
		if ( in_array( $stored_status, array( 'running', 'paused' ), true )
			&& $source_done
			&& 0 === (int) $bulk_pending
		) {
			return 'completed';
		}
		return $stored_status;
	}

	/**
	 * Canonical bulk-run display state — the SINGLE source of truth for
	 * { status, processed, total, percent }. The server-side first paint
	 * (bulk-page.php) and the progress poll (ajax_progress) both call this, so
	 * they agree by construction; bulk.js renders these numbers verbatim and
	 * never computes progress itself.
	 *
	 * `processed` is the count of THIS run's bulk/retry rows that have actually
	 * finished (status=done, finished_at >= the run's start). It is deliberately
	 * NOT `total - pending - failed`: that counts un-fed rows as done (the feed
	 * enqueues the source set across worker ticks, so right after Start nothing
	 * is queued yet and pending=0 would make processed=total). Counting real
	 * completions is 0 at Start and climbs monotonically as rows finish.
	 */
	public static function progress() {
		$session = Slash_Image_Worker::get_session();
		$status  = (string) ( $session['status'] ?? 'idle' );
		$total   = (int) ( $session['total_target'] ?? 0 );

		$counts       = Slash_Image_Queue::counts();
		$failed_total = (int) ( $counts[ Slash_Image_Queue::STATUS_FAILED ] ?? 0 );
		$bulk_pending = self::bulk_pending_count();

		// A run whose source feed is exhausted and whose bulk queue has fully
		// drained is complete — surface that to the UI even before the next
		// worker tick flips the session status, and for a 'paused' run too (a
		// 401/402 halt pauses the session; without this it would sit paused
		// forever once drained).
		$status = self::decide_run_status( $status, ! empty( $session['source_done'] ), $bulk_pending );

		// When a run first reaches 'completed', schedule ONE authoritative plan
		// refresh (GET /v1/keys/me via the daily-sync handler) to correct any
		// drift from the per-image X-Images-Remaining cache patches. Keyed on
		// started_at so it fires exactly once per run despite progress() being
		// polled often: a fresh run gets a new started_at, so the guard resets
		// without touching the seed paths. Non-blocking — the event runs on the
		// next WP-Cron spawn; the returned numbers are unchanged.
		$started_at = (int) ( $session['started_at'] ?? 0 );
		if ( 'completed' === $status
			&& $started_at > 0
			&& (int) ( $session['plan_synced_run'] ?? 0 ) !== $started_at ) {
			wp_schedule_single_event( time(), Slash_Image_Connection::DAILY_SYNC_HOOK );
			$session['plan_synced_run'] = $started_at;
			Slash_Image_Worker::save_session( $session );
		}

		if ( 'idle' === $status ) {
			// No active run → 0 / 0, no bar. (A leftover total_target from a
			// cancelled/finished run must not leak into the display.)
			$total     = 0;
			$processed = 0;
		} else {
			// running / paused / completed → count rows actually finished in
			// this run. min() guards against any over-count; max(0,…) is belt.
			$started_at = (int) ( $session['started_at'] ?? 0 );
			$done       = ( $started_at > 0 ) ? self::bulk_done_count_since( $started_at ) : 0;
			$processed  = max( 0, min( $done, $total ) );
		}

		$percent = ( $total > 0 ) ? min( 100, (int) round( ( $processed / $total ) * 100 ) ) : 0;

		return array(
			'status'           => $status,
			'started_at'       => (int) ( $session['started_at'] ?? 0 ),
			'total'            => $total,
			'processed'        => $processed,
			'percent'          => $percent,
			'failed_count'     => $failed_total,
			'last_activity_at' => (int) ( $session['last_tick_at'] ?? 0 ),
			'finished_at'      => (int) ( $session['finished_at'] ?? 0 ),
			'force_redo'       => (bool) ( $session['force_redo'] ?? false ),
		);
	}

	/**
	 * Returns the attachment IDs of bulk-source rows still in waiting/claimed.
	 * Used as a remaining-count source where callers expect an array of IDs.
	 */
	public static function queue() {
		global $wpdb;

		$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT attachment_id FROM {$wpdb->prefix}slash_image_queue
				  WHERE status IN ( %s, %s )
				    AND source IN ( %s, %s )",
				Slash_Image_Queue::STATUS_WAITING,
				Slash_Image_Queue::STATUS_CLAIMED,
				Slash_Image_Queue::SOURCE_BULK,
				Slash_Image_Queue::SOURCE_RETRY
			)
		);

		return is_array( $rows ) ? array_map( 'intval', $rows ) : array();
	}

	/**
	 * Image-level counts (one attachment = one unit). Limited to the supported
	 * MIME allowlist. Served from the short-TTL stats bundle (see
	 * stats_bundle()) — SQL aggregates over the flat fields, no per-row
	 * unserialize.
	 *
	 * Shape: { total, optimized, not_optimized, errors, excluded,
	 * thumbnails_optimized }.
	 */
	public static function library_counts() {
		return self::stats_bundle()['counts'];
	}

	/**
	 * Pure: compose the displayed library-counts array from raw SQL counts.
	 * `not_optimized` is derived exactly as the pre-Phase-4 blob loop did:
	 * max( 0, total - optimized - errors - excluded ).
	 */
	public static function compose_library_counts( $total, $optimized, $excluded, $errors, $thumbnails_optimized ) {
		$total                = (int) $total;
		$optimized            = (int) $optimized;
		$excluded             = (int) $excluded;
		$errors               = (int) $errors;
		$thumbnails_optimized = (int) $thumbnails_optimized;

		$not_optimized = max( 0, $total - $optimized - $errors - $excluded );

		return array(
			'total'                => $total,
			'optimized'            => $optimized,
			'not_optimized'        => $not_optimized,
			'errors'               => $errors,
			'excluded'             => $excluded,
			'thumbnails_optimized' => $thumbnails_optimized,
		);
	}

	/**
	 * Pure: compose the size-totals array. optimized_bytes is derived as
	 * original - saved (floored at 0); attachments = the optimized count.
	 */
	public static function compose_size_totals( $original_bytes, $saved_bytes, $optimized_count ) {
		$original_bytes = (int) $original_bytes;
		$saved_bytes    = (int) $saved_bytes;

		return array(
			'original_bytes'  => $original_bytes,
			'optimized_bytes' => max( 0, $original_bytes - $saved_bytes ),
			'attachments'     => (int) $optimized_count,
		);
	}

	/* ── Internal helpers ────────────────────────────────── */

	/**
	 * Registers the cron schedules used by the worker. Hooked into
	 * cron_schedules from the main plugin class.
	 */
	public static function register_schedule( $schedules ) {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}
		if ( ! isset( $schedules['slash_image_one_minute'] ) ) {
			$schedules['slash_image_one_minute'] = array(
				'interval' => MINUTE_IN_SECONDS,
				'display'  => __( 'Every minute (SlashImage worker)', 'slashimage-image-optimizer' ),
			);
		}
		return $schedules;
	}

	public static function message_for_code( $code ) {
		switch ( (string) $code ) {
			case 'not_processable_format':
			case 'unsupported_mime':
				// unsupported_mime stays a distinct classifier elsewhere (media
				// handler, Media Library, worker categorize_result); it only shares
				// this user-facing string with not_processable_format.
				return __( 'File type not supported.', 'slashimage-image-optimizer' );
			case 'file_too_large':
				return __( 'File is too large to optimize.', 'slashimage-image-optimizer' );
			case 'size_exceeded_for_plan':
				return __( 'File exceeds the 3 MB limit on the Free plan. Upgrade for higher limits.', 'slashimage-image-optimizer' );
			case 'size_exceeded_server':
				return __( 'File is too large to optimize.', 'slashimage-image-optimizer' );
			case 'gif_too_large':
				return __( 'This GIF is too large to optimize.', 'slashimage-image-optimizer' );
			case 'file_corrupt':
				return __( 'File appears to be corrupted.', 'slashimage-image-optimizer' );
			case 'file_unreadable':
				return __( 'File could not be read from disk.', 'slashimage-image-optimizer' );
			case 'network_error':
				return __( 'Could not connect to the optimization service. Your host may be blocking outbound requests.', 'slashimage-image-optimizer' );
			case 'timeout_exceeded':
				return __( 'Request timed out - you can retry again.', 'slashimage-image-optimizer' );
			case 'payment_required':
				return __( 'You have used all your credits. Upgrade your plan to continue.', 'slashimage-image-optimizer' );
			case 'invalid_key':
				return __( 'API key is invalid or has been revoked.', 'slashimage-image-optimizer' );
			case 'no_api_key':
				return __( 'No API key configured.', 'slashimage-image-optimizer' );
			case 'rate_limited':
				return __( 'Too many requests - please wait a moment and try again.', 'slashimage-image-optimizer' );
			case 'server_error':
				return __( 'Optimization failed. Please try again.', 'slashimage-image-optimizer' );
			default:
				return __( 'An unexpected error occurred.', 'slashimage-image-optimizer' );
		}
	}

	public static function code_carries_upgrade_hint( $code ) {
		// size_exceeded_for_plan is the only billing-aware size cap (Free
		// plan, 3 MB). Upgrading to any paid plan lifts it. The universal
		// 100 MB server cap (size_exceeded_server) and the legacy
		// file_too_large fallback have no plan remedy.
		return ( 'size_exceeded_for_plan' === (string) $code );
	}

	private static function record_recent_completion() {
		$now    = time();
		$cutoff = $now - self::RECENT_WINDOW_SECONDS;
		$list   = get_transient( self::TRANSIENT_RECENT );
		if ( ! is_array( $list ) ) {
			$list = array(); }
		$list   = array_values(
			array_filter(
				$list,
				function ( $ts ) use ( $cutoff ) {
					return $ts >= $cutoff;
				}
			)
		);
		$list[] = $now;
		set_transient( self::TRANSIENT_RECENT, $list, 5 * MINUTE_IN_SECONDS );
	}

	public static function recent_completions_count() {
		$cutoff = time() - self::RECENT_WINDOW_SECONDS;
		$list   = get_transient( self::TRANSIENT_RECENT );
		if ( ! is_array( $list ) ) {
			return 0; }
		return count(
			array_filter(
				$list,
				function ( $ts ) use ( $cutoff ) {
					return $ts >= $cutoff;
				}
			)
		);
	}

	/* ── Bulk Optimize page redesign helpers (presentation data) ───────────── */

	/**
	 * Human-readable byte size for the redesigned bulk page ("2.4 MB", "540 KB").
	 * MB and up render with one decimal; KB rounds to whole, matching the mockup.
	 */
	private static function format_bytes( $bytes ) {
		$bytes    = max( 0, (int) $bytes );
		$decimals = ( $bytes >= MB_IN_BYTES ) ? 1 : 0;
		$human    = size_format( $bytes, $decimals );
		return $human ? $human : '0 B';
	}

	/**
	 * Upper-bound estimate of the total thumbnails across the WHOLE library
	 * (optimized + pending). We only store a real per-attachment thumbnail count
	 * for already-optimized images, so for the library total we estimate
	 * registered-subsize-count × attachments. Deliberately an upper bound — small
	 * images may not generate every registered size; the exact count would need an
	 * O(library) scan of _wp_attachment_metadata, which was removed on purpose.
	 * Surfaced only as an approximate header figure.
	 */
	private static function total_thumbnails_estimate() {
		$counts = self::library_counts();
		$sizes  = Slash_Image_Settings::available_image_sizes();
		// available_image_sizes() includes a synthetic 'full' entry — subtract it.
		$thumb_sizes = max( 0, count( $sizes ) - 1 );
		return $thumb_sizes * (int) $counts['total'];
	}

	/**
	 * Rough credit estimate for a from-scratch bulk run of the pending images:
	 * each pending image costs 1 credit per non-excluded size (full + registered
	 * thumbnail sizes, minus the user's Image Size Exclusions). AVIF/WebP variants
	 * are free and not counted. Upper bound for the same reason as
	 * total_thumbnails_estimate().
	 */
	public static function credits_estimate() {
		$counts          = self::library_counts();
		$pending         = (int) $counts['not_optimized'];
		$available       = Slash_Image_Settings::available_image_sizes();
		$excluded        = Slash_Image_Settings::excluded_image_sizes();
		$files_per_image = max( 1, count( $available ) - count( $excluded ) );
		return $pending * $files_per_image;
	}

	/**
	 * Up to 3 rows for the running-state "now" list: the row being processed right
	 * now (if any) followed by the last 2 completions. Cheap and bounded — two
	 * index-backed queries (LIMIT 1 / LIMIT 2) plus a handful of cached postmeta
	 * reads (perf-tested: ~0.34 ms total per poll on a no-object-cache host).
	 */
	private static function recent_completions() {
		global $wpdb;

		$results = array();

		// Currently-claimed row (the image being optimized right now). The claimed
		// set is bounded by the concurrency ceiling, so any claimed row identifies
		// the active image — no ORDER BY needed.
		$claimed_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT attachment_id FROM {$wpdb->prefix}slash_image_queue
				  WHERE status = %s
				  LIMIT 1",
				Slash_Image_Queue::STATUS_CLAIMED
			)
		);
		if ( $claimed_id > 0 ) {
			$path       = get_attached_file( $claimed_id );
			$size_bytes = ( is_string( $path ) && file_exists( $path ) ) ? (int) filesize( $path ) : 0;
			$results[]  = array(
				'state'    => 'active',
				'filename' => is_string( $path ) ? wp_basename( $path ) : '',
				'size'     => self::format_bytes( $size_bytes ),
			);
		}

		// Last 2 completed rows, any source — a backward index scan on
		// (status, finished_at); LIMIT 2 short-circuits immediately.
		$done_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT attachment_id FROM {$wpdb->prefix}slash_image_queue
				  WHERE status = %s
				  ORDER BY finished_at DESC
				  LIMIT 2",
				Slash_Image_Queue::STATUS_DONE
			)
		);
		foreach ( (array) $done_ids as $id ) {
			$id   = (int) $id;
			$data = get_post_meta( $id, Slash_Image_Media_Handler::META_DATA_KEY, true );
			$path = get_attached_file( $id );

			$original_bytes  = ( is_array( $data ) && isset( $data['original_size_bytes'] ) ) ? (int) $data['original_size_bytes'] : 0;
			$optimized_bytes = ( is_array( $data ) && isset( $data['optimized_size_bytes'] ) ) ? (int) $data['optimized_size_bytes'] : 0;
			$saved_percent   = ( is_array( $data ) && isset( $data['saved_percent_overall'] ) ) ? (int) round( $data['saved_percent_overall'] ) : 0;

			$results[] = array(
				'state'          => 'done',
				'filename'       => is_string( $path ) ? wp_basename( $path ) : '',
				'original_size'  => self::format_bytes( $original_bytes ),
				'optimized_size' => self::format_bytes( $optimized_bytes ),
				'saved_percent'  => $saved_percent,
			);
		}

		return $results;
	}
}
