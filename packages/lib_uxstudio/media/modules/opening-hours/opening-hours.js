/* UX Studio Opening Hours: location switcher (shows the card of the selected location). No inline code. */
(function () {
	'use strict';

	function init() {
		document.querySelectorAll('[data-uxs-oh-multi]').forEach(function (wrap) {
			var select = wrap.querySelector('select');
			if (!select) return;
			select.addEventListener('change', function () {
				wrap.querySelectorAll('.uxs-oh').forEach(function (card) {
					card.classList.toggle('is-hidden', card.getAttribute('data-location') !== select.value);
				});
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
