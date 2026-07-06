( function () {
	'use strict';

	if ( typeof window.SlashImageMedia === 'undefined' ) {
		return;
	}

	var cfg  = window.SlashImageMedia;
	var i18n = cfg.i18n || {};

	// Adaptive poll cadence: poll fast — every 1 s — while any cell
	// is in a transitional state (queued or processing), so finishes surface
	// smoothly rather than in 5 s bursts. When no transitional cells remain,
	// schedulePoll() stops entirely (idle = don't poll; nothing to watch).
	// Tighter polling because this is an
	// admin-only screen with a single polling tab and the poll endpoint is a
	// cheap per-cell read (no library counts / snapshot).
	var ACTIVE_POLL_MS = 1000;
	var STALE_TIMEOUT_MS = 30 * 60 * 1000;

	var pollTimer          = null;
	var lastActivityClient = Date.now();
	var pollingPaused      = false;

	// Worker-kick state. One kick request in flight at a time
	// ("sequential await" debounce). Triggers (Optimize click, watched
	// upload) that arrive while a kick is running are coalesced into a
	// single follow-up kick fired when the current one returns — so a
	// multi-file drag-drop drains without ever running two kicks in
	// parallel. Server-side concurrency safety is the queue's atomic claim,
	// not this guard; this just avoids redundant overlapping requests.
	var kickInFlight = false;
	var kickPending  = false;

	function $( id ) { return document.getElementById( id ); }
	function qsa( selector, root ) { return Array.prototype.slice.call( ( root || document ).querySelectorAll( selector ) ); }

	function findCell( id ) {
		// The cell is whatever wraps a .slash-image-col with the matching data-id.
		var inner = document.querySelector( '.slash-image-col[data-id="' + id + '"]' );
		if ( ! inner ) { return null; }
		return inner.parentNode; // <td>
	}

	function transitionalIds() {
		// Cells whose current pill is queued/processing — found by the spinner in
		// any .slash-image-col, so polling resolves on BOTH the Media Library column
		// (upload.php) and the attachment-edit meta box (post.php).
		return qsa( '.slash-image-col .slash-image-pill__spinner' )
			.map( function ( el ) {
				var inner = el.closest( '.slash-image-col' );
				return inner ? parseInt( inner.dataset.id, 10 ) : 0;
			} )
			.filter( function ( id ) { return id > 0; } );
	}

	function postForm( params ) {
		var body = new URLSearchParams();
		Object.keys( params ).forEach( function ( k ) { body.append( k, params[ k ] ); } );
		return fetch( cfg.ajax_url, {
			method:      'POST',
			credentials: 'same-origin',
			headers:     { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body:        body.toString(),
		} ).then( function ( res ) {
			return res.json().catch( function () { return { success: false }; } );
		} );
	}

	/* ── Worker kick (instant single-image + watched uploads) ── */

	// Fire one budgeted worker tick in its own request: the server processes
	// ONE attachment and returns quickly (it never holds a PHP-FPM worker for
	// the whole batch), so the status poll keeps getting served and rows
	// update smoothly throughout a run. Sequential-await debounce: never two
	// kicks in flight; triggers during a kick coalesce to one follow-up.
	function kickWorker() {
		if ( kickInFlight ) { kickPending = true; return; }
		kickInFlight = true;
		postForm( {
			action: 'slash_image_kick',
			nonce:  cfg.poll_nonce,
		} ).then( afterKick ).catch( function () { afterKick( null ); } );
	}

	function afterKick( data ) {
		kickInFlight = false;
		if ( document.hidden ) { kickPending = false; return; }
		// Re-dispatch while the server reports more queued work (the chain that
		// drains the queue one budgeted tick at a time), or if a fresh trigger
		// arrived mid-flight. Stops cleanly when the server says queue_has_more
		// is false.
		var hasMore = !! ( data && data.data && data.data.queue_has_more );
		if ( hasMore || kickPending ) {
			kickPending = false;
			kickWorker();
		}
	}

	/* ── Bulk actions ─────────────────────────────────────────────── */

	function selectedCount( form ) {
		return form.querySelectorAll( 'input[name="media[]"]:checked' ).length;
	}

	function checkedIds( form ) {
		return qsa( 'input[name="media[]"]:checked', form )
			.map( function ( el ) { return parseInt( el.value, 10 ); } )
			.filter( function ( id ) { return id > 0; } );
	}

	function chosenAction( form ) {
		var top    = form.querySelector( 'select[name="action"]' );
		var bottom = form.querySelector( 'select[name="action2"]' );
		var topV    = top    && '-1' !== top.value    ? top.value    : '';
		var bottomV = bottom && '-1' !== bottom.value ? bottom.value : '';
		return topV || bottomV;
	}

	// The server-rendered "Optimizing…" pill with the real id swapped in for the
	// placeholder, so the painted cell is byte-identical to what the server
	// returns (no flicker on the applyUpdates swap) and the poll tracks it by id.
	function processingCellHtml( id ) {
		var tpl = cfg.processing_template || '';
		if ( ! tpl ) { return ''; }
		return tpl.split( '__SLASH_IMAGE_ID__' ).join( String( id ) );
	}

	function revertCells( prior ) {
		Object.keys( prior ).forEach( function ( id ) {
			var cell = findCell( id );
			if ( cell ) { cell.innerHTML = prior[ id ]; }
		} );
	}

	// Brief, translatable failure notice. We preventDefault() the native submit,
	// so a failed AJAX must not leave a silent dead-end — pair this with reverting
	// the optimistic pills. De-duped by id so repeated failures don't stack.
	function showBulkError() {
		var msg = i18n.bulk_start_failed || '';
		if ( ! msg ) { return; }
		var existing = $( 'slash-image-bulk-error' );
		if ( existing ) { existing.remove(); }
		var wrap = document.querySelector( '.wrap' );
		if ( ! wrap ) { window.alert( msg ); return; }
		var notice = document.createElement( 'div' );
		notice.id        = 'slash-image-bulk-error';
		notice.className = 'notice notice-error';
		var p = document.createElement( 'p' );
		p.textContent = msg;
		notice.appendChild( p );
		var anchor = wrap.querySelector( '.wp-header-end' );
		if ( anchor && anchor.parentNode ) {
			anchor.parentNode.insertBefore( notice, anchor.nextSibling );
		} else {
			wrap.insertBefore( notice, wrap.firstChild );
		}
	}

	// Brief, TRANSIENT "add an API key" notice for a keyless deliberate action
	// (per-row Optimize / bulk Optimize). The global disconnected banner is the
	// persistent prompt; this is a one-shot nudge that the click wasn't a silent
	// no-op, so it auto-dismisses. De-duped by id.
	function showKeyNotice( msg ) {
		msg = msg || i18n.no_api_key || '';
		if ( ! msg ) { return; }
		var existing = $( 'slash-image-key-notice' );
		if ( existing ) { existing.remove(); }
		var wrap = document.querySelector( '.wrap' );
		if ( ! wrap ) { window.alert( msg ); return; }
		var notice = document.createElement( 'div' );
		notice.id        = 'slash-image-key-notice';
		notice.className = 'notice notice-warning';
		var p = document.createElement( 'p' );
		p.textContent = msg;
		notice.appendChild( p );
		var anchor = wrap.querySelector( '.wp-header-end' );
		if ( anchor && anchor.parentNode ) {
			anchor.parentNode.insertBefore( notice, anchor.nextSibling );
		} else {
			wrap.insertBefore( notice, wrap.firstChild );
		}
		window.setTimeout( function () {
			if ( notice.parentNode ) { notice.parentNode.removeChild( notice ); }
		}, 6000 );
	}

	// True (and shows the transient notice) when an AJAX error payload is the
	// no-key gate, so callers can suppress their generic failure message.
	function isKeyGate( data ) {
		if ( data && data.data && 'no_api_key' === data.data.code ) {
			showKeyNotice( data.data.message );
			return true;
		}
		return false;
	}

	// Progressive enhancement: enqueue the selected rows via AJAX instead of a
	// full page reload — the single-row [Optimize] flow applied to N rows. Paints
	// each cell to "Optimizing…" INSTANTLY on click (optimistic), then enqueues in
	// the background; reconciles with the authoritative server HTML on success, or
	// reverts + notifies on failure. The native handle_bulk_actions handler stays
	// as the no-JS fallback.
	function bulkEnqueue( ids ) {
		// Optimistic paint: flip each cell to "Optimizing…" now, saving prior
		// innerHTML keyed by id so a failed enqueue can be reverted.
		var prior = {};
		ids.forEach( function ( id ) {
			var cell = findCell( id );
			if ( ! cell ) { return; }
			prior[ id ] = cell.innerHTML;
			var html = processingCellHtml( id );
			if ( html ) { cell.innerHTML = html; }
		} );

		var body = new URLSearchParams();
		body.append( 'action', 'slash_image_bulk_enqueue' );
		body.append( 'nonce', cfg.poll_nonce );
		ids.forEach( function ( id ) { body.append( 'ids[]', id ); } );

		fetch( cfg.ajax_url, {
			method:      'POST',
			credentials: 'same-origin',
			headers:     { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body:        body.toString(),
		} ).then( function ( res ) {
			// Non-2xx (expired nonce, caps, server error): revert the optimistic
			// pills and surface a notice — no silent dead-end. Mirrors the poll's
			// non-2xx handling.
			if ( ! res.ok ) {
				revertCells( prior );
				showBulkError();
				return null;
			}
			return res.json().catch( function () { return { success: false }; } );
		} ).then( function ( data ) {
			if ( null === data ) { return; } // already handled (non-2xx)
			if ( ! data || ! data.success || ! data.data || ! data.data.updates ) {
				revertCells( prior );
				if ( ! isKeyGate( data ) ) { showBulkError(); }
				return;
			}
			// Reconcile: overwrite optimistic pills with authoritative per-id HTML.
			// A painted id missing from the response (shouldn't happen — we enqueue
			// every id) is left as-is for the poll to reconcile, not reverted.
			applyUpdates( data.data.updates );
			lastActivityClient = Date.now();
			pollingPaused      = false;
			kickWorker();
			schedulePoll();
		} ).catch( function () {
			revertCells( prior );
			showBulkError();
		} );
	}

	function attachBulkActions( form ) {
		if ( ! form ) { return; }
		form.addEventListener( 'submit', function ( e ) {
			var action = chosenAction( form );

			// Optimize: AJAX-enqueue with no reload. With nothing selected, fall
			// through to WP's native handling (no preventDefault).
			if ( cfg.optimize_action === action ) {
				var ids = checkedIds( form );
				if ( ids.length === 0 ) { return; }
				e.preventDefault();
				bulkEnqueue( ids );
				return;
			}

			// Restore: confirm, then native submit + redirect. No client cap — the
			// action now enqueues async restore jobs (bounded by the worker), so a
			// large selection no longer risks a request timeout.
			if ( cfg.restore_action !== action ) { return; }
			var count = selectedCount( form );
			if ( count === 0 ) { return; }
			var confirmTmpl = i18n.restore_confirm || {};
			var msg = ( ( 1 === count ? confirmTmpl.one : confirmTmpl.other ) || '' ).replace( '%d', count );
			if ( ! window.confirm( msg ) ) { e.preventDefault(); }
		} );
	}

	/* ── Polling ──────────────────────────────────────────────────── */

	// Edit-screen completion cell: "Optimized ✓" + a muted reload hint, shown
	// instead of the full detail view (the meta box's mode links + buttons were
	// server-rendered at page load and now reflect the pre-re-optimize state).
	function reoptimizeDoneNode( id ) {
		var col = document.createElement( 'div' );
		col.className = 'slash-image-col slash-image-col--reoptimized';
		col.setAttribute( 'data-id', id );
		var line = document.createElement( 'div' );
		line.className = 'slash-image-reopt-done';
		line.textContent = ( i18n.optimized || 'Optimized' ) + ' ✓';
		var reload = document.createElement( 'p' );
		reload.className = 'slash-image-reopt-reload';
		reload.textContent = i18n.reload_to_see_details || '';
		col.appendChild( line );
		col.appendChild( reload );
		return col;
	}

	function applyUpdates( updates ) {
		Object.keys( updates ).forEach( function ( id ) {
			var update = updates[ id ];
			if ( ! update || typeof update.html !== 'string' ) { return; }
			// Replace the .slash-image-col element itself (not its parent's
			// innerHTML) so the meta box's surrounding action buttons survive.
			var col = document.querySelector( '.slash-image-col[data-id="' + id + '"]' );
			if ( ! col || ! col.parentNode ) { return; }
			var fresh;
			if ( col.closest( '.slash-image-mb' ) && ! update.transitional && 'optimized' === update.kind ) {
				fresh = reoptimizeDoneNode( id );
			} else {
				var tmp = document.createElement( 'div' );
				tmp.innerHTML = update.html;
				fresh = tmp.firstElementChild;
			}
			if ( fresh ) { col.parentNode.replaceChild( fresh, col ); }
		} );
	}

	function pollOnce() {
		var ids = transitionalIds();
		if ( ids.length === 0 ) { stopPoll(); return; }

		var body = new URLSearchParams();
		body.append( 'action', 'slash_image_status_poll' );
		body.append( 'nonce', cfg.poll_nonce );
		ids.forEach( function ( id ) { body.append( 'ids[]', id ); } );

		fetch( cfg.ajax_url, {
			method:      'POST',
			credentials: 'same-origin',
			headers:     { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body:        body.toString(),
		} ).then( function ( res ) {
			// Non-2xx means the endpoint can't be reached / nonce expired /
			// caps changed. Don't infinite-loop the poll — pause until the
			// next visibility change or page reload.
			if ( ! res.ok ) {
				pollingPaused = true;
				stopPoll();
				return null;
			}
			return res.json().catch( function () { return { success: false }; } );
		} ).then( function ( data ) {
			if ( data && data.success && data.data && data.data.updates ) {
				applyUpdates( data.data.updates );
				lastActivityClient = Date.now();
			}
		} ).finally( function () {
			if ( ! pollingPaused ) { schedulePoll(); }
		} );
	}

	function schedulePoll() {
		if ( pollTimer ) { clearTimeout( pollTimer ); pollTimer = null; }
		if ( document.hidden ) { return; }
		if ( pollingPaused ) { return; }
		// No transitional cells → stop polling. Idle tier = "don't poll at all"
		// since there's nothing to watch; a fresh trigger re-arms it.
		if ( transitionalIds().length === 0 ) { return; }
		var stale = Date.now() - lastActivityClient;
		if ( stale > STALE_TIMEOUT_MS ) {
			pollingPaused = true;
			return;
		}
		// Active tier: something is queued/processing — poll fast.
		pollTimer = window.setTimeout( pollOnce, ACTIVE_POLL_MS );
	}

	function stopPoll() {
		if ( pollTimer ) { clearTimeout( pollTimer ); pollTimer = null; }
	}

	/* ── Per-attachment actions (Optimize / Retry / View details) ── */

	function handleClick( event ) {
		var target = event.target.closest( '[data-action]' );
		if ( ! target ) { return; }

		var action = target.dataset.action;

		if ( 'toggle-details' === action ) {
			event.preventDefault();
			var inner   = target.closest( '.slash-image-col' );
			var details = inner ? inner.querySelector( '.slash-image-col-details' ) : null;
			if ( ! details ) { return; }
			var expanded = details.classList.toggle( 'is-expanded' );
			target.setAttribute( 'aria-expanded', expanded ? 'true' : 'false' );
			return;
		}

		if ( 'reoptimize' === action ) {
			// Meta-box "Re-optimize as <mode>" link. Progressive enhancement over the
			// admin-post href: restore + enqueue via AJAX and repaint the cell to the
			// "Optimizing…" pill without a reload. A missing backup returns an error.
			event.preventDefault();
			target.classList.add( 'is-busy' );
			postForm( {
				action: 'slash_image_reoptimize',
				nonce:  cfg.poll_nonce,
				id:     target.dataset.id,
				mode:   target.dataset.mode || '',
			} ).then( function ( data ) {
				if ( ! data || ! data.success || ! data.data ) {
					target.classList.remove( 'is-busy' );
					if ( isKeyGate( data ) ) { return; }
					var msg = ( data && data.data && data.data.message ) || i18n.reoptimize_failed || '';
					if ( msg ) { window.alert( msg ); }
					return;
				}
				// Replace the .slash-image-col element itself — in the meta box its
				// parent also holds the mode links, so we must not innerHTML the parent.
				var col = document.querySelector( '.slash-image-col[data-id="' + data.data.id + '"]' );
				if ( col && data.data.html ) {
					var tmp = document.createElement( 'div' );
					tmp.innerHTML = data.data.html;
					var fresh = tmp.firstElementChild;
					if ( fresh ) { col.parentNode.replaceChild( fresh, col ); }
				}
				lastActivityClient = Date.now();
				pollingPaused      = false;
				kickWorker();
				schedulePoll();
			} ).catch( function () {
				target.classList.remove( 'is-busy' );
			} );
			return;
		}

		if ( 'optimize-now' === action ) {
			// Meta-box "Optimize now" (not-optimized state). Progressive enhancement
			// over the admin-post href: enqueue via the same AJAX endpoint as the
			// Media Library per-row [Optimize] and paint an "Optimizing…" pill in
			// place of the button — no full-page navigation. The not-optimized meta
			// box has no .slash-image-col yet, so we inject one; the poll then tracks
			// it by id and resolves it in place to "Optimized ✓".
			event.preventDefault();
			var mb = target.closest( '.slash-image-mb' );
			if ( ! mb ) { return; }
			var optimizeId  = target.dataset.id;
			var mbOriginal  = mb.innerHTML;
			// Optimistic: paint the pill instantly (server template, real id swapped
			// in), then enqueue in the background.
			var painted = processingCellHtml( optimizeId );
			if ( painted ) { mb.innerHTML = painted; }
			postForm( {
				action: 'slash_image_enqueue_one',
				nonce:  cfg.poll_nonce,
				id:     optimizeId,
			} ).then( function ( data ) {
				if ( ! data || ! data.success || ! data.data || ! data.data.html ) {
					// Revert to the button and surface a brief inline error.
					mb.innerHTML = mbOriginal;
					if ( isKeyGate( data ) ) { return; }
					var errLine = document.createElement( 'p' );
					errLine.className   = 'slash-image-mb__status slash-image-mb__status--err';
					errLine.textContent = i18n.bulk_start_failed || '';
					mb.appendChild( errLine );
					return;
				}
				// Reconcile the optimistic pill with the authoritative queued cell.
				mb.innerHTML       = data.data.html;
				lastActivityClient = Date.now();
				pollingPaused      = false;
				kickWorker();
				schedulePoll();
			} ).catch( function () {
				mb.innerHTML = mbOriginal;
			} );
			return;
		}

		if ( 'enqueue' === action || 'stalled-retry' === action ) {
			event.preventDefault();
			target.disabled = true;
			// 'enqueue' (Optimize / failed-Retry) → fresh enqueue; 'stalled-retry'
			// → credit-safe reset-in-place of the existing row. Both
			// return the cell HTML to paint and then kick the worker — the kick
			// also restarts the driver on a driver-dead host.
			var endpoint = ( 'stalled-retry' === action )
				? 'slash_image_retry_stalled'
				: 'slash_image_enqueue_one';
			postForm( {
				action: endpoint,
				nonce:  cfg.poll_nonce,
				id:     target.dataset.id,
			} ).then( function ( data ) {
				if ( ! data || ! data.success || ! data.data ) {
					target.disabled = false;
					isKeyGate( data );
					return;
				}
				var cell = findCell( data.data.id );
				if ( cell && data.data.html ) {
					cell.innerHTML = data.data.html;
				}
				lastActivityClient = Date.now();
				pollingPaused      = false;
				// Start processing now rather than on the next cron tick.
				kickWorker();
				schedulePoll();
			} ).catch( function () {
				target.disabled = false;
			} );
		}
	}

	function bindVisibility() {
		document.addEventListener( 'visibilitychange', function () {
			if ( document.visibilityState === 'visible' ) {
				lastActivityClient = Date.now();
				pollingPaused      = false;
				schedulePoll();
			}
		} );
	}

	// Watched uploads: when a browser upload finishes (drag-drop or file
	// picker in the Media Library grid / Add-New screen), WordPress's
	// uploader calls wp.Uploader.prototype.success per attachment. Hook it
	// to fire a kick so the auto-optimize row (enqueued server-side at
	// PRIORITY_UPLOAD by the upload handler) starts processing immediately.
	// Programmatic uploads (WP-CLI, REST imports) have no JS context and
	// intentionally fall back to the cron tick. Guarded: if wp.Uploader
	// isn't present on this screen, uploads simply fall back to cron.
	function bindUploader() {
		if ( ! window.wp || ! wp.Uploader || ! wp.Uploader.prototype ) { return; }
		var original = wp.Uploader.prototype.success;
		wp.Uploader.prototype.success = function () {
			try { kickWorker(); } catch ( e ) {}
			if ( typeof original === 'function' ) {
				return original.apply( this, arguments );
			}
		};
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		attachBulkActions( document.getElementById( 'posts-filter' ) );
		document.addEventListener( 'click', handleClick );
		bindVisibility();
		bindUploader();
		// If we land on the Media Library with any queued/processing rows
		// (e.g. just after the "Optimize with Slash Image" bulk action, or a
		// queue stalled on a no-traffic cron host), nudge the worker once so
		// processing starts within seconds rather than on the next cron tick.
		// Coalescing guard keeps it to one kick; harmless when nothing's queued.
		if ( transitionalIds().length > 0 ) {
			kickWorker();
		}
		schedulePoll();
	} );
} )();
