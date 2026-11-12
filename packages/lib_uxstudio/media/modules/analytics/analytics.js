/* UX1 Studio - Visitor Statistics beacon. Cookie-free: sends the path and the referrer once per page view, after render. */
( function () {
	'use strict';
	var script = document.currentScript;
	if ( ! script ) {
		return;
	}
	var endpoint = script.getAttribute( 'data-endpoint' );
	if ( ! endpoint || ! window.fetch ) {
		return;
	}
	if ( script.getAttribute( 'data-dnt' ) === '1' && ( navigator.doNotTrack === '1' || window.doNotTrack === '1' ) ) {
		return;
	}
	try {
		// Plain pathname; non-SEF Joomla URLs (index.php?option=...) keep their query so pages stay distinguishable.
		var path = location.pathname;
		if ( /[?&]option=com_/.test( location.search ) ) {
			path += location.search;
		}
		// Do not count the same page twice within 10 s (F5 spam). The server also rate limits.
		var key = 'uxs_a_' + path;
		var now = Date.now();
		try {
			var last = +( sessionStorage.getItem( key ) || 0 );
			if ( now - last < 10000 ) {
				return;
			}
			sessionStorage.setItem( key, String( now ) );
		} catch ( e ) {}

		fetch( endpoint, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { path: path, ref: document.referrer } ),
			keepalive: true,
			credentials: 'omit',
		} ).catch( function () {} );
	} catch ( e ) {}
} )();
