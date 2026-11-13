// Browser E2E for the Forms module (+ mod_uxstudio_form): node tests/e2e/forms.mjs [j5|j6|all] [--headed]
// Builds a form in the SPA editor, places it on the site (Joomla module created in com_modules + {uxsform N} shortcode in an
// article), submits it as an anonymous visitor (client + server validation, file upload, honeypot, too-fast), checks the
// archive, file download, CSV export, e-mail log, dark mode, cs-CZ, and deletes everything it created.
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import { launch, context, SITES, sitesFromArgv, openStudio, shot, checker } from './lib.mjs';

const ROOT = new URL('../..', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1');
const env = Object.fromEntries(
	fs.readFileSync(`${ROOT}.env.local`, 'utf8').split(/\r?\n/).map((l) => l.match(/^([A-Z0-9_]+)=(.*)$/)).filter(Boolean).map((m) => [m[1], m[2]])
);
const MYSQL = 'D:/xampp/mysql/bin/mysql.exe';
const TAG = 'FRMX Forms';
const sql = (site, q) => execFileSync(MYSQL, ['-uroot', '--default-character-set=utf8mb4', '-N', '-B', site.db, '-e', q], { encoding: 'utf8' }).trim();
const NL = new RegExp('\r?\n');
/** Poll a condition for up to 8 s (the SPA mutation and its list refresh are asynchronous). */
async function until(fn, ms = 8000) {
	const end = Date.now() + ms;
	while (Date.now() < end) {
		if (await fn()) return true;
		await sleep(250);
	}
	return Boolean(await fn());
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const docRoot = (site) => `D:/xampp/htdocs/${site.db}`;
const uploadDir = (site) => `${docRoot(site)}/administrator/logs/uxs-forms`;
const filesOf = (site, ids) => [...sql(site, `SELECT files FROM jos_uxstudio_forms_submissions WHERE form_id IN (${ids.join(',')})`).matchAll(/"path":"([^"]+)"/g)].map((m) => m[1].replace(/\\/g, ''));

/** Admin login through the real form, optionally choosing the administrator language on the login screen (session only). */
async function login(ctx, site, lang) {
	const page = await ctx.newPage();
	await page.goto(`${site.url}/administrator/index.php`);
	await page.selectOption('#lang', lang);
	await page.fill('#mod-login-username', env.JOOMLA_ADMIN_USER);
	await page.fill('#mod-login-password', env.JOOMLA_ADMIN_PASSWORD);
	await Promise.all([page.waitForLoadState('load'), page.click('#btn-login-submit')]);
	if (await page.locator('#mod-login-username').count()) throw new Error(`admin login failed on ${site.key}`);
	return page;
}

const rowOf = (scope, label) => scope.locator(`xpath=.//div[contains(@class,'uxs-form__row')][label[normalize-space(.)=${JSON.stringify(label)}]]`);
const tab = (page, name) => page.getByRole('tab', { name, exact: true });

async function setTheme(page, theme) {
	await page.evaluate((t) => localStorage.setItem('uxstudio-theme', t), theme);
	await page.reload();
	await page.locator('#uxs-root').waitFor();
	await page.waitForLoadState('networkidle');
}

/** Adds a field through the SPA modal. o: label,key,required,placeholder,help,def,options,terms,minlength,maxlength,min,max,accept[],maxsize,width,html,cond{field,operator,value},multiple */
async function addField(page, type, o = {}) {
	await page.selectOption('select[aria-label="Field type"]', type);
	await page.getByRole('button', { name: 'Add field' }).click();
	const m = page.getByRole('dialog');
	await m.waitFor();
	if (o.label !== undefined) await rowOf(m, 'Label').locator('input').fill(o.label);
	if (o.key) await rowOf(m, 'Field key').locator('input').fill(o.key);
	if (o.placeholder) await rowOf(m, 'Placeholder').locator('input').fill(o.placeholder);
	if (o.help) await rowOf(m, 'Help text').locator('input').fill(o.help);
	if (o.def !== undefined) await rowOf(m, 'Default value').locator('input').fill(o.def);
	if (o.required) await rowOf(m, 'Required').locator('input[type=checkbox]').check();
	if (o.options) await rowOf(m, 'Options').locator('textarea').fill(o.options);
	if (o.terms) await rowOf(m, 'Link to the terms').locator('input').fill(o.terms);
	if (o.html) await rowOf(m, 'Content (HTML)').locator('textarea').fill(o.html);
	if (o.minlength != null) await rowOf(m, 'Length').locator('input').nth(0).fill(String(o.minlength));
	if (o.maxlength != null) await rowOf(m, 'Length').locator('input').nth(1).fill(String(o.maxlength));
	if (o.min != null) await rowOf(m, 'Range').locator('input').nth(0).fill(String(o.min));
	if (o.max != null) await rowOf(m, 'Range').locator('input').nth(1).fill(String(o.max));
	if (o.accept) {
		for (const ext of ['jpg', 'png', 'pdf']) await rowOf(m, 'Allowed file types').getByLabel(ext, { exact: true }).uncheck();
		for (const ext of o.accept) await rowOf(m, 'Allowed file types').getByLabel(ext, { exact: true }).check();
	}
	if (o.maxsize) await rowOf(m, 'Maximum size (MB)').locator('input').fill(String(o.maxsize));
	if (o.multiple) await rowOf(m, 'Several files').locator('input').check();
	if (o.width) await rowOf(m, 'Width').locator('select').selectOption(String(o.width));
	if (o.cond) {
		await m.getByRole('button', { name: 'Add condition' }).click();
		await m.locator('select[aria-label="Field"]').selectOption(o.cond.field);
		await m.locator('select[aria-label="Operator"]').selectOption(o.cond.operator);
		if (o.cond.value !== undefined) await m.locator('input[aria-label="Value"]').fill(o.cond.value);
	}
	await m.getByRole('button', { name: 'Apply' }).click();
	await m.waitFor({ state: 'detached' });
}

/** Other modules (exit popup, top bar) can cover the page on the shared test site: close their overlay when it blocks a click. */
async function guardOverlays(p) {
	for (const sel of ['.uxs-exit__backdrop', '.uxs-popup__backdrop']) {
		await p.addLocatorHandler(p.locator(sel), async () => {
			await p.locator('[data-uxs-close]').first().click({ force: true }).catch(() => {});
			await p.keyboard.press('Escape').catch(() => {});
		});
	}
}

/** Closes a Joomla edit screen (check-in) and waits until the list is shown, so the next goto does not race the redirect. */
async function cancelEdit(p) {
	await p.locator('#toolbar-cancel button, button.button-cancel').first().click().catch(() => {});
	await p.waitForURL((u) => !/layout=edit/.test(u.search), { timeout: 15000 }).catch(() => {});
	await p.waitForLoadState('load');
}

/** Smallest contrast ratio of the visible text of `selector` against its effective background (walks up to the first opaque layer). */
async function minContrast(page, selector) {
	return page.evaluate((sel) => {
		const parse = (s) => {
			const m = s.match(/rgba?\(([^)]+)\)/) ?? s.match(/color\(srgb ([^)]+)\)/);
			if (!m) return null;
			const n = m[1].split(/[\s,\/]+/).filter(Boolean).map(Number);
			const k = s.startsWith('color(') ? 255 : 1;
			return { r: n[0] * k, g: n[1] * k, b: n[2] * k, a: n.length > 3 ? n[3] : 1 };
		};
		const lum = ({ r, g, b }) => {
			const f = (v) => ((v /= 255) <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4);
			return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
		};
		let worst = 21;
		let who = '';
		for (const el of document.querySelectorAll(sel)) {
			if (!el.offsetParent || !(el.textContent ?? '').trim()) continue;
			const fg = parse(getComputedStyle(el).color);
			let node = el;
			let bg = null;
			while (node) {
				const c = parse(getComputedStyle(node).backgroundColor);
				if (c && c.a > 0.5) {
					bg = c;
					break;
				}
				node = node.parentElement;
			}
			bg ??= { r: 255, g: 255, b: 255, a: 1 };
			if (!fg) continue;
			const [hi, lo] = [lum(fg), lum(bg)].sort((x, y) => y - x);
			const ratio = (hi + 0.05) / (lo + 0.05);
			if (ratio < worst) {
				worst = ratio;
				who = (el.textContent ?? '').trim().slice(0, 40);
			}
		}
		return { ratio: Math.round(worst * 10) / 10, who };
	}, selector);
}

/** Edits the article body through the real Joomla article form (TinyMCE) and saves it. */
async function setArticleBody(page, site, articleId, html) {
	for (let attempt = 0; attempt < 3; attempt++) {
		await page.goto(`${site.url}/administrator/index.php?option=com_content&task=article.edit&id=${articleId}`);
		if (await page.locator('#jform_title').waitFor({ timeout: 12000 }).then(() => true, () => false)) break;
	}
	await page.waitForFunction(() => window.tinymce?.get('jform_articletext'), null, { timeout: 15000 });
	await page.evaluate((h) => window.tinymce.get('jform_articletext').setContent(h), html);
	await page.locator('#toolbar-apply button, button.button-apply').first().click();
	await page.waitForLoadState('load');
	await cancelEdit(page);
}

/** Opens the editor of a form from the list. */
async function listRowEdit(page, title) {
	await page.locator('#uxs-root table tbody tr', { hasText: title }).first().getByRole('button', { name: 'Edit' }).click();
	await page.getByRole('button', { name: 'Back to forms' }).waitFor();
}

const bigTxt = 'plain text attachment for the e2e run\n';

/** Poll the toast area / page for a text. */
async function seesText(page, re, ms = 12000) {
	try {
		await page.getByText(re).first().waitFor({ timeout: ms });
		return true;
	} catch {
		return false;
	}
}

async function runSite(browser, site) {
	const c = checker(`forms ${site.key}`);
	const stamp = Date.now().toString(36);
	const title = `${TAG} ${stamp}`;
	const dupTitle = `${TAG} copy ${stamp}`;
	const dblTitle = `${TAG} dbl ${stamp}`;
	let formId = 0;
	let dupId = 0;
	let moduleId = 0;
	let articleId = 0;
	const issues = [];
	const mailBefore = Number(sql(site, `SELECT COALESCE(MAX(id),0) FROM jos_uxstudio_email_log`));

	// Console errors of the admin SPA/Joomla; the intentional bad requests of the anonymous visitor are filtered below.
	const adminIgnore = [/HTTP 422 .*route=forms\/form(\/\d+)?$/, /status of 422/];
	const admin = await context(browser, { ignore: adminIgnore });
	const anon = await context(browser, { ignore: [/HTTP (400|429|403|404) .*route=forms\/submit/, /Failed to load resource: the server responded with a status of (400|429)/, /__xss/, /status of 404/] });
	issues.push(admin.issues, anon.issues);
	try {
		// ---------------------------------------------------------------- 1. module screen (en-GB)
		const page = await login(admin.ctx, site, 'en-GB');
		await openStudio(page, site, '#/module?id=forms');
		await page.evaluate(() => localStorage.setItem('uxstudio-theme', 'light'));
		await page.reload();
		await page.locator('#uxs-root').waitFor();
		await page.waitForLoadState('networkidle');
		c.ok(await tab(page, 'Forms').isVisible() && (await tab(page, 'Submissions').isVisible()) && (await tab(page, 'Settings').isVisible()), 'module has tabs Forms / Submissions / Settings');
		c.ok(/Form Builder/i.test(await page.locator('#uxs-root').innerText()), 'module title shown');

		// Settings tab renders and has the sections.
		await tab(page, 'Settings').click();
		await page.locator('#uxs-root input[type=number], #uxs-root input[type=text]').first().waitFor();
		await page.waitForLoadState('networkidle');
		c.ok((await page.locator('#uxs-root input, #uxs-root select').count()) > 3, 'settings tab shows fields');
		await shot(page, `forms-${site.key}-settings`);
		await tab(page, 'Forms').click();

		// ---------------------------------------------------------------- 2. build the form
		await page.getByRole('button', { name: 'New form' }).click();
		await page.getByRole('button', { name: 'Back to forms' }).waitFor();
		const saveBtn = page.getByRole('button', { name: 'Save', exact: true });
		c.ok(await saveBtn.isDisabled(), 'Save is disabled while the form name is empty');
		await rowOf(page, 'Form name').locator('input').fill(title);
		c.ok(await saveBtn.isEnabled(), 'Save enabled once the form has a name');
		// A new form starts with a starter set (name, e-mail, message, consent); remove it to get the empty state.
		c.ok((await page.locator('table.uxs-table tbody tr').count()) === 4, 'new form starts with 4 starter fields');
		while ((await page.locator('table.uxs-table tbody tr td.is-empty').count()) === 0) {
			await page.locator('table.uxs-table tbody tr').first().getByRole('button', { name: 'Delete' }).click();
		}
		c.ok(await page.getByText('No fields yet.').isVisible(), 'empty fields state shown');

		// Saving without a single input field is a server-side validation error.
		const [emptyRes] = await Promise.all([page.waitForResponse((r) => /route=forms(%2F|\/)form$/.test(r.url()) && r.request().method() === 'POST'), saveBtn.click()]);
		c.ok(emptyRes.status() === 422, `saving an empty form is rejected by the server with 422 (got ${emptyRes.status()})`);
		c.ok(await seesText(page, /Add at least one field|Přidejte alespoň jedno pole/i, 4000), 'saving an empty form shows the server message in an error toast');
		c.ok(Number(sql(site, `SELECT COUNT(*) FROM jos_uxstudio_forms_forms WHERE title='${title}'`)) === 0, 'the rejected empty form was not stored');
		await shot(page, `forms-${site.key}-empty-save-error`);
		await sleep(1200);

		await addField(page, 'text', { label: 'Full name', key: 'name', required: true, minlength: 2, maxlength: 50, placeholder: 'Jane Doe', help: 'Your first and last name', width: 50 });
		await addField(page, 'email', { label: 'E-mail address', key: 'email', required: true, width: 50 });
		await addField(page, 'tel', { label: 'Phone', key: 'phone' });
		await addField(page, 'url', { label: 'Website', key: 'site' });
		await addField(page, 'number', { label: 'Quantity', key: 'qty', min: 1, max: 10 });
		await addField(page, 'date', { label: 'Date', key: 'when' });
		await addField(page, 'select', { label: 'Topic', key: 'topic', required: true, options: 'Sales|sales\nSupport|support\nOther|other' });
		await addField(page, 'text', { label: 'Specify other', key: 'other_text', required: true, cond: { field: 'topic', operator: 'equals', value: 'other' } });
		await addField(page, 'radio', { label: 'Priority', key: 'priority', options: 'Low|low\nHigh|high', def: 'low' });
		await addField(page, 'checkbox_group', { label: 'Interests', key: 'tags', options: 'Alpha\nBeta\nGamma' });
		await addField(page, 'checkbox', { label: 'Subscribe to the newsletter', key: 'newsletter' });
		await addField(page, 'textarea', { label: 'Message', key: 'message', required: true, minlength: 5 });
		await addField(page, 'file', { label: 'Attachment', key: 'attach', accept: ['txt', 'png'], maxsize: 1 });
		await addField(page, 'hidden', { label: '', key: 'src', def: 'e2e' });
		await addField(page, 'html', { key: 'note', html: '<p class="e2e-note">Read <strong>this</strong> first.</p><script>window.__e2e_xss=1</script><scr<script></script>ipt>window.__e2e_nest=1</script><script>window.__e2e_open=1' });
		await addField(page, 'acceptance', { label: 'I agree to the processing of personal data', key: 'consent', required: true, terms: '/privacy' });
		c.ok((await page.locator('table.uxs-table tbody tr').count()) === 16, '16 fields in the editor table');

		// Reorder: move "consent" (last) up, then back down; move first row's "down" and back.
		const names = async () => (await page.locator('table.uxs-table tbody tr td:nth-child(3) code').allInnerTexts());
		await page.locator('table.uxs-table tbody tr').last().getByRole('button', { name: 'Move up' }).click();
		let order = await names();
		c.ok(order[14] === 'consent' && order[15] === 'note', 'Move up swaps the last two fields');
		await page.locator('table.uxs-table tbody tr').nth(14).getByRole('button', { name: 'Move down' }).click();
		order = await names();
		c.ok(order[15] === 'consent', 'Move down restores the order');
		c.ok(await page.locator('table.uxs-table tbody tr').first().getByRole('button', { name: 'Move up' }).isDisabled(), 'first field cannot move up');
		c.ok(await page.locator('table.uxs-table tbody tr').last().getByRole('button', { name: 'Move down' }).isDisabled(), 'last field cannot move down');
		c.ok((await page.locator('table.uxs-table tbody tr', { hasText: 'Specify other' }).innerText()).includes('conditional'), 'conditional badge shown');

		// Duplicate and delete a field.
		await page.locator('table.uxs-table tbody tr', { hasText: 'Phone' }).getByRole('button', { name: 'Duplicate' }).click();
		c.ok((await names()).includes('phone_copy'), 'field duplicated (phone_copy)');
		await page.locator('table.uxs-table tbody tr', { has: page.locator('code', { hasText: /^phone_copy$/ }) }).getByRole('button', { name: 'Delete' }).click();
		c.ok(!(await names()).includes('phone_copy'), 'field removed');

		// Field modal: cancel discards, key is sanitized.
		await page.locator('table.uxs-table tbody tr').first().getByRole('button', { name: 'Edit' }).click();
		let m = page.getByRole('dialog');
		await rowOf(m, 'Field key').locator('input').fill('Bad Key!');
		c.ok((await rowOf(m, 'Field key').locator('input').inputValue()) === 'bad_key_', 'field key is lowercased and sanitized in the modal');
		await shot(page, `forms-${site.key}-field-modal`);
		await m.getByRole('button', { name: 'Cancel' }).click();
		await m.waitFor({ state: 'detached' });
		c.ok((await names())[0] === 'name', 'Cancel keeps the original key');
		// Escape closes the modal too.
		await page.locator('table.uxs-table tbody tr').first().getByRole('button', { name: 'Edit' }).click();
		await page.keyboard.press('Escape');
		await page.getByRole('dialog').waitFor({ state: 'detached', timeout: 3000 }).then(() => c.ok(true, 'Escape closes the field modal'), () => c.ok(false, 'Escape closes the field modal'));

		// Edge: a second field renamed onto an existing key.
		await addField(page, 'text', { label: 'Clash', key: 'name' });
		const clashNames = await names();
		c.ok(new Set(clashNames).size === clashNames.length, `duplicate field keys are not allowed in the editor (got ${clashNames.filter((n) => n === 'name').length}x "name")`);
		await page.locator('table.uxs-table tbody tr').last().getByRole('button', { name: 'Delete' }).click();

		// E-mails tab.
		await tab(page, 'E-mails').click();
		await rowOf(page, 'Notification').locator('input[type=checkbox]').check();
		c.ok(await rowOf(page, 'Recipients').locator('input').isVisible(), 'enabling the notification reveals the recipient fields');
		await rowOf(page, 'Recipients').locator('input').fill('e2e-owner@example.com, second@example.com');
		await rowOf(page, 'Subject').locator('input').fill('FRMX {form_title} #{submission_id} from {name}');
		await rowOf(page, 'Reply-To').locator('select').selectOption('email');
		await rowOf(page, 'Attachments').locator('input').check();
		await rowOf(page, 'Confirmation to the visitor').locator('input').check();
		await rowOf(page, 'Send to the address in').locator('select').selectOption('email');
		await rowOf(page, 'Confirmation subject').locator('input').fill('FRMX confirmation for {name}');
		await rowOf(page, 'Confirmation text').locator('textarea').fill('Thanks {name}, we got it.\n{all_fields}');
		const rowLabel = await rowOf(page, 'Notification').locator('xpath=./label').boundingBox();
		const rowCheck = await rowOf(page, 'Notification').locator('label:has(input[type=checkbox])').boundingBox();
		c.ok(!!rowLabel && !!rowCheck && rowCheck.x >= rowLabel.x + rowLabel.width - 1, 'E-mails tab: the checkbox sits in the control column, not over the row label');
		await shot(page, `forms-${site.key}-tab-mail`);
		// After submit tab.
		await tab(page, 'After submit').click();
		await rowOf(page, 'Button text').locator('input').fill('Send E2E');
		await rowOf(page, 'Thank-you message').locator('input').fill('E2E thanks, received!');
		await shot(page, `forms-${site.key}-tab-after`);
		// Spam protection tab.
		await tab(page, 'Spam protection').click();
		await rowOf(page, 'Minimum fill time (seconds)').locator('input').fill('6');
		await shot(page, `forms-${site.key}-tab-spam`);
		await tab(page, 'Fields').click();

		await saveBtn.click();
		c.ok(await seesText(page, /Form saved\./), 'save shows the success toast');
		await page.getByText('Where to show it').waitFor();
		const shortcodeText = await rowOf(page, 'Where to show it').locator('code').innerText();
		formId = Number((shortcodeText.match(/\d+/) ?? [0])[0]);
		c.ok(formId > 0, `form saved with id ${formId} (${shortcodeText})`);
		await shot(page, `forms-${site.key}-editor-saved`);

		// Back to the list, the form is listed with its facts.
		await page.getByRole('button', { name: 'Back to forms' }).click();
		const listRow = page.locator('#uxs-root table tbody tr', { hasText: title });
		await listRow.waitFor();
		const rowText = await listRow.innerText();
		c.ok(/Published/.test(rowText) && rowText.includes(`{uxsform ${formId}}`), 'list shows the form as Published with its shortcode');
		c.ok(/\b15\b/.test(rowText), 'list shows the input field count (15, text block excluded)');

		// Re-open: values persisted.
		await listRow.getByRole('button', { name: 'Edit' }).click();
		await page.getByRole('button', { name: 'Back to forms' }).waitFor();
		c.ok((await page.locator('table.uxs-table tbody tr').count()) === 16, 're-opened form still has 16 fields');
		await tab(page, 'After submit').click();
		c.ok((await rowOf(page, 'Button text').locator('input').inputValue()) === 'Send E2E', 'button text persisted');
		await tab(page, 'Spam protection').click();
		c.ok((await rowOf(page, 'Minimum fill time (seconds)').locator('input').inputValue()) === '6', 'min fill time persisted');
		await tab(page, 'E-mails').click();
		c.ok((await rowOf(page, 'Reply-To').locator('select').inputValue()) === 'email', 'reply-to field persisted');
		await page.getByRole('button', { name: 'Back to forms' }).click();

		// Leaving the editor with unsaved changes asks for confirmation (and Cancel keeps the draft).
		await listRowEdit(page, title);
		await rowOf(page, 'Form name').locator('input').fill(`${title} unsaved`);
		await page.getByRole('button', { name: 'Back to forms' }).click();
		const leaveDlg = page.locator('.uxs-modal').last();
		c.ok(await leaveDlg.getByText(/unsaved changes/i).isVisible(), 'Back with unsaved changes asks for confirmation');
		await leaveDlg.getByRole('button', { name: 'Cancel' }).click();
		c.ok((await rowOf(page, 'Form name').locator('input').inputValue()) === `${title} unsaved`, 'Cancel in the confirmation keeps the editor and the draft');
		await page.getByRole('button', { name: 'Back to forms' }).click();
		await page.locator('.uxs-modal').last().getByRole('button', { name: 'Leave' }).click();
		await page.locator('#uxs-root table tbody tr', { hasText: title }).first().waitFor();
		c.ok(sql(site, `SELECT title FROM jos_uxstudio_forms_forms WHERE id=${formId}`) === title, 'leaving without saving discards the change');

		// ---------------------------------------------------------------- 2b. editor robustness: double click, nonsense values, server errors, keyboard, mobile
		await page.getByRole('button', { name: 'New form' }).click();
		await page.getByRole('button', { name: 'Back to forms' }).waitFor();
		await rowOf(page, 'Form name').locator('input').fill(dblTitle);
		await saveBtn.dblclick();
		c.ok(await seesText(page, /Form saved\./), 'double-click on Save of a new form saves');
		await sleep(1500);
		c.ok(Number(sql(site, `SELECT COUNT(*) FROM jos_uxstudio_forms_forms WHERE title='${dblTitle}'`)) === 1, 'double-click on Save creates exactly one form (no orphan duplicate)');
		const dblId = Number(sql(site, `SELECT COALESCE(MAX(id),0) FROM jos_uxstudio_forms_forms WHERE title='${dblTitle}'`));

		// Nonsense values are refused in the field modal (inline alert, the modal stays open).
		const modal = () => page.getByRole('dialog');
		const apply = async () => modal().getByRole('button', { name: 'Apply' }).click();
		await page.locator('table.uxs-table tbody tr').first().getByRole('button', { name: 'Edit' }).click();
		await modal().waitFor();
		await rowOf(modal(), 'Length').locator('input').nth(0).fill('50');
		await rowOf(modal(), 'Length').locator('input').nth(1).fill('2');
		await apply();
		c.ok((await modal().getByRole('alert').innerText()).length > 5 && (await modal().isVisible()), 'min length greater than max length is refused in the field modal');
		await rowOf(modal(), 'Length').locator('input').nth(0).fill('2');
		await rowOf(modal(), 'Length').locator('input').nth(1).fill('50');
		await rowOf(modal(), 'Field key').locator('input').fill('1mail');
		await apply();
		c.ok(/start with a letter/i.test(await modal().getByRole('alert').innerText()), 'a field key starting with a digit is refused');
		await modal().getByRole('button', { name: 'Cancel' }).click();
		await page.selectOption('select[aria-label="Field type"]', 'select');
		await page.getByRole('button', { name: 'Add field' }).click();
		await modal().waitFor();
		await rowOf(modal(), 'Options').locator('textarea').fill('');
		await rowOf(modal(), 'Required').locator('input').check();
		await apply();
		c.ok(/at least one option/i.test(await modal().getByRole('alert').innerText()), 'a drop-down without options is refused');
		await modal().getByRole('button', { name: 'Cancel' }).click();
		await page.locator('table.uxs-table tbody tr').last().getByRole('button', { name: 'Delete' }).click();
		await page.selectOption('select[aria-label="Field type"]', 'number');
		await page.getByRole('button', { name: 'Add field' }).click();
		await modal().waitFor();
		await rowOf(modal(), 'Range').locator('input').nth(0).fill('10');
		await rowOf(modal(), 'Range').locator('input').nth(1).fill('1');
		await apply();
		c.ok(/minimum value/i.test(await modal().getByRole('alert').innerText()), 'a number range with min > max is refused');
		await shot(page, `forms-${site.key}-field-modal-error`);
		await modal().getByRole('button', { name: 'Cancel' }).click();
		await page.locator('table.uxs-table tbody tr').last().getByRole('button', { name: 'Delete' }).click();

		// Keyboard: Add field opens the modal with focus inside, Escape closes it.
		await page.selectOption('select[aria-label="Field type"]', 'text');
		await page.getByRole('button', { name: 'Add field' }).focus();
		await page.keyboard.press('Enter');
		await modal().waitFor();
		c.ok(await until(() => page.evaluate(() => Boolean(document.activeElement?.closest('[role=dialog]'))), 3000), 'keyboard: the field modal takes the focus');
		await page.keyboard.press('Escape');
		await modal().waitFor({ state: 'detached', timeout: 3000 }).catch(() => {});
		await page.locator('table.uxs-table tbody tr').last().getByRole('button', { name: 'Delete' }).click();

		// Server-side validation of the settings: the toast carries the reason and nothing is stored.
		const postForm = () => page.waitForResponse((r) => /route=forms(%2F|\/)form(%2F|\/)\d+$/.test(r.url()) && r.request().method() === 'POST');
		const rejected = async (label, re) => {
			const [res] = await Promise.all([postForm(), saveBtn.click()]);
			c.ok(res.status() === 422, `${label}: the server answers 422 (got ${res.status()})`);
			c.ok(await seesText(page, re, 4000), `${label}: the toast explains the problem (${(await page.locator('[role=status], [role=alert]').allInnerTexts()).join(' / ').replace(/\s+/g, ' ').slice(0, 160)})`);
			await sleep(400);
		};
		await tab(page, 'E-mails').click();
		await rowOf(page, 'Recipients').locator('input').fill('not-an-email, foo@');
		await rejected('invalid recipients', /not a valid e-mail/i);
		c.ok(sql(site, `SELECT JSON_UNQUOTE(JSON_EXTRACT(settings,'$.to')) FROM jos_uxstudio_forms_forms WHERE id=${dblId}`) === '', 'invalid recipients were not stored');
		await rowOf(page, 'Recipients').locator('input').fill('ok@example.com');
		await tab(page, 'After submit').click();
		await rowOf(page, 'Redirect to').locator('input').fill('javascript:alert(1)');
		await rejected('javascript: redirect', /enter a path starting with/i);
		await rowOf(page, 'Redirect to').locator('input').fill('');
		await tab(page, 'Spam protection').click();
		await rowOf(page, 'Minimum fill time (seconds)').locator('input').fill('-5');
		await rejected('negative fill time', /between 0 and 60/i);
		await rowOf(page, 'Minimum fill time (seconds)').locator('input').fill('3');
		await saveBtn.click();
		c.ok(await seesText(page, /Form saved\./), 'after the rejected saves a valid save works again (the Save guard is released)');
		c.ok(await until(() => sql(site, `SELECT JSON_UNQUOTE(JSON_EXTRACT(settings,'$.to')) FROM jos_uxstudio_forms_forms WHERE id=${dblId}`) === 'ok@example.com'), 'valid recipient stored');

		// Mobile viewport: the editor must not overflow the page horizontally and every row action stays reachable.
		await tab(page, 'Fields').click();
		await page.setViewportSize({ width: 390, height: 844 });
		await sleep(400);
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
		c.ok(overflow <= 1, `mobile 390px: no horizontal page overflow in the editor (${overflow}px)`);
		const lastBtn = page.locator('table.uxs-table tbody tr').first().getByRole('button', { name: 'Delete' });
		await lastBtn.scrollIntoViewIfNeeded();
		const bx = await lastBtn.boundingBox();
		c.ok(!!bx && bx.x >= 0 && bx.x + bx.width <= 391, 'mobile 390px: the row actions can be scrolled into view');
		await shot(page, `forms-${site.key}-editor-mobile`);
		await page.setViewportSize({ width: 1400, height: 900 });
		await page.getByRole('button', { name: 'Back to forms' }).click();
		await page.locator('#uxs-root table tbody tr', { hasText: dblTitle }).waitFor();

		// ---------------------------------------------------------------- 3. Joomla module via com_modules
		await page.goto(`${site.url}/administrator/index.php?option=com_modules&view=select&client_id=0`);
		await page.getByText('UX1 Studio - Form', { exact: true }).first().click();
		await page.waitForLoadState('load');
		await page.fill('#jform_title', `${TAG} module ${stamp}`);
		await page.selectOption('#jform_params_form_id', String(formId));
		// Position: custom value in the fancy-select.
		await page.locator('joomla-field-fancy-select .choices').first().click();
		const pos = page.locator('joomla-field-fancy-select input.choices__input--cloned').first();
		await pos.fill('sidebar-left');
		const opt = page.locator('joomla-field-fancy-select .choices__list--dropdown .choices__item--selectable[data-value="sidebar-left"]').first();
		if (await opt.isVisible().catch(() => false)) await opt.click();
		else await page.keyboard.press('Enter');
		await page.locator('#toolbar-apply button, button.button-apply').first().click();
		await page.waitForLoadState('load');
		const msg = (await page.locator('joomla-alert, #system-message-container').allInnerTexts()).join(' ');
		const mm = page.url().match(/[?&]id=(\d+)/);
		moduleId = mm ? Number(mm[1]) : Number(sql(site, `SELECT COALESCE(MAX(id),0) FROM jos_modules WHERE title='${TAG} module ${stamp}'`));
		c.ok(moduleId > 0 && /saved|uložen/i.test(msg), `Joomla module created in com_modules (id ${moduleId})`);
		const modRow = sql(site, `SELECT CONCAT(published,'|',position,'|',params) FROM jos_modules WHERE id=${moduleId}`);
		c.ok(modRow.includes(`"form_id":"${formId}"`), `module params keep form_id (${modRow})`);
		if (!/\|sidebar-left\|/.test(modRow)) {
			// The position picker is finicky in headless mode; set the position the way Save would, still exercising the module rendering.
			sql(site, `UPDATE jos_modules SET position='sidebar-left' WHERE id=${moduleId}`);
			console.log('NOTE position set through DB (UI picker did not keep the typed value)');
		}
		if (!/^1\|/.test(modRow)) sql(site, `UPDATE jos_modules SET published=1 WHERE id=${moduleId}`);
		const assigned = sql(site, `SELECT COUNT(*) FROM jos_modules_menu WHERE moduleid=${moduleId} AND menuid=0`);
		if (assigned === '0') sql(site, `INSERT IGNORE INTO jos_modules_menu (moduleid, menuid) VALUES (${moduleId}, 0)`);
		await cancelEdit(page);

		// ---------------------------------------------------------------- 4. article with the shortcode via com_content
		for (let attempt = 0; attempt < 3; attempt++) {
			await page.goto(`${site.url}/administrator/index.php?option=com_content&task=article.add`);
			if (await page.locator('#jform_title').waitFor({ timeout: 12000 }).then(() => true, () => false)) break;
			console.log(`NOTE article.add attempt ${attempt + 1} landed on ${page.url()}`);
		}
		await page.fill('#jform_title', `${TAG} article ${stamp}`);
		await page.waitForFunction(() => window.tinymce?.get('jform_articletext'), null, { timeout: 15000 }).catch(() => {});
		await page.evaluate((id) => window.tinymce.get('jform_articletext').setContent(`<p>Intro before.</p><p>{uxsform ${id}}</p><p>Draft:</p><p>{uxsform 99999}</p><p>Outro after.</p>`), formId);
		await page.locator('#toolbar-apply button, button.button-apply').first().click();
		await page.waitForLoadState('load');
		const am = page.url().match(/[?&]id=(\d+)/);
		articleId = am ? Number(am[1]) : Number(sql(site, `SELECT COALESCE(MAX(id),0) FROM jos_content WHERE title='${TAG} article ${stamp}'`));
		c.ok(articleId > 0 && sql(site, `SELECT introtext FROM jos_content WHERE id=${articleId}`).includes(`{uxsform ${formId}}`), `article with the shortcode created in com_content (id ${articleId})`);
		if (articleId && sql(site, `SELECT state FROM jos_content WHERE id=${articleId}`) !== '1') sql(site, `UPDATE jos_content SET state=1 WHERE id=${articleId}`);
		await cancelEdit(page);

		// ---------------------------------------------------------------- 5. anonymous visitor
		const v = await anon.ctx.newPage();
		await guardOverlays(v);
		const artUrl = `${site.url}/index.php?option=com_content&view=article&id=${articleId}&catid=2`;
		let loadedAt = Date.now();
		await v.goto(artUrl);
		let form = v.locator(`.com-content-article form[data-uxs-form][data-form-id="${formId}"]`).first();
		await form.waitFor();
		const forms = await v.locator(`form[data-uxs-form][data-form-id="${formId}"]`).count();
		c.ok(forms >= 2, `form rendered both by the shortcode and by the Joomla module on the page (${forms}x)`);
		c.ok((await v.locator('form[data-uxs-form]').count()) >= forms, 'forms on the page rendered');
		c.ok((await v.locator('.e2e-note').count()) > 0 && (await v.evaluate(() => window.__e2e_xss)) === undefined, 'text block is rendered and its <script> does not run');
		const bodyText = await v.locator('body').innerText();
		c.ok(!/__e2e_(xss|nest|open)/.test(bodyText), 'text block: script bodies (also nested and unclosed) do not leak as visible text');
		c.ok(!/__e2e_/.test(sql(site, `SELECT fields FROM jos_uxstudio_forms_forms WHERE id=${formId}`)), 'text block: stored HTML has no script content');
		c.ok((await v.locator('text=Intro before.').count()) > 0 && (await v.locator('text=Outro after.').count()) > 0, 'article text around the shortcode kept; unknown form id renders nothing');
		c.ok((await form.locator('button.uxs-form__submit').innerText()) === 'Send E2E', 'custom button text shown');
		c.ok((await form.locator('[data-field="other_text"]').isHidden()), 'conditional field hidden initially');
		c.ok((await form.locator('[data-field="src"]').count()) === 1 && (await form.locator('input[name=src]').inputValue()) === 'e2e', 'hidden field carries its value');
		c.ok((await form.locator('input[name=uxs_website]').evaluate((el) => el.closest('.uxs-form__hp')?.hidden)) === true, 'honeypot wrapper is hidden');
		c.ok((await form.locator('[data-field="consent"] a[href$="/privacy"]').count()) === 1, 'consent field links to the terms');
		await shot(v, `forms-${site.key}-frontend-article-light`);

		// 5a. native (browser) validation: empty submit sends no request.
		let submits = 0;
		v.on('request', (r) => /route=forms\/submit/.test(r.url()) && submits++);
		await form.locator('button.uxs-form__submit').click();
		await sleep(300);
		c.ok(submits === 0, 'empty submit is stopped by browser validation (no request)');
		c.ok((await form.locator('input[name=name]').evaluate((el) => !el.validity.valid)), 'required name is invalid');

		// 5b. conditional field becomes visible & required.
		await form.locator('select[name=topic]').selectOption('other');
		c.ok(await form.locator('[data-field="other_text"]').isVisible(), 'conditional field shown when topic = Other');
		await form.locator('select[name=topic]').selectOption('sales');
		c.ok(await form.locator('[data-field="other_text"]').isHidden(), 'conditional field hidden again');
		c.ok(await form.locator('input[name=other_text]').isDisabled(), 'hidden conditional field is disabled (not submitted)');

		// 5c. client-side file size check (1 MB limit, 1.5 MB file).
		const fill = async (f, extra = {}) => {
			const d = { name: 'Jane Tester', email: `jane.${stamp}@example.com`, phone: '+420 123 456 789', site: 'https://example.com', qty: '3', when: '2026-11-07', message: 'Hello from the e2e run', ...extra };
			for (const [k, val] of Object.entries(d)) await f.locator(`[name="${k}"]`).fill(val);
			await f.locator('select[name=topic]').selectOption('sales');
			await f.locator('input[name=priority][value=high]').check();
			await f.locator('input[name="tags[]"][value=Alpha]').check();
			await f.locator('input[name="tags[]"][value=Gamma]').check();
			await f.locator('input[name=newsletter]').check();
			await f.locator('input[name=consent]').check();
		};
		await fill(form);
		await form.locator('input[type=file]').setInputFiles({ name: 'big.txt', mimeType: 'text/plain', buffer: Buffer.alloc(1.5 * 1048576, 'a') });
		await form.locator('button.uxs-form__submit').click();
		await sleep(400);
		c.ok(submits === 0, 'oversized file stopped on the client (no request)');
		await until(async () => (await form.locator('.uxs-form__status').innerText()).length > 3, 4000);
		const sizeMsg = await form.locator('.uxs-form__status').innerText();
		c.ok(sizeMsg.length > 3 && (await form.locator('[data-field="attach"] .uxs-form__error').isVisible()), `oversized file shows an error under the field and in the status ("${sizeMsg}")`);
		await shot(v, `forms-${site.key}-frontend-file-too-large`);

		// 5d. too fast (min fill time 6 s): fresh page, valid data, submit right away -> rejected.
		await v.reload();
		form = v.locator(`.com-content-article form[data-uxs-form][data-form-id="${formId}"]`).first();
		await form.waitFor();
		loadedAt = Date.now();
		// Only the required fields, filled as fast as possible.
		await form.locator('[name=name]').fill('Fast Tester');
		await form.locator('[name=email]').fill(`fast.${stamp}@example.com`);
		await form.locator('select[name=topic]').selectOption('sales');
		await form.locator('[name=message]').fill('Too fast to be human');
		await form.locator('input[name=consent]').check();
		const fastBefore = (Date.now() - loadedAt) / 1000;
		await Promise.all([v.waitForResponse((r) => /route=forms\/submit/.test(r.url())), form.locator('button.uxs-form__submit').click()]);
		await sleep(300);
		const fastMsg = await form.locator('.uxs-form__status').innerText();
		if (fastBefore < 4) {
			c.ok(/fast|rychle|rychl/i.test(fastMsg) && (await form.locator('.uxs-form__status.is-error').count()) === 1, `submission before the minimum fill time is rejected ("${fastMsg}")`);
			c.ok(Number(sql(site, `SELECT COUNT(*) FROM jos_uxstudio_forms_submissions WHERE form_id=${formId}`)) === 0, 'too-fast submission was not stored');
		} else {
			console.log(`NOTE too-fast case skipped: filling took ${fastBefore}s`);
		}
		c.ok(await form.locator('button.uxs-form__submit').isEnabled(), 'submit button re-enabled after an error');

		// 5e. server-side validation: wrong phone + wrong file extension (the accept attribute is not enforced by the server-side-only check).
		await sleep(Math.max(0, 6500 - (Date.now() - loadedAt)));
		await form.locator('input[name=phone]').fill('call me maybe');
		await form.locator('input[type=file]').setInputFiles({ name: 'evil.php', mimeType: 'text/plain', buffer: Buffer.from('<?php echo 1;') });
		await Promise.all([v.waitForResponse((r) => /route=forms\/submit/.test(r.url())), form.locator('button.uxs-form__submit').click()]);
		await sleep(300);
		const phoneErr = await form.locator('[data-field="phone"] .uxs-form__error').innerText();
		const fileErr = await form.locator('[data-field="attach"] .uxs-form__error').innerText();
		c.ok(phoneErr.length > 3, `server error shown under the phone field ("${phoneErr}")`);
		c.ok(fileErr.length > 3, `server rejects a .php upload ("${fileErr}")`);
		c.ok(
			(await form.locator('textarea[name=message]').getAttribute('maxlength')) === '10000' &&
				(await form.locator('input[name=name]').getAttribute('maxlength')) === '50' &&
				(await form.locator('input[name=email]').getAttribute('maxlength')) === '1000',
			'long-text limits are visible to the browser (textarea 10000, custom 50, default 1000)'
		);
		// Bypassing the attribute: the server must refuse an over-long text instead of silently cutting it.
		await form.locator('textarea[name=message]').evaluate((el) => {
			el.removeAttribute('maxlength');
			el.value = 'x'.repeat(10100);
		});
		await Promise.all([v.waitForResponse((r) => /route=forms\/submit/.test(r.url())), form.locator('button.uxs-form__submit').click()]);
		await sleep(300);
		const longErr = await form.locator('[data-field="message"] .uxs-form__error').innerText();
		c.ok(/10000/.test(longErr), `an over-long text is refused with a message, not cut silently ("${longErr}")`);
		c.ok(Number(sql(site, `SELECT COUNT(*) FROM jos_uxstudio_forms_submissions WHERE form_id=${formId}`)) === 0, 'the over-long submission was not stored');
		await form.locator('textarea[name=message]').fill('Hello from the e2e run');
		c.ok(await form.locator('input[name=phone]').evaluate((el) => el.getAttribute('aria-invalid') === 'true'), 'invalid phone has aria-invalid');
		c.ok(Number(sql(site, `SELECT COUNT(*) FROM jos_uxstudio_forms_submissions WHERE form_id=${formId}`)) === 0, 'invalid submission was not stored');
		await shot(v, `forms-${site.key}-frontend-server-errors`);

		// 5f. honeypot: pretends success, stores nothing.
		await form.locator('input[name=phone]').fill('+420 111 222 333');
		await form.locator('input[type=file]').setInputFiles({ name: 'ok.txt', mimeType: 'text/plain', buffer: Buffer.from(bigTxt) });
		await form.locator('input[name=uxs_website]').evaluate((el) => (el.value = 'http://spam.example'));
		await Promise.all([v.waitForResponse((r) => /route=forms\/submit/.test(r.url())), form.locator('button.uxs-form__submit').click()]);
		await sleep(300);
		c.ok((await form.locator('.uxs-form__status.is-success').count()) === 1, 'honeypot submission gets a (fake) success');
		c.ok(Number(sql(site, `SELECT COUNT(*) FROM jos_uxstudio_forms_submissions WHERE form_id=${formId}`)) === 0, 'honeypot submission was not stored');

		// 5g. the real submission (page open > 6 s).
		await v.reload();
		const form2 = v.locator(`.com-content-article form[data-uxs-form][data-form-id="${formId}"]`).first();
		await form2.waitFor();
		const reloadedAt = Date.now();
		await fill(form2, { message: '=cmd|calc!A0 with "quotes"; and; semicolons' });
		await form2.locator('input[type=file]').setInputFiles({ name: 'report.txt', mimeType: 'text/plain', buffer: Buffer.from(bigTxt) });
		await sleep(Math.max(0, 6500 - (Date.now() - reloadedAt)));
		const mailMax = Number(sql(site, `SELECT COALESCE(MAX(id),0) FROM jos_uxstudio_email_log`));
		await Promise.all([v.waitForResponse((r) => /route=forms\/submit/.test(r.url())), form2.locator('button.uxs-form__submit').click()]);
		await sleep(500);
		const okMsg = await form2.locator('.uxs-form__status').innerText();
		c.ok(okMsg === 'E2E thanks, received!' && (await form2.locator('.uxs-form__status.is-success').count()) === 1, `confirmation message shown ("${okMsg}")`);
		c.ok((await form2.locator('input[name=name]').inputValue()) === '', 'form is reset after success');
		c.ok(Number(sql(site, `SELECT COUNT(*) FROM jos_uxstudio_forms_submissions WHERE form_id=${formId}`)) === 1, 'submission stored in the archive');
		const myFiles = filesOf(site, [formId]);
		c.ok(myFiles.length === 1 && fs.existsSync(`${uploadDir(site)}/${myFiles[0]}`), 'uploaded file stored on disk');
		await shot(v, `forms-${site.key}-frontend-success`);

		// 5h. direct access to a stored file is denied.
		const direct = await anon.ctx.request.get(`${site.url}/administrator/logs/uxs-forms/${myFiles[0]}`);
		c.ok([403, 404].includes(direct.status()), `stored file is not downloadable directly (HTTP ${direct.status()})`);

		// 5i. e-mail log: notification and confirmation were captured with substituted placeholders.
		const subjects = sql(site, `SELECT CONCAT(subject,' => ',to_email) FROM jos_uxstudio_email_log WHERE id>${mailMax} ORDER BY id`).split(NL);
		c.ok(subjects.some((s) => s.startsWith('FRMX ' + title) && s.includes('e2e-owner@example.com') && /from Jane Tester/.test(s)), `owner notification logged with substituted subject (${subjects.join(' | ')})`);
		c.ok(subjects.some((s) => s.startsWith('FRMX confirmation for Jane Tester') && s.includes(`jane.${stamp}@example.com`)), 'visitor confirmation logged');

		// 5j. the Joomla module instance (left sidebar) also submits.
		await v.reload();
		const modForm2 = v.locator(`.container-sidebar-left form[data-uxs-form][data-form-id="${formId}"]`).first();
		await modForm2.waitFor();
		const reloadedAt2 = Date.now();
		await fill(modForm2, { name: 'Module Visitor', email: `module.${stamp}@example.com`, message: 'Sent from the module instance' });
		await modForm2.locator('input[type=file]').setInputFiles({ name: 'via-module.txt', mimeType: 'text/plain', buffer: Buffer.from('module upload\n') });
		await sleep(Math.max(0, 6500 - (Date.now() - reloadedAt2)));
		await Promise.all([v.waitForResponse((r) => /route=forms\/submit/.test(r.url())), modForm2.locator('button.uxs-form__submit').click()]);
		await sleep(400);
		c.ok(Number(sql(site, `SELECT COUNT(*) FROM jos_uxstudio_forms_submissions WHERE form_id=${formId}`)) === 2, 'submission through the Joomla module instance stored');

		// ---------------------------------------------------------------- 6. archive in the administration
		await openStudio(page, site, '#/module?id=forms');
		await tab(page, 'Submissions').click();
		await page.locator('select[aria-label="Form"]').selectOption(String(formId));
		await page.waitForLoadState('networkidle');
		const rows = page.locator('#uxs-root table tbody tr');
		await rows.first().waitFor();
		c.ok((await rows.count()) === 2, 'archive lists the 2 submissions of the form');
		c.ok(/2 submissions/.test(await page.locator('#uxs-root').innerText()), 'archive shows the total');
		const first = rows.filter({ hasText: 'Module Visitor' });
		c.ok(/New/.test(await first.innerText()), 'new submission has the New badge');
		await shot(page, `forms-${site.key}-archive-light`);

		// Search & unread filters, empty state.
		const listCall = (action) => Promise.all([page.waitForResponse((r) => /route=forms(%2F|\/)submissions/.test(r.url()) && r.request().method() === 'GET'), action()]);
		const searchFor = async (text) => {
			await page.locator('input[type=search]').fill(text);
			await listCall(() => page.getByRole('button', { name: 'Search', exact: true }).click());
			await page.waitForTimeout(250);
		};
		await searchFor('Module Visitor');
		c.ok((await rows.count()) === 1, 'search narrows the archive');
		await searchFor('zzzz-no-such-answer-zzzz');
		c.ok(await page.getByText('No submissions yet.').isVisible(), 'empty archive state shown');
		await searchFor('');
		await listCall(() => page.getByLabel('Unread only').check());
		await page.waitForTimeout(250);
		c.ok((await rows.count()) === 2, 'unread filter shows both unread submissions');

		// Detail modal, file download, mark read/unread.
		await rows.filter({ hasText: 'Jane Tester' }).getByRole('button', { name: 'Open' }).click();
		const dlg = page.getByRole('dialog');
		await dlg.waitFor();
		await dlg.getByText('Full name').waitFor();
		const dtxt = await dlg.innerText();
		c.ok(dtxt.includes('Jane Tester') && dtxt.includes(`jane.${stamp}@example.com`) && dtxt.includes('Alpha, Gamma') && /high/i.test(dtxt), 'detail shows the answers (incl. checkbox group)');
		c.ok(/quantity/i.test(dtxt) && /full name/i.test(dtxt), `detail uses the field labels (${dtxt.replace(/\s+/g, ' ').slice(0, 300)})`);
		c.ok(!dtxt.includes('Specify other'), 'detail omits the hidden conditional field');
		c.ok(dtxt.includes('Yes') && /E-mail:/.test(dtxt), 'consent stored as Yes; mail status line shown');
		await shot(page, `forms-${site.key}-submission-detail-light`);
		const [download] = await Promise.all([page.waitForEvent('download'), dlg.getByRole('button', { name: /report\.txt/ }).click()]);
		const dlPath = `C:/Users/sturm/.claude/screenshots/forms-${site.key}-download.txt`;
		await download.saveAs(dlPath);
		c.ok(download.suggestedFilename() === 'report.txt' && fs.readFileSync(dlPath, 'utf8') === bigTxt, 'file downloads with the original name and content');
		fs.rmSync(dlPath, { force: true });
		await dlg.getByRole('button', { name: 'Mark as unread' }).click();
		await dlg.getByRole('button', { name: 'Mark as read' }).waitFor();
		c.ok(await until(() => sql(site, `SELECT COUNT(*) FROM jos_uxstudio_forms_submissions WHERE form_id=${formId} AND is_read=0`) === '2'), 'marking as unread is stored (both submissions unread again)');
		await dlg.getByRole('button', { name: 'Close' }).click();
		await dlg.waitFor({ state: 'detached' });

		// CSV export.
		await listCall(() => page.getByLabel('Unread only').uncheck());
		await page.waitForTimeout(250);
		const [csvDl] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Export CSV' }).click()]);
		const csvPath = `C:/Users/sturm/.claude/screenshots/forms-${site.key}-export.csv`;
		await csvDl.saveAs(csvPath);
		const csv = fs.readFileSync(csvPath, 'utf8');
		fs.rmSync(csvPath, { force: true });
		c.ok(/^form-.*\.csv$/.test(csvDl.suggestedFilename()), `CSV file name (${csvDl.suggestedFilename()})`);
		c.ok(csv.charCodeAt(0) === 0xfeff && csv.includes(';'), 'CSV has a UTF-8 BOM and semicolons');
		c.ok(csv.includes('Jane Tester') && csv.includes('Module Visitor') && csv.split(NL).filter(Boolean).length === 3, 'CSV has header + 2 rows');
		c.ok(/'=cmd/.test(csv), 'CSV injection is neutralised (=cmd... prefixed)');

		// Delete one submission through the UI (cancel first).
		const modFile = filesOf(site, [formId]).filter((f) => f !== myFiles[0]);
		const deletedFile = modFile[0] ?? 'missing';
		c.ok(modFile.length === 1 && fs.existsSync(`${uploadDir(site)}/${deletedFile}`), 'module submission file is on disk before the delete');
		await rows.filter({ hasText: 'Module Visitor' }).getByRole('button', { name: 'Delete' }).click();
		await page.locator('.uxs-modal').last().getByRole('button', { name: 'Cancel' }).click();
		await page.waitForTimeout(300);
		c.ok((await rows.count()) === 2, 'cancelling the delete confirmation keeps the submission');
		await rows.filter({ hasText: 'Module Visitor' }).getByRole('button', { name: 'Delete' }).click();
		await page.locator('.uxs-modal').last().getByRole('button', { name: 'Delete' }).click();
		c.ok(await until(() => Number(sql(site, `SELECT COUNT(*) FROM jos_uxstudio_forms_submissions WHERE form_id=${formId}`)) === 1), 'submission deleted');
		c.ok(await until(() => !fs.existsSync(`${uploadDir(site)}/${deletedFile}`)), 'its uploaded file was removed from disk');

		// ---------------------------------------------------------------- 7. duplicate -> draft, redirect after submit
		await tab(page, 'Forms').click();
		await page.locator('#uxs-root table tbody tr', { hasText: title }).getByRole('button', { name: 'Duplicate' }).click();
		const dupRow = page.locator('#uxs-root table tbody tr', { hasText: /\((copy|kopie)\)/i }).filter({ hasText: title });
		await dupRow.waitFor();
		c.ok(/Draft/.test(await dupRow.innerText()), 'duplicate is created as a draft');
		dupId = Number((await dupRow.innerText()).match(/\{uxsform (\d+)\}/)?.[1] ?? 0);
		await dupRow.getByRole('button', { name: 'Edit' }).click();
		await rowOf(page, 'Form name').locator('input').fill(dupTitle);
		await tab(page, 'After submit').click();
		await rowOf(page, 'Redirect to').locator('input').fill('/index.php?uxs_e2e=thanks');
		await tab(page, 'Spam protection').click();
		await rowOf(page, 'Minimum fill time (seconds)').locator('input').fill('0');
		await tab(page, 'E-mails').click();
		await rowOf(page, 'Confirmation to the visitor').locator('input').uncheck();
		await page.getByRole('button', { name: 'Save', exact: true }).click();
		c.ok(await seesText(page, /Form saved\./), 'the duplicate saves');
		// Still a draft: the shortcode renders nothing for anonymous visitors.
		await setArticleBody(page, site, articleId, `<p>Intro before.</p><p>{uxsform ${formId}}</p><p>{uxsform ${dupId}}</p><p>Outro after.</p>`);
		c.ok(sql(site, `SELECT introtext FROM jos_content WHERE id=${articleId}`).includes(`{uxsform ${dupId}}`), 'article edited through the Joomla UI to add the second shortcode');
		await openStudio(page, site, '#/module?id=forms');
		await page.locator('#uxs-root table tbody tr', { hasText: dupTitle }).getByRole('button', { name: 'Edit' }).click();
		await page.getByRole('button', { name: 'Back to forms' }).waitFor();
		const v2 = await anon.ctx.newPage();
		await guardOverlays(v2);
		await v2.goto(artUrl);
		c.ok((await v2.locator(`form[data-form-id="${dupId}"]`).count()) === 0, 'draft form is not rendered on the site');
		// Publish it via the status select, then submit with redirect.
		await rowOf(page, 'Status').locator('select').selectOption('published');
		await page.getByRole('button', { name: 'Save', exact: true }).click();
		c.ok(await until(() => sql(site, `SELECT status FROM jos_uxstudio_forms_forms WHERE id=${dupId}`) === 'published'), 'status select publishes the duplicate');
		await v2.reload();
		const dupForm = v2.locator(`.com-content-article form[data-form-id="${dupId}"]`);
		await dupForm.waitFor();
		await fill(dupForm, { message: 'Redirect run' });
		await dupForm.locator('input[type=file]').setInputFiles({ name: 'r.txt', mimeType: 'text/plain', buffer: Buffer.from('r\n') });
		await Promise.all([
			v2.waitForURL(/uxs_e2e=thanks/, { timeout: 15000 }).then(() => c.ok(true, 'successful submit redirects to the configured path'), () => c.ok(false, 'successful submit redirects to the configured path')),
			dupForm.locator('button.uxs-form__submit').click(),
		]);
		c.ok(Number(sql(site, `SELECT COUNT(*) FROM jos_uxstudio_forms_submissions WHERE form_id=${dupId}`)) === 1, 'the redirecting form stored its submission (deleted with the form later)');
		await v2.close();

		// ---------------------------------------------------------------- 8. dark mode + cs-CZ screenshots
		await openStudio(page, site, '#/module?id=forms');
		await setTheme(page, 'dark');
		await shot(page, `forms-${site.key}-list-dark`);
		await tab(page, 'Submissions').click();
		await page.waitForLoadState('networkidle');
		{
			const cr = await minContrast(page, '#uxs-root label, #uxs-root td, #uxs-root th, #uxs-root .uxs-muted');
			c.ok(cr.ratio >= 4.5, `dark mode, archive: text contrast >= 4.5 (worst ${cr.ratio} on "${cr.who}")`);
		}
		await shot(page, `forms-${site.key}-archive-dark`);
		await page.locator('#uxs-root table tbody tr').first().getByRole('button', { name: 'Open' }).click();
		await page.getByRole('dialog').waitFor();
		await page.getByRole('dialog').getByText('Full name').waitFor().catch(() => {});
		await shot(page, `forms-${site.key}-submission-detail-dark`);
		await page.getByRole('dialog').getByRole('button', { name: 'Close' }).click();
		await tab(page, 'Forms').click();
		await page.locator('#uxs-root table tbody tr', { hasText: title }).getByRole('button', { name: 'Edit' }).click();
		await page.getByRole('button', { name: 'Back to forms' }).waitFor();
		await shot(page, `forms-${site.key}-editor-dark`);
		for (const [name, shotName] of [['Fields', 'fields'], ['E-mails', 'mail'], ['After submit', 'after'], ['Spam protection', 'spam']]) {
			await tab(page, name).click();
			await page.waitForTimeout(150);
			const cr = await minContrast(page, '#uxs-root label, #uxs-root .uxs-form__help, #uxs-root .uxs-muted, #uxs-root td, #uxs-root th');
			c.ok(cr.ratio >= 4.5, `dark mode, editor tab "${name}": text contrast >= 4.5 (worst ${cr.ratio} on "${cr.who}")`);
			await shot(page, `forms-${site.key}-editor-dark-${shotName}`);
		}
		await tab(page, 'Fields').click();
		await page.locator('table.uxs-table tbody tr').first().getByRole('button', { name: 'Edit' }).click();
		await page.getByRole('dialog').waitFor();
		{
			await page.getByRole('dialog').locator('input').first().waitFor();
			const cr = await minContrast(page, '[role=dialog] label, [role=dialog] .uxs-form__help');
			c.ok(cr.ratio >= 4.5, `dark mode, field modal: text contrast >= 4.5 (worst ${cr.ratio} on "${cr.who}")`);
		}
		await shot(page, `forms-${site.key}-field-modal-dark`);
		await page.getByRole('dialog').getByRole('button', { name: 'Cancel' }).click();
		await setTheme(page, 'light');

		// cs-CZ: separate session whose administrator language is chosen on the login screen (no DB change).
		const csBox = await context(browser, { ignore: adminIgnore });
		issues.push(csBox.issues);
		const csPage = await login(csBox.ctx, site, 'cs-CZ').catch((e) => (console.log(`cs login: ${e.message}`), null));
		if (csPage) {
			await openStudio(csPage, site, '#/module?id=forms');
			const cs = JSON.parse(fs.readFileSync(`${ROOT}admin-app/src/modules/forms/i18n/cs.json`, 'utf8'));
			const leaks = async (label) => {
				const text = await csPage.locator('#uxs-root').innerText();
				const bad = Object.keys(cs).filter((k) => k.length > 5 && /[a-z]{3}/.test(k) && text.includes(k) && cs[k] !== k);
				c.ok(bad.length === 0, `cs-CZ ${label}: no untranslated strings${bad.length ? ' -> ' + bad.slice(0, 5).join(' | ') : ''}`);
			};
			c.ok(/Formuláře/.test(await csPage.locator('#uxs-root').innerText()), 'cs-CZ administration uses Czech texts');
			await leaks('forms list');
			await shot(csPage, `forms-${site.key}-list-cs`);
			await csPage.getByRole('tab').nth(1).click();
			await csPage.waitForLoadState('networkidle');
			await leaks('submissions');
			await shot(csPage, `forms-${site.key}-archive-cs`);
			await csPage.getByRole('tab').nth(2).click();
			await csPage.locator('#uxs-root input').first().waitFor();
			await csPage.waitForLoadState('networkidle');
			await leaks('settings');
			await csPage.getByRole('tab').nth(0).click();
			await csPage.locator('#uxs-root table tbody tr', { hasText: title }).locator('.uxs-btn--small').first().click();
			await csPage.locator('table.uxs-table').waitFor();
			await leaks('editor fields');
			await shot(csPage, `forms-${site.key}-editor-cs`);
			for (const i of [1, 2, 3]) {
				await csPage.getByRole('tab').nth(i).click();
				await leaks(`editor tab ${i}`);
				if (i === 1) await shot(csPage, `forms-${site.key}-editor-mail-cs`);
			}
			await csPage.getByRole('tab').nth(0).click();
			await csPage.locator('table.uxs-table tbody tr').nth(6).locator('.uxs-btn--small').nth(2).click();
			await csPage.getByRole('dialog').waitFor();
			await leaks('field modal');
			await shot(csPage, `forms-${site.key}-field-modal-cs`);
			await csPage.keyboard.press('Escape');
			await csBox.ctx.close();
			// Every string the editor, modal and archive pass to t()/tn() must have a Czech translation.
			const srcDir = `${ROOT}admin-app/src/modules/forms/`;
			const wanted = new Set();
			for (const f of fs.readdirSync(srcDir).filter((n) => n.endsWith('.tsx'))) {
				const src = fs.readFileSync(srcDir + f, 'utf8');
				for (const m of src.matchAll(/\btn?\(\s*'((?:[^'\\]|\\.)*)'(?:\s*,\s*'((?:[^'\\]|\\.)*)')?/g)) {
					const one = m[1].replace(/\'/g, "'");
					wanted.add(m[2] ? `${one}|${m[2].replace(/\'/g, "'")}` : one);
				}
			}
			const sameOk = new Set(['E-mail', 'Text', 'URL', 'Reply-To', 'Honeypot', 'CSS class', 'min', 'max', 'Cloudflare Turnstile', 'Form', 'Export CSV', 'Status']);
			const missing = [...wanted].filter((k) => k.length > 1 && !(k in cs) && !sameOk.has(k) && !/^[\s\d{}.,:;()\-\/]+$/.test(k));
			c.ok(missing.length === 0, `cs-CZ: every UI string has a translation${missing.length ? ' -> missing: ' + missing.slice(0, 8).join(' | ') : ''}`);
		} else {
			c.ok(false, 'cs-CZ login');
		}

		// ---------------------------------------------------------------- 9. delete via UI
		await openStudio(page, site, '#/module?id=forms');
		for (const t of [dblTitle, dupTitle, title]) {
			const r = page.locator('#uxs-root table tbody tr', { hasText: t });
			await r.getByRole('button', { name: 'Delete' }).click();
			const confirmText = await page.locator('.uxs-modal').last().innerText();
			if (t === title) c.ok(/1 submission/.test(confirmText), `delete confirmation mentions the submission count ("${confirmText.replace(/\s+/g, ' ').slice(0, 90)}")`);
			await page.locator('.uxs-modal').last().getByRole('button', { name: 'Delete' }).click();
			await r.waitFor({ state: 'detached' });
		}
		c.ok(Number(sql(site, `SELECT COUNT(*) FROM jos_uxstudio_forms_forms WHERE title LIKE '${TAG}%'`)) === 0, 'forms deleted');
		c.ok(Number(sql(site, `SELECT COUNT(*) FROM jos_uxstudio_forms_submissions WHERE form_id IN (${formId},${dupId})`)) === 0, 'their submissions deleted with them');
		c.ok(!fs.existsSync(`${uploadDir(site)}/${myFiles[0]}`), 'uploaded files removed from disk');
		// After deletion the shortcode and module render nothing.
		const v3 = await anon.ctx.newPage();
		await guardOverlays(v3);
		await v3.goto(artUrl);
		c.ok((await v3.locator(`form[data-form-id="${formId}"]`).count()) === 0, 'after deleting the form nothing is rendered on the site');
		await v3.close();
	} catch (e) {
		c.ok(false, `scenario crashed: ${String(e.stack ?? e).slice(0, 600)}`);
		try {
			const pages = [...admin.ctx.pages(), ...anon.ctx.pages()];
			for (const [i, pg] of pages.entries()) await shot(pg, `forms-${site.key}-crash-${i}`);
		} catch {}
	} finally {
		// Safety net + removal of the Joomla module and article created through the UI.
		try {
			sql(site, `DELETE FROM jos_modules_menu WHERE moduleid IN (SELECT id FROM jos_modules WHERE title LIKE '${TAG} module%')`);
			sql(site, `DELETE FROM jos_modules WHERE title LIKE '${TAG} module%'`);
			const ids = sql(site, `SELECT id FROM jos_content WHERE title LIKE '${TAG} article%'`).split(NL).filter(Boolean);
			for (const id of ids) {
				sql(site, `DELETE FROM jos_workflow_associations WHERE item_id=${id} AND extension='com_content.article'`);
				sql(site, `DELETE FROM jos_content_frontpage WHERE content_id=${id}`);
				sql(site, `DELETE FROM jos_content_rating WHERE content_id=${id}`);
				sql(site, `DELETE FROM jos_assets WHERE name='com_content.article.${id}'`);
				sql(site, `DELETE FROM jos_content WHERE id=${id}`);
			}
			// Uploads of an aborted run: only files referenced by submissions of this scenario's forms.
			const mine = sql(site, `SELECT id FROM jos_uxstudio_forms_forms WHERE title LIKE '${TAG}%'`).split(NL).filter(Boolean);
			if (mine.length) for (const f of filesOf(site, mine)) fs.rmSync(`${uploadDir(site)}/${f}`, { force: true });
			sql(site, `DELETE FROM jos_uxstudio_forms_submissions WHERE form_id IN (SELECT id FROM jos_uxstudio_forms_forms WHERE title LIKE '${TAG}%')`);
			sql(site, `DELETE FROM jos_uxstudio_forms_forms WHERE title LIKE '${TAG}%'`);
			sql(site, `DELETE FROM jos_uxstudio_email_log WHERE id>${mailBefore} AND subject LIKE 'FRMX %'`);
			sql(site, `DELETE FROM jos_ucm_content WHERE core_title LIKE '${TAG} article%'`);
		} catch (e) {
			console.log(`cleanup problem: ${e.message}`);
		}
		const left = sql(site, `SELECT (SELECT COUNT(*) FROM jos_uxstudio_forms_forms WHERE title LIKE '${TAG}%') + (SELECT COUNT(*) FROM jos_modules WHERE title LIKE '${TAG}%') + (SELECT COUNT(*) FROM jos_content WHERE title LIKE '${TAG}%')`);
		c.ok(left === '0', 'test data cleaned up (forms, module, article)');
		await admin.ctx.close();
		await anon.ctx.close();
	}
	return c.done(issues.flat());
}

const browser = await launch();
let bad = 0;
for (const site of sitesFromArgv()) bad += await runSite(browser, site);
await browser.close();
void SITES;
process.exit(bad ? 1 : 0);
