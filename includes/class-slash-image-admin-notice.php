<?php
/**
 * Site-wide admin notice. Shown across every WP admin page until the plugin
 * is connected (an API key has been verified at least once).
 *
 * Non-dismissible — these are blocking issues, not nags.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Admin_Notice {

	/**
	 * Transient holding a one-off account-level error notice (bulk paused on a
	 * 401/402). Shape: array( 'type' => 'error', 'code' => 'invalid_key' |
	 * 'payment_required' ). Set by the worker on a terminal key/billing failure;
	 * cleared when the user dismisses the notice.
	 */
	const ACCOUNT_TRANSIENT = 'slash_image_admin_notice';
	const ACCOUNT_TTL       = WEEK_IN_SECONDS;
	const DISMISS_ACTION    = 'slash_image_dismiss_notice';

	public function __construct() {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::DISMISS_ACTION, array( $this, 'ajax_dismiss' ) );
	}

	/**
	 * Enqueue the notice stylesheet, gated to the same condition render() uses
	 * to print the styled "needs a key" notice: a manage_options user, on a
	 * plugin-relevant screen, while disconnected. The account-error notice uses
	 * core notice-error markup and needs no custom CSS, so mirroring the
	 * disconnected gate loads the stylesheet exactly on the requests that emit
	 * the .slash-image-admin-notice markup.
	 */
	public function enqueue_assets() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		if ( ! self::is_plugin_relevant_screen( get_current_screen() ) ) {
			return;
		}

		$state = Slash_Image_Connection::snapshot();
		if ( 'disconnected' !== ( $state['status'] ?? '' ) ) {
			return;
		}

		wp_enqueue_style(
			'slash-image-admin-notice',
			SLASH_IMAGE_URL . 'admin/css/admin-notice.css',
			array(),
			SLASH_IMAGE_VERSION
		);
	}

	/**
	 * Whether the persistent connection notices should render on this screen.
	 * True on the Media library (upload.php), the attachment edit screen, the
	 * plugin Settings page, the Bulk Optimize page, the Dashboard (index.php),
	 * and plugins.php — false everywhere else.
	 *
	 * @param WP_Screen|mixed $screen Current screen.
	 * @return bool
	 */
	public static function is_plugin_relevant_screen( $screen ) {
		if ( ! ( $screen instanceof WP_Screen ) ) {
			return false;
		}

		// The two plugin admin pages, by their registered screen IDs.
		$settings_id = 'media_page_' . Slash_Image_Admin::MENU_SLUG;
		$bulk_id     = 'media_page_' . Slash_Image_Bulk_Page::MENU_SLUG;
		if ( $screen->id === $settings_id || $screen->id === $bulk_id ) {
			return true;
		}

		// The attachment edit screen (post.php for an attachment).
		if ( 'attachment' === $screen->id ) {
			return true;
		}

		// Core screens where image optimization is relevant: Media library,
		// Dashboard, plugins list.
		return in_array( (string) $screen->base, array( 'upload', 'dashboard', 'plugins' ), true );
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || in_array( $screen->base, array( 'customize', 'site-editor' ), true ) ) {
			return;
		}

		// Scope the persistent connection notices (setup / reconnect / credits) to
		// plugin-relevant screens only — they are actionable on the screens where a
		// user manages images, and pure clutter everywhere else.
		if ( ! self::is_plugin_relevant_screen( $screen ) ) {
			return;
		}

		// Account-level error (paused on a 401/402). Dismissible; shown on every
		// relevant screen until the user dismisses it. Can fire even while
		// connected (e.g. a 402). Returns whether it actually rendered.
		$account_rendered = $this->render_account_error();

		// At most ONE connection notice per pass — if the account-error notice
		// rendered, never also show the cream "needs a key" notice (and vice
		// versa). This makes them mutually exclusive in *every* state, not just
		// the common combos: a present-but-dead key shows the single reconnect
		// notice, a keyless site shows only the cream setup notice, a healthy
		// connection shows neither.
		if ( $account_rendered ) {
			return;
		}

		// Setup notice: ONLY for a truly disconnected site (no key).
		$state = Slash_Image_Connection::snapshot();
		if ( 'disconnected' !== ( $state['status'] ?? '' ) ) {
			return;
		}

		$settings_url = admin_url( 'upload.php?page=' . Slash_Image_Admin::MENU_SLUG . '#settings' );
		?>
		<div class="notice notice-warning slash-image-admin-notice" id="slash-image-admin-notice" data-slash-image-notice="needs-key">
			<div class="slash-image-admin-notice__icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
					<path d="M16 5 L8 19" />
				</svg>
			</div>
			<div class="slash-image-admin-notice__body">
				<p class="slash-image-admin-notice__title">
					<?php echo esc_html__( 'SlashImage needs an API key to optimize images', 'slashimage-image-optimizer' ); ?>
				</p>
				<p class="slash-image-admin-notice__text">
					<?php echo esc_html__( 'Sign up for a free account to start optimizing', 'slashimage-image-optimizer' ); ?>
				</p>
			</div>
			<div class="slash-image-admin-notice__actions">
				<a class="button button-primary" href="<?php echo esc_url( SLASH_IMAGE_DASHBOARD_URL ); ?>" target="_blank" rel="noopener noreferrer">
					<?php echo esc_html__( 'Get a free API key', 'slashimage-image-optimizer' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( $settings_url ); ?>">
					<?php echo esc_html__( 'I have a key', 'slashimage-image-optimizer' ); ?>
				</a>
			</div>
		</div>
		<?php
		// Styles for this notice live in admin/css/admin-notice.css, enqueued by
		// enqueue_assets() below under the same screen + disconnected condition
		// this notice renders on.
	}

	/**
	 * Render the account-level error notice (bulk paused on a 401/402), or
	 * nothing when none is pending. Dismissible — the × clears the transient via
	 * ajax_dismiss(). Standard WordPress notice-error markup so it renders
	 * consistently on every admin screen.
	 *
	 * @return bool Whether a notice was actually emitted.
	 */
	private function render_account_error() {
		// An account-level error (invalid/revoked key, or out of credits) only
		// makes sense when a key exists — both codes imply a key. With no key the
		// live status is 'disconnected' and the cream "needs a key" notice is the
		// single correct prompt, so suppress any account-error transient here. This
		// is the belt-and-suspenders that stops a marker which outlived its key from
		// rendering, regardless of how it went stale.
		if ( ! Slash_Image_Connection::has_api_key() ) {
			return false;
		}

		$code = self::current_account_error();
		if ( null === $code ) {
			return false;
		}

		if ( 'payment_required' === $code ) {
			$message = sprintf(
				/* translators: %s: linked "slashimage.com" */
				esc_html__( 'Bulk optimization paused - you have used all your credits. Visit %s to upgrade your plan.', 'slashimage-image-optimizer' ),
				'<a href="' . esc_url( SLASH_IMAGE_DASHBOARD_URL ) . '" target="_blank" rel="noopener noreferrer">slashimage.com</a>'
			);
		} else { // invalid_key — the single reconnect notice (folds in the paused context).
			$settings_url = admin_url( 'upload.php?page=' . Slash_Image_Admin::MENU_SLUG . '#settings' );
			$message      = sprintf(
				/* translators: %s: linked "SlashImage settings" */
				esc_html__( 'Your API key is invalid or was revoked. Optimizing is paused - reconnect in %s.', 'slashimage-image-optimizer' ),
				'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'SlashImage settings', 'slashimage-image-optimizer' ) . '</a>'
			);
		}

		printf(
			'<div class="notice notice-error is-dismissible" id="slash-image-account-notice" data-slash-image-dismiss="%1$s"><p>%2$s</p></div>',
			esc_attr( wp_create_nonce( self::DISMISS_ACTION ) ),
			wp_kses(
				$message,
				array(
					'a' => array(
						'href'   => true,
						'target' => true,
						'rel'    => true,
					),
				)
			)
		);

		return true;
	}

	/**
	 * Store an account-level error notice (called by the worker on a terminal
	 * 401/402). Only invalid_key / payment_required are accepted.
	 *
	 * @param string $code Plugin error code.
	 */
	public static function set_account_error( $code ) {
		$code = (string) $code;
		if ( 'invalid_key' !== $code && 'payment_required' !== $code ) {
			return;
		}
		set_transient(
			self::ACCOUNT_TRANSIENT,
			array(
				'type' => 'error',
				'code' => $code,
			),
			self::ACCOUNT_TTL
		);
	}

	/** Clear the stored account-level error notice. */
	public static function clear_account_error() {
		delete_transient( self::ACCOUNT_TRANSIENT );
	}

	/**
	 * The pending account-error code (invalid_key | payment_required), or null
	 * when none is set / the stored value is unrecognised.
	 *
	 * @return string|null
	 */
	public static function current_account_error() {
		$notice = get_transient( self::ACCOUNT_TRANSIENT );
		if ( ! is_array( $notice ) || empty( $notice['code'] ) ) {
			return null;
		}
		$code = (string) $notice['code'];
		return in_array( $code, array( 'invalid_key', 'payment_required' ), true ) ? $code : null;
	}

	/**
	 * AJAX: dismiss the account-error notice (clears the transient). Bound to
	 * wp_ajax_slash_image_dismiss_notice; posted by admin/js/notice.js when the
	 * user clicks the notice's × button.
	 */
	public function ajax_dismiss() {
		check_ajax_referer( self::DISMISS_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
		}
		self::clear_account_error();
		wp_send_json_success();
	}
}
