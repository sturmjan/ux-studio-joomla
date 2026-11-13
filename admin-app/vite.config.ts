import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import react from '@vitejs/plugin-react';
import { defineConfig, type Plugin } from 'vite';

const outDir = resolve(import.meta.dirname, '../packages/com_uxstudio/media');
const version = (JSON.parse(readFileSync(resolve(import.meta.dirname, '../package.json'), 'utf8')) as { version: string }).version;

/** Cleans js/css of the previous build and writes media/joomla.asset.json (WebAssetManager registry) with a content hash as version. */
function joomlaAssets(): Plugin {
	return {
		name: 'uxstudio-joomla-assets',
		buildStart() {
			for (const dir of ['js', 'css']) {
				rmSync(resolve(outDir, dir), { recursive: true, force: true });
			}
		},
		writeBundle() {
			const js = readFileSync(resolve(outDir, 'js/app.js'));
			const css = readFileSync(resolve(outDir, 'css/app.css'));
			const hash = (buf: Buffer): string => createHash('sha1').update(buf).digest('hex').slice(0, 10);
			const registry = {
				$schema: 'https://developer.joomla.org/schemas/json-schema/web_assets.json',
				name: 'com_uxstudio',
				version,
				description: 'UX1 Studio admin app',
				license: 'GPL-2.0-or-later',
				assets: [
					{ name: 'com_uxstudio.app', type: 'script', uri: 'com_uxstudio/app.js', attributes: { type: 'module' }, version: hash(js) },
					{ name: 'com_uxstudio.app', type: 'style', uri: 'com_uxstudio/app.css', version: hash(css) },
				],
			};
			mkdirSync(outDir, { recursive: true });
			writeFileSync(resolve(outDir, 'joomla.asset.json'), JSON.stringify(registry, null, '\t') + '\n');
		},
	};
}

/**
 * Production builds leave dev-only modules (meta.json "dev": true, ids in UXS_EXCLUDE_MODULES) out of the bundle:
 * their screens and dictionaries are replaced by empty stubs.
 */
function excludeDevModules(): Plugin {
	const ids = (process.env.UXS_EXCLUDE_MODULES ?? '').split(',').filter(Boolean);
	return {
		name: 'uxstudio-exclude-dev-modules',
		enforce: 'pre',
		load(id) {
			const path = id.split('?')[0]?.replaceAll('\\', '/') ?? '';
			const m = /\/src\/modules\/([a-z0-9-]+)\/(Page\.tsx|i18n\/[a-z]+\.json)$/.exec(path);
			if (!m || !ids.includes(m[1] as string)) {
				return null;
			}
			return m[2] === 'Page.tsx' ? 'export default function Page() { return null; }' : '{}';
		},
	};
}

export default defineConfig({
	base: './',
	plugins: [excludeDevModules(), react(), joomlaAssets()],
	define: { 'process.env.NODE_ENV': JSON.stringify('production') },
	build: {
		outDir,
		emptyOutDir: false,
		cssCodeSplit: false,
		sourcemap: false,
		target: 'es2022',
		rollupOptions: {
			input: resolve(import.meta.dirname, 'src/main.tsx'),
			output: {
				entryFileNames: 'js/app.js',
				chunkFileNames: 'js/chunks/[name]-[hash].js',
				assetFileNames: (info) => ((info.names ?? [info.name ?? '']).some((n) => n.endsWith('.css')) ? 'css/app.css' : 'assets/[name]-[hash][extname]'),
			},
		},
	},
});
