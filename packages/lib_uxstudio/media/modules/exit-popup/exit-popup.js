/**
 * UX1 Studio exit-popup front end. No dependencies, no inline code: the server renders the dialog hidden
 * (div#uxs-exit-popup[data-uxs-exit]); this script applies the style, arms the exit detections (mouse leave, tab change,
 * window blur, inactivity, fast scroll up, time on page), enforces device / schedule / frequency and runs the e-mail form.
 * The dialog is a modal: focus moves in and is trapped, Esc closes, focus returns to where it was, the page does not scroll.
 */
( function () {
	'use strict';

	var el = document.getElementById( 'uxs-exit-popup' );
	if ( ! el ) {
		return;
	}
	var cfg;
	try {
		cfg = JSON.parse( el.getAttribute( 'data-uxs-exit' ) || '' );
	} catch ( e ) {
		return;
	}
	if ( ! cfg ) {
		return;
	}

	var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]):not(.uxs-exit__trap),select:not([disabled]),textarea:not([disabled])';
	var KEY = 'uxs_ep';
	var det = cfg.detect || {};
	var msgs = cfg.messages || {};
	var dialog = el.querySelector( '.uxs-exit__dialog' );
	var shown = false;
	var lastFocus = null;
	var teardown = [];
	var reduceMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	// ---- storage ------------------------------------------------------------------------------------------------

	function readCookie( name ) {
		var m = document.cookie.match( new RegExp( '(?:^|; )' + name + '=([^;]*)' ) );
		return m ? decodeURIComponent( m[ 1 ] ) : null;
	}

	function persistentGet( key ) {
		if ( cfg.storage !== 'cookie' ) {
			try {
				return window.localStorage.getItem( key );
			} catch ( e ) {
				// blocked storage: use the cookie
			}
		}
		return readCookie( key );
	}

	function persistentSet( key, value ) {
		if ( cfg.storage !== 'cookie' ) {
			try {
				window.localStorage.setItem( key, value );
				return;
			} catch ( e ) {
				// fall through
			}
		}
		document.cookie = key + '=' + encodeURIComponent( value ) + '; path=/; max-age=31536000; SameSite=Lax' + ( location.protocol === 'https:' ? '; Secure' : '' );
	}

	function sessionGet( key ) {
		try {
			return window.sessionStorage.getItem( key );
		} catch ( e ) {
			return null;
		}
	}

	function sessionSet( key, value ) {
		try {
			window.sessionStorage.setItem( key, value );
		} catch ( e ) {
			// private mode: may repeat
		}
	}

	function device() {
		var w = window.innerWidth;
		return w < 768 ? 'mobile' : w < 1024 ? 'tablet' : 'desktop';
	}

	function eligible() {
		var now = Date.now() / 1000;
		if ( ( cfg.from && now < cfg.from ) || ( cfg.until && now > cfg.until ) ) {
			return false;
		}
		if ( cfg.devices && cfg.devices.length && cfg.devices.indexOf( device() ) === -1 ) {
			return false;
		}
		if ( cfg.frequency === 'always' ) {
			return true;
		}
		if ( persistentGet( KEY + '_done' ) === '1' ) {
			return false; // the visitor already subscribed
		}
		if ( cfg.frequency === 'session' ) {
			return sessionGet( KEY ) !== '1';
		}
		var last = parseInt( persistentGet( KEY ), 10 );
		if ( ! last ) {
			return true;
		}
		return cfg.frequency === 'days' && Date.now() - last > cfg.days * 86400000;
	}

	function remember() {
		if ( cfg.frequency === 'session' ) {
			sessionSet( KEY, '1' );
		} else if ( cfg.frequency !== 'always' ) {
			persistentSet( KEY, String( Date.now() ) );
		}
	}

	// ---- style ----------------------------------------------------------------------------------------------------

	var c = cfg.colors || {};
	el.style.setProperty( '--uxs-ep-bg', c.bg || '#ffffff' );
	el.style.setProperty( '--uxs-ep-title', c.title || '#1f2937' );
	el.style.setProperty( '--uxs-ep-text', c.text || '#4b5563' );
	el.style.setProperty( '--uxs-ep-button', c.button || '#2563eb' );
	el.style.setProperty( '--uxs-ep-button-text', c.buttonText || '#ffffff' );
	el.style.setProperty( '--uxs-ep-width', ( cfg.width || 480 ) + 'px' );
	el.style.setProperty( '--uxs-ep-overlay', String( ( cfg.overlay === undefined ? 60 : cfg.overlay ) / 100 ) );

	// ---- show / hide ----------------------------------------------------------------------------------------------

	function destroyDetection() {
		for ( var i = 0; i < teardown.length; i++ ) {
			teardown[ i ]();
		}
		teardown = [];
	}

	function listen( target, type, fn, opts ) {
		target.addEventListener( type, fn, opts );
		teardown.push( function () {
			target.removeEventListener( type, fn, opts );
		} );
	}

	function timer( id, interval ) {
		teardown.push( function () {
			if ( interval ) {
				window.clearInterval( id );
			} else {
				window.clearTimeout( id );
			}
		} );
	}

	function focusables() {
		return Array.prototype.filter.call( dialog.querySelectorAll( FOCUSABLE ), function ( n ) {
			return n.offsetParent !== null;
		} );
	}

	function onKeydown( e ) {
		if ( ! el.classList.contains( 'is-open' ) ) {
			return;
		}
		if ( e.key === 'Escape' || e.key === 'Esc' ) {
			e.stopPropagation();
			close();
			return;
		}
		if ( e.key !== 'Tab' ) {
			return;
		}
		var items = focusables();
		if ( ! items.length ) {
			e.preventDefault();
			dialog.focus();
			return;
		}
		var first = items[ 0 ];
		var last = items[ items.length - 1 ];
		var current = document.activeElement;
		if ( e.shiftKey && ( current === first || current === dialog || ! dialog.contains( current ) ) ) {
			e.preventDefault();
			last.focus();
		} else if ( ! e.shiftKey && ( current === last || ! dialog.contains( current ) ) ) {
			e.preventDefault();
			first.focus();
		}
	}

	var retrying = false;

	function open() {
		if ( shown || ! eligible() ) {
			return;
		}
		// Never stack two modals: while a popup-manager dialog is open, try again shortly.
		if ( document.documentElement.classList.contains( 'uxs-popup-lock' ) ) {
			if ( ! retrying ) {
				retrying = true;
				window.setTimeout( function () {
					retrying = false;
					open();
				}, 1000 );
			}
			return;
		}
		shown = true;
		destroyDetection();
		remember();
		lastFocus = document.activeElement;
		el.hidden = false;
		void el.offsetWidth;
		el.classList.add( 'is-open' );
		document.documentElement.classList.add( 'uxs-exit-lock' );
		document.addEventListener( 'keydown', onKeydown, true );
		// Focus the e-mail field when there is one, otherwise the dialog itself.
		var field = dialog.querySelector( '.uxs-exit__email' );
		( field || dialog ).focus( { preventScroll: true } );
	}

	function close() {
		if ( ! el.classList.contains( 'is-open' ) ) {
			return;
		}
		el.classList.remove( 'is-open' );
		document.removeEventListener( 'keydown', onKeydown, true );
		var finish = function () {
			el.hidden = true;
			document.documentElement.classList.remove( 'uxs-exit-lock' );
			if ( lastFocus && typeof lastFocus.focus === 'function' && document.contains( lastFocus ) ) {
				lastFocus.focus( { preventScroll: true } );
			}
		};
		if ( reduceMotion ) {
			finish();
		} else {
			window.setTimeout( finish, 260 );
		}
	}

	el.addEventListener( 'click', function ( e ) {
		var target = e.target;
		if ( ! ( target instanceof Element ) ) {
			return;
		}
		var cta = target.closest( '[data-uxs-cta]' );
		if ( cta ) {
			if ( cta.getAttribute( 'href' ) === '#' ) {
				e.preventDefault();
			}
			close();
		} else if ( target.closest( '[data-uxs-close]' ) ) {
			e.preventDefault();
			close();
		}
	} );

	// ---- e-mail form ----------------------------------------------------------------------------------------------

	var form = el.querySelector( '.uxs-exit__form' );
	var message = el.querySelector( '.uxs-exit__message' );

	function say( text, kind ) {
		message.textContent = text;
		message.className = 'uxs-exit__message' + ( kind ? ' is-' + kind : '' );
	}

	if ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var email = form.elements.email;
			var consent = form.elements.consent;
			var trap = form.elements.website;
			var submit = form.querySelector( '.uxs-exit__submit' );
			say( '', '' );
			if ( ! email.value || ! /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test( email.value.trim() ) ) {
				say( msgs.invalid, 'error' );
				email.focus();
				return;
			}
			if ( consent && ! consent.checked ) {
				say( msgs.consent, 'error' );
				consent.focus();
				return;
			}
			if ( ! cfg.subscribeUrl ) {
				// Preview: nothing is stored.
				say( msgs.success, 'success' );
				return;
			}
			submit.disabled = true;
			window
				.fetch( cfg.subscribeUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
					credentials: 'omit',
					body: JSON.stringify( {
						email: email.value.trim(),
						consent: consent ? consent.checked : false,
						website: trap ? trap.value : '',
						page_url: window.location.href,
					} ),
				} )
				.then( function ( response ) {
					return response.json().then( function ( json ) {
						return { ok: response.ok && json && json.ok, json: json };
					} );
				} )
				.then( function ( result ) {
					if ( ! result.ok ) {
						var err = result.json && result.json.error;
						throw new Error( err && err.message ? err.message : msgs.error );
					}
					persistentSet( KEY + '_done', '1' );
					form.hidden = true;
					say( msgs.success, 'success' );
					window.setTimeout( close, 3000 );
				} )
				.catch( function ( err ) {
					say( err && err.message ? err.message : msgs.error, 'error' );
					submit.disabled = false;
				} );
		} );
	}

	// ---- exit detection -------------------------------------------------------------------------------------------

	function startDetection() {
		var root = document.documentElement;

		if ( det.mouseLeave ) {
			var leaveTimer = null;
			var delay = parseInt( det.mouseLeaveDelay, 10 );
			listen( root, 'mouseleave', function ( e ) {
				if ( e.clientY <= 0 ) {
					window.clearTimeout( leaveTimer );
					leaveTimer = window.setTimeout( open, delay >= 0 ? delay : 500 );
				}
			} );
			listen( root, 'mouseenter', function () {
				window.clearTimeout( leaveTimer );
			} );
			teardown.push( function () {
				window.clearTimeout( leaveTimer );
			} );
		}

		if ( det.tabChange ) {
			listen( document, 'visibilitychange', function () {
				if ( document.visibilityState === 'hidden' ) {
					open();
				}
			} );
		}

		if ( det.windowBlur ) {
			listen( window, 'blur', function () {
				window.setTimeout( function () {
					var active = document.activeElement;
					if ( active && active.tagName === 'IFRAME' ) {
						return; // focus moved into an embedded frame, the visitor is still on the page
					}
					open();
				}, 0 );
			} );
		}

		if ( det.idle ) {
			var idleMs = ( parseInt( det.idleTimeout, 10 ) || 30 ) * 1000;
			var idleTimer = window.setTimeout( open, idleMs );
			var reset = function () {
				window.clearTimeout( idleTimer );
				idleTimer = window.setTimeout( open, idleMs );
			};
			[ 'mousemove', 'keydown', 'mousedown', 'touchstart', 'scroll' ].forEach( function ( type ) {
				listen( window, type, reset, { passive: true } );
			} );
			teardown.push( function () {
				window.clearTimeout( idleTimer );
			} );
		}

		if ( det.scrollUp ) {
			var threshold = window.innerWidth <= 768 ? parseInt( det.scrollUpMobile, 10 ) : parseInt( det.scrollUpDesktop, 10 );
			if ( threshold > 0 ) {
				var lastY = window.pageYOffset;
				var scrollTimer = window.setInterval( function () {
					var y = window.pageYOffset;
					if ( lastY - y >= threshold ) {
						open();
					}
					lastY = y;
				}, 100 );
				timer( scrollTimer, true );
			}
		}
	}

	if ( cfg.preview ) {
		window.setTimeout( open, 300 );
		return;
	}
	if ( ! eligible() ) {
		return;
	}

	var top = parseInt( det.timeOnPage, 10 ) || 0;
	if ( top > 0 ) {
		timer( window.setTimeout( open, top * 1000 ) );
	}
	timer( window.setTimeout( startDetection, Math.max( 0, parseInt( cfg.delay, 10 ) || 0 ) * 1000 ) );
} )();
