<?php
/**
 * Media Library integration: status column, filter dropdown, color-coded
 * row content. Replaces the inline failed-items list inside the plugin
 * admin pages (per the Step 6 spec — failed items surface here, not there).
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Media_Library {

	const QUERY_VAR = 'slash_image_status';

	const STATUS_OPTIMIZED       = 'optimized';
	const STATUS_NOT_OPTIMIZED   = 'not_optimized';
	const STATUS_ERROR           = 'error';
	const STATUS_NOT_PROCESSABLE = 'not_processable';
	const STATUS_QUEUED          = 'queued';
	const STATUS_PROCESSING      = 'processing';
	const STATUS_RESTORING       = 'restoring';
	const STATUS_STALLED         = 'stalled';
	const STATUS_EXCLUDED        = 'excluded';
	// Connection is invalid (dead key) — the worker no-ops, so an in-progress row
	// is neither working nor stalled-and-retryable. A neutral, NON-transitional
	// state: no spinner, no retry, and the poll stops (nothing will transition
	// until the key is reconnected).
	const STATUS_PAUSED          = 'paused';

	const NOTICE_QUERY_VAR = 'slash_image_notice';
	const NOTICE_COUNT_VAR = 'slash_image_count';

	const ACTION_REOPTIMIZE = 'slash_image_reoptimize';
	const ACTION_RESTORE    = 'slash_image_restore_one';

	const BULK_OPTIMIZE = 'slash_image_bulk_optimize';
	const BULK_RESTORE  = 'slash_image_bulk_restore';

	const BULK_RESTORE_SOFT_CAP = 500;

	/**
	 * Per-request caches of the queue lookups behind the status column, keyed by
	 * attachment_id. array_key_exists() distinguishes a primed "no row" (null)
	 * from an unprimed id, so an optimized row (which has no failed/active queue
	 * row) is a cache hit rather than a fall-through per-row SELECT. Filled in
	 * bulk by prime_status_records(). (A6-01)
	 *
	 * @var array<int,array|null>
	 */
	private static $failed_records = array();

	/** @var array<int,array|null> Per-request cache; see $failed_records. (A6-01) */
	private static $active_records = array();

	public function __construct() {
		// The status column is admin-only (v1's manage_options posture). Gate its
		// registration so a non-admin who can reach upload.php doesn't see an inert
		// column whose row/bulk actions and status poll would all 403. Instantiated
		// on plugins_loaded inside is_admin() — pluggable.php is loaded by then, so
		// current_user_can() is reliable here. (A1-01) The the_posts primer batches
		// the column's queue lookups for the whole list page. (A6-01)
		if ( current_user_can( 'manage_options' ) ) {
			add_filter( 'manage_media_columns', array( $this, 'add_column' ) );
			add_action( 'manage_media_custom_column', array( $this, 'render_column' ), 10, 2 );
			add_filter( 'the_posts', array( $this, 'prime_status_caches_for_list' ), 10, 2 );
		}

		add_action( 'restrict_manage_posts', array( $this, 'render_filter' ) );
		add_action( 'pre_get_posts', array( $this, 'apply_filter' ) );

		add_filter( 'media_row_actions', array( $this, 'add_row_actions' ), 10, 2 );

		add_filter( 'bulk_actions-upload', array( $this, 'add_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_actions' ), 10, 3 );

		add_action( 'add_meta_boxes_attachment', array( $this, 'register_meta_box' ) );

		add_action( 'admin_post_' . self::ACTION_REOPTIMIZE, array( $this, 'handle_reoptimize' ) );
		add_action( 'admin_post_' . self::ACTION_RESTORE, array( $this, 'handle_restore' ) );

		add_action( 'admin_notices', array( $this, 'render_notice' ) );

		add_action( 'wp_ajax_slash_image_status_poll', array( $this, 'ajax_status_poll' ) );
		add_action( 'wp_ajax_slash_image_enqueue_one', array( $this, 'ajax_enqueue_one' ) );
		add_action( 'wp_ajax_slash_image_bulk_enqueue', array( $this, 'ajax_bulk_enqueue' ) );
		add_action( 'wp_ajax_slash_image_kick', array( $this, 'ajax_kick' ) );
		add_action( 'wp_ajax_slash_image_retry_stalled', array( $this, 'ajax_retry_stalled' ) );
		add_action( 'wp_ajax_slash_image_reoptimize', array( $this, 'ajax_reoptimize' ) );
	}

	public function ajax_status_poll() {
		check_ajax_referer( 'slash_image_status_poll', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
		}

		$ids = isset( $_POST['ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$ids = array_values( array_unique( $ids ) );
		$ids = array_slice(
			array_filter(
				$ids,
				function ( $i ) {
					return $i > 0;
				}
			),
			0,
			100
		);

		// Batch the queue lookups for the whole id set so the loop below doesn't
		// fire a failed/active SELECT per attachment. (A6-01)
		self::prime_status_records( $ids );

		$updates = array();
		foreach ( $ids as $id ) {
			$state                   = self::status_for_attachment( (int) $id );
			$kind                    = isset( $state['kind'] ) ? (string) $state['kind'] : self::STATUS_NOT_OPTIMIZED;
			$updates[ (string) $id ] = array(
				'kind'         => $kind,
				'html'         => Slash_Image_Column::render_for_attachment( (int) $id ),
				'transitional' => Slash_Image_Column::is_transitional_kind( $kind ),
			);
		}

		wp_send_json_success( array( 'updates' => $updates ) );
	}

	/**
	 * Per-attachment Optimize / Retry button handler. Enqueues the attachment
	 * into the new-uploads queue (priority over the bulk queue) and returns
	 * the freshly-rendered cell HTML so the JS can swap the cell content
	 * immediately to the transitional pill.
	 */
	public function ajax_enqueue_one() {
		check_ajax_referer( 'slash_image_status_poll', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
		}

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
			wp_send_json_error( array( 'code' => 'invalid_id' ), 400 );
		}

		$mime = get_post_mime_type( $id );
		if ( ! Slash_Image_Api_Client::is_supported_mime( $mime ) ) {
			wp_send_json_error( array( 'code' => 'unsupported_mime' ) );
		}

		// No-key gate: a deliberate click must not silently no-op. Surface a brief
		// add-a-key prompt (the JS shows it as a transient one-liner) instead of
		// enqueuing a row that the worker would never claim.
		if ( ! Slash_Image_Connection::has_api_key() ) {
			wp_send_json_error(
				array(
					'code'    => 'no_api_key',
					'message' => __( 'Add an API key to start optimizing.', 'slashimage-image-optimizer' ),
				)
			);
		}

		// Clear any prior failure record so the new pass isn't pre-judged.
		Slash_Image_Bulk_Processor::clear_failed_for( array( $id ) );

		// Route through the same priority queue as auto-optimize.
		Slash_Image_Bulk_Processor::enqueue_new_upload( $id );

		wp_send_json_success(
			array(
				'id'   => $id,
				'html' => Slash_Image_Column::render_for_attachment( $id ),
			)
		);
	}

	/**
	 * Sanitize a raw list of attachment IDs: absint each, drop non-positive,
	 * de-dupe. Pure (no WP state) so it's unit-testable.
	 *
	 * @param mixed $raw Raw ids (array of strings/ints from the request).
	 * @return int[]
	 */
	public static function sanitize_ids( $raw ) {
		$ids = array_map( 'absint', (array) $raw );
		$ids = array_filter(
			$ids,
			static function ( $i ) {
				return $i > 0;
			}
		);
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Batch sibling of ajax_enqueue_one: the no-reload Media Library bulk
	 * "Optimize with Slash Image" action. Does exactly what the
	 * handle_bulk_actions() BULK_OPTIMIZE case does — clear_failed_for() then
	 * enqueue_new_upload() per id (that path's request-static-guarded
	 * maybe_start_chain() fires a single chain dispatch for the whole batch) —
	 * and returns a per-id map of freshly-rendered "Optimizing…" column HTML so
	 * the JS can paint each row, mirroring ajax_status_poll's `updates` shape.
	 * No synchronous optimization happens here.
	 */
	public function ajax_bulk_enqueue() {
		check_ajax_referer( 'slash_image_status_poll', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
		}

		$raw = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$ids = self::sanitize_ids( $raw );
		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'code' => 'no_ids' ), 400 );
		}

		// No-key gate: surface the add-a-key prompt instead of enqueuing rows the
		// worker would never claim.
		if ( ! Slash_Image_Connection::has_api_key() ) {
			wp_send_json_error(
				array(
					'code'    => 'no_api_key',
					'message' => __( 'Add an API key to start optimizing.', 'slashimage-image-optimizer' ),
				)
			);
		}

		Slash_Image_Bulk_Processor::clear_failed_for( $ids );
		foreach ( $ids as $id ) {
			Slash_Image_Bulk_Processor::enqueue_new_upload( $id );
		}

		$updates = array();
		foreach ( $ids as $id ) {
			$updates[ (string) $id ] = array(
				'html' => Slash_Image_Column::render_for_attachment( (int) $id ),
			);
		}

		wp_send_json_success( array( 'updates' => $updates ) );
	}

	/**
	 * Lightweight worker kick. Fired by the Media Library JS right
	 * after a single-image enqueue (Optimize click) or a watched browser
	 * upload, so the just-queued PRIORITY_UPLOAD row starts processing within
	 * a few seconds instead of waiting up to a full cron tick. Runs one
	 * worker tick in its own request and returns a minimal ack — the existing
	 * 5 s status poll surfaces the queued -> processing -> result transition.
	 *
	 * Concurrency-safe with NO lock: Slash_Image_Queue's atomic,
	 * token-discriminated claim guarantees overlapping kicks (or a kick
	 * overlapping a cron tick) grab disjoint rows. The only guard is a
	 * client-side "one kick in flight" debounce in media-library.js.
	 *
	 * Reuses the slash_image_status_poll nonce that the rest of this screen's
	 * AJAX already uses (no second nonce introduced); manage_options is still
	 * required, matching ajax_enqueue_one and the bulk-page kick. Deliberately
	 * does NOT build the bulk-progress payload (snapshot() + cron probe) the
	 * bulk page's kick returns — that full-library pass is the C2 scaling
	 * hotspot and has no business running on every optimize/upload kick.
	 */
	public function ajax_kick() {
		check_ajax_referer( 'slash_image_status_poll', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
		}

		Slash_Image_Worker::remove_time_limit();

		// Budgeted tick: process ONE attachment, then return so this foreground
		// request frees its PHP-FPM worker within seconds rather than holding it
		// for the whole batch — on a constrained host a long-held worker would
		// starve the concurrent status poll. The JS chain re-fires while
		// queue_has_more, so the queue still drains continuously.
		$result  = Slash_Image_Worker::tick( 1 );
		$claimed = (int) ( $result['claimed'] ?? 0 );
		// More to do only if this tick actually claimed a row AND rows remain —
		// so a tick that claims nothing (queue empty, or all remaining rows are
		// backoff-gated) stops the chain rather than busy-looping. Gated retries
		// fall through to the cron tick.
		$has_more = ( $claimed > 0 ) && ( (int) ( Slash_Image_Queue::counts()['waiting'] ?? 0 ) > 0 );

		wp_send_json_success(
			array(
				'processed'      => (int) ( $result['processed'] ?? 0 ),
				'failed'         => (int) ( $result['failed'] ?? 0 ),
				'queue_has_more' => $has_more,
			)
		);
	}

	/**
	 * Credit-safe stalled-retry. Resets the EXISTING active row in place
	 * via reset_for_retry (releases the claim → waiting; clears
	 * attempts/available_at/claimed_at/finished_at) — NOT a fresh enqueue, which
	 * on a still-claimed row the enqueue() FOR UPDATE dedupe would no-op, and
	 * which would risk the Phase-7 duplicate-row → double-/v1/optimize → wasted
	 * credit path. Reusing the one row keeps it credit-safe. Then the JS fires a
	 * kick, which on a driver-dead host is also the manual driver restart (its
	 * tick runs recover_stale + feed + drain from this tab).
	 *
	 * Returns an optimistic "Optimizing…" pill rather than a re-derived cell: the
	 * row is now `waiting` with its original (old) enqueued_at, so re-deriving
	 * would immediately re-flag it as driver-dead-stalled until the kick claims
	 * it. The kick (fired by the JS next) claims it within ~1-2 s — fresh
	 * claimed_at → no longer stalled — and the poll then settles the real state.
	 */
	public function ajax_retry_stalled() {
		check_ajax_referer( 'slash_image_status_poll', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
		}

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
			wp_send_json_error( array( 'code' => 'invalid_id' ), 400 );
		}

		$row = Slash_Image_Queue::active_row_for( $id );
		if ( is_array( $row ) && ! empty( $row['id'] ) ) {
			// Release + requeue the existing row (credit-safe — same row, no dupe).
			Slash_Image_Queue::reset_for_retry( (int) $row['id'] );
		} else {
			// No active row (already drained / failed / purged) — fall back to a
			// fresh enqueue so the button still does something sensible. Safe
			// here precisely because there is no active row to duplicate.
			Slash_Image_Bulk_Processor::clear_failed_for( array( $id ) );
			Slash_Image_Bulk_Processor::enqueue_new_upload( $id );
		}

		// Bust the cached liveness verdict so the next poll re-evaluates promptly
		// once the kick starts completing rows.
		delete_transient( Slash_Image_Queue::STALL_LIVENESS_TRANSIENT );

		wp_send_json_success(
			array(
				'id'   => $id,
				'html' => Slash_Image_Column::render_processing( $id ),
			)
		);
	}

	public function add_column( $columns ) {
		$columns['slash_image_status'] = esc_html__( 'SlashImage', 'slashimage-image-optimizer' );
		return $columns;
	}

	public function render_column( $column, $attachment_id ) {
		if ( Slash_Image_Column::COLUMN_ID !== $column ) {
			return;
		}
		echo wp_kses(
			Slash_Image_Column::render_for_attachment( (int) $attachment_id ),
			Slash_Image_Column::allowed_html()
		);
	}


	public function render_filter() {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}

		// Read-only Media Library list filter: a nonce doesn't belong on bookmarkable
		// GET navigation; the value is sanitized and only drives the dropdown state.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$choices = array(
			''                           => __( 'All SlashImage statuses', 'slashimage-image-optimizer' ),
			self::STATUS_OPTIMIZED       => __( 'Optimized', 'slashimage-image-optimizer' ),
			self::STATUS_QUEUED          => __( 'Queued', 'slashimage-image-optimizer' ),
			self::STATUS_PROCESSING      => __( 'Processing', 'slashimage-image-optimizer' ),
			self::STATUS_EXCLUDED        => __( 'Excluded', 'slashimage-image-optimizer' ),
			self::STATUS_NOT_OPTIMIZED   => __( 'Not optimized', 'slashimage-image-optimizer' ),
			self::STATUS_ERROR           => __( 'Optimization error', 'slashimage-image-optimizer' ),
			self::STATUS_NOT_PROCESSABLE => __( 'Not processable', 'slashimage-image-optimizer' ),
		);

		echo '<label for="slash-image-mlfilter" class="screen-reader-text">' . esc_html__( 'Filter by SlashImage status', 'slashimage-image-optimizer' ) . '</label>';
		echo '<select id="slash-image-mlfilter" name="' . esc_attr( self::QUERY_VAR ) . '">';
		foreach ( $choices as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $value, $current, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	public function apply_filter( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'attachment' !== $query->get( 'post_type' ) ) {
			return;
		}

		// Read-only Media Library list filter: a nonce doesn't belong on bookmarkable
		// GET navigation; the value is sanitized and only drives the meta_query.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$value = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( '' === $value ) {
			return;
		}

		switch ( $value ) {
			case self::STATUS_OPTIMIZED:
				$query->set(
					'meta_query',
					array(
						array(
							'key'     => Slash_Image_Media_Handler::META_DATA_KEY,
							'compare' => 'EXISTS',
						),
					)
				);
				break;

			case self::STATUS_NOT_OPTIMIZED:
				$query->set(
					'meta_query',
					array(
						array(
							'key'     => Slash_Image_Media_Handler::META_DATA_KEY,
							'compare' => 'NOT EXISTS',
						),
					)
				);
				$query->set( 'post_mime_type', Slash_Image_Api_Client::SUPPORTED_MIME_TYPES );
				break;

			case self::STATUS_ERROR:
				$ids = self::failed_attachment_ids( false );
				$query->set( 'post__in', empty( $ids ) ? array( 0 ) : $ids );
				break;

			case self::STATUS_NOT_PROCESSABLE:
				$ids = self::failed_attachment_ids( true );
				$query->set( 'post__in', empty( $ids ) ? array( 0 ) : $ids );
				break;

			case self::STATUS_QUEUED:
				$query->set(
					'meta_query',
					array(
						array(
							'key'     => Slash_Image_Bulk_Processor::STATUS_META_KEY,
							'value'   => Slash_Image_Bulk_Processor::STATUS_QUEUED,
							'compare' => '=',
						),
					)
				);
				break;

			case self::STATUS_PROCESSING:
				$query->set(
					'meta_query',
					array(
						array(
							'key'     => Slash_Image_Bulk_Processor::STATUS_META_KEY,
							'value'   => Slash_Image_Bulk_Processor::STATUS_PROCESSING,
							'compare' => '=',
						),
					)
				);
				break;

			case self::STATUS_EXCLUDED:
				$query->set(
					'meta_query',
					array(
						array(
							'key'     => Slash_Image_Media_Handler::META_DATA_KEY,
							'value'   => '"excluded";b:1',
							'compare' => 'LIKE',
						),
					)
				);
				break;
		}
	}

	public static function status_for_attachment( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$mime          = get_post_mime_type( $attachment_id );

		// Unsupported MIME (SVG, PDF, TIFF, BMP, …) is a permanent skip the
		// instant we know the type — no API call ever happens. Surface it as
		// "not processable" so the column shows a clear label with no
		// Optimize/Retry button, rather than falling through to the
		// not-optimized state (which renders the misleading Optimize button).
		if ( ! Slash_Image_Api_Client::is_supported_mime( $mime ) ) {
			return array(
				'kind'               => self::STATUS_NOT_PROCESSABLE,
				'code'               => 'not_processable_format',
				'message'            => __( 'Format not supported', 'slashimage-image-optimizer' ),
				'upgrade_hint'       => false,
				'format_unsupported' => true,
			);
		}

		// Transitional state from the new-uploads / bulk processor.
		$status_meta = (string) get_post_meta( $attachment_id, Slash_Image_Bulk_Processor::STATUS_META_KEY, true );

		// Restore in progress (the restore-job worker badge). A simple "Restoring…"
		// pill — restore work has no dead-key pause or stall path, so it short
		// -circuits the optimize transitional handling below.
		if ( Slash_Image_Bulk_Processor::STATUS_RESTORING === $status_meta ) {
			return array( 'kind' => self::STATUS_RESTORING );
		}

		if ( Slash_Image_Bulk_Processor::STATUS_QUEUED === $status_meta
			|| Slash_Image_Bulk_Processor::STATUS_PROCESSING === $status_meta ) {
			// Dead-key pause: while the connection is invalid the worker no-ops, so
			// an in-progress row is neither working (no spinner) nor
			// stalled-and-retryable (retry can't succeed with a dead key). Collapse
			// queued / processing / would-be-stalled into ONE neutral, non-
			// transitional "paused" state so the column shows no spinner and the
			// poll stops; the site-wide reconnect notice explains why. Resumes to
			// the normal queued/processing rendering on reconnect.
			if ( 'invalid' === Slash_Image_Connection::current_status() ) {
				return array( 'kind' => self::STATUS_PAUSED );
			}
			// The postmeta says in-flight — but decide the displayed state from
			// the QUEUE ROW, not the postmeta: recover_stale() can leave the
			// postmeta at 'processing' while the row is already back to 'waiting',
			// so the postmeta is unreliable for staleness.
			$active = self::active_record_for( $attachment_id );
			if ( $active ) {
				$verdict = Slash_Image_Queue::decide_stall_state(
					$active,
					self::stall_liveness(),
					time(),
					self::stall_threshold()
				);
				if ( ! empty( $verdict['stalled'] ) ) {
					return array(
						'kind'   => self::STATUS_STALLED,
						'reason' => isset( $verdict['reason'] ) ? (string) $verdict['reason'] : '',
					);
				}
			}
			return array(
				'kind' => ( Slash_Image_Bulk_Processor::STATUS_QUEUED === $status_meta )
					? self::STATUS_QUEUED
					: self::STATUS_PROCESSING,
			);
		}

		$failed_record = self::failed_record_for( $attachment_id );
		if ( $failed_record ) {
			$code         = (string) ( $failed_record['error_code'] ?? '' );
			$message      = (string) ( $failed_record['error_message'] ?? '' );
			$upgrade_hint = Slash_Image_Bulk_Processor::code_carries_upgrade_hint( $code );
			$kind         = self::is_not_processable_code( $code )
				? self::STATUS_NOT_PROCESSABLE
				: self::STATUS_ERROR;

			return array(
				'kind'         => $kind,
				'code'         => $code,
				'message'      => $message,
				'upgrade_hint' => $upgrade_hint,
			);
		}

		$data = get_post_meta( $attachment_id, Slash_Image_Media_Handler::META_DATA_KEY, true );
		if ( is_array( $data ) && ! empty( $data['excluded'] ) ) {
			return array(
				'kind'    => self::STATUS_EXCLUDED,
				'reason'  => isset( $data['exclusion_reason'] ) ? (string) $data['exclusion_reason'] : '',
				'pattern' => isset( $data['excluded_pattern'] ) ? (string) $data['excluded_pattern'] : '',
			);
		}
		if ( is_array( $data ) && ! empty( $data['optimized'] ) ) {
			return array(
				'kind'          => self::STATUS_OPTIMIZED,
				'saved_percent' => isset( $data['saved_percent'] ) ? (int) $data['saved_percent'] : 0,
				// Thread the already-loaded blob so render_optimized() need not
				// re-read the same postmeta. (A6-01)
				'data'          => $data,
			);
		}

		return array( 'kind' => self::STATUS_NOT_OPTIMIZED );
	}

	private static function failed_record_for( $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		// Shared per-request cache (so prime_status_records() can pre-fill it in
		// bulk): a single render derives each row's status more than once, and the
		// poll loops several attachments. array_key_exists distinguishes a primed
		// "no failed row" (null) from an unprimed id. (A6-01)
		if ( array_key_exists( $attachment_id, self::$failed_records ) ) {
			return self::$failed_records[ $attachment_id ];
		}

		$record = self::normalize_failed_row( Slash_Image_Queue::failed_row_for( $attachment_id ) );
		self::$failed_records[ $attachment_id ] = $record;
		return $record;
	}

	/**
	 * Normalize a raw failed queue row (ARRAY_A) to the compact record the column
	 * consumes, or null. Shared by the single lookup and the batch primer. (A6-01)
	 *
	 * @param mixed $row
	 * @return array|null
	 */
	private static function normalize_failed_row( $row ) {
		return is_array( $row )
			? array(
				'attachment_id' => (int) $row['attachment_id'],
				'error_code'    => (string) ( $row['error_code'] ?? '' ),
				'error_message' => (string) ( $row['error_message'] ?? '' ),
				'attempted_at'  => isset( $row['finished_at'] ) ? strtotime( $row['finished_at'] ) : 0,
			)
			: null;
	}

	/**
	 * Stall threshold (seconds). ~10 min: ≥3× recover_stale's 180 s and
	 * ≥ several 60 s cron cycles, so "stalled" only fires after automatic
	 * recovery has demonstrably failed to run — a row past this age proves no
	 * tick reaped it. Filterable.
	 */
	private static function stall_threshold() {
		return max( 60, (int) apply_filters( 'slash_image_stall_threshold', 600 ) );
	}

	/**
	 * Per-request cache of the (already transient-cached) queue liveness verdict,
	 * so a 100-id status poll reads it once rather than once per row.
	 */
	private static function stall_liveness() {
		static $cache = null;
		if ( null === $cache ) {
			$cache = Slash_Image_Queue::liveness_verdict();
		}
		return $cache;
	}

	/**
	 * Per-request-cached active (waiting/claimed) queue row for an attachment,
	 * normalized to unix timestamps for the pure decide_stall_state() predicate.
	 * Shares the prime_status_records() batch cache with failed_record_for. (A6-01)
	 */
	private static function active_record_for( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( array_key_exists( $attachment_id, self::$active_records ) ) {
			return self::$active_records[ $attachment_id ];
		}

		$record = self::normalize_active_row( Slash_Image_Queue::active_row_for( $attachment_id ) );
		self::$active_records[ $attachment_id ] = $record;
		return $record;
	}

	/**
	 * Normalize a raw active queue row (ARRAY_A) to the timestamps the pure
	 * decide_stall_state() predicate consumes, or null. Shared by the single
	 * lookup and the batch primer. (A6-01)
	 *
	 * @param mixed $row
	 * @return array|null
	 */
	private static function normalize_active_row( $row ) {
		return is_array( $row )
			? array(
				'id'           => (int) ( $row['id'] ?? 0 ),
				'status'       => (string) ( $row['status'] ?? '' ),
				// UTC mysql datetimes → unix (0 when NULL/empty).
				'claimed_at'   => empty( $row['claimed_at'] ) ? 0 : (int) strtotime( $row['claimed_at'] . ' UTC' ),
				'enqueued_at'  => empty( $row['enqueued_at'] ) ? 0 : (int) strtotime( $row['enqueued_at'] . ' UTC' ),
				'available_at' => empty( $row['available_at'] ) ? 0 : (int) strtotime( $row['available_at'] . ' UTC' ),
			)
			: null;
	}

	/**
	 * Prime the per-request failed/active caches for a whole set of attachment
	 * ids in one query each, instead of a SELECT per row. Records a null sentinel
	 * for ids with no row so they read as a cache hit (not a fall-through lookup)
	 * — this is what closes the N+1 for already-optimized rows. Called with the
	 * full visible id set before the column renders. (A6-01)
	 *
	 * @param int[] $ids Attachment ids.
	 */
	public static function prime_status_records( array $ids ) {
		$failed_uncached = array();
		$active_uncached = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 ) {
				continue;
			}
			if ( ! array_key_exists( $id, self::$failed_records ) ) {
				$failed_uncached[] = $id;
			}
			if ( ! array_key_exists( $id, self::$active_records ) ) {
				$active_uncached[] = $id;
			}
		}

		if ( ! empty( $failed_uncached ) ) {
			$map = Slash_Image_Queue::failed_rows_for( $failed_uncached );
			foreach ( $failed_uncached as $id ) {
				self::$failed_records[ $id ] = isset( $map[ $id ] ) ? self::normalize_failed_row( $map[ $id ] ) : null;
			}
		}
		if ( ! empty( $active_uncached ) ) {
			$map = Slash_Image_Queue::active_rows_for( $active_uncached );
			foreach ( $active_uncached as $id ) {
				self::$active_records[ $id ] = isset( $map[ $id ] ) ? self::normalize_active_row( $map[ $id ] ) : null;
			}
		}
	}

	/**
	 * the_posts primer: prime the status-column caches for the whole media-list
	 * page in two queries (see prime_status_records), so the per-row column render
	 * doesn't fire a queue SELECT per attachment. Scoped to the main upload.php
	 * list query for an admin; a no-op everywhere else. (A6-01)
	 *
	 * @param mixed $posts Queried posts (WP_Post[]).
	 * @param mixed $query The WP_Query.
	 * @return mixed $posts, unmodified.
	 */
	public function prime_status_caches_for_list( $posts, $query ) {
		if ( ! is_admin() || ! is_array( $posts ) || empty( $posts ) ) {
			return $posts;
		}
		if ( ! ( $query instanceof WP_Query ) || ! $query->is_main_query() ) {
			return $posts;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'upload' !== $screen->id ) {
			return $posts;
		}

		$ids = array();
		foreach ( $posts as $post ) {
			if ( isset( $post->ID ) && 'attachment' === get_post_type( $post ) ) {
				$ids[] = (int) $post->ID;
			}
		}
		if ( ! empty( $ids ) ) {
			self::prime_status_records( $ids );
		}
		return $posts;
	}

	private static function failed_attachment_ids( $not_processable_only ) {
		$ids = array();
		foreach ( Slash_Image_Queue::failed_rows( 1000 ) as $row ) {
			$id   = (int) ( $row['attachment_id'] ?? 0 );
			$code = (string) ( $row['error_code'] ?? '' );
			if ( $id <= 0 ) {
				continue;
			}
			if ( $not_processable_only && ! self::is_not_processable_code( $code ) ) {
				continue;
			}
			if ( ! $not_processable_only && self::is_not_processable_code( $code ) ) {
				continue;
			}
			$ids[] = $id;
		}
		return array_values( array_unique( $ids ) );
	}

	private static function is_not_processable_code( $code ) {
		// All four are permanent and never auto-retried, so they render
		// like the format errors — "Not optimized" + reason, no Retry button.
		// size_exceeded_for_plan adds an upgrade link as the CTA; the others
		// have no remedy. A bulk-page "Retry failed" or per-row Re-optimize
		// can re-enqueue any of these after the underlying cause is fixed
		// (e.g. plan upgrade for size_exceeded_for_plan).
		return in_array(
			(string) $code,
			array(
				'unsupported_mime',
				'not_processable_format',
				'gif_too_large',
				'file_corrupt',
				'file_too_large',
				'size_exceeded_for_plan',
				'size_exceeded_server',
			),
			true
		);
	}

	/* ── Row actions ──────────────────────────────────────────── */

	public function add_row_actions( $actions, $post ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $actions;
		}
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return $actions;
		}
		if ( ! Slash_Image_Api_Client::is_supported_mime( get_post_mime_type( $post->ID ) ) ) {
			return $actions;
		}

		$state    = self::status_for_attachment( (int) $post->ID );
		$has_data = ( self::STATUS_OPTIMIZED === $state['kind'] || self::STATUS_ERROR === $state['kind'] );

		$reopt_label = $has_data
			? __( 'Re-optimize with SlashImage', 'slashimage-image-optimizer' )
			: __( 'Optimize with SlashImage', 'slashimage-image-optimizer' );

		$actions['slash_image_reoptimize'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::action_url( self::ACTION_REOPTIMIZE, (int) $post->ID ) ),
			esc_html( $reopt_label )
		);

		$backup = get_post_meta( $post->ID, Slash_Image_Restore::BACKUP_META_KEY, true );
		if ( is_array( $backup ) && ! empty( $backup['sizes'] ) ) {
			$actions['slash_image_restore'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::action_url( self::ACTION_RESTORE, (int) $post->ID ) ),
				esc_html__( 'Restore original', 'slashimage-image-optimizer' )
			);
		}

		return $actions;
	}

	private static function action_url( $action, $attachment_id ) {
		$url = add_query_arg(
			array(
				'action'        => $action,
				'attachment_id' => $attachment_id,
				'_wpnonce'      => wp_create_nonce( $action . '_' . $attachment_id ),
			),
			admin_url( 'admin-post.php' )
		);
		return $url;
	}

	/* ── Bulk actions ─────────────────────────────────────────── */

	public function add_bulk_actions( $bulk_actions ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $bulk_actions;
		}
		$bulk_actions[ self::BULK_OPTIMIZE ] = __( 'Optimize with SlashImage', 'slashimage-image-optimizer' );
		$bulk_actions[ self::BULK_RESTORE ]  = __( 'Restore originals', 'slashimage-image-optimizer' );
		return $bulk_actions;
	}

	public function handle_bulk_actions( $redirect_to, $action, $ids ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return add_query_arg( self::NOTICE_QUERY_VAR, 'error_capability', $redirect_to );
		}

		$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
		if ( empty( $ids ) ) {
			return $redirect_to;
		}

		switch ( $action ) {
			case self::BULK_OPTIMIZE:
				// Enqueue each selected attachment at
				// PRIORITY_UPLOAD — the same path as the individual Optimize
				// button — and stay on the Media Library. A page-load kick in
				// media-library.js starts processing within seconds and the
				// existing 5 s poll surfaces results in place. This deliberately
				// does NOT start a bulk session via start_with_ids(): the
				// dedicated Bulk Optimize page handles that. clear_failed_for
				// first so a re-run of previously-failed items isn't pre-judged.
				// No success notice: the selected rows immediately render the
				// "Optimizing…" pill and the stats update live, so the notice was
				// redundant (and a dismissible one lingered in the URL).
				Slash_Image_Bulk_Processor::clear_failed_for( $ids );
				foreach ( $ids as $id ) {
					Slash_Image_Bulk_Processor::enqueue_new_upload( $id );
				}
				return $redirect_to;

			case self::BULK_RESTORE:
				// Route through the queue as restore jobs — async, bounded by the
				// worker budget like bulk optimize, so even a large selection can't
				// time the request out (the old synchronous per-image loop could
				// 504 well under the 500 cap). enqueue_restore() skips ids with no
				// backup; the selected rows render the "Restoring…" pill and the
				// column poll + worker kick drive completion in place.
				$queued = 0;
				foreach ( $ids as $id ) {
					if ( Slash_Image_Bulk_Processor::enqueue_restore( $id ) ) {
						++$queued;
					}
				}

				return add_query_arg(
					array(
						self::NOTICE_QUERY_VAR => 'restore_queued',
						self::NOTICE_COUNT_VAR => $queued,
					),
					$redirect_to
				);
		}

		return $redirect_to;
	}

	/* ── admin-post.php handlers (single-attachment) ──────────── */

	public function handle_reoptimize() {
		$id = isset( $_REQUEST['attachment_id'] ) ? (int) $_REQUEST['attachment_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Per-attachment nonce + manage_options verified in verify_action() at line 920; the derived nonce action requires reading the id before verification.
		$this->verify_action( self::ACTION_REOPTIMIZE, $id );

		// Nonce + capability already verified in verify_action() above.
		$mode = isset( $_GET['compression_mode'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? self::sanitize_mode( sanitize_key( wp_unslash( $_GET['compression_mode'] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: null;

		$result = self::enqueue_reoptimize( $id, $mode );

		if ( ! empty( $result['ok'] ) ) {
			$code = 'reoptimize_done';
		} elseif ( 'no_backup' === ( $result['code'] ?? '' ) ) {
			$code = 'reoptimize_no_backup';
		} else {
			$code = 'reoptimize_failed';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					self::NOTICE_QUERY_VAR => $code,
					self::NOTICE_COUNT_VAR => 1,
				),
				$this->referer_or_upload()
			)
		);
		exit;
	}

	/**
	 * AJAX sibling of the admin-post re-optimize: same restore→enqueue flow, but
	 * returns the freshly-rendered cell HTML so the meta box / column repaints to
	 * the "Optimizing…" pill without a reload (the admin-post link is the no-JS
	 * fallback). A missing backup returns a clear JSON error.
	 */
	public function ajax_reoptimize() {
		check_ajax_referer( 'slash_image_status_poll', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
		}

		$id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$mode = isset( $_POST['mode'] ) ? self::sanitize_mode( sanitize_key( wp_unslash( $_POST['mode'] ) ) ) : null;

		$result = self::enqueue_reoptimize( $id, $mode );
		if ( empty( $result['ok'] ) ) {
			$code = (string) ( $result['code'] ?? 'reoptimize_failed' );
			if ( 'no_backup' === $code ) {
				$msg = __( 'Original backup not found. Restore the original file manually before re-optimizing.', 'slashimage-image-optimizer' );
			} elseif ( 'no_api_key' === $code ) {
				$msg = __( 'Add an API key to start optimizing.', 'slashimage-image-optimizer' );
			} else {
				$msg = __( 'Re-optimization could not be started.', 'slashimage-image-optimizer' );
			}
			wp_send_json_error(
				array(
					'code'    => $code,
					'message' => $msg,
				)
			);
		}

		wp_send_json_success(
			array(
				'id'   => $id,
				'html' => Slash_Image_Column::render_for_attachment( $id ),
			)
		);
	}

	/**
	 * Validate a compression mode string, or null when not one of the three modes.
	 */
	private static function sanitize_mode( $raw ) {
		$mode = sanitize_key( (string) $raw );
		return in_array( $mode, array( 'lossy', 'glossy', 'lossless' ), true ) ? $mode : null;
	}

	/**
	 * Shared queue-based re-optimize for both the admin-post link and the AJAX
	 * endpoint. For an already-optimized image it RESTORES the pristine original
	 * first (so the worker never recompresses an already-compressed file) — which
	 * also clears the optimized marker, so the worker reprocesses it without any
	 * force flag; a missing backup is refused (code 'no_backup'). A non-null $mode
	 * is stored as a one-shot override the worker reads. No synchronous API call
	 * happens here — the queued row drains through the worker + loopback chain.
	 *
	 * @return array ['ok' => bool, 'code' => string|null]
	 */
	private static function enqueue_reoptimize( $id, $mode = null ) {
		$id = (int) $id;
		if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
			return array(
				'ok'   => false,
				'code' => 'invalid_id',
			);
		}
		// No-key gate: refuse before the restore-then-enqueue so a keyless
		// re-optimize neither restores nor queues. The caller maps the code to a
		// prompt.
		if ( ! Slash_Image_Connection::has_api_key() ) {
			return array(
				'ok'   => false,
				'code' => 'no_api_key',
			);
		}
		if ( ! Slash_Image_Api_Client::is_supported_mime( get_post_mime_type( $id ) ) ) {
			return array(
				'ok'   => false,
				'code' => 'unsupported_mime',
			);
		}

		$data         = get_post_meta( $id, Slash_Image_Media_Handler::META_DATA_KEY, true );
		$is_optimized = is_array( $data ) && ! empty( $data['optimized'] );

		if ( $is_optimized ) {
			$backup     = get_post_meta( $id, Slash_Image_Restore::BACKUP_META_KEY, true );
			$has_backup = is_array( $backup ) && ! empty( $backup['sizes'] );
			if ( ! $has_backup ) {
				return array(
					'ok'   => false,
					'code' => 'no_backup',
				);
			}
			// Restores all sizes to disk AND deletes _slash_image_data, so the
			// worker sees an un-optimized image. (The worker re-creates a backup of
			// the restored original when it optimizes.)
			$restored = Slash_Image_Restore::restore_attachment( $id );
			if ( empty( $restored['ok'] ) ) {
				return array(
					'ok'   => false,
					'code' => 'restore_failed',
				);
			}
		}

		if ( null !== $mode ) {
			update_post_meta( $id, Slash_Image_Media_Handler::META_MODE_OVERRIDE_KEY, $mode );
		}

		Slash_Image_Bulk_Processor::clear_failed_for( array( $id ) );
		Slash_Image_Queue::enqueue( $id, Slash_Image_Queue::SOURCE_MANUAL, Slash_Image_Queue::PRIORITY_MANUAL );
		update_post_meta( $id, Slash_Image_Bulk_Processor::STATUS_META_KEY, Slash_Image_Bulk_Processor::STATUS_QUEUED );
		Slash_Image_Worker::schedule_cron();
		Slash_Image_Loopback::maybe_start_chain();

		return array( 'ok' => true );
	}

	public function handle_restore() {
		$id = isset( $_REQUEST['attachment_id'] ) ? (int) $_REQUEST['attachment_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Per-attachment nonce + manage_options verified in verify_action() at line 1072; the derived nonce action requires reading the id before verification.
		$this->verify_action( self::ACTION_RESTORE, $id );

		$result = Slash_Image_Restore::restore_attachment( $id );
		Slash_Image_Bulk_Processor::clear_failed_for( array( $id ) );

		$code = ! empty( $result['ok'] ) ? 'restore_done' : 'restore_failed';

		wp_safe_redirect(
			add_query_arg(
				array(
					self::NOTICE_QUERY_VAR => $code,
					self::NOTICE_COUNT_VAR => 1,
				),
				$this->referer_or_upload()
			)
		);
		exit;
	}

	private function verify_action( $action, $attachment_id ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'slashimage-image-optimizer' ), 403 );
		}
		check_admin_referer( $action . '_' . (int) $attachment_id );
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			wp_safe_redirect(
				add_query_arg( self::NOTICE_QUERY_VAR, 'error_invalid_id', $this->referer_or_upload() )
			);
			exit;
		}
	}

	private function referer_or_upload() {
		$referer = wp_get_referer();
		if ( $referer ) {
			return $referer;
		}
		return admin_url( 'upload.php' );
	}

	/* ── Admin notices ────────────────────────────────────────── */

	public function render_notice() {
		// Display-only post-redirect admin notice: the args are sanitized (sanitize_key
		// / int casts) and only build the notice text; the originating admin-post action
		// was already nonce-verified, and a redirect-display target cannot be nonced.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET[ self::NOTICE_QUERY_VAR ] ) ) {
			return;
		}
		$code  = sanitize_key( wp_unslash( $_GET[ self::NOTICE_QUERY_VAR ] ) );
		$count = isset( $_GET[ self::NOTICE_COUNT_VAR ] ) ? (int) $_GET[ self::NOTICE_COUNT_VAR ] : 0;
		$fail  = isset( $_GET['slash_image_failed'] ) ? (int) $_GET['slash_image_failed'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$kind = 'success';
		$msg  = '';

		switch ( $code ) {
			case 'restore_done':
				if ( $fail > 0 ) {
					$kind = 'warning';
					/* translators: %1$d: restored count, %2$d: failed count */
					$msg = sprintf( __( 'Restored %1$d attachment(s). %2$d could not be restored.', 'slashimage-image-optimizer' ), $count, $fail );
				} else {
					/* translators: %d: number of attachments restored */
					$msg = sprintf( _n( 'Restored %d attachment.', 'Restored %d attachments.', $count, 'slashimage-image-optimizer' ), $count );
				}
				$msg .= ' ' . Slash_Image_Admin::cache_purge_reminder( 'restore' );
				break;
			case 'restore_queued':
				/* translators: %d: number of attachments queued for restore */
				$msg = sprintf( _n( 'Queued %d image for restore. It will appear restored as soon as it finishes.', 'Queued %d images for restore. They will appear restored as each one finishes.', $count, 'slashimage-image-optimizer' ), $count );
				break;
			case 'reoptimize_done':
				$msg = __( 'Original restored — queued for re-optimization.', 'slashimage-image-optimizer' ) . ' ' . Slash_Image_Admin::cache_purge_reminder();
				break;
			case 'reoptimize_no_backup':
				$kind = 'error';
				$msg  = __( 'Original backup not found. Restore the original file manually before re-optimizing.', 'slashimage-image-optimizer' );
				break;
			case 'reoptimize_failed':
				$kind = 'error';
				$msg  = __( 'Re-optimization failed. Check the Media Library status column for details.', 'slashimage-image-optimizer' );
				break;
			case 'restore_failed':
				$kind = 'error';
				$msg  = __( 'Restore failed. The backup may be missing or unwritable.', 'slashimage-image-optimizer' );
				break;
			case 'restore_too_many':
				$kind = 'warning';
				/* translators: %d: number of attachments selected */
				$msg = sprintf( __( 'Cannot restore %d attachments at once. Please select fewer than 500, or use Media → SlashImage → Restore all.', 'slashimage-image-optimizer' ), $count );
				break;
			case 'error_capability':
				$kind = 'error';
				$msg  = __( 'You do not have permission to do that.', 'slashimage-image-optimizer' );
				break;
			case 'error_invalid_id':
				$kind = 'error';
				$msg  = __( 'That attachment could not be found.', 'slashimage-image-optimizer' );
				break;
			default:
				return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $kind ),
			esc_html( $msg )
		);

		// Strip the notice query args from the URL so refreshing doesn't re-fire.
		// Handled by admin/js/notice-cleanup.js, enqueued from
		// Slash_Image_Admin::enqueue_assets() on the screens these args land on
		// (upload.php, post.php).
	}

	/* ── Attachment edit-screen meta box ──────────────────────── */

	public function register_meta_box() {
		add_meta_box(
			'slash-image-status',
			__( 'SlashImage Status', 'slashimage-image-optimizer' ),
			array( $this, 'render_meta_box' ),
			'attachment',
			'side',
			'default'
		);
	}

	public function render_meta_box( $post ) {
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return;
		}
		require SLASH_IMAGE_PATH . 'admin/views/attachment-meta-box.php';
	}
}
