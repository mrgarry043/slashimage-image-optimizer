<?php
/**
 * One-time migration of the legacy options-based queues into the new
 * wp_slash_image_queue table.
 *
 * Reads `slash_image_bulk_queue` (an array of attachment IDs) and
 * `slash_image_new_uploads_queue` (same shape) and enqueues each into
 * the new queue with the correct priority. Then deletes the legacy
 * options.
 *
 * Idempotent via the `slash_image_queue_migrated_v1` flag — running
 * twice is a no-op. Safe to call on every plugins_loaded.
 *
 * Runs in two contexts:
 *   - On the activator (fresh installs and reactivations).
 *   - On plugins_loaded for upgrade-in-place where the activator
 *     might not fire (e.g. WP-CLI plugin update path).
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Migrator {

	const FLAG_OPTION = 'slash_image_queue_migrated_v1';

	const LEGACY_BULK_OPTION    = 'slash_image_bulk_queue';
	const LEGACY_UPLOADS_OPTION = 'slash_image_new_uploads_queue';

	public static function maybe_migrate() {
		if ( get_option( self::FLAG_OPTION ) ) {
			return array(
				'migrated'      => false,
				'reason'        => 'already_migrated',
				'bulk_count'    => 0,
				'uploads_count' => 0,
			);
		}

		// Only run when the queue table exists. If somebody pulled the
		// new code without ever running the activator, dbDelta won't
		// have created the table yet — bail until next activation.
		if ( ! class_exists( 'Slash_Image_Queue' ) || ! self::queue_table_exists() ) {
			return array(
				'migrated'      => false,
				'reason'        => 'no_queue_table',
				'bulk_count'    => 0,
				'uploads_count' => 0,
			);
		}

		$bulk_count    = self::migrate_option(
			self::LEGACY_BULK_OPTION,
			Slash_Image_Queue::SOURCE_BULK,
			Slash_Image_Queue::PRIORITY_BULK
		);
		$uploads_count = self::migrate_option(
			self::LEGACY_UPLOADS_OPTION,
			Slash_Image_Queue::SOURCE_UPLOAD,
			Slash_Image_Queue::PRIORITY_UPLOAD
		);

		delete_option( self::LEGACY_BULK_OPTION );
		delete_option( self::LEGACY_UPLOADS_OPTION );

		update_option( self::FLAG_OPTION, time(), false );

		return array(
			'migrated'      => true,
			'reason'        => 'completed',
			'bulk_count'    => $bulk_count,
			'uploads_count' => $uploads_count,
		);
	}

	/**
	 * Reset the migration flag — used by tests and the
	 * Slash_Image_Uninstaller in full-removal mode.
	 */
	public static function reset_flag() {
		delete_option( self::FLAG_OPTION );
	}

	private static function migrate_option( $option_key, $source, $priority ) {
		$ids = get_option( $option_key, array() );
		if ( ! is_array( $ids ) ) {
			return 0;
		}

		$enqueued = 0;
		foreach ( $ids as $raw_id ) {
			$id = (int) $raw_id;
			if ( $id <= 0 ) {
				continue;
			}
			$row_id = Slash_Image_Queue::enqueue( $id, $source, $priority );
			if ( $row_id > 0 ) {
				$enqueued++;
			}
		}
		return $enqueued;
	}

	private static function queue_table_exists() {
		global $wpdb;

		$table = $wpdb->prefix . 'slash_image_queue';

		// SHOW TABLES LIKE is the standard pattern WP core uses for
		// table-existence checks in migrations.
		$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		return ! empty( $exists );
	}
}
