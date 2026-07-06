<?php
/**
 * Self-sustaining background loopback chain.
 *
 * Drives the queue with no browser tab open and no dependence on visitor
 * traffic, by chaining non-blocking server-to-self HTTP requests
 * (see wp-background-process.php handle()->dispatch()).
 *
 * Flow:
 *   - maybe_start_chain() (called server-side wherever rows enter the queue,
 *     and by the cron net) fires ONE non-blocking loopback if there is pending
 *     work and no chain is already running.
 *   - handle() (the admin-ajax endpoint) verifies the secret, no-ops when idle,
 *     runs one budgeted Slash_Image_Worker::tick(), then — if rows remain — fires
 *     the NEXT loopback directly and keeps the lock held. When the queue drains
 *     it releases the lock and the chain ends.
 *
 * Concurrency: the queue's atomic token claim already makes overlapping workers
 * SAFE (disjoint rows). The chain lock is therefore only an optimisation to
 * avoid redundant parallel chains, NOT a correctness primitive — a brief race
 * at chain boundaries is acceptable. It is emphatically NOT a host-wide
 * "one in-flight API call" serialize lock (constraint #3): one chain still
 * processes rows one at a time within the Phase-8 reactive budget, and the
 * interactive JS kick lane runs alongside it.
 *
 * Security bounds on the nopriv endpoint, all three required:
 *   1. Secret token (hash_equals) — only code holding the server-minted secret
 *      can trigger it.
 *   2. Idle no-op — returns before any work when the queue is empty / no bulk
 *      session is active, so even a leaked token can at worst make the site
 *      process its OWN already-queued images, and nothing at all when idle.
 *   3. The chain lock — bounds a leaked token to one chain, not a flood.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Loopback {

	const ACTION         = 'slash_image_loopback';
	const SECRET_OPTION  = 'slash_image_loopback_secret';
	const LOCK_TRANSIENT = 'slash_image_chain_running';

	/**
	 * Lock TTL. Comfortably above the worker's TIME_BUDGET_SEC (25 s) so the
	 * lock never expires mid-tick, but short enough that a crashed chain is
	 * picked back up by the cron net within ~1.5 min.
	 */
	const LOCK_TTL = 90;

	public function __construct() {
		// Registered on BOTH hooks: a server-to-self loopback carries no login
		// cookie, so wp_ajax_nopriv_ is required; wp_ajax_ covers the (rare)
		// case where the dispatch happens to carry a session.
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/* ── Pure decision helpers (unit-tested) ───────────────────────── */

	/**
	 * Constant-time secret comparison with type guards.
	 *
	 * @param mixed  $provided Token supplied on the request.
	 * @param string $expected The stored secret.
	 * @return bool
	 */
	public static function token_is_valid( $provided, $expected ) {
		if ( ! is_string( $provided ) || ! is_string( $expected ) || '' === $expected ) {
			return false;
		}
		return hash_equals( $expected, $provided );
	}

	/**
	 * Should maybe_start_chain() fire a fresh loopback? Only when there is work
	 * to do AND no chain is already running (the dispatch decision given lock
	 * state). The continuation path does NOT use this — it dispatches directly.
	 *
	 * @param bool $has_pending_work Whether the queue has claimable/pending work.
	 * @param bool $chain_running    Whether a chain lock is currently held.
	 * @return bool
	 */
	public static function should_start_dispatch( $has_pending_work, $chain_running ) {
		return (bool) $has_pending_work && ! (bool) $chain_running;
	}

	/**
	 * Should the chain fire its next loopback after a tick? Only when the tick
	 * actually claimed a row AND work remains — so a tick that claims nothing
	 * (queue drained, or all remaining rows are backoff-gated) ends the chain
	 * instead of busy-looping. Gated retries fall back to the cron net. Mirrors
	 * the JS kick's queue_has_more semantics.
	 *
	 * @param int  $claimed          Rows this tick claimed.
	 * @param bool $has_pending_work Whether work remains after the tick.
	 * @return bool
	 */
	public static function should_continue_chain( $claimed, $has_pending_work ) {
		return ( (int) $claimed > 0 ) && (bool) $has_pending_work;
	}

	/* ── Secret ─────────────────────────────────────────────────────── */

	/**
	 * The server-minted loopback secret, generated lazily on first use.
	 */
	public static function secret() {
		$secret = get_option( self::SECRET_OPTION );
		if ( ! is_string( $secret ) || strlen( $secret ) < 32 ) {
			$secret = wp_generate_password( 64, false );
			update_option( self::SECRET_OPTION, $secret, false );
		}
		return $secret;
	}

	/* ── Pending-work / lock state (runtime reads) ──────────────────── */

	/**
	 * Is there work the chain should be draining? True when waiting rows exist,
	 * or a bulk session is still running with source rows left to feed (so the
	 * chain shouldn't stop just because the current chunk drained).
	 */
	public static function has_pending_work() {
		$counts = Slash_Image_Queue::counts();
		if ( (int) ( $counts['waiting'] ?? 0 ) > 0 ) {
			return true;
		}

		$session = Slash_Image_Worker::get_session();
		if ( 'running' === ( $session['status'] ?? '' ) && empty( $session['source_done'] ) ) {
			return true;
		}

		return false;
	}

	public static function is_chain_running() {
		return (bool) get_transient( self::LOCK_TRANSIENT );
	}

	private static function refresh_lock() {
		set_transient( self::LOCK_TRANSIENT, time(), self::LOCK_TTL );
	}

	public static function release_lock() {
		delete_transient( self::LOCK_TRANSIENT );
	}

	/* ── Dispatch + chain entry ─────────────────────────────────────── */

	/**
	 * External entry point: start the background chain if warranted. Called
	 * server-side wherever rows enter the queue (upload enqueue, bulk Start) and
	 * by the cron net.
	 *
	 * Dispatch at most once per request — one chain drains the whole queue, so
	 * one loopback is enough. The request-static guard collapses loop callers
	 * (e.g. the Media Library bulk action calling enqueue_new_upload() once per
	 * selected image) to a single dispatch; without it, the cross-request lock
	 * is held by the loopback *handler* in a separate request, so within one
	 * request is_chain_running() reads false on every iteration and the loop
	 * would fire N dispatches (the 504 fan-out). The cross-request lock
	 * still prevents a second chain when one is already running from a prior
	 * request.
	 *
	 * The static is set ONLY after a real dispatch fires — not on a no-op — so
	 * an early call that bails (no pending work yet, or the lock is held) does
	 * not consume the one allowed dispatch for a later legitimate call in the
	 * same request.
	 */
	public static function maybe_start_chain() {
		static $dispatched = false;

		if ( $dispatched ) {
			return;
		}
		if ( ! self::should_start_dispatch( self::has_pending_work(), self::is_chain_running() ) ) {
			return;
		}

		self::dispatch();
		$dispatched = true;
	}

	/**
	 * Fire ONE non-blocking loopback request. Fire-and-forget — never reads the
	 * response (so a blocked-loopback host fails silently and the cron net
	 * remains the fallback). The lock is acquired by the receiving handler when
	 * it actually runs, NOT here, so a failed dispatch leaves no phantom lock
	 * that would suppress the cron net.
	 */
	private static function dispatch() {
		$url = add_query_arg(
			array(
				'action' => self::ACTION,
				'token'  => self::secret(),
			),
			admin_url( 'admin-ajax.php' )
		);

		wp_remote_post(
			esc_url_raw( $url ),
			array(
				'blocking'  => false,
				// Fire-and-forget: we never read the response, so this timeout only
				// caps how long a doomed/slow self-connect can block the dispatching
				// request on a loopback-blocked host. Kept short for that reason.
				'timeout'   => 2,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core WP filter, read intentionally
				'body'      => array(),
			)
		);
	}

	/**
	 * admin-ajax handler for the loopback chain. Secret-authed, idle no-op, runs
	 * one budgeted tick, then self-continues.
	 */
	public static function handle() {
		// Don't hold the PHP session lock while processing.
		if ( function_exists( 'session_write_close' ) ) {
			session_write_close();
		}

		// Auth: server-minted secret, NOT a user nonce / capability — a loopback
		// has no logged-in user. phpcs nonce sniff is N/A: hash_equals on the
		// secret IS the verification.
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! self::token_is_valid( $token, self::secret() ) ) {
			status_header( 403 );
			wp_die( '', '', array( 'response' => 403 ) );
		}

		// Idle no-op: nothing queued / no active bulk session → stop before any
		// work. Release the lock defensively in case a prior chain left it set.
		if ( ! self::has_pending_work() ) {
			self::release_lock();
			wp_die();
		}

		Slash_Image_Worker::remove_time_limit();

		// We're now the running chain — hold the lock across this tick.
		self::refresh_lock();

		$result  = Slash_Image_Worker::tick();
		$claimed = (int) ( $result['claimed'] ?? 0 );

		if ( self::should_continue_chain( $claimed, self::has_pending_work() ) ) {
			// Continue the chain DIRECTLY — not via maybe_start_chain(), which
			// would no-op because we hold the lock. Refresh first so the lock
			// stays held across the boundary to the next loopback.
			self::refresh_lock();
			self::dispatch();
		} else {
			self::release_lock();
		}

		wp_die();
	}
}
