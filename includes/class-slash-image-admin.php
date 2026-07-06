<?php
/**
 * Admin bootstrap. Registers the Media → SlashImage page, enqueues
 * page-scoped assets, and handles the AJAX actions used by the settings UI.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Admin {

	const MENU_SLUG = 'slash-image-settings';

	private $settings_screen_hook = '';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( 'Slash_Image_Settings', 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_slash_image_save_key', array( $this, 'ajax_save_key' ) );
		add_action( 'wp_ajax_slash_image_disconnect', array( $this, 'ajax_disconnect' ) );
		add_action( 'wp_ajax_slash_image_restore_all', array( $this, 'ajax_restore_all' ) );
		add_action( 'wp_ajax_slash_image_delete_backups', array( $this, 'ajax_delete_backups' ) );
		add_action( 'wp_ajax_slash_image_htaccess_apply', array( $this, 'ajax_htaccess_apply' ) );
		add_action( 'wp_ajax_slash_image_htaccess_remove', array( $this, 'ajax_htaccess_remove' ) );
	}

	public static function detect_cache_plugin() {
		if ( defined( 'WP_ROCKET_VERSION' ) || function_exists( 'rocket_clean_domain' ) ) {
			return array(
				'name'   => 'WP Rocket',
				'action' => __( 'Purge your WP Rocket cache', 'slashimage-image-optimizer' ),
			);
		}
		if ( defined( 'W3TC' ) || class_exists( 'W3TC\\Root_Loader', false ) ) {
			return array(
				'name'   => 'W3 Total Cache',
				'action' => __( 'Purge your W3 Total Cache', 'slashimage-image-optimizer' ),
			);
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			return array(
				'name'   => 'WP Super Cache',
				'action' => __( 'Delete the WP Super Cache contents', 'slashimage-image-optimizer' ),
			);
		}
		if ( defined( 'LSCWP_V' ) || class_exists( 'LiteSpeed\\Core', false ) ) {
			return array(
				'name'   => 'LiteSpeed Cache',
				'action' => __( 'Purge all LiteSpeed cache', 'slashimage-image-optimizer' ),
			);
		}
		if ( defined( 'FLYING_PRESS_VERSION' ) ) {
			return array(
				'name'   => 'FlyingPress',
				'action' => __( 'Purge all FlyingPress caches', 'slashimage-image-optimizer' ),
			);
		}
		return null;
	}

	/**
	 * One-line "purge your cache" reminder for the moments where we overwrite an
	 * already-served image (bulk completion, manual re-optimize, restore) — so a
	 * CDN / page cache doesn't keep serving the stale bytes at that URL. When a
	 * cache plugin is detected, name its specific purge action; otherwise a
	 * generic CDN/page-cache line. The trailing outcome clause matches the action:
	 * `optimize` (default) → "…the optimized images"; `restore` → "…the restored
	 * images" (the user just un-optimized, so "optimized" would be wrong). Plain
	 * text (callers escape).
	 *
	 * @param string $context 'optimize' (default) | 'restore'.
	 * @return string
	 */
	public static function cache_purge_reminder( $context = 'optimize' ) {
		$plugin = self::detect_cache_plugin();
		$action = ( is_array( $plugin ) && ! empty( $plugin['action'] ) ) ? (string) $plugin['action'] : '';

		if ( 'restore' === $context ) {
			if ( '' !== $action ) {
				return sprintf(
					/* translators: %s: a cache-purge instruction, e.g. "Purge your WP Rocket cache" */
					__( '%s - and any CDN - so the restored images are served.', 'slashimage-image-optimizer' ),
					$action
				);
			}
			return __( 'If you use a CDN or page cache, purge it so the restored images are served.', 'slashimage-image-optimizer' );
		}

		if ( '' !== $action ) {
			return sprintf(
				/* translators: %s: a cache-purge instruction, e.g. "Purge your WP Rocket cache" */
				__( '%s - and any CDN - so visitors get the optimized images.', 'slashimage-image-optimizer' ),
				$action
			);
		}
		return __( 'If you use a CDN or page cache, purge it so visitors get the optimized images.', 'slashimage-image-optimizer' );
	}

	public function register_menu() {
		$this->settings_screen_hook = add_media_page(
			esc_html__( 'SlashImage', 'slashimage-image-optimizer' ),
			esc_html__( 'SlashImage', 'slashimage-image-optimizer' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		require SLASH_IMAGE_PATH . 'admin/views/settings-page.php';
	}

	public function enqueue_assets( $hook_suffix ) {
		// Tiny global JS to dismiss the admin notice on auto-save success across all admin pages.
		if ( current_user_can( 'manage_options' ) ) {
			wp_enqueue_script(
				'slash-image-notice',
				SLASH_IMAGE_URL . 'admin/js/notice.js',
				array(),
				SLASH_IMAGE_VERSION,
				true
			);
			// ajax_url for the account-error notice's dismiss POST (the nonce
			// rides in the notice's data attribute). See Slash_Image_Admin_Notice.
			wp_localize_script(
				'slash-image-notice',
				'SlashImageNotice',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
				)
			);

			// Strip the post-redirect notice query args from the URL so a refresh
			// doesn't re-fire the Media Library notice. render_notice() (on
			// admin_notices) is gated only by the presence of this query arg, not
			// by screen — its redirect target is wp_get_referer(), so the notice
			// can surface on any plugin screen (e.g. the Bulk page). Mirror that
			// trigger exactly: load whenever the arg is present. Presence-only
			// read for a cosmetic URL cleanup — no state change, so no nonce.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET[ Slash_Image_Media_Library::NOTICE_QUERY_VAR ] ) ) {
				wp_enqueue_script(
					'slash-image-notice-cleanup',
					SLASH_IMAGE_URL . 'admin/js/notice-cleanup.js',
					array(),
					SLASH_IMAGE_VERSION,
					true
				);
			}
		}

		// Media Library + attachment edit screen — load the dedicated stylesheet
		// and the bulk-action confirm helper. media-new.php (Media → Add New)
		// is included so the watched-upload kick fires there too; its
		// status-column CSS is inert on that screen but harmless.
		if ( in_array( $hook_suffix, array( 'upload.php', 'media-new.php', 'post.php', 'post-new.php' ), true ) ) {
			wp_enqueue_style( 'dashicons' );
			wp_enqueue_style(
				'slash-image-media-library',
				SLASH_IMAGE_URL . 'admin/css/media-library.css',
				array( 'dashicons' ),
				SLASH_IMAGE_VERSION
			);
			if ( in_array( $hook_suffix, array( 'upload.php', 'media-new.php', 'post.php', 'post-new.php' ), true ) ) {
				wp_enqueue_script(
					'slash-image-media-library',
					SLASH_IMAGE_URL . 'admin/js/media-library.js',
					array(),
					SLASH_IMAGE_VERSION,
					true
				);
				wp_localize_script(
					'slash-image-media-library',
					'SlashImageMedia',
					array(
						'restore_action'      => Slash_Image_Media_Library::BULK_RESTORE,
						'optimize_action'     => Slash_Image_Media_Library::BULK_OPTIMIZE,
						'restore_cap'         => Slash_Image_Media_Library::BULK_RESTORE_SOFT_CAP,
						'ajax_url'            => admin_url( 'admin-ajax.php' ),
						'poll_nonce'          => wp_create_nonce( 'slash_image_status_poll' ),
						'poll_interval'       => 5000,
						// Server-rendered "Optimizing…" pill with a placeholder
						// data-id; the JS swaps in each real id to paint the bulk
						// action's optimistic pills (matches the server's exactly).
						'processing_template' => Slash_Image_Column::render_processing_template(),
						'i18n'                => array(
							'restore_confirm'       => array(
								/* translators: %d: number of selected attachments */
								'other' => __( 'Restore the originals for %d images? Each one replaces its optimized version as soon as it finishes.', 'slashimage-image-optimizer' ),
								/* translators: %d: number of selected attachments */
								'one'   => __( 'Restore the original for %d image? It replaces its optimized version as soon as it finishes.', 'slashimage-image-optimizer' ),
							),
							'queued'                => __( 'Queued', 'slashimage-image-optimizer' ),
							'processing'            => __( 'Optimizing…', 'slashimage-image-optimizer' ),
							'optimized'             => __( 'Optimized', 'slashimage-image-optimizer' ),
							'error'                 => __( 'Error', 'slashimage-image-optimizer' ),
							'not_optimized'         => __( 'Not optimized', 'slashimage-image-optimizer' ),
							'bulk_start_failed'     => __( "Couldn't start optimization, please try again.", 'slashimage-image-optimizer' ),
							'reoptimize_failed'     => __( 'Re-optimization could not be started.', 'slashimage-image-optimizer' ),
							'no_api_key'            => __( 'Add an API key to start optimizing.', 'slashimage-image-optimizer' ),
							'reload_to_see_details' => __( 'Reload to see updated details.', 'slashimage-image-optimizer' ),
						),
					)
				);
			}
		}

		if ( $hook_suffix !== $this->settings_screen_hook ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );

		wp_enqueue_style(
			'slash-image-settings',
			SLASH_IMAGE_URL . 'admin/css/settings.css',
			array( 'dashicons' ),
			SLASH_IMAGE_VERSION
		);

		wp_enqueue_script(
			'slash-image-settings',
			SLASH_IMAGE_URL . 'admin/js/settings.js',
			array(),
			SLASH_IMAGE_VERSION,
			true
		);

		wp_localize_script(
			'slash-image-settings',
			'SlashImageSettings',
			array(
				'ajax_url'          => admin_url( 'admin-ajax.php' ),
				'save_key_nonce'    => wp_create_nonce( 'slash_image_save_key' ),
				'restore_nonce'     => wp_create_nonce( 'slash_image_restore_all' ),
				'delete_nonce'      => wp_create_nonce( 'slash_image_delete_backups' ),
				'htaccess_nonce'    => wp_create_nonce( 'slash_image_htaccess' ),
				'disconnect_nonce'  => wp_create_nonce( 'slash_image_disconnect' ),
				// Client-side key-format check derives from the single PHP source
				// of truth (delimiters stripped for use in a JS RegExp).
				'key_regex'         => trim( Slash_Image_Settings::API_KEY_REGEX, '/' ),
				'htaccess_active'   => Slash_Image_Htaccess::is_active(),
				'server_kind'       => Slash_Image_Server::detect(),
				'htaccess_writable' => Slash_Image_Server::htaccess_writable(),
				'cache_plugin'      => self::detect_cache_plugin(),
				// Danger-Zone confirmation-modal copy (read top-level by settings.js).
				'modal_restore_title'   => __( 'Restore all originals?', 'slashimage-image-optimizer' ),
				'modal_restore_body'    => __( 'This restores your original images and removes all WebP and AVIF variants. This cannot be undone.', 'slashimage-image-optimizer' ),
				'modal_restore_confirm' => __( 'Restore all', 'slashimage-image-optimizer' ),
				'modal_delete_title'    => __( 'Delete all backups?', 'slashimage-image-optimizer' ),
				'modal_delete_body'     => __( 'This permanently removes all original backup files. You will not be able to restore or re-optimize images after this.', 'slashimage-image-optimizer' ),
				'modal_delete_confirm'  => __( 'Delete all backups', 'slashimage-image-optimizer' ),
				'i18n'              => array(
					'show'                           => __( 'Show', 'slashimage-image-optimizer' ),
					'hide'                           => __( 'Hide', 'slashimage-image-optimizer' ),
					'disconnect'                     => __( 'Disconnect', 'slashimage-image-optimizer' ),
					'disconnecting'                  => __( 'Disconnecting…', 'slashimage-image-optimizer' ),
					'connect'                        => __( 'Connect', 'slashimage-image-optimizer' ),
					'connecting'                     => __( 'Connecting…', 'slashimage-image-optimizer' ),
					'key_empty'                      => __( 'Please paste your API key.', 'slashimage-image-optimizer' ),
					'key_format'                     => __( 'Key looks incomplete - it should start with sk_live_ or sk_sub_ followed by 48 characters.', 'slashimage-image-optimizer' ),
					'format_hint'                    => __( 'Format: sk_live_… or sk_sub_…', 'slashimage-image-optimizer' ),
					'verifying'                      => __( 'Verifying…', 'slashimage-image-optimizer' ),
					'connected_just_now'             => __( '✓ Connected — verified just now', 'slashimage-image-optimizer' ),
					/* translators: %s: human time difference, e.g. "5 minutes" */
					'connected_at'                   => __( '✓ Connected — verified %s ago', 'slashimage-image-optimizer' ),
					'cleared'                        => __( 'Key cleared.', 'slashimage-image-optimizer' ),
					'invalid_format'                 => __( 'API key format is invalid. Keys look like sk_live_… or sk_sub_… and are 48 hex characters long.', 'slashimage-image-optimizer' ),
					'invalid_key'                    => __( 'Key was rejected by the API. Double-check that you copied the entire key.', 'slashimage-image-optimizer' ),
					'rate_limited'                   => __( 'Too many requests - please wait a moment and try again.', 'slashimage-image-optimizer' ),
					'server_error'                   => __( 'The slashimage.com API returned an error. Try again in a minute.', 'slashimage-image-optimizer' ),
					'network_error'                  => __( "Could not reach the API. Check your server's outbound network access.", 'slashimage-image-optimizer' ),
					'unexpected'                     => __( 'Unexpected response from the API. Try again, then contact support if it persists.', 'slashimage-image-optimizer' ),
					'restore_running'                => __( 'Starting restore…', 'slashimage-image-optimizer' ),
					'restore_started'                => array(
						/* translators: %d: number of images queued for restore */
						'other' => __( 'Restore started. %d images queued. Track progress on the Bulk Optimize page.', 'slashimage-image-optimizer' ),
						/* translators: %d: number of images queued for restore */
						'one'   => __( 'Restore started. %d image queued. Track progress on the Bulk Optimize page.', 'slashimage-image-optimizer' ),
					),
					'restore_busy'                   => __( 'An optimization run is in progress. Pause or cancel it on the Bulk Optimize page, then start Restore all again.', 'slashimage-image-optimizer' ),
					'restore_none'                   => __( 'No backups found to restore.', 'slashimage-image-optimizer' ),
					'restore_failed'                 => __( 'Restore failed. Reload the page and try again.', 'slashimage-image-optimizer' ),
					'delete_running'                 => __( 'Deleting backups…', 'slashimage-image-optimizer' ),
					/* translators: %1$d: backups deleted, %2$d: attachments touched, %3$s: human-readable size */
					'delete_summary'                 => __( '%1$d backups deleted across %2$d attachments. %3$s freed.', 'slashimage-image-optimizer' ),
					/* translators: %1$d: backups deleted, %2$d: errors encountered, %3$s: human-readable size */
					'delete_partial'                 => __( '%1$d backups deleted, %2$d errors. %3$s freed.', 'slashimage-image-optimizer' ),
					'delete_failed'                  => __( 'Delete failed. Reload the page and try again.', 'slashimage-image-optimizer' ),
					'delete_none'                    => __( 'No backups to delete.', 'slashimage-image-optimizer' ),
					'mode_helper_lossy'              => __( 'Best for most websites. Smallest file sizes with very minor quality differences invisible to most viewers.', 'slashimage-image-optimizer' ),
					'mode_helper_glossy'             => __( 'Higher quality with moderate compression. Choose this for portfolios or photography sites where image fidelity matters.', 'slashimage-image-optimizer' ),
					'mode_helper_lossless'           => __( 'Minimum compression. Choose this only when you need pixel-perfect originals.', 'slashimage-image-optimizer' ),
					// Backup-mode pill (Full = 0, Smart = 1) — swapped under the pill on selection.
					'mode_helper_0'                  => __( 'Backs up the original and all its thumbnails, so a restore brings everything back exactly as it was. Uses more disk space.', 'slashimage-image-optimizer' ),
					'mode_helper_1'                  => __( 'Backs up only the original to save disk space. When you restore, thumbnails are rebuilt from it using your current image sizes.', 'slashimage-image-optimizer' ),
					/* translators: %s: human time difference, e.g. "5 minutes" */
					'last_saved'                     => __( 'Last saved %s ago', 'slashimage-image-optimizer' ),
					'never_saved'                    => __( 'Not saved yet', 'slashimage-image-optimizer' ),
					'saving'                         => __( 'Saving…', 'slashimage-image-optimizer' ),
					'saved'                          => __( 'Saved.', 'slashimage-image-optimizer' ),
					'fe_helper_picture'              => __( 'Wraps <img> tags with <picture> elements. Works on any host. Recommended for most sites.', 'slashimage-image-optimizer' ),
					'fe_helper_htaccess_apache'      => __( 'Faster and works for CSS backgrounds. Apache only.', 'slashimage-image-optimizer' ),
					'fe_helper_htaccess_nginx'       => __( 'Faster and works for CSS backgrounds. Nginx requires manual config — see the docs.', 'slashimage-image-optimizer' ),
					'fe_helper_htaccess_unsupported' => __( 'Not supported on this server. Use the <picture> tag mode.', 'slashimage-image-optimizer' ),
					'fe_helper_disabled'             => __( "Disable SlashImage's frontend serving. Use this if you handle format delivery via a CDN or cache plugin.", 'slashimage-image-optimizer' ),
					'fe_apply_btn'                   => __( 'Apply rewrite rules to .htaccess', 'slashimage-image-optimizer' ),
					'fe_remove_btn'                  => __( 'Remove rewrite rules', 'slashimage-image-optimizer' ),
					'fe_active'                      => __( '✓ Rewrite rules active in .htaccess', 'slashimage-image-optimizer' ),
					'fe_apply_running'               => __( 'Applying rules…', 'slashimage-image-optimizer' ),
					'fe_remove_running'              => __( 'Removing rules…', 'slashimage-image-optimizer' ),
					'fe_apply_failed'                => __( 'Could not apply rules.', 'slashimage-image-optimizer' ),
					'fe_remove_failed'               => __( 'Could not remove rules.', 'slashimage-image-optimizer' ),
					'fe_not_writable'                => __( 'The site\'s .htaccess is not writable. Check file permissions.', 'slashimage-image-optimizer' ),
					'fe_probe_failed'                => __( 'Site failed to respond after applying rules. The previous .htaccess was restored from backup.', 'slashimage-image-optimizer' ),
					'fe_nginx_docs'                  => __( 'Nginx requires manual server configuration. See the setup guide:', 'slashimage-image-optimizer' ),
					'fe_open_nginx_docs'             => __( 'Open Nginx docs →', 'slashimage-image-optimizer' ),
					/* translators: %s: cache-plugin specific purge instruction */
					'fe_cache_notice'                => __( 'Frontend serving mode changed. %s to apply changes to existing pages.', 'slashimage-image-optimizer' ),
					'fe_cache_generic'               => __( 'If you use a page cache plugin, purge its cache', 'slashimage-image-optimizer' ),
					'fe_cf_warning'                  => __( 'Cloudflare detected. Server rewrite rules need the Accept header forwarded to origin, which is enterprise-only on Cloudflare. Switch to <picture> tag mode for reliable AVIF/WebP delivery.', 'slashimage-image-optimizer' ),
					'fe_cf_switch'                   => __( 'Switch to picture mode', 'slashimage-image-optimizer' ),
					'fe_cf_learn_more'               => __( 'Learn more →', 'slashimage-image-optimizer' ),
					'connected_label'                => __( 'Connected', 'slashimage-image-optimizer' ),
					'disconnected_label'             => __( 'Not configured', 'slashimage-image-optimizer' ),
					/* translators: %d: count of patterns */
					'exclusions_count'               => __( 'Currently excluding: %d patterns', 'slashimage-image-optimizer' ),
					'exclusions_none'                => __( 'No custom patterns yet.', 'slashimage-image-optimizer' ),
				),
			)
		);
	}

	public function ajax_save_key() {
		check_ajax_referer( 'slash_image_save_key', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
		}

		$candidate = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		$candidate = trim( $candidate );

		$current_settings = Slash_Image_Settings::all();
		$current_settings = is_array( $current_settings ) ? $current_settings : array();

		if ( '' === $candidate ) {
			$current_settings['api_key'] = '';
			update_option( Slash_Image_Settings::OPTION_NAME, $current_settings );
			delete_option( 'slash_image_key_verified_at' );
			Slash_Image_Connection::clear_invalid();
			Slash_Image_Connection::invalidate();
			Slash_Image_Connection::clear_plan_cache();
			wp_send_json_success(
				array(
					'code'      => 'cleared',
					'connected' => false,
				)
			);
		}

		if ( ! preg_match( Slash_Image_Settings::API_KEY_REGEX, $candidate ) ) {
			wp_send_json_error( array( 'code' => 'invalid_format' ) );
		}

		$result = Slash_Image_Api_Client::verify_key( $candidate );

		if ( empty( $result['ok'] ) ) {
			$code = isset( $result['code'] ) ? (string) $result['code'] : 'unexpected';
			wp_send_json_error( array( 'code' => $code ) );
		}

		$current_settings['api_key'] = $candidate;
		update_option( Slash_Image_Settings::OPTION_NAME, $current_settings );
		update_option( 'slash_image_key_verified_at', time(), false );
		// Reconnecting (a verified key) clears any prior dead-key flip.
		Slash_Image_Connection::clear_invalid();

		// Cache the plan returned by the verify probe (GET /v1/keys/me) so the
		// dashboard credits row + the plugins.php Upgrade gate have it immediately.
		if ( ! empty( $result['plan'] ) && is_array( $result['plan'] ) ) {
			Slash_Image_Connection::set_plan_cache( $result['plan'] );
		}

		Slash_Image_Connection::invalidate();
		Slash_Image_Connection::rebuild();

		wp_send_json_success(
			array(
				'code'        => 'valid',
				'connected'   => true,
				'fingerprint' => Slash_Image_Connection::fingerprint( $candidate ),
			)
		);
	}

	/**
	 * Disconnect: wipe the stored key, verification stamp, connection state, and
	 * plan cache. The settings page reloads into the disconnected (empty-input)
	 * state. Uses clear_plan_cache() — set_plan_cache() requires an array.
	 */
	public function ajax_disconnect() {
		check_ajax_referer( 'slash_image_disconnect', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$settings = Slash_Image_Settings::all();
		$settings = is_array( $settings ) ? $settings : array();
		$settings['api_key'] = '';
		update_option( Slash_Image_Settings::OPTION_NAME, $settings );
		delete_option( 'slash_image_key_verified_at' );
		Slash_Image_Connection::clear_invalid();
		Slash_Image_Connection::invalidate();
		Slash_Image_Connection::clear_plan_cache();

		wp_send_json_success( array( 'disconnected' => true ) );
	}

	public function ajax_restore_all() {
		check_ajax_referer( 'slash_image_restore_all', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
		}

		// Async: start a typed restore run through the queue/worker (bounded by the
		// worker budget) instead of a synchronous whole-library loop. The snapshot
		// carries `refused => 'optimize_running'` when an optimize bulk is active
		// and `total` = images queued; returned as success so the JS reads the
		// fields without a non-2xx rejection.
		$snapshot = Slash_Image_Bulk_Processor::start_restore();
		wp_send_json_success( $snapshot );
	}

	public function ajax_delete_backups() {
		check_ajax_referer( 'slash_image_delete_backups', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
		}

		Slash_Image_Worker::remove_time_limit();
		$summary                  = Slash_Image_Restore::delete_all_backups();
		$bytes_freed_human        = size_format( $summary['bytes_freed'], 1 );
		$summary['bytes_freed_h'] = $bytes_freed_human ? $bytes_freed_human : '0 B';

		wp_send_json_success( $summary );
	}

	public function ajax_htaccess_apply() {
		check_ajax_referer( 'slash_image_htaccess', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
		}

		$result = Slash_Image_Htaccess::apply();
		if ( ! empty( $result['ok'] ) ) {
			wp_send_json_success( array( 'active' => true ) );
		}
		wp_send_json_error(
			array(
				'code'    => isset( $result['code'] ) ? $result['code'] : 'unknown',
				'message' => isset( $result['message'] ) ? $result['message'] : '',
			)
		);
	}

	public function ajax_htaccess_remove() {
		check_ajax_referer( 'slash_image_htaccess', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
		}

		$result = Slash_Image_Htaccess::remove();
		if ( ! empty( $result['ok'] ) ) {
			wp_send_json_success( array( 'active' => false ) );
		}
		wp_send_json_error( array( 'code' => isset( $result['code'] ) ? $result['code'] : 'unknown' ) );
	}
}
