<?php
/**
 * Claims another optimizer's next-gen sibling files for our <picture> rewriter.
 *
 * Our Slash_Image_Variant_Resolver probes DOUBLE-extension siblings — it takes
 * the live file's full path including extension and appends '.webp' / '.avif'
 * (photo.jpg -> photo.jpg.webp). ShortPixel's default is SINGLE extension
 * (photo.jpg -> photo.webp), so its files exist but our rewriter never finds
 * them. Rather than re-encoding, we place a HARDLINK at the name we probe,
 * pointing at their file: one inode, no extra disk, no second copy to keep in
 * sync. Where hardlinks are unavailable (cross-device, restrictive host) we fall
 * back to copy().
 *
 * Deliberately serving-only. This writes nothing restore-adjacent: no
 * _slash_image_backup, no metadata about originals. A hardlinked variant is a
 * delivery optimization, never a recovery point — and the source file remains
 * entirely ShortPixel's to manage. We never rename, move, modify, or delete it.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Migrate_Claim {

	/**
	 * Sentinel meaning "this format was generated but came out larger, so it was
	 * discarded" — an integer where a filename would otherwise be. No file
	 * exists, so there is nothing to claim.
	 */
	const FILETYPE_BIGGER = -10;

	/**
	 * Evaluate and (unless scanning) claim one format for one size.
	 *
	 * The source records the sibling's BARE BASENAME, never a path — the
	 * directory comes from the attachment's own file. It also cannot be
	 * reconstructed from the size name: a portrait 768x1152 thumbnail is
	 * "name-768x1152.webp", so only the recorded value is reliable.
	 *
	 * Absence of the key means the format was never generated — checked with
	 * isset(), never a null comparison, because the source strips null-valued
	 * properties before encoding rather than storing nulls.
	 *
	 * @param array  $extra       Decoded extra_info for this size's row.
	 * @param string $format      'webp' or 'avif'.
	 * @param string $dir         Trailing-slashed directory of the attachment's files.
	 * @param string $source_file Absolute path of the live file for this size.
	 * @return array {
	 *     @type int    $bytes       Sibling size in bytes, 0 when nothing was claimed.
	 *     @type string $link_target The double-extension path, '' when not applicable.
	 *     @type array  $stats       Stat deltas for Slash_Image_Migrate.
	 * }
	 */
	public static function evaluate( array $extra, $format, $dir, $source_file, $dry_run = false ) {
		$none = array(
			'bytes'       => 0,
			'link_target' => '',
			'stats'       => array(),
		);

		if ( ! isset( $extra[ $format ] ) ) {
			// Format never generated for this size. Not a problem, not counted.
			return $none;
		}

		$value = $extra[ $format ];

		// Sentinel: generated but discarded for being larger. No file exists.
		if ( is_int( $value ) || is_numeric( $value ) ) {
			if ( self::FILETYPE_BIGGER === (int) $value ) {
				$none['stats'] = array( 'sentinel_skipped' => 1 );
			}
			return $none;
		}

		$basename = wp_basename( (string) $value );
		if ( '' === $basename ) {
			return $none;
		}

		$their_file = $dir . $basename;

		// The database records what was written, not what survived. Verify.
		if ( ! is_readable( $their_file ) ) {
			$none['stats'] = array( $format . '_missing' => 1 );
			return $none;
		}

		$bytes  = (int) filesize( $their_file );
		$target = $source_file . '.' . $format;

		// Already probeable — either we linked it on an earlier run, or the site
		// runs ShortPixel's double-extension mode and their own file is already
		// at the name we probe. Either way, nothing to do; never overwrite.
		if ( file_exists( $target ) ) {
			return array(
				'bytes'       => (int) filesize( $target ),
				'link_target' => '',
				'stats'       => array( $format . '_already_present' => 1 ),
			);
		}

		if ( $dry_run ) {
			// Report what a real run would link, without touching the filesystem.
			return array(
				'bytes'       => $bytes,
				'link_target' => $target,
				'stats'       => array( $format . '_linked' => 1 ),
			);
		}

		if ( self::place( $their_file, $target ) ) {
			return array(
				'bytes'       => $bytes,
				'link_target' => $target,
				'stats'       => array( $format . '_linked' => 1 ),
			);
		}

		return array(
			'bytes'       => 0,
			'link_target' => '',
			'stats'       => array( 'link_failed' => 1 ),
		);
	}

	/**
	 * Place $target as a hardlink to $source, falling back to a copy.
	 *
	 * link() fails across filesystems and on hosts that disable it; a copy is
	 * correct in both cases, just uses the disk. Both are additive — the source
	 * is never modified.
	 *
	 * @param string $source Existing file (belongs to the other plugin).
	 * @param string $target Path to create.
	 * @return bool
	 */
	private static function place( $source, $target ) {
		$dir = dirname( $target );
		if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- link() warns on cross-device / disabled-function hosts; the copy() fallback is the handler.
		if ( @link( $source, $target ) ) {
			return true;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed copy is reported through the return value.
		return (bool) @copy( $source, $target );
	}
}
