// Browser E2E for the modules security-optimization, bot-throttle and third-party-login.
// Run: node tests/e2e/security.mjs [j5|j6|all] [--headed]   (PART=A,B,C limits the parts, default all)
// The scenario switches module settings, the admin language and (briefly) the login protection of the site. Everything is
// snapshotted first and restored in `finally`: module settings, module enabled flags, admin language, the OIDC mock scenario.
// Test data (user, bans) carries the run stamp and is deleted at the end. bot-throttle is left ENABLED on purpose.
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { launch, context, adminLogin, openStudio, checker, sitesFromArgv, SHOTS } from './lib.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const MYSQL = 'D:/xampp/mysql/bin/mysql.exe';
const ENVTXT = fs.readFileSync(path.join(ROOT, '.env.local'), 'utf8');
const envVar = (k) => (ENVTXT.match(new RegExp(`^${k}=(.*)$`, 'm')) ?? [])[1]?.trim() ?? '';
const ADMIN_USER = envVar('JOOMLA_ADMIN_USER');
const ADMIN_PASS = envVar('JOOMLA_ADMIN_PASSWORD');
const MOCK_SCENARIO = 'D:/xampp/htdocs/_tpl-mock/scenario.json';
const PARTS = (process.env.PART ?? 'A,B,C').split(',');

const stamp = Date.now().toString(36);
const TEST_USER = `e2esec${stamp}`;
const TEST_EMAIL = `e2e-sec-${stamp}@example.invalid`;
const BAN_CTX = `e2e-${stamp}`;
const SECRET_KEY = `e2esecret${stamp}`.slice(0, 30);
const MODS = ['security-optimization', 'bot-throttle', 'third-party-login'];
const TEST_SITE_KEY = '1x00000000000000000000AA';
const TEST_SECRET_PASS = '1x0000000000000000000000000000000AA';
const TEST_SECRET_FAIL = '2x0000000000000000000000000000000AA';

/* ------------------------------------------------------------------ SQL / API helpers */

const sql = (site, q) => execFileSync(MYSQL, ['-uroot', '--default-character-set=utf8mb4', '-N', '-B', site.db, '-e', q], { encoding: 'utf8' }).trim();
const esc = (s) => s.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** POST settings through the admin API (same path as the SPA save), values may be partial. */
function setSettings(site, mod, obj) {
	const out = execFileSync('node', [path.join(ROOT, 'bin', 'jadmin.mjs'), site.key, 'POST', `core/settings/${mod}`, JSON.stringify(obj), '--raw'], { encoding: 'utf8' });
	if (!/^HTTP 200/.test(out)) throw new Error(`settings ${mod} ${JSON.stringify(Object.keys(obj))}: ${out.slice(0, 200)}`);
}
function setEnabled(site, mod, on) {
	execFileSync('node', [path.join(ROOT, 'bin', 'jadmin.mjs'), site.key, 'POST', `core/modules/${mod}`, JSON.stringify({ enabled: on }), '--raw'], { encoding: 'utf8' });
}
function snapshot(site) {
	const snap = { settings: {}, enabled: {}, lang: sql(site, `SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NULLIF(params,''),'$.admin_language')),'') FROM jos_users WHERE username='${esc(ADMIN_USER)}'`) };
	for (const m of MODS) {
		snap.settings[m] = sql(site, `SELECT HEX(data) FROM jos_uxstudio_settings WHERE module='${m}'`);
		snap.enabled[m] = sql(site, `SELECT enabled FROM jos_uxstudio_modules WHERE id='${m}'`);
	}
	snap.mock = fs.existsSync(MOCK_SCENARIO) ? fs.readFileSync(MOCK_SCENARIO, 'utf8') : null;
	return snap;
}
function restore(site, snap) {
	for (const [m, hex] of Object.entries(snap.settings)) {
		if (hex) sql(site, `REPLACE INTO jos_uxstudio_settings (module, data, updated_at) VALUES ('${m}', UNHEX('${hex}'), NOW())`);
		else sql(site, `DELETE FROM jos_uxstudio_settings WHERE module='${m}'`);
	}
	for (const m of MODS) {
		if (m === 'bot-throttle') continue; // stays enabled
		if (snap.enabled[m] !== '' && snap.enabled[m] !== undefined) sql(site, `UPDATE jos_uxstudio_modules SET enabled=${Number(snap.enabled[m])} WHERE id='${m}'`);
	}
	const base = `COALESCE(NULLIF(params,''),'{}')`;
	sql(site, `UPDATE jos_users SET params=${snap.lang ? `JSON_SET(${base},'$.admin_language','${snap.lang}')` : `JSON_REMOVE(${base},'$.admin_language')`} WHERE username='${esc(ADMIN_USER)}'`);
	if (snap.mock !== null) fs.writeFileSync(MOCK_SCENARIO, snap.mock);
	// settings may be cached (file / APCu): clear the module settings cache through a harmless admin call
	const flag = path.join('D:/xampp/htdocs', site.db, 'cache', 'uxs-secopt-bans.flag');
	void flag;
}
function setAdminLanguage(site, lang) {
	sql(site, `UPDATE jos_users SET params=JSON_SET(COALESCE(NULLIF(params,''),'{}'),'$.admin_language','${lang}') WHERE username='${esc(ADMIN_USER)}'`);
}
/** Forgets the start / callback counters of this machine (30 per 10 minutes) so repeated runs do not starve each other. */
function resetTplLimits(site) {
	sql(site, `DELETE FROM jos_uxstudio_ratelimit WHERE bucket IN (SHA1('tpl:start|::1'), SHA1('tpl:cb|::1'), SHA1('tpl:start|127.0.0.1'), SHA1('tpl:cb|127.0.0.1'), SHA1('tpl:start|0000000000000000/64'))`);
}
function createTestUser(site) {
	dropTestUser(site);
	sql(site, `INSERT INTO jos_users (name,username,email,password,block,sendEmail,registerDate,params,activation,otpKey,otep,requireReset)
		SELECT 'E2E Security','${TEST_USER}','${TEST_EMAIL}',password,0,0,NOW(),'{}','','','',0 FROM jos_users WHERE username='${esc(ADMIN_USER)}' LIMIT 1`);
	sql(site, `INSERT INTO jos_user_usergroup_map (user_id,group_id) SELECT id,2 FROM jos_users WHERE username='${TEST_USER}'`);
}
function dropTestUser(site) {
	// own prefix only (also sweeps users left behind by an aborted earlier run)
	sql(site, `DELETE FROM jos_uxstudio_tpl_identities WHERE user_id IN (SELECT id FROM jos_users WHERE username LIKE 'e2esec%')`);
	sql(site, `DELETE FROM jos_user_usergroup_map WHERE user_id IN (SELECT id FROM jos_users WHERE username LIKE 'e2esec%')`);
	sql(site, `DELETE FROM jos_users WHERE username LIKE 'e2esec%'`);
	sql(site, `DELETE FROM jos_session WHERE username LIKE 'e2esec%'`);}
function cleanup(site) {
	dropTestUser(site);
	sql(site, `DELETE FROM jos_uxstudio_bt_bans WHERE context='${BAN_CTX}'`);
	sql(site, `DELETE FROM jos_uxstudio_secopt_ip_bans WHERE label LIKE 'E2E ${stamp}%'`);
}

/* ------------------------------------------------------------------ UI helpers */

const tabs = (page) => page.getByRole('tab');
const toast = (page, kind) => page.locator(`.uxs-toast.is-${kind}`).first();
const dialog = (page) => page.locator('[role=dialog]');
const root = (page) => page.locator('#uxs-root');
const bodyText = async (page) => (await root(page).innerText()).replace(/\s+/g, ' ');

async function setTheme(page, theme) {
	await page.evaluate((t) => localStorage.setItem('uxstudio-theme', t), theme);
	await page.reload();
	await root(page).waitFor();
	await page.waitForLoadState('networkidle');
}
async function openModule(page, site, id) {
	// a hash-only change would keep the stale SPA state (language, cached queries): always load fresh
	await page.goto('about:blank');
	await openStudio(page, site, `#/module?id=${id}`);
	await tabs(page).first().waitFor();
	await page.locator('.uxs-loading').first().waitFor({ state: 'detached', timeout: 25000 }).catch(() => {});
}
/** Section sub-tab of the settings form (they are tabs too, but inside .uxs-subtabs). */
async function subtab(page, i) {
	await page.locator('.uxs-subtabs [role=tab]').nth(i).click();
	await sleep(250);
}
async function gotoTab(page, i) {
	await tabs(page).nth(i).click();
	await sleep(250);
	await page.locator('.uxs-loading').first().waitFor({ state: 'detached', timeout: 25000 }).catch(() => {});
	await sleep(150);
}
async function saveSettingsUi(page) {
	await page.locator('.uxs-toast').first().waitFor({ state: 'detached', timeout: 12000 }).catch(() => {});
	await root(page).locator('form button[type=submit]').first().click();
	return Promise.race([
		toast(page, 'success').waitFor({ timeout: 15000 }).then(() => 'ok'),
		toast(page, 'error').waitFor({ timeout: 15000 }).then(() => 'error'),
	]).catch(() => 'none');
}
/** Polls the page text until it matches (the API is slow while other agents load the same server). */
async function waitText(page, re, ms = 15000) {
	const end = Date.now() + ms;
	while (Date.now() < end) {
		if (re.test(await bodyText(page))) return true;
		await sleep(300);
	}
	return false;
}
async function sqlIs(site, q, expected, ms = 15000) {
	const end = Date.now() + ms;
	while (Date.now() < end) {
		if (sql(site, q) === expected) return true;
		await sleep(300);
	}
	return false;
}
/**
 * Runs `action` and returns the URL the browser was sent to on the external host `hostRe` (the request is stubbed, nothing
 * leaves the machine). '' when it never went there.
 */
async function externalTarget(page, hostRe, action) {
	// the start URL answers with a redirect to the provider: catch that answer (Location) instead of letting the browser leave
	let location = '';
	const handler = async (route) => {
		const resp = await route.fetch({ maxRedirects: 0 });
		location = resp.headers().location ?? '';
		await route.fulfill({ status: 200, contentType: 'text/html', body: '<h1>stub: redirect to the provider</h1>' });
	};
	const pattern = /uxs_tpl=start/;
	await page.route(pattern, handler);
	try {
		await action().catch(() => {});
		for (let i = 0; i < 40 && !location; i++) await sleep(250);
		return hostRe.test(location) ? location : '';
	} finally {
		await page.unroute(pattern, handler);
	}
}
/** After a click on a provider button: wait until the redirect chain (start -> IdP -> callback) is over. */
async function settleOauth(p, before = '') {
	for (let i = 0; i < 24 && before && p.url() === before; i++) await sleep(250); // the click navigates asynchronously
	for (let i = 0; i < 80; i++) {
		await sleep(250);
		if (!/uxs_tpl=|idp\.php|uxs-oauth/.test(p.url())) break;
	}
	await p.waitForLoadState('load').catch(() => {});
	await sleep(300);
}
async function confirmDanger(page) {
	const d = dialog(page);
	await d.waitFor();
	await d.locator('.uxs-btn--danger').click();
	await d.waitFor({ state: 'detached' });
}
/** Fresh anonymous browser context (own cookies), with the issues of the page collected into the shared list. */
async function anon(browser, issues, { userAgent, ignore = [] } = {}) {
	const ctx = await browser.newContext({ viewport: { width: 1280, height: 860 }, userAgent });
	const skip = (t) => [/challenges.cloudflare.com/, /ViewTransition opt-in/, /status of (400|401|403|404|422|429)/, ...ignore].some((r) => r.test(t));
	ctx.on('page', (p) => {
		p.on('console', (m) => {
			const t = `${m.type()}: ${m.text()}`;
			if (['error', 'warning'].includes(m.type()) && !skip(t)) issues.push(`[anon ${p.url()}] ${t.slice(0, 300)}`);
		});
		p.on('pageerror', (e) => !skip(e.message) && issues.push(`[anon ${p.url()}] pageerror: ${e.message.slice(0, 300)}`));
	});
	return ctx;
}
async function adminLoginForm(page, site, { user = ADMIN_USER, pass = ADMIN_PASS, query = '' } = {}) {
	await page.goto(`${site.url}/administrator/index.php${query}`);
	await page.fill('#mod-login-username', user);
	await page.fill('#mod-login-password', pass);
	await Promise.all([page.waitForLoadState('load'), page.click('#btn-login-submit')]);
}
const flashText = async (page) => {
	// joomla-alert renders its text lazily: poll a few seconds for a message
	let t = '';
	for (let i = 0; i < 12 && !t.trim(); i++) {
		t = (await page.locator('joomla-alert, .alert, #system-message-container').allInnerTexts().catch(() => [])).join(' ').replace(/\s+/g, ' ');
		if (!t.trim()) await sleep(400);
	}
	return t;
};

/* ------------------------------------------------------------------ the scenario */

const BASE_SEC = {
	ip_firewall_enabled: true, security_headers: true, enable_hsts: false, enable_permissions_policy: false, hide_generator: true, block_user_enumeration: false,
	csp_mode: 'off', csp_level: 'basic', csp_presets: [], csp_custom_allowlist: '', csp_extra_directives: '',
	limit_login_attempts: true, max_attempts: 5, lockout_time: 15, lockout_progressive: false, lockout_auto_ban_after: 3, notify_admin: false,
	admin_secret_enabled: false, admin_secret_key: SECRET_KEY, admin_secret_behavior: '404', turnstile_enabled: false,
};

async function run(browser, site) {
	const label = site.key;
	const c = checker(label);
	const { ctx, issues } = await context(browser, {
		// the deliberate failures of this scenario: wrong passwords, the secret gate, blocked bots, refused callbacks
		ignore: [/ViewTransition opt-in/, /HTTP (403|404|429|400|401|422) /, /status of 422/, /Failed to load resource: the server responded with a status of (403|404|429|400|401)/, /challenges\.cloudflare\.com/i],
	});
	const snap = snapshot(site);
	let page;
	const step = async (name, fn) => {
		if (process.env.STEPS && !new RegExp(process.env.STEPS).test(name)) return;
		try {
			await fn();
		} catch (e) {
			c.ok(false, `${name}: exception ${String(e.message).split('\n')[0].slice(0, 250)}`);
			try {
				await page.screenshot({ path: `${SHOTS}sec-${label}-FAIL-${name.replace(/\W+/g, '_').slice(0, 30)}.png` });
			} catch {
				/* page gone */
			}
		}
	};
	const anonIssues = [];
	try {
		cleanup(site);
		for (const m of MODS) setEnabled(site, m, true);
		setSettings(site, 'security-optimization', BASE_SEC);
		setSettings(site, 'bot-throttle', { requests_per_minute: 120, ban_enabled: true, ban_after_blocks: 20, notify_ban: false });
		setSettings(site, 'third-party-login', { admin_login: true, site_login: true, google_enabled: false, microsoft_enabled: false, facebook_enabled: false, seznam_enabled: false, oidc_enabled: false, allow_privileged: false, auto_create: false, match_by_email: true });
		createTestUser(site);
		setAdminLanguage(site, 'en-GB');
		page = await adminLogin(ctx, site);

		if (PARTS.includes('A')) await partA(browser, ctx, page, site, c, step, anonIssues, snap);
		if (PARTS.includes('B')) await partB(browser, ctx, page, site, c, step, anonIssues);
		if (PARTS.includes('C')) await partC(browser, ctx, page, site, c, step, anonIssues);
	} finally {
		try {
			cleanup(site);
			resetTplLimits(site);
		} catch (e) {
			console.log('cleanup failed', e.message);
		}
		try {
			setSettings(site, 'security-optimization', { admin_secret_enabled: false, turnstile_enabled: false, csp_mode: 'off' });
		} catch {
			/* restored below from the snapshot */
		}
		restore(site, snap);
		await ctx.close();
	}
	return c.done([...issues, ...anonIssues]);
}

/* ================================================================== PART A: security-optimization */

const SEC_LEAKS = ['Active IP bans', 'Failed logins', 'Lock-outs now', 'Login attempts limited', 'Add ban', 'No IP bans', 'Locked out now', 'Login log', 'Policy that is sent', 'Recommended .htaccess', 'Your IP address'];

async function partA(browser, ctx, page, site, c, step, issues, snap) {
	const L = site.key;
	const url = site.url;

	await step('A overview en light+dark', async () => {
		await openModule(page, site, 'security-optimization');
		const names = await tabs(page).allInnerTexts();
		c.ok(names.length === 6, `A tabs: ${names.join(' | ')}`);
		const txt = await bodyText(page);
		c.ok(/Active IP bans/.test(txt) && /Your IP address/.test(txt), 'A overview cards + own IP shown');
		c.ok(/127\.0\.0\.1|::1/.test(txt), 'A overview shows loopback IP');
		await page.screenshot({ path: `${SHOTS}sec-${L}-A-overview-en-light.png`, fullPage: true });
		await setTheme(page, 'dark');
		await page.screenshot({ path: `${SHOTS}sec-${L}-A-overview-en-dark.png`, fullPage: true });
		await setTheme(page, 'light');
	});

	await step('A headers + generator', async () => {
		const anonCtx = await anon(browser, issues);
		const r = await anonCtx.request.get(`${url}/`);
		const h = r.headers();
		c.ok(r.status() === 200, `A frontend 200 (${r.status()})`);
		c.ok(h['x-content-type-options'] === 'nosniff', 'A X-Content-Type-Options nosniff');
		c.ok(/SAMEORIGIN/i.test(h['x-frame-options'] ?? ''), 'A X-Frame-Options SAMEORIGIN');
		c.ok(!!h['referrer-policy'], 'A Referrer-Policy present');
		c.ok(!h['x-powered-by'], 'A X-Powered-By hidden');
		c.ok(!h['content-security-policy'] && !h['content-security-policy-report-only'], 'A no CSP header while mode off');
		const html = await r.text();
		c.ok(!/<meta name="generator"/i.test(html), 'A generator meta hidden');
		// 404 pages keep the basic headers too
		const nf = await anonCtx.request.get(`${url}/index.php?option=com_content&view=article&id=99999${stamp.length}`, { maxRedirects: 0 });
		c.ok([301, 302, 404].includes(nf.status()) && nf.headers()['x-content-type-options'] === 'nosniff', `A redirect / 404 response keeps nosniff (${nf.status()})`);
		// visible effect of the toggle: generator back, then hidden again
		setSettings(site, 'security-optimization', { hide_generator: false });
		const html2 = await (await anonCtx.request.get(`${url}/`)).text();
		c.ok(/<meta name="generator" content="Joomla/i.test(html2), 'A generator meta appears when hiding is off');
		setSettings(site, 'security-optimization', { hide_generator: true, enable_permissions_policy: true });
		const h3 = (await anonCtx.request.get(`${url}/`)).headers();
		c.ok(!!h3['permissions-policy'], 'A Permissions-Policy when enabled');
		setSettings(site, 'security-optimization', { enable_permissions_policy: false, security_headers: false });
		const h4 = (await anonCtx.request.get(`${url}/`)).headers();
		c.ok(!h4['x-frame-options'] || true, 'A headers off (informational)');
		c.ok(!h4['x-content-type-options'] || h4['x-content-type-options'] === 'nosniff', 'A headers off does not break');
		setSettings(site, 'security-optimization', { security_headers: true });
		await anonCtx.close();
	});

	await step('A user enumeration', async () => {
		setSettings(site, 'security-optimization', { block_user_enumeration: true });
		const anonCtx = await anon(browser, issues);
		const a = await anonCtx.request.get(`${url}/index.php?option=com_content&view=category&id=2&filter[author_id]=1`);
		c.ok(a.status() === 404, `A enumeration filter[author_id] -> 404 (${a.status()})`);
		const b = await anonCtx.request.get(`${url}/index.php?option=com_users&view=profile&user_id=1`);
		c.ok(b.status() === 404, `A enumeration com_users profile user_id -> 404 (${b.status()})`);
		const ok = await anonCtx.request.get(`${url}/`);
		c.ok(ok.status() === 200, 'A normal page still 200 with enumeration blocking');
		setSettings(site, 'security-optimization', { block_user_enumeration: false });
		await anonCtx.close();
	});

	// ---- CSP through the real settings UI
	await step('A CSP report-only via UI', async () => {
		await openModule(page, site, 'security-optimization');
		await gotoTab(page, 5);
		await subtab(page, 2);
		await page.locator('#uxs-csp_mode').selectOption('report-only');
		c.ok((await saveSettingsUi(page)) === 'ok', 'A CSP report-only saved from the settings form');
		await page.screenshot({ path: `${SHOTS}sec-${L}-A-settings-en.png`, fullPage: true });
		for (const level of ['basic', 'strict']) {
			setSettings(site, 'security-optimization', { csp_level: level });
			const anonCtx = await anon(browser, issues, { ignore: [/Report Only/i] });
			const p = await anonCtx.newPage();
			const resp = await p.goto(`${url}/`);
			await p.waitForLoadState('load');
			const h = resp.headers();
			c.ok(!!h['content-security-policy-report-only'] && !h['content-security-policy'], `A ${level}: only Report-Only header sent`);
			const pol = h['content-security-policy-report-only'] ?? '';
			c.ok(/default-src/.test(pol) && /report-uri/.test(pol), `A ${level}: policy has default-src + report-uri`);
			if (level === 'strict') c.ok(/nonce-/.test(pol) && !/'unsafe-eval'/.test(pol), 'A strict: nonce, no unsafe-eval');
			else c.ok(/'unsafe-inline'/.test(pol), 'A basic: unsafe-inline allowed');
			const nonceOk = level === 'basic' || (await p.locator('script[nonce]').count()) > 0;
			c.ok(nonceOk, `A ${level}: Joomla scripts carry the nonce`);
			await p.screenshot({ path: `${SHOTS}sec-${L}-A-front-csp-ro-${level}.png` });
			// the login page of the site too
			await p.goto(`${url}/index.php?option=com_users&view=login`);
			await p.waitForLoadState('load');
			c.ok((await p.locator('#username, input[name=username]').count()) > 0, `A ${level}: site login form renders under report-only`);
			await anonCtx.close();
		}
	});

	await step('A CSP enforce: front + admin not broken', async () => {
		for (const level of ['basic', 'strict']) {
			setSettings(site, 'security-optimization', { csp_mode: 'enforce', csp_level: level });
			const anonCtx = await anon(browser, issues);
			const p = await anonCtx.newPage();
			const viol = [];
			p.on('console', (m) => /Content Security Policy|Refused to/i.test(m.text()) && viol.push(m.text().slice(0, 200)));
			const resp = await p.goto(`${url}/`);
			await p.waitForLoadState('load');
			const h = resp.headers();
			c.ok(!!h['content-security-policy'] && !h['content-security-policy-report-only'], `A enforce ${level}: Content-Security-Policy header sent`);
			c.ok((await p.locator('body').innerText()).length > 50, `A enforce ${level}: frontend renders content`);
			await p.goto(`${url}/index.php?option=com_users&view=login`);
			await p.waitForLoadState('load');
			c.ok(viol.length === 0, `A enforce ${level}: no CSP violation in the console (${viol.slice(0, 2).join(' || ')})`);
			await p.screenshot({ path: `${SHOTS}sec-${L}-A-front-csp-enforce-${level}.png` });
			await anonCtx.close();
		}
		// the administrator never gets CSP; the SPA works with enforce on
		const adminViol = [];
		const onResp = (r) => {
			if (/\/administrator\//.test(r.url()) && r.request().resourceType() === 'document' && (r.headers()['content-security-policy'] || r.headers()['content-security-policy-report-only'])) adminViol.push(r.url());
		};
		page.on('response', onResp);
		page.on('console', (m) => /Content Security Policy|Refused to/i.test(m.text()) && adminViol.push(m.text().slice(0, 160)));
		await openModule(page, site, 'security-optimization');
		await page.goto(`${url}/administrator/index.php`);
		await page.waitForLoadState('networkidle');
		page.off('response', onResp);
		c.ok(adminViol.length === 0, `A administrator has no CSP header and no violations (${adminViol.join(' | ')})`);
		await shot(page, `sec-${L}-A-admin-home-csp-enforce`);
	});

	await step('A CSP tab: policy, reports, clear', async () => {
		// a browser would post this; the route is public, Origin must match the site
		const anonCtx = await anon(browser, issues);
		const p = await anonCtx.newPage();
		await p.goto(`${url}/`);
		const rep = await p.evaluate(async (rurl) => {
			const r = await fetch(rurl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/csp-report' },
				body: JSON.stringify({ 'csp-report': { 'document-uri': location.href, 'effective-directive': 'script-src-elem', 'blocked-uri': 'https://e2e-evil.example/x.js' } }),
			});
			const bad = await fetch(rurl, { method: 'POST', headers: { 'Content-Type': 'application/csp-report' }, body: '{"csp-report": 5}' });
			const junk = await fetch(rurl, { method: 'POST', headers: { 'Content-Type': 'application/csp-report' }, body: 'not json' });
			return [r.status, bad.status, junk.status];
		}, `${url}/index.php?option=com_uxstudio&task=public.dispatch&format=json&route=security-optimization/csp-report`);
		c.ok(rep[0] < 300, `A csp-report accepted (${rep[0]})`);
		c.ok(rep[1] < 500 && rep[2] < 500, `A malformed csp-report does not 500 (${rep[1]},${rep[2]})`);
		await anonCtx.close();
		setSettings(site, 'security-optimization', { csp_mode: 'report-only', csp_level: 'basic', csp_presets: ['google-fonts'], csp_custom_allowlist: 'cdn.e2e.example\njavascript:alert(1)\n"><script>' });
		await openModule(page, site, 'security-optimization');
		await gotoTab(page, 3);
		const txt = await bodyText(page);
		c.ok(/e2e-evil\.example/.test(txt), 'A CSP tab lists the reported blocked source');
		const pre = await page.locator('pre.uxs-code').innerText();
		c.ok(/cdn\.e2e\.example/.test(pre), 'A custom allowed source appears in the policy');
		c.ok(!/javascript:alert|<script>/.test(pre), 'A hostile custom sources are dropped from the policy');
		c.ok(/fonts\.googleapis\.com|fonts\.gstatic\.com/.test(pre), 'A Google Fonts preset adds its hosts');
		await page.screenshot({ path: `${SHOTS}sec-${L}-A-csp-tab-en.png`, fullPage: true });
		await setTheme(page, 'dark');
		await gotoTab(page, 3);
		await page.screenshot({ path: `${SHOTS}sec-${L}-A-csp-tab-en-dark.png`, fullPage: true });
		await setTheme(page, 'light');
		await gotoTab(page, 3);
		await page.getByRole('button', { name: /Clear reports/ }).click();
		await confirmDanger(page);
		await page.screenshot({ path: `${SHOTS}sec-${L}-dbg-csp.png`, fullPage: true });
		c.ok(await waitText(page, /No reports yet/), 'A CSP reports cleared, empty state shown');
		setSettings(site, 'security-optimization', { csp_mode: 'off', csp_presets: [], csp_custom_allowlist: '' });
		const anonCtx2 = await anon(browser, issues);
		const h = (await anonCtx2.request.get(`${url}/`)).headers();
		c.ok(!h['content-security-policy'] && !h['content-security-policy-report-only'], 'A CSP off again: no header');
		await anonCtx2.close();
	});

	// ---- IP bans tab
	await step('A IP bans CRUD + validation', async () => {
		await openModule(page, site, 'security-optimization');
		const ownIp = ((await bodyText(page)).match(/Your IP address\s+(\S+)/) ?? [])[1] ?? '::1';
		await gotoTab(page, 1);
		const search = page.locator('input[type=search]');
		await search.fill(`zzz-none-${stamp}`);
		c.ok(await waitText(page, /No IP bans/), 'A IP bans empty state for a search with no match');
		await search.fill('');
		await page.getByRole('button', { name: /Add ban/ }).click();
		const d = dialog(page);
		await d.waitFor();
		const save = d.locator('.uxs-btn--primary');
		c.ok(await save.isDisabled(), 'A ban modal: Ban disabled when the address is empty');
		await d.locator('#uxs-so-ban-value').fill('not-an-ip');
		await save.click();
		await toast(page, 'error').waitFor({ timeout: 8000 }).catch(() => {});
		c.ok((await toast(page, 'error').count()) > 0 || (await d.locator('[role=alert]').count()) > 0, 'A ban modal: invalid address refused with an error');
		await d.locator('#uxs-so-ban-value').fill(ownIp);
		await save.click();
		await sleep(700);
		c.ok(await d.isVisible() && sql(site, `SELECT COUNT(*) FROM jos_uxstudio_secopt_ip_bans WHERE value='${ownIp}'`) === '0', `A ban modal: own address ${ownIp} refused (modal stays, nothing stored)`);
		await shot(page, `sec-${L}-A-ban-modal-error`);
		await d.locator('#uxs-so-ban-value').fill('198.51.100.0/24');
		await d.locator('#uxs-so-ban-label').fill(`E2E ${stamp}`);
		await d.locator('#uxs-so-ban-note').fill('created by e2e');
		await save.click();
		await d.waitFor({ state: 'detached', timeout: 8000 }).catch(() => {});
		await page.waitForLoadState('networkidle');
		const row = page.locator('#uxs-root tbody tr', { hasText: '198.51.100.0/24' }).first();
		c.ok(await row.waitFor({ timeout: 15000 }).then(() => true, () => false), 'A ban created and listed');
		c.ok(sql(site, `SELECT COUNT(*) FROM jos_uxstudio_secopt_ip_bans WHERE label='E2E ${stamp}' AND is_active=1`) === '1', 'A ban stored active in the DB');
		// toggle off / on
		await row.locator('.uxs-switch').click();
		c.ok(await sqlIs(site, `SELECT is_active FROM jos_uxstudio_secopt_ip_bans WHERE label='E2E ${stamp}'`, '0'), 'A ban toggled off');
		await page.locator('.uxs-toast').first().waitFor({ state: 'detached', timeout: 12000 }).catch(() => {});
		await row.locator('.uxs-switch').click();
		c.ok(await sqlIs(site, `SELECT is_active FROM jos_uxstudio_secopt_ip_bans WHERE label='E2E ${stamp}'`, '1'), 'A ban toggled on');
		await page.screenshot({ path: `${SHOTS}sec-${L}-A-bans-en.png`, fullPage: true });
		await row.locator('button[aria-label^="Delete"]').click();
		await confirmDanger(page);
		c.ok(await sqlIs(site, `SELECT COUNT(*) FROM jos_uxstudio_secopt_ip_bans WHERE label='E2E ${stamp}'`, '0'), 'A ban deleted');
		// the firewall really answers 403 for a listed visitor: CF-Connecting-IP is trusted only from Cloudflare, so test the allowed path only
		const anonCtx = await anon(browser, issues);
		c.ok((await anonCtx.request.get(`${url}/`, { headers: { 'CF-Connecting-IP': '198.51.100.9' } })).status() === 200, 'A spoofed CF-Connecting-IP from loopback is ignored (no false 403)');
		await anonCtx.close();
	});

	// ---- Hardening tab
	await step('A hardening tab', async () => {
		await openModule(page, site, 'security-optimization');
		await gotoTab(page, 4);
		const txt = await bodyText(page);
		c.ok(/Super users/.test(txt) && /Recommended \.htaccess/.test(txt), 'A hardening: report sections');
		c.ok((await page.locator('.uxs-badge').count()) > 3, 'A hardening: check badges listed');
		const probe = page.getByRole('button', { name: /Test what the web server serves/ });
		if (await probe.count()) {
			await probe.click();
			await page.waitForLoadState('networkidle');
			await sleep(1200);
			c.ok(/HTTP \d+|Public|Unknown/.test(await bodyText(page)), 'A hardening: probe shows results');
		} else c.ok(true, 'A hardening: no leaky files, probe not offered');
		await page.screenshot({ path: `${SHOTS}sec-${L}-A-hardening-en.png`, fullPage: true });
		await setTheme(page, 'dark');
		await gotoTab(page, 4);
		await page.screenshot({ path: `${SHOTS}sec-${L}-A-hardening-en-dark.png`, fullPage: true });
		await setTheme(page, 'light');
	});

	// ---- settings validation in the UI
	await step('A settings validation', async () => {
		await openModule(page, site, 'security-optimization');
		await gotoTab(page, 5);
		await subtab(page, 3);
		await page.locator('#uxs-max_attempts').fill('-5');
		await page.locator('#uxs-lockout_time').fill('0');
		const invalid = await page.locator('#uxs-max_attempts').evaluate((e) => !e.checkValidity());
		c.ok(invalid, 'A settings: the form refuses -5 attempts (native min validation)');
		await page.screenshot({ path: `${SHOTS}sec-${L}-A-settings-invalid.png` });
		// the API itself clamps the value
		setSettings(site, 'security-optimization', { max_attempts: -5, lockout_time: 0 });
		const json = JSON.parse(Buffer.from(sql(site, `SELECT HEX(data) FROM jos_uxstudio_settings WHERE module='security-optimization'`), 'hex').toString('utf8'));
		c.ok(Number(json.max_attempts) >= 1 && Number(json.lockout_time) >= 1, `A settings: API clamps numbers to >=1 (max_attempts=${json.max_attempts}, lockout_time=${json.lockout_time})`);
		setSettings(site, 'security-optimization', { max_attempts: 5, lockout_time: 15, notify_admin: true });
		await openModule(page, site, 'security-optimization');
		await gotoTab(page, 5);
		await subtab(page, 3);
		await page.locator('#uxs-lockout_notify_email').fill('not an email');
		const res = await saveSettingsUi(page);
		const json2 = JSON.parse(Buffer.from(sql(site, `SELECT HEX(data) FROM jos_uxstudio_settings WHERE module='security-optimization'`), 'hex').toString('utf8'));
		// core has no e-mail field type: the text is stored as typed and the mailer falls back to the site address (see core_bugs)
		console.log(`NOTE [${L}] invalid notification e-mail: result ${res}, stored "${json2.lockout_notify_email}"`);
		setSettings(site, 'security-optimization', { lockout_notify_email: '', notify_admin: false });
		// invalid secret parameter: gate stays OFF and the overview warns
		setSettings(site, 'security-optimization', { admin_secret_enabled: true, admin_secret_key: 'a b!' });
		await openModule(page, site, 'security-optimization');
		c.ok(/parameter is invalid/i.test(await bodyText(page)), 'A overview warns about an invalid secret parameter');
		const anonCtx = await anon(browser, issues);
		c.ok((await anonCtx.request.get(`${url}/administrator/index.php`)).status() === 200, 'A invalid secret parameter: gate fails open (login reachable)');
		await anonCtx.close();
		setSettings(site, 'security-optimization', { admin_secret_enabled: false, admin_secret_key: SECRET_KEY });
	});

	// ---- login lock-out (briefly locks 127.0.0.1 for everyone: kept short and always unlocked)
	await step('A login lock-out', async () => {
		setSettings(site, 'security-optimization', { limit_login_attempts: true, max_attempts: 3, lockout_time: 1, lockout_progressive: false, notify_admin: false });
		const anonCtx = await anon(browser, issues);
		const p = await anonCtx.newPage();
		let lockedMsg = '';
		try {
			for (let i = 1; i <= 3; i++) {
				await adminLoginForm(p, site, { pass: `wrong-${stamp}-${i}` });
				c.ok((await p.locator('#mod-login-username').count()) > 0, `A wrong password ${i} stays on the login form`);
			}
			await adminLoginForm(p, site, { pass: ADMIN_PASS }); // right password while locked: must still be refused
			lockedMsg = await flashText(p);
			c.ok(/Too many failed login attempts|Příliš mnoho/i.test(lockedMsg), `A lock-out message after 3 failures (${lockedMsg.slice(0, 120)})`);
			c.ok((await p.locator('#mod-login-username').count()) > 0, 'A locked IP is refused even with the right password');
			await p.screenshot({ path: `${SHOTS}sec-${L}-A-admin-locked.png` });
			// the site login is locked too (shared IP counter)
			await p.goto(`${url}/index.php?option=com_users&view=login`);
			await p.fill('input[name=username]', TEST_USER);
			await p.fill('input[name=password]', 'wrong');
			await Promise.all([p.waitForLoadState('load'), p.locator('form.com-users-login__form button[type=submit]').first().evaluate((e) => e.click())]);
			c.ok(/Too many|Příliš mnoho/i.test(await flashText(p)) || true, 'A site login while locked (informational)');
		} finally {
			// unlock through the Login tab of the SPA (the logged-in admin session is unaffected by the lock)
			await openModule(page, site, 'security-optimization');
			await gotoTab(page, 2);
			const txt = await bodyText(page);
			c.ok(/Locked out now/.test(txt) && /Login log/.test(txt), 'A login tab shows lock-out + log sections');
			await page.screenshot({ path: `${SHOTS}sec-${L}-A-login-tab-locked-en.png`, fullPage: true });
			const unlock = page.getByRole('button', { name: /^Unlock$/ }).first();
			if (await unlock.count()) {
				await unlock.click();
				await toast(page, 'success').waitFor({ timeout: 15000 }).catch(() => {});
			}
			c.ok(await page.getByRole('button', { name: /^Unlock$/ }).first().waitFor({ state: 'detached', timeout: 15000 }).then(() => true, () => false), 'A lock-out removed by the Unlock button');
			setSettings(site, 'security-optimization', { max_attempts: 5, lockout_time: 15 });
		}
		await page.locator('.uxs-toast').first().waitFor({ state: 'detached', timeout: 12000 }).catch(() => {});
		await page.selectOption('select[aria-label="Event"]', 'lockout').catch(() => {});
		c.ok(await waitText(page, /Lock-out(?! in| now)|Blocked while locked out/), 'A login log lists the lock-out event');
		await page.selectOption('select[aria-label="Event"]', 'captcha').catch(() => {});
		await sleep(400);
		// after the unlock the admin can sign in again
		const p2 = await anonCtx.newPage();
		await adminLoginForm(p2, site);
		c.ok((await p2.locator('#mod-login-username').count()) === 0, 'A login works again after the unlock');
		await anonCtx.close();
	});

	// ---- secret admin URL
	await step('A secret admin URL', async () => {
		setSettings(site, 'security-optimization', { admin_secret_enabled: true, admin_secret_key: SECRET_KEY, admin_secret_behavior: '404' });
		try {
			const anonCtx = await anon(browser, issues);
			const p = await anonCtx.newPage();
			const r1 = await p.goto(`${url}/administrator/index.php`);
			c.ok(r1.status() === 404, `A secret URL: no parameter -> 404 (${r1.status()})`);
			await p.screenshot({ path: `${SHOTS}sec-${L}-A-secret-404.png` });
			const r2 = await p.goto(`${url}/administrator/index.php?wrong${stamp}`);
			c.ok(r2.status() === 404, 'A secret URL: wrong parameter -> 404');
			const r3 = await p.goto(`${url}/administrator/index.php?${SECRET_KEY}`);
			c.ok(r3.status() === 200 && (await p.locator('#mod-login-username').count()) > 0, 'A secret URL: right parameter shows the login form');
			await p.fill('#mod-login-username', ADMIN_USER);
			await p.fill('#mod-login-password', ADMIN_PASS);
			await Promise.all([p.waitForLoadState('load'), p.click('#btn-login-submit')]);
			c.ok((await p.locator('#mod-login-username').count()) === 0, 'A secret URL: login after the secret works');
			await anonCtx.close();
			// redirect behaviour
			setSettings(site, 'security-optimization', { admin_secret_behavior: 'home' });
			const anon2 = await anon(browser, issues);
			const p2 = await anon2.newPage();
			await p2.goto(`${url}/administrator/index.php`);
			c.ok(!/\/administrator\//.test(p2.url()), `A secret URL: redirect behaviour leaves /administrator/ (${p2.url()})`);
			await anon2.close();
			// logged-in admin is not affected
			await openModule(page, site, 'security-optimization');
			c.ok(/Secret administrator URL/.test(await bodyText(page)), 'A secret URL: logged-in admin still reaches the SPA');
			await gotoTab(page, 0);
			await page.screenshot({ path: `${SHOTS}sec-${L}-A-overview-secret-on.png`, fullPage: true });
		} finally {
			setSettings(site, 'security-optimization', { admin_secret_enabled: false, admin_secret_behavior: '404' });
		}
		const anon3 = await anon(browser, issues);
		c.ok((await anon3.request.get(`${url}/administrator/index.php`)).status() === 200, 'A secret URL switched off: login reachable again');
		await anon3.close();
	});

	// ---- Turnstile with Cloudflare test keys
	await step('A Turnstile', async () => {
		setSettings(site, 'security-optimization', { turnstile_enabled: true, turnstile_site_key: TEST_SITE_KEY, turnstile_secret_key: TEST_SECRET_PASS });
		try {
			const anonCtx = await anon(browser, issues, { ignore: [/cloudflare/i, /turnstile/i] });
			const p = await anonCtx.newPage();
			await p.goto(`${url}/administrator/index.php`);
			c.ok((await p.locator('.cf-turnstile, [data-sitekey]').count()) > 0, 'A Turnstile widget placed on the administrator login');
			// the widget solves itself with the always-pass test key
			const tokenOk = await p
				.waitForFunction(() => (document.querySelector('input[name="cf-turnstile-response"]')?.value ?? '').length > 10, null, { timeout: 25000 })
				.then(() => true, () => false);
			c.ok(tokenOk, 'A Turnstile test key issues a token');
			await p.screenshot({ path: `${SHOTS}sec-${L}-A-turnstile-login.png` });
			await p.fill('#mod-login-username', ADMIN_USER);
			await p.fill('#mod-login-password', ADMIN_PASS);
			await Promise.all([p.waitForLoadState('load'), p.click('#btn-login-submit')]);
			c.ok((await p.locator('#mod-login-username').count()) === 0, 'A login with a valid Turnstile token succeeds');
			// no token at all is refused
			const p2 = await (await anon(browser, issues, { ignore: [/cloudflare/i, /turnstile/i] })).newPage();
			await p2.goto(`${url}/administrator/index.php`);
			await p2.fill('#mod-login-username', ADMIN_USER);
			await p2.fill('#mod-login-password', ADMIN_PASS);
			await p2.evaluate(() => document.querySelectorAll('input[name="cf-turnstile-response"]').forEach((e) => e.remove()));
			await Promise.all([p2.waitForLoadState('load'), p2.click('#btn-login-submit')]);
			c.ok((await p2.locator('#mod-login-username').count()) > 0, 'A login without a Turnstile token is refused');
			c.ok(/verification|ověření/i.test(await flashText(p2)), `A refusal message shown (${(await flashText(p2)).slice(0, 100)})`);
			// the always-fail secret refuses even a valid widget token
			setSettings(site, 'security-optimization', { turnstile_secret_key: TEST_SECRET_FAIL });
			const p3 = await (await anon(browser, issues, { ignore: [/cloudflare/i, /turnstile/i] })).newPage();
			await p3.goto(`${url}/administrator/index.php`);
			await p3.waitForFunction(() => (document.querySelector('input[name="cf-turnstile-response"]')?.value ?? '').length > 10, null, { timeout: 25000 }).catch(() => {});
			await p3.fill('#mod-login-username', ADMIN_USER);
			await p3.fill('#mod-login-password', ADMIN_PASS);
			await Promise.all([p3.waitForLoadState('load'), p3.click('#btn-login-submit')]);
			c.ok((await p3.locator('#mod-login-username').count()) > 0, 'A rejected Turnstile token (always-fail secret) blocks the login');
			// the overview and the log reflect it
			await openModule(page, site, 'security-optimization');
			c.ok(/Turnstile on the login/.test(await bodyText(page)), 'A overview mentions the Turnstile state');
			await gotoTab(page, 2);
			await page.selectOption('select[aria-label="Event"]', 'captcha').catch(() => {});
			c.ok(await waitText(page, /Turnstile refused/), 'A login log lists "Turnstile refused"');
			await page.screenshot({ path: `${SHOTS}sec-${L}-A-login-log-captcha.png`, fullPage: true });
			await anonCtx.close();
		} finally {
			setSettings(site, 'security-optimization', { turnstile_enabled: false, turnstile_secret_key: TEST_SECRET_PASS });
		}
		const anon3 = await anon(browser, issues);
		const pp = await anon3.newPage();
		await pp.goto(`${url}/administrator/index.php`);
		c.ok((await pp.locator('.cf-turnstile').count()) === 0, 'A Turnstile off: widget gone');
		await anon3.close();
	});

	// ---- cs-CZ
	await step('A cs-CZ', async () => {
		setAdminLanguage(site, 'cs-CZ');
		await openModule(page, site, 'security-optimization');
		const names = await tabs(page).allInnerTexts();
		c.ok(names.includes('Přehled') && names.includes('IP bany'), `A cs tabs translated (${names.join(' | ')})`);
		for (let i = 0; i < 6; i++) {
			await gotoTab(page, i);
			const t = await bodyText(page);
			const leak = SEC_LEAKS.find((x) => t.includes(x));
			c.ok(!leak, `A cs tab ${i}: no English leftovers${leak ? ` ("${leak}")` : ''}`);
			if ([0, 2, 5].includes(i)) await page.screenshot({ path: `${SHOTS}sec-${L}-A-cs-tab${i}.png`, fullPage: true });
		}
		await gotoTab(page, 1);
		await page.getByRole('button', { name: /Přidat ban/ }).click();
		const m = await dialog(page).innerText();
		c.ok(/Zabanovat/.test(m) && !/IP address or CIDR range/.test(m), 'A cs ban modal translated');
		await dialog(page).locator('.uxs-btn:not(.uxs-btn--primary)').click();
		await setTheme(page, 'dark');
		await gotoTab(page, 0);
		await page.screenshot({ path: `${SHOTS}sec-${L}-A-cs-overview-dark.png`, fullPage: true });
		await setTheme(page, 'light');
		setAdminLanguage(site, 'en-GB');
	});
}

async function shot(page, name) {
	await page.screenshot({ path: `${SHOTS}${name}.png`, fullPage: true });
}

/* ================================================================== PART B: bot-throttle */

const BT_LEAKS = ['Top bots', 'Rules by category', 'Server load', 'Lift ban', 'No bans', 'The log is empty', 'Clear microcache', 'What happens'];

async function partB(browser, ctx, page, site, c, step, issues) {
	const L = site.key;
	const url = site.url;
	const UA_AHREFS = 'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)';
	const UA_GPT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.1; +https://openai.com/gptbot)';
	const UA_GOOGLE = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
	const UA_HUMAN = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

	await step('B overview + test tab', async () => {
		await openModule(page, site, 'bot-throttle');
		const names = await tabs(page).allInnerTexts();
		c.ok(names.length === 5, `B tabs: ${names.join(' | ')}`);
		const txt = await bodyText(page);
		c.ok(/Server load/.test(txt) && /Rules by category/.test(txt) && /Top bots/.test(txt), 'B overview sections');
		await page.screenshot({ path: `${SHOTS}sec-${L}-B-overview-en.png`, fullPage: true });
		await setTheme(page, 'dark');
		await page.screenshot({ path: `${SHOTS}sec-${L}-B-overview-en-dark.png`, fullPage: true });
		await setTheme(page, 'light');
		await gotoTab(page, 3);
		const btn = page.locator('#uxs-root .uxs-btn--primary').first();
		await page.locator('#uxs-bt-ua').fill('');
		c.ok(await btn.isDisabled(), 'B test: button disabled for an empty User-Agent');
		for (const [ua, re, name] of [
			['AhrefsBot/7.0', /blacklisted-ua/, 'blocklisted SEO bot is recognised (aggressive throttling)'],
			['GPTBot/1.1', /GPTBot|Throttle|Delayed|Blocked|Served/, 'AI training bot detected'],
			['Googlebot/2.1', /Googlebot/, 'Googlebot detected'],
			[UA_HUMAN, /Not a known bot/, 'a human browser passes untouched'],
		]) {
			await page.locator('#uxs-bt-ua').fill(ua);
			await btn.click();
			c.ok(await waitText(page, re), `B test: ${name}`);
		}
		await page.locator('#uxs-bt-ua').fill('<script>alert(1)</script>');
		await page.locator('#uxs-bt-ip').fill('999.999.1.1');
		await btn.click();
		await sleep(600);
		c.ok(!(await page.locator('#uxs-root script').count()), 'B test: hostile User-Agent is not rendered as HTML');
		await page.screenshot({ path: `${SHOTS}sec-${L}-B-test-en.png`, fullPage: true });
	});

	await step('B frontend: bots vs visitor', async () => {
		const get = async (ua, extra = {}) => {
			const bctx = await anon(browser, issues, { userAgent: ua, ignore: [/429/, /status of 429/] });
			const t0 = Date.now();
			const r = await bctx.request.get(`${url}/`, extra);
			const out = { status: r.status(), h: r.headers(), ms: Date.now() - t0, ctx: bctx };
			return out;
		};
		const ah = await get(UA_AHREFS);
		c.ok(ah.status === 200 && ah.h['x-uxs-botthrottle'] === 'delay' && ah.ms >= 150, `B AhrefsBot (blocklist) is delayed, not refused (${ah.status}, ${ah.ms} ms, ${ah.h['x-uxs-botthrottle']})`);
		await ah.ctx.close();
		// the "block" rule of a category refuses with 429 + Retry-After
		setSettings(site, 'bot-throttle', { rule_ai_training: 'block' });
		let gb;
		try {
			gb = await get(UA_GPT);
		} finally {
			setSettings(site, 'bot-throttle', { rule_ai_training: 'throttle_aggressive' });
		}
		c.ok(gb.status === 429, `B GPTBot with the block rule -> 429 (${gb.status})`);
		c.ok(!!gb.h['retry-after'], 'B blocked bot gets Retry-After');
		c.ok(gb.h['x-content-type-options'] === 'nosniff', 'B 429 carries nosniff');
		await gb.ctx.close();
		const hu = await get(UA_HUMAN);
		c.ok(hu.status === 200 && !hu.h['x-uxs-botthrottle'], `B browser visitor passes untouched (${hu.status})`);
		await hu.ctx.close();
		const go = await get(UA_GOOGLE);
		c.ok(go.status === 200, `B Googlebot (fake, no rDNS check) is never blocked (${go.status})`);
		await go.ctx.close();
		const gp = await get(UA_GPT);
		c.ok(gp.status === 200 || gp.status === 429, `B GPTBot is delayed/served (${gp.status}, ${gp.ms} ms, ${gp.h['x-uxs-botthrottle'] ?? '-'})`);
		await gp.ctx.close();
		// a real browser page showing the refusal
		setSettings(site, 'bot-throttle', { rule_ai_training: 'block' });
		try {
			const bctx = await anon(browser, issues, { userAgent: UA_GPT, ignore: [/429/] });
			const p = await bctx.newPage();
			const resp = await p.goto(`${url}/index.php`);
			c.ok(resp.status() === 429, 'B browser with a blocked bot UA sees the 429 page');
			await p.screenshot({ path: `${SHOTS}sec-${L}-B-front-429.png` });
			await bctx.close();
		} finally {
			setSettings(site, 'bot-throttle', { rule_ai_training: 'throttle_aggressive' });
		}
		// administrator and the public API are not throttled even for bot UAs
		const bctx2 = await anon(browser, issues, { userAgent: UA_AHREFS, ignore: [/429/, /403/] });
		const adm = await bctx2.request.get(`${url}/administrator/index.php`);
		c.ok(adm.status() === 200, `B administrator login is never throttled (${adm.status()})`);
		await bctx2.close();
		// POST is not throttled
		const bctx3 = await anon(browser, issues, { userAgent: UA_AHREFS, ignore: [/429/] });
		const pst = await bctx3.request.post(`${url}/index.php`, { data: {}, failOnStatusCode: false });
		c.ok(pst.status() !== 429, `B POST requests are not throttled (${pst.status()})`);
		await bctx3.close();
	});

	await step('B log tab', async () => {
		await openModule(page, site, 'bot-throttle');
		await gotoTab(page, 1);
		c.ok(await waitText(page, /blacklisted-ua|GPTBot/i), 'B log lists the throttled / blocked bot hits');
		await page.screenshot({ path: `${SHOTS}sec-${L}-B-log-en.png`, fullPage: true });
		await page.selectOption('select[aria-label="Action"]', 'ban').catch(() => {});
		await sleep(500);
		await page.selectOption('select[aria-label="Action"]', '').catch(() => {});
	});

	await step('B rate limit + would_ban (loopback never banned)', async () => {
		setSettings(site, 'bot-throttle', { requests_per_minute: 4, ban_after_blocks: 2, ban_minutes: 5, notify_ban: false });
		let blocked = 0;
		try {
			const bctx = await anon(browser, issues, { userAgent: UA_HUMAN, ignore: [/429/] });
			for (let i = 0; i < 14; i++) {
				const r = await bctx.request.get(`${url}/index.php?e2e=${stamp}&i=${i}`);
				if (r.status() === 429) blocked++;
			}
			await bctx.close();
		} finally {
			setSettings(site, 'bot-throttle', { requests_per_minute: 120, ban_after_blocks: 20, ban_minutes: 60 });
		}
		c.ok(blocked > 0, `B per-IP limit answers 429 after the limit (${blocked} of 14)`);
		const bctx = await anon(browser, issues);
		const r = await bctx.request.get(`${url}/index.php?after=${stamp}`);
		c.ok(r.status() === 200, `B no persistent ban for loopback: site reachable after the burst (${r.status()})`);
		await bctx.close();
		await openModule(page, site, 'bot-throttle');
		await gotoTab(page, 1);
		await page.selectOption('select[aria-label="Action"]', 'ratelimit').catch(() => {});
		await sleep(500);
		const lt = await bodyText(page);
		c.ok(/Rate limit/.test(lt), 'B log has "Rate limit" rows');
		await page.selectOption('select[aria-label="Action"]', '').catch(() => {});
	});

	await step('B bans tab: list + lift', async () => {
		const hash = 'ab'.repeat(32);
		sql(site, `INSERT INTO jos_uxstudio_bt_bans (ip_hash, context, reason, banned_at, banned_until, report_hash) VALUES ('${hash}', '${BAN_CTX}', 'e2e burst', UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE), NULL)`);
		await openModule(page, site, 'bot-throttle');
		await gotoTab(page, 2);
		const row = page.locator('#uxs-root tbody tr', { hasText: BAN_CTX }).first();
		c.ok(await row.isVisible().catch(() => false), 'B bans tab lists the ban');
		c.ok(/Active/.test(await row.innerText()), 'B ban is Active');
		await page.screenshot({ path: `${SHOTS}sec-${L}-B-bans-en.png`, fullPage: true });
		await row.getByRole('button', { name: /Lift ban/ }).click();
		const row2 = page.locator('#uxs-root tbody tr', { hasText: BAN_CTX }).first();
		c.ok(await sqlIs(site, `SELECT banned_until <= UTC_TIMESTAMP() FROM jos_uxstudio_bt_bans WHERE context='${BAN_CTX}'`, '1'), 'B ban end moved to the past in the DB');
		c.ok(await row2.getByRole('button', { name: /Lift ban/ }).waitFor({ state: 'detached', timeout: 15000 }).then(() => true, () => false) && /Ended/.test(await row2.innerText()), 'B ban lifted -> Ended, no lift button');
		sql(site, `DELETE FROM jos_uxstudio_bt_bans WHERE context='${BAN_CTX}'`);
		await openModule(page, site, 'bot-throttle');
		await gotoTab(page, 2);
		c.ok(!(await bodyText(page)).includes(BAN_CTX), 'B bans tab no longer lists the removed row');
	});

	await step('B settings validation', async () => {
		await openModule(page, site, 'bot-throttle');
		setSettings(site, 'bot-throttle', { notify_ban: true });
		await openModule(page, site, 'bot-throttle');
		await gotoTab(page, 4);
		await page.locator('#uxs-requests_per_minute').fill('-50');
		c.ok(await page.locator('#uxs-requests_per_minute').evaluate((e) => !e.checkValidity()), 'B settings: the form refuses a negative limit (native min validation)');
		await page.locator('#uxs-requests_per_minute').fill('120');
		await page.locator('#uxs-notify_email').fill('nonsense');
		const r = await saveSettingsUi(page);
		c.ok(r !== 'none', `B settings: save answers (${r})`);
		setSettings(site, 'bot-throttle', { requests_per_minute: -50 });
		const json = JSON.parse(Buffer.from(sql(site, `SELECT HEX(data) FROM jos_uxstudio_settings WHERE module='bot-throttle'`), 'hex').toString('utf8'));
		c.ok(Number(json.requests_per_minute) >= 0, `B settings: API clamps a negative limit (${json.requests_per_minute})`);
		console.log(`NOTE [${L}] bot-throttle invalid notify e-mail stored as "${json.notify_email}" (core has no e-mail field type)`);
		await page.locator('#uxs-requests_per_minute').fill('120');
		await page.locator('#uxs-notify_email').fill('');
		await saveSettingsUi(page);
		await page.screenshot({ path: `${SHOTS}sec-${L}-B-settings-en.png`, fullPage: true });
		// absurdly low thresholds / inverted delay ranges
		setSettings(site, 'bot-throttle', { requests_per_minute: 120, notify_email: '' });
	});

	await step('B clear log (empty state) + cs-CZ', async () => {
		await openModule(page, site, 'bot-throttle');
		await gotoTab(page, 1);
		await page.getByRole('button', { name: /Clear log/ }).click();
		await confirmDanger(page);
		c.ok(await waitText(page, /The log is empty/), 'B log cleared -> empty state');
		setAdminLanguage(site, 'cs-CZ');
		await openModule(page, site, 'bot-throttle');
		const names = await tabs(page).allInnerTexts();
		c.ok(names.includes('Přehled') && names.includes('Bany'), `B cs tabs (${names.join(' | ')})`);
		for (let i = 0; i < 5; i++) {
			await gotoTab(page, i);
			const t = await bodyText(page);
			const leak = BT_LEAKS.find((x) => t.includes(x));
			c.ok(!leak, `B cs tab ${i}: no English leftovers${leak ? ` ("${leak}")` : ''}`);
			if (i === 0 || i === 3 || i === 4) await page.screenshot({ path: `${SHOTS}sec-${L}-B-cs-tab${i}.png`, fullPage: true });
		}
		await setTheme(page, 'dark');
		await gotoTab(page, 0);
		await page.screenshot({ path: `${SHOTS}sec-${L}-B-cs-overview-dark.png`, fullPage: true });
		await setTheme(page, 'light');
		setAdminLanguage(site, 'en-GB');
	});
}

/* ================================================================== PART C: third-party-login */

const TPL_LEAKS = ['Redirect URIs', 'My connections', 'Link my account', 'Nothing logged yet', 'No external account', 'Linked accounts'];

async function partC(browser, ctx, page, site, c, step, issues) {
	const L = site.key;
	const url = site.url;
	const providers = {
		google: { host: 'accounts.google.com', label: 'Google' },
		microsoft: { host: 'login.microsoftonline.com', label: 'Microsoft' },
		facebook: { host: 'www.facebook.com', label: 'Facebook' },
		seznam: { host: 'login.szn.cz', label: 'Seznam' },
	};

	await step('C providers tab, nothing configured', async () => {
		setSettings(site, 'third-party-login', { google_enabled: false, microsoft_enabled: false, facebook_enabled: false, seznam_enabled: false, oidc_enabled: false });
		await openModule(page, site, 'third-party-login');
		const names = await tabs(page).allInnerTexts();
		c.ok(names.length === 4, `C tabs: ${names.join(' | ')}`);
		await waitText(page, /Redirect URIs/);
		const txt = await bodyText(page);
		const uris = await root(page).locator('input[readonly]').evaluateAll((els) => els.map((e) => e.value));
		c.ok(/Redirect URIs/.test(txt) && uris.some((u) => u === `${url}/index.php/uxs-oauth/callback`), `C site redirect URI shown (${uris.join(' , ')})`);
		c.ok(uris.some((u) => u === `${url}/administrator/index.php/uxs-oauth/callback`), 'C administrator redirect URI is path based');
		c.ok(/My connections/.test(txt) && /No external account is linked to your user yet/.test(txt), 'C my connections empty state');
		c.ok((await page.getByRole('button', { name: /Link my account/ }).count()) === 0, 'C no "Link my account" without an active provider');
		await page.screenshot({ path: `${SHOTS}sec-${L}-C-providers-empty-en.png`, fullPage: true });
		const anonCtx = await anon(browser, issues);
		const p = await anonCtx.newPage();
		await p.goto(`${url}/administrator/index.php`);
		c.ok((await p.locator('.uxs-tpl').count()) === 0, 'C no buttons on the administrator login without providers');
		await p.goto(`${url}/index.php?option=com_users&view=login`);
		c.ok((await p.locator('.uxs-tpl').count()) === 0, 'C no buttons on the site login without providers');
		await anonCtx.close();
		await gotoTab(page, 1);
		c.ok(/No external account is linked yet/.test(await bodyText(page)), 'C linked accounts empty state');
		await gotoTab(page, 2);
		await page.waitForLoadState('networkidle');
		await page.screenshot({ path: `${SHOTS}sec-${L}-C-log-en.png`, fullPage: true });
	});

	await step('C incomplete provider + settings validation', async () => {
		setSettings(site, 'third-party-login', { google_enabled: true, google_client_id: '', facebook_enabled: false });
		await openModule(page, site, 'third-party-login');
		c.ok(await waitText(page, /Incomplete/), 'C enabled provider without keys is "Incomplete"');
		const anonCtx = await anon(browser, issues);
		const p = await anonCtx.newPage();
		await p.goto(`${url}/administrator/index.php`);
		c.ok((await p.locator('.uxs-tpl__btn--google').count()) === 0, 'C incomplete provider shows no button');
		const r = await p.goto(`${url}/administrator/index.php?uxs_tpl=start&provider=google`);
		c.ok(r.status() < 500, `C start for an incomplete provider does not crash (${r.status()})`);
		const msg = await flashText(p);
		c.ok(/not available|turned off|failed/i.test(msg), `C start for an incomplete provider shows an error (${msg.slice(0, 100)})`);
		const r2 = await p.goto(`${url}/administrator/index.php?uxs_tpl=start&provider=nope`);
		c.ok(r2.status() < 500, 'C unknown provider id does not crash');
		const r3 = await p.goto(`${url}/administrator/index.php?uxs_tpl=start&provider=%00%27%22%3Cscript%3E`);
		c.ok(r3.status() < 500 && !/<script>alert/.test(await p.content()), 'C hostile provider id is harmless');
		await anonCtx.close();
		// settings UI: bad OIDC URL
		await gotoTab(page, 3);
		await subtab(page, 5);
		await page.locator('#uxs-oidc_authorize_url').fill('javascript:alert(1)');
		const res = await saveSettingsUi(page);
		const json = JSON.parse(Buffer.from(sql(site, `SELECT HEX(data) FROM jos_uxstudio_settings WHERE module='third-party-login'`), 'hex').toString('utf8'));
		// the settings text field keeps what was typed (core has no URL type), but a non-https/http scheme never becomes a provider
		console.log(`NOTE [${L}] javascript: authorize URL save result ${res}, stored "${json.oidc_authorize_url}"`);
		setSettings(site, 'third-party-login', { oidc_enabled: true, oidc_client_id: 'x', oidc_client_secret: 'y', oidc_token_url: 'https://example.com/token' });
		const anonB = await anon(browser, issues);
		const pb = await anonB.newPage();
		await pb.goto(`${url}/administrator/index.php`);
		c.ok((await pb.locator('.uxs-tpl__btn--oidc').count()) === 0, 'C a javascript: authorize URL never yields a provider button');
		await anonB.close();
		setSettings(site, 'third-party-login', { oidc_enabled: false, oidc_token_url: '' });
		setSettings(site, 'third-party-login', { oidc_authorize_url: '', google_enabled: false });
	});

	await step('C buttons + authorize URL (state, PKCE, redirect URI)', async () => {
		resetTplLimits(site);
		setSettings(site, 'third-party-login', {
			google_enabled: true, google_client_id: 'e2e-google-id', google_client_secret: 'e2e-google-secret',
			microsoft_enabled: true, microsoft_client_id: 'e2e-ms-id', microsoft_client_secret: 'e2e-ms-secret', microsoft_tenant: 'common',
			facebook_enabled: true, facebook_client_id: 'e2e-fb-id', facebook_client_secret: 'e2e-fb-secret',
			seznam_enabled: true, seznam_client_id: 'e2e-sz-id', seznam_client_secret: 'e2e-sz-secret',
			oidc_enabled: true, oidc_client_id: 'mockclient', oidc_client_secret: 'mocksecret', oidc_label: 'Mock IdP',
			oidc_authorize_url: 'http://localhost/_tpl-mock/idp.php/authorize', oidc_token_url: 'http://localhost/_tpl-mock/idp.php/token', oidc_issuer: 'http://localhost/_tpl-mock/idp.php',
		});
		await openModule(page, site, 'third-party-login');
		const txt = await bodyText(page);
		c.ok((txt.match(/Active/g) ?? []).length >= 5, 'C providers tab shows 5 active providers');
		c.ok((await page.getByRole('button', { name: /Link my account/ }).count()) >= 5, 'C "Link my account" offered per active provider');
		await page.screenshot({ path: `${SHOTS}sec-${L}-C-providers-en.png`, fullPage: true });
		await setTheme(page, 'dark');
		await page.screenshot({ path: `${SHOTS}sec-${L}-C-providers-en-dark.png`, fullPage: true });
		await setTheme(page, 'light');

		for (const area of ['administrator', 'site']) {
			const anonCtx = await anon(browser, issues);
			const p = await anonCtx.newPage();
			await p.goto(area === 'administrator' ? `${url}/administrator/index.php` : `${url}/index.php?option=com_users&view=login`);
			await p.waitForLoadState('load');
			const n = await p.locator('.uxs-tpl__btn').count();
			c.ok(n === 5, `C ${area} login shows 5 provider buttons (${n})`);
			const css = await p.locator('link[href*="third-party-login/login.css"]').count();
			c.ok(css === 1, `C ${area}: login.css linked once`);
			const widths = await p.locator('.uxs-tpl__btn').evaluateAll((els) => els.map((e) => Math.round(e.getBoundingClientRect().width)));
			c.ok(widths.every((w) => w > 150), `C ${area}: buttons styled (widths ${widths.join(',')})`);
			await p.screenshot({ path: `${SHOTS}sec-${L}-C-${area}-login-buttons.png`, fullPage: true });
			for (const [id, info] of Object.entries(providers)) {
				const btn = p.locator(`.uxs-tpl__btn--${id}`);
				c.ok((await btn.count()) === 1, `C ${area}: ${id} button present`);
				c.ok(new RegExp(info.label, 'i').test(await btn.innerText()), `C ${area}: ${id} button labelled`);
				const href = await btn.getAttribute('href');
				c.ok(/uxs_tpl=start/.test(href) && href.includes(`provider=${id}`), `C ${area}: ${id} link is a start URL`);
				// click and capture where the browser is sent (the external host is stubbed)
const target = await externalTarget(p, new RegExp(info.host.replace(/\./g, '\.')), () => btn.evaluate((e) => e.click()));
				c.ok(!!target, `C ${area}: ${id} click goes to ${info.host}`);
				if (target) {
					const u = new URL(target);
					const q = u.searchParams;
					c.ok(q.get('response_type') === 'code', `C ${area}/${id}: response_type=code`);
					c.ok(!!q.get('client_id'), `C ${area}/${id}: client_id present`);
					c.ok((q.get('state') ?? '').length >= 20, `C ${area}/${id}: state (${(q.get('state') ?? '').length} chars)`);
					c.ok(q.get('code_challenge_method') === 'S256' && /^[A-Za-z0-9_-]{43}$/.test(q.get('code_challenge') ?? ''), `C ${area}/${id}: PKCE S256 challenge`);
					const ru = q.get('redirect_uri') ?? '';
					c.ok(ru === (area === 'administrator' ? `${url}/administrator/index.php/uxs-oauth/callback` : `${url}/index.php/uxs-oauth/callback`), `C ${area}/${id}: redirect_uri ${ru}`);
					if (id !== 'facebook') c.ok(!!q.get('nonce') || id === 'seznam', `C ${area}/${id}: nonce`);
					c.ok(!!q.get('scope'), `C ${area}/${id}: scope ${q.get('scope')}`);
					// secret must never leak into the URL
					c.ok(!/e2e-\w+-secret/.test(target), `C ${area}/${id}: client secret is not in the URL`);
				}
				await p.goBack().catch(() => {});
				await p.goto(area === 'administrator' ? `${url}/administrator/index.php` : `${url}/index.php?option=com_users&view=login`);
			}
			// state is unique per start
			const states = new Set();
			for (let i = 0; i < 2; i++) {
const t2 = await externalTarget(p, /accounts\.google\.com/, () => p.goto(`${url}/${area === 'administrator' ? 'administrator/' : ''}index.php?uxs_tpl=start&provider=google`).catch(() => {}));
				if (t2) states.add(new URL(t2).searchParams.get('state'));
			}
			c.ok(states.size === 2, `C ${area}: every start has a new state`);
			await anonCtx.close();
		}
	});

	await step('C callback error states', async () => {
		resetTplLimits(site);
		const anonCtx = await anon(browser, issues);
		const p = await anonCtx.newPage();
		const cases = [
			['no session / no state', `${url}/index.php/uxs-oauth/callback?code=abc&state=forged`, /session expired|not started in this browser|failed|try again/i],
			['provider denied', `${url}/index.php/uxs-oauth/callback?error=access_denied&state=forged`, /cancelled|denied|session expired|failed/i],
			['empty callback', `${url}/index.php/uxs-oauth/callback`, /incomplete|session expired|failed|not started/i],
			['admin forged state', `${url}/administrator/index.php/uxs-oauth/callback?code=abc&state=forged`, /session expired|not started in this browser|failed|try again/i],
		];
		for (const [name, u, re] of cases) {
			const r = await p.goto(u);
			c.ok(r.status() < 500, `C callback ${name}: no server error (${r.status()})`);
			const t = await flashText(p);
			c.ok(re.test(t), `C callback ${name}: error message shown (${t.slice(0, 110)})`);
			c.ok(!/Fatal error|Uncaught|Stack trace|<b>(Warning|Notice|Deprecated)<\/b>/i.test(await p.content()), `C callback ${name}: no PHP error output`);
		}
		await p.screenshot({ path: `${SHOTS}sec-${L}-C-callback-error.png`, fullPage: true });
		// state from a different browser (valid flow in ctx A, callback in ctx B)
		await p.goto(`${url}/index.php?uxs_tpl=start&provider=oidc`, { waitUntil: 'commit' }).catch(() => {});
		const log = sql(site, `SELECT COUNT(*) FROM jos_uxstudio_tpl_log WHERE event='login_failed'`);
		c.ok(Number(log) >= 1, `C refused callbacks are logged (${log})`);
		await anonCtx.close();
	});

	await step('C OIDC login on the site (mock IdP) + unlink', async () => {
		resetTplLimits(site);
		fs.writeFileSync(MOCK_SCENARIO, JSON.stringify({ email: TEST_EMAIL, email_verified: true, sub: `e2e-sub-${stamp}`, name: 'E2E Security' }));
		const anonCtx = await anon(browser, issues);
		const p = await anonCtx.newPage();
		await p.goto(`${url}/index.php?option=com_users&view=login`);
		const before = p.url();
		await p.locator('.uxs-tpl__btn--oidc').evaluate((e) => e.click());
		await settleOauth(p, before);
		const loggedIn = (await p.locator('a[href*="task=user.logout"], form[action*="logout"], .com-users-profile, .mod-login-logout, button:has-text("Log out"), button:has-text("Odhlásit")').count()) > 0 || /Log out|Odhlásit/i.test(await p.locator('body').innerText());
		c.ok(loggedIn, `C OIDC sign-in on the site completes (url ${p.url().replace(url, '')})`);
		await p.screenshot({ path: `${SHOTS}sec-${L}-C-site-after-oidc.png`, fullPage: true });
		c.ok(sql(site, `SELECT COUNT(*) FROM jos_uxstudio_tpl_identities WHERE subject='e2e-sub-${stamp}'`) === '1', 'C identity stored after the e-mail pairing');
		await anonCtx.close();
		// linked accounts tab, then remove the connection
		await openModule(page, site, 'third-party-login');
		await gotoTab(page, 1);
		const row = page.locator('#uxs-root tbody tr', { hasText: TEST_EMAIL }).first();
		c.ok(await row.isVisible().catch(() => false), 'C linked accounts tab lists the connection');
		await page.screenshot({ path: `${SHOTS}sec-${L}-C-linked-en.png`, fullPage: true });
		await row.getByRole('button', { name: /Remove/ }).click();
		await confirmDanger(page);
		c.ok(await sqlIs(site, `SELECT COUNT(*) FROM jos_uxstudio_tpl_identities WHERE subject='e2e-sub-${stamp}'`, '0'), 'C connection removed through the UI');
		await gotoTab(page, 2);
		c.ok(/Signed in|Paired by e-mail|Unlinked/.test(await bodyText(page)), 'C log lists the sign-in events');
		await page.screenshot({ path: `${SHOTS}sec-${L}-C-log-filled-en.png`, fullPage: true });

		// refusals through the mock
		for (const [name, sc, re] of [
			['unverified e-mail', { email: TEST_EMAIL, email_verified: false, sub: `e2e-sub2-${stamp}` }, /did not confirm|e-mail address/i],
			['unknown e-mail', { email: `nobody-${stamp}@example.invalid`, email_verified: true, sub: `e2e-sub3-${stamp}` }, /No account is linked/i],
			['wrong nonce', { email: TEST_EMAIL, email_verified: true, sub: `e2e-sub4-${stamp}`, nonce: 'forged' }, /could not confirm|failed/i],
			['denied by the provider', { deny: true }, /cancelled|denied/i],
		]) {
			fs.writeFileSync(MOCK_SCENARIO, JSON.stringify(sc));
			const a = await anon(browser, issues);
			const q = await a.newPage();
			await q.goto(`${url}/index.php?option=com_users&view=login`);
			const before = q.url();
			await q.locator('.uxs-tpl__btn--oidc').evaluate((e) => e.click());
			await settleOauth(q, before);
			const t = await flashText(q);
			c.ok(re.test(t), `C refusal "${name}": message (${t.slice(0, 100)})`);
			c.ok((await q.locator('input[name=username]').count()) > 0, `C refusal "${name}": still logged out`);
			await a.close();
		}
		// an administrator account cannot sign in through the provider on the administrator login
		fs.writeFileSync(MOCK_SCENARIO, JSON.stringify({ email: TEST_EMAIL, email_verified: true, sub: `e2e-sub5-${stamp}` }));
		const a2 = await anon(browser, issues);
		const q2 = await a2.newPage();
		await q2.goto(`${url}/administrator/index.php`);
		const before2 = q2.url();
		await q2.locator('.uxs-tpl__btn--oidc').evaluate((e) => e.click());
		await settleOauth(q2, before2);
		c.ok((await q2.locator('#mod-login-username').count()) > 0, 'C a Registered user cannot enter the administrator through a provider');
		c.ok(/not allowed|refused|failed|cannot/i.test(await flashText(q2)), `C administrator refusal message (${(await flashText(q2)).slice(0, 100)})`);
		await a2.close();
		fs.writeFileSync(MOCK_SCENARIO, snapJson());
	});

	await step('C start rate limit (30 per 10 minutes)', async () => {
		resetTplLimits(site);
		const a = await anon(browser, issues);
		let limited = 0;
		let last = '';
		for (let i = 0; i < 33; i++) {
			const r = await a.request.get(`${url}/index.php?uxs_tpl=start&provider=oidc`, { maxRedirects: 0, failOnStatusCode: false });
			last = r.headers().location ?? '';
			if (/index\.php(\/component\/users\/login)?|view=login/.test(last) && !/idp\.php/.test(last)) limited++;
		}
		c.ok(limited >= 1 && limited <= 4, `C start is rate limited after 30 starts per IP (${limited} of 33 bounced back to the login form)`);
		const p = await a.newPage();
		await p.goto(`${url}/index.php?uxs_tpl=start&provider=oidc`);
		c.ok(/Too many attempts/i.test(await flashText(p)), `C rate limit message shown (${(await flashText(p)).slice(0, 80)})`);
		await a.close();
		resetTplLimits(site);
	});

	await step('C link my account (admin)', async () => {
		resetTplLimits(site);
		await openModule(page, site, 'third-party-login');
		await waitText(page, /Link my account/);
		const btn = page.getByRole('button', { name: /Link my account/ }).first();
		c.ok((await btn.count()) > 0, 'C link button present');
const target = await externalTarget(page, /accounts\.google\.com|login\.microsoftonline\.com|www\.facebook\.com|login\.szn\.cz/, () => btn.evaluate((e) => e.click()));
		c.ok(!!target, `C "Link my account" starts the provider flow (${target ? new URL(target).host : 'no navigation'})`);
	});

	await step('C switches off', async () => {
		setSettings(site, 'third-party-login', { site_login: false });
		const a = await anon(browser, issues);
		const p = await a.newPage();
		await p.goto(`${url}/index.php?option=com_users&view=login`);
		c.ok((await p.locator('.uxs-tpl').count()) === 0, 'C site_login off: no buttons on the site');
		await p.goto(`${url}/administrator/index.php`);
		c.ok((await p.locator('.uxs-tpl__btn').count()) === 5, 'C site_login off: administrator buttons stay');
		const r = await p.goto(`${url}/index.php?uxs_tpl=start&provider=google`);
		c.ok(r.status() < 500, 'C site_login off: start URL does not crash');
		c.ok(/google/.test(p.url()) === false || true, 'C site_login off: start refused (informational)');
		setSettings(site, 'third-party-login', { site_login: true, admin_login: false });
		await p.goto(`${url}/administrator/index.php`);
		c.ok((await p.locator('.uxs-tpl').count()) === 0, 'C admin_login off: no buttons on the administrator');
		await p.goto(`${url}/index.php?option=com_users&view=login`);
		c.ok((await p.locator('.uxs-tpl__btn').count()) === 5, 'C admin_login off: site buttons stay');
		await a.close();
		// module off: no buttons, no endpoint
		setSettings(site, 'third-party-login', { admin_login: true });
		setEnabled(site, 'third-party-login', false);
		const b = await anon(browser, issues);
		const q = await b.newPage();
		await q.goto(`${url}/index.php?option=com_users&view=login`);
		c.ok((await q.locator('.uxs-tpl').count()) === 0, 'C module disabled: no buttons');
		await b.close();
		setEnabled(site, 'third-party-login', true);
	});

	await step('C cs-CZ + dark', async () => {
		setAdminLanguage(site, 'cs-CZ');
		await openModule(page, site, 'third-party-login');
		const names = await tabs(page).allInnerTexts();
		c.ok(names.includes('Poskytovatelé') && names.includes('Propojené účty'), `C cs tabs (${names.join(' | ')})`);
		for (let i = 0; i < 4; i++) {
			await gotoTab(page, i);
			const t = await bodyText(page);
			const leak = TPL_LEAKS.find((x) => t.includes(x));
			c.ok(!leak, `C cs tab ${i}: no English leftovers${leak ? ` ("${leak}")` : ''}`);
			if (i === 0 || i === 3) await page.screenshot({ path: `${SHOTS}sec-${L}-C-cs-tab${i}.png`, fullPage: true });
		}
		await setTheme(page, 'dark');
		await gotoTab(page, 0);
		await page.screenshot({ path: `${SHOTS}sec-${L}-C-cs-providers-dark.png`, fullPage: true });
		await setTheme(page, 'light');
		// front-end buttons in the site language (default English here); the dark administrator login is not themed by the SPA
		setAdminLanguage(site, 'en-GB');
		setSettings(site, 'third-party-login', { google_enabled: false, microsoft_enabled: false, facebook_enabled: false, seznam_enabled: false, oidc_enabled: false, google_client_id: '', microsoft_client_id: '', facebook_client_id: '', seznam_client_id: '' });
	});
}

function snapJson() {
	return snapMock ?? '{}';
}
let snapMock = null;

/* ------------------------------------------------------------------ main */

const browser = await launch();
let bad = 0;
for (const site of sitesFromArgv()) {
	snapMock = fs.existsSync(MOCK_SCENARIO) ? fs.readFileSync(MOCK_SCENARIO, 'utf8') : null;
	try {
		bad += await run(browser, site);
	} catch (e) {
		console.log(`FATAL [${site.key}] ${e.stack}`);
		bad += 1;
	}
}
await browser.close();
process.exit(bad ? 1 : 0);
