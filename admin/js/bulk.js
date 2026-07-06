( function () {
	'use strict';

	if ( typeof window.SlashImageBulk === 'undefined' ) {
		return;
	}

	var cfg  = window.SlashImageBulk;
	var i18n = cfg.i18n || {};

	// Progress poll cadence: 1 s while a run is active (smooth bar), 30 s otherwise.
	var POLL_RUNNING_MS  = 1000;
	var POLL_OTHER_MS    = 30000;
	var STALE_TIMEOUT_MS = 30 * 60 * 1000;

	var pollTimer          = null;
	var isDriving          = false;
	var lastActivityClient = Date.now();
	var pollingPaused      = false;
	// Bumped by every explicit user action (pause/cancel/resume). A progress poll
	// already in flight when the user clicks captures the old value and discards its
	// result, so a stale "running" snapshot can't revert the action's state flip.
	var pollGen            = 0;
	// Previous run status seen by render(), for the one-shot completion reminder.
	var prevStatus         = null;
	var currentMode        = 'optimize';

	function $( id ) { return document.getElementById( id ); }
	function qs( sel ) { return document.querySelector( sel ); }

	function postForm( params ) {
		var body = new URLSearchParams();
		Object.keys( params ).forEach( function ( k ) { body.append( k, params[ k ] ); } );
		body.append( 'nonce', cfg.nonce );
		return fetch( cfg.ajax_url, {
			method:      'POST',
			credentials: 'same-origin',
			headers:     { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body:        body.toString(),
		} ).then( function ( res ) {
			return res.json().catch( function () { return { success: false }; } );
		} );
	}

	// Brief, TRANSIENT "add an API key" notice for a keyless Bulk Start. The
	// global disconnected banner is the persistent prompt; this is a one-shot
	// nudge that the click wasn't a silent no-op, so it auto-dismisses.
	function showKeyNotice() {
		var msg = i18n.no_api_key || '';
		if ( ! msg ) { return; }
		var existing = $( 'slash-image-key-notice' );
		if ( existing ) { existing.remove(); }
		var wrap = qs( '.wrap' );
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

	function applySnapshot( payload ) {
		if ( ! payload || ! payload.success || ! payload.data || ! payload.data.progress ) {
			return null;
		}
		var data        = payload.data;
		var progress    = data.progress;
		var cron_status = data.cron_status;

		render( progress );
		updateCronBanner( cron_status );

		if ( progress && progress.last_activity_at ) {
			lastActivityClient = Date.now();
			pollingPaused      = false;
			toggleHidden( $( 'slash-image-progress-stale' ), true );
		}

		return progress;
	}

	// Renders the redesigned bulk page from the canonical snapshot. processed/
	// total/percent come verbatim from the server's progress() state; the client
	// never computes progress itself, so first paint and every poll agree.
	function render( progress ) {
		var status    = ( progress && progress.status ) || 'idle';
		var processed = ( progress && progress.processed ) || 0;
		var total     = ( progress && progress.total ) || 0;
		var pct       = ( progress && progress.percent ) || 0;
		var failed    = ( progress && progress.failed_count ) || 0;
		var rate      = ( progress && progress.recent_rate ) || 0;
		var skipped   = ( progress && progress.skipped ) || 0;
		var remaining = ( progress && progress.queue_remaining ) || 0;
		var credits   = ( progress && progress.credits_estimate ) || 0;
		var thumbs    = ( progress && progress.total_thumbnails ) || 0;
		var lib       = ( progress && progress.library ) || { total: 0, optimized: 0, not_optimized: 0, errors: 0 };
		var totals    = ( progress && progress.totals ) || { original_bytes: 0, optimized_bytes: 0, best_format_bytes: 0 };
		var recent    = ( progress && progress.recent_completions ) || [];
		var mode      = ( progress && progress.mode ) || 'optimize';
		var deferred  = ( progress && progress.deferred_in_flight ) || 0;
		currentMode   = mode;

		var isRunning   = ( 'running'   === status );
		var isPaused    = ( 'paused'    === status );
		var isCompleted = ( 'completed' === status );
		// The redesign returns to the idle Start state on completion (refreshed donut).
		var isIdle      = ( 'idle' === status || isCompleted );
		var showRunning = ( isRunning || isPaused );

		var actionCard = $( 'slash-image-bulk-action-card' );
		if ( actionCard ) { actionCard.dataset.status = status; }

		// ── Header crumb counts ──
		setText( $( 'slash-image-crumb-images' ), formatNum( lib.total ) );
		setText( $( 'slash-image-crumb-thumbs' ), formatNum( thumbs ) );

		// ── Status & Savings card (always visible; PHP renders it without [hidden]) ──
		var donutPct = lib.total > 0 ? Math.round( lib.optimized / lib.total * 100 ) : 0;
		var donut    = qs( '.slash-image-app--bulk .donut' );
		if ( donut ) {
			donut.style.background = 'conic-gradient( var(--slash-color-primary) 0 ' + donutPct + '%, var(--slash-color-track) ' + donutPct + '% 100% )';
			var big = donut.querySelector( '.big' );
			if ( big ) { big.textContent = donutPct + '%'; }
		}

		// Legend <b> elements, in markup order: Optimized / Pending / Errors.
		var legend = document.querySelectorAll( '.slash-image-app--bulk .donut-legend b' );
		if ( legend.length >= 3 ) {
			legend[ 0 ].textContent = formatNum( lib.optimized );
			legend[ 1 ].textContent = formatNum( lib.not_optimized );
			legend[ 2 ].textContent = formatNum( lib.errors );
		}

		// Hero savings %.
		var savingsPct = totals.original_bytes > 0
			? Math.round( ( 1 - totals.best_format_bytes / totals.original_bytes ) * 100 ) : 0;
		setQS( '.slash-image-app--bulk .hero-pct', savingsPct + '%' );

		// Size bars.
		var optWidth = totals.original_bytes > 0
			? ( totals.best_format_bytes / totals.original_bytes * 100 ).toFixed( 1 ) : 0;
		setQS( '.slash-image-app--bulk .size-row.orig .v', humanBytes( totals.original_bytes ) );
		setQS( '.slash-image-app--bulk .size-row.opt .v', humanBytes( totals.best_format_bytes ) );
		var optFill = qs( '.slash-image-app--bulk .size-row.opt .track i' );
		if ( optFill ) { optFill.style.width = optWidth + '%'; }

		// Credits estimate.
		setQS( '.slash-image-app--bulk .credit-note b', formatNum( credits ) + ' ' + ( i18n.credits || 'credits' ) );

		// Re-optimize label count.
		setText( $( 'slash-image-reopt-count' ), formatNum( lib.optimized ) );

		// ── idle / running block visibility ──
		var idleBlock    = qs( '.slash-image-app--bulk .idle' );
		var runningBlock = qs( '.slash-image-app--bulk .running' );
		if ( idleBlock ) { idleBlock.classList.toggle( 'hide', showRunning ); }
		if ( runningBlock ) {
			runningBlock.classList.toggle( 'show', showRunning );
			// Settled state reached — drop the transitional pausing freeze; the
			// data-status="paused" rule keeps the spinner/bar frozen while paused.
			runningBlock.classList.remove( 'is-pausing' );
		}

		// ── Buttons (existing IDs; wiring untouched) ──
		toggleHidden( $( 'slash-image-bulk-start' ),  ! isIdle );
		toggleHidden( $( 'slash-image-bulk-pause' ),  ! isRunning );
		toggleHidden( $( 'slash-image-bulk-resume' ), ! isPaused );
		toggleHidden( $( 'slash-image-bulk-cancel' ), ! showRunning );

		// ── Running internals ──
		setQS( '.slash-image-app--bulk .run-count', formatNum( processed ) );
		setQS( '.slash-image-app--bulk .run-total', formatNum( total ) );
		var runFill = qs( '.slash-image-app--bulk .run-track i' );
		if ( runFill ) { runFill.style.width = pct + '%'; }
		setQS( '.slash-image-app--bulk .run-pctline .si-pct',
			( i18n.pct_complete || '%d% complete' ).replace( '%d', pct ) );

		// Right side of the pct line — recent throughput when running.
		var rateEl = $( 'slash-image-progress-rate' );
		if ( rateEl ) {
			rateEl.textContent = ( isRunning && rate > 0 )
				? ( i18n.recent_rate || 'Recent rate: %d images in the last minute' ).replace( '%d', rate )
				: '';
		}

		setQS( '.slash-image-app--bulk .run-stat-optimized', formatNum( processed ) );
		setQS( '.slash-image-app--bulk .run-stat-skipped',   formatNum( skipped ) );
		setQS( '.slash-image-app--bulk .run-stat-remaining', formatNum( remaining ) );

		if ( showRunning ) {
			renderNowList( recent );
		}

		// ── Failed card ──
		toggleHidden( $( 'slash-image-failed-card' ), failed === 0 );
		setText( $( 'slash-image-failed-count' ), formatNum( failed ) );

		// ── One-shot "purge your cache" reminder on run completion ──
		// Shown ONLY on the running/paused -> completed transition, so it never
		// reappears on a plain reload of an already-completed session (which renders
		// the idle Start state). Hidden again the moment a new run starts.
		var completeNote = $( 'slash-image-bulk-complete-note' );
		if ( completeNote ) {
			if ( showRunning ) {
				completeNote.hidden = true;
			} else if ( isCompleted && ( 'running' === prevStatus || 'paused' === prevStatus ) ) {
				completeNote.hidden = false;
			}
		}

		// Restore run only: if some images were mid-optimize and got skipped, tell
		// the user on completion so they know the run wasn't 100% (skip-and-report).
		var deferredNote = $( 'slash-image-bulk-deferred-note' );
		if ( deferredNote ) {
			if ( isCompleted && 'restore' === mode && deferred > 0 ) {
				var defTmpl = i18n.restore_deferred || {};
				var defMsg  = ( 1 === deferred ? defTmpl.one : defTmpl.other ) || '';
				deferredNote.textContent = defMsg.replace( '%d', deferred );
				deferredNote.hidden = false;
			} else if ( showRunning ) {
				deferredNote.hidden = true;
			}
		}
		prevStatus = status;
	}

	// Rebuilds the rolling "now" list: row 1 = active (pulsing), rows 2–3 = done
	// with before → after −%. All user-supplied strings are escaped.
	function renderNowList( completions ) {
		var list = qs( '.slash-image-app--bulk .now-list' );
		if ( ! list ) { return; }
		list.innerHTML = '';
		( completions || [] ).forEach( function ( row ) {
			var div = document.createElement( 'div' );
			div.className = 'now-row' + ( 'active' === row.state ? ' active' : '' );
			if ( 'active' === row.state ) {
				div.innerHTML =
					'<span class="pulse" aria-hidden="true"></span>' +
					'<span class="fname"><span class="doing">' + escHtml( 'restore' === currentMode ? ( i18n.restoring || 'Restoring' ) : ( i18n.optimizing || 'Optimizing' ) ) +
					'</span> ' + escHtml( row.filename ) + '</span>' +
					'<span class="meta">' + escHtml( row.size ) + '</span>';
			} else {
				div.innerHTML =
					'<svg class="check" viewBox="0 0 16 16" fill="none" aria-hidden="true">' +
					'<path d="M3.5 8.5l3 3 6-7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
					'<span class="fname">' + escHtml( row.filename ) + '</span>' +
					'<span class="meta">' + escHtml( row.original_size ) + ' → ' + escHtml( row.optimized_size ) +
					' <span class="save">−' + ( parseInt( row.saved_percent, 10 ) || 0 ) + '%</span></span>';
			}
			list.appendChild( div );
		} );
	}

	function escHtml( str ) {
		return String( ( str === null || str === undefined ) ? '' : str ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function updateCronBanner( cron_status ) {
		var banner = $( 'slash-image-bulk-cron-banner' );
		if ( ! banner ) { return; }
		var show = ( 'no_cron' === cron_status || 'unknown' === cron_status );
		toggleHidden( banner, ! show );
	}

	function setText( el, text ) { if ( el ) { el.textContent = text; } }
	function setQS( selector, text ) { var el = qs( selector ); if ( el ) { el.textContent = text; } }
	function formatNum( n ) { return Number( n || 0 ).toLocaleString(); }
	function humanBytes( b ) {
		b = Number( b || 0 );
		if ( b < 1024 ) { return b + ' B'; }
		if ( b < 1024 * 1024 ) { return ( b / 1024 ).toFixed( 1 ) + ' KB'; }
		if ( b < 1024 * 1024 * 1024 ) { return ( b / 1024 / 1024 ).toFixed( 1 ) + ' MB'; }
		return ( b / 1024 / 1024 / 1024 ).toFixed( 1 ) + ' GB';
	}

	function toggleHidden( el, hide ) {
		if ( ! el ) { return; }
		if ( hide ) {
			el.setAttribute( 'hidden', 'hidden' );
		} else {
			el.removeAttribute( 'hidden' );
		}
	}

	// Drive the worker on all hosts while a run is `running`.
	// Single lane — one budgeted kick in flight at a time, re-dispatched while
	// work remains. The separate progress poll renders the bar, so the kick
	// payload is minimal and the driver never calls render().
	function maybeStartDriver( progress ) {
		var running = ( progress && 'running' === progress.status );
		if ( ! running ) {
			stopDriver();
			return;
		}
		startDriver();
	}

	function startDriver() {
		if ( isDriving ) { return; }   // chain already running
		isDriving = true;
		driveKick();
	}

	// One budgeted kick (server processes ~one attachment, returns fast), then
	// re-dispatch while the server reports more queued work. Stops when the
	// queue is drained, the tab is hidden, or stopDriver() flips isDriving.
	function driveKick() {
		postForm( { action: 'slash_image_worker_kick' } )
			.then( function ( payload ) {
				var more = !! ( payload && payload.data && payload.data.queue_has_more );
				if ( more && isDriving && ! document.hidden ) {
					driveKick();
				} else {
					isDriving = false;
				}
			} )
			.catch( function () { isDriving = false; } );
	}

	function stopDriver() {
		// The in-flight kick checks isDriving on return and stops the chain.
		isDriving = false;
	}

	// Quiesce the loop for an explicit user action (pause/cancel/resume): invalidate
	// any in-flight poll, clear the pending poll timer, and stop the driver — so the
	// action's own AJAX response is the sole authority on the next rendered state and
	// a late "running" poll can't revert the transition.
	function beginUserAction() {
		pollGen++;
		if ( pollTimer ) { clearTimeout( pollTimer ); pollTimer = null; }
		stopDriver();
	}

	function pollOnce() {
		var gen = pollGen;
		return postForm( { action: 'slash_image_bulk_progress' } ).then( function ( payload ) {
			if ( gen !== pollGen ) { return null; }   // superseded by a user action — discard
			var progress = applySnapshot( payload );
			var status   = progress ? progress.status : 'idle';
			maybeStartDriver( progress );
			schedulePoll( status );
			return progress;
		} );
	}

	function schedulePoll( status ) {
		if ( pollTimer ) { clearTimeout( pollTimer ); pollTimer = null; }
		if ( pollingPaused ) { return; }

		var staleAge = Date.now() - lastActivityClient;
		if ( staleAge > STALE_TIMEOUT_MS ) {
			pollingPaused = true;
			toggleHidden( $( 'slash-image-progress-stale' ), false );
			return;
		}

		var delay = ( 'running' === status ) ? POLL_RUNNING_MS : POLL_OTHER_MS;
		pollTimer = window.setTimeout( pollOnce, delay );
	}

	function bindStart() {
		var btn   = $( 'slash-image-bulk-start' );
		var force = $( 'slash-image-bulk-force' );
		if ( ! btn ) { return; }
		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			postForm( {
				action:     'slash_image_bulk_start',
				force_redo: force && force.checked ? '1' : '0',
			} ).then( function ( payload ) {
				// No-key gate: the run wasn't started — show the transient add-a-key
				// one-liner instead of flipping the page to a (non-existent) run.
				if ( payload && payload.data && 'no_key' === payload.data.refused ) {
					showKeyNotice();
					return;
				}
				applySnapshot( payload );
				var progress = payload && payload.data ? payload.data.progress : null;
				maybeStartDriver( progress );
				schedulePoll( progress ? progress.status : 'idle' );
				if ( force ) { force.checked = false; } // Reset per-visit per spec.
			} ).finally( function () {
				btn.disabled = false;
			} );
		} );
	}

	function bindPause() {
		var btn = $( 'slash-image-bulk-pause' );
		if ( ! btn ) { return; }
		btn.addEventListener( 'click', function () {
			var original     = btn.textContent;
			var runningBlock = qs( '.slash-image-app--bulk .running' );
			var actionCard   = $( 'slash-image-bulk-action-card' );

			// Immediate feedback BEFORE the AJAX: disable + relabel the button and
			// freeze the spinner + striped bar via the transitional is-pausing class
			// (plus a data-status="pausing" hook). render() clears is-pausing once the
			// settled state arrives; the data-status="paused" rule then holds the freeze.
			btn.disabled    = true;
			btn.textContent = i18n.pausing || 'Pausing…';
			if ( runningBlock ) { runningBlock.classList.add( 'is-pausing' ); }
			if ( actionCard )   { actionCard.dataset.status = 'pausing'; }

			function revertPausing() {
				if ( runningBlock ) { runningBlock.classList.remove( 'is-pausing' ); }
				if ( actionCard )   { actionCard.dataset.status = 'running'; }
			}

			beginUserAction();
			postForm( { action: 'slash_image_bulk_pause' } )
				.then( function ( payload ) {
					var progress = applySnapshot( payload );
					if ( progress ) {
						schedulePoll( progress.status );   // paused → idle cadence
					} else {
						revertPausing();   // request failed — undo the transitional UI
						pollOnce();
					}
				} )
				.catch( function () { revertPausing(); pollOnce(); } )
				.finally( function () { btn.disabled = false; btn.textContent = original; } );
		} );
	}

	function bindResume() {
		var btn = $( 'slash-image-bulk-resume' );
		if ( ! btn ) { return; }
		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			beginUserAction();
			postForm( { action: 'slash_image_bulk_resume' } )
				.then( function ( payload ) {
					var progress = applySnapshot( payload );
					if ( progress ) {
						maybeStartDriver( progress );
						schedulePoll( progress.status );
					} else {
						pollOnce();   // request failed — re-sync
					}
				} )
				.catch( function () { pollOnce(); } )
				.finally( function () { btn.disabled = false; } );
		} );
	}

	function bindCancel() {
		var btn = $( 'slash-image-bulk-cancel' );
		if ( ! btn ) { return; }
		btn.addEventListener( 'click', function () {
			if ( ! window.confirm( i18n.cancel_confirm ) ) { return; }
			btn.disabled = true;
			beginUserAction();
			postForm( { action: 'slash_image_bulk_cancel' } )
				.then( function ( payload ) {
					var progress = applySnapshot( payload );
					if ( progress ) {
						schedulePoll( progress.status );   // idle
					} else {
						pollOnce();   // request failed — re-sync
					}
				} )
				.catch( function () { pollOnce(); } )
				.finally( function () { btn.disabled = false; } );
		} );
	}

	function bindRetryFailed() {
		var btn = $( 'slash-image-bulk-retry-failed' );
		if ( ! btn ) { return; }
		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			var prev = btn.textContent;
			btn.textContent = i18n.retry_running;
			postForm( { action: 'slash_image_bulk_retry_failed' } )
				.then( function ( payload ) {
					applySnapshot( payload );
					var progress = payload && payload.data ? payload.data.progress : null;
					maybeStartDriver( progress );
					schedulePoll( progress ? progress.status : 'idle' );
				} )
				.finally( function () { btn.disabled = false; btn.textContent = prev; } );
		} );
	}

	function bindClearFailed() {
		var btn = $( 'slash-image-bulk-clear-failed' );
		if ( ! btn ) { return; }
		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			postForm( { action: 'slash_image_bulk_clear_failed' } )
				.then( applySnapshot )
				.finally( function () { btn.disabled = false; } );
		} );
	}

	function bindVisibility() {
		document.addEventListener( 'visibilitychange', function () {
			if ( document.visibilityState === 'visible' ) {
				lastActivityClient = Date.now();
				pollingPaused      = false;
				toggleHidden( $( 'slash-image-progress-stale' ), true );
				pollOnce();
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		bindStart();
		bindPause();
		bindResume();
		bindCancel();
		bindRetryFailed();
		bindClearFailed();
		bindVisibility();

		var app = document.querySelector( '.slash-image-app--bulk' );
		if ( app && app.dataset.cronStatus ) {
			updateCronBanner( app.dataset.cronStatus );
		}

		pollOnce();
	} );
} )();
