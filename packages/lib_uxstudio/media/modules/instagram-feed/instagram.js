/* UX1 Studio - Instagram Feed carousel: arrow buttons for the scroll-snap list (no inline code, CSP friendly). */
( function () {
	'use strict';

	function init( root ) {
		var list = root.querySelector( '.uxs-ig__list' );
		var prev = root.querySelector( '[data-uxs-ig-prev]' );
		var next = root.querySelector( '[data-uxs-ig-next]' );
		if ( ! list || ! prev || ! next || root.classList.contains( 'uxs-ig--js' ) ) {
			return;
		}
		root.classList.add( 'uxs-ig--js' );

		var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

		function update() {
			var max = list.scrollWidth - list.clientWidth;
			prev.hidden = list.scrollLeft <= 2;
			next.hidden = list.scrollLeft >= max - 2;
		}

		function go( dir ) {
			list.scrollBy( { left: dir * list.clientWidth * 0.9, behavior: reduce ? 'auto' : 'smooth' } );
		}

		prev.addEventListener( 'click', function () { go( -1 ); } );
		next.addEventListener( 'click', function () { go( 1 ); } );
		list.addEventListener( 'scroll', update, { passive: true } );
		window.addEventListener( 'resize', update );
		update();
	}

	function boot() {
		var roots = document.querySelectorAll( '[data-uxs-ig-carousel]' );
		for ( var i = 0; i < roots.length; i++ ) {
			init( roots[ i ] );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
