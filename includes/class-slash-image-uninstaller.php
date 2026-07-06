<?php
/**
 * Uninstall logic. Two modes:
 *
 *  - Minimal (default, every uninstall): unschedule cron, clear transients.
 *  - Full removal (opt-in via slash_image_settings['uninstall_remove_all']):
 *    delete every option + every postmeta we wrote, strip the managed
 *    .htaccess block, delete any .htaccess.slash-image-backup-* files.
 *
 * Even in full-removal mode we NEVER touch:
 *  - the slashimage-backups/ directory (originals)
 *  - .webp / .avif sibling files (optimized variants)
 *  - any file under wp-content/uploads/ at all
 *
 * Image files are user content. Plugin-internal data is plugin-internal data.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Uninstaller {

	const SETTINGS_OPTION = 'slash_image_settings';

	/**
	 * Options the plugin owns. Includes legacy bulk options
	 * (slash_image_bulk_queue / _progress / _failed and
	 * slash_image_new_uploads_queue) so users who upgraded from a pre-queue
	 * release still get a clean uninstall.
	 */
	const OPTIONS = array(
		'slash_image_settings',
		'slash_image_key_verified_at',
		'slash_image_key_invalid_at',
		'slash_image_plan_cache_refreshed_at',
		'slash_image_settings_saved_at',
		'slash_image_stats_images_optimized',
		'slash_image_stats_thumbnails_optimized',
		'slash_image_stats_total_saved_bytes',
		'slash_image_stats_last_updated',
		'slash_image_bulk_session',
		'slash_image_queue_schema_version',
		'slash_image_queue_migrated_v1',
		'slash_image_backup_cleanup_cursor',
		'slash_image_debug_log',
		'slash_image_loopback_secret',
		// Legacy options (kept for clean uninstall on upgraded sites).
		'slash_image_bulk_queue',
		'slash_image_new_uploads_queue',
		'slash_image_bulk_progress',
		'slash_image_bulk_failed',
	);

	/** Transients the plugin owns. Cleared in BOTH minimal and full modes. */
	const TRANSIENTS = array(
		'slash_image_connection_state',
		'slash_image_cf_detected',
		'slash_image_cron_status',
		'slash_image_cron_probe_started_at',
		'slash_image_cron_probe_fired',
		'slash_image_bulk_recent_completions',
		'slash_image_chain_running',
		'slash_image_stats_bundle',
		'slash_image_admin_notice',
		'slash_image_plan_cache',
		'slash_image_key_recheck',
	);

	/**
	 * Cron hooks the plugin owns. Always cleared. Includes legacy hooks
	 * (slash_image_bulk_process / slash_image_process_new_uploads) so a
	 * pre-queue install gets fully unscheduled.
	 */
	const CRON_HOOKS = array(
		'slash_image_worker_tick',
		'slash_image_queue_purge',
		'slash_image_cleanup_backups',
		'slash_image_cron_probe',
		// Legacy hooks (kept for clean uninstall on upgraded sites).
		'slash_image_bulk_process',
		'slash_image_process_new_uploads',
	);

	/** Postmeta keys the plugin owns. Cleared in full mode only. */
	const POSTMETA_KEYS = array(
		'_slash_image_data',
		'_slash_image_backup',
		// Flat stats fields.
		'_slash_image_saved_bytes',
		'_slash_image_original_bytes',
		'_slash_image_thumb_count',
	);

	const HTACCESS_MARKER = 'Slash Image';

	public static function run() {
		if ( is_multisite() ) {
			$sites = get_sites(
				array(
					'number' => 0,
					'fields' => 'ids',
				)
			);
			foreach ( $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::run_for_current_site();
				restore_current_blog();
			}
			return;
		}
		self::run_for_current_site();
	}

	private static function run_for_current_site() {
		$full_removal = self::full_removal_enabled();

		// MINIMAL — runs every uninstall.
		self::clear_cron();
		self::clear_transients();

		if ( ! $full_removal ) {
			return;
		}

		// FULL REMOVAL — opt-in only.
		self::delete_options();
		self::delete_postmeta();
		self::drop_queue_table();
		self::remove_htaccess_block();
		self::delete_htaccess_backups();
	}

	private static function drop_queue_table() {
		if ( class_exists( 'Slash_Image_Queue' ) ) {
			Slash_Image_Queue::drop_schema();
			return;
		}
		// Fallback when uninstall.php loads without the full plugin bootstrap.
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}slash_image_queue" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL on the plugin's own custom queue table; not a cacheable read.
	}

	private static function full_removal_enabled() {
		$settings = get_option( self::SETTINGS_OPTION );
		if ( ! is_array( $settings ) ) {
			return false;
		}
		return ! empty( $settings['uninstall_remove_all'] );
	}

	private static function clear_cron() {
		foreach ( self::CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	private static function clear_transients() {
		foreach ( self::TRANSIENTS as $key ) {
			delete_transient( $key );
		}
	}

	private static function delete_options() {
		foreach ( self::OPTIONS as $key ) {
			delete_option( $key );
		}
	}

	private static function delete_postmeta() {
		global $wpdb;
		foreach ( self::POSTMETA_KEYS as $key ) {
			delete_metadata( 'post', 0, $key, '', true );
		}
	}

	private static function remove_htaccess_block() {
		$path = trailingslashit( ABSPATH ) . '.htaccess';
		if ( ! file_exists( $path ) || ! is_writable( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Read-only writability probe on the site-root .htaccess during uninstall cleanup; native check avoids a WP_Filesystem credential prompt.
			return;
		}
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		// Empty array → insert_with_markers strips the BEGIN/END block entirely.
		insert_with_markers( $path, self::HTACCESS_MARKER, array() );
	}

	private static function delete_htaccess_backups() {
		$pattern = trailingslashit( ABSPATH ) . '.htaccess.slash-image-backup-*';
		$matches = @glob( $pattern );
		if ( ! is_array( $matches ) ) {
			return;
		}
		foreach ( $matches as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}
}
