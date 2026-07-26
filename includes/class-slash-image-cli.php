<?php
/**
 * WP-CLI command surface for SlashImage: `wp slashimage <command>`.
 *
 * Registered under a `defined( 'WP_CLI' ) && WP_CLI` guard in the plugin
 * bootstrap (class-slash-image.php), so this file is only ever autoloaded when
 * running under WP-CLI — there is zero overhead on normal web / admin requests.
 *
 * Output is intentionally plain English (not wrapped in translation functions),
 * following the WordPress core convention that WP-CLI command output is not
 * localized. All human-facing messaging goes through WP_CLI::log / ::warning /
 * ::error, never error_log().
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inspect and (in later commands) drive SlashImage optimization from the shell.
 */
class Slash_Image_CLI {

	/**
	 * Show connection, queue, active-run, and library status.
	 *
	 * Read-only: this command never enqueues, ticks, or writes any plugin state.
	 * The run line is derived from the bulk session plus a queue count rather
	 * than from progress()/snapshot(), because progress() writes the session and
	 * schedules a cron event the first time a run reaches "completed" — which
	 * would violate the read-only contract.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Human-readable status table
	 *     $ wp slashimage status
	 *
	 *     # Machine-readable output for scripting
	 *     $ wp slashimage status --format=json
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (supports --format).
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		// Connection. current_status() is the cheap read — it rebuilds no
		// transient and schedules no refresh.
		$has_key    = Slash_Image_Connection::has_api_key();
		$connection = Slash_Image_Connection::current_status();

		// Queue: a single GROUP BY read over the queue table.
		$counts = Slash_Image_Queue::counts();

		// Active-run line, derived purely. count( queue() ) reproduces the private
		// bulk_pending_count() (same predicate), and decide_run_status() is a pure
		// resolver, so this matches progress()'s status without its write path.
		$session      = Slash_Image_Worker::get_session();
		$bulk_pending = count( Slash_Image_Bulk_Processor::queue() );
		$run_status   = Slash_Image_Bulk_Processor::decide_run_status(
			(string) ( $session['status'] ?? 'idle' ),
			! empty( $session['source_done'] ),
			$bulk_pending
		);

		$is_active  = in_array( $run_status, array( 'running', 'paused' ), true );
		$run_action = $is_active ? (string) ( $session['action'] ?? Slash_Image_Queue::JOB_TYPE_OPTIMIZE ) : '-';
		$run_total  = $is_active ? (int) ( $session['total_target'] ?? 0 ) : 0;
		$run_remain = $is_active ? $bulk_pending : 0;

		// Library counts from the short-TTL cached stats bundle (read-through
		// cache; one bounded aggregate on a cold cache, same path the admin
		// dashboard widget already uses).
		$library = Slash_Image_Bulk_Processor::library_counts();

		$rows = array(
			array(
				'field' => 'api_key_present',
				'value' => $has_key ? 'yes' : 'no',
			),
			array(
				'field' => 'connection_status',
				'value' => $connection,
			),
			array(
				'field' => 'queue_waiting',
				'value' => (int) ( $counts['waiting'] ?? 0 ),
			),
			array(
				'field' => 'queue_claimed',
				'value' => (int) ( $counts['claimed'] ?? 0 ),
			),
			array(
				'field' => 'queue_done',
				'value' => (int) ( $counts['done'] ?? 0 ),
			),
			array(
				'field' => 'queue_failed',
				'value' => (int) ( $counts['failed'] ?? 0 ),
			),
			array(
				'field' => 'run_status',
				'value' => $run_status,
			),
			array(
				'field' => 'run_action',
				'value' => $run_action,
			),
			array(
				'field' => 'run_total',
				'value' => $run_total,
			),
			array(
				'field' => 'run_remaining',
				'value' => $run_remain,
			),
			array(
				'field' => 'library_optimized',
				'value' => (int) ( $library['optimized'] ?? 0 ),
			),
			array(
				'field' => 'library_not_optimized',
				'value' => (int) ( $library['not_optimized'] ?? 0 ),
			),
			array(
				'field' => 'library_excluded',
				'value' => (int) ( $library['excluded'] ?? 0 ),
			),
			array(
				'field' => 'library_errors',
				'value' => (int) ( $library['errors'] ?? 0 ),
			),
		);

		\WP_CLI\Utils\format_items( $format, $rows, array( 'field', 'value' ) );
	}

	/**
	 * Restore optimized images to their backed-up originals.
	 *
	 * Runs synchronously in-process — one restore_attachment() call per id, no
	 * queue/worker. Restore is local (no API call) and works without an API key,
	 * so there is no no-key / invalid-key gate here. It DOES refuse while an
	 * optimize run is active, mirroring "Restore all" on the settings page.
	 *
	 * Each restore reverts the on-disk files from the backup and clears the
	 * attachment's optimized data. On a full restore the backup is consumed.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : One or more attachment IDs to restore. Omit when using --all.
	 *
	 * [--all]
	 * : Restore every attachment that has a backup (paged by ID cursor).
	 *
	 * [--yes]
	 * : Answer yes to the confirmation prompt shown for --all.
	 *
	 * ## EXAMPLES
	 *
	 *     # Restore two specific attachments
	 *     $ wp slashimage restore 217 218
	 *
	 *     # Restore everything with a backup, no prompt
	 *     $ wp slashimage restore --all --yes
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Attachment IDs (empty when --all is used).
	 * @param array $assoc_args Associative arguments (--all, --yes).
	 * @return void
	 */
	public function restore( $args, $assoc_args ) {
		$all = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false );
		$yes = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false );

		if ( $all && ! empty( $args ) ) {
			WP_CLI::error( 'Pass either attachment IDs or --all, not both.' );
		}
		if ( ! $all && empty( $args ) ) {
			WP_CLI::error( 'Specify one or more attachment IDs, or pass --all to restore every backed-up image.' );
		}

		// Refuse while an optimize run is active. Reproduces the private
		// Slash_Image_Bulk_Processor::active_run() predicate with public
		// primitives, mirroring start_restore()'s 'optimize_running' refusal.
		$session       = Slash_Image_Worker::get_session();
		$run_status    = Slash_Image_Bulk_Processor::decide_run_status(
			(string) ( $session['status'] ?? 'idle' ),
			! empty( $session['source_done'] ),
			count( Slash_Image_Bulk_Processor::queue() )
		);
		$active_action = in_array( $run_status, array( 'running', 'paused' ), true )
			? (string) ( $session['action'] ?? Slash_Image_Queue::JOB_TYPE_OPTIMIZE )
			: '';
		if ( Slash_Image_Queue::JOB_TYPE_OPTIMIZE === $active_action ) {
			WP_CLI::error( 'An optimization run is in progress. Run "wp slashimage cancel" to stop it (or pause/cancel it on the Bulk Optimize page), then run this command again.' );
		}

		// Resolve the target IDs. For --all, page the backed-up set through the
		// ID cursor into a stable snapshot BEFORE restoring — restore deletes the
		// backup meta, so a snapshot avoids cursor drift and gives an exact
		// progress total. next_backed_up_ids() returns only IDs greater than the
		// cursor, so an empty page is the sole exit.
		if ( $all ) {
			$ids    = array();
			$cursor = 0;
			$chunk  = 200;
			while ( true ) {
				$page = Slash_Image_Restore::next_backed_up_ids( $cursor, $chunk );
				if ( empty( $page ) ) {
					break;
				}
				foreach ( $page as $pid ) {
					$ids[] = (int) $pid;
				}
				$cursor = (int) max( $page );
			}
		} else {
			$ids = array_values( array_unique( array_filter( array_map( 'intval', $args ) ) ) );
		}

		if ( empty( $ids ) ) {
			WP_CLI::success( 'Nothing to restore — no backed-up images found.' );
			return;
		}

		if ( $all && ! $yes ) {
			WP_CLI::confirm( sprintf( 'Restore %d backed-up image(s) to their originals? This clears their optimized data.', count( $ids ) ) );
		}

		$restored = 0;
		$skipped  = 0;
		$failed   = 0;

		$progress = \WP_CLI\Utils\make_progress_bar( 'Restoring', count( $ids ) );
		foreach ( $ids as $id ) {
			$result = Slash_Image_Restore::restore_attachment( $id );
			if ( ! empty( $result['ok'] ) ) {
				++$restored;
			} elseif ( 'no_backup' === (string) ( $result['code'] ?? '' ) ) {
				++$skipped;
				WP_CLI::warning( sprintf( 'Attachment %d: no backup found — skipped.', $id ) );
			} else {
				++$failed;
				WP_CLI::warning( sprintf( 'Attachment %d: restore failed (%s).', $id, (string) ( $result['code'] ?? 'unknown' ) ) );
			}
			$progress->tick();
		}
		$progress->finish();

		$summary = sprintf( 'Restore complete: %d restored, %d skipped (no backup), %d failed.', $restored, $skipped, $failed );
		if ( $failed > 0 ) {
			WP_CLI::warning( $summary );
		} else {
			WP_CLI::success( $summary );
		}
	}

	/**
	 * Optimize images through the SlashImage API, synchronously in the foreground.
	 *
	 * Seeds the shared queue with background scheduling suppressed, then drives
	 * the worker in-process to completion — so the whole run happens inside this
	 * one command with a live progress bar, with no cron / loopback chain running
	 * alongside it. Refuses to start while an optimize or restore run is already
	 * active ("wp slashimage cancel" clears a stuck run). Exits non-zero when any
	 * image in this run fails.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : One or more attachment IDs to optimize. Omit when using --all.
	 *
	 * [--all]
	 * : Optimize every eligible attachment in the library.
	 *
	 * [--force]
	 * : Re-queue images that are already optimized (drops the skip-already-optimized filter). NOTE: the worker currently re-optimizes only images queued via retry, so already-optimized images are re-queued and then skipped, not re-optimized. To re-optimize a specific image, use the Media Library "Re-optimize" action, or restore it first with "wp slashimage restore" and then run optimize on that ID.
	 *
	 * ## EXAMPLES
	 *
	 *     # Optimize two specific attachments
	 *     $ wp slashimage optimize 217 218
	 *
	 *     # Optimize the whole library
	 *     $ wp slashimage optimize --all
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Attachment IDs (empty when --all is used).
	 * @param array $assoc_args Associative arguments (--all, --force).
	 * @return void
	 */
	public function optimize( $args, $assoc_args ) {
		$all   = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false );
		$force = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );

		if ( $all && ! empty( $args ) ) {
			WP_CLI::error( 'Pass either attachment IDs or --all, not both.' );
		}
		if ( ! $all && empty( $args ) ) {
			WP_CLI::error( 'Specify one or more attachment IDs, or pass --all to optimize the whole library.' );
		}

		// No-key / dead-key gates, up front — never enqueue dead work or spin on a
		// no-op tick. (Optimize needs the API; restore does not, hence no gate there.)
		if ( ! Slash_Image_Connection::has_api_key() ) {
			WP_CLI::error( 'No API key configured. Add one on the SlashImage settings page first.' );
		}
		if ( 'invalid' === Slash_Image_Connection::current_status() ) {
			WP_CLI::error( 'The configured API key is invalid or was revoked. Reconnect it on the settings page first.' );
		}

		// Mutual exclusion: refuse while an optimize run is already active —
		// seeding would clobber the live session's total/started_at. Same
		// derived-status read restore/cancel use. A crashed or killed driver can
		// leave a stale 'running' session behind, so the message always carries
		// the escape hatch instead of dead-ending the CLI. (A restore run is
		// caught downstream by start()'s existing restore_running refusal.)
		$session       = Slash_Image_Worker::get_session();
		$run_status    = Slash_Image_Bulk_Processor::decide_run_status(
			(string) ( $session['status'] ?? 'idle' ),
			! empty( $session['source_done'] ),
			count( Slash_Image_Bulk_Processor::queue() )
		);
		$active_action = in_array( $run_status, array( 'running', 'paused' ), true )
			? (string) ( $session['action'] ?? Slash_Image_Queue::JOB_TYPE_OPTIMIZE )
			: '';
		if ( Slash_Image_Queue::JOB_TYPE_OPTIMIZE === $active_action ) {
			$started_at = (int) ( $session['started_at'] ?? 0 );
			$started    = $started_at > 0 ? sprintf( 'started %s ago', human_time_diff( $started_at ) ) : 'start time unknown';
			WP_CLI::error(
				sprintf(
					'An optimization run is already in progress (%s). Wait for it to finish, or run "wp slashimage cancel" to stop it.',
					$started
				)
			);
		}

		$ids = $all ? array() : array_values( array_unique( array_filter( array_map( 'intval', $args ) ) ) );

		// A forced run restores each already-optimized image from its backup before
		// re-optimizing, so an optimized image with NO backup cannot be returned to
		// source and is skipped (worker: prepare_forced_reoptimize → 'no_backup').
		// Warn up front when EVERY targeted image is in that state, since the run
		// would otherwise finish reporting 0 processed with no explanation. The
		// authoritative per-run count comes from the completion codes after the
		// drain, not from this estimate.
		$force_unbacked   = 0;
		$force_actionable = true;
		if ( $force && ! $all ) {
			$force_actionable = false;
			foreach ( $ids as $target_id ) {
				if ( self::is_optimized_without_backup( $target_id ) ) {
					++$force_unbacked;
				} else {
					$force_actionable = true;
				}
			}
		}

		// Seed the queue with background scheduling suppressed ($schedule = false):
		// this command is the sole driver, so no cron / loopback chain runs beside it.
		$snapshot = $all
			? Slash_Image_Bulk_Processor::start( $force, false )
			: Slash_Image_Bulk_Processor::start_with_ids( $ids, $force, false );

		// Surface any refusal code (restore-run mutual exclusion, or a no-key race).
		if ( ! empty( $snapshot['refused'] ) ) {
			$refused = (string) $snapshot['refused'];
			if ( 'restore_running' === $refused ) {
				WP_CLI::error( 'A restore run is in progress. Wait for it to finish, then run this command again.' );
			}
			if ( 'no_key' === $refused ) {
				WP_CLI::error( 'No API key configured. Add one on the SlashImage settings page first.' );
			}
			WP_CLI::error( sprintf( 'Could not start optimization (%s).', $refused ) );
		}

		// Run-scoped failure baseline, taken right AFTER seeding: enqueue()
		// clears an attachment's prior terminal failed row when re-queueing it,
		// so the post-seed count is the true zero point for THIS run's failures.
		// Pre-existing failed rows from earlier runs never inflate this run's
		// summary or its exit code.
		$failed_baseline = (int) ( Slash_Image_Queue::counts()['failed'] ?? 0 );

		// Same run-scoping for no-backup skips: the code count is queue-global, so
		// baseline it here and diff after the drain. This is the authoritative
		// figure reported in the summary — derived from what the run actually did,
		// not from the pre-seed estimate above.
		$no_backup_baseline = Slash_Image_Queue::count_done_with_code( 'no_backup' );

		// Nothing eligible → clean exit (e.g. --all with everything already
		// optimized and no --force). start*() leaves status != 'running' here.
		$progress = Slash_Image_Bulk_Processor::progress();
		if ( 'running' !== (string) $progress['status'] ) {
			WP_CLI::success( 'Nothing to optimize — no eligible images found.' );
			return;
		}

		// Foreground drain: each iteration runs one full-concurrency worker tick
		// (feed + drain), then reads the run's progress. Loop until 'completed'.
		$bar      = \WP_CLI\Utils\make_progress_bar( 'Optimizing', (int) $progress['total'] );
		$done     = 0;
		$idle     = 0;
		$deferred = 0;

		while ( true ) {
			$tick = Slash_Image_Worker::tick();

			// Dead key mid-run — stop rather than spin (the tick claimed nothing).
			if ( ! empty( $tick['skipped_invalid'] ) ) {
				$bar->finish();
				WP_CLI::error( 'The API key became invalid during the run. Reconnect it, then re-run this command.' );
			}

			$progress = Slash_Image_Bulk_Processor::progress();
			$delta    = max( 0, (int) $progress['processed'] - $done );
			for ( $i = 0; $i < $delta; $i++ ) {
				$bar->tick();
			}
			$done = (int) $progress['processed'];

			if ( 'completed' === (string) $progress['status'] ) {
				break;
			}

			// Advancement = this tick claimed a row OR the run's processed count
			// climbed. The second clause matters on a WP-Cron-active host, where a
			// cron-spawned loopback chain may drain the shared queue alongside this
			// command (safe via the atomic claim); the run still advances even when
			// our own tick claimed nothing, so we must not count that as idle.
			$advanced = ( (int) ( $tick['claimed'] ?? 0 ) > 0 ) || ( $delta > 0 );
			$idle     = $advanced ? 0 : ( $idle + 1 );

			// Stuck guard: two consecutive non-advancing ticks with work still
			// waiting mean the remaining rows are backoff-deferred (available_at in
			// the future) and nothing can advance them now — stop cleanly and let a
			// later run pick them up. (Reported as "deferred", not a failure.)
			if ( $idle >= 2 ) {
				$deferred = (int) ( Slash_Image_Queue::counts()['waiting'] ?? 0 );
				break;
			}
		}

		$bar->finish();

		// This run's failures only: progress()['failed_count'] is queue-GLOBAL
		// (Slash_Image_Queue::counts()['failed']), so subtract the post-seed
		// baseline. max( 0, … ) guards the concurrent-cleanup edge.
		$failed = max( 0, (int) $progress['failed_count'] - $failed_baseline );

		// Run-scoped no-backup skips, from the completion codes.
		$no_backup = max( 0, Slash_Image_Queue::count_done_with_code( 'no_backup' ) - $no_backup_baseline );

		// Every targeted image was already optimized with no backup to revert to,
		// so the run had nothing it could act on. Say so plainly rather than
		// reporting a bare "0 processed".
		if ( $force && $force_unbacked > 0 && ! $force_actionable && 0 === $failed ) {
			WP_CLI::warning(
				sprintf(
					'Skipped %d already-optimized image(s): re-optimizing restores the original from backup first, and no backup exists for them. Enable "Keep backup of original images" in SlashImage settings before optimizing if you want to be able to re-optimize later.',
					$force_unbacked
				)
			);
			return;
		}

		$summary = sprintf( 'Optimize complete: %d processed, %d failed, %d deferred.', $done, $failed, $deferred );
		if ( $no_backup > 0 ) {
			// Phrased as a subset of $done on purpose: progress()['processed']
			// counts every completed bulk row regardless of its terminal code
			// (the same is true of 'excluded' and 'not_processable_format'
			// skips), so these rows are already inside that figure. Reporting
			// them as a separate addend would imply a larger run than happened.
			$summary .= sprintf(
				' Of those, %d skipped (no backup): already optimized with no backup to restore from, so they could not be re-optimized.',
				$no_backup
			);
		}
		if ( $deferred > 0 ) {
			// Accurate on both drive paths: the worker cron retries deferred rows
			// on its own once their backoff passes, and a straight CLI re-run would
			// be refused by the active-run guard (the session is still 'running'
			// with rows pending) — hence the cancel-then-re-run recipe.
			$summary .= sprintf(
				' The background worker retries deferred image(s) automatically once their backoff passes. To retry from the CLI instead, run `wp slashimage cancel` and then `wp slashimage optimize%s` — cancel drops the deferred rows and the fresh run re-seeds them (they are still unoptimized).',
				$all ? ' --all' : ''
			);
		}
		if ( $failed > 0 ) {
			// Real failures in this run → non-zero exit so scripts can detect it.
			WP_CLI::error( $summary );
		} elseif ( $deferred > 0 ) {
			// Deferred-only = backoff, re-run later — not a failure; exit 0.
			WP_CLI::warning( $summary );
		} else {
			WP_CLI::success( $summary );
		}
	}

	/**
	 * Cancel the active optimize or restore run.
	 *
	 * Drops the run's still-waiting queued images and returns the session to idle.
	 * Non-destructive: only waiting queue rows (which can be re-queued) are removed
	 * — no files, backups, already-optimized data, completed rows, or an image
	 * already in progress are touched. Local operation; no API key required.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp slashimage cancel
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (unused).
	 * @return void
	 */
	public function cancel( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- WP-CLI invokes every command with ( $args, $assoc_args ); cancel needs neither.
		$session    = Slash_Image_Worker::get_session();
		$run_status = Slash_Image_Bulk_Processor::decide_run_status(
			(string) ( $session['status'] ?? 'idle' ),
			! empty( $session['source_done'] ),
			count( Slash_Image_Bulk_Processor::queue() )
		);

		if ( ! in_array( $run_status, array( 'running', 'paused' ), true ) ) {
			WP_CLI::warning( 'No optimization or restore run is active — nothing to cancel.' );
			return;
		}

		$action  = (string) ( $session['action'] ?? Slash_Image_Queue::JOB_TYPE_OPTIMIZE );
		$waiting = (int) ( Slash_Image_Queue::counts()['waiting'] ?? 0 );

		WP_CLI::log( sprintf( 'Cancelling the active %s run — dropping %d waiting queued image(s).', $action, $waiting ) );

		Slash_Image_Bulk_Processor::cancel();

		WP_CLI::success( sprintf( 'Cancelled the %s run. Waiting images were dropped; any image already in progress finishes, and completed work is kept.', $action ) );
	}

	/**
	 * True when an attachment is already optimized but carries no backup record —
	 * the state a forced re-optimize cannot act on, because it restores from
	 * backup first rather than re-compressing compressed bytes.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return bool
	 */
	private static function is_optimized_without_backup( $attachment_id ) {
		$data = get_post_meta( $attachment_id, Slash_Image_Media_Handler::META_DATA_KEY, true );
		if ( ! is_array( $data ) || empty( $data['optimized'] ) ) {
			return false;
		}

		$backup = get_post_meta( $attachment_id, Slash_Image_Restore::BACKUP_META_KEY, true );
		return ! is_array( $backup ) || empty( $backup['sizes'] );
	}

	/**
	 * Import another image optimizer's state so its work counts as SlashImage's.
	 *
	 * Reads the other plugin's optimization records, writes the equivalent
	 * SlashImage metadata, and claims its WebP/AVIF sibling files for our
	 * <picture> rewriter by hardlinking them at the filenames we look for.
	 *
	 * Read-only with respect to the source plugin: its tables, settings, files
	 * and backups are never written, moved, or deleted. Makes no API calls, so
	 * it works without an API key configured.
	 *
	 * Safe to re-run: attachments already migrated, or already optimized by
	 * SlashImage, are skipped rather than overwritten.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Which optimizer to import from. Currently: shortpixel
	 *
	 * [--dry-run]
	 * : Scan and report what would be imported, writing nothing.
	 *
	 * [--batch-size=<n>]
	 * : Attachments examined per batch. Default 200.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp slashimage migrate shortpixel --dry-run
	 *     $ wp slashimage migrate shortpixel
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments; [0] is the source slug.
	 * @param array $assoc_args Associative arguments (--dry-run, --batch-size).
	 * @return void
	 */
	public function migrate( $args, $assoc_args ) {
		$source  = isset( $args[0] ) ? (string) $args[0] : '';
		$dry_run = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$batch   = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'batch-size', Slash_Image_Migrate::DEFAULT_BATCH_SIZE );

		if ( '' === $source ) {
			WP_CLI::error( sprintf( 'Specify a migration source. Available: %s.', implode( ', ', array_keys( Slash_Image_Migrate::adapters() ) ) ) );
		}

		$adapter = Slash_Image_Migrate::adapter_for( $source );
		if ( '' === $adapter ) {
			WP_CLI::error( sprintf( 'Unknown migration source "%s". Available: %s.', $source, implode( ', ', array_keys( Slash_Image_Migrate::adapters() ) ) ) );
		}

		$label = call_user_func( array( $adapter, 'label' ) );

		// Global refusals (multisite) and source detection run before any work,
		// so a refusal never leaves a partial import behind.
		$pre = Slash_Image_Migrate::preflight();
		if ( empty( $pre['ok'] ) ) {
			WP_CLI::error( $pre['message'] );
		}
		$detect = call_user_func( array( $adapter, 'detect' ) );
		if ( empty( $detect['ok'] ) ) {
			// An empty source is not an error: nothing to do is a clean outcome.
			if ( 'empty' === (string) ( $detect['code'] ?? '' ) ) {
				WP_CLI::success( (string) $detect['message'] );
				return;
			}
			WP_CLI::error( (string) $detect['message'] );
		}

		$total = (int) call_user_func( array( $adapter, 'count' ) );
		WP_CLI::log( sprintf( 'Scanning %s data: %d attachment(s) with optimization records.', $label, $total ) );

		$bar    = \WP_CLI\Utils\make_progress_bar( $dry_run ? 'Scanning' : 'Migrating', $total );
		$result = Slash_Image_Migrate::run(
			$source,
			$dry_run,
			$batch,
			function ( $scanned ) use ( $bar ) {
				for ( $i = 0; $i < (int) $scanned; $i++ ) {
					$bar->tick();
				}
			}
		);
		$bar->finish();

		if ( empty( $result['ok'] ) ) {
			WP_CLI::error( (string) $result['message'] );
		}

		self::report_migration( $result['stats'], $label, $dry_run );
	}

	/**
	 * Print a migration report. Shared by the dry-run and real paths so the two
	 * can never drift.
	 *
	 * @param array  $s       Accumulated stats.
	 * @param string $label   Source label.
	 * @param bool   $dry_run Whether this was a scan.
	 * @return void
	 */
	private static function report_migration( array $s, $label, $dry_run ) {
		WP_CLI::log( '' );
		WP_CLI::log( $dry_run ? sprintf( 'Would import from %s:', $label ) : sprintf( 'Imported from %s:', $label ) );
		WP_CLI::log( sprintf( '  %-36s %d', 'Importable attachments:', $s['migrated'] ) );
		WP_CLI::log( sprintf( '  %-36s %d', 'Already optimized by SlashImage:', $s['already_ours'] ) );
		WP_CLI::log( sprintf( '  %-36s %d', 'Already migrated:', $s['already_migrated'] ) );
		WP_CLI::log(
			sprintf(
				'  %-36s %d (of which reverted in %s: %d)',
				'Skipped, not an importable status:',
				$s['skipped_status'],
				$label,
				$s['skipped_restored']
			)
		);
		WP_CLI::log( sprintf( '  %-36s %d', 'Skipped, unsupported type:', $s['skipped_unsupported_mime'] ) );
		WP_CLI::log( sprintf( '  %-36s %d', 'Skipped, file missing on disk:', $s['skipped_file_missing'] ) );
		WP_CLI::log( sprintf( '  %-36s %d', 'Skipped, no usable records:', $s['skipped_no_usable_rows'] ) );

		WP_CLI::log( '' );
		WP_CLI::log( 'Next-gen siblings:' );
		$verb = $dry_run ? 'to link' : 'linked';
		WP_CLI::log( sprintf( '  %-36s %d', 'WebP ' . $verb . ':', $s['webp_linked'] ) );
		WP_CLI::log( sprintf( '  %-36s %d', 'AVIF ' . $verb . ':', $s['avif_linked'] ) );
		WP_CLI::log( sprintf( '  %-36s %d', 'Already present (left alone):', $s['webp_already_present'] + $s['avif_already_present'] ) );
		WP_CLI::log( sprintf( '  %-36s %d', 'Recorded but missing on disk:', $s['webp_missing'] + $s['avif_missing'] ) );
		WP_CLI::log( sprintf( '  %-36s %d', 'Skipped, conversion was larger:', $s['sentinel_skipped'] ) );
		if ( $s['link_failed'] > 0 ) {
			WP_CLI::log( sprintf( '  %-36s %d', 'Could not be linked or copied:', $s['link_failed'] ) );
		}
		WP_CLI::log( '' );

		if ( $dry_run ) {
			WP_CLI::success( 'Dry run complete — nothing was written.' );
			return;
		}

		if ( $s['link_failed'] > 0 ) {
			WP_CLI::warning(
				sprintf(
					'Imported %d attachment(s), but %d sibling file(s) could not be linked or copied. Those images keep their imported savings but will not be served in a next-gen format until re-optimized.',
					$s['migrated'],
					$s['link_failed']
				)
			);
			return;
		}

		WP_CLI::success(
			sprintf(
				'Imported %d attachment(s) from %s. Linked %d WebP and %d AVIF sibling file(s).',
				$s['migrated'],
				$label,
				$s['webp_linked'],
				$s['avif_linked']
			)
		);
	}
}
