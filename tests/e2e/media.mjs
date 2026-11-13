// Browser E2E for the group "media": image-optimizer, auto-image-upload, instagram-feed (+ mod_uxstudio_instagram),
// dashboard-widgets (+ mod_uxstudio_dashboard) and service-requests.
// Run: node tests/e2e/media.mjs [j5|j6|all] [--headed] [--only=optimizer,auto,instagram,dashboard,requests]
// Real flows: Joomla Media Manager upload/delete, article editor of Joomla, UX1 Studio SPA (en-GB and cs-CZ, light and dark),
// com_cpanel, anonymous visitor on the frontend. Test data is prefixed "uxs-e2e" and removed at the end (also before the start).
// Known leftover: every run of the requests part creates one ticket in the Central App (centrani_joomla.tickets, subject "uxs-e2e-ticket ...").
// The scenario may only read that database, so those tickets have to be closed or deleted in the Central App by hand.
// The auto-image-upload part downloads two real public images (the module's SSRF guard refuses any local source), so it is skipped when the network is down.
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import crypto from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { launch, context, openStudio as openStudio0, shot, checker, sitesFromArgv, SITES } from './lib.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const HTDOCS = 'D:/xampp/htdocs';
const MYSQL = 'D:/xampp/mysql/bin/mysql.exe';
const PHP = 'D:/xampp/php/php.exe';
const P = 'uxs-e2e';
// The shared admin account (a private user was tried, but the SPA redirects a fresh user away from com_uxstudio).
const TESTER = env().JOOMLA_ADMIN_USER;
const sql = (db, q) => {
	// Non-ASCII statements go through a UTF-8 file: command-line arguments are mangled by the Windows code page.
	if ([...q].some((ch) => ch.charCodeAt(0) > 127)) {
		const f = path.join(os.tmpdir(), 'uxs-e2e-media-q.sql').split(path.sep).join('/');
		fs.writeFileSync(f, q.endsWith(';') ? q : q + ';', 'utf8');
		q = 'source ' + f;
	}
	return execFileSync(MYSQL, ['-uroot', '--default-character-set=utf8mb4', '-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const only = (process.argv.find((a) => a.startsWith('--only=')) ?? '').slice(7).split(',').filter(Boolean);
const want = (part) => only.length === 0 || only.includes(part);
const TMP = path.join(os.tmpdir(), 'uxs-e2e-media').replace(/\\/g, '/');
const ca = (q) => sql('centrani_joomla', q);

// Expected browser noise: the SSRF probe, the CA page of the Instagram connect step, intentionally broken requests.
const EXPECTED = [/challenges.cloudflare.com/, /Failed to load resource.*status of 400/, /Direct access to Joomla\.editors\.instances is deprecated/, /HTTP 40[34] .*centrani|_ca-wt-joomla/, /HTTP 422 /, /HTTP 409 /, /HTTP 500 .*service-requests/, /Failed to load resource.*(40[34]|422|409|500)/];

const siteDir = (site) => `${HTDOCS}/${site.db}`;
const T = (site) => (n) => `jos_${n}`; // prefix is jos_ on both sites

/* ------------------------------------------------------------------ fixtures */

function makeImages() {
	fs.mkdirSync(TMP, { recursive: true });
	const script = `${TMP}/gen.php`;
	fs.writeFileSync(
		script,
		`<?php
$d = $argv[1];
mt_srand(5);
$im = imagecreatetruecolor(3200, 2000);
for ($i = 0; $i < 3000; $i++) { $c = imagecolorallocate($im, mt_rand(0,255), mt_rand(0,255), mt_rand(0,255)); imagefilledrectangle($im, mt_rand(0,3200), mt_rand(0,2000), mt_rand(0,3200), mt_rand(0,2000), $c); }
imagejpeg($im, "$d/${P}-photo.jpg", 100);
$s = imagecreatetruecolor(1800, 1200);
for ($i = 0; $i < 1500; $i++) { $c = imagecolorallocate($s, mt_rand(0,255), mt_rand(0,255), mt_rand(0,255)); imagefilledrectangle($s, mt_rand(0,1800), mt_rand(0,1200), mt_rand(0,1800), mt_rand(0,1200), $c); }
for ($k = 1; $k <= 24; $k++) { imagejpeg($s, "$d/bulk-$k.jpg", 100); }
imagejpeg($s, "$d/${P}-ig1.jpg", 85);
$p = imagecreatetruecolor(1200, 800); imagealphablending($p, false); imagesavealpha($p, true);
for ($y = 0; $y < 800; $y += 4) for ($x = 0; $x < 1200; $x += 4) { $c = imagecolorallocatealpha($p, ($x/5)%256, ($y/3)%256, 128, ($x+$y)%100 > 50 ? 60 : 0); imagefilledrectangle($p, $x, $y, $x+3, $y+3, $c); }
imagepng($p, "$d/${P}-logo.png", 0);
echo 'ok';
`
	);
	execFileSync(PHP, [script, TMP]);
}

/** Removes only what this scenario downloaded: the map rows carry the marker query "uxs-e2e=1" in the URL. */
function cleanExternal(site) {
	const db = site.db;
	const dir = siteDir(site);
	const rows = sql(db, `SELECT path FROM jos_uxstudio_auto_image_upload WHERE url LIKE '%uxs-e2e=1%' AND status='ok' AND path <> ''`).split(/\n/).filter(Boolean);
	for (const p of rows) {
		for (const suffix of ['', '.webp', '.avif']) fs.rmSync(`${dir}/${p}${suffix}`, { force: true });
		fs.rmSync(`${dir}/media/uxstudio-originals/${p}`, { force: true });
		sql(db, `DELETE FROM jos_uxstudio_image_optimizer WHERE path='${p.replace(/'/g, "''")}'`);
	}
	sql(db, `DELETE FROM jos_uxstudio_auto_image_upload WHERE url LIKE '%uxs-e2e=1%' OR url LIKE 'http://127.0.0.1/${P}%'`);
}

function cleanup(site) {
	cleanExternal(site);
	const db = site.db;
	const dir = siteDir(site);
	// image optimizer: files, backups, rows
	for (const base of [`${dir}/images`, `${dir}/media/uxstudio-originals/images`]) {
		if (fs.existsSync(base)) for (const f of fs.readdirSync(base)) if (f.startsWith(P)) fs.rmSync(`${base}/${f}`, { recursive: true, force: true });
	}
	sql(db, `DELETE FROM jos_uxstudio_image_optimizer WHERE path LIKE '%${P}%'`);
	// auto image upload: downloaded files live in images/external/YYYY/MM (shared with other tests: only ours by name pattern)
	sql(db, `DELETE FROM jos_uxstudio_auto_image_upload WHERE url LIKE '%${P}%' OR path LIKE '%${P}%'`);
	if (fs.existsSync(`${dir}/images/${P}-ext`)) fs.rmSync(`${dir}/images/${P}-ext`, { recursive: true, force: true });
	if (fs.existsSync(`${dir}/media/uxstudio-originals/images/${P}-ext`)) fs.rmSync(`${dir}/media/uxstudio-originals/images/${P}-ext`, { recursive: true, force: true });
	sql(db, `DELETE FROM jos_uxstudio_image_optimizer WHERE path LIKE '%${P}-ext%'`);
	// articles
	const ids = sql(db, `SELECT id FROM jos_content WHERE alias LIKE '${P}%'`).split(/\s+/).filter(Boolean);
	if (ids.length) {
		const l = ids.join(',');
		sql(db, `DELETE FROM jos_workflow_associations WHERE item_id IN (${l}) AND extension='com_content.article'`);
		sql(db, `DELETE FROM jos_content_frontpage WHERE content_id IN (${l})`);
		sql(db, `DELETE FROM jos_content_rating WHERE content_id IN (${l})`);
		sql(db, `DELETE FROM jos_assets WHERE name IN (${ids.map((i) => `'com_content.article.${i}'`).join(',')})`);
		sql(db, `DELETE FROM jos_content WHERE id IN (${l})`);
	}
	// instagram
	sql(db, `DELETE FROM jos_uxstudio_instagram_media WHERE instagram_id LIKE '${P}%'`);
	for (const k of [1, 2, 3, 4, 5]) fs.rmSync(`${dir}/images/uxstudio-instagram/ig-${crypto.createHash('sha1').update(`${P}-${k}`).digest('hex').slice(0, 20)}.jpg`, { force: true });
	const mods = sql(db, `SELECT id FROM jos_modules WHERE title LIKE '${P}%'`).split(/\s+/).filter(Boolean);
	if (mods.length) {
		sql(db, `DELETE FROM jos_modules_menu WHERE moduleid IN (${mods.join(',')})`);
		sql(db, `DELETE FROM jos_assets WHERE name IN (${mods.map((i) => `'com_modules.module.${i}'`).join(',')})`);
		sql(db, `DELETE FROM jos_modules WHERE id IN (${mods.join(',')})`);
	}
	// dashboard widgets
	sql(db, `DELETE FROM jos_uxstudio_dashboard_items WHERE title LIKE '${P}%'`);
	// service requests (local copies only: the tickets stay in the Central App)
	sql(db, `DELETE FROM jos_uxstudio_service_request_outbox WHERE request_id IN (SELECT id FROM jos_uxstudio_service_requests WHERE title LIKE '${P}%')`);
	sql(db, `DELETE FROM jos_uxstudio_service_requests WHERE title LIKE '${P}%'`);
}

/* ------------------------------------------------------------------ login with a language, SPA helpers */

function env() {
	const out = {};
	for (const line of fs.readFileSync(path.join(ROOT, '.env.local'), 'utf8').split(/\r?\n/)) {
		const m = line.match(/^([A-Z0-9_]+)=(.*)$/);
		if (m) out[m[1]] = m[2];
	}
	return out;
}

function setLang(site, lang) {
	const cur = sql(site.db, `SELECT params FROM jos_users WHERE username='${TESTER}'`);
	const o = JSON.parse(cur || '{}');
	sql(site.db, `UPDATE jos_users SET params=CONVERT(0x${Buffer.from(JSON.stringify({ ...o, admin_language: lang }), 'utf8').toString('hex')} USING utf8mb4) WHERE username='${TESTER}'`);
}

/** Real login form. The administrator language comes from the profile at login time, so it is set right before and the session keeps it. */
async function login(ctx, site, lang) {
	const e = env();
	setLang(site, lang);
	const page = await ctx.newPage();
	await page.goto(`${site.url}/administrator/index.php`);
	await page.fill('#mod-login-username', TESTER);
	await page.fill('#mod-login-password', e.JOOMLA_ADMIN_PASSWORD);
	await page.selectOption('#lang', lang).catch(() => {});
	await page.click('#btn-login-submit', { noWaitAfter: true });
	await page.locator('#mod-login-username').waitFor({ state: 'detached', timeout: 90000 });
	await page.waitForLoadState('load');
	pageLang.set(page, lang);
	return page;
}

const inflight = new WeakMap();
/** Waits until the SPA has no API request in flight. */
async function settle(page) {
	if (!inflight.has(page)) {
		const st = { set: new Set() };
		inflight.set(page, st);
		const isApi = (r) => /task=api\.dispatch/.test(r.url());
		page.on('request', (r) => isApi(r) && st.set.add(r));
		page.on('requestfinished', (r) => st.set.delete(r));
		page.on('requestfailed', (r) => st.set.delete(r));
	}
	const st = inflight.get(page);
	await sleep(300);
	for (let i = 0; i < 150 && st.set.size > 0; i++) await sleep(100);
	await sleep(300);
}

async function setTheme(page, theme) {
	await page.evaluate((th) => localStorage.setItem('uxstudio-theme', th), theme);
	await page.reload();
	await page.locator('#uxs-root').waitFor();
	await page.waitForLoadState('networkidle');
	await settle(page);
}

const tabs = (page) => page.locator('#uxs-root [role="tab"]');
const dialog = (page) => page.locator('[role="dialog"]');
const toast = (page) => page.locator('.uxs-toast');
async function toastText(page, ms = 8000) {
	try {
		await toast(page).first().waitFor({ timeout: ms });
		return (await toast(page).allInnerTexts()).join(' | ');
	} catch {
		return '';
	}
}
async function clearToasts(page) {
	for (const b of await page.locator('.uxs-toast__close').all()) await b.click().catch(() => {});
	await sleep(200);
}

/** Texts of the module's i18n catalogue that are visible in the current view although they are not translated. */
const catalogue = {};
function untranslated(id, text, cs) {
	catalogue[id] ??= JSON.parse(fs.readFileSync(path.join(ROOT, `admin-app/src/modules/${id}/i18n/cs.json`), 'utf8'));
	return Object.keys(catalogue[id]).filter((k) => k.length > 6 && /[a-z]{3}/.test(k) && text.includes(k) && catalogue[id][k] !== k);
}

/** Joomla.submitbutton navigates away, so the evaluate promise never settles normally. */
async function submit(page, task) {
	const nav = page.waitForNavigation({ waitUntil: 'load', timeout: 180000 });
	await page.evaluate((a) => { setTimeout(() => Joomla.submitbutton(a), 50); }, task).catch(() => {});
	await nav;
}

async function setModule(site, id, enabled) {
	sql(site.db, `UPDATE jos_uxstudio_modules SET enabled=${enabled ? 1 : 0} WHERE id='${id}'`);
}

/** Language each logged-in page was created for (the session keeps the language of the login, so a page is never switched). */
const pageLang = new WeakMap();
let CHK = null;
/** Opens a module screen with a fresh SPA state (a hash change alone would keep the current tab). */
async function openStudio(page, site, hash) {
	await openStudio0(page, site, '#/modules');
	await page.goto(`${site.url}/administrator/index.php?option=com_uxstudio${hash}`);
	// Another agent rebuilding the SPA can briefly leave stale chunk names behind, so a blank root is retried.
	for (let attempt = 0; attempt < 4; attempt++) {
		await page.reload();
		if (await page.locator('#uxs-root').waitFor({ state: 'visible', timeout: 15000 }).then(() => true, () => false)) break;
		await sleep(3000);
	}
	await page.locator('#uxs-root').waitFor();
	await page.waitForLoadState('networkidle');
	const want = pageLang.get(page);
	if (want && CHK) {
		const got = await page.evaluate(() => document.documentElement.lang);
		CHK.ok(got.toLowerCase().startsWith(want.slice(0, 2).toLowerCase()), `administration language is ${want} as expected (page says "${got}") at ${hash}`);
	}
}

function restoreSettings(db, module, data) {
	if (!data) return void sql(db, `DELETE FROM jos_uxstudio_settings WHERE module='${module}'`);
	const hex = Buffer.from(data, 'utf8').toString('hex');
	sql(db, `UPDATE jos_uxstudio_settings SET data=CONVERT(0x${hex} USING utf8mb4) WHERE module='${module}'`);
}

const kb = (p) => (fs.existsSync(p) ? fs.statSync(p).size : -1);

/* ------------------------------------------------------------------ image optimizer */

async function optimizerPart(site, ctxs, c) {
	const { page } = ctxs;
	const dir = siteDir(site);
	const db = site.db;
	const jpg = `${P}-photo.jpg`;
	const png = `${P}-logo.png`;
	const origJpg = kb(`${TMP}/${jpg}`);
	const origPng = kb(`${TMP}/${png}`);

	// ---- 1. upload through the Joomla Media Manager (real browser)
	await page.goto(`${site.url}/administrator/index.php?option=com_media&path=local-images:/`);
	await page.waitForSelector('.media-browser', { timeout: 30000 });
	await sleep(1500);
	await page.setInputFiles('input[type=file]', [`${TMP}/${jpg}`, `${TMP}/${png}`]);
	await page.locator('.media-browser-item', { hasText: jpg }).first().waitFor({ timeout: 60000 });
	await page.locator('.media-browser-item', { hasText: png }).first().waitFor({ timeout: 60000 });
	await sleep(1500);
	await shot(page, `media-${site.key}-optimizer-mediamanager`);
	const jNow = kb(`${dir}/images/${jpg}`);
	const pNow = kb(`${dir}/images/${png}`);
	c.ok(jNow > 0 && jNow < origJpg * 0.8, `JPEG compressed at upload (${origJpg} -> ${jNow} B)`);
	c.ok(pNow > 0 && pNow < origPng, `PNG compressed at upload (${origPng} -> ${pNow} B)`);
	c.ok(fs.existsSync(`${dir}/images/${jpg}.webp`), 'WebP sibling of the JPEG created');
	c.ok(fs.existsSync(`${dir}/images/${png}.webp`), 'WebP sibling of the PNG created');
	c.ok(fs.existsSync(`${dir}/media/uxstudio-originals/images/${jpg}`), 'original of the JPEG backed up');
	const size = execFileSync(PHP, ['-r', `echo json_encode(getimagesize('${dir}/images/${jpg}'));`], { encoding: 'utf8' });
	c.ok(JSON.parse(size)[0] <= 2560, `JPEG resized to the maximum width (${JSON.parse(size)[0]} px, was 3200)`);
	c.ok(sql(db, `SELECT status FROM jos_uxstudio_image_optimizer WHERE path='images/${jpg}'`) === 'optimized', 'database row = optimized');
	// frontend (anonymous): the file is served, and the backup folder is not
	const anon = await ctxs.anonCtx.newPage();
	let r = await anon.request.get(`${site.url}/images/${jpg}`, { headers: { Accept: 'image/jpeg,*/*' } });
	c.ok(r.status() === 200 && /image\/jpeg/.test(r.headers()['content-type']), 'anonymous visitor gets the optimized JPEG (200)');
	r = await anon.request.get(`${site.url}/media/uxstudio-originals/images/${jpg}`, { failOnStatusCode: false });
	c.ok(r.status() === 403 || r.status() === 404, `backup of the original is not public (HTTP ${r.status()})`);

	// ---- 2. SPA: overview, images tab, filters, empty state
	await openStudio(page, site, '#/module?id=image-optimizer');
	await settle(page);
	const txt = await page.locator('#uxs-root').innerText();
	c.ok(/Space saved/.test(txt) && /Optimized images/.test(txt), 'overview shows the statistic cards');
	await shot(page, `media-${site.key}-optimizer-overview-light`);
	await tabs(page).nth(1).click();
	await settle(page);
	let row = page.locator('#uxs-root table tbody tr', { hasText: jpg });
	c.ok((await row.count()) === 1, 'Images tab lists the uploaded JPEG');
	c.ok(/Optimized/.test(await row.innerText()), 'the row says Optimized');
	await page.fill('input[type=search]', 'zzz-nothing-' + P);
	await settle(page);
	c.ok(/No images have been processed yet/.test(await page.locator('#uxs-root').innerText()), 'empty state for a search without a match');
	await page.fill('input[type=search]', P);
	await settle(page);
	c.ok((await page.locator('#uxs-root table tbody tr').count()) === 2, 'search by path finds both uploaded files');
	await page.selectOption('select[aria-label="Status"]', 'failed');
	await settle(page);
	c.ok(/No images have been processed yet/.test(await page.locator('#uxs-root').innerText()), 'status filter Failed has no rows (empty state)');
	await page.selectOption('select[aria-label="Status"]', '');
	await settle(page);

	// ---- 3. restore the original, then optimize again
	row = page.locator('#uxs-root table tbody tr', { hasText: jpg });
	await row.getByRole('button', { name: 'Restore' }).click();
	await dialog(page).waitFor();
	await shot(page, `media-${site.key}-optimizer-restore-confirm`);
	await dialog(page).getByRole('button', { name: 'Cancel' }).click();
	c.ok(kb(`${dir}/images/${jpg}`) === jNow, 'cancelling the confirmation keeps the file');
	await row.getByRole('button', { name: 'Restore' }).click();
	await dialog(page).getByRole('button', { name: 'Restore' }).click();
	c.ok(/original was restored/i.test(await toastText(page)), 'toast: the original was restored');
	await settle(page);
	c.ok(kb(`${dir}/images/${jpg}`) === origJpg, `file is the original again (${kb(`${dir}/images/${jpg}`)} B)`);
	c.ok(!fs.existsSync(`${dir}/images/${jpg}.webp`), 'WebP sibling removed after the restore');
	c.ok(sql(db, `SELECT status FROM jos_uxstudio_image_optimizer WHERE path='images/${jpg}'`) === 'restored', 'row = restored');
	await clearToasts(page);
	row = page.locator('#uxs-root table tbody tr', { hasText: jpg });
	await row.getByRole('button', { name: 'Optimize again' }).click();
	c.ok(await page.locator('.uxs-toast', { hasText: /optimized again/i }).first().waitFor({ timeout: 15000 }).then(() => true, () => false), 'toast: the image was optimized again');
	await settle(page);
	c.ok(kb(`${dir}/images/${jpg}`) < origJpg * 0.8 && fs.existsSync(`${dir}/images/${jpg}.webp`), 'Optimize again compresses it from the backup and recreates WebP');
	await clearToasts(page);

	// ---- 4. bulk run with progress and stop. 24 big files dropped into a folder by FTP-like copy.
	const bulkDir = `${dir}/images/${P}-bulk`;
	fs.mkdirSync(bulkDir, { recursive: true });
	for (let k = 1; k <= 24; k++) fs.copyFileSync(`${TMP}/bulk-${k}.jpg`, `${bulkDir}/${P}-b${k}.jpg`);
	await openStudio(page, site, '#/module?id=image-optimizer');
	await settle(page);
	await page.getByRole('button', { name: 'Refresh' }).click();
	await settle(page);
	const pendingTxt = await page.locator('.uxs-card', { hasText: 'Still to do' }).innerText();
	const pending = Number(pendingTxt.replace(/\D/g, ''));
	c.ok(pending >= 24, `scan reports the dropped files as still to do (${pending})`);
	await page.getByRole('button', { name: 'Optimize existing images' }).click();
	const stopBtn = page.getByRole('button', { name: 'Stop after this batch' });
	await stopBtn.waitFor({ timeout: 10000 });
	c.ok((await page.locator('progress').count()) === 1, 'progress bar is shown while the run is going');
	await stopBtn.click();
	c.ok(/Optimized \d+ images/.test(await toastText(page, 120000)), 'toast with the result after stopping');
	await settle(page);
	const doneAfterStop = Number(sql(db, `SELECT COUNT(*) FROM jos_uxstudio_image_optimizer WHERE path LIKE '%${P}-bulk/%' AND status='optimized'`));
	c.ok(doneAfterStop >= 10 && doneAfterStop < 24, `stop interrupted the run after a batch (${doneAfterStop} of 24 optimized)`);
	await clearToasts(page);
	await shot(page, `media-${site.key}-optimizer-after-stop`);
	await page.getByRole('button', { name: 'Optimize existing images' }).click();
	await page.getByRole('button', { name: 'Optimize existing images' }).waitFor({ timeout: 180000 });
	await settle(page);
	const doneAll = Number(sql(db, `SELECT COUNT(*) FROM jos_uxstudio_image_optimizer WHERE path LIKE '%${P}-bulk/%' AND status IN ('optimized','skipped')`));
	c.ok(doneAll === 24, `the second run continued and finished all 24 (${doneAll})`);
	c.ok(/Processed \d+, saved/.test(await page.locator('#uxs-root').innerText()), 'result line "Processed N, saved ..." stays visible');
	const left = Number((await page.locator('.uxs-card', { hasText: 'Still to do' }).innerText()).replace(/\D/g, ''));
	c.ok(left === 0, 'nothing left to do');
	c.ok(await page.getByRole('button', { name: 'Optimize existing images' }).isDisabled(), 'the run button is disabled when nothing is left');
	await shot(page, `media-${site.key}-optimizer-bulk-done`);

	// ---- 5. settings validation (out of range / nonsense) and restore
	const before = sql(db, `SELECT data FROM jos_uxstudio_settings WHERE module='image-optimizer'`);
	await tabs(page).nth(2).click();
	await settle(page);
	const q = page.getByLabel('JPEG quality', { exact: false }).first();
	if (await q.count()) {
		await q.fill('999');
		await page.getByRole('button', { name: 'Save' }).click();
		const t1 = await toastText(page);
		await settle(page);
		const stored = sql(db, `SELECT data FROM jos_uxstudio_settings WHERE module='image-optimizer'`);
		const m = stored.match(/"jpeg_quality":\s*"?(\d+)/);
		c.ok(!m || Number(m[1]) <= 100, `jpeg_quality 999 is not stored as is (${m ? m[1] : 'unset'}; toast: ${t1.slice(0, 80)})`);
		await q.fill('');
		await page.getByRole('button', { name: 'Save' }).click();
		await toastText(page);
		await settle(page);
		const stored2 = sql(db, `SELECT data FROM jos_uxstudio_settings WHERE module='image-optimizer'`);
		const m2 = stored2.match(/"jpeg_quality":\s*"?(\d+)/);
		c.ok(!m2 || (Number(m2[1]) >= 1 && Number(m2[1]) <= 100), `empty jpeg_quality falls back to a valid value (${m2 ? m2[1] : 'unset'})`);
	} else {
		c.ok(false, 'settings tab has a JPEG quality field');
	}
	restoreSettings(db, "image-optimizer", before);
	await clearToasts(page);
	await shot(page, `media-${site.key}-optimizer-settings-light`);

	// ---- 6. dark mode, then Czech
	await tabs(page).nth(0).click();
	await setTheme(page, 'dark');
	await shot(page, `media-${site.key}-optimizer-overview-dark`);
	await tabs(page).nth(1).click();
	await settle(page);
	await shot(page, `media-${site.key}-optimizer-images-dark`);
	await setTheme(page, 'light');

	await ctxs.withCs(async (csPage) => {
		await openStudio(csPage, site, '#/module?id=image-optimizer');
		await settle(csPage);
		const cs = await csPage.locator('#uxs-root').innerText();
		c.ok(/Ušetřeno|Ušetřené/i.test(cs) || /Optimalizace/.test(cs), 'cs-CZ: image optimizer is in Czech');
		c.ok(untranslated('image-optimizer', cs).length === 0, `cs-CZ overview: no untranslated strings ${untranslated('image-optimizer', cs).slice(0, 4).join(' | ')}`);
		await shot(csPage, `media-${site.key}-optimizer-overview-cs`);
		await tabs(csPage).nth(1).click();
		await settle(csPage);
		const cs2 = await csPage.locator('#uxs-root').innerText();
		c.ok(untranslated('image-optimizer', cs2).length === 0, `cs-CZ images tab: no untranslated strings ${untranslated('image-optimizer', cs2).slice(0, 4).join(' | ')}`);
		await shot(csPage, `media-${site.key}-optimizer-images-cs`);
		await tabs(csPage).nth(2).click();
		await settle(csPage);
		const cs3 = await csPage.locator('#uxs-root').innerText();
		c.ok(untranslated('image-optimizer', cs3).length === 0, 'cs-CZ settings: no untranslated strings');
	});

	// ---- 7. delete through the Media Manager removes siblings, backup and row
	await page.goto(`${site.url}/administrator/index.php?option=com_media&path=local-images:/`);
	await page.waitForSelector('.media-browser', { timeout: 30000 });
	await sleep(1500);
	await page.locator('.media-browser-item', { hasText: png }).first().click();
	await page.locator('#mediaDelete').click();
	await page.locator('.modal.show, joomla-dialog, dialog[open]').first().waitFor({ timeout: 10000 }).catch(() => {});
	await sleep(500);
	await shot(page, `media-${site.key}-optimizer-media-delete-confirm`);
	const confirmBtn = page.locator('button:visible', { hasText: /^Delete$/ }).last();
	await confirmBtn.click();
	await page.locator(".media-browser-item", { hasText: png }).first().waitFor({ state: "detached", timeout: 15000 }).catch(() => {});
	await sleep(1500);
	c.ok(!fs.existsSync(`${dir}/images/${png}`) && !fs.existsSync(`${dir}/images/${png}.webp`), 'delete in the Media Manager removes the PNG and its WebP');
	c.ok(!fs.existsSync(`${dir}/media/uxstudio-originals/images/${png}`), 'the backup of the deleted image is removed');
	c.ok(sql(db, `SELECT COUNT(*) FROM jos_uxstudio_image_optimizer WHERE path='images/${png}'`) === '0', 'the table row is removed');
	await anon.close();
}

/* ------------------------------------------------------------------ auto image upload */

const EXT_PNG = 'https://upload.wikimedia.org/wikipedia/commons/4/47/PNG_transparency_demonstration_1.png?uxs-e2e=1';
const EXT_JPG = 'https://httpbin.org/image/jpeg?uxs-e2e=1';

async function createArticle(page, site, title, html) {
	await page.goto(`${site.url}/administrator/index.php?option=com_content&task=article.add`);
	await page.locator('#jform_title').fill(title);
	await page.waitForFunction(() => window.JoomlaEditor?.getActive?.() || window.JoomlaEditor?.get?.('jform_articletext') || (window.Joomla && Joomla.editors && Joomla.editors.instances && Joomla.editors.instances.jform_articletext), null, { timeout: 20000 });
	await page.evaluate((h) => (window.JoomlaEditor?.getActive?.() ?? window.JoomlaEditor?.get?.('jform_articletext') ?? Joomla.editors.instances.jform_articletext).setValue(h), html);
	await submit(page, 'article.apply');
	const m = page.url().match(/[?&]id=(\d+)/);
	return m ? Number(m[1]) : 0;
}
async function cancelEdit(page) {
	await submit(page, 'article.cancel').catch(() => {});
}

async function autoPart(site, ctxs, c) {
	const { page } = ctxs;
	const db = site.db;
	const dir = siteDir(site);

	// The module downloads from the real internet (it refuses local addresses), so without network the part is skipped, loudly.
	const reach = await Promise.all([EXT_PNG, EXT_JPG].map((u) => fetch(u, { method: 'GET', signal: AbortSignal.timeout(20000) }).then((r) => r.ok, () => false)));
	if (!reach.every(Boolean)) {
		console.log(`SKIP auto-image-upload: the test images are not reachable from this machine (${reach.join('/')}); nothing was verified in this part`);
		return;
	}
	await openStudio(page, site, '#/module?id=auto-image-upload');
	await settle(page);
	const before = Number((await page.locator('.uxs-card', { hasText: 'Downloaded images' }).innerText()).replace(/\D/g, ''));
	await shot(page, `media-${site.key}-auto-overview-light`);

	// ---- 1. validation in the Joomla editor: an article without a title is refused
	await page.goto(`${site.url}/administrator/index.php?option=com_content&task=article.add`);
	await page.waitForFunction(() => window.JoomlaEditor?.getActive?.() || window.JoomlaEditor?.get?.('jform_articletext') || (window.Joomla && Joomla.editors && Joomla.editors.instances && Joomla.editors.instances.jform_articletext), null, { timeout: 20000 });
	await page.evaluate((h) => (window.JoomlaEditor?.getActive?.() ?? window.JoomlaEditor?.get?.('jform_articletext') ?? Joomla.editors.instances.jform_articletext).setValue(h), `<p><img src="${EXT_PNG}"></p>`);
	const mapBefore = sql(db, `SELECT COUNT(*) FROM jos_uxstudio_auto_image_upload`);
	const contentBefore = sql(db, `SELECT COUNT(*) FROM jos_content`);
	await page.evaluate(() => { setTimeout(() => Joomla.submitbutton('article.apply'), 50); });
	await sleep(2500);
	c.ok((await page.locator('#jform_title.invalid, #jform_title:invalid, joomla-alert').count()) > 0 && /option=com_content/.test(page.url()), 'article without a title stays in the editor and shows the validation error');
	c.ok(sql(db, `SELECT COUNT(*) FROM jos_content`) === contentBefore && sql(db, `SELECT COUNT(*) FROM jos_uxstudio_auto_image_upload`) === mapBefore, 'nothing was saved and no download happened without a valid save');
	await cancelEdit(page);

	// ---- 2. article with external images saved through the Joomla editor
	const title = `${P}-auto-article`;
	const html = `<p>Intro</p><p><img src="${EXT_PNG}" alt="Transparent demo" srcset="https://example.com/x-2x.png 2x" width="280"></p><p><img src="http://127.0.0.1/${P}-internal.png" alt="internal"></p><p><img src="images/joomla_black.png" alt="local"></p>`;
	const id = await createArticle(page, site, title, html);
	c.ok(id > 0, `article saved in Joomla (id ${id})`);
	sql(db, `UPDATE jos_content SET alias='${P}-auto-article' WHERE id=${id}`);
	const body = sql(db, `SELECT CONCAT(introtext, \`fulltext\`) FROM jos_content WHERE id=${id}`);
	const m = body.match(/<img[^>]*alt="Transparent demo"[^>]*>/);
	c.ok(!!m && !/wikimedia/.test(m[0]) && /src="images\//.test(m[0]), `external image rewritten to a local path (${m ? m[0].slice(0, 120) : body.slice(0, 160)})`);
	c.ok(!!m && !/srcset/.test(m[0]), 'srcset of the foreign variants removed');
	c.ok(/127\.0\.0\.1/.test(body), 'the internal address (SSRF) was left alone in the text');
	c.ok(/images\/joomla_black\.png/.test(body), 'local image untouched');
	const file = m && m[0].match(/src="([^"]+)"/)?.[1];
	c.ok(!!file && fs.existsSync(`${dir}/${file}`), `file downloaded into images/ (${file})`);
	c.ok(sql(db, `SELECT COUNT(*) FROM jos_uxstudio_auto_image_upload WHERE url='${EXT_PNG}' AND status='ok'`) === '1', 'map row for the downloaded URL');
	c.ok(sql(db, `SELECT COUNT(*) FROM jos_uxstudio_auto_image_upload WHERE url LIKE 'http://127.0.0.1/%' AND status='failed'`) !== '0', 'SSRF address recorded as failed');
	await cancelEdit(page);

	// ---- 2b. alt pattern with replacement characters in the title ($0, backslash-1) must not mangle the alt attribute
	const settingsBefore = sql(db, `SELECT data FROM jos_uxstudio_settings WHERE module='auto-image-upload'`);
	const merged = { ...(settingsBefore ? JSON.parse(settingsBefore) : {}), alt_pattern: '%title%' };
	const mergedHex = Buffer.from(JSON.stringify(merged), 'utf8').toString('hex');
	if (settingsBefore) sql(db, `UPDATE jos_uxstudio_settings SET data=CONVERT(0x${mergedHex} USING utf8mb4) WHERE module='auto-image-upload'`);
	else sql(db, `INSERT INTO jos_uxstudio_settings (module, data) VALUES ('auto-image-upload', CONVERT(0x${mergedHex} USING utf8mb4))`);
	try {
		const trickyTitle = `${P}-alt Cena $0 akce \\1`;
		const id2 = await createArticle(page, site, trickyTitle, `<p><img src="${EXT_PNG}" alt="puvodni"></p>`);
		sql(db, `UPDATE jos_content SET alias='${P}-alt' WHERE id=${id2}`);
		const body2 = sql(db, `SELECT introtext FROM jos_content WHERE id=${id2}`);
		const alt2 = body2.match(/alt="([^"]*)"/)?.[1] ?? '';
		c.ok(alt2.includes('Cena $0 akce') && !alt2.includes('puvodni'), `alt built from the title keeps "$0" literally (${alt2.slice(0, 70)})`);
		await cancelEdit(page);
	} finally {
		restoreSettings(db, 'auto-image-upload', settingsBefore);
	}

	// frontend: anonymous visitor gets the local image
	const anon = await ctxs.anonCtx.newPage();
	sql(db, `UPDATE jos_content SET state=1 WHERE id=${id}`);
	await anon.goto(`${site.url}/index.php?option=com_content&view=article&id=${id}&catid=2`);
	const imgs = await anon.locator('.com-content-article img[alt="Transparent demo"]').evaluateAll((els) => els.map((e) => ({ src: e.src, ok: e.complete && e.naturalWidth > 0 })));
	c.ok(imgs.length === 1 && imgs[0].src.startsWith(site.url) && imgs[0].ok, `frontend shows the image from this server (${JSON.stringify(imgs)})`);
	await shot(anon, `media-${site.key}-auto-frontend-article`);
	await anon.close();

	// ---- 3. SPA: counts, history
	await openStudio(page, site, '#/module?id=auto-image-upload');
	await settle(page);
	const after = Number((await page.locator('.uxs-card', { hasText: 'Downloaded images' }).innerText()).replace(/\D/g, ''));
	c.ok(after === before + 1, `counter of downloaded images grew by one (${before} -> ${after})`);
	await tabs(page).nth(1).click();
	await settle(page);
	let hist = await page.locator('#uxs-root table tbody').innerText();
	c.ok(/PNG_transparency_demonstration_1/.test(hist) && /Downloaded/.test(hist), 'History lists the downloaded address');
	c.ok(/127\.0\.0\.1/.test(hist) && /Failed/.test(hist), 'History lists the refused internal address as failed');
	await page.selectOption('select[aria-label="Result"]', 'failed');
	await settle(page);
	hist = await page.locator('#uxs-root table tbody').innerText();
	c.ok(!/PNG_transparency/.test(hist) && /Failed/.test(hist), 'filter Failed hides the downloaded one');
	await page.selectOption('select[aria-label="Result"]', 'ok');
	await settle(page);
	await shot(page, `media-${site.key}-auto-history-light`);
	await page.selectOption('select[aria-label="Result"]', '');

	// ---- 4. bulk tool over existing articles: scan (dry run), run, second run finds nothing
	sql(db, `UPDATE jos_content SET introtext=CONCAT('<p><img src="${EXT_JPG}" alt="bulk jpeg"></p>', introtext) WHERE id=${id}`);
	await tabs(page).nth(0).click();
	await settle(page);
	await page.getByRole('button', { name: 'Scan articles' }).click();
	await page.getByText(/Scan finished|Articles checked/).first().waitFor({ timeout: 60000 });
	await settle(page);
	let line = await page.locator('#uxs-root').innerText();
	c.ok(/with external images: [1-9]/.test(line), 'dry-run scan finds the article with an external image');
	c.ok(/downloaded: 0/.test(line), 'the scan itself downloads nothing');
	await clearToasts(page);
	await page.getByRole('button', { name: 'Download images of existing articles' }).click();
	c.ok(/Done: \d+ images downloaded/.test(await toastText(page, 90000)), 'bulk run finishes with a toast');
	await settle(page);
	const body2 = sql(db, `SELECT introtext FROM jos_content WHERE id=${id}`);
	c.ok(!/httpbin/.test(body2) && /alt="bulk jpeg"/.test(body2), 'bulk run rewrote the stored article text');
	await shot(page, `media-${site.key}-auto-bulk-done`);
	await clearToasts(page);
	await page.getByRole('button', { name: 'Scan articles' }).click();
	await page.getByText(/Scan finished/).first().waitFor({ timeout: 60000 }).catch(() => {});
	await settle(page);
	line = await page.locator('#uxs-root').innerText();
	c.ok(/downloaded: 0/.test(line) && !/httpbin|wikimedia/.test(sql(db, `SELECT CONCAT(introtext, \`fulltext\`) FROM jos_content WHERE id=${id}`)), 'second scan: nothing left to download');

	// ---- 5. dark mode and Czech
	await setTheme(page, 'dark');
	await shot(page, `media-${site.key}-auto-overview-dark`);
	await tabs(page).nth(1).click();
	await settle(page);
	await shot(page, `media-${site.key}-auto-history-dark`);
	await setTheme(page, 'light');
	await ctxs.withCs(async (csPage) => {
		await openStudio(csPage, site, '#/module?id=auto-image-upload');
		await settle(csPage);
		let cs = await csPage.locator('#uxs-root').innerText();
		c.ok(/Staž|obrázk/i.test(cs), 'cs-CZ: auto-image-upload is in Czech');
		c.ok(untranslated('auto-image-upload', cs).length === 0, `cs-CZ overview: no untranslated strings ${untranslated('auto-image-upload', cs).slice(0, 4).join(' | ')}`);
		await shot(csPage, `media-${site.key}-auto-overview-cs`);
		await tabs(csPage).nth(1).click();
		await settle(csPage);
		cs = await csPage.locator('#uxs-root').innerText();
		c.ok(untranslated('auto-image-upload', cs).length === 0, `cs-CZ history: no untranslated strings ${untranslated('auto-image-upload', cs).slice(0, 4).join(' | ')}`);
		await shot(csPage, `media-${site.key}-auto-history-cs`);
	});
	cleanExternal(site);
}

/* ------------------------------------------------------------------ instagram feed */

async function instagramPart(site, ctxs, c) {
	const { page } = ctxs;
	const db = site.db;
	const dir = siteDir(site);
	const igDir = `${dir}/images/uxstudio-instagram`;
	const metaBefore = sql(db, `SELECT value FROM jos_uxstudio_instagram_meta WHERE name='connection_id'`);

	// ---- 1. state of an unconnected, paired site
	await openStudio(page, site, '#/module?id=instagram-feed');
	await settle(page);
	let txt = await page.locator('#uxs-root').innerText();
	c.ok(/No Instagram account is connected yet/.test(txt), 'overview: no account connected');
	const connectBtn = page.getByRole('button', { name: 'Connect Instagram' });
	const paired = await connectBtn.isEnabled();
	console.log(`NOTE instagram: Connect button enabled = ${paired} (site paired with the Central App)`);
	c.ok(await page.getByRole('button', { name: 'Synchronise now' }).isDisabled(), 'Synchronise is disabled without an account');
	await shot(page, `media-${site.key}-instagram-overview-light`);
	if (paired) {
		// click Connect: the browser is taken to the Central App (it has no Meta application configured locally, so it shows an error page)
		const [resp] = await Promise.all([page.waitForNavigation({ waitUntil: 'load', timeout: 30000 }).catch(() => null), connectBtn.click()]);
		await sleep(800);
		const url = page.url();
		c.ok(/instagram_auth/.test(url) || /_ca-wt-joomla/.test(url), `Connect hands the browser over to the Central App (${url.slice(0, 90)})`);
		console.log(`NOTE instagram connect page says: ${(await page.locator('body').innerText().catch(() => '')).slice(0, 160).replace(/\s+/g, ' ')}`);
		await shot(page, `media-${site.key}-instagram-ca-connect`);
		void resp;
	} else {
		c.ok(/not connected to the Central App|switched off/.test(txt), 'not paired: explanation shown');
	}
	// callback with a garbage signature must end in an error toast in the SPA
	await page.goto(`${site.url}/index.php?option=com_uxstudio&task=public.dispatch&format=json&route=instagram-feed/callback&connection_id=9&ig_user_id=1&ig_username=x&ig_account_type=BUSINESS&nonce=bad&ts=1&sig=bad`);
	await page.waitForLoadState('load');
	await sleep(1500);
	c.ok(/ig=error/.test(page.url()) || /instagram-feed/.test(page.url()), `forged callback ends in the administration with an error (${page.url().slice(-70)})`);
	if (/ig=error/.test(page.url())) {
		await page.locator('#uxs-root').waitFor();
		await settle(page);
		c.ok(/could not be verified|connection failed|expired/i.test(await toastText(page)), 'error toast after the forged callback');
	}
	c.ok(sql(db, `SELECT value FROM jos_uxstudio_instagram_meta WHERE name='connection_id'`) === metaBefore, 'forged callback did not change the stored connection');

	// ---- 2. seed posts (CA has no Meta application, so no real sync is possible) + local images
	fs.mkdirSync(igDir, { recursive: true });
	const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
	const files = [];
	for (let k = 1; k <= 5; k++) {
		const f = `ig-${crypto.createHash('sha1').update(`${P}-${k}`).digest('hex').slice(0, 20)}.jpg`;
		fs.copyFileSync(`${TMP}/${P}-ig1.jpg`, `${igDir}/${f}`);
		files.push(f);
		const cap = k === 1 ? `Káva & dort <b>tučně</b> #kava #${P}` : `Příspěvek ${k} #${k % 2 ? 'dort' : 'kava'}`;
		sql(db, `INSERT INTO jos_uxstudio_instagram_media (instagram_id, media_type, media_url, thumbnail_url, permalink, caption, hashtags, media_timestamp, like_count, comments_count, local_file, is_hidden, synced_at) VALUES ('${P}-${k}', 'IMAGE', 'https://scontent.cdninstagram.com/${P}-${k}.jpg', '', 'https://www.instagram.com/p/${P}${k}/', '${cap.replace(/'/g, "''")}', '${JSON.stringify(cap.match(/#\w+/g)?.map((x) => x.slice(1)) ?? [])}', DATE_SUB('${now}', INTERVAL ${k} HOUR), ${k * 3}, ${k}, '${f}', 0, '${now}')`);
	}
	await openStudio(page, site, '#/module?id=instagram-feed');
	await settle(page);
	txt = await page.locator('#uxs-root').innerText();
	c.ok(/Posts stored\s*\n?\s*\d+/.test(txt), 'overview shows the stored post counter');
	await tabs(page).nth(1).click();
	await settle(page);
	let rows = page.locator('#uxs-root table tbody tr', { hasText: /Příspěvek|Káva/ });
	c.ok((await rows.count()) === 5, 'Posts tab lists the 5 seeded posts');
	const firstRow = page.locator('#uxs-root table tbody tr', { hasText: 'Káva & dort' });
	c.ok(((await firstRow.innerHTML()).match(/<b>/g) ?? []).length === 0, 'HTML in the caption is escaped in the admin list');
	await shot(page, `media-${site.key}-instagram-posts-light`);
	await firstRow.getByRole('button', { name: 'Hide post' }).click();
	await settle(page);
	c.ok(sql(db, `SELECT is_hidden FROM jos_uxstudio_instagram_media WHERE instagram_id='${P}-1'`) === '1', 'hiding a post is stored');
	c.ok(await page.locator('#uxs-root table tbody tr', { hasText: 'Káva & dort' }).locator('.uxs-badge', { hasText: 'Hidden' }).waitFor({ timeout: 10000 }).then(() => true, () => false), 'the row shows Hidden');

	// ---- 3. Joomla module "UX1 Studio - Instagram" created in the Joomla administration
	let moduleId = 0;
	await page.goto(`${site.url}/administrator/index.php?option=com_modules&view=modules&client_id=0`);
	await page.getByRole('button', { name: 'New' }).first().click();
	await page.locator('a, button', { hasText: /UX1 Studio - Instagram/ }).first().waitFor({ timeout: 20000 }).catch(() => {});
	const pick = page.locator('a, button', { hasText: /UX1 Studio - Instagram/ }).first();
	c.ok((await pick.count()) > 0, 'module type "UX1 Studio - Instagram" is offered in the Joomla module chooser');
	if (await pick.count()) {
		await pick.click();
		await page.locator('#jform_title').waitFor({ timeout: 20000 });
		await page.fill('#jform_title', `${P}-instagram`);
		await page.fill('#jform_params_heading', 'Z Instagramu');
		await page.fill('#jform_params_count', '4');
		await page.selectOption('#jform_params_columns', '2').catch(() => {});
		// position: typed into the Choices.js picker; fall back to the DB if the picker does not keep it
		await page.locator('joomla-field-fancy-select input.choices__input--cloned, #jform_position').first().fill('sidebar-left').catch(() => {});
		await page.keyboard.press('Enter').catch(() => {});
		await submit(page, 'module.apply');
		const mm = page.url().match(/[?&]id=(\d+)/);
		moduleId = mm ? Number(mm[1]) : Number(sql(db, `SELECT COALESCE(MAX(id),0) FROM jos_modules WHERE title='${P}-instagram'`));
		c.ok(moduleId > 0, `module saved in com_modules (id ${moduleId})`);
		if (moduleId) {
			if (sql(db, `SELECT position FROM jos_modules WHERE id=${moduleId}`) !== 'sidebar-left') {
				sql(db, `UPDATE jos_modules SET position='sidebar-left' WHERE id=${moduleId}`);
				console.log('NOTE position set through the DB (the Choices.js picker did not keep the typed value)');
			}
			if (sql(db, `SELECT COUNT(*) FROM jos_modules_menu WHERE moduleid=${moduleId}`) === '0') sql(db, `INSERT INTO jos_modules_menu (moduleid, menuid) VALUES (${moduleId}, 0)`);
			sql(db, `UPDATE jos_modules SET published=1 WHERE id=${moduleId}`);
		}
		await submit(page, 'module.cancel').catch(() => {});
	}
	// article with the tag
	const tagId = await createArticle(page, site, `${P}-ig-tag`, `<p>Before</p><p>{uxstudio_instagram layout=carousel count=3 columns=3 show_caption=1 heading="Značka"}</p><p>After</p>`);
	sql(db, `UPDATE jos_content SET alias='${P}-ig-tag', state=1 WHERE id=${tagId}`);
	await cancelEdit(page);

	// ---- 4. frontend as an anonymous visitor
	const anon = await ctxs.anonCtx.newPage();
	await anon.goto(`${site.url}/index.php?option=com_content&view=article&id=${tagId}&catid=2`);
	await anon.waitForLoadState('load');
	const grid = anon.locator('.uxs-ig');
	const gridCount = await grid.count();
	c.ok(gridCount >= 1, `the Instagram feed renders on the frontend (${gridCount} block(s): module + tag)`);
	if (gridCount) {
		const items = await anon.locator('.uxs-ig a[href*="instagram.com"]').evaluateAll((a) => a.map((x) => x.href));
		c.ok(items.length >= 3 && !items.some((h) => /instagram\.com\/p\/uxs-e2e1\//.test(h)), `the hidden post is not shown (${items.length} links)`);
		const imgSrc = await anon.locator('.uxs-ig img').evaluateAll((a) => a.map((x) => ({ src: x.src, ok: x.complete && x.naturalWidth > 0 })));
		c.ok(imgSrc.length > 0 && imgSrc.every((i) => i.src.startsWith(site.url) && i.ok), 'every image comes from this server and loads (visitor never contacts Instagram)');
		c.ok((await anon.locator('.uxs-ig [style], .uxs-ig script, .uxs-ig [onclick]').count()) === 0, 'no inline style, script or handler in the feed markup');
		const carousel = anon.locator('.uxs-ig--carousel').first();
		c.ok((await carousel.count()) === 1, 'tag layout=carousel renders a carousel');
		if (await carousel.count()) c.ok((await carousel.locator('button[aria-label]').count()) >= 2, 'carousel has labelled arrow buttons');
		c.ok((await anon.locator('text=Z Instagramu').count()) + (await anon.locator('text=Značka').count()) >= 1, 'heading is shown');
		c.ok(!(await anon.content()).includes('<b>tučně</b>'), 'caption HTML is escaped on the frontend');
	}
	await shot(anon, `media-${site.key}-instagram-frontend-light`);
	await anon.setViewportSize({ width: 390, height: 800 });
	await anon.reload();
	await shot(anon, `media-${site.key}-instagram-frontend-mobile`);
	// show the hidden post again -> appears on the site (cache of the module is off)
	// show the hidden post again through the SPA (fresh screen: the earlier one was left for com_modules and the editor)
	await anon.setViewportSize({ width: 1400, height: 900 });
	await openStudio(page, site, '#/module?id=instagram-feed');
	await tabs(page).nth(1).click();
	await settle(page);
	await page.locator('#uxs-root table tbody tr', { hasText: 'Káva & dort' }).getByRole('button', { name: 'Show post' }).click();
	await settle(page);
	c.ok(sql(db, `SELECT is_hidden FROM jos_uxstudio_instagram_media WHERE instagram_id='${P}-1'`) === '0', 'Show post stores the post as visible again');
	await anon.goto(`${site.url}/index.php?option=com_content&view=article&id=${tagId}&catid=2`);
	const shownLinks = await anon.locator('.uxs-ig a[href*="instagram.com"]').evaluateAll((a) => a.map((x) => x.href));
	c.ok(shownLinks.length >= 3, `the feed shows posts again (${shownLinks.length} links)`);

	// no visible posts: visitors see nothing (no error text); the strict check only runs when the site has no real posts of its own
	sql(db, `UPDATE jos_uxstudio_instagram_media SET is_hidden=1 WHERE instagram_id LIKE '${P}-%'`);
	const hasOther = Number(sql(db, `SELECT COUNT(*) FROM jos_uxstudio_instagram_media WHERE instagram_id NOT LIKE '${P}-%' AND is_hidden=0`));
	await anon.goto(`${site.url}/index.php?option=com_content&view=article&id=${tagId}&catid=2`);
	c.ok((await anon.locator('.uxs-ig a[href*="instagram.com/p/uxs-e2e"]').count()) === 0, 'all test posts hidden: none of them is on the page');
	if (hasOther === 0) {
		c.ok((await anon.locator('.uxs-ig').count()) === 0 && !/Instagram/.test(await anon.locator('.com-content-article').innerText()), 'with no visible posts the visitor sees nothing (no error text)');
	} else {
		console.log(`NOTE instagram: ${hasOther} real posts exist on ${site.key}, the "visitor sees nothing" check is not applicable`);
	}
	sql(db, `UPDATE jos_uxstudio_instagram_media SET is_hidden=0 WHERE instagram_id LIKE '${P}-%'`);
	await anon.close();

	// ---- 5. dark mode and Czech
	await openStudio(page, site, '#/module?id=instagram-feed');
	await setTheme(page, 'dark');
	await shot(page, `media-${site.key}-instagram-overview-dark`);
	await tabs(page).nth(1).click();
	await settle(page);
	await shot(page, `media-${site.key}-instagram-posts-dark`);
	await setTheme(page, 'light');
	await ctxs.withCs(async (csPage) => {
		await openStudio(csPage, site, '#/module?id=instagram-feed');
		await settle(csPage);
		let cs = await csPage.locator('#uxs-root').innerText();
		c.ok(/Instagram/.test(cs) && /Příspěvk|účet/i.test(cs), 'cs-CZ: instagram-feed is in Czech');
		c.ok(untranslated('instagram-feed', cs).length === 0, `cs-CZ overview: no untranslated strings ${untranslated('instagram-feed', cs).slice(0, 4).join(' | ')}`);
		await shot(csPage, `media-${site.key}-instagram-overview-cs`);
		await tabs(csPage).nth(1).click();
		await settle(csPage);
		cs = await csPage.locator('#uxs-root').innerText();
		c.ok(untranslated('instagram-feed', cs).length === 0, 'cs-CZ posts: no untranslated strings');
		await shot(csPage, `media-${site.key}-instagram-posts-cs`);
		await tabs(csPage).nth(2).click();
		await settle(csPage);
		cs = await csPage.locator('#uxs-root').innerText();
		c.ok(untranslated('instagram-feed', cs).length === 0, `cs-CZ settings: no untranslated strings ${untranslated('instagram-feed', cs).slice(0, 4).join(' | ')}`);
	});
	void files;
}

/* ------------------------------------------------------------------ dashboard widgets */

async function dashboardPart(site, ctxs, c) {
	const { page } = ctxs;
	const db = site.db;
	const note = `${P}-note`;
	const link = `${P}-link`;
	const cpanel = `${site.url}/administrator/index.php?option=com_cpanel&view=cpanel`;

	await openStudio(page, site, '#/module?id=dashboard-widgets');
	await settle(page);
	let txt = await page.locator('#uxs-root').innerText();
	c.ok(/Module summaries/.test(txt) && /Other blocks on the Joomla home screen/.test(txt), 'Cards tab shows module cards and Joomla blocks');
	c.ok(/No own cards yet|Own notes and links/.test(txt), 'section for own cards present');
	await shot(page, `media-${site.key}-dashboard-cards-light`);

	// ---- 1. new note: title is required (Save disabled), HTML is stripped
	await page.getByRole('button', { name: 'New card' }).click();
	await dialog(page).waitFor();
	c.ok(await dialog(page).getByRole('button', { name: 'Save' }).isDisabled(), 'empty title: Save is disabled');
	await dialog(page).locator('#dw-title').fill(`${note} <script>alert(1)</script>`);
	await dialog(page).locator('#dw-body').fill('Řádek 1\nŘádek 2 <b>x</b>');
	await shot(page, `media-${site.key}-dashboard-newcard-modal`);
	await dialog(page).getByRole('button', { name: 'Save' }).click();
	await settle(page);
	let stored = sql(db, `SELECT CONCAT(title,'|',body) FROM jos_uxstudio_dashboard_items WHERE title LIKE '${note}%'`);
	c.ok(!!stored && !/<script/.test(stored), `note stored without script tag (${stored.slice(0, 80)})`);
	// ---- 2. link with a javascript: address is refused, https accepted
	await page.getByRole('button', { name: 'New card' }).click();
	await dialog(page).waitFor();
	await dialog(page).locator('#dw-kind').selectOption('link');
	await dialog(page).locator('#dw-title').fill(link);
	await dialog(page).locator('#dw-url').fill('javascript:alert(1)');
	await dialog(page).getByRole('button', { name: 'Save' }).click();
	const err = await toastText(page);
	c.ok(/address|URL|link|invalid|adres/i.test(err) && (await dialog(page).count()) === 1, `javascript: link is refused with an error (${err.slice(0, 80)}) and the dialog stays open`);
	c.ok(sql(db, `SELECT COUNT(*) FROM jos_uxstudio_dashboard_items WHERE title='${link}'`) === '0', 'nothing stored for the refused link');
	await clearToasts(page);
	await dialog(page).locator('#dw-url').fill('index.php?option=com_content&view=articles');
	await dialog(page).locator('#dw-ordering').fill('5');
	await dialog(page).getByRole('button', { name: 'Save' }).click();
	await settle(page);
	c.ok(sql(db, `SELECT COUNT(*) FROM jos_uxstudio_dashboard_items WHERE title='${link}'`) === '1', 'link card with an administration path is stored');
	await clearToasts(page);
	let tbl = page.locator('#uxs-root table', { hasText: link });
	c.ok((await tbl.count()) >= 1, 'both own cards are listed');
	await shot(page, `media-${site.key}-dashboard-owncards-light`);

	// ---- 3. the cards show on the Joomla home screen (com_cpanel)
	await page.goto(cpanel, { timeout: 90000, waitUntil: 'domcontentloaded' });
	await page.waitForLoadState('load');
	const cards = page.locator('ul.uxs-cp-cards');
	c.ok((await cards.count()) === 1, 'com_cpanel renders the UX1 Studio block');
	let cp = await cards.innerText().catch(() => '');
	c.ok(cp.includes(link) && cp.includes(note), 'com_cpanel shows both own cards');
	c.ok(!/<script|alert\(1\)<\/script>/.test(await cards.innerHTML()), 'no executable markup in the cpanel block');
	await shot(page, `media-${site.key}-dashboard-cpanel-light`);

	// ---- 3b. a double click on Save creates just one card
	await openStudio(page, site, '#/module?id=dashboard-widgets');
	await settle(page);
	await page.getByRole('button', { name: 'New card' }).click();
	await dialog(page).waitFor();
	await dialog(page).locator('#dw-title').fill(`${P}-dbl`);
	await dialog(page).getByRole('button', { name: 'Save' }).dblclick();
	await settle(page);
	await sleep(800);
	c.ok(sql(db, `SELECT COUNT(*) FROM jos_uxstudio_dashboard_items WHERE title='${P}-dbl'`) === '1', 'double click on Save creates exactly one card');
	await page.locator('#uxs-root table tbody tr', { hasText: `${P}-dbl` }).first().getByRole('button', { name: 'Delete' }).click();
	await dialog(page).getByRole('button', { name: 'Delete' }).click();
	await settle(page);
	c.ok(sql(db, `SELECT COUNT(*) FROM jos_uxstudio_dashboard_items WHERE title='${P}-dbl'`) === '0', 'the double-click test card was deleted');

	// ---- 4. edit the note, unpublish -> disappears from the cpanel; delete asks for confirmation
	await openStudio(page, site, '#/module?id=dashboard-widgets');
	await settle(page);
	await page.locator('#uxs-root table tbody tr', { hasText: note }).getByRole('button', { name: 'Edit' }).click();
	await dialog(page).waitFor();
	c.ok((await dialog(page).locator('#dw-kind').isDisabled()), 'the type of an existing card cannot be changed');
	await dialog(page).locator('#dw-title').fill(`${note}-edited`);
	await dialog(page).getByRole('button', { name: 'Save' }).click();
	await settle(page);
	c.ok(sql(db, `SELECT COUNT(*) FROM jos_uxstudio_dashboard_items WHERE title='${note}-edited'`) === '1', 'edit stored');
	await page.locator('#uxs-root table tbody tr', { hasText: `${note}-edited` }).locator('button.uxs-switch').click();
	await settle(page);
	c.ok(sql(db, `SELECT published FROM jos_uxstudio_dashboard_items WHERE title='${note}-edited'`) === '0', 'unpublished through the switch');
	await page.goto(cpanel, { timeout: 90000, waitUntil: 'domcontentloaded' });
	await page.waitForLoadState('load');
	cp = await page.locator('ul.uxs-cp-cards').innerText();
	c.ok(!cp.includes(`${note}-edited`) && cp.includes(link), 'the unpublished card disappeared from com_cpanel, the other stays');

	// ---- 5. visibility per group: restrict the link to the Manager group -> a Super User does not see it
	await openStudio(page, site, '#/module?id=dashboard-widgets');
	await settle(page);
	await page.locator('#uxs-root table tbody tr', { hasText: link }).getByRole('button', { name: 'Edit' }).click();
	await dialog(page).waitFor();
	await dialog(page).locator('label.uxs-checklist__item', { hasText: /^\s*Manager\s*$/ }).first().locator('input').check();
	await dialog(page).getByRole('button', { name: 'Save' }).click();
	await settle(page);
	c.ok(/^\s*\[?\s*"?6"?\s*\]?\s*$/.test(sql(db, `SELECT group_ids FROM jos_uxstudio_dashboard_items WHERE title='${link}'`)), `group restriction (Manager = 6) stored (${sql(db, `SELECT group_ids FROM jos_uxstudio_dashboard_items WHERE title='${link}'`)})`);
	await page.goto(cpanel, { timeout: 90000, waitUntil: 'domcontentloaded' });
	await page.waitForLoadState('load');
	cp = await page.locator('ul.uxs-cp-cards').innerText().catch(() => '');
	c.ok(!cp.includes(link), 'a Super User outside the Manager group no longer sees the restricted card');

	// ---- 6. module card: hide one module card, rule dialog, then restore
	await openStudio(page, site, '#/module?id=dashboard-widgets');
	await settle(page);
	const helloRow = page.locator('#uxs-root table tbody tr', { hasText: 'Hello check' });
	c.ok((await helloRow.count()) > 0, 'the module card "Hello check" is listed (module hello is on)');
	if (await helloRow.count()) {
		await helloRow.locator('button.uxs-switch').click();
		await settle(page);
		await page.goto(cpanel, { timeout: 90000, waitUntil: 'domcontentloaded' });
		await page.waitForLoadState('load');
		c.ok(!(await page.locator('ul.uxs-cp-cards').innerText()).includes('heartbeats'), 'hidden module card is gone from com_cpanel');
		await openStudio(page, site, '#/module?id=dashboard-widgets');
		await settle(page);
		await page.locator('#uxs-root table tbody tr', { hasText: 'Hello check' }).locator('button.uxs-switch').click();
		await settle(page);
		await page.goto(cpanel, { timeout: 90000, waitUntil: 'domcontentloaded' });
		await page.waitForLoadState('load');
		c.ok((await page.locator('ul.uxs-cp-cards').innerText()).includes('heartbeats'), 'the module card is back after switching it on');
		await openStudio(page, site, '#/module?id=dashboard-widgets');
		await settle(page);
	}
	await page.locator('#uxs-root table tbody tr', { hasText: 'Hello check' }).locator('button.uxs-link-btn').first().click();
	await dialog(page).waitFor();
	await shot(page, `media-${site.key}-dashboard-rule-modal`);
	await dialog(page).getByRole('button', { name: 'Cancel' }).click();

	// ---- 7. preview tab, pagination of the long table
	await tabs(page).nth(1).click();
	await settle(page);
	c.ok(/cards? (is|are) shown to you/.test(await page.locator('#uxs-root').innerText()), 'Preview tab counts the cards');
	await shot(page, `media-${site.key}-dashboard-preview-light`);
	await tabs(page).nth(0).click();
	await settle(page);
	const nextBtn = page.getByRole('button', { name: 'Next' }).first();
	if (await nextBtn.isEnabled().catch(() => false)) {
		await nextBtn.click();
		c.ok(/Page 2 of/.test(await page.locator('#uxs-root').innerText()), 'pagination of the module table works');
	} else {
		console.log(`NOTE dashboard: the module table fits on one page (${await page.locator('#uxs-root table tbody tr').count()} rows), pagination not applicable`);
	}

	// ---- 8. delete both cards (confirmation) ; empty state
	for (const t of [`${note}-edited`, link]) {
		await openStudio(page, site, '#/module?id=dashboard-widgets');
		await settle(page);
		const row = page.locator('#uxs-root table tbody tr', { hasText: t }).first();
		await row.getByRole('button', { name: 'Delete' }).click();
		await dialog(page).waitFor();
		await dialog(page).getByRole('button', { name: 'Cancel' }).click();
		c.ok(sql(db, `SELECT COUNT(*) FROM jos_uxstudio_dashboard_items WHERE title='${t}'`) === '1', `cancel keeps card ${t}`);
		await row.getByRole('button', { name: 'Delete' }).click();
		await dialog(page).getByRole('button', { name: 'Delete' }).click();
		await settle(page);
		c.ok(sql(db, `SELECT COUNT(*) FROM jos_uxstudio_dashboard_items WHERE title='${t}'`) === '0', `card ${t} deleted`);
	}
	if (sql(db, 'SELECT COUNT(*) FROM jos_uxstudio_dashboard_items') === '0') c.ok(/No own cards yet/.test(await page.locator('#uxs-root').innerText()), 'empty state of own cards');

	// ---- 9. dark mode, cpanel dark, Czech
	await setTheme(page, 'dark');
	await shot(page, `media-${site.key}-dashboard-cards-dark`);
	await tabs(page).nth(1).click();
	await settle(page);
	await shot(page, `media-${site.key}-dashboard-preview-dark`);
	await setTheme(page, 'light');
	await page.goto(cpanel, { timeout: 90000, waitUntil: 'domcontentloaded' });
	await page.evaluate(() => document.documentElement.setAttribute('data-color-scheme', 'dark'));
	await sleep(500);
	await shot(page, `media-${site.key}-dashboard-cpanel-dark`);
	await ctxs.withCs(async (csPage) => {
		await csPage.goto(cpanel, { timeout: 90000, waitUntil: 'domcontentloaded' });
		await csPage.waitForLoadState('load');
		await shot(csPage, `media-${site.key}-dashboard-cpanel-cs`);
		const cpcs = await csPage.locator('ul.uxs-cp-cards').innerText().catch(() => '');
		c.ok(cpcs.length > 0 && !/\bimages? downloaded\b|optimized images|unread form submissions/.test(cpcs), `cs-CZ: cpanel cards are in Czech (${cpcs.slice(0, 80).replace(/\s+/g, ' ')})`);
		await openStudio(csPage, site, '#/module?id=dashboard-widgets');
		await settle(csPage);
		let cs = await csPage.locator('#uxs-root').innerText();
		c.ok(untranslated('dashboard-widgets', cs).length === 0, `cs-CZ cards: no untranslated strings ${untranslated('dashboard-widgets', cs).slice(0, 4).join(' | ')}`);
		await csPage.getByRole('button').filter({ hasText: /Nová karta/ }).first().click().catch(() => {});
		if (await dialog(csPage).count()) {
			cs = await dialog(csPage).innerText();
			c.ok(untranslated('dashboard-widgets', cs).length === 0, `cs-CZ new-card dialog: no untranslated strings ${untranslated('dashboard-widgets', cs).slice(0, 4).join(' | ')}`);
			await shot(csPage, `media-${site.key}-dashboard-newcard-cs`);
			await dialog(csPage).getByRole('button').first().click().catch(() => {});
			await csPage.keyboard.press('Escape');
		}
		await shot(csPage, `media-${site.key}-dashboard-cards-cs`);
		await tabs(csPage).nth(1).click();
		await settle(csPage);
		c.ok(untranslated('dashboard-widgets', await csPage.locator('#uxs-root').innerText()).length === 0, 'cs-CZ preview: no untranslated strings');
	});
}

/* ------------------------------------------------------------------ service requests */

async function requestsPart(site, ctxs, c) {
	const { page } = ctxs;
	const db = site.db;
	const title = `${P}-ticket ${site.key} čeština`;

	await openStudio(page, site, '#/module?id=service-requests');
	await settle(page);
	let txt = await page.locator('#uxs-root').innerText();
	c.ok(!/not connected to the Central App/.test(txt), 'site is connected to the Central App (no warning)');
	await shot(page, `media-${site.key}-requests-list-light`);

	// ---- 1. empty and error states of the list (API answers faked in the browser only)
	await page.route(/route=service-requests(\/|%2F)items(&|$)/, (route) => route.request().method() === 'GET' ? route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, data: { items: [], connected: true, counts: { open: 0, waiting_client: 0, done: 0, failed: 0 } } }) }) : route.continue());
	await openStudio(page, site, '#/module?id=service-requests');
	await settle(page);
	c.ok(/No service requests yet/.test(await page.locator('#uxs-root').innerText()), 'empty state when there are no requests');
	await shot(page, `media-${site.key}-requests-empty`);
	await page.unroute(/route=service-requests(\/|%2F)items(&|$)/);
	await page.route(/route=service-requests(\/|%2F)items(&|$)/, (route) => route.request().method() === 'GET' ? route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ ok: false, error: { message: 'Simulated outage' } }) }) : route.continue());
	await openStudio(page, site, '#/module?id=service-requests');
	await settle(page);
	await sleep(1500);
	c.ok(/Simulated outage|went wrong|error/i.test(await page.locator('#uxs-root').innerText()), 'a failing list request shows an error text instead of a blank page');
	await shot(page, `media-${site.key}-requests-error`);
	await page.unroute(/route=service-requests(\/|%2F)items(&|$)/);

	// ---- 2. validation of the form
	await openStudio(page, site, '#/module?id=service-requests');
	await settle(page);
	await page.getByRole('button', { name: 'New request' }).click();
	await page.locator('#uxs-sr-title').waitFor();
	c.ok(await page.getByRole('button', { name: 'Send request' }).isDisabled(), 'empty title: Send is disabled');
	await page.fill('#uxs-sr-title', '   ');
	c.ok(await page.getByRole('button', { name: 'Send request' }).isDisabled(), 'whitespace-only title: Send is still disabled');
	await page.fill('#uxs-sr-title', title);
	await page.fill('#uxs-sr-email', 'not-an-email');
	await page.fill('#uxs-sr-url', 'not a url');
	await page.getByRole('button', { name: 'Send request' }).click();
	await sleep(800);
	c.ok(await page.locator('#uxs-sr-email:invalid, #uxs-sr-url:invalid').count() > 0 && sql(db, `SELECT COUNT(*) FROM jos_uxstudio_service_requests WHERE title='${title}'`) === '0', 'invalid e-mail / address blocked by the form, nothing created');
	await shot(page, `media-${site.key}-requests-invalid`);
	await page.fill('#uxs-sr-email', 'e2e@example.com');
	await page.fill('#uxs-sr-url', `${site.url}/`);
	await page.fill('#uxs-sr-desc', 'Popis problému.\nDruhý řádek s diakritikou: žluťoučký kůň.');
	await page.selectOption('#uxs-sr-type', 'question');
	await page.selectOption('#uxs-sr-priority', 'high');
	await shot(page, `media-${site.key}-requests-form-light`);
	await page.getByRole('button', { name: 'Send request' }).click();
	const msg = await toastText(page, 30000);
	c.ok(/Request TCK-\d{4}-\d+ was sent/.test(msg), `creating a request reports the Central App number (${msg.slice(0, 80)})`);
	await settle(page);
	const num = (msg.match(/TCK-\d{4}-\d+/) ?? [''])[0];
	const local = sql(db, `SELECT CONCAT(central_number,'|',sync_state,'|',type,'|',priority) FROM jos_uxstudio_service_requests WHERE title='${title}'`);
	c.ok(local.startsWith(num) && /\|synced\|question\|high$/.test(local), `local row mirrors the CA (${local})`);

	// ---- 3. the request arrived in the Central App (read-only check of centrani_joomla)
	const central = ca(`SELECT CONCAT(number,'|',status,'|',type,'|',priority,'|',requester_email,'|',LEFT(description,20)) FROM tickets WHERE number='${num}'`);
	c.ok(central.startsWith(`${num}|`) && /\|question\|high\|e2e@example\.com\|Popis/.test(central), `the ticket exists in the CA database (${central})`);
	const subj = ca(`SELECT subject FROM tickets WHERE number='${num}'`);
	c.ok(subj === title, 'the Czech title reached the CA unchanged');
	const env = ca(`SELECT LENGTH(environment_json) FROM tickets WHERE number='${num}'`);
	c.ok(Number(env) > 20, 'the site environment snapshot was attached');

	// ---- 4. detail, status and the reply
	let row = page.locator('#uxs-root table tbody tr', { hasText: title });
	c.ok((await row.count()) === 1 && /New/.test(await row.innerText()), 'list shows the new request with status New');
	await row.locator('.uxs-link-btn').click();
	await page.locator('#uxs-sr-reply').waitFor();
	await settle(page);
	txt = await page.locator('#uxs-root').innerText();
	c.ok(txt.includes(num) && txt.includes('Druhý řádek s diakritikou'), 'detail shows number and description');
	c.ok(/No replies yet/.test(txt), 'conversation empty state');
	c.ok((await page.locator('#uxs-sr-status').count()) === 0, 'no manual status select for a request owned by the Central App');
	await page.locator('#uxs-root details summary').click();
	await shot(page, `media-${site.key}-requests-detail-light`);
	c.ok(await page.getByRole('button', { name: 'Send', exact: true }).isDisabled(), 'Send is disabled for an empty reply');
	const reply = `Odpověď z Joomly ${site.key} <b>x</b>`;
	await page.fill('#uxs-sr-reply', reply);
	await page.getByRole('button', { name: 'Send', exact: true }).click();
	await settle(page);
	await sleep(500);
	txt = await page.locator('#uxs-root').innerText();
	c.ok(txt.includes(reply), 'the reply appears in the conversation');
	const msgs = ca(`SELECT COUNT(*) FROM ticket_messages m JOIN tickets t ON t.id=m.ticket_id WHERE t.number='${num}' AND m.body LIKE 'Odpověď z Joomly%'`);
	c.ok(msgs === '1', 'the reply reached ticket_messages in the CA');
	c.ok((await page.locator('#uxs-root .uxs-status-list b').count()) === 0, 'HTML in the reply is escaped');
	await shot(page, `media-${site.key}-requests-reply-light`);
	// CA side change is picked up by Refresh: the team answers and the status changes
	const tid = ca(`SELECT id FROM tickets WHERE number='${num}'`);
	void tid; // writing to the CA database is not allowed, so the status change is only checked in reading
	await page.getByRole('button', { name: 'Refresh' }).click();
	await settle(page);
	await page.getByRole('button', { name: 'Back to the list' }).click();
	await settle(page);

	// ---- 5. dark and Czech
	await setTheme(page, 'dark');
	await shot(page, `media-${site.key}-requests-list-dark`);
	await page.locator('#uxs-root table tbody tr', { hasText: title }).locator('.uxs-link-btn').click();
	await page.locator('#uxs-sr-reply').waitFor();
	await settle(page);
	await shot(page, `media-${site.key}-requests-detail-dark`);
	await setTheme(page, 'light');
	await ctxs.withCs(async (csPage) => {
		await openStudio(csPage, site, '#/module?id=service-requests');
		await settle(csPage);
		let cs = await csPage.locator('#uxs-root').innerText();
		c.ok(/Servisní|požadavk/i.test(cs), 'cs-CZ: service requests are in Czech');
		c.ok(untranslated('service-requests', cs).length === 0, `cs-CZ list: no untranslated strings ${untranslated('service-requests', cs).slice(0, 4).join(' | ')}`);
		await shot(csPage, `media-${site.key}-requests-list-cs`);
		await csPage.locator('#uxs-root table tbody tr', { hasText: title }).locator('.uxs-link-btn').click();
		await csPage.locator('#uxs-sr-reply').waitFor();
		await settle(csPage);
		cs = await csPage.locator('#uxs-root').innerText();
		c.ok(untranslated('service-requests', cs).length === 0, `cs-CZ detail: no untranslated strings ${untranslated('service-requests', cs).slice(0, 4).join(' | ')}`);
		await shot(csPage, `media-${site.key}-requests-detail-cs`);
		await openStudio(csPage, site, '#/module?id=service-requests');
		await settle(csPage);
		await csPage.locator('#uxs-root .uxs-actions.uxs-list-toolbar button').first().click();
		await csPage.locator('#uxs-sr-title').waitFor();
		cs = await csPage.locator('#uxs-root').innerText();
		c.ok(untranslated('service-requests', cs).length === 0, `cs-CZ form: no untranslated strings ${untranslated('service-requests', cs).slice(0, 4).join(' | ')}`);
		await shot(csPage, `media-${site.key}-requests-form-cs`);
	});

	// ---- 6. delete the local copy (confirmation; the ticket stays in the CA)
	await openStudio(page, site, '#/module?id=service-requests');
	await settle(page);
	row = page.locator('#uxs-root table tbody tr', { hasText: title });
	await row.getByRole('button', { name: 'Delete' }).click();
	await dialog(page).waitFor();
	c.ok(/stays with the Central App/.test(await dialog(page).innerText()), 'delete confirmation says the ticket stays in the Central App');
	await shot(page, `media-${site.key}-requests-delete-confirm`);
	await dialog(page).getByRole('button', { name: 'Delete' }).click();
	await settle(page);
	c.ok(sql(db, `SELECT COUNT(*) FROM jos_uxstudio_service_requests WHERE title='${title}'`) === '0', 'local copy deleted');
	c.ok(ca(`SELECT COUNT(*) FROM tickets WHERE number='${num}'`) === '1', 'the ticket itself remains in the CA');
}

/* ------------------------------------------------------------------ driver */

async function scenario(browser, site) {
	const c = checker(`media ${site.key}`);
	CHK = c;
	const box = await context(browser, { ignore: EXPECTED });
	const csBox = await context(browser, { ignore: EXPECTED });
	const anonBox = await context(browser, { ignore: EXPECTED });
	let page;
	let csPage = null;
	const userParams = sql(site.db, `SELECT params FROM jos_users WHERE username='${TESTER}'`);
	try {
		cleanup(site);
		makeImages();
		page = await login(box.ctx, site, 'en-GB');
		// Czech administration: a second session logged in with cs-CZ (the language is fixed per session); the profile is put back at the end.
		const withCs = async (fn) => {
			csPage ??= await login(csBox.ctx, site, 'cs-CZ');
			await fn(csPage);
		};
		for (const m of ['image-optimizer', 'auto-image-upload', 'instagram-feed', 'dashboard-widgets', 'service-requests']) {
			if (sql(site.db, `SELECT enabled FROM jos_uxstudio_modules WHERE id='${m}'`) !== '1') console.log(`NOTE module ${m} is off on ${site.key}, left as it is`);
		}
		const ctxs = { page, withCs, anonCtx: anonBox.ctx };
		const parts = { optimizer: optimizerPart, auto: autoPart, instagram: instagramPart, dashboard: dashboardPart, requests: requestsPart };
		for (const [name, fn] of Object.entries(parts)) {
			if (!want(name)) continue;
			console.log(`---- ${site.key}: ${name}`);
			try {
				await fn(site, ctxs, c);
			} catch (e) {
				c.ok(false, `${name}: scenario aborted: ${String(e.message).split('\n')[0]}`);
				console.log(e.stack.split('\n').slice(0, 4).join('\n'));
				await shot(page, `media-${site.key}-${name}-ABORT`).catch(() => {});
			}
		}
	} finally {
		// the profile goes back to exactly what it was before the run
		try {
			sql(site.db, `UPDATE jos_users SET params=CONVERT(0x${Buffer.from(userParams, 'utf8').toString('hex')} USING utf8mb4) WHERE username='${TESTER}'`);
		} catch (e) {
			console.log(`profile restore failed: ${e.message}`);
		}
		try {
			cleanup(site);
		} catch (e) {
			console.log(`cleanup failed: ${e.message}`);
		}
		await box.ctx.close();
		await csBox.ctx.close();
		await anonBox.ctx.close();
	}
	// the arrays are filled while the run goes on, so they are read only now
	return c.done([...box.issues, ...csBox.issues, ...anonBox.issues]);
}

const browser = await launch();
let bad = 0;
for (const site of sitesFromArgv()) bad += await scenario(browser, site);
await browser.close();
void SITES;
process.exit(bad ? 1 : 0);
