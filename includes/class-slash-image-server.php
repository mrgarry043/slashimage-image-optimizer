<?php
/**
 * Server-environment detection. Pure parsing of $_SERVER, no shelling out.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Server {

	public static function detect() {
		$sig = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		$sig = strtolower( $sig );

		if ( '' === $sig ) {
			return 'unknown';
		}
		if ( false !== strpos( $sig, 'litespeed' ) ) {
			return 'litespeed';
		}
		if ( false !== strpos( $sig, 'apache' ) ) {
			return 'apache';
		}
		if ( false !== strpos( $sig, 'nginx' ) ) {
			return 'nginx';
		}
		return 'unknown';
	}

	public static function supports_htaccess() {
		$server = self::detect();
		return in_array( $server, array( 'apache', 'litespeed' ), true );
	}

	public static function htaccess_path() {
		return trailingslashit( ABSPATH ) . '.htaccess';
	}

	public static function htaccess_writable() {
		$path = self::htaccess_path();
		if ( file_exists( $path ) ) {
			return is_writable( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Read-only writability probe on the site-root .htaccess before an opt-in server-rewrite edit; native check avoids a WP_Filesystem credential prompt.
		}
		return is_writable( dirname( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Read-only writability probe on the site-root .htaccess dir before an opt-in server-rewrite edit; native check avoids a WP_Filesystem credential prompt.
	}
}
