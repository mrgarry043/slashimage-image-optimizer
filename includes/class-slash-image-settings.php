<?php
/**
 * Settings registration, sanitization, and accessors.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Settings {

	const OPTION_GROUP = 'slash_image_settings_group';
	const OPTION_NAME  = 'slash_image_settings';

	const API_KEY_REGEX = '/^sk_(live|sub)_[a-f0-9]{48}$/';

	const COMPRESSION_MODES = array( 'lossy', 'glossy', 'lossless' );

	const FRONTEND_MODES = array( 'picture', 'htaccess', 'disabled' );

	const DIMENSION_MIN = 16;
	const DIMENSION_MAX = 10000;

	const RETENTION_DAYS_MAX = 3650;

	const CUSTOM_EXCLUSIONS_MAX_PATTERNS    = 100;
	const CUSTOM_EXCLUSIONS_MAX_PATTERN_LEN = 200;

	public static function register() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => Slash_Image_Activator::default_settings(),
			)
		);
	}

	public static function all() {
		$stored = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( Slash_Image_Activator::default_settings(), $stored );
	}

	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	public static function sanitize( $input ) {
		$current = self::all();
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$output = $current;

		if ( array_key_exists( 'api_key', $input ) ) {
			$candidate = sanitize_text_field( (string) $input['api_key'] );
			$candidate = trim( $candidate );

			if ( '' === $candidate ) {
				$output['api_key'] = '';
				delete_option( 'slash_image_key_verified_at' );
				// Mirror ajax_disconnect / ajax_save_key: emptying the key clears any
				// stored invalid marker + the reconnect notice so internal state
				// matches reality (no key can't be "invalid").
				Slash_Image_Connection::clear_invalid();
			} elseif ( preg_match( self::API_KEY_REGEX, $candidate ) ) {
				if ( ( $current['api_key'] ?? '' ) !== $candidate ) {
					delete_option( 'slash_image_key_verified_at' );
					// A changed key must re-verify; drop any stale invalid marker so
					// the prior key's dead state can't shadow the new one.
					Slash_Image_Connection::clear_invalid();
				}
				$output['api_key'] = $candidate;
			} else {
				add_settings_error(
					self::OPTION_NAME,
					'invalid_api_key',
					esc_html__( 'API key format is invalid. Keys look like sk_live_… or sk_sub_… and are 48 hex characters long.', 'slashimage-image-optimizer' )
				);
			}
		}

		if ( array_key_exists( 'compression_mode', $input ) ) {
			$mode = is_string( $input['compression_mode'] ) ? strtolower( trim( $input['compression_mode'] ) ) : '';
			if ( in_array( $mode, self::COMPRESSION_MODES, true ) ) {
				$output['compression_mode'] = $mode;
			}
		}

		if ( array_key_exists( 'frontend_serving_mode', $input ) ) {
			$fmode = is_string( $input['frontend_serving_mode'] ) ? strtolower( trim( $input['frontend_serving_mode'] ) ) : '';
			if ( in_array( $fmode, self::FRONTEND_MODES, true ) ) {
				$output['frontend_serving_mode'] = $fmode;
			}
		}

		foreach ( array( 'auto_optimize_uploads', 'generate_webp', 'generate_avif', 'convert_png_to_jpeg', 'preserve_metadata', 'resize_on_upload', 'keep_backups', 'smart_backups', 'auto_delete_backups', 'optimize_all_sizes', 'uninstall_remove_all' ) as $flag ) {
			if ( array_key_exists( $flag, $input ) ) {
				$output[ $flag ] = (bool) $input[ $flag ];
			}
		}

		foreach ( array( 'max_width', 'max_height' ) as $dim ) {
			if ( array_key_exists( $dim, $input ) ) {
				$value = absint( $input[ $dim ] );
				// 0 = "no limit on this side" (WordPress Media-settings convention);
				// any other value must fall inside [DIMENSION_MIN, DIMENSION_MAX].
				if ( 0 === $value || ( $value >= self::DIMENSION_MIN && $value <= self::DIMENSION_MAX ) ) {
					$output[ $dim ] = $value;
				}
			}
		}

		if ( array_key_exists( 'backup_retention_days', $input ) ) {
			$days = absint( $input['backup_retention_days'] );
			if ( $days <= self::RETENTION_DAYS_MAX ) {
				$output['backup_retention_days'] = $days;
			}
		}

		if ( array_key_exists( 'excluded_image_sizes', $input ) ) {
			$output['excluded_image_sizes'] = self::sanitize_excluded_sizes( $input['excluded_image_sizes'] );
		}

		if ( array_key_exists( 'custom_exclusions', $input ) ) {
			$output['custom_exclusions'] = self::sanitize_custom_exclusions( (string) $input['custom_exclusions'] );
		}

		update_option( 'slash_image_settings_saved_at', time(), false );

		if ( class_exists( 'Slash_Image_Connection' ) ) {
			Slash_Image_Connection::invalidate();
		}

		return $output;
	}

	/**
	 * Returns the list of image sizes the optimizer can target on this site.
	 * One entry per registered subsize plus a synthetic 'full' entry for the
	 * original. Filters out 0×0 placeholder sizes that some plugins register
	 * but that aren't physically generated.
	 *
	 * Re-queried on every render — themes/plugins activating can add or
	 * remove sizes between page loads.
	 *
	 * Returned shape:
	 *   [
	 *     'full' => [ 'label' => 'Original (full size)', 'width' => 0, 'height' => 0 ],
	 *     'thumbnail' => [ 'label' => 'thumbnail', 'width' => 150, 'height' => 150 ],
	 *     ...
	 *   ]
	 */
	public static function available_image_sizes() {
		$sizes = function_exists( 'wp_get_registered_image_subsizes' )
			? wp_get_registered_image_subsizes()
			: array();

		$out = array(
			'full' => array(
				'label'  => __( 'Original (full size)', 'slashimage-image-optimizer' ),
				'width'  => 0,
				'height' => 0,
			),
		);

		if ( is_array( $sizes ) ) {
			foreach ( $sizes as $key => $data ) {
				$w = isset( $data['width'] ) ? (int) $data['width'] : 0;
				$h = isset( $data['height'] ) ? (int) $data['height'] : 0;
				if ( 0 === $w && 0 === $h ) {
					continue; // Placeholder — not physically generated.
				}
				$out[ (string) $key ] = array(
					'label'  => (string) $key,
					'width'  => $w,
					'height' => $h,
				);
			}
		}

		return $out;
	}

	/**
	 * Returns the list of size keys the user has excluded. Validated against
	 * the currently-available sizes — a previously-saved exclusion for a
	 * size that no longer exists is dropped silently.
	 */
	public static function excluded_image_sizes() {
		$saved = self::get( 'excluded_image_sizes', array() );
		if ( ! is_array( $saved ) ) {
			return array();
		}
		$available = array_keys( self::available_image_sizes() );
		return array_values( array_intersect( array_map( 'strval', $saved ), $available ) );
	}

	/**
	 * Returns the list of custom-exclusion patterns (one per line, trimmed,
	 * non-empty) from the saved string setting.
	 */
	public static function custom_exclusion_patterns() {
		$raw = (string) self::get( 'custom_exclusions', '' );
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$out   = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			$out[] = $line;
		}
		return $out;
	}

	private static function sanitize_excluded_sizes( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}
		$available = array_keys( self::available_image_sizes() );
		$out       = array();
		foreach ( $input as $key ) {
			$key = sanitize_text_field( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			if ( in_array( $key, $available, true ) && ! in_array( $key, $out, true ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	private static function sanitize_custom_exclusions( $input ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $input );
		$out   = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			$line = sanitize_text_field( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( strlen( $line ) > self::CUSTOM_EXCLUSIONS_MAX_PATTERN_LEN ) {
				$line = substr( $line, 0, self::CUSTOM_EXCLUSIONS_MAX_PATTERN_LEN );
			}
			$out[] = $line;
			if ( count( $out ) >= self::CUSTOM_EXCLUSIONS_MAX_PATTERNS ) {
				break;
			}
		}
		return implode( "\n", $out );
	}
}
