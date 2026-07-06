<?php
/**
 * Backup and restore.
 *
 * Hooks into the slash_image_pre_replace_original action fired by the media
 * handler before each destructive overwrite. Copies the file being replaced
 * into wp-content/uploads/slashimage-backups/, mirroring the uploads
 * subdirectory structure: an original at uploads/2024/03/photo.jpg is backed
 * up to uploads/slashimage-backups/2024/03/photo.jpg (and each thumbnail
 * alongside it under the same mirrored directory). The path recorded in
 * postmeta is RELATIVE to the backups base, so it is independent of the
 * absolute uploads path. Per-size backup entries accumulate in the
 * _slash_image_backup attachment meta key, tagged with a top-level 'kind'
 * ('full' in v1; 'main_only' reserved for v1.1 Smart Backups).
 *
 * Restore copies each saved size back into place, deletes any sibling
 * .webp / .avif variants (they regenerate on the next optimize), refreshes
 * the filesize fields in _wp_attachment_metadata, and removes the
 * _slash_image_data and _slash_image_backup meta keys.
 *
 * Per-attachment backups are removed when the attachment is deleted (the
 * delete_attachment hook reads the record and unlinks each mirrored file);
 * whole-site purge and daily retention cleanup unlink each size and prune the
 * now-empty mirror directories. Uninstall deliberately preserves backups (a
 * separate concern — see the uninstaller).
 *
 * Auto-cleanup runs daily via WP-Cron. The retention period is in days;
 * 0 means never auto-delete.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Restore {

	const BACKUP_META_KEY = '_slash_image_backup';
	const BACKUP_DIR_NAME = 'slashimage-backups';
	const CLEANUP_HOOK    = 'slash_image_cleanup_backups';
	const META_VERSION    = 2;

	// Cursor for the bounded daily retention sweep (run_cleanup). Persists the
	// last-processed attachment ID across daily runs; reset on a full pass.
	const CLEANUP_CURSOR_OPTION = 'slash_image_backup_cleanup_cursor';

	/**
	 * Backup-kind tag stored at the top level of the _slash_image_backup meta.
	 * A 'full' record holds every backed-up size — written when Smart Backups is
	 * off, or when the full was excluded so it could not be secured (the gate
	 * then falls back to backing thumbnails up). The tag lets restore branch
	 * per-attachment, so flipping the setting never rewrites pre-existing backups
	 * and an install can hold both kinds at once.
	 */
	const BACKUP_KIND_FULL = 'full';

	/**
	 * Smart Backups kind: only the working full is backed up; thumbnails are
	 * regenerated from it on restore. Stamped at backup-write when Smart Backups
	 * is on and the full is secured (`on_pre_replace()` then skips the
	 * thumbnails); `restore_attachment()` regenerates them from the restored full.
	 */
	const BACKUP_KIND_MAIN_ONLY = 'main_only';

	public function __construct() {
		add_action( 'slash_image_pre_replace_original', array( $this, 'on_pre_replace' ), 10, 3 );
		add_action( self::CLEANUP_HOOK, array( __CLASS__, 'run_cleanup' ) );
		// Per-attachment backup cleanup on deletion. Fires before WordPress
		// removes the attachment's files/postmeta, so the backup dir is still
		// resolvable. This is deliberately SEPARATE from uninstall, which
		// preserves backups on purpose (see class-slash-image-uninstaller.php).
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete_attachment' ) );
	}

	public function on_pre_replace( $attachment_id, $size_key, $abs_path ) {
		$attachment_id = (int) $attachment_id;
		$size_key      = (string) $size_key;
		$abs_path      = (string) $abs_path;

		if ( $attachment_id <= 0 || '' === $size_key || '' === $abs_path ) {
			return;
		}

		if ( ! (bool) Slash_Image_Settings::get( 'keep_backups', true ) ) {
			return;
		}

		if ( ! is_readable( $abs_path ) ) {
			return;
		}

		// Smart Backups gate: when on, skip backing up a THUMBNAIL — restore
		// regenerates thumbnails from the full. Skip ONLY once the full is
		// actually secured in this attachment's record (the full is backed up
		// first in the normal flow). If the full was excluded from optimization
		// and so never backed up, fall through and back the thumbnail up (full
		// behaviour) so no original is lost.
		$smart   = (bool) Slash_Image_Settings::get( 'smart_backups', true );
		$is_full = ( Slash_Image_Media_Handler::FULL_SIZE_KEY === $size_key );
		if ( $smart && ! $is_full ) {
			$existing = get_post_meta( $attachment_id, self::BACKUP_META_KEY, true );
			if ( is_array( $existing ) && ! empty( $existing['sizes'][ Slash_Image_Media_Handler::FULL_SIZE_KEY ]['rel'] ) ) {
				return;
			}
		}

		$backup_path = self::backup_path_for( $attachment_id, $size_key, $abs_path );
		if ( '' === $backup_path ) {
			return;
		}

		$dir = dirname( $backup_path );
		if ( ! wp_mkdir_p( $dir ) ) {
			return;
		}

		// Directory-listing guard: drop an inert index.php into every backup dir
		// just created — the month dir and each parent up to the base — so no
		// level can be web-listed. The prune paths delete these stubs when a dir
		// empties (rmdir_if_empty_confined / prune_backups_base_if_empty).
		self::ensure_listing_guards( $dir );

		if ( file_exists( $backup_path ) ) {
			return;
		}

		if ( ! @copy( $abs_path, $backup_path ) ) {
			return;
		}

		$mtime = @filemtime( $abs_path );
		if ( false !== $mtime ) {
			@touch( $backup_path, $mtime ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Direct filesystem op on a wp-content/uploads path the plugin requires direct write access to; runs in background/cron where WP_Filesystem credential init is unreliable.
		}
		@chmod( $backup_path, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Direct filesystem op on a wp-content/uploads path the plugin requires direct write access to; runs in background/cron where WP_Filesystem credential init is unreliable.

		// kind: a record is 'main_only' only when Smart Backups is on AND this is
		// the full (the gate above then skips this attachment's thumbnails).
		// Backing up a thumbnail means we are keeping every size for it → 'full'.
		$record_kind = ( $smart && $is_full ) ? self::BACKUP_KIND_MAIN_ONLY : self::BACKUP_KIND_FULL;

		$meta = get_post_meta( $attachment_id, self::BACKUP_META_KEY, true );
		if ( ! is_array( $meta ) ) {
			$meta = array(
				'version'    => self::META_VERSION,
				'kind'       => $record_kind,
				'created_at' => gmdate( 'c' ),
				'sizes'      => array(),
			);
		} else {
			if ( empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
				$meta['sizes'] = array();
			}
			if ( empty( $meta['kind'] ) ) {
				// Forward-compat: stamp the kind on any record predating this field.
				$meta['kind'] = $record_kind;
			}
		}

		$backup_size  = (int) @filesize( $backup_path );
		$record_mtime = $mtime ? (int) $mtime : time();

		$meta['sizes'][ $size_key ] = array(
			'rel'        => self::rel_from_base( $backup_path ),
			'size'       => $backup_size,
			'mtime'      => $record_mtime,
			'created_at' => gmdate( 'c' ),
		);

		update_post_meta( $attachment_id, self::BACKUP_META_KEY, $meta );
	}

	public static function restore_attachment( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return array(
				'ok'   => false,
				'code' => 'invalid_id',
			);
		}

		$meta = get_post_meta( $attachment_id, self::BACKUP_META_KEY, true );
		if ( ! is_array( $meta ) || empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
			return array(
				'ok'   => false,
				'code' => 'no_backup',
			);
		}

		// Smart Backups: a main-only backup carries just the working full, so
		// restore regenerates the subsizes from it (failure-safe). The 'full' kind
		// below is the verbatim copy-every-size path and is left unchanged.
		$kind = isset( $meta['kind'] ) ? (string) $meta['kind'] : self::BACKUP_KIND_FULL;
		if ( self::BACKUP_KIND_MAIN_ONLY === $kind ) {
			return self::restore_attachment_main_only( $attachment_id, $meta );
		}

		$original_path = get_attached_file( $attachment_id );
		if ( ! is_string( $original_path ) || '' === $original_path ) {
			return array(
				'ok'   => false,
				'code' => 'attachment_missing',
			);
		}

		$attachment_meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $attachment_meta ) ) {
			$attachment_meta = array();
		}

		$dir            = trailingslashit( dirname( $original_path ) );
		$restored_sizes = array();
		$failed_sizes   = array();
		$variant_paths  = array();

		foreach ( $meta['sizes'] as $size_key => $entry ) {
			// A3-01: re-validate the copy SOURCE at use-time. resolve_entry_path()
			// joins entry['rel'] onto the backups base and realpath-confines it, so a
			// path that doesn't resolve under the base (or doesn't exist) is '' and
			// treated as missing.
			$source = self::resolve_entry_path( $entry );
			if ( '' === $source || ! is_readable( $source ) ) {
				$failed_sizes[ $size_key ] = 'backup_missing';
				continue;
			}

			$target = self::target_path_for_size( $original_path, $attachment_meta, $size_key );
			if ( '' === $target ) {
				$failed_sizes[ $size_key ] = 'unknown_size';
				continue;
			}

			if ( ! @copy( $source, $target ) ) {
				$failed_sizes[ $size_key ] = 'copy_failed';
				continue;
			}

			clearstatcache( true, $target );

			foreach ( array( 'webp', 'avif' ) as $variant ) {
				$variant_paths[] = $target . '.' . $variant;
			}

			$restored_sizes[] = $size_key;
		}

		foreach ( array_unique( $variant_paths ) as $vpath ) {
			if ( file_exists( $vpath ) ) {
				wp_delete_file( $vpath );
			}
		}

		if ( ! empty( $restored_sizes ) ) {
			$attachment_meta = self::refresh_filesizes( $original_path, $attachment_meta );
			// Revert the persisted full-size dimensions. The WP-side resize may have
			// downscaled the full-size and written the smaller width/height into the
			// metadata; restoring the backup brings the larger file back, so re-read
			// its actual dimensions from disk and write them back (else the metadata
			// would keep advertising the resized size). The _slash_image_data blob
			// carries no width/height fields and is deleted on a full restore below,
			// so there is nothing dimension-derived to refresh there.
			if ( in_array( Slash_Image_Media_Handler::FULL_SIZE_KEY, $restored_sizes, true ) ) {
				$dims = self::read_dimensions( $original_path );
				if ( null !== $dims ) {
					$attachment_meta['width']  = $dims[0];
					$attachment_meta['height'] = $dims[1];
				}
			}
			wp_update_attachment_metadata( $attachment_id, $attachment_meta );
		}

		if ( empty( $failed_sizes ) ) {
			// Tear down the backup: unlink every mirrored size and prune the
			// emptied mirror dirs (A3-01 confinement is inside delete_backup_files).
			self::delete_backup_files( $meta );
			delete_post_meta( $attachment_id, self::BACKUP_META_KEY );
			delete_post_meta( $attachment_id, Slash_Image_Media_Handler::META_DATA_KEY );
			// Drop the flat stats fields in lockstep with the blob
			// (full-success restore only — a partial restore keeps the blob).
			Slash_Image_Media_Handler::delete_flat_stats_fields( $attachment_id );
		} else {
			$meta['sizes'] = array_intersect_key( $meta['sizes'], array_flip( array_diff( array_keys( $meta['sizes'] ), $restored_sizes ) ) );
			update_post_meta( $attachment_id, self::BACKUP_META_KEY, $meta );
		}

		return array(
			'ok'             => empty( $failed_sizes ),
			'code'           => empty( $failed_sizes ) ? 'restored' : 'partial',
			'restored_sizes' => $restored_sizes,
			'failed_sizes'   => $failed_sizes,
		);
	}

	/**
	 * Smart Backups restore: the backup holds only the working full, so the
	 * subsizes are regenerated from it. Failure-safe — nothing is deleted until
	 * regeneration succeeds, so a failure DEGRADES (restored full + the old,
	 * still-valid optimized thumbnails) rather than breaking the attachment.
	 *
	 * Regeneration sources from the restored WORKING full (get_attached_file —
	 * the `-scaled` file when WordPress kept one). WordPress's unscaled
	 * original_image is never touched, and its metadata reference is preserved
	 * across the rebuild (regenerating the at/below-threshold working full does
	 * not re-create it).
	 *
	 * @param int   $attachment_id Attachment to restore.
	 * @param array $meta          The _slash_image_backup record (kind=main_only).
	 * @return array { ok, code, restored_sizes, [regenerated] }
	 */
	private static function restore_attachment_main_only( $attachment_id, array $meta ) {
		$full_key    = Slash_Image_Media_Handler::FULL_SIZE_KEY;
		$full_entry  = isset( $meta['sizes'][ $full_key ] ) ? $meta['sizes'][ $full_key ] : null;
		$backup_full = self::resolve_entry_path( $full_entry );

		// 1. Validate the backup full is present, confined, and a readable image.
		if ( '' === $backup_full || ! is_readable( $backup_full ) ) {
			return array(
				'ok'   => false,
				'code' => 'no_backup',
			);
		}
		if ( null === self::read_dimensions( $backup_full ) ) {
			return array(
				'ok'   => false,
				'code' => 'backup_unreadable',
			);
		}

		$working_full = get_attached_file( $attachment_id );
		if ( ! is_string( $working_full ) || '' === $working_full ) {
			return array(
				'ok'   => false,
				'code' => 'attachment_missing',
			);
		}
		$live_dir = trailingslashit( dirname( $working_full ) );

		// 2. Stage-and-swap the working full: copy the backup to a temp file on the
		// same filesystem, then atomically rename it over the live full. The live
		// full is only ever replaced by a complete file.
		$tmp = $live_dir . '.slash-image-restore-' . uniqid( '', true ) . '.tmp';
		if ( ! @copy( $backup_full, $tmp ) ) {
			wp_delete_file( $tmp );
			return array(
				'ok'   => false,
				'code' => 'copy_failed',
			);
		}
		if ( ! @rename( $tmp, $working_full ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Direct filesystem op on a wp-content/uploads path the plugin requires direct write access to; runs in background/cron where WP_Filesystem credential init is unreliable.
			wp_delete_file( $tmp );
			return array(
				'ok'   => false,
				'code' => 'swap_failed',
			);
		}
		clearstatcache( true, $working_full );

		// 3. + 4. Snapshot the pre-regeneration metadata — the old subsize
		// filenames (for orphan cleanup) and the original_image reference (to
		// preserve) — and revert the full-size width/height to the restored
		// file's true dimensions (undoing any WP-side resize).
		$attachment_meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $attachment_meta ) ) {
			$attachment_meta = array();
		}
		$old_sizes = ( ! empty( $attachment_meta['sizes'] ) && is_array( $attachment_meta['sizes'] ) ) ? $attachment_meta['sizes'] : array();
		$dims      = self::read_dimensions( $working_full );
		if ( null !== $dims ) {
			$attachment_meta['width']  = $dims[0];
			$attachment_meta['height'] = $dims[1];
		}

		// 5. Regenerate subsizes from the restored working full.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		if ( function_exists( 'wp_create_image_subsizes' ) ) {
			$new_meta = wp_create_image_subsizes( $working_full, $attachment_id );
		} else {
			$new_meta = wp_generate_attachment_metadata( $attachment_id, $working_full );
		}
		$new_sizes = ( is_array( $new_meta ) && ! empty( $new_meta['sizes'] ) && is_array( $new_meta['sizes'] ) ) ? $new_meta['sizes'] : array();

		// Regeneration failed if it returned no usable metadata, or produced zero
		// subsizes for an image that previously had them (e.g. the editor could
		// not process the file). STOP: keep the backup + meta, and restore the
		// pre-regeneration metadata so the attachment still advertises the
		// still-present old optimized thumbnails. The full is already restored, so
		// this degrades cleanly rather than breaking the attachment.
		$regen_failed = ( ! is_array( $new_meta ) )
			|| empty( $new_meta['width'] )
			|| ( ! empty( $old_sizes ) && empty( $new_sizes ) );

		if ( $regen_failed ) {
			wp_update_attachment_metadata( $attachment_id, $attachment_meta );
			return array(
				'ok'             => false,
				'code'           => 'regenerate_failed',
				'restored_sizes' => array( $full_key ),
			);
		}

		// Preserve WordPress's unscaled-original reference. Regenerating from the
		// already-(at-or-below-threshold) working full does not re-create it, so
		// carry it over from the pre-restore metadata.
		if ( ! isset( $new_meta['original_image'] ) && isset( $attachment_meta['original_image'] ) ) {
			$new_meta['original_image'] = $attachment_meta['original_image'];
		}
		wp_update_attachment_metadata( $attachment_id, $new_meta );

		// 6. Success-only cleanup. (i) Remove orphaned old subsize image files (old
		// names absent from the regenerated set, never the working full); then
		// (ii) sweep stale .webp/.avif siblings for the working full and every old
		// AND new subsize name, so leftovers from removed sizes are cleaned too.
		$working_bn    = wp_basename( $working_full );
		$new_names     = array();
		$variant_bases = array( $working_full );

		foreach ( $new_sizes as $sd ) {
			if ( empty( $sd['file'] ) ) {
				continue;
			}
			$bn               = wp_basename( (string) $sd['file'] );
			$new_names[ $bn ] = true;
			$variant_bases[]  = $live_dir . $bn;
		}
		foreach ( $old_sizes as $sd ) {
			if ( empty( $sd['file'] ) ) {
				continue;
			}
			$bn              = wp_basename( (string) $sd['file'] );
			$variant_bases[] = $live_dir . $bn;
			if ( isset( $new_names[ $bn ] ) || $bn === $working_bn ) {
				continue; // Still a current subsize (or the full) — keep the file.
			}
			$orphan = $live_dir . $bn;
			if ( file_exists( $orphan ) ) {
				wp_delete_file( $orphan );
			}
		}
		foreach ( array_unique( $variant_bases ) as $vb ) {
			foreach ( array( 'webp', 'avif' ) as $variant ) {
				$sibling = $vb . '.' . $variant;
				if ( file_exists( $sibling ) ) {
					wp_delete_file( $sibling );
				}
			}
		}

		// 7. Tear down the backup now that the new state is committed: unlink every
		// mirrored size and prune the emptied mirror dirs.
		self::delete_backup_files( $meta );
		delete_post_meta( $attachment_id, self::BACKUP_META_KEY );
		delete_post_meta( $attachment_id, Slash_Image_Media_Handler::META_DATA_KEY );
		Slash_Image_Media_Handler::delete_flat_stats_fields( $attachment_id );

		return array(
			'ok'             => true,
			'code'           => 'restored',
			'restored_sizes' => array( $full_key ),
			'regenerated'    => array_keys( $new_sizes ),
		);
	}

	/**
	 * Source query for the backup sweeps: up to $limit attachment IDs that have a
	 * backup record (BACKUP_META_KEY) and id > $after, ordered by id ascending.
	 * Bounded + indexed (the postmeta meta_key index); the id cursor makes paging
	 * stable under concurrent backup deletion. The single home for this raw query,
	 * shared by the restore feed (Slash_Image_Worker::next_restore_ids), the daily
	 * retention sweep (run_cleanup), and the danger-zone delete-all sweep
	 * (delete_all_backups). Raw $wpdb (not get_posts), so no SlowDBQuery warning.
	 *
	 * @param int $after Return IDs strictly greater than this (the cursor).
	 * @param int $limit Maximum number of IDs to return.
	 * @return int[] Ascending attachment IDs that have a backup record.
	 */
	public static function next_backed_up_ids( $after, $limit ) {
		global $wpdb;

		$after = (int) $after;
		$limit = max( 1, (int) $limit );

		return array_map(
			'intval',
			$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT p.ID
					   FROM {$wpdb->posts} p
					   INNER JOIN {$wpdb->postmeta} m
					     ON m.post_id = p.ID AND m.meta_key = %s
					  WHERE p.post_type = 'attachment'
					    AND p.post_status = 'inherit'
					    AND p.ID > %d
					  ORDER BY p.ID ASC
					  LIMIT %d",
					self::BACKUP_META_KEY,
					$after,
					$limit
				)
			)
		);
	}

	public static function delete_all_backups() {
		$summary = array(
			'attachments' => 0,
			'files'       => 0,
			'bytes_freed' => 0,
			'errors'      => 0,
		);

		// Single-shot "delete every backup now" via an in-memory ID-cursor paging
		// loop — distinct from run_cleanup's resumable daily sweep (no persisted
		// CLEANUP_CURSOR_OPTION, no per-run cap; this finishes the whole job in one
		// request). Termination invariant: next_backed_up_ids() only returns IDs
		// strictly greater than $cursor (p.ID > %d, ORDER BY p.ID ASC), so
		// max($ids) > $cursor on every iteration and the cursor strictly advances;
		// an empty page is the sole exit. Bounds memory (one page at a time, never a
		// posts_per_page=>-1 load) and uses the shared raw cursor query, so no
		// SlowDBQuery warning and no suppression.
		$cursor = 0;
		$chunk  = max( 1, (int) apply_filters( 'slash_image_delete_backups_chunk_size', 200 ) );

		while ( true ) {
			$ids = self::next_backed_up_ids( $cursor, $chunk );
			if ( empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $id ) {
				$id   = (int) $id;
				$meta = get_post_meta( $id, self::BACKUP_META_KEY, true );
				if ( ! is_array( $meta ) || empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
					// Stale/empty record: the mirror layout has no per-id dir to
					// sweep, so just drop the meta.
					delete_post_meta( $id, self::BACKUP_META_KEY );
					continue;
				}

				++$summary['attachments'];

				$deleted                 = self::delete_backup_files( $meta );
				$summary['files']       += $deleted['files'];
				$summary['bytes_freed'] += $deleted['bytes'];
				$summary['errors']      += $deleted['errors'];

				delete_post_meta( $id, self::BACKUP_META_KEY );
			}

			$cursor = (int) max( $ids );
		}

		// Per-attachment removals prune their own month/year dirs; sweep any empty
		// dirs left behind, then drop the backups base if the whole tree is gone.
		self::prune_empty_tree();
		self::prune_backups_base_if_empty();

		return $summary;
	}

	/**
	 * delete_attachment hook: remove one attachment's mirrored backup files when
	 * the attachment is deleted. Postmeta-driven — reads the _slash_image_backup
	 * record (still present at this hook, before WP strips the attachment's meta),
	 * unlinks each mirrored size, prunes the emptied mirror dirs, then drops the
	 * record. This is what closes the mirror-layout collision window: a deleted
	 * attachment leaves no stale backup behind to alias a later upload that reuses
	 * its {month}/{filename}.
	 *
	 * @param int $attachment_id Attachment being deleted.
	 */
	public static function on_delete_attachment( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return;
		}
		$meta = get_post_meta( $attachment_id, self::BACKUP_META_KEY, true );
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			self::delete_backup_files( $meta );
		}
		delete_post_meta( $attachment_id, self::BACKUP_META_KEY );
	}

	/**
	 * Unlink every mirrored backup file in a _slash_image_backup record and prune
	 * the now-empty mirror directories. Each size is resolved through
	 * resolve_entry_path() (rel → confined absolute) before unlink, so a missing
	 * or out-of-base entry is simply skipped. After unlinking, every touched month
	 * dir and its year parent are pruned if they have become empty.
	 *
	 * Accepts a real record or a synthetic { sizes => [...] } subset (used by the
	 * retention sweep to delete only the expired sizes).
	 *
	 * @param array $meta A _slash_image_backup record (must carry a 'sizes' map).
	 * @return array{files:int,bytes:int,errors:int} Counts for caller summaries.
	 */
	private static function delete_backup_files( array $meta ) {
		$summary = array(
			'files'  => 0,
			'bytes'  => 0,
			'errors' => 0,
		);
		if ( empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
			return $summary;
		}

		$dirs = array();
		foreach ( $meta['sizes'] as $entry ) {
			// A3-01: resolve_entry_path() realpath-confines the entry inside the
			// backups base; a non-resolving entry returns '' and is skipped.
			$abs = self::resolve_entry_path( $entry );
			if ( '' === $abs ) {
				continue;
			}
			$size = (int) @filesize( $abs );
			wp_delete_file( $abs );
			clearstatcache( true, $abs );
			if ( ! file_exists( $abs ) ) {
				++$summary['files'];
				$summary['bytes']       += $size;
				$dirs[ dirname( $abs ) ] = true;
			} else {
				++$summary['errors'];
			}
		}

		// Prune each touched mirror dir bottom-up: the month dir, then its year
		// parent — each only if empty and still confined to the base.
		foreach ( array_keys( $dirs ) as $dir ) {
			self::rmdir_if_empty_confined( $dir );
			self::rmdir_if_empty_confined( dirname( $dir ) );
		}

		return $summary;
	}

	/**
	 * Sweep empty mirror directories left inside the backups base after a mass
	 * delete. Walks the base's year dirs and their month children, pruning any
	 * that are empty (bottom-up). The base's own index.php stub and the base
	 * dir itself are handled separately by prune_backups_base_if_empty().
	 */
	private static function prune_empty_tree() {
		$real_base = self::real_backups_base();
		if ( '' === $real_base ) {
			return;
		}
		$years = @scandir( $real_base );
		if ( ! is_array( $years ) ) {
			return;
		}
		foreach ( $years as $year ) {
			if ( '.' === $year || '..' === $year || 'index.php' === $year ) {
				continue;
			}
			$year_dir = $real_base . '/' . $year;
			if ( ! is_dir( $year_dir ) || is_link( $year_dir ) ) {
				continue;
			}
			$months = @scandir( $year_dir );
			if ( is_array( $months ) ) {
				foreach ( $months as $month ) {
					if ( '.' === $month || '..' === $month ) {
						continue;
					}
					self::rmdir_if_empty_confined( $year_dir . '/' . $month );
				}
			}
			self::rmdir_if_empty_confined( $year_dir );
		}
	}

	/**
	 * Normalized absolute path of the backups base
	 * ({uploads}/slashimage-backups), without a trailing slash. No realpath —
	 * callers add it when they need filesystem resolution.
	 *
	 * @return string Base path, or '' if the uploads dir is unavailable.
	 */
	private static function backups_base_dir() {
		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['basedir'] ) ) {
			return '';
		}
		return trailingslashit( wp_normalize_path( $uploads['basedir'] ) ) . self::BACKUP_DIR_NAME;
	}

	/**
	 * realpath()-resolved, normalized backups base used as the confinement
	 * anchor for operations on existing files (A3-01). Returns '' if the base
	 * does not exist on disk.
	 *
	 * @return string Resolved base path, or ''.
	 */
	private static function real_backups_base() {
		$base = self::backups_base_dir();
		if ( '' === $base ) {
			return '';
		}
		$real = realpath( $base );
		if ( false === $real ) {
			return '';
		}
		return wp_normalize_path( $real );
	}

	/**
	 * Remove a directory iff it is empty and resolves strictly inside the
	 * backups base (never the base itself). Used to prune mirror (year/month) dirs.
	 *
	 * @param string $dir Absolute directory path.
	 */
	private static function rmdir_if_empty_confined( $dir ) {
		$real_base = self::real_backups_base();
		if ( '' === $real_base ) {
			return;
		}
		$real = realpath( $dir );
		if ( false === $real ) {
			return;
		}
		$real = wp_normalize_path( $real );
		if ( 0 !== strpos( $real, trailingslashit( $real_base ) ) ) {
			return; // Outside the base, or the base itself — leave it.
		}
		$entries = @scandir( $real );
		if ( ! is_array( $entries ) ) {
			return;
		}
		// "Empty" = nothing remains but our own index.php stub (plus . and ..). If
		// ANY real backup file is left, keep the dir — co-resident backups in a
		// shared month dir must survive a sibling's deletion.
		$remaining = array_diff( $entries, array( '.', '..', 'index.php' ) );
		if ( ! empty( $remaining ) ) {
			return;
		}
		if ( in_array( 'index.php', $entries, true ) ) {
			wp_delete_file( $real . '/index.php' );
		}
		@rmdir( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Direct filesystem op on a wp-content/uploads path the plugin requires direct write access to; runs in background/cron where WP_Filesystem credential init is unreliable.
	}

	/**
	 * Remove the backups base dir itself once the whole tree is purged. The base
	 * is removed when nothing remains under it except the plugin's own
	 * directory-index stub (`index.php`), which is unlinked first. Any other
	 * residue — a real backup dir, a stray file, a `.DS_Store` — leaves the base
	 * in place, so this can never delete a base that still holds backups.
	 * Called once after a whole-library backup purge.
	 */
	private static function prune_backups_base_if_empty() {
		$base = self::backups_base_dir();
		if ( '' === $base ) {
			return;
		}
		$real = realpath( $base );
		if ( false === $real ) {
			return;
		}
		$entries = @scandir( $real );
		if ( ! is_array( $entries ) ) {
			return;
		}
		$remaining = array_diff( $entries, array( '.', '..', 'index.php' ) );
		if ( ! empty( $remaining ) ) {
			return; // Real content remains — leave the base alone.
		}
		if ( in_array( 'index.php', $entries, true ) ) {
			wp_delete_file( $real . '/index.php' );
		}
		@rmdir( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Direct filesystem op on a wp-content/uploads path the plugin requires direct write access to; runs in background/cron where WP_Filesystem credential init is unreliable.
	}

	public static function schedule_cleanup() {
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	public static function unschedule_cleanup() {
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
	}

	public static function run_cleanup() {
		// Primary gate: auto-delete is opt-in. When off, no backup is ever deleted
		// automatically, regardless of the retention-days value.
		if ( ! (bool) Slash_Image_Settings::get( 'auto_delete_backups', false ) ) {
			return;
		}

		$days = (int) Slash_Image_Settings::get( 'backup_retention_days', 90 );
		if ( $days <= 0 ) {
			// Safety net: a non-positive retention window means nothing to delete.
			return;
		}

		$cutoff = time() - ( $days * DAY_IN_SECONDS );

		// Bounded per-tick sweep (retires the unbounded A3-03 loop on this daily
		// cron). Cursor by attachment ID so a huge library is cleaned across
		// successive daily runs rather than in one -1 load; the cursor resets once
		// a pass exhausts the set, and is capped at $per_run per run.
		$per_run = max( 100, (int) apply_filters( 'slash_image_backup_cleanup_per_run', 1000 ) );
		$chunk   = 200;
		$cursor  = (int) get_option( self::CLEANUP_CURSOR_OPTION, 0 );
		$seen    = 0;

		while ( $seen < $per_run ) {
			$ids = self::next_backed_up_ids( $cursor, $chunk );

			if ( empty( $ids ) ) {
				// Set exhausted — restart the cursor for the next daily run.
				delete_option( self::CLEANUP_CURSOR_OPTION );
				return;
			}

			foreach ( $ids as $id ) {
				self::cleanup_attachment( (int) $id, $cutoff );
			}

			$cursor = (int) max( $ids );
			$seen  += count( $ids );
		}

		update_option( self::CLEANUP_CURSOR_OPTION, $cursor, false );
	}

	private static function cleanup_attachment( $attachment_id, $cutoff ) {
		$meta = get_post_meta( $attachment_id, self::BACKUP_META_KEY, true );
		if ( ! is_array( $meta ) || empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
			return;
		}

		$kept    = array();
		$expired = array();
		foreach ( $meta['sizes'] as $size_key => $entry ) {
			$created_at = isset( $entry['created_at'] ) ? strtotime( $entry['created_at'] ) : 0;
			if ( $created_at > 0 && $created_at >= $cutoff ) {
				$kept[ $size_key ] = $entry;
			} else {
				$expired[ $size_key ] = $entry;
			}
		}

		// Unlink only the expired sizes (confinement + dir pruning live in
		// delete_backup_files); a shared month dir survives until its last
		// surviving backup is gone.
		if ( ! empty( $expired ) ) {
			self::delete_backup_files( array( 'sizes' => $expired ) );
		}

		if ( empty( $kept ) ) {
			delete_post_meta( $attachment_id, self::BACKUP_META_KEY );
		} else {
			$meta['sizes'] = $kept;
			update_post_meta( $attachment_id, self::BACKUP_META_KEY, $meta );
		}
	}

	/**
	 * Compute the absolute backup path for one size of an attachment, mirroring
	 * the uploads subdirectory structure under the backups base.
	 *
	 * Layout: {uploads}/slashimage-backups/{file's uploads-relative path} —
	 * e.g. a live size at uploads/2024/03/photo-300x200.jpg backs up to
	 * {uploads}/slashimage-backups/2024/03/photo-300x200.jpg. The mirror tail is
	 * the live file's own path relative to the uploads base, so the live
	 * filename (which already encodes the size as name-WxH.ext) keeps each size
	 * distinct with no extra namespacing.
	 *
	 * Collision note: unlike the pre-v1.0 ID-namespaced layout, a mirror path
	 * can alias a stale backup if a deleted attachment's {month}/{filename} is
	 * later reused. That window is closed by unlinking an attachment's mirrored
	 * files on delete_attachment (see on_delete_attachment) — the same mitigation
	 * ShortPixel and Imagify rely on.
	 *
	 * The target does not exist yet, so confinement is lexical (reject any '..'
	 * that escapes the base), not realpath-based (A3-02).
	 *
	 * @param int    $attachment_id Attachment post ID (validated; not part of the path).
	 * @param string $size_key      Size key — retained for signature/caller symmetry; the
	 *                              live filename already distinguishes sizes, so it is not
	 *                              encoded in the path.
	 * @param string $abs_path      Absolute path of the live file being backed up.
	 * @return string Absolute backup path, or '' on any validation failure.
	 */
	public static function backup_path_for( $attachment_id, $size_key, $abs_path ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return '';
		}

		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['basedir'] ) ) {
			return '';
		}
		$basedir = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );
		$base    = $basedir . self::BACKUP_DIR_NAME;

		// Only ever back up files that live inside the uploads tree.
		$abs_norm = wp_normalize_path( (string) $abs_path );
		if ( 0 !== strpos( $abs_norm, $basedir ) ) {
			return '';
		}

		// Mirror tail: the live file's path relative to the uploads base
		// (e.g. '2024/03/photo-300x200.jpg').
		$rel = substr( $abs_norm, strlen( $basedir ) );
		if ( '' === $rel ) {
			return '';
		}

		$path = $base . '/' . $rel;

		return self::confine_lexical( $path, $base ) ? $path : '';
	}

	/**
	 * Reduce an absolute backup path to its tail relative to the backups base —
	 * the value persisted in postmeta as entry['rel'] (e.g.
	 * '{uploads}/slashimage-backups/2024/03/photo.jpg' → '2024/03/photo.jpg').
	 * Inverse of the join in resolve_entry_path(). Returns '' if $abs_path does
	 * not sit under the base.
	 *
	 * @param string $abs_path Absolute backup path (as returned by backup_path_for).
	 * @return string Path relative to the backups base, or ''.
	 */
	private static function rel_from_base( $abs_path ) {
		$base = self::backups_base_dir();
		if ( '' === $base ) {
			return '';
		}
		$abs    = wp_normalize_path( (string) $abs_path );
		$prefix = trailingslashit( $base );
		if ( 0 !== strpos( $abs, $prefix ) ) {
			return '';
		}
		return substr( $abs, strlen( $prefix ) );
	}

	/**
	 * Assert that a stored backup path resolves (realpath) inside the backups
	 * base. Use-time guard (A3-01) for any copy-from / unlink of a path read
	 * from postmeta. Returns false for empty, non-existent, or out-of-base paths.
	 *
	 * @param string $path Stored absolute path.
	 * @return bool True iff the path exists and resolves inside the backups base.
	 */
	private static function is_confined_backup_file( $path ) {
		if ( empty( $path ) ) {
			return false;
		}
		$real_base = self::real_backups_base();
		if ( '' === $real_base ) {
			return false;
		}
		$real = realpath( $path );
		if ( false === $real ) {
			return false;
		}
		$real = wp_normalize_path( $real );
		return 0 === strpos( $real, trailingslashit( $real_base ) );
	}

	/**
	 * Resolve a stored backup entry to a confined, existing absolute path.
	 *
	 * Reads entry['rel'] (a path relative to the backups base), joins it onto the
	 * current base, then runs BOTH confinement guards: lexical (reject any '..'
	 * that escapes the base, A3-02) and realpath-based (the resolved file must
	 * exist and sit inside the base, A3-01). This is the single choke point every
	 * read site passes through before a copy-from or unlink, replacing the former
	 * raw is_confined_backup_file( entry['path'] ) calls.
	 *
	 * @param array $entry One element of the _slash_image_backup 'sizes' map.
	 * @return string Normalized absolute path, or '' if missing / unresolvable / out-of-base.
	 */
	private static function resolve_entry_path( $entry ) {
		$rel = ( is_array( $entry ) && isset( $entry['rel'] ) ) ? (string) $entry['rel'] : '';
		if ( '' === $rel ) {
			return '';
		}
		$base = self::backups_base_dir();
		if ( '' === $base ) {
			return '';
		}
		$abs = trailingslashit( $base ) . ltrim( $rel, '/' );
		// Lexical guard first (the join need not exist yet), then realpath
		// confinement (existence + symlink-resolved containment).
		if ( ! self::confine_lexical( $abs, $base ) || ! self::is_confined_backup_file( $abs ) ) {
			return '';
		}
		return wp_normalize_path( realpath( $abs ) );
	}

	/**
	 * Lexically confine $path to $base: normalize both (collapsing '.'/'..'
	 * without touching the filesystem) and confirm $path stays within $base.
	 * For paths that do not exist yet (A3-02).
	 *
	 * @param string $path Candidate absolute path.
	 * @param string $base Base directory it must stay within.
	 * @return bool True iff confined.
	 */
	private static function confine_lexical( $path, $base ) {
		$norm_base = self::lexical_normalize( wp_normalize_path( (string) $base ) );
		$norm_path = self::lexical_normalize( wp_normalize_path( (string) $path ) );
		if ( '' === $norm_base || '' === $norm_path ) {
			return false;
		}
		return ( $norm_path === $norm_base ) || ( 0 === strpos( $norm_path, trailingslashit( $norm_base ) ) );
	}

	/**
	 * Collapse '.' and '..' segments lexically (no filesystem access). A leading
	 * '/' is preserved; Windows drive prefixes (e.g. 'C:') survive as a segment.
	 *
	 * @param string $path Forward-slash path.
	 * @return string Normalized path.
	 */
	private static function lexical_normalize( $path ) {
		$path        = (string) $path;
		$is_absolute = ( '' !== $path && '/' === $path[0] );
		$out         = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $out );
				continue;
			}
			$out[] = $segment;
		}
		return ( $is_absolute ? '/' : '' ) . implode( '/', $out );
	}

	private static function target_path_for_size( $original_path, array $attachment_meta, $size_key ) {
		if ( Slash_Image_Media_Handler::FULL_SIZE_KEY === $size_key ) {
			return $original_path;
		}

		if ( empty( $attachment_meta['sizes'][ $size_key ]['file'] ) ) {
			return '';
		}

		return trailingslashit( dirname( $original_path ) ) . wp_basename( (string) $attachment_meta['sizes'][ $size_key ]['file'] );
	}

	private static function refresh_filesizes( $original_path, array $attachment_meta ) {
		clearstatcache();

		$size = @filesize( $original_path );
		if ( false !== $size ) {
			$attachment_meta['filesize'] = (int) $size;
		}

		if ( ! empty( $attachment_meta['sizes'] ) && is_array( $attachment_meta['sizes'] ) ) {
			$dir = trailingslashit( dirname( $original_path ) );
			foreach ( $attachment_meta['sizes'] as $size_key => $size_data ) {
				if ( empty( $size_data['file'] ) ) {
					continue;
				}
				$abs = $dir . wp_basename( (string) $size_data['file'] );
				$sz  = @filesize( $abs );
				if ( false !== $sz ) {
					$attachment_meta['sizes'][ $size_key ]['filesize'] = (int) $sz;
				}
			}
		}

		return $attachment_meta;
	}

	/**
	 * Read an image file's pixel dimensions from disk. Used by restore to revert
	 * the persisted full-size width/height after the backup is moved back.
	 *
	 * @param string $path Absolute file path.
	 * @return array{0:int,1:int}|null [ width, height ], or null if unreadable.
	 */
	private static function read_dimensions( $path ) {
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$size = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $path ) : @getimagesize( $path );
		if ( is_array( $size ) && isset( $size[0], $size[1] ) && (int) $size[0] > 0 && (int) $size[1] > 0 ) {
			return array( (int) $size[0], (int) $size[1] );
		}
		return null;
	}

	/**
	 * Walk from $leaf_dir up to (and including) the backups base, dropping an
	 * inert index.php listing-guard into each level. Stops AT the base so nothing
	 * is ever written outside slashimage-backups/. $leaf_dir is always inside (or
	 * equal to) the base by construction — it is dirname() of a confined backup
	 * path — so the walk can never escape upward. Idempotent.
	 *
	 * @param string $leaf_dir Deepest dir just created (the month dir, or the base
	 *                         itself for date-folder-less uploads).
	 */
	private static function ensure_listing_guards( $leaf_dir ) {
		$base = self::backups_base_dir();
		if ( '' === $base ) {
			return;
		}
		$base = wp_normalize_path( $base );
		$dir  = wp_normalize_path( (string) $leaf_dir );
		// Only ever inside-or-equal the base; never above it.
		while ( $dir === $base || 0 === strpos( $dir, trailingslashit( $base ) ) ) {
			self::ensure_directory_index( $dir );
			if ( $dir === $base ) {
				break;
			}
			$parent = dirname( $dir );
			if ( $parent === $dir ) {
				break; // Filesystem-root guard; unreachable inside the base.
			}
			$dir = $parent;
		}
	}

	private static function ensure_directory_index( $dir ) {
		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			// Inert "silence is golden" guard (WP-core / Imagify convention) so the
			// directory can never be web-listed. index.php (not .htaccess) is inert
			// on every server — zero HTTP-500 risk.
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}
}
