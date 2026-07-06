<?php
/**
 * Cron-availability probe.
 *
 * Many managed hosts (WPEngine, Kinsta, SiteGround) define DISABLE_WP_CRON
 * but trigger wp-cron.php externally on a real cron schedule. The plugin
 * needs to know which is the case so the bulk page can decide whether to
 * fall back to the JS-driven AJAX driver.
 *
 * The probe schedules a one-shot event 60 seconds in the future. The
 * handler sets a transient. The caller reads the transient ~30 seconds
 * later and classifies the host.
 *
 * Result transient `slash_image_cron_status` holds one of:
 *   - 'wp_cron_active'    — DISABLE_WP_CRON is not set / false
 *   - 'real_cron_active'  — DISABLE_WP_CRON is true AND probe fired
 *   - 'no_cron'           — DISABLE_WP_CRON is true AND probe did NOT fire
 *   - 'probing'           — probe scheduled, result not yet known
 *   - 'unknown'           — schedule failed; treat as no_cron for safety
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Cron_Probe {

	const HOOK_PROBE       = 'slash_image_cron_probe';
	const TR_FIRED         = 'slash_image_cron_probe_fired';
	const TR_STATUS        = 'slash_image_cron_status';
	const TR_PROBE_STARTED = 'slash_image_cron_probe_started_at';
	const PROBE_DELAY_SEC  = 60;
	const PROBE_BUFFER_SEC = 30;

	public function __construct() {
		add_action( self::HOOK_PROBE, array( __CLASS__, 'on_probe' ) );
	}

	public static function status() {
		if ( ! self::cron_is_disabled() ) {
			set_transient( self::TR_STATUS, 'wp_cron_active', DAY_IN_SECONDS );
			return 'wp_cron_active';
		}

		$cached = get_transient( self::TR_STATUS );
		if ( false !== $cached ) {
			return $cached;
		}

		// No cached result — kick off a probe and return 'probing'. The caller
		// (the bulk page or admin notice) can re-check after ~90s.
		self::start_probe();
		return 'probing';
	}

	public static function start_probe() {
		if ( ! self::cron_is_disabled() ) {
			set_transient( self::TR_STATUS, 'wp_cron_active', DAY_IN_SECONDS );
			return 'wp_cron_active';
		}

		$existing = get_transient( self::TR_PROBE_STARTED );
		if ( false !== $existing ) {
			return 'probing';
		}

		delete_transient( self::TR_FIRED );
		set_transient( self::TR_PROBE_STARTED, time(), 5 * MINUTE_IN_SECONDS );

		$scheduled = wp_schedule_single_event( time() + self::PROBE_DELAY_SEC, self::HOOK_PROBE );
		if ( false === $scheduled ) {
			set_transient( self::TR_STATUS, 'unknown', DAY_IN_SECONDS );
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

		set_transient( self::TR_STATUS, $result, DAY_IN_SECONDS );
		delete_transient( self::TR_PROBE_STARTED );
		delete_transient( self::TR_FIRED );

		return $result;
	}

	public static function cron_is_disabled() {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}

	public static function reset() {
		delete_transient( self::TR_STATUS );
		delete_transient( self::TR_PROBE_STARTED );
		delete_transient( self::TR_FIRED );
	}
}
