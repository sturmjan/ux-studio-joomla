# UX Studio pro Joomla - návod pro autory modulů

Tento návod popisuje, jak přidat funkci (modul) do UX Studia. Závazná pravidla jsou v `ARCHITECTURE.md`,
vzorový modul je `packages/lib_uxstudio/src/Modules/HelloCheck` (id `hello-check`) včetně obrazovky
`admin-app/src/modules/hello-check/Page.tsx`. **Začni zkopírováním těchto dvou složek.**

## 0. Pravidla hry

- Měníš JEN svoje adresáře: `packages/lib_uxstudio/src/Modules/<Name>/`, `admin-app/src/modules/<id>/`,
  `packages/mod_uxstudio_<x>/`, `docs/modules/<id>.md`. Jádro (`Core/`, `app/`, `bin/`) neupravuj; potřebuješ-li změnu
  jádra, popiš ji ve výstupu jako `core_requests`.
- Nepoužívej nic z `ARCHITECTURE.md` §1 "zakázáno" (CMSObject, JFactory, `Factory::getDbo()`, `Factory::getUser()`,
  `Joomla\CMS\Filesystem\*` ...). DB: `$ctx->db()`, uživatel: `$request->user()`.
- Kód musí běžet na PHP 8.1+ a Joomla 5.4 i 6.x beze změn. Komentáře anglicky, texty uživateli přes překlady (cs + en).
- Žádná tajemství v kódu, dotazy jen parametrizované (`$query->bind(...)`), výstup escapovat, na zapisujících routách
  je CSRF a ACL zajištěná jádrem (viz níže), validace patří na server.

## 1. Založení modulu (PHP)

```
packages/lib_uxstudio/src/Modules/<Name>/          # Name = PascalCase, id = kebab-case
  meta.json
  Module.php
  language/en-GB/uxs_<id>.ini
  language/cs-CZ/uxs_<id>.ini
  Api.php, další třídy ...                          # namespace Ux1\UxStudio\Modules\<Name>
```

Žádný centrální seznam neexistuje: jádro najde modul podle `meta.json` (auto-discovery). Po přidání složky stačí
(při `dev-linku`) obnovit stránku; modul se objeví v mřížce modulů.

### meta.json

```json
{
	"id": "hello-check",
	"name": "Hello check",
	"description": "English description shown in the module list.",
	"group": "developer",
	"icon": "hand",
	"settings": true,
	"requires": []
}
```

- `group`: `security | communication | marketing | content | performance | admin | developer` (jiná hodnota spadne do "Other").
- `icon`: název lucide ikony v kebab-case. Seznam podporovaných je v `admin-app/src/app/moduleIcons.tsx`; chybí-li ti
  ikona, napiš `core_request` (neznámá se vykreslí jako Puzzle).
- `settings: true` znamená, že řádek v seznamu modulů je klikací a vede na obrazovku modulu (generický formulář
  nastavení, nebo tvoje `Page.tsx`).
- `requires`: id modulů, které musí být zapnuté (jádro hlídá zapnutí i vypnutí).
- Název a popis lze přeložit klíči `UXS_<ID>_NAME` a `UXS_<ID>_DESC` v ini souboru (id v UPPER_SNAKE, např. `UXS_HELLO_CHECK_NAME`).

### Module.php

```php
namespace Ux1\UxStudio\Modules\HelloCheck;

use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\ModuleContext;

final class Module extends BaseModule
{
	public function boot(ModuleContext $ctx): void { /* listenery */ }
}
```

`boot()` je jediná povinná metoda. Běží při každém požadavku (site, administrator, cli), dokud je modul zapnutý,
proto jen registruj listenery a nic těžkého nedělej. Výjimka v `boot()` jen zapíše chybu do logu `uxstudio`
(`administrator/logs/uxstudio.php`) a web běží dál.

`ModuleContext` nabízí: `id()`, `app()`, `db()`, `client()`, `isAdmin()`, `isSite()`, `on($event, $callable, $priority)`,
`setting($key, $default)`, `settings()`, `t($key, ...$args)`, `http()`.

## 2. Nastavení (`settingsSchema()`)

Pole polí; typy: `toggle | text | textarea | css | number | select | multiselect | color | media | datetime | richtext | password`.

```php
public function settingsSchema(): array
{
	return [
		['key' => 'greeting', 'type' => 'text', 'label' => 'UXS_HELLO_CHECK_GREETING', 'help' => 'UXS_HELLO_CHECK_GREETING_HELP',
		 'default' => 'Hello', 'maxlength' => 120],
		['key' => 'secret_token', 'type' => 'password', 'label' => 'UXS_HELLO_CHECK_SECRET'],
	];
}
```

Volitelné vlastnosti pole: `help`, `default`, `options` (`hodnota => popisek`), `section` (dvě a více sekcí = podzáložky),
`group` + `group_help` (karty), `show_if` (`{key, equals|in|truthy}`, seznam = AND), `wide`, `min`, `max`, `step`, `maxlength`, `placeholder`.
Popisky mohou být jazykové klíče (`UXS_...`), jádro je přeloží.

- Ukládání: tabulka `#__uxstudio_settings` (jeden JSON na modul). Jádro validuje a sanitizuje podle schématu (neznámé klíče zahodí,
  `select` musí být z `options`, `number` se ořízne na `min/max`, `css` nemůže vyskočit z `<style>`, `richtext` projde InputFilterem).
- Čtení v kódu: `$ctx->setting('greeting', '')` nebo `$ctx->settings()` (defaulty doplněné, **hesla dešifrovaná**).
- **`password`** se ukládá šifrovaně (libsodium secretbox, klíč z `$secret` v configuration.php) a do prohlížeče se **nikdy nevrací**:
  GET vrací `"__set__"` (nastaveno) nebo `""`. Odeslání `"__set__"` znamená "beze změny", `""` smaže, jiná hodnota přepíše.
  Hodnotu nikdy nelogovat a nevracet z vlastních rout.
- API: `GET core/settings/<id>` a `POST core/settings/<id>` (tělo = objekt klíč => hodnota; chybějící klíče zůstanou).

## 3. API routy (`routes()`)

```php
public function routes(): array
{
	return [
		'GET ping'        => [$this, 'ping'],
		'POST items/{id}' => [$this, 'saveItem'],
	];
}

public function ping(ApiRequest $r): array
{
	return ['time' => Factory::getDate()->toSql()];     // => {"ok":true,"data":{...}}
}
```

Endpoint: `administrator/index.php?option=com_uxstudio&task=api.dispatch&format=json&route=<module-id>/<path>`.
Jádro před handlerem ověří: přihlášení (401), `core.manage` na `com_uxstudio` (403), **CSRF token v hlavičce `X-CSRF-Token`**
(403 `invalid_token`; platí pro GET i POST), existenci modulu (404), zapnutí modulu (409 `module_disabled`),
ACL modulu (`acl()`, např. `core.admin` pro rizikové moduly) a metodu (405). Handler dostane `ApiRequest`
(`param('id')`, `query()`, `input()` = JSON tělo nebo query, `int()`, `string()`, `bool()`, `body()`, `user()`).

Chyby: `throw new ApiException('Zpráva', 422, 'invalid_value');` => `{"ok":false,"error":{"code","message"}}` se správným HTTP kódem.
Neošetřená výjimka vrátí 500 s obecnou zprávou (detail jde jen do logu `uxstudio`).

Handler vždy sám validuje vstup (typy, délky, whitelist) a používá parametrizované dotazy.

## 4. Migrace (`migrations()`)

```php
public function migrations(): array
{
	return [
		1 => ['CREATE TABLE IF NOT EXISTS `#__uxstudio_hello_check` (...) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci'],
		2 => ['ALTER TABLE `#__uxstudio_hello_check` ADD COLUMN `extra` VARCHAR(50) NOT NULL DEFAULT \'\''],
	];
}
```

- Verze jsou celá čísla; každá se provede jednou (záznam v `#__uxstudio_migrations`), v pořadí. Hotové verze **nikdy neupravuj**, přidej novou.
- Jeden příkaz = jedna položka pole (žádné víceřádkové dávky). Používej `IF NOT EXISTS` kde to jde.
- Tabulky pojmenuj `#__uxstudio_<modul>_<něco>` (retention čistí jen tabulky s prefixem `#__uxstudio_`).
- Spouští se při zapnutí modulu a při instalaci/update balíčku (pro zapnuté moduly). Po `dev-linku` nového kódu
  s migrací zavolej `node bin/jadmin.mjs j5 POST core/migrate` (potřebuje `core.admin`), nebo modul vypni a zapni.

## 5. Eventy

```php
public function boot(ModuleContext $ctx): void
{
	if ($ctx->isSite()) {
		$ctx->on('onAfterRender', [$this, 'inject']);          // Joomla event name
	}
}
public function inject(\Joomla\Event\Event $event): void { $app = $this->ctx->app(); ... }
```

`onAfterInitialise` už při bootu modulů proběhl; poslouchej pozdější události (`onAfterRoute`, `onBeforeCompileHead`,
`onAfterRender`, `onContentPrepare` ...). Frontendové výstupy respektují CSP: žádné inline `onclick`, skripty a styly
přes WebAssetManager (`$app->getDocument()->getWebAssetManager()`), případně s nonce.

Další háčky modulu: `onEnable()` / `onDisable()` (vedlejší efekty; **data se při vypnutí nemažou**),
`acl()`, `dashboardWidget()` (karta na přehledu: `['title','value','text','status']`).

## 6. Úlohy plánovače (`tasks()`) a retence

```php
public function tasks(): array
{
	return ['heartbeat' => ['label' => 'UXS_HELLO_CHECK_JOB_HEARTBEAT', 'callable' => [$this, 'heartbeat'], 'interval' => 'PT6H']];
}
```

`interval`: ISO 8601 (`PT15M`, `PT6H`, `P1D`) nebo počet hodin. Při zapnutí modulu jádro založí řádek v `#__scheduler_tasks`
(typ `uxstudio.run`, parametr `job` = `<id modulu>.<id úlohy>`), při vypnutí ho vypne (stav 0). Interval si admin může
změnit v Systém - Plánované úlohy. Callable vrací text pro log (nebo nic); výjimka = úloha selhala.
Ruční spuštění: `php <site>/cli/joomla.php scheduler:run --id=<id úlohy>`.

`retention()` vrací pravidla `[['table' => '#__uxstudio_x_log', 'column' => 'created', 'days' => 90]]` (`days` může být
i callable). Denní úloha jádra `core.retention` smaže starší řádky. Sloupec je DATETIME v UTC.

## 7. Překlady

- PHP: `language/en-GB/uxs_<id>.ini` a `language/cs-CZ/uxs_<id>.ini`, klíče `UXS_<ID>_...`. Jádro soubor načte pro zapnutý modul
  (i při volání API a při běhu úlohy). V kódu `Text::_()` / `$ctx->t()`.
- SPA: `admin-app/src/modules/<id>/i18n/en.json` a `cs.json`. Zdrojové řetězce jsou anglicky a zároveň klíče:
  `t('Save')` vrátí českou hodnotu z `cs.json`, jinak se zobrazí klíč. `en.json` může být `{}`. Placeholdery `t('Hi {name}', {name})`,
  množné číslo `tn('{n} item', '{n} items', n)` (klíč ve slovníku `"{n} item|{n} items"`, česky tři tvary `1|2-4|5+`).
  Pro modulové texty používej dostatečně jedinečné věty, slovníky všech modulů se slévají do jednoho.

## 8. Obrazovka v administraci (SPA)

Bez `Page.tsx` se vykreslí generický formulář nastavení (`SettingsPanel`). Vlastní obrazovku přidáš souborem
`admin-app/src/modules/<id>/Page.tsx` s default exportem `({ module }: { module: ModuleInfo }) => JSX`; jádro ji najde samo
(`import.meta.glob`), načte líně a ukáže až po zapnutí modulu. Sdílené komponenty: `import {...} from '../../app/ui'`
(`PageHead`, `ToggleSwitch`, `Loading`, `Modal`, `confirm`, `Tabs`/`TabPanel`, `DataTable`, `pushToast`, `ModuleIcon`),
formuláře `import {SettingsPanel, SettingsFields, useModuleSettings} from '../../app/SettingsForm'`,
API klient `import {api} from '../../app/api'` (`api.get<T>('mod-id/route', {query})`, `api.post<T>('mod-id/route', body)`),
datum `formatDate()` z `app/i18n`. Vzhled: jen třídy `uxs-*` a tokeny `--uxs-*` (viz `DESIGN.md` WP verze), tlačítka
`uxs-btn`, `uxs-btn--primary`, `uxs-btn--small`, `uxs-btn--danger`, bez vlastních hex barev a stínů, dark mode funguje automaticky.
Ikony jen z `lucide-react`. Žádné `any` (TypeScript strict).

## 9. Frontendový Joomla modul (`mod_uxstudio_<x>`)

Když funkce potřebuje pozici v šabloně (např. horní lišta), vlastní ji konkrétní feature modul jako standardní Joomla modul
ve složce `packages/mod_uxstudio_<x>/` (manifest `mod_uxstudio_<x>.xml`, `services/provider.php`, `src/Dispatcher/Dispatcher.php`,
`tmpl/default.php`, `language/`). `bin/build.mjs` ho přidá do balíčku sám. Pro vývoj spusť `node bin/dev-link.mjs`:
složku nalinkuje do `modules/` na obou webech a udělá `extension:discover` + `extension:discover:install`.
Nastavení zdroje dat čti z UX Studia (`Kernel::instance()->settings()->get('<id>', 'klíč')`), neduplikuj ho v parametrech modulu.

## 10. Testování přes `bin/jadmin.mjs`

`jadmin.mjs` se přihlásí do administrace (`.env.local`), uloží cookie a posílá požadavky s CSRF tokenem jako SPA:

```bash
node bin/jadmin.mjs j5 GET core/modules                         # seznam modulů
node bin/jadmin.mjs j5 POST core/modules/hello-check '{"enabled":true}'
node bin/jadmin.mjs j6 GET hello-check/ping
node bin/jadmin.mjs j6 POST core/settings/hello-check '{"greeting":"Ahoj","secret_token":"tajne"}'
node bin/jadmin.mjs j5 GET core/meta --no-csrf --expect 403      # bez tokenu musí být 403
node bin/jadmin.mjs j5 page                                       # stránka komponenty: 200 + mount SPA
```

Každou funkci ověř na **J5 i J6**. Navíc:
- `php -l` na všech svých PHP souborech, `npm run typecheck` pro SPA, `npm run build` (nebo `npm run build:spa`).
- Úlohy: `php D:/xampp/htdocs/joomla5/cli/joomla.php scheduler:run --id=<id>`; tabulky kontroluj
  `D:\xampp\mysql\bin\mysql.exe -uroot --default-character-set=utf8mb4 joomla5 -e "SHOW TABLES LIKE 'jos_uxstudio%'"`.
- Frontend: `curl -s http://localhost/joomla5/ | grep 'UX Studio hello-check'`.
- Na J6 zkontroluj, že nic nepotřebuje plugin "Behaviour - Backward Compatibility" (vypni ho a test zopakuj).
- Po změně PHP tříd se může hodit `php <site>/cli/joomla.php cache:clean`.

## 11. Odevzdání

Modulový agent necommituje. Ve výstupu uveď: co je hotové, co jsi ověřil (příkazy a výstupy), `core_requests` (změny jádra,
které potřebuješ) a odchylky od `ARCHITECTURE.md`.
