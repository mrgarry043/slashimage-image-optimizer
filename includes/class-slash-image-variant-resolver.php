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

	/**
	 * Naming conventions a variant file can be found under.
	 *
	 * DOUBLE is ours: the whole source filename plus the format
	 * (photo.png -> photo.png.avif). SINGLE is what ShortPixel writes by
	 * default: the source extension replaced (photo.png -> photo.avif).
	 * Imagify already uses the double form, so it needs no fallback.
	 */
	const NAMING_DOUBLE = 'double';
	const NAMING_SINGLE = 'single';

	/**
	 * Autoloaded site-level flag: this site holds next-gen files under another
	 * optimizer's naming convention, so the SINGLE fallback is worth paying for.
	 *
	 * Set by Slash_Image_Migrate the first time a real migration run records a
	 * competitor-named variant. It describes THIS SITE'S FILE LAYOUT, not the
	 * other plugin's presence, so it deliberately survives that plugin being
	 * deactivated or deleted — the files it wrote are still on disk and still
	 * being served. It is never derived from is_plugin_active().
	 *
	 * The constant lives here rather than on the migration class because the
	 * resolver is loaded on every front-end request while the migration classes
	 * are not; the writer depends on the reader, never the other way round.
	 */
	const FOREIGN_VARIANTS_OPTION = 'slash_image_foreign_variants';

	/** @var array<string, array{webp:string|false,avif:string|false}> */
	private $cache = array();

	/** @var bool Whether the single-extension fallback is enabled for this site. */
	private $foreign_variants = false;

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

		// Read ONCE per request, not per image: the rewriter builds one resolver
		// per request and this cannot change mid-request. The option is
		// autoloaded, so it costs no query of its own.
		$this->foreign_variants = function_exists( 'get_option' )
			&& '1' === (string) get_option( self::FOREIGN_VARIANTS_OPTION, '' );

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
	 * Returns ['webp' => false|NAMING_*, 'avif' => false|NAMING_*] for an
	 * absolute disk path: false when no variant exists, otherwise WHICH naming
	 * convention it was found under, so the caller can build the matching URL.
	 *
	 * Both values stay falsy/truthy-compatible with the previous boolean
	 * contract, so an existing `! empty( $variants['avif'] )` test is unchanged.
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
			'webp' => $this->locate( $path, 'webp' ),
			'avif' => $this->locate( $path, 'avif' ),
		);
		$this->cache[ $path ] = $result;
		return $result;
	}

	/**
	 * Find which name a variant actually sits at — ours first, then the other
	 * optimizer's.
	 *
	 * The fallback is what lets a library migrated from another optimizer serve
	 * its existing next-gen files in place: no hardlink, no copy, and nothing
	 * written into the other plugin's tree. It is derived by pure string work,
	 * so it needs no attachment ID and no database read.
	 *
	 * It runs ONLY on a site whose FOREIGN_VARIANTS_OPTION flag is set by a real
	 * migration. A site that has never migrated therefore behaves exactly as it
	 * did before the fallback existed — same two stat calls, and no chance of
	 * picking up another optimizer's file just because both plugins are active.
	 *
	 * Ours is checked first and short-circuits, so a natively optimized image
	 * still costs exactly one stat call per format either way.
	 *
	 * @param string $path   Absolute path of the source image.
	 * @param string $format 'webp' or 'avif'.
	 * @return string|false NAMING_* constant, or false when neither name exists.
	 */
	private function locate( $path, $format ) {
		$ours = $path . '.' . $format;
		if ( is_readable( $ours ) ) {
			return self::NAMING_DOUBLE;
		}

		if ( ! $this->foreign_variants ) {
			return false;
		}

		$single = self::single_extension_name( $path, $format );
		// Identical when the source carries no extension — already stat'ed above.
		if ( $single !== $ours && is_readable( $single ) ) {
			return self::NAMING_SINGLE;
		}

		return false;
	}

	/**
	 * Replace a path or URL's final extension with $format
	 * (photo.png -> photo.avif), preserving any query string or fragment.
	 *
	 * GUARDRAIL: a file sitting at this name belongs to ANOTHER plugin. We only
	 * ever read it, and only in order to serve it — the plugin must never
	 * delete, move, rename, or overwrite it. Every delete path in
	 * Slash_Image_Restore builds the DOUBLE-extension name instead
	 * ( $live_file . '.webp' / '.avif' ), which can only ever match a file we
	 * wrote ourselves, and must keep doing so.
	 *
	 * Two limits keep that guarantee narrow. This name is only ever PROBED, and
	 * only when FOREIGN_VARIANTS_OPTION marks the site as holding foreign-named
	 * variants — so a site that never migrated never even looks for one. If a
	 * future caller needs this name for anything other than a read, that is the
	 * point at which the guarantee has to be re-established, not assumed.
	 *
	 * @param string $target Absolute path, or a URL.
	 * @param string $format 'webp' or 'avif'.
	 * @return string
	 */
	public static function single_extension_name( $target, $format ) {
		$target = (string) $target;
		$head   = $target;
		$tail   = '';

		$cut = strcspn( $target, '?#' );
		if ( $cut < strlen( $target ) ) {
			$head = substr( $target, 0, $cut );
			$tail = substr( $target, $cut );
		}

		$dot   = strrpos( $head, '.' );
		$slash = strrpos( $head, '/' );
		// No extension on the filename itself — a dot earlier in the path (a
		// directory name) must never be treated as the extension separator.
		if ( false === $dot || ( false !== $slash && $dot < $slash ) ) {
			return $head . '.' . $format . $tail;
		}

		return substr( $head, 0, $dot ) . '.' . $format . $tail;
	}
}
