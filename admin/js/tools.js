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

	function t( key, fallback ) {
		return Object.prototype.hasOwnProperty.call( i18n, key ) ? i18n[ key ] : fallback;
	}

	/** Escape anything interpolated into markup — card text is server data. */
	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function post( action, data ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', cfg.nonce || '' );
		Object.keys( data || {} ).forEach( function ( k ) {
			body.set( k, data[ k ] );
		} );
		return fetch( cfg.ajax_url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( r ) {
			return r.json().then( function ( j ) {
				return { ok: r.ok && j && j.success, payload: ( j && j.data ) || {} };
			} );
		} );
	}

	function blankStats() {
		return {
			scanned: 0, migrated: 0, already_ours: 0, already_migrated: 0,
			skipped_status: 0, skipped_restored: 0, skipped_already_optimized: 0,
			skipped_unsupported_mime: 0, skipped_no_usable_rows: 0, skipped_file_missing: 0,
			webp_linked: 0, avif_linked: 0, webp_already_present: 0, avif_already_present: 0,
			webp_missing: 0, avif_missing: 0, sentinel_skipped: 0, link_failed: 0
		};
	}

	function addStats( into, from ) {
		Object.keys( into ).forEach( function ( k ) {
			into[ k ] += parseInt( from[ k ], 10 ) || 0;
		} );
		return into;
	}

	/* ── Rendering ──────────────────────────────────── */

	function statRow( label, value ) {
		return '<div class="slash-image-tools__stat"><span>' + esc( label ) +
			'</span><strong>' + esc( value ) + '</strong></div>';
	}

	function scanReport( s ) {
		var html = '<div class="slash-image-tools__report">';
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
		html += statRow( t( 'stat_webp', 'WebP files to serve' ), s.webp_linked + s.webp_already_present );
		if ( s.avif_linked + s.avif_already_present > 0 ) {
			html += statRow( t( 'stat_avif', 'AVIF files to serve' ), s.avif_linked + s.avif_already_present );
		}
		if ( s.webp_missing + s.avif_missing > 0 ) {
			html += statRow( t( 'stat_missing_disk', 'Recorded but missing on disk' ), s.webp_missing + s.avif_missing );
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

		if ( card.migrated > 0 && ! st.stats ) {
			badge = t( 'badge_migrated', 'Migrated' );
			badgeClass = 'is-done';
			// The check carries the meaning for anyone who does not read the
			// label; the text stays for screen readers and translation.
			badgeIcon = '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>';
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

		// Done state — no scan in progress and everything already imported.
		if ( card.migrated > 0 && ! st.stats && ! st.busy ) {
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

		// Pre-scan warning (ShortPixel only — its "Remove all data" tool deletes
		// the very files we link to). Shown BEFORE any migration, on the card.
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
		var scanFoundNothing = !! st.stats && 0 === st.stats.migrated;

		if ( st.stats ) {
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
				st.error = res.payload.message || t( 'failed', 'That did not work. Please reload and try again.' );
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
		runs[ source ] = { cursor: 0, stats: blankStats(), busy: true, token: runs[ source ] ? runs[ source ].token : '' };
		if ( 'slash_image_migrate_scan' === action ) { runs[ source ].token = ''; }
		render();
		loop( source, action );
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
				root.innerHTML = '<p class="slash-image-tools__muted">' +
					esc( t( 'failed', 'That did not work. Please reload and try again.' ) ) + '</p>';
				return;
			}
			cards = res.payload.cards || [];
			render();
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
				post( 'slash_image_migrate_deactivate', { source: source } ).then( function ( res ) {
					if ( ! res.ok ) { btn.disabled = false; return; }
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
