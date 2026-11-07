/* UX Studio Forms - front-end behaviour. No inline code: configuration comes from data-* attributes of the form. */
(function () {
	'use strict';

	function qsa(root, sel) {
		return Array.prototype.slice.call(root.querySelectorAll(sel));
	}

	/* ---- conditional fields ---- */

	function valueOf(form, key) {
		var els = qsa(form, '[name="' + key + '"], [name="' + key + '[]"]').filter(function (el) {
			return !el.disabled || el.closest('[data-field]') === null;
		});
		var out = [];
		els.forEach(function (el) {
			if (el.type === 'checkbox' || el.type === 'radio') {
				if (el.checked) {
					out.push(el.value);
				}
			} else if (el.value !== '') {
				out.push(el.value);
			}
		});
		return out;
	}

	function evaluate(form, wrap) {
		var raw = wrap.getAttribute('data-conditions');
		if (!raw) {
			return true;
		}
		var conditions;
		try {
			conditions = JSON.parse(raw);
		} catch (e) {
			return true;
		}
		var results = conditions.map(function (c) {
			var list = valueOf(form, c.field);
			var joined = list.join(',');
			switch (c.operator) {
				case 'equals':
					return list.indexOf(c.value) !== -1;
				case 'not_equals':
					return list.indexOf(c.value) === -1;
				case 'contains':
					return c.value !== '' && joined.toLowerCase().indexOf(String(c.value).toLowerCase()) !== -1;
				case 'empty':
					return joined.trim() === '';
				case 'not_empty':
					return joined.trim() !== '';
				default:
					return true;
			}
		});
		return wrap.getAttribute('data-logic') === 'any' ? results.indexOf(true) !== -1 : results.indexOf(false) === -1;
	}

	function applyConditions(form) {
		// Repeat so that a field depending on another conditional field settles.
		for (var pass = 0; pass < 3; pass++) {
			qsa(form, '[data-conditions]').forEach(function (wrap) {
				var show = evaluate(form, wrap);
				wrap.hidden = !show;
				qsa(wrap, 'input, select, textarea').forEach(function (el) {
					el.disabled = !show;
				});
			});
		}
	}

	/* ---- helpers ---- */

	function clearErrors(form) {
		qsa(form, '.uxs-form__error').forEach(function (p) {
			p.hidden = true;
			p.textContent = '';
		});
		qsa(form, '[aria-invalid]').forEach(function (el) {
			el.removeAttribute('aria-invalid');
		});
	}

	function showError(form, key, message) {
		var wrap = form.querySelector('[data-field="' + key + '"]');
		if (!wrap) {
			return null;
		}
		var p = wrap.querySelector('.uxs-form__error');
		if (p) {
			p.textContent = message;
			p.hidden = false;
		}
		var input = wrap.querySelector('input, select, textarea');
		if (input) {
			input.setAttribute('aria-invalid', 'true');
		}
		return input;
	}

	function readFile(file) {
		return new Promise(function (resolve, reject) {
			var reader = new FileReader();
			reader.onload = function () {
				var result = String(reader.result || '');
				resolve({ name: file.name, data: result.slice(result.indexOf(',') + 1) });
			};
			reader.onerror = function () {
				reject(reader.error);
			};
			reader.readAsDataURL(file);
		});
	}

	function collect(form) {
		var fields = {};
		var seen = {};
		qsa(form, 'input, select, textarea').forEach(function (el) {
			var name = el.name;
			if (!name || el.disabled || el.type === 'file' || el.type === 'submit' || name === 'uxs_website' || name.indexOf('cf-turnstile') === 0) {
				return;
			}
			if (name.slice(-2) === '[]') {
				var base = name.slice(0, -2);
				fields[base] = fields[base] || [];
				if (el.checked) {
					fields[base].push(el.value);
				}
				return;
			}
			if (el.type === 'radio') {
				if (el.checked) {
					fields[name] = el.value;
				} else if (!(name in seen)) {
					fields[name] = '';
				}
				seen[name] = true;
				return;
			}
			if (el.type === 'checkbox') {
				fields[name] = el.checked ? '1' : '';
				return;
			}
			fields[name] = el.value;
		});
		return fields;
	}

	function status(form, text, kind) {
		var box = form.querySelector('.uxs-form__status');
		if (!box) {
			return;
		}
		box.textContent = text;
		box.className = 'uxs-form__status' + (kind ? ' is-' + kind : '');
	}

	/* ---- submit ---- */

	function init(form) {
		if (form.getAttribute('data-uxs-ready')) {
			return;
		}
		form.setAttribute('data-uxs-ready', '1');
		applyConditions(form);
		form.addEventListener('input', function () {
			applyConditions(form);
		});
		form.addEventListener('change', function () {
			applyConditions(form);
		});

		form.addEventListener('submit', function (event) {
			event.preventDefault();
			if (form.getAttribute('data-busy')) {
				return;
			}
			if (!form.checkValidity()) {
				form.reportValidity();
				return;
			}
			clearErrors(form);

			var button = form.querySelector('.uxs-form__submit');
			var original = button ? button.textContent : '';
			var tooLarge = false;
			var jobs = [];
			var fileKeys = [];
			qsa(form, 'input[type="file"]').forEach(function (input) {
				if (input.disabled || !input.files || !input.files.length) {
					return;
				}
				var max = (parseInt(input.getAttribute('data-max-mb'), 10) || 2) * 1048576;
				Array.prototype.forEach.call(input.files, function (file) {
					if (file.size > max) {
						tooLarge = true;
						showError(form, input.name, form.getAttribute('data-msg-size'));
					}
				});
				fileKeys.push(input.name);
				jobs.push(Promise.all(Array.prototype.map.call(input.files, readFile)));
			});
			if (tooLarge) {
				status(form, form.getAttribute('data-msg-size'), 'error');
				return;
			}

			form.setAttribute('data-busy', '1');
			if (button) {
				button.disabled = true;
				button.textContent = form.getAttribute('data-msg-sending');
			}
			status(form, form.getAttribute('data-msg-sending'), '');

			var hp = form.querySelector('[name="uxs_website"]');
			var ts = form.querySelector('[name="cf-turnstile-response"]');

			Promise.all(jobs)
				.then(function (read) {
					var files = {};
					fileKeys.forEach(function (key, i) {
						files[key] = read[i];
					});
					return fetch(form.getAttribute('data-endpoint'), {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
						body: JSON.stringify({
							form_id: parseInt(form.getAttribute('data-form-id'), 10),
							ts: form.getAttribute('data-ts'),
							hp: hp ? hp.value : '',
							turnstile: ts ? ts.value : '',
							page_url: window.location.href,
							fields: collect(form),
							files: files,
						}),
					});
				})
				.then(function (res) {
					return res.json().catch(function () {
						return null;
					});
				})
				.then(function (json) {
					if (!json) {
						throw new Error('bad response');
					}
					if (!json.ok) {
						status(form, (json.error && json.error.message) || form.getAttribute('data-msg-error'), 'error');
						return;
					}
					var data = json.data || {};
					if (data.sent) {
						status(form, data.message || '', 'success');
						form.reset();
						applyConditions(form);
						if (data.redirect) {
							window.location.assign(data.redirect);
						}
						return;
					}
					var first = null;
					Object.keys(data.errors || {}).forEach(function (key) {
						var input = showError(form, key, data.errors[key]);
						first = first || input;
					});
					status(form, data.message || form.getAttribute('data-msg-error'), 'error');
					if (first) {
						first.focus();
					}
				})
				.catch(function () {
					status(form, form.getAttribute('data-msg-error'), 'error');
				})
				.then(function () {
					form.removeAttribute('data-busy');
					if (button) {
						button.disabled = false;
						button.textContent = original;
					}
					if (window.turnstile && form.querySelector('.cf-turnstile')) {
						try {
							window.turnstile.reset();
						} catch (e) {
							/* widget not ready */
						}
					}
				});
		});
	}

	function boot() {
		qsa(document, 'form[data-uxs-form]').forEach(init);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
