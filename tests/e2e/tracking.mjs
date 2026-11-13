// E2E scenario for the "tracking" group: analytics, pixel-tag-manager, page-load, ai-markdown.
// Run: node tests/e2e/tracking.mjs [j5|j6|all] [--only=analytics,ptm,pageload,aimd] [--headed]
//
// Everything the scenario creates (a dedicated super user, two articles, measurements, bot log rows) is removed at the
// end and the settings of the four modules are restored, so the scenario can be repeated. The shared admin user is not
// touched (the language of the administration is switched on the scenario's own user).
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import crypto from 'node:crypto';
import { SHOTS, SITES, sitesFromArgv, checker } from './lib.mjs';

const MYSQL = 'D:/xampp/mysql/bin/mysql.exe';
const PHP = 'D:/xampp/php/php.exe';
const CHROME_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
const GOOGLEBOT_UA = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
const GPTBOT_UA = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot';
const MODS = ['analytics', 'pixel-tag-manager', 'page-load', 'ai-markdown'];
const USER = 'e2e_tracking';
const MARK = 'E2E Tracking';

const only = (process.argv.find((a) => a.startsWith('--only=')) ?? '').slice(7).split(',').filter(Boolean);
const wants = (k) => only.length === 0 || only.includes(k);

/* ------------------------------------------------------------------ helpers */

function sql(site, q) {
	return execFileSync(MYSQL, ['-uroot', '--default-character-set=utf8mb4', '-N', '-B', site.db], { input: q, encoding: 'utf8' }).trim();
}
const lit = (s) => "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function until(fn, ms = 8000, step = 250) {
	const end = Date.now() + ms;
	for (;;) {
		const v = await fn();
		if (v) return v;
		if (Date.now() > end) return v;
		await sleep(step);
	}
}

/** Browser context with collected console errors / HTTP >= 400 (same rules as lib.mjs context, but with a chosen UA). */
async function newCtx(browser, { ua = CHROME_UA, locale = 'cs-CZ', ignore = [] } = {}) {
	const ctx = await browser.newContext({ viewport: { width: 1400, height: 900 }, userAgent: ua, locale });
	const issues = [];
	// 404s of another module's cached images (instagram feed) are not ours
	const skipAll = [...ignore, /uxstudio-instagram\//, /challenges\.cloudflare\.com/]; // other modules' images / Turnstile widget
	const skip = (t) => skipAll.some((r) => r.test(t));
	ctx.on('page', (page) => {
		page.on('console', (m) => {
			const t = `${m.type()}: ${m.text()}`;
			if (['error', 'warning'].includes(m.type()) && !skip(t)) issues.push(`[${page.url()}] ${t.slice(0, 300)}`);
		});
		page.on('pageerror', (e) => !skip(e.message) && issues.push(`[${page.url()}] pageerror: ${e.message.slice(0, 300)}`));
		page.on('response', (r) => {
			const t = `HTTP ${r.status()} ${r.url()}`;
			if (r.status() >= 400 && !/favicon/.test(r.url()) && !skip(t)) issues.push(t.slice(0, 300));
		});
	});
	return { ctx, issues };
}

/** Fixture admin user so the language of the administration can be switched without touching the shared admin. */
function createUser(site) {
	const password = crypto.randomBytes(12).toString('hex');
	const hash = execFileSync(PHP, ['-r', 'echo password_hash($argv[1], PASSWORD_BCRYPT);', password], { encoding: 'utf8' }).trim();
	sql(site, `DELETE FROM jos_user_usergroup_map WHERE user_id IN (SELECT id FROM jos_users WHERE username='${USER}');
		DELETE FROM jos_users WHERE username='${USER}';
		INSERT INTO jos_users (name, username, email, password, block, sendEmail, registerDate, activation, params, otpKey, otep, requireReset, authProvider)
		VALUES ('E2E Tracking', '${USER}', 'e2e-tracking@example.invalid', ${lit(hash)}, 0, 0, NOW(), '', '{"admin_language":"en-GB"}', '', '', 0, '');
		INSERT INTO jos_user_usergroup_map (user_id, group_id) SELECT id, 8 FROM jos_users WHERE username='${USER}';`);
	return password;
}
function dropUser(site) {
	sql(site, `DELETE FROM jos_action_logs WHERE user_id IN (SELECT id FROM jos_users WHERE username='${USER}');
		DELETE FROM jos_user_usergroup_map WHERE user_id IN (SELECT id FROM jos_users WHERE username='${USER}');
		DELETE FROM jos_users WHERE username='${USER}';`);
}
const setLang = (site, lang) => sql(site, `UPDATE jos_users SET params='{"admin_language":"${lang}"}' WHERE username='${USER}'`);

async function login(ctx, site, password) {
	const page = await ctx.newPage();
	// the shared dev server is sometimes busy (other agents): the login form may need more than one try
	for (let attempt = 0; ; attempt++) {
		try {
			await page.goto(`${site.url}/administrator/index.php`, { timeout: 45000 });
			if (!(await page.locator('#mod-login-username').count()) && page.url().includes('/administrator/')) break; // an earlier attempt already got in
			await page.fill('#mod-login-username', USER, { timeout: 20000 });
			await page.fill('#mod-login-password', password);
			await Promise.all([page.waitForLoadState('load'), page.click('#btn-login-submit', { timeout: 20000 })]);
			break;
		} catch (e) {
			if (attempt >= 2) {
				const body = await page.locator('body').innerText().catch(() => '?');
				throw new Error(`${e.message.split(/\r?\n/)[0]} :: url=${page.url()} body=${body.slice(0, 200).replace(/\s+/g, ' ')}`);
			}
			await sleep(2500);
		}
	}
	if (await page.locator('#mod-login-username').count()) throw new Error(`login failed on ${site.key}`);
	// A brand new Joomla user gets the guided tour, which redirects to the dashboard: dismiss it for good.
	const hide = page.getByRole('button', { name: /Hide Forever|Skrýt navždy/i });
	if (await hide.waitFor({ timeout: 4000 }).then(() => true, () => false)) {
		await hide.click();
		await page.waitForTimeout(500);
	}
	return page;
}

/** Logged-in FRONTEND session of the fixture super user (the administrator session is separate). */
async function frontLogin(browser, site, pw) {
	const f = await newCtx(browser, { locale: 'cs-CZ' });
	const pg = await f.ctx.newPage();
	await pg.goto(`${site.url}/index.php?option=com_users&view=login`);
	await pg.fill('#username', USER);
	await pg.fill('#password', pw);
	await Promise.all([pg.waitForNavigation({ waitUntil: 'load' }), pg.press('#password', 'Enter')]);
	return { ...f, pg };
}

async function studio(page, site, id, tab) {
	await page.goto('about:blank'); // same URL + hash would not reload the SPA (stale react-query cache)
	// another agent may rebuild the SPA bundle at this very moment: retry once
	for (let attempt = 0; attempt < 2; attempt++) {
		await page.goto(`${site.url}/administrator/index.php?option=com_uxstudio#/module?id=${id}`);
		if (await page.locator('#uxs-root').waitFor({ timeout: 15000 }).then(() => true, () => false)) break;
		await page.goto('about:blank');
		await sleep(3000);
	}
	await page.locator('#uxs-root').waitFor({ timeout: 15000 });
	await page.waitForLoadState('networkidle');
	await page.locator('.uxs-tabs').first().waitFor({ timeout: 15000 }).catch(async (e) => {
		await shot(page, `trk-fail-${id}`);
		throw new Error(`${e.message.split('\n')[0]} ::${(await page.locator('body').innerText()).slice(0, 200).replace(/\s+/g, ' ')}`);
	});
	if (tab) await clickTab(page, tab);
}
/** networkidle can fire before a click-triggered request starts: add a short grace period. */
async function settle(page) {
	await page.waitForLoadState('networkidle');
	await sleep(500);
}
async function clickTab(page, name) {
	await page.locator('.uxs-tabs [role=tab]').filter({ hasText: name }).first().click();
	await page.waitForLoadState('networkidle');
}
async function theme(page, mode) {
	await page.evaluate((m) => localStorage.setItem('uxstudio-theme', m), mode);
	await page.reload();
	await page.locator('#uxs-root').waitFor();
	await page.waitForLoadState('networkidle');
	await page.locator('.uxs-tabs').first().waitFor();
}
const shot = (page, name) => page.screenshot({ path: `${SHOTS}${name}.png`, fullPage: true });

/** Light/dark contrast sanity: text colour of the main heading/card must differ clearly from its background. */
async function readable(page) {
	return page.evaluate(() => {
		const lum = (c) => {
			const m = c.match(/[\d.]+/g)?.map(Number) ?? [0, 0, 0];
			const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
			return 0.2126 * f(m[0]) + 0.7152 * f(m[1]) + 0.0722 * f(m[2]);
		};
		const bgOf = (el) => {
			for (let e = el; e; e = e.parentElement) {
				const b = getComputedStyle(e).backgroundColor;
				const a = b.match(/[\d.]+/g);
				if (a && (a.length === 3 || Number(a[3]) > 0.5)) return b;
			}
			return 'rgb(255,255,255)';
		};
		const bad = [];
		for (const el of document.querySelectorAll('#uxs-root .uxs-card, #uxs-root .uxs-table td, #uxs-root .uxs-table th, #uxs-root .uxs-muted, #uxs-root h2, #uxs-root h3, #uxs-root .uxs-badge')) {
			if (!el.textContent.trim() || !el.offsetParent) continue;
			const fg = lum(getComputedStyle(el).color);
			const bg = lum(bgOf(el));
			const ratio = (Math.max(fg, bg) + 0.05) / (Math.min(fg, bg) + 0.05);
			if (ratio < 3) bad.push(`${el.className || el.tagName}:${el.textContent.trim().slice(0, 24)}:${ratio.toFixed(1)}`);
		}
		return bad.slice(0, 5);
	});
}

function getSettings(site, mod) {
	const r = sql(site, `SELECT data FROM jos_uxstudio_settings WHERE module=${lit(mod)}`);
	return r === '' ? null : r;
}
function putSettings(site, mod, data) {
	if (data === null) sql(site, `DELETE FROM jos_uxstudio_settings WHERE module=${lit(mod)}`);
	else sql(site, `INSERT INTO jos_uxstudio_settings (module, data, updated_at) VALUES (${lit(mod)}, ${lit(data)}, NOW()) ON DUPLICATE KEY UPDATE data=VALUES(data)`);
}
function mergeSettings(site, mod, patch) {
	const cur = JSON.parse(getSettings(site, mod) ?? '{}');
	putSettings(site, mod, JSON.stringify({ ...cur, ...patch }));
}
/**
 * Real site data the scenario has to empty (hits, measurements, bot log, cache) and the settings of the four modules are
 * copied to e2e_trk_bak_* tables first and put back at the end. An existing copy is NEVER overwritten: if an earlier run
 * died before its cleanup, the copy still holds the genuine original state and the next run restores that.
 */
const STASH_TABLES = ['uxstudio_analytics_hits', 'uxstudio_analytics_daily', 'uxstudio_analytics_daily_pages', 'uxstudio_analytics_daily_refs',
	'uxstudio_analytics_daily_dims', 'uxstudio_page_load', 'uxstudio_ai_markdown_log', 'uxstudio_ai_markdown_cache'];
const bakTables = (site) => sql(site, "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE 'e2e_trk_bak_%'").split(/\r?\n/);
function stash(site) {
	const have = bakTables(site);
	const mods = MODS.map(lit).join(',');
	for (const t of STASH_TABLES) {
		if (!have.includes(`e2e_trk_bak_${t}`)) sql(site, `CREATE TABLE e2e_trk_bak_${t} AS SELECT * FROM jos_${t}`);
	}
	if (!have.includes('e2e_trk_bak_settings')) sql(site, `CREATE TABLE e2e_trk_bak_settings AS SELECT * FROM jos_uxstudio_settings WHERE module IN (${mods})`);
	if (!have.includes('e2e_trk_bak_flag')) sql(site, 'CREATE TABLE e2e_trk_bak_flag (x INT)');
}
function unstash(site) {
	const have = bakTables(site);
	if (!have.includes('e2e_trk_bak_flag')) return; // stash was never completed: nothing to restore
	const mods = MODS.map(lit).join(',');
	for (const t of STASH_TABLES) {
		if (have.includes(`e2e_trk_bak_${t}`)) sql(site, `DELETE FROM jos_${t}; INSERT INTO jos_${t} SELECT * FROM e2e_trk_bak_${t}; DROP TABLE e2e_trk_bak_${t}`);
	}
	if (have.includes('e2e_trk_bak_settings')) {
		sql(site, `DELETE FROM jos_uxstudio_settings WHERE module IN (${mods}); INSERT INTO jos_uxstudio_settings SELECT * FROM e2e_trk_bak_settings; DROP TABLE e2e_trk_bak_settings`);
	}
	sql(site, 'DROP TABLE e2e_trk_bak_flag');
}
function clearRate(site) {
	sql(site, 'DELETE FROM jos_uxstudio_ratelimit');
}

/** Set a settings field through the real form (text/number/select/toggle) and press Save. */
async function formSet(page, key, value) {
	const row = page.locator(`.uxs-form__row:has(label[for="uxs-${key}"])`).first();
	await row.waitFor({ state: 'attached' });
	if (typeof value === 'boolean') {
		const sw = row.locator('button.uxs-switch');
		if (((await sw.getAttribute('aria-pressed')) === 'true') !== value) await sw.click();
	} else if ((await row.locator('select').count()) > 0) {
		await row.locator('select').selectOption(String(value));
	} else {
		await row.locator('input, textarea').first().fill(String(value));
	}
}
async function formSave(page) {
	// close old toasts first so that toastText() can only match the toast of THIS save
	for (const b of await page.locator('.uxs-toast__close').all()) await b.click().catch(() => {});
	await sleep(150);
	await page.locator('form button[type=submit].uxs-btn--primary').first().click();
}
async function toastText(page, re, ms = 6000) {
	return until(async () => (await page.locator('.uxs-toast').allInnerTexts()).find((t) => re.test(t)), ms, 200);
}
async function subTab(page, name) {
	await page.locator('.uxs-subtabs').first().waitFor({ timeout: 6000 }).catch(() => {});
	const tab = page.locator('.uxs-subtabs [role=tab]').filter({ hasText: name }).first();
	if (await tab.count()) await tab.click();
}

/* ------------------------------------------------------------------ fixtures: articles via the Joomla administration */

/** Joomla.submitbutton navigates away, so the evaluate promise never settles normally. */
async function submit(page, task) {
	const nav = page.waitForNavigation({ waitUntil: 'load' });
	await page.evaluate((a) => { setTimeout(() => Joomla.submitbutton(a), 50); }, task).catch(() => {});
	await nav;
}

async function createArticle(page, site, title, html) {
	await page.goto(`${site.url}/administrator/index.php?option=com_content&task=article.add`);
	await page.locator('#jform_title').fill(title);
	await page.waitForFunction(() => window.Joomla && Joomla.editors && Joomla.editors.instances && Joomla.editors.instances.jform_articletext, null, { timeout: 15000 });
	await page.evaluate((h) => Joomla.editors.instances.jform_articletext.setValue(h), html);
	await submit(page, 'article.save');
	return Number(sql(SITE_REF.site, `SELECT id FROM jos_content WHERE title=${lit(title)} ORDER BY id DESC LIMIT 1`)) || 0;
}
const SITE_REF = { site: null };

async function listAction(page, site, id, action) {
	await page.goto(`${site.url}/administrator/index.php?option=com_content&view=articles&filter[published]=*&filter[search]=id:${id}`);
	await page.locator(`input[name="cid[]"][value="${id}"]`).check();
	await submit(page, action);
}

async function setAccess(page, site, id, level) {
	await page.goto(`${site.url}/administrator/index.php?option=com_content&task=article.edit&id=${id}`);
	await page.locator('#jform_access').selectOption(String(level));
	await submit(page, 'article.save');
	await submit(page, 'article.cancel');
}

const ALPHA_HTML =
	'<h2>Sekce Alfa</h2><p>Text s <strong>tučným</strong> a <a href="/odkaz-alfa">odkazem</a> a příliš žluťoučký kůň.</p>' +
	'<ul><li>první položka</li><li>druhá položka</li></ul>' +
	'<table><thead><tr><th>Sloupec A</th><th>Sloupec B</th></tr></thead><tbody><tr><td>a1</td><td>b1</td></tr></tbody></table>';

/* ------------------------------------------------------------------ scenario parts */

const NUM = (s) => Number(String(s).replace(/[^\d]/g, '')) || 0;
const metricAfter = (text, label) => {
	const m = text.match(new RegExp(label + '\\s*\\n?\\s*(\\d[\\d\\s\\u00a0.,]*)', 'i'));
	return m ? NUM(m[1]) : -1;
};

async function analyticsPart(browser, site, pw, c, art) {
	const mod = 'analytics';
	const wipe = () => sql(site, 'DELETE FROM jos_uxstudio_analytics_hits; DELETE FROM jos_uxstudio_analytics_daily; DELETE FROM jos_uxstudio_analytics_daily_pages; DELETE FROM jos_uxstudio_analytics_daily_refs; DELETE FROM jos_uxstudio_analytics_daily_dims;');
	const hits = () => Number(sql(site, "SELECT COUNT(*) FROM jos_uxstudio_analytics_hits WHERE lang='cs'"));
	wipe();
	clearRate(site);

	// -- empty state, light + dark, both languages
	const adm = await newCtx(browser, { locale: 'en-GB' });
	const ap = await login(adm.ctx, site, pw);
	await studio(ap, site, mod);
	let body = await ap.locator('#uxs-root').innerText();
	c.ok(/No data/i.test(body), 'analytics empty state: "No data." is shown');
	c.ok(/\bVisitors\b/.test(body) && /Right now/i.test(body), 'analytics empty state: KPI cards render');
	await shot(ap, `trk-${site.key}-analytics-empty-light`);

	// -- visits as an anonymous visitor
	const vis = await newCtx(browser, { locale: 'cs-CZ', ignore: [/e2e-excluded-page/, /Failed to load resource: the server responded with a status of 404/] });
	const p = await vis.ctx.newPage();
	const visit = async (url, referer) => {
		const wait = p.waitForResponse((r) => r.url().includes('analytics/hit') && r.request().method() === 'POST', { timeout: 20000 }).catch(() => null);
		await p.goto(url, referer ? { referer } : undefined);
		const r = await wait;
		return r ? r.status() : 0;
	};
	const aUrl = `${site.url}/index.php?option=com_content&view=article&id=${art.alpha}&catid=2`;
	const bUrl = `${site.url}/index.php?option=com_content&view=article&id=${art.beta}&catid=2`;
	c.ok((await visit(`${site.url}/`, 'https://www.google.com/search?q=x')) === 200, 'visit home with Google referrer: beacon answered 200');
	c.ok((await visit(aUrl, `${site.url}/`)) === 200, 'visit article Alpha (internal referrer): beacon 200');
	c.ok((await visit(bUrl, 'https://www.seznam.cz/')) === 200, 'visit article Beta (Seznam referrer): beacon 200');
	await sleep(300);
	c.ok(hits() === 3, `3 hits stored in buffer (got ${hits()})`);
	const row = sql(site, "SELECT CONCAT(device,'|',browser,'|',os,'|',lang,'|',IFNULL(referer_host,'-')) FROM jos_uxstudio_analytics_hits WHERE lang='cs' ORDER BY id LIMIT 1");
	c.ok(/^desktop\|Chrome\|Windows\|cs\|www\.google\.com$/i.test(row), `hit dimensions parsed (device|browser|os|lang|ref): ${row}`);
	c.ok(sql(site, "SELECT COUNT(*) FROM jos_uxstudio_analytics_hits WHERE referer_host='localhost'") === '0', 'internal referrer is not counted as a source');

	// F5 spam within 10 s is not counted twice
	const before = hits();
	await p.reload();
	await sleep(1200);
	c.ok(hits() === before, 'reload within 10 s is not counted twice (client side)');

	// bots are ignored
	const bot = await newCtx(browser, { ua: GOOGLEBOT_UA });
	const bp = await bot.ctx.newPage();
	const bw = bp.waitForResponse((r) => r.url().includes('analytics/hit'), { timeout: 8000 }).catch(() => null);
	await bp.goto(`${site.url}/`);
	const br = await bw;
	await sleep(400);
	c.ok(br && br.status() === 200 && hits() === before, 'Googlebot: beacon answers ok but nothing is stored (bot filter)');
	await bot.ctx.close();
	// default headless UA ("HeadlessChrome") is treated as a bot too
	const hl = await browser.newContext({ locale: 'cs-CZ' });
	const hp = await hl.newPage();
	await hp.goto(`${site.url}/`);
	await sleep(1200);
	c.ok(hits() === before, 'HeadlessChrome UA is filtered as bot');
	await hl.close();

	// direct API abuse of the public beacon
	const api = vis.ctx.request;
	const ep = `${site.url}/index.php?option=com_uxstudio&task=public.dispatch&format=json&route=analytics/hit`;
	let r = await api.post(ep, { data: { path: '/x', ref: '' }, headers: { Origin: 'http://evil.example' } });
	c.ok(r.status() === 403, `foreign Origin rejected (${r.status()})`);
	r = await api.post(ep, { data: { path: 'x'.repeat(6000), ref: '' }, headers: { Origin: new URL(site.url).origin, 'Accept-Language': 'cs-CZ' } });
	c.ok([400, 413].includes(r.status()), `oversize body rejected (${r.status()})`);
	r = await api.post(ep, { data: { path: '', ref: '' }, headers: { Origin: new URL(site.url).origin, 'Accept-Language': 'cs-CZ' } });
	await sleep(200);
	c.ok(hits() === before, `empty path is not a page view (status ${r.status()})`);
	r = await api.post(ep, { data: { path: '/administrator/secret', ref: '' }, headers: { Origin: new URL(site.url).origin, 'Accept-Language': 'cs-CZ' } });
	c.ok(hits() === before, 'administrator path is excluded');

	// staff logged in on the FRONTEND are not counted (the administrator session is separate)
	const fs = await newCtx(browser, { locale: 'cs-CZ' });
	const fp = await fs.ctx.newPage();
	await fp.goto(`${site.url}/index.php?option=com_users&view=login`);
	await fp.fill('#username', USER);
	await fp.fill('#password', pw);
	await Promise.all([fp.waitForNavigation({ waitUntil: 'load' }), fp.press('#password', 'Enter')]);
	const loggedIn = (await fp.locator('#password').count()) === 0;
	sql(site, "DELETE FROM jos_uxstudio_analytics_hits WHERE path LIKE '%users%'"); // the anonymous login form view itself was a legitimate hit
	const beforeStaff = hits();
	await fp.goto(`${site.url}/`);
	await sleep(1200);
	await shot(fp, `trk-${site.key}-frontend-login`);
	c.ok(loggedIn, 'frontend login as the fixture super user worked');
	c.ok(hits() === beforeStaff, 'logged-in UX1 Studio admin on the frontend is not counted (exclude_staff)');
	c.ok((await fp.locator('script[src*="analytics.js"]').count()) === 0, 'no beacon script for staff');
	await fs.ctx.close();

	// -- dashboard
	await studio(ap, site, mod);
	await sleep(1800); // KPI numbers count up
	body = await ap.locator('#uxs-root').innerText();
	const views = metricAfter(body, 'Views');
	const visitors = metricAfter(body, 'Visitors');
	c.ok(views >= 3 && visitors >= 1, `overview Today: views=3 visitors=1 (got ${views}/${visitors})`);
	c.ok(/www\.google\.com/.test(body) && /www\.seznam\.cz/.test(body), 'traffic sources list Google and Seznam');
	const topPages = await ap.locator('.uxs-table').allInnerTexts();
	c.ok(topPages.some((tt) => new RegExp(`id=${art.alpha}`).test(tt)), 'top pages table lists the visited article page');
	c.ok(/Desktop/.test(body) && /Chrome/.test(body) && /Windows/.test(body), 'device / browser / OS donuts populated');
	c.ok((await ap.locator('[role=img][aria-label]').count()) > 0, 'bar chart rendered');
	const bars = await ap.locator('[role=img][aria-label] > div').count();
	c.ok(bars > 0, `chart has bars (${bars})`);
	await shot(ap, `trk-${site.key}-analytics-light`);
	c.ok((await readable(ap)).length === 0, 'analytics overview light mode: text readable ' + JSON.stringify(await readable(ap)));

	for (const label of ['Yesterday', '7 days', '14 days', '30 days', 'This month', 'Today']) {
		await ap.locator('[role=group] button').filter({ hasText: new RegExp('^' + label + '$') }).click();
		await settle(ap);
		// KPI numbers count up and a loaded machine is slow: poll until the value is the expected one
		const want = label === 'Yesterday' ? 0 : 3;
		await until(async () => metricAfter(await ap.locator('#uxs-root').innerText(), 'Views') >= want, 8000, 300);
		const txt = await ap.locator('#uxs-root').innerText();
		const v = metricAfter(txt, 'Views');
		if (label === 'Yesterday') c.ok(v === 0, `period ${label}: zero views for a day without traffic (${v})`);
		else c.ok(v >= 3, `period ${label}: views>=3 (${v})`);
	}

	await theme(ap, 'dark');
	c.ok((await ap.locator('#uxs-root').getAttribute('data-theme')) === 'dark', 'dark mode applied');
	await sleep(1800);
	await shot(ap, `trk-${site.key}-analytics-dark`);
	const darkBad = await readable(ap);
	c.ok(darkBad.length === 0, 'analytics overview dark mode: text readable ' + JSON.stringify(darkBad));
	await theme(ap, 'light');

	// -- GeoIP card present, buttons only for admins
	c.ok(/GeoIP/i.test(await ap.locator('#uxs-root').innerText()), 'GeoIP card is visible');

	// -- settings through the form: validation + effect
	await clickTab(ap, 'Settings');
	await formSet(ap, 'exclude_paths', '/e2e-excluded*');
	await formSet(ap, 'retention_days', 99999);
	await formSave(ap);
	await sleep(600);
	const invalidNative = await ap.evaluate(() => !document.querySelector('form').checkValidity());
	c.ok(invalidNative || (await toastText(ap, /saved|invalid|between|max|error/i, 1500)), 'retention 99999: rejected by the browser or clamped and answered by the server');
	let saved = JSON.parse(getSettings(site, mod) ?? '{}');
	c.ok(Number(saved.retention_days ?? 730) <= 3650 && Number(saved.retention_days ?? 730) !== 99999, `retention above max was not stored as typed (${saved.retention_days})`);
	await formSet(ap, 'retention_days', -5);
	await formSave(ap);
	await sleep(600);
	saved = JSON.parse(getSettings(site, mod) ?? '{}');
	c.ok(Number(saved.retention_days ?? 730) >= 0 && Number(saved.retention_days ?? 730) !== -5, `negative retention was not stored as typed (${saved.retention_days})`);
	await formSet(ap, 'retention_days', 365);
	await formSave(ap);
	c.ok(await toastText(ap, /saved/i), 'valid settings saved (toast)');
	saved = JSON.parse(getSettings(site, mod) ?? '{}');
	c.ok(/e2e-excluded/.test(saved.exclude_paths ?? '') && Number(saved.retention_days) === 365, 'settings persisted in DB');
	await shot(ap, `trk-${site.key}-analytics-settings`);

	// excluded path no longer counted
	const exBefore = hits();
	await visit(`${site.url}/e2e-excluded-page`, null);
	await sleep(400);
	c.ok(hits() === exBefore, 'excluded path (/e2e-excluded*) is not recorded');

	// DNT toggle changes the beacon attribute
	await formSet(ap, 'respect_dnt', true);
	await formSave(ap);
	await toastText(ap, /saved/i);
	await p.goto(`${site.url}/`);
	c.ok((await p.locator('script[src*="analytics.js"]').getAttribute('data-dnt')) === '1', 'respect_dnt on: data-dnt=1 on the beacon script');
	await formSet(ap, 'respect_dnt', false);
	await formSave(ap);
	await toastText(ap, /saved/i);

	// -- Czech administration
	await adm.ctx.close();
	setLang(site, 'cs-CZ');
	const cz = await newCtx(browser, { locale: 'cs-CZ' });
	const cp = await login(cz.ctx, site, pw);
	await studio(cp, site, mod);
	await cp.waitForSelector('.uxs-card');
	await sleep(800);
	const ct = await cp.locator('#uxs-root').innerText();
	c.ok(/Návštěvníci/i.test(ct) && /Zobrazení/i.test(ct), 'cs-CZ: overview translated');
	c.ok(!/Top pages|Traffic sources|Visitors and views/.test(ct), 'cs-CZ: no leftover English headings');
	await shot(cp, `trk-${site.key}-analytics-cs`);
	await clickTab(cp, 'Nastavení');
	await cp.waitForSelector('.uxs-form__row');
	const csForm = await cp.locator('#uxs-root').innerText();
	c.ok(/Nepočítat administrátory|Vyřazovat roboty/.test(csForm) && !/Exclude paths|Retention/.test(csForm), 'cs-CZ: settings form translated: ' + csForm.replace(/\s+/g, ' ').slice(250, 800));
	await cz.ctx.close();
	setLang(site, 'en-GB');

	await vis.ctx.close();
	wipe();
	return [...adm.issues, ...vis.issues, ...cz.issues];
}

async function ptmPart(browser, site, pw, c, art) {
	const mod = 'pixel-tag-manager';
	const stub = async (ctx, seen) => {
		await ctx.route(/googletagmanager\.com|connect\.facebook\.net|clarity\.ms|pinimg\.com|c\.seznam\.cz/, (route) => {
			seen.push(route.request().url());
			route.fulfill({ status: 200, contentType: 'application/javascript', body: '/* stub */' });
		});
	};
	const hit = (seen, re) => seen.some((u) => re.test(u));
	const ids = { ga4_id: 'G-TEST123456', gtm_id: 'GTM-TEST123', meta_id: '1234567890123', clarity_id: 'abcde12345', ads_id: '', pinterest_id: '', seznam_id: '' };
	const visitFront = async (seed, { cookies = [], after } = {}) => {
		const v = await newCtx(browser, { locale: 'cs-CZ' });
		const seen = [];
		await stub(v.ctx, seen);
		if (cookies.length) await v.ctx.addCookies(cookies);
		const pg = await v.ctx.newPage();
		await pg.goto(`${site.url}/`);
		await pg.waitForLoadState('load');
		await sleep(1200);
		if (after) await after(pg, seen);
		return { v, pg, seen };
	};
	const domain = 'localhost';

	// Admin: settings through the form (consent off first = the previous fixture, then each mode)
	const adm = await newCtx(browser, { locale: 'en-GB' });
	const ap = await login(adm.ctx, site, pw);
	mergeSettings(site, mod, { ...ids, consent_mode: 'uxstudio', skip_staff: true });
	await studio(ap, site, mod);
	let body = await ap.locator('#uxs-root').innerText();
	c.ok(/Google Analytics 4/.test(body) && /G-TEST123456/.test(body) && /Active/.test(body), 'overview lists GA4 with ID and "Active"');
	c.ok(/Not set/.test(body), 'overview shows "Not set" for empty providers');
	c.ok(/Content Security Policy/.test(body) && /googletagmanager\.com/.test(body), 'CSP hosts listed for active tags');
	c.ok(/No consent solution found|Consent mode|Consent category/.test(body) && /Consent/.test(body), 'consent state card is shown');
	await shot(ap, `trk-${site.key}-ptm-uxstudio-light`);

	// mode uxstudio: nothing loads without consent
	let f = await visitFront();
	c.ok(f.seen.length === 0, `consent_mode=uxstudio, no consent: no provider script requested (${f.seen.length})`);
	c.ok((await f.pg.locator('script[src*="ptm.js"]').count()) === 1, 'ptm.js loader is on the page');
	const cfg = JSON.parse(await f.pg.locator('script[src*="ptm.js"]').getAttribute('data-config'));
	c.ok(cfg.ids.ga4 === 'G-TEST123456' && cfg.consent.mode === 'uxstudio', 'data-config carries validated IDs and mode');
	c.ok((await f.pg.locator('script:not([src])').evaluateAll((s) => s.filter((x) => /googletagmanager|fbq\(|clarity/.test(x.textContent)).length)) === 0, 'no inline tracking code (CSP friendly)');

	// live consent: cookie + event -> analytics providers
	await f.pg.evaluate(() => {
		document.cookie = 'uxs_consent=' + encodeURIComponent(JSON.stringify({ analytics: true, marketing: false })) + '; path=/';
		window.dispatchEvent(new Event('uxs:consent'));
	});
	await sleep(600);
	c.ok(hit(f.seen, /gtag\/js\?id=G-TEST123456/) && hit(f.seen, /clarity\.ms\/tag\/abcde12345/), 'analytics consent granted live: GA4 + Clarity load');
	c.ok(!hit(f.seen, /gtm\.js/) && !hit(f.seen, /fbevents/), 'marketing tags (GTM, Meta) stay off without marketing consent');
	const dl = await f.pg.evaluate(() => (window.dataLayer || []).map((a) => Array.prototype.slice.call(a).slice(0, 2).join(':')));
	c.ok(dl.some((x) => x.startsWith('consent:default')) && dl.some((x) => x.startsWith('consent:update')), `Consent Mode v2 default+update pushed (${dl.slice(0, 4).join(', ')})`);
	await f.pg.evaluate(() => {
		document.cookie = 'uxs_consent=' + encodeURIComponent(JSON.stringify({ analytics: true, marketing: true })) + '; path=/';
		window.dispatchEvent(new Event('uxs:consent'));
	});
	await sleep(600);
	c.ok(hit(f.seen, /gtm\.js\?id=GTM-TEST123/) && hit(f.seen, /fbevents\.js/), 'marketing consent granted live: GTM + Meta load');
	const issues1 = f.v.issues;
	await f.v.ctx.close();

	// cookie present from the start
	f = await visitFront(0, { cookies: [{ name: 'uxs_consent', value: encodeURIComponent(JSON.stringify({ analytics: false, marketing: true })), domain, path: '/' }] });
	c.ok(hit(f.seen, /gtm\.js/) && hit(f.seen, /fbevents/) && !hit(f.seen, /gtag\/js/) && !hit(f.seen, /clarity/), 'consent cookie marketing-only: only marketing tags load');
	await f.v.ctx.close();
	f = await visitFront(0, { cookies: [{ name: 'uxs_consent', value: '%7Bnot-json', domain, path: '/' }] });
	c.ok(f.seen.length === 0, 'garbled consent cookie is treated as no consent');
	await f.v.ctx.close();

	// mode off: everything loads straight away (and the overview warns)
	mergeSettings(site, mod, { consent_mode: 'off' });
	f = await visitFront();
	c.ok(hit(f.seen, /gtag\/js/) && hit(f.seen, /gtm\.js/) && hit(f.seen, /fbevents/) && hit(f.seen, /clarity/), 'consent_mode=off: all four tags load without consent');
	await f.v.ctx.close();
	await studio(ap, site, mod);
	body = await ap.locator('#uxs-root').innerText();
	c.ok(/Tags load without consent/.test(body), 'overview warns "Tags load without consent"');
	await shot(ap, `trk-${site.key}-ptm-off-light`);
	await ap.getByRole('button', { name: /Change consent settings/ }).click();
	c.ok((await ap.locator('.uxs-tabs [role=tab][aria-selected=true]').innerText()).trim() === 'Settings', 'warning button jumps to Settings');

	// mode cookie (other tool)
	mergeSettings(site, mod, { consent_mode: 'cookie', consent_analytics_cookie: 'cc_analytics', consent_analytics_match: 'yes', consent_marketing_cookie: '', consent_marketing_match: '' });
	f = await visitFront();
	c.ok(f.seen.length === 0, 'consent_mode=cookie: nothing without cookie');
	await f.v.ctx.close();
	f = await visitFront(0, { cookies: [{ name: 'cc_analytics', value: 'no', domain, path: '/' }] });
	c.ok(f.seen.length === 0, 'cookie present but without the required text: not allowed');
	await f.v.ctx.close();
	f = await visitFront(0, { cookies: [{ name: 'cc_analytics', value: 'yes', domain, path: '/' }] });
	c.ok(hit(f.seen, /gtag\/js/) && hit(f.seen, /clarity/) && !hit(f.seen, /gtm\.js/), 'cookie with matching text: analytics tags load, marketing (no cookie name) never');
	await f.v.ctx.close();
	await studio(ap, site, mod);
	body = await ap.locator('#uxs-root').innerText();
	c.ok(/consent cookie name is missing or invalid/i.test(body) && /Marketing/.test(body), 'overview reports missing marketing cookie name');

	// invalid ID through the settings form
	mergeSettings(site, mod, { consent_mode: 'off' });
	await studio(ap, site, mod, 'Settings');
	await formSet(ap, 'ga4_id', '<script>alert(1)</script>');
	await formSet(ap, 'meta_id', 'abc');
	await formSave(ap);
	c.ok(await toastText(ap, /saved/i), 'settings with bad IDs are saved (format check happens on render)');
	await clickTab(ap, 'Overview');
	await ap.waitForFunction(() => /Invalid ID/.test(document.body.innerText), null, { timeout: 12000 }).catch(() => {});
	body = await ap.locator('#uxs-root').innerText();
	c.ok(/Invalid ID/.test(body) && /wrong format and are ignored/.test(body), 'overview shows "Invalid ID" for GA4 and Meta: ' + body.replace(/\s+/g, ' ').slice(150, 700));
	await shot(ap, `trk-${site.key}-ptm-invalid`);
	f = await visitFront();
	const cfg2 = JSON.parse(await f.pg.locator('script[src*="ptm.js"]').getAttribute('data-config'));
	c.ok(!cfg2.ids.ga4 && !cfg2.ids.meta && !!cfg2.ids.gtm, 'invalid IDs are dropped from data-config, valid ones stay');
	c.ok(!hit(f.seen, /gtag\/js/) && !hit(f.seen, /fbevents/) && hit(f.seen, /gtm\.js/), 'invalid-ID providers do not load');
	c.ok(!(await f.pg.content()).includes('<script>alert(1)</script>'), 'raw <script> from settings is not echoed into the page');
	await f.v.ctx.close();

	// staff are skipped
	const fl = await frontLogin(browser, site, pw);
	await fl.pg.goto(`${site.url}/`);
	c.ok((await fl.pg.locator('script[src*="ptm.js"]').count()) === 0, 'skip_staff: no loader for logged-in UX1 Studio admins');
	await fl.ctx.close();

	// no IDs at all -> loader absent
	mergeSettings(site, mod, { ga4_id: '', gtm_id: '', meta_id: '', clarity_id: '' });
	const none = await newCtx(browser);
	const np = await none.ctx.newPage();
	await np.goto(`${site.url}/`);
	c.ok((await np.locator('script[src*="ptm.js"]').count()) === 0, 'no IDs configured: loader is not added');
	await none.ctx.close();
	await studio(ap, site, mod);
	body = await ap.locator('#uxs-root').innerText();
	c.ok(!/Content Security Policy/.test(body), 'no active tags: no CSP host list');

	// extra providers: Ads / Pinterest / Seznam
	mergeSettings(site, mod, { ads_id: 'AW-123456789', pinterest_id: '2612345678901', seznam_id: '123456', consent_mode: 'off' });
	f = await visitFront();
	c.ok(hit(f.seen, /gtag\/js\?id=AW-123456789/) && hit(f.seen, /s\.pinimg\.com\/ct\/core\.js/) && hit(f.seen, /c\.seznam\.cz\/js\/rc\.js/), 'Ads, Pinterest and Seznam load');
	await f.v.ctx.close();
	await studio(ap, site, mod);
	body = await ap.locator('#uxs-root').innerText();
	c.ok(/googleadservices|doubleclick/.test(body) && /seznam\.cz/.test(body) && /pinimg\.com|pinterest\.com/.test(body), 'CSP host list extended by Ads, Pinterest and Seznam hosts');

	// restore the fixture look (consent off with 4 tags) for the screenshots in both modes, then dark mode
	mergeSettings(site, mod, { ...ids, ads_id: '', pinterest_id: '', seznam_id: '', consent_mode: 'uxstudio' });
	await studio(ap, site, mod);
	await theme(ap, 'dark');
	await shot(ap, `trk-${site.key}-ptm-dark`);
	const darkBad = await readable(ap);
	c.ok(darkBad.length === 0, 'ptm overview dark mode: text readable ' + JSON.stringify(darkBad));
	await clickTab(ap, 'Settings');
	await shot(ap, `trk-${site.key}-ptm-settings-dark`);
	await theme(ap, 'light');

	// Czech
	await adm.ctx.close();
	setLang(site, 'cs-CZ');
	const cz = await newCtx(browser, { locale: 'cs-CZ' });
	const cp = await login(cz.ctx, site, pw);
	await studio(cp, site, mod);
	const ct = await cp.locator('#uxs-root').innerText();
	c.ok(/Aktivní/.test(ct) && /Souhlas/.test(ct), 'cs-CZ: overview translated');
	const eng = ct.replace(/Google Tag Manager|Pinterest Tag/g, '').match(/\b(Active|Not set|Consent category|Invalid ID)\b/);
	c.ok(!eng, 'cs-CZ: no leftover English table headers: ' + (eng ? eng[0] + ' in ' + ct.replace(/\s+/g, ' ').slice(Math.max(0, eng.index - 40), eng.index + 40) : ''));
	await shot(cp, `trk-${site.key}-ptm-cs`);
	await clickTab(cp, 'Nastavení');
	await subTab(cp, 'Souhlas');
	const cs2 = await cp.locator('#uxs-root').innerText();
	c.ok(!/Consent mode|Skip staff|Cookie of another/.test(cs2), 'cs-CZ: consent settings translated');
	await shot(cp, `trk-${site.key}-ptm-settings-cs`);
	await cz.ctx.close();
	setLang(site, 'en-GB');
	return [...adm.issues, ...issues1, ...cz.issues];
}

async function pageLoadPart(browser, site, pw, c, art) {
	const mod = 'page-load';
	const wipe = () => sql(site, 'DELETE FROM jos_uxstudio_page_load');
	const count = () => Number(sql(site, 'SELECT COUNT(*) FROM jos_uxstudio_page_load'));
	wipe();
	clearRate(site);
	mergeSettings(site, mod, { sample_rate: 100, vitals: true, skip_logged_in: true, slow_ms: 3000, retention_days: 30 });

	const adm = await newCtx(browser, { locale: 'en-GB' });
	const ap = await login(adm.ctx, site, pw);
	await studio(ap, site, mod);
	let body = await ap.locator('#uxs-root').innerText();
	c.ok(/No measurements yet/.test(body), 'overview empty state is shown');
	c.ok((await ap.getByRole('button', { name: /Delete data/ }).isDisabled()), '"Delete data" is disabled without data');
	await shot(ap, `trk-${site.key}-pageload-empty`);
	await clickTab(ap, 'Slowest pages');
	body = await ap.locator('#uxs-root').innerText();
	c.ok(/No measurements for this period/.test(body), 'slowest pages empty state');

	// real navigations (Navigation Timing -> beacon on pagehide / 4 s after load)
	const vis = await newCtx(browser, { locale: 'cs-CZ' });
	const p = await vis.ctx.newPage();
	const aUrl = `${site.url}/index.php?option=com_content&view=article&id=${art.alpha}&catid=2`;
	// Each page view sends its sample 4 s after load (the pagehide path is not reliable in headless Chromium).
	await p.goto(`${site.url}/`);
	c.ok(!!(await until(() => count() >= 1, 9000)), `home page beacon stored (${count()})`);
	await p.goto(aUrl);
	c.ok(!!(await until(() => count() >= 2, 9000)), `article page beacon stored (${count()})`);
	await p.goto(`${site.url}/index.php?option=com_content&view=featured`);
	await until(() => count() >= 3, 9000);
	const n = count();
	c.ok(n >= 3, `timer-based beacon also stored (${n} rows)`);
	const sample = sql(site, 'SELECT CONCAT(path,"|",device,"|",nav_type,"|",ttfb_ms>0,"|",load_ms>0,"|",fcp_ms>0) FROM jos_uxstudio_page_load ORDER BY id DESC LIMIT 1');
	c.ok(/^\/.*\|desktop\|[a-z_-]+\|1\|1\|[01]$/.test(sample), `sample has path, device, nav type, ttfb and load (fcp optional in headless): ${sample}`);
	c.ok(sql(site, "SELECT COUNT(*) FROM jos_uxstudio_page_load WHERE path LIKE '%joomla%' AND path NOT LIKE '%option=%'") === '0', 'stored path is relative to the site (no sub-folder)');
	const cols = sql(site, 'SHOW COLUMNS FROM jos_uxstudio_page_load');
	c.ok(!/\bip\b|user_agent/i.test(cols), 'no IP / User-Agent column stored');

	// bots and abuse
	const ep = `${site.url}/index.php?option=com_uxstudio&task=public.dispatch&format=json&route=page-load/beacon`;
	const origin = new URL(site.url).origin;
	const good = { path: '/e2e-pl-api', ttfb: 50, fcp: 200, lcp: 300, dcl: 400, load: 500, cls: 5, size: 1000, nav: 'navigate', w: 1200 };
	const botCtx = await newCtx(browser, { ua: GOOGLEBOT_UA });
	const b0 = count();
	await botCtx.ctx.request.post(ep, { data: good, headers: { Origin: origin } });
	c.ok(count() === b0, 'Googlebot beacon ignored');
	await botCtx.ctx.close();
	let r = await vis.ctx.request.post(ep, { data: { ...good, path: '/e2e-pl-"><x' }, headers: { Origin: origin } });
	c.ok(count() === b0, `path with <>" rejected (status ${r.status()})`);
	r = await vis.ctx.request.post(ep, { data: { ...good, load: 0, path: '/e2e-pl-zero' }, headers: { Origin: origin } });
	c.ok(count() === b0, 'load=0 rejected');
	r = await vis.ctx.request.post(ep, { data: good, headers: { Origin: 'http://evil.example' } });
	c.ok(r.status() === 403 && count() === b0, `foreign Origin -> ${r.status()}`);
	r = await vis.ctx.request.post(ep, { data: { ...good, load: 9999999, path: '/e2e-pl-huge' }, headers: { Origin: origin } });
	const huge = sql(site, "SELECT MAX(load_ms) FROM jos_uxstudio_page_load WHERE path='/e2e-pl-huge'");
	c.ok(huge === 'NULL' || Number(huge) <= 120000, `absurd load time is clamped (${huge})`);
	r = await vis.ctx.request.post(ep, { data: { ...good, path: '/e2e-pl-slow', load: 7000, ttfb: 2500, fcp: 3500, lcp: 5000 }, headers: { Origin: origin } });
	await vis.ctx.request.post(ep, { data: { ...good, path: '/e2e-pl-slow', load: 9000, ttfb: 2600, fcp: 3600, lcp: 6000 }, headers: { Origin: origin } });
	c.ok(Number(sql(site, "SELECT COUNT(*) FROM jos_uxstudio_page_load WHERE path='/e2e-pl-slow'")) === 2, 'two synthetic slow samples stored');

	// staff not measured
	const fl = await frontLogin(browser, site, pw);
	await sleep(5200); // the anonymous login form view itself may still send its sample
	const s0 = count();
	await fl.pg.goto(`${site.url}/`);
	await sleep(5200); // the beacon would be sent 4 s after load
	await fl.pg.goto(`${site.url}/index.php?option=com_content&view=featured`);
	await sleep(5200);
	c.ok(count() === s0, 'logged-in visitors are not measured (skip_logged_in)');
	await fl.ctx.close();

	// overview
	await studio(ap, site, mod);
	await ap.waitForFunction(() => /measurements/i.test(document.body.innerText));
	body = await ap.locator('#uxs-root').innerText();
	c.ok(/Page load \(median\)/.test(body) && /Server response \(TTFB\)/.test(body) && /Largest contentful paint/.test(body), 'overview cards rendered');
	c.ok(/Devices/.test(body) && /Desktop/.test(body) && /Trend/.test(body), 'devices and trend sections rendered');
	c.ok(!/NaN|undefined|Infinity/.test(body), 'no NaN/undefined in overview');
	await shot(ap, `trk-${site.key}-pageload-light`);
	c.ok((await readable(ap)).length === 0, 'page-load overview light: readable ' + JSON.stringify(await readable(ap)));
	for (const [val, label] of [['1d', 'Last 24 hours'], ['30d', 'Last 30 days'], ['90d', 'Last 90 days'], ['7d', 'Last 7 days']]) {
		await ap.locator('select[aria-label=Period]').selectOption(val);
		await settle(ap);
		c.ok(!/No measurements yet/.test(await ap.locator('#uxs-root').innerText()), `period ${label}: data shown`);
	}
	await theme(ap, 'dark');
	await ap.waitForFunction(() => /Devices/.test(document.body.innerText));
	await shot(ap, `trk-${site.key}-pageload-dark`);
	const darkBad = await readable(ap);
	c.ok(darkBad.length === 0, 'page-load overview dark: readable ' + JSON.stringify(darkBad));
	await theme(ap, 'light');

	// slowest pages
	await clickTab(ap, 'Slowest pages');
	await ap.waitForFunction(() => /e2e-pl-slow/.test(document.body.innerText), null, { timeout: 8000 }).catch(() => {});
	const rows = (await ap.locator('.uxs-table tbody tr').allInnerTexts()).map((r) => r.split('	')[0].trim());
	c.ok(rows[0] === '/e2e-pl-huge' && rows.includes('/e2e-pl-slow') && rows.indexOf('/e2e-pl-slow') < (rows.indexOf('/') < 0 ? 99 : rows.indexOf('/')), `ranked by load time, slowest first: ${rows.join(', ')}`);
	c.ok((await ap.locator('.uxs-table tbody tr').filter({ hasText: '/e2e-pl-slow' }).locator('.uxs-badge.is-danger').count()) === 1, 'slow page (9 s avg 8 s) has red badge (> 2x slow_ms)');
	await ap.locator('select[aria-label="Rank by"]').selectOption('ttfb_ms');
	await settle(ap);
	c.ok((await ap.locator('.uxs-table tbody tr').count()) > 0, 'ranking by TTFB works');
	await ap.locator('select[aria-label="Minimum measurements"]').selectOption('10');
	await settle(ap);
	c.ok(/No measurements for this period/.test(await ap.locator('#uxs-root').innerText()), 'minimum 10 measurements: empty state');
	await ap.locator('select[aria-label="Minimum measurements"]').selectOption('1');
	await settle(ap);
	await shot(ap, `trk-${site.key}-pageload-pages`);

	// settings: validation + effect on the frontend
	await clickTab(ap, 'Settings');
	await formSet(ap, 'sample_rate', 150);
	await formSave(ap);
	await sleep(700);
	let saved = JSON.parse(getSettings(site, mod) ?? '{}');
	c.ok(Number(saved.sample_rate) !== 150, `sample_rate 150 rejected/clamped (stored ${saved.sample_rate})`);
	await formSet(ap, 'slow_ms', 10);
	await formSave(ap);
	await sleep(700);
	saved = JSON.parse(getSettings(site, mod) ?? '{}');
	c.ok(Number(saved.slow_ms) !== 10, `slow_ms below minimum rejected/clamped (stored ${saved.slow_ms})`);
	await formSet(ap, 'sample_rate', 0);
	await formSet(ap, 'slow_ms', 3000);
	await formSave(ap);
	c.ok(await toastText(ap, /saved/i), 'valid settings saved');
	const off = await newCtx(browser);
	const op = await off.ctx.newPage();
	await op.goto(`${site.url}/`);
	const rate = await op.locator('script[src*="page-load.js"]').getAttribute('data-rate').catch(() => null);
	c.ok(rate === null || Number(rate) === 0, `sample_rate 0: beacon switched off (data-rate=${rate})`);
	await off.ctx.close();
	await clickTab(ap, 'Overview');
	await ap.waitForFunction(() => /Measuring is switched off/.test(document.body.innerText), null, { timeout: 12000 }).catch(() => {});
	c.ok(/Measuring is switched off/.test(await ap.locator('#uxs-root').innerText()), 'overview warns when sampling is 0 %');

	// delete data with confirmation
	await ap.getByRole('button', { name: /Delete data/ }).click();
	await ap.locator('.uxs-modal').waitFor();
	c.ok(/Delete all stored measurements/.test(await ap.locator('.uxs-modal').innerText()), 'confirm dialog shown');
	await ap.locator('.uxs-modal button').filter({ hasText: /^Cancel$/ }).click();
	c.ok(count() > 0, 'cancel keeps the data');
	await ap.getByRole('button', { name: /Delete data/ }).click();
	await ap.locator('.uxs-modal button').filter({ hasText: /^Delete$/ }).click();
	c.ok(await toastText(ap, /deleted/i), 'delete toast');
	c.ok(count() === 0, 'measurements removed');
	await sleep(500);
	await ap.waitForFunction(() => /No measurements yet/.test(document.body.innerText), null, { timeout: 12000 }).catch(() => {});
	c.ok(/No measurements yet/.test(await ap.locator('#uxs-root').innerText()), 'empty state after deleting');

	// Czech
	await adm.ctx.close();
	mergeSettings(site, mod, { sample_rate: 100 });
	await vis.ctx.request.post(ep, { data: { ...good, path: '/e2e-pl-cs' }, headers: { Origin: origin } });
	setLang(site, 'cs-CZ');
	const cz = await newCtx(browser, { locale: 'cs-CZ' });
	const cp = await login(cz.ctx, site, pw);
	await studio(cp, site, mod);
	await cp.waitForFunction(() => /měření|Zatím/i.test(document.body.innerText));
	const ct = await cp.locator('#uxs-root').innerText();
	c.ok(/Načtení stránky|Doba načtení/.test(ct) && /Zařízení/.test(ct), 'cs-CZ: overview translated');
	c.ok(!/Page load \(median\)|Devices|Trend \(average/.test(ct), 'cs-CZ: no leftover English');
	await shot(cp, `trk-${site.key}-pageload-cs`);
	await clickTab(cp, 'Nejpomalejší');
	await cp.waitForSelector('.uxs-table tbody tr');
	const cpt = await cp.locator('#uxs-root').innerText();
	c.ok(!/Server response|Average load time|Slowest visit/.test(cpt), 'cs-CZ: slowest pages translated');
	await cz.ctx.close();
	setLang(site, 'en-GB');
	await vis.ctx.close();
	wipe();
	return [...adm.issues, ...vis.issues, ...cz.issues, ...off.issues, ...botCtx.issues.filter(() => false)];
}

async function aiMdPart(browser, site, pw, c, art) {
	const mod = 'ai-markdown';
	const base = site.url;
	const llms = `${base}/index.php/llms.txt`;
	const wipeLog = () => sql(site, 'DELETE FROM jos_uxstudio_ai_markdown_log');
	wipeLog();
	sql(site, 'DELETE FROM jos_uxstudio_ai_markdown_cache');
	mergeSettings(site, mod, { enable_llms_txt: true, enable_llms_full: true, enable_sitemap: true, enable_markdown: true, enable_accept: true, link_style: 'query', exclude_ids: '', exclude_categories: '', enable_logging: true, cache_ttl: '0' });

	const vis = await newCtx(browser, { locale: 'cs-CZ' });
	const api = vis.ctx.request;
	const get = (url, headers = {}) => api.get(url, { headers, maxRedirects: 0 });
	const aMd = `${base}/index.php?option=com_content&view=article&id=${art.alpha}&catid=2&format=md`;

	let r = await get(llms);
	let txt = await r.text();
	c.ok(r.status() === 200 && /text\/plain/.test(r.headers()['content-type']), `llms.txt 200 text/plain (${r.status()})`);
	c.ok(txt.startsWith('# '), 'llms.txt starts with "# site name"');
	c.ok(txt.includes(`${MARK} Alpha`) && txt.includes(`${MARK} Beta`), 'llms.txt lists both new articles');
	c.ok(/## Optional/.test(txt) && /llms-full\.txt/.test(txt), 'llms.txt has the Optional section');
	r = await get(`${base}/index.php/llms-full.txt`);
	txt = await r.text();
	c.ok(r.status() === 200 && txt.includes('Sekce Alfa') && txt.includes('| Sloupec A | Sloupec B |'), 'llms-full.txt contains full article text with a GFM table');
	r = await get(`${base}/index.php/ai-sitemap.md`);
	txt = await r.text();
	c.ok(r.status() === 200 && /markdown/.test(r.headers()['content-type']) && txt.includes(`${MARK} Alpha`), 'ai-sitemap.md lists the article');

	// markdown of an article via ?format=md
	r = await get(aMd);
	txt = await r.text();
	const h = r.headers();
	c.ok(r.status() === 200 && /markdown/.test(h['content-type']), `article ?format=md -> 200 markdown (${r.status()} ${h['content-type']})`);
	c.ok(txt.startsWith('---') && txt.includes(`title: ${MARK} Alpha`), 'markdown has YAML front matter with title');
	c.ok(txt.includes(`# ${MARK} Alpha`) && txt.includes('## Sekce Alfa'), 'markdown has heading structure');
	c.ok(txt.includes('**tučným**') && /\[odkazem\]\(http/.test(txt) && txt.includes('- první položka'), 'markdown converts bold, absolute link and list');
	c.ok(txt.includes('| a1 | b1 |'), 'markdown converts the table');
	c.ok(!/<script|<table|<ul/.test(txt), 'no leftover HTML in markdown');
	c.ok(h['x-robots-tag'] === 'noindex' && h['x-content-type-options'] === 'nosniff' && !h['set-cookie'], 'security headers present, no cookie');
	c.ok(!!h['etag'] && /max-age/.test(h['cache-control'] ?? '') && /canonical/.test(h['link'] ?? ''), 'ETag, Cache-Control and canonical Link present');
	r = await get(aMd, { 'If-None-Match': h['etag'] });
	c.ok(r.status() === 304, `If-None-Match -> 304 (${r.status()})`);
	r = await get(`${base}/index.php?option=com_content&view=article&id=${art.alpha}&catid=2`, { Accept: 'text/markdown' });
	c.ok(/markdown/.test(r.headers()['content-type'] ?? ''), `Accept: text/markdown negotiates markdown (${r.headers()['content-type']})`);
	r = await get(`${base}/index.php?option=com_content&view=article&id=${art.alpha}&catid=2`, { Accept: 'text/html,*/*;q=0.8' });
	c.ok(/html/.test(r.headers()['content-type'] ?? ''), 'browser Accept still gets HTML');
	r = await get(`${base}/index.php?option=com_content&view=article&id=999999&catid=2&format=md`);
	c.ok(r.status() === 404, `unknown article markdown -> 404 (${r.status()})`);

	// <link rel=alternate> on the article page
	const pg = await vis.ctx.newPage();
	await pg.goto(`${base}/index.php?option=com_content&view=article&id=${art.alpha}&catid=2`);
	c.ok((await pg.locator('link[rel=alternate][type="text/markdown"]').count()) === 1, 'article page advertises <link rel=alternate type=text/markdown>');

	// bot log
	const bot = await newCtx(browser, { ua: GPTBOT_UA });
	await bot.ctx.request.get(aMd);
	await bot.ctx.request.get(llms);
	await bot.ctx.close();
	c.ok(Number(sql(site, "SELECT COUNT(*) FROM jos_uxstudio_ai_markdown_log WHERE bot_name LIKE '%GPTBot%'")) >= 2, 'GPTBot requests are logged');
	await api.get(aMd);
	c.ok(Number(sql(site, 'SELECT COUNT(*) FROM jos_uxstudio_ai_markdown_log')) === 2, 'human (non-bot) request is not logged');

	// admin UI
	const adm = await newCtx(browser, { locale: 'en-GB' });
	const ap = await login(adm.ctx, site, pw);
	await studio(ap, site, mod);
	await ap.waitForSelector('.uxs-card__num');
	let body = await ap.locator('#uxs-root').innerText();
	c.ok(/Public articles/.test(body) && /Addresses for AI/.test(body) && /llms\.txt/.test(body), 'overview rendered');
	c.ok(/GPTBot/.test(body), 'overview "Who reads the site" shows GPTBot');
	await shot(ap, `trk-${site.key}-aimd-light`);
	c.ok((await readable(ap)).length === 0, 'ai-markdown overview light: readable ' + JSON.stringify(await readable(ap)));
	// preview modal
	await ap.locator('.uxs-status-list__row').filter({ hasText: 'llms.txt' }).first().getByRole('button', { name: /Preview/ }).click();
	await ap.locator('.uxs-modal').waitFor();
	await ap.waitForFunction(() => /^#/.test(document.querySelector('.uxs-modal pre')?.textContent ?? ''));
	c.ok((await ap.locator('.uxs-modal pre').innerText()).includes(MARK), 'preview modal shows llms.txt content');
	await shot(ap, `trk-${site.key}-aimd-preview-light`);
	await ap.locator('.uxs-modal').getByRole('button', { name: 'Close' }).last().click();
	await ap.locator('.uxs-modal').waitFor({ state: 'detached' });
	// rebuild / empty cache buttons
	await ap.getByRole('button', { name: /Rebuild everything/ }).click();
	c.ok(await toastText(ap, /Cache cleared/i), 'rebuild everything toast');
	await ap.getByRole('button', { name: /Empty the cache/ }).click();
	c.ok(await toastText(ap, /emptied/i), 'empty the cache toast');
	await theme(ap, 'dark');
	await ap.waitForSelector('.uxs-card__num');
	await shot(ap, `trk-${site.key}-aimd-dark`);
	const darkBad = await readable(ap);
	c.ok(darkBad.length === 0, 'ai-markdown overview dark: readable ' + JSON.stringify(darkBad));
	await theme(ap, 'light');

	// articles tab
	await clickTab(ap, 'Articles');
	await ap.waitForSelector('.uxs-table tbody tr');
	// other agents add articles too, so narrow the list to this scenario's articles first
	await ap.locator('input[type=search]').fill(MARK);
	await ap.getByRole('button', { name: 'Search', exact: true }).click();
	await settle(ap);
	await ap.waitForFunction((m) => document.body.innerText.includes(m + ' Beta'), MARK, { timeout: 8000 }).catch(() => {});
	body = await ap.locator('#uxs-root').innerText();
	c.ok(body.includes(`${MARK} Alpha`) && body.includes(`${MARK} Beta`), 'articles tab lists the new articles');
	await ap.locator('input[type=search]').fill('zzzz-nothing-matches');
	await ap.getByRole('button', { name: 'Search', exact: true }).click();
	await settle(ap);
	await ap.waitForFunction(() => /No public articles/.test(document.body.innerText), null, { timeout: 12000 }).catch(() => {});
	c.ok(/No public articles/.test(await ap.locator('#uxs-root').innerText()), 'search without result: empty state');
	await ap.locator('input[type=search]').fill(`${MARK} Alpha`);
	await ap.getByRole('button', { name: 'Search', exact: true }).click();
	await settle(ap);
	const rowA = ap.locator('.uxs-table tbody tr').filter({ hasText: `${MARK} Alpha` });
	await rowA.first().waitFor({ timeout: 12000 }).catch(() => {});
	c.ok((await rowA.count()) === 1, 'search finds Alpha');
	await rowA.getByRole('button', { name: /Preview/ }).click();
	await ap.waitForFunction(() => /Sekce Alfa/.test(document.querySelector('.uxs-modal pre')?.textContent ?? ''));
	c.ok(/Sekce Alfa/.test(await ap.locator('.uxs-modal pre').innerText()) && /\*\*tučným\*\*/.test(await ap.locator('.uxs-modal pre').innerText()), 'article preview modal shows converted markdown (heading and bold)');
	await shot(ap, `trk-${site.key}-aimd-article-preview`);
	await ap.locator('.uxs-modal').getByRole('button', { name: 'Close' }).last().click();
	await rowA.getByRole('button', { name: 'Rebuild' }).click();
	await settle(ap);

	// bot log tab
	await clickTab(ap, 'Bot log');
	await ap.waitForSelector('.uxs-table tbody tr');
	body = await ap.locator('#uxs-root').innerText();
	c.ok(/GPTBot/.test(body) && /\?format=md/.test(body), 'bot log shows GPTBot and the way it asked');
	await shot(ap, `trk-${site.key}-aimd-log`);
	await ap.locator('input[type=search]').fill('nothing-like-this');
	await ap.getByRole('button', { name: 'Search', exact: true }).click();
	await settle(ap);
	await ap.waitForFunction(() => /No AI bot has requested/.test(document.body.innerText), null, { timeout: 12000 }).catch(() => {});
	c.ok(/No AI bot has requested/.test(await ap.locator('#uxs-root').innerText()), 'bot log search without result: empty state');
	await ap.locator('input[type=search]').fill('');
	await ap.getByRole('button', { name: 'Search', exact: true }).click();
	await settle(ap);
	await ap.locator('.uxs-table tbody tr').first().getByRole('button', { name: 'Delete entry' }).click();
	await settle(ap);
	await until(() => Number(sql(site, 'SELECT COUNT(*) FROM jos_uxstudio_ai_markdown_log')) === 1, 4000);
	c.ok(Number(sql(site, 'SELECT COUNT(*) FROM jos_uxstudio_ai_markdown_log')) === 1, 'single log entry deleted');
	await ap.getByRole('button', { name: /Clear all/ }).click();
	await ap.locator('.uxs-modal').waitFor();
	await ap.locator('.uxs-modal button').filter({ hasText: /^Delete all$/ }).click();
	await until(() => Number(sql(site, 'SELECT COUNT(*) FROM jos_uxstudio_ai_markdown_log')) === 0, 4000);
	c.ok(Number(sql(site, 'SELECT COUNT(*) FROM jos_uxstudio_ai_markdown_log')) === 0, 'clear whole log (with confirm)');
	await sleep(500);
	await ap.waitForFunction(() => /No AI bot has requested/.test(document.body.innerText), null, { timeout: 12000 }).catch(() => {});
	c.ok(/No AI bot has requested/.test(await ap.locator('#uxs-root').innerText()), 'bot log empty state after clearing');

	// robots tab
	await clickTab(ap, 'robots.txt');
	await ap.waitForSelector('.uxs-table tbody tr');
	const snippetAll = await ap.locator('pre.uxs-code-editor').innerText();
	await ap.locator('select[aria-label=Policy]').selectOption('block_all');
	await settle(ap);
	await sleep(300);
	const snippetBlock = await ap.locator('pre.uxs-code-editor').innerText();
	c.ok(snippetAll !== snippetBlock && /Disallow: \//.test(snippetBlock) && /GPTBot/.test(snippetBlock), 'robots policy block_all produces a Disallow snippet');
	await ap.locator('select[aria-label=Policy]').selectOption('block_training');
	await sleep(500);
	const snippetTrain = await ap.locator('pre.uxs-code-editor').innerText();
	c.ok(/User-agent: GPTBot\nDisallow: \//.test(snippetTrain) && /User-agent: OAI-SearchBot\nAllow: \//.test(snippetTrain), 'block_training disallows training bots (GPTBot) and allows search bots (OAI-SearchBot)');
	await shot(ap, `trk-${site.key}-aimd-robots`);

	// settings: exclude an article, then switch features off
	await clickTab(ap, 'Settings');
	await subTab(ap, 'Content');
	await formSet(ap, 'exclude_ids', String(art.beta));
	await formSet(ap, 'max_articles', 0);
	await formSave(ap);
	await sleep(700);
	let saved = JSON.parse(getSettings(site, mod) ?? '{}');
	c.ok(Number(saved.max_articles) !== 0, `max_articles=0 rejected/clamped (stored ${saved.max_articles})`);
	await formSet(ap, 'max_articles', 200);
	await formSave(ap);
	c.ok(await toastText(ap, /saved/i), 'settings saved (exclude_ids)');
	await shot(ap, `trk-${site.key}-aimd-settings`);
	r = await get(llms);
	txt = await r.text();
	c.ok(txt.includes(`${MARK} Alpha`) && !txt.includes(`${MARK} Beta`), 'excluded article disappears from llms.txt');
	r = await get(`${base}/index.php?option=com_content&view=article&id=${art.beta}&catid=2&format=md`);
	c.ok(r.status() === 404, `excluded article markdown -> 404 (${r.status()})`);
	r = await get(`${base}/index.php/llms-full.txt`);
	c.ok(!(await r.text()).includes(`${MARK} Beta`), 'excluded article not in llms-full.txt');
	await subTab(ap, 'Content');
	await formSet(ap, 'exclude_ids', '');
	await formSet(ap, 'exclude_categories', '2');
	await formSave(ap);
	await toastText(ap, /saved/i);
	r = await get(llms);
	c.ok(!(await r.text()).includes(`${MARK} Alpha`), 'excluded category hides its articles from llms.txt');
	r = await get(aMd);
	c.ok(r.status() === 404, 'article in excluded category: markdown 404');
	await formSet(ap, 'exclude_categories', '');
	await formSave(ap);
	await toastText(ap, /saved/i);
	r = await get(aMd);
	c.ok(r.status() === 200, 'category exclusion lifted: markdown back');

	await subTab(ap, 'Addresses');
	await formSet(ap, 'enable_llms_txt', false);
	await formSet(ap, 'site_name', 'E2E <b>Site</b> name');
	await formSave(ap);
	await toastText(ap, /saved/i);
	r = await get(llms);
	c.ok(r.status() === 404, `enable_llms_txt off -> llms.txt 404 (${r.status()})`);
	await clickTab(ap, 'Overview');
	await ap.waitForSelector('.uxs-card__num');
	await ap.waitForFunction(() => /Switched off/.test(document.body.innerText), null, { timeout: 12000 }).catch(() => {});
	c.ok(/Switched off/.test(await ap.locator('#uxs-root').innerText()), 'overview marks llms.txt "Switched off"');
	await clickTab(ap, 'Settings');
	await subTab(ap, 'Addresses');
	await formSet(ap, 'enable_llms_txt', true);
	await formSave(ap);
	await toastText(ap, /saved/i);
	r = await get(llms);
	txt = await r.text();
	c.ok(r.status() === 200 && txt.startsWith('# E2E Site name'), `site_name override appears in llms.txt heading with tags stripped (${txt.split('\n')[0]})`);
	c.ok(!/<b>/.test(txt.split('\n')[0]), 'HTML in site name is not passed through');
	await formSet(ap, 'enable_markdown', false);
	await formSave(ap);
	await toastText(ap, /saved/i);
	r = await get(aMd);
	c.ok(!/markdown/.test(r.headers()['content-type'] ?? ''), 'enable_markdown off: ?format=md no longer serves markdown');
	await formSet(ap, 'enable_markdown', true);
	await formSet(ap, 'site_name', '');
	await formSave(ap);
	await toastText(ap, /saved/i);

	// suffix link style
	await formSet(ap, 'link_style', 'suffix');
	await formSave(ap);
	await toastText(ap, /saved/i);
	r = await get(llms);
	txt = await r.text();
	const links = [...txt.split(/\r?\n/).filter((l) => l.includes(MARK)).join(String.fromCharCode(10)).matchAll(/\]\((http[^)\s]+)\)/g)].map((m) => m[1]).filter((u) => u.startsWith(base));
	const mdLinks = links.filter((u) => /\.md$/.test(u));
	const queryLinks = links.filter((u) => /format=md/.test(u));
	// ".md" is only possible for clean SEF URLs; non-SEF URLs (index.php?...) must fall back to ?format=md, never stay unmarked
	c.ok(links.length > 0 && links.every((u) => /\.md$/.test(u) || /format=md/.test(u)), `link_style=suffix: every page link is a .md (${mdLinks.length}) or format=md fallback (${queryLinks.length}) link`);
	if (mdLinks.length) {
		const rr = await get(mdLinks[0]);
		c.ok(rr.status() === 200 && /markdown/.test(rr.headers()['content-type'] ?? ''), `a .md suffix URL serves markdown (${rr.status()})`);
	}
	await formSet(ap, 'link_style', 'query');
	await formSave(ap);
	await toastText(ap, /saved/i);

	// content change through the Joomla UI invalidates the cache
	r = await get(aMd);
	const etag1 = r.headers()['etag'];
	await ap.goto(`${base}/administrator/index.php?option=com_content&task=article.edit&id=${art.alpha}`);
	await ap.locator('#jform_title').fill(`${MARK} Alpha PREMENOVANY`);
	await submit(ap, 'article.save');
	await submit(ap, 'article.cancel');
	r = await get(aMd);
	txt = await r.text();
	c.ok(txt.includes('PREMENOVANY') && r.headers()['etag'] !== etag1, 'edited article title shows up in markdown immediately (cache invalidated, new ETag)');
	txt = await (await get(llms)).text();
	c.ok(txt.includes('PREMENOVANY'), 'llms.txt reflects the rename');

	// unpublish / trash through the Joomla UI -> disappears
	await listAction(ap, site, art.beta, 'articles.unpublish');
	r = await get(`${base}/index.php?option=com_content&view=article&id=${art.beta}&catid=2&format=md`);
	c.ok(r.status() === 404, `unpublished article: markdown 404 (${r.status()})`);
	txt = await (await get(llms)).text();
	c.ok(!txt.includes(`${MARK} Beta`), 'unpublished article gone from llms.txt');
	await listAction(ap, site, art.beta, 'articles.publish');
	r = await get(`${base}/index.php?option=com_content&view=article&id=${art.beta}&catid=2&format=md`);
	c.ok(r.status() === 200, 'republished article: markdown is back');

	// registered-only article never leaks: access changed through the article form (fires the Joomla change events)
	await setAccess(ap, site, art.beta, 2);
	r = await get(`${base}/index.php?option=com_content&view=article&id=${art.beta}&catid=2&format=md`);
	c.ok(r.status() === 404 || r.status() === 403, `Registered-access article: no markdown for anonymous (${r.status()})`);
	txt = await (await get(llms)).text();
	c.ok(!txt.includes(`${MARK} Beta`), 'Registered-access article hidden from llms.txt');
	await setAccess(ap, site, art.beta, 1);
	r = await get(`${base}/index.php?option=com_content&view=article&id=${art.beta}&catid=2&format=md`);
	c.ok(r.status() === 200, 'access back to Public: markdown is served again');

	// script injected straight into the DB is dropped by the converter
	sql(site, `UPDATE jos_content SET introtext=CONCAT(introtext, '<script>alert("x")</script><p onclick="x()">tail</p><a href="javascript:alert(1)">bad link</a>') WHERE id=${art.beta}`);
	txt = await (await get(`${base}/index.php?option=com_content&view=article&id=${art.beta}&catid=2&format=md`)).text();
	c.ok(!/<script|alert\(/.test(txt) && !/javascript:/.test(txt) && txt.includes('tail'), 'script and javascript: links are removed from markdown');

	// trash -> permanent delete through the Joomla UI: the article disappears from every AI view
	await listAction(ap, site, art.beta, 'articles.trash');
	r = await get(`${base}/index.php?option=com_content&view=article&id=${art.beta}&catid=2&format=md`);
	c.ok(r.status() === 404, `trashed article: markdown 404 (${r.status()})`);
	txt = await (await get(llms)).text();
	c.ok(!txt.includes(`${MARK} Beta`), 'trashed article gone from llms.txt');
	await listAction(ap, site, art.beta, 'articles.delete');
	c.ok(sql(site, `SELECT COUNT(*) FROM jos_content WHERE id=${art.beta}`) === '0', 'article deleted permanently through the Joomla UI');
	r = await get(`${base}/index.php?option=com_content&view=article&id=${art.beta}&catid=2&format=md`);
	c.ok(r.status() === 404, `permanently deleted article: markdown 404 (${r.status()})`);

	// Czech administration
	await adm.ctx.close();
	setLang(site, 'cs-CZ');
	const cz = await newCtx(browser, { locale: 'cs-CZ' });
	const cp = await login(cz.ctx, site, pw);
	await studio(cp, site, mod);
	await cp.waitForSelector('.uxs-card__num');
	const ct = await cp.locator('#uxs-root').innerText();
	c.ok(/Veřejné články|Adresy pro AI/.test(ct) && !/Public articles|Addresses for AI|AI bot requests/.test(ct), 'cs-CZ: overview translated');
	await shot(cp, `trk-${site.key}-aimd-cs`);
	for (const t of ['Články', 'Log botů', 'robots.txt', 'Nastavení']) {
		const tab = cp.locator('.uxs-tabs [role=tab]').filter({ hasText: t }).first();
		if (!(await tab.count())) { c.ok(false, `cs-CZ: tab "${t}" present`); continue; }
		await tab.click();
		await settle(cp);
		await sleep(300);
		const txtCs = await cp.locator('#uxs-root').innerText();
		c.ok(!/\b(Search by title|AI bot requests|Suggested rules|Allow all AI crawlers|Bot log|Switched off|Clear all)\b/.test(txtCs), `cs-CZ: tab ${t} has no leftover English`);
		if (t === 'robots.txt') await shot(cp, `trk-${site.key}-aimd-robots-cs`);
		if (t === 'Nastavení') await shot(cp, `trk-${site.key}-aimd-settings-cs`);
	}
	await cz.ctx.close();
	setLang(site, 'en-GB');
	await vis.ctx.close();
	wipeLog();
	return [...adm.issues, ...cz.issues, ...vis.issues.filter((i) => !/HTTP (404|403|304)/.test(i))];
}

/** Error state: a failing module API must show an alert, never an endless spinner (all four overviews). */
async function errorPart(browser, site, pw, c) {
	const adm = await newCtx(browser, { locale: 'en-GB', ignore: [/status of 500/, /HTTP 500/, /Failed to load resource/] });
	const ap = await login(adm.ctx, site, pw);
	for (const id of MODS) {
		await ap.unroute(/./).catch(() => {});
		await ap.route(new RegExp(`route=${id}(%2F|/)`), (r) => r.fulfill({ status: 500, contentType: 'application/json', body: '{"error":"boom"}' }));
		await studio(ap, site, id);
		await sleep(1500);
		const alert = await ap.locator('#uxs-root [role=alert]').count();
		const spinner = await ap.locator('#uxs-root .uxs-loading, #uxs-root [aria-busy=true]').count();
		c.ok(alert > 0 && spinner === 0, `${id}: API failure shows an error message and no endless spinner (alerts=${alert}, spinners=${spinner})`);
		if (id === 'ai-markdown') await shot(ap, `trk-${site.key}-error-${id}`);
	}
	await adm.ctx.close();
	return adm.issues;
}

/* ------------------------------------------------------------------ main */

const browser = await chromium.launch({ headless: !process.argv.includes('--headed') });
let bad = 0;
for (const site of sitesFromArgv()) {
	SITE_REF.site = site;
	stash(site);
	const enabledBefore = sql(site, `SELECT CONCAT(id,'=',enabled) FROM jos_uxstudio_modules WHERE id IN (${MODS.map(lit).join(',')})`);
	const c = checker(`tracking ${site.key}`);
	const issues = [];
	const arts = { alpha: 0, beta: 0 };
	let pw = '';
	let adm;
	try {
		// leftovers of a run that was killed before its cleanup
		sql(site, "DELETE FROM jos_content WHERE title LIKE 'E2E Tracking%'");
		pw = createUser(site);
		adm = await newCtx(browser, { locale: 'en-GB' });
		const ap = await login(adm.ctx, site, pw);
		arts.alpha = await createArticle(ap, site, `${MARK} Alpha`, ALPHA_HTML);
		arts.beta = await createArticle(ap, site, `${MARK} Beta`, '<p>Druhý testovací článek.</p>');
		c.ok(arts.alpha > 0 && arts.beta > 0, `fixture articles created through the Joomla UI (${arts.alpha}, ${arts.beta})`);
		await ap.close();
		const parts = [
			['analytics', analyticsPart],
			['ptm', ptmPart],
			['pageload', pageLoadPart],
			['aimd', aiMdPart],
			['errors', errorPart],
		];
		for (const [key, fn] of parts) {
			if (!wants(key)) continue;
			try {
				issues.push(...(await fn(browser, site, pw, c, arts)).map((i) => `(${key}) ${i}`));
			} catch (e) {
				c.ok(false, `${key} part crashed: ${String(e.stack ?? e).split('\n').slice(0, 3).join(' | ')}`);
			}
		}
	} catch (e) {
		c.ok(false, `setup crashed: ${String(e.stack ?? e).split('\n').slice(0, 4).join(' | ')}`);
	} finally {
		// cleanup: restore settings and module state, remove fixtures
		unstash(site);
		for (const row of enabledBefore.split('\n').filter(Boolean)) {
			const [id, en] = row.split('=');
			sql(site, `UPDATE jos_uxstudio_modules SET enabled=${Number(en)} WHERE id=${lit(id)}`);
		}
		for (const id of [arts.alpha, arts.beta].filter(Boolean)) {
			sql(site, `DELETE FROM jos_content_frontpage WHERE content_id=${id}; DELETE FROM jos_contentitem_tag_map WHERE content_item_id=${id}; DELETE FROM jos_workflow_associations WHERE item_id=${id} AND extension='com_content.article'; DELETE FROM jos_assets WHERE name='com_content.article.${id}'; DELETE FROM jos_content WHERE id=${id};`);
		}
		sql(site, "DELETE FROM jos_content WHERE title LIKE 'E2E Tracking%'");
		dropUser(site);
		clearRate(site);
		if (adm) await adm.ctx.close().catch(() => {});
	}
	bad += c.done(issues);
}
await browser.close();
process.exit(bad ? 1 : 0);
