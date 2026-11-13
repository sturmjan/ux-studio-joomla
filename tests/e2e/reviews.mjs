// Browser E2E for the group "reviews": review-aggregator, google-review-request, mod_uxstudio_reviews.
// Run: node tests/e2e/reviews.mjs [j5|j6|all] [--headed]
//
// Real flows in Chromium: UX1 Studio SPA (cs-CZ light, en-GB dark), Joomla administrator (creating / editing the
// Joomla module through com_modules), anonymous front end. Everything the scenario creates is removed again;
// it uses its own temporary super user (own admin language, own session) so parallel scenarios are not disturbed.
import { execFileSync } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { request } from 'playwright';
import { launch, context, openStudio, shot, checker, sitesFromArgv, SHOTS } from './lib.mjs';

const MYSQL = 'D:/xampp/mysql/bin/mysql.exe';
const PHP = 'D:/xampp/php/php.exe';
const HTDOCS = 'D:/xampp/htdocs';
const USER = 'rvx_reviews';
const USER_EMAIL = 'rvx-reviews@example.test';
const BACKUP = `${SHOTS}reviews-e2e-settings-backup.json`;
const MODS = ['review-aggregator', 'google-review-request'];

// ---------------------------------------------------------------- helpers

function sql(site, q) {
	return execFileSync(MYSQL, ['-uroot', '--default-character-set=utf8mb4', '-N', '-B', site.db, '-e', q], { encoding: 'utf8' }).trim();
}
const esc = (s) => String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'");

function readSecret(site) {
	const cfg = fs.readFileSync(`${HTDOCS}/${site.db}/configuration.php`, 'utf8');
	return /public \$secret = '([^']*)'/.exec(cfg)?.[1] ?? '';
}

/** Remove everything a previous (or this) run created. Settings are restored from the backup file. */
function cleanup(site, { restore = true } = {}) {
	sql(site, "DELETE FROM jos_uxstudio_reviews WHERE author LIKE 'E2E %' OR source = 'e2e' OR author LIKE 'E2E%'");
	const ids = sql(site, "SELECT id FROM jos_modules WHERE title LIKE 'RVX %'").split(/\s+/).filter(Boolean);
	for (const id of ids) {
		sql(site, `DELETE FROM jos_modules_menu WHERE moduleid = ${Number(id)}; DELETE FROM jos_modules WHERE id = ${Number(id)}; DELETE FROM jos_assets WHERE name = 'com_modules.module.${Number(id)}'`);
	}
	sql(site, `DELETE FROM jos_uxstudio_grr_log WHERE email LIKE '%rvx.test' OR email = '${USER_EMAIL}'`);
	sql(site, "DELETE FROM jos_uxstudio_grr_optout WHERE email LIKE '%rvx.test'");
	sql(site, `DELETE FROM jos_uxstudio_email_log WHERE to_email LIKE '%rvx.test' OR to_email = '${USER_EMAIL}'`);
	const uid = sql(site, `SELECT id FROM jos_users WHERE username = '${USER}'`);
	if (uid) {
		sql(site, `DELETE FROM jos_session WHERE userid = ${Number(uid)}; DELETE FROM jos_user_usergroup_map WHERE user_id = ${Number(uid)}; DELETE FROM jos_users WHERE id = ${Number(uid)}`);
	}
	if (restore && fs.existsSync(BACKUP)) {
		const all = JSON.parse(fs.readFileSync(BACKUP, 'utf8'));
		const mine = all[site.key];
		if (mine) {
			for (const m of MODS) {
				sql(site, `DELETE FROM jos_uxstudio_settings WHERE module = '${m}'`);
				if (mine[m]) {
					sql(site, `INSERT INTO jos_uxstudio_settings (module, data, updated_at) VALUES ('${m}', '${esc(mine[m].data)}', '${mine[m].updated_at}')`);
				}
			}
		}
	}
}

function backupSettings(site) {
	const all = fs.existsSync(BACKUP) ? JSON.parse(fs.readFileSync(BACKUP, 'utf8')) : {};
	all[site.key] = {};
	for (const m of MODS) {
		const row = sql(site, `SELECT data, updated_at FROM jos_uxstudio_settings WHERE module = '${m}'`);
		if (row) {
			const [data, updated_at] = [row.slice(0, row.lastIndexOf('\t')), row.slice(row.lastIndexOf('\t') + 1)];
			all[site.key][m] = { data, updated_at };
		}
	}
	fs.writeFileSync(BACKUP, JSON.stringify(all));
}

function createUser(site, password) {
	const hash = execFileSync(PHP, ['-r', "echo password_hash(getenv('E2E_PW'), PASSWORD_BCRYPT);"], { encoding: 'utf8', env: { ...process.env, E2E_PW: password } });
	sql(site, `INSERT INTO jos_users (name, username, email, password, block, sendEmail, registerDate, params) VALUES ('E2E Reviews', '${USER}', '${USER_EMAIL}', '${hash}', 0, 0, NOW(), '{}')`);
	const id = sql(site, `SELECT id FROM jos_users WHERE username = '${USER}'`);
	sql(site, `INSERT INTO jos_user_usergroup_map (user_id, group_id) VALUES (${Number(id)}, 8)`);
}

function setLang(site, lang) {
	sql(site, `UPDATE jos_users SET params = '${JSON.stringify({ admin_language: lang, language: '', editor: 'none' })}' WHERE username = '${USER}'`);
}

let CRED = null;

/** goto() for administrator URLs that survives a vanished session (other scenarios share the DB and may clear sessions). */
async function adminGoto(page, url) {
	await page.goto(url);
	if (CRED && (await page.locator('#mod-login-username').count())) {
		await page.fill('#mod-login-username', USER);
		await page.fill('#mod-login-password', CRED.password);
		await page.click('#btn-login-submit');
		await page.locator('#mod-login-username').waitFor({ state: 'detached', timeout: 20000 });
		await page.goto(url);
	}
}

/** Open a Joomla edit form (module.add / module.edit); retried because parallel scenarios share sessions and may reset the edit state. */
async function openForm(page, url) {
	for (let i = 0; i < 4; i++) {
		await adminGoto(page, url);
		if (await page.locator('#jform_title').waitFor({ timeout: 8000 }).then(() => true, () => false)) return;
		console.log('RETRY form', url.slice(-60), (await page.locator('joomla-alert').allInnerTexts()).join('|').slice(0, 200));
	}
	throw new Error(`form did not open: ${url}`);
}

async function login(ctx, site, password) {
	CRED = { site, password };
	const page = await ctx.newPage();
	await page.goto(`${site.url}/administrator/index.php`);
	await page.fill('#mod-login-username', USER);
	await page.fill('#mod-login-password', password);
	await page.click('#btn-login-submit');
	await page.locator('#mod-login-username').waitFor({ state: 'detached', timeout: 20000 }).catch(() => {
		throw new Error(`login as ${USER} failed on ${site.key}`);
	});
	await page.waitForLoadState('load');
	// first login of a new user: the Joomla welcome tour pops up (and redirects every screen to the dashboard); dismiss it for good
	const never = page.getByRole('button', { name: /Skrýt navždy|Hide forever|Never/i });
	if (await never.first().waitFor({ timeout: 5000 }).then(() => true, () => false)) {
		await never.first().click();
		await page.waitForTimeout(800);
	}
	return page;
}

const toastRe = (page, re, timeout = 8000) => page.locator('.uxs-toast').filter({ hasText: re }).first().waitFor({ timeout }).then(() => true, () => false);
const clearToasts = (page) => page.evaluate(() => document.querySelectorAll('.uxs-toast__close').forEach((b) => b.click()));
const settle = async (page) => {
	await page.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {});
	await page.waitForFunction(() => !document.querySelector('#uxs-root .uxs-loading'), null, { timeout: 60000 }).catch(() => {});
	await page.waitForTimeout(400);
};
const tab = async (page, id) => {
	await page.locator(`#uxs-tab-${id}`).click();
	await settle(page);
};
const modal = (page) => page.locator('.uxs-modal');
async function pollSql(site, q, want, timeout = 40000) {
	const end = Date.now() + timeout;
	let v = sql(site, q);
	while (v !== want && Date.now() < end) {
		await new Promise((r) => setTimeout(r, 1000));
		v = sql(site, q);
	}
	return v;
}
const hasRow = (page, name, timeout = 6000) => page.locator('#uxs-root tbody tr', { hasText: name }).first().waitFor({ timeout }).then(() => true, () => false);

async function openModule(page, site, id) {
	await adminGoto(page, `${site.url}/administrator/index.php?option=com_uxstudio#/module?id=${id}`);
	await page.locator('#uxs-root').waitFor({ state: 'attached' });
	await page.waitForLoadState('networkidle');
	try {
		// the SPA bundle may be rebuilt by another scenario at this very moment: reload a few times before giving up
		for (let i = 0; i < 5; i++) {
			if (await page.locator('[role=tablist]').first().waitFor({ timeout: 10000 }).then(() => true, () => false)) break;
			console.log('RETRY SPA mount', id, 'root html length', await page.evaluate(() => document.getElementById('uxs-root')?.innerHTML.length));
			await page.goto('about:blank');
			await adminGoto(page, `${site.url}/administrator/index.php?option=com_uxstudio#/module?id=${id}`);
			await page.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {});
		}
		await page.locator('[role=tablist]').first().waitFor({ timeout: 10000 });
		await settle(page);
	} catch (e) {
		await page.screenshot({ path: `${SHOTS}reviews-debug-open.png` });
		console.log('DEBUG url:', page.url(), 'alerts:', (await page.locator('joomla-alert, .alert').allInnerTexts()).join('|').slice(0,300));
		console.log('DEBUG body:', (await page.locator('body').innerText()).slice(0, 400).replace(/\s+/g, ' '));
		throw e;
	}
}

/** Visible static texts of the screen (labels, buttons, headings, help) for the untranslated-text comparison. */
async function texts(page) {
	return page.evaluate(() => {
		const out = new Set();
		document.querySelectorAll('#uxs-root button, #uxs-root label, #uxs-root th, #uxs-root h1, #uxs-root h2, #uxs-root h3, #uxs-root [role=tab], #uxs-root .uxs-form__help, #uxs-root .uxs-notice, #uxs-root .uxs-card__label, #uxs-root option, #uxs-root .uxs-muted').forEach((el) => {
			if (el.closest('.uxs-sidebar')) return;
			if (!el.offsetParent && el.tagName !== 'OPTION') return;
			const t = (el.getAttribute('aria-label') || el.textContent || '').replace(/\s+/g, ' ').trim();
			if (t.length > 3 && t.length < 400) out.add(t);
		});
		return [...out];
	});
}

/** WCAG contrast of the text colour of an element against its first opaque ancestor background. */
async function contrastIssues(page, selectors) {
	return page.evaluate((sels) => {
		const parse = (c) => {
			const m = c.match(/rgba?\(([^)]+)\)/);
			if (!m) return null;
			const p = m[1].split(/[ ,\/]+/).filter(Boolean).map(Number);
			return { r: p[0], g: p[1], b: p[2], a: p[3] ?? 1 };
		};
		const lum = ({ r, g, b }) => {
			const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
			return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
		};
		const bgOf = (el) => {
			let n = el;
			while (n) {
				const c = parse(getComputedStyle(n).backgroundColor);
				if (c && c.a > 0.9) return c;
				n = n.parentElement;
			}
			return { r: 255, g: 255, b: 255, a: 1 };
		};
		const bad = [];
		for (const s of sels) {
			document.querySelectorAll(s).forEach((el) => {
				if (!el.offsetParent || !(el.textContent || '').trim()) return;
				const fg = parse(getComputedStyle(el).color);
				const bg = bgOf(el);
				if (!fg) return;
				const a = lum(fg), b = lum(bg);
				const ratio = (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
				if (ratio < 3) bad.push(`${s} "${(el.textContent || '').trim().slice(0, 30)}" ${ratio.toFixed(2)}`);
			});
		}
		return bad;
	}, selectors);
}

// ---------------------------------------------------------------- Joomla module form

async function setPosition(page, position) {
	await page.evaluate((pos) => {
		const sel = document.querySelector('#jform_position');
		if (![...sel.options].some((o) => o.value === pos)) sel.add(new Option(pos, pos));
		sel.value = pos;
		sel.dispatchEvent(new Event('change', { bubbles: true }));
	}, position);
}

async function saveJoomlaModule(page) {
	await Promise.all([page.waitForLoadState('load'), page.locator('#toolbar-apply button, button.button-apply').first().click()]);
	await page.waitForLoadState('networkidle');
	const ok = (await page.locator('joomla-alert, .alert-message, #system-message-container').allInnerTexts()).join(' ');
	return { ok: !(await page.locator('joomla-alert[type=danger], .alert-danger').count()), message: ok };
}

async function closeJoomlaModule(page) {
	await Promise.all([page.waitForLoadState('load'), page.locator('#toolbar-cancel button, button.button-cancel').first().click()]);
}

/** Create the Joomla module "UX1 Studio - Reviews" through com_modules. Returns its id. */
async function createReviewsModule(page, site, title, fields, position = 'sidebar-left') {
	const eid = sql(site, "SELECT extension_id FROM jos_extensions WHERE element = 'mod_uxstudio_reviews'");
	await openForm(page, `${site.url}/administrator/index.php?option=com_modules&task=module.add&client_id=0&eid=${eid}`);
	await page.fill('#jform_title', title);
	for (const [k, v] of Object.entries(fields)) {
		const el = page.locator(`#jform_params_${k}`);
		if ((await el.evaluate((n) => n.tagName)) === 'SELECT') await el.selectOption(String(v));
		else await el.fill(String(v));
	}
	await setPosition(page, position);
	const r = await saveJoomlaModule(page);
	const id = Number(new URL(page.url()).searchParams.get('id'));
	await closeJoomlaModule(page);
	return { id, ...r };
}

async function editReviewsModule(page, site, id, fields) {
	await openForm(page, `${site.url}/administrator/index.php?option=com_modules&task=module.edit&id=${id}`);
	for (const [k, v] of Object.entries(fields)) {
		const el = page.locator(`#jform_params_${k}`);
		if ((await el.evaluate((n) => n.tagName)) === 'SELECT') await el.selectOption(String(v));
		else await el.fill(String(v));
	}
	const r = await saveJoomlaModule(page);
	await closeJoomlaModule(page);
	return r;
}

async function createCustomModule(page, site, title, html, position) {
	const eid = sql(site, "SELECT extension_id FROM jos_extensions WHERE element = 'mod_custom' AND type = 'module' AND client_id = 0");
	await openForm(page, `${site.url}/administrator/index.php?option=com_modules&task=module.add&client_id=0&eid=${eid}`);
	await page.fill('#jform_title', title);
	await page.locator('#jform_content').fill(html);
	// mod_custom only runs content plugins (and so the {uxstudio_reviews} tag) when "Prepare Content" is switched on
	await page.evaluate(() => {
		const r = document.querySelector('#jform_params_prepare_content1');
		if (r) { r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); }
	});
	await setPosition(page, position);
	const r = await saveJoomlaModule(page);
	const id = Number(new URL(page.url()).searchParams.get('id'));
	await closeJoomlaModule(page);
	return { id, ...r };
}

// ---------------------------------------------------------------- front end

async function front(ctx, site, url = '/index.php') {
	const page = await ctx.newPage();
	await page.goto(site.url + url, { waitUntil: 'load' });
	// popups of other modules (exit popup / notice board) overlay the page and swallow clicks: dismiss them
	await page.addStyleTag({ content: 'body [class*="uxs-popup"]:not(html):not(body), body [class*="uxs-exit"]:not(html):not(body) { display: none !important; } html.uxs-popup-lock, html.uxs-exit-lock { overflow: auto !important; }' });
	await page.waitForTimeout(600);
	for (let i = 0; i < 3; i++) {
		const dlg = page.locator('dialog[open], [role=dialog]:visible, .uxs-popup:visible, .uxs-modal:visible').first();
		if (!(await dlg.count()) || !(await dlg.isVisible().catch(() => false))) break;
		const close = dlg.locator('button[aria-label], button:has-text("×"), button:has-text("✕")').first();
		if (await close.count()) await close.click({ timeout: 2000 }).catch(() => {});
		else await page.keyboard.press('Escape');
		await page.waitForTimeout(400);
	}
	await page.keyboard.press('Escape');
	return page;
}

async function section(page, heading) {
	const s = page.locator('section.uxs-reviews').filter({ has: page.locator('h3.uxs-reviews__heading', { hasText: heading }) });
	return s;
}

async function ldJson(page) {
	const blocks = await page.locator('script[type="application/ld+json"]').allTextContents();
	const out = [];
	for (const b of blocks) {
		try { out.push(JSON.parse(b)); } catch { out.push({ __invalid: b.slice(0, 80) }); }
	}
	return out;
}

// ---------------------------------------------------------------- the scenario

const EXPECTED_HTTP = [/HTTP (400|401|403|404|409|413|422|424)\b/, /Failed to load resource.*\b(400|401|403|404|409|413|422|424)\b/];

async function run(browser, site) {
	const label = site.key;
	const c = checker(`reviews ${label}`);
	const password = `E2e!${crypto.randomBytes(9).toString('hex')}`;
	const secret = readSecret(site);
	const allIssues = [];
	const textsByLang = { cs: {}, en: {} };
	let uiLang = 'cs';
	const snap = async (page, name) => settle(page).then(() => shot(page, `reviews-${label}-${name}`));
	const collect = async (page, screen) => {
		textsByLang[uiLang][screen] = await texts(page);
	};

	cleanup(site);
	backupSettings(site);
	createUser(site, password);

	try {
		// ======================================================== PASS 1: cs-CZ, light
		setLang(site, 'cs-CZ');
		const { ctx, issues } = await context(browser, { ignore: EXPECTED_HTTP });
		await ctx.addInitScript(() => { try { window.localStorage.setItem('uxstudio-theme', 'light'); } catch { /* sandboxed iframe */ } });
		const page = await login(ctx, site, password);
		globalThis.__page = page;
		page.setDefaultTimeout(90000);

		// ---------- Review Aggregator: reviews tab, empty states
		await openModule(page, site, 'review-aggregator');
		c.ok((await page.locator('#uxs-root').innerText()).length > 100, 'RA: stránka modulu se vykreslila');
		c.ok((await page.locator('[role=tab]').allInnerTexts()).join('|').includes('Recenze'), 'RA: záložky v češtině');
		await collect(page, 'ra-reviews');
		await snap(page, 'ra-reviews-cs-light');

		await page.fill('.uxs-list-toolbar input[type=search]', 'zzzqqq-nic');
		await page.locator('.uxs-list-toolbar button[type=submit]').click();
		await page.locator('td.is-empty').waitFor();
		c.ok(/Zatím žádné recenze/.test(await page.locator('td.is-empty').innerText()), 'RA: prázdný stav tabulky při hledání bez výsledku');
		await page.fill('.uxs-list-toolbar input[type=search]', '');
		await page.locator('.uxs-list-toolbar button[type=submit]').click();

		// ---------- add review: validation + create
		const addBtn = page.locator('.uxs-list-toolbar .uxs-btn--primary');
		await addBtn.click();
		await modal(page).waitFor();
		c.ok(/Přidat recenzi/.test(await modal(page).locator('.uxs-modal__title').innerText()), 'RA: modál "Přidat recenzi"');
		const saveBtn = modal(page).locator('.uxs-btn--primary');
		c.ok(await saveBtn.isDisabled(), 'RA: Uložit je neaktivní bez hodnocení (validace)');
		await collect(page, 'ra-modal');
		await snap(page, 'ra-modal-cs-light');
		await page.fill('#rv-source', 'e2e');
		await page.selectOption('#rv-rating', '5');
		await clearToasts(page);
		await saveBtn.click(); // author + text empty -> server validation
		c.ok(await toastRe(page, /Vyplňte autora nebo text/i), 'RA: prázdný autor i text -> chyba ze serveru');
		c.ok(await modal(page).isVisible(), 'RA: po chybě zůstane modál otevřený');
		await page.fill('#rv-author', 'E2E Alice');
		await page.fill('#rv-text', 'Skvělé služby, všem doporučuji. <b>tučně</b> & "uvozovky"');
		await page.fill('#rv-date', '2026-10-01');
		await saveBtn.click();
		await modal(page).waitFor({ state: 'hidden' });
		c.ok(await hasRow(page, 'E2E Alice'), 'RA: nová recenze je ve výpisu');
		// duplicate
		await addBtn.click();
		await page.fill('#rv-source', 'e2e');
		await page.selectOption('#rv-rating', '5');
		await page.fill('#rv-author', 'E2E Alice');
		await page.fill('#rv-text', 'Skvělé služby, všem doporučuji. <b>tučně</b> & "uvozovky"');
		await page.fill('#rv-date', '2026-10-01');
		await clearToasts(page);
		await modal(page).locator('.uxs-btn--primary').click();
		c.ok(await toastRe(page, /existuje|duplic|již|stejn/i), 'RA: duplicitní recenze je odmítnuta');
		await modal(page).locator('.uxs-btn:not(.uxs-btn--primary)').click();
		await modal(page).waitFor({ state: 'hidden' });
		// manual-source review (marked up by schema_reviews later)
		await addBtn.click();
		await page.fill('#rv-source', 'manual');
		await page.selectOption('#rv-rating', '4');
		await page.fill('#rv-author', 'E2E Manual Zed');
		await page.fill('#rv-text', 'Ruční recenze pro schema.org');
		await page.fill('#rv-date', '2026-10-02');
		await modal(page).locator('.uxs-btn--primary').click();
		await modal(page).waitFor({ state: 'hidden' });
		c.ok(await hasRow(page, 'E2E Manual Zed'), 'RA: ruční recenze (platforma manual) uložena');

		// ---------- API-level validation (not reachable from the form)
		const jadmin = (args, expect) => {
			try {
				execFileSync('node', ['bin/jadmin.mjs', site.key, ...args, '--expect', String(expect)], { cwd: path.resolve(import.meta.dirname, '..', '..'), encoding: 'utf8', stdio: 'pipe' });
				return true;
			} catch { return false; }
		};
		c.ok(jadmin(['POST', 'review-aggregator/reviews', '{"rating":9,"author":"E2E x","source":"e2e"}'], 422), 'RA API: hodnocení 9 -> 422');
		c.ok(jadmin(['POST', 'review-aggregator/reviews', '{"rating":0,"author":"E2E x","source":"e2e"}'], 422), 'RA API: hodnocení 0 -> 422');
		c.ok(jadmin(['POST', 'review-aggregator/reviews', '{"rating":3,"author":"E2E x","source":"e2e","review_date":"neni datum"}'], 422), 'RA API: nesmyslné datum -> 422');
		c.ok(jadmin(['POST', 'review-aggregator/reviews/999999', '{"rating":3,"author":"E2E x"}'], 404), 'RA API: úprava neexistující recenze -> 404');
		sql(site, `INSERT INTO jos_uxstudio_reviews (source, origin, external_id, dedup_hash, author, rating, text, review_date, imported_at, visible) VALUES ('e2e','ca','x1','${crypto.randomBytes(20).toString('hex')}','E2E Fromca',3,'z CA',NOW(),NOW(),1)`);
		const caId = sql(site, "SELECT id FROM jos_uxstudio_reviews WHERE author = 'E2E Fromca'");
		c.ok(jadmin(['POST', `review-aggregator/reviews/${caId}`, '{"rating":1,"author":"E2E Fromca","source":"e2e"}'], 409), 'RA API: recenze z CA se nedá upravit -> 409');
		sql(site, `DELETE FROM jos_uxstudio_reviews WHERE id = ${Number(caId)}`);

		// ---------- edit, hide/show, delete
		await openModule(page, site, 'review-aggregator');
		await page.locator('#uxs-root tbody tr').first().waitFor();
		const rowOf = (name) => page.locator('#uxs-root tbody tr', { hasText: name });
		await rowOf('E2E Alice').locator('.uxs-actions button').nth(1).click(); // pencil
		await modal(page).waitFor();
		c.ok(/Upravit recenzi/.test(await modal(page).locator('.uxs-modal__title').innerText()), 'RA: modál "Upravit recenzi"');
		c.ok((await page.inputValue('#rv-author')) === 'E2E Alice', 'RA: formulář úpravy je předvyplněný');
		await page.fill('#rv-text', 'Upravený text Alice <b>tučně</b> & "uvozovky" <script>alert(1)</script>');
		await modal(page).locator('.uxs-btn--primary').click();
		await modal(page).waitFor({ state: 'hidden' });
		c.ok(await hasRow(page, 'Upravený text Alice'), 'RA: úprava se projeví ve výpisu');

		await rowOf('E2E Manual Zed').locator('.uxs-actions button').first().click(); // hide
		await page.locator('#uxs-root tbody tr', { hasText: 'E2E Manual Zed' }).locator('.uxs-badge', { hasText: 'Skryto' }).waitFor();
		c.ok(true, 'RA: recenze lze skrýt (štítek Skryto)');
		await page.selectOption('select[aria-label="Zobrazeno"]', '0');
		await page.waitForLoadState('networkidle');
		c.ok((await page.locator('#uxs-root tbody').innerText()).includes('E2E Manual Zed') && !(await page.locator('#uxs-root tbody').innerText()).includes('E2E Alice'), 'RA: filtr "Jen skryté"');
		await page.selectOption('select[aria-label="Zobrazeno"]', '');
		await rowOf('E2E Manual Zed').locator('.uxs-actions button').first().click();
		await rowOf('E2E Manual Zed').locator('.uxs-badge', { hasText: 'Ano' }).waitFor();
		c.ok(true, 'RA: recenze lze znovu zobrazit');

		// temp review for delete
		await addBtn.click();
		await page.fill('#rv-source', 'e2e');
		await page.selectOption('#rv-rating', '2');
		await page.fill('#rv-author', 'E2E Temp');
		await page.fill('#rv-text', 'smazat');
		await modal(page).locator('.uxs-btn--primary').click();
		await modal(page).waitFor({ state: 'hidden' });
		await rowOf('E2E Temp').locator('.uxs-actions button').last().click();
		await modal(page).waitFor();
		c.ok(/Smazat tuto recenzi/.test(await modal(page).innerText()), 'RA: potvrzení smazání');
		await snap(page, 'ra-confirm-cs-light');
		await modal(page).locator('.uxs-btn:not(.uxs-btn--danger)').click();
		await modal(page).waitFor({ state: 'hidden' });
		c.ok((await rowOf('E2E Temp').count()) === 1, 'RA: Zrušit u potvrzení recenzi nesmaže');
		await rowOf('E2E Temp').locator('.uxs-actions button').last().click();
		await modal(page).locator('.uxs-btn--danger').click();
		await modal(page).waitFor({ state: 'hidden' });
		await page.waitForFunction(() => !document.body.innerText.includes('E2E Temp'));
		c.ok(sql(site, "SELECT COUNT(*) FROM jos_uxstudio_reviews WHERE author = 'E2E Temp'") === '0', 'RA: smazání potvrzeno a provedeno v DB');

		// ---------- regressions found by QA: "<" in text, double click, long strings, cleared date
		{
			const qa = async (author, text, rating = '5', date = '2026-10-05') => {
				await addBtn.click();
				await page.fill('#rv-source', 'e2eqa');
				await page.selectOption('#rv-rating', rating);
				await page.fill('#rv-author', author);
				await page.fill('#rv-text', text);
				await page.fill('#rv-date', date);
			};
			const LT = 'Super <3 doporučuji všem, cena<500 Kč a kvalita>průměr.';
			await qa('E2E Lt', LT);
			await modal(page).locator('.uxs-btn--primary').click();
			await modal(page).waitFor({ state: 'hidden' });
			c.ok(sql(site, "SELECT text FROM jos_uxstudio_reviews WHERE author = 'E2E Lt'") === LT, 'RA: text se znakem "<" se neuřízne (zůstane celý)');

			await clearToasts(page);
			await qa('E2E Dbl', 'dvojklik', '3');
			let posts = 0;
			const onReq = (rq) => { if (rq.method() === 'POST' && /review-aggregator(\/|%2F)reviews(?!(\/|%2F))/.test(decodeURIComponent(rq.url()))) posts++; };
			page.on('request', onReq);
			await modal(page).locator('.uxs-btn--primary').dblclick();
			await modal(page).waitFor({ state: 'hidden' });
			await page.waitForTimeout(1200);
			page.off('request', onReq);
			const toastsAll = (await page.locator('.uxs-toast').allInnerTexts()).join(' | ');
			c.ok(posts === 1, `RA: dvojklik na Uložit odešle jediný požadavek (${posts})`);
			c.ok(sql(site, "SELECT COUNT(*) FROM jos_uxstudio_reviews WHERE author = 'E2E Dbl'") === '1' && !/Chyba serveru|existuje/i.test(toastsAll), `RA: dvojklik - 1 řádek a žádná chybová hláška (${toastsAll})`);

			// server-side: an identical second insert is a clean 409
			const dupe = (() => { try { execFileSync('node', ['bin/jadmin.mjs', site.key, 'POST', 'review-aggregator/reviews', '{"rating":3,"author":"E2E Dbl","text":"dvojklik","source":"e2eqa","review_date":"2026-10-05"}', '--expect', '409'], { cwd: path.resolve(import.meta.dirname, '..', '..'), encoding: 'utf8', stdio: 'pipe' }); return true; } catch { return false; } })();
			c.ok(dupe, 'RA API: stejná recenze znovu -> 409');

			await qa('E2E ' + 'Dlouhejmeno'.repeat(22), 'x'.repeat(3000), '4', '2026-10-06');
			await modal(page).locator('.uxs-btn--primary').click();
			await modal(page).waitFor({ state: 'hidden' });
			await hasRow(page, 'Dlouhejmeno');
			await page.waitForTimeout(300);
			const ov = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
			c.ok(ov.sw <= ov.cw + 1, `RA: dlouhý autor/text bez mezer nerozbije šířku stránky (${ov.sw} <= ${ov.cw})`);
			await snap(page, 'ra-long-cs');

			// clear the date while editing -> really cleared
			await rowOf('E2E Lt').locator('.uxs-actions button').nth(1).click();
			await modal(page).waitFor();
			await page.fill('#rv-date', '');
			await modal(page).locator('.uxs-btn--primary').click();
			await modal(page).waitFor({ state: 'hidden' });
			await page.waitForTimeout(500);
			c.ok(sql(site, "SELECT review_date IS NULL FROM jos_uxstudio_reviews WHERE author = 'E2E Lt'") === '1', 'RA: vymazané datum při úpravě se opravdu smaže');

			// keyboard: Escape closes the modal and focus returns to the opener
			await addBtn.focus();
			await page.keyboard.press('Enter');
			await modal(page).waitFor();
			await page.waitForTimeout(500);
			await page.keyboard.press('Escape');
			await modal(page).waitFor({ state: 'hidden' });
			c.ok(await addBtn.evaluate((n) => n === document.activeElement), 'RA: po Escape se fokus vrátí na tlačítko Přidat');

			// narrow admin width
			const vp = page.viewportSize();
			await page.setViewportSize({ width: 390, height: 800 });
			await page.waitForTimeout(300);
			const nar = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
			c.ok(nar.sw <= nar.cw + 40, `RA: mobilní šířka administrace bez velkého horizontálního přetečení (${nar.sw} / ${nar.cw})`);
			await snap(page, 'ra-mobile-cs');
			await page.setViewportSize(vp ?? { width: 1400, height: 900 });

			sql(site, "DELETE FROM jos_uxstudio_reviews WHERE source = 'e2eqa'");
			await openModule(page, site, 'review-aggregator');
		}

		// ---------- overview + import
		await tab(page, 'overview');
		await collect(page, 'ra-overview');
		const caAvailable = !(await page.locator('.uxs-notice').count());
		c.ok(caAvailable || (await page.locator('.uxs-notice').first().innerText()).includes('Centrální aplikac'), 'RA: stav bez CA hlásí notice o nepropojení (nebo CA je dostupná)');
		if (!caAvailable) {
			c.ok(await page.locator('button', { hasText: 'Stáhnout recenze' }).isDisabled(), 'RA: bez CA je "Stáhnout recenze" neaktivní');
			c.ok(await page.locator('button', { hasText: 'Vyžádat hloubkový sken' }).isDisabled(), 'RA: bez CA je "Hloubkový sken" neaktivní');
		}
		await snap(page, 'ra-overview-cs-light');
		const importBtn = page.locator('button.uxs-btn--primary', { hasText: 'Importovat' });
		c.ok(await importBtn.isDisabled(), 'RA: Import je neaktivní bez dat');
		await page.fill('#rv-isrc', 'e2e');
		await page.fill('#rv-data', 'author;text\nE2E Bad;bez hodnocení');
		await clearToasts(page);
		await importBtn.click();
		c.ok(await toastRe(page, /rating|záhlav|CSV/i), 'RA: CSV bez sloupce rating -> chyba');
		const longText = 'Dlouhý text recenze '.repeat(20).trim();
		await page.fill('#rv-data', `rating;author;text;date\n4;E2E Bob;Dobré;2026-09-20\n2;E2E Carol;Horší;2026-09-21\n3;E2E Dan;${longText};2026-09-22\n0;E2E Nula;bez hodnocení;2026-09-23`);
		await clearToasts(page);
		await importBtn.click();
		c.ok(await toastRe(page, /Naimportováno: 3 nových, 0 aktualizovaných, 1 přeskočených/), 'RA: CSV import 3 nové + 1 přeskočený');
		await page.fill('#rv-data', `rating;author;text;date\n4;E2E Bob;Dobré;2026-09-20`);
		await clearToasts(page);
		await importBtn.click();
		c.ok(await toastRe(page, /Naimportováno: 0 nových, 1 aktualizovaných/), 'RA: opakovaný import nezdvojí (aktualizuje)');
		await page.selectOption('#rv-fmt', 'json');
		await page.fill('#rv-data', '{nevalidní json');
		await clearToasts(page);
		await importBtn.click();
		c.ok(await toastRe(page, /JSON/i), 'RA: nevalidní JSON -> chyba');
		await page.fill('#rv-data', '[{"rating":1,"author":"E2E Eve","text":"Špatné","date":"2026-09-25","external_id":"e2e-eve-1"},{"rating":"x","author":"E2E Nic"}]');
		await clearToasts(page);
		await importBtn.click();
		c.ok(await toastRe(page, /Naimportováno: 1 nových, 0 aktualizovaných, 1 přeskočených/), 'RA: JSON import (1 nová, 1 přeskočená)');
		await page.fill('#rv-data', '[{"rating":6,"author":"E2E Six","text":"šest hvězd","date":"2026-10-04"},{"rating":10,"author":"E2E Ten","text":"deset"}]');
		await clearToasts(page);
		await importBtn.click();
		c.ok(await toastRe(page, /Naimportováno: 0 nových, 0 aktualizovaných, 2 přeskočených/), 'RA: import s hodnocením mimo 1-5 se přeskočí (ne tiše 5 hvězd)');
		c.ok(sql(site, "SELECT COUNT(*) FROM jos_uxstudio_reviews WHERE author IN ('E2E Six','E2E Ten')") === '0', 'RA: hodnocení mimo rozsah nebylo uloženo');
		c.ok(/Připravit obsah/.test(await page.locator('#uxs-root').innerText()), 'RA: nápověda zmiňuje "Připravit obsah" pro modul Vlastní');
		c.ok(sql(site, "SELECT COUNT(*) FROM jos_uxstudio_reviews WHERE author LIKE 'E2E %' AND origin = 'import'") === '4', 'RA: DB drží 4 importované recenze');
		c.ok(sql(site, "SELECT COUNT(*) FROM jos_uxstudio_reviews WHERE author LIKE 'E2E%'") === '6', 'RA: DB celkem 6 testovacích recenzí');
		await page.locator('.uxs-cards').waitFor();
		c.ok((await page.locator('.uxs-cards').innerText()).includes('Průměrné hodnocení'), 'RA: karty statistiky');
		c.ok((await page.locator('progress').count()) === 5, 'RA: rozložení hodnocení (5 řádků)');
		await snap(page, 'ra-overview2-cs-light');

		// ---------- imported review is editable, CA stub is "ca" origin (edit button absent) - checked in the list
		await tab(page, 'reviews');
		await page.locator('#uxs-root tbody tr').first().waitFor();
		await hasRow(page, 'E2E Bob');
		c.ok((await rowOf('E2E Bob').locator('.uxs-actions button').count()) === 3, 'RA: importovaná recenze má tlačítka skrýt/upravit/smazat');
		await page.selectOption('select[aria-label="Platforma"]', 'e2e');
		await page.waitForLoadState('networkidle');
		c.ok(/\d+ recenz/.test(await page.locator('.uxs-list-toolbar__count').innerText()), 'RA: počet recenzí se skloňuje');

		// ---------- settings tab
		await tab(page, 'settings');
		await page.locator('.uxs-subtabs [role=tab]').first().waitFor();
		const subTabs = await page.locator('.uxs-subtabs [role=tab]').allInnerTexts();
		c.ok(subTabs.length === 3, `RA: nastavení má 3 sekce (${subTabs.join(', ')})`);
		await collect(page, 'ra-settings-display');
		await snap(page, 'ra-settings-cs-light');
		// source section: show_if
		await page.locator('.uxs-subtabs [role=tab]').nth(1).click();
		await collect(page, 'ra-settings-source');
		c.ok(await page.locator('#uxs-fetch_interval').count() > 0, 'RA: interval stahování je vidět při zapnutém auto-stahování');
		await page.locator('#uxs-root form button.uxs-switch').first().click();
		await page.waitForTimeout(500);
		c.ok((await page.locator('#uxs-fetch_interval').count()) === 0, 'RA: show_if schová interval po vypnutí auto-stahování');
		await page.locator('#uxs-root form button.uxs-switch').first().click();
		// SEO section
		await page.locator('.uxs-subtabs [role=tab]').nth(2).click();
		await collect(page, 'ra-settings-seo');
		await page.selectOption('#uxs-schema_type', 'LocalBusiness');
		await page.waitForTimeout(500);
		await page.fill('#uxs-schema_name', 'E2E Test Firma');
		await page.fill('#uxs-schema_image', '/images/e2e-logo.png');
		await page.locator('#uxs-root form button.uxs-switch').nth(1).click(); // schema_reviews (2nd toggle in the section)
		await snap(page, 'ra-settings-seo-cs-light');
		await clearToasts(page);
		await clearToasts(page);
		await page.locator('form button[type=submit]').click();
		c.ok(await toastRe(page, /uložen/i), 'RA: nastavení uloženo');
		// out-of-range number
		await page.locator('.uxs-subtabs [role=tab]').first().click();
		await page.fill('#uxs-min_rating', '9');
		await clearToasts(page);
		await clearToasts(page);
		await page.locator('form button[type=submit]').click();
		await page.waitForTimeout(1200);
		const savedMin = sql(site, "SELECT JSON_UNQUOTE(JSON_EXTRACT(data,'$.min_rating')) FROM jos_uxstudio_settings WHERE module = 'review-aggregator'");
		c.ok(['5', '1'].includes(savedMin) || (await toastRe(page, /min|rozsah|neplatn/i, 500)), `RA: min_rating 9 je serverem omezen/odmítnut (uloženo: ${savedMin})`);
		await page.fill('#uxs-min_rating', '1');
		await clearToasts(page);
		await page.locator('form button[type=submit]').click();
		await toastRe(page, /uložen/i);
		await openModule(page, site, 'review-aggregator');
		await page.reload();
		await openModule(page, site, 'review-aggregator');
		await tab(page, 'settings');
		await page.locator('.uxs-subtabs [role=tab]').nth(2).click();
		c.ok((await page.inputValue('#uxs-schema_name')) === 'E2E Test Firma', 'RA: nastavení přežije reload');

		// ======================================================== FRONT END
		const anon = (await context(browser, { ignore: EXPECTED_HTTP }));
		const m1 = await createReviewsModule(page, site, 'RVX Reviews', { heading: 'E2E Grid', layout_mode: 'grid', source: 'e2e' });
		c.ok(m1.id > 0 && m1.ok, `Joomla: modul UX1 Studio - Reviews vytvořen přes com_modules (id ${m1.id})`);
		const readSchema = (arr) => arr.find((j) => j.aggregateRating);

		let fp = await front(anon.ctx, site);
		let sec = await section(fp, 'E2E Grid');
		if ((await sec.count()) !== 1) {
			console.log('DEBUG front: sections=', await fp.locator('section.uxs-reviews').count(), 'headings=', await fp.locator('h3.uxs-reviews__heading').allInnerTexts(), 'url', fp.url(), 'title', await fp.title());
			await fp.screenshot({ path: `${SHOTS}reviews-debug-front.png` });
			console.log((await fp.locator('body').innerText()).slice(0, 300).replace(/\s+/g, ' '));
		}
		c.ok((await sec.count()) === 1, 'Front: modul se zobrazí anonymnímu návštěvníkovi');
		c.ok((await sec.locator('article.uxs-reviews__card').count()) === 5, 'Front grid: 5 viditelných recenzí zdroje e2e');
		c.ok(await sec.evaluate((n) => n.classList.contains('uxs-reviews--grid')), 'Front: třída uxs-reviews--grid');
		c.ok(/3[.,]0/.test(await sec.locator('.uxs-reviews__avg').innerText()), 'Front: průměr 3.0 v souhrnu');
		c.ok(/5/.test(await sec.locator('.uxs-reviews__count').innerText()), 'Front: počet 5 v souhrnu');
		const cr = await sec.evaluate((n) => {
			const el = n.querySelector('.uxs-reviews__date');
			if (!el) return null;
			const parse = (col) => { const cs = col.match(/color\(srgb ([^)]+)\)/); if (cs) { const q = cs[1].split(/[ \/]+/).filter(Boolean).map(Number); return { r: q[0] * 255, g: q[1] * 255, b: q[2] * 255, a: q[3] ?? 1 }; } const m = col.match(/rgba?\(([^)]+)\)/); const p = m[1].split(/[ ,\/]+/).filter(Boolean).map(Number); return { r: p[0], g: p[1], b: p[2], a: p[3] ?? 1 }; };
			const lum = ({ r, g, b }) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
			let bg = { r: 255, g: 255, b: 255, a: 1 };
			for (let e = el; e; e = e.parentElement) { const b = parse(getComputedStyle(e).backgroundColor); if (b.a > 0.9) { bg = b; break; } }
			const fg = parse(getComputedStyle(el).color);
			const mix = { r: fg.r * fg.a + bg.r * (1 - fg.a), g: fg.g * fg.a + bg.g * (1 - fg.a), b: fg.b * fg.a + bg.b * (1 - fg.a) };
			const [hi, lo] = [lum(mix), lum(bg)].sort((x, y) => y - x);
			return (hi + 0.05) / (lo + 0.05);
		});
		c.ok(cr !== null && cr >= 4.5, `Front: kontrast data recenze >= 4.5:1 (${cr?.toFixed(2)})`);
		const alice = sec.locator('article', { hasText: 'E2E Alice' });
		c.ok((await alice.locator('.uxs-reviews__star.is-filled').count()) === 5, 'Front: Alice má 5 plných hvězd');
		const aliceTxt = await alice.innerText();
		c.ok((await alice.locator('b').count()) === 0 && !aliceTxt.includes('<') && aliceTxt.includes('tučně') && aliceTxt.includes('& "uvozovky"'), 'Front: HTML v textu recenze se neprovede (tagy odstraněny, & a " správně escapované)');
		const dan = sec.locator('article', { hasText: 'E2E Dan' });
		const more = dan.locator('button.uxs-reviews__more');
		c.ok((await more.count()) === 1, 'Front: dlouhý text má "zobrazit více"');
		console.log('DEBUG more:', JSON.stringify(await more.boundingBox()), await more.isVisible(), await more.evaluate((n) => getComputedStyle(n).cssText.length && [getComputedStyle(n).display, getComputedStyle(n).visibility, getComputedStyle(n).opacity, getComputedStyle(n).transition].join('|')));
		await fp.screenshot({ path: `${SHOTS}reviews-debug-front.png`, fullPage: true });
		await more.click({ timeout: 5000 });
		c.ok(await dan.locator('.uxs-reviews__full').isVisible() && (await more.getAttribute('aria-expanded')) === 'true', 'Front: "více" rozbalí celý text');
		await more.click();
		c.ok(!(await dan.locator('.uxs-reviews__full').isVisible()), 'Front: druhý klik text sbalí');
		let ld = await ldJson(fp);
		const sch = readSchema(ld);
		c.ok(!!sch && sch.aggregateRating.reviewCount === 5 && sch.aggregateRating.ratingValue === '3.0', `Front: JSON-LD AggregateRating 3.0 / 5 (${sch ? sch.aggregateRating.ratingValue + '/' + sch.aggregateRating.reviewCount : 'chybí'})`);
		c.ok(ld.filter((j) => j.aggregateRating).length === 1, 'Front: AggregateRating je na stránce právě jednou (i s dalším modulem recenzí)');
		c.ok(sch?.['@type'] === 'LocalBusiness' && sch?.name === 'E2E Test Firma' && /e2e-logo\.png$/.test(sch?.image ?? ''), 'Front: schema typ/název/obrázek z nastavení');
		c.ok(!sch?.review, 'Front: recenze z jiné platformy než manual se do schema neznačkují');
		c.ok(!ld.some((j) => j.__invalid), 'Front: JSON-LD je validní JSON');
		await fp.setViewportSize({ width: 390, height: 800 });
		await shot(fp, `reviews-${label}-front-grid-mobile`);
		await fp.setViewportSize({ width: 1400, height: 900 });
		await sec.first().screenshot({ path: `${SHOTS}reviews-${label}-front-grid.png` });
		await fp.close();

		// list + min rating
		let r = await editReviewsModule(page, site, m1.id, { layout_mode: 'list', min_rating: '4' });
		c.ok(r.ok, 'Joomla: úprava modulu (list, min 4) uložena');
		fp = await front(anon.ctx, site);
		sec = await section(fp, 'E2E Grid');
		c.ok(await sec.evaluate((n) => n.classList.contains('uxs-reviews--list')), 'Front list: třída uxs-reviews--list');
		c.ok((await sec.locator('article').count()) === 2, 'Front list + min 4: jen Alice a Bob');
		ld = await ldJson(fp);
		c.ok(readSchema(ld)?.aggregateRating.reviewCount === 5, 'Front: průměr a počet ignorují filtr min. hodnocení');
		await sec.first().screenshot({ path: `${SHOTS}reviews-${label}-front-list.png` });
		await fp.close();

		// slider + count + order
		await editReviewsModule(page, site, m1.id, { layout_mode: 'slider', min_rating: '', count: '2', order: 'best_rating' });
		fp = await front(anon.ctx, site);
		sec = await section(fp, 'E2E Grid');
		c.ok(await sec.evaluate((n) => n.classList.contains('uxs-reviews--slider')), 'Front slider: třída uxs-reviews--slider');
		const cards = await sec.locator('article .uxs-reviews__name').allInnerTexts();
		c.ok(cards.length === 2 && cards[0] === 'E2E Alice' && cards[1] === 'E2E Bob', `Front slider: count=2, řazení nejlepší (${cards.join(', ')})`);
		c.ok((await sec.locator('.uxs-reviews__list').evaluate((n) => getComputedStyle(n).scrollSnapType)).includes('x'), 'Front slider: scroll-snap');
		await sec.first().screenshot({ path: `${SHOTS}reviews-${label}-front-slider.png` });
		await fp.close();

		// oldest order + badge
		await editReviewsModule(page, site, m1.id, { layout_mode: 'badge', count: '0', order: 'oldest' });
		fp = await front(anon.ctx, site);
		sec = await section(fp, 'E2E Grid');
		c.ok((await sec.locator('article').count()) === 0 && (await sec.locator('.uxs-reviews__summary').count()) === 1, 'Front badge: jen souhrn bez karet');
		await sec.first().screenshot({ path: `${SHOTS}reviews-${label}-front-badge.png` });
		await fp.close();

		// schema off for this module: no JSON-LD right behind it
		await editReviewsModule(page, site, m1.id, { layout_mode: 'grid', schema: '0', order: 'oldest' });
		fp = await front(anon.ctx, site);
		sec = await section(fp, 'E2E Grid');
		c.ok((await sec.evaluate((n) => (n.nextElementSibling ? n.nextElementSibling.tagName : ''))) !== 'SCRIPT', 'Front: schema=Ne -> za modulem není JSON-LD');
		const oldest = await sec.locator('article .uxs-reviews__name').allInnerTexts();
		c.ok(oldest[0] === 'E2E Bob', `Front: řazení nejstarší první (${oldest[0]})`);
		await fp.close();

		// hidden review disappears + empty state
		sql(site, "UPDATE jos_uxstudio_reviews SET visible = 0 WHERE author = 'E2E Eve'");
		await editReviewsModule(page, site, m1.id, { schema: '', order: 'newest' });
		fp = await front(anon.ctx, site);
		sec = await section(fp, 'E2E Grid');
		c.ok((await sec.locator('article', { hasText: 'E2E Eve' }).count()) === 0 && (await sec.locator('article').count()) === 4, 'Front: skrytá recenze se nezobrazuje');
		ld = await ldJson(fp);
		c.ok(readSchema(ld)?.aggregateRating.reviewCount === 4 && readSchema(ld)?.aggregateRating.ratingValue === '3.5', 'Front: průměr po skrytí = 3.5 z 4');
		await fp.close();
		sql(site, "UPDATE jos_uxstudio_reviews SET visible = 1 WHERE author = 'E2E Eve'");

		await editReviewsModule(page, site, m1.id, { source: 'neexistujici' });
		fp = await front(anon.ctx, site);
		sec = await section(fp, 'E2E Grid');
		c.ok((await sec.locator('.uxs-reviews__empty').count()) === 1, 'Front: prázdný stav pro zdroj bez recenzí');
		c.ok(!(await ldJson(fp)).some((j) => j.aggregateRating && j.aggregateRating.reviewCount === 0), 'Front: prázdný stav nevypisuje AggregateRating s nulou');
		await fp.close();

		// manual source with schema reviews
		await editReviewsModule(page, site, m1.id, { source: 'manual', count: '5' });
		fp = await front(anon.ctx, site);
		ld = await ldJson(fp);
		const withRev = readSchema(ld);
		c.ok(Array.isArray(withRev?.review) && withRev.review.some((x) => x.author.name === 'E2E Manual Zed' && x.reviewRating.ratingValue === '4'), 'Front: schema_reviews značí ručně zadané recenze (Review)');
		await fp.close();

		// content tag in a Custom module
		await editReviewsModule(page, site, m1.id, { source: 'e2e', count: '0' });
		const tagMod = await createCustomModule(page, site, 'RVX Tag', '<p>E2E tag intro</p>{uxstudio_reviews layout=list count=2 min_rating=4 platform=e2e heading="E2E Tag" schema=0}<p>{uxstudio_reviews layout=nonsense count=abc}</p>', 'main-bottom');
		c.ok(tagMod.ok && tagMod.id > 0, 'Joomla: modul Vlastní se značkou vytvořen');
		fp = await front(anon.ctx, site);
		const tagSec = await section(fp, 'E2E Tag');
		if (!(await tagSec.count())) {
			const t = await fp.locator('body').innerText();
			const i = t.indexOf('E2E tag intro');
			console.log('DEBUG tag: ', i < 0 ? 'intro missing' : t.slice(i, i + 200).replace(/\s+/g, ' '));
		}
		c.ok((await tagSec.count()) === 1 && (await tagSec.locator('article').count()) === 2 && (await tagSec.evaluate((n) => n.classList.contains('uxs-reviews--list'))), 'Front: značka {uxstudio_reviews layout=list count=2 min_rating=4 platform=e2e}');
		c.ok(!(await fp.content()).includes('{uxstudio_reviews'), 'Front: nezůstala žádná neprovedená značka (ani s nesmyslnými parametry)');
		await shot(fp, `reviews-${label}-front-page`);
		await fp.close();

		// ---------- tiny a11y / CSP check of front markup
		fp = await front(anon.ctx, site);
		c.ok((await fp.locator('section.uxs-reviews [style]').count()) === 0, 'Front: markup recenzí bez inline stylů (CSP)');
		await fp.close();

		// ======================================================== GOOGLE REVIEW REQUEST
		await openModule(page, site, 'google-review-request');
		await collect(page, 'grr-send');
		c.ok(await page.locator('.uxs-notice[role=alert]', { hasText: 'Odkaz na recenzi není nastaven' }).count() > 0, 'GRR: bez odkazu notice "Odkaz na recenzi není nastaven"');
		await page.fill('#grr-list', 'jana@rvx.test');
		const sendBtn = page.locator('.uxs-form__actions .uxs-btn--primary');
		c.ok(await sendBtn.isDisabled(), 'GRR: bez odkazu je Odeslat neaktivní');
		c.ok(await page.locator('button', { hasText: 'Poslat test sobě' }).isDisabled(), 'GRR: bez odkazu je test neaktivní');
		await snap(page, 'grr-send-nolink-cs-light');

		// preview without link still renders
		await page.locator('button', { hasText: 'Náhled' }).click();
		await modal(page).waitFor();
		c.ok((await modal(page).innerText()).includes('Předmět'), 'GRR: náhled se otevře i bez odkazu');
		await page.keyboard.press('Escape');
		await modal(page).waitFor({ state: 'hidden' });
		c.ok(true, 'GRR: Esc zavře náhled');

		// settings: invalid link first
		await tab(page, 'settings');
		await page.locator('.uxs-subtabs [role=tab]').first().waitFor();
		await collect(page, 'grr-settings-message');
		await page.fill('#uxs-google_review_url', 'tohle neni odkaz');
		await page.fill('#uxs-email_subject', 'E2E Prosba pro {name} z {site}');
		await clearToasts(page);
		await clearToasts(page);
		await page.locator('form button[type=submit]').click();
		c.ok(await toastRe(page, /uložen/i), 'GRR: nastavení (neplatný odkaz) se uložilo');
		await tab(page, 'send');
		await page.waitForLoadState('networkidle');
		c.ok(await page.locator('.uxs-notice[role=alert]', { hasText: 'Odkaz na recenzi není nastaven' }).count() > 0, 'GRR: neplatný odkaz se nepovažuje za nastavený');
		// valid Place ID
		await tab(page, 'settings');
		await page.fill('#uxs-google_review_url', 'ChIJN1t_tDeuEmsRUsoyG83frY4');
		await page.locator('.uxs-subtabs [role=tab]').nth(1).click();
		await collect(page, 'grr-settings-rules');
		await page.fill('#uxs-blocklist', 'blocked.rvx.test\nspam@rvx.test');
		await page.fill('#uxs-cooldown_days', '90');
		await page.locator('.uxs-subtabs [role=tab]').nth(2).click();
		await collect(page, 'grr-settings-automation');
		await snap(page, 'grr-settings-automation-cs-light');
		await clearToasts(page);
		await clearToasts(page);
		await page.locator('form button[type=submit]').click();
		c.ok(await toastRe(page, /uložen/i), 'GRR: nastavení s Place ID uloženo');
		await tab(page, 'send');
		await page.waitForLoadState('networkidle');
		c.ok(await page.locator('.uxs-notice[role=alert]', { hasText: 'Odkaz na recenzi není nastaven' }).waitFor({ state: 'detached', timeout: 6000 }).then(() => true, () => false), 'GRR: s Place ID zmizí varování');
		const mailOn = (await page.locator('.uxs-notice.is-error').count()) === 0;
		await snap(page, 'grr-send-cs-light');

		// preview with the link
		await page.fill('#grr-list', 'jana@rvx.test; Jana Nováková; obj-1');
		await page.locator('button', { hasText: 'Náhled' }).click();
		await modal(page).waitFor();
		const mtxt = await modal(page).innerText();
		c.ok(/E2E Prosba pro Jana Nováková z /.test(mtxt), 'GRR: náhled dosadí {name} a {site} do předmětu');
		const body = await modal(page).locator('iframe').getAttribute('srcdoc');
		c.ok(body.includes('writereview?placeid=ChIJN1t_tDeuEmsRUsoyG83frY4'), 'GRR: náhled obsahuje odkaz z Place ID');
		c.ok((await modal(page).locator('iframe').getAttribute('sandbox')) === '', 'GRR: iframe náhledu je v sandboxu');
		await snap(page, 'grr-preview-cs-light');
		await modal(page).locator('.uxs-btn').click();
		await modal(page).waitFor({ state: 'hidden' });

		// send: invalid address
		await page.fill('#grr-list', 'neni-email');
		await page.locator('.uxs-form__actions .uxs-btn--primary').click();
		await page.locator('h3', { hasText: 'Výsledek' }).waitFor();
		c.ok(/Neplatná e-mailová adresa/.test(await page.locator('#uxs-root tbody').first().innerText()), 'GRR: neplatný e-mail -> přeskočeno s důvodem');
		c.ok(sql(site, "SELECT COUNT(*) FROM jos_uxstudio_grr_log WHERE email = 'neni-email'") === '0', 'GRR: neplatná adresa se do logu nezapisuje');

		// send: list with duplicate + blocked
		await page.fill('#grr-list', 'a1@rvx.test; Anna; obj-1\na1@rvx.test; Anna dup\nb@blocked.rvx.test; Blok\nspam@rvx.test');
		await page.locator('.uxs-form__actions .uxs-btn--primary').click();
		await page.waitForFunction(() => /Listed twice|Uvedeno dvakrát/.test(document.body.innerText));
		const resTxt = await page.locator('#uxs-root table').last().innerText();
		c.ok(/Uvedeno dvakrát/.test(resTxt), 'GRR: duplicita v seznamu přeskočena');
		c.ok((resTxt.match(/Na seznamu „nikdy neposílat“/g) ?? []).length === 2, 'GRR: adresa i doména z blocklistu přeskočeny');
		const a1 = sql(site, "SELECT status, channel, ref FROM jos_uxstudio_grr_log WHERE email = 'a1@rvx.test' ORDER BY id LIMIT 1").split('\t');
		c.ok(['sent', 'failed'].includes(a1[0]) && a1[1] === 'bulk' && a1[2] === 'obj-1', `GRR: a1 zalogováno (${a1.join('/')}, kanál bulk, reference)`);
		c.ok(sql(site, "SELECT COUNT(*) FROM jos_uxstudio_grr_log WHERE email = 'b@blocked.rvx.test' AND status = 'skipped' AND reason = 'blocked'") >= '1', 'GRR: blocklist zalogován jako skipped/blocked');
		// e-mail log capture
		const el = sql(site, "SELECT status, source, subject FROM jos_uxstudio_email_log WHERE to_email = 'a1@rvx.test' ORDER BY id DESC LIMIT 1").split('\t');
		c.ok(el.length >= 3 && /UX1 Studio/.test(el[1]) && /E2E Prosba pro Anna z /.test(el[2]), `GRR: e-mail zachycen v email-logu (${el[0]} | ${el[1]} | ${el[2]})`);
		const msg = sql(site, "SELECT message FROM jos_uxstudio_email_log WHERE to_email = 'a1@rvx.test' ORDER BY id DESC LIMIT 1");
		c.ok(msg.includes('writereview?placeid=ChIJN1t_tDeuEmsRUsoyG83frY4') && /unsubscribe/.test(msg), 'GRR: tělo e-mailu má odkaz na recenzi i odhlášení');
		const hdr = sql(site, "SELECT headers FROM jos_uxstudio_email_log WHERE to_email = 'a1@rvx.test' ORDER BY id DESC LIMIT 1");
		c.ok(/List-Unsubscribe/i.test(hdr), 'GRR: hlavička List-Unsubscribe je v odeslaném e-mailu');

		// cooldown + force
		sql(site, `INSERT INTO jos_uxstudio_grr_log (created, email, name, status, reason, channel, ref) VALUES (NOW(), 'cool@rvx.test', '', 'sent', '', 'manual', '')`);
		await page.fill('#grr-list', 'cool@rvx.test');
		await page.locator('.uxs-form__actions .uxs-btn--primary').click();
		await page.waitForFunction(() => /Asked recently|Nedávno osloveno/.test(document.body.innerText));
		c.ok(true, 'GRR: adresa osloveného v ochranné lhůtě je přeskočena');
		await page.locator('#grr-force').check();
		await page.fill('#grr-list', 'cool@rvx.test');
		await page.locator('.uxs-form__actions .uxs-btn--primary').click();
		await page.waitForFunction(() => !/Nedávno osloveno/.test(document.querySelector('#uxs-root').innerText) || false, null, { timeout: 15000 }).catch(() => {});
		c.ok((await pollSql(site, "SELECT COUNT(*) FROM jos_uxstudio_grr_log WHERE email = 'cool@rvx.test'", '3')) === '3', 'GRR: "Ignorovat lhůtu" odešle i osloveného');
		await page.locator('#grr-force').uncheck();

		// unsubscribe via the real link (from the captured message, fallback computed)
		const unsubFromMail = /href="([^"]*grr[^"]*|[^"]*google-review-request\/unsubscribe[^"]*)"/.exec(msg.replace(/&amp;/g, '&'))?.[1];
		const tok = crypto.createHmac('sha256', secret).update('grr-unsub|a1@rvx.test').digest('hex').slice(0, 40);
		const unsubUrl = unsubFromMail && unsubFromMail.startsWith('http') ? unsubFromMail : `${site.url}/index.php?option=com_uxstudio&task=public.dispatch&format=json&route=google-review-request/unsubscribe&e=a1%40rvx.test&t=${tok}`;
		const up = await anon.ctx.newPage();
		const ur = await up.goto(unsubUrl);
		c.ok(ur.status() === 200, `GRR: odhlašovací odkaz otevře potvrzovací stránku (HTTP ${ur.status()})`);
		c.ok(sql(site, "SELECT COUNT(*) FROM jos_uxstudio_grr_optout WHERE email = 'a1@rvx.test'") === '0', 'GRR: samotné otevření odkazu adresu neodhlásí (skenery pošty)');
		await shot(up, `reviews-${label}-unsub-confirm`);
		await up.locator('a.b').click();
		await up.waitForLoadState('load');
		c.ok(sql(site, "SELECT COUNT(*) FROM jos_uxstudio_grr_optout WHERE email = 'a1@rvx.test'") === '1', 'GRR: potvrzení odhlášení zapíše adresu');
		const bad = await up.goto(unsubUrl.replace(/t=[0-9a-f]+/, 't=deadbeef'));
		c.ok(bad.status() === 400, `GRR: podvržený token odhlášení -> 400 (HTTP ${bad.status()})`);
		await up.close();
		await page.fill('#grr-list', 'a1@rvx.test');
		await page.locator('#grr-force').check();
		await page.locator('.uxs-form__actions .uxs-btn--primary').click();
		await page.waitForFunction(() => /customer unsubscribed|Zákazník se odhlásil/.test(document.body.innerText));
		c.ok(true, 'GRR: odhlášená adresa je přeskočena i s "Ignorovat lhůtu"');
		await page.locator('#grr-force').uncheck();

		// daily limit
		await tab(page, 'settings');
		await page.locator('.uxs-subtabs [role=tab]').nth(1).click();
		await page.fill('#uxs-daily_limit', '2');
		await clearToasts(page);
		await page.locator('form button[type=submit]').click();
		await toastRe(page, /uložen/i);
		for (let i = 0; i < 2; i++) sql(site, `INSERT INTO jos_uxstudio_grr_log (created, email, name, status, reason, channel, ref) VALUES (NOW(), 'lim${i}@rvx.test', '', 'sent', '', 'manual', '')`);
		await tab(page, 'send');
		await page.waitForLoadState('networkidle');
		await page.waitForFunction(() => /\d+ \/ 2/.test(document.querySelector('.uxs-cards')?.innerText ?? ''), null, { timeout: 6000 }).catch(() => {});
		c.ok(/\d+ \/ 2/.test(await page.locator('.uxs-cards').innerText()), 'GRR: karta "Posledních 24 hodin" ukazuje počet / limit');
		await page.fill('#grr-list', 'limit@rvx.test');
		await page.locator('.uxs-form__actions .uxs-btn--primary').click();
		await page.waitForFunction(() => /Daily limit reached|Dosažen denní limit/.test(document.body.innerText));
		c.ok(true, 'GRR: denní limit zastaví odeslání');
		await tab(page, 'settings');
		await page.locator('.uxs-subtabs [role=tab]').nth(1).click();
		await page.fill('#uxs-daily_limit', '100');
		await clearToasts(page);
		await page.locator('form button[type=submit]').click();
		await toastRe(page, /uložen/i);

		// bulk > batch (12 addresses -> 2 requests)
		await tab(page, 'send');
		await page.waitForLoadState('networkidle');
		const bulk = Array.from({ length: 12 }, (_, i) => `bulk${i}@rvx.test; Bulk ${i}; B-${i}`).join('\n');
		await page.fill('#grr-list', bulk);
		c.ok(/12/.test(await page.locator('.uxs-form__actions .uxs-btn--primary').innerText()), 'GRR: tlačítko skloňuje počet žádostí (12)');
		await page.locator('.uxs-form__actions .uxs-btn--primary').click();
		await page.waitForFunction(() => document.querySelectorAll('#uxs-root table tbody tr').length >= 12, null, { timeout: 280000 });
		c.ok(sql(site, "SELECT COUNT(*) FROM jos_uxstudio_grr_log WHERE email LIKE 'bulk%@rvx.test' AND channel = 'bulk'") === '12', 'GRR: hromadné odeslání 12 adres po dávkách, všechny v logu (kanál bulk)');
		// the e-mail log is shared with the email-log scenario (which may clear it), so only "captured at all" is asserted
		c.ok(Number(await pollSql(site, "SELECT COUNT(*) FROM jos_uxstudio_email_log WHERE to_email LIKE 'bulk%@rvx.test'", '12', 8000)) > 0, 'GRR: hromadné e-maily zachyceny v email-logu');
		// duplicate across batches (1st and 11th line) with "ignore cool-down": must still be sent only once
		await page.locator('#grr-force').check();
		const dupList = ['dupx@rvx.test; Dup', ...Array.from({ length: 9 }, (_, k) => `dupf${k}@rvx.test`), 'DUPX@rvx.test'].join('\n');
		await page.fill('#grr-list', dupList);
		await page.locator('.uxs-form__actions .uxs-btn--primary').click();
		await page.waitForFunction(() => /Listed twice|Uvedeno dvakrát/.test(document.body.innerText), null, { timeout: 280000 });
		c.ok(sql(site, "SELECT COUNT(*) FROM jos_uxstudio_grr_log WHERE email = 'dupx@rvx.test'") === '1', 'GRR: adresa uvedená 2x napříč dávkami (s Ignorovat lhůtu) se odešle jen jednou');
		await page.locator('#grr-force').uncheck();
		await snap(page, 'grr-bulk-cs-light');

		// test mail
		await clearToasts(page);
		await page.locator('button', { hasText: 'Poslat test sobě' }).click();
		c.ok(await toastRe(page, /Test odeslán na|Test se neodeslal/, 120000), 'GRR: "Poslat test sobě" zobrazí výsledek');
		const tl = sql(site, `SELECT status, channel FROM jos_uxstudio_grr_log WHERE email = '${USER_EMAIL}' ORDER BY id DESC LIMIT 1`).split('\t');
		c.ok(tl[1] === 'test', `GRR: test zalogován s kanálem test (${tl.join('/')})`);
		c.ok(sql(site, `SELECT COUNT(*) FROM jos_uxstudio_email_log WHERE to_email = '${USER_EMAIL}'`) !== '0', 'GRR: test zachycen v email-logu');

		// ---------- log tab
		await tab(page, 'log');
		await page.locator('#uxs-root tbody tr').first().waitFor();
		await collect(page, 'grr-log');
		await snap(page, 'grr-log-cs-light');
		c.ok(/záznam|záznamů|záznamy/.test(await page.locator('.uxs-list-toolbar__count').innerText()), 'GRR log: počet záznamů');
		await page.selectOption('select[aria-label="Výsledek"]', 'skipped');
		await page.waitForFunction(() => { const r = [...document.querySelectorAll('#uxs-root tbody tr')]; return r.length > 0 && r.every((x) => /Přeskočeno/.test(x.innerText)); }, null, { timeout: 8000 }).catch(() => {});
		const skippedRows = await page.locator('#uxs-root tbody tr').allInnerTexts();
		c.ok(skippedRows.length > 0 && skippedRows.every((t) => /Přeskočeno/.test(t)), 'GRR log: filtr "Přeskočeno"');
		await page.selectOption('select[aria-label="Výsledek"]', '');
		await page.fill('.uxs-list-toolbar input[type=search]', 'obj-1');
		await page.locator('.uxs-list-toolbar button[type=submit]').click();
		await page.waitForFunction(() => { const r = [...document.querySelectorAll('#uxs-root tbody tr')]; return r.length > 0 && r.length < 6 && r.every((x) => /rvx\.test/.test(x.innerText)); }, null, { timeout: 8000 }).catch(() => {});
		c.ok((await page.locator('#uxs-root tbody tr').count()) >= 1 && (await page.locator('#uxs-root tbody').innerText()).includes('a1@rvx.test'), 'GRR log: hledání podle reference');
		await page.fill('.uxs-list-toolbar input[type=search]', 'zzzzqqqq');
		await page.locator('.uxs-list-toolbar button[type=submit]').click();
		await page.locator('td.is-empty').waitFor();
		c.ok(/Zatím nebyla odeslána/.test(await page.locator('td.is-empty').innerText()), 'GRR log: prázdný stav při hledání bez výsledku');
		// pagination
		await page.fill('.uxs-list-toolbar input[type=search]', '');
		for (let i = 0; i < 25; i++) sql(site, `INSERT INTO jos_uxstudio_grr_log (created, email, name, status, reason, channel, ref) VALUES (NOW() - INTERVAL ${i + 1} DAY, 'page${i}@rvx.test', '', 'skipped', 'cooldown', 'api', '')`);
		await page.locator('.uxs-list-toolbar button[type=submit]').click();
		await page.locator('.uxs-pager').waitFor();
		c.ok(/Strana 1 z \d+/.test(await page.locator('.uxs-pager').innerText()), 'GRR log: stránkování');
		await page.locator('.uxs-pager button', { hasText: 'Další' }).click();
		await page.waitForLoadState('networkidle');
		c.ok(/Strana 2 z/.test(await page.locator('.uxs-pager').innerText()), 'GRR log: další strana');
		await page.reload();
		await openModule(page, site, 'google-review-request');

		// ---------- public signed endpoint
		await tab(page, 'settings');
		await page.locator('.uxs-subtabs [role=tab]').nth(2).click();
		await page.fill('#uxs-api_secret', 'krátký');
		await clearToasts(page);
		await page.locator('form button[type=submit]').click();
		await toastRe(page, /uložen/i);
		await tab(page, 'send');
		await page.waitForLoadState('networkidle');
		c.ok(/Vypnuto/.test(await page.locator('.uxs-form__help', { hasText: 'Vypnuto' }).innerText()), 'GRR: krátký klíč = automatické žádosti vypnuté');
		const apiUrl = (n) => `${site.url}/index.php?option=com_uxstudio&task=public.dispatch&format=json&route=google-review-request/request`;
		const api = await request.newContext();
		const post = async (bodyObj, { sig = true, secretUsed = 'e2e-secret-0123456789abcdef', ts = Math.floor(Date.now() / 1000), raw = null } = {}) => {
			const bodyStr = raw ?? JSON.stringify(bodyObj);
			const headers = { 'Content-Type': 'application/json' };
			if (sig) {
				headers['X-UXS-Timestamp'] = String(ts);
				headers['X-UXS-Signature'] = crypto.createHmac('sha256', secretUsed).update(`${ts}.${bodyStr}`).digest('hex');
			}
			const res = await api.post(apiUrl(), { headers, data: bodyStr });
			return { status: res.status(), json: await res.json().catch(() => ({})), headers };
		};
		const closed = await post({ email: 'api0@rvx.test' });
		c.ok(closed.status >= 400, `GRR API: se zkráceným klíčem je endpoint zavřený (HTTP ${closed.status})`);
		await tab(page, 'settings');
		await page.locator('.uxs-subtabs [role=tab]').nth(2).click();
		const change = page.locator('#uxs-root form button', { hasText: 'Změnit' });
		if (await change.count()) await change.click();
		await page.locator('#uxs-api_secret').fill('e2e-secret-0123456789abcdef');
		await clearToasts(page);
		await page.locator('form button[type=submit]').click();
		await toastRe(page, /uložen/i);
		await tab(page, 'send');
		await page.waitForLoadState('networkidle');
		await page.waitForTimeout(800);
		if ((await page.locator('pre.uxs-code-editor').count()) !== 1) console.log('DEBUG secret row:', sql(site, "SELECT JSON_EXTRACT(data, '$.api_secret') FROM jos_uxstudio_settings WHERE module = 'google-review-request'"), '| toasts:', await page.locator('.uxs-toast').allInnerTexts());
		await page.locator('pre.uxs-code-editor').first().waitFor({ timeout: 10000 }).catch(() => {});
		c.ok((await page.locator('pre.uxs-code-editor').count()) === 1, 'GRR: s klíčem se ukáže adresa endpointu');
		const ok1 = await post({ email: 'api1@rvx.test', name: 'Api', ref: 'ord-9' });
		const st = ok1.json?.data?.status ?? ok1.json?.status;
		c.ok(ok1.status === 200 || (ok1.status === 502 && /send_failed/.test(JSON.stringify(ok1.json ?? {})) && sql(site, "SELECT status FROM jos_uxstudio_grr_log WHERE email = 'api1@rvx.test'") === 'failed'), `GRR API: podepsaný požadavek přijat (HTTP ${ok1.status}, ${st ?? ''})`);
		c.ok(sql(site, "SELECT COUNT(*) FROM jos_uxstudio_grr_log WHERE email = 'api1@rvx.test' AND channel = 'api' AND ref = 'ord-9'") === '1', 'GRR API: volání zalogováno s kanálem api a referencí');
		const unsigned = await post({ email: 'api2@rvx.test' }, { sig: false });
		c.ok(unsigned.status === 401 || unsigned.status === 403, `GRR API: bez podpisu odmítnuto (HTTP ${unsigned.status})`);
		const wrongSig = await post({ email: 'api2@rvx.test' }, { secretUsed: 'jiny-klic-jiny-klic-jiny' });
		c.ok(wrongSig.status === 401 || wrongSig.status === 403, `GRR API: špatný podpis odmítnut (HTTP ${wrongSig.status})`);
		const oldTs = await post({ email: 'api2@rvx.test' }, { ts: Math.floor(Date.now() / 1000) - 3600 });
		c.ok(oldTs.status >= 400, `GRR API: zastaralé časové razítko odmítnuto (HTTP ${oldTs.status})`);
		// replay guard (retried with fresh bodies: a parallel scenario may wipe the shared rate-limit table between the two calls)
		let first, again;
		for (let i = 0; i < 3; i++) {
			const replayBody = JSON.stringify({ email: `api3${i}@rvx.test` });
			const ts = Math.floor(Date.now() / 1000);
			first = await post(null, { raw: replayBody, ts });
			again = await post(null, { raw: replayBody, ts });
			if (again.status === 409) break;
		}
		c.ok(first.status !== 409 && again.status === 409, `GRR API: replay stejného podpisu -> 409 (${first.status}, ${again.status})`);
		const noEmail = await post({ name: 'bez emailu' });
		c.ok(noEmail.status === 422, `GRR API: chybí e-mail -> 422 (HTTP ${noEmail.status})`);
		const badMail = await post({ email: 'tohle-neni-email' });
		c.ok(badMail.status === 200 && (badMail.json?.data?.status ?? badMail.json?.status) === 'skipped', `GRR API: neplatný e-mail -> skipped (HTTP ${badMail.status})`);
		const blockedApi = await post({ email: 'x@blocked.rvx.test' });
		c.ok(blockedApi.status === 200 && (blockedApi.json?.data?.reason ?? blockedApi.json?.reason) === 'blocked', 'GRR API: blocklist platí i pro API');
		await api.dispose();

		// clear secret afterwards
		await tab(page, 'settings');
		await page.locator('.uxs-subtabs [role=tab]').nth(2).click();
		const removeBtn = page.locator('button', { hasText: 'Odebrat' });
		if (await removeBtn.count()) {
			await removeBtn.click();
			await clearToasts(page);
			await page.locator('form button[type=submit]').click();
			await toastRe(page, /uložen/i);
		}

		allIssues.push(...issues.map((i) => `[cs-light] ${i}`), ...anon.issues.map((i) => `[front] ${i}`));
		await ctx.close();
		await anon.ctx.close();

		// ======================================================== PASS 2: en-GB, dark
		setLang(site, 'en-GB');
		uiLang = 'en';
		const d = await context(browser, { ignore: EXPECTED_HTTP });
		await d.ctx.addInitScript(() => { try { window.localStorage.setItem('uxstudio-theme', 'dark'); } catch { /* sandboxed iframe */ } });
		const dp = await login(d.ctx, site, password);

		await openModule(dp, site, 'review-aggregator');
		c.ok((await dp.locator('[role=tab]').allInnerTexts()).join('|').includes('Reviews'), 'EN: záložky v angličtině');
		c.ok((await dp.locator('#uxs-root').getAttribute('data-theme')) === 'dark', 'Tmavý režim: data-theme=dark');
		await collect(dp, 'ra-reviews');
		await snap(dp, 'ra-reviews-en-dark');
		let bad2 = await contrastIssues(dp, ['.uxs-badge', 'tbody td', 'th', '.uxs-btn', '.uxs-list-toolbar__count', '.uxs-tabs [role=tab]']);
		c.ok(bad2.length === 0, `Tmavý režim: kontrast RA výpisu (${bad2.slice(0, 3).join('; ')})`);
		await dp.locator('.uxs-list-toolbar .uxs-btn--primary').click();
		await modal(dp).waitFor();
		await collect(dp, 'ra-modal');
		await snap(dp, 'ra-modal-en-dark');
		bad2 = await contrastIssues(dp, ['.uxs-modal label', '.uxs-modal .uxs-modal__title', '.uxs-modal .uxs-btn']);
		c.ok(bad2.length === 0, `Tmavý režim: kontrast modálu (${bad2.slice(0, 3).join('; ')})`);
		c.ok(await modal(dp).locator('.uxs-btn--primary').isDisabled(), 'EN: Save je neaktivní bez hodnocení');
		await dp.keyboard.press('Escape');
		await modal(dp).waitFor({ state: 'hidden' });
		await tab(dp, 'overview');
		await collect(dp, 'ra-overview');
		await snap(dp, 'ra-overview-en-dark');
		bad2 = await contrastIssues(dp, ['.uxs-card__label', '.uxs-card__num', '.uxs-section-title', '.uxs-form__help', '.uxs-notice', '.uxs-muted']);
		c.ok(bad2.length === 0, `Tmavý režim: kontrast přehledu (${bad2.slice(0, 3).join('; ')})`);
		await dp.fill('#rv-data', 'x');
		await dp.locator('button.uxs-btn--primary', { hasText: 'Import' }).click();
		c.ok(await toastRe(dp, /rating|header|CSV/i), 'EN: chyba importu v angličtině');
		await tab(dp, 'settings');
		for (let i = 0; i < 3; i++) {
			await dp.locator('.uxs-subtabs [role=tab]').nth(i).click();
			await collect(dp, ['ra-settings-display', 'ra-settings-source', 'ra-settings-seo'][i]);
		}
		await snap(dp, 'ra-settings-en-dark');

		await openModule(dp, site, 'google-review-request');
		await collect(dp, 'grr-send');
		await snap(dp, 'grr-send-en-dark');
		bad2 = await contrastIssues(dp, ['.uxs-card__label', '.uxs-card__num', '.uxs-notice', '.uxs-btn', '.uxs-form__help', 'label', '.uxs-section-title']);
		c.ok(bad2.length === 0, `Tmavý režim: kontrast GRR (${bad2.slice(0, 3).join('; ')})`);
		await dp.fill('#grr-list', 'en1@rvx.test; Eng');
		await dp.locator('button', { hasText: 'Preview' }).click();
		await modal(dp).waitFor();
		await snap(dp, 'grr-preview-en-dark');
		c.ok((await modal(dp).innerText()).includes('Subject'), 'EN: náhled e-mailu v angličtině');
		await dp.keyboard.press('Escape');
		await dp.locator('.uxs-form__actions .uxs-btn--primary').click();
		await dp.locator('h3', { hasText: 'Result' }).waitFor();
		const enRes = await dp.locator('#uxs-root table').last().innerText();
		c.ok(/Failed|Sent/.test(enRes), 'EN: výsledek odeslání (Sent/Failed) anglicky');
		await tab(dp, 'log');
		await dp.locator('#uxs-root tbody tr').first().waitFor();
		await collect(dp, 'grr-log');
		await snap(dp, 'grr-log-en-dark');
		bad2 = await contrastIssues(dp, ['.uxs-badge', 'tbody td', 'th', '.uxs-form__help']);
		c.ok(bad2.length === 0, `Tmavý režim: kontrast logu (${bad2.slice(0, 3).join('; ')})`);
		await tab(dp, 'settings');
		for (let i = 0; i < 3; i++) {
			await dp.locator('.uxs-subtabs [role=tab]').nth(i).click();
			await collect(dp, ['grr-settings-message', 'grr-settings-rules', 'grr-settings-automation'][i]);
		}
		await snap(dp, 'grr-settings-en-dark');
		allIssues.push(...d.issues.map((i) => `[en-dark] ${i}`));
		await d.ctx.close();

		// ---------- untranslated text detection (same text in cs and en on the same screen)
		const WHITELIST = /^(Data|Text|Dismiss|SMTP Error.*|Could not.*|CSV|JSON|Google|Facebook|Mapy\.cz|E-mail|Place ID|Organization|LocalBusiness|Product|Service|URL|HTML|reply-to|Reply-to|[A-Z0-9._@#-]+)$|E2E|@e2e|ChIJ|\{|^\d/;
		const leaks = [];
		for (const screen of Object.keys(textsByLang.cs)) {
			const en = new Set(textsByLang.en[screen] ?? []);
			for (const t of textsByLang.cs[screen]) {
				if (en.has(t) && !WHITELIST.test(t) && /[a-z]{3,}/i.test(t)) leaks.push(`${screen}: ${t.slice(0, 70)}`);
			}
		}
		for (const l of leaks.slice(0, 15)) console.log(`LEAK [${label}] ${l}`);
		c.ok(leaks.length === 0, `i18n: žádné nepřeložené texty v cs (${leaks.length} shodných s en)`);
	} finally {
		setLang(site, 'en-GB');
		if (process.env.KEEP) throw new Error('KEEP set: data left in place for debugging');
		cleanup(site);
		c.ok(sql(site, "SELECT COUNT(*) FROM jos_uxstudio_reviews WHERE author LIKE 'E2E%'") === '0', 'Úklid: testovací recenze smazány');
		c.ok(sql(site, "SELECT COUNT(*) FROM jos_modules WHERE title LIKE 'RVX %'") === '0', 'Úklid: testovací moduly smazány');
		c.ok(sql(site, `SELECT COUNT(*) FROM jos_users WHERE username = '${USER}'`) === '0', 'Úklid: testovací uživatel smazán');
	}
	return c.done(allIssues);
}

const browser = await launch();
let bad = 0;
for (const site of sitesFromArgv()) {
	try {
		bad += await run(browser, site);
	} catch (e) {
		await globalThis.__page?.screenshot({ path: `${SHOTS}reviews-fatal-${site.key}.png` }).catch(() => {});
	console.log(`FATAL [${site.key}] ${e.stack?.split('\n').slice(0, 6).join('\n')}`);
		bad += 1;
	}
}
await browser.close();
process.exit(bad ? 1 : 0);
