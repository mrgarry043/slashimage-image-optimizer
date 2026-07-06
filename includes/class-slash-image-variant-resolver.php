<?php
/**
 * Resolves an image URL to a local disk path and reports which sibling
 * variants exist (.webp / .avif). Wrapped in a class so the rewriter can
 * be unit-tested with a stub resolver.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Variant_Resolver {

	/** @var array<string, array{webp:bool,avif:bool}> */
	private $cache = array();

	/** @var string */
	private $uploads_basedir;

	/** @var string */
	private $uploads_baseurl;

	/** @var array<string> */
	private $home_hosts;

	public function __construct() {
		$uploads               = function_exists( 'wp_get_upload_dir' ) ? wp_get_upload_dir() : array();
		$this->uploads_basedir = isset( $uploads['basedir'] ) ? rtrim( (string) $uploads['basedir'], '/' ) : '';
		$this->uploads_baseurl = isset( $uploads['baseurl'] ) ? rtrim( (string) $uploads['baseurl'], '/' ) : '';

		$this->home_hosts = array();
		if ( function_exists( 'home_url' ) ) {
			$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
			$site_host = wp_parse_url( site_url(), PHP_URL_HOST );
			$base_host = $this->uploads_baseurl ? wp_parse_url( $this->uploads_baseurl, PHP_URL_HOST ) : null;
			foreach ( array( $home_host, $site_host, $base_host ) as $h ) {
				if ( $h ) {
					$this->home_hosts[] = strtolower( (string) $h );
				}
			}
			$this->home_hosts = array_values( array_unique( $this->home_hosts ) );
		}
	}

	/**
	 * Convert a URL (absolute or root-relative) to an absolute disk path
	 * inside the uploads directory. Returns null if the URL is off-site,
	 * outside uploads, or otherwise unresolvable.
	 */
	public function url_to_path( $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return null;
		}

		// Strip query / fragment.
		$qpos = strpos( $url, '?' );
		if ( false !== $qpos ) {
			$url = substr( $url, 0, $qpos );
		}
		$hpos = strpos( $url, '#' );
		if ( false !== $hpos ) {
			$url = substr( $url, 0, $hpos );
		}

		if ( '' === $url ) {
			return null;
		}

		// Reject unsupported source extensions early.
		$ext = strtolower( pathinfo( $url, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif' ), true ) ) {
			return null;
		}

		// Normalize to a host-anchored URL when possible.
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		} elseif ( 0 === strpos( $url, '/' ) ) {
			// Root-relative — assume same host as uploads baseurl.
			if ( '' === $this->uploads_baseurl ) {
				return null;
			}
			$base_path = (string) wp_parse_url( $this->uploads_baseurl, PHP_URL_PATH );
			$base_root = preg_replace( '#' . preg_quote( $base_path, '#' ) . '$#', '', $this->uploads_baseurl );
			$url       = $base_root . $url;
		}

		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['path'] ) ) {
			return null;
		}

		// Off-site → bail.
		if ( ! empty( $parsed['host'] ) && ! empty( $this->home_hosts ) ) {
			$host = strtolower( $parsed['host'] );
			if ( ! in_array( $host, $this->home_hosts, true ) ) {
				return null;
			}
		}

		// Uploads URL prefix → uploads disk prefix.
		$base_url_path = wp_parse_url( $this->uploads_baseurl, PHP_URL_PATH );
		if ( ! $base_url_path ) {
			return null;
		}
		$path_in_uploads = ( 0 === strpos( $parsed['path'], $base_url_path . '/' ) ) || ( $parsed['path'] === $base_url_path );
		if ( ! $path_in_uploads ) {
			return null;
		}

		$rel = ltrim( substr( $parsed['path'], strlen( $base_url_path ) ), '/' );
		if ( '' === $rel ) {
			return null;
		}

		$rel = rawurldecode( $rel );

		// Path traversal guard.
		if ( false !== strpos( $rel, '..' ) ) {
			return null;
		}

		$path = $this->uploads_basedir . '/' . $rel;
		return $path;
	}

	/**
	 * Returns ['webp' => bool, 'avif' => bool] for an absolute disk path.
	 */
	public function variants_for( $path ) {
		if ( ! is_string( $path ) || '' === $path ) {
			return array(
				'webp' => false,
				'avif' => false,
			);
		}
		if ( isset( $this->cache[ $path ] ) ) {
			return $this->cache[ $path ];
		}
		$result               = array(
			'webp' => is_readable( $path . '.webp' ),
			'avif' => is_readable( $path . '.avif' ),
		);
		$this->cache[ $path ] = $result;
		return $result;
	}
}
