<?php
/**
 * Records which of another optimizer's next-gen sibling files are servable.
 *
 * READ-ONLY, and deliberately so. Nothing here creates, moves, or deletes a
 * file — it only reports what exists and how big it is, so the migration can
 * write honest byte figures into our own postmeta.
 *
 * Serving those files is the resolver's job, not ours.
 * Slash_Image_Variant_Resolver probes our DOUBLE-extension name first
 * (photo.jpg -> photo.jpg.avif) and falls back to the SINGLE-extension name
 * ShortPixel writes by default (photo.jpg -> photo.avif), so a migrated file is
 * found where it already sits.
 *
 * This replaced a hardlink pass that materialized our name pointing at their
 * inode. It was removed for two reasons: `link` and `symlink` are in
 * disable_functions on many hosts (a PHP 8 disabled function throws Error,
 * which @ does NOT suppress, so @link() was an uncaught fatal that killed the
 * request before the copy() fallback could run) — and, more fundamentally, we
 * should not be writing into another plugin's file tree at all.
 *
 * The source files remain entirely the other plugin's to manage. We never
 * rename, move, modify, or delete them.
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
	 *     @type int   $bytes Sibling size in bytes, 0 when nothing is servable.
	 *     @type array $stats Stat deltas for Slash_Image_Migrate.
	 * }
	 */
	public static function evaluate( array $extra, $format, $dir, $source_file ) {
		$none = array(
			'bytes' => 0,
			'stats' => array(),
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

		return self::claim_at( $dir . $basename, $source_file . '.' . $format, $format );
	}

	/**
	 * Evaluate one format for a source whose sibling name is DERIVED by the same
	 * rule we probe, rather than recorded.
	 *
	 * Imagify's imagify_path_to_nextgen() appends to the full path including
	 * extension (photo.jpg -> photo.jpg.webp) — byte-identical to the name
	 * Slash_Image_Variant_Resolver probes first. So the file either already sits
	 * at our name or was never generated. The shared claim_at() handles both,
	 * which is why this reports through the same counters instead of a parallel
	 * vocabulary.
	 *
	 * Deliberately NOT expressed by faking a recorded-basename array for
	 * evaluate(): that would report a foreign-named file where none exists and
	 * put a fiction in the stats.
	 *
	 * @param string $format      'webp' or 'avif'.
	 * @param string $source_file Absolute path of the live file for this size.
	 * @return array Same shape as evaluate().
	 */
	public static function evaluate_derived( $format, $source_file ) {
		$target = $source_file . '.' . $format;
		return self::claim_at( $target, $target, $format );
	}

	/**
	 * Shared resolution for both entry points. Reports only; writes nothing.
	 *
	 * Order matters: our own name is checked BEFORE the other plugin's, matching
	 * the order Slash_Image_Variant_Resolver::locate() probes at serve time, so
	 * the bytes recorded here are the bytes that will actually be delivered.
	 * The PREDICATE matches too - both probes are is_readable(), because that is
	 * what locate() gates on for both names. Anything looser claims files the
	 * resolver will refuse.
	 *
	 * For a derived-name source $their_file and $target are the same path, so
	 * this collapses to "present, or never generated".
	 *
	 * The '_linked' stat key is historical: it now counts siblings servable at
	 * the OTHER plugin's name, with nothing created. It is kept because the key
	 * is shared with the CLI report and the Tools tab, and both already describe
	 * it to users as files to serve.
	 *
	 * @param string $their_file Existing sibling belonging to the other plugin.
	 * @param string $target     Double-extension path we would write ourselves.
	 * @param string $format     'webp' or 'avif'.
	 * @return array
	 */
	private static function claim_at( $their_file, $target, $format ) {
		// is_readable(), not file_exists(): a file that exists but cannot be
		// opened (restrictive umask, suexec, open_basedir) is not servable
		// however present it is, and locate() will refuse it. Strictly
		// narrowing - is_readable() implies existence - so this can only claim
		// less than before, never more.
		if ( is_readable( $target ) ) {
			return array(
				'bytes' => (int) filesize( $target ),
				'stats' => array( $format . '_already_present' => 1 ),
			);
		}

		// The database records what was written, not what survived. Verify.
		if ( ! is_readable( $their_file ) ) {
			return array(
				'bytes' => 0,
				'stats' => array( $format . '_missing' => 1 ),
			);
		}

		// Servable in place, under the other plugin's name. Nothing to create:
		// the resolver's single-extension fallback finds it where it already is.
		return array(
			'bytes' => (int) filesize( $their_file ),
			'stats' => array( $format . '_linked' => 1 ),
		);
	}
}
