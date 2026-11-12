/* UX1 Studio Notice Board: subscribe form (progressive enhancement, no inline code). Texts come from data attributes. */
(function () {
	'use strict';

	function init() {
		document.querySelectorAll('form.uxs-nb-subscribe').forEach(function (form) {
			var msg = form.querySelector('.uxs-nb-subscribe__msg');
			var email = form.querySelector('input[name=email]');
			var button = form.querySelector('button[type=submit]');
			form.addEventListener('submit', function (event) {
				event.preventDefault();
				if (!email || !msg) return;
				msg.textContent = form.getAttribute('data-sending') || '';
				if (button) button.disabled = true;
				fetch(form.getAttribute('data-endpoint') || form.getAttribute('action'), {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
					body: JSON.stringify({ email: email.value })
				})
					.then(function (res) {
						if (!res.ok) throw new Error('http ' + res.status);
						form.reset();
						msg.textContent = form.getAttribute('data-ok') || '';
					})
					.catch(function () {
						msg.textContent = form.getAttribute('data-error') || '';
					})
					.finally(function () {
						if (button) button.disabled = false;
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
