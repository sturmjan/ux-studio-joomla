# ai-markdown (WP: AiMarkdown)

Dělá web čitelný pro AI asistenty a crawlery: `llms.txt`, `llms-full.txt`, `ai-sitemap.md` a čistá markdown verze
článků a kategorií. Vše se generuje lokálně (žádná AI služba), cachuje se v DB a loguje se, kdo to čte.

## Co modul nabízí

| Adresa | Obsah |
|---|---|
| `/llms.txt` | Přehled webu podle llmstxt.org: `# název`, `> popis`, volitelný vlastní text, sekce `Pages` (položky menu), pak články seskupené podle kategorií (`- [titulek](md-url): popis`), nakonec `Optional` (llms-full, sitemapa, kategorie). `text/plain`. |
| `/llms-full.txt` | Plné texty N nejnovějších článků v jednom souboru (`full_max_articles`, výchozí 100). |
| `/ai-sitemap.md` | Všechny veřejné články (až 5000) s datem a odkazem na markdown verzi, seskupené podle kategorií, + kategorie. `text/markdown`. |
| článek / kategorie jako markdown | `?format=md` (i `?format=markdown`), přípona `.md` na konci adresy (SEF), nebo hlavička `Accept: text/markdown`. |

- Markdown článku: volitelná YAML hlavička (`title, url, type, date, modified, author, category, tags, language, description`),
  `# titulek`, tělo převedené z `introtext` + `fulltext`. Konvertor `HtmlToMarkdown` (DOMDocument): nadpisy, odstavce,
  odkazy, obrázky (alt), vnořené seznamy, citace, `pre/code` (jazyk z `language-x`), tabulky GFM, tučné/kurzíva/přeškrtnuté,
  `iframe/video/audio` jako odkaz. Skripty, styly, formuláře a podobné se zahazují; relativní URL se převádějí na absolutní;
  povolená schémata jen `http(s)`, `mailto`, `tel`. Značky `{loadmodule ...}` a readmore se odstraní.
  **Obsahové pluginy se nespouští** (mohly by cloakovat e-maily JavaScriptem, načítat moduly nebo mít vedlejší efekty).
- Kategorie jako markdown: popis, podkategorie, až 200 článků kategorie.
- `Accept: text/markdown` na běžné adrese článku/kategorie vrátí markdown, jen když má text/markdown vyšší nebo stejnou
  váhu jako text/html a `*/*` (prohlížeč ho neposílá). Na stránky článků se pak přidává `Vary: Accept`.
- Odpověď: `X-Robots-Tag: noindex`, `X-Content-Type-Options: nosniff`, `ETag` + `If-None-Match` (304), `Cache-Control: public, max-age=300`,
  `Link: <kanonická url>; rel="canonical"`, bez `Set-Cookie`. HEAD funguje. Ostatní metody se neřeší (dál Joomla).
- Do `<head>` stránek článků se přidává `<link rel="alternate" type="text/markdown" href="...">` (volba `advertise_alternate`).

### Co je „veřejné“
Vše prochází `Content`: článek musí být publikovaný (`state=1`), v rozmezí `publish_up/publish_down`, v publikované a veřejně
přístupné kategorii (včetně rodičů), mít přístupovou úroveň, kterou vidí nepřihlášený návštěvník (`Access::getAuthorisedViewLevels(0)`),
a nesmí být vyloučený (`exclude_ids`, `exclude_categories` včetně podkategorií). Koncept, expirovaný článek, článek jen pro
registrované ani vyloučená kategorie nikdy neprojdou ani do llms.txt, ani do markdownu (404). Markdown se vždy renderuje
jako pro anonyma, i když si ho vyžádá přihlášený admin.

## Mezipaměť a invalidace
Tabulka `#__uxstudio_ai_markdown_cache` (`cache_key`: `article:<id>`, `category:<id>`, `llms`, `llms-full`, `sitemap`).
- Řádek platí, dokud sedí hash zdroje (obsah článku, `modified`, `publish_up`, autor, kategorie, **signatura nastavení** a
  **kořen webu**) a dokud nevyprší `cache_ttl` (0 = jen do změny). Agregáty mají hash z „otisku“ (počet veřejných článků,
  nejnovější `modified`, součet id), takže je opraví i změna bez události (import, SQL).
- Události `onContentAfterSave/AfterDelete/ChangeState` (kontext `com_content.*`) smažou cache článku a všech agregátů;
  `com_categories.category` a `com_menus.*` smažou agregáty a kategorie. Volba `auto_regenerate` článek hned přegeneruje.
- Kořen webu v hashi chrání před otrávením cache podvrženou hlavičkou `Host`. Navíc se dokument **ukládá jen tehdy, když kořen
  žádosti odpovídá kanonickému** (`live_site`, jinak kořen zapamatovaný v `Core\Store` klíčem `canonical_root` při zapnutí modulu
  a při čtení `GET status` z administrace). Žádost s cizím hostem dostane jednorázově sestavený dokument, řádek v cache nepřepíše.
- Přegenerování při cache miss z veřejné žádosti je omezeno `RateLimiter::hitClient('ai-markdown:build', 30, 60)`; nad limit
  odpoví 429 s `Retry-After`. Zásahy cache jsou bez limitu.
- Hash článku zahrnuje cestu kategorie a názvy štítků; `onContentChangeState` čte id z argumentu `pks`; kontext `com_tags.*` maže agregáty.
- Z příkazové řádky (cron, `scheduler:run`) není znám host: bez `live_site` v `configuration.php` se z CLI nic neukládá
  (odkazy by byly špatně), úloha jen uklidí cache a dokumenty se postaví při příští webové žádosti.

## Přístup botů, log
Loguje se jen když User-Agent odpovídá známému bot (`BotDetector`: GPTBot, ChatGPT-User, OAI-SearchBot, ClaudeBot, Claude-Web/User,
PerplexityBot, Google-Extended, Googlebot, Bingbot, Bytespider, CCBot, FacebookBot, Meta-ExternalAgent, cohere-ai, Applebot(-Extended),
YouBot, Diffbot, ImagesiftBot, Amazonbot; každý má účel `training` / `search`). Záznam: čas, dokument, odkazující id, bot, URL,
způsob (`file|query|suffix|accept`), IP (`Core\ClientIp::current()`), UA, plná URL včetně query. Zápis je omezen na 60 za minutu na IP (spoofovaný UA nenafoukne tabulku). Retence `log_retention_days` (výchozí 90, 0 = navždy;
řeší jádro). **Pozor na osobní údaje:** IP adresa botů je ukládána; nepřihlášení lidé se nelogují.

## robots.txt (AI crawlery)
Modul robots.txt **nikdy neupravuje**. Záložka robots.txt přečte `robots.txt` v kořeni, pro každého známého bota ukáže stav
(povolen / zablokován / bez pravidla; specifická skupina má přednost před `*`) a nabídne hotový úryvek pro politiku
`allow_all`, `block_training` (zakáže trénovací boty, ponechá vyhledávací) nebo `block_all`. Googlebot, Bingbot a Applebot
(běžné vyhledávače) se v úryvku nevynechávají jako „AI“, zůstávají pod běžnými pravidly.

## Nastavení (sekce Adresy / Obsah / Mezipaměť / Log botů)
`enable_llms_txt`, `enable_llms_full`, `enable_sitemap`, `enable_markdown`, `enable_accept`, `link_style` (`query` | `suffix`;
suffix jen u SEF adres bez dotazu, jinak query), `advertise_alternate`, `site_name`, `site_description`, `extra_text`;
`include_menu`, `menu_types`, `include_categories`, `exclude_categories` (ID, včetně potomků), `exclude_ids`, `max_articles` (200),
`full_max_articles` (100), `include_meta_header`, `include_author`, `include_taxonomy`, `convert_images`;
`cache_ttl`, `auto_regenerate`; `enable_logging`, `log_retention_days`.
Prázdný název/popis = `sitename` / `MetaDesc` z globální konfigurace. Struktura výstupu (nadpisy `Pages`, `Optional`, `Articles`) je anglicky podle konvence llms.txt.

## API (administrace, `core.manage`)
`GET status`, `GET articles?page&per_page&search`, `GET preview?kind=llms|llms-full|sitemap` nebo `?id=<článek>`,
`POST regenerate-all` (vymaže cache a naplánuje úlohu `refresh`, nepřevádí synchronně), `POST regenerate/{id}`, `POST cache/clear`, `GET log?page&per_page&search&bot&kind`, `GET log/stats`,
`POST log/delete {ids}`, `POST log/clear` (`core.admin`), `GET robots?policy=`.
Úloha plánovače `ai-markdown.refresh` (P1D): smaže cache článků, které už nejsou veřejné, a ohřeje agregáty.
Dashboard karta: požadavky AI botů za 24 h.

## Centrální aplikace
`caFeed('ai-markdown', [], [])` vrací `{endpoints:{llms_txt,llms_full,sitemap}, markdown, public_items, cached_items, bot_requests:{total,last_24h,by_bot[]}}`.
Aby ho CA dostala, musí `CaConnector\Node::FEEDS` obsahovat `'ai-markdown' => ['ai-markdown', 'GET']` (viz core_requests).

## Nasazení a omezení
- `/llms.txt`, `/llms-full.txt`, `/ai-sitemap.md` a `.md` přípony potřebují přepisování URL (Joomla `.htaccess` z `htaccess.txt`
  posílá neexistující soubory do `index.php`; jinde nginx `try_files`). Bez něj jde o `index.php/llms.txt`: `Content::virtualUrl()` proto vrací `index.php/<soubor>`, pokud není zapnuté `sef_rewrite` a existující `.htaccess`
  (používá to `llms.txt` pro odkazy v Optional, `GET status` i feed CA; `status.rewrite` říká, zda přepisování běží). Soubor stejného jména
  fyzicky v kořeni má na serveru přednost.
- Virtuální soubory se obsluhují už při bootu modulů (před routováním), takže nikdy neskončí jako 404 Joomly a fungují i bez SEF.
  Přípona `.md` se z cesty odřízne před routováním, stránka se tak vyřeší normálně a `onAfterRoute` ji vrátí jako markdown;
  `.md` adresa, která není článkem ani kategorií, dostane markdown 404.
- Plugin „System - Page Cache“ a reverzní cache: negociace přes `Accept` funguje díky `Vary: Accept`; pokud cache hlavičku ignoruje,
  použijte `?format=md`.
- URL článku se staví přes `Route::link('site', ...)`; z administrace (regenerace) vyjde stejně jako na webu (ověřeno bez menu položky
  pro článek). U webu se složitými menu aliasy se může adresa vygenerovaná z administrace lišit od webové; po „Přegenerovat vše“
  ze skutečné webové žádosti se řádky obnoví díky hashi.

Rozdíly proti WP verzi: žádná kategorie `taxonomies`/CPT, místo toho kategorie, menu a štítky Joomly; `Accept: text/markdown`,
přípona `.md`, `ETag/304`, `canonical`, robots.txt analýza a konvertor přes DOM jsou nové; WooCommerce šablona produktu odpadá;
automatická regenerace podle intervalu je řešena denní úlohou plánovače (úklid + ohřev), plné přegenerování je ruční nebo při uložení.
