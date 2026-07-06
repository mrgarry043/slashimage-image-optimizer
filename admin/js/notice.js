( function () {
	'use strict';

	window.addEventListener( 'slash-image:connected', function () {
		var n = document.getElementById( 'slash-image-admin-notice' );
		if ( n && n.parentNode ) {
			n.parentNode.removeChild( n );
		}
	} );

	// Account-error notice (bulk paused on a 401/402): clear its transient
	// server-side when the user clicks WordPress's dismiss (×) button, so it
	// doesn't reappear on the next page load. WP core adds the .notice-dismiss
	// button to is-dismissible notices and handles the visual removal.
	var notice = document.getElementById( 'slash-image-account-notice' );
	var cfg = window.SlashImageNotice || null;
	if ( notice && cfg && cfg.ajax_url && window.fetch ) {
		notice.addEventListener( 'click', function ( e ) {
			var target = e.target;
			var dismiss = target && target.closest ? target.closest( '.notice-dismiss' ) : null;
			if ( ! dismiss ) {
				return;
			}
			var nonce = notice.getAttribute( 'data-slash-image-dismiss' ) || '';
			fetch( cfg.ajax_url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: 'action=slash_image_dismiss_notice&nonce=' + encodeURIComponent( nonce )
			} );
		} );
	}
} )();
