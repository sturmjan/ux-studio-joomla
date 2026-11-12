/* UX1 Studio - Page Load beacon. Sends one anonymous timing sample (Navigation Timing, paint, LCP, CLS) per sampled page view. */
( function () {
	'use strict';
	var script = document.currentScript;
	if ( ! script || ! window.fetch || ! window.performance || ! performance.getEntriesByType ) {
		return;
	}
	var endpoint = script.getAttribute( 'data-endpoint' );
	var rate = parseFloat( script.getAttribute( 'data-rate' ) || '0' );
	if ( ! endpoint || ! ( rate > 0 ) || Math.random() * 100 >= rate || document.prerendering ) {
		return;
	}
	var vitals = script.getAttribute( 'data-vitals' ) === '1';
	var lcp = 0;
	var cls = 0;
	var sent = false;

	if ( vitals && window.PerformanceObserver ) {
		try {
			new PerformanceObserver( function ( list ) {
				var entries = list.getEntries();
				if ( entries.length ) {
					lcp = entries[ entries.length - 1 ].startTime;
				}
			} ).observe( { type: 'largest-contentful-paint', buffered: true } );
		} catch ( e ) {}
		try {
			new PerformanceObserver( function ( list ) {
				list.getEntries().forEach( function ( e ) {
					if ( ! e.hadRecentInput ) {
						cls += e.value;
					}
				} );
			} ).observe( { type: 'layout-shift', buffered: true } );
		} catch ( e ) {}
	}

	function whole( n ) {
		return Math.max( 0, Math.round( n || 0 ) );
	}

	function send() {
		if ( sent ) {
			return;
		}
		var nav = performance.getEntriesByType( 'navigation' )[ 0 ];
		if ( ! nav || ! ( nav.loadEventEnd > 0 ) ) {
			return;
		}
		sent = true;
		var fcp = 0;
		( performance.getEntriesByType( 'paint' ) || [] ).forEach( function ( p ) {
			if ( p.name === 'first-contentful-paint' ) {
				fcp = p.startTime;
			}
		} );
		var path = location.pathname;
		if ( /[?&]option=com_/.test( location.search ) ) {
			path += location.search;
		}
		try {
			fetch( endpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( {
					path: path,
					ttfb: whole( nav.responseStart ),
					fcp: whole( fcp ),
					lcp: whole( lcp ),
					dcl: whole( nav.domContentLoadedEventEnd ),
					load: whole( nav.loadEventEnd ),
					cls: whole( cls * 1000 ),
					size: whole( nav.transferSize ),
					nav: nav.type || '',
					w: window.innerWidth || 0,
				} ),
				keepalive: true,
				credentials: 'omit',
			} ).catch( function () {} );
		} catch ( e ) {}
	}

	// LCP is final once the page is hidden / left (or after a few quiet seconds), so send then.
	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'hidden' ) {
			send();
		}
	} );
	window.addEventListener( 'pagehide', send );
	window.addEventListener( 'load', function () {
		setTimeout( send, 4000 );
	} );
} )();
