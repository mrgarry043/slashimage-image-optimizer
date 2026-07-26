<?php
/**
 * Imagify migration adapter (1.9+ postmeta format).
 *
 * Imagify keeps its state in three postmeta keys — _imagify_data (a serialized
 * array), _imagify_status, and _imagify_optimization_level, the latter two
 * written for the full size only. Everything this adapter needs is in
 * _imagify_data['sizes'].
 *
 * Detection reads POSTMETA ONLY, never an Imagify class or function: their
 * uninstall routine leaves this postmeta in place, so the adapter must work
 * with Imagify deactivated or deleted.
 *
 * Read-only with respect to Imagify: their postmeta, their tables, their
 * uploads/backup/ tree and their files are never written, moved, or deleted.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Migrate_Imagify implements Slash_Image_Migrate_Adapter {

	const META_DATA   = '_imagify_data';
	const META_STATUS = '_imagify_status';
	const META_LEVEL  = '_imagify_optimization_level';

	/** Attachment-level status meaning Imagify judged the file already small enough. */
	const STATUS_ALREADY_OPTIMIZED = 'already_optimized';

	/**
	 * Marker separating a size name from its next-gen format inside the sizes
	 * map: 'full@imagify-webp'. An ARRAY-KEY SUFFIX — it never appears in a
	 * filename.
	 */
	const NEXTGEN_MARKER = '@imagify-';

	public static function slug() {
		return 'imagify';
	}

	public static function label() {
		return 'Imagify';
	}

	public static function detect() {
		if ( 0 === self::count() ) {
			return array(
				'ok'      => false,
				'code'    => 'no_data',
				'message' => 'No Imagify data found on this site. Nothing to import.',
			);
		}

		return array(
			'ok'      => true,
			'code'    => '',
			'message' => '',
		);
	}

	public static function count() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::META_DATA
			)
		);
	}

	public static function migrate_batch( $after, $limit, $dry_run ) {
		global $wpdb;

		$after = (int) $after;
		$limit = max( 1, (int) $limit );
		$stats = Slash_Image_Migrate::stat_keys();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
				  WHERE meta_key = %s AND post_id > %d
				  ORDER BY post_id ASC LIMIT %d",
				self::META_DATA,
				$after,
				$limit
			)
		);
		$ids = array_map( 'intval', (array) $ids );

		if ( empty( $ids ) ) {
			return array(
				'cursor' => $after,
				'done'   => true,
				'stats'  => $stats,
			);
		}

		foreach ( $ids as $attachment_id ) {
			++$stats['scanned'];
			foreach ( self::migrate_one( $attachment_id, $dry_run ) as $key => $delta ) {
				if ( isset( $stats[ $key ] ) ) {
					$stats[ $key ] += (int) $delta;
				}
			}
		}

		return array(
			'cursor' => (int) max( $ids ),
			'done'   => count( $ids ) < $limit,
			'stats'  => $stats,
		);
	}

	/**
	 * Import one attachment. Returns a partial stat delta.
	 *
	 * @param int  $attachment_id Attachment post ID.
	 * @param bool $dry_run       Scan only.
	 * @return array
	 */
	private static function migrate_one( $attachment_id, $dry_run ) {
		if ( Slash_Image_Migrate::is_already_migrated( $attachment_id ) ) {
			return array( 'already_migrated' => 1 );
		}
		if ( Slash_Image_Migrate::is_optimized_by_us( $attachment_id ) ) {
			return array( 'already_ours' => 1 );
		}
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return array( 'skipped_no_usable_rows' => 1 );
		}
		if ( ! Slash_Image_Migrate::is_countable_mime( $attachment_id ) ) {
			return array( 'skipped_unsupported_mime' => 1 );
		}

		// An attachment Imagify judged already small enough carries NO byte
		// figures: its per-size entries are success=false with only an error
		// string (their DataInterface documents "If an error or
		// 'already_optimized': @type string $error"). There is nothing to
		// import, so it gets its own line rather than being lumped in with
		// failures.
		$status = (string) get_post_meta( $attachment_id, self::META_STATUS, true );
		if ( self::STATUS_ALREADY_OPTIMIZED === $status ) {
			return array(
				'skipped_status'            => 1,
				'skipped_already_optimized' => 1,
			);
		}

		$data = get_post_meta( $attachment_id, self::META_DATA, true );
		if ( ! is_array( $data ) || empty( $data['sizes'] ) || ! is_array( $data['sizes'] ) ) {
			return array( 'skipped_no_usable_rows' => 1 );
		}

		$attached = get_attached_file( $attachment_id );
		if ( ! $attached || ! file_exists( $attached ) ) {
			return array( 'skipped_file_missing' => 1 );
		}

		$plan = self::build_plan( $attachment_id, $data['sizes'], $attached, $dry_run );
		if ( empty( $plan['per_size'] ) ) {
			return array( 'skipped_no_usable_rows' => 1 );
		}

		$delta = array();
		foreach ( $plan['claim_stats'] as $key => $value ) {
			$delta[ $key ] = ( isset( $delta[ $key ] ) ? $delta[ $key ] : 0 ) + (int) $value;
		}

		if ( ! $dry_run ) {
			self::write_meta( $attachment_id, $plan );
		}

		$delta['migrated'] = 1;
		return $delta;
	}

	/**
	 * Build the import plan from Imagify's sizes map.
	 *
	 * The map interleaves two kinds of entry under one array:
	 *   'large'                => the optimized ORIGINAL-FORMAT file
	 *   'large@imagify-webp'   => the WebP produced FROM that same source
	 *
	 * A next-gen entry's original_size REPEATS the plain entry's original_size
	 * (verified live: full and full@imagify-webp both report 144724), which is
	 * why their own stats block double-counts and must never be read. Only the
	 * next-gen entry's optimized_size is used here, and only as that format's
	 * byte count.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param array  $sizes         _imagify_data['sizes'].
	 * @param string $attached      Absolute path of the full-size file.
	 * @param bool   $dry_run       Scan only.
	 * @return array
	 */
	private static function build_plan( $attachment_id, array $sizes, $attached, $dry_run ) {
		$meta     = wp_get_attachment_metadata( $attachment_id );
		$dir      = trailingslashit( dirname( $attached ) );
		$full_key = Slash_Image_Media_Handler::FULL_SIZE_KEY;

		$per_size    = array();
		$claim_stats = array();

		foreach ( $sizes as $key => $entry ) {
			// Next-gen entries are handled with their base size, not on their own.
			if ( false !== strpos( (string) $key, self::NEXTGEN_MARKER ) ) {
				continue;
			}
			// A failed size, or an already-optimized one, carries only an error
			// string. Nothing to import for it.
			if ( ! is_array( $entry ) || empty( $entry['success'] ) ) {
				continue;
			}

			$original  = (int) ( $entry['original_size'] ?? 0 );
			$optimized = (int) ( $entry['optimized_size'] ?? 0 );
			if ( $original <= 0 || $optimized <= 0 ) {
				continue;
			}

			$size_key    = (string) $key;
			$source_file = ( $full_key === $size_key )
				? $attached
				: self::size_path( $meta, $size_key, $dir );

			$plan_entry = array(
				'original_kb'           => (int) round( $original / 1024 ),
				'optimized_kb'          => (int) round( $optimized / 1024 ),
				'webp_kb'               => 0,
				'avif_kb'               => 0,
				'saved_kb'              => (int) round( max( 0, $original - $optimized ) / 1024 ),
				// Imagify exposes no pre-optimized signal and no PNG->JPEG
				// conversion of ours; false is the honest value, not a guess.
				'pre_optimized'         => false,
				'png_converted_to_jpeg' => false,
				'wrote'                 => array(),
				'write_failed'          => array(),
			);

			if ( '' !== $source_file ) {
				foreach ( array( 'webp', 'avif' ) as $format ) {
					// Presence of the suffixed key with success=true is the
					// source's own "this format was generated" signal; the claim
					// pass then confirms the file on disk.
					if ( ! self::has_nextgen( $sizes, $size_key, $format ) ) {
						continue;
					}
					$claim = Slash_Image_Migrate_Claim::evaluate_derived( $format, $source_file, $dry_run );
					foreach ( $claim['stats'] as $skey => $sval ) {
						$claim_stats[ $skey ] = ( isset( $claim_stats[ $skey ] ) ? $claim_stats[ $skey ] : 0 ) + (int) $sval;
					}
					if ( $claim['bytes'] > 0 ) {
						$plan_entry[ $format . '_kb' ] = (int) round( $claim['bytes'] / 1024 );
					}
				}
			}

			$per_size[ $size_key ] = $plan_entry;
		}

		return array(
			'per_size'    => $per_size,
			'claim_stats' => $claim_stats,
			'mode'        => self::compression_mode( (int) get_post_meta( $attachment_id, self::META_LEVEL, true ) ),
			'ts'          => self::processed_at( $attached ),
		);
	}

	/**
	 * Whether the sizes map records a successful next-gen conversion for a size.
	 *
	 * @param array  $sizes    The sizes map.
	 * @param string $size_key Base size name.
	 * @param string $format   'webp' or 'avif'.
	 * @return bool
	 */
	private static function has_nextgen( array $sizes, $size_key, $format ) {
		$key = $size_key . self::NEXTGEN_MARKER . $format;
		return isset( $sizes[ $key ] ) && is_array( $sizes[ $key ] ) && ! empty( $sizes[ $key ]['success'] );
	}

	/**
	 * Absolute path of a registered size's file.
	 *
	 * @param mixed  $meta     Attachment metadata.
	 * @param string $size_key Size name.
	 * @param string $dir      Directory holding the attachment's files.
	 * @return string '' when the size is not in the metadata.
	 */
	private static function size_path( $meta, $size_key, $dir ) {
		if ( ! is_array( $meta ) || empty( $meta['sizes'][ $size_key ]['file'] ) ) {
			return '';
		}
		return $dir . $meta['sizes'][ $size_key ]['file'];
	}

	/**
	 * When the optimized bytes were produced.
	 *
	 * Imagify records no timestamp anywhere in its postmeta (the blob's only
	 * top-level keys are 'sizes' and 'stats'), so the optimized full-size file's
	 * mtime is the closest true answer — it is literally when Imagify wrote
	 * those bytes. Falls back to migration time when the file cannot be stat'ed.
	 *
	 * @param string $attached Absolute path of the full-size file.
	 * @return int Unix timestamp.
	 */
	private static function processed_at( $attached ) {
		$mtime = @filemtime( $attached ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A false return is the documented fallback trigger.
		return $mtime ? (int) $mtime : time();
	}

	/**
	 * Map Imagify's optimization level onto our compression-mode vocabulary,
	 * preserving the aggressiveness ordering: their Normal < Aggressive < Ultra
	 * against our lossless < glossy < lossy.
	 *
	 * @param int $level 0 Normal | 1 Aggressive | 2 Ultra.
	 * @return string
	 */
	private static function compression_mode( $level ) {
		switch ( $level ) {
			case 0:
				return 'lossless';
			case 1:
				return 'glossy';
			default:
				return 'lossy';
		}
	}

	/**
	 * Write our postmeta for a migrated attachment.
	 *
	 * Mirrors Slash_Image_Media_Handler::run()'s success block exactly — the
	 * same shape the ShortPixel adapter writes — so the stats bundle, the Media
	 * Library column, and the already-optimized guard all behave identically to
	 * a genuine optimize. Writes nothing restore-adjacent: there is no backup of
	 * ours, and a forced re-optimize correctly degrades to the honest no_backup
	 * skip.
	 *
	 * @param int   $attachment_id Attachment post ID.
	 * @param array $plan          From build_plan().
	 */
	private static function write_meta( $attachment_id, array $plan ) {
		$per_size = $plan['per_size'];
		$full_key = Slash_Image_Media_Handler::FULL_SIZE_KEY;

		$totals     = array(
			'original_kb'  => 0,
			'optimized_kb' => 0,
			'webp_kb'      => 0,
			'avif_kb'      => 0,
			'saved_kb'     => 0,
		);
		$thumbnails = 0;
		$webp_count = 0;
		$avif_count = 0;

		foreach ( $per_size as $size_key => $entry ) {
			$totals['original_kb']  += $entry['original_kb'];
			$totals['optimized_kb'] += $entry['optimized_kb'];
			$totals['webp_kb']      += $entry['webp_kb'];
			$totals['avif_kb']      += $entry['avif_kb'];
			$totals['saved_kb']     += $entry['saved_kb'];

			if ( $full_key !== $size_key ) {
				++$thumbnails;
			}
			if ( $entry['webp_kb'] > 0 ) {
				++$webp_count;
			}
			if ( $entry['avif_kb'] > 0 ) {
				++$avif_count;
			}
		}

		$saved_percent_overall = ( $totals['original_kb'] > 0 )
			? round( ( $totals['saved_kb'] / $totals['original_kb'] ) * 100, 2 )
			: 0;

		$full               = isset( $per_size[ $full_key ] ) ? $per_size[ $full_key ] : array();
		$main_original_kb   = (int) ( $full['original_kb'] ?? 0 );
		$main_optimized_kb  = (int) ( $full['optimized_kb'] ?? 0 );
		$main_saved_percent = ( $main_original_kb > 0 )
			? round( ( ( $main_original_kb - $main_optimized_kb ) / $main_original_kb ) * 100, 2 )
			: 0;

		$data_meta = array(
			'optimized'             => true,
			'compression_mode'      => $plan['mode'],
			'pre_optimized'         => false,
			'png_converted_to_jpeg' => false,
			'original_size_kb'      => $totals['original_kb'],
			'optimized_size_kb'     => $totals['optimized_kb'],
			// All four variant-size fields are TOTALS, matching the writer, where
			// webp_size_kb and webp_total_kb hold the same value. The main-size
			// figure is read from per_size['full'] by the column.
			'webp_size_kb'          => $totals['webp_kb'],
			'avif_size_kb'          => $totals['avif_kb'],
			'saved_kb'              => $totals['saved_kb'],
			'saved_percent'         => (int) round( $saved_percent_overall ),
			'original_size_bytes'   => $main_original_kb * 1024,
			'optimized_size_bytes'  => $main_optimized_kb * 1024,
			'saved_percent_main'    => $main_saved_percent,
			'saved_percent_overall' => $saved_percent_overall,
			'thumbnails_processed'  => $thumbnails,
			'webp_count'            => $webp_count,
			'avif_count'            => $avif_count,
			'webp_total_kb'         => (int) $totals['webp_kb'],
			'avif_total_kb'         => (int) $totals['avif_kb'],
			'processed_sizes'       => array_keys( $per_size ),
			'failed_sizes'          => array(),
			'per_size'              => $per_size,
			'sizes_processed'       => array_keys( $per_size ),
			'sizes_failed'          => array(),
			'processed_at'          => gmdate( 'c', $plan['ts'] ),
			'migrated_from'         => self::slug(),
		);

		update_post_meta( $attachment_id, Slash_Image_Media_Handler::META_DATA_KEY, $data_meta );

		update_post_meta( $attachment_id, Slash_Image_Media_Handler::META_SAVED_BYTES_KEY, (int) $totals['saved_kb'] * 1024 );
		update_post_meta( $attachment_id, Slash_Image_Media_Handler::META_ORIGINAL_BYTES_KEY, (int) $totals['original_kb'] * 1024 );
		update_post_meta( $attachment_id, Slash_Image_Media_Handler::META_THUMB_COUNT_KEY, $thumbnails );
		update_post_meta(
			$attachment_id,
			Slash_Image_Media_Handler::META_BEST_FORMAT_BYTES_KEY,
			self::best_format_bytes( $per_size )
		);

		update_post_meta( $attachment_id, Slash_Image_Migrate::META_MIGRATED_FROM, self::slug() );
		update_post_meta( $attachment_id, Slash_Image_Migrate::META_MIGRATED_AT, time() );
	}

	/**
	 * Best-format total bytes: per size AVIF, else WebP, else the
	 * original-format optimized bytes. Same precedence as
	 * Slash_Image_Media_Handler::compute_best_format_bytes().
	 *
	 * @param array $per_size Per-size entries.
	 * @return int
	 */
	private static function best_format_bytes( array $per_size ) {
		$total = 0;
		foreach ( $per_size as $entry ) {
			if ( ! empty( $entry['avif_kb'] ) ) {
				$total += (int) $entry['avif_kb'];
			} elseif ( ! empty( $entry['webp_kb'] ) ) {
				$total += (int) $entry['webp_kb'];
			} else {
				$total += (int) ( $entry['optimized_kb'] ?? 0 );
			}
		}
		return $total * 1024;
	}
}
