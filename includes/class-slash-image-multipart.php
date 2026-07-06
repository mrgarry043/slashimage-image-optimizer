<?php
/**
 * multipart/form-data body builder.
 *
 * wp_remote_post()'s built-in `body => array` mode encodes everything as
 * application/x-www-form-urlencoded, which corrupts binary image bytes.
 * We construct the multipart envelope manually and pass it as a raw string.
 *
 * Boolean serialization: the slashimage.com API expects the literal strings
 * "true" / "false" for boolean form fields — never 1/0 or yes/no. The
 * to_field_value() helper enforces this.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Multipart {

	const CRLF = "\r\n";

	public static function build( array $fields, array $files ) {
		$boundary = '----SlashImage-' . wp_generate_uuid4();
		$body     = '';

		foreach ( $fields as $name => $value ) {
			if ( null === $value ) {
				continue;
			}
			$body .= '--' . $boundary . self::CRLF;
			$body .= 'Content-Disposition: form-data; name="' . self::escape_quoted( (string) $name ) . '"' . self::CRLF . self::CRLF;
			$body .= self::field_value( $value ) . self::CRLF;
		}

		foreach ( $files as $name => $file ) {
			$filename = isset( $file['filename'] ) ? (string) $file['filename'] : 'upload.bin';
			$mime     = isset( $file['mime'] ) ? (string) $file['mime'] : 'application/octet-stream';
			$contents = isset( $file['contents'] ) ? (string) $file['contents'] : '';

			$body .= '--' . $boundary . self::CRLF;
			$body .= 'Content-Disposition: form-data; name="' . self::escape_quoted( (string) $name ) . '"; filename="' . self::escape_quoted( $filename ) . '"' . self::CRLF;
			$body .= 'Content-Type: ' . $mime . self::CRLF . self::CRLF;
			$body .= $contents . self::CRLF;
		}

		$body .= '--' . $boundary . '--' . self::CRLF;

		return array(
			'boundary' => $boundary,
			'body'     => $body,
		);
	}

	/**
	 * Serialize a single form-field value to its wire string. Booleans become
	 * the literal "true" / "false" the slashimage.com API expects (never 1/0).
	 *
	 * Public so the streaming-upload path (Slash_Image_Api_Client's
	 * http_api_curl hook, which hands cURL an array of POSTFIELDS) serializes
	 * field values through the exact same rule as the in-memory body builder —
	 * single source of truth for the boolean contract.
	 *
	 * @param mixed $value
	 * @return string
	 */
	public static function field_value( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		return (string) $value;
	}

	private static function escape_quoted( $value ) {
		return str_replace( array( "\r", "\n", '"' ), array( '', '', '\\"' ), $value );
	}
}
