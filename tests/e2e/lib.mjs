// Shared helpers for the browser end-to-end scenarios in tests/e2e/*.mjs.
// Run a scenario: node tests/e2e/<name>.mjs [j5|j6|all] [--headed]
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
export const SHOTS = 'C:/Users/sturm/.claude/screenshots/';

function env() {
	const out = {};
	for (const line of fs.readFileSync(path.join(ROOT, '.env.local'), 'utf8').split(/\r?\n/)) {
		const m = line.match(/^([A-Z0-9_]+)=(.*)$/);
		if (m) out[m[1]] = m[2];
	}
	return out;
}

export const SITES = {
	j5: { key: 'j5', db: 'joomla5', url: 'http://localhost/joomla5' },
	j6: { key: 'j6', db: 'joomla6', url: 'http://localhost/joomla6' },
};

export function sitesFromArgv() {
	const arg = process.argv.slice(2).find((a) => !a.startsWith('--')) ?? 'all';
	return arg === 'all' ? Object.values(SITES) : [SITES[arg]];
}

export async function launch() {
	return chromium.launch({ headless: !process.argv.includes('--headed') });
}

/**
 * New browser context with console/page errors and HTTP >= 400 collected into `issues`.
 * Ignore expected errors by passing `ignore` regexes.
 */
export async function context(browser, { ignore = [] } = {}) {
	const ctx = await browser.newContext({ viewport: { width: 1400, height: 900 } });
	const issues = [];
	const skip = (t) => ignore.some((r) => r.test(t));
	ctx.on('page', (page) => watch(page, issues, skip));
	return { ctx, issues };
}

function watch(page, issues, skip) {
	page.on('console', (m) => {
		const t = `${m.type()}: ${m.text()}`;
		if (['error', 'warning'].includes(m.type()) && !skip(t)) issues.push(`[${page.url()}] ${t.slice(0, 300)}`);
	});
	page.on('pageerror', (e) => !skip(e.message) && issues.push(`[${page.url()}] pageerror: ${e.message.slice(0, 300)}`));
	page.on('response', (r) => {
		const t = `HTTP ${r.status()} ${r.url()}`;
		if (r.status() >= 400 && !/favicon/.test(r.url()) && !skip(t)) issues.push(t.slice(0, 300));
	});
}

/** Log in to the Joomla administrator through the real login form. */
export async function adminLogin(ctx, site) {
	const e = env();
	const page = await ctx.newPage();
	await page.goto(`${site.url}/administrator/index.php`);
	await page.fill('#mod-login-username', e.JOOMLA_ADMIN_USER);
	await page.fill('#mod-login-password', e.JOOMLA_ADMIN_PASSWORD);
	await Promise.all([page.waitForLoadState('load'), page.click('#btn-login-submit')]);
	if (await page.locator('#mod-login-username').count()) throw new Error(`admin login failed on ${site.key}`);
	return page;
}

/** Open a UX1 Studio SPA route, e.g. '#/module?id=forms'. */
export async function openStudio(page, site, hash = '#/modules') {
	await page.goto(`${site.url}/administrator/index.php?option=com_uxstudio${hash}`);
	await page.locator('#uxs-root').waitFor();
	await page.waitForLoadState('networkidle');
}

export function shot(page, name) {
	return page.screenshot({ path: `${SHOTS}${name}.png`, fullPage: true });
}

/** Tiny assertion collector so one scenario reports every failure, not just the first. */
export function checker(label) {
	const fails = [];
	return {
		ok(cond, msg) {
			if (!cond) fails.push(msg);
			console.log(`${cond ? 'PASS' : 'FAIL'} [${label}] ${msg}`);
		},
		done(issues = []) {
			for (const i of issues) console.log(`ISSUE [${label}] ${i}`);
			const bad = fails.length + issues.length;
			console.log(`== ${label}: ${fails.length} failed checks, ${issues.length} browser issues`);
			return bad;
		},
	};
}
