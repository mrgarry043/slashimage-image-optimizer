<?php
/**
 * Plugin activation hook handler.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Activator {

	const SETTINGS_OPTION_KEY = 'slash_image_settings';

	/**
	 * Plugin activation handler.
	 *
	 * WordPress passes $network_wide = true when the plugin is network-activated
	 * across a multisite. In that case every existing subsite's queue table is
	 * created up front so activation is deterministic instead of leaning on the
	 * lazy maybe_upgrade_schema() net. The single-site body below always runs
	 * for the current (network-admin) site, and is the only path on a
	 * single-site install or a per-site activation of one subsite.
	 *
	 * @param bool $network_wide True when network-activated across a multisite.
	 */
	public static function activate( $network_wide = false ) {
		// Network activation: create the per-site queue table on every existing
		// subsite up front. Skipped on large networks — looping thousands of
		// CREATE TABLEs in the activation request would time out, so those defer
		// to the lazy maybe_upgrade_schema() path on each subsite's first load.
		// install_schema() is idempotent (dbDelta is diff-only and writes a
		// version-option guard), so this can't double-create against the
		// current-site install below or the lazy path. No cron scheduling here:
		// each subsite's init() schedulers self-heal its crons on first load.
		if ( is_multisite() && $network_wide && ! wp_is_large_network() && class_exists( 'Slash_Image_Queue' ) ) {
			$site_ids = get_sites(
				array(
					'number' => 0,
					'fields' => 'ids',
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				Slash_Image_Queue::install_schema();
				restore_current_blog();
			}
		}

		if ( false === get_option( self::SETTINGS_OPTION_KEY ) ) {
			add_option( self::SETTINGS_OPTION_KEY, self::default_settings() );
		}

		// Queue table — idempotent. dbDelta handles fresh installs and column
		// adds on upgrades.
		if ( class_exists( 'Slash_Image_Queue' ) ) {
			Slash_Image_Queue::install_schema();
			Slash_Image_Queue::schedule_purge_cron();
		}

		// One-time migration: legacy options-based queues → table rows.
		// Idempotent via the migrator's flag option.
		if ( class_exists( 'Slash_Image_Migrator' ) ) {
			Slash_Image_Migrator::maybe_migrate();
		}

		// Kick off the first cron probe so the bulk page has accurate banner state on first visit.
		if ( class_exists( 'Slash_Image_Cron_Probe' ) ) {
			Slash_Image_Cron_Probe::reset();
			Slash_Image_Cron_Probe::start_probe();
		}

		// Daily plan/usage sync cron.
		if ( class_exists( 'Slash_Image_Connection' ) ) {
			Slash_Image_Connection::schedule_daily_sync();
		}
	}

	/**
	 * Provision a multisite subsite created AFTER (network) activation.
	 *
	 * Hooked to wp_initialize_site at a late priority, so it runs after core's
	 * default handler has created the new site's base tables (including its
	 * options table). Creates the per-site queue table at site-creation time
	 * rather than waiting for the lazy maybe_upgrade_schema() net on the
	 * subsite's first request. Idempotent via install_schema(), so it never
	 * conflicts with the lazy path or the network-activation loop above.
	 *
	 * @param WP_Site $new_site The newly-created site.
	 */
	public static function provision_new_site( $new_site ) {
		if ( ! $new_site instanceof WP_Site || ! class_exists( 'Slash_Image_Queue' ) ) {
			return;
		}
		switch_to_blog( (int) $new_site->id );
		Slash_Image_Queue::install_schema();
		restore_current_blog();
	}

	public static function default_settings() {
		return array(
			'auto_optimize_uploads' => true,
			'compression_mode'      => 'lossy',
			'generate_webp'         => true,
			'generate_avif'         => true,
			'convert_png_to_jpeg'   => false,
			'preserve_metadata'     => false,
			'resize_on_upload'      => false,
			'max_width'             => 1560,
			'max_height'            => 1560,
			'keep_backups'          => true,
			'smart_backups'         => true,
			'auto_delete_backups'   => false,
			'backup_retention_days' => 90,
			'optimize_all_sizes'    => true,
			'frontend_serving_mode' => 'picture',
			'bulk_concurrency'      => 5,
			'debug_logging'         => false,
			'uninstall_remove_all'  => false,
			// New in v1.1: exclusion features.
			'excluded_image_sizes'  => array(), // empty = all sizes optimized
			'custom_exclusions'     => '',      // newline-separated keyword patterns
		);
	}
}
