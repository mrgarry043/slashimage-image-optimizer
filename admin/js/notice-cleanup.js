(function () {
	if ( window.history && window.history.replaceState ) {
		var u = new URL( window.location.href );
		[ 'slash_image_notice', 'slash_image_count', 'slash_image_failed' ].forEach( function ( k ) {
			u.searchParams.delete( k );
		} );
		window.history.replaceState( {}, '', u.toString() );
	}
})();
