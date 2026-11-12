# UX1 Studio pro Joomla 5 a 6 - architektura (závazná)

Port WordPress pluginu UX1 Studio (referenční kód: `D:\xampp\htdocs\_uxs-wt-csp`, jen ke čtení,
NIKDY tam nezapisovat). Neportujeme 1:1: přebíráme logiku, UI a vzhled, PHP vrstvu píšeme
nativně proti Joomla API. Jeden kód musí běžet na **Joomla 5.4 i 6.x** beze změn.

## 1. Cílové prostředí

| | Joomla 5 | Joomla 6 |
|---|---|---|
| Lokální web | http://localhost/joomla5 (`D:\xampp\htdocs\joomla5`) | http://localhost/joomla6 (`D:\xampp\htdocs\joomla6`) |
| DB | `joomla5`, prefix `jos_` | `joomla6`, prefix `jos_` |
| Admin | přihlašovací údaje v `.env.local` (gitignored, nikdy nevypisovat) | stejné |

- PHP: `D:\xampp\php\php.exe` (8.4). Kód musí jít na **PHP 8.1+** (min. pro J5), na J6 je min. 8.3.
- MySQL klient: `D:\xampp\mysql\bin\mysql.exe -uroot --default-character-set=utf8mb4`.
- Joomla CLI: `php <site>/cli/joomla.php extension:install --path=...`, `extension:discover`,
  `extension:discover:install`, `cache:clean`.
- Composer není nainstalovaný; běhové závislosti PHP nepoužíváme (Joomla framework stačí).

### Kompatibilita J5/J6 - zakázáno
`CMSObject`, `JFactory`/`J*` aliasy, `Factory::getUser()`, `Factory::getDbo()`, `Factory::getSession()`,
`Factory::getDocument()` (použij `$app->getDocument()`), `JRequest`, `Joomla\CMS\Filesystem\*`
(použij `Joomla\Filesystem\*`), cokoli `@deprecated` v J5 (např. `$db->getQuery(true)` - používej `$db->createQuery()`; plugin s `DispatcherInterface` v konstruktoru - `new Plugin((array) PluginHelper::getPlugin(...))`). Vše musí fungovat na J6 i s vypnutými
pluginy „Behaviour - Backward Compatibility“. DB vždy `Factory::getContainer()->get(DatabaseInterface::class)`
nebo `DatabaseAwareTrait`, uživatel `$app->getIdentity()`.

## 2. Struktura repozitáře

```
ux-studio-joomla/
├── ARCHITECTURE.md, PLAN.md, README.md
├── packages/
│   ├── lib_uxstudio/                 # knihovna = jádro + všechny moduly (PHP)
│   │   ├── lib_uxstudio.xml          # <namespace path="src">Ux1\UxStudio</namespace>
│   │   ├── media/modules/<id>/       # frontendové assety modulu (JS/CSS), publikují se do media/lib_uxstudio/modules/<id>/
│   │   └── src/
│   │       ├── Core/                 # jádro (Kernel, ModuleRegistry, Settings, Migrator, Upgrader, Http, Api, PublicApi, RateLimiter, ...)
│   │       └── Modules/<Name>/       # jeden adresář = jeden modul (viz §4)
│   ├── com_uxstudio/                 # admin komponenta (mount React SPA + JSON API) a site/ (veřejný JSON endpoint)
│   ├── plg_system_uxstudio/          # bootuje zapnuté moduly, předává jim Joomla eventy
│   ├── plg_task_uxstudio/            # Joomla Scheduler úlohy, které deklarují moduly
│   ├── mod_uxstudio_<x>/             # frontendové Joomla moduly (pozice šablony), vlastní je konkrétní feature modul
│   └── pkg_uxstudio.xml              # GENEROVANÝ build skriptem z obsahu packages/
├── admin-app/                        # React 19 + TypeScript (strict) + Vite
│   └── src/
│       ├── app/                      # shell, routing, api klient, sdílené komponenty, i18n
│       └── modules/<id>/             # vlastní obrazovky modulů (auto-discovery přes import.meta.glob)
└── bin/
    ├── build.mjs                     # build SPA + zip pkg_uxstudio-<ver>.zip do dist/
    ├── dev-link.mjs                  # junctiony repa do joomla5 a joomla6 (vývoj bez reinstalace)
    └── jadmin.mjs                    # přihlášení do administrace (cookie + CSRF token) pro testy API curlem/node
```

## 3. Jádro (Core)

- **Kernel**: singleton, načte `ModuleRegistry`, pro zapnuté moduly zavolá `boot()`. Volá ho
  `plg_system_uxstudio` v `onAfterInitialise`. Admin i site.
- **ModuleRegistry**: auto-discovery `src/Modules/*/meta.json` (žádný centrální seznam, nic se
  needituje při přidání modulu). Stav zapnutí v tabulce `#__uxstudio_modules (id, enabled, updated_at)`.
- **Settings**: tabulka `#__uxstudio_settings (module VARCHAR(64) PK, data MEDIUMTEXT JSON, updated_at)`.
  Validace a sanitizace podle `settingsSchema()` (stejné typy polí jako WP verze:
  toggle | text | textarea | css | number | select | multiselect | color | media | datetime | richtext | password | repeater).
  Typ `password` (API klíče, SMTP heslo) se ukládá šifrovaně (sodium secretbox, klíč odvozený z
  `$secret` v configuration.php) a do SPA se nikdy nevrací v čisté podobě (jen `"__set__"`).
- **Migrator**: `#__uxstudio_migrations (module, version, applied_at)`. Každý modul vrací
  `migrations(): array<int, string[]>` (verze => SQL příkazy s `#__` prefixem). Spouští se při
  zapnutí modulu a při update balíčku (install script komponenty). Žádné SQL soubory v manifestech.
- **Api**: jediný endpoint `administrator/index.php?option=com_uxstudio&task=api.dispatch&format=json&route=<module>/<path>`.
  Ověřuje: přihlášený admin, `core.manage` na `com_uxstudio` (modul může požadovat přísnější
  akci, např. `core.admin`), CSRF token v hlavičce `X-CSRF-Token` (Session::checkToken), metoda.
  Routa může mít přísnější ACL než modul: `'POST purge' => ['handler' => [...], 'acl' => 'core.admin']` (musí projít obojí).
  Odpověď vždy JSON `{ok:true,data}` / `{ok:false,error:{code,message}}` se správným HTTP kódem.
  Jádro samo vystavuje `core/modules` (seznam + toggle), `core/settings/<id>` (GET/POST), `core/meta`.
- **PublicApi** (site endpoint, `BaseModule::publicRoutes()`): `index.php?option=com_uxstudio&task=public.dispatch&format=json&route=<module>/<path>`.
  Bez session/CSRF; každá routa deklaruje `auth` (`none` + kontrola Origin | `token` Bearer | `hmac` X-UXS-Timestamp + X-UXS-Signature),
  `limit` [požadavků, okno s] na IP a routu (`Core\RateLimiter`, tabulka `#__uxstudio_ratelimit`, 429 + Retry-After) a `max_body` (413).
- **Upgrader**: po instalaci/update jen příznak (`markDirty`); migrace a `Scheduler::syncAll()` (úlohy modulů: založit, změnit interval/název,
  odebrané úlohy do koše) běží líně při prvním požadavku administrace/CLI, když se změnil otisk (verze, soubory modulů, migrace, úlohy).
- **Frontendové assety modulu**: `packages/lib_uxstudio/media/modules/<id>/` (publikuje se do `media/lib_uxstudio/modules/<id>/`),
  `$ctx->useScript('file.js')` / `useStyle()` přes WebAssetManager.
- **Http**: wrapper nad `Joomla\Http\HttpFactory` s timeoutem, User-Agentem a SSRF guardem
  (zákaz privátních/loopback IP u URL zadaných uživatelem; ověřená IP se připíná přes CURLOPT_RESOLVE proti DNS rebindingu, IPv4/IPv6 rozsahy
  jsou explicitní CIDR seznam, jen http/https, strop velikosti odpovědi).
- **Scheduler**: `plg_task_uxstudio` vystaví routinu `uxstudio.run` s parametrem `job`; moduly
  deklarují `tasks(): array` (job id => [label, callable, default interval]). Jádro při zapnutí
  modulu úlohu založí v `#__scheduler_tasks`, při vypnutí ji vypne.
- **Sdílené služby jádra** (podrobně v `docs/MODULE-GUIDE.md` §6b): `Core\ClientIp` (režim a důvěryhodné proxy v nastavení jádra, `core/settings/core`), `Core\Mail`, `Core\Store` (`#__uxstudio_store`),
  `Core\Hooks`, `Core\Notices`, `Core\SafeRegex`, `Http` (`follow_redirects`), jednorázové běhy úloh (`ModuleContext::runJobSoon`), multipart v `PublicApi`.
  Selhání načtení nebo `boot()` jednoho modulu ostatní nezastaví (chyba v logu a v SPA).
- **Language**: PHP řetězce v `src/Modules/<Name>/language/{en-GB,cs-CZ}/uxs_<id>.ini`, jádro je
  načte pro zapnutý modul. Jádro má vlastní `lib_uxstudio.ini`. Klíče `UXS_<ID>_...`.
- **Logy/retence**: `Core\Retention` - moduly s log tabulkami deklarují `retention(): array` (tabulka, sloupec data, dny).

## 4. Kontrakt modulu

```
packages/lib_uxstudio/src/Modules/<Name>/       # Name = PascalCase, id = kebab-case
  meta.json        # {id, name, description, group, icon (lucide název), settings:true|false, requires?: []}
  Module.php       # final class Module extends \Ux1\UxStudio\Core\BaseModule
  Api.php          # volitelně: handler rout modulu
  language/en-GB/uxs_<id>.ini, language/cs-CZ/uxs_<id>.ini
  ...další třídy v namespace Ux1\UxStudio\Modules\<Name>
admin-app/src/modules/<id>/
  Page.tsx         # volitelně, default export; bez něj se vykreslí generický settings renderer
  i18n/en.json, i18n/cs.json
packages/mod_uxstudio_<x>/                      # volitelně, frontendový Joomla modul
packages/lib_uxstudio/media/modules/<id>/       # volitelně, frontendové assety (JS/CSS) přes $ctx->useScript()/useStyle()
```

`BaseModule` metody (všechny kromě `boot` mají default):

| Metoda | Účel |
|---|---|
| `boot(ModuleContext $ctx): void` | registrace listenerů: `$ctx->on('onAfterRender', [$this,'x'], priority)` |
| `settingsSchema(): array` | pole nastavení (formát jako WP `settings_schema`) |
| `routes(): array` | `['GET logs' => [$this,'listLogs'], 'POST test' => ...]`, handler dostane `ApiRequest`, vrací pole; routa může být i `['handler'=>..,'acl'=>'core.admin']` |
| `publicRoutes(): array` | veřejné (site) routy s `auth`, `limit`, `max_body` (viz PublicApi) |
| `ctx(): ModuleContext` | (protected, final) kontext modulu i při API/CLI požadavku, kdy `boot()` neběžel |
| `migrations(): array` | verze => SQL |
| `tasks(): array` | cron úlohy pro Scheduler |
| `retention(): array` | čištění logů |
| `onEnable()/onDisable(): void` | vedlejší efekty (.htaccess bloky apod.); data se při vypnutí NEMAŽOU |
| `acl(): string` | výchozí `core.manage`, rizikové moduly `core.admin` |
| `dashboardWidget(): ?array` | volitelná karta na přehledu UX1 Studia |

## 5. Admin SPA

- Vite + React 19 + TS strict (žádné `any`), `@tanstack/react-query`, `lucide-react`,
  `react-aria-components`. Výstup do `packages/com_uxstudio/media/` (registrováno přes `joomla.asset.json`).
- **Vzhled převzít z WP verze**: tokeny `--uxs-*`, dark mode, komponenty (`ModuleGrid`, `PageHead`,
  `DataTable`, `Modal`, `Tabs`, `Toast`, `Confirm`, `ToggleSwitch`, `SettingsForm`...) portovat z
  `_uxs-wt-csp/src/app` a `src/style.scss`. Pravidla viz `_uxs-wt-csp/DESIGN.md` (platí i tady, kromě
  WP-specifických tříd: tlačítka jsou vlastní `uxs-btn` třídy, ne Bootstrap Atum). CSS je scoped pod
  `#uxs-root`, aby se nebilo s Atum šablonou.
- Moduly: `import.meta.glob('../modules/*/Page.tsx')` (lazy), překlady `import.meta.glob('../modules/*/i18n/*.json')`.
- i18n: zdroj anglicky, jazyk podle jazyka administrace (cs-CZ → cs, jinak en). Jádro: `t('key')` z `app/i18n`. Moduly mají VLASTNÍ slovník
  (`createT('<id>')` → `{t, tn}`: nejdřív slovník modulu, pak jádra); slovníky modulů se nikdy nesléváji do globálního `t`.
- Ikona modulu: `admin-app/src/modules/<id>/icon.tsx` (default export lucide ikony), jinak název z `meta.json` ze sdíleného seznamu, jinak Puzzle.
- Vývojové moduly (`"dev": true` v `meta.json`) se do produkčního zipu a SPA bundlu nedostanou (`bin/build.mjs`; `--with-dev` je zahrne).
- API klient: `app/api.ts` - `api.get/post(route, body)`, CSRF token a URL z `Joomla.getOptions('uxstudio')`.

## 6. Pravidla

- Bezpečnost: parametrizované dotazy (`$query->bind`/`whereIn` s typy), escapování výstupu
  (`htmlspecialchars` / `HTMLHelper`), validace na serveru podle schématu, CSRF a ACL na každé
  zapisující routě, žádné tajné údaje v kódu ani gitu, rate limiting u veřejných (site) endpointů.
- Frontendové výstupy (popupy, lišty, formuláře) musí respektovat CSP: žádné inline `onclick`,
  skripty přes WebAssetManager.
- Komentáře v kódu anglicky, uživatelské texty přes překlady (cs + en povinně).
- **Paralelní práce**: modulový agent smí měnit JEN svoje adresáře (`src/Modules/<Name>/`,
  `admin-app/src/modules/<id>/`, `packages/mod_uxstudio_<x>/`, `docs/modules/<id>.md`).
  Potřebuje-li změnu jádra, NEMĚNÍ ho, ale popíše ji ve výstupu (`core_requests`). Necommituje
  (a nikdy nepoužívá `git add -A`: přidává jen vlastní soubory).
- Každá funkce se ověřuje živě na J5 **i** J6 (instalace, API curlem přes `bin/jadmin.mjs`,
  frontend curlem), `php -l` na všech PHP souborech, `npm run typecheck` pro SPA.
