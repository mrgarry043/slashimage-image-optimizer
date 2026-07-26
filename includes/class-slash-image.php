<?php
/**
 * Main plugin class. Singleton.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Slash_Image {

	/**
	 * Cache-busting version for a bundled admin asset.
	 *
	 * SLASH_IMAGE_VERSION alone is not enough: it only moves at release time, so
	 * an asset edited between releases keeps the exact same URL and browsers (and
	 * any CDN in front) go on serving the stale copy for the full max-age —
	 * silently, with no error to notice. Appending the file's mtime makes the URL
	 * change precisely when the content does, and stay stable when it does not.
	 *
	 * Costs one filemtime() per enqueued asset, on plugin admin screens only.
	 *
	 * @param string $rel_path Path relative to the plugin root, e.g. 'admin/js/settings.js'.
	 * @return string
	 */
	public static function asset_version( $rel_path ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing file falls back to the release version.
		$mtime = @filemtime( SLASH_IMAGE_PATH . ltrim( (string) $rel_path, '/' ) );
		return $mtime ? SLASH_IMAGE_VERSION . '.' . $mtime : SLASH_IMAGE_VERSION;
	}

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	private function __construct() {}

	private function init() {
		new Slash_Image_Media_Handler();
		new Slash_Image_Restore();
		new Slash_Image_Bulk_Processor();
		new Slash_Image_Worker();
		new Slash_Image_Cron_Probe();
		// Always instantiated (not admin-gated): the loopback chain's admin-ajax
		// handler must be registered for logged-out/server-to-self requests.
		new Slash_Image_Loopback();

		Slash_Image_Worker::schedule_cron();

		if ( class_exists( 'Slash_Image_Queue' ) ) {
			// Self-healing schema upgrade for update-in-place (the activation hook
			// doesn't fire on a plugin update). Self-gates on the stored version.
			Slash_Image_Queue::maybe_upgrade_schema();
			Slash_Image_Queue::register_cron();
			Slash_Image_Queue::schedule_purge_cron();
		}

		// Daily plan/usage sync (GET /v1/keys/me). register_cron() wires the
		// handler so WP-Cron can fire it; schedule_daily_sync() self-heals the
		// schedule on existing installs that won't re-run activation.
		Slash_Image_Connection::register_cron();
		Slash_Image_Connection::schedule_daily_sync();

		// One-time migration for upgrade-in-place. Idempotent — does
		// nothing once slash_image_queue_migrated_v1 is set. Cheap on
		// subsequent loads (single get_option call).
		if ( class_exists( 'Slash_Image_Migrator' ) ) {
			Slash_Image_Migrator::maybe_migrate();
		}

		if ( ! is_admin() && 'picture' === (string) Slash_Image_Settings::get( 'frontend_serving_mode', 'picture' ) ) {
			new Slash_Image_Frontend();
		}

		add_filter( 'cron_schedules', array( 'Slash_Image_Bulk_Processor', 'register_schedule' ) );
		add_action( 'slash_image_cleanup_backups', array( 'Slash_Image_Cron_Probe', 'evaluate' ) );

		// Multisite: provision a queue table for any subsite created after
		// (network) activation. Priority 20 runs after core's default
		// wp_initialize_site handler (priority 10) has created the new site's
		// base tables, so switch_to_blog() + install_schema() land on a site
		// whose options table already exists. The network-activation loop in
		// Slash_Image_Activator::activate() covers pre-existing subsites; this
		// covers ones added later. Single-site installs never fire this hook.
		if ( is_multisite() ) {
			add_action( 'wp_initialize_site', array( 'Slash_Image_Activator', 'provision_new_site' ), 20 );
			// Drop the per-site queue table when a subsite is deleted — core
			// drops only its own blog tables, which would orphan ours.
			add_filter( 'wpmu_drop_tables', array( 'Slash_Image_Queue', 'add_drop_table' ) );
		}

		if ( is_admin() ) {
			new Slash_Image_Admin();
			new Slash_Image_Admin_Notice();
			new Slash_Image_Bulk_Page();
			new Slash_Image_Media_Library();
			new Slash_Image_Dashboard_Widget();
			// Registers three AJAX handlers only. The Tools panel renders no
			// server-side body, so nothing here queries until a user opens the
			// tab — and the migration classes stay unloaded until then.
			new Slash_Image_Tools_Page();
		}

		Slash_Image_Restore::schedule_cleanup();

		// WP-CLI command surface (`wp slashimage ...`). Guarded so the CLI class
		// is only autoloaded under WP-CLI — no overhead on normal web/admin
		// requests. init() runs on plugins_loaded, which on CLI runs is after
		// WP_CLI is defined, so the command registers before dispatch.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'slashimage', 'Slash_Image_CLI' );
		}
	}
}
