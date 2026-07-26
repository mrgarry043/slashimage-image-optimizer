/**
 * Post-redirect notice housekeeping. Enqueued only when a slash_image_notice
 * arg is present, so this runs on exactly the page load that carries an
 * outcome — which makes it the right place to both surface the notice and
 * clean the URL.
 */
(function () {
	// Scroll the notice into view BEFORE stripping the args. Bulk actions are
	// triggered from wherever the user is in a long media list, but WordPress
	// renders their outcome at the very top of the next page load — so someone
	// acting from the bottom of the list lands mid-page and never sees it.
	// Reading before tidying also keeps the order of intent obvious.
	var notice = document.querySelector( '.slash-image-bulk-notice' );
	if ( notice && notice.scrollIntoView ) {
		// 'center' rather than 'start' so it clears the admin bar, and smooth so
		// the movement is visible — an instant jump reads as a page glitch
		// rather than as the result of what you just did.
		notice.scrollIntoView( { behavior: 'smooth', block: 'center' } );
	}

	if ( window.history && window.history.replaceState ) {
		var u = new URL( window.location.href );
		[ 'slash_image_notice', 'slash_image_count', 'slash_image_failed', 'slash_image_skipped', 'slash_image_cause' ].forEach( function ( k ) {
			u.searchParams.delete( k );
		} );
		window.history.replaceState( {}, '', u.toString() );
	}
})();
