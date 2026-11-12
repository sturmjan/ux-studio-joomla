/* UX1 Studio - Review Aggregator: "show more / show less" toggle of long reviews (no inline code, CSP friendly). */
( function () {
	'use strict';
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.uxs-reviews__more' ) : null;
		if ( ! btn ) {
			return;
		}
		var text = btn.closest( '.uxs-reviews__text' );
		var excerpt = text && text.querySelector( '.uxs-reviews__excerpt' );
		var full = text && text.querySelector( '.uxs-reviews__full' );
		if ( ! excerpt || ! full ) {
			return;
		}
		var open = full.hidden;
		full.hidden = ! open;
		excerpt.hidden = open;
		btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		btn.textContent = btn.getAttribute( open ? 'data-less' : 'data-more' );
	} );
}() );
