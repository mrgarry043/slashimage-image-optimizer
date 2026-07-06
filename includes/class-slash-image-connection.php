<?php
/**
 * Connection-state helper. Wraps the slash_image_connection_state transient
 * so the admin-notice, the settings page, and the API client all read from
 * one source of truth.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Connection {

	const TRANSIENT_KEY = 'slash_image_connection_state';
	const TTL_SECONDS   = DAY_IN_SECONDS;

	// Plan/usage cache (from GET /v1/keys/me). The transient is the value; the
	// companion option records when it was last refreshed so the rebuild path can
	// decide whether to fire a background refresh.
	const PLAN_CACHE_TRANSIENT     = 'slash_image_plan_cache';
	const PLAN_CACHE_TTL           = 25 * HOUR_IN_SECONDS;
	const PLAN_REFRESHED_AT_OPTION = 'slash_image_plan_cache_refreshed_at';
	const PLAN_REFRESH_AFTER       = 23 * HOUR_IN_SECONDS;
	const DAILY_SYNC_HOOK          = 'slash_image_daily_sync';

	// Key-invalidation: set when a previously-connected key is confirmed dead
	// (genuine invalid_key only — never domain codes). 0/absent = valid. The
	// stored key is deliberately kept so the UI can show the masked fingerprint
	// and a reconnect prompt.
	const KEY_INVALID_AT_OPTION = 'slash_image_key_invalid_at';

	// Settings-page synchronous re-probe (decision A): throttle so it is at most
	// one /v1/keys/me call per ~10 min, with a short timeout so the page never
	// hangs on a slow API.
	const KEY_RECHECK_TRANSIENT = 'slash_image_key_recheck';
	const KEY_RECHECK_THROTTLE  = 10 * MINUTE_IN_SECONDS;
	const KEY_RECHECK_TIMEOUT   = 5;

	/**
	 * Pure: derive the 3-state key status from its inputs (unit-testable).
	 *
	 * @param string $api_key     The stored API key ('' when none).
	 * @param int    $verified_at Unix ts of last successful Connect/verify (0 = never).
	 * @param int    $invalid_at  Unix ts the key was confirmed dead (0 = valid).
	 * @return string 'connected' | 'invalid' | 'disconnected'.
	 */
	public static function derive_status( $api_key, $verified_at, $invalid_at ) {
		if ( '' === (string) $api_key || (int) $verified_at <= 0 ) {
			return 'disconnected';
		}
		if ( (int) $invalid_at > 0 ) {
			return 'invalid';
		}
		return 'connected';
	}

	/**
	 * Pure: the DISCRIMINATOR. Only the API's genuine `invalid_key` flips status.
	 * Domain codes (origin_required / domain_not_allowed / domain_blocked), which
	 * the plugin collapses into internal invalid_key, and every other code must
	 * NOT flip — so this checks the RAW API `code`, not the mapped plugin code.
	 *
	 * @param string $api_code Raw API `code` field from the failure body.
	 * @return bool
	 */
	public static function is_key_dead_code( $api_code ) {
		return 'invalid_key' === (string) $api_code;
	}

	/**
	 * Whether an API key is configured — PRESENCE only, deliberately decoupled
	 * from verification status. This is the optimization gate: no key → no work
	 * (auto-optimize-on-upload, manual Optimize, Bulk Optimize all check it, and
	 * the worker uses it as a no-key safety net).
	 *
	 * Presence (not current_status() === 'connected') is intentional: a valid
	 * domain-restricted sub-key 401s on /v1/keys/me (so it never verifies and
	 * reads as 'disconnected') yet optimizes fine — gating on presence keeps it
	 * working. A genuinely dead key is still caught downstream by the invalid-key
	 * path (current_status() === 'invalid' → Slash_Image_Worker::should_skip_tick).
	 *
	 * @return bool
	 */
	public static function has_api_key() {
		return '' !== (string) Slash_Image_Settings::get( 'api_key', '' );
	}

	/**
	 * Cheap current status read (no transient rebuild / no plan-refresh
	 * scheduling) for hot paths like the worker tick gate.
	 *
	 * @return string 'connected' | 'invalid' | 'disconnected'.
	 */
	public static function current_status() {
		return self::derive_status(
			(string) Slash_Image_Settings::get( 'api_key', '' ),
			(int) get_option( 'slash_image_key_verified_at', 0 ),
			(int) get_option( self::KEY_INVALID_AT_OPTION, 0 )
		);
	}

	public static function snapshot() {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		return self::rebuild();
	}

	public static function rebuild() {
		$api_key     = (string) Slash_Image_Settings::get( 'api_key', '' );
		$verified_at = (int) get_option( 'slash_image_key_verified_at', 0 );
		$invalid_at  = (int) get_option( self::KEY_INVALID_AT_OPTION, 0 );
		$status      = self::derive_status( $api_key, $verified_at, $invalid_at );

		$state = array(
			// Back-compat boolean: true ONLY for a healthy connection.
			'connected'   => ( 'connected' === $status ),
			'status'      => $status,
			'invalid'     => ( 'invalid' === $status ),
			'fingerprint' => self::fingerprint( $api_key ),
			'verified_at' => $verified_at,
			'invalid_at'  => $invalid_at,
		);

		set_transient( self::TRANSIENT_KEY, $state, self::TTL_SECONDS );

		// Opportunistically refresh stale plan data in the background.
		self::maybe_refresh_plan_cache();

		return $state;
	}

	public static function invalidate() {
		delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * Flip a previously-connected key to "invalid" (confirmed dead). Persists the
	 * marker so rebuild() reports 'invalid' (the stored key is kept for the
	 * reconnect prompt), busts the cached state, drops the now-meaningless plan
	 * cache, and raises the site-wide admin notice. Idempotent — the timestamp is
	 * stamped only on the first flip. Caller is responsible for confirming the
	 * code is a GENUINE invalid_key (see is_key_dead_code()).
	 */
	public static function mark_invalid() {
		if ( (int) get_option( self::KEY_INVALID_AT_OPTION, 0 ) <= 0 ) {
			update_option( self::KEY_INVALID_AT_OPTION, time(), false );
		}
		self::invalidate();
		self::clear_plan_cache();
		if ( class_exists( 'Slash_Image_Admin_Notice' ) ) {
			Slash_Image_Admin_Notice::set_account_error( 'invalid_key' );
		}
	}

	/**
	 * Clear the "invalid" marker on recovery (a successful verify / probe). A
	 * no-op when not currently invalid so it is cheap to call on every success.
	 */
	public static function clear_invalid() {
		if ( (int) get_option( self::KEY_INVALID_AT_OPTION, 0 ) > 0 ) {
			delete_option( self::KEY_INVALID_AT_OPTION );
			self::invalidate();
		}
		// Clear the reconnect notice on recovery — but ONLY when it's the
		// invalid_key one, never a payment_required (402) notice that shares the
		// same transient.
		if ( class_exists( 'Slash_Image_Admin_Notice' )
			&& 'invalid_key' === Slash_Image_Admin_Notice::current_account_error() ) {
			Slash_Image_Admin_Notice::clear_account_error();
		}
	}

	/**
	 * Settings-page synchronous re-probe (decision A). Runs at most once per
	 * KEY_RECHECK_THROTTLE, with a short timeout so the page never hangs. Only a
	 * genuine invalid_key flips to invalid; a transient/network failure leaves the
	 * status unchanged and the throttle simply retries after it expires. A success
	 * clears any invalid marker (recovery) and refreshes the plan cache.
	 *
	 * Call BEFORE reading the connection state on the settings page so the same
	 * page load renders the correct pill.
	 */
	public static function maybe_recheck_key() {
		$api_key = (string) Slash_Image_Settings::get( 'api_key', '' );
		if ( '' === $api_key ) {
			return;
		}
		// Only re-probe a key that was connected at least once; a never-verified
		// key is the Connect flow's job, not a recheck.
		if ( (int) get_option( 'slash_image_key_verified_at', 0 ) <= 0 ) {
			return;
		}
		if ( false !== get_transient( self::KEY_RECHECK_TRANSIENT ) ) {
			return; // throttled
		}
		// Stamp the throttle BEFORE the probe so a slow/failing API can't trigger a
		// probe on every settings load within the window.
		set_transient( self::KEY_RECHECK_TRANSIENT, 1, self::KEY_RECHECK_THROTTLE );

		$result = Slash_Image_Api_Client::verify_key( $api_key, self::KEY_RECHECK_TIMEOUT );

		if ( ! empty( $result['ok'] ) ) {
			self::clear_invalid();
			if ( ! empty( $result['plan'] ) && is_array( $result['plan'] ) ) {
				self::set_plan_cache( $result['plan'] );
			}
			return;
		}

		if ( 'invalid_key' === ( $result['code'] ?? '' ) ) {
			self::mark_invalid();
		}
		// Transient / network: status unchanged; the throttle retries next load
		// after it expires.
	}

	public static function fingerprint( $api_key ) {
		$api_key = (string) $api_key;
		if ( strlen( $api_key ) <= 12 ) {
			return '';
		}
		return substr( $api_key, 0, 8 ) . '••••••••' . substr( $api_key, -4 );
	}

	/**
	 * Masked fingerprint of the STORED key (e.g. sk_live_••••••••abcd), or '' when
	 * no key is set. Lets the connected-state settings UI show the key identity
	 * without ever putting the full key in the DOM.
	 */
	public static function get_fingerprint() {
		$api_key = (string) Slash_Image_Settings::get( 'api_key', '' );
		return '' === $api_key ? '' : self::fingerprint( $api_key );
	}

	/* ── Plan / usage cache ───────────────────────────────────────── */

	/**
	 * The cached plan array, or null when no plan is cached (unknown).
	 *
	 * @return array|null { monthly_limit:int|null, images_used_this_month:int, images_remaining:int|null }
	 */
	public static function get_plan_cache() {
		$cached = get_transient( self::PLAN_CACHE_TRANSIENT );
		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Store the plan array (transient value) and stamp the refresh time (option).
	 *
	 * @param array $plan Normalized plan (see Slash_Image_Api_Client::extract_plan).
	 */
	public static function set_plan_cache( array $plan ) {
		set_transient( self::PLAN_CACHE_TRANSIENT, $plan, self::PLAN_CACHE_TTL );
		update_option( self::PLAN_REFRESHED_AT_OPTION, time(), false );
	}

	/** Forget the cached plan (e.g. on key clear or an invalid_key sync). */
	public static function clear_plan_cache() {
		delete_transient( self::PLAN_CACHE_TRANSIENT );
		delete_option( self::PLAN_REFRESHED_AT_OPTION );
	}

	/**
	 * Patch the cached plan in place from the X-Images-Remaining header returned
	 * on every successful /v1/optimize call — live credits at zero extra cost (no
	 * API call). A no-op when no plan is cached yet: we can't safely synthesize a
	 * full plan from one number, so we wait for the daily sync / next key-save to
	 * populate it. The header value is the raw string: "unlimited" (flips the
	 * cache to an uncapped plan) or a non-negative integer (sets images_remaining
	 * and, on a capped plan, derives images_used_this_month from the cap).
	 *
	 * Reuses set_plan_cache() so the 25h TTL is re-applied with the patched value
	 * — the value changes, the cache lifetime stays the 25h window; the daily
	 * cron remains the authoritative correction.
	 *
	 * @param string $value Raw X-Images-Remaining header ("unlimited" | integer string).
	 */
	public static function update_remaining( $value ) {
		$cached = self::get_plan_cache();
		if ( null === $cached ) {
			return;
		}

		if ( 'unlimited' === (string) $value ) {
			$cached['monthly_limit']    = null;
			$cached['images_remaining'] = null;
		} else {
			$remaining                  = max( 0, (int) $value );
			$cached['images_remaining'] = $remaining;
			if ( null !== $cached['monthly_limit'] ) {
				$cached['images_used_this_month'] = (int) $cached['monthly_limit'] - $remaining;
			}
		}

		self::set_plan_cache( $cached );
	}

	/**
	 * Pure: whether the plugins.php Upgrade link should show for this plan.
	 * Show for an unknown plan (null cache — safe default) or any capped plan
	 * (monthly_limit !== null = free / sub-key-capped); hide only for an
	 * unlimited plan (monthly_limit === null).
	 *
	 * @param array|null $plan Cached plan, or null when unknown.
	 * @return bool
	 */
	public static function should_show_upgrade( $plan ) {
		if ( ! is_array( $plan ) || ! array_key_exists( 'monthly_limit', $plan ) ) {
			return true;
		}
		return null !== $plan['monthly_limit'];
	}

	/**
	 * Refresh stale plan data in the background (called from rebuild()). Fires
	 * only when a key is configured and the cache is missing or older than
	 * PLAN_REFRESH_AFTER. Rather than a literal fire-and-forget
	 * wp_remote_get(blocking:false) to the API — which would discard its response
	 * and so couldn't update the cache — it schedules the (blocking)
	 * run_daily_sync() handler to run on the next WP-Cron spawn (a separate,
	 * non-blocking request), so the triggering page is never slowed.
	 */
	public static function maybe_refresh_plan_cache() {
		$api_key = (string) Slash_Image_Settings::get( 'api_key', '' );
		if ( '' === $api_key ) {
			return;
		}

		$cached       = get_transient( self::PLAN_CACHE_TRANSIENT );
		$refreshed_at = (int) get_option( self::PLAN_REFRESHED_AT_OPTION, 0 );
		$is_stale     = ( ! is_array( $cached ) ) || ( ( time() - $refreshed_at ) > self::PLAN_REFRESH_AFTER );
		if ( ! $is_stale ) {
			return;
		}

		// wp_schedule_single_event de-dupes identical (hook, args) events within
		// ~10 min, so repeated rebuilds won't pile up requests.
		wp_schedule_single_event( time(), self::DAILY_SYNC_HOOK );
	}

	/**
	 * Daily-sync cron handler (also fired as a single event by
	 * maybe_refresh_plan_cache). Re-verifies the stored key and refreshes the
	 * plan cache. A transient failure (network / 5xx / rate limit) leaves the
	 * existing cache intact; only a definitive invalid_key clears the cache and
	 * invalidates the connection-state (today's key-invalidation flow).
	 */
	public static function run_daily_sync() {
		$api_key = (string) Slash_Image_Settings::get( 'api_key', '' );
		if ( '' === $api_key ) {
			return;
		}

		$result = Slash_Image_Api_Client::verify_key( $api_key );

		if ( ! empty( $result['ok'] ) ) {
			// Recovery: a key that was flipped invalid is healthy again.
			self::clear_invalid();
			if ( ! empty( $result['plan'] ) && is_array( $result['plan'] ) ) {
				self::set_plan_cache( $result['plan'] );
			} else {
				// Valid key, unknown plan — stamp the refresh time to debounce.
				update_option( self::PLAN_REFRESHED_AT_OPTION, time(), false );
			}
			return;
		}

		// /v1/keys/me does not enforce domain restrictions, so a non-200 here is a
		// genuinely dead key, never a domain code. Only invalid_key flips; a
		// transient (network / 5xx / 429) leaves the cache + status untouched.
		if ( 'invalid_key' === ( $result['code'] ?? '' ) ) {
			self::mark_invalid();
		}
	}

	/* ── Daily-sync cron wiring ───────────────────────────────────── */

	/** Register the cron action so WP-Cron can fire the daily sync. */
	public static function register_cron() {
		add_action( self::DAILY_SYNC_HOOK, array( __CLASS__, 'run_daily_sync' ) );
	}

	/** Schedule the daily sync if not already scheduled (idempotent). */
	public static function schedule_daily_sync() {
		if ( ! wp_next_scheduled( self::DAILY_SYNC_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY_SYNC_HOOK );
		}
	}

	/** Clear the daily-sync schedule (deactivation). */
	public static function clear_daily_sync() {
		wp_clear_scheduled_hook( self::DAILY_SYNC_HOOK );
	}
}
