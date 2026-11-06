#!/usr/bin/env node
/**
 * Joomla administrator CLI for testing the UX Studio API like the SPA does (session cookie + CSRF token).
 *
 *   node bin/jadmin.mjs <j5|j6> GET core/modules
 *   node bin/jadmin.mjs j6 POST core/settings/hello-check '{"greeting":"Hi"}'
 *   node bin/jadmin.mjs j5 page [route]       # GET administrator/index.php?option=com_uxstudio (status + SPA mount check)
 *   node bin/jadmin.mjs j5 login              # (re)login and cache the session
 *   node bin/jadmin.mjs j5 GET core/meta --no-csrf     # send the request WITHOUT the X-CSRF-Token header
 *   node bin/jadmin.mjs j5 GET core/meta --expect 403   # exit 0 only when the HTTP status matches
 *
 * Credentials come from .env.local (JOOMLA_ADMIN_USER, JOOMLA_ADMIN_PASSWORD, J5_URL, J6_URL) and are never printed.
 * The session is cached in .jadmin/<site>.json (gitignored).
 */
import { mkdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

class Done extends Error {}
function return_() {
	throw new Done();
}

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const STATE_DIR = join(ROOT, '.jadmin');

function loadEnv() {
	const env = {};
	for (const line of readFileSync(join(ROOT, '.env.local'), 'utf8').split(/\r?\n/)) {
		const m = /^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/.exec(line);
		if (m) {
			env[m[1]] = m[2].replace(/^["']|["']$/g, '');
		}
	}
	return env;
}

const argv = process.argv.slice(2);
const flags = { noCsrf: false, raw: false, expect: null };
const pos = [];
for (let i = 0; i < argv.length; i++) {
	if (argv[i] === '--no-csrf') flags.noCsrf = true;
	else if (argv[i] === '--raw') flags.raw = true;
	else if (argv[i] === '--expect') flags.expect = Number(argv[++i]);
	else pos.push(argv[i]);
}
const [siteKey, cmd, arg1, arg2] = pos;
if (!['j5', 'j6'].includes(siteKey) || !cmd) {
	console.error('Usage: node bin/jadmin.mjs <j5|j6> <GET|POST|page|login> [route] [json] [--no-csrf] [--raw] [--expect <status>]');
	process.exit(2);
}

const env = loadEnv();
const base = (env[`${siteKey.toUpperCase()}_URL`] || '').replace(/\/+$/, '');
if (!base || !env.JOOMLA_ADMIN_USER || !env.JOOMLA_ADMIN_PASSWORD) {
	console.error('Missing J5_URL / J6_URL / JOOMLA_ADMIN_USER / JOOMLA_ADMIN_PASSWORD in .env.local');
	process.exit(2);
}

const statePath = join(STATE_DIR, `${siteKey}.json`);
let state = existsSync(statePath) ? JSON.parse(readFileSync(statePath, 'utf8')) : { cookies: {}, csrf: '' };
const saveState = () => {
	mkdirSync(STATE_DIR, { recursive: true });
	writeFileSync(statePath, JSON.stringify(state));
};

function cookieHeader() {
	return Object.entries(state.cookies)
		.map(([k, v]) => `${k}=${v}`)
		.join('; ');
}

/** fetch with a manual cookie jar and manual redirects (max 6). */
async function http(url, init = {}, hops = 0) {
	const headers = { ...(init.headers || {}), Cookie: cookieHeader(), 'User-Agent': 'jadmin/1.0', Connection: 'close' };
	const res = await fetch(url, { ...init, headers, redirect: 'manual' });
	for (const c of res.headers.getSetCookie?.() ?? []) {
		const [pair] = c.split(';');
		const eq = pair.indexOf('=');
		const name = pair.slice(0, eq).trim();
		const value = pair.slice(eq + 1).trim();
		if (/expires=Thu, 01 Jan 1970/i.test(c) || value === 'deleted') delete state.cookies[name];
		else state.cookies[name] = value;
	}
	if (res.status >= 300 && res.status < 400 && res.headers.get('location') && hops < 6) {
		const next = new URL(res.headers.get('location'), url).toString();
		return http(next, { method: 'GET' }, hops + 1);
	}
	return { status: res.status, text: await res.text(), headers: res.headers };
}

const adminUrl = `${base}/administrator/index.php`;

async function login() {
	state = { cookies: {}, csrf: '' };
	const page = await http(adminUrl);
	const token = /<input[^>]+type="hidden"[^>]+name="([0-9a-f]{32})"[^>]+value="1"/i.exec(page.text)?.[1];
	if (!token) {
		throw new Error(`Login form with CSRF token not found (HTTP ${page.status}).`);
	}
	const ret = /name="return"[^>]+value="([^"]*)"/i.exec(page.text)?.[1] ?? '';
	const body = new URLSearchParams({
		username: env.JOOMLA_ADMIN_USER,
		passwd: env.JOOMLA_ADMIN_PASSWORD,
		option: 'com_login',
		task: 'login',
		return: ret,
		[token]: '1',
	});
	const res = await http(adminUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
	if (/name="passwd"/.test(res.text) || /task=login|com_login/.test(res.text) && /name="username"/.test(res.text)) {
		throw new Error('Login failed (login form returned). Check credentials / MFA.');
	}
	saveState();
}

async function fetchComponentPage(route = '') {
	return http(`${adminUrl}?option=com_uxstudio${route ? '&' + route : ''}`);
}

function isLoginPage(text) {
	return /name="passwd"/.test(text) && /name="username"/.test(text);
}

async function ensureSession() {
	if (Object.keys(state.cookies).length) {
		const probe = await fetchComponentPage();
		if (probe.status === 200 && !isLoginPage(probe.text)) {
			const t = /"csrf":"([0-9a-f]{32})"/.exec(probe.text)?.[1];
			if (t) {
				state.csrf = t;
				saveState();
				return probe;
			}
		}
	}
	await login();
	const page = await fetchComponentPage();
	const t = /"csrf":"([0-9a-f]{32})"/.exec(page.text)?.[1];
	if (!t) {
		throw new Error(`Could not read the CSRF token from the component page (HTTP ${page.status}). Is com_uxstudio installed?`);
	}
	state.csrf = t;
	saveState();
	return page;
}

function finish(status, text) {
	let shown = text;
	if (!flags.raw) {
		try {
			shown = JSON.stringify(JSON.parse(text), null, 2);
		} catch {
			shown = text.length > 600 ? text.slice(0, 600) + ' ...' : text;
		}
	}
	console.log(`HTTP ${status}`);
	console.log(shown);
	const ok = flags.expect !== null ? status === flags.expect : status < 400;
	{ process.exitCode = ok ? 0 : 1; return_(); }
}

try {
	if (cmd === 'login') {
		await login();
		console.log(`Logged in to ${base} (session cached in .jadmin/${siteKey}.json).`);
		{ process.exitCode = 0; return_(); }
	}

	if (cmd === 'page') {
		const page = await ensureSession();
		const mount = /id="uxs-root"/.test(page.text);
		const script = /com_uxstudio\/js\/app\.js/.test(page.text);
		const css = /com_uxstudio\/css\/app\.css/.test(page.text);
		const options = /"uxstudio":\{/.test(page.text);
		console.log(`HTTP ${page.status}`);
		console.log(JSON.stringify({ mountRoot: mount, scriptTag: script, styleTag: css, scriptOptions: options, bytes: page.text.length }));
		const ok = page.status === 200 && mount && script && css && options;
		{ process.exitCode = flags.expect !== null ? (page.status === flags.expect ? 0 : 1) : ok ? 0 : 1; return_(); }
	}

	const method = cmd.toUpperCase();
	if (!['GET', 'POST'].includes(method) || !arg1) {
		throw new Error('Expected: <GET|POST> <route> [json]');
	}
	await ensureSession();
	const call = async () => {
		const url = `${adminUrl}?option=com_uxstudio&task=api.dispatch&format=json&route=${arg1.split('/').map(encodeURIComponent).join('/')}`;
		const headers = { Accept: 'application/json' };
		if (!flags.noCsrf) headers['X-CSRF-Token'] = state.csrf;
		const init = { method, headers };
		if (method === 'POST') {
			headers['Content-Type'] = 'application/json';
			init.body = arg2 ?? '{}';
		}
		return http(url, init);
	};
	let res = await call();
	if (res.status === 403 && !flags.noCsrf && /invalid_token/.test(res.text)) {
		await ensureSession();
		res = await call();
	}
	finish(res.status, res.text);
} catch (e) {
	if (e instanceof Done) {
		// normal termination, exit code already set
	} else {
	console.error(`jadmin: ${e.message}`);
	process.exitCode = 3;
	}
}
