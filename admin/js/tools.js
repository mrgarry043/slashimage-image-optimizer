/**
 * Tools tab — migrate from another optimizer.
 *
 * Populates the Tools panel on first activation (nothing is rendered
 * server-side, so the settings page pays no detection cost until the tab is
 * opened), then drives the scan and migration one batch per request. Each
 * response carries the cursor, so a library of any size completes without a
 * single long request.
 */
( function () {
	'use strict';

	var cfg = window.SlashImageTools || {};
	var i18n = cfg.i18n || {};

	var root = null;
	var loaded = false;
	/** Per-source client state: cursor, accumulated stats, scan token. */
	var runs = {};

	/**
	 * Per-request ceiling. A batch is bounded at 200 attachments and measures
	 * well under two seconds, so this is roughly ninety times the observed cost —
	 * high enough never to cut short a slow host mid-batch, and above the common
	 * 60-second gateway default so the server's own error surfaces first. It
	 * exists only so a connection that is dropped without any response ends in a
	 * visible message instead of a progress bar that never moves again.
	 */
	var REQUEST_TIMEOUT_MS = 120000;

	function t( key, fallback ) {
		return Object.prototype.hasOwnProperty.call( i18n, key ) ? i18n[ key ] : fallback;
	}

	/** Escape anything interpolated into markup — card text is server data. */
	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	/**
	 * POST one action. Never rejects: every failure resolves to a result object
	 * so a caller can render it. A rejected promise here used to escape
	 * unhandled, leaving the card stuck on its progress bar with no message.
	 *
	 * `transport` separates "the request never completed" (dropped connection,
	 * timeout, or a server-side fatal whose body is not JSON) from "the server
	 * answered and refused", so only the latter shows a server-supplied message.
	 */
	function post( action, data ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', cfg.nonce || '' );
		Object.keys( data || {} ).forEach( function ( k ) {
			body.set( k, data[ k ] );
		} );

		var controller = ( 'undefined' !== typeof AbortController ) ? new AbortController() : null;
		var timer = controller
			? window.setTimeout( function () { controller.abort(); }, REQUEST_TIMEOUT_MS )
			: null;

		var opts = {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		};
		if ( controller ) { opts.signal = controller.signal; }

		return fetch( cfg.ajax_url, opts ).then( function ( r ) {
			return r.json().then(
				function ( j ) {
					return {
						ok: r.ok && j && j.success,
						payload: ( j && j.data ) || {},
						transport: false
					};
				},
				function () {
					// Body was not JSON — a server-side fatal, not a clean refusal.
					return { ok: false, payload: {}, transport: true };
				}
			);
		} ).catch( function () {
			// Network failure, or our own abort once REQUEST_TIMEOUT_MS elapsed.
			return { ok: false, payload: {}, transport: true };
		} ).then( function ( res ) {
			if ( timer ) { window.clearTimeout( timer ); }
			return res;
		} );
	}

	/** Turn a failed result into something a person can act on. */
	function messageFor( res ) {
		if ( res.transport ) {
			return t( 'failed_network', 'Could not reach the server. Check your connection and try again.' );
		}
		if ( res.payload && res.payload.message ) {
			return res.payload.message;
		}
		if ( res.payload && 'scan_required' === res.payload.code ) {
			return t( 'failed_scan_required', 'That scan has expired. Please scan again before migrating.' );
		}
		return t( 'failed', 'That did not work. Please reload and try again.' );
	}

	function blankStats() {
		return {
			scanned: 0, migrated: 0, already_ours: 0, already_migrated: 0,
			skipped_status: 0, skipped_restored: 0, skipped_already_optimized: 0,
			skipped_unsupported_mime: 0, skipped_no_usable_rows: 0, skipped_file_missing: 0,
			webp_linked: 0, avif_linked: 0, webp_already_present: 0, avif_already_present: 0,
			webp_missing: 0, avif_missing: 0, sentinel_skipped: 0
		};
	}

	function addStats( into, from ) {
		Object.keys( into ).forEach( function ( k ) {
			into[ k ] += parseInt( from[ k ], 10 ) || 0;
		} );
		return into;
	}

	/* ── Rendering ──────────────────────────────────── */

	function statRow( label, value, modifier ) {
		return '<div class="slash-image-tools__stat' + ( modifier ? ' ' + modifier : '' ) +
			'"><span>' + esc( label ) + '</span><strong>' + esc( value ) + '</strong></div>';
	}

	function statHeading( label ) {
		return '<div class="slash-image-tools__stat-heading">' + esc( label ) + '</div>';
	}

	/**
	 * Two groups, two denominators — labelled rather than run together, because
	 * reading them as one list makes the figures look like they do not add up.
	 *
	 * The image rows are the mutually exclusive outcomes of examining ONE
	 * attachment, so they reconcile exactly to `scanned`, which is printed first
	 * so that is checkable. Every outcome migrate_one() can return has a row:
	 * omitting one (skipped_no_usable_rows was missing) silently loses images
	 * from the total.
	 *
	 * The file rows count individual WebP/AVIF siblings across every size of
	 * every image — a different and much larger denominator. They are not
	 * expected to sum to `scanned` and must not be read as if they were.
	 */
	function scanReport( s ) {
		var html = '<div class="slash-image-tools__report">';
		html += statRow( t( 'stat_scanned', 'Images examined' ), s.scanned, 'is-total' );
		html += statRow( t( 'stat_importable', 'Importable' ), s.migrated );
		html += statRow( t( 'stat_already_migrated', 'Already migrated' ), s.already_migrated );
		html += statRow( t( 'stat_already_ours', 'Already optimized by SlashImage' ), s.already_ours );
		html += statRow( t( 'stat_skipped_status', 'Skipped — not importable' ), s.skipped_status );
		if ( s.skipped_restored > 0 ) {
			html += statRow( t( 'stat_skipped_restored', '— of which reverted' ), s.skipped_restored );
		}
		if ( s.skipped_already_optimized > 0 ) {
			html += statRow( t( 'stat_skipped_already', '— of which already small enough' ), s.skipped_already_optimized );
		}
		html += statRow( t( 'stat_skipped_mime', 'Skipped — unsupported type' ), s.skipped_unsupported_mime );
		html += statRow( t( 'stat_skipped_missing', 'Skipped — file missing' ), s.skipped_file_missing );
		html += statRow( t( 'stat_skipped_no_data', 'Skipped — no usable data' ), s.skipped_no_usable_rows );

		html += statHeading( t( 'stat_heading_files', 'WebP and AVIF files' ) );
		html += statRow( t( 'stat_webp', 'WebP files to serve' ), s.webp_linked + s.webp_already_present );
		if ( s.avif_linked + s.avif_already_present > 0 ) {
			html += statRow( t( 'stat_avif', 'AVIF files to serve' ), s.avif_linked + s.avif_already_present );
		}
		if ( s.webp_missing + s.avif_missing > 0 ) {
			html += statRow( t( 'stat_missing_disk', 'Recorded but missing on disk' ), s.webp_missing + s.avif_missing );
		}
		if ( s.sentinel_skipped > 0 ) {
			html += statRow( t( 'stat_sentinel', 'Skipped — no smaller version existed' ), s.sentinel_skipped );
		}
		html += '</div>';
		return html;
	}

	/** Shared markup: the neutral "why deactivate" notice, then the button. */
	function deactivateNotice() {
		return '<div class="slash-image-alert slash-image-alert--inline">' +
			esc( t( 'note_deactivate',
				'Two active optimizers both rewrite front-end markup, which can double-wrap images. Now that the data is imported, this plugin can be turned off.' ) ) +
			'</div>';
	}

	function deactivateButton( card ) {
		return '<button type="button" class="slash-image-btn slash-image-btn--danger-outline" data-act="deactivate" data-source="' + esc( card.source ) + '">' +
			esc( ( t( 'btn_deactivate', 'Deactivate %s' ) ).replace( '%s', card.label ) ) + '</button>';
	}

	function noBackupNote() {
		// No forward-promise here on purpose: there is currently no path that
		// turns a migrated image back into a fully-ours one (plain optimize skips
		// it as already optimized, --force skips it for having no backup). This
		// copy changes when backup import ships.
		return '<p class="slash-image-tools__note">' + esc( t( 'note_no_backup',
			'Migrated images have no SlashImage backup, so Restore and forced re-optimize are not available for them.' ) ) + '</p>';
	}

	function cardMarkup( card ) {
		var st = runs[ card.source ] || {};
		var body = '';
		var badge, badgeClass, badgeIcon = '';

		// `complete` is the server's record of a walk that reached the end of the
		// source. A migrated count alone only says work happened — never that it
		// finished — so every done-state affordance hangs off this, not off the
		// count. Deactivating the source mid-migration strips the data still to
		// be imported, and on ShortPixel invites the "Remove all data" tool that
		// deletes the very files we now serve.
		var isComplete = card.migrated > 0 && !! card.complete;
		var isPartial = card.migrated > 0 && ! card.complete;

		if ( isComplete && ! st.stats ) {
			badge = t( 'badge_migrated', 'Migrated' );
			badgeClass = 'is-done';
			// The check carries the meaning for anyone who does not read the
			// label; the text stays for screen readers and translation.
			badgeIcon = '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>';
		} else if ( isPartial && ! st.stats ) {
			badge = t( 'badge_partial', 'Partly migrated' );
			badgeClass = 'is-partial';
		} else if ( card.detected ) {
			badge = t( 'badge_detected', 'Detected' );
			badgeClass = 'is-detected';
		} else {
			badge = t( 'badge_none', 'Not detected' );
			badgeClass = 'is-none';
		}

		body += '<div class="slash-image-tools__card-head">';
		body += '<h3>' + esc( card.label ) + '</h3>';
		body += '<span class="slash-image-tools__badge ' + badgeClass + '">' + badgeIcon + esc( badge ) + '</span>';
		body += '</div>';

		if ( ! card.detected && ! card.migrated ) {
			body += '<p class="slash-image-tools__muted">' + esc( card.message || t( 'none_found', 'No optimization data found.' ) ) + '</p>';
			return body;
		}

		// Rendered here rather than beside the scan report, because several card
		// states return early below and a failure has to be visible in all of them.
		if ( st.error ) {
			body += '<div class="slash-image-alert is-error slash-image-alert--inline">' + esc( st.error ) + '</div>';
		}

		// Done state — the source was walked to the end, nothing left to import.
		if ( isComplete && ! st.stats && ! st.busy ) {
			body += '<p class="slash-image-tools__done">' + esc(
				( t( 'done_summary', '%1$d images migrated from %2$s on %3$s.' ) )
					.replace( '%1$d', card.migrated )
					.replace( '%2$s', card.label )
					.replace( '%3$s', card.migrated_at )
			) + '</p>';
			body += noBackupNote();
			if ( card.plugin_file ) { body += deactivateNotice(); }
			body += '<div class="slash-image-tools__actions">';
			body += '<button type="button" class="slash-image-btn slash-image-btn--soft" data-act="scan" data-source="' + esc( card.source ) + '">' + esc( t( 'btn_rescan', 'Scan again' ) ) + '</button>';
			if ( card.plugin_file ) { body += deactivateButton( card ); }
			body += '</div>';
			return body;
		}

		// ShortPixel only — its "Remove all data" tool deletes the very files we
		// serve. Deliberately rendered ABOVE the partially-migrated block below,
		// so it survives into that state: a half-migrated site is exactly where
		// that tool does the most damage, and the done state is the only one
		// entitled to replace this warning with the deactivate notice.
		if ( card.detected && 'shortpixel' === card.source ) {
			body += '<div class="slash-image-alert is-warning slash-image-alert--inline">' +
				'<strong>' + esc( t( 'warn_title', 'Before you migrate' ) ) + '</strong> ' +
				esc( t( 'warn_shortpixel',
					'Keep ShortPixel installed until this finishes. Afterwards uninstall it normally — do NOT use its "Remove all data" tool, which deletes the WebP and AVIF files SlashImage links to.' ) ) +
				'</div>';
		}
		if ( card.detected && 'imagify' === card.source ) {
			body += '<p class="slash-image-tools__muted">' + esc( t( 'note_imagify',
				'Imagify\'s own uninstall leaves its optimization data and image files in place, so no special order is needed.' ) ) + '</p>';
		}

		// Partially-migrated state — work has happened but the source was never
		// walked to the end, usually because the tab was closed mid-run. Reports
		// the two counts side by side and routes back into the scan, and offers
		// NO deactivation: the source still holds data we have not imported.
		if ( isPartial && ! st.stats && ! st.busy ) {
			body += '<p class="slash-image-tools__done">' + esc(
				( t( 'partial_summary', '%1$d of %2$d images migrated from %3$s so far.' ) )
					.replace( '%1$d', card.migrated )
					.replace( '%2$d', card.total )
					.replace( '%3$s', card.label )
			) + '</p>';
			body += '<p class="slash-image-tools__note">' + esc( t( 'note_partial',
				'This migration has not finished. Scan again to see what is left, then choose Migrate now to carry on.' ) ) + '</p>';
			body += '<div class="slash-image-tools__actions">';
			body += '<button type="button" class="slash-image-btn slash-image-btn--primary" data-act="scan" data-source="' + esc( card.source ) + '">' +
				esc( t( 'btn_rescan', 'Scan again' ) ) + '</button>';
			body += '</div>';
			return body;
		}

		// How much data the source HAS. Deliberately makes no importability
		// claim: only a scan can tell how much of it we can actually import,
		// and most of it may already be migrated.
		if ( card.detected ) {
			body += '<p>' + esc(
				( t( 'detected_count', '%1$d images with %2$s optimization data.' ) )
					.replace( '%1$d', card.total )
					.replace( '%2$s', card.label )
			) + '</p>';
		}

		if ( st.busy ) {
			var pct = card.total > 0 ? Math.min( 100, Math.round( ( st.stats.scanned / card.total ) * 100 ) ) : 0;
			body += '<div class="slash-image-tools__progress"><div style="width:' + pct + '%"></div></div>';
			body += '<p class="slash-image-tools__muted">' + esc(
				( t( 'progress', '%1$d of %2$d examined' ) ).replace( '%1$d', st.stats.scanned ).replace( '%2$d', card.total )
			) + '</p>';
			return body;
		}

		// A completed scan that found nothing to import means everything here is
		// already migrated or already ours — so the plugin is safe to turn off,
		// same as the done state. With work outstanding, Migrate now is the
		// primary action and deactivating would strip the source mid-job.
		//
		// This is also the recovery path for a site migrated before the
		// completion record existed: it has no record, so it shows as partly
		// migrated, and one scan finding nothing left restores the full
		// done-state affordances without needing a backfill.
		//
		// A FAILED run must never qualify: its stats are still the zeroed
		// starting set, which would otherwise read as "nothing to import" and
		// offer to deactivate the very plugin whose data has not been imported.
		var scanFoundNothing = !! st.stats && ! st.error && 0 === st.stats.migrated;

		// Counts from an interrupted run are not a result worth showing.
		if ( st.stats && ! st.error ) {
			body += scanReport( st.stats );
			body += noBackupNote();
		}
		if ( scanFoundNothing && card.plugin_file ) { body += deactivateNotice(); }

		body += '<div class="slash-image-tools__actions">';
		body += '<button type="button" class="slash-image-btn slash-image-btn--soft" data-act="scan" data-source="' + esc( card.source ) + '">' +
			esc( st.stats ? t( 'btn_rescan', 'Scan again' ) : t( 'btn_scan', 'Scan' ) ) + '</button>';
		// "Migrate now" appears only after a completed scan that found work.
		if ( st.token && st.stats && st.stats.migrated > 0 ) {
			body += '<button type="button" class="slash-image-btn slash-image-btn--primary" data-act="run" data-source="' + esc( card.source ) + '">' +
				esc( t( 'btn_migrate', 'Migrate now' ) ) + '</button>';
		}
		if ( scanFoundNothing && card.plugin_file ) { body += deactivateButton( card ); }
		body += '</div>';

		return body;
	}

	var cards = [];

	function render() {
		if ( ! cards.length ) {
			root.innerHTML = '<p class="slash-image-tools__muted">' +
				esc( t( 'empty', 'No other image optimizer was found on this site. Nothing to migrate.' ) ) + '</p>';
			return;
		}
		var anything = cards.some( function ( c ) { return c.detected || c.migrated > 0; } );
		if ( ! anything ) {
			root.innerHTML = '<p class="slash-image-tools__muted">' +
				esc( t( 'empty', 'No other image optimizer was found on this site. Nothing to migrate.' ) ) + '</p>';
			return;
		}
		root.innerHTML = cards.map( function ( c ) {
			return '<div class="slash-image-tools__card" data-source="' + esc( c.source ) + '">' + cardMarkup( c ) + '</div>';
		} ).join( '' );
	}

	function cardFor( source ) {
		for ( var i = 0; i < cards.length; i++ ) {
			if ( cards[ i ].source === source ) { return cards[ i ]; }
		}
		return null;
	}

	/* ── Batch loops ────────────────────────────────── */

	function loop( source, action ) {
		var st = runs[ source ];
		return post( action, {
			source: source,
			cursor: st.cursor,
			token: st.token || ''
		} ).then( function ( res ) {
			if ( ! res.ok ) {
				st.busy = false;
				st.error = messageFor( res );
				render();
				return;
			}
			st.cursor = res.payload.cursor;
			addStats( st.stats, res.payload.stats || {} );
			render();

			if ( res.payload.done ) {
				st.busy = false;
				if ( res.payload.token ) { st.token = res.payload.token; }
				if ( res.payload.migrated ) {
					var c = cardFor( source );
					if ( c ) {
						c.migrated = res.payload.migrated.count;
						c.migrated_at = res.payload.migrated.date;
						// ajax_run recorded the completed walk before answering,
						// so the card must adopt it here too — otherwise a run
						// that just finished falls back to the partial state on
						// the stale `complete` from the initial detect.
						c.complete = true;
					}
					// A completed migration returns the card to its done state.
					st.stats = null;
					st.token = '';
				}
				render();
				return;
			}
			return loop( source, action );
		} );
	}

	function start( source, action ) {
		runs[ source ] = { cursor: 0, stats: blankStats(), busy: true, error: '', token: runs[ source ] ? runs[ source ].token : '' };
		if ( 'slash_image_migrate_scan' === action ) { runs[ source ].token = ''; }
		render();
		// post() resolves on every failure, so this only fires if our own
		// rendering throws. Without it such a throw leaves busy === true and the
		// card sits on its progress bar for good.
		loop( source, action ).catch( function () {
			var st = runs[ source ];
			if ( ! st ) { return; }
			st.busy = false;
			st.error = t( 'failed', 'That did not work. Please reload and try again.' );
			render();
		} );
	}

	/**
	 * Confirm a deactivation on the card, then remove it — cards render only for
	 * ACTIVE plugins, so this one no longer qualifies. Falls back to the empty
	 * state when it was the last card.
	 */
	function retireCard( source, label ) {
		var el = root.querySelector( '.slash-image-tools__card[data-source="' + source + '"]' );
		cards = cards.filter( function ( c ) { return c.source !== source; } );
		delete runs[ source ];

		if ( ! el ) { render(); return; }

		el.innerHTML = '<p class="slash-image-tools__deactivated">' +
			esc( ( t( 'deactivated', '%s deactivated. Its imported data stays with SlashImage.' ) ).replace( '%s', label ) ) +
			'</p>';
		el.classList.add( 'is-retiring' );

		window.setTimeout( function () {
			// Re-render from the trimmed card list, which also restores the empty
			// state if nothing is left.
			render();
		}, 2200 );
	}

	/* ── Wiring ─────────────────────────────────────── */

	function load() {
		if ( loaded ) { return; }
		loaded = true;
		post( 'slash_image_migrate_detect', {} ).then( function ( res ) {
			if ( ! res.ok ) {
				root.innerHTML = '<div class="slash-image-alert is-error">' + esc( messageFor( res ) ) + '</div>';
				// Let the user try again without reloading the whole page.
				loaded = false;
				return;
			}
			cards = res.payload.cards || [];
			render();
		} ).catch( function () {
			root.innerHTML = '<div class="slash-image-alert is-error">' +
				esc( t( 'failed', 'That did not work. Please reload and try again.' ) ) + '</div>';
			loaded = false;
		} );
	}

	function init() {
		root = document.getElementById( 'slash-image-tools-cards' );
		if ( ! root ) { return; }

		root.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '[data-act]' );
			if ( ! btn ) { return; }
			var source = btn.dataset.source;
			var act = btn.dataset.act;

			if ( 'scan' === act ) { start( source, 'slash_image_migrate_scan' ); }
			if ( 'run' === act ) { start( source, 'slash_image_migrate_run' ); }
			if ( 'deactivate' === act ) {
				var c = cardFor( source );
				if ( ! window.confirm( ( t( 'confirm_deactivate', 'Deactivate %s now?' ) ).replace( '%s', c ? c.label : source ) ) ) { return; }
				btn.disabled = true;
				post( 'slash_image_migrate_deactivate', { source: source } ).catch( function () {
					return { ok: false, payload: {}, transport: true };
				} ).then( function ( res ) {
					if ( ! res.ok ) {
						btn.disabled = false;
						var failed = cardFor( source );
						if ( failed ) {
							runs[ source ] = runs[ source ] || {};
							runs[ source ].error = messageFor( res );
							render();
						}
						return;
					}
					// The card is about to stop qualifying for display (cards are
					// active-plugins-only), so retire it here rather than leaving a
					// dead card behind or forcing a full page reload. Brief
					// confirmation first, so the action does not just vanish.
					retireCard( source, c ? c.label : source );
				} );
			}
		} );

		document.addEventListener( 'slashimage:tab', function ( e ) {
			if ( e.detail && 'tools' === e.detail.tab ) { load(); }
		} );

		// Direct link / reload straight onto #tools.
		if ( '#tools' === window.location.hash ) { load(); }
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
