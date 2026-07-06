<?php
/**
 * Bulk Optimize admin page (Media → Bulk Optimize) and its AJAX endpoints.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Bulk_Page {

	const MENU_SLUG = 'slash-image-bulk';

	private $screen_hook = '';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'wp_ajax_slash_image_bulk_progress', array( $this, 'ajax_progress' ) );
		add_action( 'wp_ajax_slash_image_bulk_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_slash_image_bulk_pause', array( $this, 'ajax_pause' ) );
		add_action( 'wp_ajax_slash_image_bulk_resume', array( $this, 'ajax_resume' ) );
		add_action( 'wp_ajax_slash_image_bulk_cancel', array( $this, 'ajax_cancel' ) );
		add_action( 'wp_ajax_slash_image_worker_kick', array( $this, 'ajax_worker_kick' ) );
		add_action( 'wp_ajax_slash_image_bulk_retry_failed', array( $this, 'ajax_retry_failed' ) );
		add_action( 'wp_ajax_slash_image_bulk_clear_failed', array( $this, 'ajax_clear_failed' ) );
		add_action( 'wp_ajax_slash_image_bulk_probe_status', array( $this, 'ajax_probe_status' ) );
	}

	public function register_menu() {
		$this->screen_hook = add_media_page(
			esc_html__( 'Bulk Optimize', 'slashimage-image-optimizer' ),
			esc_html__( 'Bulk Optimize', 'slashimage-image-optimizer' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		require SLASH_IMAGE_PATH . 'admin/views/bulk-page.php';
	}

	public function enqueue_assets( $hook_suffix ) {
		if ( $hook_suffix !== $this->screen_hook ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style(
			'slash-image-settings',
			SLASH_IMAGE_URL . 'admin/css/settings.css',
			array( 'dashicons' ),
			SLASH_IMAGE_VERSION
		);
		wp_enqueue_style(
			'slash-image-bulk',
			SLASH_IMAGE_URL . 'admin/css/bulk.css',
			array( 'slash-image-settings' ),
			SLASH_IMAGE_VERSION
		);
		wp_enqueue_script(
			'slash-image-bulk',
			SLASH_IMAGE_URL . 'admin/js/bulk.js',
			array(),
			SLASH_IMAGE_VERSION,
			true
		);

		// Trigger a probe for accurate banner state when the user lands here.
		Slash_Image_Cron_Probe::status();

		wp_localize_script(
			'slash-image-bulk',
			'SlashImageBulk',
			array(
				'ajax_url'  => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'slash_image_bulk' ),
				'media_url' => admin_url( 'upload.php' ),
				'i18n'      => array(
					'cancel_confirm'   => __( 'Cancel will discard the pending queue. Already-processed images stay optimized. Are you sure?', 'slashimage-image-optimizer' ),
					/* translators: %d: number of images processed in the last 60 seconds */
					'recent_rate'      => __( 'Recent rate: %d images in the last minute', 'slashimage-image-optimizer' ),
					'polling_paused'   => __( 'Polling paused after 30 minutes of inactivity. Refresh to resume.', 'slashimage-image-optimizer' ),
					'retry_running'    => __( 'Retrying failed items…', 'slashimage-image-optimizer' ),
					'title_idle'       => __( 'Bulk Optimization', 'slashimage-image-optimizer' ),
					'title_processing' => __( 'Bulk Optimization — Processing', 'slashimage-image-optimizer' ),
					'title_paused'     => __( 'Bulk Optimization — Paused', 'slashimage-image-optimizer' ),
					'title_completed'  => __( 'Bulk Optimization — Completed', 'slashimage-image-optimizer' ),
					/* translators: %1$s: processed count, %2$s: total count */
					'progress_x_of_y'  => __( '%1$s / %2$s files', 'slashimage-image-optimizer' ),
					/* translators: %1$s: processed count, %2$s: total count */
					'completed_x_of_y' => __( '%1$s / %2$s processed', 'slashimage-image-optimizer' ),
					/* translators: %s: number of unoptimized files */
					'hint_unoptimized' => __( 'This will process %s unoptimized files.', 'slashimage-image-optimizer' ),
					/* translators: %1$d: percent saved, %2$d: number of images */
					'overview_summary' => __( '%1$d%% saved across %2$d images', 'slashimage-image-optimizer' ),
					/* translators: %s: number of thumbnails optimized */
					'thumbs_subtitle'  => __( '+%s thumbnails optimized', 'slashimage-image-optimizer' ),
					// Bulk Optimize page redesign — consumed by bulk.js render().
					'optimizing'       => __( 'Optimizing', 'slashimage-image-optimizer' ),
					'restoring'        => __( 'Restoring', 'slashimage-image-optimizer' ),
					'restore_deferred' => array(
						/* translators: %d: images skipped because they were mid-optimize */
						'other' => __( '%d images were being optimized and were not restored. Run Restore all again to catch them.', 'slashimage-image-optimizer' ),
						/* translators: %d: image skipped because it was mid-optimize */
						'one'   => __( '%d image was being optimized and was not restored. Run Restore all again to catch them.', 'slashimage-image-optimizer' ),
					),
					'credits'          => __( 'credits', 'slashimage-image-optimizer' ),
					/* translators: %d: percent complete */
					'pct_complete'     => __( '%d% complete', 'slashimage-image-optimizer' ),
					'pausing'          => __( 'Pausing…', 'slashimage-image-optimizer' ),
					'no_api_key'       => __( 'Add an API key to start optimizing.', 'slashimage-image-optimizer' ),
				),
			)
		);
	}

	/* ── AJAX endpoints ──────────────────────────────────── */

	public function ajax_progress() {
		$this->verify_request();
		wp_send_json_success( $this->build_payload() );
	}

	public function ajax_start() {
		$this->verify_request();
		// Nonce + cap already verified inside verify_request().
		$force = ! empty( $_POST['force_redo'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		// No-key gate: don't start a run; hand the JS a `refused => 'no_key'`
		// payload so it shows the transient add-a-key one-liner. (start() also
		// self-gates, but ajax_start rebuilds a fresh payload that wouldn't carry
		// its refusal marker — so the surface is set here.)
		if ( ! Slash_Image_Connection::has_api_key() ) {
			$payload            = $this->build_payload();
			$payload['refused'] = 'no_key';
			wp_send_json_success( $payload );
		}

		// Force a probe re-run if the host disabled WP-Cron — gives a fresh classification.
		if ( Slash_Image_Cron_Probe::cron_is_disabled() ) {
			Slash_Image_Cron_Probe::reset();
			Slash_Image_Cron_Probe::start_probe();
		}

		Slash_Image_Bulk_Processor::start( $force );
		wp_send_json_success( $this->build_payload() );
	}

	public function ajax_pause() {
		$this->verify_request();
		Slash_Image_Bulk_Processor::pause();
		wp_send_json_success( $this->build_payload() );
	}

	public function ajax_resume() {
		$this->verify_request();
		Slash_Image_Bulk_Processor::resume();
		wp_send_json_success( $this->build_payload() );
	}

	public function ajax_cancel() {
		$this->verify_request();
		Slash_Image_Bulk_Processor::cancel();
		wp_send_json_success( $this->build_payload() );
	}

	/**
	 * Bulk-page worker kick. Driven continuously by the bulk
	 * page JS on all hosts while a run is `running`. **Budgeted**: processes ONE
	 * attachment per request via `process_batch(1)` and returns immediately, so
	 * a foreground request never holds a PHP-FPM worker for the whole batch and
	 * starve the concurrent progress poll (same model as the Media Library
	 * kick). The JS chain re-dispatches while `queue_has_more`. Returns a MINIMAL
	 * payload — NO snapshot — because the separate `ajax_progress` poll renders
	 * the bar; building the snapshot (library_counts + aggregate_size_totals,
	 * the C2 pass) on every kick would be wasteful.
	 */
	public function ajax_worker_kick() {
		$this->verify_request();
		Slash_Image_Worker::remove_time_limit();
		$result  = Slash_Image_Bulk_Processor::process_batch( 1 );
		$claimed = (int) ( $result['claimed'] ?? 0 );
		// Keep draining only while this tick claimed a row AND rows remain — so
		// a tick that claims nothing (queue drained, or remaining rows are
		// backoff-gated) stops the chain instead of busy-looping; gated retries
		// fall to the cron tick.
		$has_more = ( $claimed > 0 ) && ( (int) ( Slash_Image_Queue::counts()['waiting'] ?? 0 ) > 0 );

		wp_send_json_success(
			array(
				'processed'      => (int) ( $result['processed'] ?? 0 ),
				'failed'         => (int) ( $result['failed'] ?? 0 ),
				'queue_has_more' => $has_more,
			)
		);
	}

	public function ajax_retry_failed() {
		$this->verify_request();
		Slash_Image_Bulk_Processor::retry_failed();
		wp_send_json_success( $this->build_payload() );
	}

	public function ajax_clear_failed() {
		$this->verify_request();
		Slash_Image_Bulk_Processor::clear_failed();
		wp_send_json_success( $this->build_payload() );
	}

	public function ajax_probe_status() {
		$this->verify_request();
		$status = Slash_Image_Cron_Probe::evaluate();
		wp_send_json_success( array( 'cron_status' => $status ) );
	}

	private function verify_request() {
		check_ajax_referer( 'slash_image_bulk', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
		}
	}

	private function build_payload() {
		return array(
			'progress'    => Slash_Image_Bulk_Processor::snapshot(),
			'cron_status' => Slash_Image_Cron_Probe::evaluate(),
		);
	}
}
