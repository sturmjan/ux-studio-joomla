#!/usr/bin/env node
/**
 * Build the installable package:
 *   1. builds the admin SPA into packages/com_uxstudio/media (skip with --skip-spa)
 *   2. generates packages/pkg_uxstudio.xml from the contents of packages/ (lib_*, com_*, plg_*_*, mod_*)
 *   3. zips every extension and the whole package into dist/pkg_uxstudio-<version>.zip
 * Modules whose meta.json has "dev": true (samples, test fixtures) are left out of the zip and of the SPA bundle;
 * pass --with-dev to keep them (local test installs).
 * Version comes from the root package.json and is written into every extension manifest inside the zips.
 */
import { spawnSync } from 'node:child_process';
import { mkdirSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { zipSync } from 'fflate';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const PACKAGES = join(ROOT, 'packages');
const DIST = join(ROOT, 'dist');
const args = new Set(process.argv.slice(2));

const withDev = args.has('--with-dev');

/** Dev-only modules: { id, dir } from packages/lib_uxstudio/src/Modules/<Dir>/meta.json ("dev": true). */
function devModules() {
	const base = join(PACKAGES, 'lib_uxstudio', 'src', 'Modules');
	const out = [];
	for (const dir of readdirSync(base)) {
		try {
			const meta = JSON.parse(readFileSync(join(base, dir, 'meta.json'), 'utf8'));
			if (meta.dev === true && typeof meta.id === 'string') {
				out.push({ id: meta.id, dir });
			}
		} catch {
			// not a module folder
		}
	}
	return out;
}
const excluded = withDev ? [] : devModules();
if (excluded.length) {
	console.log(`> Leaving out dev modules: ${excluded.map((m) => m.id).join(', ')}`);
}

const version = JSON.parse(readFileSync(join(ROOT, 'package.json'), 'utf8')).version;

function run(cmd, cmdArgs, cwd = ROOT) {
	const r = spawnSync(cmd, cmdArgs, { cwd, stdio: 'inherit', shell: process.platform === 'win32' });
	if (r.status !== 0) {
		throw new Error(`${cmd} ${cmdArgs.join(' ')} failed (exit ${r.status})`);
	}
}

/** Recursively list files of a directory (relative posix paths), skipping dev junk. */
function walk(dir, base = dir, out = []) {
	for (const name of readdirSync(dir)) {
		if (['node_modules', '.git', '.DS_Store', 'Thumbs.db', '.gitkeep'].includes(name) || name.endsWith('.map')) {
			continue;
		}
		const full = join(dir, name);
		// Test scripts must not ship: some define _JEXEC themselves and run when requested directly.
		if (['tests', 'test'].includes(name) || /\.test\.php$/.test(name)) {
			continue;
		}
		if (statSync(full).isDirectory()) {
			walk(full, base, out);
		} else {
			out.push(relative(base, full).split(sep).join('/'));
		}
	}
	return out;
}

function findManifest(dir) {
	for (const f of readdirSync(dir)) {
		if (!f.endsWith('.xml')) {
			continue;
		}
		const text = readFileSync(join(dir, f), 'utf8');
		if (/<extension\s[^>]*type="/.test(text)) {
			return { file: f, text };
		}
	}
	throw new Error(`No extension manifest in ${dir}`);
}

function describe(name) {
	const dir = join(PACKAGES, name);
	const { file, text } = findManifest(dir);
	const type = /<extension\s[^>]*type="([a-z]+)"/.exec(text)?.[1];
	const info = { name, dir, manifestFile: file, manifestText: text, type };
	if (type === 'plugin') {
		info.group = /<extension\s[^>]*group="([a-z0-9_-]+)"/.exec(text)?.[1];
		info.id = /<folder\s+plugin="([^"]+)"/.exec(text)?.[1] ?? /<filename\s+plugin="([^"]+)"/.exec(text)?.[1];
	} else if (type === 'library') {
		info.id = /<libraryname>([^<]+)<\/libraryname>/.exec(text)?.[1];
	} else if (type === 'module') {
		info.client = /<extension\s[^>]*client="([a-z]+)"/.exec(text)?.[1] ?? 'site';
		info.id = name;
	} else {
		info.id = name;
	}
	return info;
}

if (!args.has('--skip-spa')) {
	console.log('> Building admin SPA');
	process.env.UXS_EXCLUDE_MODULES = excluded.map((m) => m.id).join(',');
	run('npm', ['run', 'build', '-w', 'admin-app']);
}

const extNames = readdirSync(PACKAGES).filter((n) => /^(lib|com|plg|mod|tpl)_/.test(n) && statSync(join(PACKAGES, n)).isDirectory());
const order = { library: 0, component: 1, plugin: 2, module: 3, template: 4 };
const exts = extNames.map(describe).sort((a, b) => (order[a.type] ?? 9) - (order[b.type] ?? 9) || a.name.localeCompare(b.name));

// The component needs the SPA bundle; fail loudly instead of shipping a broken zip.
for (const need of ['js/app.js', 'css/app.css', 'joomla.asset.json']) {
	try {
		statSync(join(PACKAGES, 'com_uxstudio', 'media', need));
	} catch {
		throw new Error(`Missing packages/com_uxstudio/media/${need}: run the SPA build (do not use --skip-spa on a clean checkout).`);
	}
}

rmSync(DIST, { recursive: true, force: true });
mkdirSync(join(DIST, 'packages'), { recursive: true });

const enc = new TextEncoder();
const zips = [];
for (const ext of exts) {
	const files = {};
	for (const rel of walk(ext.dir)) {
		if (ext.name === 'lib_uxstudio' && excluded.some((m) => rel.startsWith(`src/Modules/${m.dir}/`) || rel.startsWith(`media/modules/${m.id}/`))) {
			continue;
		}
		let data = readFileSync(join(ext.dir, rel));
		if (rel === ext.manifestFile) {
			data = enc.encode(ext.manifestText.replace(/<version>[^<]*<\/version>/, `<version>${version}</version>`));
		}
		files[rel] = data;
	}
	const zipName = `${ext.name}.zip`;
	writeFileSync(join(DIST, 'packages', zipName), zipSync(files, { level: 9 }));
	zips.push({ ext, zipName });
	console.log(`  ${zipName}  (${Object.keys(files).length} files)`);
}

const fileTags = zips
	.map(({ ext, zipName }) => {
		const attrs = [`type="${ext.type}"`, `id="${ext.id}"`];
		if (ext.type === 'plugin') {
			attrs.push(`group="${ext.group}"`);
		}
		if (ext.type === 'module') {
			attrs.push(`client="${ext.client}"`);
		}
		return `\t\t<file ${attrs.join(' ')}>${zipName}</file>`;
	})
	.join('\n');

const pkgXml = `<?xml version="1.0" encoding="UTF-8"?>
<!-- GENERATED by bin/build.mjs from the contents of packages/ - do not edit. -->
<extension type="package" method="upgrade">
	<name>pkg_uxstudio</name>
	<packagename>uxstudio</packagename>
	<version>${version}</version>
	<creationDate>${new Date().toISOString().slice(0, 7)}</creationDate>
	<author>UX1</author>
	<authorEmail>info@ux1.cz</authorEmail>
	<authorUrl>https://ux1.cz</authorUrl>
	<copyright>(C) UX1</copyright>
	<license>GNU General Public License version 2 or later</license>
	<description>UX Studio for Joomla: a modular toolbox (library, component, plugins and modules).</description>
	<scriptfile>script.php</scriptfile>
	<files folder="packages">
${fileTags}
	</files>
</extension>
`;
writeFileSync(join(PACKAGES, 'pkg_uxstudio.xml'), pkgXml);

const pkgFiles = { 'pkg_uxstudio.xml': enc.encode(pkgXml), 'script.php': readFileSync(join(PACKAGES, 'pkg_uxstudio.script.php')) };
for (const { zipName } of zips) {
	pkgFiles[`packages/${zipName}`] = readFileSync(join(DIST, 'packages', zipName));
}
const out = join(DIST, `pkg_uxstudio-${version}.zip`);
writeFileSync(out, zipSync(pkgFiles, { level: 6 }));
console.log(`\nBuilt ${relative(ROOT, out)} (${(statSync(out).size / 1024).toFixed(0)} kB), ${zips.length} extensions.`);
