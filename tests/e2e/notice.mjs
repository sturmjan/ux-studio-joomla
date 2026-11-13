// Browser E2E for the modules notice-board, top-bar and opening-hours (+ mod_uxstudio_opening_hours).
// Run: node tests/e2e/notice.mjs [j5|j6|all] [--headed]
// Everything the scenario creates is prefixed "E2E" and removed at the end (also on failure); changed settings, the admin
// language and the Joomla user params are restored.
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { launch, context, adminLogin, openStudio, checker, sitesFromArgv, SHOTS } from './lib.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const MYSQL = 'D:/xampp/mysql/bin/mysql.exe';
const ADMIN_USER = (fs.readFileSync(path.join(ROOT, '.env.local'), 'utf8').match(/^JOOMLA_ADMIN_USER=(.*)$/m) ?? [])[1]?.trim() ?? '';
const TODAY = new Date().toISOString().slice(0, 10);
const addDays = (n) => new Date(Date.now() + n * 864e5).toISOString().slice(0, 10);

const sql = (site, q) => execFileSync(MYSQL, ['-uroot', '--default-character-set=utf8mb4', '-N', '-B', site.db, '-e', q], { encoding: 'utf8' }).trim();
/** Polls a SQL scalar until it equals `expected` (UI mutations are asynchronous). */
async function sqlIs(site, q, expected, tries = 20) {
	let v = '';
	for (let i = 0; i < tries; i++) {
		v = sql(site, q);
		if (v === expected) return true;
		await new Promise((r) => setTimeout(r, 250));
	}
	return false;
}
const esc = (s) => s.replace(/\\/g, '\\\\').replace(/'/g, "\\'");

const ENGLISH_LEAKS = ['Add notice', 'Add location', 'Add message', 'Back to modules', 'Weekly hours', 'Subscribers', 'Announcements', 'More messages', 'Close for', 'Public reason', 'Save', 'Cancel', 'Delete', 'Publish date', 'Expiry', 'Edit notice', 'Edit location', 'Close now', 'Basics'];
/** Czech plurals that were wrong ("1 hodin", "1 dní", "2 hodin") and the English singular/plural mix-ups. */
const BAD_PLURAL = /\b1 (hodin|dní|minut)\b|\b[2-4] (hodin|dní|minut)\b|\b1 (hours|days|minutes)\b/;
const LANGS = {
	'en-GB': { tile: ['Notice Board', 'Opening Hours', 'Top Bar'], visible: 'Visible' },
	'cs-CZ': { tile: ['Úřední deska', 'Otevírací doba', 'Horní lišta'], visible: 'Zobrazena' },
};

const stamp = Date.now().toString(36);
const NB_TITLE = `E2E Oznameni ${stamp}`;
const OH_TITLE = `E2E Pobocka ${stamp}`;
const LIKE = `%${stamp}%`; // everything this run creates carries the stamp: cleanup never touches other agents' E2E data

/* ------------------------------------------------------------------ small helpers */

const dialog = (page) => page.locator('[role=dialog]');
const toast = (page, kind) => page.locator(`.uxs-toast.is-${kind}`).first();
async function saveSettings(page) {
	// the toast of the previous save must be gone, otherwise this wait returns before the new save finished
	await page.locator('.uxs-toast').first().waitFor({ state: 'detached', timeout: 12000 }).catch(() => {});
	await page.locator('#uxs-root form button[type=submit]').first().click();
	await toast(page, 'success').waitFor({ timeout: 20000 });
}
/** Waits until a row with `text` is (not) in the table. */
async function appears(page, text, state = 'visible') {
	return page.locator('#uxs-root tbody tr', { hasText: text }).first().waitFor({ state, timeout: 20000 }).then(() => true, () => false);
}
/** Clicks Save in a modal and returns the new inline error (422 alert) or '' when none showed up. */
async function saveExpectError(page, save, prev = '') {
	await save.click();
	const al = dialog(page).locator('[role=alert]').first();
	for (let i = 0; i < 40; i++) {
		const t = (await al.innerText().catch(() => '')).trim();
		if (t && t !== prev) return t;
		await page.waitForTimeout(250);
	}
	return '';
}
/** Simulates an empty list and a failing list endpoint for one module screen. */
async function emptyAndErrorState(env, id, re, label, pre) {
	const { page, site, c, issues, lang } = env;
	setAdminLanguage(site, lang); // the admin account is shared: re-pin the language before the SPA reloads
	const strip = (o) => {
		if (Array.isArray(o)) return o;
		if (o && typeof o === 'object') for (const k of Object.keys(o)) o[k] = k === 'items' ? [] : strip(o[k]);
		return o;
	};
	await page.route(re, async (r) => {
		try {
			if (r.request().method() !== 'GET') return await r.continue();
			const resp = await r.fetch();
			const j = await resp.json().catch(() => null);
			return await (j ? r.fulfill({ response: resp, json: strip(j) }) : r.fulfill({ response: resp }));
		} catch { /* navigation replaced the request */ }
	});
	await openStudio(page, site, `#/module?id=${id}`);
	await page.reload(); // drop the query cache of the SPA
	await page.locator('#uxs-root [role=tab]').first().waitFor();
	if (pre) await pre();
	const cell = page.locator('#uxs-root td.is-empty').first();
	const shown = await cell.waitFor({ timeout: 20000 }).then(() => true, () => false);
	const txt = shown ? (await cell.innerText()).trim() : '';
	c.ok(shown && txt.length > 3, `${label}: empty state message shown ("${txt.slice(0, 40)}")`);
	if (lang === 'cs-CZ') c.ok(!/^No\b/.test(txt), `${label}: empty state is translated`);
	await page.screenshot({ path: `${SHOTS}notice_${env.tag}_${id}_empty.png` });
	await page.unroute(re);
	// failing endpoint -> a visible error, not a blank screen
	const n = issues.length;
	await page.route(re, (r) => (r.request().method() === 'GET' ? r.fulfill({ status: 500, contentType: 'application/json', body: '{"error":{"message":"E2E simulated failure"}}' }) : r.continue()));
	await openStudio(page, site, `#/module?id=${id}`);
	await page.reload(); // drop the query cache of the SPA
	await page.locator('#uxs-root [role=tab]').first().waitFor();
	if (pre) await pre();
	const err = await page.locator('#uxs-root [role=alert]').first().waitFor({ timeout: 30000 }).then(() => true, () => false);
	c.ok(err, `${label}: failing list request shows an error message`);
	await page.unroute(re);
	for (let i = issues.length - 1; i >= n; i--) if (/500|Failed to load resource/.test(issues[i])) issues.splice(i, 1);
	await openStudio(page, site, `#/module?id=${id}`);
}
/** Switch of a settings field by its key (language independent: the row label points to #uxs-<key>). */
async function toggleByLabel(page, key, on) {
	const sw = page.locator(`#uxs-root .uxs-form__row:has(label[for="uxs-${key}"]) button.uxs-switch`);
	const pressed = (await sw.getAttribute('aria-pressed')) === 'true';
	if (pressed !== on) await sw.click();
}
async function tab(page, i, scope) {
	await (scope ?? page).locator('[role=tab]').nth(i).click();
	await page.waitForTimeout(250);
}
/** cs-CZ: no English UI strings left. Re-pins the language and reloads once (the admin account is shared with other test runs). */
async function noLeaks(env, name, text, { modal = false } = {}) {
	const { page, c, site, lang } = env;
	let leak = ENGLISH_LEAKS.find((x) => text.includes(x));
	if (leak && modal) {
		c.ok(false, `${name}: no English leftovers in cs-CZ (${leak})`);
		return;
	}
	if (leak) {
		setAdminLanguage(site, lang);
		await page.reload();
		await page.locator('#uxs-root [role=tab]').first().waitFor();
		await page.waitForTimeout(1500);
		const again = await rootText(page);
		leak = ENGLISH_LEAKS.find((x) => again.includes(x));
	}
	c.ok(!leak, `${name}: no English leftovers in cs-CZ (${leak ?? 'ok'})`);
	c.ok(!BAD_PLURAL.test(text), `${name}: no wrong plural forms`);
}
async function rootText(page) {
	return page.locator('#uxs-root').innerText();
}
async function rowOf(page, text) {
	return page.locator('#uxs-root tbody tr', { hasText: text }).first();
}
async function confirmDanger(page) {
	await dialog(page).locator('.uxs-btn--danger').click();
	await dialog(page).waitFor({ state: 'detached' });
}
async function front(browser, issues, url, { width = 1280, ignore = [] } = {}) {
	const ctx = await browser.newContext({ viewport: { width, height: 800 } });
	const page = await ctx.newPage();
	page.setDefaultTimeout(20000);
	page.on('console', (m) => ['error', 'warning'].includes(m.type()) && !/status of 404/.test(m.text()) && !ignore.some((r) => r.test(m.text())) && !/challenges\.cloudflare\.com/.test(m.text()) && issues.push(`[front ${url}] ${m.type()}: ${m.text().slice(0, 200)}`));
	page.on('pageerror', (e) => issues.push(`[front ${url}] pageerror: ${e.message.slice(0, 200)}`));
	// alert()/confirm() on the public site would mean injected script ran
	page.on('dialog', (d) => { issues.push(`[front ${url}] unexpected dialog (injected script?): ${d.message().slice(0, 80)}`); d.dismiss().catch(() => {}); });
	page.on('response', (r) => r.status() >= 400 && !/favicon|__xss|uxstudio-instagram/.test(r.url()) && issues.push(`[front] HTTP ${r.status()} ${r.url().slice(0, 150)}`));
	const resp = await page.goto(url);
	await page.waitForLoadState('load');
	await page.waitForTimeout(300);
	return { ctx, page, status: resp?.status() ?? 0 };
}

/* ------------------------------------------------------------------ test data on the site */

function snapshot(site) {
	const snap = { settings: {}, lang: sql(site, `SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NULLIF(params,''),'$.admin_language')),'') FROM jos_users WHERE username='${esc(ADMIN_USER)}'`) };
	for (const m of ['notice-board', 'top-bar', 'opening-hours']) {
		snap.settings[m] = sql(site, `SELECT HEX(data) FROM jos_uxstudio_settings WHERE module='${m}'`);
	}
	return snap;
}
function restore(site, snap) {
	for (const [m, hex] of Object.entries(snap.settings)) {
		if (hex) sql(site, `REPLACE INTO jos_uxstudio_settings (module, data, updated_at) VALUES ('${m}', UNHEX('${hex}'), NOW())`);
		else sql(site, `DELETE FROM jos_uxstudio_settings WHERE module='${m}'`);
	}
	// only the language key is touched, the other admin params (editor, timezone ...) stay as they are
	const base = `COALESCE(NULLIF(params,''),'{}')`;
	sql(site, `UPDATE jos_users SET params=${snap.lang ? `JSON_SET(${base},'$.admin_language','${snap.lang}')` : `JSON_REMOVE(${base},'$.admin_language')`} WHERE username='${esc(ADMIN_USER)}'`);
}
function setAdminLanguage(site, lang) {
	sql(site, `UPDATE jos_users SET params=JSON_SET(COALESCE(NULLIF(params,''),'{}'),'$.admin_language','${lang}') WHERE username='${esc(ADMIN_USER)}'`);
}
function cleanup(site) {
	// strictly this run's data (stamp in title / e-mail / alias): other agents share these sites and tables like jos_content
	sql(site, `DELETE FROM jos_uxstudio_notice_board WHERE title LIKE '${LIKE}'`);
	sql(site, `DELETE FROM jos_uxstudio_notice_board_categories WHERE name LIKE '${LIKE}'`);
	sql(site, `DELETE FROM jos_uxstudio_notice_board_subscriptions WHERE email LIKE 'e2e-${stamp}%'`);
	sql(site, `DELETE FROM jos_uxstudio_top_bar_slides WHERE body LIKE '${LIKE}'`);
	sql(site, `DELETE FROM jos_uxstudio_oh_locations WHERE title LIKE '${LIKE}'`);
	sql(site, `DELETE FROM jos_modules_menu WHERE moduleid IN (SELECT id FROM jos_modules WHERE title = 'E2E modul ${stamp}')`);
	sql(site, `DELETE FROM jos_modules WHERE title = 'E2E modul ${stamp}'`);
	sql(site, `DELETE FROM jos_workflow_associations WHERE extension='com_content.article' AND item_id IN (SELECT id FROM jos_content WHERE alias LIKE 'e2e-${stamp}-%')`);
	sql(site, `DELETE FROM jos_content WHERE alias LIKE 'e2e-${stamp}-%'`);
}
function dropArticle(site, id) {
	sql(site, `DELETE FROM jos_workflow_associations WHERE extension='com_content.article' AND item_id=${id}`);
	sql(site, `DELETE FROM jos_content WHERE id=${id}`);
}
function createArticle(site, title, html) {
	// Clone the first existing article row (a known-good shape) and replace the text; UTC publish_up so it is already live.
	sql(
		site,
		`INSERT INTO jos_content (title, alias, introtext, \`fulltext\`, state, catid, created, created_by, publish_up, images, urls, attribs, version, metakey, metadesc, access, metadata, language)
		 SELECT '${esc(title)}', 'e2e-${stamp}-${Math.random().toString(36).slice(2, 6)}', '${esc(html)}', '', 1, catid, UTC_TIMESTAMP() - INTERVAL 1 HOUR, created_by, UTC_TIMESTAMP() - INTERVAL 1 HOUR, images, urls, attribs, 1, '', '', 1, metadata, '*'
		 FROM jos_content WHERE state=1 AND access=1 AND title NOT LIKE 'E2E%' ORDER BY id LIMIT 1`
	);
	const id = Number(sql(site, `SELECT id FROM jos_content WHERE alias LIKE 'e2e-${stamp}-%' ORDER BY id DESC LIMIT 1`));
	sql(site, `INSERT INTO jos_workflow_associations (item_id, stage_id, extension) VALUES (${id}, 1, 'com_content.article')`);
	return id;
}

/* ------------------------------------------------------------------ flows */

async function dashboardFlow(env) {
	const { page, site, c, L, tag } = env;
	await page.goto(`${site.url}/administrator/index.php?option=com_cpanel&view=cpanel`);
	await page.waitForLoadState('load');
	const txt = await page.locator('.uxs-cp-cards').first().innerText();
	for (const name of L.tile) c.ok(txt.includes(name), `cpanel dashboard shows UX1 Studio tile "${name}"`);
	await page.screenshot({ path: `${SHOTS}notice_${tag}_cpanel.png`, fullPage: false });
}

async function noticeBoardFlow(env) {
	const { page, site, c, L, tag, browser, issues, lang } = env;
	await openStudio(page, site, '#/module?id=notice-board');
	await page.locator('#uxs-root [role=tab]').first().waitFor();
	const text0 = await rootText(page);
	c.ok(!!(await page.locator('#uxs-root table').count()), 'notice-board: notices table visible');
	if (lang === 'cs-CZ') await noLeaks(env, 'notice-board', text0);
	for (const i of [1, 2, 3, 0]) {
		await tab(page, i);
		const sel = ['#uxs-root table', '#uxs-root form input[type=text]', '#uxs-root p.uxs-muted, #uxs-root table', '#uxs-board_url'][i];
		c.ok(await page.locator(sel).first().waitFor({ timeout: 5000 }).then(() => true, () => false), `notice-board: tab ${i} renders its content`);
		if (i === 2) c.ok(await page.locator('#uxs-root p.uxs-muted').first().waitFor({ timeout: 5000 }).then(() => true, () => false), 'notice-board: subscribers tab shows counts / empty state');
		if (i === 3) c.ok(await page.locator('#uxs-board_url').waitFor({ timeout: 5000 }).then(() => true, () => false), 'notice-board: settings fields present');
	}

	// --- categories: validation + create + duplicate
	await tab(page, 1);
	const catInput = page.locator('#uxs-root form input[type=text]').first();
	const addBtn = page.locator('#uxs-root form button[type=submit]');
	c.ok(await addBtn.isDisabled(), 'categories: add button disabled for empty name');
	await catInput.fill('   ');
	c.ok(await addBtn.isDisabled(), 'categories: add button disabled for whitespace name');
	const CAT = `E2E Kategorie ${stamp}`;
	await catInput.fill(CAT);
	await addBtn.click();
	c.ok(await appears(page, CAT), 'categories: created');
	await catInput.fill(CAT);
	await addBtn.click();
	await toast(page, 'error').waitFor({ timeout: 5000 }).then(() => c.ok(true, 'categories: duplicate name gives an error toast'), () => c.ok(false, 'categories: duplicate name gives an error toast'));
	const slug = (await (await rowOf(page, CAT)).locator('code').innerText()).trim();
	// rename
	await (await rowOf(page, CAT)).locator('.uxs-btn--small').first().click();
	await page.locator('#uxs-root tbody input[type=text]').fill(`${CAT} X`);
	await page.locator('#uxs-root tbody .uxs-btn--primary').click();
	c.ok(await appears(page, `${CAT} X`), 'categories: renamed');

	// --- notice: validation, create
	await tab(page, 0);
	await page.locator('#uxs-root .uxs-list-toolbar .uxs-btn--primary').click();
	await dialog(page).waitFor();
	const save = dialog(page).locator('.uxs-btn--primary');
	c.ok(await save.isDisabled(), 'notice: Save disabled with empty title');
	await page.fill('#uxs-nb-title', '   ');
	c.ok(await save.isDisabled(), 'notice: Save disabled with whitespace title');
	await page.screenshot({ path: `${SHOTS}notice_${tag}_nb_modal.png` });
	if (lang === 'cs-CZ') await noLeaks(env, 'notice-board modal', await dialog(page).innerText(), { modal: true });
	// keyboard: Tab never leaves the dialog
	await page.focus('#uxs-nb-title');
	let inside = true;
	for (let k = 0; k < 10; k++) {
		await page.keyboard.press('Tab');
		inside = inside && (await page.evaluate(() => !!document.activeElement?.closest('[role=dialog]')));
	}
	c.ok(inside, 'notice modal: Tab keeps the focus inside the dialog');
	// removal date earlier than publication date is refused with a message and nothing is stored
	await page.fill('#uxs-nb-title', `E2E Datum ${stamp}`);
	await page.fill('#uxs-nb-publish', addDays(-1));
	await page.fill('#uxs-nb-expiry', addDays(-10));
	const eDates = await saveExpectError(page, save);
	c.ok(!!eDates, `notice: removal date before publication date rejected (${eDates.slice(0, 60)})`);
	c.ok(sql(site, `SELECT COUNT(*) FROM jos_uxstudio_notice_board WHERE title='E2E Datum ${stamp}'`) === '0', 'notice: invalid dates are not stored');
	await page.keyboard.press('Escape');
	c.ok(await dialog(page).waitFor({ state: 'detached', timeout: 3000 }).then(() => true, () => false), 'notice modal: Escape closes the dialog');
	await page.locator('#uxs-root .uxs-list-toolbar .uxs-btn--primary').click();
	await dialog(page).waitFor();
	await page.fill('#uxs-nb-title', NB_TITLE);
	await page.selectOption('#uxs-nb-category', slug);
	await page.fill('#uxs-nb-reference', 'E2E-1/2026');
	await page.fill('#uxs-nb-body', '<p>Text E2E <b>tučně</b></p><script>window.__e2e_xss=1</script><a href="javascript:window.__e2e_xss=1" onclick="window.__e2e_xss=1">odkaz</a>');
	// attachment that does not exist is dropped by the server, path traversal is rejected / dropped
	await dialog(page).getByRole('button').filter({ has: page.locator('svg.lucide-paperclip') }).click();
	await dialog(page).locator('input[placeholder*="images/"]').first().fill('images/../configuration.php');
	await save.click();
	await toast(page, 'success').waitFor({ timeout: 20000 }).then(() => c.ok(true, 'notice: saved (bad attachment dropped / accepted without error)'), async () => {
		const err = await dialog(page).locator('[role=alert]').innerText().catch(() => '');
		c.ok(/\S/.test(err), `notice: bad attachment path rejected with a message (${err.slice(0, 60)})`);
		await dialog(page).locator('input[placeholder*="images/"]').first().fill('');
		await dialog(page).locator('.uxs-btn--danger').first().click();
		await save.click();
		await toast(page, 'success').waitFor({ timeout: 20000 });
	});
	await dialog(page).waitFor({ state: 'detached' });
	const row = await rowOf(page, NB_TITLE);
	await row.waitFor();
	c.ok((await row.innerText()).includes('E2E-1/2026'), 'notice: row shows reference');
	const stored = sql(site, `SELECT body FROM jos_uxstudio_notice_board WHERE title='${esc(NB_TITLE)}'`);
	c.ok(!stored.includes('__e2e_xss'), 'notice: script content is dropped, not left behind as visible text');
	c.ok(!/<script|onclick|javascript:/i.test(stored), 'notice: server filtered script / onclick / javascript: out of the body');
	c.ok(await sqlIs(site, `SELECT COUNT(*) FROM jos_uxstudio_notice_board WHERE title='${esc(NB_TITLE)}' AND attachments LIKE '%configuration%'`, '0'), 'notice: path traversal attachment not stored');

	// --- admin dashboard tile counts it
	await page.goto(`${site.url}/administrator/index.php?option=com_cpanel&view=cpanel`);
	await page.locator('.uxs-cp-cards').first().waitFor();
	let tileVal = '';
	for (let attempt = 0; attempt < 3 && !/[1-9]/.test(tileVal); attempt++) {
		if (attempt) await page.reload();
		await page.locator('.uxs-cp-cards').first().waitFor();
		const tile = page.locator('.uxs-cp-card', { hasText: L.tile[0] }).first();
		tileVal = (await tile.waitFor({ timeout: 20000 }).then(() => true, () => false)) ? await tile.locator('.uxs-cp-card__value').innerText() : '';
	}
	c.ok(/[1-9]/.test(tileVal), `notice: Joomla cpanel tile counts the new notice (value "${tileVal}")`);
	await openStudio(page, site, '#/module?id=notice-board');

	// --- frontend
	const artId = createArticle(site, `E2E Deska ${stamp}`, '<p>E2E deska</p><p>{uxs-notice-board category="' + slug + '"}</p>');
	const url = `${site.url}/index.php?option=com_content&view=article&id=${artId}`;
	if (process.env.DBG) console.log(sql(site, `SELECT id,title,state,catid,publish_up,UTC_TIMESTAMP(),access,created_by FROM jos_content WHERE id=${artId}`));
	const f = await front(browser, issues, url);
	c.ok(f.status === 200, `notice front: article renders (HTTP ${f.status})`);
	const board = f.page.locator('.uxs-notice-board');
	c.ok((await board.innerText()).includes(NB_TITLE), 'notice front: notice visible to anonymous visitor');
	c.ok(!(await f.page.evaluate(() => window.__e2e_xss)), 'notice front: no script executed from the notice body');
	c.ok(!!(await f.page.locator('.uxs-nb-subscribe').count()), 'notice front: subscribe form present');
	await f.page.screenshot({ path: `${SHOTS}notice_${tag}_nb_front.png`, fullPage: true });
	// detail page
	// (other modules' popups may overlay the page on the shared test sites, so navigate by href instead of clicking)
	await f.page.goto(await f.page.locator('.uxs-nb-item__title a').first().getAttribute('href'));
	c.ok(/Text E2E/.test(await f.page.locator('.uxs-nb-detail').innerText()), 'notice front: detail page shows the body');
	// subscribe with an invalid and a valid address
	await f.page.goto(url);
	const mail = `e2e-${stamp}@example.com`;
	await f.page.fill('.uxs-nb-subscribe input[type=email]', 'not-an-email');
	await f.page.locator('.uxs-nb-subscribe button[type=submit]').dispatchEvent('click');
	await f.page.waitForTimeout(800);
	c.ok(await sqlIs(site, `SELECT COUNT(*) FROM jos_uxstudio_notice_board_subscriptions WHERE email LIKE 'not-an%'`, '0'), 'notice front: invalid e-mail not stored');
	await f.page.fill('.uxs-nb-subscribe input[type=email]', mail);
	await f.page.locator('.uxs-nb-subscribe button[type=submit]').dispatchEvent('click');
	await f.page.waitForTimeout(1500);
	c.ok(await sqlIs(site, `SELECT COUNT(*) FROM jos_uxstudio_notice_board_subscriptions WHERE email='${mail}'`, '1'), 'notice front: valid e-mail stored as pending subscriber');
	const rss = await f.page.request.get(`${site.url}/index.php?uxs_nb=feed&cat=${slug}`);
	c.ok(rss.ok() && /<rss/.test(await rss.text()), 'notice front: RSS feed valid');
	const od = await f.page.request.get(`${site.url}/index.php?uxs_nb=open-data`);
	c.ok(od.ok() && (await od.text()).includes(NB_TITLE), 'notice front: open data contains the notice');
	// a long unbroken word / URL must not widen the page on a phone
	sql(site, `INSERT INTO jos_uxstudio_notice_board (created_at, updated_at, title, body, category, reference, attachments, publish_date, expiry_date, is_archived) VALUES (NOW(), NOW(), 'E2E Dlouhe ${stamp}', '<p>${'Dlouheslovobezmezer'.repeat(20)}</p>', '${slug}', '', '[]', '${addDays(-1)}', NULL, 0)`);
	const fm = await front(browser, issues, url, { width: 375 });
	await fm.page.goto(await fm.page.locator('.uxs-nb-item__title a', { hasText: 'E2E Dlouhe' }).first().getAttribute('href'));
	const swDetail = await fm.page.evaluate(() => document.documentElement.scrollWidth);
	c.ok(swDetail <= 376, `notice front mobile: detail with a very long word does not scroll sideways (${swDetail}px)`);
	await fm.page.screenshot({ path: `${SHOTS}notice_${tag}_nb_detail_mobile.png` });
	await fm.page.goBack();
	const swList = await fm.page.evaluate(() => document.documentElement.scrollWidth);
	c.ok(swList <= 376, `notice front mobile: list with a very long word does not scroll sideways (${swList}px)`);
	await fm.ctx.close();
	sql(site, `DELETE FROM jos_uxstudio_notice_board WHERE title='E2E Dlouhe ${stamp}'`);
	await f.ctx.close();

	// subscriber tab
	await tab(page, 2);
	c.ok(await appears(page, mail), 'subscribers: pending subscriber listed');
	await (await rowOf(page, mail)).locator('.uxs-btn--danger').click();
	await confirmDanger(page);
	await page.waitForTimeout(500);
	c.ok(await sqlIs(site, `SELECT COUNT(*) FROM jos_uxstudio_notice_board_subscriptions WHERE email='${mail}'`, '0'), 'subscribers: deleted via UI');

	// --- edit + archive + archived on front
	await tab(page, 0);
	await (await rowOf(page, NB_TITLE)).locator('.uxs-btn--small').first().click();
	await page.fill('#uxs-nb-title', `${NB_TITLE} upraveno`);
	await dialog(page).locator('.uxs-btn--primary').click();
	c.ok(await appears(page, `${NB_TITLE} upraveno`), 'notice: edited');
	await (await rowOf(page, 'upraveno')).locator('.uxs-btn--small').nth(1).click();
	await page.waitForTimeout(700);
	c.ok(await sqlIs(site, `SELECT is_archived FROM jos_uxstudio_notice_board WHERE title LIKE '${LIKE}upraveno'`, '1'), 'notice: archived via UI');
	const f2 = await front(browser, issues, `${url}&nb_tab=archive`);
	c.ok((await f2.page.locator('.uxs-notice-board').innerText()).includes('upraveno'), 'notice front: archived notice is in the archive tab');
	await f2.ctx.close();
	// future publish date hides the notice
	await (await rowOf(page, 'upraveno')).locator('.uxs-btn--small').first().click();
	await page.fill('#uxs-nb-publish', addDays(30));
	await dialog(page).locator('.uxs-btn--primary').click();
	await dialog(page).waitFor({ state: 'detached' });
	await (await rowOf(page, 'upraveno')).locator('.uxs-btn--small').nth(1).click(); // restore from archive
	await page.waitForTimeout(700);
	const f3 = await front(browser, issues, url);
	c.ok(!(await f3.page.locator('.uxs-notice-board').innerText()).includes('upraveno'), 'notice front: future publish date keeps the notice hidden');
	await f3.ctx.close();

	await page.screenshot({ path: `${SHOTS}notice_${tag}_nb_admin.png`, fullPage: true });
	// double click on Save creates exactly one record
	await page.locator('#uxs-root .uxs-list-toolbar .uxs-btn--primary').click();
	await dialog(page).waitFor();
	await page.fill('#uxs-nb-title', `E2E Dvojklik ${stamp}`);
	await dialog(page).locator('.uxs-btn--primary').dblclick();
	await dialog(page).waitFor({ state: 'detached' }).catch(() => {});
	await page.waitForTimeout(900);
	c.ok(sql(site, `SELECT COUNT(*) FROM jos_uxstudio_notice_board WHERE title='E2E Dvojklik ${stamp}'`) === '1', 'notice: double click on Save creates one record');
	await (await rowOf(page, 'E2E Dvojklik')).locator('.uxs-btn--danger').click();
	await confirmDanger(page);
	await page.waitForTimeout(400);

	// --- delete notice + category via UI
	await (await rowOf(page, 'upraveno')).locator('.uxs-btn--danger').click();
	await confirmDanger(page);
	await page.waitForTimeout(500);
	c.ok(await sqlIs(site, `SELECT COUNT(*) FROM jos_uxstudio_notice_board WHERE title LIKE '${LIKE}'`, '0'), 'notice: deleted via UI');
	await tab(page, 1);
	await (await rowOf(page, `${CAT} X`)).locator('.uxs-btn--danger').click();
	await confirmDanger(page);
	await page.waitForTimeout(500);
	c.ok(await sqlIs(site, `SELECT COUNT(*) FROM jos_uxstudio_notice_board_categories WHERE name LIKE '${LIKE}'`, '0'), 'category: deleted via UI');
	dropArticle(site, artId);

	// empty list and failing request
	await emptyAndErrorState(env, 'notice-board', /route=notice-board\/notices(&|$)/, 'notice-board');
}

async function topBarFlow(env) {
	const { page, site, c, L, tag, browser, issues, lang } = env;
	await openStudio(page, site, '#/module?id=top-bar');
	await page.locator('#uxs-root [role=tab]').first().waitFor();
	const t0 = await rootText(page);
	if (lang === 'cs-CZ') await noLeaks(env, 'top-bar', t0);
	const base = site.url + '/';
	const barText = (p) => p.locator('#uxstudio-top-bar').innerText().catch(() => null);

	const setSource = async (html) => {
		const src = page.locator('.uxs-rte__btn--source');
		if ((await src.getAttribute('aria-pressed')) !== 'true') await src.click();
		await page.fill('#uxs-content', html);
	};
	const settingsTab = async () => {
		await tab(page, 0);
		await page.locator('#uxs-content, .uxs-rte').first().waitFor();
	};

	// --- text, link, colours, dismissible
	await settingsTab();
	await setSource('<a href="https://example.com/e2e">E2E akce</a> jen tento týden');
	await page.fill('#uxs-bg_color', '#ff0000');
	await page.fill('#uxs-text_color', '#00ff00');
	await toggleByLabel(page, 'dismissible', true);
	await toggleByLabel(page, 'sticky', false);
	await saveSettings(page);
	let f = await front(browser, issues, base);
	c.ok((await barText(f.page))?.includes('E2E akce'), 'top-bar front: text visible');
	c.ok((await f.page.locator('#uxstudio-top-bar a[href="https://example.com/e2e"]').count()) === 1, 'top-bar front: link rendered');
	const colors = await f.page.locator('#uxstudio-top-bar').evaluate((el) => ({ bg: getComputedStyle(el).backgroundColor, fg: getComputedStyle(el).color }));
	c.ok(colors.bg === 'rgb(255, 0, 0)', `top-bar front: background colour applied (${colors.bg})`);
	await f.page.screenshot({ path: `${SHOTS}notice_${tag}_tb_front.png` });
	// dismiss + remember
	await f.page.locator('.uxstudio-top-bar__close').dispatchEvent('click'); // dispatch: another module's popup may overlay the page
	c.ok((await f.page.locator('#uxstudio-top-bar').count()) === 0, 'top-bar front: bar removed after close');
	await f.page.reload();
	c.ok((await f.page.locator('#uxstudio-top-bar').count()) === 0, 'top-bar front: stays closed after reload (cookie)');
	const nav = await f.page.goto(`${base}index.php?option=com_content&view=featured`);
	c.ok((await f.page.locator('#uxstudio-top-bar').count()) === 0, `top-bar front: stays closed on another page (HTTP ${nav?.status()})`);
	// changing the text brings it back for the same visitor
	await settingsTab();
	await setSource('<a href="https://example.com/e2e">E2E akce</a> zmeneny text');
	await saveSettings(page);
	c.ok(await sqlIs(site, "SELECT data LIKE '%zmeneny text%' FROM jos_uxstudio_settings WHERE module='top-bar'", '1'), 'top-bar: changed text is stored');
	await f.page.goto(base);
	c.ok((await barText(f.page))?.includes('zmeneny text'), 'top-bar front: changed text shows the bar again after dismissal');
	await f.ctx.close();

	// --- mobile
	f = await front(browser, issues, base, { width: 375 });
	const m = await f.page.locator('#uxstudio-top-bar').boundingBox();
	c.ok(!!m && m.width <= 376, `top-bar mobile: bar fits the viewport (${m?.width})`);
	c.ok(await f.page.locator('.uxstudio-top-bar__close').isVisible(), 'top-bar mobile: close button visible');
	await f.page.screenshot({ path: `${SHOTS}notice_${tag}_tb_mobile.png` });
	await f.ctx.close();

	// --- sticky + template colour mode
	await settingsTab();
	await toggleByLabel(page, 'sticky', true);
	await page.selectOption('#uxs-color_mode', 'template');
	await saveSettings(page);
	f = await front(browser, issues, base);
	c.ok(/is-sticky/.test((await f.page.locator('#uxstudio-top-bar').getAttribute('class')) ?? ''), 'top-bar front: sticky class set');
	await f.page.evaluate(() => window.scrollTo(0, 600));
	const stuck = await f.page.locator('#uxstudio-top-bar').evaluate((el) => getComputedStyle(el).position);
	c.ok(stuck === 'sticky' || stuck === 'fixed', `top-bar front: sticky position (${stuck})`);
	await f.ctx.close();
	await settingsTab();
	await page.selectOption('#uxs-color_mode', 'custom');
	await toggleByLabel(page, 'sticky', false);
	await toggleByLabel(page, 'dismissible', false);

	// --- whole-bar schedule
	await page.fill('#uxs-schedule_publish', '2099-01-01T08:00');
	await saveSettings(page);
	f = await front(browser, issues, base);
	c.ok((await f.page.locator('#uxstudio-top-bar').count()) === 0, 'top-bar schedule: future "publish from" hides the bar');
	await f.ctx.close();
	await page.fill('#uxs-schedule_publish', '');
	await page.fill('#uxs-schedule_deactivate', '2020-01-01T08:00');
	await saveSettings(page);
	f = await front(browser, issues, base);
	c.ok((await f.page.locator('#uxstudio-top-bar').count()) === 0, 'top-bar schedule: past "deactivate" hides the bar');
	await f.ctx.close();
	await page.fill('#uxs-schedule_deactivate', '');
	await page.fill('#uxs-schedule_publish', '2020-01-01T08:00');
	await saveSettings(page);
	f = await front(browser, issues, base);
	c.ok((await f.page.locator('#uxstudio-top-bar').count()) === 1, 'top-bar schedule: window containing now shows the bar');
	await f.ctx.close();
	await page.fill('#uxs-schedule_publish', '');
	await page.fill('#uxs-interval', '2');
	await saveSettings(page);

	// --- more messages: validation, create, schedule, rotation
	await tab(page, 1);
	c.ok(/\S/.test(await page.locator('#uxs-root .uxs-badge').first().innerText()), 'top-bar messages: status badge visible');
	c.ok(await page.locator('#uxs-root tbody tr').first().waitFor({ timeout: 6000 }).then(() => true, () => false), 'top-bar messages: table (or empty-state row) rendered');
	await page.locator('#uxs-root .uxs-list-toolbar .uxs-btn--primary').click();
	const save = dialog(page).locator('.uxs-btn--primary');
	c.ok(await save.isDisabled(), 'top-bar message: Save disabled for empty text');
	await page.screenshot({ path: `${SHOTS}notice_${tag}_tb_modal.png` });
	if (lang === 'cs-CZ') await noLeaks(env, 'top-bar modal', await dialog(page).innerText(), { modal: true });
	await page.fill('#uxs-tb-text', '<b></b>');
	await save.click();
	await dialog(page).locator('[role=alert]').first().waitFor({ timeout: 10000 }).then(() => c.ok(true, 'top-bar message: empty-after-filter text rejected by server'), () => c.ok(false, 'top-bar message: empty-after-filter text rejected by server'));
	await page.fill('#uxs-tb-text', 'x'.repeat(5100));
	await save.click();
	await page.waitForFunction(() => document.querySelector('[role=dialog] .uxs-btn--primary')?.getAttribute('aria-busy') === 'false', null, { timeout: 15000 }).catch(() => {});
	c.ok(await dialog(page).locator('[role=alert]').isVisible(), 'top-bar message: over-long text rejected (422 alert)');
	await page.selectOption('#uxs-tb-mode', 'title_text');
	await page.fill('#uxs-tb-title', 'E2E Nadpis');
	await page.fill('#uxs-tb-text', `E2E druha ${stamp} zpráva <script>alert(1)</script><a href="https://example.com/e2e" onclick="alert(2)">odkaz</a>`);
	await save.click();
	await (await rowOf(page, 'E2E druha')).waitFor();
	c.ok(!/script|onclick|alert/i.test(sql(site, `SELECT body FROM jos_uxstudio_top_bar_slides WHERE body LIKE '%E2E druha ${stamp}%'`)), 'top-bar message: script and event handler stripped by the server (no leftover text)');
	// scheduled for the future: not shown
	await page.locator('#uxs-root .uxs-list-toolbar .uxs-btn--primary').click();
	await page.fill('#uxs-tb-text', `E2E budouci ${stamp}`);
	await page.fill('#uxs-tb-from', '2099-01-01T08:00');
	await dialog(page).locator('.uxs-btn--primary').click();
	await (await rowOf(page, 'E2E budouci')).waitFor();
	await page.waitForTimeout(500);
	c.ok(/2099/.test(await (await rowOf(page, 'E2E budouci')).innerText()), 'top-bar messages: schedule shown in table');
	// third: unpublished via toggle
	await page.locator('#uxs-root .uxs-list-toolbar .uxs-btn--primary').click();
	await page.fill('#uxs-tb-text', `E2E skryta ${stamp}`);
	await dialog(page).locator('.uxs-btn--primary').click();
	await (await rowOf(page, 'E2E skryta')).waitFor();
	await (await rowOf(page, 'E2E skryta')).locator('button.uxs-switch').click();
	await page.waitForTimeout(600);
	c.ok(await sqlIs(site, `SELECT published FROM jos_uxstudio_top_bar_slides WHERE body LIKE '%E2E skryta ${stamp}%'`, '0'), 'top-bar messages: unpublish toggle works');
	await page.screenshot({ path: `${SHOTS}notice_${tag}_tb_admin.png`, fullPage: true });
	f = await front(browser, issues, base);
	const all = await f.page.locator('#uxstudio-top-bar .uxstudio-top-bar__content').allInnerTexts();
	c.ok(all.length === 2 && all.some((x) => x.includes('E2E druha')) && !all.join('').includes('budouci') && !all.join('').includes('skryta'), `top-bar front: two active messages rotate, future/unpublished excluded (${all.length})`);
	c.ok(all.some((x) => x.includes('E2E Nadpis')), 'top-bar front: heading of a "heading and text" message shown');
	if (process.env.DBG) console.log(await f.page.locator('#uxstudio-top-bar').evaluate((e) => e.outerHTML.slice(0, 900)));
	const first = await f.page.locator('.uxstudio-top-bar__content.is-active').innerText();
	const second = await f.page.waitForFunction((prev) => { const a = document.querySelector('.uxstudio-top-bar__content.is-active'); return a && a.innerText !== prev ? a.innerText : false; }, first, { timeout: 20000 }).then((h) => h.jsonValue(), () => first);
	c.ok(first !== second, 'top-bar front: slideshow switches message');
	await f.ctx.close();
	// preview badge in admin
	await page.reload();
	await page.waitForLoadState('load');
	await tab(page, 1);
	c.ok(/2/.test(await page.locator('#uxs-root p', { has: page.locator('.uxs-badge') }).first().innerText()), 'top-bar messages: admin preview reports 2 rotating messages');
	// double click on Save creates exactly one message
	await page.locator('#uxs-root .uxs-list-toolbar .uxs-btn--primary').click();
	await dialog(page).waitFor();
	await page.fill('#uxs-tb-text', `E2E dvojklik ${stamp}`);
	await dialog(page).locator('.uxs-btn--primary').dblclick();
	await dialog(page).waitFor({ state: 'detached' }).catch(() => {});
	await page.waitForTimeout(900);
	c.ok(sql(site, `SELECT COUNT(*) FROM jos_uxstudio_top_bar_slides WHERE body LIKE '%E2E dvojklik ${stamp}%'`) === '1', 'top-bar message: double click on Save creates one message');
	// edit + delete
	await (await rowOf(page, 'E2E druha')).locator('.uxs-btn--small').first().click();
	await page.fill('#uxs-tb-text', `E2E druha upravena ${stamp}`);
	await dialog(page).locator('.uxs-btn--primary').click();
	c.ok(await appears(page, 'E2E druha upravena'), 'top-bar message: edited');
	for (const t of ['E2E druha', 'E2E budouci', 'E2E skryta', 'E2E dvojklik']) {
		await (await rowOf(page, t)).locator('.uxs-btn--danger').click();
		await confirmDanger(page);
		await page.waitForTimeout(400);
	}
	c.ok(await sqlIs(site, `SELECT COUNT(*) FROM jos_uxstudio_top_bar_slides WHERE body LIKE '${LIKE}'`, '0'), 'top-bar messages: deleted via UI');

	// --- no text at all: bar hidden
	await tab(page, 0);
	await setSource('');
	await saveSettings(page);
	f = await front(browser, issues, base);
	c.ok((await f.page.locator('#uxstudio-top-bar').count()) === 0, 'top-bar front: empty text and no messages = no bar');
	await f.ctx.close();
	// long text -> marquee
	await setSource('E2E ' + 'dlouhý text '.repeat(30));
	await saveSettings(page);
	f = await front(browser, issues, base, { width: 600 });
	await f.page.waitForTimeout(500);
	c.ok(/marquee/.test((await f.page.locator('#uxstudio-top-bar').getAttribute('class')) ?? ''), 'top-bar front: over-long single message scrolls (marquee)');
	await f.ctx.close();
	// one very long word on a phone
	await setSource('E2E ' + 'Dlouheslovobezmezer'.repeat(15));
	await saveSettings(page);
	f = await front(browser, issues, base, { width: 375 });
	const swBar = await f.page.evaluate(() => document.documentElement.scrollWidth);
	c.ok(swBar <= 376, `top-bar mobile: a very long word does not make the page scroll sideways (${swBar}px)`);
	await f.page.screenshot({ path: `${SHOTS}notice_${tag}_tb_mobile_long.png` });
	await f.ctx.close();
	await emptyAndErrorState(env, 'top-bar', /route=top-bar\/slides(&|$)/, 'top-bar messages', async () => { await tab(page, 1); });
}

async function openingHoursFlow(env) {
	const { page, site, c, L, tag, browser, issues, lang } = env;
	await openStudio(page, site, '#/module?id=opening-hours');
	await page.locator('#uxs-root [role=tab]').first().waitFor();
	await page.locator('#uxs-root table, #uxs-root .uxs-muted').first().waitFor();
	const t0 = await rootText(page);
	if (lang === 'cs-CZ') await noLeaks(env, 'opening-hours', t0);

	// --- create with validation
	await page.locator('#uxs-root .uxs-list-toolbar .uxs-btn--primary').click();
	await dialog(page).waitFor();
	const save = dialog(page).locator('.uxs-btn--primary');
	c.ok(await save.isDisabled(), 'location: Save disabled for empty name');
	await page.screenshot({ path: `${SHOTS}notice_${tag}_oh_modal.png` });
	if (lang === 'cs-CZ') await noLeaks(env, 'opening-hours modal', await dialog(page).innerText(), { modal: true });
	await page.fill('#uxs-oh-title', OH_TITLE);
	await page.fill('#uxs-oh-address', 'E2E ulice 1, Praha');
	await page.fill('#uxs-oh-phone', '+420 123 000 111');
	await page.fill('#uxs-oh-lat', '999');
	await page.fill('#uxs-oh-lng', '14.4');
	const e0 = await saveExpectError(page, save);
	c.ok(!!e0, 'location: latitude 999 rejected by server');
	await page.fill('#uxs-oh-lat', '50.0755');
	await page.fill('#uxs-oh-phone', 'abc not a phone');
	const e1 = await saveExpectError(page, save, e0);
	c.ok(!!e1 && e1 !== e0, `location: nonsense phone number rejected (${e1.slice(0, 60)})`);
	await page.fill('#uxs-oh-phone', '+420 123 000 111');
	// geocoding without key -> error toast
	await dialog(page).locator('.uxs-btn', { has: page.locator('svg.lucide-map-pin') }).click();
	await toast(page, 'error').waitFor({ timeout: 20000 }).then(() => c.ok(true, 'location: geocoding without API key reports an error'), () => c.ok(false, 'location: geocoding without API key reports an error'));
	// weekly hours: Monday 00:00-23:59, copy to Tue-Fri, weekend by hand
	await tab(page, 1, dialog(page));
	const rows = dialog(page).locator('table tbody tr');
	await rows.nth(0).locator('button', { has: page.locator('svg.lucide-plus') }).click();
	await rows.nth(0).locator('input[type=time]').nth(0).fill('10:00');
	await rows.nth(0).locator('input[type=time]').nth(1).fill('10:00');
	const e2 = await saveExpectError(page, save, e1);
	c.ok(!!e2 && e2 !== e1, `location: a range from 10:00 to 10:00 is rejected (${e2.slice(0, 60)})`);
	await rows.nth(0).locator('input[type=time]').nth(0).fill('00:00');
	await rows.nth(0).locator('input[type=time]').nth(1).fill('23:59');
	await dialog(page).locator('.uxs-btn', { has: page.locator('svg.lucide-copy') }).click();
	for (const i of [5, 6]) {
		await rows.nth(i).locator('button', { has: page.locator('svg.lucide-plus') }).click();
		await rows.nth(i).locator('input[type=time]').nth(0).fill('00:00');
		await rows.nth(i).locator('input[type=time]').nth(1).fill('23:59');
	}
	c.ok((await rows.nth(2).locator('input[type=time]').count()) === 2, 'location: copy Monday to Tue-Fri filled the other weekdays');
	// exceptions: a closed future day (listed on the card), no date -> ?
	await tab(page, 2, dialog(page));
	await dialog(page).locator('.uxs-btn', { has: page.locator('svg.lucide-plus') }).last().click();
	const e3 = await saveExpectError(page, save, e2);
	c.ok(!!e3 && e3 !== e2, `location: an exception without a date is rejected (${e3.slice(0, 60)})`);
	await dialog(page).locator('input[type=date]').first().fill(addDays(5));
	await dialog(page).locator('input[type=text]').first().fill('E2E Inventura');
	// announcements
	await tab(page, 3, dialog(page));
	await dialog(page).locator('.uxs-btn', { has: page.locator('svg.lucide-plus') }).last().click();
	await dialog(page).locator('input[type=text]').first().fill('E2E Oznameni o provozu');
	await dialog(page).locator('select').first().selectOption('warning');
	await page.screenshot({ path: `${SHOTS}notice_${tag}_oh_modal_ann.png` });
	await save.click();
	await dialog(page).waitFor({ state: 'detached', timeout: 20000 });
	const row = await rowOf(page, OH_TITLE);
	await row.waitFor();
	const locId = Number(sql(site, `SELECT id FROM jos_uxstudio_oh_locations WHERE title='${esc(OH_TITLE)}'`));
	const isOpenBadge = async (want) => {
		for (let i = 0; i < 24; i++) {
			const cls = (await (await rowOf(page, OH_TITLE)).locator('.uxs-badge').first().getAttribute('class').catch(() => '')) ?? '';
			if (!want || want.test(cls)) return cls;
			await page.waitForTimeout(250);
		}
		return 'timeout';
	};
	c.ok(/is-success/.test(await isOpenBadge(/is-success/)), 'location: state "open" with 00:00-23:59 hours');
	c.ok(!!locId, 'location: created');
	// narrow screen: the action buttons stay inside the viewport
	await page.setViewportSize({ width: 390, height: 844 });
	await page.waitForTimeout(500);
	const delBox = await (await rowOf(page, OH_TITLE)).locator('.uxs-btn--danger').boundingBox();
	c.ok(!!delBox && delBox.x + delBox.width <= 391 && delBox.x >= 0, `opening-hours admin mobile: row buttons inside the viewport (right edge ${delBox ? Math.round(delBox.x + delBox.width) : '?'}px)`);
	await page.screenshot({ path: `${SHOTS}notice_${tag}_oh_admin_mobile.png`, fullPage: true });
	await page.setViewportSize({ width: 1400, height: 900 });

	// --- joomla module through the Joomla admin UI is created in the "front" stage by the caller (env.moduleReady)
	const art = createArticle(site, `E2E Doba ${stamp}`, `<p>E2E</p><div id="e2e-card">{opening-hours id=${locId}}</div><div id="e2e-badge">{opening-hours-status id=${locId}}</div><div id="e2e-all">{opening-hours id=all}</div>`);
	const aurl = `${site.url}/index.php?option=com_content&view=article&id=${art}`;
	let f = await front(browser, issues, aurl);
	const card = f.page.locator('#e2e-card .uxs-oh');
	c.ok((await card.innerText()).includes(OH_TITLE), 'hours front: card with the location name');
	c.ok(/Open|Otev/i.test(await card.locator('.uxs-oh__badge').innerText()) && (await card.locator('.uxs-oh__badge.is-open').count()) === 1, 'hours front: badge says open');
	c.ok((await card.locator('.uxs-oh__banner--warning').innerText()).includes('E2E Oznameni'), 'hours front: warning announcement shown');
	c.ok((await card.locator('.uxs-oh__table tr').count()) === 7 && (await card.locator('.uxs-oh__table tr.is-today').count()) === 1, 'hours front: weekly table with 7 rows, today highlighted');
	c.ok((await card.locator('.uxs-oh__table').innerText()).includes('00:00–23:59'), 'hours front: hours text 00:00–23:59');
	c.ok((await card.locator('.uxs-oh__phone a[href^="tel:+420123000111"]').count()) === 1, 'hours front: phone is a tel: link');
	const up = await card.locator('.uxs-oh__exceptions').innerText().catch(() => '');
	c.ok(up.includes('E2E Inventura'), 'hours front: upcoming exception listed');
	c.ok((await f.page.locator('#e2e-badge .uxs-oh__badge').count()) === 1, 'hours front: {opening-hours-status} shows just the badge');
	c.ok((await f.page.locator('#e2e-all .uxs-oh').count()) >= 2, 'hours front: id=all lists every location');
	await f.page.screenshot({ path: `${SHOTS}notice_${tag}_oh_front.png`, fullPage: true });
	// public JSON status
	const st = await f.page.request.get(`${site.url}/index.php?option=com_uxstudio&task=public.dispatch&format=json&route=opening-hours/locations/${locId}/status`);
	const stj = await st.json().catch(() => ({}));
	c.ok(st.ok() && (stj.data?.open === true), `hours API: public status says open (${JSON.stringify(stj).slice(0, 80)})`);
	const st404 = await f.page.request.get(`${site.url}/index.php?option=com_uxstudio&task=public.dispatch&format=json&route=opening-hours/locations/999999/status`);
	c.ok(st404.status() === 404, `hours API: unknown location gives 404 (${st404.status()})`);
	await f.ctx.close();

	// --- closed today exception => closed
	await (await rowOf(page, OH_TITLE)).locator('.uxs-btn--small').nth(1).click();
	await tab(page, 2, dialog(page));
	await dialog(page).locator('.uxs-btn', { has: page.locator('svg.lucide-plus') }).last().click();
	await dialog(page).locator('input[type=date]').last().fill(TODAY);
	await dialog(page).locator('input[type=text]').nth(1).fill('E2E Dnes zavreno');
	await dialog(page).locator('.uxs-btn--primary').click();
	await dialog(page).waitFor({ state: 'detached' });
	await page.waitForTimeout(500);
	c.ok(/is-danger/.test(await isOpenBadge(/is-danger/)), 'location: closed-today exception flips admin state to closed');
	f = await front(browser, issues, aurl);
	c.ok((await f.page.locator('#e2e-card .uxs-oh__badge.is-closed').count()) === 1, 'hours front: closed badge with today exception');
	c.ok((await f.page.locator('#e2e-card .uxs-oh__note').innerText()).includes('E2E Dnes zavreno'), 'hours front: today note shows the exception label');
	await f.page.screenshot({ path: `${SHOTS}notice_${tag}_oh_front_closed.png` });
	await f.ctx.close();
	// remove the exception for today again
	await (await rowOf(page, OH_TITLE)).locator('.uxs-btn--small').nth(1).click();
	await tab(page, 2, dialog(page));
	await dialog(page).locator('.uxs-card', { hasText: '' }).filter({ has: page.locator(`input[value="${TODAY}"]`) }).locator('.uxs-btn--danger').click();
	await dialog(page).locator('.uxs-btn--primary').click();
	await dialog(page).waitFor({ state: 'detached' });
	await page.waitForTimeout(400);
	c.ok(/is-success/.test(await isOpenBadge(/is-success/)), 'location: open again after removing the exception');

	// --- temporary closure override
	await (await rowOf(page, OH_TITLE)).locator('.uxs-btn--small', { has: page.locator('svg.lucide-door-closed') }).click();
	await dialog(page).waitFor();
	await page.selectOption('#uxs-oh-minutes', '15');
	const opts = await page.locator('#uxs-oh-minutes option').allInnerTexts();
	c.ok(opts.length === 7 && !BAD_PLURAL.test(opts.join(' | ')), `override: duration labels are grammatical (${opts.join(', ')})`);
	if (lang === 'cs-CZ') await noLeaks(env, 'override modal', await dialog(page).innerText(), { modal: true });
	await page.fill('#uxs-oh-reason', 'E2E obed');
	await dialog(page).locator('.uxs-btn--danger').click();
	await dialog(page).waitFor({ state: 'detached' });
	await page.waitForTimeout(500);
	c.ok(/is-danger/.test(await isOpenBadge(/is-danger/)) && (await (await rowOf(page, OH_TITLE)).innerText()).includes('E2E obed'), 'override: location closed with public reason in admin');
	f = await front(browser, issues, aurl);
	c.ok((await f.page.locator('#e2e-card .uxs-oh__badge.is-closed').count()) === 1 && (await f.page.locator('#e2e-card .uxs-oh__note').innerText()).includes('E2E obed'), 'override: front shows closed + reason');
	await f.ctx.close();
	await (await rowOf(page, OH_TITLE)).locator('.uxs-btn--small', { has: page.locator('svg.lucide-door-open') }).click();
	await page.waitForTimeout(600);
	c.ok(/is-success/.test(await isOpenBadge(/is-success/)), 'override: reopen clears the closure');
	await page.screenshot({ path: `${SHOTS}notice_${tag}_oh_admin.png`, fullPage: true });

	// --- schema.org
	await tab(page, 1);
	await page.locator('.uxs-subtabs [role=tab]').nth(1).click();
	await toggleByLabel(page, 'schema_enabled', true);
	await page.fill('#uxs-schema_location_ids', String(locId));
	await saveSettings(page);
	f = await front(browser, issues, site.url + '/');
	const ld = await f.page.locator('script[type="application/ld+json"]').allTextContents();
	const mine = ld.map((x) => { try { return JSON.parse(x); } catch { return null; } }).filter(Boolean);
	const flat = JSON.stringify(mine);
	c.ok(flat.includes(OH_TITLE) && flat.includes('OpeningHoursSpecification') && flat.includes('LocalBusiness'), 'schema.org: LocalBusiness + OpeningHoursSpecification for the location on the home page');
	c.ok(flat.includes('00:00') && flat.includes('23:59'), 'schema.org: weekly opens/closes present');
	c.ok(flat.includes(addDays(5)), 'schema.org: dated exception (closed day) present');
	await f.ctx.close();
	await toggleByLabel(page, 'schema_enabled', false);
	await saveSettings(page);
	f = await front(browser, issues, site.url + '/');
	c.ok(!(await f.page.locator('script[type="application/ld+json"]').allTextContents()).join('').includes(OH_TITLE), 'schema.org: switched off = no JSON-LD for the location');
	await f.ctx.close();
	// dropdown of id=all
	await page.locator('.uxs-subtabs [role=tab]').nth(0).click();
	await toggleByLabel(page, 'show_location_dropdown', true);
	await saveSettings(page);
	f = await front(browser, issues, aurl);
	c.ok((await f.page.locator('#e2e-all [data-uxs-oh-multi] select').count()) === 1, 'hours front: location dropdown with id=all');
	if ((await f.page.locator('#e2e-all [data-uxs-oh-multi] select option').count()) > 1) {
		await f.page.selectOption('#e2e-all [data-uxs-oh-multi] select', { index: 1 });
		const shown = await f.page.locator('#e2e-all .uxs-oh:not(.is-hidden)').count();
		c.ok(shown === 1, `hours front: dropdown switches the visible card (${shown})`);
	}
	await f.ctx.close();
	await toggleByLabel(page, 'show_location_dropdown', false);
	await saveSettings(page);
	await tab(page, 0);

	// --- Joomla module (through the Joomla admin UI)
	await joomlaModuleFlow(env, locId, OH_TITLE);
	await openStudio(page, site, '#/module?id=opening-hours');
	await page.locator('#uxs-root tbody tr', { hasText: OH_TITLE }).first().waitFor();

	// --- edit name, then delete
	await (await rowOf(page, OH_TITLE)).locator('.uxs-btn--small').nth(1).click();
	await page.fill('#uxs-oh-title', `${OH_TITLE} X`);
	await dialog(page).locator('.uxs-btn--primary').click();
	c.ok(await appears(page, `${OH_TITLE} X`), 'location: renamed');
	await (await rowOf(page, `${OH_TITLE} X`)).locator('.uxs-btn--danger').click();
	await confirmDanger(page);
	await page.waitForTimeout(500);
	c.ok(await sqlIs(site, `SELECT COUNT(*) FROM jos_uxstudio_oh_locations WHERE title LIKE '${LIKE}'`, '0'), 'location: deleted via UI');
	// double click on Save creates exactly one location
	await page.locator('#uxs-root .uxs-list-toolbar .uxs-btn--primary').click();
	await dialog(page).waitFor();
	await page.fill('#uxs-oh-title', `E2E Dvojklik ${stamp}`);
	await dialog(page).locator('.uxs-btn--primary').dblclick();
	await dialog(page).waitFor({ state: 'detached' }).catch(() => {});
	await page.waitForTimeout(900);
	c.ok(sql(site, `SELECT COUNT(*) FROM jos_uxstudio_oh_locations WHERE title='E2E Dvojklik ${stamp}'`) === '1', 'location: double click on Save creates one location');
	await (await rowOf(page, 'E2E Dvojklik')).locator('.uxs-btn--danger').click();
	await confirmDanger(page);
	await page.waitForTimeout(400);
	dropArticle(site, art);
	await emptyAndErrorState(env, 'opening-hours', /route=opening-hours\/locations(&|$)/, 'opening-hours');
}

/** Creates a site module "UX1 Studio - Opening Hours" in the Joomla administrator, checks it on the front and trashes it. */
async function joomlaModuleFlow(env, locId, title) {
	const { page, site, c, browser, issues, tag } = env;
	const eid = sql(site, `SELECT extension_id FROM jos_extensions WHERE element='mod_uxstudio_opening_hours'`);
	await page.goto(`${site.url}/administrator/index.php?option=com_modules&view=select&client_id=0`);
	await page.waitForLoadState('load');
	// (Joomla 6 only opens the add form through the "select a module type" list)
	await page.locator(`a[href*="module.add"][href*="eid=${eid}"]`).first().click();
	await page.waitForLoadState('load');
	if (!(await page.locator('#jform_title').waitFor({ timeout: 15000 }).then(() => true, () => false))) {
		await page.goto(`${site.url}/administrator/index.php?option=com_modules&view=select&client_id=0`);
		await page.locator(`a[href*="module.add"][href*="eid=${eid}"]`).first().click();
		await page.locator('#jform_title').waitFor({ timeout: 15000 }).catch(() => {});
	}
	c.ok(!!(await page.locator('#jform_title').count()), `joomla module: edit form opens (${page.url().slice(-60)})`);
	if (!(await page.locator('#jform_title').count())) return;
	await page.fill('#jform_title', `E2E modul ${stamp}`);
	await page.selectOption('#jform_params_location', String(locId));
	await page.selectOption('#jform_params_display', 'card');
	await page.locator('joomla-field-fancy-select .choices__inner').first().click();
	const pos = page.locator('joomla-field-fancy-select input.choices__input--cloned').first();
	await pos.pressSequentially('sidebar-right', { delay: 30 });
	await page.keyboard.press('Enter');
	await page.waitForTimeout(400);
	if ((await page.locator('#jform_position').inputValue()) !== 'sidebar-right') {
		// the Choices widget did not take the custom value: set it on the underlying select
		await page.locator('#jform_position').evaluate((el) => { el.add(new Option('sidebar-right', 'sidebar-right', true, true)); el.value = 'sidebar-right'; });
	}
	await page.screenshot({ path: `${SHOTS}notice_${tag}_joomla_module.png` });
	await page.click('#toolbar-apply button, joomla-toolbar-button#toolbar-apply button');
	await page.waitForLoadState('load');
	const ok = (await page.locator('joomla-alert, .alert-message').allInnerTexts()).join(' ');
	c.ok(/saved|uložen/i.test(ok), `joomla module: saved (${ok.replace(/\s+/g, ' ').slice(0, 60)})`);
	const f = await front(browser, issues, site.url + '/');
	const mod = f.page.locator(`.uxs-oh[data-location="${locId}"]`);
	c.ok((await mod.count()) >= 1, 'joomla module: card rendered on the site by mod_uxstudio_opening_hours');
	await f.page.screenshot({ path: `${SHOTS}notice_${tag}_joomla_module_front.png` });
	await f.ctx.close();
	// status-only display
	await page.selectOption('#jform_params_display', 'status');
	await page.click('#toolbar-apply button, joomla-toolbar-button#toolbar-apply button');
	await page.waitForLoadState('load');
	const f2 = await front(browser, issues, site.url + '/');
	c.ok((await f2.page.locator(`.uxs-oh[data-location="${locId}"]`).count()) === 0 && (await f2.page.locator('.uxs-oh__badge').count()) >= 1, 'joomla module: "badge only" display renders just the badge');
	await f2.ctx.close();
	await page.click('#toolbar-cancel button, joomla-toolbar-button#toolbar-cancel button');
	await page.waitForLoadState('load');
	sql(site, `DELETE FROM jos_modules_menu WHERE moduleid IN (SELECT id FROM jos_modules WHERE title = 'E2E modul ${stamp}')`);
	sql(site, `DELETE FROM jos_modules WHERE title = 'E2E modul ${stamp}'`);
	void title;
}

async function viewOnly(env) {
	// dark theme: main screens + one modal each, screenshots and a background check
	const { page, site, c, tag } = env;
	for (const id of ['notice-board', 'top-bar', 'opening-hours']) {
		await openStudio(page, site, `#/module?id=${id}`);
		if (!(await page.locator('#uxs-root [role=tab]').first().waitFor({ timeout: 12000 }).then(() => true, () => false))) {
			await page.reload(); // the shared sites are rebuilt / re-logged by other test runs now and then
			await page.locator('#uxs-root [role=tab]').first().waitFor();
		}
		await page.waitForFunction(() => !/Loading|Načítání|Načítám/.test(document.querySelector('#uxs-root')?.innerText ?? 'Loading') && !document.querySelector('#uxs-root .uxs-loading, #uxs-root [aria-busy=true]'), null, { timeout: 15000 }).catch(() => {});
		await page.waitForTimeout(600);
			// first non-transparent background up the tree (the root itself is transparent)
		const bg = await page.locator('#uxs-root').evaluate((root) => { for (let n = root.firstElementChild ?? root; n; n = n.parentElement) { const b = getComputedStyle(n).backgroundColor; if (!/rgba\(.*, 0\)$|transparent/.test(b)) return b; } return 'rgb(255, 255, 255)'; });
		const dark = await page.evaluate(() => localStorage.getItem('uxstudio-theme'));
		const lum = bg.match(/\d+/g)?.slice(0, 3).map(Number).reduce((a, b) => a + b, 0) ?? 999;
		c.ok(dark !== 'dark' || lum < 300, `${id}: dark mode background is dark (${bg})`);
		await page.screenshot({ path: `${SHOTS}notice_${tag}_${id}.png`, fullPage: true });
		if (id === 'top-bar') {
			await page.locator('#uxs-root [role=tab]').nth(1).click();
			await page.locator('#uxs-root .uxs-list-toolbar .uxs-btn--primary').waitFor();
			await page.waitForTimeout(600);
			await page.screenshot({ path: `${SHOTS}notice_${tag}_${id}_messages.png`, fullPage: true });
		}
		const add = page.locator('#uxs-root .uxs-list-toolbar .uxs-btn--primary').first();
		if (await add.count()) {
			await add.click();
			await dialog(page).waitFor();
			await page.screenshot({ path: `${SHOTS}notice_${tag}_${id}_modal.png` });
			await page.keyboard.press('Escape');
			await dialog(page).waitFor({ state: 'detached' });
		}
	}
}

/* ------------------------------------------------------------------ runner */

async function runPass(browser, site, lang, theme, mode) {
	setAdminLanguage(site, lang);
	const { ctx, issues } = await context(browser, { ignore: [/challenges\.cloudflare\.com/, /favicon/i, /status of (409|422|424)/, /^HTTP (409|422|424) /] });
	await ctx.addInitScript((th) => { try { if (location.protocol.startsWith('http')) localStorage.setItem('uxstudio-theme', th); } catch (e) { /* about:blank */ } }, theme);
	const tag = `${site.key}_${lang}_${theme}`;
	const c = checker(`notice ${tag} ${mode}`);
	const L = LANGS[lang];
	const label = lang === 'cs-CZ'
		? { dismissible: 'Návštěvník může lištu zavřít', sticky: 'Držet lištu při posouvání stránky', schema: 'Vypisovat schema.org JSON-LD (SEO)', dropdown: 'Výběr pobočky' }
		: { dismissible: 'Visitors can close the bar', sticky: 'Keep the bar visible when scrolling', schema: 'Output schema.org JSON-LD (SEO)', dropdown: 'Location dropdown' };
	const dd = sql(site, 'SELECT 1');
	void dd;
	const env = { browser, issues, site, c, L, tag, lang, label };
	let bad = 0;
	try {
		const page = await adminLogin(ctx, site);
		env.page = page;
		page.setDefaultTimeout(25000);
		const stats = page.locator('button:has-text("No"), .js-pstats-btn-allow-never').first();
		void stats;
		const only = (process.argv.find((a) => a.startsWith('--only=')) ?? '').slice(7);
		const all = { dash: dashboardFlow, nb: noticeBoardFlow, tb: topBarFlow, oh: openingHoursFlow };
		const steps = mode === 'full' ? (only ? [all[only]] : Object.values(all)) : [viewOnly];
		for (const step of steps) {
			try {
				setAdminLanguage(site, lang); // other agents share the admin account and reset its language: pin it again for every flow
				await step(env);
			} catch (e) {
				c.ok(false, `${step.name} crashed: ${String(e.message).split('\n')[0].slice(0, 220)} @${(e.stack.match(/notice\.mjs:(\d+)/) ?? [])[1]}`);
				await page.screenshot({ path: `${SHOTS}notice_${tag}_crash_${step.name}.png` }).catch(() => {});
			}
		}
	} catch (e) {
		c.ok(false, `pass crashed: ${String(e.message).split('\n')[0]}`);
	}
	bad += c.done(issues);
	await ctx.close();
	return bad;
}

const browser = await launch();
let bad = 0;
for (const site of sitesFromArgv()) {
	const snap = snapshot(site);
	try {
		cleanup(site);
		const only = (process.argv.find((a) => a.startsWith('--lang=')) ?? '').slice(7);
		if (!only || only === 'en') bad += await runPass(browser, site, 'en-GB', 'light', 'full');
		cleanup(site);
		if (!only || only === 'cs') bad += await runPass(browser, site, 'cs-CZ', 'light', 'full');
		cleanup(site);
		if (!only) {
			bad += await runPass(browser, site, 'en-GB', 'dark', 'view');
			bad += await runPass(browser, site, 'cs-CZ', 'dark', 'view');
		}
	} finally {
		cleanup(site);
		restore(site, snap);
	}
}
await browser.close();
console.log(bad ? `== TOTAL: ${bad} problems` : '== TOTAL: all passed');
process.exit(bad ? 1 : 0);
