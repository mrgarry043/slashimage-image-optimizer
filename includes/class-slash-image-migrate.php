<?php
/**
 * Migration orchestrator: adapter registry, global refusals, batch loop, cursor.
 *
 * Drives an adapter (see Slash_Image_Migrate_Adapter) over the library in
 * ID-ordered batches, accumulating one shared stat vocabulary so every source
 * reports the same shape. Loaded on demand — under WP-CLI today, from the
 * Tools tab later — so it costs nothing on a normal web request.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Migrate {

	/** Non-autoloaded resume cursor; deleted on completion. */
	const CURSOR_OPTION = 'slash_image_migrate_cursor';

	/** Provenance written on every migrated attachment. */
	const META_MIGRATED_FROM = '_slash_image_migrated_from';
	const META_MIGRATED_AT   = '_slash_image_migrated_at';

	/** Default attachments examined per batch. */
	const DEFAULT_BATCH_SIZE = 200;

	/**
	 * Registered adapters, slug => class name.
	 *
	 * @return array
	 */
	public static function adapters() {
		return array(
			'shortpixel' => 'Slash_Image_Migrate_Shortpixel',
			'imagify'    => 'Slash_Image_Migrate_Imagify',
		);
	}

	/**
	 * Resolve a slug to its adapter class, or '' when unknown.
	 *
	 * @param string $slug Adapter slug.
	 * @return string
	 */
	public static function adapter_for( $slug ) {
		$adapters = self::adapters();
		$slug     = strtolower( trim( (string) $slug ) );
		return isset( $adapters[ $slug ] ) ? $adapters[ $slug ] : '';
	}

	/**
	 * The shared stat vocabulary. Every adapter reports these keys and the
	 * orchestrator sums them, so a new source needs no reporting changes.
	 *
	 * @return array Zeroed counters.
	 */
	public static function stat_keys() {
		return array(
			// Scan outcomes.
			'scanned'                   => 0,
			'migrated'                  => 0,
			'already_ours'              => 0,
			'already_migrated'          => 0,
			'skipped_status'            => 0,
			// Sub-counts of skipped_status. A source reports whichever it has:
			// ShortPixel can revert an image (status 3); Imagify instead marks
			// one it judged already small enough. Separate keys because
			// collapsing them would print "reverted" for images nobody reverted.
			'skipped_restored'          => 0,
			'skipped_already_optimized' => 0,
			'skipped_unsupported_mime'  => 0,
			'skipped_no_usable_rows'    => 0,
			'skipped_file_missing'      => 0,
			// Next-gen claim outcomes.
			'webp_linked'               => 0,
			'avif_linked'               => 0,
			'webp_already_present'      => 0,
			'avif_already_present'      => 0,
			'webp_missing'              => 0,
			'avif_missing'              => 0,
			'sentinel_skipped'          => 0,
			'link_failed'               => 0,
		);
	}

	/**
	 * Global refusals that apply regardless of source. Checked before the
	 * adapter's own detect().
	 *
	 * @return array ['ok' => bool, 'code' => string, 'message' => string]
	 */
	public static function preflight() {
		if ( is_multisite() ) {
			return array(
				'ok'      => false,
				'code'    => 'multisite',
				'message' => 'Multisite is not supported by the migrator. Optimization state is per-site, and a network-wide import would need per-site review; run the migration per site with a site-scoped WP-CLI invocation once that is supported.',
			);
		}

		return array(
			'ok'      => true,
			'code'    => '',
			'message' => '',
		);
	}

	/**
	 * True when this attachment already carries genuine SlashImage optimization
	 * data that a migration must never overwrite.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return bool
	 */
	public static function is_optimized_by_us( $attachment_id ) {
		$data = get_post_meta( $attachment_id, Slash_Image_Media_Handler::META_DATA_KEY, true );
		if ( ! is_array( $data ) || empty( $data['optimized'] ) ) {
			return false;
		}
		// Data we wrote ourselves has no migration provenance.
		return '' === (string) get_post_meta( $attachment_id, self::META_MIGRATED_FROM, true );
	}

	/**
	 * True when this attachment has already been migrated (from any source).
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return bool
	 */
	public static function is_already_migrated( $attachment_id ) {
		return '' !== (string) get_post_meta( $attachment_id, self::META_MIGRATED_FROM, true );
	}

	/**
	 * Whether the attachment's MIME is inside the allowlist the stats bundle
	 * aggregates over. An attachment outside it can be given postmeta but will
	 * never appear in the stats totals, so migrating it would make the reported
	 * counts irreconcilable — skip and report instead.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return bool
	 */
	public static function is_countable_mime( $attachment_id ) {
		return in_array(
			(string) get_post_mime_type( $attachment_id ),
			Slash_Image_Api_Client::SUPPORTED_MIME_TYPES,
			true
		);
	}

	/**
	 * Run a migration to completion, batching by attachment ID.
	 *
	 * @param string        $slug      Adapter slug.
	 * @param bool          $dry_run   Scan only; write nothing.
	 * @param int           $batch     Attachments per batch.
	 * @param callable|null $on_batch  Optional progress callback, receives the
	 *                                 batch's 'scanned' count.
	 * @return array ['ok' => bool, 'code' => string, 'message' => string, 'stats' => array]
	 */
	public static function run( $slug, $dry_run = false, $batch = self::DEFAULT_BATCH_SIZE, $on_batch = null ) {
		$stats = self::stat_keys();

		$pre = self::preflight();
		if ( empty( $pre['ok'] ) ) {
			return array(
				'ok'      => false,
				'code'    => $pre['code'],
				'message' => $pre['message'],
				'stats'   => $stats,
			);
		}

		$adapter = self::adapter_for( $slug );
		if ( '' === $adapter ) {
			return array(
				'ok'      => false,
				'code'    => 'unknown_source',
				'message' => sprintf( 'Unknown migration source "%s". Available: %s.', $slug, implode( ', ', array_keys( self::adapters() ) ) ),
				'stats'   => $stats,
			);
		}

		$detect = call_user_func( array( $adapter, 'detect' ) );
		if ( empty( $detect['ok'] ) ) {
			return array(
				'ok'      => false,
				'code'    => (string) ( $detect['code'] ?? 'undetected' ),
				'message' => (string) ( $detect['message'] ?? '' ),
				'stats'   => $stats,
			);
		}

		$batch = max( 1, (int) $batch );

		// Resume a killed run. Dry runs never read or write the cursor: a scan
		// must always report on the whole library.
		$cursor = $dry_run ? 0 : (int) get_option( self::CURSOR_OPTION, 0 );

		while ( true ) {
			$result = call_user_func( array( $adapter, 'migrate_batch' ), $cursor, $batch, $dry_run );

			foreach ( $stats as $key => $unused ) {
				if ( isset( $result['stats'][ $key ] ) ) {
					$stats[ $key ] += (int) $result['stats'][ $key ];
				}
			}

			if ( is_callable( $on_batch ) ) {
				call_user_func( $on_batch, (int) ( $result['stats']['scanned'] ?? 0 ) );
			}

			$cursor = (int) ( $result['cursor'] ?? $cursor );

			if ( ! $dry_run ) {
				update_option( self::CURSOR_OPTION, $cursor, false );
			}

			// Keep the object cache from growing across a long run: every batch
			// primes post + meta caches that are never read again.
			self::flush_object_cache();

			if ( ! empty( $result['done'] ) ) {
				break;
			}
		}

		if ( ! $dry_run ) {
			delete_option( self::CURSOR_OPTION );
		}

		return array(
			'ok'      => true,
			'code'    => '',
			'message' => '',
			'stats'   => $stats,
		);
	}

	/**
	 * Drop the per-batch object-cache churn. Uses the public WordPress helper
	 * when available; on a persistent object cache this is a no-op by design
	 * (that cache has its own eviction) and the run simply relies on it.
	 */
	private static function flush_object_cache() {
		global $wpdb, $wp_object_cache;

		if ( is_object( $wpdb ) && isset( $wpdb->queries ) && is_array( $wpdb->queries ) ) {
			$wpdb->queries = array();
		}

		if ( wp_using_ext_object_cache() ) {
			return;
		}

		if ( is_object( $wp_object_cache ) && isset( $wp_object_cache->cache ) ) {
			$wp_object_cache->cache = array();
		}
	}
}
