<?php
/**
 * Exclusion gate. Two layers:
 *
 *   - Image Size Exclusions (per-size opt-out): drops specific size keys
 *     from the targets list inside Slash_Image_Media_Handler::build_targets.
 *   - Custom Exclusions (per-attachment opt-out): runs in the worker before
 *     the API call. Matches case-insensitive substring against the
 *     attachment's filename basename and full URL path.
 *
 * Power-user escape hatch:
 *   apply_filters( 'slash_image_should_skip_attachment', false, $attachment_id, $filename, $url )
 *
 * If the filter returns true (or a non-empty string), the attachment is
 * skipped with reason 'developer_filter' (or that string used as the
 * exclusion pattern).
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Exclusions {

	const REASON_PATTERN = 'custom_pattern';
	const REASON_FILTER  = 'developer_filter';

	/**
	 * Returns ['reason' => ..., 'pattern' => ...] when the attachment should
	 * be skipped, or null when it shouldn't.
	 */
	public static function evaluate_attachment( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return null;
		}

		$file = get_attached_file( $attachment_id );
		$file = is_string( $file ) ? $file : '';
		$base = '' !== $file ? wp_basename( $file ) : '';

		$url  = (string) wp_get_attachment_url( $attachment_id );
		$path = '';
		if ( '' !== $url ) {
			$parsed = wp_parse_url( $url );
			$path   = is_array( $parsed ) && isset( $parsed['path'] ) ? (string) $parsed['path'] : '';
		}

		// Custom-exclusions setting: case-insensitive substring match.
		$patterns = Slash_Image_Settings::custom_exclusion_patterns();
		if ( ! empty( $patterns ) ) {
			$haystacks = array(
				strtolower( $base ),
				strtolower( $path ),
			);
			foreach ( $patterns as $pattern ) {
				$needle = strtolower( $pattern );
				if ( '' === $needle ) {
					continue;
				}
				foreach ( $haystacks as $h ) {
					if ( '' !== $h && false !== strpos( $h, $needle ) ) {
						return array(
							'reason'  => self::REASON_PATTERN,
							'pattern' => $pattern,
						);
					}
				}
			}
		}

		// Developer filter — runs after the setting so custom_exclusions
		// can be overridden by site code if needed.
		$filtered = apply_filters( 'slash_image_should_skip_attachment', false, $attachment_id, $base, $url );
		if ( $filtered ) {
			return array(
				'reason'  => self::REASON_FILTER,
				'pattern' => is_string( $filtered ) ? $filtered : '',
			);
		}

		return null;
	}

	/**
	 * Apply image-size exclusions to a targets array (size_key => abs_path).
	 * Always preserves 'full' if it was in the input — we don't allow the
	 * user to skip the original via the size grid, only via custom
	 * exclusions or the optimize_all_sizes toggle.
	 *
	 * Note: 'full' IS shown in the size grid checkbox list per the spec,
	 * so it CAN be excluded — this method removes it from $targets when
	 * the user checks it off in settings.
	 */
	public static function filter_targets( array $targets ) {
		$excluded = Slash_Image_Settings::excluded_image_sizes();
		if ( empty( $excluded ) ) {
			return $targets;
		}
		foreach ( $excluded as $size_key ) {
			unset( $targets[ $size_key ] );
		}
		return $targets;
	}

	/**
	 * Mark an attachment as user-excluded by writing the slash-image data
	 * meta. Mirrors the success-path metadata shape so the Media Library
	 * column can render an "Excluded" state without a special branch.
	 */
	public static function mark_attachment_excluded( $attachment_id, array $exclusion ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return;
		}
		$meta = array(
			'optimized'        => false,
			'excluded'         => true,
			'exclusion_reason' => isset( $exclusion['reason'] ) ? (string) $exclusion['reason'] : self::REASON_PATTERN,
			'excluded_pattern' => isset( $exclusion['pattern'] ) ? (string) $exclusion['pattern'] : '',
			'processed_at'     => gmdate( 'c' ),
		);
		update_post_meta( $attachment_id, Slash_Image_Media_Handler::META_DATA_KEY, $meta );
		// Overwrite hazard: if this attachment was previously
		// optimized, its flat stats fields would otherwise survive this blob
		// overwrite and keep being summed. Drop them — an excluded attachment is
		// not an optimize-success.
		Slash_Image_Media_Handler::delete_flat_stats_fields( $attachment_id );
	}

	/**
	 * True when the attachment's _slash_image_data has the excluded flag
	 * set. Used by the Media Library column.
	 */
	public static function is_attachment_excluded( $attachment_id ) {
		$data = get_post_meta( (int) $attachment_id, Slash_Image_Media_Handler::META_DATA_KEY, true );
		return is_array( $data ) && ! empty( $data['excluded'] );
	}
}
