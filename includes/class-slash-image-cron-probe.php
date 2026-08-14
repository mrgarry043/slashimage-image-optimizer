<?php
/**
 * Cron-availability probe.
 *
 * Many managed hosts (WPEngine, Kinsta, SiteGround) define DISABLE_WP_CRON
 * but trigger wp-cron.php externally on a real cron schedule. The plugin
 * needs to know which is the case so the bulk page can decide whether to
 * show the "keep this tab open" banner. Classification only — the AJAX
 * worker driver is NOT gated on it and runs on every host.
 *
 * Two signals, in order of authority:
 *
 * 1. Heartbeat (primary). `Slash_Image_Worker::cron_tick()` stamps
 *    `slash_image_cron_last_run` on every fire. A stamp inside
 *    HEARTBEAT_WINDOW_SEC is direct proof the host's cron runs, so it
 *    classifies `real_cron_active` outright — nothing is scheduled and
 *    nothing is waited on.
 * 2. One-shot probe (cold start only). Reached when the heartbeat is
 *    absent or stale: a fresh install whose worker cron has not fired
 *    yet, or a host where it genuinely never fires. Schedules an event
 *    PROBE_DELAY_SEC out and classifies PROBE_BUFFER_SEC after it comes
 *    due. That buffer is 90 s, not 30: an external cron runs on minute
 *    boundaries, so an event due at T+60 fires anywhere in [T+60, T+120]
 *    and a tighter deadline calls a healthy host `no_cron`.
 *
 * Result transient `slash_image_cron_status` holds one of:
 *   - 'wp_cron_active'    — DISABLE_WP_CRON is not set / false
 *   - 'real_cron_active'  — DISABLE_WP_CRON is true AND cron demonstrably runs
 *   - 'no_cron'           — DISABLE_WP_CRON is true AND nothing fired
 *   - 'probing'           — probe scheduled, result not yet known
 *   - 'unknown'           — schedule failed; treat as no_cron for safety
 *
 * Verdict TTLs are asymmetric on purpose. A healthy verdict caches for a
 * day; 'no_cron' and 'unknown' cache for FAILURE_TTL_SEC, so a host that
 * starts working — or a probe that simply lost a race — re-checks within
 * minutes instead of showing a stale banner until tomorrow.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Cron_Probe {

	const HOOK_PROBE           = 'slash_image_cron_probe';
	const TR_FIRED             = 'slash_image_cron_probe_fired';
	const TR_STATUS            = 'slash_image_cron_status';
	const TR_PROBE_STARTED     = 'slash_image_cron_probe_started_at';
	const OPT_LAST_CRON_RUN    = 'slash_image_cron_last_run';
	const PROBE_DELAY_SEC      = 60;
	const PROBE_BUFFER_SEC     = 90;
	const HEARTBEAT_WINDOW_SEC = 300;
	const FAILURE_TTL_SEC      = 300;

	public function __construct() {
		add_action( self::HOOK_PROBE, array( __CLASS__, 'on_probe' ) );
	}

	/**
	 * Record a cron fire. Called as the first statement of
	 * Slash_Image_Worker::cron_tick(), ahead of its chain guard, so it stamps
	 * even on a tick that defers to a running loopback chain — the fact being
	 * recorded is that WP-Cron fired the event, not that the tick did work.
	 *
	 * An option rather than a transient: it must survive an object-cache
	 * flush, and it carries no expiry semantics of its own (freshness is
	 * decided at read time against HEARTBEAT_WINDOW_SEC). Not autoloaded —
	 * only the bulk page and this class read it.
	 */
	public static function record_cron_run() {
		update_option( self::OPT_LAST_CRON_RUN, time(), false );
	}

	/**
	 * Whether a cron fire was recorded inside the freshness window. The worker
	 * cron runs every minute, so a 300 s window absorbs several missed fires
	 * before the host reads as dead.
	 */
	public static function heartbeat_is_fresh() {
		$last = (int) get_option( self::OPT_LAST_CRON_RUN, 0 );
		if ( $last <= 0 ) {
			return false;
		}
		return ( time() - $last ) <= self::HEARTBEAT_WINDOW_SEC;
	}

	/**
	 * Heartbeat short-circuit shared by status(), start_probe() and
	 * evaluate(): a fresh stamp settles the question outright, so no caller
	 * schedules or waits on a probe. Returns '' when it has nothing to say.
	 */
	private static function heartbeat_verdict() {
		if ( ! self::heartbeat_is_fresh() ) {
			return '';
		}
		set_transient( self::TR_STATUS, 'real_cron_active', DAY_IN_SECONDS );
		return 'real_cron_active';
	}

	public static function status() {
		if ( ! self::cron_is_disabled() ) {
			set_transient( self::TR_STATUS, 'wp_cron_active', DAY_IN_SECONDS );
			return 'wp_cron_active';
		}

		$heartbeat = self::heartbeat_verdict();
		if ( '' !== $heartbeat ) {
			return $heartbeat;
		}

		$cached = get_transient( self::TR_STATUS );
		if ( false !== $cached ) {
			return $cached;
		}

		// No heartbeat and no cached result — cold start. Kick off a probe and
		// return 'probing'. The caller (the bulk page or admin notice) can
		// re-check after PROBE_DELAY_SEC + PROBE_BUFFER_SEC.
		self::start_probe();
		return 'probing';
	}

	public static function start_probe() {
		if ( ! self::cron_is_disabled() ) {
			set_transient( self::TR_STATUS, 'wp_cron_active', DAY_IN_SECONDS );
			return 'wp_cron_active';
		}

		// A live heartbeat already answers the question, so don't schedule a
		// probe for it — and don't let ajax_start()'s reset-then-re-probe throw
		// a correct answer away on a host that is demonstrably running cron.
		$heartbeat = self::heartbeat_verdict();
		if ( '' !== $heartbeat ) {
			return $heartbeat;
		}

		$existing = get_transient( self::TR_PROBE_STARTED );
		if ( false !== $existing ) {
			return 'probing';
		}

		delete_transient( self::TR_FIRED );
		set_transient( self::TR_PROBE_STARTED, time(), 5 * MINUTE_IN_SECONDS );

		$scheduled = wp_schedule_single_event( time() + self::PROBE_DELAY_SEC, self::HOOK_PROBE );
		if ( false === $scheduled ) {
			set_transient( self::TR_STATUS, 'unknown', self::FAILURE_TTL_SEC );
			delete_transient( self::TR_PROBE_STARTED );
			return 'unknown';
		}
		return 'probing';
	}

	public static function on_probe() {
		set_transient( self::TR_FIRED, time(), 5 * MINUTE_IN_SECONDS );
	}

	public static function evaluate() {
		if ( ! self::cron_is_disabled() ) {
			set_transient( self::TR_STATUS, 'wp_cron_active', DAY_IN_SECONDS );
			delete_transient( self::TR_PROBE_STARTED );
			return 'wp_cron_active';
		}

		// Ahead of any cached verdict, so a host that starts running cron
		// self-heals on the next page load instead of serving out a cached
		// 'no_cron'.
		$heartbeat = self::heartbeat_verdict();
		if ( '' !== $heartbeat ) {
			return $heartbeat;
		}

		$started = get_transient( self::TR_PROBE_STARTED );
		if ( false === $started ) {
			return self::status();
		}

		$age = time() - (int) $started;
		if ( $age < self::PROBE_DELAY_SEC + self::PROBE_BUFFER_SEC ) {
			return 'probing';
		}

		$fired  = (bool) get_transient( self::TR_FIRED );
		$result = $fired ? 'real_cron_active' : 'no_cron';

		// A failure verdict is the one most likely to be wrong (a slow host, a
		// probe that lost the minute-boundary race), so it expires in minutes
		// rather than a day.
		$ttl = ( 'real_cron_active' === $result ) ? DAY_IN_SECONDS : self::FAILURE_TTL_SEC;

		set_transient( self::TR_STATUS, $result, $ttl );
		delete_transient( self::TR_PROBE_STARTED );
		delete_transient( self::TR_FIRED );

		return $result;
	}

	public static function cron_is_disabled() {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}

	public static function reset() {
		// Unschedule BEFORE clearing the transients. WP treats an identical
		// pending event as a duplicate — and for a new event less than ten
		// minutes out, every past one counts too (wp-includes/cron.php) — so
		// leaving the old probe queued makes the next
		// wp_schedule_single_event() return false and cache a bogus 'unknown'.
		wp_clear_scheduled_hook( self::HOOK_PROBE );
		delete_transient( self::TR_STATUS );
		delete_transient( self::TR_PROBE_STARTED );
		delete_transient( self::TR_FIRED );
	}
}
