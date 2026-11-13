// Browser E2E for the SPA shell: module grid, core settings, setup wizard, generic settings form, session expiry,
// language / theme switch, history navigation and keyboard access.
// Run: node tests/e2e/shell.mjs [j5|j6|all] [--headed]
// Uses its own administrator account (e2e_shell, copy of the password hash of the admin user, super user) so the admin
// language can be switched without touching other people's sessions. Everything it changes is restored at the end:
// the account, hello-check files / settings, module switches, core + onboarding settings.
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { launch, context, openStudio, checker, sitesFromArgv, SHOTS } from './lib.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const MYSQL = 'D:/xampp/mysql/bin/mysql.exe';
const HELLO_PHP = path.join(ROOT, 'packages/lib_uxstudio/src/Modules/HelloCheck/Module.php');
const HELLO_BAK = path.join(ROOT, 'tests/e2e/.shell-hello-module.bak');
const USER = 'e2e_shell';

function envLocal() {
	const out = {};
	for (const line of fs.readFileSync(path.join(ROOT, '.env.local'), 'utf8').split(/\r?\n/)) {
		const m = line.match(/^([A-Z0-9_]+)=(.*)$/);
		if (m) out[m[1]] = m[2];
	}
	return out;
}
const PASSWORD = envLocal().JOOMLA_ADMIN_PASSWORD;
const ADMIN_USER = envLocal().JOOMLA_ADMIN_USER;

const sql = (site, q) => execFileSync(MYSQL, ['-uroot', '--default-character-set=utf8mb4', '-N', '-B', site.db, '-e', q], { encoding: 'utf8' }).trim();
const esc = (s) => s.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function sqlIs(site, q, expected, tries = 24) {
	let v = '';
	for (let i = 0; i < tries; i++) {
		v = sql(site, q);
		if (v === expected) return true;
		await sleep(250);
	}
	return false;
}
const moduleOn = (site, id) => sql(site, `SELECT enabled FROM jos_uxstudio_modules WHERE id='${id}'`);

/* ------------------------------------------------------------------ account + file patching */

function createUser(site, lang) {
	sql(site, `DELETE FROM jos_user_usergroup_map WHERE user_id IN (SELECT id FROM jos_users WHERE username='${USER}')`);
	sql(site, `DELETE FROM jos_users WHERE username='${USER}'`);
	sql(
		site,
		`INSERT INTO jos_users (name,username,email,password,block,sendEmail,registerDate,params,activation,otpKey,otep,requireReset)
		 SELECT 'E2E Shell','${USER}','e2e-shell@example.invalid',password,0,0,NOW(),'${esc(JSON.stringify({ admin_language: lang }))}','','','',0 FROM jos_users WHERE username='${esc(ADMIN_USER)}' LIMIT 1`
	);
	sql(site, `INSERT INTO jos_user_usergroup_map (user_id,group_id) SELECT id,8 FROM jos_users WHERE username='${USER}'`);
	// the autostarting welcome tour would redirect away from the SPA on the first request
	sql(site, `INSERT INTO jos_user_profiles (user_id,profile_key,profile_value,ordering) SELECT id,'guidedtour.id.12','{"state":"skipped"}',0 FROM jos_users WHERE username='${USER}'`);
}
function setLang(site, lang) {
	sql(site, `UPDATE jos_users SET params='${esc(JSON.stringify({ admin_language: lang }))}' WHERE username='${USER}'`);
}
function dropUser(site) {
	sql(site, `DELETE FROM jos_user_profiles WHERE user_id IN (SELECT id FROM jos_users WHERE username='${USER}')`);
	sql(site, `DELETE FROM jos_user_usergroup_map WHERE user_id IN (SELECT id FROM jos_users WHERE username='${USER}')`);
	sql(site, `DELETE FROM jos_users WHERE username='${USER}'`);
	sql(site, `DELETE FROM jos_session WHERE username='${USER}'`);
}

function restoreHello() {
	if (fs.existsSync(HELLO_BAK)) {
		fs.copyFileSync(HELLO_BAK, HELLO_PHP);
		fs.unlinkSync(HELLO_BAK);
	}
}
/** Temporarily rewrites hello-check/Module.php (restored in `finally` and at the next start after a crash). */
async function patchHello(transform) {
	restoreHello();
	const src = fs.readFileSync(HELLO_PHP, 'utf8');
	fs.writeFileSync(HELLO_BAK, src);
	const out = transform(src);
	if (out === src) throw new Error('hello-check patch did not match');
	const tmp = HELLO_PHP + '.e2e-tmp';
	fs.writeFileSync(tmp, out);
	fs.renameSync(tmp, HELLO_PHP); // atomic: other sessions never read a half written module
	await sleep(2600); // opcache revalidate_freq
}
async function unpatchHello() {
	restoreHello();
	await sleep(2600);
}

for (const sig of ['SIGINT', 'SIGTERM']) process.on(sig, () => { restoreHello(); process.exit(130); });
process.on('exit', () => restoreHello());

const WIDGET_FIELDS = `
            ['key' => 'w_toggle', 'type' => 'toggle', 'label' => 'E2E toggle', 'default' => false],
            ['key' => 'w_select', 'type' => 'select', 'label' => 'E2E select', 'default' => 'a', 'options' => ['a' => 'Option A', 'b' => 'Option B', 'c' => 'Option C']],
            ['key' => 'w_number', 'type' => 'number', 'label' => 'E2E number', 'default' => 5, 'min' => 1, 'max' => 10],
            ['key' => 'w_color', 'type' => 'color', 'label' => 'E2E color', 'default' => '#336699'],
            ['key' => 'w_media', 'type' => 'media', 'label' => 'E2E media'],
            ['key' => 'w_rich', 'type' => 'richtext', 'label' => 'E2E richtext', 'maxlength' => 2000],
            ['key' => 'w_pass', 'type' => 'password', 'label' => 'E2E password'],
            ['key' => 'w_multi', 'type' => 'multiselect', 'label' => 'E2E multiselect', 'options' => ['x' => 'Xray', 'y' => 'Yankee', 'z' => 'Zulu']],
            ['key' => 'w_css', 'type' => 'css', 'label' => 'E2E css', 'maxlength' => 500],
            ['key' => 'w_date', 'type' => 'datetime', 'label' => 'E2E datetime'],
            ['key' => 'w_dep', 'type' => 'text', 'label' => 'E2E dependent text', 'show_if' => ['key' => 'w_toggle', 'in' => [true]]],
            ['key' => 'w_rep', 'type' => 'repeater', 'label' => 'E2E repeater', 'max' => 3, 'add_label' => 'Add E2E row', 'fields' => [
                ['key' => 'title', 'type' => 'text', 'label' => 'Row title', 'default' => ''],
                ['key' => 'size', 'type' => 'number', 'label' => 'Row size', 'default' => 1, 'min' => 1, 'max' => 9],
                ['key' => 'tint', 'type' => 'color', 'label' => 'Row tint', 'default' => '#ff0000'],
            ]],
`;
const withWidgets = (src) => src.replace(/(public function settingsSchema\(\): array\s*\{\s*return \[)/, `$1${WIDGET_FIELDS}`);
const withBootError = (src) => src.replace(/(public function boot\(ModuleContext \$ctx\): void\s*\{)/, `$1\n        throw new \\RuntimeException('E2E simulated boot failure');`);

/* ------------------------------------------------------------------ browser helpers */

const toast = (page, kind) => page.locator(`.uxs-toast.is-${kind}`).first();
const root = (page) => page.locator('#uxs-root');
const tile = (page, name) => page.locator('.uxs-tile', { hasText: name }).first();
const hashOf = (page) => page.evaluate(() => location.hash);

let LAST = null;
async function login(ctx, site) {
	const page = await ctx.newPage();
	LAST = page;
	await page.setViewportSize({ width: 1700, height: 1000 }); // the Joomla sidebar eats 288px; below ~1100px of SPA width the SPA sidebar becomes a drawer
	await page.goto(`${site.url}/administrator/index.php`);
	await page.fill('#mod-login-username', USER);
	await page.fill('#mod-login-password', PASSWORD);
	await page.click('#btn-login-submit');
	await page.locator('#mod-login-username').waitFor({ state: 'detached', timeout: 20000 });
	await page.waitForLoadState('load', { timeout: 90000 });
	if (await page.locator('#mod-login-username').count()) throw new Error(`login failed on ${site.key}`);
	return page;
}
/** Reloads until the SPA root has mounted (a concurrent PHP edit by another session can break one request). */
async function reloadStudio(page, ready) {
	for (let i = 0; i < 3; i++) {
		await page.reload();
		if (await page.locator(ready).first().waitFor({ timeout: 12000 }).then(() => true, () => false)) return;
	}
	throw new Error('SPA did not mount after 3 reloads (' + ready + ')');
}
async function setTheme(page, theme) {
	await page.evaluate((v) => localStorage.setItem('uxstudio-theme', v), theme);
}
async function snap(page, name) {
	await page.screenshot({ path: `${SHOTS}shell-${name}.png`, fullPage: true });
}
async function frontend(browser, site) {
	const ctx = await browser.newContext();
	const res = await ctx.request.get(`${site.url}/index.php`);
	const body = await res.text();
	await ctx.close();
	return { status: res.status(), body };
}

/** Fetches the front end until the hello-check stamp is (not) present - tolerates other sessions touching the module. */
async function frontendStamp(browser, site, want) {
	let res = await frontend(browser, site);
	for (let i = 0; i < 8 && res.body.includes('UX1 Studio hello-check:') !== want; i++) {
		await sleep(1000);
		res = await frontend(browser, site);
	}
	return res;
}

/* Visible English words in chrome of the Czech UI (module names / descriptions come from meta.json and are exempt). */
const ENGLISH = /\b(the|and|your|you|this|with|for|turn on|enabled|disabled|search|settings|save|cancel|back|continue|module|modules)\b/i;
async function englishLeaks(page) {
	return page.evaluate((reSrc) => {
		const re = new RegExp(reSrc, 'i');
		const out = [];
		const walker = document.createTreeWalker(document.getElementById('uxs-root'), NodeFilter.SHOW_TEXT);
		for (let n = walker.nextNode(); n; n = walker.nextNode()) {
			const el = n.parentElement;
			if (!el || el.closest('option, .uxs-tile__desc, .uxs-tile__name, .uxs-sidebar__item[title], script, style, code, .uxs-wiz-choice__body, .uxs-wiz-facts, .uxs-wiz-types, .uxs-card')) continue;
			const txt = n.textContent.trim();
			if (txt.length > 2 && re.test(txt)) out.push(txt.slice(0, 80));
		}
		return out;
	}, ENGLISH.source);
}

/* ------------------------------------------------------------------ phases */

async function phaseGrid(browser, site, c, lang) {
	const en = lang === 'en-GB';
	const { ctx, issues } = await context(browser, { ignore: [/HTTP 409/, /status of 409/] });
	const page = await login(ctx, site);
	await openStudio(page, site);
	await setTheme(page, 'light');
	await page.reload();
	await page.locator('.uxs-tile').first().waitFor();
	const L = (a, b) => (en ? a : b);

	c.ok(await root(page).getAttribute('data-theme') === 'light', `[${lang}] light theme applied`);
	const total = await page.locator('.uxs-tile').count();
	c.ok(total >= 29, `[${lang}] grid lists ${total} modules`);
	const summary = await page.locator('.uxs-modulefilter__empty').first().innerText();
	c.ok(/\d+/.test(summary), `[${lang}] summary line "${summary}"`);
	await snap(page, `${site.key}-grid-${lang}-light`);

	// Search + empty state
	const search = page.locator('.uxs-modulefilter__search');
	await search.fill('hello');
	c.ok((await page.locator('.uxs-tile').count()) === 1, `[${lang}] search "hello" -> one tile`);
	await search.fill('zzzz-nothing-matches');
	c.ok((await page.locator('.uxs-tile').count()) === 0 && (await page.locator('.uxs-modulefilter__empty').count()) >= 2, `[${lang}] empty state for no match`);
	await snap(page, `${site.key}-grid-${lang}-empty`);
	await search.fill('   ');
	c.ok((await page.locator('.uxs-tile').count()) === total, `[${lang}] whitespace-only search shows all`);
	await search.fill('');

	// Group tabs
	const tabs = page.locator('.uxs-subtabs button[role=tab]');
	const tabCount = await tabs.count();
	c.ok(tabCount >= 4, `[${lang}] ${tabCount} group tabs`);
	let sum = 0;
	for (let i = 1; i < tabCount; i++) {
		await tabs.nth(i).click();
		const n = await page.locator('.uxs-tile').count();
		const label = await tabs.nth(i).innerText();
		const expected = Number((label.match(/\((\d+)\)/) ?? [])[1]);
		sum += n;
		c.ok(n === expected && (await tabs.nth(i).getAttribute('aria-selected')) === 'true', `[${lang}] tab "${label.replace(/\s+/g, ' ')}" shows ${n}`);
	}
	c.ok(sum === total, `[${lang}] group tab counts add up to ${total} (got ${sum})`);
	await tabs.nth(0).click();

	// Status filter
	const status = page.locator('.uxs-modulefilter__status');
	await status.selectOption('off');
	const offN = await page.locator('.uxs-tile').count();
	c.ok(offN >= 1 && (await page.locator('.uxs-tile .uxs-switch.is-on').count()) === 0, `[${lang}] "disabled" filter shows ${offN} off modules only`);
	await status.selectOption('on');
	c.ok((await page.locator('.uxs-tile .uxs-switch:not(.is-on)').count()) === 0, `[${lang}] "enabled" filter shows only on modules`);
	await status.selectOption('all');
	await snap(page, `${site.key}-grid-${lang}-filtered`);

	if (en) {
		// Switch hello-check off / on (UI) + visible effect on the front end
		const before = await frontendStamp(browser, site, true);
		c.ok(before.body.includes('UX1 Studio hello-check:'), `[${lang}] front end carries the hello-check stamp while on`);
		await search.fill('hello');
		const sw = page.locator('.uxs-tile .uxs-switch').first();
		await sw.click();
		c.ok(await sqlIs(site, "SELECT enabled FROM jos_uxstudio_modules WHERE id='hello-check'", '0'), `[${lang}] hello-check switched off in the DB`);
		c.ok((await sw.getAttribute('aria-pressed')) === 'false', `[${lang}] switch shows off`);
		const off = await frontendStamp(browser, site, false);
		c.ok(off.status === 200 && !off.body.includes('UX1 Studio hello-check:'), `[${lang}] stamp gone from the front end after switching off`);
		// disabled module page offers "Turn on"
		await page.locator('.uxs-tile').first().click();
		await page.locator('.uxs-notice .uxs-btn--primary').waitFor();
		c.ok(true, `[${lang}] disabled module page shows the "turn on" notice`);
		await snap(page, `${site.key}-module-disabled`);
		await page.locator('.uxs-notice .uxs-btn--primary').click();
		c.ok(await sqlIs(site, "SELECT enabled FROM jos_uxstudio_modules WHERE id='hello-check'", '1'), `[${lang}] "Turn on" enables hello-check`);
		const on = await frontendStamp(browser, site, true);
		c.ok(on.body.includes('UX1 Studio hello-check:'), `[${lang}] stamp back on the front end`);

		// Back / forward navigation
		await page.goBack();
		await page.waitForFunction(() => location.hash.startsWith('#/modules') || location.hash === '');
		c.ok((await page.locator('.uxs-tile').count()) >= 1 || (await page.locator('.uxs-modulefilter__search').count()) === 1, `[${lang}] back returns to the grid`);
		await page.goForward();
		await page.waitForFunction(() => location.hash.startsWith('#/module?id=hello-check'));
		c.ok(/hello/i.test(await root(page).locator('h1').first().innerText()), `[${lang}] forward returns to the Hello check module page`);

		// Unknown module + unknown route
		await page.goto(`${site.url}/administrator/index.php?option=com_uxstudio#/module?id=does-not-exist`);
		await page.locator('.uxs-notice.is-error').waitFor();
		c.ok(true, `[${lang}] unknown module id shows an error notice`);
		await page.goto(`${site.url}/administrator/index.php?option=com_uxstudio#/nonsense`);
		await page.locator('.uxs-modulefilter__search').waitFor();
		c.ok(true, `[${lang}] unknown route falls back to the grid`);

		// Keyboard: Tab to a tile, Enter opens it
		await page.goto(`${site.url}/administrator/index.php?option=com_uxstudio#/modules`);
		await page.locator('.uxs-modulefilter__search').fill('hello');
		await page.locator('.uxs-tile[role=link]').first().focus();
		await page.keyboard.press('Enter');
		await page.waitForFunction(() => location.hash.includes('id=hello-check'));
		c.ok(true, `[${lang}] Enter on a focused tile opens the module`);
		// Space on a focused switch toggles and does not navigate (focus a switch of a tile)
		await page.goto(`${site.url}/administrator/index.php?option=com_uxstudio#/modules`);
		await page.locator('.uxs-modulefilter__search').fill('hello');
		const sw2 = page.locator('.uxs-tile .uxs-switch').first();
		await sw2.focus();
		await page.keyboard.press('Space');
		const spaceToggled = await sqlIs(site, "SELECT enabled FROM jos_uxstudio_modules WHERE id='hello-check'", '0');
		c.ok(spaceToggled && (await hashOf(page)).startsWith('#/modules'), `[${lang}] Space on a switch toggles it without opening the module`);
		await sw2.click();
		c.ok(await sqlIs(site, "SELECT enabled FROM jos_uxstudio_modules WHERE id='hello-check'", '1'), `[${lang}] hello-check on again`);
		await sw2.focus();
		await page.keyboard.press('Enter');
		const enterToggled = await sqlIs(site, "SELECT enabled FROM jos_uxstudio_modules WHERE id='hello-check'", '0');
		await sleep(400);
		c.ok(enterToggled && (await hashOf(page)).startsWith('#/modules'), `[${lang}] Enter on a switch toggles it without opening the module (hash ${await hashOf(page)})`);
		await sw2.click();
		await sqlIs(site, "SELECT enabled FROM jos_uxstudio_modules WHERE id='hello-check'", '1');
		const posts = [];
		const onReq = (rq) => rq.method() === 'POST' && /core\/modules\/hello-check/.test(rq.url()) && posts.push(rq.postData());
		page.on('request', onReq);
		await sw2.click({ clickCount: 3, delay: 30 });
		await sleep(2500);
		page.off('request', onReq);
		const dbNow = moduleOn(site, 'hello-check');
		const uiNow = (await sw2.getAttribute('aria-pressed')) === 'true' ? '1' : '0';
		c.ok(posts.length === 1 && dbNow === uiNow, `[${lang}] triple click sends one request and UI == DB (posts ${posts.length}, db ${dbNow}, ui ${uiNow})`);
		if (dbNow !== '1') {
			await sw2.click();
			await sqlIs(site, "SELECT enabled FROM jos_uxstudio_modules WHERE id='hello-check'", '1');
		}
	} else {
		const leaks = await englishLeaks(page);
		c.ok(leaks.length === 0, `[${lang}] no English leaks in grid chrome ${leaks.slice(0, 5).join(' | ')}`);
		for (const [plain, accented] of [['presmer', 'přesměr'], ['formular', 'formulář']]) {
			await search.fill(accented);
			const withD = await page.locator('.uxs-tile').count();
			await search.fill(plain);
			const without = await page.locator('.uxs-tile').count();
			c.ok(withD >= 1 && without === withD, `[${lang}] search "${plain}" == "${accented}" (${without} / ${withD})`);
		}
		await search.fill('');
	}
	await setTheme(page, 'dark');
	await page.goto(`${site.url}/administrator/index.php?option=com_uxstudio#/modules`);
	await reloadStudio(page, '.uxs-tile');
	c.ok((await root(page).getAttribute('data-theme')) === 'dark', `[${lang}] grid renders in dark mode`);
	await snap(page, `${site.key}-grid-${lang}-dark`);
	const lowContrast = await page.evaluate(() => {
		const lum = (rgb) => {
			const [r, g, b] = rgb.match(/[\d.]+/g).slice(0, 3).map(Number).map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; });
			return 0.2126 * r + 0.7152 * g + 0.0722 * b;
		};
		const bgOf = (el) => {
			for (let n = el; n; n = n.parentElement) {
				const bg = getComputedStyle(n).backgroundColor;
				const a = bg.match(/[\d.]+/g);
				if (a && (a.length < 4 || Number(a[3]) > 0.9)) return bg;
			}
			return 'rgb(0,0,0)';
		};
		const bad = [];
		for (const el of document.querySelectorAll('#uxs-root .uxs-tile__name, #uxs-root .uxs-tile__desc, #uxs-root .uxs-sidebar__item, #uxs-root .uxs-subtabs button, #uxs-root .uxs-modulefilter__empty')) {
			const f = lum(getComputedStyle(el).color);
			const b = lum(bgOf(el));
			const ratio = (Math.max(f, b) + 0.05) / (Math.min(f, b) + 0.05);
			if (ratio < 3) bad.push(`${el.className} ${ratio.toFixed(1)}`);
		}
		return bad;
	});
	c.ok(lowContrast.length === 0, `[${lang}] dark grid text keeps contrast >= 3:1 ${lowContrast.slice(0, 3).join(' | ')}`);
	await setTheme(page, 'light');
	await ctx.close();
	return issues;
}

async function phaseBootError(browser, site, c) {
	const { ctx, issues } = await context(browser, { ignore: [/E2E simulated/i, /HTTP 409/, /status of 409/] });
	await patchHello(withBootError);
	try {
		const page = await login(ctx, site);
		await openStudio(page, site);
		await page.locator('.uxs-tile').first().waitFor();
		const t = tile(page, 'Hello check');
		c.ok((await t.locator('.uxs-badge.is-danger').count()) === 1, 'boot error badge on the hello-check tile');
		c.ok(((await t.locator('.uxs-badge.is-danger').getAttribute('title')) ?? '').includes('E2E simulated'), 'badge title carries the error message');
		c.ok((await page.locator('.uxs-tile .uxs-badge.is-danger').count()) === 1, 'only the broken module has the badge');
		await snap(page, `${site.key}-grid-booterror`);
		await t.click();
		await page.locator('.uxs-notice.is-error code').waitFor();
		c.ok((await page.locator('.uxs-notice.is-error').innerText()).includes('E2E simulated'), 'module page explains the boot error');
		await snap(page, `${site.key}-module-booterror`);
		const fe = await frontend(browser, site);
		c.ok(fe.status === 200 && fe.body.includes('</html>'), `other modules and the front end survive a boot error (HTTP ${fe.status})`);
		// SPA of other modules still opens
		await page.goto(`${site.url}/administrator/index.php?option=com_uxstudio#/module?id=redirect-404-to-homepage`);
		await page.locator('#uxs-code').waitFor();
		c.ok(true, 'another module still opens its settings');
	} finally {
		await unpatchHello();
		await ctx.close();
	}
	return issues;
}

async function phaseCore(browser, site, c, lang) {
	const en = lang === 'en-GB';
	const { ctx, issues } = await context(browser, { ignore: [/HTTP 422/, /status of 422/] });
	const page = await login(ctx, site);
	await openStudio(page, site, '#/settings');
	await setTheme(page, 'light');
	await page.reload();
	await page.locator('#uxs-client_ip_mode').waitFor();
	const L = (a, b) => (en ? a : b);
	c.ok((await page.locator('.uxs-sidebar__item.is-active').innerText()).trim().length > 0, `[${lang}] Settings entry is active in the sidebar`);
	await snap(page, `${site.key}-core-${lang}-light`);
	const original = sql(site, "SELECT data FROM jos_uxstudio_settings WHERE module='core'");
	try {
		const mode = page.locator('#uxs-client_ip_mode');
		const proxies = page.locator('#uxs-trusted_proxies');
		c.ok(await proxies.isVisible(), `[${lang}] trusted proxies visible in cloudflare mode`);
		await mode.selectOption('none');
		await proxies.waitFor({ state: 'detached', timeout: 3000 });
		c.ok(true, `[${lang}] trusted proxies hidden in "no proxy" mode (show_if)`);
		await page.locator('#uxs-root form button[type=submit]').click();
		await toast(page, 'success').waitFor({ timeout: 8000 });
		c.ok(await sqlIs(site, "SELECT JSON_UNQUOTE(JSON_EXTRACT(data,'$.client_ip_mode')) FROM jos_uxstudio_settings WHERE module='core'", 'none'), `[${lang}] mode "none" saved`);
		await page.reload();
		await page.locator('#uxs-client_ip_mode').waitFor();
		c.ok((await page.locator('#uxs-client_ip_mode').inputValue()) === 'none', `[${lang}] mode survives a reload`);
		// reverse proxy + garbage in the proxy list
		await page.locator('#uxs-client_ip_mode').selectOption('xff');
		await proxies.waitFor();
		await proxies.fill('10.0.0.0/8\nnot-an-ip\n<script>alert(1)</script>\n203.0.113.5');
		await page.locator('#uxs-root form button[type=submit]').click();
		await toast(page, 'success').waitFor({ timeout: 8000 });
		const stored = sql(site, "SELECT JSON_UNQUOTE(JSON_EXTRACT(data,'$.trusted_proxies')) FROM jos_uxstudio_settings WHERE module='core'");
		c.ok(!/<script/i.test(stored) && stored.includes('203.0.113.5'), `[${lang}] markup in the proxy list is stripped, valid entries kept (stored ${JSON.stringify(stored).slice(0, 80)})`);
		const huge = Array.from({ length: 3000 }, (_, i) => `10.${i % 250}.0.${i % 250}`).join('\n') + '\nžluťoučký kůň';
		await proxies.evaluate((el, v) => { Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value').set.call(el, v); el.dispatchEvent(new Event('input', { bubbles: true })); }, huge);
		await page.locator('.uxs-toast').first().waitFor({ state: 'detached', timeout: 8000 }).catch(() => undefined);
		await page.locator('#uxs-root form button[type=submit]').click();
		await toast(page, 'error').waitFor({ timeout: 8000 });
		const afterHuge = sql(site, "SELECT JSON_UNQUOTE(JSON_EXTRACT(data,'$.trusted_proxies')) FROM jos_uxstudio_settings WHERE module='core'");
		c.ok(afterHuge === stored, `[${lang}] over-long proxy list is refused, stored value unchanged (toast "${(await toast(page, 'error').innerText()).trim().slice(0, 70)}")`);
		await proxies.fill('203.0.113.9\n# žluťoučký kůň');
		const settingsPosts = [];
		const onPost = (rq) => rq.method() === 'POST' && /core\/settings\/core/.test(rq.url()) && settingsPosts.push(1);
		page.on('request', onPost);
		await page.locator('#uxs-root form button[type=submit]').dblclick();
		await sleep(2000);
		page.off('request', onPost);
		c.ok(settingsPosts.length === 1 && (await page.locator('.uxs-toast.is-success').count()) <= 1, `[${lang}] double click on Save sends one request (${settingsPosts.length}) and shows one toast`);
		await snap(page, `${site.key}-core-${lang}-garbage`);
		// theme: dark
		await page.locator('.uxs-sidebar__foot .uxs-sidebar__item').click();
		c.ok((await root(page).getAttribute('data-theme')) === 'dark' && (await page.evaluate(() => localStorage.getItem('uxstudio-theme'))) === 'dark', `[${lang}] theme toggle -> dark, remembered`);
		await snap(page, `${site.key}-core-${lang}-dark`);
		await page.reload();
		await page.locator('#uxs-client_ip_mode').waitFor();
		c.ok((await root(page).getAttribute('data-theme')) === 'dark', `[${lang}] dark theme survives a reload`);
		await page.locator('.uxs-sidebar__foot .uxs-sidebar__item').click();
		c.ok((await root(page).getAttribute('data-theme')) === 'light', `[${lang}] toggled back to light`);
		if (!en) {
			const leaks = await englishLeaks(page);
			c.ok(leaks.length === 0, `[${lang}] no English leaks on the core settings ${leaks.slice(0, 5).join(' | ')}`);
		}
	} finally {
		sql(site, `UPDATE jos_uxstudio_settings SET data='${esc(original)}' WHERE module='core'`);
	}
	await ctx.close();
	return issues;
}


/** Fakes a fresh installation (pending wizard) for the onboarding GET; the state POST flips it to "dismissed" without touching the DB. */
async function mockOnboarding(page, auto) {
	const st = { dismissed: false };
	await page.route(/route=core\/onboarding(&|$)/, async (route) => {
		try {
			if (route.request().method() !== 'GET') return await route.continue();
			const res = await route.fetch();
			const json = await res.json();
			if (!st.dismissed) {
				json.data.pending = true;
				json.data.auto = auto;
				json.data.state.status = 'new';
			}
			await route.fulfill({ response: res, json });
		} catch {
			/* page navigated away */
		}
	});
	await page.route(/route=core\/onboarding\/state/, async (route) => {
		st.dismissed = true;
		await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, data: { state: { status: 'dismissed' } } }) }).catch(() => undefined);
	});
}
async function unmock(page) {
	await page.unrouteAll({ behavior: 'ignoreErrors' });
}

async function phaseWizard(browser, site, c, lang) {
	const en = lang === 'en-GB';
	const { ctx, issues } = await context(browser);
	const page = await login(ctx, site);
	await openStudio(page, site);
	const wizState = sql(site, "SELECT data FROM jos_uxstudio_settings WHERE module='_onboarding'");
	const helloWasOn = moduleOn(site, 'instagram-feed');
	try {
		// ---- A. mocked "fresh installation": auto-open, skip
		await mockOnboarding(page, true);
		await page.reload();
		await page.waitForFunction(() => location.hash.startsWith('#/wizard'), null, { timeout: 25000 });
		c.ok(true, `[${lang}] fresh installation opens the wizard by itself`);
		await page.locator('.uxs-wiz').waitFor();
		await snap(page, `${site.key}-wizard-${lang}-welcome`);
		const skip = page.locator('.uxs-wiz-head .uxs-btn');
		c.ok(await skip.isVisible(), `[${lang}] skip button visible`);
		await skip.click();
		await page.waitForFunction(() => location.hash.startsWith('#/modules'));
		await page.locator('.uxs-modulefilter__search').waitFor();
		c.ok(true, `[${lang}] skipping the wizard returns to the grid`);
		await sleep(700);
		c.ok((await hashOf(page)).startsWith('#/modules'), `[${lang}] wizard does not re-open after skipping`);
		await unmock(page);

		// ---- B. mocked pending banner: dismiss
		await mockOnboarding(page, false);
		await page.reload();
		await page.locator('.uxs-wizhint').waitFor();
		await snap(page, `${site.key}-wizard-${lang}-hint`);
		await page.locator('.uxs-wizhint__close').click();
		await page.locator('.uxs-wizhint').waitFor({ state: 'detached' });
		c.ok(true, `[${lang}] hint banner can be dismissed`);
		await unmock(page);

		// ---- C. real run again, whole flow
		if (en) {
			// instagram-feed off so the wizard has something to turn on
			await page.goto(`${site.url}/administrator/index.php?option=com_uxstudio#/modules`);
			await page.locator('.uxs-modulefilter__search').fill('instagram');
			await page.locator('.uxs-tile .uxs-switch').first().click();
			c.ok(await sqlIs(site, "SELECT enabled FROM jos_uxstudio_modules WHERE id='instagram-feed'", '0'), 'instagram-feed off before the wizard run');
			await page.reload();
		} else {
			await page.reload();
		}
		await page.locator('.uxs-wizhint-again .uxs-wiz-linkbtn').click();
		await page.locator('.uxs-wiz').waitFor();
		const steps = page.locator('.uxs-wiz-steps li');
		const nSteps = await steps.count();
		c.ok(nSteps === 6, `[${lang}] stepper has ${nSteps} steps (with Central App)`);
		// welcome
		c.ok((await page.locator('.uxs-wiz-facts dd').nth(1).innerText()).startsWith('http'), `[${lang}] welcome shows the site address`);
		await page.locator('.uxs-wiz-actions .uxs-btn--primary').click();
		// type
		const types = page.locator('.uxs-wiz-type');
		c.ok((await types.count()) >= 3, `[${lang}] ${await types.count()} site types`);
		await types.nth(1).click();
		c.ok((await types.nth(1).getAttribute('aria-checked')) === 'true' && (await types.nth(0).getAttribute('aria-checked')) === 'false', `[${lang}] choosing a type selects exactly one`);
		await snap(page, `${site.key}-wizard-${lang}-type`);
		await page.locator('.uxs-wiz-actions .uxs-btn--primary').click();
		// modules
		await page.locator('.uxs-wiz-choices').first().waitFor();
		const cont = page.locator('.uxs-wiz-actions .uxs-btn--primary');
		const contBefore = (await cont.innerText()).trim();
		await page.locator('.uxs-wiz-bulk .uxs-wiz-linkbtn').nth(1).click(); // clear selection
		const noneText = (await cont.innerText()).trim();
		await page.locator('.uxs-wiz-bulk .uxs-wiz-linkbtn').nth(0).click(); // select recommended
		const recText = (await cont.innerText()).trim();
		c.ok(/without|bez/i.test(noneText), `[${lang}] continue label with no module selected: "${noneText}" (was "${contBefore}", recommended "${recText}")`);
		await page.locator('.uxs-wiz-bulk .uxs-wiz-linkbtn').nth(1).click();
		await snap(page, `${site.key}-wizard-${lang}-modules`);
		// more modules + tick instagram-feed only (never bot-throttle)
		const more = page.locator('.uxs-wiz-packgroup button[aria-expanded]');
		if ((await more.count()) && (await more.getAttribute('aria-expanded')) === 'false') await more.click();
		const helloTick = page.locator('.uxs-wiz-tick[aria-label="Instagram Feed"]');
		if (en) {
			c.ok((await helloTick.count()) === 1 && (await helloTick.isEnabled()), '[en-GB] instagram-feed (off) can be ticked');
			await helloTick.click();
			c.ok((await helloTick.getAttribute('aria-checked')) === 'true', '[en-GB] tick checks');
			c.ok(/with 1 module/i.test(await cont.innerText()), '[en-GB] continue label counts the ticked module');
			const enabledTicks = page.locator('.uxs-wiz-tick:disabled');
			c.ok((await enabledTicks.count()) >= 20, `[en-GB] modules that are already on are locked (${await enabledTicks.count()})`);
			await snap(page, `${site.key}-wizard-${lang}-modules-more`);
		}
		await cont.click();
		// settings: invalid e-mail -> error, then clear
		const sender = page.locator('#uxs-wiz-sender_email');
		await sender.fill('not an email');
		await page.locator('.uxs-wiz-actions .uxs-btn--primary').click();
		c.ok((await page.locator('.uxs-wiz-error-text[role=alert]').count()) >= 1, `[${lang}] invalid e-mail blocks the step with an alert`);
		await snap(page, `${site.key}-wizard-${lang}-settings-error`);
		await sender.fill('');
		await page.locator('#uxs-wiz-contact_email').fill('');
		await page.locator('#uxs-wiz-sender_name').fill('');
		await page.locator('.uxs-wiz-actions .uxs-btn--primary').click();
		// central app: back & forth then skip
		await page.locator('.uxs-wiz-ca').waitFor();
		await snap(page, `${site.key}-wizard-${lang}-ca`);
		await page.locator('.uxs-wiz-ca__barbtns .uxs-btn').nth(0).click(); // back
		await page.locator('#uxs-wiz-sender_email').waitFor();
		c.ok(true, `[${lang}] back from Central App returns to settings`);
		await page.locator('.uxs-wiz-actions .uxs-btn--primary').click();
		await page.locator('.uxs-wiz-ca__barbtns .uxs-btn').nth(1).click(); // skip
		await page.locator('.uxs-wiz-summary').waitFor();
		await snap(page, `${site.key}-wizard-${lang}-summary`);
		const summaryText = await page.locator('.uxs-wiz-summary').innerText();
		if (en) c.ok(summaryText.includes('Instagram Feed'), '[en-GB] summary lists the chosen module');
		// Stepper is not clickable, header skip is visible. Run it.
		await page.locator('.uxs-wiz-actions .uxs-btn--primary').click();
		await page.locator('.uxs-wiz-finish').waitFor({ timeout: 20000 });
		await snap(page, `${site.key}-wizard-${lang}-done`);
		if (en) {
			c.ok(await sqlIs(site, "SELECT enabled FROM jos_uxstudio_modules WHERE id='instagram-feed'", '1'), '[en-GB] wizard turned instagram-feed on');
			c.ok((await page.locator('.uxs-wiz-modulelinks .uxs-btn').count()) === 1, '[en-GB] done page links the module that was turned on');
		}
		c.ok(!(await page.locator('.uxs-wiz-head .uxs-btn').count()), `[${lang}] skip button hidden once finished`);
		await page.locator('.uxs-wiz-actions .uxs-btn').last().click();
		await page.waitForFunction(() => location.hash.startsWith('#/modules'));
		await page.locator('.uxs-wizhint-again').waitFor();
		c.ok(true, `[${lang}] "Back to modules" leaves the wizard, "run again" link shown`);
		if (en) {
			await page.locator('.uxs-modulefilter__search').fill('instagram');
			const shownOn = await page.waitForFunction(() => document.querySelector('.uxs-tile .uxs-switch')?.getAttribute('aria-pressed') === 'true', null, { timeout: 5000 }).then(() => true, () => false);
			c.ok(shownOn, '[en-GB] grid shows the module the wizard turned on');
		}
		if (!en) {
			await page.goto(`${site.url}/administrator/index.php?option=com_uxstudio#/wizard`);
			await page.locator('.uxs-wiz').waitFor();
			const leaks = await englishLeaks(page);
			c.ok(leaks.length === 0, `[${lang}] no English leaks in the wizard welcome ${leaks.slice(0, 5).join(' | ')}`);
		}
		// dark theme on the wizard
		await setTheme(page, 'dark');
		await page.goto(`${site.url}/administrator/index.php?option=com_uxstudio#/wizard`);
		await page.reload();
		await page.locator('.uxs-wiz').waitFor();
		await snap(page, `${site.key}-wizard-${lang}-dark`);
		await page.locator('.uxs-wiz-actions .uxs-btn--primary').click();
		await page.locator('.uxs-wiz-type').first().click();
		await snap(page, `${site.key}-wizard-${lang}-dark-type`);
		await setTheme(page, 'light');
	} finally {
		sql(site, `UPDATE jos_uxstudio_settings SET data='${esc(wizState)}' WHERE module='_onboarding'`);
		sql(site, `UPDATE jos_uxstudio_modules SET enabled=${helloWasOn} WHERE id='instagram-feed'`);
	}
	await ctx.close();
	return issues;
}

async function phaseWidgets(browser, site, c) {
	const { ctx, issues } = await context(browser, { ignore: [/HTTP 422/, /status of 422/] });
	const helloRow = sql(site, "SELECT data FROM jos_uxstudio_settings WHERE module='hello-check'");
	await patchHello(withWidgets);
	try {
		const page = await login(ctx, site);
		await openStudio(page, site, '#/module?id=hello-check');
		await page.locator('[role=tab]', { hasText: /Settings|Nastavení/ }).first().click();
		await page.locator('#uxs-w_select').waitFor();
		await snap(page, `${site.key}-generic-form-light`);

		// select + toggle + show_if
		await page.locator('#uxs-w_select').selectOption('b');
		const dep = page.locator('#uxs-w_dep');
		c.ok((await dep.count()) === 0, 'dependent field hidden while toggle is off');
		await page.locator('.uxs-form__row:has(label[for="uxs-w_toggle"]) .uxs-switch').click();
		await dep.waitFor();
		c.ok(true, 'toggle reveals the dependent field');
		await dep.fill('<b>dep</b> text');
		// number out of range
		await page.locator('#uxs-w_number').fill('99');
		c.ok(await page.locator('#uxs-w_number').evaluate((el) => el.validity.rangeOverflow), 'number: value above max is flagged by the browser');
		await page.locator('#uxs-w_number').fill('7');
		// color
		await page.locator('#uxs-w_color').fill('#12ab34');
		// multiselect
		await page.locator('.uxs-checklist__item', { hasText: 'Yankee' }).locator('input').check();
		await page.locator('.uxs-checklist__item', { hasText: 'Zulu' }).locator('input').check();
		// css
		await page.locator('#uxs-w_css').fill('a{color:red}</style><script>x</script>');
		// datetime
		await page.locator('#uxs-w_date').fill('2030-01-02T03:04');
		// password
		await page.locator('#uxs-w_pass').fill('s3cret-value');
		// richtext
		const rte = page.locator('.uxs-rte [contenteditable=true]').first();
		await rte.click();
		await page.keyboard.type('Rich ');
		await page.locator('.uxs-rte__btn[aria-label="Bold"]').click();
		await page.keyboard.type('bold');
		c.ok((await rte.innerHTML()).includes('<b>') || (await rte.innerHTML()).includes('<strong>'), 'richtext: bold button wraps typed text');
		await page.locator('.uxs-rte__btn--source').click();
		const src = page.locator('.uxs-rte textarea').first();
		c.ok(/bold/.test(await src.inputValue()), 'richtext: HTML source view shows the markup');
		await src.fill('<p>Src <i>mode</i> <script>alert(1)</script></p>');
		await page.locator('.uxs-rte__btn--source').click();
		// repeater
		const add = page.locator('.uxs-repeater > .uxs-btn', { hasText: 'Add E2E row' });
		await add.click();
		await add.click();
		await add.click();
		c.ok(await add.isDisabled(), 'repeater: "add" disabled at the maximum (3 rows)');
		const rows = page.locator('.uxs-repeater__row');
		c.ok((await rows.count()) === 3, 'repeater: three rows');
		for (let i = 0; i < 3; i++) await rows.nth(i).locator('input[type=text]').first().fill(`Row ${i + 1}`);
		await rows.nth(0).locator('[aria-label="Move down"], .uxs-repeater__tools button').nth(1).click();
		c.ok((await rows.nth(1).locator('input[type=text]').first().inputValue()) === 'Row 1', 'repeater: move down swaps rows');
		c.ok(await rows.nth(0).locator('.uxs-repeater__tools button').first().isDisabled(), 'repeater: first row "move up" disabled');
		await rows.nth(2).locator('.uxs-repeater__tools button').nth(2).click();
		c.ok((await rows.count()) === 2 && !(await add.isDisabled()), 'repeater: remove a row re-enables add');
		await rows.nth(0).locator('input[type=number]').fill('5');
		await snap(page, `${site.key}-generic-form-filled`);

		// media manager modal: open, Esc closes, focus returns
		const selectBtn = page.locator('.uxs-media .uxs-btn', { hasText: /Select|Vybrat/ }).first();
		await selectBtn.click();
		await page.locator('[role=dialog] iframe.uxs-media__frame').waitFor();
		await sleep(1500);
		await snap(page, `${site.key}-generic-media-modal`);
		const dlgPick = page.locator('[role=dialog] .uxs-modal__actions .uxs-btn--primary');
		c.ok(await dlgPick.isDisabled(), 'media modal: Select disabled until a file is clicked');
		await page.keyboard.press('Escape');
		await page.locator('[role=dialog]').waitFor({ state: 'detached' });
		c.ok(true, 'media modal closes with Esc');
		c.ok(await selectBtn.evaluate((el) => el === document.activeElement), 'media modal: focus returns to the trigger');
		await selectBtn.click();
		await page.locator('[role=dialog]').waitFor();
		await page.locator('[role=dialog] .uxs-modal__actions .uxs-btn', { hasText: /Cancel|Zrušit/ }).click();
		await page.locator('[role=dialog]').waitFor({ state: 'detached' });
		c.ok(true, 'media modal closes with Cancel');
		// media: javascript: path must be rejected by the server
		await page.locator('#uxs-w_media').fill('javascript:alert(1)');
		await page.locator('#uxs-root form button[type=submit]').click();
		await toast(page, 'error').waitFor({ timeout: 8000 });
		c.ok(true, `invalid media path -> error toast ("${(await toast(page, 'error').innerText()).trim().slice(0, 60)}")`);
		await snap(page, `${site.key}-generic-form-error`);
		await page.locator('#uxs-w_media').fill('images/joomla_black.png');
		await page.locator('#uxs-root form button[type=submit]').click();
		await toast(page, 'success').waitFor({ timeout: 8000 });
		c.ok(true, 'valid values save with a success toast');

		// persistence after reload + sanitising
		await page.reload();
		await page.locator('[role=tab]', { hasText: /Settings|Nastavení/ }).first().click();
		await page.locator('#uxs-w_select').waitFor();
		c.ok((await page.locator('#uxs-w_select').inputValue()) === 'b', 'select persisted');
		c.ok((await page.locator('#uxs-w_number').inputValue()) === '7', 'number persisted');
		c.ok((await page.locator('#uxs-w_color').inputValue()) === '#12ab34', 'color persisted');
		c.ok((await page.locator('#uxs-w_date').inputValue()) === '2030-01-02T03:04', 'datetime persisted');
		c.ok((await page.locator('#uxs-w_media').inputValue()) === 'images/joomla_black.png', 'media path persisted');
		c.ok((await page.locator('.uxs-checklist__item input:checked').count()) === 2, 'multiselect persisted');
		c.ok(!/<\/style>|<script/i.test(await page.locator('#uxs-w_css').inputValue()), 'css: markup breakout neutralised');
		const richHtml = await page.locator('.uxs-rte [contenteditable=true]').first().innerHTML();
		c.ok(/Src/.test(richHtml) && !/<script/i.test(richHtml), 'richtext persisted and script stripped');
		c.ok((await page.locator('.uxs-repeater__row').count()) === 2, 'repeater persisted with 2 rows');
		c.ok((await page.locator('.uxs-repeater__row').nth(0).locator('input[type=number]').inputValue()) === '5', 'repeater row number persisted');
		c.ok((await page.locator('.uxs-form__row:has(label[for="uxs-w_pass"]) .uxs-muted').count()) === 1, 'password shown as "stored encrypted", never echoed');
		const rawPass = sql(site, "SELECT data FROM jos_uxstudio_settings WHERE module='hello-check'");
		c.ok(!rawPass.includes('s3cret-value'), 'password is not stored in clear text');
		// password: Change / Remove
		const pwRow = page.locator('.uxs-form__row:has(label[for="uxs-w_pass"])');
		await pwRow.locator('.uxs-btn', { hasText: /Change|Změnit/ }).click();
		await page.locator('#uxs-w_pass').waitFor();
		c.ok(true, 'password: "Change" reveals an empty input');
		await page.reload();
		await page.locator('[role=tab]', { hasText: /Settings|Nastavení/ }).first().click();
		await pwRow.locator('.uxs-btn--danger').click();
		await page.locator('#uxs-root form button[type=submit]').click();
		await toast(page, 'success').waitFor({ timeout: 8000 });
		c.ok(/"w_pass":""/.test(sql(site, "SELECT data FROM jos_uxstudio_settings WHERE module='hello-check'")), 'password: "Remove" + save clears the secret');
		// dark mode of the form
		await setTheme(page, 'dark');
		await reloadStudio(page, '[role=tab]');
		await page.locator('[role=tab]', { hasText: /Settings|Nastavení/ }).first().click();
		await page.locator('#uxs-w_select').waitFor();
		c.ok((await root(page).getAttribute('data-theme')) === 'dark', 'generic form renders in dark mode');
		await snap(page, `${site.key}-generic-form-dark`);
		await setTheme(page, 'light');
	} finally {
		await LAST?.screenshot({ path: `${SHOTS}shell-${site.key}-widgets-end.png` }).catch(() => undefined);
		await unpatchHello();
		sql(site, `UPDATE jos_uxstudio_settings SET data='${esc(helloRow)}' WHERE module='hello-check'`);
		await ctx.close();
	}
	return issues;
}

async function phaseGenericPlain(browser, site, c, lang) {
	const { ctx, issues } = await context(browser);
	const page = await login(ctx, site);
	await openStudio(page, site, '#/module?id=redirect-404-to-homepage');
	await page.locator('#uxs-root h1').first().waitFor();
	if ((await page.locator('.uxs-notice .uxs-btn--primary').count()) > 0 && (await page.locator('#uxs-code').count()) === 0) {
		// another test run has this module switched off right now: only the "turned off" gate can be checked
		c.ok(true, `[${lang}] module is off (gate shown, generic form not testable now)`);
		await ctx.close();
		return issues;
	}
	await page.locator('#uxs-code').waitFor();
	c.ok((await page.locator('#uxs-code option').count()) === 3, `[${lang}] generic page of a module without Page.tsx renders its select (3 options)`);
	c.ok((await page.locator('.uxs-sidebar__item.is-active').innerText()).length > 0, `[${lang}] sidebar highlights the open module`);
	c.ok((await page.locator('.uxs-form__row .uxs-switch').count()) === 1, `[${lang}] toggle rendered`);
	await snap(page, `${site.key}-generic-plain-${lang}`);
	if (lang === 'cs-CZ') {
		const leaks = await englishLeaks(page);
		c.ok(leaks.length === 0, `[${lang}] no English leaks on the generic page ${leaks.slice(0, 5).join(' | ')}`);
	}
	await ctx.close();
	return issues;
}

async function phaseSession(browser, site, c) {
	const { ctx, issues } = await context(browser, { ignore: [/HTTP (401|403|303)/, /Failed to load resource/, /status of 40[13]/] });
	const page = await login(ctx, site);
	await openStudio(page, site, '#/modules');
	await page.locator('.uxs-tile').first().waitFor();
	await ctx.clearCookies();
	// a toggle on the grid after expiry: optimistic switch rolls back with an error toast
	await page.locator('.uxs-modulefilter__search').fill('redirect-404');
	const sw = page.locator('.uxs-tile .uxs-switch').first();
	const was = await sw.getAttribute('aria-pressed');
	await sw.click();
	await toast(page, 'error').waitFor({ timeout: 10000 });
	await sleep(800);
	c.ok((await page.locator('.uxs-tile').count()) > 0, 'grid keeps showing the modules after a failed refetch (not replaced by an error)');
	c.ok((await sw.getAttribute('aria-pressed')) === was, 'toggle rolls back after the failed request');
	c.ok(/session|expired/i.test(await toast(page, 'error').innerText()), 'toggle failure toast explains the expired session');
	c.ok(moduleOn(site, 'redirect-404-to-homepage') === '1', 'redirect-404 module stayed on');
	await snap(page, `${site.key}-session-toggle-error`);
	// the settings page is fetched fresh, so the expired session surfaces there
	await page.locator('.uxs-sidebar__item', { hasText: /Settings|Nastavení/ }).click();
	const alert = page.locator('#uxs-root [role=alert]').first();
	await alert.waitFor({ timeout: 10000 });
	const msg = (await alert.innerText()).trim();
	c.ok(/session|expired/i.test(msg), `session expiry explained in the UI: "${msg.slice(0, 90)}"`);
	await snap(page, `${site.key}-session-expired`);
	await ctx.close();
	return issues;
}

async function phaseNarrow(browser, site, c) {
	const { ctx, issues } = await context(browser);
	const page = await login(ctx, site);
	await page.setViewportSize({ width: 800, height: 900 });
	await openStudio(page, site, '#/modules');
	await page.locator('.uxs-tile').first().waitFor();
	const menu = page.locator('.uxs-topbar__toggle');
	c.ok(await menu.isVisible(), 'narrow: Menu button in the top bar');
	await snap(page, `${site.key}-narrow-grid`);
	await menu.click();
	await page.locator('.uxs-shell.is-drawer-open').waitFor();
	c.ok((await menu.getAttribute('aria-expanded')) === 'true', 'narrow: Menu button reports aria-expanded=true while the drawer is open');
	c.ok(await page.evaluate(() => !!document.activeElement?.closest('.uxs-sidebar')), 'narrow: focus moves into the drawer when it opens');
	let leaked = 0;
	for (let i = 0; i < 25; i++) {
		await page.keyboard.press('Tab');
		if (!(await page.evaluate(() => !!document.activeElement?.closest('.uxs-sidebar')))) leaked++;
	}
	c.ok(leaked === 0, `narrow: Tab stays inside the open drawer (focus left it ${leaked}x in 25 presses)`);
	await page.keyboard.press('Shift+Tab');
	c.ok(await page.evaluate(() => !!document.activeElement?.closest('.uxs-sidebar')), 'narrow: Shift+Tab stays inside the drawer');
	await snap(page, `${site.key}-narrow-drawer`);
	await page.keyboard.press('Escape');
	await page.locator('.uxs-shell.is-drawer-open').waitFor({ state: 'detached' });
	c.ok(await menu.evaluate((el) => el === document.activeElement), 'narrow: Esc closes the drawer and focus returns to the Menu button');
	await menu.click();
	await page.locator('.uxs-shell__backdrop').click({ position: { x: 780, y: 450 } });
	await page.locator('.uxs-shell.is-drawer-open').waitFor({ state: 'detached' });
	c.ok(true, 'narrow: click on the backdrop closes the drawer');
	await menu.click();
	await page.locator('.uxs-sidebar__item', { hasText: /Settings|Nastavení/ }).click();
	await page.locator('#uxs-client_ip_mode').waitFor();
	c.ok((await page.locator('.uxs-shell.is-drawer-open').count()) === 0, 'narrow: choosing an entry closes the drawer');
	await page.setViewportSize({ width: 390, height: 844 });
	await page.goto(`${site.url}/administrator/index.php?option=com_uxstudio#/modules`);
	await reloadStudio(page, '.uxs-tile');
	const searchY = await page.locator('.uxs-modulefilter__search').evaluate((el) => el.getBoundingClientRect().top + window.scrollY);
	c.ok(searchY < 1200, `phone: module search is near the top of the page (y=${Math.round(searchY)}px)`);
	await snap(page, `${site.key}-phone-grid`);
	await page.setViewportSize({ width: 800, height: 900 });
	// wide: fold the sidebar and persist
	await page.setViewportSize({ width: 1500, height: 900 });
	await page.waitForFunction(() => !document.querySelector('.uxs-shell.is-narrow'));
	await page.locator('.uxs-sidebar__fold').click();
	c.ok((await page.locator('.uxs-shell.is-folded').count()) === 1 && (await page.evaluate(() => localStorage.getItem('uxstudio-sidebar'))) === 'hidden', 'wide: sidebar folds and the choice is stored');
	await snap(page, `${site.key}-folded`);
	await page.locator('.uxs-topbar__toggle').click();
	c.ok((await page.locator('.uxs-shell.is-folded').count()) === 0, 'wide: sidebar unfolds');
	await ctx.close();
	return issues;
}

/* ------------------------------------------------------------------ main */

restoreHello();
const browser = await launch();
let bad = 0;
const only = process.argv.find((a) => a.startsWith('--only='))?.slice(7);
for (const site of sitesFromArgv()) {
	const c = checker(`shell ${site.key}`);
	const allIssues = [];
	const snapshot = sql(site, 'SELECT id, enabled FROM jos_uxstudio_modules ORDER BY id');
	const coreRow = sql(site, "SELECT data FROM jos_uxstudio_settings WHERE module='core'");
	createUser(site, 'en-GB');
	const run = async (name, fn) => {
		if (only && !only.split(',').includes(name)) return;
		try {
			allIssues.push(...(await fn()));
		} catch (e) {
			c.ok(false, `phase ${name} crashed: ${String(e.message).split('\n')[0]}`);
			console.log(e.stack?.split('\n').slice(0, 4).join('\n'));
			await LAST?.screenshot({ path: `${SHOTS}shell-crash-${site.key}-${name}.png` }).catch(() => undefined);
		}
	};
	try {
		for (const lang of ['en-GB', 'cs-CZ']) {
			setLang(site, lang);
			await run(`grid-${lang}`, () => phaseGrid(browser, site, c, lang));
			await run(`core-${lang}`, () => phaseCore(browser, site, c, lang));
			await run(`plain-${lang}`, () => phaseGenericPlain(browser, site, c, lang));
			await run(`wizard-${lang}`, () => phaseWizard(browser, site, c, lang));
		}
		setLang(site, 'en-GB');
		await run('booterror', () => phaseBootError(browser, site, c));
		await run('widgets', () => phaseWidgets(browser, site, c));
		await run('session', () => phaseSession(browser, site, c));
		await run('narrow', () => phaseNarrow(browser, site, c));
	} finally {
		restoreHello();
		// restore module switches (only hello-check and redirect-404 are touched by this scenario)
		for (const line of snapshot.split(/\r?\n/)) {
			const [id, en] = line.split('\t');
			if (!['hello-check', 'instagram-feed'].includes(id)) continue;
			if (moduleOn(site, id) !== en) {
				sql(site, `UPDATE jos_uxstudio_modules SET enabled=${en} WHERE id='${id}'`);
				console.log(`NOTE restored module ${id} -> ${en}`);
			}
		}
		sql(site, `UPDATE jos_uxstudio_settings SET data='${esc(coreRow)}' WHERE module='core'`);
		dropUser(site);
	}
	bad += c.done(allIssues);
}
await browser.close();
process.exit(bad ? 1 : 0);
