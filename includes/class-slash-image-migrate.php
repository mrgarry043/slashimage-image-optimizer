<?php
/**
 * Migration orchestrator: adapter registry, global refusals, batch loop, cursor.
 *
 * Drives an adapter (see Slash_Image_Migrate_Adapter) over the library in
 * ID-ordered batches, accumulating one shared stat vocabulary so every source
 * reports the same shape. Loaded on demand — under WP-CLI today, from the
 * Tools tab later — so it costs nothing on a normal web request.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Migrate {

	/**
	 * Non-autoloaded resume cursors, keyed by adapter slug: [ slug => cursor ].
	 * A slug's entry is removed when its walk completes; the option is dropped
	 * once no slug has a position left.
	 *
	 * Per-slug because the cursor is an attachment-ID position within ONE
	 * source's own row set. Sharing a single scalar across adapters meant an
	 * interrupted ShortPixel run left a position that a later Imagify run
	 * resumed from, silently skipping every Imagify attachment below it and
	 * then recording that source as complete.
	 *
	 * The KEY is deliberately unchanged. A pre-1.2.0-dev site holds a bare
	 * scalar here, and the option is not in the uninstaller's list — renaming
	 * would strand that value on every upgraded site with nothing able to
	 * remove it. Reading through cursors() discards the stale scalar instead.
	 */
	const CURSOR_OPTION = 'slash_image_migrate_cursor';

	/**
	 * Per-source record of a walk that reached the end of the source, written
	 * only when an adapter reports done. Non-autoloaded: read on the Tools tab,
	 * never on a front-end request.
	 */
	const COMPLETED_OPTION = 'slash_image_migrate_completed';

	/** Provenance written on every migrated attachment. */
	const META_MIGRATED_FROM = '_slash_image_migrated_from';
	const META_MIGRATED_AT   = '_slash_image_migrated_at';

	/** Default attachments examined per batch. */
	const DEFAULT_BATCH_SIZE = 200;

	/**
	 * Registered adapters, slug => class name.
	 *
	 * @return array
	 */
	public static function adapters() {
		return array(
			'shortpixel' => 'Slash_Image_Migrate_Shortpixel',
			'imagify'    => 'Slash_Image_Migrate_Imagify',
		);
	}

	/**
	 * Resolve a slug to its adapter class, or '' when unknown.
	 *
	 * @param string $slug Adapter slug.
	 * @return string
	 */
	public static function adapter_for( $slug ) {
		$adapters = self::adapters();
		$slug     = strtolower( trim( (string) $slug ) );
		return isset( $adapters[ $slug ] ) ? $adapters[ $slug ] : '';
	}

	/**
	 * The shared stat vocabulary. Every adapter reports these keys and the
	 * orchestrator sums them, so a new source needs no reporting changes.
	 *
	 * @return array Zeroed counters.
	 */
	public static function stat_keys() {
		return array(
			// Scan outcomes.
			'scanned'                   => 0,
			'migrated'                  => 0,
			'already_ours'              => 0,
			'already_migrated'          => 0,
			'skipped_status'            => 0,
			// Sub-counts of skipped_status. A source reports whichever it has:
			// ShortPixel can revert an image (status 3); Imagify instead marks
			// one it judged already small enough. Separate keys because
			// collapsing them would print "reverted" for images nobody reverted.
			'skipped_restored'          => 0,
			'skipped_already_optimized' => 0,
			'skipped_unsupported_mime'  => 0,
			'skipped_no_usable_rows'    => 0,
			'skipped_file_missing'      => 0,
			// Next-gen claim outcomes. '_linked' is historical: nothing is
			// created any more, so it counts siblings servable under the OTHER
			// plugin's filename, which the resolver finds where they already sit.
			'webp_linked'               => 0,
			'avif_linked'               => 0,
			'webp_already_present'      => 0,
			'avif_already_present'      => 0,
			'webp_missing'              => 0,
			'avif_missing'              => 0,
			'sentinel_skipped'          => 0,
		);
	}

	/**
	 * Global refusals that apply regardless of source. Checked before the
	 * adapter's own detect().
	 *
	 * @return array ['ok' => bool, 'code' => string, 'message' => string]
	 */
	public static function preflight() {
		if ( is_multisite() ) {
			return array(
				'ok'      => false,
				'code'    => 'multisite',
				'message' => __( 'Migration is not available on multisite networks yet, because optimization data is stored separately for each site.', 'slashimage-image-optimizer' ),
			);
		}

		return array(
			'ok'      => true,
			'code'    => '',
			'message' => '',
		);
	}

	/**
	 * True when this attachment already carries genuine SlashImage optimization
	 * data that a migration must never overwrite.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return bool
	 */
	public static function is_optimized_by_us( $attachment_id ) {
		$data = get_post_meta( $attachment_id, Slash_Image_Media_Handler::META_DATA_KEY, true );
		if ( ! is_array( $data ) || empty( $data['optimized'] ) ) {
			return false;
		}
		// Data we wrote ourselves has no migration provenance.
		return '' === (string) get_post_meta( $attachment_id, self::META_MIGRATED_FROM, true );
	}

	/**
	 * True when this attachment has already been migrated (from any source).
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return bool
	 */
	public static function is_already_migrated( $attachment_id ) {
		return '' !== (string) get_post_meta( $attachment_id, self::META_MIGRATED_FROM, true );
	}

	/**
	 * Whether the attachment's MIME is inside the allowlist the stats bundle
	 * aggregates over. An attachment outside it can be given postmeta but will
	 * never appear in the stats totals, so migrating it would make the reported
	 * counts irreconcilable — skip and report instead.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return bool
	 */
	public static function is_countable_mime( $attachment_id ) {
		return in_array(
			(string) get_post_mime_type( $attachment_id ),
			Slash_Image_Api_Client::SUPPORTED_MIME_TYPES,
			true
		);
	}

	/**
	 * Resolve a slug to a usable adapter, applying the global refusals and the
	 * source's own detection. Shared by run() and run_batch() so a refusal reads
	 * identically whichever drives it.
	 *
	 * @param string $slug Adapter slug.
	 * @return array ['ok' => bool, 'code' => string, 'message' => string, 'adapter' => string]
	 */
	public static function resolve( $slug ) {
		$fail = function ( $code, $message ) {
			return array(
				'ok'      => false,
				'code'    => $code,
				'message' => $message,
				'adapter' => '',
			);
		};

		$pre = self::preflight();
		if ( empty( $pre['ok'] ) ) {
			return $fail( $pre['code'], $pre['message'] );
		}

		$adapter = self::adapter_for( $slug );
		if ( '' === $adapter ) {
			return $fail(
				'unknown_source',
				__( 'That migration source is not available.', 'slashimage-image-optimizer' )
			);
		}

		$detect = call_user_func( array( $adapter, 'detect' ) );
		if ( empty( $detect['ok'] ) ) {
			return $fail(
				(string) ( $detect['code'] ?? 'undetected' ),
				(string) ( $detect['message'] ?? '' )
			);
		}

		return array(
			'ok'      => true,
			'code'    => '',
			'message' => '',
			'adapter' => $adapter,
		);
	}

	/**
	 * Run exactly ONE batch and return where it stopped.
	 *
	 * The entry point for request-per-batch drivers (the Tools screen): a single
	 * browser request does one bounded slice of work and hands the cursor back,
	 * so no request can approach a proxy timeout however large the library is.
	 *
	 * The CALLER owns the cursor here — this deliberately does not read or write
	 * CURSOR_OPTION. The browser threads the cursor through its own request
	 * chain, and persisting it would let two concurrent drivers (a CLI run and
	 * an open Tools tab) overwrite each other's position.
	 *
	 * @param string $slug    Adapter slug.
	 * @param int    $cursor  Exclusive attachment-ID cursor; 0 starts at the beginning.
	 * @param bool   $dry_run Scan only; write nothing.
	 * @param int    $batch   Attachments to examine in this batch.
	 * @return array ['ok','code','message','cursor','done','stats']
	 */
	public static function run_batch( $slug, $cursor = 0, $dry_run = false, $batch = self::DEFAULT_BATCH_SIZE ) {
		$resolved = self::resolve( $slug );
		if ( empty( $resolved['ok'] ) ) {
			return array(
				'ok'      => false,
				'code'    => $resolved['code'],
				'message' => $resolved['message'],
				'cursor'  => (int) $cursor,
				'done'    => true,
				'stats'   => self::stat_keys(),
			);
		}

		$result = call_user_func(
			array( $resolved['adapter'], 'migrate_batch' ),
			(int) $cursor,
			max( 1, (int) $batch ),
			(bool) $dry_run
		);

		$stats = self::stat_keys();
		foreach ( $stats as $key => $unused ) {
			if ( isset( $result['stats'][ $key ] ) ) {
				$stats[ $key ] = (int) $result['stats'][ $key ];
			}
		}

		self::note_foreign_variants( $stats, (bool) $dry_run );
		self::flush_object_cache();

		return array(
			'ok'      => true,
			'code'    => '',
			'message' => '',
			'cursor'  => (int) ( $result['cursor'] ?? $cursor ),
			'done'    => ! empty( $result['done'] ),
			'stats'   => $stats,
		);
	}

	/**
	 * Run a migration to completion, batching by attachment ID.
	 *
	 * Shares the adapter's migrate_batch() call with run_batch() but does NOT
	 * delegate to it — the two drivers each drive the adapter directly. Edit
	 * one and the other is unaffected; anything that must hold for both belongs
	 * in the adapter or in a helper both call.
	 *
	 * Unlike run_batch(), this OWNS the cursor: a killed CLI run resumes where
	 * it stopped, and its slug's entry is dropped on completion.
	 *
	 * @param string        $slug      Adapter slug.
	 * @param bool          $dry_run   Scan only; write nothing.
	 * @param int           $batch     Attachments per batch.
	 * @param callable|null $on_batch  Optional progress callback, receives the
	 *                                 batch's 'scanned' count.
	 * @return array ['ok' => bool, 'code' => string, 'message' => string, 'stats' => array]
	 */
	public static function run( $slug, $dry_run = false, $batch = self::DEFAULT_BATCH_SIZE, $on_batch = null ) {
		$stats = self::stat_keys();

		// Resolve once up front rather than per batch: detect() costs a COUNT
		// query, and a long run would otherwise repeat it for every batch.
		$resolved = self::resolve( $slug );
		if ( empty( $resolved['ok'] ) ) {
			return array(
				'ok'      => false,
				'code'    => $resolved['code'],
				'message' => $resolved['message'],
				'stats'   => $stats,
			);
		}

		$batch = max( 1, (int) $batch );

		// Resume a killed run of THIS source. Dry runs never read or write the
		// cursor: a scan must always report on the whole library.
		$cursor = $dry_run ? 0 : self::cursor_for( $slug );

		while ( true ) {
			$result = call_user_func( array( $resolved['adapter'], 'migrate_batch' ), $cursor, $batch, $dry_run );

			foreach ( $stats as $key => $unused ) {
				if ( isset( $result['stats'][ $key ] ) ) {
					$stats[ $key ] += (int) $result['stats'][ $key ];
				}
			}

			// Per batch, not once at the end, so a run that is killed part-way
			// still leaves the flag set for the attachments it did import.
			self::note_foreign_variants(
				isset( $result['stats'] ) ? (array) $result['stats'] : array(),
				$dry_run
			);

			if ( is_callable( $on_batch ) ) {
				call_user_func( $on_batch, (int) ( $result['stats']['scanned'] ?? 0 ) );
			}

			$cursor = (int) ( $result['cursor'] ?? $cursor );

			if ( ! $dry_run ) {
				self::set_cursor( $slug, $cursor );
			}

			// Keep the object cache from growing across a long run: every batch
			// primes post + meta caches that are never read again.
			self::flush_object_cache();

			if ( ! empty( $result['done'] ) ) {
				break;
			}
		}

		if ( ! $dry_run ) {
			self::clear_cursor( $slug );
			self::mark_complete( $slug, (int) call_user_func( array( $resolved['adapter'], 'count' ) ) );
		}

		return array(
			'ok'      => true,
			'code'    => '',
			'message' => '',
			'stats'   => $stats,
		);
	}

	/**
	 * The whole cursor map, normalised.
	 *
	 * Anything that is not an array is discarded and reported as no positions
	 * held. That is what retires the pre-1.2.0-dev scalar: a site upgrading
	 * mid-run loses one resume position and re-walks that source from the
	 * start, which costs time but cannot skip an attachment. The check MUST
	 * come before any (int) cast — casting an array yields 1, which would
	 * resume just past ID 1 and silently skip nothing visible.
	 *
	 * @return array<string, int>
	 */
	private static function cursors() {
		$all = get_option( self::CURSOR_OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Resume position for one source, 0 when it holds none.
	 *
	 * @param string $slug Adapter slug.
	 * @return int
	 */
	private static function cursor_for( $slug ) {
		$all = self::cursors();
		return isset( $all[ (string) $slug ] ) ? (int) $all[ (string) $slug ] : 0;
	}

	/**
	 * Store one source's resume position, leaving every other source's alone.
	 *
	 * Read-modify-write, so a second driver working a different source keeps
	 * its position. Two drivers on the SAME source still last-write-wins, which
	 * is unchanged from the scalar behaviour and is why run_batch() continues
	 * to leave the cursor to its caller.
	 *
	 * @param string $slug   Adapter slug.
	 * @param int    $cursor Exclusive attachment-ID position.
	 * @return void
	 */
	private static function set_cursor( $slug, $cursor ) {
		$all                   = self::cursors();
		$all[ (string) $slug ] = (int) $cursor;
		update_option( self::CURSOR_OPTION, $all, false );
	}

	/**
	 * Drop one source's resume position on completion.
	 *
	 * Deliberately NOT delete_option() on the whole map — that would wipe an
	 * unrelated source's in-progress position. The option itself is removed
	 * only once the last entry goes, so a finished site leaves nothing behind.
	 *
	 * @param string $slug Adapter slug.
	 * @return void
	 */
	private static function clear_cursor( $slug ) {
		$all = self::cursors();
		unset( $all[ (string) $slug ] );

		if ( empty( $all ) ) {
			delete_option( self::CURSOR_OPTION );
			return;
		}

		update_option( self::CURSOR_OPTION, $all, false );
	}

	/**
	 * Record that a walk reached the end of a source.
	 *
	 * Completion is a fact about the WALK, not about arithmetic: an attachment
	 * whose file is gone, or whose source rows carry no usable figures, is
	 * examined and permanently skipped, so a finished site keeps a standing gap
	 * between the source's count and ours. Comparing the two would mark such a
	 * site incomplete for good and keep offering a migration that can never
	 * close. The adapter's own done flag ("the source returned a short batch")
	 * is the only signal that means what it says, and until now it was consumed
	 * by the client and thrown away.
	 *
	 * The source count is stored alongside so the record can go stale honestly:
	 * if the other plugin optimizes more images afterwards, is_complete() sees
	 * the larger count and reports work outstanding again.
	 *
	 * @param string $slug         Adapter slug.
	 * @param int    $source_total The source's count() at the moment of completion.
	 * @return void
	 */
	public static function mark_complete( $slug, $source_total ) {
		$all = get_option( self::COMPLETED_OPTION, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}

		$all[ (string) $slug ] = array(
			'at'    => time(),
			'total' => (int) $source_total,
		);

		update_option( self::COMPLETED_OPTION, $all, false );
	}

	/**
	 * Whether a source has been walked to the end and has gained nothing since.
	 *
	 * A site migrated before this record existed reports false, which shows the
	 * partially-migrated card rather than the done card. That is the safe way
	 * round — it withholds the deactivate button rather than offering it wrongly
	 * — and a single scan finding nothing left to import restores the full
	 * done-state affordances.
	 *
	 * @param string $slug         Adapter slug.
	 * @param int    $source_total The source's current count().
	 * @return bool
	 */
	public static function is_complete( $slug, $source_total ) {
		$all = get_option( self::COMPLETED_OPTION, array() );
		if ( ! is_array( $all ) || ! isset( $all[ (string) $slug ] ) ) {
			return false;
		}

		$entry = $all[ (string) $slug ];
		if ( ! is_array( $entry ) ) {
			return false;
		}

		return (int) $source_total <= (int) ( $entry['total'] ?? 0 );
	}

	/**
	 * Per-source done-state, derived from the provenance meta rather than from
	 * anything a client reports: count of attachments migrated from each source
	 * and when the most recent one was migrated.
	 *
	 * @return array slug => ['count' => int, 'last_at' => int]
	 */
	public static function migrated_summary() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS source, COUNT(*) AS total,
				        COALESCE( MAX( CAST( at.meta_value AS UNSIGNED ) ), 0 ) AS last_at
				   FROM {$wpdb->postmeta} pm
				   LEFT JOIN {$wpdb->postmeta} at
				          ON at.post_id = pm.post_id AND at.meta_key = %s
				  WHERE pm.meta_key = %s
				  GROUP BY pm.meta_value",
				self::META_MIGRATED_AT,
				self::META_MIGRATED_FROM
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['source'] ] = array(
				'count'   => (int) $row['total'],
				'last_at' => (int) $row['last_at'],
			);
		}
		return $out;
	}

	/**
	 * Flag this site as holding next-gen variants under another optimizer's
	 * filename, so the front-end resolver enables its single-extension fallback.
	 *
	 * Site-level and one-way: it records a fact about the FILES ON DISK, which
	 * outlives the other plugin being deactivated or deleted, so it is never
	 * derived from is_plugin_active() and is never cleared when that plugin
	 * goes away. Only '_linked' counts — '_already_present' means the variant
	 * sits at our own name, which needs no fallback.
	 *
	 * A dry run never sets it: a scan writes nothing.
	 *
	 * @param array $stats   Stats from one batch.
	 * @param bool  $dry_run Scan only.
	 * @return void
	 */
	private static function note_foreign_variants( array $stats, $dry_run ) {
		if ( $dry_run ) {
			return;
		}

		$foreign = (int) ( $stats['webp_linked'] ?? 0 ) + (int) ( $stats['avif_linked'] ?? 0 );
		if ( $foreign < 1 ) {
			return;
		}

		$option = Slash_Image_Variant_Resolver::FOREIGN_VARIANTS_OPTION;
		if ( '1' === (string) get_option( $option, '' ) ) {
			return;
		}

		// Autoloaded: the resolver reads it on every front-end request.
		update_option( $option, '1', true );
	}

	/**
	 * Drop the per-batch object-cache churn. Uses the public WordPress helper
	 * when available; on a persistent object cache this is a no-op by design
	 * (that cache has its own eviction) and the run simply relies on it.
	 */
	private static function flush_object_cache() {
		global $wpdb, $wp_object_cache;

		if ( is_object( $wpdb ) && isset( $wpdb->queries ) && is_array( $wpdb->queries ) ) {
			$wpdb->queries = array();
		}

		if ( wp_using_ext_object_cache() ) {
			return;
		}

		if ( is_object( $wp_object_cache ) && isset( $wp_object_cache->cache ) ) {
			$wp_object_cache->cache = array();
		}
	}
}
