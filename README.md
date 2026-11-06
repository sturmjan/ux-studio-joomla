# UX Studio pro Joomla 5 a 6

Modulární sada nástrojů pro Joomlu (port WordPress pluginu UX Studio). Architektura: `ARCHITECTURE.md`, plán: `PLAN.md`,
návod pro autory modulů: `docs/MODULE-GUIDE.md`.

Obsah repozitáře: `packages/` (knihovna `lib_uxstudio` s jádrem a moduly, komponenta `com_uxstudio`, pluginy
`plg_system_uxstudio` a `plg_task_uxstudio`, frontendové moduly `mod_uxstudio_*`), `admin-app/` (React 19 + TypeScript SPA),
`bin/` (build, dev-link, jadmin).

## Požadavky

- Node.js 20+ (vyvíjeno na 24), npm
- PHP 8.1+ (lokálně `D:\xampp\php\php.exe`), Joomla 5.4+ nebo 6.x
- Lokální weby: `http://localhost/joomla5` (`D:\xampp\htdocs\joomla5`) a `http://localhost/joomla6`; přihlašovací údaje a URL
  v `.env.local` (není v gitu): `JOOMLA_ADMIN_USER`, `JOOMLA_ADMIN_PASSWORD`, `J5_URL`, `J6_URL`.

## Build

```bash
npm install            # v kořeni repa (npm workspaces: admin-app)
npm run typecheck      # TypeScript strict
npm run build          # build SPA + dist/pkg_uxstudio-<verze>.zip
npm run build:spa      # jen SPA do packages/com_uxstudio/media
```

`npm run build` (`bin/build.mjs`) sestaví SPA (Vite), vygeneruje `packages/pkg_uxstudio.xml` z obsahu `packages/`, zazipuje každé
rozšíření do `dist/packages/` a celý balíček do `dist/pkg_uxstudio-<verze>.zip`. Verze se bere z kořenového `package.json`
a zapisuje se do manifestů v zipech.

## Instalace

Administrace: Systém - Instalace - Rozšíření - nahrát `dist/pkg_uxstudio-<verze>.zip`. Nebo z příkazové řádky:

```bash
D:/xampp/php/php.exe D:/xampp/htdocs/joomla5/cli/joomla.php extension:install --path=D:/xampp/htdocs/ux-studio-joomla/dist/pkg_uxstudio-0.1.0.zip
D:/xampp/php/php.exe D:/xampp/htdocs/joomla6/cli/joomla.php extension:install --path=D:/xampp/htdocs/ux-studio-joomla/dist/pkg_uxstudio-0.1.0.zip
```

Instalace vytvoří tabulky jádra (`#__uxstudio_modules`, `_settings`, `_migrations`), zapne oba pluginy a založí úlohu plánovače
pro retenci logů. Komponenta je v menu Komponenty - UX Studio (`index.php?option=com_uxstudio`).

## Vývoj bez reinstalace (dev-link)

Po první instalaci zipu:

```bash
node bin/dev-link.mjs        # oba weby (nebo: node bin/dev-link.mjs j5)
```

Nahradí nainstalované složky junctiony do repa (`libraries/uxstudio`, `administrator/components/com_uxstudio`,
`media/com_uxstudio`, `plugins/system/uxstudio`, `plugins/task/uxstudio`, `modules/mod_uxstudio_*`). Skript je idempotentní,
nové `mod_uxstudio_*` sám nalinkuje a nainstaluje (`extension:discover` + `extension:discover:install`) a vyčistí cache.
Po změně SPA stačí `npm run build:spa` (nebo `npm run watch -w admin-app`) a obnovit stránku.

## Testování API (jadmin)

```bash
node bin/jadmin.mjs j5 GET core/modules
node bin/jadmin.mjs j6 POST core/settings/hello-check '{"greeting":"Ahoj"}'
node bin/jadmin.mjs j5 GET core/meta --no-csrf --expect 403
node bin/jadmin.mjs j5 page
```

Podrobnosti v `docs/MODULE-GUIDE.md` (sekce Testování). Session se ukládá do `.jadmin/` (gitignorováno).

## Vzorový modul

`hello-check` (`packages/lib_uxstudio/src/Modules/HelloCheck`, `admin-app/src/modules/hello-check`) ukazuje nastavení (text +
šifrované heslo), jednu API routu, migraci, úlohu plánovače, retenci, listener události a vlastní obrazovku. Slouží jako šablona
i jako test jádra.
