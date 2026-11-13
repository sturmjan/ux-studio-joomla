# Modul page-load (Rychlost načítání)

Kód: `packages/lib_uxstudio/src/Modules/PageLoad`, obrazovka `admin-app/src/modules/page-load`, beacon
`packages/lib_uxstudio/media/modules/page-load/page-load.js`. Skupina `performance`.
Protějšek WP modulu PageLoad, ale jinak: WP verze měří serverový čas a vliv pluginů z admin baru. Tady se měří **skutečné načtení v prohlížeči
návštěvníka** (Navigation Timing), protože na Joomle nemáme admin bar ani aktivaci pluginů jako benchmark.

## Co dělá

1. **Beacon** (`page-load.js`, `defer`, přes WebAssetManager, bez inline kódu): na části zobrazení (`sample_rate` %, rozhoduje prohlížeč,
   takže funguje i s page cache) pošle jedno měření: `ttfb` (responseStart), `fcp` (paint), `lcp` (PerformanceObserver, jen s `vitals`),
   `dcl`, `load`, `cls` (v tisícinách), `size` (transferSize), typ navigace a šířku okna. Odesílá se při skrytí stránky / `pagehide`, nebo 4 s po
   `load`; jednou na zobrazení; `fetch keepalive`, `credentials: omit`, žádné cookies. Nepřihlášení návštěvníci: ve výchozím stavu se
   přihlášení uživatelé neměří (`skip_logged_in`). Prerender se přeskočí.
2. **Veřejná routa** `page-load/beacon` (`POST`, `auth: none`, kontrola Origin jádrem, `max_body` 2 kB, jádrový limit 20 000/h jako záchytná
   pojistka). V handleru: roboti podle User-Agentu se ignorují, **40 vzorků za 10 min na klienta** (`RateLimiter::hitClient`, IP s ohledem na
   proxy režim jádra), validace (čísla oříznuta na 0 až 120 s, `load` musí být > 0, cesta očištěná jako v Analytics: bez dotazu kromě
   `option=com_*` parametrů, bez podsložky webu, 255 znaků). **`path` je nedůvěryhodný vstup**: povoleny jsou jen znaky URL cesty
   (písmena, číslice, `/._~%:@!$&()*+,;=-`), cokoli jiného (`<>"'`, zpětné lomítko) celé měření odmítne; nové (dosud neviděné) cesty
   jsou omezeny na 1000 různých za 24 h, další se zahodí (už známé cesty se zapisují dál). Příjemce (admin, UX1 Network app přes
   `caFeed()`) musí `path` přesto escapovat při výpisu. `data-endpoint` je relativní URL (`Uri::root(true)`), takže se z hlavičky Host
   nic nedostane do HTML uloženého page cache. Vždy odpoví `ok` (měření nikdy nehlásí chybu návštěvníkovi, výjimky jdou do logu `uxstudio`).
3. **Ukládání**: tabulka `#__uxstudio_page_load` (migrace 1): čas, cesta, třída zařízení (mobil < 768, tablet < 1100, počítač), typ navigace,
   milisekundové hodnoty. **Neukládá se IP ani User-Agent.** Retence `retention_days` (30, 1 až 365) denní úlohou jádra.
4. **Přehled**: období 24 h / 7 / 30 / 90 dní; medián a 75. percentil z posledních 5000 vzorků období; hodnocení dobré / ke zlepšení / špatné
   podle limitů Core Web Vitals (TTFB 0,8/1,8 s, FCP 1,8/3 s, LCP 2,5/4 s, CLS 0,1/0,25; ostatní podle `slow_ms`); rozdělení podle zařízení;
   denní vývoj.
5. **Nejpomalejší stránky**: seskupeno podle cesty, řazení podle doby načtení / TTFB / FCP / LCP (průměr), minimum měření (1/3/10), top 50;
   barva podle `slow_ms` (3000) a jeho dvojnásobku.
6. **Smazání dat** (`POST clear`, jen `core.admin`, s potvrzením). Karta na přehledu UX1 Studia: medián doby načtení za 7 dní (warn/bad podle `slow_ms`).
7. **UX1 Network app**: `caFeed()` vrací `{summary, slowest}`. Aby ho CaConnector vystavil, musí mít v `Node::FEEDS` řádek
   `'page-load' => ['page-load', 'GET']` (viz core_requests); protokol CA tuto routu zatím nezná.

## API

| Routa | ACL | Účel |
|---|---|---|
| `GET page-load/summary?period=7d` | manage | souhrn, percentily, zařízení, vývoj |
| `GET page-load/pages?period=&sort=&min=` | manage | nejpomalejší stránky |
| `POST page-load/clear` | core.admin | smaže všechna měření |
| `POST` veřejná `page-load/beacon` | anonym | viz výše |

## Ověřeno (J5.4.10 a J6.2.0; J6 s vypnutým Backward Compatibility pluginem)

- Migrace proběhla při zapnutí na obou webech; `php -l` čistý.
- Veřejná routa curlem: platná měření se uložila (cesta `/joomla5/index.php/page1?utm=1` -> `/index.php/page1`), robot UA a `load=0` ne,
  cizí `Origin` -> 403, po 40 vzorcích je klient omezen (36 uloženo, 4 už bylo dřív).
- **Reálný prohlížeč** (Chrome headless, `sample_rate=100`): na J5 i J6 se z `page-load.js` uložilo měření s TTFB, FCP, LCP a load.
- Souhrn a seznam stránek vrací očekávané percentily (3 vzorky: p75 = 3. nejvyšší, medián = prostřední).
- `retention`, `clear` a karta přehledu se načtou bez chyb; Apache ani `uxstudio` log neobsahují chyby modulu.

## Poznámky

- **Omezení měření:** beacon se odesílá až po `loadEventEnd` (skript je navíc `defer`), takže návštěvy opuštěné před dokončením načtení se
  neměří a nejpomalejší stránky jsou systematicky podhodnocené (obrazovka „Nejpomalejší stránky“ to uvádí). Částečný vzorek při
  `pagehide` před `load` zatím není. Řazení nejpomalejších stránek podle FCP/LCP/TTFB je podle průměru bez nul (`AVG(NULLIF(..,0))`),
  stejně jako zobrazené sloupce.
- **Odchylka od WP verze:** serverový čas PHP, počet DB dotazů a paměť na požadavek se neměří (viz performance-optimization.md).

- Při testech na localhostu se rychle vyčerpá limit 40/10 min na klienta; vyčistit ho jde `DELETE FROM jos_uxstudio_ratelimit`.
- Obrazovka byla ověřena buildem, typecheckem a načtením stránky komponenty, ne vizuálně (Playwright MCP nebyl dostupný).

## E2E test v prohlížeči

`node tests/e2e/tracking.mjs all` (skupina tracking: analytics, pixel-tag-manager, page-load, ai-markdown) projde skutečné toky na J5 i J6 (SPA v cs/en, světlý/tmavý režim, frontend jako anonym, koncept i úpravy článků přes administraci Joomly). Poznámky: po uložení nastavení se přehled modulu znovu načte (cache react-query se zneplatní); KPI čísla v Analytics se animují (počítání od 0), proto testy čekají; `HeadlessChrome` v User-Agentu je v Analytics i Page load brán jako bot.
