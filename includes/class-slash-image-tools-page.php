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
		add_action( 'wp_ajax_slash_image_migrate_deactivate', array( $this, 'ajax_deactivate' ) );
	}

	/**
	 * Deactivate a migrated-from plugin, on explicit user action only.
	 *
	 * Offered from the done-state card because two active optimizers both
	 * rewrite front-end markup, which can double-wrap images. Never automatic
	 * and never offered before a migration completes — deactivating ShortPixel
	 * early would strip the source data mid-import.
	 *
	 * Only ever acts on a plugin file this class maps to a known migration
	 * source AND that is currently active, so an arbitrary plugin path from the
	 * request can never be deactivated.
	 */
	public function ajax_deactivate() {
		$this->verify_request();

		// Deactivation is plugin management, not settings management, so it
		// needs the capability WordPress itself requires for the act — NOT the
		// shared manage_options gate the other three Tools handlers use, which
		// only ever read migration data.
		//
		// This is the whole multisite exposure in one line: a subsite
		// administrator holds manage_options, but core's map_meta_cap adds
		// manage_network_plugins to activate_plugins on multisite unless the
		// network enables the Plugins menu — so without this check they could
		// turn off a plugin they are not permitted to manage.
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
		}

		$source = $this->requested_source();

		$plugin_file = $this->active_plugin_file( $source );
		if ( '' === $plugin_file ) {
			// Already inactive, or not a plugin we recognise for this source.
			wp_send_json_error( array( 'code' => 'not_active' ), 409 );
		}

		// A network activation belongs to the network admin, and undoing it
		// affects every site on the network — never something to do from one
		// subsite's Media screen. Reachable in practice: a plugin can be listed
		// in BOTH this site's active_plugins and the network's
		// active_sitewide_plugins, because network-activating does not remove a
		// pre-existing per-site entry.
		if ( is_multisite() && is_plugin_active_for_network( $plugin_file ) ) {
			wp_send_json_error( array( 'code' => 'network_active' ), 409 );
		}

		// Confine to THIS site explicitly. Core treats a null $network_wide as
		// "both", so the default would arm the network branch for exactly the
		// both-listed case guarded above.
		deactivate_plugins( $plugin_file, false, false );

		wp_send_json_success(
			array(
				'source'      => $source,
				'deactivated' => ! is_plugin_active( $plugin_file ),
			)
		);
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
			// Card visibility is decided by the plugin being ACTIVE, not by the
			// presence of its data: an inactive plugin's leftover records are
			// not something to offer a browser user, and a card for a plugin
			// they cannot see in their admin would be confusing. Orphaned data
			// stays importable through the CLI.
			$plugin_file = $this->active_plugin_file( $slug );
			if ( '' === $plugin_file ) {
				continue;
			}

			$detect = call_user_func( array( $adapter, 'detect' ) );
			$done   = isset( $migrated[ $slug ] ) ? $migrated[ $slug ] : null;
			$total  = ! empty( $detect['ok'] ) ? (int) call_user_func( array( $adapter, 'count' ) ) : 0;

			$cards[] = array(
				'source'      => $slug,
				'label'       => (string) call_user_func( array( $adapter, 'label' ) ),
				'detected'    => ! empty( $detect['ok'] ),
				'code'        => (string) ( $detect['code'] ?? '' ),
				'message'     => (string) ( $detect['message'] ?? '' ),
				'total'       => $total,
				// Whether a walk has reached the end of this source. The card
				// offers deactivation on this and nothing else: a migrated count
				// alone says work has happened, never that it finished.
				'complete'    => Slash_Image_Migrate::is_complete( $slug, $total ),
				'migrated'    => $done ? (int) $done['count'] : 0,
				'migrated_at' => $done && $done['last_at'] > 0
					? date_i18n( get_option( 'date_format' ), (int) $done['last_at'] )
					: '',
				// Drives the Deactivate button in tools.js, so it carries the
				// path only when this user may actually deactivate it. The card
				// itself still renders — visibility is active_plugin_file()'s
				// job, eligibility is this. Offering a button the handler will
				// refuse is worse than not offering it.
				'plugin_file' => $this->can_deactivate( $plugin_file ) ? $plugin_file : '',
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
			// Record the completed walk BEFORE deriving the summary, so the card
			// this response produces is already the done-state one.
			$adapter = Slash_Image_Migrate::adapter_for( $source );
			Slash_Image_Migrate::mark_complete( $source, (int) call_user_func( array( $adapter, 'count' ) ) );
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
	 * Whether the CURRENT USER may deactivate this plugin from THIS site.
	 *
	 * Card visibility and deactivation eligibility used to be the same
	 * predicate; on multisite they have to diverge, because a card can be worth
	 * showing to someone who may not turn the plugin off.
	 *
	 * ajax_deactivate() re-checks both of these conditions itself rather than
	 * calling this, so it can answer with a specific code ('forbidden' vs
	 * 'network_active') instead of one opaque refusal. Keep the two in step.
	 *
	 * @param string $plugin_file Plugin file relative to the plugins dir.
	 * @return bool
	 */
	private function can_deactivate( $plugin_file ) {
		if ( '' === $plugin_file || ! current_user_can( 'activate_plugins' ) ) {
			return false;
		}

		return ! ( is_multisite() && is_plugin_active_for_network( $plugin_file ) );
	}

	/**
	 * Identifying patterns for each migration source's plugin.
	 *
	 * A single hardcoded '{dir}/{file}.php' is too brittle: an install can be
	 * renamed, and the folder differs between wordpress.org and a manual or
	 * bundled copy. Matching on EITHER the directory or the main-file name
	 * survives one of the two being renamed. Both renamed is not detectable
	 * without loading the plugin's own code, which we will not do — the CLI
	 * remains the escape hatch for such an install.
	 *
	 * @return array slug => [ 'dirs' => string[], 'files' => string[] ]
	 */
	private static function plugin_patterns() {
		return array(
			'shortpixel' => array(
				'dirs'  => array( 'shortpixel-image-optimiser' ),
				'files' => array( 'wp-shortpixel.php' ),
			),
			'imagify'    => array(
				'dirs'  => array( 'imagify' ),
				'files' => array( 'imagify.php' ),
			),
		);
	}

	/**
	 * The active plugin file for a migration source, or '' when that plugin is
	 * not currently active.
	 *
	 * Drives BOTH card visibility (a source with no active plugin gets no card,
	 * however much leftover data it has) and whether the done-state card may
	 * offer to deactivate it.
	 *
	 * Note this deliberately does NOT gate the CLI, which works against the
	 * database regardless of active state — the documented way to import
	 * orphaned data after its plugin is gone.
	 *
	 * @param string $slug Adapter slug.
	 * @return string Plugin file relative to the plugins dir, or ''.
	 */
	private function active_plugin_file( $slug ) {
		$patterns = self::plugin_patterns();
		if ( ! isset( $patterns[ $slug ] ) ) {
			return '';
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$dirs  = $patterns[ $slug ]['dirs'];
		$files = $patterns[ $slug ]['files'];

		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin_file ) {
			$plugin_file = (string) $plugin_file;
			$dir         = dirname( $plugin_file );
			$file        = basename( $plugin_file );

			if ( in_array( $dir, $dirs, true ) || in_array( $file, $files, true ) ) {
				// Re-check through the API so a plugin listed in the option but
				// since disabled (or filtered out) is never treated as active.
				return is_plugin_active( $plugin_file ) ? $plugin_file : '';
			}
		}

		return '';
	}
}
