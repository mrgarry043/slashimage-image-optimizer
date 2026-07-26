<?php
/**
 * Tools tab controller: migration detection, scan, and run over AJAX.
 *
 * The Tools panel is a third tab on the SlashImage settings screen. Its body is
 * NOT rendered server-side — it ships as an empty skeleton and fetches its
 * cards on first activation. That keeps detection (a ShortPixel table probe and
 * an Imagify postmeta count) off every settings pageview: nothing here runs
 * until someone actually opens Tools. It also means the migration classes are
 * only ever autoloaded inside these handlers.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Tools_Page {

	/** Shared nonce action for every Tools request. */
	const NONCE_ACTION = 'slash_image_migrate';

	/**
	 * Transient prefix for the scan token that gates a real migration.
	 * Short-lived: a scan's findings go stale as soon as anything else writes.
	 */
	const TOKEN_PREFIX = 'slash_image_migrate_token_';
	const TOKEN_TTL    = 1800;

	/** Attachments examined per AJAX batch. */
	const BATCH_SIZE = 200;

	public function __construct() {
		add_action( 'wp_ajax_slash_image_migrate_detect', array( $this, 'ajax_detect' ) );
		add_action( 'wp_ajax_slash_image_migrate_scan', array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_slash_image_migrate_run', array( $this, 'ajax_run' ) );
	}

	/**
	 * Nonce + capability gate for every Tools request. Mirrors
	 * Slash_Image_Bulk_Page::verify_request().
	 */
	private function verify_request() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
		}
	}

	/**
	 * Read the requested source and validate it against the adapter registry.
	 * An unknown slug never reaches the engine.
	 *
	 * @return string Validated slug.
	 */
	private function requested_source() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_request() ran first in every caller.
		$slug = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		if ( '' === Slash_Image_Migrate::adapter_for( $slug ) ) {
			wp_send_json_error( array( 'code' => 'unknown_source' ), 400 );
		}
		return $slug;
	}

	/**
	 * Per-source card payload: detection result, importable count, done-state.
	 */
	public function ajax_detect() {
		$this->verify_request();

		$migrated = Slash_Image_Migrate::migrated_summary();
		$cards    = array();

		foreach ( Slash_Image_Migrate::adapters() as $slug => $adapter ) {
			$detect = call_user_func( array( $adapter, 'detect' ) );
			$done   = isset( $migrated[ $slug ] ) ? $migrated[ $slug ] : null;

			$cards[] = array(
				'source'      => $slug,
				'label'       => (string) call_user_func( array( $adapter, 'label' ) ),
				'detected'    => ! empty( $detect['ok'] ),
				'code'        => (string) ( $detect['code'] ?? '' ),
				'message'     => (string) ( $detect['message'] ?? '' ),
				'total'       => ! empty( $detect['ok'] ) ? (int) call_user_func( array( $adapter, 'count' ) ) : 0,
				'migrated'    => $done ? (int) $done['count'] : 0,
				'migrated_at' => $done && $done['last_at'] > 0
					? date_i18n( get_option( 'date_format' ), (int) $done['last_at'] )
					: '',
				'plugin_file' => $this->active_plugin_file( $slug ),
			);
		}

		wp_send_json_success( array( 'cards' => $cards ) );
	}

	/**
	 * One dry-run batch. On the final batch, issues the scan token that
	 * ajax_run() requires — so a real migration cannot be reached without a
	 * completed scan, enforced server-side rather than only in the UI.
	 */
	public function ajax_scan() {
		$this->verify_request();
		$source = $this->requested_source();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_request() ran first.
		$cursor = isset( $_POST['cursor'] ) ? absint( wp_unslash( $_POST['cursor'] ) ) : 0;

		$result = Slash_Image_Migrate::run_batch( $source, $cursor, true, self::BATCH_SIZE );
		if ( empty( $result['ok'] ) ) {
			wp_send_json_error(
				array(
					'code'    => $result['code'],
					'message' => $result['message'],
				),
				400
			);
		}

		$token = '';
		if ( ! empty( $result['done'] ) ) {
			$token = wp_generate_password( 32, false );
			set_transient( self::TOKEN_PREFIX . $source, $token, self::TOKEN_TTL );
		}

		wp_send_json_success(
			array(
				'cursor' => (int) $result['cursor'],
				'done'   => (bool) $result['done'],
				'stats'  => $result['stats'],
				'token'  => $token,
			)
		);
	}

	/**
	 * One real batch, gated on the scan token.
	 */
	public function ajax_run() {
		$this->verify_request();
		$source = $this->requested_source();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_request() ran first.
		$cursor = isset( $_POST['cursor'] ) ? absint( wp_unslash( $_POST['cursor'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_request() ran first.
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

		$expected = (string) get_transient( self::TOKEN_PREFIX . $source );
		if ( '' === $expected || ! hash_equals( $expected, $token ) ) {
			wp_send_json_error( array( 'code' => 'scan_required' ), 409 );
		}

		$result = Slash_Image_Migrate::run_batch( $source, $cursor, false, self::BATCH_SIZE );
		if ( empty( $result['ok'] ) ) {
			wp_send_json_error(
				array(
					'code'    => $result['code'],
					'message' => $result['message'],
				),
				400
			);
		}

		$migrated = array();
		if ( ! empty( $result['done'] ) ) {
			// The run is finished: burn the token so a second migration needs a
			// fresh scan, and hand back the server-derived done-state.
			delete_transient( self::TOKEN_PREFIX . $source );
			$summary  = Slash_Image_Migrate::migrated_summary();
			$entry    = isset( $summary[ $source ] ) ? $summary[ $source ] : null;
			$migrated = array(
				'count' => $entry ? (int) $entry['count'] : 0,
				'date'  => $entry && $entry['last_at'] > 0
					? date_i18n( get_option( 'date_format' ), (int) $entry['last_at'] )
					: '',
			);
		}

		wp_send_json_success(
			array(
				'cursor'   => (int) $result['cursor'],
				'done'     => (bool) $result['done'],
				'stats'    => $result['stats'],
				'migrated' => $migrated,
			)
		);
	}

	/**
	 * The active plugin file for a migration source, or '' when that plugin is
	 * not active. Used to decide whether the done-state card may offer to
	 * deactivate it.
	 *
	 * Matched on the known main-file paths rather than a directory scan: this
	 * runs on the Tools screen only, and an exact match avoids deactivating
	 * something merely similarly named.
	 *
	 * @param string $slug Adapter slug.
	 * @return string Plugin file relative to the plugins dir, or ''.
	 */
	private function active_plugin_file( $slug ) {
		$known = array(
			'shortpixel' => 'shortpixel-image-optimiser/wp-shortpixel.php',
			'imagify'    => 'imagify/imagify.php',
		);

		if ( ! isset( $known[ $slug ] ) ) {
			return '';
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( $known[ $slug ] ) ? $known[ $slug ] : '';
	}
}
