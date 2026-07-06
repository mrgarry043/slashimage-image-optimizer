<?php
/**
 * Hooks into the WordPress upload pipeline to optimize new attachments.
 *
 * Runs on the wp_generate_attachment_metadata filter, after WordPress has
 * written the original and generated all registered thumbnail sizes. For
 * each size we call the API, write the returned variants to disk, and
 * record an aggregated _slash_image_data meta entry on the attachment.
 *
 * Step 4 ships the destructive write path. Step 5 hooks into the
 * `slash_image_pre_replace_original` action to copy originals into the
 * backups directory before they're overwritten. Until step 5 lands,
 * replacements happen in place with no backup.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Media_Handler {

	const META_DATA_KEY = '_slash_image_data';
	// Flat, SQL-summable per-attachment stats fields. Written on
	// optimize-success alongside META_DATA_KEY; the EXISTENCE of
	// META_SAVED_BYTES_KEY is the "optimized" marker (written even for a
	// pre_optimized / 0-saved success). Deleted in lockstep wherever the blob is
	// removed (restore, full-size failure, exclude-after-optimize).
	const META_SAVED_BYTES_KEY       = '_slash_image_saved_bytes';
	const META_ORIGINAL_BYTES_KEY    = '_slash_image_original_bytes';
	const META_THUMB_COUNT_KEY       = '_slash_image_thumb_count';
	const META_BEST_FORMAT_BYTES_KEY = '_slash_image_best_format_bytes';
	// One-shot per-image compression-mode override, set by a meta-box "Re-optimize
	// as …" link. run() reads it for this attachment's API calls, records it in the
	// stored blob, and deletes it on the terminal outcome so later upload/bulk runs
	// fall back to the global compression_mode setting.
	const META_MODE_OVERRIDE_KEY = '_slash_image_mode_override';
	const FULL_SIZE_KEY          = 'full';
	const TMP_SUFFIX             = '.simg.tmp';
	// Codes that doom the whole attachment (and the whole run): once any size
	// returns one, the remaining sizes would fail identically, so run() breaks
	// the per-size loop instead of burning more API calls. The worker pauses the
	// bulk run on invalid_key / payment_required (see maybe_halt_on_account_error).
	const FATAL_ATTACHMENT_CODES = array( 'no_api_key', 'payment_required', 'invalid_key' );

	public function __construct() {
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'process_attachment' ), 10, 2 );
	}

	/**
	 * Remove the flat stats fields. Called in lockstep with any
	 * removal of the optimized state so the SQL aggregates can't double-count.
	 * Sites: full-success restore, full-size failure, exclude-after-optimize.
	 * (Permanent attachment delete needs no call — WP cascades postmeta.)
	 */
	public static function delete_flat_stats_fields( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return;
		}
		delete_post_meta( $attachment_id, self::META_SAVED_BYTES_KEY );
		delete_post_meta( $attachment_id, self::META_ORIGINAL_BYTES_KEY );
		delete_post_meta( $attachment_id, self::META_THUMB_COUNT_KEY );
		delete_post_meta( $attachment_id, self::META_BEST_FORMAT_BYTES_KEY );
	}

	/**
	 * Pure fit-inside target math for the WP-side resize step.
	 *
	 * Delegates the aspect-preserving arithmetic to WordPress core's
	 * wp_constrain_dimensions() (which never upscales), then returns the target
	 * only when it is an actual shrink. Returns null for an image already inside
	 * the box, a non-positive current size, or both boxes unset — all "do not resize".
	 *
	 * @param int $cur_w Current width in px.
	 * @param int $cur_h Current height in px.
	 * @param int $max_w Max width box in px; 0 = no limit on this side.
	 * @param int $max_h Max height box in px; 0 = no limit on this side.
	 * @return array{0:int,1:int}|null [ $width, $height ] to resize to, or null for no-op.
	 */
	public static function compute_resize_target( $cur_w, $cur_h, $max_w, $max_h ) {
		$cur_w = (int) $cur_w;
		$cur_h = (int) $cur_h;
		$max_w = (int) $max_w;
		$max_h = (int) $max_h;

		// A 0 on one side = "no limit there" — wp_constrain_dimensions() handles it
		// natively. Only a non-positive current size, or BOTH boxes unset, is a no-op.
		if ( $cur_w <= 0 || $cur_h <= 0 || ( $max_w <= 0 && $max_h <= 0 ) ) {
			return null;
		}

		list( $new_w, $new_h ) = wp_constrain_dimensions( $cur_w, $cur_h, $max_w, $max_h );
		$new_w                 = (int) $new_w;
		$new_h                 = (int) $new_h;

		// wp_constrain_dimensions() never upscales, so an unchanged result means
		// the image already fits the box — a no-op.
		if ( $new_w >= $cur_w && $new_h >= $cur_h ) {
			return null;
		}

		return array( max( 1, $new_w ), max( 1, $new_h ) );
	}

	/**
	 * A1 orientation guard: skip the WP-side resize for a JPEG that still carries
	 * a live EXIF Orientation of 2-8.
	 *
	 * Such a file would be re-encoded by the image editor, which drops the tag and
	 * leaves the pixels in stored (un-rotated) orientation — displaying sideways.
	 * Skipping lets the file reach the API unchanged, where the jpegtran path
	 * rotates it losslessly. Orientation 1 / absent (0) / out-of-range, and every
	 * non-JPEG format, are not skipped.
	 *
	 * @param string $mime        Attachment MIME type.
	 * @param int    $orientation EXIF Orientation value (0 when absent/unknown).
	 * @return bool True to skip resize.
	 */
	public static function should_skip_resize_for_orientation( $mime, $orientation ) {
		if ( 'image/jpeg' !== (string) $mime ) {
			return false;
		}
		$orientation = (int) $orientation;
		return ( $orientation >= 2 && $orientation <= 8 );
	}

	/**
	 * Composed gate for the worker resize step. Resize iff the setting is on, the
	 * image editor can load the file, the orientation guard did not skip, and there
	 * is a real downscale target. Any miss is a no-op.
	 *
	 * @param bool       $enabled          resize_on_upload setting.
	 * @param bool       $editor_ok        wp_get_image_editor() returned an editor.
	 * @param array|null $target           compute_resize_target() result.
	 * @param bool       $orientation_skip should_skip_resize_for_orientation() result.
	 * @return bool
	 */
	public static function should_resize_full( $enabled, $editor_ok, $target, $orientation_skip ) {
		return (bool) $enabled
			&& (bool) $editor_ok
			&& ! (bool) $orientation_skip
			&& null !== $target;
	}

	/**
	 * Resize the full-size file in place before its API call.
	 *
	 * Runs for the 'full' target only, just before the per-size optimize loop, so
	 * it covers BOTH new uploads and bulk/existing images (everything flows through
	 * the worker → reprocess_attachment → run()). The locked ordering is:
	 *   1. DECIDE — resize iff enabled AND the editor can load the file AND the box
	 *      would shrink it AND the A1 orientation guard does not skip. Any miss is a
	 *      complete no-op (zero behaviour change; fall through to optimize as-is).
	 *   2. BACK UP the true original via the slash_image_pre_replace_original action
	 *      (idempotent + keep_backups-gated) so the backup is the PRE-resize file.
	 *   3. RESIZE to a temp file at quality 90 (instance-scoped, so WordPress
	 *      thumbnail quality is untouched); preserve metadata when the setting is on.
	 *   4. ATOMIC MOVE the temp over the live full-size.
	 *   5. PERSIST the new dimensions immediately (independent of optimize outcome).
	 * A WP_Error editor or a failed resize/save is swallowed — the un-resized file
	 * is optimized rather than failing the attachment.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $full_path     Absolute path to the full-size file.
	 * @param array  $metadata      Attachment metadata (mutated in place: width/height).
	 * @return void
	 */
	private function maybe_resize_full( $attachment_id, $full_path, array &$metadata ) {
		// GIF: never resize. Resizing re-encodes and would flatten animation;
		// GIFs are sent to the API at their original dimensions.
		if ( 'image/gif' === (string) get_post_mime_type( $attachment_id ) ) {
			return;
		}

		if ( ! (bool) Slash_Image_Settings::get( 'resize_on_upload', false ) ) {
			return;
		}

		$max_w = (int) Slash_Image_Settings::get( 'max_width', 1560 );
		$max_h = (int) Slash_Image_Settings::get( 'max_height', 1560 );
		// At least one side must be set; both unset (0) = nothing to constrain.
		if ( $max_w <= 0 && $max_h <= 0 ) {
			return;
		}

		$editor    = wp_get_image_editor( $full_path );
		$editor_ok = ! is_wp_error( $editor );

		$target = null;
		if ( $editor_ok ) {
			$size   = $editor->get_size();
			$cur_w  = is_array( $size ) && isset( $size['width'] ) ? (int) $size['width'] : 0;
			$cur_h  = is_array( $size ) && isset( $size['height'] ) ? (int) $size['height'] : 0;
			$target = self::compute_resize_target( $cur_w, $cur_h, $max_w, $max_h );
		}

		// A1 orientation guard — JPEG only.
		$mime             = (string) get_post_mime_type( $attachment_id );
		$orientation_skip = false;
		if ( 'image/jpeg' === $mime ) {
			$orientation      = self::read_jpeg_orientation( $full_path );
			$orientation_skip = self::should_skip_resize_for_orientation( $mime, $orientation );
		}

		if ( ! self::should_resize_full( true, $editor_ok, $target, $orientation_skip ) ) {
			return;
		}

		// Back up the true (pre-resize) original. Idempotent + keep_backups-gated:
		// a no-op when a backup already exists or backups are disabled.
		do_action( 'slash_image_pre_replace_original', $attachment_id, self::FULL_SIZE_KEY, $full_path );

		// D: keep EXIF/IPTC/XMP across the resize save when the user opted in. The
		// filter is scoped to this save only (Imagick honours it; harmless on GD).
		$preserve = (bool) Slash_Image_Settings::get( 'preserve_metadata', false );
		if ( $preserve ) {
			add_filter( 'image_strip_meta', '__return_false', 789 );
		}

		$resized = $editor->resize( $target[0], $target[1], false );
		if ( is_wp_error( $resized ) ) {
			if ( $preserve ) {
				remove_filter( 'image_strip_meta', '__return_false', 789 );
			}
			return;
		}

		// Quality 90 on this editor instance only — minimises generation-1 loss
		// before the API's second lossy pass, without touching WP thumbnail quality.
		$editor->set_quality( 90 );

		$tmp_path = $editor->generate_filename( 'simg-resized' );
		$saved    = $editor->save( $tmp_path );

		if ( $preserve ) {
			remove_filter( 'image_strip_meta', '__return_false', 789 );
		}

		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! file_exists( $saved['path'] ) ) {
			if ( ! empty( $saved['path'] ) && file_exists( $saved['path'] ) ) {
				wp_delete_file( $saved['path'] );
			}
			return;
		}

		if ( ! $this->move_into_place( $saved['path'], $full_path ) ) {
			wp_delete_file( $saved['path'] );
			return;
		}

		$this->persist_full_dimensions( $attachment_id, $metadata, (int) $target[0], (int) $target[1] );
	}

	/**
	 * Atomically move a freshly-written temp file over the live full-size,
	 * preserving the destination's permissions. Mirrors write_atomic()'s rename.
	 *
	 * @param string $from Temp source path.
	 * @param string $to   Destination (live) path.
	 * @return bool
	 */
	private function move_into_place( $from, $to ) {
		if ( ! file_exists( $from ) ) {
			return false;
		}

		if ( file_exists( $to ) ) {
			$existing_perms = @fileperms( $to );
			if ( false !== $existing_perms ) {
				@chmod( $from, $existing_perms & 0777 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Direct filesystem op on a wp-content/uploads path the plugin requires direct write access to; runs in background/cron where WP_Filesystem credential init is unreliable.
			}
		}

		if ( ! @rename( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Direct filesystem op on a wp-content/uploads path the plugin requires direct write access to; runs in background/cron where WP_Filesystem credential init is unreliable.
			return false;
		}

		clearstatcache( true, $to );
		return true;
	}

	/**
	 * Persist the full-size's new dimensions to _wp_attachment_metadata
	 * immediately, independent of the optimize outcome — so a later full-size
	 * optimize failure (early return) cannot leave stale dimensions on disk.
	 * Also mutates the in-memory metadata so the success path stays consistent.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $metadata      Metadata (mutated in place).
	 * @param int   $width         New full-size width.
	 * @param int   $height        New full-size height.
	 * @return void
	 */
	private function persist_full_dimensions( $attachment_id, array &$metadata, $width, $height ) {
		$metadata['width']  = (int) $width;
		$metadata['height'] = (int) $height;
		wp_update_attachment_metadata( (int) $attachment_id, $metadata );
	}

	/**
	 * Read a JPEG's EXIF Orientation for the A1 guard. Returns 0 when absent or
	 * undeterminable (no exif extension) — which the guard treats as "proceed".
	 *
	 * @param string $path Absolute file path.
	 * @return int Orientation value (0 when unknown).
	 */
	private static function read_jpeg_orientation( $path ) {
		if ( ! function_exists( 'wp_read_image_metadata' ) ) {
			$image_include = ABSPATH . 'wp-admin/includes/image.php';
			if ( is_readable( $image_include ) ) {
				require_once $image_include;
			}
		}

		if ( function_exists( 'wp_read_image_metadata' ) ) {
			$meta = wp_read_image_metadata( $path );
			if ( is_array( $meta ) && isset( $meta['orientation'] ) ) {
				return (int) $meta['orientation'];
			}
		}

		if ( function_exists( 'exif_read_data' ) ) {
			$exif = @exif_read_data( $path );
			if ( is_array( $exif ) && isset( $exif['Orientation'] ) ) {
				return (int) $exif['Orientation'];
			}
		}

		return 0;
	}

	public function process_attachment( $metadata, $attachment_id ) {
		// Upload-time path. Returns immediately so the upload UI doesn't block.
		// The actual API call happens later in the new-uploads cron / AJAX driver.
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return $metadata;
		}

		// Skip if auto-optimize is disabled — user must trigger manually.
		if ( ! (bool) Slash_Image_Settings::get( 'auto_optimize_uploads', true ) ) {
			return $metadata;
		}

		// Skip unsupported MIMEs immediately so we don't pollute the queue or
		// call the API for a format the server can't process (SVG, PDF, …).
		$mime = get_post_mime_type( $attachment_id );
		if ( ! Slash_Image_Api_Client::is_supported_mime( $mime ) ) {
			return $metadata;
		}

		// Skip if already optimized (re-uploads / regenerate-thumbnails plugins).
		$existing = get_post_meta( $attachment_id, self::META_DATA_KEY, true );
		if ( is_array( $existing ) && ! empty( $existing['optimized'] ) ) {
			return $metadata;
		}

		Slash_Image_Bulk_Processor::enqueue_new_upload( $attachment_id );

		return $metadata;
	}

	/**
	 * Public re-entry point for bulk processing and Media Library "Re-optimize" actions.
	 *
	 * @param int  $attachment_id Attachment post ID.
	 * @param bool $force         When true, ignore _slash_image_data['optimized'] guard.
	 * @return array              ['ok' => bool, 'code' => string, 'metadata' => ?array]
	 */
	public static function reprocess_attachment( $attachment_id, $force = false ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return array(
				'ok'   => false,
				'code' => 'attachment_missing',
			);
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $metadata ) ) {
			$metadata = array();
		}

		$result = self::run( $attachment_id, $metadata, (bool) $force );

		if ( ! empty( $result['ok'] ) && ! empty( $result['metadata'] ) ) {
			wp_update_attachment_metadata( $attachment_id, $result['metadata'] );
		}

		return $result;
	}

	private static function run( $attachment_id, array $metadata, $force ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return array(
				'ok'   => false,
				'code' => 'attachment_missing',
			);
		}

		if ( '' === (string) Slash_Image_Settings::get( 'api_key', '' ) ) {
			return array(
				'ok'   => false,
				'code' => 'no_api_key',
			);
		}

		$existing = get_post_meta( $attachment_id, self::META_DATA_KEY, true );
		if ( ! $force && is_array( $existing ) && ! empty( $existing['optimized'] ) ) {
			return array(
				'ok'       => true,
				'code'     => 'already_optimized',
				'metadata' => $metadata,
			);
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( ! Slash_Image_Api_Client::is_supported_mime( $mime ) ) {
			return array(
				'ok'   => false,
				'code' => 'unsupported_mime',
			);
		}

		if ( ! apply_filters( 'slash_image_should_optimize', true, $attachment_id, $metadata ) ) {
			return array(
				'ok'   => false,
				'code' => 'filtered_out',
			);
		}

		$original_path = get_attached_file( $attachment_id );
		if ( ! is_string( $original_path ) || '' === $original_path ) {
			return array(
				'ok'   => false,
				'code' => 'attachment_missing',
			);
		}
		if ( ! file_exists( $original_path ) ) {
			return array(
				'ok'   => false,
				'code' => 'attachment_missing',
			);
		}
		if ( ! is_readable( $original_path ) ) {
			return array(
				'ok'   => false,
				'code' => 'file_unreadable',
			);
		}

		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['basedir'] ) && 0 !== strpos( $original_path, trailingslashit( $uploads['basedir'] ) ) ) {
			return array(
				'ok'   => false,
				'code' => 'file_outside_uploads',
			);
		}

		$instance = new self();
		$targets  = $instance->build_targets( $original_path, $metadata, $mime );

		// WP-side resize: downscale the full-size in place (back up the true
		// original first, update dimensions) before any API call. No-op when
		// resize is off, the image already fits, the editor can't load it, or the
		// A1 orientation guard skips. Only when 'full' is an actual target (the
		// user can exclude the original via size exclusions).
		if ( isset( $targets[ self::FULL_SIZE_KEY ] ) ) {
			$instance->maybe_resize_full( $attachment_id, $targets[ self::FULL_SIZE_KEY ], $metadata );
		}

		$per_size        = array();
		$failed_sizes    = array();
		$totals          = array(
			'original_kb'  => 0,
			'optimized_kb' => 0,
			'webp_kb'      => 0,
			'avif_kb'      => 0,
			'saved_kb'     => 0,
		);
		$any_success            = false;
		$first_fail_code        = '';
		$first_fail_message     = '';
		$first_fail_retry_after = 0;
		$first_fail_api_code    = '';
		// Latest X-Images-Remaining seen across this image's per-size optimize
		// calls. The last successful size wins (it reflects the server credit
		// counter after all of this image's decrements); applied once below.
		$last_remaining = null;

		// One-shot per-image compression-mode override (meta-box "Re-optimize as …").
		// Applied to every size this run; recorded in the blob; cleared on the
		// terminal outcome below.
		$mode_override    = self::read_mode_override( $attachment_id );
		$optimize_options = ( null !== $mode_override )
			? array( 'compression_mode' => $mode_override )
			: array();

		// GIF: the API never makes AVIF for a GIF, so the WebP and AVIF toggles act
		// as a single "make a modern variant" switch. Force the WebP request on when
		// EITHER toggle is on (off only when both are off); generate_avif is left
		// as-is — the API ignores it for GIF.
		if ( 'image/gif' === (string) $mime ) {
			$optimize_options['generate_webp'] =
				(bool) Slash_Image_Settings::get( 'generate_webp', true )
				|| (bool) Slash_Image_Settings::get( 'generate_avif', true );
		}

		foreach ( $targets as $size_key => $abs_path ) {
			$kind       = ( self::FULL_SIZE_KEY === $size_key ) ? 'main' : 'thumbnail';
			$api_result = Slash_Image_Api_Client::optimize( $abs_path, $optimize_options, $kind );

			if ( empty( $api_result['ok'] ) ) {
				$code            = isset( $api_result['code'] ) ? (string) $api_result['code'] : 'unknown';
				$api_message     = isset( $api_result['message'] ) ? (string) $api_result['message'] : '';
				$api_retry_after = isset( $api_result['retry_after'] ) ? (int) $api_result['retry_after'] : 0;
				// Raw API code (uncollapsed) — distinguishes genuine invalid_key from
				// the domain codes for the worker's status-flip decision.
				$api_code = isset( $api_result['api_code'] ) ? (string) $api_result['api_code'] : '';

				$failed_sizes[ $size_key ] = array(
					'code'        => $code,
					'message'     => $api_message,
					'retry_after' => $api_retry_after,
					'api_code'    => $api_code,
				);
				if ( '' === $first_fail_code ) {
					$first_fail_code        = $code;
					$first_fail_message     = $api_message;
					$first_fail_retry_after = $api_retry_after;
					$first_fail_api_code    = $api_code;
				}
				if ( in_array( $code, self::FATAL_ATTACHMENT_CODES, true ) ) {
					break;
				}
				continue;
			}

			$data         = $api_result['data'];
			$write_result = $instance->apply_variants( $attachment_id, $size_key, $abs_path, $data );

			$variant_kb = function ( $variant ) {
				return isset( $variant['size'] ) ? (int) round( $variant['size'] / 1024 ) : 0;
			};
			$variants   = isset( $data['variants'] ) ? $data['variants'] : array();

			$per_size[ $size_key ] = array(
				'original_kb'           => (int) $data['original_size_kb'],
				'optimized_kb'          => isset( $variants['original_format'] ) ? $variant_kb( $variants['original_format'] ) : 0,
				'webp_kb'               => isset( $variants['webp'] ) ? $variant_kb( $variants['webp'] ) : 0,
				'avif_kb'               => isset( $variants['avif'] ) ? $variant_kb( $variants['avif'] ) : 0,
				'saved_kb'              => (int) $data['saved_kb'],
				'pre_optimized'         => ! empty( $data['pre_optimized'] ),
				'png_converted_to_jpeg' => ! empty( $data['png_converted_to_jpeg'] ),
				'wrote'                 => $write_result['wrote'],
				'write_failed'          => $write_result['failed'],
			);

			$totals['original_kb']  += $per_size[ $size_key ]['original_kb'];
			$totals['optimized_kb'] += $per_size[ $size_key ]['optimized_kb'];
			$totals['webp_kb']      += $per_size[ $size_key ]['webp_kb'];
			$totals['avif_kb']      += $per_size[ $size_key ]['avif_kb'];
			$totals['saved_kb']     += $per_size[ $size_key ]['saved_kb'];

			$any_success = true;

			if ( isset( $api_result['images_remaining'] ) && null !== $api_result['images_remaining'] ) {
				$last_remaining = (string) $api_result['images_remaining'];
			}
		}

		// The full/primary size is the image users see and the frontend serves.
		// If it failed (e.g. timed out) the attachment is NOT optimized — even
		// when thumbnails succeeded. Fail the whole attachment with the primary's
		// code so the queue row is marked failed and the Media Library shows the
		// error + Retry, never a bogus "already optimal" + saving %
		// derived from thumbnails alone. Clear any stale optimized meta from an
		// earlier (buggy) pass so it isn't counted as optimized or rendered.
		if ( isset( $failed_sizes[ self::FULL_SIZE_KEY ] ) ) {
			delete_post_meta( $attachment_id, self::META_DATA_KEY );
			self::delete_flat_stats_fields( $attachment_id );
			delete_post_meta( $attachment_id, self::META_MODE_OVERRIDE_KEY );
			$full_fail = $failed_sizes[ self::FULL_SIZE_KEY ];
			return array(
				'ok'          => false,
				'code'        => (string) ( $full_fail['code'] ?? 'unknown' ),
				'message'     => (string) ( $full_fail['message'] ?? '' ),
				'retry_after' => (int) ( $full_fail['retry_after'] ?? 0 ),
				'api_code'    => (string) ( $full_fail['api_code'] ?? '' ),
			);
		}

		if ( $any_success ) {
			$metadata = $instance->refresh_filesizes( $original_path, $metadata );

			// Aggregate percent (across original + thumbnails). Float so we don't lose
			// precision in the formatter when computing per-format savings.
			$saved_percent_overall = ( $totals['original_kb'] > 0 )
				? round( ( $totals['saved_kb'] / $totals['original_kb'] ) * 100, 2 )
				: 0;

			// Main image (full size) breakdown — reads from per_size['full'].
			$full_entry         = isset( $per_size[ self::FULL_SIZE_KEY ] ) ? $per_size[ self::FULL_SIZE_KEY ] : array();
			$main_original_kb   = (int) ( $full_entry['original_kb'] ?? 0 );
			$main_optimized_kb  = (int) ( $full_entry['optimized_kb'] ?? 0 );
			$main_saved_percent = ( $main_original_kb > 0 )
				? round( ( ( $main_original_kb - $main_optimized_kb ) / $main_original_kb ) * 100, 2 )
				: 0;
			$main_pre_optimized = ! empty( $full_entry['pre_optimized'] );

			// Counts: thumbnails processed (excludes 'full'), variant counts (per-size kb > 0).
			$thumbnails_processed = 0;
			$webp_count           = 0;
			$avif_count           = 0;
			foreach ( $per_size as $size_key => $entry ) {
				if ( self::FULL_SIZE_KEY !== $size_key ) {
					++$thumbnails_processed;
				}
				if ( ! empty( $entry['webp_kb'] ) ) {
					++$webp_count;
				}
				if ( ! empty( $entry['avif_kb'] ) ) {
					++$avif_count;
				}
			}

			// "Already optimal" only true when the MAIN image was pre-optimized AND
			// the main image had effectively zero reduction. Either flag alone is not
			// enough — a thumbnail being pre-optimized while the main image was reduced
			// produced inconsistent UI in earlier builds.
			$attachment_pre_optimized = $main_pre_optimized && ( $main_saved_percent < 0.01 );

			$data_meta = array(
				'optimized'             => true,
				'compression_mode'      => ( null !== $mode_override ) ? $mode_override : (string) Slash_Image_Settings::get( 'compression_mode', 'lossy' ),
				'pre_optimized'         => $attachment_pre_optimized,
				'png_converted_to_jpeg' => self::any_png_to_jpeg( $per_size ),
				// Aggregates (existing fields, kept for back-compat).
				'original_size_kb'      => $totals['original_kb'],
				'optimized_size_kb'     => $totals['optimized_kb'],
				'webp_size_kb'          => $totals['webp_kb'],
				'avif_size_kb'          => $totals['avif_kb'],
				'saved_kb'              => $totals['saved_kb'],
				'saved_percent'         => (int) round( $saved_percent_overall ),
				// Fields used by Slash_Image_Data_Formatter.
				'original_size_bytes'   => $main_original_kb * 1024,
				'optimized_size_bytes'  => $main_optimized_kb * 1024,
				'saved_percent_main'    => $main_saved_percent,
				'saved_percent_overall' => $saved_percent_overall,
				'thumbnails_processed'  => $thumbnails_processed,
				'webp_count'            => $webp_count,
				'avif_count'            => $avif_count,
				'webp_total_kb'         => (int) $totals['webp_kb'],
				'avif_total_kb'         => (int) $totals['avif_kb'],
				// Provenance.
				'processed_sizes'       => array_keys( $per_size ),
				'failed_sizes'          => $failed_sizes,
				'per_size'              => $per_size,
				// File-level state for the Bulk Optimize stat counts.
				'sizes_processed'       => array_keys( $per_size ),
				'sizes_failed'          => array_keys( $failed_sizes ),
				'processed_at'          => gmdate( 'c' ),
			);

			update_post_meta( $attachment_id, self::META_DATA_KEY, $data_meta );

			// Flat, SQL-summable stats fields. Written for EVERY
			// optimize-success including pre_optimized / 0-saved (so saved=0 is
			// still written — existence is the "optimized" marker). KB→bytes to
			// match the blob-derived totals within rounding.
			update_post_meta( $attachment_id, self::META_SAVED_BYTES_KEY, (int) $totals['saved_kb'] * 1024 );
			update_post_meta( $attachment_id, self::META_ORIGINAL_BYTES_KEY, (int) $totals['original_kb'] * 1024 );
			update_post_meta( $attachment_id, self::META_THUMB_COUNT_KEY, (int) $thumbnails_processed );
			update_post_meta(
				$attachment_id,
				self::META_BEST_FORMAT_BYTES_KEY,
				self::compute_best_format_bytes( $per_size )
			);

			// One-shot override consumed — clear it so later runs use the global mode.
			delete_post_meta( $attachment_id, self::META_MODE_OVERRIDE_KEY );

			// Live credits: patch the local plan cache with this image's latest
			// X-Images-Remaining (no API call, one write per image). A no-op when
			// no plan is cached yet — the daily sync / next key-save populates it.
			if ( null !== $last_remaining ) {
				Slash_Image_Connection::update_remaining( $last_remaining );
			}

			do_action( 'slash_image_attachment_processed', $attachment_id, $data_meta );

			return array(
				'ok'       => true,
				'code'     => 'optimized',
				'metadata' => $metadata,
			);
		}

		return array(
			'ok'          => false,
			'code'        => '' !== $first_fail_code ? $first_fail_code : 'no_sizes_processable',
			'message'     => $first_fail_message,
			'retry_after' => $first_fail_retry_after,
			'api_code'    => $first_fail_api_code,
		);
	}

	/**
	 * Read + validate the one-shot per-image compression-mode override, or null
	 * when absent / not one of the three supported modes.
	 */
	private static function read_mode_override( $attachment_id ) {
		$mode = (string) get_post_meta( (int) $attachment_id, self::META_MODE_OVERRIDE_KEY, true );
		return in_array( $mode, array( 'lossy', 'glossy', 'lossless' ), true ) ? $mode : null;
	}

	private static function any_png_to_jpeg( array $per_size ) {
		foreach ( $per_size as $entry ) {
			if ( ! empty( $entry['png_converted_to_jpeg'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Best-format total bytes across all processed sizes.
	 * Per size: AVIF if available, else WebP, else original-format optimized.
	 * Powers the Bulk Optimize page's best-format savings, mirroring the Media
	 * Library column's AVIF → WebP → original headline.
	 */
	private static function compute_best_format_bytes( array $per_size ) {
		$total = 0;
		foreach ( $per_size as $size_data ) {
			if ( ! empty( $size_data['avif_kb'] ) ) {
				$total += (int) $size_data['avif_kb'];
			} elseif ( ! empty( $size_data['webp_kb'] ) ) {
				$total += (int) $size_data['webp_kb'];
			} else {
				$total += (int) ( $size_data['optimized_kb'] ?? 0 );
			}
		}
		return $total * 1024;
	}

	private function build_targets( $original_path, array $metadata, $mime = '' ) {
		$dir     = trailingslashit( dirname( $original_path ) );
		$targets = array( self::FULL_SIZE_KEY => $original_path );

		// GIF: optimize the full-size only. WordPress flattens GIF thumbnails to
		// a static first frame, so there is no animation to preserve at those
		// sizes — we send only the animated full-size to the API.
		if ( (bool) Slash_Image_Settings::get( 'optimize_all_sizes', true )
			&& 'image/gif' !== (string) $mime
			&& ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_key => $size_data ) {
				if ( empty( $size_data['file'] ) ) {
					continue;
				}
				$abs = $dir . wp_basename( (string) $size_data['file'] );
				if ( $abs === $original_path ) {
					continue;
				}
				if ( is_readable( $abs ) && ! isset( $targets[ $size_key ] ) ) {
					$targets[ $size_key ] = $abs;
				}
			}
		}

		// Apply per-size user exclusions (Settings → Image Size Exclusions).
		// 'full' is filterable too — when the user excludes the original,
		// thumbnails are still optimized but the original file is preserved.
		return Slash_Image_Exclusions::filter_targets( $targets );
	}

	private function apply_variants( $attachment_id, $size_key, $original_path, array $data ) {
		$wrote    = array();
		$failed   = array();
		$variants = isset( $data['variants'] ) ? $data['variants'] : array();

		if ( isset( $variants['original_format']['bytes'] ) ) {
			if ( ! empty( $data['pre_optimized'] ) ) {
				$wrote['original_format'] = 'skipped_pre_optimized';
			} else {
				do_action( 'slash_image_pre_replace_original', $attachment_id, $size_key, $original_path );
				if ( $this->write_atomic( $original_path, $variants['original_format']['bytes'] ) ) {
					$wrote['original_format'] = $original_path;
					do_action( 'slash_image_replaced_original', $attachment_id, $size_key, $original_path );
				} else {
					$failed['original_format'] = 'write_failed';
				}
			}
		}

		foreach ( array( 'webp', 'avif' ) as $variant_key ) {
			if ( ! isset( $variants[ $variant_key ]['bytes'] ) ) {
				continue;
			}
			$variant_path = $original_path . '.' . $variant_key;
			if ( $this->write_atomic( $variant_path, $variants[ $variant_key ]['bytes'] ) ) {
				$wrote[ $variant_key ] = $variant_path;
			} else {
				$failed[ $variant_key ] = 'write_failed';
			}
		}

		return array(
			'wrote'  => $wrote,
			'failed' => $failed,
		);
	}

	private function write_atomic( $path, $bytes ) {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
			return false;
		}

		$tmp = @tempnam( $dir, wp_basename( $path ) . '.' );
		if ( false === $tmp ) {
			return false;
		}

		$written = @file_put_contents( $tmp, $bytes, LOCK_EX );
		if ( false === $written || strlen( $bytes ) !== $written ) {
			wp_delete_file( $tmp );
			return false;
		}

		if ( file_exists( $path ) ) {
			$existing_perms = @fileperms( $path );
			if ( false !== $existing_perms ) {
				@chmod( $tmp, $existing_perms & 0777 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Direct filesystem op on a wp-content/uploads path the plugin requires direct write access to; runs in background/cron where WP_Filesystem credential init is unreliable.
			}
		} else {
			@chmod( $tmp, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Direct filesystem op on a wp-content/uploads path the plugin requires direct write access to; runs in background/cron where WP_Filesystem credential init is unreliable.
		}

		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Direct filesystem op on a wp-content/uploads path the plugin requires direct write access to; runs in background/cron where WP_Filesystem credential init is unreliable.
			wp_delete_file( $tmp );
			return false;
		}

		clearstatcache( true, $path );
		return true;
	}

	private function refresh_filesizes( $original_path, array $metadata ) {
		clearstatcache();
		$size = @filesize( $original_path );
		if ( false !== $size ) {
			$metadata['filesize'] = (int) $size;
		}

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$dir = trailingslashit( dirname( $original_path ) );
			foreach ( $metadata['sizes'] as $size_key => $size_data ) {
				if ( empty( $size_data['file'] ) ) {
					continue;
				}
				$abs = $dir . wp_basename( (string) $size_data['file'] );
				$sz  = @filesize( $abs );
				if ( false !== $sz ) {
					$metadata['sizes'][ $size_key ]['filesize'] = (int) $sz;
				}
			}
		}

		return $metadata;
	}
}
