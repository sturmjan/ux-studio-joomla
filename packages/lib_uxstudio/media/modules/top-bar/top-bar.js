/* UX Studio Top Bar: marquee for long text, slideshow for several messages, dismiss button (cookie). No inline code. */
(function () {
	'use strict';

	var COOKIE = 'uxs_tb_dismiss';
	var bar, track, content;
	var isMarquee = false;
	var resizeTimer;

	function readCookie() {
		var match = document.cookie.match(new RegExp('(?:^|; )' + COOKIE + '=([^;]*)'));
		return match ? decodeURIComponent(match[1]) : '';
	}

	function init() {
		bar = document.getElementById('uxstudio-top-bar');
		if (!bar) return;

		var hash = bar.getAttribute('data-hash');
		if (hash && readCookie() === hash) {
			bar.parentNode.removeChild(bar);
			return;
		}
		var close = bar.querySelector('.uxstudio-top-bar__close');
		if (close && hash) {
			close.addEventListener('click', function () {
				var days = parseInt(bar.getAttribute('data-days'), 10) || 0;
				var cookie = COOKIE + '=' + encodeURIComponent(hash) + '; path=/; SameSite=Lax';
				if (days > 0) cookie += '; max-age=' + days * 86400;
				if (location.protocol === 'https:') cookie += '; Secure';
				document.cookie = cookie;
				bar.parentNode.removeChild(bar);
			});
		}

		track = bar.querySelector('.uxstudio-top-bar__track');
		content = bar.querySelector('.uxstudio-top-bar__content');
		if (!track || !content) return;

		if (bar.hasAttribute('data-slides')) {
			startSlideshow();
			return;
		}

		checkOverflow();
		window.addEventListener('resize', function () {
			clearTimeout(resizeTimer);
			resizeTimer = setTimeout(checkOverflow, 200);
		});
	}

	// Two or more messages: one at a time, switched on a timer (paused on hover and focus).
	function startSlideshow() {
		var slides = track.querySelectorAll('.uxstudio-top-bar__content');
		var index = 0;
		var paused = false;
		var seconds = parseInt(bar.getAttribute('data-interval'), 10) || 6;

		bar.classList.add('uxstudio-top-bar--slides');
		bar.addEventListener('mouseenter', function () { paused = true; });
		bar.addEventListener('mouseleave', function () { paused = false; });
		bar.addEventListener('focusin', function () { paused = true; });
		bar.addEventListener('focusout', function () { paused = false; });

		window.setInterval(function () {
			if (paused || document.hidden) return;
			slides[index].classList.remove('is-active');
			index = (index + 1) % slides.length;
			slides[index].classList.add('is-active');
		}, seconds * 1000);
	}

	function checkOverflow() {
		if (isMarquee) {
			bar.classList.remove('uxstudio-top-bar--marquee');
			var clone = track.querySelector('.uxstudio-top-bar__content--clone');
			if (clone) clone.remove();
			bar.style.removeProperty('--uxstudio-tb-duration');
			isMarquee = false;
		}
		var contentWidth = content.scrollWidth;
		if (contentWidth > bar.offsetWidth) {
			enableMarquee(contentWidth);
		}
	}

	function enableMarquee(contentWidth) {
		var clone = content.cloneNode(true);
		clone.classList.add('uxstudio-top-bar__content--clone');
		clone.setAttribute('aria-hidden', 'true');
		track.appendChild(clone);

		// About 60 px per second.
		bar.style.setProperty('--uxstudio-tb-duration', contentWidth / 60 + 's');
		bar.classList.add('uxstudio-top-bar--marquee');
		isMarquee = true;
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
