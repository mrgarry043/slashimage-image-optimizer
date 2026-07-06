( function () {
	'use strict';

	if ( typeof window.SlashImageSettings === 'undefined' ) {
		return;
	}

	var cfg  = window.SlashImageSettings;
	var i18n = cfg.i18n || {};

	var TABS              = [ 'dashboard', 'settings' ];
	// Section anchors that deep-link to a tab and scroll to a section (e.g. the
	// Bulk Optimize credits tooltip points at #image-sizes). Maps fragment → tab.
	var SECTION_TABS      = { 'image-sizes': 'settings' };
	// API key format pattern, derived from Slash_Image_Settings::API_KEY_REGEX
	// (passed via cfg.key_regex) so PHP stays the single source of truth.
	var KEY_REGEX         = cfg.key_regex ? new RegExp( cfg.key_regex ) : null;

	function $( id ) { return document.getElementById( id ); }
	function qsa( selector, root ) { return Array.prototype.slice.call( ( root || document ).querySelectorAll( selector ) ); }

	function postForm( params ) {
		var body = new URLSearchParams();
		Object.keys( params ).forEach( function ( key ) { body.append( key, params[ key ] ); } );
		return fetch( cfg.ajax_url, {
			method:      'POST',
			credentials: 'same-origin',
			headers:     { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body:        body.toString(),
		} ).then( function ( res ) {
			return res.json().catch( function () { return { success: false, data: { code: 'unexpected' } }; } );
		} );
	}

	function setAlert( el, kind, message ) {
		if ( ! el ) { return; }
		el.classList.remove( 'is-success', 'is-warning', 'is-error' );
		if ( ! message ) {
			el.textContent = '';
			el.hidden = true;
			return;
		}
		if ( kind ) { el.classList.add( 'is-' + kind ); }
		el.textContent = message;
		el.hidden = false;
	}

	function messageForCode( code ) {
		switch ( code ) {
			case 'invalid_format':   return { kind: 'error',   text: i18n.invalid_format };
			case 'invalid_key':      return { kind: 'error',   text: i18n.invalid_key };
			case 'rate_limited':     return { kind: 'warning', text: i18n.rate_limited };
			case 'server_error':     return { kind: 'error',   text: i18n.server_error };
			case 'network_error':    return { kind: 'error',   text: i18n.network_error };
			default:                  return { kind: 'error',   text: i18n.unexpected };
		}
	}

	/* ── Tabs ───────────────────────────────────────── */

	function activateTab( name ) {
		if ( TABS.indexOf( name ) === -1 ) { name = 'dashboard'; }
		qsa( '.slash-image-tab' ).forEach( function ( btn ) {
			var on = btn.dataset.tab === name;
			btn.classList.toggle( 'is-active', on );
			btn.setAttribute( 'aria-selected', on ? 'true' : 'false' );
		} );
		qsa( '.slash-image-tab-panel' ).forEach( function ( panel ) {
			panel.classList.toggle( 'is-active', panel.dataset.tabPanel === name );
		} );
		if ( window.location.hash !== '#' + name ) {
			history.replaceState( null, '', '#' + name );
		}
	}

	// Route a raw hash fragment. A tab name activates that tab; a known section
	// anchor (SECTION_TABS) activates its tab and then scrolls that section into
	// view — so a cross-page deep link like #image-sizes works despite the
	// hash-based tab system (which would otherwise coerce it to the default tab).
	function applyHash( raw ) {
		var section = SECTION_TABS[ raw ];
		if ( section ) {
			activateTab( section );
			var target = $( raw );
			if ( target ) {
				window.setTimeout( function () {
					target.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				}, 0 );
			}
			return;
		}
		activateTab( raw );
	}

	function bindTabs() {
		qsa( '.slash-image-tab' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () { activateTab( btn.dataset.tab ); } );
		} );
		qsa( '[data-jump-tab]' ).forEach( function ( el ) {
			el.addEventListener( 'click', function () {
				activateTab( el.dataset.jumpTab );
				window.scrollTo( { top: 0, behavior: 'smooth' } );
			} );
		} );
		window.addEventListener( 'hashchange', function () {
			applyHash( ( window.location.hash || '' ).slice( 1 ) );
		} );
		var initial = ( window.location.hash || '' ).slice( 1 );
		if ( initial ) {
			applyHash( initial );
		} else {
			var app = document.querySelector( '.slash-image-app' );
			activateTab( ( app && app.dataset.initialTab ) || 'dashboard' );
		}
	}

	/* ── API key connect ────────────────────────────── */

	function bindConnect() {
		var input = $( 'slash-image-api-key' );
		var btn   = $( 'slash-image-connect' );
		var err   = $( 'slash-image-key-error' );
		if ( ! input || ! btn ) { return; }

		function showError( msg ) {
			if ( ! err ) { return; }
			err.textContent = msg;
			err.hidden = false;
		}

		function clearError() {
			if ( ! err ) { return; }
			err.textContent = '';
			err.hidden = true;
		}

		function connect() {
			clearError();
			var value = input.value.trim();
			if ( '' === value ) {
				showError( i18n.key_empty || 'Please paste your API key.' );
				return;
			}
			if ( KEY_REGEX && ! KEY_REGEX.test( value ) ) {
				showError( i18n.key_format || '' );
				return;
			}

			btn.disabled    = true;
			btn.textContent = i18n.connecting || 'Connecting…';

			postForm( { action: 'slash_image_save_key', nonce: cfg.save_key_nonce, api_key: value } )
				.then( function ( data ) {
					if ( data && data.success && data.data && data.data.connected ) {
						// Reload so the connected state is always PHP-rendered.
						window.location.reload();
						return;
					}
					var code = ( data && data.data && data.data.code ) || 'unexpected';
					showError( messageForCode( code ).text );
					btn.disabled    = false;
					btn.textContent = i18n.connect || 'Connect';
				} )
				.catch( function () {
					showError( messageForCode( 'network_error' ).text );
					btn.disabled    = false;
					btn.textContent = i18n.connect || 'Connect';
				} );
		}

		btn.addEventListener( 'click', connect );

		// Enter in the key field connects, rather than submitting the settings form.
		input.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) {
				e.preventDefault();
				connect();
			}
		} );

		// Clear a shown error as soon as the user edits the field.
		input.addEventListener( 'input', clearError );
	}

	/* ── Segmented controls ─────────────────────────── */

	function bindSegmented() {
		qsa( '.slash-image-segmented' ).forEach( function ( group ) {
			var segments      = qsa( '.slash-image-segment', group );
			var hidden        = $( group.dataset.target );
			var helper        = group.dataset.helperTarget ? $( group.dataset.helperTarget ) : null;
			var helperPrefix  = ( hidden && hidden.id === 'slash-image-input-frontend-mode' ) ? 'fe_helper_' : 'mode_helper_';

			function helperKeyFor( value ) {
				if ( 'fe_helper_' === helperPrefix ) {
					if ( 'htaccess' === value ) {
						switch ( cfg.server_kind ) {
							case 'apache':
							case 'litespeed': return 'fe_helper_htaccess_apache';
							case 'nginx':     return 'fe_helper_htaccess_nginx';
							default:           return 'fe_helper_htaccess_unsupported';
						}
					}
					return 'fe_helper_' + value;
				}
				return 'mode_helper_' + value;
			}

			function setHelperText( value ) {
				if ( ! helper ) { return; }
				var text = i18n[ helperKeyFor( value ) ] || '';
				// Compression helper has a structured layout (badge + text span).
				var textNode  = helper.querySelector( '#slash-image-helper-text' );
				var badgeNode = helper.querySelector( '#slash-image-helper-recommended' );
				if ( textNode ) {
					textNode.textContent = text;
					if ( badgeNode ) {
						badgeNode.hidden = ( 'lossy' !== value );
					}
				} else {
					helper.textContent = text;
				}
			}

			function activate( seg ) {
				segments.forEach( function ( s ) {
					var on = ( s === seg );
					s.classList.toggle( 'is-active', on );
					s.setAttribute( 'aria-checked', on ? 'true' : 'false' );
					s.tabIndex = on ? 0 : -1;
				} );
				if ( hidden ) {
					hidden.value = seg.dataset.value;
					hidden.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				}
				setHelperText( seg.dataset.value );
				if ( hidden && hidden.id === 'slash-image-input-frontend-mode' ) {
					updateFrontendModeUI( seg.dataset.value );
				}
			}

			segments.forEach( function ( seg, idx ) {
				seg.addEventListener( 'click', function () { activate( seg ); seg.focus(); } );
				seg.addEventListener( 'keydown', function ( e ) {
					var dir = 0;
					if ( e.key === 'ArrowRight' || e.key === 'ArrowDown' ) { dir = 1; }
					if ( e.key === 'ArrowLeft'  || e.key === 'ArrowUp'   ) { dir = -1; }
					if ( ! dir ) { return; }
					e.preventDefault();
					var next = segments[ ( idx + dir + segments.length ) % segments.length ];
					activate( next );
					next.focus();
				} );
			} );

			// Initial helper text + initial mode-driven UI for the FE card.
			var initial = segments.filter( function ( s ) { return s.classList.contains( 'is-active' ); } )[ 0 ];
			if ( initial ) {
				setHelperText( initial.dataset.value );
				if ( hidden && hidden.id === 'slash-image-input-frontend-mode' ) {
					updateFrontendModeUI( initial.dataset.value );
				}
			}
		} );
	}

	function updateFrontendModeUI( mode ) {
		qsa( '[data-show-when-mode]' ).forEach( function ( el ) {
			el.dataset.showWhenModeActive = ( el.dataset.showWhenMode === mode ) ? '1' : '0';
		} );
	}

	function bindHtaccessActions() {
		var applyBtn  = $( 'slash-image-fe-apply' );
		var removeBtn = $( 'slash-image-fe-remove' );
		var result    = $( 'slash-image-fe-result' );

		function refreshAfter( active ) {
			// Refresh by reloading just the FE actions container would require
			// a server round-trip; simpler to reload the page on success so the
			// PHP-rendered initial state matches reality.
			window.setTimeout( function () { window.location.reload(); }, 500 );
		}

		if ( applyBtn ) {
			applyBtn.addEventListener( 'click', function () {
				applyBtn.disabled = true;
				setAlert( result, null, i18n.fe_apply_running );
				postForm( {
					action: 'slash_image_htaccess_apply',
					nonce:  cfg.htaccess_nonce,
				} ).then( function ( data ) {
					if ( data && data.success ) {
						setAlert( result, 'success', i18n.fe_active );
						refreshAfter( true );
					} else {
						var code = data && data.data && data.data.code ? data.data.code : 'unknown';
						var msg  = i18n.fe_apply_failed;
						if ( 'not_writable' === code )  { msg = i18n.fe_not_writable; }
						if ( 'probe_failed' === code )  { msg = i18n.fe_probe_failed; }
						setAlert( result, 'error', msg );
					}
				} ).catch( function () {
					setAlert( result, 'error', i18n.fe_apply_failed );
				} ).finally( function () {
					applyBtn.disabled = false;
				} );
			} );
		}

		if ( removeBtn ) {
			removeBtn.addEventListener( 'click', function () {
				removeBtn.disabled = true;
				setAlert( result, null, i18n.fe_remove_running );
				postForm( {
					action: 'slash_image_htaccess_remove',
					nonce:  cfg.htaccess_nonce,
				} ).then( function ( data ) {
					if ( data && data.success ) {
						refreshAfter( false );
					} else {
						setAlert( result, 'error', i18n.fe_remove_failed );
					}
				} ).catch( function () {
					setAlert( result, 'error', i18n.fe_remove_failed );
				} ).finally( function () {
					removeBtn.disabled = false;
				} );
			} );
		}
	}

	/* ── Toggle-driven row dimming ──────────────────── */

	function bindDependentRow( toggleId, rowSelector ) {
		var toggle = $( toggleId );
		var row    = document.querySelector( rowSelector );
		if ( ! toggle || ! row ) { return; }
		var sync = function () { row.classList.toggle( 'is-disabled', ! toggle.checked ); };
		toggle.addEventListener( 'change', sync );
		sync();
	}

	// Like bindDependentRow, but the row depends on several toggles at once: it is
	// disabled when ANY of them is unchecked (enabled only when all are on). Used
	// for the retention-days row, which needs both "Keep backups" and "Auto-delete".
	function bindDependentRowMulti( toggleIds, rowSelector ) {
		var row     = document.querySelector( rowSelector );
		var toggles = toggleIds.map( function ( id ) { return $( id ); } ).filter( Boolean );
		if ( ! row || ! toggles.length ) { return; }
		var sync = function () {
			var allOn = toggles.every( function ( t ) { return t.checked; } );
			row.classList.toggle( 'is-disabled', ! allOn );
		};
		toggles.forEach( function ( t ) { t.addEventListener( 'change', sync ); } );
		sync();
	}

	/* ── Toggle-driven row visibility (progressive disclosure) ──────── */

	// Show/hide row(s) by toggling .is-hidden (display:none) on a master toggle.
	function bindRowVisibility( toggleId, rowSelector ) {
		var toggle = $( toggleId );
		var rows   = qsa( rowSelector );
		if ( ! toggle || ! rows.length ) { return; }
		var sync = function () {
			rows.forEach( function ( r ) { r.classList.toggle( 'is-hidden', ! toggle.checked ); } );
		};
		toggle.addEventListener( 'change', sync );
		sync();
	}

	// Like bindRowVisibility, but the row(s) show only when ALL toggles are on.
	function bindRowVisibilityMulti( toggleIds, rowSelector ) {
		var rows    = qsa( rowSelector );
		var toggles = toggleIds.map( function ( id ) { return $( id ); } ).filter( Boolean );
		if ( ! rows.length || ! toggles.length ) { return; }
		var sync = function () {
			var allOn = toggles.every( function ( t ) { return t.checked; } );
			rows.forEach( function ( r ) { r.classList.toggle( 'is-hidden', ! allOn ); } );
		};
		toggles.forEach( function ( t ) { t.addEventListener( 'change', sync ); } );
		sync();
	}

	/* ── Danger-Zone confirmation modal (Restore All, Delete All) ── */

	function showConfirmModal( config ) {
		var modal   = document.getElementById( 'slash-image-confirm-modal' );
		var title   = modal.querySelector( '.slash-image-modal-title' );
		var body    = modal.querySelector( '.slash-image-modal-body' );
		var confirm = modal.querySelector( '.slash-image-modal-confirm' );
		var cancel  = modal.querySelector( '.slash-image-modal-cancel' );

		title.textContent   = config.title;
		body.textContent    = config.body;
		confirm.textContent = config.confirmLabel;

		// Danger styling on the confirm button.
		confirm.classList.toggle( 'slash-image-btn-danger', config.danger === true );

		modal.hidden = false;
		cancel.focus();

		function cleanup() {
			modal.hidden = true;
			confirm.removeEventListener( 'click', onConfirm );
			cancel.removeEventListener( 'click', onCancel );
			modal.removeEventListener( 'click', onOverlay );
			document.removeEventListener( 'keydown', onKeydown );
		}

		function onConfirm() { cleanup(); config.onConfirm(); }
		function onCancel()  { cleanup(); }
		function onOverlay( e ) { if ( e.target === modal ) { cleanup(); } }
		function onKeydown( e ) { if ( e.key === 'Escape' ) { cleanup(); } }

		confirm.addEventListener( 'click', onConfirm );
		cancel.addEventListener( 'click', onCancel );
		modal.addEventListener( 'click', onOverlay );
		document.addEventListener( 'keydown', onKeydown );
	}

	function bindRestoreAll() {
		var btn   = $( 'slash-image-restore-all' );
		var alert = $( 'slash-image-restore-result' );
		if ( ! btn ) { return; }

		btn.addEventListener( 'click', function () {
			showConfirmModal( {
				title:        cfg.modal_restore_title,
				body:         cfg.modal_restore_body,
				confirmLabel: cfg.modal_restore_confirm,
				danger:       true,
				onConfirm:    function () {
					// Async: start a restore run through the queue/worker, then point
					// the user at the Bulk Optimize page to watch progress.
					btn.disabled = true;
					setAlert( alert, null, i18n.restore_running );
					postForm( {
						action: 'slash_image_restore_all',
						nonce:  cfg.restore_nonce,
					} ).then( function ( data ) {
						if ( ! data || ! data.success || ! data.data ) {
							setAlert( alert, 'error', i18n.restore_failed );
							return;
						}
						var s = data.data;
						// Mutual exclusion: an optimize run is active.
						if ( 'optimize_running' === s.refused ) {
							setAlert( alert, 'warning', i18n.restore_busy );
							return;
						}
						var total = s.total || 0;
						if ( ! total ) {
							setAlert( alert, 'warning', i18n.restore_none );
							return;
						}
						var startedTmpl = i18n.restore_started || {};
						var startedMsg  = ( 1 === total ? startedTmpl.one : startedTmpl.other ) || '';
						setAlert( alert, 'success', startedMsg.replace( '%d', total ) );
					} ).catch( function () {
						setAlert( alert, 'error', i18n.restore_failed );
					} ).finally( function () {
						btn.disabled = false;
					} );
				},
			} );
		} );
	}

	function bindDeleteBackups() {
		var btn   = $( 'slash-image-delete-backups' );
		var alert = $( 'slash-image-delete-result' );
		if ( ! btn ) { return; }

		btn.addEventListener( 'click', function () {
			showConfirmModal( {
				title:        cfg.modal_delete_title,
				body:         cfg.modal_delete_body,
				confirmLabel: cfg.modal_delete_confirm,
				danger:       true,
				onConfirm:    function () {
					// Existing delete AJAX call — unchanged, just gated by the modal.
					btn.disabled = true;
					setAlert( alert, null, i18n.delete_running );
					postForm( {
						action: 'slash_image_delete_backups',
						nonce:  cfg.delete_nonce,
					} ).then( function ( data ) {
						if ( ! data || ! data.success || ! data.data ) {
							setAlert( alert, 'error', i18n.delete_failed );
							return;
						}
						var s = data.data;
						if ( ! s.attachments && ! s.files ) { setAlert( alert, 'warning', i18n.delete_none ); return; }
						var msg, kind;
						if ( s.errors ) {
							msg  = i18n.delete_partial.replace( '%1$d', s.files ).replace( '%2$d', s.errors ).replace( '%3$s', s.bytes_freed_h );
							kind = 'warning';
						} else {
							msg  = i18n.delete_summary.replace( '%1$d', s.files ).replace( '%2$d', s.attachments ).replace( '%3$s', s.bytes_freed_h );
							kind = 'success';
						}
						setAlert( alert, kind, msg );
					} ).catch( function () {
						setAlert( alert, 'error', i18n.delete_failed );
					} ).finally( function () {
						btn.disabled = false;
					} );
				},
			} );
		} );
	}

	/* ── Image Size Exclusions grid ─────────────────── */

	function bindSizeGrid() {
		var grid       = document.querySelector( '.slash-image-sizegrid' );
		var hiddenWrap = document.getElementById( 'slash-image-excluded-hidden' );
		if ( ! grid || ! hiddenWrap ) { return; }

		// Determine the option-name prefix from any existing input in the
		// grid (we can't hardcode it because the option name comes from PHP).
		var optionPrefix = '';
		var anyHidden    = hiddenWrap.querySelector( 'input[type="hidden"]' );
		if ( anyHidden ) {
			optionPrefix = anyHidden.name.replace( /\[excluded_image_sizes\]\[\]$/, '' );
		} else {
			var anyCb = grid.querySelector( 'input[type="checkbox"][data-size-key]' );
			if ( anyCb ) {
				optionPrefix = anyCb.name.replace( /\[__included_sizes\]\[\]$/, '' );
			}
		}
		if ( ! optionPrefix ) { return; }

		function rebuildHidden() {
			hiddenWrap.innerHTML = '';
			var cbs = grid.querySelectorAll( 'input[type="checkbox"][data-size-key]' );
			Array.prototype.forEach.call( cbs, function ( cb ) {
				if ( cb.checked ) { return; } // checked = include; unchecked = exclude
				var input = document.createElement( 'input' );
				input.type  = 'hidden';
				input.name  = optionPrefix + '[excluded_image_sizes][]';
				input.value = cb.dataset.sizeKey;
				hiddenWrap.appendChild( input );
			} );
		}

		grid.addEventListener( 'change', function ( e ) {
			if ( e.target && e.target.matches( 'input[type="checkbox"][data-size-key]' ) ) {
				rebuildHidden();
			}
		} );

		// Sync once on load (covers programmatic state changes).
		rebuildHidden();
	}

	/* ── Custom Exclusions modal ────────────────────── */

	function bindExclusionsModal() {
		var modal     = document.getElementById( 'slash-image-exclusions-modal' );
		var openBtn   = document.getElementById( 'slash-image-exclusions-edit' );
		var saveBtn   = document.getElementById( 'slash-image-exclusions-save' );
		var hiddenInp = document.getElementById( 'slash-image-custom-exclusions-input' );
		var textarea  = document.getElementById( 'slash-image-exclusions-textarea' );
		var summary   = document.getElementById( 'slash-image-exclusions-summary' );
		if ( ! modal || ! openBtn || ! saveBtn || ! hiddenInp || ! textarea ) { return; }

		var lastFocus = null;

		function patternsFromText( txt ) {
			return ( txt || '' ).split( /\r\n|\r|\n/ )
				.map( function ( s ) { return s.trim(); } )
				.filter( function ( s ) { return s.length > 0; } );
		}

		function setSummary( count ) {
			if ( ! summary ) { return; }
			if ( count > 0 ) {
				summary.textContent = ( i18n.exclusions_count || 'Currently excluding: %d patterns' ).replace( '%d', count );
			} else {
				summary.textContent = ( i18n.exclusions_none || 'No custom patterns yet.' );
			}
		}

		function open() {
			lastFocus    = document.activeElement;
			modal.hidden = false;
			modal.classList.add( 'is-open' );
			textarea.value = hiddenInp.value;
			document.body.classList.add( 'slash-image-modal-open' );
			window.requestAnimationFrame( function () { textarea.focus(); } );
		}

		function close() {
			modal.hidden = true;
			modal.classList.remove( 'is-open' );
			document.body.classList.remove( 'slash-image-modal-open' );
			if ( lastFocus && typeof lastFocus.focus === 'function' ) {
				lastFocus.focus();
			}
		}

		openBtn.addEventListener( 'click', function ( e ) { e.preventDefault(); open(); } );

		modal.addEventListener( 'click', function ( e ) {
			var t = e.target;
			if ( t && t.dataset && 'modal-close' === t.dataset.action ) {
				e.preventDefault();
				close();
			}
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( modal.hidden ) { return; }
			if ( 'Escape' === e.key || 'Esc' === e.key ) {
				close();
				return;
			}
			if ( 'Tab' === e.key ) {
				var focusables = modal.querySelectorAll( 'button, textarea, input:not([type="hidden"]), select, [tabindex]:not([tabindex="-1"])' );
				if ( focusables.length === 0 ) { return; }
				var first = focusables[0];
				var last  = focusables[ focusables.length - 1 ];
				if ( e.shiftKey && document.activeElement === first ) {
					e.preventDefault();
					last.focus();
				} else if ( ! e.shiftKey && document.activeElement === last ) {
					e.preventDefault();
					first.focus();
				}
			}
		} );

		saveBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var lines       = patternsFromText( textarea.value );
			hiddenInp.value = lines.join( '\n' );
			setSummary( lines.length );
			close();
		} );
	}

	/* ── Boot ───────────────────────────────────────── */

	// "Save & Bulk Optimize" — a normal form submit, but it points
	// _wp_http_referer at the Bulk Optimize page so options.php redirects there
	// after saving (instead of back to settings).
	function bindSaveBulk() {
		var btn = $( 'slash-image-save-bulk' );
		if ( ! btn ) { return; }
		btn.addEventListener( 'click', function () {
			var form = btn.closest( 'form' );
			var ref  = form ? form.querySelector( 'input[name="_wp_http_referer"]' ) : null;
			if ( ref && btn.dataset.bulkUrl ) { ref.value = btn.dataset.bulkUrl; }
			// Default submit proceeds.
		} );
	}

	// Disconnect (connected state only) — clears the key server-side, then reloads
	// so PHP re-renders the disconnected (empty-input) state.
	function bindDisconnect() {
		var btn = $( 'slash-image-disconnect' );
		if ( ! btn ) { return; }
		btn.addEventListener( 'click', function () {
			btn.disabled    = true;
			btn.textContent = i18n.disconnecting || 'Disconnecting…';
			postForm( {
				action: 'slash_image_disconnect',
				nonce:  cfg.disconnect_nonce,
			} ).then( function ( data ) {
				if ( data && data.success ) {
					window.location.reload();
				} else {
					btn.disabled    = false;
					btn.textContent = i18n.disconnect || 'Disconnect';
				}
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		bindTabs();
		bindConnect();
		bindSegmented();
		bindDependentRow( 'slash-image-resize-toggle', '.slash-image-resize-bounds' );
		// Backups section uses progressive disclosure (show/hide, not grey-out):
		// "Keep backups" off hides the backup-mode pill + the Auto-delete toggle;
		// the retention-days field shows only when BOTH "Keep backups" and
		// "Auto-delete" are on.
		bindRowVisibility( 'slash-image-backups-toggle', '.slash-image-smart-backups-bounds, .slash-image-auto-delete-bounds' );
		bindRowVisibilityMulti( [ 'slash-image-backups-toggle', 'slash-image-auto-delete-toggle' ], '.slash-image-retention-bounds' );
		bindRestoreAll();
		bindDeleteBackups();
		bindHtaccessActions();
		bindSizeGrid();
		bindExclusionsModal();
		bindSaveBulk();
		bindDisconnect();
	} );
} )();
