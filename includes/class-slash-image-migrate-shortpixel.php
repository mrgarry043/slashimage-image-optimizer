<?php
/**
 * ShortPixel migration adapter (SPIO 5.0+ custom-table format).
 *
 * Reads {prefix}shortpixel_postmeta, which holds ONE ROW PER ACTUALLY-GENERATED
 * SIZE — main image at parent=0 / image_type=0 / size NULL, each thumbnail at
 * parent=<attach_id> / image_type=1 / size=<name>. The row set is authoritative:
 * iterate rows, never the site's registered sizes (an attachment whose source is
 * too small for `medium_large` simply has no row for it).
 *
 * Everything here is READ-ONLY with respect to ShortPixel: no writes to their
 * table, no touching their files, backups, or settings.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Migrate_Shortpixel implements Slash_Image_Migrate_Adapter {

	/** Importable status. Their FILE_STATUS_SUCCESS. */
	const STATUS_SUCCESS = 2;

	/** Their FILE_STATUS_RESTORED — reverted by the user, deliberately not imported. */
	const STATUS_RESTORED = 3;

	/**
	 * Sentinel stored in extra_info.webp / .avif when the converted file came out
	 * LARGER than the source, so ShortPixel discarded it. Their FILETYPE_BIGGER.
	 * An integer, never a filename — no file exists for that format.
	 */
	const FILETYPE_BIGGER = -10;

	/** Their image_type for a thumbnail row. */
	const IMAGE_TYPE_THUMB = 1;

	public static function slug() {
		return 'shortpixel';
	}

	public static function label() {
		return 'ShortPixel';
	}

	/**
	 * Fully-qualified source table name.
	 *
	 * @return string
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'shortpixel_postmeta';
	}

	/**
	 * Whether the source table exists.
	 *
	 * @return bool
	 */
	private static function table_exists() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Count attachments still carrying pre-5.0 ShortPixel state, which lived
	 * inside _wp_attachment_metadata under the ShortPixel / ShortPixelImprovement
	 * / ShortPixelPng2Jpg keys rather than in a table. We cannot read that shape,
	 * and saying so beats reporting "nothing to migrate" on a site that plainly
	 * has ShortPixel data.
	 *
	 * @return int
	 */
	private static function legacy_row_count() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
				'_wp_attachment_metadata',
				'%' . $wpdb->esc_like( 'ShortPixel' ) . '%'
			)
		);
	}

	public static function detect() {
		if ( ! self::table_exists() ) {
			if ( self::legacy_row_count() > 0 ) {
				return array(
					'ok'      => false,
					'code'    => 'legacy_format',
					'message' => __( 'This site\'s ShortPixel data is in an older format that SlashImage cannot read. Run ShortPixel\'s own "Migrate optimization data" tool first, then scan again.', 'slashimage-image-optimizer' ),
				);
			}
			return array(
				'ok'      => false,
				'code'    => 'no_table',
				'message' => __( 'No ShortPixel data was found on this site.', 'slashimage-image-optimizer' ),
			);
		}

		if ( 0 === self::count() ) {
			if ( self::legacy_row_count() > 0 ) {
				return array(
					'ok'      => false,
					'code'    => 'legacy_format',
					'message' => __( 'ShortPixel\'s optimization data is in an older format that SlashImage cannot read. Run ShortPixel\'s own "Migrate optimization data" tool first, then scan again.', 'slashimage-image-optimizer' ),
				);
			}
			return array(
				'ok'      => false,
				'code'    => 'empty',
				'message' => __( 'ShortPixel is installed but has no optimized images to import.', 'slashimage-image-optimizer' ),
			);
		}

		return array(
			'ok'      => true,
			'code'    => '',
			'message' => '',
		);
	}

	/**
	 * Attachments this source holds optimization records for, at ANY status —
	 * the same semantics as the Imagify adapter's count(), which counts every
	 * attachment carrying _imagify_data regardless of outcome.
	 *
	 * Deliberately NOT "importable". This is the denominator the progress bar
	 * and the Tools card divide `scanned` by, so it has to describe the set
	 * migrate_batch() actually walks. Counting only SUCCESS while the walk
	 * paged over every status made scanned overshoot total — a card reading
	 * "14,760 of 14,740 examined". Which of them can be imported is decided per
	 * attachment inside migrate_one(), and reported through the stat keys.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;
		$table = self::table();
		// No placeholder remains, so prepare() is not used: calling it without
		// one raises a WP notice. $table is prefix-derived, never user input.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT attach_id) FROM {$table}" );
	}

	public static function migrate_batch( $after, $limit, $dry_run ) {
		global $wpdb;

		$after = (int) $after;
		$limit = max( 1, (int) $limit );
		$table = self::table();
		$stats = Slash_Image_Migrate::stat_keys();

		// Page by attachment, not by row: an attachment's rows must be imported
		// together to compute its totals.
		//
		// Deliberately UNFILTERED by status, and count() matches it. Selecting
		// only SUCCESS rows here would reconcile the numbers by making an
		// attachment whose rows are ALL status 3 unreachable — and that branch
		// in migrate_one() is the only producer of skipped_status and
		// skipped_restored, so a reverted image would stop being reported and
		// simply vanish from the report instead.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT attach_id FROM {$table} WHERE attach_id > %d ORDER BY attach_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix-derived literal.
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

		$rows_by_attachment = self::rows_for( $ids );

		foreach ( $ids as $attachment_id ) {
			++$stats['scanned'];
			$outcome = self::migrate_one(
				$attachment_id,
				isset( $rows_by_attachment[ $attachment_id ] ) ? $rows_by_attachment[ $attachment_id ] : array(),
				$dry_run
			);
			foreach ( $outcome as $key => $delta ) {
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
	 * Fetch every row for a batch of attachments in ONE query, grouped in PHP.
	 * One query per batch, not per attachment.
	 *
	 * @param array $ids Attachment IDs.
	 * @return array attach_id => array of row objects.
	 */
	private static function rows_for( array $ids ) {
		global $wpdb;

		if ( empty( $ids ) ) {
			return array();
		}

		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		// $table is prefix-derived and $placeholders is built from %d tokens only;
		// the merged args line up with the tokens.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT attach_id, parent, image_type, size, status, compression_type,
				        compressed_size, original_size, tsOptimized, extra_info
				   FROM {$table}
				  WHERE attach_id IN ( {$placeholders} )
				  ORDER BY attach_id ASC, parent ASC, id ASC",
				$ids
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$grouped = array();
		foreach ( (array) $rows as $row ) {
			$grouped[ (int) $row->attach_id ][] = $row;
		}
		return $grouped;
	}

	/**
	 * Import one attachment. Returns a partial stat delta.
	 *
	 * @param int   $attachment_id Attachment post ID.
	 * @param array $rows          That attachment's source rows.
	 * @param bool  $dry_run       Scan only.
	 * @return array
	 */
	private static function migrate_one( $attachment_id, array $rows, $dry_run ) {
		// Idempotency, cheapest checks first.
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
			// Migrating it would write postmeta the stats bundle never counts,
			// making the reported totals irreconcilable.
			return array( 'skipped_unsupported_mime' => 1 );
		}

		$attached = get_attached_file( $attachment_id );
		if ( ! $attached || ! file_exists( $attached ) ) {
			return array( 'skipped_file_missing' => 1 );
		}
		$dir = trailingslashit( dirname( $attached ) );

		$delta    = array();
		$usable   = array();
		$restored = 0;
		foreach ( $rows as $row ) {
			$status = (int) $row->status;
			if ( self::STATUS_SUCCESS !== $status ) {
				if ( self::STATUS_RESTORED === $status ) {
					++$restored;
				}
				continue;
			}
			$usable[] = $row;
		}

		if ( empty( $usable ) ) {
			// Nothing importable. A wholly-restored attachment is reported under
			// its own counter, since a user may expect those to be migrated.
			return $restored > 0
				? array(
					'skipped_status'   => 1,
					'skipped_restored' => 1,
				)
				: array( 'skipped_status' => 1 );
		}

		$plan = self::build_plan( $attachment_id, $usable, $dir );
		if ( empty( $plan['per_size'] ) ) {
			return array( 'skipped_no_usable_rows' => 1 );
		}

		foreach ( $plan['claim_stats'] as $key => $value ) {
			$delta[ $key ] = ( isset( $delta[ $key ] ) ? $delta[ $key ] : 0 ) + (int) $value;
		}

		if ( $dry_run ) {
			$delta['migrated'] = 1;
			return $delta;
		}

		self::write_meta( $attachment_id, $plan );
		$delta['migrated'] = 1;
		return $delta;
	}

	/**
	 * Build the import plan for one attachment from its SUCCESS rows.
	 *
	 * Per-size figures come from the row (byte-exact, verified against disk).
	 * Next-gen byte counts are NOT in the source — ShortPixel records the sibling
	 * FILENAME only — so each is stat()ed on disk, which doubles as the existence
	 * check. The claim pass runs here so its counters and the per-size webp/avif
	 * figures come from the same verified file list. It is read-only, so a scan
	 * and a real run produce identical claim figures.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param array  $rows          SUCCESS rows.
	 * @param string $dir           Directory holding the attachment's files.
	 * @return array
	 */
	private static function build_plan( $attachment_id, array $rows, $dir ) {
		$per_size    = array();
		$claim_stats = array();
		$ts_latest   = 0;
		$mode        = 'lossy';

		foreach ( $rows as $row ) {
			$is_main  = ( 0 === (int) $row->parent && self::IMAGE_TYPE_THUMB !== (int) $row->image_type );
			$size_key = $is_main
				? Slash_Image_Media_Handler::FULL_SIZE_KEY
				: (string) $row->size;

			if ( '' === $size_key ) {
				continue;
			}

			$original   = (int) $row->original_size;
			$compressed = (int) $row->compressed_size;
			if ( $original <= 0 || $compressed <= 0 ) {
				continue;
			}

			// The live file for this size, whose siblings we claim.
			$source_file = $is_main ? $dir . wp_basename( get_attached_file( $attachment_id ) ) : '';
			if ( ! $is_main ) {
				$source_file = self::thumb_path( $attachment_id, $size_key, $dir );
			}

			$entry = array(
				'original_kb'           => (int) round( $original / 1024 ),
				'optimized_kb'          => (int) round( $compressed / 1024 ),
				'webp_kb'               => 0,
				'avif_kb'               => 0,
				'saved_kb'              => (int) round( max( 0, $original - $compressed ) / 1024 ),
				// Not derivable from ShortPixel: they have no pre-optimized signal,
				// and their convertMeta records THEIR PNG->JPEG feature, not ours.
				'pre_optimized'         => false,
				'png_converted_to_jpeg' => false,
				// Ours-only provenance; empty is the honest value for imported state.
				'wrote'                 => array(),
				'write_failed'          => array(),
			);

			$extra = json_decode( (string) $row->extra_info, true );
			if ( is_array( $extra ) && '' !== $source_file ) {
				foreach ( array( 'webp', 'avif' ) as $format ) {
					$claim = Slash_Image_Migrate_Claim::evaluate( $extra, $format, $dir, $source_file );
					foreach ( $claim['stats'] as $skey => $sval ) {
						$claim_stats[ $skey ] = ( isset( $claim_stats[ $skey ] ) ? $claim_stats[ $skey ] : 0 ) + (int) $sval;
					}
					if ( $claim['bytes'] > 0 ) {
						$entry[ $format . '_kb' ] = (int) round( $claim['bytes'] / 1024 );
					}
				}
			}

			$per_size[ $size_key ] = $entry;

			// ShortPixel's column is camelCase; the sniff can't know it is theirs.
			$ts_optimized = $row->tsOptimized; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Third-party column name.
			$ts           = strtotime( (string) $ts_optimized . ' UTC' );
			if ( $ts && $ts > $ts_latest ) {
				$ts_latest = $ts;
			}

			$mode = self::compression_mode( (int) $row->compression_type );
		}

		return array(
			'per_size'    => $per_size,
			'claim_stats' => $claim_stats,
			'ts'          => $ts_latest,
			'mode'        => $mode,
			'dir'         => $dir,
		);
	}

	/**
	 * Absolute path of a registered size's file, from the attachment metadata.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $size_key      Size name.
	 * @param string $dir           Directory holding the attachment's files.
	 * @return string '' when the size is not in the metadata.
	 */
	private static function thumb_path( $attachment_id, $size_key, $dir ) {
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $meta ) || empty( $meta['sizes'][ $size_key ]['file'] ) ) {
			return '';
		}
		return $dir . $meta['sizes'][ $size_key ]['file'];
	}

	/**
	 * Map ShortPixel's compression_type to our compression_mode vocabulary.
	 *
	 * @param int $type 0 lossless | 1 lossy | 2 glossy.
	 * @return string
	 */
	private static function compression_mode( $type ) {
		switch ( $type ) {
			case 0:
				return 'lossless';
			case 2:
				return 'glossy';
			default:
				return 'lossy';
		}
	}

	/**
	 * Write our postmeta for a migrated attachment.
	 *
	 * Mirrors Slash_Image_Media_Handler::run()'s success block exactly, so the
	 * stats bundle's INNER JOIN, the Media Library column, and the
	 * already-optimized guard in run() all behave identically to a genuine
	 * optimize. Deliberately does NOT write _slash_image_status (a transitional
	 * badge the worker owns) and nothing restore-adjacent: there is no backup,
	 * and a forced re-optimize correctly degrades to the honest no_backup skip.
	 *
	 * @param int   $attachment_id Attachment post ID.
	 * @param array $plan          From build_plan().
	 */
	private static function write_meta( $attachment_id, array $plan ) {
		$per_size = $plan['per_size'];
		$full_key = Slash_Image_Media_Handler::FULL_SIZE_KEY;

		$totals = array(
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
			// Matches the writer: all four variant-size fields are TOTALS
			// (webp_size_kb and webp_total_kb are duplicates there). The
			// main-size figure is read from per_size['full'] by the column.
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
			// Their timestamp, not now(): it is the truth about when these bytes
			// were produced, and it keeps a re-run visibly idempotent.
			'processed_at'          => gmdate( 'c', $plan['ts'] > 0 ? $plan['ts'] : time() ),
			// Display-side provenance, so a renderer holding $data can label it
			// without a second meta read.
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

		// Queryable provenance for later stats segmentation, without
		// unserialising the blob.
		update_post_meta( $attachment_id, Slash_Image_Migrate::META_MIGRATED_FROM, self::slug() );
		update_post_meta( $attachment_id, Slash_Image_Migrate::META_MIGRATED_AT, time() );
	}

	/**
	 * Best-format total bytes, same precedence as
	 * Slash_Image_Media_Handler::compute_best_format_bytes(): per size AVIF, else
	 * WebP, else the original-format optimized bytes.
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
