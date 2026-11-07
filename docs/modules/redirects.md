# Moduly `redirects` a `redirect-404-to-homepage`

Port WordPress modulů Redirects a Redirect404ToHomepage. PHP vrstva je psaná nativně pro Joomlu 5/6, obrazovka v administraci
je React SPA (`admin-app/src/modules/redirects`). Soubory modulů:

- `packages/lib_uxstudio/src/Modules/Redirects/` - `Module`, `Engine`, `Repository`, `Url`, `SafeRegex`, `NotFoundLog`, `Suggester`, `Tester`, `Importer`, `Api`, `language/`
- `packages/lib_uxstudio/src/Modules/Redirect404ToHomepage/` - `Module`, `language/`
- `admin-app/src/modules/redirects/` - `Page.tsx`, `i18n/`

## Redirects (`redirects`)

### Co dělá

- **Pravidla** s kódy 301, 308, 302, 307 a 410 (Gone). Zdroj je přesná adresa (velikost písmen a lomítko na konci se ignorují, volitelně
  rozlišování velikosti písmen) nebo **regulární výraz**. Cílem může být cesta na webu (`/nova`), celá adresa, nebo **trasa Joomly**
  (`index.php?Itemid=101`, `index.php?option=com_content&view=article&id=1:alias&catid=2`), která se při každém přesměrování převede
  přes router webu (funguje s SEF i bez, s přepisováním URL i bez a přežije změnu aliasu).
- **Parametry v URL**: u každého pravidla režim `default` (podle nastavení modulu), `exact` (žádné další parametry), `ignore`, `pass`.
  Zdroj s parametry (`/a?b=1`) zasáhne jen tuto kombinaci a má přednost před pravidlem bez parametrů.
- **Řetězy a smyčky**: při uložení se cíl, který sám míří jinam, přepíše rovnou na konec řetězu (`A->B`, `B->C` = `A->C`), starší pravidla
  mířící na nový zdroj se přesměrují, smyčka se odmítne. Audit umí řetězy hromadně opravit.
- **Bezpečné regulární výrazy** (`SafeRegex`, ekvivalent WP `SafeRegex`): předmět se ořízne na 1 KB, `pcre.backtrack_limit` se pro volání sníží
  na 20 000 (a vrátí), chyba = „nezasáhlo“, při uložení se vzorek proběhne nad „zlými“ řetězci a katastrofický vzorek (např. `(a+)+$`)
  se odmítne. Vzorek je omezen na 500 znaků. V cíli lze použít `$1`..`$9`.
- **Použití**: počítadlo zásahů a čas posledního zásahu (jeden UPDATE před odesláním přesměrování, vypnutelné), reset jednoho/všech.
- **Log 404**: jedna řádka na adresu (počet, první/poslední výskyt, odkazující web, user agent, bez IP adres), strop 20 000 řádků, retence ve
  dnech (jádro, `retention()`), denní úloha plánovače `redirects.cleanup` drží strop.
- **Návrh přesměrování z logu**: u každé nevyřešené adresy se porovná poslední segment adresy (bez přípony a číselného prefixu) s aliasy a
  názvy publikovaných článků, kategorií a položek menu. Tlačítko „301 -> {název} (87 %)“ vytvoří pravidlo jedním kliknutím (cíl se uloží jako
  trasa Joomly), „Přesměrovat…“ otevře formulář, „410“ označí obsah jako zmizelý.
- **Automatické přesměrování při změně URL článku** (monitor aliasů): `onContentBeforeSave`/`onContentAfterSave` pro `com_content.article` a
  `com_content.form`; když publikovaný článek změní alias nebo kategorii, vznikne 301 ze staré adresy na novou a případné pravidlo na nové adrese
  (změna zpět) se smaže.
- **Import/export CSV** (`source,target,code,note` + `match_type,enabled,hits,position,case_sensitive,query_mode,last_hit`, oddělovač čárka nebo
  středník, ochrana proti vzorcům v tabulce), „dry run“, volba `on_conflict` (update/skip/error). **Import z Přesměrování Joomly**
  (`#__redirect_links` s vyplněnou novou adresou, zakázané zůstanou zakázané, počítadla se přenesou).
- **Test adresy** (která pravidla zasáhnou, kolik kroků, kam to vede) včetně živé kontroly přes HTTP po jednotlivých krocích, a **audit**
  (smyčky, řetězy, dlouhé dočasné přesměrování, hromadné přesměrování na úvodku = soft 404, neplatné regulární výrazy, volitelně živá kontrola cílů).

### Napojení na Joomlu

`Engine` poslouchá dvě události s nejvyšší prioritou, protože Joomla selže na dvou místech:

- `onAfterRoute` - adresa se rozpoznala (menu, článek). Pravidlo má přednost, takže může přepsat existující obsah (410 nebo přesměrování).
- `onError` s kódem 404 - router nebo komponenta adresu odmítly. Zkusí se pravidla a nezachycená 404 se zaloguje.

410 se vykreslí chybovou stránkou šablony (výjimka s kódem 410, `X-Robots-Tag: noindex`). Zasahuje se jen do front-endových GET/HEAD
požadavků (ne administrace, API, CLI, POST). Adresa se normalizuje bez podadresáře webu a bez `/index.php/` (weby bez přepisování URL),
takže pravidlo `/stara` platí pro `/stara` i `/index.php/stara`. Cíl typu `/cesta` se na webu bez přepisování URL automaticky doplní o
`/index.php` (pokud to není skutečný soubor). Trvalá přesměrování mají omezenou cache prohlížeče (výchozí 1 hodina, nastavitelné).

**Koexistence s com_redirect**: moduly nekolidují. Plugin „System - Redirect“ Joomly reaguje na `onError` s normální prioritou, naše pravidla
mají prioritu nejvyšší, takže vyhrají. Nezachycená 404 pak projde i pluginem Joomly (ten ji zapíše do com_redirect). Když je plugin zapnutý,
obrazovka ukáže upozornění a nabídne import jeho přesměrování (`joomla_redirect_plugin` ve `GET redirects/status`).

### Nastavení

| Klíč | Výchozí | Popis |
|---|---|---|
| `monitor_slugs` | zapnuto | automatické 301 při změně aliasu/kategorie článku |
| `pass_query` | zapnuto | přenést parametry (utm, gclid) na cíl |
| `permanent_cache_ttl` | 3600 | cache prohlížeče pro 301/308 v sekundách (0 = bez cache) |
| `track_hits` | zapnuto | počítat zásahy pravidel |
| `log_404` | zapnuto | logovat 404 |
| `log_404_retention_days` | 90 | stáří záznamů 404 (0 = nemazat) |

### API (`administrator/index.php?option=com_uxstudio&task=api.dispatch&route=redirects/...`)

`GET status`, `GET rules`, `POST rules` (hromadné vytvoření/aktualizace `{items,dry_run,on_conflict}`), `POST rules/{id}` (částečná úprava),
`POST rules/delete {ids}`, `POST rules/reset-hits {ids|all}`, `POST test {urls,live}`, `POST audit {live,fix_chains}`, `GET 404`
(`unresolved_only`, `suggest`, `search`, `limit`, `offset`), `POST 404/delete {ids|all}`, `GET suggest?url=&title=`, `POST import/csv`,
`POST import/joomla`, `GET export`. Tabulky: `#__uxstudio_redirects`, `#__uxstudio_redirects_404`.

### Rozdíly proti WP verzi

- **Chybí podmínky pravidel** (přihlášení, role, referrer, user agent, cookie, IP, hlavička; více pravidel na jednu adresu): tabulka má jedno
  pravidlo na zdroj. Tester proto nemá simulaci návštěvníka. (Nepožadováno zadáním, dá se doplnit jako migrace 2 + `Conditions`.)
- **Bez vazby na příspěvek** (`target_post_id`): v Joomle je ekvivalentem trasa `index.php?...`, kterou nabízí návrh z logu; ručně zadaný
  cíl je text. Cíl se při změně aliasu cílového článku sám nezmění (u trasy ano).
- **Bez AI nástrojů (Abilities/MCP)** a bez „site index“ endpointu; místo toho `GET suggest`.
- **Bez importu z pluginu Redirection**, místo něj import z com_redirect.
- Monitor změn URL sleduje jen **články** (alias, kategorie). Změna aliasu kategorie nebo položky menu staré adresy podřízených článků
  nepřesměruje. Přesměrování z odstraněné/přesunuté stránky Joomla neřeší (stejně jako WP verze jen u příspěvků).
- Zásahy se zapisují hned před odesláním přesměrování (Joomla po `redirect()` ukončí aplikaci), ne po odeslání odpovědi.
- Varování „zdroj má živý obsah“ (WP `url_to_postid`) tu není; zdroj, který míří na existující stránku, ukáže test s živou kontrolou.

## Redirect 404 to Homepage (`redirect-404-to-homepage`)

Na `onError` s kódem 404 (nejnižší priorita) přesměruje na úvodní stránku (301, volitelně 302). Přeskočí se, když požadavek už vyřídilo
pravidlo modulu Redirects (včetně 410, `Engine::handled()`), když plugin Joomly přesměroval (ten po přesměrování skončí dřív), pro
ne-HTML formáty (`format=json`...), pro POST a když chybí sama úvodní stránka (smyčka). Navíc proti WP verzi:

- `code` (301/302) a `skip_assets` (výchozí zapnuto): chybějící obrázek, CSS, JS, PDF apod. zůstane skutečnou 404 místo HTML úvodní stránky.

## Změny po revizi

- **Oprávnění**: všechny zapisující routy (`rules`, `rules/{id}`, `rules/delete`, `rules/reset-hits`, `404/delete`, `import/*`) vyžadují `core.admin`
  (pravidla přesměrují celý web, ekvivalent WP `manage_options`); čtecí routy a test/audit stačí `core.manage`.
- **Dosazení `$1`..`$9`**: zachycené skupiny se znovu zakódují (`rawurlencode`, `/ ? = &` zůstávají), absolutní cíl musí po dosazení mít stejný
  hostitel jako šablona pravidla (`$n` uprostřed hostitele smí nahradit jen znaky `a-z0-9.-`, `$n` na konci hostitele nesmí přidat nic, zachycení musí začínat `/`), jinak pravidlo nezasáhne. Zbytková nejednoznačnost:
  klíč požadavku je rozkódovaný, takže `%3F` v cestě se v cíli objeví jako `?` (zůstává kvůli předávání dotazů).
- **Log 404**: `unresolved_only` se filtruje při průchodu logem po dávkách (limit 2000 řádků na stránku, 6000 absolutně, za limitem je `total` odhad),
  `full_url` jde přes `Url::absolute()` (správně i s `/index.php`).
- **Návrhy**: jen články/kategorie/menu s veřejným přístupem, publikované, v okně publish_up/publish_down a v publikované kategorii.
- **CSV import**: `fgetcsv` (buňky s novým řádkem v uvozovkách), úvodní apostrof se odstraňuje jen před `= + - @ TAB CR`, řádek bez cíle a bez
  kódu je chyba (410 jen výslovně).
- **`Url::isInternal`** porovnává i port a schéma (http na https webu se bere jako vnitřní, ostatní rozdíly ne); živá kontrola posílá přímý curl
  jen na stejný origin (`Url::isSameOrigin`).

## Známá omezení

- `SafeRegex` je uvnitř modulu Redirects; kdyby ho potřeboval další modul, patří do jádra (viz `core_requests`).
- Živá kontrola cílů používá přímý curl na vlastní adresy webu a `Core\Http` (se SSRF guardem, sleduje max. 3 přesměrování) pro cizí.
