<?php
/**
 * Contract every migration source adapter implements.
 *
 * A migration imports another optimizer's per-attachment state so its work
 * counts as ours: our stats aggregate it, the Media Library column renders it,
 * and its next-gen sibling files are claimed for our <picture> rewriter. An
 * adapter is READ-ONLY with respect to the source plugin — it never writes to
 * the other plugin's tables, options, or files, and never moves or renames a
 * file it did not create.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Slash_Image_Migrate_Adapter {

	/**
	 * Machine slug, used as the CLI argument and as the value written to
	 * _slash_image_migrated_from. Lowercase a-z only.
	 *
	 * @return string
	 */
	public static function slug();

	/**
	 * Human label for CLI output (e.g. 'ShortPixel').
	 *
	 * @return string
	 */
	public static function label();

	/**
	 * Whether this source has importable data on the current site.
	 *
	 * Returns a result array rather than a bool so a refusal can name its own
	 * reason — "the table is missing" and "the data is in a legacy format we
	 * cannot read" need different guidance.
	 *
	 * @return array {
	 *     @type bool   $ok      True when migrate_batch() may run.
	 *     @type string $code    Machine code ('' when ok): no_table |
	 *                           legacy_format | empty.
	 *     @type string $message Translated, user-facing explanation. Rendered on
	 *                           the Tools card AND printed by the CLI, so it must
	 *                           read for both: no table names, no meta keys, and
	 *                           no instruction naming one interface or the other.
	 * }
	 */
	public static function detect();

	/**
	 * Count of distinct attachments the source considers importable, BEFORE
	 * our own skip rules (already-ours, already-migrated, unsupported MIME)
	 * are applied. Used only to size the progress bar, so an upper bound is
	 * acceptable; the report's figures come from migrate_batch().
	 *
	 * @return int
	 */
	public static function count();

	/**
	 * Import one batch of attachments, ascending by ID from an exclusive cursor.
	 *
	 * The adapter owns its own source query. Implementations MUST page rather
	 * than materialise the whole library, and MUST NOT write anything when
	 * $dry_run is true.
	 *
	 * @param int  $after   Exclusive attachment-ID cursor; 0 starts at the beginning.
	 * @param int  $limit   Maximum attachments to examine in this batch.
	 * @param bool $dry_run When true, scan and report but write nothing.
	 * @return array {
	 *     @type int   $cursor Highest attachment ID examined (the next $after).
	 *     @type bool  $done   True when the source is exhausted.
	 *     @type array $stats  Counters keyed by Slash_Image_Migrate::stat_keys().
	 * }
	 */
	public static function migrate_batch( $after, $limit, $dry_run );
}
