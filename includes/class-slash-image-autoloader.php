<?php
/**
 * PSR-style autoloader mapping Slash_Image_* classes to includes/class-slash-image-*.php.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Autoloader {

	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	public static function load( $class_name ) {
		if ( 'Slash_Image' !== $class_name && strpos( $class_name, 'Slash_Image_' ) !== 0 ) {
			return;
		}

		$file_slug = strtolower( str_replace( '_', '-', $class_name ) );
		$file_path = SLASH_IMAGE_PATH . 'includes/class-' . $file_slug . '.php';

		if ( is_readable( $file_path ) ) {
			require_once $file_path;
		}
	}
}
