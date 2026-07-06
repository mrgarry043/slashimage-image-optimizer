<?php
/**
 * Per-site optimization stats. Counted locally, never displays plan or
 * subscription info.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Stats {

	const OPT_IMAGES_OPTIMIZED     = 'slash_image_stats_images_optimized';
	const OPT_THUMBNAILS_OPTIMIZED = 'slash_image_stats_thumbnails_optimized';
	const OPT_TOTAL_SAVED_BYTES    = 'slash_image_stats_total_saved_bytes';
	const OPT_LAST_UPDATED         = 'slash_image_stats_last_updated';

	public static function record_optimization( $kind, $saved_bytes ) {
		$kind        = ( 'main' === $kind ) ? 'main' : 'thumbnail';
		$saved_bytes = max( 0, (int) $saved_bytes );

		$counter_option = ( 'main' === $kind ) ? self::OPT_IMAGES_OPTIMIZED : self::OPT_THUMBNAILS_OPTIMIZED;

		update_option( $counter_option, ( (int) get_option( $counter_option, 0 ) ) + 1, false );
		update_option( self::OPT_TOTAL_SAVED_BYTES, ( (int) get_option( self::OPT_TOTAL_SAVED_BYTES, 0 ) ) + $saved_bytes, false );
		update_option( self::OPT_LAST_UPDATED, time(), false );
	}

	public static function reset() {
		delete_option( self::OPT_IMAGES_OPTIMIZED );
		delete_option( self::OPT_THUMBNAILS_OPTIMIZED );
		delete_option( self::OPT_TOTAL_SAVED_BYTES );
		delete_option( self::OPT_LAST_UPDATED );
	}

	/**
	 * Returns the dashboard's stats view. Derived from current postmeta
	 * state via Slash_Image_Bulk_Processor::library_counts() and
	 * aggregate_size_totals() — counts decrease when an attachment is
	 * restored (its _slash_image_data meta is cleared on restore), so the
	 * dashboard always reflects what's currently optimized.
	 *
	 * The cumulative options (OPT_IMAGES_OPTIMIZED / OPT_THUMBNAILS_OPTIMIZED
	 * / OPT_TOTAL_SAVED_BYTES) are still written by record_optimization for
	 * backwards compatibility but no longer drive the UI.
	 */
	public static function snapshot() {
		$updated = (int) get_option( self::OPT_LAST_UPDATED, 0 );

		if ( class_exists( 'Slash_Image_Bulk_Processor' ) ) {
			$counts = Slash_Image_Bulk_Processor::library_counts();
			$totals = Slash_Image_Bulk_Processor::aggregate_size_totals();

			$images     = (int) ( $counts['optimized'] ?? 0 );
			$thumbnails = (int) ( $counts['thumbnails_optimized'] ?? 0 );
			$saved      = max( 0, (int) ( $totals['original_bytes'] ?? 0 ) - (int) ( $totals['optimized_bytes'] ?? 0 ) );

			return array(
				'images_optimized'     => $images,
				'thumbnails_optimized' => $thumbnails,
				'total_optimized'      => $images + $thumbnails,
				'total_saved_bytes'    => $saved,
				'last_updated'         => $updated,
			);
		}

		// Fallback (used during early bootstrap before the bulk processor
		// class is autoloaded — should rarely hit in practice).
		$images     = (int) get_option( self::OPT_IMAGES_OPTIMIZED, 0 );
		$thumbnails = (int) get_option( self::OPT_THUMBNAILS_OPTIMIZED, 0 );
		$saved      = (int) get_option( self::OPT_TOTAL_SAVED_BYTES, 0 );

		return array(
			'images_optimized'     => $images,
			'thumbnails_optimized' => $thumbnails,
			'total_optimized'      => $images + $thumbnails,
			'total_saved_bytes'    => $saved,
			'last_updated'         => $updated,
		);
	}
}
