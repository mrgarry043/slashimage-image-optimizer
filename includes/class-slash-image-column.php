<?php
/**
 * Media Library status column. Direct table-cell rendering — no flex
 * wrapper, no intermediate span. WP table layout handles vertical
 * alignment via CSS on the column itself.
 *
 * State-driven content:
 *   optimized       → compact summary + [View details] toggle
 *   not_optimized   → [Optimize] primary button
 *   queued          → [Queued] pill with spinner
 *   processing      → [Optimizing…] pill with animated spinner
 *   restoring       → [Restoring…] pill with animated spinner
 *   failed          → [Retry] button + inline error reason
 *   not_processable → "Not optimized" + format-specific reason text
 *
 * No checkmark icon, no decorative chrome on the optimized headline —
 * just the percent reduction line.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Column {

	const COLUMN_ID = 'slash_image_status';

	/**
	 * Placeholder `data-id` baked into the client-side "Optimizing…" template
	 * (render_processing_template). The Media Library JS substitutes the real
	 * attachment id per cell when painting the optimistic pill on bulk Apply.
	 */
	const ID_PLACEHOLDER = '__SLASH_IMAGE_ID__';

	/**
	 * Render the cell content for an attachment. Returns HTML; the caller
	 * echoes it into the <td>.
	 */
	public static function render_for_attachment( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return '';
		}

		$state = Slash_Image_Media_Library::status_for_attachment( $attachment_id );
		$kind  = isset( $state['kind'] ) ? (string) $state['kind'] : Slash_Image_Media_Library::STATUS_NOT_OPTIMIZED;

		switch ( $kind ) {
			case Slash_Image_Media_Library::STATUS_OPTIMIZED:
				// Reuse the blob status_for_attachment() already loaded. (A6-01)
				return self::render_optimized( $attachment_id, isset( $state['data'] ) ? $state['data'] : null );

			case Slash_Image_Media_Library::STATUS_QUEUED:
			case Slash_Image_Media_Library::STATUS_PROCESSING:
				// At N=1 (single-lane) the queued-vs-claimed distinction is
				// meaningless to the user — both mean "work is happening". Render
				// both as the animated "Optimizing…" pill. The server-side queue
				// states (waiting vs claimed) are unchanged; only this renderer
				// collapses them. A row that's genuinely stuck is told apart by
				// status_for_attachment() and rendered as STATUS_STALLED.
				return self::render_pill( $attachment_id, __( 'Optimizing…', 'slashimage-image-optimizer' ), true );

			case Slash_Image_Media_Library::STATUS_RESTORING:
				// Restore in progress: the animated "Restoring…" pill. Same spinner
				// template, so the column poll keeps it live until the worker
				// finishes the restore and the cell repaints to "not optimized".
				return self::render_pill( $attachment_id, __( 'Restoring…', 'slashimage-image-optimizer' ), true );

			case Slash_Image_Media_Library::STATUS_STALLED:
				return self::render_stalled( $attachment_id );

			case Slash_Image_Media_Library::STATUS_PAUSED:
				return self::render_paused( $attachment_id );

			case Slash_Image_Media_Library::STATUS_EXCLUDED:
				$pattern = isset( $state['pattern'] ) ? (string) $state['pattern'] : '';
				return self::render_excluded( $attachment_id, $pattern );

			case Slash_Image_Media_Library::STATUS_ERROR:
				$message      = isset( $state['message'] ) ? (string) $state['message'] : '';
				$upgrade_hint = ! empty( $state['upgrade_hint'] );
				// No separate timeout detail line: the timeout copy in
				// message_for_code() already covers the busy-server / scale-down
				// guidance, so a second line would just repeat it.
				return self::render_failed( $attachment_id, $message, $upgrade_hint );

			case Slash_Image_Media_Library::STATUS_NOT_PROCESSABLE:
				// A type the API can't process (SVG, PDF, …) is a flat, single
				// line — there's nothing to "not optimize", so no heading.
				if ( ! empty( $state['format_unsupported'] ) ) {
					return self::render_format_unsupported();
				}
				$message      = isset( $state['message'] ) ? (string) $state['message'] : __( 'Format not supported', 'slashimage-image-optimizer' );
				$upgrade_hint = ! empty( $state['upgrade_hint'] );
				return self::render_not_processable( $message, $upgrade_hint );

			case Slash_Image_Media_Library::STATUS_NOT_OPTIMIZED:
			default:
				return self::render_not_optimized( $attachment_id );
		}
	}

	public static function is_transitional_kind( $kind ) {
		return in_array(
			(string) $kind,
			array(
				Slash_Image_Media_Library::STATUS_QUEUED,
				Slash_Image_Media_Library::STATUS_PROCESSING,
				Slash_Image_Media_Library::STATUS_RESTORING,
				// Stalled keeps polling: if the driver revives on its own (cron
				// fires, traffic resumes, another tab kicks) the cell auto-updates
				// to the result without a reload. The poll is cheap (one indexed
				// active_row_for + a 12 s-cached liveness verdict).
				Slash_Image_Media_Library::STATUS_STALLED,
			),
			true
		);
	}

	public static function allowed_html() {
		return array(
			'div'    => array(
				'class'         => true,
				'aria-hidden'   => true,
				'aria-expanded' => true,
				'data-id'       => true,
			),
			'span'   => array(
				'class'       => true,
				'aria-hidden' => true,
				'title'       => true,
			),
			'button' => array(
				'class'         => true,
				'type'          => true,
				'data-id'       => true,
				'data-action'   => true,
				'aria-expanded' => true,
				'aria-controls' => true,
			),
			'a'      => array(
				'href'   => true,
				'class'  => true,
				'target' => true,
				'rel'    => true,
			),
			'svg'    => array(
				'xmlns'   => true,
				'viewBox' => true,
				'width'   => true,
				'height'  => true,
				'class'   => true,
				'fill'    => true,
			),
			'circle' => array(
				'cx'                => true,
				'cy'                => true,
				'r'                 => true,
				'fill'              => true,
				'stroke'            => true,
				'stroke-width'      => true,
				'stroke-dasharray'  => true,
				'stroke-dashoffset' => true,
			),
			'p'      => array( 'class' => true ),
		);
	}

	/* ── State renderers ────────────────────────────────────────── */

	private static function render_optimized( $attachment_id, $data = null ) {
		// $data is threaded from status_for_attachment() to avoid a duplicate
		// postmeta read; fall back to a direct read for any other caller. (A6-01)
		if ( null === $data ) {
			$data = get_post_meta( $attachment_id, Slash_Image_Media_Handler::META_DATA_KEY, true );
		}
		if ( ! is_array( $data ) || empty( $data['optimized'] ) ) {
			return self::render_not_optimized( $attachment_id );
		}

		$mode_label     = self::compression_label( $data );
		$saved_headline = self::headline_saved_percent( $data );

		// Headline — full size, best available format (AVIF → WebP → original).
		// "Already optimal" when that best format yields no net saving (a genuine
		// pre-optimized passthrough, or output not smaller than the input).
		$is_optimal = ( $saved_headline < 0.01 );

		if ( $is_optimal ) {
			$headline = esc_html__( 'Already optimal', 'slashimage-image-optimizer' )
				. ( '' !== $mode_label ? ' <span class="slash-image-col-mode">(' . esc_html( $mode_label ) . ')</span>' : '' );
		} else {
			$headline = sprintf(
				/* translators: %s: best full-size saving percent (AVIF, WebP, or original format) */
				esc_html__( 'Reduced by %s', 'slashimage-image-optimizer' ),
				'<span class="slash-image-col-pct">' . esc_html( self::format_percent( $saved_headline ) ) . '</span>'
			) . ( '' !== $mode_label ? ' <span class="slash-image-col-mode">(' . esc_html( $mode_label ) . ')</span>' : '' );
		}

		// Compact lines
		$lines = '';
		if ( ! empty( $data['png_converted_to_jpeg'] ) ) {
			$lines .= '<p class="slash-image-col-line">' . esc_html__( 'PNG → JPEG conversion', 'slashimage-image-optimizer' ) . '</p>';
		}

		$thumbs = self::thumbnails_processed( $data );
		if ( $thumbs > 0 ) {
			$lines .= '<p class="slash-image-col-line">'
				. sprintf(
					/* translators: %d: thumbnail count */
					esc_html( _n( '+%d thumbnail optimized', '+%d thumbnails optimized', $thumbs, 'slashimage-image-optimizer' ) ),
					(int) $thumbs
				)
				. '</p>';
		}

		// Combined modern-format counts: "+N AVIF · +N WebP" — only the formats
		// actually generated, dot-separated. Omitted entirely when neither ran.
		$variant_parts = array();
		$avif_count    = self::variant_count( $data, 'avif' );
		if ( $avif_count > 0 ) {
			/* translators: %d: number of AVIF images generated (full size + thumbnails) */
			$variant_parts[] = sprintf( __( '+%d AVIF', 'slashimage-image-optimizer' ), (int) $avif_count );
		}
		$webp_count = self::variant_count( $data, 'webp' );
		if ( $webp_count > 0 ) {
			/* translators: %d: number of WebP images generated (full size + thumbnails) */
			$variant_parts[] = sprintf( __( '+%d WebP', 'slashimage-image-optimizer' ), (int) $webp_count );
		}
		if ( ! empty( $variant_parts ) ) {
			$lines .= '<p class="slash-image-col-line">' . esc_html( implode( ' · ', $variant_parts ) ) . '</p>';
		}

		// Detail rows (collapsed by default)
		$detail_id = 'slash-image-details-' . $attachment_id;
		$detail    = '<div class="slash-image-col-divider" aria-hidden="true"></div>';
		$detail   .= self::detail_row( __( 'Original size:', 'slashimage-image-optimizer' ), self::format_size_main_original( $data ), 'baseline' );
		$detail   .= self::detail_row( __( 'New size:', 'slashimage-image-optimizer' ), self::format_size_main_optimized( $data ) );

		// Full-size variant sizes (per_size 'full' entry), only when generated.
		$avif_kb = self::full_variant_kb( $data, 'avif' );
		if ( $avif_kb > 0 ) {
			$detail .= self::detail_row( __( 'AVIF size:', 'slashimage-image-optimizer' ), size_format( $avif_kb * 1024, 1 ) );
		}
		$webp_kb = self::full_variant_kb( $data, 'webp' );
		if ( $webp_kb > 0 ) {
			$detail .= self::detail_row( __( 'WebP size:', 'slashimage-image-optimizer' ), size_format( $webp_kb * 1024, 1 ) );
		}

		// All-sizes total, distinct from the full-size headline above.
		$detail .= self::detail_row(
			__( 'Overall saving:', 'slashimage-image-optimizer' ),
			self::format_percent( self::total_saved_percent( $data ) ),
			'',
			__( 'Saving across the full image and all thumbnail sizes', 'slashimage-image-optimizer' )
		);

		// Toggle button (plain text + chevron, no link/button chrome)
		$toggle = '<button type="button" class="slash-image-col-toggle"'
			. ' data-action="toggle-details"'
			. ' aria-expanded="false"'
			. ' aria-controls="' . esc_attr( $detail_id ) . '">'
			. '<span class="slash-image-col-toggle__open">' . esc_html__( 'View details', 'slashimage-image-optimizer' ) . ' ▼</span>'
			. '<span class="slash-image-col-toggle__close">' . esc_html__( 'Hide details', 'slashimage-image-optimizer' ) . ' ▲</span>'
			. '</button>';

		return '<div class="slash-image-col slash-image-col--optimized" data-id="' . esc_attr( $attachment_id ) . '">'
			. '<p class="slash-image-col-headline">' . $headline . '</p>'
			. $lines
			. $toggle
			. '<div class="slash-image-col-details" id="' . esc_attr( $detail_id ) . '">'
			. $detail
			. '</div>'
			. '</div>';
	}

	private static function render_not_optimized( $attachment_id ) {
		return '<div class="slash-image-col" data-id="' . esc_attr( $attachment_id ) . '">'
			. '<button type="button" class="button button-primary" data-action="enqueue" data-id="' . esc_attr( $attachment_id ) . '">'
			. esc_html__( 'Optimize', 'slashimage-image-optimizer' )
			. '</button>'
			. '</div>';
	}

	private static function render_failed( $attachment_id, $message, $upgrade_hint = false, $details = '' ) {
		// No "Error:" prefix — the failed visual state (red styling + Retry)
		// already conveys that this is an error.
		$message_html = ( '' !== $message )
			? '<p class="slash-image-col-error">' . esc_html( $message ) . '</p>'
			: '';
		$details_html = ( '' !== $details )
			? '<p class="slash-image-col-line slash-image-col-muted">' . esc_html( $details ) . '</p>'
			: '';
		return '<div class="slash-image-col slash-image-col--failed" data-id="' . esc_attr( $attachment_id ) . '">'
			. '<button type="button" class="button" data-action="enqueue" data-id="' . esc_attr( $attachment_id ) . '">'
			. esc_html__( 'Retry', 'slashimage-image-optimizer' )
			. '</button>'
			. $message_html
			. $details_html
			. ( $upgrade_hint ? self::upgrade_link_html() : '' )
			. '</div>';
	}

	/**
	 * "Stalled — retry?" cell. Visually distinct from both "Optimizing…"
	 * (animated pill) and "Failed" (red error) via the --stalled modifier, with a
	 * DISTINCT action (`stalled-retry`, not `enqueue`) so the JS routes it to the
	 * credit-safe reset-in-place endpoint rather than the failed-retry enqueue.
	 */
	private static function render_stalled( $attachment_id ) {
		return '<div class="slash-image-col slash-image-col--stalled" data-id="' . esc_attr( $attachment_id ) . '">'
			. '<button type="button" class="button" data-action="stalled-retry" data-id="' . esc_attr( $attachment_id ) . '">'
			. esc_html__( 'Retry', 'slashimage-image-optimizer' )
			. '</button>'
			. '<p class="slash-image-col-line slash-image-col-muted">'
			. esc_html__( 'Stalled - retry?', 'slashimage-image-optimizer' )
			. '</p>'
			. '</div>';
	}

	/**
	 * Dead-key "paused — reconnect" cell. NEUTRAL and NON-transitional by design:
	 * NO `.slash-image-pill__spinner` element (so the JS poll's spinner scan does
	 * not treat it as transitional and stops polling), and no retry affordance
	 * (a retry can't succeed with an invalid key). The site-wide reconnect notice
	 * carries the call to action; this cell just stops the endless spinner.
	 */
	private static function render_paused( $attachment_id ) {
		return '<div class="slash-image-col slash-image-col--paused" data-id="' . esc_attr( $attachment_id ) . '">'
			. '<p class="slash-image-col-line slash-image-col-muted">'
			. esc_html__( 'Paused - reconnect your API key', 'slashimage-image-optimizer' )
			. '</p>'
			. '</div>';
	}

	/**
	 * The animated "Optimizing…" pill for a concrete attachment. Public so the
	 * stalled-retry endpoint can paint it optimistically after a reset (see
	 * Slash_Image_Media_Library::ajax_retry_stalled).
	 */
	public static function render_processing( $attachment_id ) {
		return self::render_pill( (int) $attachment_id, __( 'Optimizing…', 'slashimage-image-optimizer' ), true );
	}

	private static function render_excluded( $attachment_id, $pattern ) {
		$line = ( '' === $pattern )
			? esc_html__( 'Excluded', 'slashimage-image-optimizer' )
			: sprintf(
				/* translators: %s: matched exclusion pattern */
				esc_html__( 'Excluded (matches: %s)', 'slashimage-image-optimizer' ),
				esc_html( $pattern )
			);
		return '<div class="slash-image-col slash-image-col--excluded" data-id="' . esc_attr( $attachment_id ) . '">'
			. '<p class="slash-image-col-line slash-image-col-muted">' . $line . '</p>'
			. '</div>';
	}

	/**
	 * Unsupported input format (SVG, PDF, TIFF, BMP, …). A permanent skip the
	 * plugin knows from the MIME alone — no API call, no failed row. Rendered
	 * as a single neutral line; no "Not optimized" heading, no action button.
	 */
	private static function render_format_unsupported() {
		return '<div class="slash-image-col slash-image-col--not-processable">'
			. '<p class="slash-image-col-line">' . esc_html__( 'Format not supported', 'slashimage-image-optimizer' ) . '</p>'
			. '</div>';
	}

	private static function render_not_processable( $message, $upgrade_hint = false ) {
		return '<div class="slash-image-col slash-image-col--not-processable">'
			. '<p class="slash-image-col-line"><strong>' . esc_html__( 'Not optimized', 'slashimage-image-optimizer' ) . '</strong></p>'
			. '<p class="slash-image-col-line slash-image-col-muted">' . esc_html( $message ) . '</p>'
			. ( $upgrade_hint ? self::upgrade_link_html() : '' )
			. '</div>';
	}

	/**
	 * Upgrade-link snippet shared by the failed / not-processable renderers.
	 * Mirrors the attachment-meta-box copy so the list column and detail
	 * page surface the same CTA when a row carries upgrade_hint.
	 */
	private static function upgrade_link_html() {
		return '<p class="slash-image-col-line slash-image-col-upgrade">'
			. '<a href="' . esc_url( SLASH_IMAGE_DASHBOARD_URL ) . '" target="_blank" rel="noopener noreferrer">'
			. esc_html__( 'Upgrade for higher size limits →', 'slashimage-image-optimizer' )
			. '</a>'
			. '</p>';
	}

	/**
	 * The "Optimizing…" pill as a client-side template — byte-identical to what
	 * a freshly-queued attachment renders (so swapping in the authoritative
	 * server HTML causes no flicker), but with ID_PLACEHOLDER in place of the
	 * data-id for the JS to substitute per cell. Used for optimistic painting on
	 * the no-reload bulk action; the label stays translatable here.
	 */
	public static function render_processing_template() {
		return self::render_pill( self::ID_PLACEHOLDER, __( 'Optimizing…', 'slashimage-image-optimizer' ), true );
	}

	private static function render_pill( $attachment_id, $label, $animated ) {
		$spinner = $animated
			? '<span class="slash-image-pill__spinner" aria-hidden="true"></span>'
			: '<span class="slash-image-pill__spinner slash-image-pill__spinner--static" aria-hidden="true"></span>';
		return '<div class="slash-image-col" data-id="' . esc_attr( $attachment_id ) . '">'
			. '<span class="slash-image-pill">'
			. $spinner
			. '<span>' . esc_html( $label ) . '</span>'
			. '</span>'
			. '</div>';
	}

	/* ── Helpers ────────────────────────────────────────────────── */

	private static function detail_row( $label, $value, $modifier = '', $label_title = '' ) {
		$value_class = 'slash-image-col-detail-value';
		if ( 'baseline' === $modifier ) {
			$value_class .= ' slash-image-col-detail-value--baseline';
		}
		$label_attr = ( '' !== $label_title ) ? ' title="' . esc_attr( $label_title ) . '"' : '';
		return '<div class="slash-image-col-detail-row">'
			. '<span class="slash-image-col-detail-label"' . $label_attr . '>' . esc_html( $label ) . '</span>'
			. '<span class="' . esc_attr( $value_class ) . '">' . esc_html( $value ) . '</span>'
			. '</div>';
	}

	private static function format_size_main_original( array $data ) {
		$bytes = isset( $data['original_size_bytes'] )
			? (int) $data['original_size_bytes']
			: ( isset( $data['original_size_kb'] ) ? (int) $data['original_size_kb'] * 1024 : 0 );
		$h     = $bytes > 0 ? size_format( $bytes, 1 ) : '';
		return $h ? $h : '—';
	}

	private static function format_size_main_optimized( array $data ) {
		$bytes = isset( $data['optimized_size_bytes'] )
			? (int) $data['optimized_size_bytes']
			: ( isset( $data['optimized_size_kb'] ) ? (int) $data['optimized_size_kb'] * 1024 : 0 );
		$h     = $bytes > 0 ? size_format( $bytes, 1 ) : '';
		return $h ? $h : '—';
	}

	private static function main_saved_percent( array $data ) {
		if ( isset( $data['saved_percent_main'] ) ) {
			return (float) $data['saved_percent_main'];
		}
		$o = isset( $data['original_size_bytes'] ) ? (int) $data['original_size_bytes'] : 0;
		$n = isset( $data['optimized_size_bytes'] ) ? (int) $data['optimized_size_bytes'] : 0;
		if ( $o > 0 && $n >= 0 && $n <= $o ) {
			return ( ( $o - $n ) / $o ) * 100;
		}
		return isset( $data['saved_percent'] ) ? (float) $data['saved_percent'] : 0.0;
	}

	/**
	 * Full-size variant size in whole KB, read from the per_size 'full' entry.
	 * Returns 0 when the variant wasn't generated (or per_size is absent).
	 */
	private static function full_variant_kb( array $data, $format ) {
		if ( ! in_array( $format, array( 'webp', 'avif' ), true ) ) {
			return 0;
		}
		$key = Slash_Image_Media_Handler::FULL_SIZE_KEY;
		if ( empty( $data['per_size'][ $key ] ) || ! is_array( $data['per_size'][ $key ] ) ) {
			return 0;
		}
		$full = $data['per_size'][ $key ];
		return isset( $full[ $format . '_kb' ] ) ? (int) $full[ $format . '_kb' ] : 0;
	}

	/**
	 * Headline saving percent — full size, best available format in priority
	 * AVIF → WebP → original format. Headlines the strongest single-format
	 * saving for the main image.
	 * Falls back to the original-format saving when no variant was generated.
	 */
	private static function headline_saved_percent( array $data ) {
		$key  = Slash_Image_Media_Handler::FULL_SIZE_KEY;
		$orig = isset( $data['per_size'][ $key ]['original_kb'] ) ? (int) $data['per_size'][ $key ]['original_kb'] : 0;
		if ( $orig > 0 ) {
			foreach ( array( 'avif', 'webp' ) as $format ) {
				$variant_kb = self::full_variant_kb( $data, $format );
				if ( $variant_kb > 0 ) {
					return ( ( $orig - $variant_kb ) / $orig ) * 100;
				}
			}
		}
		return self::main_saved_percent( $data );
	}

	private static function thumbnails_processed( array $data ) {
		if ( isset( $data['thumbnails_processed'] ) ) {
			return (int) $data['thumbnails_processed'];
		}
		if ( ! empty( $data['processed_sizes'] ) && is_array( $data['processed_sizes'] ) ) {
			$count = 0;
			foreach ( $data['processed_sizes'] as $key ) {
				if ( 'full' !== $key ) {
					++$count;
				}
			}
			return $count;
		}
		return 0;
	}

	private static function variant_count( array $data, $format ) {
		$key = $format . '_count';
		if ( isset( $data[ $key ] ) ) {
			return (int) $data[ $key ];
		}
		if ( ! empty( $data['per_size'] ) && is_array( $data['per_size'] ) ) {
			$count    = 0;
			$size_key = $format . '_kb';
			foreach ( $data['per_size'] as $size ) {
				if ( ! empty( $size[ $size_key ] ) ) {
					++$count;
				}
			}
			return $count;
		}
		return 0;
	}

	private static function compression_label( array $data ) {
		$mode = isset( $data['compression_mode'] ) ? (string) $data['compression_mode'] : '';
		switch ( $mode ) {
			case 'lossy':
				return __( 'Lossy', 'slashimage-image-optimizer' );
			case 'glossy':
				return __( 'Glossy', 'slashimage-image-optimizer' );
			case 'lossless':
				return __( 'Lossless', 'slashimage-image-optimizer' );
			default:
				return '';
		}
	}

	/**
	 * Whole-attachment saving percent (full size + all thumbnails), to match
	 * the scope of the per-format saving lines. Prefers the stored overall
	 * figure; falls back to deriving it from the stored all-sizes totals for
	 * meta written before saved_percent_overall existed.
	 */
	private static function total_saved_percent( array $data ) {
		if ( isset( $data['saved_percent_overall'] ) ) {
			return (float) $data['saved_percent_overall'];
		}
		$o = isset( $data['original_size_kb'] ) ? (int) $data['original_size_kb'] : 0;
		$n = isset( $data['optimized_size_kb'] ) ? (int) $data['optimized_size_kb'] : 0;
		if ( $o > 0 ) {
			return ( ( $o - $n ) / $o ) * 100;
		}
		return isset( $data['saved_percent'] ) ? (float) $data['saved_percent'] : 0.0;
	}

	private static function format_percent( $value ) {
		$value     = (float) $value;
		$formatted = rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
		return $formatted . '%';
	}
}
