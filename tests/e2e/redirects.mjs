// Browser E2E for the modules `redirects` and `redirect-404-to-homepage`.
// Run: node tests/e2e/redirects.mjs [j5|j6|all] [--headed]
// Real flows: SPA of UX1 Studio (rules, 404 log, test/audit, CSV, settings), Joomla administrator (article alias monitor),
// anonymous visitor on the frontend. Test data is prefixed "uxs-e2e" and removed at the end (also before the start).
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import { launch, context, adminLogin, openStudio, shot, checker, sitesFromArgv, SHOTS } from './lib.mjs';

const MYSQL = 'D:/xampp/mysql/bin/mysql.exe';
const P = 'uxs-e2e';
const sql = (db, q) => execFileSync(MYSQL, ['-uroot', '--default-character-set=utf8mb4', '-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// Browser noise that is expected: the 404/410 pages of the redirect tests themselves.
// (the console line of a failed resource carries no URL, but every HTTP >= 400 is also reported with its URL and checked there)
const EXPECTED = [/HTTP 404 .*uxs-e2e/, /HTTP 410 .*uxs-e2e/, /HTTP 404 .*nothing/, /Failed to load resource.*\b(404|410)\b/];

function cleanup(site) {
	const db = site.db;
	sql(db, `DELETE FROM jos_uxstudio_redirects WHERE source LIKE '%${P}%' OR note LIKE 'e2e%' OR target LIKE '%${P}%'`);
	sql(db, `DELETE FROM jos_uxstudio_redirects_404 WHERE url LIKE '%${P}%'`);
	sql(db, `DELETE FROM jos_redirect_links WHERE old_url LIKE '%${P}%'`);
	// test articles (created through the Joomla UI)
	const ids = sql(db, `SELECT id FROM jos_content WHERE alias LIKE '${P}%'`).split(/\s+/).filter(Boolean);
	if (ids.length) {
		const l = ids.join(',');
		sql(db, `DELETE FROM jos_workflow_associations WHERE item_id IN (${l}) AND extension='com_content.article'`);
		sql(db, `DELETE FROM jos_content_frontpage WHERE content_id IN (${l})`);
		sql(db, `DELETE FROM jos_content_rating WHERE content_id IN (${l})`);
		sql(db, `DELETE FROM jos_assets WHERE name IN (${ids.map((i) => `'com_content.article.${i}'`).join(',')})`);
		sql(db, `DELETE FROM jos_content WHERE id IN (${l})`);
	}
}

const setModule = (site, id, enabled) => sql(site.db, `UPDATE jos_uxstudio_modules SET enabled=${enabled ? 1 : 0} WHERE id='${id}'`);
const getModule = (site, id) => sql(site.db, `SELECT enabled FROM jos_uxstudio_modules WHERE id='${id}'`) === '1';
const hitsOf = (site, source) => Number(sql(site.db, `SELECT hits FROM jos_uxstudio_redirects WHERE source='${source}'`) || -1);

/** HTTP-level request without following redirects (status + Location). */
async function raw(ctx, url, opts = {}) {
	const r = await ctx.request.get(url, { maxRedirects: 0, failOnStatusCode: false, ...opts });
	return { status: r.status(), loc: r.headers()['location'] ?? '', headers: r.headers(), body: await r.text() };
}

/** Administrator login with the language chosen in the login form (per session, no change to the user profile). */
async function adminLoginLang(ctx, site, lang) {
	const env = {};
	for (const line of fs.readFileSync(new URL('../../.env.local', import.meta.url), 'utf8').split(/\r?\n/)) {
		const m = line.match(/^([A-Z0-9_]+)=(.*)$/);
		if (m) env[m[1]] = m[2];
	}
	const page = await ctx.newPage();
	await page.goto(`${site.url}/administrator/index.php`);
	await page.fill('#mod-login-username', env.JOOMLA_ADMIN_USER);
	await page.fill('#mod-login-password', env.JOOMLA_ADMIN_PASSWORD);
	await page.selectOption('#lang', lang);
	await page.click('#btn-login-submit', { noWaitAfter: true });
	await page.locator('#mod-login-username').waitFor({ state: 'detached', timeout: 90000 });
	await page.waitForLoadState('load');
	return page;
}
const inflight = new WeakMap();
/** Waits until the SPA has no API request in flight (Playwright's networkidle only fires once per navigation). */
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
	await sleep(250);
	for (let i = 0; i < 100 && st.set.size > 0; i++) await sleep(100);
	await sleep(250);
}
/** Re-asserts the state of the 404 -> homepage module right before a check (parallel runs restore their own snapshots). */
const iso = (site, on) => setModule(site, 'redirect-404-to-homepage', on);
const dialog = (page) => page.locator('[role="dialog"]');

/** Fills the "Add redirect" modal and submits it. Returns what happened. */
async function addRule(page, r) {
	await page.locator('.uxs-list-toolbar .uxs-btn--primary').click();
	await dialog(page).waitFor();
	await fillRule(page, r);
	return submitRule(page);
}
async function fillRule(page, r) {
	if (r.match) await page.selectOption('#uxs-rd-match', r.match);
	if (r.code) await page.selectOption('#uxs-rd-code', String(r.code));
	if (r.source !== undefined) await page.fill('#uxs-rd-source', r.source);
	if (r.target !== undefined && r.code !== 410) await page.fill('#uxs-rd-target', r.target);
	if (r.query) await page.selectOption('#uxs-rd-query', r.query);
	if (r.note !== undefined) await page.fill('#uxs-rd-note', r.note);
}
async function submitRule(page) {
	const answered = page.waitForResponse((r) => /route=redirects/.test(decodeURIComponent(r.url())) && r.request().method() === 'POST', { timeout: 15000 }).catch(() => null);
	await dialog(page).locator('.uxs-modal__actions .uxs-btn--primary').click();
	await answered;
	await sleep(250);
	for (let i = 0; i < 40; i++) {
		await sleep(150);
		if (!(await dialog(page).count())) return { closed: true, error: '', warnings: '' };
		const err = await dialog(page).locator('[role="alert"]').allInnerTexts();
		if (err.length && err[0]) return { closed: false, error: err[0], warnings: '' };
		if (await dialog(page).locator('.uxs-badge.is-success').count()) {
			const w = await dialog(page).innerText();
			await dialog(page).locator('.uxs-modal__actions .uxs-btn--primary').click();
			await dialog(page).waitFor({ state: 'detached' });
			return { closed: true, error: '', warnings: w };
		}
	}
	return { closed: false, error: '(timeout)', warnings: '' };
}
async function cancelModal(page) {
	if (await dialog(page).count()) {
		await dialog(page).locator('.uxs-modal__actions .uxs-btn').first().click();
		await dialog(page).waitFor({ state: 'detached' });
	}
}
const tab = async (page, i) => { await settle(page); await page.locator('[role="tab"]').nth(i).click(); await settle(page); await sleep(300); };
const row = (page, text) => page.locator('table.uxs-table tbody tr', { hasText: text });
async function confirmDialog(page) {
	await dialog(page).waitFor();
	await dialog(page).locator('.uxs-modal__actions .uxs-btn--danger, .uxs-modal__actions .uxs-btn--primary').click();
	await dialog(page).waitFor({ state: 'detached' });
}
async function setTheme(page, theme) {
	await page.evaluate((th) => localStorage.setItem('uxstudio-theme', th), theme);
}

async function scenario(browser, site) {
	const c = checker(`redirects ${site.key}`);
	const base = site.url;
	const idx = `${base}/index.php`;
	const homeUrl = `${base}/`;

	// ---- initial state (restored in finally) ----
	const was404 = getModule(site, 'redirect-404-to-homepage');
	const wasRedirects = getModule(site, 'redirects');
	const settingsBefore = sql(site.db, `SELECT data FROM jos_uxstudio_settings WHERE module='redirects'`);
	const settings404Before = sql(site.db, `SELECT data FROM jos_uxstudio_settings WHERE module='redirect-404-to-homepage'`);
	const issues = [];
	let aIssues = [];
	let sIssues = [];
	cleanup(site);
	try {
		setModule(site, 'redirects', true);
		iso(site, false); // isolated: the 404 log tests need real 404 pages

		const anonCtx = await context(browser, { ignore: EXPECTED });
		const actx = anonCtx.ctx;
		aIssues = anonCtx.issues; // kept by reference: everything collected until the end of the scenario counts
		const anon = await actx.newPage();
		const adminCtx = await context(browser, { ignore: EXPECTED });
		const ctx = adminCtx.ctx;
		sIssues = adminCtx.issues;
		const page = await adminLoginLang(ctx, site, 'en-GB'); // language chosen per session, immune to a changed profile

		// =================== SPA: empty state, creating rules ===================
		await settle(page);
		await openStudio(page, site, '#/module?id=redirects');
		await setTheme(page, 'light');
		await page.reload();
		await page.locator('#uxs-root [role="tab"]').first().waitFor();
		c.ok((await page.locator('[role="tab"]').count()) === 5, 'five tabs (rules, 404, audit, import, settings)');
		await page.fill('input[type="search"]', 'zzz-nothing-matches');
		await settle(page);
		c.ok(/No redirects yet/.test(await page.locator('table.uxs-table').innerText()), 'empty state when the search finds nothing');
		await page.fill('input[type="search"]', '');
		await settle(page);

		// ---- validation errors ----
		await page.locator('.uxs-list-toolbar .uxs-btn--primary').click();
		await dialog(page).waitFor();
		c.ok(await dialog(page).locator('.uxs-modal__actions .uxs-btn--primary').isDisabled(), 'save is disabled with an empty source');
		await page.fill('#uxs-rd-source', '/administrator/foo');
		await page.fill('#uxs-rd-target', '/x');
		let r = await submitRule(page);
		c.ok(/system paths/i.test(r.error), `reserved path refused: ${r.error}`);
		await page.fill('#uxs-rd-source', '/');
		r = await submitRule(page);
		c.ok(/homepage itself/i.test(r.error), `homepage as source refused: ${r.error}`);
		await page.fill('#uxs-rd-source', '/index.php');
		r = await submitRule(page);
		c.ok(/homepage itself/i.test(r.error), `/index.php (homepage without URL rewrite) refused: ${r.error}`);
		await page.fill('#uxs-rd-source', `/${P}-v1`);
		await page.fill('#uxs-rd-target', `/${'b'.repeat(2100)}`);
		r = await submitRule(page);
		c.ok(/too long/i.test(r.error), `too long target gets a specific message: ${r.error}`);
		await page.fill('#uxs-rd-target', '/x');
		await page.fill('#uxs-rd-source', `/${P}-v1`);
		await page.fill('#uxs-rd-target', '');
		r = await submitRule(page);
		c.ok(/Target is required/i.test(r.error), `empty target for 301 refused: ${r.error}`);
		await page.fill('#uxs-rd-target', '//evil.example/x');
		r = await submitRule(page);
		c.ok(/Protocol-relative/i.test(r.error), `protocol-relative target refused: ${r.error}`);
		await page.fill('#uxs-rd-target', 'javascript:alert(1)');
		r = await submitRule(page);
		c.ok(r.error !== '' && !r.closed, `javascript: target refused: ${r.error}`);
		await page.fill('#uxs-rd-source', `${P} with spaces <b>`);
		await page.fill('#uxs-rd-target', '/x');
		r = await submitRule(page);
		c.ok(r.closed, `a path with spaces is accepted as a literal path (${r.closed ? 'accepted' : r.error})`);
		c.ok(!(await page.locator('table.uxs-table b').count()), '<b> in a source is shown as text, not rendered as HTML');
		sql(site.db, `DELETE FROM jos_uxstudio_redirects WHERE source LIKE '%with spaces%'`);
		await shot(page, `redirects-${site.key}-validation`);

		// ---- unsafe / invalid regex ----
		let res = await addRule(page, { match: 'regex', source: '(a+)+$', target: '/x', code: 301 });
		c.ok(/too slow|backtrack/i.test(res.error), `catastrophic regex refused: ${res.error}`);
		await fillRule(page, { source: '^/uxs-e2e-bad/(unclosed' });
		res = await submitRule(page);
		c.ok(/not a valid regular expression/i.test(res.error), `invalid regex refused: ${res.error}`);
		await fillRule(page, { source: '(x+x+)+y' });
		res = await submitRule(page);
		c.ok(!res.closed && /too slow|backtrack/i.test(res.error), `second nested-repeat regex refused: ${res.error}`);
		await cancelModal(page);

		// ---- 301 exact to a Joomla route (article), SEF ----
		const articleRoute = 'index.php?option=com_content&view=article&id=2:forms-module-page&catid=2';
		res = await addRule(page, { source: `/${P}-old-a`, target: articleRoute, code: 301, note: 'e2e 301' });
		c.ok(res.closed && !res.error, `301 rule created (${res.error}${res.warnings.slice(0, 80)})`);
		await row(page, `/${P}-old-a`).first().waitFor({ timeout: 8000 }).catch(() => null);
		c.ok((await row(page, `/${P}-old-a`).count()) === 1, 'rule visible in the table');

		// duplicate + loop
		res = await addRule(page, { source: `/${P}-old-a`, target: '/other', code: 301 });
		c.ok(/already handles/i.test(res.error), `duplicate source refused: ${res.error}`);
		await cancelModal(page);

		// ---- 302 with a warning, 410, regex, query pass ----
		res = await addRule(page, { source: `/${P}-old-b`, target: `${base}/index.php/${P}-new-b`, code: 302, note: 'e2e 302' });
		c.ok(res.closed && /Temporary redirect/i.test(res.warnings), `302 saves with a temporary warning`);
		res = await addRule(page, { source: `/${P}-gone`, code: 410, note: 'e2e 410' });
		c.ok(res.closed, `410 rule created (${res.error})`);
		c.ok(await (async () => { await page.locator('.uxs-list-toolbar .uxs-btn--primary').click(); await dialog(page).waitFor(); await page.selectOption('#uxs-rd-code', '410'); const dis = await page.locator('#uxs-rd-target').isDisabled(); await cancelModal(page); return dis; })(), 'target field is disabled for 410');
		res = await addRule(page, { match: 'regex', source: `^/${P}-blog/([0-9]+)-(.*)$`, target: `/${P}-dest/$2`, code: 301, note: 'e2e regex' });
		c.ok(res.closed, `regex rule created (${res.error})`);
		res = await addRule(page, { source: `/${P}-old-c`, target: `/${P}-dest-c`, code: 301, query: 'pass', note: 'e2e pass' });
		c.ok(res.closed, `query=pass rule created (${res.error})`);
		res = await addRule(page, { source: `/${P}-old-d`, target: `/${P}-dest-d`, code: 301, query: 'ignore', note: 'e2e ignore' });
		c.ok(res.closed, `query=ignore rule created (${res.error})`);
		res = await addRule(page, { source: `/${P}-Case`, target: `/${P}-dest-case`, code: 301, note: 'e2e case' });
		c.ok(res.closed, 'case rule created');
		// loop: dest-c -> old-c
		res = await addRule(page, { source: `/${P}-dest-c`, target: `/${P}-old-c`, code: 301 });
		c.ok(/loop/i.test(res.error), `loop refused: ${res.error}`);
		await cancelModal(page);
		// chain flattening: old-e -> old-a (itself redirected)
		res = await addRule(page, { source: `/${P}-old-e`, target: `/${P}-old-a`, code: 301, note: 'e2e chain' });
		c.ok(res.closed && /straight|chain/i.test(res.warnings), `chain flattened with a warning`);
		await shot(page, `redirects-${site.key}-rules-light`);

		// =================== Frontend, anonymous ===================
		let f = await raw(actx, `${idx}/${P}-old-a`);
		c.ok(f.status === 301 && /forms-module-page/.test(f.loc), `anon 301 -> article (${f.status} ${f.loc})`);
		c.ok(/max-age=3600/.test(f.headers['cache-control'] ?? ''), 'bounded browser cache on 301');
		c.ok(/UX1 Studio Redirects/.test(f.headers['x-redirect-by'] ?? ''), 'X-Redirect-By header');
		let resp = await anon.goto(`${idx}/${P}-old-a`);
		c.ok(resp.status() === 200 && /forms-module-page/.test(anon.url()) && /Forms module page/i.test(await anon.title() + await anon.locator('body').innerText()), `browser lands on the article (${anon.url()})`);
		// trailing slash / upper case ignored
		f = await raw(actx, `${idx}/${P}-OLD-A/`);
		c.ok(f.status === 301, 'case and trailing slash ignored');
		// non-SEF style access path
		f = await raw(actx, `${base}/index.php?${P}=1`);
		c.ok(f.status !== 500 && f.status !== 301 && f.status !== 302, `non-SEF query URL is not redirected and does not break (${f.status})`);
		// 302
		f = await raw(actx, `${idx}/${P}-old-b`);
		c.ok(f.status === 302 && f.loc.includes(`${P}-new-b`) && !/max-age/.test(f.headers['cache-control'] ?? ''), `302 without cache (${f.status})`);
		// 410
		resp = await anon.goto(`${idx}/${P}-gone`);
		c.ok(resp.status() === 410 && /removed/i.test(await anon.locator('body').innerText()), `410 Gone page rendered (${resp.status()})`);
		c.ok(/noindex/.test(resp.headers()['x-robots-tag'] ?? ''), '410 has X-Robots-Tag noindex');
		// regex
		f = await raw(actx, `${idx}/${P}-blog/123-hello-world`);
		c.ok(f.status === 301 && f.loc.endsWith(`/${P}-dest/hello-world`), `regex capture group $2 substituted (${f.loc})`);
		// query handling
		f = await raw(actx, `${idx}/${P}-old-c?utm_source=news&gclid=1`);
		c.ok(f.status === 301 && /utm_source=news/.test(f.loc), `query=pass keeps utm (${f.loc})`);
		f = await raw(actx, `${idx}/${P}-old-d?utm_source=news`);
		c.ok(f.status === 301 && !/utm_source/.test(f.loc), `query=ignore drops utm (${f.loc})`);
		f = await raw(actx, `${idx}/${P}-old-a?utm_campaign=z`);
		c.ok(f.status === 301 && /utm_campaign=z/.test(f.loc), 'default query mode passes utm');
		// chain flattened live
		f = await raw(actx, `${idx}/${P}-old-e`);
		c.ok(f.status === 301 && /forms-module-page/.test(f.loc), 'flattened chain redirects straight to the article');
		// POST is not redirected
		const post = await actx.request.post(`${idx}/${P}-old-a`, { maxRedirects: 0, failOnStatusCode: false });
		c.ok(post.status() !== 301, `POST is not redirected (${post.status()})`);
		// non-SEF legacy URL with a query-based rule
		await page.locator('.uxs-list-toolbar .uxs-btn--primary').click();
		await dialog(page).waitFor();
		await fillRule(page, { source: `/index.php?option=com_${P.replace('-', '_')}&view=old`, target: `/${P}-dest-q`, code: 301, note: 'e2e legacy' });
		res = await submitRule(page);
		c.ok(res.closed, `legacy query-string rule created (${res.error})`);
		f = await raw(actx, `${idx}?option=com_${P.replace('-', '_')}&view=old`);
		c.ok(f.status === 301 && f.loc.includes(`${P}-dest-q`), `non-SEF legacy URL redirected (${f.status} ${f.loc})`);

		// hit counters
		await sleep(300);
		const hitsA = hitsOf(site, `/${P}-old-a`);
		c.ok(hitsA >= 4, `hit counter counts visits (${hitsA})`);
		await page.reload();
		await page.locator('table.uxs-table').waitFor();
		await row(page, `/${P}-old-a`).first().locator('button[aria-label="Edit"]').waitFor();
		const rowA = row(page, `/${P}-old-a`).first();
		c.ok(new RegExp(`\\b${hitsA}\\b`).test(await rowA.innerText()), 'counter shown in the table');
		await rowA.locator('button[aria-label="Reset hit counter"]').click();
		await settle(page);
		c.ok(hitsOf(site, `/${P}-old-a`) === 0, 'reset hit counter works');

		// ---- edit, toggle, delete ----
		await row(page, `/${P}-old-b`).locator('button[aria-label="Edit"]').click();
		await dialog(page).waitFor();
		c.ok((await page.inputValue('#uxs-rd-source')) === `/${P}-old-b`, 'edit modal prefilled');
		await page.selectOption('#uxs-rd-code', '307');
		await page.fill('#uxs-rd-note', 'e2e edited');
		res = await submitRule(page);
		c.ok(res.closed, `rule edited (${res.error})`);
		await settle(page);
		f = await raw(actx, `${idx}/${P}-old-b`);
		c.ok(f.status === 307, `edited code is live (${f.status})`);
		c.ok((await row(page, 'e2e edited').count()) === 1, 'edited note shown');
		// disable
		await row(page, `/${P}-old-b`).locator('button[aria-pressed], [role="switch"]').first().click();
		await settle(page);
		iso(site, false);
		f = await raw(actx, `${idx}/${P}-old-b`);
		c.ok(f.status === 404, `disabled rule does not redirect (${f.status})`);
		await row(page, `/${P}-old-b`).locator('button[aria-pressed], [role="switch"]').first().click();
		await settle(page);
		f = await raw(actx, `${idx}/${P}-old-b`);
		c.ok(f.status === 307, `re-enabled rule redirects again (${f.status}, enabled=${sql(site.db, `SELECT enabled FROM jos_uxstudio_redirects WHERE source='/${P}-old-b'`)})`);
		// delete (with cancel first)
		await row(page, `/${P}-old-d`).locator('button[aria-label="Delete"]').click();
		await dialog(page).waitFor();
		await dialog(page).locator('.uxs-modal__actions .uxs-btn').first().click();
		await dialog(page).waitFor({ state: 'detached' });
		c.ok((await row(page, `/${P}-old-d`).count()) === 1, 'delete can be cancelled');
		await row(page, `/${P}-old-d`).locator('button[aria-label="Delete"]').click();
		await confirmDialog(page);
		await settle(page);
		iso(site, false);
		f = await raw(actx, `${idx}/${P}-old-d`);
		c.ok((await row(page, `/${P}-old-d`).count()) === 0 && f.status === 404, 'deleted rule is gone and no longer redirects');

		// =================== 404 log ===================
		iso(site, false);
		for (let i = 0; i < 2; i++) await anon.goto(`${idx}/${P}-missing-1`, { waitUntil: 'domcontentloaded' });
		resp = await anon.goto(`${idx}/${P}-old/forms-module-page`, { waitUntil: 'domcontentloaded' });
		c.ok(resp.status() === 404, '404 in the browser for an unknown URL');
		await anon.goto(`${idx}/${P}-removed-forever`, { waitUntil: 'domcontentloaded' });
		await anon.goto(`${idx}/${P}-gone`, { waitUntil: 'domcontentloaded' });
		c.ok(sql(site.db, `SELECT hits FROM jos_uxstudio_redirects_404 WHERE url LIKE '%${P}-missing-1'`) === '2', '404 written to the log, one row per URL with a counter');
		c.ok(sql(site.db, `SELECT COUNT(*) FROM jos_uxstudio_redirects_404 WHERE url LIKE '%${P}-gone'`) === '0', 'a URL answered by a rule (410) is not logged as 404');
		await tab(page, 1);
		await shot(page, `redirects-${site.key}-404log-light`);
		await row(page, `${P}-missing-1`).first().waitFor({ timeout: 8000 }).catch(() => null);
		const logRow = row(page, `${P}-missing-1`);
		c.ok((await logRow.count()) === 1, '404 row visible in the log tab');
		// one-click suggestion
		await row(page, `${P}-old/forms-module-page`).first().locator('.uxs-btn').first().waitFor({ timeout: 8000 }).catch(() => null);
		const sugRow = row(page, `${P}-old/forms-module-page`);
		const sugBtn = sugRow.locator('.uxs-btn--primary');
		c.ok((await sugBtn.count()) === 1 && /Forms module/.test(await sugBtn.innerText()), `suggestion button offered (${(await sugBtn.allInnerTexts())[0] ?? '-'})`);
		await sugBtn.click();
		await settle(page);
		c.ok(sql(site.db, `SELECT origin FROM jos_uxstudio_redirects WHERE source LIKE '%${P}-old/forms-module-page'`) === 'suggest', 'one-click redirect created with origin "suggest"');
		f = await raw(actx, `${idx}/${P}-old/forms-module-page`);
		c.ok(f.status === 301 && /forms-module-page/.test(f.loc), 'suggested redirect works');
		// redirect... form from the log
		await row(page, `${P}-missing-1`).locator('.uxs-btn', { hasText: /Redirect/ }).click();
		await dialog(page).waitFor();
		c.ok((await page.inputValue('#uxs-rd-source')).includes(`${P}-missing-1`), '"Redirect..." opens the form with the logged URL');
		await page.fill('#uxs-rd-target', `/${P}-dest-m`);
		res = await submitRule(page);
		c.ok(res.closed, `rule from the 404 log created (${res.error})`);
		await settle(page);
		// 410 button
		await row(page, `${P}-removed-forever`).locator('.uxs-btn', { hasText: /^410$/ }).click();
		await settle(page);
		resp = await anon.goto(`${idx}/${P}-removed-forever`, { waitUntil: 'domcontentloaded' });
		c.ok(resp.status() === 410, `410 button from the log works (${resp.status()})`);
		// log shows "resolved" rows only when the filter is off
		await page.reload();
		await tab(page, 1);
		c.ok((await row(page, `${P}-missing-1`).count()) === 0, 'resolved URLs hidden by default');
		await page.locator('input[type="checkbox"]').first().uncheck();
		await row(page, `${P}-missing-1`).first().waitFor({ timeout: 8000 }).catch(() => null);
		c.ok((await row(page, `${P}-missing-1`).count()) === 1, 'resolved URLs shown after the filter is turned off');
		await page.locator('input[type="checkbox"]').first().check();
		// delete a log row (asks first)
		await anon.goto(`${idx}/${P}-trash-me`, { waitUntil: 'domcontentloaded' });
		await page.reload();
		await tab(page, 1);
		await row(page, `${P}-trash-me`).locator('button[aria-label="Delete"]').click();
		await dialog(page).waitFor();
		await dialog(page).locator('.uxs-modal__actions .uxs-btn').first().click();
		await dialog(page).waitFor({ state: 'detached' });
		c.ok(sql(site.db, `SELECT COUNT(*) FROM jos_uxstudio_redirects_404 WHERE url LIKE '%${P}-trash-me'`) === '1', 'deleting a log row can be cancelled');
		await row(page, `${P}-trash-me`).locator('button[aria-label="Delete"]').click();
		await confirmDialog(page);
		await settle(page);
		c.ok(sql(site.db, `SELECT COUNT(*) FROM jos_uxstudio_redirects_404 WHERE url LIKE '%${P}-trash-me'`) === '0', 'log row deleted');
		// "Clear log" asks for confirmation; cancel (never clear the shared log)
		const log404Before = sql(site.db, `SELECT COUNT(*) FROM jos_uxstudio_redirects_404`);
		await page.locator('.uxs-list-toolbar .uxs-btn', { hasText: /Clear log/ }).click();
		await dialog(page).waitFor();
		await dialog(page).locator('.uxs-modal__actions .uxs-btn').first().click();
		await dialog(page).waitFor({ state: 'detached' });
		c.ok(sql(site.db, `SELECT COUNT(*) FROM jos_uxstudio_redirects_404`) === log404Before, 'cancelling the clear-log confirmation keeps every log row');

		// =================== Test and audit ===================
		await tab(page, 2);
		await page.fill('#uxs-rd-test', `/${P}-old-a`);
		await page.locator('.uxs-btn', { hasText: /^\s*Test\s*$/ }).click();
		await settle(page);
		let body = await page.locator('#uxs-root').innerText();
		c.ok(/forms-module-page/.test(body) && /hop/i.test(body), 'test box shows the target and hops');
		await page.fill('#uxs-rd-test', `/${P}-nothing-matches`);
		await page.locator('.uxs-btn', { hasText: /^\s*Test\s*$/ }).click();
		await settle(page);
		c.ok(/No rule matches/.test(await page.locator('#uxs-root').innerText()), 'test box: no rule matches');
		await page.fill('#uxs-rd-test', `/${P}-old-c`);
		await page.locator('label', { hasText: 'Live HTTP check' }).locator('input').check();
		await page.locator('.uxs-btn', { hasText: /^\s*Test\s*$/ }).click();
		await settle(page);
		c.ok(/\b301\b/.test(await page.locator('#uxs-root ol').first().innerText().catch(() => '')), 'live check lists the hops with status codes');
		await page.locator('.uxs-btn--primary', { hasText: 'Run audit' }).click();
		await settle(page);
		body = await page.locator('#uxs-root').innerText();
		c.ok(/Active rules/.test(body) && /Errors/.test(body), 'audit renders its summary');
		await shot(page, `redirects-${site.key}-audit-light`);

		// =================== Import / export ===================
		await tab(page, 3);
		c.ok(!/Import from Joomla Redirects/.test(await page.locator('#uxs-root').innerText()), 'Joomla import section hidden when com_redirect has nothing to import');
		const dl = page.waitForEvent('download');
		await page.locator('.uxs-btn', { hasText: 'Download CSV' }).click();
		const download = await dl;
		const csvPath = `${SHOTS}redirects-${site.key}-export.csv`;
		await download.saveAs(csvPath);
		const csvText = fs.readFileSync(csvPath, 'utf8');
		c.ok(csvText.includes(`/${P}-old-a`) && csvText.includes('source') && csvText.includes('410'), 'CSV export contains the rules');
		fs.unlinkSync(csvPath);
		c.ok(await page.locator('.uxs-btn', { hasText: /^\s*Import\s*$/ }).isDisabled(), 'import disabled with an empty CSV');
		const csv = [
			'source,target,code,note',
			`/${P}-imp-1,/${P}-imp-1-new,301,e2e import 1`,
			`/${P}-imp-2,,410,e2e import 2`,
			`/${P}-imp-3,/${P}-imp-3-new,999,e2e bad code`,
			`/${P}-imp-4,=HYPERLINK("http://evil"),301,e2e formula`,
		].join('\n');
		await page.fill('textarea', csv);
		await page.locator('.uxs-btn', { hasText: 'Check (dry run)' }).first().click();
		await settle(page);
		body = await page.locator('#uxs-root').innerText();
		c.ok(/Would create/.test(body) && /Error/.test(body), 'dry run reports "would create" and an error for the bad code');
		c.ok(sql(site.db, `SELECT COUNT(*) FROM jos_uxstudio_redirects WHERE source LIKE '%${P}-imp-%'`) === '0', 'dry run wrote nothing');
		await shot(page, `redirects-${site.key}-import-light`);
		await page.locator('.uxs-btn--primary', { hasText: /Import/ }).first().click();
		await settle(page);
		const imported = Number(sql(site.db, `SELECT COUNT(*) FROM jos_uxstudio_redirects WHERE source LIKE '%${P}-imp-%'`));
		c.ok(imported >= 2, `import created rules (${imported})`);
		c.ok(sql(site.db, `SELECT COUNT(*) FROM jos_uxstudio_redirects WHERE source LIKE '%${P}-imp-3'`) === '0', 'bad code 999 rejected by the import');
		c.ok(sql(site.db, `SELECT CONCAT(code,'|',target) FROM jos_uxstudio_redirects WHERE source LIKE '%${P}-imp-1'`) === `301|/${P}-imp-1-new`, 'imp-1: 301 with its target');
		c.ok(sql(site.db, `SELECT CONCAT(code,'|',target) FROM jos_uxstudio_redirects WHERE source LIKE '%${P}-imp-2'`) === '410|', 'imp-2: 410 without a target');
		// a semicolon separated file (Excel in cs-CZ) with a header
		await page.fill('textarea', `source;target;code;note
/${P}-imp-5;/${P}-imp-5-new;302;e2e semicolon
`);
		await page.locator('.uxs-btn--primary', { hasText: /Import/ }).first().click();
		await settle(page);
		c.ok(sql(site.db, `SELECT CONCAT(code,'|',target) FROM jos_uxstudio_redirects WHERE source LIKE '%${P}-imp-5'`) === `302|/${P}-imp-5-new`, 'semicolon separated CSV imported with 302');
		await page.fill('textarea', csv);
		const evil = sql(site.db, `SELECT target FROM jos_uxstudio_redirects WHERE source LIKE '%${P}-imp-4'`);
		c.ok(!evil.startsWith('='), `formula cell neutralised or rejected (${evil || 'rejected'})`);
		f = await raw(actx, `${idx}/${P}-imp-1`);
		c.ok(f.status === 301, 'imported rule redirects');
		// import again: conflicts handled, no crash
		await page.locator('.uxs-btn--primary', { hasText: /Import/ }).first().click();
		await settle(page);
		c.ok(!(await page.locator('[role="alert"]').count()) && Number(sql(site.db, `SELECT COUNT(*) FROM jos_uxstudio_redirects WHERE source LIKE '%${P}-imp-%'`)) === imported + 1, 'second import of the same CSV changes nothing and does not crash');
		// file upload
		const file = `${SHOTS}redirects-${site.key}-upload.csv`;
		fs.writeFileSync(file, `source,target,code\n/${P}-imp-6,/${P}-imp-6-new,301\n`);
		await page.locator('input[type="file"]').setInputFiles(file);
		await settle(page);
		c.ok((await page.inputValue('textarea')).includes(`${P}-imp-6`), 'CSV file loaded into the textarea');
		fs.unlinkSync(file);
		// garbage
		await page.fill('textarea', 'this is not a csv at all');
		await page.locator('.uxs-btn', { hasText: 'Check (dry run)' }).first().click();
		await settle(page);
		body = await page.locator('#uxs-root').innerText();
		console.log(`(info) garbage CSV result: ${body.replace(/\s+/g, ' ').slice(-200)}`);
		c.ok(/\bError\b/.test(body.replace(/Check \(dry run\)/g, '')) && !/Would create/.test(body), 'garbage CSV reports an error row instead of importing');
		c.ok(sql(site.db, `SELECT COUNT(*) FROM jos_uxstudio_redirects WHERE source LIKE '%not a csv%'`) === '0', 'garbage wrote nothing');

		// ---- com_redirect collision / import ----
		sql(site.db, `INSERT INTO jos_redirect_links (old_url,new_url,referer,comment,hits,published,created_date,modified_date,header) VALUES ('${base}/${P}-joomla-old','/${P}-joomla-new','','e2e',3,1,NOW(),NOW(),301)`);
		await page.reload();
		await tab(page, 3);
		await page.getByText('Import from Joomla Redirects').first().waitFor({ timeout: 8000 }).catch(() => null);
		body = await page.locator('#uxs-root').innerText();
		c.ok(/Import from Joomla Redirects/.test(body), `Joomla import section appears when com_redirect has redirects (links in DB: ${sql(site.db, `SELECT COUNT(*) FROM jos_redirect_links WHERE new_url<>'' AND published=1`)})`);
		const jsec = page.locator('h2', { hasText: 'Import from Joomla Redirects' });
		await jsec.locator('xpath=following::button[contains(.,"dry run")][1]').click();
		await settle(page);
		c.ok(/Would create/.test(await page.locator('#uxs-root').innerText()), 'Joomla import dry run');
		await jsec.locator('xpath=following::button[normalize-space()="Import"][1]').click();
		await settle(page);
		c.ok(sql(site.db, `SELECT COUNT(*) FROM jos_uxstudio_redirects WHERE source LIKE '%${P}-joomla-old'`) === '1', 'Joomla redirect imported');
		// notice about the enabled Joomla plugin (the site setting itself is not touched: the status response is mocked)
		const statusRoute = /route=redirects(\/|%2F)status/;
		await page.route(statusRoute, async (route) => {
			const resp2 = await route.fetch();
			const j = await resp2.json();
			j.data.joomla_redirect_plugin = true;
			await route.fulfill({ response: resp2, json: j }).catch(() => null);
		});
		setModule(site, 'redirects', true); // re-asserted: a parallel run may have restored its own snapshot
		await page.reload();
		await page.locator('[role="tab"]').first().waitFor();
		await page.locator('.uxs-notice').first().waitFor({ timeout: 5000 }).catch(() => null);
		c.ok(await page.locator('.uxs-notice').count() > 0 && /System - Redirect/.test(await page.locator('.uxs-notice').innerText()), 'collision notice with the Joomla Redirect plugin shown');
		await shot(page, `redirects-${site.key}-notice`);
		await page.unroute(statusRoute);
		const plugin = sql(site.db, `SELECT enabled FROM jos_extensions WHERE element='redirect' AND type='plugin'`);
		console.log(`(info) Joomla Redirect plugin enabled=${plugin}`);

		// ---- settings tab ----
		await tab(page, 4);
		await settle(page);
		const ttl = page.locator('#uxs-permanent_cache_ttl');
		c.ok((await ttl.count()) === 1, 'settings form renders the cache TTL field');
		await ttl.fill('120');
		await page.getByRole('button', { name: 'Save', exact: true }).click();
		await settle(page);
		c.ok(/"permanent_cache_ttl":\s*120/.test(sql(site.db, `SELECT data FROM jos_uxstudio_settings WHERE module='redirects'`)), 'setting saved to the DB');
		f = await raw(actx, `${idx}/${P}-old-a`);
		c.ok(/max-age=120/.test(f.headers['cache-control'] ?? ''), 'new cache TTL visible on the frontend');
		await ttl.fill('-5');
		await page.getByRole('button', { name: 'Save', exact: true }).click();
		await settle(page);
		const afterNeg = sql(site.db, `SELECT data FROM jos_uxstudio_settings WHERE module='redirects'`);
		c.ok(!/"permanent_cache_ttl":\s*-5/.test(afterNeg), `negative TTL is not stored (${afterNeg.slice(0, 120)})`);
		await shot(page, `redirects-${site.key}-settings-light`);
		// track_hits off -> counter stays
		await ttl.fill('3600');
		await page.getByRole('button', { name: 'Save', exact: true }).click();
		await settle(page);

		// =================== Joomla administration: alias monitor ===================
		const stamp = Date.now().toString().slice(-6);
		const alias1 = `${P}-art-${stamp}`;
		const alias2 = `${P}-art-renamed-${stamp}`;
		const ap = await ctx.newPage();
		await ap.goto(`${base}/administrator/index.php?option=com_content&task=article.add`);
		await ap.waitForLoadState('load');
		await ap.fill('#jform_title', `E2E article ${stamp}`);
		await ap.fill('#jform_alias', alias1);
		await Promise.all([ap.waitForNavigation({ waitUntil: 'load' }), ap.evaluate(() => Joomla.submitbutton('article.apply'))]);
		const artId = sql(site.db, `SELECT id FROM jos_content WHERE alias='${alias1}'`);
		c.ok(artId !== '', `article created through the Joomla UI (id ${artId}, url ${ap.url()}, ${(await ap.locator('joomla-alert, .alert').allInnerTexts().catch(() => [])).join('|').slice(0, 200)})`);
		if (artId) {
			c.ok(sql(site.db, `SELECT state FROM jos_content WHERE id=${artId}`) === '1', 'article is published');
			await ap.fill('#jform_alias', alias2);
			await Promise.all([ap.waitForNavigation({ waitUntil: 'load' }), ap.evaluate(() => Joomla.submitbutton('article.apply'))]);
			const autoCount = sql(site.db, `SELECT COUNT(*) FROM jos_uxstudio_redirects WHERE origin='auto' AND source LIKE '%${alias1}%'`);
			c.ok(autoCount === '1', `alias change created an automatic 301 (${autoCount})`);
			iso(site, false);
			const autoSrc = sql(site.db, `SELECT source FROM jos_uxstudio_redirects WHERE origin='auto' AND source LIKE '%${alias1}%' LIMIT 1`);
			f = await raw(actx, `${idx}${autoSrc.startsWith('/') ? autoSrc : `/${autoSrc}`}`);
			c.ok(f.status === 301 && f.loc.includes(alias2), `the old article URL ${autoSrc} redirects with 301 to the new alias (${f.status} ${f.loc})`);
			await page.reload();
			await page.locator('[role="tab"]').first().waitFor();
			const origin = await row(page, alias1).first().innerText().catch(() => '');
			c.ok(/Automatic/.test(origin), `auto rule is marked "Automatic" in the SPA`);
			await ap.close();
			// back in the Joomla list: trash + delete through the toolbar
			const lp = await ctx.newPage();
			try {
				await lp.goto(`${base}/administrator/index.php?option=com_content&view=articles&filter[search]=id:${artId}&filter[published]=*&list[limit]=20`);
				await lp.waitForLoadState('load');
				await lp.locator('input[name="cid[]"]').first().check({ timeout: 8000 });
				await Promise.all([lp.waitForNavigation({ waitUntil: 'load' }), lp.evaluate(() => Joomla.submitbutton('articles.trash'))]);
				await lp.goto(`${base}/administrator/index.php?option=com_content&view=articles&filter[search]=id:${artId}&filter[published]=-2`);
				await lp.waitForLoadState('load');
				await lp.locator('input[name="cid[]"]').first().check({ timeout: 8000 });
				await Promise.all([lp.waitForNavigation({ waitUntil: 'load' }), lp.evaluate(() => Joomla.submitbutton('articles.delete'))]);
			} catch (e) {
				console.log(`(info) Joomla list cleanup fell back to SQL: ${String(e.message).slice(0, 80)}`);
			}
			await lp.goto(`${base}/administrator/index.php?option=com_content&view=articles&filter[search]=&filter[published]=`).catch(() => null);
			await lp.close();
			c.ok(sql(site.db, `SELECT COUNT(*) FROM jos_content WHERE alias LIKE '${P}%'`) === '0', 'test article removed through the Joomla UI');
		} else {
			await ap.close();
		}

		// =================== More UI: keyboard, 308, case sensitive, query "exact", diacritics, double click, bulk actions ===================
		iso(site, false);
		await page.reload();
		await page.locator('table.uxs-table').waitFor();
		const newRule = async () => { await page.locator('.uxs-list-toolbar .uxs-btn--primary').click(); await dialog(page).waitFor(); };
		// Escape closes the modal; Tab stays inside it (focus trap)
		await newRule();
		let trapped = true;
		for (let i = 0; i < 24; i++) {
			await page.keyboard.press('Tab');
			if (!(await page.evaluate(() => !!document.activeElement?.closest('[role="dialog"]')))) trapped = false;
		}
		c.ok(trapped, 'focus stays inside the modal while tabbing');
		await page.keyboard.press('Escape');
		await dialog(page).waitFor({ state: 'detached', timeout: 5000 }).catch(() => null);
		c.ok((await dialog(page).count()) === 0, 'Escape closes the modal');
		// Enter in a text field saves the rule; 308
		await newRule();
		await fillRule(page, { source: `/${P}-enter`, target: `/${P}-enter-dest`, code: 308, note: 'e2e enter' });
		await page.press('#uxs-rd-target', 'Enter');
		await dialog(page).waitFor({ state: 'detached', timeout: 8000 }).catch(() => null);
		c.ok(sql(site.db, `SELECT code FROM jos_uxstudio_redirects WHERE source='/${P}-enter'`) === '308', 'Enter in the New URL field saves the rule (code 308)');
		f = await raw(actx, `${idx}/${P}-enter`);
		c.ok(f.status === 308 && f.loc.endsWith(`/${P}-enter-dest`), `308 on the frontend (${f.status} ${f.loc})`);
		// double click on the save button creates exactly one rule and no server error
		await newRule();
		await fillRule(page, { source: `/${P}-dbl`, target: `/${P}-dbl-new`, note: 'e2e dbl' });
		await dialog(page).locator('.uxs-modal__actions .uxs-btn--primary').dblclick();
		await dialog(page).waitFor({ state: 'detached', timeout: 8000 }).catch(() => null);
		await settle(page);
		c.ok(sql(site.db, `SELECT COUNT(*) FROM jos_uxstudio_redirects WHERE source='/${P}-dbl'`) === '1', 'double click on Add redirect creates one rule');
		// case sensitive toggle
		await newRule();
		await fillRule(page, { source: `/${P}-CsRule`, target: `/${P}-cs-dest`, note: 'e2e cs' });
		await dialog(page).locator('button[aria-pressed]').click();
		res = await submitRule(page);
		c.ok(res.closed, `case sensitive rule saved (${res.error})`);
		f = await raw(actx, `${idx}/${P}-CsRule`);
		c.ok(f.status === 301, `case sensitive: exact spelling redirects (${f.status})`);
		f = await raw(actx, `${idx}/${P}-csrule`);
		c.ok(f.status === 404, `case sensitive: another spelling does not (${f.status})`);
		// query mode "exact": a source without a query does not match a URL with one
		res = await addRule(page, { source: `/${P}-qx`, target: `/${P}-qx-dest`, query: 'exact', note: 'e2e qx' });
		c.ok(res.closed, `query=exact rule saved (${res.error})`);
		f = await raw(actx, `${idx}/${P}-qx`);
		c.ok(f.status === 301, `query=exact: plain URL redirects (${f.status})`);
		f = await raw(actx, `${idx}/${P}-qx?a=1`);
		c.ok(f.status === 301 && !/a=1/.test(f.loc) || f.status === 404, `query=exact: a visitor query is not carried over (${f.status} ${f.loc})`);
		// diacritics in the source and in the target; the Location header stays ASCII
		res = await addRule(page, { source: `/${P}-čeština stránka`, target: `/${P}-cíl-ž`, note: 'e2e diacritics' });
		c.ok(res.closed, `rule with diacritics saved (${res.error})`);
		f = await raw(actx, `${idx}/${P}-%C4%8De%C5%A1tina%20str%C3%A1nka`);
		c.ok(f.status === 301 && /^[\x20-\x7e]*$/.test(f.loc) && f.loc.endsWith(`/${P}-c%C3%ADl-%C5%BE`), `diacritics: 301 with a percent-encoded ASCII Location (${f.status} ${f.loc})`);
		// the homepage source stays refused when saved through the API directly as well
		// Fix chains and live audit: both are confirmed / read-only, neither crashes
		await tab(page, 2);
		const fixBtn = page.locator('.uxs-btn', { hasText: 'Fix chains' });
		const rulesBefore = sql(site.db, `SELECT GROUP_CONCAT(CONCAT(id,':',target) ORDER BY id) FROM jos_uxstudio_redirects`);
		await fixBtn.click();
		await dialog(page).waitFor();
		await dialog(page).locator('.uxs-modal__actions .uxs-btn').first().click();
		await dialog(page).waitFor({ state: 'detached' });
		c.ok(sql(site.db, `SELECT GROUP_CONCAT(CONCAT(id,':',target) ORDER BY id) FROM jos_uxstudio_redirects`) === rulesBefore && !(await page.locator('.uxs-card').count()), 'Fix chains asks first; Cancel changes nothing');
		await fixBtn.click();
		await confirmDialog(page);
		await settle(page);
		c.ok(/Chains fixed/.test(await page.locator('#uxs-root').innerText()), 'Fix chains confirmed: the result summary is shown');
		await page.locator('.uxs-btn', { hasText: 'Audit + check targets over HTTP' }).click();
		await settle(page);
		for (let i = 0; i < 40 && !(await page.locator('.uxs-card').count()); i++) await sleep(250);
		c.ok(/Active rules/.test(await page.locator('#uxs-root').innerText()), 'live audit (targets checked over HTTP) renders its summary');
		// reset all hit counters (cancel, then confirm); counters of real rules are restored afterwards
		const realHits = sql(site.db, `SELECT id,hits FROM jos_uxstudio_redirects WHERE source NOT LIKE '%${P}%' AND hits>0`).split('\n').filter(Boolean);
		sql(site.db, `UPDATE jos_uxstudio_redirects SET hits=5 WHERE source='/${P}-enter'`);
		await tab(page, 0);
		await page.reload();
		await page.locator('table.uxs-table').waitFor();
		const resetAll = page.locator('.uxs-list-toolbar .uxs-btn', { hasText: 'Reset all hit counters' });
		await resetAll.waitFor({ timeout: 8000 });
		await resetAll.click();
		await dialog(page).waitFor();
		await dialog(page).locator('.uxs-modal__actions .uxs-btn').first().click();
		await dialog(page).waitFor({ state: 'detached' });
		c.ok(hitsOf(site, `/${P}-enter`) === 5, 'Reset all hit counters can be cancelled');
		await resetAll.click();
		await confirmDialog(page);
		await settle(page);
		c.ok(sql(site.db, `SELECT COUNT(*) FROM jos_uxstudio_redirects WHERE hits>0`) === '0', 'Reset all hit counters zeroes every counter');
		for (const l of realHits) { const [id, h] = l.split('\t'); sql(site.db, `UPDATE jos_uxstudio_redirects SET hits=${Number(h)} WHERE id=${Number(id)}`); }
		// mobile viewport: no horizontal overflow of the page, the modal fits
		await page.setViewportSize({ width: 390, height: 844 });
		await page.reload();
		await page.locator('table.uxs-table').waitFor();
		await sleep(400);
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
		c.ok(overflow <= 2, `mobile 390px: no horizontal page overflow (${overflow}px)`);
		await shot(page, `redirects-${site.key}-mobile`);
		await newRule();
		const mbox = await dialog(page).boundingBox();
		c.ok(mbox !== null && mbox.x >= -1 && mbox.x + mbox.width <= 392, `mobile 390px: the modal fits the screen (${mbox ? `${Math.round(mbox.x)}..${Math.round(mbox.x + mbox.width)}` : 'none'})`);
		await shot(page, `redirects-${site.key}-mobile-modal`);
		await cancelModal(page);
		await page.setViewportSize({ width: 1400, height: 900 });

		// =================== Dark theme ===================
		await setTheme(page, 'dark');
		await page.reload();
		await page.locator('table.uxs-table').waitFor();
		await sleep(500);
		await shot(page, `redirects-${site.key}-rules-dark`);
		await tab(page, 1);
		await shot(page, `redirects-${site.key}-404log-dark`);
		await tab(page, 2);
		await shot(page, `redirects-${site.key}-audit-dark`);
		await tab(page, 0);
		await page.locator('.uxs-list-toolbar .uxs-btn--primary').click();
		await dialog(page).waitFor();
		await shot(page, `redirects-${site.key}-modal-dark`);
		const bg = await page.evaluate(() => getComputedStyle(document.querySelector('.uxs-modal')).backgroundColor);
		c.ok(bg !== 'rgb(255, 255, 255)', `modal is dark in dark mode (${bg})`);
		await cancelModal(page);
		await setTheme(page, 'light');

		// =================== redirect-404-to-homepage ===================
		setModule(site, 'redirect-404-to-homepage', true);
		await openStudio(page, site, '#/module?id=redirect-404-to-homepage');
		await page.reload();
		await page.locator('#uxs-root select').first().waitFor({ timeout: 10000 }).catch(() => null);
		await shot(page, `redirects-${site.key}-404home-settings`);
		let txt = await page.locator('#uxs-root').innerText();
		c.ok(/301/.test(txt) && /static|image|file|asset/i.test(txt), 'module settings page renders code and skip_assets');
		iso(site, true);
		f = await raw(actx, `${idx}/${P}-totally-missing`);
		c.ok(f.status === 301 && f.loc.replace(/\/$/, '') === base.replace(/\/$/, ''), `404 -> homepage 301 (${f.status} ${f.loc})`);
		resp = await anon.goto(`${idx}/${P}-totally-missing`);
		c.ok(resp.status() === 200 && anon.url().replace(/\/$/, '') === base, `browser lands on the homepage (${anon.url()})`);
		f = await raw(actx, `${idx}/${P}-missing-image.png`);
		c.ok(f.status === 404, `missing asset stays 404 (${f.status})`);
		f = await raw(actx, `${idx}/${P}-totally-missing?format=json`);
		c.ok(f.status === 404, `non-HTML format stays 404 (${f.status})`);
		f = await raw(actx, `${idx}/${P}-gone`);
		c.ok(f.status === 410, `410 rule is not turned into a homepage redirect (${f.status})`);
		f = await raw(actx, `${idx}/${P}-old-a`);
		c.ok(f.status === 301 && /forms-module-page/.test(f.loc), 'a redirect rule still wins over the homepage fallback');
		f = await raw(actx, `${idx}/${P}-missing-post`, { method: 'GET' });
		const pst = await actx.request.post(`${idx}/${P}-totally-missing`, { maxRedirects: 0, failOnStatusCode: false });
		c.ok(pst.status() !== 301, `POST not redirected (${pst.status()})`);
		// the 404 log still records (Redirects runs first) and the visitor is redirected
		await anon.goto(`${idx}/${P}-logged-and-redirected`);
		c.ok(sql(site.db, `SELECT COUNT(*) FROM jos_uxstudio_redirects_404 WHERE url LIKE '%${P}-logged-and-redirected'`) === '1', '404 is still logged while the homepage fallback is on');
		// switch to 302 through the settings UI
		const sel = page.locator('#uxs-root select').first();
		if (await sel.count()) {
			await sel.selectOption('302');
			await page.getByRole('button', { name: 'Save', exact: true }).click();
			await settle(page);
			f = await raw(actx, `${idx}/${P}-totally-missing`);
			c.ok(f.status === 302, `code switched to 302 in the settings (${f.status})`);
		} else {
			c.ok(false, 'settings select for the code not found');
		}
		// skip_assets off
		iso(site, true);
		const toggleSkip = page.getByRole('switch').first();
		if (await toggleSkip.count()) {
			await toggleSkip.click();
			await page.getByRole('button', { name: 'Save', exact: true }).click();
			await settle(page);
			f = await raw(actx, `${idx}/${P}-missing-image.png`);
			c.ok(f.status === 302, `with skip_assets off an image 404 is redirected too (${f.status})`);
		}
		await shot(page, `redirects-${site.key}-404home-dark-check`);
		setModule(site, 'redirect-404-to-homepage', false);

		// =================== cs-CZ ===================
		{
			const { ctx: cctx, issues: cIssues } = await context(browser, { ignore: EXPECTED });
			setModule(site, 'redirect-404-to-homepage', true);
			const cpage = await adminLoginLang(cctx, site, 'cs-CZ');
			await openStudio(cpage, site, '#/module?id=redirects');
			await setTheme(cpage, 'light');
			await cpage.reload();
			await cpage.locator('table.uxs-table').waitFor();
			const tabs = await cpage.locator('[role="tab"]').allInnerTexts();
			c.ok(tabs.length === 5 && !tabs.some((x) => /^(Redirects|404 log|Settings|Test and audit)$/.test(x.trim())), `cs-CZ tab labels translated (${tabs.join(' | ')})`);
			const english = /\b(Add redirect|Old URL|New URL|No redirects yet|Search redirects|Hits|Last hit|Reset|Delete this|Active)\b/;
			const seen = [];
			for (let i = 0; i < 5; i++) {
				await tab(cpage, i);
				const text = await cpage.locator('#uxs-root').innerText();
				const m = text.match(english);
				if (m) seen.push(`tab${i}:${m[0]}`);
				await shot(cpage, `redirects-${site.key}-cs-tab${i}`);
			}
			c.ok(seen.length === 0, `no untranslated strings in cs-CZ (${seen.join(', ')})`);
			await tab(cpage, 0);
			await cpage.locator('.uxs-list-toolbar .uxs-btn--primary').click();
			await dialog(cpage).waitFor();
			await cpage.fill('#uxs-rd-source', '/administrator/x');
			await cpage.fill('#uxs-rd-target', '/x');
			const csr = await submitRule(cpage);
			c.ok(csr.error !== '' && !/system paths|cannot be redirected/i.test(csr.error), `validation message in Czech (${csr.error})`);
			await shot(cpage, `redirects-${site.key}-cs-modal`);
			await cancelModal(cpage);
			await cpage.goto(`${base}/administrator/index.php?option=com_uxstudio#/module?id=redirect-404-to-homepage`);
			await cpage.reload();
			await cpage.locator('#uxs-root select').first().waitFor({ timeout: 10000 }).catch(() => null);
			await sleep(500);
			await shot(cpage, `redirects-${site.key}-cs-404home`);
			// the 410 text for the frontend uses the site language (en-GB site): just ensure it renders
			issues.push(...cIssues);
			await cctx.close();
			setModule(site, 'redirect-404-to-homepage', false);
		}

		await actx.close();
		await ctx.close();
	} finally {
		issues.push(...aIssues, ...sIssues);
		cleanup(site);
		setModule(site, 'redirects', wasRedirects);
		setModule(site, 'redirect-404-to-homepage', was404);
		const restore = (m, before) => {
			if (before) sql(site.db, `UPDATE jos_uxstudio_settings SET data='${before.replace(/'/g, "''")}' WHERE module='${m}'`);
			else sql(site.db, `DELETE FROM jos_uxstudio_settings WHERE module='${m}'`);
		};
		restore('redirects', settingsBefore);
		restore('redirect-404-to-homepage', settings404Before);
	}
	c.ok(sql(site.db, `SELECT COUNT(*) FROM jos_uxstudio_redirects WHERE source LIKE '%${P}%'`) === '0', 'test data removed (repeatable)');
	return c.done(issues);
}

const browser = await launch();
let bad = 0;
for (const site of sitesFromArgv()) bad += await scenario(browser, site);
await browser.close();
process.exit(bad ? 1 : 0);
