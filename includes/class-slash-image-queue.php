<?php
/**
 * Persistent queue backed by a custom MySQL table (wp_slash_image_queue).
 *
 * One row = one attachment slated for optimization. Status enum:
 *   - waiting   — eligible to be claimed by a worker
 *   - claimed   — a worker has it and is processing
 *   - done      — finished (success or silent skip)
 *   - failed    — permanently failed after exhausting retries
 *
 * Concurrency-safety primitives:
 *   - claim() uses a SELECT-then-UPDATE with token discrimination to
 *     assign rows to a single worker.
 *   - enqueue() wraps SELECT-then-INSERT in a transaction with FOR UPDATE
 *     to dedupe across concurrent workers.
 *   - recover_stale() reaps rows whose claimed_at exceeds the stale timeout.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Queue {

	const STATUS_WAITING = 'waiting';
	const STATUS_CLAIMED = 'claimed';
	const STATUS_DONE    = 'done';
	const STATUS_FAILED  = 'failed';

	const SOURCE_UPLOAD = 'upload';
	const SOURCE_BULK   = 'bulk';
	const SOURCE_MANUAL = 'manual';
	const SOURCE_RETRY  = 'retry';

	// Job type — WHAT work a row represents (the worker dispatches on this).
	// Distinct from `source` (WHO enqueued it). Schema v3. Existing/in-flight
	// rows default to 'optimize' on the column add, staying unambiguous.
	const JOB_TYPE_OPTIMIZE = 'optimize';
	const JOB_TYPE_RESTORE  = 'restore';

	// enqueue() sentinel: a new row of the OTHER job_type collided with an
	// already-claimed (in-flight) row that cannot be preempted. Distinct from a
	// row id (positive) and failure (0).
	const ENQUEUE_IN_FLIGHT = -1;

	const PRIORITY_UPLOAD = 10;
	const PRIORITY_MANUAL = 50;
	const PRIORITY_BULK   = 100;

	const SCHEMA_VERSION_OPTION = 'slash_image_queue_schema_version';
	const SCHEMA_VERSION        = 3;

	// The queue-level driver-liveness verdict (last completion / pending
	// / chain-running) is cached for this short window so the 1 s status poll
	// doesn't re-run the MAX(finished_at) aggregate every second.
	// Time-expiry only.
	const STALL_LIVENESS_TRANSIENT = 'slash_image_stall_liveness';
	const STALL_LIVENESS_TTL       = 12;

	const MAX_ATTEMPTS = 3;

	/** Backoff in seconds per attempt: 1m, 5m, 15m. */
	const BACKOFF_SECONDS = array( 60, 300, 900 );

	const PURGE_CRON_HOOK = 'slash_image_queue_purge';

	/**
	 * Subscribe to the daily purge cron event. Called once per page load
	 * from the main plugin class.
	 */
	public static function register_cron() {
		add_action( self::PURGE_CRON_HOOK, array( __CLASS__, 'purge_old' ) );
	}

	/**
	 * Schedule the daily purge cron event if not already scheduled.
	 * Idempotent.
	 */
	public static function schedule_purge_cron() {
		if ( ! wp_next_scheduled( self::PURGE_CRON_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::PURGE_CRON_HOOK );
		}
	}

	public static function clear_purge_cron() {
		wp_clear_scheduled_hook( self::PURGE_CRON_HOOK );
	}

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'slash_image_queue';
	}

	/**
	 * Create or upgrade the queue table. Idempotent — safe to call on every
	 * activation.
	 */
	public static function install_schema() {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$table   = $wpdb->prefix . 'slash_image_queue';

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT UNSIGNED NOT NULL,
			source VARCHAR(20) NOT NULL DEFAULT 'bulk',
			job_type VARCHAR(20) NOT NULL DEFAULT 'optimize',
			status VARCHAR(10) NOT NULL DEFAULT 'waiting',
			priority SMALLINT NOT NULL DEFAULT 100,
			attempts TINYINT NOT NULL DEFAULT 0,
			available_at DATETIME NULL DEFAULT NULL,
			claimed_at DATETIME NULL DEFAULT NULL,
			claimed_by VARCHAR(40) NULL DEFAULT NULL,
			error_code VARCHAR(64) NULL DEFAULT NULL,
			error_message TEXT NULL,
			enqueued_at DATETIME NOT NULL,
			finished_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status_priority (status, priority, enqueued_at),
			KEY attachment_status (attachment_id, status),
			KEY available_status (status, available_at),
			KEY status_finished (status, finished_at),
			KEY type_status (job_type, status)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Self-healing schema upgrade for update-in-place. The activation hook does
	 * NOT fire on a plugin update, so a column added in a new schema version
	 * would otherwise never land. Called on load; self-gates on the stored
	 * version (a single get_option once upgraded). install_schema() writes the
	 * new version on success, so a successful run flips this to a no-op — without
	 * that write it would re-run dbDelta on every request.
	 */
	public static function maybe_upgrade_schema() {
		$installed = (int) get_option( self::SCHEMA_VERSION_OPTION, 0 );
		if ( $installed < self::SCHEMA_VERSION ) {
			self::install_schema();
		}
	}

	public static function drop_schema() {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}slash_image_queue" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL on the plugin's own custom queue table; not a cacheable read.
		delete_option( self::SCHEMA_VERSION_OPTION );
	}

	/**
	 * Add this site's queue table to core's site-deletion drop list.
	 *
	 * Filters wpmu_drop_tables so core drops {prefix}slash_image_queue when a
	 * multisite subsite is deleted — by default core drops only its own blog
	 * tables, which would orphan this custom table. The filter runs while
	 * switched to the site being deleted, so table_name() already resolves
	 * $wpdb->prefix to that site's prefix; no blog_id handling is needed. Core
	 * performs the DROP in its own teardown — this only appends the name.
	 *
	 * @param string[] $tables Tables core will drop for the site.
	 * @return string[] Tables with this plugin's queue table appended.
	 */
	public static function add_drop_table( $tables ) {
		$tables[] = self::table_name();
		return $tables;
	}

	/* ── Public API ────────────────────────────────────────────── */

	/**
	 * Enqueue an attachment for a given job_type (optimize or restore), keeping
	 * the one-row-per-attachment invariant for waiting/claimed rows:
	 *
	 * - No pending row → insert a fresh waiting row (clearing any terminal failed
	 *   row first).
	 * - Pending row of the SAME job_type → idempotent (return its id, no change).
	 * - WAITING row of the OTHER job_type → supersede in place (last-action-wins:
	 *   optimize and restore are mutually exclusive intents). The row keeps its id
	 *   but is reset to the new job_type/source/priority and re-queued.
	 * - CLAIMED (in-flight) row of the OTHER job_type → cannot preempt a worker;
	 *   return ENQUEUE_IN_FLIGHT and leave the claimed work to finish.
	 *
	 * Wraps the lookup in a transaction with FOR UPDATE so concurrent workers
	 * can't both miss and both insert (or both supersede).
	 *
	 * Returns the row id on insert/found/supersede, ENQUEUE_IN_FLIGHT (-1) on the
	 * in-flight collision, or 0 on failure.
	 */
	public static function enqueue( $attachment_id, $source = self::SOURCE_BULK, $priority = self::PRIORITY_BULK, $job_type = self::JOB_TYPE_OPTIMIZE ) {
		global $wpdb;

		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return 0;
		}

		$source   = self::sanitize_source( $source );
		$job_type = self::sanitize_job_type( $job_type );

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control on the custom queue table; not a cacheable read.

		// Lock any existing waiting/claimed row for this attachment. We need its
		// status + job_type to decide idempotent-return vs supersede vs in-flight.
		$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, status, job_type FROM {$wpdb->prefix}slash_image_queue
				  WHERE attachment_id = %d
				    AND status IN ( %s, %s )
				  LIMIT 1
				  FOR UPDATE",
				$attachment_id,
				self::STATUS_WAITING,
				self::STATUS_CLAIMED
			),
			ARRAY_A
		);

		if ( $existing ) {
			$existing_id     = (int) $existing['id'];
			$existing_type   = (string) $existing['job_type'];
			$existing_status = (string) $existing['status'];

			// Same intent already pending — idempotent.
			if ( $existing_type === $job_type ) {
				$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control on the custom queue table; not a cacheable read.
				return $existing_id;
			}

			// Different intent on an in-flight (claimed) row — cannot preempt the
			// worker; let it finish and signal the caller.
			if ( self::STATUS_CLAIMED === $existing_status ) {
				$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control on the custom queue table; not a cacheable read.
				return self::ENQUEUE_IN_FLIGHT;
			}

			// Different intent on a WAITING row — supersede in place (last-action
			// -wins). Keep the row id; reset everything else to a fresh attempt of
			// the new intent.
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prefix . 'slash_image_queue',
				array(
					'job_type'      => $job_type,
					'source'        => $source,
					'priority'      => (int) $priority,
					'status'        => self::STATUS_WAITING,
					'attempts'      => 0,
					'available_at'  => null,
					'claimed_at'    => null,
					'claimed_by'    => null,
					'error_code'    => null,
					'error_message' => null,
					'enqueued_at'   => current_time( 'mysql', true ),
				),
				array( 'id' => $existing_id ),
				array( '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control on the custom queue table; not a cacheable read.
			return $existing_id;
		}

		// Starting a fresh attempt: clear any prior terminal FAILED row(s) for
		// this attachment so exactly one row remains. Otherwise an old failed
		// row lingers beside the new waiting one — a stale duplicate that keeps
		// driving the Media Library error state (the status column reads the most
		// recent failed row, which is checked before the optimized data, so it
		// would even shadow a later success). Done rows are left for history.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}slash_image_queue
				  WHERE attachment_id = %d AND status = %s",
				$attachment_id,
				self::STATUS_FAILED
			)
		);

		$now      = current_time( 'mysql', true );
		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'slash_image_queue',
			array(
				'attachment_id' => $attachment_id,
				'source'        => $source,
				'job_type'      => $job_type,
				'status'        => self::STATUS_WAITING,
				'priority'      => (int) $priority,
				'attempts'      => 0,
				'enqueued_at'   => $now,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%s' )
		);

		$row_id = $inserted ? (int) $wpdb->insert_id : 0;

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control on the custom queue table; not a cacheable read.

		return $row_id;
	}

	/**
	 * Atomic claim. Updates up to $limit waiting rows (whose available_at is
	 * either NULL or in the past) to claimed status with the given worker
	 * token, then SELECTs them. Returns the array of claimed rows.
	 *
	 * @param string      $worker_token Claim token, discriminates concurrent workers.
	 * @param int         $limit        Max rows to claim.
	 * @param string|null $job_type     Optional job_type scope. When given, only rows
	 *                                  of that type are claimed — the worker passes
	 *                                  JOB_TYPE_RESTORE while no API key is configured
	 *                                  so a stray/legacy optimize row is never
	 *                                  claimed-then-failed (local restore jobs still
	 *                                  drain). NULL = claim any job_type.
	 */
	public static function claim( $worker_token, $limit = 5, $job_type = null ) {
		global $wpdb;

		$worker_token = (string) $worker_token;
		$limit        = max( 1, (int) $limit );
		$now          = current_time( 'mysql', true );

		$type_clause = '';
		$select_args = array( self::STATUS_WAITING, $now );
		if ( null !== $job_type ) {
			$type_clause   = ' AND job_type = %s';
			$select_args[] = self::sanitize_job_type( $job_type );
		}
		$select_args[] = $limit;

		// $type_clause is a fixed string with a single %s placeholder (or empty);
		// the merged $select_args line up with the placeholders. The array-arg form
		// also defeats the placeholder-count sniff, so it's disabled here too (same
		// as next_source_ids()). UnescapedDBParameter likewise can't see $type_clause
		// is a literal whose %s is bound via prepare(), so it's disabled here too.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}slash_image_queue
				  WHERE status = %s
				    AND ( available_at IS NULL OR available_at <= %s ){$type_clause}
				  ORDER BY priority ASC, enqueued_at ASC
				  LIMIT %d",
				$select_args
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( empty( $ids ) ) {
			return array();
		}

		$ids = array_map( 'intval', $ids );

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$update_args  = array_merge( array( self::STATUS_CLAIMED, $now, $worker_token, self::STATUS_WAITING ), $ids );

		// $placeholders is built from %d tokens only — safe to interpolate.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}slash_image_queue
				    SET status = %s, claimed_at = %s, claimed_by = %s
				  WHERE status = %s
				    AND id IN ( {$placeholders} )",
				$update_args
			)
		);

		// SELECT only rows we actually claimed (claimed_by = our token).
		$select_args = array_merge( array( self::STATUS_CLAIMED, $worker_token ), $ids );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}slash_image_queue
				  WHERE status = %s
				    AND claimed_by = %s
				    AND id IN ( {$placeholders} )",
				$select_args
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return is_array( $rows ) ? $rows : array();
	}

	public static function complete( $row_id, $code = null ) {
		global $wpdb;

		$row_id = (int) $row_id;
		if ( $row_id <= 0 ) {
			return false;
		}

		return (bool) $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'slash_image_queue',
			array(
				'status'        => self::STATUS_DONE,
				'finished_at'   => current_time( 'mysql', true ),
				'error_code'    => null === $code ? null : (string) $code,
				'error_message' => null,
				'claimed_at'    => null,
				'claimed_by'    => null,
			),
			array( 'id' => $row_id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Mark a row permanently failed immediately — no retry, no backoff
	 * requeue, and the 3 retry attempts are not consumed. For terminal
	 * conditions that are identical on every attempt (e.g. file_too_large,
	 * unsupported_mime). The worker routes 'permanent'-categorised results
	 * here; transient failures keep going through fail().
	 */
	public static function fail_permanent( $row_id, $code, $message = '' ) {
		global $wpdb;

		$row_id = (int) $row_id;
		if ( $row_id <= 0 ) {
			return false;
		}

		return (bool) $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'slash_image_queue',
			array(
				'status'        => self::STATUS_FAILED,
				'finished_at'   => current_time( 'mysql', true ),
				'error_code'    => (string) $code,
				'error_message' => (string) $message,
				'claimed_at'    => null,
				'claimed_by'    => null,
			),
			array( 'id' => $row_id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Mark a row failed. If attempts < MAX_ATTEMPTS, the row is requeued with
	 * an exponential backoff (available_at = now + backoff). Otherwise it
	 * stays in 'failed' status.
	 *
	 * @param int      $row_id          Queue row id.
	 * @param string   $code            Error code stored on the row.
	 * @param string   $message         Error message stored on the row.
	 * @param int|null $backoff_override Seconds until the row is re-eligible,
	 *                                   overriding the default BACKOFF_SECONDS
	 *                                   schedule. Used to honor an API
	 *                                   `Retry-After` (e.g. 503 service_busy).
	 *                                   Ignored on the terminal (cap-reached)
	 *                                   path and when null / non-positive.
	 */
	public static function fail( $row_id, $code, $message = '', $backoff_override = null ) {
		global $wpdb;

		$row_id = (int) $row_id;
		if ( $row_id <= 0 ) {
			return false;
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT attempts FROM {$wpdb->prefix}slash_image_queue WHERE id = %d LIMIT 1",
				$row_id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return false;
		}

		$attempts = (int) $row['attempts'] + 1;
		$now      = current_time( 'mysql', true );

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			return (bool) $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prefix . 'slash_image_queue',
				array(
					'status'        => self::STATUS_FAILED,
					'attempts'      => $attempts,
					'finished_at'   => $now,
					'error_code'    => (string) $code,
					'error_message' => (string) $message,
					'claimed_at'    => null,
					'claimed_by'    => null,
				),
				array( 'id' => $row_id ),
				array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		}

		$backoff_idx     = max( 0, min( $attempts - 1, count( self::BACKOFF_SECONDS ) - 1 ) );
		$default_backoff = self::BACKOFF_SECONDS[ $backoff_idx ];
		// Honor an API Retry-After hint (e.g. 503 service_busy) when provided;
		// otherwise use the default exponential schedule.
		$backoff      = ( null !== $backoff_override && (int) $backoff_override > 0 )
			? (int) $backoff_override
			: $default_backoff;
		$available_at = gmdate( 'Y-m-d H:i:s', time() + $backoff );

		return (bool) $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'slash_image_queue',
			array(
				'status'        => self::STATUS_WAITING,
				'attempts'      => $attempts,
				'available_at'  => $available_at,
				'error_code'    => (string) $code,
				'error_message' => (string) $message,
				'claimed_at'    => null,
				'claimed_by'    => null,
			),
			array( 'id' => $row_id ),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Reset a failed row to waiting when the user explicitly retries via UI.
	 * Re-eligible immediately (clears available_at), attempts reset to 0.
	 */
	public static function reset_for_retry( $row_id ) {
		global $wpdb;
		$row_id = (int) $row_id;
		if ( $row_id <= 0 ) {
			return false;
		}
		return (bool) $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'slash_image_queue',
			array(
				'status'        => self::STATUS_WAITING,
				'attempts'      => 0,
				'available_at'  => null,
				'error_code'    => null,
				'error_message' => null,
				'claimed_at'    => null,
				'claimed_by'    => null,
				'finished_at'   => null,
			),
			array( 'id' => $row_id ),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Recover claimed rows whose claimed_at exceeds the stale timeout.
	 * Returns them to 'waiting' with attempts incremented.
	 *
	 * Filter: slash_image_stale_claim_timeout (seconds), default 180.
	 */
	public static function recover_stale() {
		global $wpdb;

		$timeout = (int) apply_filters( 'slash_image_stale_claim_timeout', 180 );
		$timeout = max( 30, $timeout );
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $timeout );

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}slash_image_queue
				    SET status = %s,
				        attempts = attempts + 1,
				        claimed_at = NULL,
				        claimed_by = NULL
				  WHERE status = %s
				    AND claimed_at IS NOT NULL
				    AND claimed_at < %s",
				self::STATUS_WAITING,
				self::STATUS_CLAIMED,
				$cutoff
			)
		);
	}

	/** Returns ['waiting'=>n,'claimed'=>n,'done'=>n,'failed'=>n]. */
	public static function counts() {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			"SELECT status, COUNT(*) AS n FROM {$wpdb->prefix}slash_image_queue GROUP BY status",
			ARRAY_A
		);

		$out = array(
			self::STATUS_WAITING => 0,
			self::STATUS_CLAIMED => 0,
			self::STATUS_DONE    => 0,
			self::STATUS_FAILED  => 0,
		);
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$out[ $r['status'] ] = (int) $r['n'];
			}
		}
		return $out;
	}

	public static function failed_rows( $limit = 100 ) {
		global $wpdb;
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}slash_image_queue
				  WHERE status = %s
				  ORDER BY finished_at DESC
				  LIMIT %d",
				self::STATUS_FAILED,
				(int) $limit
			),
			ARRAY_A
		);
	}

	/**
	 * The most recent failed row for one attachment, or null if none. Served by
	 * the (attachment_id, status) index — a single-row indexed lookup used by
	 * the Media Library status column instead of scanning failed_rows() once per
	 * rendered attachment, and with no fixed row-window cap.
	 */
	public static function failed_row_for( $attachment_id ) {
		global $wpdb;
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}slash_image_queue
				  WHERE attachment_id = %d
				    AND status = %s
				  ORDER BY finished_at DESC
				  LIMIT 1",
				(int) $attachment_id,
				self::STATUS_FAILED
			),
			ARRAY_A
		);
	}

	/**
	 * The active (waiting/claimed) queue row for one attachment, or null.
	 * Served by the (attachment_id, status) index — a single indexed lookup
	 * used by the Media Library stall check. There is at most one active row
	 * per attachment (the enqueue() FOR UPDATE dedupe guarantees it); ORDER BY
	 * id DESC LIMIT 1 is belt-and-suspenders.
	 */
	public static function active_row_for( $attachment_id ) {
		global $wpdb;
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}slash_image_queue
				  WHERE attachment_id = %d
				    AND status IN ( %s, %s )
				  ORDER BY id DESC
				  LIMIT 1",
				(int) $attachment_id,
				self::STATUS_WAITING,
				self::STATUS_CLAIMED
			),
			ARRAY_A
		);
	}

	/**
	 * Batch sibling of failed_row_for(): the most-recent failed queue row for
	 * each of $ids, as a map keyed by attachment_id. One prepared IN() query
	 * instead of N single-row lookups — the Media Library status column primes
	 * its per-request cache from this so a page of rows costs one query, not one
	 * SELECT per attachment. Ids with no failed row are simply absent from the
	 * map (the caller records a negative sentinel). (A6-01)
	 *
	 * @param int[] $ids Attachment ids.
	 * @return array<int,array> attachment_id => row (ARRAY_A), most-recent failed.
	 */
	public static function failed_rows_for( array $ids ) {
		global $wpdb;
		$ids = self::clean_attachment_ids( $ids );
		if ( empty( $ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$args         = array_merge( $ids, array( self::STATUS_FAILED ) );

		// $placeholders is built from %d tokens only — safe to interpolate.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}slash_image_queue
				  WHERE attachment_id IN ( {$placeholders} )
				    AND status = %s
				  ORDER BY finished_at DESC",
				$args
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return self::first_row_per_attachment( $rows );
	}

	/**
	 * Batch sibling of active_row_for(): the active (waiting/claimed) queue row
	 * for each of $ids, keyed by attachment_id. At most one active row exists per
	 * attachment (the enqueue() FOR UPDATE dedupe), so the map is exact. (A6-01)
	 *
	 * @param int[] $ids Attachment ids.
	 * @return array<int,array> attachment_id => row (ARRAY_A).
	 */
	public static function active_rows_for( array $ids ) {
		global $wpdb;
		$ids = self::clean_attachment_ids( $ids );
		if ( empty( $ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$args         = array_merge( $ids, array( self::STATUS_WAITING, self::STATUS_CLAIMED ) );

		// $placeholders is built from %d tokens only — safe to interpolate.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}slash_image_queue
				  WHERE attachment_id IN ( {$placeholders} )
				    AND status IN ( %s, %s )
				  ORDER BY id DESC",
				$args
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return self::first_row_per_attachment( $rows );
	}

	/**
	 * Reduce an ordered row list to the FIRST row seen per attachment_id. With
	 * the batch queries above ordered most-recent-first, that first row is the
	 * one each single lookup (failed_row_for / active_row_for) would have
	 * returned.
	 *
	 * @param mixed $rows get_results( ARRAY_A ) output.
	 * @return array<int,array>
	 */
	private static function first_row_per_attachment( $rows ) {
		$map = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$aid = (int) ( $row['attachment_id'] ?? 0 );
				if ( $aid > 0 && ! isset( $map[ $aid ] ) ) {
					$map[ $aid ] = $row;
				}
			}
		}
		return $map;
	}

	/**
	 * Normalize an id list to unique positive ints.
	 *
	 * @param array $ids
	 * @return int[]
	 */
	private static function clean_attachment_ids( array $ids ) {
		$ids = array_map( 'intval', $ids );
		$ids = array_filter(
			$ids,
			static function ( $i ) {
				return $i > 0;
			}
		);
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Queue-level driver-liveness verdict for the stall check:
	 *   - last_completion: unix of MAX(finished_at) over done rows (0 = never)
	 *   - pending: waiting + claimed row count
	 *   - chain_running: is a loopback chain holding the lock right now
	 * Cached in a short transient (STALL_LIVENESS_TTL) so the 1 s status poll
	 * recomputes this at most once per window, not once per polled row.
	 */
	public static function liveness_verdict() {
		$cached = get_transient( self::STALL_LIVENESS_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['pending'], $cached['last_completion'], $cached['chain_running'] ) ) {
			return $cached;
		}

		global $wpdb;
		$last = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT MAX(finished_at) FROM {$wpdb->prefix}slash_image_queue WHERE status = %s",
				self::STATUS_DONE
			)
		);

		$counts  = self::counts();
		$verdict = array(
			// finished_at is a UTC mysql datetime; parse as UTC to compare against time().
			'last_completion' => $last ? (int) strtotime( $last . ' UTC' ) : 0,
			'pending'         => (int) ( $counts[ self::STATUS_WAITING ] ?? 0 ) + (int) ( $counts[ self::STATUS_CLAIMED ] ?? 0 ),
			'chain_running'   => class_exists( 'Slash_Image_Loopback' ) ? (bool) Slash_Image_Loopback::is_chain_running() : false,
		);

		set_transient( self::STALL_LIVENESS_TRANSIENT, $verdict, self::STALL_LIVENESS_TTL );
		return $verdict;
	}

	/**
	 * Pure: decide whether an active queue row is "stalled". No DB / WP
	 * calls.
	 *
	 * @param array      $row       ['status', 'claimed_at'(unix), 'enqueued_at'(unix), 'available_at'(unix)].
	 * @param array      $liveness  ['pending'(int), 'chain_running'(bool), 'last_completion'(unix, 0=never)].
	 * @param int        $now       Current unix time.
	 * @param int        $threshold Stall threshold in seconds.
	 * @return array ['stalled'=>bool, 'reason'=>string|null]
	 */
	public static function decide_stall_state( $row, $liveness, $now, $threshold ) {
		$not_stalled = array(
			'stalled' => false,
			'reason'  => null,
		);
		if ( ! is_array( $row ) ) {
			return $not_stalled;
		}

		$status    = isset( $row['status'] ) ? (string) $row['status'] : '';
		$now       = (int) $now;
		$threshold = max( 1, (int) $threshold );

		// Claimed-stall: a still-claimed row older than the threshold proves no
		// tick ran (recover_stale would have reaped it at ~180 s). Unambiguous —
		// independent of the liveness verdict.
		if ( self::STATUS_CLAIMED === $status ) {
			$claimed_at = (int) ( $row['claimed_at'] ?? 0 );
			if ( $claimed_at > 0 && ( $now - $claimed_at ) > $threshold ) {
				return array(
					'stalled' => true,
					'reason'  => 'claimed_stall',
				);
			}
			return $not_stalled;
		}

		// Waiting-stall: only when the driver is dead AND the row has itself aged
		// past the gate AND it isn't intentionally backoff-deferred.
		if ( self::STATUS_WAITING === $status ) {
			$enqueued_at  = (int) ( $row['enqueued_at'] ?? 0 );
			$available_at = (int) ( $row['available_at'] ?? 0 ); // 0 = NULL = available now.

			if ( $available_at > $now ) {
				return $not_stalled; // Intentionally deferred (backoff) — not stalled.
			}
			if ( $enqueued_at <= 0 || ( $now - $enqueued_at ) <= $threshold ) {
				return $not_stalled; // Fresh enough — hasn't crossed its own age gate.
			}

			$pending = isset( $liveness['pending'] ) ? (int) $liveness['pending'] : 0;
			$chain   = ! empty( $liveness['chain_running'] );
			$last    = isset( $liveness['last_completion'] ) ? (int) $liveness['last_completion'] : 0;
			// Driver dead: work pending, no chain driving, and nothing has
			// completed recently (0 = never completed, or older than threshold).
			$no_recent_completion = ( 0 === $last ) || ( ( $now - $last ) > $threshold );

			if ( $pending > 0 && ! $chain && $no_recent_completion ) {
				return array(
					'stalled' => true,
					'reason'  => 'driver_dead',
				);
			}
			return $not_stalled;
		}

		return $not_stalled;
	}

	/**
	 * Hard-delete all rows in the failed status. Used by the "Clear failed
	 * list" admin action.
	 */
	public static function clear_failed_rows() {
		global $wpdb;
		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}slash_image_queue WHERE status = %s",
				self::STATUS_FAILED
			)
		);
	}

	/**
	 * Delete waiting rows whose source is in $sources. When $job_type is given,
	 * the delete is additionally scoped to that job_type — so a restore-run cancel
	 * (job_type=restore) can never nuke an optimize row and vice versa, while
	 * upload/manual rows of the other type are preserved by the source filter.
	 *
	 * @param string[]    $sources  Sources to match (sanitized).
	 * @param string|null $job_type Optional job_type scope.
	 * @return int Rows deleted.
	 */
	public static function delete_waiting_by_source( array $sources, $job_type = null ) {
		global $wpdb;
		$sources = array_filter( array_map( array( __CLASS__, 'sanitize_source' ), $sources ) );
		if ( empty( $sources ) ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $sources ), '%s' ) );
		$args         = array_merge( array( self::STATUS_WAITING ), $sources );
		$type_clause  = '';
		if ( null !== $job_type ) {
			$type_clause = ' AND job_type = %s';
			$args[]      = self::sanitize_job_type( $job_type );
		}

		// $placeholders is built from %s tokens only — safe to interpolate. The
		// literal $type_clause's %s is bound via prepare(), so UnescapedDBParameter
		// is a false positive here too.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$deleted = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}slash_image_queue
				  WHERE status = %s AND source IN ( {$placeholders} ){$type_clause}",
				$args
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $deleted;
	}

	/**
	 * Daily cleanup. Filters:
	 *   slash_image_queue_retention_days_done (default 30)
	 *   slash_image_queue_retention_days_failed (default 90)
	 */
	public static function purge_old() {
		global $wpdb;

		$done_days   = max( 1, (int) apply_filters( 'slash_image_queue_retention_days_done', 30 ) );
		$failed_days = max( 1, (int) apply_filters( 'slash_image_queue_retention_days_failed', 90 ) );

		$done_cutoff   = gmdate( 'Y-m-d H:i:s', time() - $done_days * DAY_IN_SECONDS );
		$failed_cutoff = gmdate( 'Y-m-d H:i:s', time() - $failed_days * DAY_IN_SECONDS );

		$deleted_done = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}slash_image_queue
				  WHERE status = %s
				    AND finished_at IS NOT NULL
				    AND finished_at < %s",
				self::STATUS_DONE,
				$done_cutoff
			)
		);

		$deleted_failed = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}slash_image_queue
				  WHERE status = %s
				    AND finished_at IS NOT NULL
				    AND finished_at < %s",
				self::STATUS_FAILED,
				$failed_cutoff
			)
		);

		return array(
			'done'   => $deleted_done,
			'failed' => $deleted_failed,
		);
	}

	private static function sanitize_source( $source ) {
		$source = (string) $source;
		$valid  = array( self::SOURCE_UPLOAD, self::SOURCE_BULK, self::SOURCE_MANUAL, self::SOURCE_RETRY );
		return in_array( $source, $valid, true ) ? $source : self::SOURCE_BULK;
	}

	private static function sanitize_job_type( $job_type ) {
		$job_type = (string) $job_type;
		$valid    = array( self::JOB_TYPE_OPTIMIZE, self::JOB_TYPE_RESTORE );
		return in_array( $job_type, $valid, true ) ? $job_type : self::JOB_TYPE_OPTIMIZE;
	}
}
