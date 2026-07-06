<?php
/**
 * Plugin deactivation hook handler.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Deactivator {

	const WORKER_CRON_HOOK  = 'slash_image_worker_tick';
	const CLEANUP_CRON_HOOK = 'slash_image_cleanup_backups';
	const CRON_PROBE_HOOK   = 'slash_image_cron_probe';
	const QUEUE_PURGE_HOOK  = 'slash_image_queue_purge';
	const DAILY_SYNC_HOOK   = 'slash_image_daily_sync';

	const BULK_SESSION_OPTION  = 'slash_image_bulk_session';
	const CHAIN_LOCK_TRANSIENT = 'slash_image_chain_running';

	public static function deactivate() {
		wp_clear_scheduled_hook( self::WORKER_CRON_HOOK );
		wp_clear_scheduled_hook( self::CLEANUP_CRON_HOOK );
		wp_clear_scheduled_hook( self::CRON_PROBE_HOOK );
		wp_clear_scheduled_hook( self::QUEUE_PURGE_HOOK );
		wp_clear_scheduled_hook( self::DAILY_SYNC_HOOK );

		// Release the loopback chain lock so a stale lock can't suppress the
		// cron net after a future re-activation.
		delete_transient( self::CHAIN_LOCK_TRANSIENT );

		// If a bulk run was active, mark its session paused so a future
		// activation doesn't auto-resume mid-batch.
		$session = get_option( self::BULK_SESSION_OPTION, array() );
		if ( is_array( $session ) && 'running' === ( $session['status'] ?? '' ) ) {
			$session['status'] = 'paused';
			update_option( self::BULK_SESSION_OPTION, $session, false );
		}
	}
}
