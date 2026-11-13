// Browser E2E for the popup modules: popup-manager and exit-popup (J5 + J6).
// Run: node tests/e2e/popups.mjs [j5|j6|all] [--headed]
// The scenario stashes the existing popups / settings / admin language in the DB, runs on a clean slate and restores everything
// (also on failure), so it is repeatable. Test data is prefixed "E2E".
import { execFileSync } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import { launch, context, adminLogin, openStudio, checker, sitesFromArgv, SHOTS } from './lib.mjs';

const MYSQL = 'D:/xampp/mysql/bin/mysql.exe';
const sql = (db, q) => execFileSync(MYSQL, ['-uroot', '--default-character-set=utf8mb4', db, '-N', '-B', '-e', q], { encoding: 'utf8' }).trim();
const hex = (s) => `CONVERT(UNHEX('${Buffer.from(s, 'utf8').toString('hex')}') USING utf8mb4) COLLATE utf8mb4_unicode_ci`;
const LIST_ROUTE = new RegExp('route=popup-manager/popups(&|$)');
const SUBS_ROUTE = new RegExp('route=exit-popup/subscribers(&|$|/)');
/** Name of the admin the harness logs in as (only that user's admin language is touched; never printed). */
function adminUser() {
	for (const line of fs.readFileSync('D:/xampp/htdocs/ux-studio-joomla/.env.local', 'utf8').split(String.fromCharCode(10))) {
		const m = line.trim().match(/^JOOMLA_ADMIN_USER=(.*)$/);
		if (m) return m[1].replace(/^["']|["']$/g, '');
	}
	throw new Error('JOOMLA_ADMIN_USER missing');
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// Expected API validation errors (422 / 429 / 404 from the two modules) are not browser issues. A 500 or a dropped request is
// ignored ONLY while a step simulates a failure (globalThis.__expectFail); any other 5xx from the modules is reported.
const IGNORE = [
	/challenges.cloudflare.com/, // Turnstile of another module on the front end
	/^HTTP (422|429|404) \S*route=(popup-manager|exit-popup)/,
	/Failed to load resource: the server responded with a status of (422|429|404)/,
	{ test: (t) => globalThis.__expectFail === true && /HTTP 500 |status of 500|ERR_FAILED/.test(t) },
];

const SECTION_PM = { title: 0, headline: 0, body: 0, image: 0, image_alt: 0, cta_text: 0, cta_url: 0, cta_new_tab: 0, position: 1, animation: 1, width: 1, bg_color: 1, overlay_opacity: 1,
	trigger_type: 2, delay_seconds: 2, scroll_percent: 2, exit_delay: 2, frequency: 2, frequency_days: 2, stop_after_click: 2,
	menu_mode: 3, menu_items: 3, url_patterns: 3, devices: 3, audience: 3, priority: 3, starts_at: 4, ends_at: 4 };
const SECTION_EP = { active: 0, headline: 0, body: 0, enable_cta: 0, cta_text: 0, cta_url: 0, width: 1, delay_seconds: 2, frequency_mode: 2, storage: 2, detect_mouse_leave: 2, time_on_page: 2,
	targeting_mode: 3, url_patterns: 3, devices: 3, audience: 3, starts_at: 3, ends_at: 3,
	enable_email: 4, email_placeholder: 4, email_button_text: 4, email_success_message: 4, consent_text: 4, enable_autoresponder: 4, autoresponder_subject: 4, autoresponder_body: 4, subscribers_retention_days: 4 };

/** Set one field of a SettingsFields form. kind: text (default) | select | toggle | multi | rich | datetime. */
async function setField(page, sections, key, val, kind = 'text') {
	await page.locator('.uxs-subtabs button[role=tab]').nth(sections[key]).click();
	const row = page.locator(`.uxs-form__row:has(label[for="uxs-${key}"])`).first();
	await row.waitFor({ timeout: 5000 });
	if (kind === 'select') await row.locator('select').selectOption(val);
	else if (kind === 'toggle') {
		const sw = row.locator('button.uxs-switch');
		if (((await sw.getAttribute('aria-pressed')) === 'true') !== val) await sw.click();
	} else if (kind === 'multi') {
		for (const label of val) await row.locator('label.uxs-checklist__item', { hasText: label }).locator('input').check();
	} else if (kind === 'rich') {
		await row.locator('button.uxs-rte__btn--source').click();
		await row.locator('textarea').fill(val);
	} else await page.locator(`#uxs-${key}`).fill(String(val));
}

const toast = (page, kind) => page.locator(`.uxs-toast.is-${kind}`);

export async function main() {
	const browser = await launch();
	let bad = 0;
	for (const site of sitesFromArgv()) {
		bad += await runSite(browser, site);
	}
	await browser.close();
	return bad;
}

async function runSite(browser, site) {
	const db = site.db;
	const c = checker(`popups ${site.key}`);
	const allIssues = [];
	const T = (n) => `${site.key}-popups-${n}`;
	const shot = async (page, name) => page.screenshot({ path: `${SHOTS}${T(name)}.png`, fullPage: true });
	const popupId = (title) => Number(sql(db, `SELECT id FROM jos_uxstudio_popups WHERE title=${hex(title)} ORDER BY id DESC LIMIT 1`) || 0);
	const statCount = (id, ev) => Number(sql(db, `SELECT COUNT(*) FROM jos_uxstudio_popup_stats WHERE popup_id=${id} AND event='${ev}'`));
	/** Tracking is a fire-and-forget request: poll the table instead of sleeping. */
	const waitStat = async (id, ev, min) => {
		for (let i = 0; i < 30; i++) {
			if (statCount(id, ev) >= min) return true;
			await sleep(400);
		}
		return false;
	};
	const seedStatsBefore = sql(db, 'SELECT COUNT(*) FROM jos_uxstudio_popup_stats WHERE popup_id IN (SELECT id FROM jos_uxstudio_popups)');
	let onCrash = async () => {};
	const step = async (name, fn) => {
		try {
			globalThis.__adminPage = globalThis.__adminPage || null;
			await fn();
		} catch (e) {
			if (globalThis.__adminPage) await globalThis.__adminPage.screenshot({ path: `${SHOTS}${T('fail-' + name.replace(/[^a-z0-9]+/gi, '_'))}.png`, fullPage: true }).catch(() => {});
			c.ok(false, `${name}: exception ${String(e.message).split('\n')[0]}`);
			if (/crash/i.test(e.message)) await onCrash();
			console.log(String(e.stack).split('\n').slice(0, 4).join('\n'));
		}
	};

	// ---- stash the live state -------------------------------------------------------------------------------------
	const stash = {
		exit: sql(db, "SELECT COUNT(*) FROM jos_uxstudio_settings WHERE module='exit-popup'") === '1' ? sql(db, "SELECT data FROM jos_uxstudio_settings WHERE module='exit-popup'") : null,
		pm: sql(db, "SELECT COUNT(*) FROM jos_uxstudio_settings WHERE module='popup-manager'") === '1' ? sql(db, "SELECT data FROM jos_uxstudio_settings WHERE module='popup-manager'") : null,
		users: sql(db, `SELECT id, HEX(params) FROM jos_users WHERE username=${hex(adminUser())}`).split('\n').filter(Boolean).map((l) => l.split('\t')),
	};
	// Left over from a crashed earlier run? Restore that stash first.
	if (sql(db, "SHOW TABLES LIKE 'jos_e2e_popups_stash'")) {
		sql(db, 'INSERT IGNORE INTO jos_uxstudio_popups SELECT * FROM jos_e2e_popups_stash; DROP TABLE jos_e2e_popups_stash');
	}
	sql(db, "DELETE FROM jos_uxstudio_popup_stats WHERE popup_id IN (SELECT id FROM jos_uxstudio_popups WHERE title LIKE 'E2E%'); DELETE FROM jos_uxstudio_popups WHERE title LIKE 'E2E%'; DELETE FROM jos_uxstudio_exit_popup_emails WHERE email LIKE 'e2e-%' OR email LIKE '=%'");
	sql(db, 'CREATE TABLE jos_e2e_popups_stash AS SELECT * FROM jos_uxstudio_popups; DELETE FROM jos_uxstudio_popups');
	const exitSettings = (patch) => {
		const base = stash.exit ? JSON.parse(stash.exit) : {};
		const next = { ...base, ...patch };
		sql(db, `DELETE FROM jos_uxstudio_settings WHERE module='exit-popup'; INSERT INTO jos_uxstudio_settings (module, data, updated_at) VALUES ('exit-popup', ${hex(JSON.stringify(next))}, NOW())`);
	};
	const resetRate = () => {
		const buckets = ['127.0.0.1', '::1'].map((ip) => `'${crypto.createHash('sha1').update(`exit-popup|POST subscribe|${ip}`).digest('hex')}'`);
		sql(db, `DELETE FROM jos_uxstudio_ratelimit WHERE bucket IN (${buckets.join(',')})`);
	};
	const setLang = (lang) => {
		for (const [id] of stash.users) sql(db, `UPDATE jos_users SET params=${hex(JSON.stringify(lang ? { admin_language: lang } : {}))} WHERE id=${Number(id)}`);
	};
	// exit popup off while popup-manager is tested
	exitSettings({ active: false });

	let restored = false;
	const restore = () => {
		if (restored) return;
		restored = true;
		try {
			sql(db, "DELETE FROM jos_uxstudio_popup_stats WHERE popup_id IN (SELECT id FROM jos_uxstudio_popups WHERE title LIKE 'E2E%'); DELETE FROM jos_uxstudio_popups WHERE title LIKE 'E2E%'; DELETE FROM jos_uxstudio_exit_popup_emails WHERE email LIKE 'e2e-%' OR email LIKE '=%'");
			sql(db, 'INSERT IGNORE INTO jos_uxstudio_popups SELECT * FROM jos_e2e_popups_stash; DROP TABLE IF EXISTS jos_e2e_popups_stash');
			sql(db, "DELETE FROM jos_uxstudio_settings WHERE module IN ('exit-popup','popup-manager')");
			if (stash.exit !== null) sql(db, `INSERT INTO jos_uxstudio_settings (module, data, updated_at) VALUES ('exit-popup', ${hex(stash.exit)}, NOW())`);
			if (stash.pm !== null) sql(db, `INSERT INTO jos_uxstudio_settings (module, data, updated_at) VALUES ('popup-manager', ${hex(stash.pm)}, NOW())`);
			for (const [id, h] of stash.users) sql(db, `UPDATE jos_users SET params=CONVERT(UNHEX('${h}') USING utf8mb4) WHERE id=${Number(id)}`);
			resetRate();
		} catch (e) {
			console.log('RESTORE FAILED', e.message);
		}
	};

	process.on('exit', restore);
	let page = null;
	// The admin account is shared with other test agents that switch its language: re-assert en-GB before every page load.
	const reloadEn = async () => {
		setLang('en-GB');
		await page.reload();
	};
	const openStudioEn = async (p, s, hash) => {
		setLang('en-GB');
		await openStudio(p, s, hash);
	};
	const PM = '#/module?id=popup-manager';
	const EP = '#/module?id=exit-popup';
	const anonCtxs = [];
	const anon = async (viewport) => {
		while (anonCtxs.length) await anonCtxs.pop().close().catch(() => {});
		const { ctx, issues } = await context(browser, { ignore: IGNORE });
		anonCtxs.push(ctx);
		const page = await ctx.newPage();
		if (viewport) await page.setViewportSize(viewport);
		allIssues.push(issues);
		return { ctx, page };
	};
	const open = (page, id) => page.locator(`#uxs-popup-${id}.is-open`);
	const home = `${site.url}/`;

	try {
		// ================================================================ ADMIN (en-GB), popup-manager
		setLang('en-GB');
		const { ctx, issues } = await context(browser, { ignore: IGNORE });
		allIssues.push(issues);
		page = await adminLogin(ctx, site);
		globalThis.__adminPage = page;
		onCrash = async () => {
			console.log('INFO renderer crashed, reopening the admin page');
			await page.close().catch(() => {});
			page = await ctx.newPage();
			globalThis.__adminPage = page;
			await openStudioEn(page, site, PM);
		};
		await openStudioEn(page, site, PM);
		await page.evaluate(() => localStorage.setItem('uxstudio-theme', 'light'));
		await reloadEn();
		await page.locator('#uxs-root').waitFor();

		await step('list empty + tabs', async () => {
			await page.locator('.uxs-tabs').waitFor();
			const tabs = (await page.locator('.uxs-tabs [role=tab]').allInnerTexts()).map((s) => s.trim());
			c.ok(tabs.join('|') === 'Popups|Settings', `tabs en: ${tabs.join('|')}`);
			await page.getByText('No popups yet.').waitFor({ timeout: 8000 }).catch(() => {});
			c.ok(await page.getByText('No popups yet.').isVisible(), 'empty state "No popups yet."');
			await shot(page, 'pm-list-empty-light');
		});

		await step('list error state', async () => {
			globalThis.__expectFail = true;
			await page.route(LIST_ROUTE, (r) => (r.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ ok: false, error: { code: 'boom', message: 'Simulated failure' } }) })));
			await reloadEn();
			await page.locator('[role=alert]').first().waitFor({ timeout: 20000 });
			c.ok((await page.locator('[role=alert]').first().innerText()).includes('Simulated failure'), 'list API error is shown in an alert');
			await page.unroute(LIST_ROUTE);
			globalThis.__expectFail = false;
			await reloadEn();
			await page.getByText('No popups yet.').waitFor();
		});

		await step('editor validation', async () => {
			await page.getByRole('button', { name: 'Add popup' }).click();
			await page.locator('.uxs-subtabs').waitFor();
			const sub = (await page.locator('.uxs-subtabs [role=tab]').allInnerTexts()).map((s) => s.trim());
			c.ok(sub.join('|') === 'Content|Appearance|Trigger|Targeting|Schedule', `editor sections: ${sub.join('|')}`);
			// all section screenshots + no empty section
			for (let i = 0; i < 5; i++) {
				await page.locator('.uxs-subtabs [role=tab]').nth(i).click();
				c.ok((await page.locator('.uxs-form__row').count()) > 0, `section ${sub[i]} renders fields`);
				if (i === 2) await shot(page, 'pm-editor-trigger');
			}
			// empty name
			await page.getByRole('button', { name: 'Save', exact: true }).click();
			await toast(page, 'error').first().waitFor();
			c.ok((await toast(page, 'error').first().innerText()).includes('Give the popup a name'), 'empty name -> validation toast');
			await page.locator('.uxs-subtabs [role=tab]').first().click();
			c.ok(await page.locator('#uxs-title').isVisible(), 'editor stays open after validation error');
			c.ok((await page.getByRole('heading', { name: 'New popup' }).count()) === 1, 'still in the New popup editor');
			// bad button URL
			await setField(page, SECTION_PM, 'title', 'E2E invalid');
			await setField(page, SECTION_PM, 'cta_text', 'Go');
			await setField(page, SECTION_PM, 'cta_url', 'javascript:alert(1)');
			await page.getByRole('button', { name: 'Save', exact: true }).click();
			await page.getByText('The button address must be #').first().waitFor({ timeout: 15000 });
			c.ok(true, 'javascript: button URL rejected');
			await setField(page, SECTION_PM, 'cta_url', 'ftp://x.test/a');
			await page.getByRole('button', { name: 'Save', exact: true }).click();
			await sleep(700);
			c.ok(popupId('E2E invalid') === 0, 'ftp: button URL not saved');
			await setField(page, SECTION_PM, 'cta_url', '#');
			// schedule end before start
			await setField(page, SECTION_PM, 'starts_at', '2031-05-02T10:00', 'datetime');
			await setField(page, SECTION_PM, 'ends_at', '2031-05-01T10:00', 'datetime');
			await page.getByRole('button', { name: 'Save', exact: true }).click();
			await page.getByText('The end of the schedule must be after its start').first().waitFor({ timeout: 45000 });
			c.ok(popupId('E2E invalid') === 0, 'schedule end before start rejected, nothing saved');
			// nonsense numbers: clamped or rejected, never a 500; check what is stored
			await setField(page, SECTION_PM, 'starts_at', '', 'text').catch(() => {});
			await page.locator('#uxs-starts_at').fill('');
			await page.locator('#uxs-ends_at').fill('');
			await setField(page, SECTION_PM, 'trigger_type', 'delay', 'select');
			const inv = [];
			const flagged = (k) => page.evaluate((key) => { const el = document.getElementById('uxs-' + key); return !!el && !el.validity.valid; }, k);
			await setField(page, SECTION_PM, 'delay_seconds', '-5');
			inv.push(await flagged('delay_seconds'));
			await setField(page, SECTION_PM, 'width', '99999');
			inv.push(await flagged('width'));
			await setField(page, SECTION_PM, 'priority', '7000');
			inv.push(await flagged('priority'));
			await page.getByRole('button', { name: 'Save', exact: true }).click();
			await sleep(800);
			const invalid = inv;
			c.ok(invalid.every((v) => v === true), `browser validation flags the out-of-range delay, width and priority (${invalid.join(',')})`);
			c.ok(popupId('E2E invalid') === 0, 'out-of-range numbers: nothing saved');
			// bypass the browser: the server must clamp or reject
			const res = await page.evaluate(async () => {
				const o = window.Joomla.getOptions('uxstudio');
				const r = await fetch(o.apiUrl + '&route=popup-manager/popups', { method: 'POST', headers: { 'X-CSRF-Token': o.csrf, 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ title: 'E2E invalid', delay_seconds: -5, width: 99999, priority: 7000, scroll_percent: 500, frequency_days: 0, overlay_opacity: 900, position: 'nowhere', trigger_type: 'bogus' }) });
				return { status: r.status, body: await r.json() };
			});
			const id = popupId('E2E invalid');
			if (id) {
				const cfg = JSON.parse(sql(db, `SELECT config FROM jos_uxstudio_popups WHERE id=${id}`));
				const pri = sql(db, `SELECT priority FROM jos_uxstudio_popups WHERE id=${id}`);
				c.ok(cfg.delay_seconds >= 0 && cfg.width <= 1200 && Number(pri) <= 100 && cfg.scroll_percent <= 100 && cfg.overlay_opacity <= 100 && cfg.position === 'center', `server clamps nonsense (delay ${cfg.delay_seconds}, width ${cfg.width}, priority ${pri}, scroll ${cfg.scroll_percent}, overlay ${cfg.overlay_opacity}, position ${cfg.position}, trigger ${cfg.trigger_type})`);
			} else {
				c.ok(res.status === 422, `server rejects nonsense with 422 (${res.status})`);
			}
		});
		// leave editor (Back to the list) and remove the validation popup if it exists
		await reloadEn();
		await page.locator('#uxs-root').waitFor();
		sql(db, "DELETE FROM jos_uxstudio_popups WHERE title='E2E invalid'");

		const fillEditor = async (name, f, publish = true) => {
			await page.getByRole('button', { name: 'Add popup' }).click();
			await page.locator('.uxs-subtabs').waitFor();
			await setField(page, SECTION_PM, 'title', name);
			for (const [k, v] of Object.entries(f)) {
				const kind = ['trigger_type', 'frequency', 'position', 'animation', 'menu_mode', 'audience'].includes(k) ? 'select'
					: ['cta_new_tab', 'stop_after_click'].includes(k) ? 'toggle'
					: k === 'devices' ? 'multi' : k === 'body' ? 'rich' : ['starts_at', 'ends_at'].includes(k) ? 'datetime' : 'text';
				await setField(page, SECTION_PM, k, v, kind);
			}
			if (publish) await page.getByRole('switch', { name: 'Published' }).click().catch(async () => page.locator('button.uxs-switch[aria-label="Published"]').click());
			await page.getByRole('button', { name: 'Save', exact: true }).click();
			await toast(page, 'success').first().waitFor({ timeout: 20000 });
			await page.getByRole('button', { name: 'Add popup' }).waitFor();
		};
		const editPopup = async (name, f) => {
			await page.locator(`tr:has(button:text-is("${name}")) button[aria-label=Edit]`).click();
			await page.locator('.uxs-subtabs').waitFor();
			await sleep(300);
			for (const [k, v] of Object.entries(f)) {
				const kind = ['trigger_type', 'frequency', 'position', 'animation', 'menu_mode', 'audience'].includes(k) ? 'select'
					: ['cta_new_tab', 'stop_after_click'].includes(k) ? 'toggle'
					: k === 'devices' ? 'multi' : k === 'body' ? 'rich' : ['starts_at', 'ends_at'].includes(k) ? 'datetime' : 'text';
				await setField(page, SECTION_PM, k, v, kind);
			}
			await page.getByRole('button', { name: 'Save', exact: true }).click();
			await page.locator('.uxs-toast.is-success').first().waitFor({ timeout: 20000 });
			await page.getByRole('button', { name: 'Add popup' }).waitFor();
		};

		await step('sanitising and double submit', async () => {
			const post = (body) => page.evaluate(async (b) => {
				const o = window.Joomla.getOptions('uxstudio');
				const r = await fetch(o.apiUrl + '&route=popup-manager/popups', { method: 'POST', headers: { 'X-CSRF-Token': o.csrf, 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(b) });
				return r.status;
			}, body);
			const stored = (title) => sql(db, `SELECT JSON_UNQUOTE(JSON_EXTRACT(config, '$.body')) FROM jos_uxstudio_popups WHERE title=${hex(title)} ORDER BY id DESC LIMIT 1`);
			await post({ title: 'E2E san unclosed', body: '<p>A</p><script>window.__q=1; alert("unclosed")', status: 'draft' });
			const b1 = stored('E2E san unclosed');
			c.ok(b1.includes('<p>A</p>') && !/alert|__q|script/i.test(b1), `unclosed <script> fragment dropped (${b1})`);
			await post({ title: 'E2E san style', body: '<p>C</p><style>body{display:none}', status: 'draft' });
			const b2 = stored('E2E san style');
			c.ok(b2.includes('<p>C</p>') && !/display|style/i.test(b2), `unclosed <style> fragment dropped (${b2})`);
			await post({ title: 'E2E san nested', body: '<p>N</p><scr<script>x</script>ipt>alert(2)</script>', status: 'draft' });
			const b3 = stored('E2E san nested');
			c.ok(!/alert|script/i.test(b3), `nested/split <script> does not reassemble (${b3})`);
			await post({ title: 'E2E Sleva <50 % dnes', body: '<p>Sleva <50 % a 3 < 5</p>', status: 'draft' });
			c.ok(popupId('E2E Sleva <50 % dnes') > 0, 'a literal "<" in the name is kept');
			const b4 = stored('E2E Sleva <50 % dnes');
			c.ok(/Sleva/.test(b4) && /50 % a 3/.test(b4), `a literal "<" in the body does not swallow text (${b4})`);
			await post({ title: '<3 E2E love', body: '', status: 'draft' });
			c.ok(popupId('<3 E2E love') > 0, 'name starting with "<3" is accepted');
			sql(db, "DELETE FROM jos_uxstudio_popups WHERE title LIKE 'E2E san%' OR title LIKE 'E2E Sleva%' OR title LIKE '<3 E2E%'");
			// double click on Save creates one row, double click on Duplicate creates one copy
			await reloadEn();
			await page.getByRole('button', { name: 'Add popup' }).click();
			await page.locator('.uxs-subtabs').waitFor();
			await setField(page, SECTION_PM, 'title', 'E2E dbl');
			await page.getByRole('button', { name: 'Save', exact: true }).dblclick();
			await page.getByRole('button', { name: 'Add popup' }).waitFor({ timeout: 20000 });
			await sleep(2000);
			c.ok(sql(db, "SELECT COUNT(*) FROM jos_uxstudio_popups WHERE title='E2E dbl'") === '1', 'double click on Save stores one popup');
			await page.locator('tr:has(button:text-is("E2E dbl")) button[aria-label=Duplicate]').dblclick();
			for (let i = 0; i < 40 && sql(db, "SELECT COUNT(*) FROM jos_uxstudio_popups WHERE title LIKE 'E2E dbl%'") < '2'; i++) await sleep(500);
			await sleep(2500);
			c.ok(sql(db, "SELECT COUNT(*) FROM jos_uxstudio_popups WHERE title LIKE 'E2E dbl%'") === '2', 'double click on Duplicate makes one copy');
			sql(db, "DELETE FROM jos_uxstudio_popups WHERE title LIKE 'E2E dbl%'");
			await reloadEn();
		});

		// ---------------------------------------------------------------- create P1 (modal, immediate, session)
		await step('create P1 via UI', async () => {
			await fillEditor('E2E modal', {
				headline: 'E2E headline', body: '<p>Hello <b>E2E</b></p><script>window.__xss=1</script><img src="javascript:alert(1)" onerror="window.__xss=2"><iframe src="/x"></iframe>',
				cta_text: 'Close me', cta_url: '#', trigger_type: 'immediate', frequency: 'session', animation: 'none',
			});
			await page.locator('tr:has(button:text-is("E2E modal"))').waitFor({ timeout: 15000 }).catch(() => {});
			c.ok(await page.locator('tr:has(button:text-is("E2E modal"))').count() === 1, 'P1 appears in the list');
			const row = page.locator('tr:has(button:text-is("E2E modal"))');
			c.ok((await row.innerText()).includes('Live'), 'P1 badge Live');
			c.ok((await row.innerText()).includes('Immediately'), 'P1 trigger label');
			c.ok((await row.innerText()).includes('0 / 0'), 'P1 stats 0 / 0');
			const id = popupId('E2E modal');
			const stored = JSON.parse(sql(db, `SELECT config FROM jos_uxstudio_popups WHERE id=${id}`));
			c.ok(!/script|onerror|javascript:|iframe|__xss/i.test(stored.body), `body sanitised on save (${stored.body.slice(0, 80)})`);
			await shot(page, 'pm-list-one-light');
		});
		const p1 = popupId('E2E modal');

		// ---------------------------------------------------------------- frontend P1
		await step('frontend P1 modal', async () => {
			const { page: fp } = await anon();
			await fp.goto(home);
			const dlg = open(fp, p1).locator('.uxs-popup__dialog');
			await dlg.waitFor({ timeout: 6000 });
			c.ok(true, 'P1 appears on the front end (anonymous)');
			c.ok((await dlg.getAttribute('role')) === 'dialog' && (await dlg.getAttribute('aria-modal')) === 'true', 'dialog role + aria-modal');
			c.ok(!!(await dlg.getAttribute('aria-labelledby')), 'dialog labelled by headline');
			c.ok(!(await fp.evaluate(() => !!window.__xss)), 'script/onerror in body not executed');
			await fp.evaluate(() => (window.__xss = 0));
			c.ok(await fp.evaluate(() => document.activeElement.closest('.uxs-popup__dialog') !== null), 'focus moved into the dialog');
			c.ok(await fp.evaluate(() => document.documentElement.classList.contains('uxs-popup-lock')), 'page scroll locked');
			for (let i = 0; i < 6; i++) await fp.keyboard.press('Tab');
			c.ok(await fp.evaluate(() => document.activeElement.closest('.uxs-popup__dialog') !== null), 'focus trapped after 6x Tab');
			for (let i = 0; i < 3; i++) await fp.keyboard.press('Shift+Tab');
			c.ok(await fp.evaluate(() => document.activeElement.closest('.uxs-popup__dialog') !== null), 'focus trapped after Shift+Tab');
			await fp.screenshot({ path: `${SHOTS}${T('fe-modal-desktop')}.png` });
			await fp.keyboard.press('Escape');
			await fp.locator(`#uxs-popup-${p1}[hidden]`).waitFor({ timeout: 3000, state: 'attached' });
			c.ok(true, 'Esc closes the popup');
			c.ok(!(await fp.evaluate(() => document.documentElement.classList.contains('uxs-popup-lock'))), 'scroll lock released');
			await sleep(500);
			c.ok(await waitStat(p1, 'shown', 1), 'stat shown recorded');
			c.ok(await waitStat(p1, 'closed', 1), 'stat closed recorded');
			await fp.reload();
			await sleep(1500);
			c.ok((await open(fp, p1).count()) === 0, 'after close not shown again in the session (frequency session)');
			// new tab in the same context = new session storage
			const tab = await fp.context().newPage();
			await tab.goto(home);
			await open(tab, p1).waitFor({ timeout: 6000 });
			c.ok(true, 'new browser tab (new session) shows it again');
			// CTA with # closes, counts clicked
			await tab.locator(`#uxs-popup-${p1} [data-uxs-cta]`).click();
			await tab.locator(`#uxs-popup-${p1}[hidden]`).waitFor({ state: 'attached', timeout: 3000 });
			await sleep(500);
			c.ok(await waitStat(p1, 'clicked', 1), 'CTA click recorded as clicked');
			// backdrop click closes (new context -> new storage)
			const { page: fp2 } = await anon();
			await fp2.goto(home);
			await open(fp2, p1).waitFor({ timeout: 6000 });
			await fp2.locator(`#uxs-popup-${p1} .uxs-popup__close`).click();
			await fp2.locator(`#uxs-popup-${p1}[hidden]`).waitFor({ state: 'attached', timeout: 3000 });
			c.ok(true, 'close button closes the popup');
			// stop_after_click default: after CTA click a "session" popup stays closed also in a new tab? (tab storage is per tab)
			// mobile viewport
			const { page: mob } = await anon({ width: 375, height: 700 });
			await mob.goto(home);
			await open(mob, p1).waitFor({ timeout: 6000 });
			const box = await mob.locator(`#uxs-popup-${p1} .uxs-popup__dialog`).boundingBox();
			c.ok(box && box.x >= 0 && box.x + box.width <= 376, `mobile: dialog fits viewport (x=${box?.x}, w=${box?.width})`);
			await mob.screenshot({ path: `${SHOTS}${T('fe-modal-mobile')}.png` });
		});

		// ---------------------------------------------------------------- P2 delay + corner, always
		await step('P2 delay corner', async () => {
			// P1 off, P2 on
			await page.locator('tr:has(button:text-is("E2E modal")) button.uxs-switch').click();
			await sleep(600);
			await fillEditor('E2E corner', { headline: 'Corner', body: '<p>c</p>', trigger_type: 'delay', delay_seconds: 2, frequency: 'always', position: 'bottom-right' });
			const id = popupId('E2E corner');
			const { page: fp } = await anon();
			await fp.goto(home);
			const early = await fp.evaluate((sel) => ({ age: performance.now(), shown: !!document.querySelector(sel) }), `#uxs-popup-${id}.is-open`);
			// on a loaded machine the page may already be older than the delay when this runs: then only the age is checked
			c.ok(!early.shown || early.age >= 1900, `delay 2 s: not visible while the page is young (age ${Math.round(early.age)} ms, shown ${early.shown})`);
			await open(fp, id).waitFor({ timeout: 5000 });
			c.ok(true, 'delay 2 s: visible afterwards');
			c.ok(!(await fp.evaluate(() => document.documentElement.classList.contains('uxs-popup-lock'))), 'corner popup does not lock the page');
			c.ok((await fp.locator(`#uxs-popup-${id} .uxs-popup__dialog`).getAttribute('aria-modal')) === 'false', 'corner popup is not aria-modal');
			await fp.screenshot({ path: `${SHOTS}${T('fe-corner')}.png` });
			await fp.reload();
			await open(fp, id).waitFor({ timeout: 6000 });
			c.ok(true, 'frequency always: shown again after reload');
			// P1 (draft) must not appear
			c.ok((await fp.locator(`#uxs-popup-${p1}`).count()) === 0, 'unpublished popup is not rendered');
		});

		// ---------------------------------------------------------------- P3 scroll
		await step('P3 scroll', async () => {
			await page.locator('tr:has(button:text-is("E2E corner")) button.uxs-switch').click();
			await sleep(500);
			await fillEditor('E2E scroll', { headline: 'Scrolled', trigger_type: 'scroll', scroll_percent: 30, frequency: 'always' });
			const id = popupId('E2E scroll');
			const { page: fp } = await anon({ width: 1200, height: 260 });
			await fp.goto(home);
			const range = await fp.evaluate(() => document.documentElement.scrollHeight - window.innerHeight);
			if (range < 50) {
				c.ok(false, `scroll trigger: home page too short to scroll (${range}px) - cannot verify`);
				return;
			}
			await sleep(800);
			c.ok((await open(fp, id).count()) === 0, 'scroll: not shown before scrolling');
			await fp.evaluate((r) => window.scrollTo(0, Math.ceil(r * 0.1)), range);
			await sleep(500);
			c.ok((await open(fp, id).count()) === 0, 'scroll: not shown at 10 %');
			await fp.evaluate((r) => window.scrollTo(0, Math.ceil(r * 0.5)), range);
			await open(fp, id).waitFor({ timeout: 4000 });
			c.ok(true, 'scroll: shown past 30 %');
		});

		// ---------------------------------------------------------------- P4 exit intent
		await step('P4 exit intent', async () => {
			await page.locator('tr:has(button:text-is("E2E scroll")) button.uxs-switch').click();
			await sleep(500);
			await fillEditor('E2E exit', { headline: 'Leaving?', trigger_type: 'exit', exit_delay: 6, frequency: 'always' });
			const id = popupId('E2E exit');
			const { page: fp } = await anon();
			await fp.goto(home);
			const t0 = await fp.evaluate(() => performance.now());
			await fp.evaluate(() => document.documentElement.dispatchEvent(new MouseEvent('mouseleave', { clientY: -1 })));
			await sleep(300);
			if (t0 < 5000) c.ok((await open(fp, id).count()) === 0, `exit intent: not armed before the exit delay (6 s), page age ${Math.round(t0)} ms`);
			await fp.waitForFunction(() => performance.now() > 9500, null, { timeout: 20000 });
			await fp.evaluate(() => document.documentElement.dispatchEvent(new MouseEvent('mouseleave', { clientY: 300 })));
			await sleep(300);
			c.ok((await open(fp, id).count()) === 0, 'exit intent: leaving through the side does not trigger');
			// a loaded machine may delay the script: retry the gesture until the popup answers
			for (let i = 0; i < 12 && (await open(fp, id).count()) === 0; i++) {
				await fp.evaluate(() => document.documentElement.dispatchEvent(new MouseEvent('mouseleave', { clientY: -1 })));
				await sleep(1000);
			}
			await open(fp, id).waitFor({ timeout: 10000 });
			c.ok(true, 'exit intent: leaving through the top edge triggers');
		});

		// ---------------------------------------------------------------- targeting / schedule / frequency matrix
		await step('targeting matrix', async () => {
			await page.locator('tr:has(button:text-is("E2E exit")) button.uxs-switch').click();
			await sleep(500);
			await fillEditor('E2E target', { headline: 'Target', trigger_type: 'immediate', frequency: 'always' });
			const id = popupId('E2E target');
			const shows = async (url, viewport, opts = {}) => {
				const { page: fp } = await anon(viewport);
				await fp.goto(url);
				await sleep(opts.wait ?? 1800);
				const n = await open(fp, id).count();
				await fp.context().close();
				return n > 0;
			};
			// include by URL fragment
			await editPopup('E2E target', { menu_mode: 'include', url_patterns: 'uxs_t=yes' });
			c.ok((await shows(`${home}?uxs_t=yes`)) === true, 'include fragment: shown on matching URL');
			c.ok((await shows(home)) === false, 'include fragment: hidden elsewhere');
			await editPopup('E2E target', { menu_mode: 'exclude' });
			c.ok((await shows(`${home}?uxs_t=yes`)) === false, 'exclude fragment: hidden on matching URL');
			c.ok((await shows(home)) === true, 'exclude fragment: shown elsewhere');
			// include by menu item (Home)
			await page.locator('tr:has(button:text-is("E2E target")) button[aria-label=Edit]').click();
			await page.locator('.uxs-subtabs').waitFor();
			await setField(page, SECTION_PM, 'menu_mode', 'include', 'select');
			await setField(page, SECTION_PM, 'url_patterns', '');
			const items = await page.locator('.uxs-form__row:has(label[for="uxs-menu_items"]) .uxs-checklist__item').allInnerTexts();
			c.ok(items.length > 0, `menu items offered (${items.map((s) => s.trim()).join(', ')})`);
			await page.locator('.uxs-form__row:has(label[for="uxs-menu_items"]) .uxs-checklist__item', { hasText: 'Home' }).first().locator('input').check();
			await page.getByRole('button', { name: 'Save', exact: true }).click();
			await toast(page, 'success').first().waitFor();
			await page.getByRole('button', { name: 'Add popup' }).waitFor();
			c.ok((await shows(home)) === true, 'include menu item Home: shown on Home');
			c.ok((await shows(`${site.url}/index.php?option=com_users&view=login`)) === false, 'include menu item Home: hidden on login page');
			// devices
			await editPopup('E2E target', { menu_mode: 'all', devices: ['Phone'] });
			c.ok((await shows(home, { width: 1400, height: 900 })) === false, 'devices phone only: hidden on desktop');
			c.ok((await shows(home, { width: 800, height: 900 })) === false, 'devices phone only: hidden on tablet width');
			c.ok((await shows(home, { width: 400, height: 800 })) === true, 'devices phone only: shown on phone width');
			// audience
			await page.locator('tr:has(button:text-is("E2E target")) button[aria-label=Edit]').click();
			await page.locator('.uxs-subtabs').waitFor();
			await sleep(300);
			await page.locator('.uxs-form__row:has(label[for="uxs-devices"]) input:checked').uncheck().catch(() => {});
			await page.locator('.uxs-subtabs [role=tab]').nth(3).click();
			for (const cb of await page.locator('.uxs-form__row:has(label[for="uxs-devices"]) input:checked').all()) await cb.uncheck();
			await setField(page, SECTION_PM, 'audience', 'members', 'select');
			await page.getByRole('button', { name: 'Save', exact: true }).click();
			await toast(page, 'success').first().waitFor();
			await page.getByRole('button', { name: 'Add popup' }).waitFor();
			c.ok((await shows(home)) === false, 'audience logged-in only: hidden for anonymous');
			await editPopup('E2E target', { audience: 'guests' });
			c.ok((await shows(home)) === true, 'audience guests only: shown for anonymous');
			// schedule
			await editPopup('E2E target', { audience: 'all', starts_at: '2037-01-01T10:00' });
			const badge = (text) => page.locator('tr:has(button:text-is("E2E target")) .uxs-badge', { hasText: text }).waitFor({ timeout: 10000 }).then(() => true, () => false);
			c.ok(await badge('Scheduled'), 'future start -> badge Scheduled');
			c.ok((await shows(home)) === false, 'scheduled in the future: not shown');
			await editPopup('E2E target', { starts_at: '2020-01-01T10:00', ends_at: '2020-06-01T10:00' });
			c.ok(await badge('Expired'), 'past end -> badge Expired');
			c.ok((await shows(home)) === false, 'expired: not shown');
			await editPopup('E2E target', { starts_at: '2020-01-01T10:00', ends_at: '2037-06-01T10:00' });
			c.ok((await shows(home)) === true, 'inside schedule window: shown');
			await shot(page, 'pm-list-states-light');
			// frequency once / days: stays closed after dismiss
			await editPopup('E2E target', { frequency: 'once' });
			const { page: fp } = await anon();
			await fp.goto(home);
			await open(fp, id).waitFor({ timeout: 6000 });
			await fp.keyboard.press('Escape');
			await sleep(600);
			await fp.reload();
			await sleep(1500);
			c.ok((await open(fp, id).count()) === 0, 'frequency once: not shown again after reload');
			await fp.evaluate(() => localStorage.clear());
			await fp.reload();
			await open(fp, id).waitFor({ timeout: 6000 });
			c.ok(true, 'frequency once: shown again after clearing the storage');
			await editPopup('E2E target', { frequency: 'days', frequency_days: 3 });
			const { page: fd } = await anon();
			await fd.goto(home);
			await open(fd, id).waitFor({ timeout: 6000 });
			await fd.keyboard.press('Escape');
			await sleep(600);
			await fd.reload();
			await sleep(1500);
			c.ok((await open(fd, id).count()) === 0, 'frequency every 3 days: not shown again right away');
			// move the stored timestamp 4 days back -> due again
			await fd.evaluate(() => {
				for (const k of Object.keys(localStorage)) if (/^uxs_pm_/.test(k)) localStorage.setItem(k, String(Date.now() - 4 * 86400000));
			});
			await fd.reload();
			await open(fd, id).waitFor({ timeout: 6000 }).then(
				() => c.ok(true, 'frequency every 3 days: shown again after 4 days'),
				() => c.ok(false, `frequency every 3 days: not shown after 4 days (storage keys ${'?'})`)
			);
			// cookie storage mode (module setting) via the Settings tab
		});

		// ---------------------------------------------------------------- priority / max per page, preview, duplicate, stats, reset, delete
		await step('priority + max per page', async () => {
			await editPopup('E2E target', { frequency: 'always', priority: 5 });
			await fillEditor('E2E second', { headline: 'Second', trigger_type: 'immediate', frequency: 'always', priority: 50 });
			const idT = popupId('E2E target');
			const idS = popupId('E2E second');
			const { page: fp } = await anon();
			await fp.goto(home);
			await sleep(1800);
			c.ok((await fp.locator(`#uxs-popup-${idS}`).count()) === 1 && (await fp.locator(`#uxs-popup-${idT}`).count()) === 0, 'max 1 per page: higher priority wins');
			await fp.screenshot({ path: `${SHOTS}${T('fe-priority')}.png` });
			// settings tab: max_per_page 2
			await page.getByRole('tab', { name: 'Settings' }).click();
			await page.locator('#uxs-max_per_page').waitFor();
			await page.locator('#uxs-max_per_page').fill('2');
			await page.getByRole('button', { name: 'Save', exact: true }).click();
			await toast(page, 'success').first().waitFor();
			const { page: f2 } = await anon();
			await f2.goto(home);
			await sleep(1800);
			c.ok((await f2.locator('[data-uxs-popup]').count()) === 2, 'max 2 per page: both rendered');
			c.ok((await f2.locator('.uxs-popup.is-open').count()) === 1, 'only one popup open at a time (queue)');
			await f2.keyboard.press('Escape');
			await f2.locator('.uxs-popup.is-open').first().waitFor({ timeout: 3000 });
			c.ok(true, 'closing the first opens the queued second one');
			await shot(page, 'pm-settings-light');
			// invalid settings
			await page.locator('#uxs-max_per_page').fill('99');
			await page.getByRole('button', { name: 'Save', exact: true }).click();
			await sleep(1000);
			const stored = sql(db, "SELECT data FROM jos_uxstudio_settings WHERE module='popup-manager'");
			c.ok(!/"max_per_page":\s*99/.test(stored), `max_per_page 99 clamped or rejected (${stored.slice(0, 120)})`);
			// tracking off -> no new stats
			await page.locator('#uxs-max_per_page').fill('1');
			const trk = page.locator('.uxs-form__row:has(label[for="uxs-tracking"]) button.uxs-switch');
			if ((await trk.getAttribute('aria-pressed')) === 'true') await trk.click();
			await page.getByRole('button', { name: 'Save', exact: true }).click();
			await toast(page, 'success').last().waitFor();
			await sleep(3000); // let beacons of the previous pages (slow machine) land before sampling
			const before = statCount(idS, 'shown');
			const { page: f3 } = await anon();
			await f3.goto(home);
			await open(f3, idS).waitFor({ timeout: 6000 });
			await sleep(2500);
			c.ok(statCount(idS, 'shown') === before, 'tracking off: shown event not recorded');
			await trk.click();
			await page.getByRole('button', { name: 'Save', exact: true }).click();
			await sleep(800);
			await page.getByRole('tab', { name: 'Popups' }).click();
		});

		await step('list actions', async () => {
			const idS = popupId('E2E second');
			await reloadEn();
			await page.locator('tr:has(button:text-is("E2E second"))').waitFor();
			const row = page.locator('tr:has(button:text-is("E2E second"))');
			const cell = (await row.innerText());
			c.ok(/\d+ \/ \d+/.test(cell), `list stats column: ${cell.replace(/\s+/g, ' ').slice(0, 100)}`);
			// preview of a draft popup
			await page.locator('tr:has(button:text-is("E2E modal")) button[aria-label="Preview on the site"]').click({ trial: true });
			const [pv] = await Promise.all([ctx.waitForEvent('page'), page.locator('tr:has(button:text-is("E2E modal")) button[aria-label="Preview on the site"]').click()]);
			await pv.waitForLoadState('load');
			await pv.locator(`#uxs-popup-${p1}.is-open`).waitFor({ timeout: 20000 });
			c.ok(true, 'preview shows an unpublished popup immediately');
			const shownBefore = statCount(p1, 'shown');
			await pv.close();
			await sleep(400);
			c.ok(statCount(p1, 'shown') === shownBefore, 'preview does not count statistics');
			// tampered preview link
			const { page: tp } = await anon();
			await tp.goto(`${home}?uxs_preview=popup&uxs_id=${p1}&uxs_exp=9999999999&uxs_sig=deadbeef`);
			await sleep(1500);
			c.ok((await tp.locator(`#uxs-popup-${p1}`).count()) === 0, 'forged preview link shows nothing');
			// duplicate
			const n0 = await page.locator('#uxs-root tbody tr').count();
			await row.locator('button[aria-label=Duplicate]').click();
			await page.waitForFunction((n) => document.querySelectorAll('#uxs-root tbody tr').length > n, n0);
			c.ok(true, 'duplicate adds a row');
			const dupTitle = sql(db, "SELECT title FROM jos_uxstudio_popups WHERE title LIKE 'E2E second%' AND title<>'E2E second' LIMIT 1");
			c.ok(dupTitle.startsWith('E2E second'), `duplicate title "${dupTitle}" keeps E2E prefix`);
			// reset stats with cancel then confirm
			await row.locator('button[aria-label="Reset statistics"]').click();
			await page.locator('.uxs-modal').waitFor();
			await shot(page, 'pm-confirm-light');
			await page.locator('.uxs-modal__actions .uxs-btn', { hasText: 'Cancel' }).click();
			c.ok(statCount(idS, 'shown') > 0, 'reset cancelled: stats kept');
			await row.locator('button[aria-label="Reset statistics"]').click();
			await page.locator('.uxs-modal__actions .uxs-btn--danger').click();
			await sleep(800);
			await sleep(1500);
			for (let i = 0; i < 20 && statCount(idS, 'shown') !== 0; i++) await sleep(500);
			c.ok(statCount(idS, 'shown') === 0, 'reset confirmed: stats cleared');
			c.ok(await page.locator('tr:has(button:text-is("E2E second"))', { hasText: '0 / 0' }).waitFor({ timeout: 10000 }).then(() => true, () => false), 'list shows 0 / 0 after reset');
			// sort by name
			await page.locator('#uxs-root thead button', { hasText: 'Name' }).click();
			await page.locator('#uxs-root thead th[aria-sort]').first().waitFor();
			c.ok(true, 'name column sortable');
			// dark mode
			await page.evaluate(() => localStorage.setItem('uxstudio-theme', 'dark'));
			await reloadEn();
			await page.locator('#uxs-root tbody tr').first().waitFor();
			await shot(page, 'pm-list-dark');
			c.ok((await page.getAttribute('#uxs-root', 'data-theme')) === 'dark', 'dark theme applied (data-theme)');
			await page.locator('tr:has(button:text-is("E2E second")) button[aria-label=Edit]').click();
			await page.locator('.uxs-subtabs').waitFor();
			await shot(page, 'pm-editor-dark');
			await page.locator('.uxs-subtabs [role=tab]').nth(1).click();
			await shot(page, 'pm-editor-appearance-dark');
			await page.getByRole('button', { name: 'Back to the list' }).click();
			await page.evaluate(() => localStorage.setItem('uxstudio-theme', 'light'));
			await reloadEn();
			// delete everything created
			for (const name of ['E2E modal', 'E2E corner', 'E2E scroll', 'E2E exit', 'E2E target', 'E2E second', 'E2E second (copy)']) {
				void name;
			}
			await page.locator('#uxs-root tbody tr').first().waitFor();
			let guard = 0;
			while ((await page.locator('#uxs-root tbody tr:has(button:has-text("E2E"))').count()) > 0 && guard++ < 12) {
				const r = page.locator('#uxs-root tbody tr:has(button:has-text("E2E"))').first();
				await r.locator('button[aria-label=Delete]').click();
				const before = await page.locator('#uxs-root tbody tr:has(button:has-text("E2E"))').count();
				await page.locator('.uxs-modal__actions .uxs-btn--danger').click();
				await page.waitForFunction((n) => [...document.querySelectorAll('#uxs-root tbody tr')].filter((tr) => /E2E/.test(tr.innerText)).length < n || !document.querySelector('#uxs-root tbody tr'), before, { timeout: 20000 }).catch(() => {});
			}
			c.ok(sql(db, "SELECT COUNT(*) FROM jos_uxstudio_popups WHERE title LIKE 'E2E%'") === '0', `all E2E popups deleted through the UI (left: ${sql(db, "SELECT GROUP_CONCAT(title) FROM jos_uxstudio_popups WHERE title LIKE 'E2E%'")})`);
			c.ok(sql(db, 'SELECT COUNT(*) FROM jos_uxstudio_popup_stats s LEFT JOIN jos_uxstudio_popups p ON p.id=s.popup_id WHERE p.id IS NULL AND s.popup_id>' + (p1 - 1)) === '0', 'stats of deleted popups removed');
			await page.getByText('No popups yet.').waitFor();
		});

		// ================================================================ exit-popup
		sql(db, "DELETE FROM jos_uxstudio_popup_stats WHERE popup_id IN (SELECT id FROM jos_uxstudio_popups WHERE title LIKE 'E2E%'); DELETE FROM jos_uxstudio_popups WHERE title LIKE 'E2E%'");
		await openStudioEn(page, site, EP);
		await step('exit-popup settings via UI', async () => {
			await page.locator(".uxs-subtabs").waitFor({ timeout: 20000 });
			const tabs = (await page.locator(".uxs-tabs [role=tab]").allInnerTexts()).map((s) => s.trim());
			c.ok(tabs.join('|') === 'Settings|Subscribers', `exit tabs: ${tabs.join('|')}`);
			const sub = (await page.locator('.uxs-subtabs [role=tab]').allInnerTexts()).map((s) => s.trim());
			c.ok(sub.length === 5, `exit settings sections: ${sub.join('|')}`);
			for (let i = 0; i < 5; i++) {
				await page.locator('.uxs-subtabs [role=tab]').nth(i).click();
				c.ok((await page.locator('.uxs-form__row').count()) > 0, `exit section ${sub[i]} renders`);
			}
			await setField(page, SECTION_EP, 'active', true, 'toggle');
			await setField(page, SECTION_EP, 'headline', 'E2E exit headline');
			await setField(page, SECTION_EP, 'body', '<p>Get the E2E news</p>', 'rich');
			await setField(page, SECTION_EP, 'time_on_page', 2);
			await setField(page, SECTION_EP, 'delay_seconds', 0);
			await setField(page, SECTION_EP, 'frequency_mode', 'session', 'select');
			await setField(page, SECTION_EP, 'enable_email', true, 'toggle');
			await setField(page, SECTION_EP, 'consent_text', 'I agree with E2E');
			await shot(page, 'ep-settings-email-light');
			// nonsense number: 99999 s
			await setField(page, SECTION_EP, 'time_on_page', 999999);
			await page.getByRole('button', { name: 'Save', exact: true }).click();
			await sleep(1000);
			const saved = JSON.parse(sql(db, "SELECT data FROM jos_uxstudio_settings WHERE module='exit-popup'"));
			c.ok(saved.time_on_page === undefined || saved.time_on_page <= 3600, `time_on_page 999999 clamped/rejected (${saved.time_on_page})`);
			await setField(page, SECTION_EP, 'time_on_page', 2);
			await page.getByRole('button', { name: 'Save', exact: true }).click();
			await toast(page, 'success').last().waitFor();
			const s2 = JSON.parse(sql(db, "SELECT data FROM jos_uxstudio_settings WHERE module='exit-popup'"));
			c.ok(s2.active === true && s2.time_on_page === 2 && s2.consent_text === 'I agree with E2E', 'exit settings saved');
		});

		await step('exit-popup validation of active schedule', async () => {
			// schedule in the past: nothing on front end, subscribe 404
			await setField(page, SECTION_EP, 'ends_at', '2020-01-01T10:00', 'datetime');
			await page.getByRole('button', { name: 'Save', exact: true }).click();
			await toast(page, 'success').last().waitFor();
			const { page: fp } = await anon();
			await fp.goto(home);
			await sleep(3500);
			c.ok((await fp.locator('#uxs-exit-popup').count()) === 0, 'exit popup with an expired schedule is not rendered');
			await page.locator('.uxs-subtabs [role=tab]').nth(3).click();
			await page.locator('#uxs-ends_at').fill('');
			await page.getByRole('button', { name: 'Save', exact: true }).click();
			await toast(page, 'success').last().waitFor();
		});

		let emailsBefore = 0;
		await step('exit-popup frontend + subscribe', async () => {
			resetRate();
			const { ctx: actx, page: fp } = await anon();
			await fp.goto(home);
			await fp.locator('#uxs-exit-popup').waitFor({ state: 'attached' });
			c.ok(await fp.locator('#uxs-exit-popup').isHidden(), 'exit popup initially hidden');
			await sleep(1000);
			c.ok(await fp.locator('#uxs-exit-popup').isHidden(), 'time on page 2 s: not open after 1 s');
			await fp.locator('#uxs-exit-popup.is-open').waitFor({ timeout: 6000 });
			c.ok(true, 'opens after 2 s on the page');
			const dlg = fp.locator('#uxs-exit-popup .uxs-exit__dialog');
			c.ok(await fp.evaluate(() => document.activeElement.id === 'uxs-exit-email'), 'focus goes to the e-mail field');
			for (let i = 0; i < 8; i++) await fp.keyboard.press('Tab');
			c.ok(await fp.evaluate(() => document.activeElement.closest('.uxs-exit__dialog') !== null), 'focus trapped (8x Tab)');
			await fp.screenshot({ path: `${SHOTS}${T('fe-exit-desktop')}.png` });
			// client validation (no server request)
			await fp.fill('#uxs-exit-popup #uxs-exit-email', 'not-an-email');
			await fp.click('.uxs-exit__submit');
			c.ok((await fp.locator('.uxs-exit__message.is-error').innerText()).length > 3, 'invalid e-mail -> error message');
			await fp.fill('#uxs-exit-popup #uxs-exit-email', 'e2e-visitor@example.test');
			await fp.click('.uxs-exit__submit');
			const msg = (await fp.locator('.uxs-exit__message').innerText()).trim();
			c.ok(msg.length > 3 && (await fp.locator('.uxs-exit__message.is-error').count()) === 1, `missing consent -> error "${msg}"`);
			await fp.check('#uxs-exit-popup input[name=consent]');
			await fp.click('.uxs-exit__submit');
			await fp.locator('.uxs-exit__message.is-success').waitFor({ timeout: 20000 });
			c.ok(true, 'valid submit -> success message');
			c.ok(sql(db, "SELECT COUNT(*) FROM jos_uxstudio_exit_popup_emails WHERE email='e2e-visitor@example.test' AND consent=1") === '1', 'subscriber stored in DB with consent');
			const ipHash = sql(db, "SELECT ip_hash FROM jos_uxstudio_exit_popup_emails WHERE email='e2e-visitor@example.test'");
			c.ok(/^[0-9a-f]{64}$/.test(ipHash), 'IP stored only as hash');
			// popup auto closes, then does not return in new tab (done flag)
			await fp.locator('#uxs-exit-popup[hidden]').waitFor({ state: 'attached', timeout: 6000 });
			c.ok(true, 'popup closes itself after success');
			const tab = await actx.newPage();
			await tab.goto(home);
			await sleep(3500);
			c.ok(await tab.locator('#uxs-exit-popup').isHidden(), 'subscribed visitor does not see it again (new tab)');
			// idempotent duplicate, honeypot, with a fresh context
			const { page: f2 } = await anon();
			await f2.goto(home);
			await f2.locator('#uxs-exit-popup.is-open').waitFor({ timeout: 6000 });
			await f2.fill('#uxs-exit-popup #uxs-exit-email', 'E2E-Visitor@Example.test');
			await f2.check('#uxs-exit-popup input[name=consent]');
			await f2.click('.uxs-exit__submit');
			await f2.locator('.uxs-exit__message.is-success').waitFor({ timeout: 20000 });
			c.ok(sql(db, "SELECT COUNT(*) FROM jos_uxstudio_exit_popup_emails WHERE email LIKE 'e2e-visitor@%'") === '1', 'same address (other case) answers success but stores one row');
			const { page: f3 } = await anon();
			await f3.goto(home);
			await f3.locator('#uxs-exit-popup.is-open').waitFor({ timeout: 6000 });
			await f3.evaluate(() => (document.querySelector('#uxs-exit-popup input[name=website]').value = 'http://spam.test'));
			await f3.fill('#uxs-exit-popup #uxs-exit-email', 'e2e-bot@example.test');
			await f3.check('#uxs-exit-popup input[name=consent]');
			await f3.click('.uxs-exit__submit');
			await f3.locator('.uxs-exit__message.is-success').waitFor({ timeout: 20000 });
			c.ok(sql(db, "SELECT COUNT(*) FROM jos_uxstudio_exit_popup_emails WHERE email='e2e-bot@example.test'") === '0', 'honeypot filled: success shown but nothing stored');
			// server side validation + rate limit via direct requests
			const url = `${site.url}/index.php?option=com_uxstudio&task=public.dispatch&format=json&route=exit-popup/subscribe`;
			const post = (data) => f3.request.post(url, { data, headers: { Origin: site.url } });
			let r = await post({ email: 'nope' });
			c.ok(r.status() === 422, `server: invalid e-mail -> 422 (${r.status()})`);
			r = await post({ email: 'e2e-noconsent@example.test', consent: false });
			c.ok(r.status() === 422, `server: missing consent -> 422 (${r.status()})`);
			resetRate();
			r = await post({ email: 'e2e-jan.novák@example.test', consent: true });
			c.ok(r.status() === 200, `server: e-mail with diacritics in the local part is accepted (${r.status()})`);
			c.ok(sql(db, "SELECT COUNT(*) FROM jos_uxstudio_exit_popup_emails WHERE email LIKE 'e2e-jan.nov%'") === '1', 'diacritics e-mail stored');
			r = await post({ email: 'e2e-idn@příklad.cz', consent: true });
			c.ok(r.status() === 200, `server: IDN domain is accepted (${r.status()})`);
			c.ok(sql(db, "SELECT COUNT(*) FROM jos_uxstudio_exit_popup_emails WHERE email LIKE 'e2e-idn@xn--%'") === '1', 'IDN e-mail stored in punycode');
			for (let i = 0; i < 6 && r.status() !== 429; i++) r = await post({ email: `e2e-late${i}@example.test`, consent: true });
			c.ok(r.status() === 429, `server: repeated attempts within 10 min end in 429 (${r.status()})`);
			c.ok(!!r.headers()['retry-after'], '429 carries Retry-After');
			r = await f3.request.post(url, { data: { email: 'e2e-x@example.test' }, headers: { Origin: 'http://evil.example' } });
			c.ok([403, 429].includes(r.status()), `foreign Origin refused (${r.status()})`);
			emailsBefore = 1;
		});

		await step('exit-popup preview + mobile', async () => {
			await reloadEn();
			await page.getByRole('button', { name: 'Preview on the site' }).click({ trial: true });
			const [pv] = await Promise.all([ctx.waitForEvent('page'), page.getByRole('button', { name: 'Preview on the site' }).click()]);
			await pv.waitForLoadState('load');
			await pv.locator('#uxs-exit-popup.is-open').waitFor({ timeout: 20000 });
			c.ok(true, 'exit popup preview opens immediately');
			await pv.fill('#uxs-exit-popup #uxs-exit-email', 'e2e-preview@example.test');
			await pv.check('#uxs-exit-popup input[name=consent]');
			await pv.click('.uxs-exit__submit');
			await pv.locator('.uxs-exit__message.is-success').waitFor();
			c.ok(sql(db, "SELECT COUNT(*) FROM jos_uxstudio_exit_popup_emails WHERE email='e2e-preview@example.test'") === '0', 'preview form stores nothing');
			await pv.close();
			resetRate();
			const { page: mob } = await anon({ width: 375, height: 700 });
			await mob.goto(home);
			await mob.locator('#uxs-exit-popup.is-open').waitFor({ timeout: 20000 });
			const box = await mob.locator('#uxs-exit-popup .uxs-exit__dialog').boundingBox();
			c.ok(box && box.x >= 0 && box.x + box.width <= 376, `mobile: exit dialog fits (x=${box?.x}, w=${box?.width})`);
			await mob.screenshot({ path: `${SHOTS}${T('fe-exit-mobile')}.png` });
			await mob.keyboard.press('Escape');
			await mob.locator('#uxs-exit-popup[hidden]').waitFor({ state: 'attached', timeout: 3000 });
			c.ok(true, 'Esc closes the exit popup');
		});

		await step('exit + popup-manager do not overlap', async () => {
			const cfg = { headline: 'Overlap', body: '', image: '', image_alt: '', cta_text: '', cta_url: '', cta_new_tab: false, position: 'center', animation: 'none', width: 520, bg_color: '#ffffff', text_color: '#1f2937', accent_color: '#2563eb', cta_text_color: '#ffffff', overlay_opacity: 60, trigger_type: 'immediate', delay_seconds: 0, scroll_percent: 50, exit_delay: 3, frequency: 'always', frequency_days: 7, stop_after_click: true, menu_mode: 'all', menu_items: [], url_patterns: '', devices: [], audience: 'all' };
			sql(db, `INSERT INTO jos_uxstudio_popups (title,status,priority,config,created,modified) VALUES ('E2E overlap', 'publish', 0, ${hex(JSON.stringify(cfg))}, NOW(), NOW())`);
			const idO = popupId('E2E overlap');
			c.ok(idO > 0 && !sql(db, `SELECT id FROM jos_e2e_popups_stash WHERE id=${idO}`), `overlap popup got a fresh id (${idO}), not one of the stashed popups`);
			const { page: fp } = await anon();
			await fp.goto(home);
			await open(fp, idO).waitFor({ timeout: 6000 });
			await sleep(3500);
			c.ok((await fp.locator('#uxs-exit-popup.is-open').count()) === 0, 'exit popup waits while the popup-manager dialog is open');
			await fp.keyboard.press('Escape');
			await fp.locator('#uxs-exit-popup.is-open').waitFor({ timeout: 5000 });
			c.ok(true, 'exit popup opens after the other one closed');
			sql(db, `DELETE FROM jos_uxstudio_popup_stats WHERE popup_id=${idO} AND ${idO} NOT IN (SELECT id FROM jos_e2e_popups_stash); DELETE FROM jos_uxstudio_popups WHERE id=${idO} AND title='E2E overlap'`);
		});

		await step('subscribers tab', async () => {
			// extra rows: 54 for paging, one searchable, one formula-like
			const rows = [];
			for (let i = 1; i <= 54; i++) rows.push(`(NOW() - INTERVAL ${i} MINUTE, 'e2e-bulk${i}@example.test', 'http://x.test/${i}', '', ${i % 2})`);
			rows.push("(NOW(), '=1+1@example.test', 'http://x.test/formula', '', 0)");
			sql(db, `INSERT INTO jos_uxstudio_exit_popup_emails (created,email,page_url,ip_hash,consent) VALUES ${rows.join(',')}`);
			await openStudioEn(page, site, EP);
			await page.getByRole('tab', { name: 'Subscribers' }).click();
			await page.locator('#uxs-root tbody tr').first().waitFor();
			const total = Number(sql(db, 'SELECT COUNT(*) FROM jos_uxstudio_exit_popup_emails'));
			c.ok((await page.getByText(`${total} addresses`).count()) === 1, `address count shown (${total} addresses)`);
			c.ok((await page.locator('#uxs-root tbody tr').count()) === 50, 'first page has 50 rows');
			await shot(page, 'ep-subscribers-light');
			// paging
			const nextBtn = page.locator('.uxs-pager button, nav[aria-label] button').filter({ hasText: /Next|›|»/ }).first();
			if (await nextBtn.count()) {
				await nextBtn.click();
				await page.waitForFunction((n) => document.querySelectorAll('#uxs-root tbody tr').length === n, total - 50, { timeout: 20000 }).catch(() => {});
				c.ok((await page.locator('#uxs-root tbody tr').count()) === total - 50, `page 2 has ${total - 50} rows`);
				await page.locator('.uxs-pager button, nav[aria-label] button').filter({ hasText: /Prev|‹|«/ }).first().click();
				await sleep(500);
			} else c.ok(false, 'pager buttons not found');
			// search
			await page.getByRole('searchbox').fill('bulk7@');
			await page.getByRole('button', { name: 'Search', exact: true }).click();
			await page.waitForFunction(() => document.querySelectorAll('#uxs-root tbody tr').length === 1, null, { timeout: 20000 }).catch(() => {});
			c.ok((await page.locator('#uxs-root tbody tr').count()) === 1 && (await page.locator('#uxs-root tbody').innerText()).includes('e2e-bulk7@'), 'search finds exactly one address');
			await page.getByRole('searchbox').fill('zzz-nothing-zzz');
			await page.getByRole('button', { name: 'Search', exact: true }).click();
			await sleep(800);
			await page.getByText('No e-mail addresses captured yet.').waitFor({ timeout: 10000 }).catch(() => {});
			c.ok((await page.getByText('No e-mail addresses captured yet.').count()) === 1, 'search without result -> empty state text');
			await page.getByRole('searchbox').fill('');
			await page.getByRole('button', { name: 'Search', exact: true }).click();
			await sleep(800);
			// error state
			globalThis.__expectFail = true;
			await page.route(SUBS_ROUTE, (r) => r.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ ok: false, error: { code: 'boom', message: 'Simulated subscribers failure' } }) }));
			await page.getByRole('searchbox').fill('x');
			await page.getByRole('button', { name: 'Search', exact: true }).click();
			await page.locator('[role=alert]').first().waitFor({ timeout: 6000 });
			c.ok((await page.locator('[role=alert]').first().innerText()).includes('Simulated subscribers failure'), 'subscribers API error shown');
			await page.unroute(SUBS_ROUTE);
			globalThis.__expectFail = false;
			await page.getByRole('searchbox').fill('');
			await page.getByRole('button', { name: 'Search', exact: true }).click();
			await page.locator('#uxs-root tbody tr').first().waitFor();
			// CSV export
			const [dl] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Export CSV' }).click()]);
			const csvPath = `${SHOTS}${T('export')}.csv`;
			await dl.saveAs(csvPath);
			const csv = fs.readFileSync(csvPath, 'utf8');
			c.ok(csv.startsWith('﻿email,page_url,created_utc,consent'), 'CSV starts with a UTF-8 BOM and the header');
			c.ok(csv.includes("\"'=1+1@example.test\""), 'CSV neutralises formula-like cell');
			c.ok(csv.split('\r\n').filter(Boolean).length === total + 1, `CSV has ${total} data rows`);
			fs.unlinkSync(csvPath);
			// delete one (cancel, then confirm)
			const first = page.locator('#uxs-root tbody tr').first();
			const email = (await first.locator('td').nth(1).innerText()).trim();
			await first.locator('button[aria-label=Delete]').click();
			await page.locator('.uxs-modal').waitFor();
			await shot(page, 'ep-confirm-light');
			c.ok((await page.locator('.uxs-modal__body').innerText()).includes(email), 'delete confirmation names the address');
			await page.locator('.uxs-modal__actions .uxs-btn', { hasText: 'Cancel' }).click();
			c.ok(sql(db, `SELECT COUNT(*) FROM jos_uxstudio_exit_popup_emails WHERE email=${hex(email)}`) === '1', 'delete cancelled');
			await first.locator('button[aria-label=Delete]').click();
			await page.locator('.uxs-modal__actions .uxs-btn--danger').click();
			for (let i = 0; i < 40 && sql(db, `SELECT COUNT(*) FROM jos_uxstudio_exit_popup_emails WHERE email=${hex(email)}`) !== '0'; i++) await sleep(500);
			c.ok(sql(db, `SELECT COUNT(*) FROM jos_uxstudio_exit_popup_emails WHERE email=${hex(email)}`) === '0', 'delete confirmed removes the address');
			// dark mode
			await page.evaluate(() => localStorage.setItem('uxstudio-theme', 'dark'));
			await reloadEn();
			await page.getByRole('tab', { name: 'Subscribers' }).click();
			await page.locator('#uxs-root tbody tr').first().waitFor();
			await shot(page, 'ep-subscribers-dark');
			await page.getByRole('tab', { name: 'Settings' }).click();
			await page.locator('.uxs-subtabs').waitFor();
			await shot(page, 'ep-settings-dark');
			await page.evaluate(() => localStorage.setItem('uxstudio-theme', 'light'));
			await reloadEn();
			// delete all
			await page.getByRole('tab', { name: 'Subscribers' }).click();
			const mine = Number(sql(db, "SELECT COUNT(*) FROM jos_uxstudio_exit_popup_emails WHERE email LIKE 'e2e-%' OR email LIKE '=%'"));
			const all = Number(sql(db, 'SELECT COUNT(*) FROM jos_uxstudio_exit_popup_emails'));
			if (mine === all) {
				await page.getByRole('button', { name: 'Delete all' }).click();
				await page.locator('.uxs-modal__actions .uxs-btn--danger').click();
				await page.getByText('No e-mail addresses captured yet.').waitFor({ timeout: 6000 });
				c.ok(sql(db, 'SELECT COUNT(*) FROM jos_uxstudio_exit_popup_emails') === '0', 'Delete all empties the table');
				c.ok(await page.getByRole('button', { name: 'Export CSV' }).isDisabled(), 'Export CSV disabled when empty');
			} else {
				sql(db, "DELETE FROM jos_uxstudio_exit_popup_emails WHERE email LIKE 'e2e-%' OR email LIKE '=%'");
				c.ok(true, 'Delete all skipped (foreign subscribers present)');
			}
		});

		await step('exit-popup autoresponder logged', async () => {
			// enable the autoresponder, subscribe once, expect a captured mail (local mail() does not deliver; email-log captures)
			await reloadEn();
			await page.getByRole('tab', { name: 'Settings' }).click();
			await page.locator('.uxs-subtabs').waitFor({ timeout: 20000 });
			await setField(page, SECTION_EP, 'enable_autoresponder', true, 'toggle');
			await setField(page, SECTION_EP, 'autoresponder_subject', 'E2E welcome');
			await setField(page, SECTION_EP, 'autoresponder_body', '<p>Thanks E2E</p>', 'rich');
			await page.getByRole('button', { name: 'Save', exact: true }).click();
			await toast(page, 'success').last().waitFor();
			resetRate();
			const { page: fp } = await anon();
			await fp.goto(home);
			await fp.locator('#uxs-exit-popup.is-open').waitFor({ timeout: 6000 });
			await fp.fill('#uxs-exit-popup #uxs-exit-email', 'e2e-auto@example.test');
			await fp.check('#uxs-exit-popup input[name=consent]');
			await fp.click('.uxs-exit__submit');
			await fp.locator('.uxs-exit__message.is-success').waitFor({ timeout: 45000 });
			c.ok(true, 'subscribing with the autoresponder on still succeeds (mail failure does not break it)');
			const logged = sql(db, "SELECT COUNT(*) FROM jos_uxstudio_email_log WHERE to_email LIKE '%e2e-auto@example.test%'");
			c.ok(Number(logged) >= 1, `autoresponder mail captured in the email log (${logged} row)`);
			sql(db, "DELETE FROM jos_uxstudio_exit_popup_emails WHERE email LIKE 'e2e-%'");
			sql(db, "DELETE FROM jos_uxstudio_email_log WHERE to_email LIKE '%e2e-auto@example.test%'");
		});

		await ctx.close();

		// ================================================================ ADMIN (cs-CZ)
		setLang('cs-CZ');
		exitSettings({ active: true, time_on_page: 2, enable_email: true });
		const cz = await context(browser, { ignore: IGNORE });
		allIssues.push(cz.issues);
		const cp = await adminLogin(cz.ctx, site);
		await step('cs-CZ popup-manager', async () => {
			setLang('cs-CZ');
			await openStudio(cp, site, PM);
			const cs = JSON.parse(fs.readFileSync('D:/xampp/htdocs/ux-studio-joomla/admin-app/src/modules/popup-manager/i18n/cs.json', 'utf8'));
			const untranslated = (text, dict) => Object.keys(dict).filter((k) => k.length > 3 && dict[k] !== k && text.includes(k));
			const tabs = (await cp.locator('.uxs-tabs [role=tab]').allInnerTexts()).map((s) => s.trim());
			c.ok(tabs.join('|') !== 'Popups|Settings' && tabs.length === 2, `cs tabs: ${tabs.join('|')}`);
			c.ok((await cp.getByText(cs['No popups yet.']).count()) === 1, 'cs empty state translated');
			await shot(cp, 'cs-pm-empty');
			await cp.locator('.uxs-btn--primary').first().click();
			await cp.locator('.uxs-subtabs').waitFor();
			for (let i = 0; i < 5; i++) {
				await cp.locator('.uxs-subtabs [role=tab]').nth(i).click();
				await sleep(250);
				const txt = await cp.locator('#uxs-root').innerText();
				const left = untranslated(txt, cs);
				c.ok(left.length === 0, `cs editor section ${i} fully translated${left.length ? ' - English left: ' + left.slice(0, 3).join(' / ') : ''}`);
				c.ok(!/UXS_[A-Z_]{4,}/.test(txt), `cs editor section ${i}: no raw language keys`);
				if (i === 2) await shot(cp, 'cs-pm-editor-trigger');
			}
			setLang('cs-CZ'); // server messages follow the admin language at request time; the account is shared with other agents
			await cp.locator('button[type=submit].uxs-btn--primary').click();
			await cp.locator('.uxs-toast.is-error').first().waitFor();
			const err = await cp.locator('.uxs-toast.is-error').first().innerText();
			c.ok(!/Give the popup a name/.test(err) && err.length > 5, `cs validation error localized: "${err.trim()}"`);
			await cp.locator('.uxs-form__actions .uxs-btn:not(.uxs-btn--primary)').click();
			await cp.getByRole('tab').nth(1).click();
			await cp.locator('.uxs-form__row').first().waitFor();
			const settingsText = await cp.locator('#uxs-root').innerText();
			c.ok(!/UXS_[A-Z_]{4,}/.test(settingsText), 'cs popup-manager settings: no raw language keys');
			await shot(cp, 'cs-pm-settings');
		});
		await step('cs-CZ exit-popup', async () => {
			setLang('cs-CZ');
			await openStudio(cp, site, EP);
			const cs = JSON.parse(fs.readFileSync('D:/xampp/htdocs/ux-studio-joomla/admin-app/src/modules/exit-popup/i18n/cs.json', 'utf8'));
			for (let i = 0; i < 5; i++) {
				await cp.locator('.uxs-subtabs [role=tab]').nth(i).click();
				await sleep(700);
				const txt = await cp.locator('#uxs-root').innerText();
				c.ok(!/UXS_[A-Z_]{4,}/.test(txt), `cs exit-popup section ${i}: no raw language keys`);
				const left = Object.keys(cs).filter((k) => k.length > 6 && cs[k] !== k && txt.includes(k));
				c.ok(left.length === 0, `cs exit-popup section ${i} translated${left.length ? ' - English left: ' + left.slice(0, 3).join(' / ') : ''}`);
				if (i === 4) await shot(cp, 'cs-ep-email');
			}
			await cp.getByRole('tab').nth(1).click();
			await cp.locator('[role=tabpanel], #uxs-root').first().waitFor();
			await sleep(600);
			const txt = await cp.locator('#uxs-root').innerText();
			const left = Object.keys(cs).filter((k) => k.length > 6 && cs[k] !== k && txt.includes(k));
			c.ok(left.length === 0, `cs subscribers tab translated${left.length ? ' - English left: ' + left.slice(0, 3).join(' / ') : ''}`);
			await shot(cp, 'cs-ep-subscribers');
		});
		await step('cs-CZ frontend language of popup', async () => {
			// front end language is the site language, independent from the admin language: just make sure nothing shows raw keys
			const { page: fp } = await anon();
			await fp.goto(home);
			await fp.locator('#uxs-exit-popup.is-open').waitFor({ timeout: 6000 });
			const txt = await fp.locator('#uxs-exit-popup').innerText();
			c.ok(!/UXS_[A-Z_]{4,}/.test(txt), 'exit popup front end shows no raw language keys');
		});
		await cz.ctx.close();
	} catch (e) {
		c.ok(false, `scenario aborted: ${e.message}`);
		console.log(e.stack);
	} finally {
		restore();
		const seedStatsAfter = sql(db, 'SELECT COUNT(*) FROM jos_uxstudio_popup_stats WHERE popup_id IN (SELECT id FROM jos_uxstudio_popups)');
		c.ok(Number(seedStatsAfter) >= Number(seedStatsBefore), `existing popups keep their statistics (${seedStatsBefore} -> ${seedStatsAfter})`);
		for (const x of anonCtxs) await Promise.race([x.close().catch(() => {}), sleep(5000)]);
	}
	const flat = allIssues.flat();
	return c.done(flat);
}

if (import.meta.url === `file:///${process.argv[1].replace(/\\/g, '/')}` || process.argv[1].endsWith('popups.mjs')) {
	const bad = await main();
	process.exit(bad ? 1 : 0);
}
