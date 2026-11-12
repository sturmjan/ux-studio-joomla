# Modul performance-optimization (Optimalizace výkonu)

Kód: `packages/lib_uxstudio/src/Modules/PerformanceOptimization`, obrazovka `admin-app/src/modules/performance-optimization`. Skupina `performance`.
Protějšek WP modulu PerformanceOptimization jen názvem: WP verze je zdravotní kontrola databáze (revize, transienty), na Joomle nemá smysl.
Tato verze zrychluje frontend. Co Joomla umí sama (cache stránek, GZIP), se **nepřepisuje**, jen se zobrazí a doporučí.

## Co dělá

Vše na frontendu (`site`), jen pro HTML dokumenty. Listenery: `onBeforeCompileHead` (hlavičkové odkazy, assety) a `onAfterRender` s nejnižší
prioritou (běží jako poslední, takže přepíše i značky, které přidaly jiné moduly).

1. **Líné načítání obrázků** (`lazy_images`, výchozí zapnuto): `loading="lazy"` + `decoding="async"` u `<img>` bez atributu `loading`.
   Prvních `lazy_skip` (1) **obsahových** obrázků zůstane eager (nad ohybem): logo a malé ikony (`width`/`height` < 150, nebo „logo“ ve značce)
   ani obrázek s vlastním `loading` okno nespotřebují (Cassiopeia má logo `loading="eager"` jako první `<img>`). Obrázek bez `loading` před
   vyčerpáním okna, který není obsahový, se líně nenačítá. Značky se hledají tokenizérem celé značky, takže `<img` uvnitř hodnoty atributu
   (`data-bs-content`, JSON v `data-*`) zůstane beze změny; stejně `<script`/`<!--` v hodnotě atributu. Obrázky s `data-src`/`data-lazy*` (jiná lazy knihovna) se nechají být.
   Výjimky: seznam textů (`lazy_exclude`), shoda se hledá v celé značce (třída, název souboru).
2. **Priorita hlavního obrázku** (`lcp_priority`, zapnuto): `fetchpriority="high"` na prvním větším obrázku, který není výslovně `loading="lazy"` (ne logo, ne ikona < 150 px podle
   atributů `width`/`height`). Vyhodnocuje se nezávisle na `lazy_skip` (funguje i s 0) a obrázek s prioritou se nikdy nenastaví jako lazy.
3. **Líné iframy** (`lazy_iframes`, zapnuto): `loading="lazy"` u `<iframe src>`.
4. **Odložení skriptů** (`defer_scripts`, **výchozí vypnuto**): `defer` u externích klasických skriptů bez `async`/`defer`/`nomodule`, ne u
   `type="module"` ani JSON. Výjimky (`defer_exclude`, výchozí `jquery`): inline skripty, které hned používají odloženou knihovnu, by se rozbily.
   Skripty uvnitř komentářů a `<noscript>` se neupravují.
5. **Odstranění assetů**: `remove_jquery_migrate` (vypne asset `jquery-migrate`) a seznam `disable_assets` (`script:název`, `style:název`, nebo
   jen `název` pro oba typy). Používá se `WebAssetManager::disableAsset`; **asset, na kterém závisí jiný aktivní asset, Joomla znovu zapne**
   (např. `fontawesome` závisí na šabloně Cassiopeia), takže se takto odstraní jen samostatné assety. Neznámé názvy se tiše přeskočí.
6. **Preconnect / preload** (`preconnect`, `preload`): `<link rel="preconnect">` pro `http(s)://host[:port]` (za adresu lze připsat slovo `crossorigin`, pro písma a fetch;
   bez něj je spojení pro obyčejné požadavky: skripty GTM/GA, obrázky) a
   `<link rel="preload" as=...>` pro řádky `adresa druh [typ]` (`druh` = style, script, font, image, fetch; font dostane
   `crossorigin=anonymous` a typ podle přípony). Adresa musí být absolutní http(s) nebo začínat `/`; cesta začínající `/` se u webu v podsložce doplní o podsložku sama
   (`/media/...` -> `/joomla5/media/...`), `&` v adrese se vypíše jako `&amp;`. Neplatné řádky se zahodí (validace na serveru).
7. **Minifikace HTML** (`minify_html`, vypnuto) a **odstranění komentářů** (`strip_comments`, vypnuto): v textu mezi značkami se sloučí bílé
   znaky. Značky (hodnoty atributů), `<script>`, `<style>`, `<pre>`, `<textarea>`, `<template>`, `<noscript>` zůstávají beze změny.
   Podmíněné komentáře (`<!--[if`) a `googleon/off` se nemažou. Stránka s řídicími znaky 0x01/0x02 nebo chybou regexu se vrátí nedotčená.
8. **Cache hlavičky pro statické soubory** (blok v kořenovém `.htaccess`): `mod_expires` + `Cache-Control: public, max-age=N` pro obrázky,
   písma, CSS, JS. Doba `cache_ttl` (týden / měsíc / půl roku / rok). **Nikdy se nezapíše samo**: jen routou `POST htaccess/apply` s
   `confirm: true` (jen `core.admin`, na obrazovce přes potvrzovací dialog). Před zápisem se původní soubor zkopíruje do
   `administrator/logs/uxs-performance/htaccess-<čas>-<náhodně>.bak` (složka má `.htaccess` s `Require all denied`, `Options -Indexes` a
   `index.html`; ověřeno 403; drží se posledních 5 záloh). Mimo blok `# BEGIN/END UX1 Studio performance-optimization` se nic nemění. Blok se hledá po řádcích mezi značkami (bez regexu); když
   značka BEGIN nemá END, soubor se nechá být (chyba, nic se nepřepíše). Zálohu dělá i odebrání bloku.
   Odebrání: tlačítko / `POST htaccess/remove`, a také automaticky při **vypnutí modulu** (`onDisable`). Pokud po odebrání zbude prázdný
   soubor (vytvořili jsme ho my), smaže se. Odmítne se na serveru, který není Apache/LiteSpeed (`not_apache`); na nginx je třeba hlavičky
   nastavit v konfiguraci serveru.

## Obrazovka

- **Přehled**: (a) „Co už umí Joomla“ - plugin Cache stránek, Cache v Globální konfiguraci, GZIP, OPcache, Debug (jen čtení + kam to zapnout);
  (b) stav a tlačítka bloku `.htaccess` + seznam záloh; (c) **kontrola úvodní stránky**: server načte vlastní úvodní stránku (`Core\Http`,
  `allow_private` jen pro tuto pevnou adresu, přesměrování na jiného hostitele jádro odmítne) a spočítá odezvu, velikost HTML, kompresi
  (`Content-Encoding`), blokující skripty, líné obrázky, počet stylů, inline JS, komentáře a hlavičky prvního lokálního souboru
  (cache). Z toho se skládají doporučení (nezapnutý GZIP / Cache stránek, debug, lazy, defer, cache hlavičky, velké HTML).
- **Nastavení**: podzáložky Obrázky a rámce / Skripty a assety / Předpřipojení / HTML / Cache prohlížeče.

## API (`performance-optimization/...`, `core.manage`)

| Routa | ACL | Účel |
|---|---|---|
| `GET status` | manage | stav Joomly (cache, gzip, opcache, debug), stav `.htaccess` bloku, zapnuté funkce |
| `POST audit` | manage | kontrola úvodní stránky (502 `fetch_failed`, když se nepodaří načíst) |
| `POST htaccess/apply` `{confirm:true, ttl?}` | core.admin | 422 `confirmation_required` bez potvrzení; 422 `not_apache`; 500 `write_failed`; vrací `backup` |
| `POST htaccess/remove` | core.admin | odebere blok |

Bez migrací, bez úloh plánovače, bez vlastních tabulek. Karta na přehledu: počet aktivních optimalizací.

## Ověřeno (J5.4.10 a J6.2.0, PHP 8.4, Apache; na J6 je plugin Behaviour - Backward Compatibility vypnutý)

- `php -l`, `npm run typecheck`, `npm run build:spa`; test `Rewriter` samostatně na vzorové stránce (lazy, fetchpriority, defer + výjimky, minify,
  pre/textarea/noscript/komentáře beze změny).
- Frontend curlem: preconnect/preload se vložily, neplatné řádky (`javascript:`, `"><x`, `../bad`) ne; `keepalive.min.js` zmizel po
  `script:keepalive`; závislý asset (`fontawesome`) se odstranit nedá (viz výše).
- `.htaccess` na J5 i J6: bez potvrzení 422; zápis do neexistujícího souboru (bez zálohy); zápis do existujícího (záloha obsahuje původ,
  složka záloh 403); odebrání vrátí původní obsah; `Cache-Control: public, max-age=...` na `core.min.js`; vypnutí modulu blok odstranilo.
- `audit` vrátí rozumná čísla na obou webech (gzip detekován).

## Omezení a poznámky

- **Odchylka od WP verze (záměrně mimo rozsah):** WP PageLoad měří serverový čas generování stránky, počet DB dotazů a špičku paměti na požadavek
  a WP PerformanceOptimization dělá zdravotní kontroly DB. Joomla verze měří jen z prohlížeče (modul page-load, TTFB zahrnuje síť) a
  staticky kontroluje úvodní stránku; serverové měření PHP/DB a kontrola velikosti tabulek (session, action_logs, redirect_links) chybí.
  Je to kandidát na další iteraci (vzorkované měření `onAfterInitialise` až `onAfterRespond`, uložené ke stejné cestě).
- Regresní test přepisovače: `php packages/lib_uxstudio/src/Modules/PerformanceOptimization/tests/rewriter-test.php` (markup Cassiopeie,
  `<img` v hodnotě atributu, defer, komentáře; návratový kód 1 při selhání).

- Obrazovka byla ověřena buildem a typecheckem a načtením stránky komponenty (`jadmin page`), ne vizuálně v prohlížeči (Playwright MCP
  nebyl dostupný).
- Minifikace předpokládá, že nikdo nepoužívá CSS `white-space: pre` na jiném prvku než `<pre>`/`<textarea>`.
- Odložení skriptů je záměrně vypnuté: u šablon, kde inline skripty používají jQuery pluginy, je nutné nejprve doplnit výjimky a otestovat.
