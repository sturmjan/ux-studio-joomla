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
- **Zakázaná (deprecated) volání** - použij náhradu:
  `$db->getQuery(true)` -> `$db->createQuery()`; `Factory::getDbo()` -> `$ctx->db()`; `Factory::getUser()` -> `$request->user()` / `$app->getIdentity()`;
  `Factory::getDocument()` -> `$app->getDocument()`; `Factory::getSession()` -> `$app->getSession()`; `CMSObject`, `JFactory`, `J*` aliasy, `JRequest`;
  `Joomla\CMS\Filesystem\*` -> `Joomla\Filesystem\*`; plugin vytvářený s `DispatcherInterface` jako 1. argumentem (od J5.4 deprecated).
- Necommituj a **nikdy nepoužívej `git add -A`** (v repu pracují souběžně další moduly): kdyby sis commit přece dělal, přidávej jen své soubory.
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
- `icon`: název lucide ikony v kebab-case ze sdíleného seznamu `admin-app/src/app/moduleIcons.tsx`. Potřebuješ jinou? Žádný `core_request`:
  vytvoř `admin-app/src/modules/<id>/icon.tsx` s `import { Fingerprint } from 'lucide-react'; export default Fingerprint;` (má přednost; bez obojího Puzzle).
- `dev`: `true` pro vzorové a testovací moduly - nejdou do produkčního zipu ani do SPA bundlu (`bin/build.mjs`, `--with-dev` je zahrne).
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
proto jen registruj listenery a nic těžkého nedělej. Výjimka (i chyba parsování třídy) v `boot()` jen zapíše chybu do logu `uxstudio`
(`administrator/logs/uxstudio.php`), ostatní moduly se nabootují normálně a modul je v SPA označen „Chyba startu“ (`error` v `core/modules`).

`ModuleContext` nabízí: `id()`, `app()`, `db()`, `client()`, `isAdmin()`, `isSite()`, `on($event, $callable, $priority)`,
`setting($key, $default)`, `settings()`, `t($key, ...$args)`, `http()`, `useScript($file)`, `useStyle($file)`, `assetUrl($file)`,
`store()`, `runJobSoon($job, $delay)`, `notice($key, $text, $level, $link)` / `clearNotice($key)` (viz §6 a §6b).

**Kontext mimo `boot()`:** handlery rout, úlohy plánovače i CLI běží na požadavku, kde `boot()` neproběhl. Použij `$this->ctx()`
(`final protected` v `BaseModule`, vytvoří se líně) a nekopíruj si vlastní privátní `context()`.

**Frontendové assety (JS/CSS):** soubory dej do `packages/lib_uxstudio/media/modules/<id>/` (publikují se do
`media/lib_uxstudio/modules/<id>/`) a z listeneru (např. `onBeforeCompileHead`) zavolej `$this->ctx()->useScript('muj.js')`
nebo `useStyle('muj.css')` (WebAssetManager, verze podle souboru, CSP-friendly, bez inline kódu). Atributy: `useScript('x.js', ['type' => 'module'])`.
Soubory v `media/modules/<id>/` musí existovat (jinak se asset tiše přeskočí). `dev-link` složku nalinkuje do `media/lib_uxstudio`.

## 2. Nastavení (`settingsSchema()`)

Pole polí; typy: `toggle | text | textarea | css | number | select | multiselect | color | media | datetime | richtext | password | repeater`.

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

Typy navíc:
- `media`: pole s tlačítkem „Vybrat“ (Správce médií Joomly v modalu); hodnota je cesta `images/…` (nebo `http(s)://` URL), server odmítne `..` a cizí schémata.
- `richtext`: jednoduché WYSIWYG (tučně, kurzíva, podtržení, nadpis, seznamy, odkaz, vyčistit formátování, přepínač na HTML); server hodnotu
  projede `InputFilter` a odkazy/`src` omezí na `http(s)`, `mailto`, `tel`, relativní a `#`.
- `repeater`: seznam řádků z podpolí. `['key' => 'links', 'type' => 'repeater', 'label' => ..., 'max' => 20, 'add_label' => '...', 'fields' => [[key,type,label,default], ...]]`.
  Podpole smí být jakýkoli typ kromě `repeater` a `password`. Hodnota je pole objektů (`[{"title":"..","url":".."}, ...]`), každý řádek se validuje podle podpolí
  (neznámé klíče zahodí, chybějící doplní z defaultu), překročení `max` (výchozí 50) vrátí 422.
- Výchozí hodnota `text` / `textarea` / `richtext` může být jazykový klíč (`'default' => 'UXS_MYMOD_DEFAULT_SUBJECT'`): jádro ji přeloží do jazyka webu
  (`$ctx->setting()` i formulář v SPA). Klíče bez překladu zůstanou, jak jsou.

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
- **Nastavení jádra** (`core/settings/core`, jen `core.admin`, obrazovka „Nastavení“ v postranním panelu): `client_ip_mode` (`none | cloudflare | xff`,
  výchozí `cloudflare`) a `trusted_proxies`. Čti je přes `Core\CoreSettings::get('client_ip_mode')`, IP přes `Core\ClientIp` (§6b).

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
(`param('id')`, `query($key)`, `queryAll()` = celá query string, `input()` = JSON tělo nebo query, `all()` = tělo přes query, `int()`, `string()`, `bool()`, `body()`, `user()`,
`files()` / `filesIn($group)` jen u veřejných multipart rout).

Chyby: `throw new ApiException('Zpráva', 422, 'invalid_value');` => `{"ok":false,"error":{"code","message"}}` se správným HTTP kódem.
Neošetřená výjimka vrátí 500 s obecnou zprávou (detail jde jen do logu `uxstudio`).

Handler vždy sám validuje vstup (typy, délky, whitelist) a používá parametrizované dotazy.

**Přísnější ACL na jedné routě:** `acl()` modulu platí pro všechny routy; rizikovou akci zpřísníš tvarem
`'POST purge' => ['handler' => [$this, 'purge'], 'acl' => 'core.admin']` (musí projít ACL modulu i routy).

### Veřejné (site) routy - `publicRoutes()`

Pro formuláře, consent, webhooky a Centrální aplikaci (bez přihlášení): jeden endpoint
`index.php?option=com_uxstudio&task=public.dispatch&format=json&route=<module-id>/<path>` (stejná JSON obálka).

```php
public function publicRoutes(): array
{
	return [
		// browser form: no auth, rate limited, a present Origin header must be this site
		'POST subscribe' => ['handler' => [$this, 'subscribe'], 'auth' => 'none', 'limit' => [10, 60], 'max_body' => 16384],
		// signed server-to-server call
		'POST webhook' => ['handler' => [$this, 'webhook'], 'auth' => 'hmac', 'secret' => fn () => (string) $this->ctx()->setting('secret', '')],
		// bearer token (also accepted in the X-UXS-Token header when the host strips Authorization)
		'GET status' => ['handler' => [$this, 'status'], 'auth' => 'token', 'secret' => fn () => (string) $this->ctx()->setting('secret', '')],
	];
}
```

- `auth`: `none` (výchozí) | `token` (`Authorization: Bearer <secret>`) | `hmac` (`X-UXS-Timestamp` + `X-UXS-Signature` = hex `hmac_sha256("<timestamp>.<raw body>", secret)`, tolerance 5 minut).
  Prázdné `secret` odmítne každý požadavek. Handler dostane `ApiRequest`; surové tělo je v `$r->raw()`.
- `limit`: `[počet, okno v sekundách]` na IP a routu (výchozí `[30, 60]`), po překročení 429 + `Retry-After`. `max_body`: bajty (výchozí 65536), jinak 413.
  Vlastní limity jinde: `(new \Ux1\UxStudio\Core\RateLimiter($ctx->db()))->hit('klic', 5, 600)` vrací 0 (povoleno) nebo počet sekund do konce okna.
- `max_files` + `max_file_size` zapnou **multipart/form-data**: nahrané soubory dostaneš v `$r->files()` (klíč = název inputu, vnořené názvy spojené tečkou, `files[photo][]` => `files.photo`;
  `$r->filesIn('files')` vrátí `['photo' => [...]]`), každý záznam `{name, type, size, tmp_name, error}`. Jádro ověří `is_uploaded_file`, chyby uploadu, počet a velikost (413), textové části jsou v `$r->body()`
  jako surové řetězce (`fields[email]=..` => `body()['fields']['email']`). `max_body` platí pro celý požadavek. Jméno a typ od klienta jsou nedůvěryhodné: obsah (finfo) si zkontroluj sám,
  `tmp_name` přečti hned (PHP ho po požadavku smaže). Vzor: modul Forms (přijímá multipart i starý JSON + base64). Multipart se přijímá jen na routě s `max_files` > 0 nebo `'multipart' => true` (jen textové části) a nikdy na `hmac` routě (tělo z `$_POST` se nepodepisuje): jinak 415 `unsupported_media_type`.
- Klientská IP: `Core\ClientIp::current()` (rate limit veřejného endpointu i `RateLimiter::hitClient()` ji používají sami). Nikdy nečti `REMOTE_ADDR` / `CF-Connecting-IP` napřímo.
- Není tu session, ACL ani CSRF token: handler musí vše validovat sám a nikdy nevracet interní data. Vstup je nedůvěryhodný.

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
- Spouští se při zapnutí modulu a **líně při prvním požadavku do administrace nebo CLI po instalaci/update balíčku i po změně souborů
  modulu** (`Core\Upgrader`: otisk verze, `Module.php`/`meta.json`, verzí migrací a deklarovaných úloh; instalační skript nic nemigruje,
  protože v jeho požadavku jsou ještě staré třídy). Ruční spuštění: `node bin/jadmin.mjs j5 POST core/migrate` (potřebuje `core.admin`).

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

**Jednorázový běh navíc:** `$this->ctx()->runJobSoon('scan')` (např. z `onEnable()`, první kontrola hned po zapnutí) posune příští spuštění úlohy na „teď“
(`$delaySeconds` pro odklad); proběhne při nejbližším ticku plánovače (lazy scheduler v administraci, cron, `scheduler:run`), ne v tomto požadavku, a úloha pak běží dál v intervalu. Vrací `false`,
když modul takovou úlohu nedeklaruje nebo je vypnutý.

`interval`: ISO 8601 (`PT15M`, `PT6H`, `P1D`) nebo počet hodin. Při zapnutí modulu jádro založí řádek v `#__scheduler_tasks`
(typ `uxstudio.run`, parametr `job` = `<id modulu>.<id úlohy>`), při vypnutí ho vypne (stav 0). Interval si admin může
změnit v Systém - Plánované úlohy; po aktualizaci se hodnota přepíše jen tehdy, když jsi v kódu změnil deklarovaný interval nebo název.
Nová úloha zapnutého modulu se po aktualizaci založí sama, úloha, kterou modul přestal deklarovat, jde do koše. Callable vrací text pro log (nebo nic); výjimka = úloha selhala.
Ruční spuštění: `php <site>/cli/joomla.php scheduler:run --id=<id úlohy>`.

`retention()` vrací pravidla `[['table' => '#__uxstudio_x_log', 'column' => 'created', 'days' => 90]]` (`days` může být
i callable). Denní úloha jádra `core.retention` smaže starší řádky. Sloupec je DATETIME v UTC.

## 6b. Sdílené služby jádra (`Ux1\UxStudio\Core`)

Nepiš si vlastní verze, použij tyto:

- **`ClientIp`** - `ClientIp::current()` je IP návštěvníka podle nastavení jádra (výchozí `cloudflare`: hlavičce `CF-Connecting-IP` se věří jen z oficiálních rozsahů Cloudflare; `xff`: `X-Forwarded-For`
  zprava přes důvěryhodné proxy; `none`). Pomocné: `isLoopback()`, `isLocal()` (loopback + privátní + link-local), `isEdge()`, `isProtected()` (local nebo edge), `matches($ip, $seznam)`, `parseList()`, `normalize()`.
  **Loopback a privátní adresy se nikdy automaticky nebanují** (trvalé zápisy: ban, auto-ban) a jen se hlásí (log `would_ban`, `local_ip` v přehledu a varování v SPA); dočasná omezení (rate limit, burst, lockout loginu) se na ně vztahují dál, aby chybně nastavený režim IP potichu nevypnul ochranu. Výjimka z omezení je jen pro Cloudflare / důvěryhodné proxy (`isEdge()`). `Modules\SecurityOptimization\ClientIp` je tenký alias.
- **`Http`** - `$ctx->http()->request($method, $url, $options)`; volby navíc: `follow_redirects` (výchozí `true`, nejvýš `max_redirects` = 3 hopy), `false` vrátí 3xx odpověď samotnou.
  Výsledek má `url` (adresa, ze které odpověď přišla), `redirected`, `redirect_chain[]` a `redirect_to` (absolutní `Location` nenásledovaného přesměrování, jinak `null`).
  S `allow_private` (pevná důvěryhodná adresa, např. vývojová CA na privátní IP) se přesměrování na jiný **origin** (scheme + host + port) odmítne (`ApiException` `redirect_blocked`). U ostatních požadavků se při přesměrování na jiný origin vynechají hlavičky `Authorization`, `Cookie`, `Proxy-Authorization` a `X-UXS-*`. Podepisované požadavky (HMAC přes URL) posílej s `follow_redirects => false`.
- **`Mail`** - `Mail::send(['to' => .., 'subject' => .., 'body' => .., 'html' => true, 'reply_to' => .., 'attachments' => [cesta, ...], 'source' => 'UX Studio: Můj modul'])` vrací `bool` (`Mail::lastError()`),
  `Mail::create()` vrátí Joomla mailer pro složitější zprávy. Zprávy jdou přes sdílený `MailHub`: použije se spojení z modulu smtp-email a zapíše je email-log, když jsou zapnuté;
  když ne, odejdou nativním mailerem Joomly beze změny. Nikdy nevolej `MailerFactoryInterface::createMailer()` napřímo. **Povinné: žádné `mail()`, `PHPMailer` ani `Factory::getMailer()` v modulu** - jinak zpráva mine SMTP spojení i email-log (typicky `mail() vrátil false` v logu). Vždy `Mail::send([... 'source' => 'UX Studio: Název modulu'])` nebo `Mail::create()`.
- **`SafeRegex`** - regulární výrazy zadané administrátorem a spouštěné na návštěvnickém vstupu (`delimit`, `match`, `compiles`, `probe`; zkrácený subjekt, nízký backtrack limit, ReDoS zkouška při uložení).
- **`RateLimiter`** - `hit($klíč, $limit, $okno)` a `hitClient($název, $limit, $okno)` (klíč doplní IP z `ClientIp`).
- **`Store`** (`$ctx->store()`) - obecné key/value úložiště modulu v `#__uxstudio_store` (hodnota cokoli pro `json_encode`): `get($k, $default)`, `set($k, $v, $ttlSekund = 0)`, `has`, `delete`,
  `increment($k, $o = 1, $ttl = 0)` (atomické), `keys($prefix)`, `deletePrefix($prefix)`. Prošlé hodnoty denně maže `core.retention`. Nic tajného (na to je pole `password`). Pro tabulky s vlastní strukturou pořád patří migrace modulu.
- **`Hooks`** - jednoduché filtry mezi moduly: `Hooks::add('jmeno', callable, $priorita)` v `boot()`, `Hooks::filter('jmeno', $hodnota, ...$args)` u vlastníka. Dnes existuje `csp.sources`
  (security-optimization): callback dostane a vrátí pole `direktiva => [zdroje]`, např. `$s['form-action'][] = 'https://app.ux1.cz'`; zdroje projdou validací CSP builderu. ca-connector tím přidává adresu CA do `form-action`
  v administraci (párovací formulář), takže vynucené CSP párování nezablokuje.
- **Upozornění v administraci** - `$ctx->notice('klic', 'Text (bez HTML)', 'warning|info|error', 'index.php?option=com_uxstudio#/module?id=muj-modul')` ukáže zprávu na přehledu Joomly (com_cpanel) a na obrazovce
  UX Studia, jen uživatelům s `core.manage`, dokud ji modul nestáhne `$ctx->clearNotice('klic')` (nebo dokud je modul zapnutý). Odkaz musí být `index.php?...`.
- **`ModuleRegistry`** - načtení nebo start jednoho modulu, který selže, zaloguje chybu a označí modul, ostatní nezasáhne (`$registry->error($id)`); chyba se uloží i do úložiště jádra, takže ji SPA ukáže i když selhala jen strana webu (site), a smaže se po úspěšném startu stejného klienta.

## 7. Překlady

- PHP: `language/en-GB/uxs_<id>.ini` a `language/cs-CZ/uxs_<id>.ini`, klíče `UXS_<ID>_...`. Jádro soubor načte pro zapnutý modul
  (i při volání API a při běhu úlohy). V kódu `Text::_()` / `$ctx->t()`.
- SPA: `admin-app/src/modules/<id>/i18n/en.json` a `cs.json`. Zdrojové řetězce jsou anglicky a zároveň klíče:
  `t('Save')` vrátí českou hodnotu z `cs.json`, jinak se zobrazí klíč. `en.json` může být `{}`. Placeholdery `t('Hi {name}', {name})`,
  množné číslo `tn('{n} item', '{n} items', n)` (klíč ve slovníku `"{n} item|{n} items"`, česky tři tvary `1|2-4|5+`).
  **Slovník modulu je oddělený** od jádra i od ostatních modulů: v `Page.tsx` na úrovni modulu napiš
  `const { t, tn } = createT( '<id>' );` (`import { createT } from '../../app/i18n'`). `t('Greeting')` hledá nejdřív v
  `modules/<id>/i18n/cs.json`, pak ve slovníku jádra (obecná slova jako Save, Refresh, Time jádro přeložená má), jinak vrátí klíč.
  Globální `t` z `app/i18n` vidí jen jádro: modulové texty jím nepřeložíš.

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

> **POZOR: na nalinkovaný web (dev-link) se zip neinstaluje ani neaktualizuje.** Instalátor Joomly před kopírováním starou
> knihovnu odinstaluje (`removeFiles`) a přes junction by smazal zdrojové soubory v repu, tedy jádro i všechny moduly, i necommitnuté.
> Instalační skripty to poznají a instalaci odmítnou. Potřebuješ-li otestovat instalaci zipu: `node bin/dev-link.mjs --unlink`
> (odstraní junctiony, zkopíruje soubory zpět), nainstaluj, a pak znovu `node bin/dev-link.mjs`. Pro běžné ověřování funkcí instalaci
> nepotřebuješ: stačí dev-link a `jadmin.mjs`.

`jadmin.mjs` se přihlásí do administrace (`.env.local`), uloží cookie a posílá požadavky s CSRF tokenem jako SPA:

```bash
node bin/jadmin.mjs j5 GET core/modules                         # seznam modulů
node bin/jadmin.mjs j5 POST core/modules/hello-check '{"enabled":true}'
node bin/jadmin.mjs j6 GET hello-check/ping
node bin/jadmin.mjs j6 POST core/settings/hello-check '{"greeting":"Ahoj","secret_token":"tajne"}'
node bin/jadmin.mjs j5 GET 'redirects/404?limit=5'               # GET s query stringem (nebo jako 4. argument: ... GET redirects/404 'limit=5&page=2')
node bin/jadmin.mjs j5 POST redirects/rules @rules.json           # tělo POST ze souboru (@cesta, relativně k aktuální složce; vyhne se problémům s uvozovkami v shellu)
node bin/jadmin.mjs j5 GET core/meta --no-csrf --expect 403      # bez tokenu musí být 403
node bin/jadmin.mjs j5 page                                       # stránka komponenty: 200 + mount SPA
```

Každou funkci ověř na **J5 i J6**. Navíc:
- `php -l` na všech svých PHP souborech, `npm run typecheck` pro SPA, `npm run build` (nebo `npm run build:spa`).
- Úlohy: `php D:/xampp/htdocs/joomla5/cli/joomla.php scheduler:run --id=<id>`; tabulky kontroluj
  `D:\xampp\mysql\bin\mysql.exe -uroot --default-character-set=utf8mb4 joomla5 -e "SHOW TABLES LIKE 'jos_uxstudio%'"`.
- Frontend: `curl -s http://localhost/joomla5/ | grep 'UX Studio hello-check'`.
- Veřejné routy: `curl -s "http://localhost/joomla5/index.php?option=com_uxstudio&task=public.dispatch&format=json&route=hello-check/hello"`.
- Na J6 zkontroluj, že nic nepotřebuje plugin "Behaviour - Backward Compatibility" (vypni ho a test zopakuj).
- Po změně PHP tříd se může hodit `php <site>/cli/joomla.php cache:clean`.

## 11. Odevzdání

Modulový agent necommituje. Ve výstupu uveď: co je hotové, co jsi ověřil (příkazy a výstupy), `core_requests` (změny jádra,
které potřebuješ) a odchylky od `ARCHITECTURE.md`.
