<?php
/**
 * Manages the # BEGIN Slash Image / # END Slash Image block in the site's
 * root .htaccess. Apply / remove are explicit (button-driven), never automatic
 * on settings save.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Htaccess {

	const MARKER = 'Slash Image';

	public static function rules() {
		return array(
			'<IfModule mod_rewrite.c>',
			'RewriteEngine On',
			'RewriteCond %{HTTP_ACCEPT} image/avif',
			'RewriteCond %{REQUEST_FILENAME}.avif -f',
			'RewriteRule ^(.+)\.(jpe?g|png|gif)$ $1.$2.avif [T=image/avif,E=accept:1,L]',
			'RewriteCond %{HTTP_ACCEPT} image/webp',
			'RewriteCond %{REQUEST_FILENAME}.webp -f',
			'RewriteRule ^(.+)\.(jpe?g|png|gif)$ $1.$2.webp [T=image/webp,E=accept:1,L]',
			'</IfModule>',
			'<IfModule mod_headers.c>',
			'<FilesMatch "\.(jpe?g|png|gif)$">',
			'Header append Vary Accept env=accept',
			'</FilesMatch>',
			'</IfModule>',
			'<IfModule mod_mime.c>',
			'AddType image/avif .avif',
			'AddType image/webp .webp',
			'</IfModule>',
		);
	}

	public static function is_active() {
		$path = Slash_Image_Server::htaccess_path();
		if ( ! file_exists( $path ) ) {
			return false;
		}
		$contents = @file_get_contents( $path );
		if ( false === $contents ) {
			return false;
		}
		return false !== strpos( $contents, '# BEGIN ' . self::MARKER );
	}

	public static function apply() {
		if ( ! Slash_Image_Server::supports_htaccess() ) {
			return array(
				'ok'   => false,
				'code' => 'unsupported_server',
			);
		}
		if ( ! Slash_Image_Server::htaccess_writable() ) {
			return array(
				'ok'   => false,
				'code' => 'not_writable',
			);
		}

		$path   = Slash_Image_Server::htaccess_path();
		$backup = self::write_backup( $path );

		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		$ok = insert_with_markers( $path, self::MARKER, self::rules() );
		if ( ! $ok ) {
			self::restore_from_backup( $path, $backup );
			return array(
				'ok'   => false,
				'code' => 'write_failed',
			);
		}

		$probe = self::probe_site();
		if ( ! $probe['ok'] ) {
			self::restore_from_backup( $path, $backup );
			return array(
				'ok'      => false,
				'code'    => 'probe_failed',
				'message' => $probe['message'],
			);
		}

		return array(
			'ok'     => true,
			'backup' => $backup,
		);
	}

	public static function remove() {
		$path = Slash_Image_Server::htaccess_path();
		if ( ! file_exists( $path ) ) {
			return array( 'ok' => true );
		}
		if ( ! is_writable( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Read-only writability probe on the site-root .htaccess before an opt-in server-rewrite edit; native check avoids a WP_Filesystem credential prompt.
			return array(
				'ok'   => false,
				'code' => 'not_writable',
			);
		}

		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		$ok = insert_with_markers( $path, self::MARKER, array() );
		if ( ! $ok ) {
			return array(
				'ok'   => false,
				'code' => 'write_failed',
			);
		}
		return array( 'ok' => true );
	}

	private static function write_backup( $path ) {
		if ( ! file_exists( $path ) ) {
			return '';
		}
		$backup = $path . '.slash-image-backup-' . time();
		if ( @copy( $path, $backup ) ) {
			return $backup;
		}
		return '';
	}

	private static function restore_from_backup( $path, $backup ) {
		if ( '' === $backup || ! file_exists( $backup ) ) {
			return;
		}
		@copy( $backup, $path );
	}

	private static function probe_site() {
		$url      = home_url( '/?slash_image_htaccess_probe=1' );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'sslverify'   => false,
				'redirection' => 2,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'message' => $response->get_error_message(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 200 && $status < 400 ) {
			return array( 'ok' => true );
		}

		return array(
			'ok'      => false,
			'message' => sprintf(
				/* translators: %d: HTTP status code */
				__( 'Site returned HTTP %d after applying rules.', 'slashimage-image-optimizer' ),
				$status
			),
		);
	}
}
