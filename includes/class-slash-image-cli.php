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
}
