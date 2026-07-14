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
			WP_CLI::error( 'An optimization run is in progress. Pause or cancel it on the Bulk Optimize page, then run this command again.' );
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
	 * alongside it.
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

		$ids = $all ? array() : array_values( array_unique( array_filter( array_map( 'intval', $args ) ) ) );

		// Measure how much of the target is ALREADY optimized before seeding, so a
		// --force run can report honestly. The worker re-optimizes only retry-sourced
		// rows (process_row derives force from source===retry), so already-optimized
		// images re-queued by --force are skipped downstream, not re-optimized.
		$force_already         = 0;
		$force_has_unoptimized = true;
		if ( $force ) {
			if ( $all ) {
				$counts                = Slash_Image_Bulk_Processor::library_counts();
				$force_already         = (int) ( $counts['optimized'] ?? 0 );
				$force_has_unoptimized = ( (int) ( $counts['not_optimized'] ?? 0 ) > 0 );
			} else {
				$force_has_unoptimized = false;
				foreach ( $ids as $target_id ) {
					$data = get_post_meta( $target_id, Slash_Image_Media_Handler::META_DATA_KEY, true );
					if ( is_array( $data ) && ! empty( $data['optimized'] ) ) {
						++$force_already;
					} else {
						$force_has_unoptimized = true;
					}
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

		$failed = (int) $progress['failed_count'];

		// Honest --force report: when every targeted image was already optimized,
		// nothing was re-optimized (the worker skipped the re-queued rows). Say so
		// plainly instead of a misleading "N processed".
		if ( $force && $force_already > 0 && ! $force_has_unoptimized && 0 === $failed ) {
			WP_CLI::warning(
				sprintf(
					'--force re-queued %d already-optimized image(s), but re-optimizing already-optimized images is not yet supported from the CLI, so they were skipped (no new optimization). Use the Media Library "Re-optimize" action, or run "wp slashimage restore <id>" then "wp slashimage optimize <id>", to re-optimize a specific image.',
					$force_already
				)
			);
			return;
		}

		$summary = sprintf( 'Optimize complete: %d processed, %d failed, %d deferred.', $done, $failed, $deferred );
		if ( $deferred > 0 ) {
			$summary .= sprintf( ' Re-run `wp slashimage optimize%s` later to finish the deferred image(s).', $all ? ' --all' : '' );
		}
		if ( $failed > 0 || $deferred > 0 ) {
			WP_CLI::warning( $summary );
		} else {
			WP_CLI::success( $summary );
		}
	}
}
