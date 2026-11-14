# Review Aggregator (`review-aggregator`) a Google Review Request (`google-review-request`)

## Review Aggregator

Sbírá recenze do lokální tabulky `#__uxstudio_reviews` (cache, nikdy se nemaže ani při vypnutí modulu) a zobrazuje je přes
Joomla modul `mod_uxstudio_reviews` nebo značku `{uxstudio_reviews ...}` v článku / modulu Vlastní.

- Zdroje: (1) UX1 Network app: `reviews_api` přes vlastní `CentralReviewsClient` (stejný protokol jako WP verze, viz níže),
  stránkované po 100, úloha plánovače `review-aggregator.fetch` (hodinová, respektuje nastavení "interval stahování", používá ETag
  / If-None-Match, takže bez změny v CA nestahuje nic); (2) ruční přidání, (3) import CSV/JSON (záhlaví `rating` povinné; aliasy
  author/name, text/review, date, source; id/external_id jen v CSV nebo klíč `external_id` v JSON). Bez CA (třída chybí nebo web
  není spárovaný) modul funguje dál, tlačítka CA jsou neaktivní.
- Podpis `reviews_api`: endpoint CA neověřuje hlavičky CaClientu, ale per-web `google_login_secret`. Klient ho jednou získá z
  `?page=site_config&action=google_secret` (podpis klíčem spárování `node_api_key`, hlavičky X-Site-Url / X-Timestamp / X-Signature),
  uloží ho šifrovaně (`Crypto`) do `#__uxstudio_reviews_meta` a při 401 ho jednou obnoví. Každé volání se podepisuje HMAC nad
  `ksort(http_build_query(site_url + ts + odeslaná pole))` do hlaviček `X-Reviews-Timestamp` / `X-Reviews-Signature`. Adresu webu si
  pamatuje z posledního webového požadavku (v CLI plánovači `Uri::root()` nesedí). Ověřeno proti lokální CA (`_ca-wt-joomla`)
  na J5 i J6: stránkování (230 recenzí = 3 stránky), 304 z ETagu, obnova secretu po 401, `request_scrape`.
- Chyba CA při automatickém stahování se zapíše do meta (`last_fetch_*`, vidět v administraci) a úloha plánovače vrátí text
  `error: ...` místo výjimky, takže Joomla nerozesílá adminům failure e-maily při každém běhu.
- Deduplikace: `dedup_hash` (source + external_id, jinak obsah); opakovaný import/stažení aktualizuje, nezdvojí a nemění `visible`.
- Routy: `GET reviews`, `POST reviews`, `POST reviews/{id}` (jen ručně přidané/importované), `.../toggle-visibility`, `.../delete`,
  `POST import` (smazání a import vyžadují `core.admin`), `GET stats`, `POST fetch`, `POST request-scrape`.
- Web: rozložení grid / list / slider (scroll-snap) / badge, počet, min. hodnocení, řazení, platforma; JSON-LD `AggregateRating`
  (jednou na stránku, typ Organization/LocalBusiness/Product/Service s `@id` `<web>/#organization`; volitelně i `Review`, ale jen u ručně zadaných recenzí, recenze z Google/Facebooku/Mapy.cz se podle pravidel Google neznačkují). Průměr počítá všechny viditelné
  hodnocené recenze bez filtru min. hodnocení. Bez inline JS/CSS (assety `media/modules/review-aggregator/`).
- Značka: `{uxstudio_reviews layout=slider count=6 min_rating=4 platform=google schema=0 heading="..."}`. V modulu Vlastní (mod_custom) se značka provede jen při zapnutém "Připravit obsah" (Prepare Content, výchozí je Ne).
- Počty se skloňují (`Text::plural`, klíče `_0`, `_1`, `_2`, `_MORE`).
- Po vypnutí modulu se značka `{uxstudio_reviews}` v článcích nahrazovat přestane a návštěvníkům se zobrazí doslovně: před vypnutím ji z obsahu odstraňte.
- Modul: parametry přepisují výchozí hodnoty z UX1 Studia; cache modulu je výchozí vypnutá (assety se registrují při renderu).

### Rozdíly proti WP verzi
- Nahrazeno: shortcode -> modul + značka, přibyly ruční zadání/úpravy/import, rozložení slider/badge/list, JSON-LD, mazání.
- Chybí: AI návrh odpovědi na recenzi (v Joomle není AI modul), levný dotaz `action=version` (nahrazen ETagem).
- Napojení JSON-LD na entitu LocalBusiness z modulu Opening hours (jiné `@id`): viz core_requests, dnes jsou na stránce dvě entity.
- Při stažení s ETagem se lokálně smazané recenze z CA nevrátí, dokud se v CA něco nezmění (ruční tlačítko "Stáhnout" ETag ignoruje).

## Google Review Request

E-mail s prosbou o Google recenzi přes standardní Joomla mailer (proto platí SmtpEmail, EmailLog atd.).

- Nastavení: odkaz nebo Place ID (`ChIJ...` -> `search.google.com/local/writereview?placeid=`; záloha Place ID z Review Aggregatoru),
  předmět, tělo (HTML, `{name}` `{site}` `{review_url}`), jméno odesílatele, reply-to, ochranná lhůta na adresu (90 dní),
  denní limit (100), seznam zakázaných adres/domén, retence logu (365 dní), tajný klíč pro veřejný endpoint.
- Admin (modul vyžaduje `core.admin`, protože odesílá poštu z domény webu): odeslání jedné adresy i seznamu (po 10 v dávce, s průběhem),
  test sobě, náhled (sandbox iframe), log s filtry. Routy: `GET stats`, `GET log`, `POST send`, `POST test`, `POST preview`.
- Pravidla: zaškrtávátko "force" přeskočí jen ochrannou lhůtu na adresu. Odhlášené adresy, blocklist, denní limit a pevný strop
  200 odeslaných za hodinu platí vždy (test sobě je z odhlášení, blocklistu a denního limitu vyjmut).
- Odhlášení: zástupce `{unsubscribe_url}` (chybí-li v těle, přidá se na konec zprávy řádek s odkazem) + hlavička `List-Unsubscribe`.
  Odkaz je podepsaný HMAC (tajný klíč webu), veřejná routa `GET google-review-request/unsubscribe` (limit 20/min) nejdřív ukáže potvrzení
  (skenery pošty odkazy jen načítají) a až `&c=1` adresu zapíše do `#__uxstudio_grr_optout` (není mazána retencí).
- Automatizace: `POST index.php?option=com_uxstudio&task=public.dispatch&format=json&route=google-review-request/request`,
  tělo `{"email","name","ref"}`, HMAC (`X-UXS-Timestamp`, `X-UXS-Signature` = hex hmac_sha256("<ts>.<tělo>", secret)).
  Klíč kratší než 16 znaků = endpoint vypnutý. Stejný podpis jde použít jen jednou (409 `replayed`). Platí stejná pravidla;
  chyba odeslání se volajícímu nevrací podrobně.
- Log `#__uxstudio_grr_log` (e-mail, jméno, stav sent/failed/skipped, důvod, kanál, reference) obsahuje osobní údaje: nastavte retenci.

### Rozdíly proti WP verzi
- Odstraněn WooCommerce trigger (v Joomle není jednotný e-shop) -> nahrazen podepsaným endpointem. Přibyl hromadný režim,
  ochrany (lhůta, limit, blocklist, strop za hodinu), test, náhled, filtrovatelný log, odhlašovací odkaz.
- Chybí: odložené odeslání "X dní po objednávce" a integrace HikaShop/VirtueMart/J2Store (e-shop zavolá endpoint ve vhodnou chvíli sám).
- Hromadné odeslání jde synchronně po 10 adresách na požadavek; s pomalým SMTP je potřeba počítat s `max_execution_time`.
- Na lokálu (bez sendmailu) odeslání končí `failed`; logika pravidel, odhlášení a replay ochrany je ověřena, doručení ne.
