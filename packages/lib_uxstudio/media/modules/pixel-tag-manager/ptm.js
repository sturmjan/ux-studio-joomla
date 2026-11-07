/*
 * UX Studio - Pixel & Tag Manager loader. No inline code on the page: the configuration (validated IDs, consent rules)
 * comes in the data-config attribute of this script tag. A provider is injected only after consent for its category.
 *
 * Consent sources (config.consent.mode):
 *   uxstudio - window.UXSConsent.has(category) or the cookie "uxs_consent" holding JSON like {"analytics":true,"marketing":false}
 *   cookie   - a cookie of another consent tool: {analytics:{cookie,match}, marketing:{cookie,match}}
 *   off      - no consent needed (the administrator took responsibility)
 * The page may also fire the event "uxs:consent" on window after the visitor decided; a slow poll covers tools
 * that only write a cookie.
 */
( function () {
	'use strict';

	var script = document.currentScript;
	if ( ! script ) {
		return;
	}
	var cfg;
	try {
		cfg = JSON.parse( script.getAttribute( 'data-config' ) || '{}' );
	} catch ( e ) {
		return;
	}
	var ids = cfg.ids || {};
	var consent = cfg.consent || { mode: 'uxstudio' };
	var nonce = script.nonce || script.getAttribute( 'nonce' ) || '';

	var CATEGORY = { ga4: 'analytics', clarity: 'analytics', ads: 'marketing', gtm: 'marketing', meta: 'marketing', pinterest: 'marketing', seznam: 'marketing' };
	var started = {};
	var gtagLoaded = false;

	function loadScript( src, onload ) {
		var el = document.createElement( 'script' );
		el.async = true;
		el.src = src;
		if ( onload ) {
			el.onload = onload;
		}
		if ( nonce ) {
			el.setAttribute( 'nonce', nonce );
		}
		document.head.appendChild( el );
	}

	function readCookie( name ) {
		if ( ! name ) {
			return '';
		}
		var parts = document.cookie ? document.cookie.split( '; ' ) : [];
		for ( var i = 0; i < parts.length; i++ ) {
			var eq = parts[ i ].indexOf( '=' );
			if ( eq > 0 && parts[ i ].slice( 0, eq ) === name ) {
				var raw = parts[ i ].slice( eq + 1 );
				try {
					return decodeURIComponent( raw );
				} catch ( e ) {
					return raw;
				}
			}
		}
		return '';
	}

	function allowed( category ) {
		if ( consent.mode === 'off' ) {
			return true;
		}
		if ( consent.mode === 'cookie' ) {
			var rule = consent[ category ];
			var value = rule && rule.cookie ? readCookie( rule.cookie ) : '';
			return value !== '' && ( ! rule.match || value.indexOf( rule.match ) !== -1 );
		}
		// uxstudio
		try {
			if ( window.UXSConsent && typeof window.UXSConsent.has === 'function' ) {
				return window.UXSConsent.has( category ) === true;
			}
			var json = JSON.parse( readCookie( 'uxs_consent' ) || 'null' );
			return !! json && json[ category ] === true;
		} catch ( e ) {
			return false;
		}
	}

	/* ---- providers ---- */

	function gtag() {
		window.dataLayer.push( arguments );
	}

	/*
	 * Google Consent Mode v2: everything starts denied, then the categories the visitor allowed are granted. Called
	 * before the first Google tag runs and again whenever the consent may have changed.
	 */
	var googleConsentReady = false;
	var googleConsentState = '';
	function googleConsent() {
		window.dataLayer = window.dataLayer || [];
		window.gtag = window.gtag || gtag;
		if ( ! googleConsentReady ) {
			googleConsentReady = true;
			window.gtag( 'consent', 'default', { ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'denied' } );
		}
		var a = allowed( 'analytics' ) ? 'granted' : 'denied';
		var m = allowed( 'marketing' ) ? 'granted' : 'denied';
		if ( a + m !== googleConsentState ) {
			googleConsentState = a + m;
			window.gtag( 'consent', 'update', { analytics_storage: a, ad_storage: m, ad_user_data: m, ad_personalization: m } );
		}
	}

	// GA4 and Google Ads share gtag.js: load it once, configure every allowed ID.
	function gtagConfig( id ) {
		googleConsent();
		if ( ! gtagLoaded ) {
			gtagLoaded = true;
			window.gtag( 'js', new Date() );
			loadScript( 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent( id ) );
		}
		window.gtag( 'config', id );
	}

	var starters = {
		ga4: function ( id ) {
			gtagConfig( id );
		},
		ads: function ( id ) {
			gtagConfig( id );
		},
		gtm: function ( id ) {
			googleConsent();
			window.dataLayer.push( { 'gtm.start': new Date().getTime(), event: 'gtm.js' } );
			loadScript( 'https://www.googletagmanager.com/gtm.js?id=' + encodeURIComponent( id ) );
		},
		meta: function ( id ) {
			if ( window.fbq ) {
				return;
			}
			var n = ( window.fbq = function () {
				if ( n.callMethod ) {
					n.callMethod.apply( n, arguments );
				} else {
					n.queue.push( arguments );
				}
			} );
			if ( ! window._fbq ) {
				window._fbq = n;
			}
			n.push = n;
			n.loaded = true;
			n.version = '2.0';
			n.queue = [];
			loadScript( 'https://connect.facebook.net/en_US/fbevents.js' );
			window.fbq( 'init', id );
			window.fbq( 'track', 'PageView' );
		},
		clarity: function ( id ) {
			window.clarity =
				window.clarity ||
				function () {
					( window.clarity.q = window.clarity.q || [] ).push( arguments );
				};
			loadScript( 'https://www.clarity.ms/tag/' + encodeURIComponent( id ) );
		},
		pinterest: function ( id ) {
			if ( window.pintrk ) {
				return;
			}
			var p = ( window.pintrk = function () {
				p.queue.push( Array.prototype.slice.call( arguments ) );
			} );
			p.queue = [];
			p.version = '3.0';
			loadScript( 'https://s.pinimg.com/ct/core.js' );
			window.pintrk( 'load', id );
			window.pintrk( 'page' );
		},
		seznam: function ( id ) {
			// rc.js defines window.rc; the hit has to be sent explicitly once it has loaded.
			loadScript( 'https://c.seznam.cz/js/rc.js', function () {
				if ( window.rc && typeof window.rc.retargetingHit === 'function' ) {
					window.rc.retargetingHit( { rtgId: parseInt( id, 10 ), consent: 1 } );
				}
			} );
		},
	};

	var pending = 0;
	var timer = null;

	function check() {
		pending = 0;
		Object.keys( ids ).forEach( function ( key ) {
			if ( started[ key ] || ! starters[ key ] ) {
				return;
			}
			if ( allowed( CATEGORY[ key ] ) ) {
				started[ key ] = true;
				try {
					starters[ key ]( ids[ key ] );
				} catch ( e ) {}
			} else {
				pending++;
			}
		} );
		if ( googleConsentReady ) {
			googleConsent();
		}
		if ( pending === 0 && timer ) {
			clearInterval( timer );
			timer = null;
		}
	}

	check();
	if ( pending > 0 ) {
		window.addEventListener( 'uxs:consent', check );
		document.addEventListener( 'visibilitychange', check );
		// Tools that only write a cookie fire no event: poll gently, give up after 10 minutes.
		var ticks = 0;
		timer = setInterval( function () {
			check();
			if ( ++ticks > 600 && timer ) {
				clearInterval( timer );
				timer = null;
			}
		}, 1000 );
	}
} )();
