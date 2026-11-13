# Modul dashboard-widgets (karty na přehledu)

Karty na úvodní obrazovce administrace Joomly (`com_cpanel`): shrnutí z modulů UX1 Studia, vlastní poznámky a odkazy, viditelnost podle
skupin uživatelů. Vzor ve WP verzi: `includes/Modules/DashboardWidgets` (tam widgety wp-admin dashboardu; v Joomle se přebírá myšlenka, ne
PageSpeed/GA/disk, které se do tohoto modulu nepřenášely).

Soubory: `packages/lib_uxstudio/src/Modules/DashboardWidgets/` (`Module`, `Items`, `meta.json`, `language/`),
`packages/lib_uxstudio/media/modules/dashboard-widgets/cards.css`, `packages/mod_uxstudio_dashboard/` (administrátorský modul Joomly),
`admin-app/src/modules/dashboard-widgets/` (`Page.tsx`, i18n). Migrace verze 1: `#__uxstudio_dashboard_items`, `#__uxstudio_dashboard_rules`.

## Jak to funguje

- **Vykreslení = administrátorský modul `mod_uxstudio_dashboard`** (pozice `cpanel`, klient administrator). Při zapnutí modulu UX1 Studia
  (`onEnable`) se vytvoří a zveřejní jeho instance v `#__modules` (+ `#__modules_menu`, přístup Special), při vypnutí (`onDisable`) se zneveřejní;
  data se nemažou. Modul (`Dispatcher`) si vyžádá `Module::cardsFor($user)`; bez karet nevykreslí nic (Atum pak nezobrazí ani prázdný rámeček).
  Správce ho může přesunout na jinou pozici cpanel-* běžnými prostředky Joomly. Když rozšíření není nainstalované, ukáže SPA upozornění
  a tlačítko "Zveřejnit" (`POST dashboard-widgets/publish`).
- **Karty z modulů:** `BaseModule::dashboardWidget()` všech zapnutých modulů (stejný zdroj jako přehled UX1 Studia), jen modulů, na které má
  uživatel ACL (`acl()` modulu). Odkaz vede na obrazovku modulu (`index.php?option=com_uxstudio#/module?id=<id>`). Vlastní kartu modul
  nedává do cpanelu (nelze ji zahrnout sama do sebe), na přehledu UX1 Studia ukazuje počet poznámek a odkazů.
- **Vlastní karty:** poznámka (text, `strip_tags`, zalamování řádků, escapování při výstupu) nebo odkaz (jen `http(s)://`, cesta začínající `/`
  nebo `index.php...`; `javascript:`, `data:` apod. server odmítne, 422 `invalid_url`). Pořadí, zveřejněno, skupiny.
- **Viditelnost podle skupin:** u každé vlastní karty i u každé karty modulu (pravidlo `hidden` + `groups`). Prázdný seznam = všichni, jinak musí
  uživatel mít aspoň jednu z vybraných skupin; počítá se `User::getAuthorisedGroups()`, tedy včetně nadřazených skupin (skupina "Manager" tak
  pokrývá i uživatele v podskupinách).
- **Nastavení:** `show_in_cpanel` (celý blok), `show_module_cards`, `show_items`.

## API (`route=dashboard-widgets/...`, ACL `core.manage`)

`GET overview` (karty modulů s pravidly, vlastní karty, skupiny, stav modulu Joomly), `GET preview` (karty tak, jak je uvidí přihlášený uživatel),
`POST rules/{module}` `{hidden, groups[]}`, `POST items` / `POST items/{id}` `{kind: note|link, title, body, url, groups[], published, ordering}`,
`POST items/{id}/delete`, `POST publish`. ID skupin se ověřují proti `#__usergroups` (neexistující se zahodí).

## Ověřeno (J5 5.4.10 i J6 6.2.0)

- zapnutí modulu: migrace, vznik instance v `jos_modules` (cpanel, published 1), vypnutí ji zneveřejní, zapnutí a `publish` obnoví;
- úvodní stránka administrace (HTTP, přihlášená session) obsahuje `ul.uxs-cp-cards` s kartami modulů a vlastními kartami, stylesheet
  `media/lib_uxstudio/modules/dashboard-widgets/cards.css` je načten; pravidlo `hidden` a skupina Manager (super user v ní není) kartu skryjí;
- XSS: `<script>` v názvu se odstraní, `javascript:` URL server odmítne, výstup se escapuje; `php -l`, `tsc --noEmit`, `vite build`;
- J6: Backward Compatibility plugin vypnutý (`compat6` enabled = 0).

## Omezení

- Ruční klikání v prohlížeči neproběhlo (Playwright MCP nebyl k dispozici); SPA prošla typecheckem a buildem, API a výstup cpanelu ověřeny curlem.
- Přehled UX1 Studia (jádro, `core/dashboard`) vykresluje jen `title/value/text`, takže vlastní poznámky a odkazy se zobrazí v cpanelu a v náhledu
  modulu, ne v mřížce přehledu UX1 Studia.
- Karty nejsou řaditelné tažením; pořadí vlastních karet je číslo `ordering`, karty modulů jdou v abecedním pořadí modulů.

## Revize (oprava nálezů reviewera)

- Nově i viditelnost cizích bloků úvodní obrazovky Joomly (moduly administrace v pozicích `cpanel*` a `icon`, např. mod_popular, mod_logged, mod_quickicon): skrýt nebo omezit na skupiny. Pravidla jsou v téže tabulce pod klíčem `joomla.<element>`, uplatňují se listenerem `onAfterModuleList` jen na `option=com_cpanel` (náš vlastní blok se nefiltruje). Přehled vrací `joomla_cards`, SPA má druhou tabulku "Ostatní bloky na úvodní obrazovce Joomly".
- `rules/{module}`, `items/{id}/delete` a `publish` vyžadují `core.admin`.
- RuleModal má `key` podle karty (výběr skupin se nepřenáší mezi kartami); doplněn překlad "Refresh".
- Z WP verze záměrně nepřenesené: PageSpeed skóre, poslední aktivita, úkoly, GA4 návštěvy, využití disku, uvítací panel a rozvržení sloupců. Jde o samostatné datové zdroje (Google API, aktivita, úkoly), které v Joomle nemají protějšek ani sdílenou službu v jádru; Joomla port je řeší tím, že moduly dodávají karty přes `dashboardWidget()`. Vlastní karty (poznámky/odkazy) na přehledu UX1 Studia vyžadují změnu jádra (`core/dashboard` vykresluje jen title/value/text).

## Browser E2E (7. 11. 2026)

`node tests/e2e/media.mjs all --only=dashboard` (J5 i J6, en-GB a cs-CZ, světlý i tmavý režim): vlastní poznámka a odkaz (validace, `javascript:` odmítnuto),
úprava, publikace, skupiny, skrytí karty modulu, blok v com_cpanel, mazání s potvrzením, prázdný stav.
Oprava: tlačítka v tabulce ("Všichni", názvy karet) měla v tmavém režimu šedé pozadí Atum (sdílené `.uxs-link-btn` nenastavuje pozadí) - stránka je přebíjí vlastním stylem.
