// Browser E2E for the "email" group: smtp-email, email-log, email-health.
// Run: node tests/e2e/email.mjs [j5|j6|all] [--headed]
//
// Real user flows in the UX1 Studio SPA, the Joomla administration and the public site (password reset as an anonymous
// visitor). Outgoing SMTP goes to a tiny SMTP sink started by this script (so the stored, encrypted password is proven
// to be decrypted and sent). Everything the scenario creates is removed again and the settings of the three modules,
// the e-mail log and the admin language are restored, so it can be repeated.
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import net from 'node:net';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { launch, context, openStudio, SHOTS, SITES, sitesFromArgv, checker } from './lib.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const MYSQL = 'D:/xampp/mysql/bin/mysql.exe';
const PHP = 'D:/xampp/php/php.exe';
const PW = 'S3cret-e2e-pw!';
const TO = 'e2e-to@example.test';
const HTML_TO = 'e2e-html@example.test';
const RESET_MAIL = 'e2e-reset@example.test';
const HEALTH_TO = 'e2e-health@example.test';
const LONG_TO = 'e2e-long@example.test';
// Known core issue (reported, not ours): the shell's "Back to modules" button keeps a light grey Joomla button background in dark mode.
const CORE_OK = /"Back to modules"/;
const MODULES = ['smtp-email', 'email-log', 'email-health'];

/* ------------------------------------------------------------------ helpers */

function env() {
	const out = {};
	for (const line of fs.readFileSync(path.join(ROOT, '.env.local'), 'utf8').split(/\r?\n/)) {
		const m = line.match(/^([A-Z0-9_]+)=(.*)$/);
		if (m) out[m[1]] = m[2];
	}
	return out;
}

function sql(db, q) {
	return execFileSync(MYSQL, ['-uroot', '--default-character-set=utf8mb4', '-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
}
const esc = (s) => String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'");

/** Minimal SMTP server: records messages and the AUTH credentials; can be told to refuse the login. */
function startSink() {
	const sink = { messages: [], creds: [], rejectAuth: false, port: 0, server: null };
	sink.server = net.createServer((sock) => {
		let buf = '';
		let mode = 'cmd';
		let data = '';
		let mailFrom = '';
		let rcpt = [];
		let authStep = null;
		const send = (s) => sock.write(s + '\r\n');
		send('220 sink ESMTP');
		const finishAuth = (user, pass) => {
			sink.creds.push({ user, pass });
			if (sink.rejectAuth) send('535 5.7.8 Authentication credentials invalid');
			else send('235 2.7.0 Authentication successful');
		};
		sock.on('data', (chunk) => {
			buf += chunk.toString('binary');
			let idx;
			while ((idx = buf.indexOf('\r\n')) >= 0) {
				const line = buf.slice(0, idx);
				buf = buf.slice(idx + 2);
				if (mode === 'data') {
					if (line === '.') {
						mode = 'cmd';
						sink.messages.push({ from: mailFrom, to: rcpt.slice(), raw: data });
						data = '';
						send('250 2.0.0 queued');
					} else data += (line.startsWith('..') ? line.slice(1) : line) + '\r\n';
					continue;
				}
				if (authStep === 'user') {
					authStep = { user: Buffer.from(line, 'base64').toString() };
					send('334 UGFzc3dvcmQ6');
					continue;
				}
				if (authStep && typeof authStep === 'object') {
					finishAuth(authStep.user, Buffer.from(line, 'base64').toString());
					authStep = null;
					continue;
				}
				if (authStep === 'plain') {
					const [, u, p] = Buffer.from(line, 'base64').toString().split('\0');
					finishAuth(u, p);
					authStep = null;
					continue;
				}
				const up = line.toUpperCase();
				if (up.startsWith('EHLO') || up.startsWith('HELO')) {
					sock.write('250-sink\r\n250-AUTH PLAIN LOGIN\r\n250 8BITMIME\r\n');
				} else if (up.startsWith('AUTH LOGIN')) {
					authStep = 'user';
					send('334 VXNlcm5hbWU6');
				} else if (up.startsWith('AUTH PLAIN')) {
					const arg = line.split(' ')[2];
					if (arg) {
						const [, u, p] = Buffer.from(arg, 'base64').toString().split('\0');
						finishAuth(u, p);
					} else {
						authStep = 'plain';
						send('334 ');
					}
				} else if (up.startsWith('MAIL FROM')) {
					mailFrom = (line.match(/<([^>]*)>/) || [])[1] || '';
					rcpt = [];
					send('250 OK');
				} else if (up.startsWith('RCPT TO')) {
					rcpt.push((line.match(/<([^>]*)>/) || [])[1] || '');
					send('250 OK');
				} else if (up === 'DATA') {
					mode = 'data';
					send('354 go ahead');
				} else if (up === 'QUIT') {
					send('221 bye');
					sock.end();
				} else if (up === 'RSET' || up === 'NOOP') send('250 OK');
				else send('250 OK');
			}
		});
		sock.on('error', () => {});
	});
	return new Promise((resolve) => sink.server.listen(0, '127.0.0.1', () => ((sink.port = sink.server.address().port), resolve(sink))));
}

/** Decoded text of a captured raw message (quoted-printable and base64 parts), enough for substring checks. */
function mailText(raw) {
	let t = raw.replace(/=\r\n/g, '').replace(/=([0-9A-F]{2})/g, (_, h) => String.fromCharCode(parseInt(h, 16)));
	const b64 = raw.split(/\r\n\r\n/).slice(1).join('\n').replace(/\s+/g, '');
	if (/^[A-Za-z0-9+/=]{16,}$/.test(b64)) t += '\n' + Buffer.from(b64, 'base64').toString('utf8');
	return t;
}

/** Rough WCAG contrast scan of the visible text inside #uxs-root. Returns offenders below the ratio. */
async function lowContrast(page, min = 4.5) {
	return page.evaluate((minRatio) => {
		const parse = (s) => {
			let m = s.match(/rgba?\(([^)]+)\)/);
			if (m) {
				const p = m[1].split(/[ ,/]+/).filter(Boolean).map(Number);
				return { r: p[0], g: p[1], b: p[2], a: p[3] === undefined ? 1 : p[3] };
			}
			m = s.match(/color\(srgb ([^)]+)\)/);
			if (m) {
				const p = m[1].split(/[ /]+/).filter(Boolean).map(Number);
				return { r: p[0] * 255, g: p[1] * 255, b: p[2] * 255, a: p[3] === undefined ? 1 : p[3] };
			}
			return null;
		};
		const lum = ({ r, g, b }) => {
			const f = (v) => ((v /= 255) <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4);
			return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
		};
		const bgOf = (el) => {
			for (let e = el; e; e = e.parentElement) {
				const c = parse(getComputedStyle(e).backgroundColor);
				if (c && c.a > 0.9) return c;
			}
			return { r: 255, g: 255, b: 255, a: 1 };
		};
		const bad = [];
		const root = document.getElementById('uxs-root');
		const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
		const seen = new Set();
		while (walker.nextNode()) {
			const n = walker.currentNode;
			const el = n.parentElement;
			if (!el || !n.textContent.trim() || seen.has(el) || el.closest('iframe')) continue;
			seen.add(el);
			const st = getComputedStyle(el);
			const r = el.getBoundingClientRect();
			if (st.visibility === 'hidden' || st.display === 'none' || r.width < 2 || r.height < 2 || parseFloat(st.opacity) < 0.3) continue;
			const fg = parse(st.color);
			if (!fg) continue;
			const bg = bgOf(el);
			const [l1, l2] = [lum(fg), lum(bg)].sort((a, b) => b - a);
			const ratio = (l1 + 0.05) / (l2 + 0.05);
			if (ratio < minRatio) bad.push(`${ratio.toFixed(2)} "${n.textContent.trim().slice(0, 40)}" fg=${st.color} bg=rgb(${bg.r | 0},${bg.g | 0},${bg.b | 0})`);
		}
		return bad;
	}, min);
}

/* ------------------------------------------------------------------ per-site scenario */

async function login(ctx, site, lang) {
	const e = env();
	const page = await ctx.newPage();
	page.setDefaultTimeout(20000);
	await page.goto(`${site.url}/administrator/index.php`);
	await page.fill('#mod-login-username', e.JOOMLA_ADMIN_USER);
	await page.fill('#mod-login-password', e.JOOMLA_ADMIN_PASSWORD);
	if (lang) await page.selectOption('select#lang', lang);
	await Promise.all([page.waitForLoadState('load'), page.click('#btn-login-submit')]);
	if (await page.locator('#mod-login-username').count()) throw new Error(`admin login failed on ${site.key}`);
	return page;
}

const L = {
	en: {
		overview: 'Overview', settings: 'Settings', send: 'Send test e-mail', messages: 'Messages', save: 'Save', saved: 'Settings saved.',
		stored: 'Stored encrypted', remove: 'Remove', change: 'Change', resend: 'Resend', close: 'Close', cancel: 'Cancel', del: 'Delete', search: 'Search',
		notStored: 'The text of this message was not stored.', checkDns: 'Check DNS', htmlLabel: 'Load remote images', tabSmtp: 'SMTP', notSetUp: 'Not set up',
	},
	cs: {
		overview: 'Přehled', settings: 'Nastavení', send: 'Odeslat zkušební e-mail', messages: 'Zprávy', save: 'Uložit', saved: 'Nastavení bylo uloženo.',
		stored: 'Uloženo šifrovaně', remove: 'Odebrat', change: 'Změnit', resend: 'Odeslat znovu', close: 'Zavřít', cancel: 'Zrušit', del: 'Smazat', search: 'Hledat',
		notStored: 'Text této zprávy nebyl uložen.', checkDns: 'Zkontrolovat DNS', htmlLabel: 'Načíst obrázky z internetu', tabSmtp: 'SMTP', notSetUp: 'Nenastaveno',
	},
};

async function runSite(browser, site, sink) {
	const c = checker(`email ${site.key}`);
	const db = site.db;
	const url = site.url;
	let bad = 0;
	const step = async (name, fn) => {
		try {
			await fn();
		} catch (e) {
			c.ok(false, `${name}: exception ${String(e.message).split('\n').slice(0, 3).join(' | ').slice(0, 300)}`);
			await page?.screenshot({ path: `${SHOTS}email-${site.key}-FAIL-${name.replace(/[^a-z0-9]+/gi, '_')}.png` }).catch(() => {});
		}
	};
	const shot = (page, name) => page.screenshot({ path: `${SHOTS}email-${site.key}-${name}.png`, fullPage: true });

	// ---- snapshot of everything we touch (restored in finally) ----
	const bak = {};
	// Settings are kept as HEX: `mysql -B` doubles every backslash, so raw JSON (escaped slashes and quotes) would come back corrupted.
	const restoreSettings = (saved) => {
		for (const m of MODULES) {
			if (saved[m] === null) sql(db, `DELETE FROM jos_uxstudio_settings WHERE module='${m}'`);
			else sql(db, `REPLACE INTO jos_uxstudio_settings (module,data,updated_at) VALUES ('${m}',UNHEX('${saved[m]}'),NOW())`);
		}
	};
	const bakFile = path.join(ROOT, 'tests', 'e2e', `.email-restore-${site.key}.json`);
	if (fs.existsSync(bakFile)) {
		// A previous run was killed before it could restore: put the original settings back first.
		const old = JSON.parse(fs.readFileSync(bakFile, 'utf8'));
		if (old.hex !== true) throw new Error(`${bakFile} comes from an old run (lossy format): restore the module settings by hand, then delete the file`);
		restoreSettings(old.settings);
		sql(db, "DELETE FROM jos_users WHERE username='e2e_mail_user'");
	}
for (const m of MODULES) bak[m] = sql(db, `SELECT HEX(data) FROM jos_uxstudio_settings WHERE module='${m}'`) || null;
fs.writeFileSync(bakFile, JSON.stringify({ hex: true, settings: bak }));
	const userParams = sql(db, "SELECT params FROM jos_users WHERE username='" + esc(env().JOOMLA_ADMIN_USER) + "'");
	// The log is shared with every other module and agent. We only ever snapshot the rows a destructive UI action (Clear all, the
	// retention job) is about to remove, and put them straight back afterwards. Ids can collide after TRUNCATE (the counter restarts),
	// so rows that could not keep their id are re-inserted under a new one.
	const snapLog = (where) => {
		sql(db, 'DROP TABLE IF EXISTS jos_uxs_e2e_email_log_bak');
		sql(db, `CREATE TABLE jos_uxs_e2e_email_log_bak AS SELECT * FROM jos_uxstudio_email_log WHERE ${where}`);
	};
	const unsnapLog = () => {
		if (!sql(db, "SHOW TABLES LIKE 'jos_uxs_e2e_email_log_bak'")) return;
		const cols = sql(db, "SELECT GROUP_CONCAT(CONCAT('`',COLUMN_NAME,'`') ORDER BY ORDINAL_POSITION) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='jos_uxstudio_email_log' AND COLUMN_NAME<>'id'");
		const bcols = cols.split(',').map((x) => 'b.' + x).join(',');
		sql(db, 'INSERT IGNORE INTO jos_uxstudio_email_log SELECT * FROM jos_uxs_e2e_email_log_bak');
		sql(db, `INSERT INTO jos_uxstudio_email_log (${cols}) SELECT ${bcols} FROM jos_uxs_e2e_email_log_bak b WHERE NOT EXISTS (SELECT 1 FROM jos_uxstudio_email_log l WHERE l.created=b.created AND l.to_email<=>b.to_email AND l.subject<=>b.subject AND l.status<=>b.status)`);
		sql(db, 'DROP TABLE jos_uxs_e2e_email_log_bak');
	};
	unsnapLog(); // a previous aborted run: put its rows back first
	// Only our own rows (e2e-*@example.test recipients and fixed subjects) are ever deleted; the From address is shared with other modules' mail.
	const LOG_CLEANUP = `DELETE FROM jos_uxstudio_email_log WHERE to_email LIKE 'e2e-%@example.test' OR subject IN ('E2E HTML message','E2E failed message','E2E long error','E2E old row','E2E dark modal')`;
	sql(db, LOG_CLEANUP);
	const healthMax = Number(sql(db, 'SELECT COALESCE(MAX(id),0) FROM jos_uxstudio_email_health'));
	const rlMax = sql(db, 'SELECT COUNT(*) FROM jos_uxstudio_ratelimit');
	void rlMax;
	const bucketKeys = ['test', 'dns'].map((w) => `SHA1('email-health:${w}:'+0)`); // placeholder, real keys computed below
	void bucketKeys;
	const adminId = sql(db, "SELECT id FROM jos_users WHERE username='" + esc(env().JOOMLA_ADMIN_USER) + "'");
	const clearRate = () => {
		for (const w of ['test', 'dns']) {
			const key = execFileSync(PHP, ['-r', `echo sha1('email-health:${w}:${adminId}');`], { encoding: 'utf8' });
			sql(db, `DELETE FROM jos_uxstudio_ratelimit WHERE bucket='${key}'`);
		}
	};
	clearRate();
	const setSettings = (module, obj) => sql(db, `REPLACE INTO jos_uxstudio_settings (module,data,updated_at) VALUES ('${module}','${esc(JSON.stringify(obj))}',NOW())`);
	const api = (method, route, body) => {
		const args = ['bin/jadmin.mjs', site.key, method, route];
		if (body) args.push(JSON.stringify(body));
		try {
			return execFileSync('node', args, { cwd: ROOT, encoding: 'utf8', stdio: 'pipe' });
		} catch (e) {
			return String(e.stdout || '') + String(e.stderr || ''); // jadmin exits non-zero on HTTP errors; callers inspect the text
		}
	};
	const getSettings = () => JSON.parse(api('GET', 'core/settings/smtp-email').replace(/^HTTP \d+\s*/, '')).data;

	// Other modules may mail through the same (global) SMTP settings while we run: only look at our own recipients.
	const mine = (addr) => sink.messages.filter((m) => m.to.includes(addr));
	let userCreated = false;
	let page = null;
	let ctx = null;
	try {
		sink.messages.length = 0;
		sink.creds.length = 0;
		sink.rejectAuth = false;
		// Known starting point: SMTP into the sink with a stored (encrypted) password goes through the UI below.
		setSettings('smtp-email', { mailer: 'smtp', fallback_mailer: 'none', from_email: '', from_name: '', host: '', port: 587, encryption: 'tls', username: '', password: '', brevo_api_key: '' });
		setSettings('email-log', { retention_days: 30, store_body: true, mask_sensitive: true, detect_source: true });
		setSettings('email-health', { test_recipient: HEALTH_TO, auto_test: false, test_interval: '24', dns_domain: '', dkim_selectors: '', auto_dns: true });

const created = await context(browser, { ignore: [/Failed to load resource.*\b(400|422|429|502)\b/i] });
		ctx = created.ctx;
		const issues = created.issues;
		// Some HTTP errors are produced on purpose (validation 422, rate limit 429); real console errors still count.
// Only the deliberate failures of the three action endpoints (validation, rate limit, refused send) are tolerated, nothing else of com_uxstudio.
			const expectedHttp = [/HTTP (400|422|429|502) .*route=(smtp-email\/test|smtp-email\/brevo\/verify|email-health\/test|email-health\/dns)/, /violates the following Content Security Policy directive: "img-src/, /ERR_NAME_NOT_RESOLVED/];
		const pending = () => issues.filter((i) => !expectedHttp.some((r) => r.test(i)));

		page = await login(ctx, site, 'en-GB');
		const toast = (re) => page.locator('.uxs-toast', { hasText: re }).first();
		const goModule = async (id, lang = 'en') => {
			await openStudio(page, site, `#/module?id=${id}`);
			const first = page.getByRole('tab', { name: L[lang].overview }).or(page.getByRole('tab', { name: L[lang].messages })).first();
			await first.waitFor({ timeout: 15000 });
			await first.click();
		};
		const tab = (name) => page.getByRole('tab', { name, exact: true }).first();
		const setTheme = async (theme) => {
			await page.evaluate((th) => {
				window.localStorage.setItem('uxstudio-theme', th);
				document.getElementById('uxs-root')?.setAttribute('data-theme', th);
			}, theme);
		};
			// POST requests to one API route (double-click / repeated Enter must not fire twice).
			const countPosts = (route) => {
				let n = 0;
				const h = (rq) => rq.method() === 'POST' && rq.url().includes(`route=${route}`) && n++;
				page.on('request', h);
				return { n: () => n, stop: () => page.off('request', h) };
			};
			const postDone = (route, timeout = 30000) => page.waitForResponse((rs) => rs.request().method() === 'POST' && rs.url().includes(`route=${route}`), { timeout });
			const confirmDialog = async (accept) => {
			const dlg = page.locator('.uxs-modal');
			await dlg.waitFor();
			await dlg.locator(accept ? '.uxs-modal__actions .uxs-btn:not(:first-child)' : '.uxs-modal__actions .uxs-btn:first-child').click();
			await dlg.waitFor({ state: 'detached' });
		};
		// Click Save and wait for the settings POST to come back (stale toasts of earlier saves must not fool the test).
		const saveSettings = async () => {
			const done = page.waitForResponse((r) => r.url().includes('route=core/settings') && r.request().method() === 'POST', { timeout: 15000 });
			await page.getByRole('button', { name: L.en.save, exact: true }).click();
			return (await done).status();
		};

		/* ===== 1. smtp-email: overview with nothing set up ===== */
		await step('smtp overview unset', async () => {
			await goModule('smtp-email');
			await page.locator('.uxs-card', { hasText: 'Connection' }).first().waitFor();
			const txt = await page.locator('#uxs-root').innerText();
			c.ok(/Not set up/.test(txt), 'smtp-email: SMTP without host shows "Not set up"');
			c.ok(/not set up yet, so Joomla sends mail as configured/.test(txt), 'smtp-email: explanatory notice for an unset connection');
			c.ok(!/\bUXS_[A-Z_]{5,}/.test(txt), 'smtp-email: no raw language keys on the overview');
			await shot(page, 'smtp-overview-light');
		});

		/* ===== 2. settings: fill SMTP via the UI, encrypted password ===== */
		await step('smtp settings save', async () => {
			await tab('Settings').click();
			await page.locator('#uxs-mailer').waitFor();
			await page.selectOption('#uxs-mailer', 'smtp');
			await page.selectOption('#uxs-fallback_mailer', 'none');
			await page.fill('#uxs-from_email', 'e2e-from@example.test');
			await page.fill('#uxs-from_name', 'E2E Sender');
			await page.getByRole('tab', { name: 'SMTP', exact: true }).click();
			await page.fill('#uxs-host', '127.0.0.1');
			await page.fill('#uxs-port', String(sink.port));
			await page.selectOption('#uxs-encryption', 'none');
			await page.fill('#uxs-username', 'e2euser');
			await page.fill('#uxs-password', PW);
			await shot(page, 'smtp-settings-light');
			await saveSettings();
			await toast(L.en.saved).waitFor({ timeout: 8000 });
			c.ok(true, 'smtp-email: settings saved with a success toast');
			// The password input is replaced by a "stored" placeholder; the plaintext is nowhere in the page.
			await page.getByText(L.en.stored, { exact: true }).waitFor();
			const html = await page.content();
			c.ok(!html.includes(PW), 'smtp-email: saved password is not present in the DOM');
			c.ok(await page.locator('#uxs-password').count() === 0, 'smtp-email: password input is gone after saving (shows "Stored encrypted")');
			const data = getSettings();
			c.ok(data.values.password === '__set__', 'smtp-email: GET settings returns the __set__ marker, not the password');
			const row = sql(db, "SELECT data FROM jos_uxstudio_settings WHERE module='smtp-email'");
			c.ok(row.includes('enc1:') && !row.includes(PW), 'smtp-email: password is stored encrypted in the database');
			// Reload: still masked; "Change" reveals an empty input.
			await goModule('smtp-email');
			await tab('Settings').click();
			await page.getByRole('tab', { name: 'SMTP', exact: true }).click();
			await page.getByText(L.en.stored, { exact: true }).waitFor();
			await page.getByRole('button', { name: L.en.change }).click();
			c.ok((await page.locator('#uxs-password').inputValue()) === '', 'smtp-email: "Change" gives an empty password field');
			await page.reload();
			await page.locator('#uxs-root').waitFor();
		});

		/* ===== 3. settings validation ===== */
		await step('smtp settings validation', async () => {
			await goModule('smtp-email');
			await tab('Settings').click();
			await page.locator('#uxs-mailer').waitFor();
			await page.getByRole('tab', { name: 'SMTP', exact: true }).click();
			await page.fill('#uxs-port', '99999');
			await page.fill('#uxs-host', '<b>host.example.test</b>');
			await page.getByRole('button', { name: L.en.save, exact: true }).click();
			await page.waitForTimeout(500);
			c.ok(await page.locator('#uxs-port').evaluate((el) => !el.checkValidity()), 'smtp-email: browser blocks port 99999 (max 65535) and nothing is saved');
			c.ok((await page.locator('.uxs-toast', { hasText: L.en.saved }).count()) === 0, 'smtp-email: no "saved" toast for an invalid port');
			await page.fill('#uxs-port', '587');
			await saveSettings();
			await toast(L.en.saved).waitFor({ timeout: 8000 });
			let v = getSettings().values;
			const tamper = JSON.parse(api('POST', 'core/settings/smtp-email', { port: 99999 }).replace(/^HTTP \d+\s*/, '')).data.values;
			c.ok(tamper.port === 65535, `smtp-email: server clamps a tampered port 99999 to 65535 (got ${tamper.port})`);
			c.ok(!/[<>]/.test(String(v.host)), `smtp-email: HTML is stripped from the host (got ${v.host})`);
			await page.fill('#uxs-port', '');
			c.ok(await page.locator('#uxs-port').evaluate((el) => !el.checkValidity()), 'smtp-email: an empty port is flagged invalid by the form (min 1)');
			// Invalid enumerations are refused by the server (here via the API, a tampered request).
			const r = api('POST', 'core/settings/smtp-email', { mailer: 'carrier-pigeon' });
			c.ok(/422|invalid/i.test(r), 'smtp-email: unknown connection is rejected with 422');
			// Restore the working values through the UI.
			await page.reload();
			await tab('Settings').click();
			await page.getByRole('tab', { name: 'SMTP', exact: true }).click();
			await page.fill('#uxs-host', '127.0.0.1');
			await page.fill('#uxs-port', String(sink.port));
			await saveSettings();
			await toast(L.en.saved).last().waitFor({ timeout: 8000 });
			// Invalid sender address: the server cannot refuse it (free text field); sending must still work and ignore it.
			await page.getByRole('tab', { name: 'General', exact: true }).click();
			await page.fill('#uxs-from_email', 'not-an-email');
			await saveSettings();
			await toast(L.en.saved).last().waitFor({ timeout: 8000 });
			// The text field cannot refuse it, but sending must ignore an unusable From instead of using or breaking on it.
			sink.messages.length = 0;
			await goModule('smtp-email');
			await page.fill('#uxs-smtp-test-to', TO);
			await page.getByRole('button', { name: L.en.send }).click();
			await page.locator('.uxs-notice[role=status]').filter({ hasText: /Sent to/ }).waitFor({ timeout: 20000 });
			const envFrom = mine(TO)[0]?.from ?? '';
			c.ok(mine(TO).length === 1 && envFrom !== 'not-an-email' && /@/.test(envFrom), `smtp-email: an invalid From address in the settings is ignored when sending (envelope From ${envFrom})`);
		});

		/* ===== 4. test e-mail through the UI -> sink + log ===== */
		await step('smtp test email', async () => {
			sink.messages.length = 0;
			sink.creds.length = 0;
			await goModule('smtp-email');
			await page.fill('#uxs-smtp-test-to', 'abc');
			await page.getByRole('button', { name: L.en.send }).click();
			await toast(/valid recipient/i).waitFor({ timeout: 8000 });
			c.ok(/valid recipient/i.test(await toast(/valid recipient/i).innerText()), 'smtp-email: invalid recipient gives a validation toast');
			c.ok(mine(TO).length === 0, 'smtp-email: nothing was sent for the invalid recipient');
			// Fix the From address, then send for real.
			await tab('Settings').click();
			await page.fill('#uxs-from_email', 'e2e-from@example.test');
			await saveSettings();
			await toast(L.en.saved).last().waitFor({ timeout: 8000 });
			await tab('Overview').click();
			await page.fill('#uxs-smtp-test-to', TO);
			// Repeated Enter in the recipient field must send exactly one message.
			const enterPosts = countPosts('smtp-email/test');
			const firstDone = postDone('smtp-email/test');
			for (let i = 0; i < 3; i++) await page.press('#uxs-smtp-test-to', 'Enter');
			await firstDone;
			await page.locator('.uxs-notice[role=status]').filter({ hasText: /Sent to/ }).waitFor({ timeout: 20000 });
			await page.waitForTimeout(700);
			c.ok(enterPosts.n() === 1, `smtp-email: pressing Enter three times sends one request (${enterPosts.n()})`);
			enterPosts.stop();
			const note = await page.locator('.uxs-notice[role=status]').innerText();
			c.ok(/through SMTP server/.test(note), `smtp-email: result says it went through the SMTP server (${note.split('\n')[0]})`);
c.ok(mine(TO).length === 1, `smtp-email: the SMTP sink received exactly one test message (${mine(TO).length})`);
			c.ok(sink.creds.length >= 1 && sink.creds.every((x) => x.user === 'e2euser' && x.pass === PW), 'smtp-email: SMTP login used the decrypted stored password');
			c.ok(mine(TO)[0]?.from === 'e2e-from@example.test', `smtp-email: envelope From is the configured override (${mine(TO)[0]?.from})`);
			const row = sql(db, `SELECT status,mailer,source FROM jos_uxstudio_email_log WHERE to_email='${TO}' ORDER BY id DESC LIMIT 1`).split('\t');
			c.ok(row[0] === 'success' && row[1] === 'smtp', `smtp-email: the test mail is in the e-mail log as success/smtp (${row.join('|')})`);
			c.ok(/UX1 Studio: SMTP e-mail/.test(row[2] ?? ''), `smtp-email: log source names the module (${row[2]})`);
			await shot(page, 'smtp-test-sent');
		});

		/* ===== 5. failing login -> error display; fallback ===== */
		await step('smtp failure and fallback', async () => {
			sink.rejectAuth = true;
			sink.messages.length = 0;
			await goModule('smtp-email');
			await page.fill('#uxs-smtp-test-to', TO);
			await page.getByRole('button', { name: L.en.send }).click();
			await page.locator('.uxs-notice.is-error').waitFor({ timeout: 20000 });
			const t = await page.locator('.uxs-notice.is-error').innerText();
			c.ok(/failed/i.test(t), `smtp-email: refused login shows a failure notice (${t.replace(/\s+/g, ' ').slice(0, 120)})`);
			const row = sql(db, `SELECT status,error_message FROM jos_uxstudio_email_log WHERE to_email='${TO}' ORDER BY id DESC LIMIT 1`).split('\t');
			c.ok(row[0] === 'error' && (row[1] || '') !== '', `smtp-email: failure recorded in the log with an error message (${row[0]})`);
			await shot(page, 'smtp-test-failed');
			// Fallback: Brevo (fake key) first, SMTP second; SMTP now works again.
			sink.rejectAuth = false;
			await tab('Settings').click();
			await page.selectOption('#uxs-mailer', 'brevo');
			await page.selectOption('#uxs-fallback_mailer', 'smtp');
			await page.getByRole('tab', { name: 'Brevo API', exact: true }).click();
			await page.fill('#uxs-brevo_api_key', 'xkeysib-e2e-not-a-real-key');
			await saveSettings();
			await toast(L.en.saved).last().waitFor({ timeout: 8000 });
			await tab('Overview').click();
			await page.getByRole('button', { name: 'Verify Brevo key' }).click();
			await page.locator('.uxs-actions .uxs-badge').filter({ hasText: /Brevo key/ }).waitFor({ timeout: 30000 });
			const brevoBadge = await page.locator('.uxs-actions .uxs-badge').filter({ hasText: /Brevo key/ }).innerText();
			c.ok(/rejected/i.test(brevoBadge) && /danger/.test(await page.locator('.uxs-actions .uxs-badge').filter({ hasText: /Brevo key/ }).getAttribute('class')), `smtp-email: "Verify Brevo key" reports the fake key as rejected (${brevoBadge.slice(0, 80)})`);
			sink.messages.length = 0;
			await page.fill('#uxs-smtp-test-to', TO);
			await page.getByRole('button', { name: L.en.send }).click();
			await page.locator('.uxs-notice[role=status]').filter({ hasText: /Sent to/ }).waitFor({ timeout: 40000 });
			c.ok(/SMTP server \(fallback\)/.test(await page.locator('.uxs-notice[role=status]').innerText()), 'smtp-email: the fallback connection delivered after Brevo failed (attempts list shows both)');
			c.ok(mine(TO).length === 1, 'smtp-email: SMTP sink got the message via the fallback');
			const attempts = sql(db, `SELECT attempts FROM jos_uxstudio_email_log WHERE to_email='${TO}' ORDER BY id DESC LIMIT 1`);
			c.ok(JSON.parse(attempts).length === 2, 'smtp-email: log row records both attempts');
			// "Send through" one chosen connection only.
			await page.selectOption('#uxs-smtp-test-via', 'brevo');
			sink.messages.length = 0;
			const brevoPosts = countPosts('smtp-email/test');
			const brevoDone = postDone('smtp-email/test', 40000);
			await page.getByRole('button', { name: L.en.send }).dblclick();
			await brevoDone;
			await page.locator('.uxs-notice.is-error').waitFor({ timeout: 40000 });
			await page.waitForTimeout(700);
			c.ok(brevoPosts.n() === 1, `smtp-email: a double click on "Send test e-mail" sends one request (${brevoPosts.n()})`);
			brevoPosts.stop();
			const lastTry = JSON.parse(sql(db, `SELECT attempts FROM jos_uxstudio_email_log WHERE to_email='${TO}' ORDER BY id DESC LIMIT 1`));
			c.ok(mine(TO).length === 0 && lastTry.length === 1 && lastTry[0].mailer === 'brevo' && lastTry[0].ok === false, `smtp-email: "Brevo API only" does not use the fallback (sink got ${mine(TO).length} messages, ${lastTry.length} attempt)`);
			// Back to SMTP only.
			await tab('Settings').click();
			await page.selectOption('#uxs-mailer', 'smtp');
			await page.selectOption('#uxs-fallback_mailer', 'none');
			await saveSettings();
			await toast(L.en.saved).last().waitFor({ timeout: 8000 });
		});

		/* ===== 6. password reset on the public site (anonymous) ===== */
		await step('password reset on frontend', async () => {
			const pwHash = sql(db, "SELECT password FROM jos_users LIMIT 1");
			void pwHash;
			sql(db, `DELETE FROM jos_users WHERE username='e2e_mail_user'`);
			sql(db, `INSERT INTO jos_users (name,username,email,password,block,sendEmail,registerDate,params,activation,otpKey,otep,requireReset) VALUES ('E2E Mail','e2e_mail_user','${RESET_MAIL}','x',0,0,NOW(),'{}','','','',0)`);
			const uid = sql(db, "SELECT id FROM jos_users WHERE username='e2e_mail_user'");
			sql(db, `INSERT INTO jos_user_usergroup_map (user_id,group_id) VALUES (${uid},2)`);
			userCreated = true;
			sink.messages.length = 0;
			const anon = await ctx.browser().newContext({ viewport: { width: 1200, height: 900 } });
			const ap = await anon.newPage();
			const aIssues = [];
			ap.on('console', (m) => ['error'].includes(m.type()) && aIssues.push(m.text().slice(0, 200)));
			ap.on('pageerror', (e) => aIssues.push(e.message.slice(0, 200)));
			await ap.goto(`${url}/index.php?option=com_users&view=reset`);
			await ap.fill('#jform_email', RESET_MAIL);
			const submit = ap.locator('form#user-registration button[type=submit]');
			await ap.screenshot({ path: `${SHOTS}email-${site.key}-reset-form.png`, fullPage: true });
			await Promise.all([ap.waitForLoadState('load'), submit.click({ timeout: 8000 }).catch(() => ap.locator('form#user-registration').evaluate((f) => f.requestSubmit()))]);
			await ap.screenshot({ path: `${SHOTS}email-${site.key}-reset-confirm.png`, fullPage: true });
			c.ok(/layout=confirm|confirm/.test(ap.url()) || (await ap.locator('#token').count()) > 0, `frontend: reset request leads to the confirmation step (${ap.url().slice(-60)})`);
			c.ok(mine(RESET_MAIL).length === 1, 'frontend: the reset e-mail was handed to the SMTP sink (configured connection)');
			const txt = mailText(mine(RESET_MAIL)[0]?.raw ?? '');
			const tokenInMail = /[?&;]token=([a-f0-9]{32,})/i.exec(txt) || /confirm[^\n]*\n[^\n]*([a-f0-9]{32,})/i.exec(txt);
			c.ok(/reset|password/i.test(txt), 'frontend: mail text is a password reset message');
			const row = sql(db, `SELECT id,status,source,LENGTH(message),mailer,subject FROM jos_uxstudio_email_log WHERE to_email='${RESET_MAIL}' ORDER BY id DESC LIMIT 1`).split('\t');
			c.ok(row[1] === 'success' && row[2] === 'component: com_users', `email-log: reset mail logged as success from component: com_users (${row.slice(1, 3).join('|')})`);
			c.ok(row[3] === '0', `email-log: the reset mail body is NOT stored (masked) (length ${row[3]})`);
			const dbText = sql(db, `SELECT CONCAT(message,headers,meta) FROM jos_uxstudio_email_log WHERE to_email='${RESET_MAIL}' ORDER BY id DESC LIMIT 1`);
			c.ok(!/[a-f0-9]{32}/i.test(dbText.replace(/\s/g, '')) && !(tokenInMail && dbText.includes(tokenInMail[1])), 'email-log: no reset token anywhere in the logged row');
			c.ok(aIssues.length === 0, `frontend: no console errors on the reset pages ${aIssues.join(' / ')}`);
			// An anonymous visitor must not reach the admin API.
			const r = await anon.request.get(`${url}/administrator/index.php?option=com_uxstudio&task=api.dispatch&format=json&route=email-log/entries`);
			c.ok(r.status() >= 400 || !/"items"/.test(await r.text()), `frontend: anonymous visitor cannot read the e-mail log API (HTTP ${r.status()})`);
			await anon.close();
		});

		/* ===== 7. email-log UI ===== */
		await step('email-log UI', async () => {
			// An HTML message with a remote image, directly in the table (stored body), to exercise preview + resend.
			const meta = JSON.stringify({ from: { email: 'e2e-from@example.test', name: 'E2E Sender' }, to: [{ email: HTML_TO, name: '' }], cc: [], bcc: [], reply_to: [], html: true, alt_body: '', body_masked: false });
			sql(db, `INSERT INTO jos_uxstudio_email_log (created,to_email,from_email,subject,status,source,message,headers,attachments,meta,mailer) VALUES (NOW(),'${HTML_TO}','e2e-from@example.test','E2E HTML message','success','plugin: e2e/test','<h1 style="color:#c00">Hello preview</h1><img src="https://example.invalid/pixel.png" alt="px"><p>Body text</p>','From: E2E <e2e-from@example.test>\nTo: ${HTML_TO}','["a.pdf"]','${esc(meta)}','smtp')`);
			sql(db, `INSERT INTO jos_uxstudio_email_log (created,to_email,from_email,subject,status,source,message,headers,attachments,meta,mailer,error_message) VALUES (NOW(),'${HTML_TO}','e2e-from@example.test','E2E failed message','error','Joomla','','From: x','[]','{}','smtp','Could not connect to host')`);
			const LONG_ERR = 'SMTP Error: Could not authenticate. ' + 'The server answered with a very long diagnostic text that must not blow up the table row. '.repeat(5) + 'END-OF-ERROR';
			sql(db, `INSERT INTO jos_uxstudio_email_log (created,to_email,from_email,subject,status,source,message,headers,attachments,meta,mailer,error_message) VALUES (NOW(),'${LONG_TO}','e2e-from@example.test','E2E long error','error','Joomla','','From: x','[]','{}','smtp','${LONG_ERR}')`);
			await goModule('email-log');
			await page.locator('.uxs-cards').first().waitFor();
			c.ok(await page.locator('.uxs-card').count() >= 3, 'email-log: statistics cards shown');
			await page.locator('.uxs-table tbody tr').first().waitFor();
			c.ok(await page.locator('.uxs-table tbody tr').count() >= 3, 'email-log: list shows messages');
			await shot(page, 'log-light');
			// search and empty state
			await page.fill('input[type=search]', 'zzz-no-such-mail-zzz');
			await page.getByRole('button', { name: 'Search', exact: true }).click();
			await page.locator('td.is-empty').waitFor();
			const emptyMsg = (await page.locator('td.is-empty').innerText()).trim();
			c.ok(/No messages match the search/.test(emptyMsg), `email-log: a search without results says so (not "no mail sent"): "${emptyMsg}"`);
			await page.fill('input[type=search]', 'E2E HTML');
			await page.getByRole('button', { name: 'Search', exact: true }).click();
			await page.locator('.uxs-table tbody tr', { hasText: 'E2E HTML message' }).waitFor();
			c.ok((await page.locator('.uxs-table tbody tr').count()) === 1, 'email-log: search by subject narrows the list to one row');
			// status filter
			await page.fill('input[type=search]', '');
			await page.getByRole('button', { name: 'Search', exact: true }).click();
			await page.selectOption('select[aria-label=Status]', 'error');
			await page.locator('.uxs-table tbody tr', { hasText: 'E2E failed message' }).waitFor();
			const rowsErr = await page.locator('.uxs-table tbody tr .uxs-badge').allInnerTexts();
			c.ok(rowsErr.length > 0 && rowsErr.every((x) => /Failed/.test(x)), 'email-log: status filter "Failed" shows only failed messages');
			c.ok(/Could not connect to host/.test(await page.locator('.uxs-table').innerText()), 'email-log: error text visible in the list');
			await page.selectOption('select[aria-label=Status]', '');
			// pagination (the site log has > 20 rows normally; skip the check when it does not)
			const total = Number(sql(db, 'SELECT COUNT(*) FROM jos_uxstudio_email_log'));
			if (total > 20) {
				await page.getByRole('button', { name: 'Next' }).click();
				await page.getByText(/Page 2 of/).waitFor();
				c.ok(true, 'email-log: pagination moves to page 2');
				await page.getByRole('button', { name: 'Previous' }).click();
				await page.getByText(/Page 1 of/).waitFor();
			}
			// detail of the HTML message
			await page.fill('input[type=search]', 'E2E HTML');
			await page.getByRole('button', { name: 'Search', exact: true }).click();
			// the subject looks and behaves like a link: it reacts to the pointer
			const subjBtn = page.getByRole('button', { name: 'E2E HTML message' });
			const styleOf = () => subjBtn.evaluate((el) => { const s = getComputedStyle(el); return `${s.color}|${s.backgroundColor}|${s.textDecorationLine}`; });
			await page.mouse.move(2, 2);
			const idle = await styleOf();
			await subjBtn.hover();
			await page.waitForTimeout(250);
			c.ok((await styleOf()) !== idle, `email-log: the subject link changes its look on hover (${idle} -> ${await styleOf()})`);
			await subjBtn.click();
			const dlg = page.locator('.uxs-modal');
			await dlg.waitFor();
			await dlg.getByText('Headers').waitFor();
			const frame = dlg.locator('iframe');
			await frame.waitFor();
			c.ok((await frame.getAttribute('sandbox')) === '', 'email-log: preview iframe is fully sandboxed');
			const srcdoc = await frame.getAttribute('srcdoc');
			const cspImg = (/Content-Security-Policy"\s+content="[^"]*img-src ([^";]*)/.exec(srcdoc) || [])[1] ?? 'MISSING';
			c.ok(cspImg.trim() === 'data:', `email-log: remote images are blocked in the preview by default (CSP img-src "${cspImg}")`);
			c.ok((await frame.getAttribute('tabindex')) === '-1', 'email-log: the preview iframe is not in the tab order (keyboard focus cannot get trapped in it)');
			c.ok(await dlg.getByText('a.pdf').count() === 1, 'email-log: attachment name listed');
			await page.frameLocator('.uxs-modal iframe').locator('h1').waitFor();
			c.ok((await page.frameLocator('.uxs-modal iframe').locator('h1').innerText()) === 'Hello preview', 'email-log: HTML body is rendered in the preview');
			await dlg.getByLabel(/Load remote images/).check();
			c.ok(/img-src data: https: http:/.test((await frame.getAttribute('srcdoc')) ?? ''), 'email-log: checkbox allows remote images');
			await shot(page, 'log-detail');
			// resend: cancel, then confirm
			sink.messages.length = 0;
			await dlg.getByRole('button', { name: L.en.resend }).click();
			await confirmDialogIn(page, false);
			await page.waitForTimeout(800);
			c.ok((await page.locator('.uxs-modal').count()) === 1 && mine(HTML_TO).length === 0, 'email-log: cancelling the resend confirmation closes only the question and sends nothing');
			await dlg.getByRole('button', { name: L.en.resend }).click();
			await confirmDialogIn(page, true);
			await toast('sent again').waitFor({ timeout: 20000 });

			c.ok(mine(HTML_TO).length === 1, 'email-log: resend delivered to the original recipient through the SMTP connection');
			c.ok(/Hello preview/.test(mailText(mine(HTML_TO)[0]?.raw ?? '')) && /text\/html/i.test(mine(HTML_TO)[0]?.raw ?? ''), 'email-log: the resent message is HTML with the stored body');
			const resent = sql(db, `SELECT source,status FROM jos_uxstudio_email_log WHERE to_email='${HTML_TO}' AND source LIKE '%resend%' ORDER BY id DESC LIMIT 1`).split('\t');
			c.ok(/resend of #\d+/.test(resent[0] ?? '') && resent[1] === 'success', `email-log: resend creates a new row with source "${resent[0]}"`);
			await dlg.getByRole('button', { name: L.en.close }).click();
			await dlg.waitFor({ state: 'detached' });
			// keyboard: Tab through the detail, then Escape still closes it
			await page.getByRole('button', { name: 'E2E HTML message' }).first().click();
			await dlg.waitFor();
			await dlg.getByText('Headers').waitFor();
			for (let i = 0; i < 8; i++) await page.keyboard.press('Tab');
			await page.keyboard.press('Escape');
			await dlg.waitFor({ state: 'detached', timeout: 5000 }).catch(() => {});
			c.ok((await page.locator('.uxs-modal').count()) === 0, 'email-log: Escape closes the detail after tabbing through it');
			if (await page.locator('.uxs-modal').count()) await page.locator('.uxs-modal').getByRole('button', { name: L.en.close }).click();
			// detail of the masked reset mail
			await page.fill('input[type=search]', RESET_MAIL);
			await page.getByRole('button', { name: 'Search', exact: true }).click();
			await page.locator('.uxs-table tbody tr', { hasText: RESET_MAIL }).first().waitFor();
			await page.locator('.uxs-table tbody tr', { hasText: RESET_MAIL }).first().getByRole('button').first().click();
			await dlg.waitFor();
			await dlg.getByText(L.en.notStored).waitFor();
			c.ok(await dlg.getByRole('button', { name: L.en.resend }).isDisabled(), 'email-log: masked reset mail cannot be resent (button disabled)');
			c.ok(/Component: com_users/.test(await dlg.innerText()), 'email-log: source shown as "Component: com_users"');
			await shot(page, 'log-detail-masked');
			await dlg.getByRole('button', { name: L.en.close }).click();
			await dlg.waitFor({ state: 'detached' });
			// delete one entry (cancel first)
			await page.fill('input[type=search]', 'E2E failed');
			await page.getByRole('button', { name: 'Search', exact: true }).click();
			await page.locator('.uxs-table tbody tr', { hasText: 'E2E failed message' }).waitFor();
			await page.getByRole('button', { name: 'Delete entry' }).click();
			await confirmDialogIn(page, false);
			c.ok(sql(db, "SELECT COUNT(*) FROM jos_uxstudio_email_log WHERE subject='E2E failed message'") === '1', 'email-log: cancelling delete keeps the entry');
			await page.getByRole('button', { name: 'Delete entry' }).click();
			await confirmDialogIn(page, true);
			await page.locator('td.is-empty').waitFor();
			c.ok(sql(db, "SELECT COUNT(*) FROM jos_uxstudio_email_log WHERE subject='E2E failed message'") === '0', 'email-log: the entry is deleted');
			// a very long error text is shortened in the list and complete in the detail
			await page.fill('input[type=search]', 'E2E long');
			await page.getByRole('button', { name: 'Search', exact: true }).click();
			const longRow = page.locator('.uxs-table tbody tr', { hasText: 'E2E long error' });
			await longRow.waitFor();
			const rowH = (await longRow.boundingBox()).height;
			c.ok(rowH < 170 && !(await longRow.innerText()).includes('END-OF-ERROR'), `email-log: a ~500 character error does not stretch its list row (${Math.round(rowH)} px)`);
			await shot(page, 'log-long-error');
			await longRow.getByRole('button', { name: 'E2E long error' }).click();
			await dlg.getByText('Headers').waitFor();
			c.ok((await dlg.innerText()).includes('END-OF-ERROR'), 'email-log: the complete error text is in the detail');
			await dlg.getByRole('button', { name: L.en.close }).click();
			await dlg.waitFor({ state: 'detached' });
			// export CSV
			await page.fill('input[type=search]', '');
			await page.getByRole('button', { name: 'Search', exact: true }).click();
			await page.locator('.uxs-table tbody tr').first().waitFor();
			const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 15000 }), page.getByRole('button', { name: 'Export CSV' }).click()]);
			const csv = fs.readFileSync(await dl.path(), 'utf8');
			c.ok(/^\uFEFF"?Date \(UTC\)"?,To,From,Subject/.test(csv) && csv.includes(HTML_TO), `email-log: CSV export downloaded (${dl.suggestedFilename()}; head ${JSON.stringify(csv.slice(0, 60))}, has recipient ${csv.includes(HTML_TO)})`);
			// refresh button reloads the list from the server
			const refreshed = page.waitForResponse((rs) => rs.url().includes('route=email-log/entries') && rs.url().includes('per_page') && rs.request().method() === 'GET', { timeout: 10000 });
			await page.getByRole('button', { name: 'Refresh' }).click();
			c.ok((await refreshed).status() === 200, 'email-log: Refresh requests the list again');
			// clear all: cancel, then confirm (rows are restored from the backup table afterwards)
			await page.getByRole('button', { name: 'Clear all' }).click();
			await confirmDialogIn(page, false);
			c.ok(Number(sql(db, 'SELECT COUNT(*) FROM jos_uxstudio_email_log')) > 0, 'email-log: cancelling "Clear all" keeps the log');
			// The real thing: TRUNCATE removes every row of the (shared) log, so snapshot right before and restore right after.
			snapLog('1=1');
			try {
				await page.getByRole('button', { name: 'Clear all' }).click();
				await confirmDialogIn(page, true);
				// other agents may log mail at any moment: judge by OUR snapshot rows, not by "the table is empty"
				const survivors = () => Number(sql(db, 'SELECT COUNT(*) FROM jos_uxstudio_email_log l JOIN jos_uxs_e2e_email_log_bak b ON l.id=b.id AND l.created=b.created AND l.subject<=>b.subject AND l.to_email<=>b.to_email'));
				const deadline = Date.now() + 10000;
				while (survivors() > 0 && Date.now() < deadline) await page.waitForTimeout(250);
				c.ok(survivors() === 0, 'email-log: "Clear all" removed every logged message');
				await page.waitForFunction(() => !document.body.innerText.includes('E2E HTML message'), null, { timeout: 10000 });
				c.ok(true, 'email-log: the list no longer shows the cleared messages');
				if (sql(db, 'SELECT COUNT(*) FROM jos_uxstudio_email_log') === '0') {
					await page.locator('td.is-empty').waitFor({ timeout: 5000 });
					c.ok(await page.getByRole('button', { name: 'Clear all' }).isDisabled(), 'email-log: "Clear all" is disabled on an empty log');
					c.ok(/No mail has been sent yet/.test(await page.locator('td.is-empty').innerText()), 'email-log: empty-log message shown');
					await shot(page, 'log-empty');
				} else console.log('  info: another process logged mail meanwhile, skipping the empty-state assertions');
			} finally {
				unsnapLog();
			}
		});

		/* ===== 8. email-log settings: store_body off, retention ===== */
		await step('email-log settings and retention', async () => {
			await goModule('email-log');
			await tab('Settings').click();
			await page.locator('#uxs-retention_days').waitFor();
			await page.fill('#uxs-retention_days', '-5');
			c.ok(await page.locator('#uxs-retention_days').evaluate((el) => !el.checkValidity()), 'email-log: a negative retention is flagged invalid by the form');
			let v = JSON.parse(api('POST', 'core/settings/email-log', { retention_days: -5 }).replace(/^HTTP \d+\s*/, '')).data.values;
			c.ok(v.retention_days === 0, `email-log: server clamps a tampered negative retention to 0 (got ${v.retention_days})`);
			await page.fill('#uxs-retention_days', '1');
			await page.locator('.uxs-switch').first().waitFor();
			// turn "Store the message text" off through its switch
			const sw = page.getByRole('button', { name: 'Store the message text', exact: true });
			await sw.click();
			await saveSettings();
			await toast(L.en.saved).last().waitFor({ timeout: 8000 });
			v = JSON.parse(api('GET', 'core/settings/email-log').replace(/^HTTP \d+\s*/, '')).data.values;
			c.ok(v.retention_days === 1 && v.store_body === false, 'email-log: retention 1 day and "store text" off saved');
			// A new test mail is logged without its text.
			await goModule('smtp-email');
			await page.fill('#uxs-smtp-test-to', TO);
			await page.getByRole('button', { name: L.en.send }).click();
			await page.locator('.uxs-notice[role=status]').filter({ hasText: /Sent to/ }).waitFor({ timeout: 20000 });
			c.ok(sql(db, `SELECT LENGTH(message) FROM jos_uxstudio_email_log WHERE to_email='${TO}' ORDER BY id DESC LIMIT 1`) === '0', 'email-log: with "store text" off the body is not saved');
			// retention: an old row and a fresh row, run the daily core job
			// The job (retention 1 day) deletes every older row of the shared log: park those rows and put them back afterwards.
			snapLog('created < DATE_SUB(NOW(), INTERVAL 1 DAY)');
			sql(db, `INSERT INTO jos_uxstudio_email_log (created,to_email,from_email,subject,status,source,message,headers,attachments,meta) VALUES (DATE_SUB(NOW(),INTERVAL 5 DAY),'${HTML_TO}','a@b.test','E2E old row','success','','x','','[]','{}')`);
			const taskId = sql(db, "SELECT id FROM jos_scheduler_tasks WHERE params LIKE '%core.retention%' ORDER BY id LIMIT 1");
			if (taskId) {
				try {
					execFileSync(PHP, [path.join('D:/xampp/htdocs', site.db, 'cli/joomla.php'), 'scheduler:run', `--id=${taskId}`], { encoding: 'utf8', stdio: 'pipe' });
				} catch (e) {
					console.log('  (scheduler:run output) ' + String(e.stdout || e.message).slice(0, 200));
				}
				c.ok(sql(db, "SELECT COUNT(*) FROM jos_uxstudio_email_log WHERE subject='E2E old row'") === '0', 'email-log: retention job deleted the row older than 1 day');
				c.ok(Number(sql(db, `SELECT COUNT(*) FROM jos_uxstudio_email_log WHERE to_email='${TO}'`)) > 0, 'email-log: retention kept the recent rows');
				} else c.ok(false, 'email-log: core.retention scheduler task not found');
			unsnapLog();
		});

		/* ===== 9. email-health ===== */
		await step('email-health', async () => {
			clearRate();
			setSettings('email-log', { retention_days: 30, store_body: true, mask_sensitive: true, detect_source: true });
			await goModule('email-health');
			await page.locator('.uxs-card', { hasText: 'Sending test' }).waitFor();
			c.ok((await page.locator('.uxs-card', { hasText: 'Sender domain DNS' }).first().innerText()).length > 10 && (await page.locator('.uxs-card', { hasText: 'Sender address' }).first().innerText()).includes('@'), 'email-health: initial overview shows the three status cards');
			await shot(page, 'health-overview-light');
			// invalid recipient -> validation toast (also counts against the rate limit)
			await page.fill('input[aria-label=Recipient]', 'nonsense');
			await page.getByRole('button', { name: L.en.send }).click();
			await toast(/valid|recipient/i).waitFor({ timeout: 8000 });
			c.ok(/valid/i.test(await toast(/valid|recipient/i).innerText()), 'email-health: invalid recipient gives a validation toast');
			// real test to the configured settings recipient (blank field)
			sink.messages.length = 0;
			await page.fill('input[aria-label=Recipient]', '');
			const healthPosts = countPosts('email-health/test');
			await page.getByRole('button', { name: L.en.send }).dblclick();
			await page.locator('.uxs-notice[role=status]').filter({ hasText: /test e-mail to/ }).waitFor({ timeout: 20000 });
			await page.waitForTimeout(700);
			c.ok(healthPosts.n() === 1, `email-health: a double click on "Send test e-mail" sends one request (${healthPosts.n()})`);
			healthPosts.stop();
			const txt = await page.locator('.uxs-notice[role=status]').innerText();
			c.ok(/was sent \(smtp \(127\.0\.0\.1\)\)/.test(txt), `email-health: test e-mail sent via the configured SMTP (${txt.split('\n')[0].slice(0, 90)})`);
			c.ok(mine(HEALTH_TO).length === 1, `email-health: exactly one message went to the recipient from the module settings (${mine(HEALTH_TO).length})`);
			c.ok(Number(sql(db, `SELECT COUNT(*) FROM jos_uxstudio_email_log WHERE to_email='${HEALTH_TO}' AND source='UX1 Studio: E-mail health' AND status='success'`)) >= 1, 'email-health: the test mail appears in the e-mail log');
			await page.locator('.uxs-card', { hasText: 'Sending test' }).getByText('Works').waitFor();
			c.ok((await page.locator('.uxs-table tbody tr').count()) >= 1 && !(await page.locator('td.is-empty').count()), 'email-health: test history table has a row');
			// failing test: SMTP login refused
			sink.rejectAuth = true;
			await page.getByRole('button', { name: L.en.send }).click();
			await page.locator('.uxs-notice.is-error').waitFor({ timeout: 20000 });
			c.ok(true, 'email-health: a refused SMTP login is reported as a failed test');
			await page.locator('.uxs-card', { hasText: 'Sending test' }).getByText('Fails').waitFor();
			sink.rejectAuth = false;
			// invalid domain
			await page.fill('input[aria-label=Domain]', 'not a domain!!');
			await page.getByRole('button', { name: L.en.checkDns }).click();
			await toast(/valid domain/i).waitFor({ timeout: 8000 });
			c.ok(true, 'email-health: an invalid domain gives a "valid domain" validation toast');
			// DNS check of a nonexistent domain: wait for THIS answer (older results on the page must not satisfy the wait)
			await page.fill('input[aria-label=Domain]', 'e2e-nonexistent-domain-xyz.test');
			const nxDone = postDone('email-health/dns', 90000);
			await page.getByRole('button', { name: L.en.checkDns }).click();
			c.ok((await nxDone).status() === 200, 'email-health: the DNS check of a nonexistent domain is answered normally');
			await page.locator('.uxs-cards .uxs-card', { hasText: 'Sender domain DNS' }).getByText(/e2e-nonexistent-domain-xyz\.test/).waitFor({ timeout: 20000 });
			const names = await page.locator('.uxs-groups .uxs-card strong').allInnerTexts();
			c.ok(['MX (receiving mail)', 'SPF', 'DKIM', 'DMARC'].every((n) => names.includes(n)), `email-health: all four DNS checks are shown (${names.join(', ')})`);
			c.ok((await page.locator('.uxs-groups .uxs-card .uxs-badge').count()) === 4, 'email-health: each DNS check has a status badge');
			const mxBadge = await page.locator('.uxs-groups .uxs-card', { hasText: 'MX (receiving mail)' }).locator('.uxs-badge').first().innerText();
			c.ok(!/In order/.test(mxBadge), `email-health: a nonexistent domain has no healthy MX verdict (${mxBadge})`);
			await shot(page, 'health-dns-light');
			// IDN: an internationalised name is looked up as punycode, not refused
			await page.fill('input[aria-label=Domain]', 'háček.cz');
			const idnDone = postDone('email-health/dns', 90000);
			await page.getByRole('button', { name: L.en.checkDns }).click();
			c.ok((await idnDone).status() === 200, 'email-health: an IDN domain (háček.cz) is accepted by the DNS check');
			await page.locator('.uxs-cards .uxs-card', { hasText: 'Sender domain DNS' }).getByText(/xn--hek-ela4t\.cz/).waitFor({ timeout: 20000 });
			c.ok(true, 'email-health: the IDN domain is shown in its punycode form (xn--hek-ela4t.cz)');
			// Enter in the domain field runs the check; a double click on the button runs it once
			await page.fill('input[aria-label=Domain]', 'gmail.com');
			const enterDone = postDone('email-health/dns', 90000);
			await page.press('input[aria-label=Domain]', 'Enter');
			c.ok((await enterDone).status() === 200, 'email-health: Enter in the Domain field starts the DNS check');
			await page.locator('.uxs-cards .uxs-card', { hasText: 'Sender domain DNS' }).getByText(/gmail\.com/).waitFor({ timeout: 20000 });
			const dnsPosts = countPosts('email-health/dns');
			const dblDone = postDone('email-health/dns', 90000);
			await page.getByRole('button', { name: L.en.checkDns }).dblclick();
			await dblDone;
			await page.waitForTimeout(700);
			c.ok(dnsPosts.n() === 1, `email-health: a double click on "Check DNS" sends one request (${dnsPosts.n()})`);
			dnsPosts.stop();
			const dnsText = await page.locator('.uxs-groups').innerText();
			c.ok(/v=spf1/.test(dnsText) && /v=DMARC1/.test(dnsText), 'email-health: SPF and DMARC records of gmail.com are displayed');
			// layout of the result cards: left aligned text, the sender address stays inside its card
			const lay = await page.evaluate(() => {
				const p = document.querySelector('.uxs-groups .uxs-card p:nth-of-type(2)');
				const li = document.querySelector('.uxs-groups .uxs-card li');
				const send = [...document.querySelectorAll('.uxs-cards .uxs-card')].find((x) => x.textContent.includes('Sender address'));
				const num = send?.querySelector('.uxs-card__num');
				return { align: p ? getComputedStyle(p).textAlign : 'none', liAlign: li ? getComputedStyle(li).textAlign : 'left', fits: num ? num.scrollWidth <= num.clientWidth + 1 : false, size: num ? parseFloat(getComputedStyle(num).fontSize) : 99 };
			});
			c.ok(['left', 'start'].includes(lay.align) && ['left', 'start'].includes(lay.liAlign), `email-health: DNS card text is left aligned (${lay.align}/${lay.liAlign})`);
			c.ok(lay.fits && lay.size <= 20, `email-health: the sender address fits its card in a normal size (${lay.size}px, fits ${lay.fits})`);
			// rate limit: 5 test runs per 10 min; clicking with an invalid address counts and finally yields 429
			clearRate();
			await page.fill('input[aria-label=Recipient]', 'nonsense');
			let limited = false;
			for (let i = 0; i < 7 && !limited; i++) {
				await page.getByRole('button', { name: L.en.send }).click();
				await page.waitForTimeout(250);
				limited = (await page.locator('.uxs-toast', { hasText: /Too many|wait|rate|seconds/i }).count()) > 0;
			}
			c.ok(limited, 'email-health: the test endpoint is rate limited (429 toast after repeated clicks)');
			clearRate();
			// settings tab: show_if interval, save, validation
			await tab('Settings').click();
			await page.locator('#uxs-test_recipient').waitFor();
			c.ok(await page.locator('#uxs-test_interval').isHidden(), 'email-health: interval select is hidden while automatic tests are off');
			await page.getByRole('button', { name: 'Scheduled test', exact: true }).first().click();
			await page.locator('#uxs-test_interval').waitFor({ state: 'visible' });
			c.ok(true, 'email-health: interval appears once automatic tests are switched on');
			await page.getByRole('tab', { name: 'DNS', exact: false }).first().click().catch(() => {});
			await saveSettings();
			await toast(L.en.saved).last().waitFor({ timeout: 8000 });
			// the Central App feed is the public face of the result
			const feed = api('GET', 'email-health/status');
			c.ok(/"success": (true|false)/.test(feed) && /"overall"/.test(feed), 'email-health: status API returns the last test and the DNS result');
			// Nothing below needs the sink: give the site its own mail settings back (they are global for every other module).
			restoreSettings(bak, ['smtp-email']);
		});

		/* ===== 10. dark mode + contrast, screenshots ===== */
		await step('dark mode', async () => {

			for (const id of MODULES) {
				for (const theme of ['dark', 'light']) {
					await goModule(id);
					await setTheme(theme);
					await page.waitForTimeout(300);
					const off = (await lowContrast(page)).filter((x) => !CORE_OK.test(x));
					c.ok(off.length === 0, `${id}: ${theme} mode has no low-contrast text${off.length ? ' -> ' + off.slice(0, 4).join(' ; ') : ''}`);
					if (theme === 'dark') await shot(page, `${id}-dark`);
					if (id !== 'smtp-email') {
						await tab('Settings').click();
						await page.waitForTimeout(400);
						const off2 = (await lowContrast(page)).filter((x) => !CORE_OK.test(x));
						c.ok(off2.length === 0, `${id}: ${theme} mode settings tab has no low-contrast text${off2.length ? ' -> ' + off2.slice(0, 4).join(' ; ') : ''}`);
					}
				}
			}
			// dark detail modal
			setSettings('email-log', { retention_days: 30, store_body: true, mask_sensitive: true, detect_source: true });
			sql(db, `INSERT INTO jos_uxstudio_email_log (created,to_email,from_email,subject,status,source,message,headers,attachments,meta,mailer) VALUES (NOW(),'${HTML_TO}','e2e-from@example.test','E2E dark modal','success','plugin: e2e/test','plain text body','From: x','[]','{"html":false,"to":[{"email":"${HTML_TO}","name":""}]}','smtp')`);
			await page.reload();
			await goModule('email-log');
			await setTheme('dark');
			await page.getByRole('button', { name: 'E2E dark modal' }).click();
			await page.locator('.uxs-modal').getByText('Headers').waitFor();
			const off = (await lowContrast(page)).filter((x) => !CORE_OK.test(x));
			c.ok(off.length === 0, `email-log: dark detail modal has no low-contrast text${off.length ? ' -> ' + off.slice(0, 4).join(' ; ') : ''}`);
			await shot(page, 'log-detail-dark');
			await page.keyboard.press('Escape');
			await page.locator('.uxs-modal').waitFor({ state: 'detached' });
			c.ok(true, 'email-log: Escape closes the detail modal');
			await setTheme('light');
		});

		/* ===== 11. Czech administration ===== */
		await step('czech UI', async () => {
			await page.close();
			await ctx.close();
			ctx = (await context(browser)).ctx; // fresh session: the login form carries the language choice
			page = await login(ctx, site, 'cs-CZ');
			const paramsNow = sql(db, "SELECT params FROM jos_users WHERE username='" + esc(env().JOOMLA_ADMIN_USER) + "'");
			console.log(`  info [${site.key}] admin params after the language login: ${paramsNow === userParams ? 'unchanged' : 'changed (Joomla stores the login language choice); restored at the end'}`);
			await openStudio(page, site, '#/module?id=smtp-email');
			await page.getByRole('tab', { name: 'Přehled' }).waitFor({ timeout: 15000 });
			let txt = await page.locator('#uxs-root').innerText();
			c.ok(/Odeslat zkušební e-mail/.test(txt) && /Spojení/.test(txt), 'czech: smtp-email overview is translated');
			await shot(page, 'smtp-overview-cs');
			await page.fill('#uxs-smtp-test-to', 'abc');
			await page.getByRole('button', { name: L.cs.send }).click();
			await page.locator('.uxs-toast').first().waitFor();
			const tt = await page.locator('.uxs-toast').first().innerText();
			c.ok(/platn|příjemc|e-mail/i.test(tt) && !/Enter a valid/.test(tt), `czech: server validation message is Czech (${tt.slice(0, 70)})`);
			await page.getByRole('tab', { name: 'Nastavení' }).click();
			await page.locator('#uxs-mailer').waitFor();
			txt = await page.locator('#uxs-root').innerText();
			c.ok(/Záložní spojení/.test(txt) && !/Fallback connection/.test(txt), 'czech: smtp-email settings are translated');
			await shot(page, 'smtp-settings-cs');
			await openStudio(page, site, '#/module?id=email-log');
			await page.getByRole('tab', { name: 'Zprávy' }).waitFor({ timeout: 15000 });
			await page.locator('.uxs-table tbody tr').first().waitFor();
			await page.locator('.uxs-card').first().waitFor();
			txt = await page.locator('#uxs-root').innerText();
			c.ok(/Posledních 24 hodin/.test(txt) && /Exportovat CSV/.test(txt) && /Vyčistit vše/.test(txt), 'czech: email-log screen is translated');
			await page.locator('.uxs-table tbody tr').first().getByRole('button').first().click();
			await page.locator('.uxs-modal').getByText('Hlavičky').waitFor();
			const mt = await page.locator('.uxs-modal').innerText();
			c.ok(/Detail e-mailu/.test(mt) && /Hlavičky/.test(mt) && /Zpráva/.test(mt), 'czech: detail modal is translated');
			await shot(page, 'log-detail-cs');
			await page.keyboard.press('Escape');
			await page.getByRole('tab', { name: 'Nastavení' }).click();
			await page.locator('#uxs-retention_days').waitFor();
			txt = await page.locator('#uxs-root').innerText();
			c.ok(/Uchovávání \(dny\)/.test(txt) && !/Retention/.test(txt), 'czech: email-log settings labels come from the Czech language file');
			await openStudio(page, site, '#/module?id=email-health');
			await page.getByRole('tab', { name: 'Přehled' }).waitFor({ timeout: 15000 });
			await page.locator('.uxs-card').first().waitFor();
			txt = await page.locator('#uxs-root').innerText();
			c.ok(/Test odeslání|Test doručení/.test(txt) && /DNS/.test(txt), 'czech: email-health overview is translated');
			await page.fill('input[aria-label=Doména]', 'gmail.com');
			const csDone = postDone('email-health/dns', 90000);
			await page.getByRole('button', { name: 'Zkontrolovat DNS' }).click();
			await csDone;
			await page.locator('.uxs-groups .uxs-card').first().waitFor({ timeout: 20000 });
			const dns = await page.locator('.uxs-groups').innerText();
			c.ok(!/\b(In order|Needs attention|Problem|Info)\b/.test(dns) && /V pořádku|Vyžaduje pozornost|Problém|Informace/.test(dns), 'czech: DNS verdicts are translated');
			c.ok(/Počet poštovních serverů domény: \d+/.test(dns) && !/mail server/i.test(dns), 'czech: the MX summary is a grammatical Czech sentence');
			await shot(page, 'health-dns-cs');
			await tab('Nastavení').click();
			await page.locator('#uxs-test_recipient').waitFor();
			await shot(page, 'health-settings-cs');
		});

		for (const i of pending()) c.ok(false, `browser issue: ${i}`);
		bad += c.done([]);
	} finally {
		try {
			await page?.close();
			await ctx?.close();
		} catch {}
		// ---- restore ----
		restoreSettings(bak);
		unsnapLog();
		sql(db, LOG_CLEANUP);
		sql(db, `DELETE FROM jos_uxstudio_email_health WHERE id>${healthMax}`);
		clearRate();
		if (userCreated) {
			const uid = sql(db, "SELECT id FROM jos_users WHERE username='e2e_mail_user'");
			if (uid) {
				sql(db, `DELETE FROM jos_user_usergroup_map WHERE user_id=${uid}; DELETE FROM jos_users WHERE id=${uid}`);
			}
		}
		sql(db, `DELETE FROM jos_action_logs WHERE message LIKE '%e2e_mail_user%'`);
		sql(db, `UPDATE jos_users SET params='${esc(userParams)}' WHERE username='${esc(env().JOOMLA_ADMIN_USER)}'`);
		fs.rmSync(bakFile, { force: true });
	}
	return bad;
}

async function confirmDialogIn(page, accept) {
	// The confirm dialog is a second modal on top of the detail modal (or the only one).
	const dlgs = page.locator('.uxs-modal');
	await page.waitForFunction(() => document.querySelectorAll('.uxs-modal').length >= 1);
	const dlg = dlgs.last();
	const btn = dlg.locator('.uxs-modal__actions .uxs-btn');
	await btn.nth(accept ? 1 : 0).click();
	await page.waitForTimeout(300);
}

/* ------------------------------------------------------------------ main */

const sink = await startSink();
const browser = await launch();
let bad = 0;
// Static check: every t('...') string of the three screens exists in the Czech dictionary.
{
	const c = checker('email i18n');
	for (const id of MODULES) {
		const src = fs.readFileSync(path.join(ROOT, 'admin-app/src/modules', id, 'Page.tsx'), 'utf8');
		const cs = JSON.parse(fs.readFileSync(path.join(ROOT, 'admin-app/src/modules', id, 'i18n/cs.json'), 'utf8'));
		const core = JSON.parse(fs.readFileSync(path.join(ROOT, 'admin-app/src/app/i18n/cs.json'), 'utf8'));
		const keys = new Set([...src.matchAll(/\bt\(\s*'((?:[^'\\]|\\.)*)'/g)].map((m) => m[1].replace(/\\'/g, "'")));
		for (const m of src.matchAll(/\btn\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'/g)) keys.add(`${m[1]}|${m[2]}`);
		const missing = [...keys].filter((k) => !(k in cs) && !(k in core));
		c.ok(missing.length === 0, `${id}: all ${keys.size} UI strings have a Czech translation${missing.length ? ' (missing: ' + missing.join(' ; ') + ')' : ''}`);
		const ini = (lang) => new Set([...fs.readFileSync(path.join(ROOT, `packages/lib_uxstudio/src/Modules/${{ 'smtp-email': 'SmtpEmail', 'email-log': 'EmailLog', 'email-health': 'EmailHealth' }[id]}/language/${lang}/uxs_${id}.ini`), 'utf8').matchAll(/^([A-Z0-9_]+)=/gm)].map((m) => m[1]));
		const en = ini('en-GB');
		const czk = ini('cs-CZ');
		const diff = [...en].filter((k) => !czk.has(k)).concat([...czk].filter((k) => !en.has(k)));
		c.ok(diff.length === 0, `${id}: en-GB and cs-CZ language files have the same keys${diff.length ? ' (' + diff.join(',') + ')' : ''}`);
	}
	bad += c.done([]);
}
try {
	for (const site of sitesFromArgv()) bad += await runSite(browser, site, sink);
} finally {
	await browser.close();
	sink.server.close();
}
void SITES;
process.exit(bad ? 1 : 0);
