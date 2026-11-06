#!/usr/bin/env node
/**
 * Development linking: after the first install of the package, replace the installed extension folders on the
 * local test sites by junctions into this repository, so every edit is live without reinstalling.
 * Idempotent. New mod_uxstudio_* modules found in packages/ are linked, discovered and installed automatically.
 *
 *   node bin/dev-link.mjs              # both sites
 *   node bin/dev-link.mjs j5           # one site (j5 | j6)
 *   node bin/dev-link.mjs --unlink     # (not implemented on purpose: reinstall the zip to get plain copies back)
 */
import { spawnSync } from 'node:child_process';
import { existsSync, lstatSync, mkdirSync, readFileSync, readdirSync, readlinkSync, rmSync, rmdirSync, statSync, symlinkSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const PACKAGES = join(ROOT, 'packages');
const PHP = 'D:/xampp/php/php.exe';
const MYSQL = 'D:/xampp/mysql/bin/mysql.exe';

const SITES = {
	j5: { dir: 'D:/xampp/htdocs/joomla5', db: 'joomla5', prefix: 'jos_' },
	j6: { dir: 'D:/xampp/htdocs/joomla6', db: 'joomla6', prefix: 'jos_' },
};

const wanted = process.argv.slice(2).filter((a) => !a.startsWith('--'));
const siteKeys = wanted.length ? wanted : Object.keys(SITES);
for (const k of siteKeys) {
	if (!SITES[k]) {
		console.error(`Unknown site "${k}" (use j5 or j6).`);
		process.exit(2);
	}
}

const norm = (p) => resolve(p).replace(/\\/g, '/').toLowerCase();

function isLink(p) {
	try {
		return lstatSync(p).isSymbolicLink();
	} catch {
		return false;
	}
}

/** Make `link` a junction to `target`. Returns a status word. */
function ensureJunction(link, target) {
	if (isLink(link)) {
		if (norm(readlinkSync(link)) === norm(target)) {
			return 'ok';
		}
		rmdirSync(link); // removes the junction only, never the target
	} else if (existsSync(link)) {
		if (!statSync(link).isDirectory()) {
			return 'skipped (not a directory)';
		}
		rmSync(link, { recursive: true, force: true }); // installed copy
	} else {
		mkdirSync(dirname(link), { recursive: true });
	}
	symlinkSync(target, link, 'junction');
	return 'linked';
}

function mysqlScalar(site, sql) {
	const r = spawnSync(MYSQL, ['-uroot', '--default-character-set=utf8mb4', '-N', '-B', site.db, '-e', sql], { encoding: 'utf8' });
	return r.status === 0 ? r.stdout.trim() : null;
}

function isInstalled(site, type, element, folder = '') {
	const sql = `SELECT COUNT(*) FROM ${site.prefix}extensions WHERE type='${type}' AND element='${element}'${folder ? ` AND folder='${folder}'` : ''}`;
	return mysqlScalar(site, sql) === '1';
}

function joomla(site, ...cliArgs) {
	const r = spawnSync(PHP, [join(site.dir, 'cli/joomla.php'), ...cliArgs], { encoding: 'utf8' });
	return { ok: r.status === 0, out: (r.stdout + r.stderr).trim() };
}

const names = readdirSync(PACKAGES).filter((n) => /^(lib|com|plg|mod)_/.test(n) && statSync(join(PACKAGES, n)).isDirectory());

let failed = false;
for (const key of siteKeys) {
	const site = SITES[key];
	console.log(`\n== ${key} (${site.dir})`);
	let discover = false;

	for (const name of names) {
		const src = join(PACKAGES, name);
		/** @type {Array<{link:string,target:string,label:string}>} */
		const links = [];
		let installed = true;
		const plg = /^plg_([a-z0-9]+)_([a-z0-9_]+)$/.exec(name);

		if (name.startsWith('lib_')) {
			links.push({ link: join(site.dir, 'libraries', name.slice(4)), target: src, label: name });
			installed = existsSync(join(site.dir, 'libraries', name.slice(4)));
		} else if (name.startsWith('com_')) {
			links.push({ link: join(site.dir, 'administrator/components', name), target: src, label: name });
			links.push({ link: join(site.dir, 'media', name), target: join(src, 'media'), label: `${name}/media` });
			installed = isInstalled(site, 'component', name);
		} else if (plg) {
			links.push({ link: join(site.dir, 'plugins', plg[1], plg[2]), target: src, label: name });
			installed = isInstalled(site, 'plugin', plg[2], plg[1]);
		} else if (name.startsWith('mod_')) {
			const manifest = readdirSync(src).find((f) => f.endsWith('.xml'));
			const client = manifest && /client="administrator"/.test(readFileSync(join(src, manifest), 'utf8')) ? 'administrator/modules' : 'modules';
			links.push({ link: join(site.dir, client, name), target: src, label: name });
			installed = isInstalled(site, 'module', name);
			if (!installed) {
				discover = true; // linked first, then Joomla discovers and installs it from the linked folder
			}
		}

		if (!installed && !name.startsWith('mod_')) {
			console.log(`  ${name}: not installed on this site - install dist/pkg_uxstudio-<version>.zip first`);
			failed = true;
			continue;
		}
		for (const l of links) {
			if (l.label.endsWith('/media') && !existsSync(l.target)) {
				mkdirSync(l.target, { recursive: true });
			}
			console.log(`  ${l.label}: ${ensureJunction(l.link, l.target)}`);
		}
	}

	if (discover) {
		for (const cmd of [['extension:discover'], ['extension:discover:install']]) {
			const r = joomla(site, ...cmd);
			console.log(`  joomla ${cmd[0]}: ${r.ok ? 'ok' : 'FAILED'} ${r.out.split('\n').slice(-2).join(' | ')}`);
			failed ||= !r.ok;
		}
	}
	const cache = joomla(site, 'cache:clean');
	console.log(`  joomla cache:clean: ${cache.ok ? 'ok' : 'FAILED ' + cache.out}`);
}

process.exit(failed ? 1 : 0);
