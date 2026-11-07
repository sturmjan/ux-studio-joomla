/**
 * UX Studio popup-manager front end. No dependencies, no inline code: the server renders every matching popup hidden
 * (div.uxs-popup[data-uxs-popup]), this script applies the style, evaluates device / schedule / frequency, waits for
 * the trigger (immediate | delay | scroll | exit intent) and shows the popup as an accessible dialog
 * (focus moved in and trapped for the centred modal variant, Esc closes, focus is returned, scroll lock).
 */
( function () {
	'use strict';

	var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
	var reduceMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	var active = null; // { cfg, el, lastFocus }
	var pending = [];

	// ---- storage ----------------------------------------------------------------------------------------------

	function readCookie( name ) {
		var m = document.cookie.match( new RegExp( '(?:^|; )' + name.replace( /[.$?*|{}()[\]\\/+^]/g, '\\$&' ) + '=([^;]*)' ) );
		return m ? decodeURIComponent( m[ 1 ] ) : null;
	}

	function writeCookie( name, value, days ) {
		var secure = location.protocol === 'https:' ? '; Secure' : '';
		document.cookie = name + '=' + encodeURIComponent( value ) + '; path=/; max-age=' + Math.round( days * 86400 ) + '; SameSite=Lax' + secure;
	}

	function persistentGet( cfg, key ) {
		if ( cfg.storage !== 'cookie' ) {
			try {
				return window.localStorage.getItem( key );
			} catch ( e ) {
				// Storage blocked: fall back to a cookie below.
			}
		}
		return readCookie( key );
	}

	function persistentSet( cfg, key, value ) {
		if ( cfg.storage !== 'cookie' ) {
			try {
				window.localStorage.setItem( key, value );
				return;
			} catch ( e ) {
				// fall through to the cookie
			}
		}
		writeCookie( key, value, 365 );
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
			// Private mode: the popup simply may repeat.
		}
	}

	function keyOf( cfg ) {
		return 'uxs_pm_' + cfg.id;
	}

	// ---- eligibility ------------------------------------------------------------------------------------------

	function device() {
		var w = window.innerWidth;
		return w < 768 ? 'mobile' : w < 1024 ? 'tablet' : 'desktop';
	}

	function eligible( cfg ) {
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
		if ( cfg.stopClick && persistentGet( cfg, keyOf( cfg ) + '_c' ) === '1' ) {
			return false;
		}
		if ( cfg.frequency === 'session' ) {
			return sessionGet( keyOf( cfg ) ) !== '1';
		}
		var last = parseInt( persistentGet( cfg, keyOf( cfg ) ), 10 );
		if ( ! last ) {
			return true;
		}
		return cfg.frequency === 'days' && Date.now() - last > cfg.days * 86400000;
	}

	function remember( cfg ) {
		if ( cfg.frequency === 'session' ) {
			sessionSet( keyOf( cfg ), '1' );
		} else if ( cfg.frequency !== 'always' ) {
			persistentSet( cfg, keyOf( cfg ), String( Date.now() ) );
		}
	}

	// ---- tracking ---------------------------------------------------------------------------------------------

	function track( cfg, event ) {
		if ( ! cfg.track || ! window.fetch ) {
			return;
		}
		try {
			window.fetch( cfg.track, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { popup_id: cfg.id, event: event } ),
				keepalive: true,
				credentials: 'omit',
			} ).catch( function () {} );
		} catch ( e ) {
			// Tracking must never break the page.
		}
	}

	// ---- show / hide ------------------------------------------------------------------------------------------

	function focusables( root ) {
		return Array.prototype.filter.call( root.querySelectorAll( FOCUSABLE ), function ( n ) {
			return n.offsetParent !== null || n === document.activeElement;
		} );
	}

	function onKeydown( e ) {
		// The exit popup owns the keyboard while it is open (Esc / Tab must not act on two dialogs).
		if ( ! active || document.documentElement.classList.contains( 'uxs-exit-lock' ) ) {
			return;
		}
		if ( e.key === 'Escape' || e.key === 'Esc' ) {
			e.stopPropagation();
			close( 'dismiss' );
			return;
		}
		if ( e.key !== 'Tab' || ! active.cfg.modal ) {
			return;
		}
		var dialog = active.el.querySelector( '.uxs-popup__dialog' );
		var items = focusables( dialog );
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

	function show( cfg, el ) {
		if ( active ) {
			pending.push( [ cfg, el ] );
			return;
		}
		// Never stack two modals: wait while the exit popup is open.
		if ( document.documentElement.classList.contains( 'uxs-exit-lock' ) ) {
			window.setTimeout( function () {
				show( cfg, el );
			}, 1000 );
			return;
		}
		active = { cfg: cfg, el: el, lastFocus: document.activeElement };
		remember( cfg );
		track( cfg, 'shown' );
		el.hidden = false;
		// Force a reflow so the transition starts from the closed state.
		void el.offsetWidth;
		el.classList.add( 'is-open' );
		document.addEventListener( 'keydown', onKeydown, true );
		if ( cfg.modal ) {
			document.documentElement.classList.add( 'uxs-popup-lock' );
			el.querySelector( '.uxs-popup__dialog' ).focus( { preventScroll: true } );
		}
	}

	function close( reason ) {
		if ( ! active ) {
			return;
		}
		var cur = active;
		active = null;
		document.removeEventListener( 'keydown', onKeydown, true );
		cur.el.classList.remove( 'is-open' );
		if ( reason !== 'cta' ) {
			track( cur.cfg, 'closed' );
		}
		var finish = function () {
			cur.el.hidden = true;
			document.documentElement.classList.remove( 'uxs-popup-lock' );
			if ( cur.cfg.modal && cur.lastFocus && typeof cur.lastFocus.focus === 'function' && document.contains( cur.lastFocus ) ) {
				cur.lastFocus.focus( { preventScroll: true } );
			}
			var next = pending.shift();
			if ( next ) {
				show( next[ 0 ], next[ 1 ] );
			}
		};
		if ( reduceMotion || cur.cfg.animation === 'none' ) {
			finish();
		} else {
			window.setTimeout( finish, 260 );
		}
	}

	// ---- triggers ---------------------------------------------------------------------------------------------

	function arm( cfg, el ) {
		var fire = function () {
			if ( eligible( cfg ) ) {
				show( cfg, el );
			}
		};
		var once = function ( fn ) {
			var done = false;
			return function () {
				if ( ! done ) {
					done = true;
					fn();
				}
			};
		};

		switch ( cfg.trigger ) {
			case 'immediate':
				window.setTimeout( fire, 0 );
				break;
			case 'scroll': {
				var go = once( fire );
				var check = function () {
					var doc = document.documentElement;
					var range = ( doc.scrollHeight || 0 ) - window.innerHeight;
					var y = window.pageYOffset || doc.scrollTop || 0;
					if ( range > 0 && ( y / range ) * 100 >= cfg.scroll ) {
						window.removeEventListener( 'scroll', check );
						go();
					}
				};
				window.addEventListener( 'scroll', check, { passive: true } );
				check();
				break;
			}
			case 'exit': {
				var trigger = once( fire );
				window.setTimeout( function () {
					// Desktop: the pointer leaves through the top edge (towards tabs / address bar / close button).
					document.documentElement.addEventListener( 'mouseleave', function ( e ) {
						if ( e.clientY <= 0 ) {
							trigger();
						}
					} );
					// Touch: a quick scroll back up is the usual "about to leave" gesture.
					var lastY = window.pageYOffset;
					var lastT = Date.now();
					window.addEventListener(
						'scroll',
						function () {
							var y = window.pageYOffset;
							var t = Date.now();
							if ( lastY - y >= 200 && t - lastT <= 400 && window.matchMedia( '(pointer: coarse)' ).matches ) {
								trigger();
							}
							lastY = y;
							lastT = t;
						},
						{ passive: true }
					);
				}, Math.max( 0, cfg.exitDelay ) * 1000 );
				break;
			}
			default:
				window.setTimeout( fire, Math.max( 0, cfg.delay ) * 1000 );
		}
	}

	// ---- setup ------------------------------------------------------------------------------------------------

	function setup( el ) {
		var cfg;
		try {
			cfg = JSON.parse( el.getAttribute( 'data-uxs-popup' ) || '' );
		} catch ( e ) {
			return;
		}
		if ( ! cfg || ! cfg.id ) {
			return;
		}
		var c = cfg.colors || {};
		var s = el.style;
		s.setProperty( '--uxs-pm-bg', c.bg || '#ffffff' );
		s.setProperty( '--uxs-pm-text', c.text || '#1f2937' );
		s.setProperty( '--uxs-pm-accent', c.accent || '#2563eb' );
		s.setProperty( '--uxs-pm-cta', c.cta || '#ffffff' );
		s.setProperty( '--uxs-pm-width', ( cfg.width || 520 ) + 'px' );
		s.setProperty( '--uxs-pm-overlay', String( ( cfg.overlay || 0 ) / 100 ) );
		el.classList.add( 'uxs-popup--anim-' + ( cfg.animation || 'fade' ) );

		el.addEventListener( 'click', function ( e ) {
			var target = e.target;
			if ( ! ( target instanceof Element ) ) {
				return;
			}
			var cta = target.closest( '[data-uxs-cta]' );
			if ( cta ) {
				track( cfg, 'clicked' );
				if ( cfg.stopClick && cfg.frequency !== 'always' ) {
					persistentSet( cfg, keyOf( cfg ) + '_c', '1' );
				}
				if ( cta.getAttribute( 'href' ) === '#' ) {
					e.preventDefault();
				}
				close( 'cta' );
				return;
			}
			if ( target.closest( '[data-uxs-close]' ) ) {
				e.preventDefault();
				close( 'dismiss' );
			}
		} );

		if ( eligible( cfg ) ) {
			arm( cfg, el );
		}
	}

	function init() {
		var nodes = document.querySelectorAll( '[data-uxs-popup]' );
		for ( var i = 0; i < nodes.length; i++ ) {
			setup( nodes[ i ] );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
