#!/usr/bin/env node
/**
 * Development linking: after the first install of the package, replace the installed extension folders on the
 * local test sites by junctions into this repository, so every edit is live without reinstalling.
 * Idempotent. New mod_uxstudio_* modules found in packages/ are linked, discovered and installed automatically.
 *
 *   node bin/dev-link.mjs              # both sites
 *   node bin/dev-link.mjs j5           # one site (j5 | j6)
 *   node bin/dev-link.mjs --unlink     # remove the junctions and copy the files back (plain installed copies again)
 *   node bin/dev-link.mjs j5 --unlink  # only one site
 *
 * WARNING: never install or update the zip on a linked site. The Joomla installer deletes the old extension folders
 * first, which through a junction deletes the files in this repository. The install scripts refuse to run when they
 * detect a link, but unlink first anyway.
 */
import { spawnSync } from 'node:child_process';
import { cpSync, existsSync, lstatSync, mkdirSync, readFileSync, readdirSync, readlinkSync, rmSync, rmdirSync, statSync, symlinkSync } from 'node:fs';
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

const unlink = process.argv.includes('--unlink');
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

/** Text of the extension manifest (the .xml with an <extension> root; config.xml, access.xml are skipped). */
function manifestOf(dir) {
	return (
		readdirSync(dir)
			.filter((f) => f.endsWith('.xml'))
			.map((f) => readFileSync(join(dir, f), 'utf8'))
			.find((text) => /<extension\s/.test(text)) ?? ''
	);
}

const names = readdirSync(PACKAGES).filter((n) => /^(lib|com|plg|mod)_/.test(n) && statSync(join(PACKAGES, n)).isDirectory());

let failed = false;
for (const key of siteKeys) {
	const site = SITES[key];
	console.log(`\n== ${key} (${site.dir})`);
	let discover = false;

	for (const name of names) {
		const src = join(PACKAGES, name);
		/** @type {Array<{link:string,target:string,label:string,skip?:string[]}>} */
		const links = [];
		let installed = true;
		const plg = /^plg_([a-z0-9]+)_([a-z0-9_]+)$/.exec(name);

		if (name.startsWith('lib_')) {
			links.push({ link: join(site.dir, 'libraries', name.slice(4)), target: src, label: name, skip: ['media'] });
			installed = existsSync(join(site.dir, 'libraries', name.slice(4)));
		} else if (name.startsWith('com_')) {
			links.push({ link: join(site.dir, 'administrator/components', name), target: src, label: name, skip: ['media', 'site'] });
			if (existsSync(join(src, 'site'))) {
				links.push({ link: join(site.dir, 'components', name), target: join(src, 'site'), label: `${name}/site` });
			}
			installed = isInstalled(site, 'component', name);
		} else if (plg) {
			links.push({ link: join(site.dir, 'plugins', plg[1], plg[2]), target: src, label: name });
			installed = isInstalled(site, 'plugin', plg[2], plg[1]);
		} else if (name.startsWith('mod_')) {
			const client = /<extension\s[^>]*client="administrator"/.test(manifestOf(src)) ? 'administrator/modules' : 'modules';
			links.push({ link: join(site.dir, client, name), target: src, label: name, skip: ['media'] });
			installed = isInstalled(site, 'module', name);
			if (!installed) {
				discover = true; // linked first, then Joomla discovers and installs it from the linked folder
			}
		}

		// <media destination="x" folder="y"> in the manifest: media/x -> <extension>/y (assets of the library, component, modules).
		const manifestText = manifestOf(src);
		for (const tag of manifestText.match(/<media\s[^>]*>/g) ?? []) {
			const destination = /destination="([^"]+)"/.exec(tag)?.[1];
			const folder = /folder="([^"]+)"/.exec(tag)?.[1];
			if (destination && folder && !/[\/]\.\./.test(destination + folder)) {
				links.push({ link: join(site.dir, 'media', destination), target: join(src, folder), label: `${name}/${folder} -> media/${destination}` });
			}
		}

		if (unlink) {
			for (const l of links) {
				if (!isLink(l.link)) {
					console.log(`  ${l.label}: not linked`);
					continue;
				}
				rmdirSync(l.link); // the junction only, never the target
				const skip = (l.skip ?? []).map((n) => norm(join(l.target, n)));
				cpSync(l.target, l.link, { recursive: true, filter: (from) => !skip.includes(norm(from)) });
				console.log(`  ${l.label}: unlinked (files copied back)`);
			}
			continue;
		}

		if (!installed && !name.startsWith('mod_')) {
			console.log(`  ${name}: not installed on this site - install dist/pkg_uxstudio-<version>.zip first`);
			failed = true;
			continue;
		}
		for (const l of links) {
			if (!existsSync(l.target)) {
				mkdirSync(l.target, { recursive: true });
			}
			console.log(`  ${l.label}: ${ensureJunction(l.link, l.target)}`);
		}
	}

	if (discover && !unlink) {
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
