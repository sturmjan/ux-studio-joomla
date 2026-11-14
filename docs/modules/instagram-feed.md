# Instagram Feed (`instagram-feed`)

Zobrazí nejnovější příspěvky připojeného účtu na Instagramu jako mřížku nebo karusel. Příspěvky se stahují přes UX1 Network app
(CA, `instagram_api`), ukládají do databáze a obrázky se kopírují na server webu. Výpis na webu tedy nepotřebuje CA ani Instagram
(funguje i u nespárovaného webu a při výpadku CA).

## Architektura

| Soubor (`src/Modules/InstagramFeed/`) | Úloha |
|---|---|
| `Module.php` | nastavení, admin routy, veřejná routa `callback`, úloha plánovače, widget, migrace, `renderFront()`, značka v obsahu |
| `CaSource.php` | klient `instagram_api` / `instagram_auth` (viz podpis níže), `availability()`, ověření návratu z CA |
| `MediaSource.php` | rozhraní zdroje dat (`profile()`, `media()`), díky němu jde `Syncer` testovat bez CA |
| `Syncer.php` | jeden běh synchronizace: profil + příspěvky -> DB, mazání smazaných příspěvků, stažení obrázků |
| `Item.php` | validace a normalizace jednoho příspěvku z CA (nedůvěryhodná data), hashtagy |
| `ImageCache.php` | lokální kopie obrázků v `images/uxstudio-instagram/` |
| `MediaStore.php` | tabulky `#__uxstudio_instagram_media` (cache příspěvků) a `#__uxstudio_instagram_meta` (stav) |
| `Renderer.php` | HTML bez inline stylů a skriptů |
| `media/modules/instagram-feed/instagram.{css,js}` | vzhled a šipky karuselu |
| `packages/mod_uxstudio_instagram` | Joomla modul pro pozici v šabloně |

### Zdroj dat: CA `instagram_api`
CA je proxy Graph API: web nikdy nevidí token Mety, zná jen `connection_id` (ukládá se jako stav modulu do tabulky `#__uxstudio_instagram_meta`, ne do Nastavení; dřívější nastavení `connection_id` se čte jako záloha do prvního připojení / odpojení).

- **Podpis `instagram_api` je starší než u ostatních API CA** (bez nonce): `hex(hmac_sha256("METHOD\nURL\nTIMESTAMP\nsha256hex(body)", node_api_key))`
  v hlavičkách `X-Content-Sync-Signature` / `X-Content-Sync-Timestamp`, v dotazu `site_url` a `connection_id`. `CaClient` z modulu
  ca-connector podepisuje s nonce, proto by ho `InstagramApiController` odmítl (401). `CaSource` si proto podepisuje sám, ale
  dostupnost (modul ca-connector zapnutý, web spárovaný) zjišťuje přes `CaClient::fromContext()` a klíč čte z nastavení ca-connectoru.
  Požadavek nikdy nesleduje přesměrování (`follow_redirects=false`, podpis váže přesnou URL; přesměrování = chyba `ca_redirect`) a posílá `X-UXStudio-Site-Id` (site_uid z ca-connectoru), takže CA najde web i po změně adresy.
  Chybějící ca-connector = `availability: connector_missing`, nespárovaný web = `not_paired`; modul pak jen zobrazuje už uložené příspěvky.
- Akce: `profile` (`fresh=1` při synchronizaci), `media` (stránkování kurzorem `paging.cursors.after`, max 5 stránek, 100 příspěvků),
  `disconnect` (POST).
- Chyba CA (`success:false`) -> `ApiException` 502 `ca_error`; jen odpověď, jejíž text říká "neznámé připojení" / "nemá platný token" (nebo anglicky), je `connection_lost` (příznak v UI "připojte znovu"); ostatní 401/403/404 (špatný podpis, posun hodin, web neznámý) jsou `ca_auth` s radou zkontrolovat spárování a příznak ztraceného účtu se nenastaví; nedostupná CA -> `ca_unreachable`.
  Adresa CA je privátní/loopback jen u vývojové CA (SSRF guard se vypíná jen pro tento jeden nastavený host, stejně jako v `CaClient`).

### Připojení účtu (OAuth přes CA)
1. Tlačítko "Připojit Instagram" -> `POST connect`: modul uloží jednorázové `nonce` (platnost 15 min) a vrátí `auth_url`
   `{CA}/?page=instagram_auth&action=init&site_url&return_url&nonce&ts&sig` (`sig` = `hex(hmac_sha256(http_build_query(ksort(params)), node_api_key))`,
   shodně s `GoogleProxySigner` v CA). Prohlížeč jde do CA -> Instagram/Facebook -> zpět.
2. CA přesměruje prohlížeč na `return_url` = veřejnou routu `index.php?option=com_uxstudio&task=public.dispatch&format=json&route=instagram-feed/callback`
   s podepsanými parametry `connection_id, ig_user_id, ig_username, ig_account_type, nonce, ts, sig`.
3. `GET callback` ověří podpis (klíč spárování), stáří (5 min) a shodu a jednorázovost `nonce`, uloží `connection_id` (při změně účtu vyčistí cache a obrázky předchozího) a
   přesměruje do administrace (`#/module?id=instagram-feed&ig=connected`, při chybě `&ig=error&reason=...`). Nikdy nevrací JSON ani tajné údaje.

## Cache a obrázky
- Příspěvek se ukládá podle `instagram_id`; opakovaná synchronizace aktualizuje, nezdvojí a **nemění ruční skrytí** (`is_hidden`) ani už stažený obrázek.
- Obrázek (u videa jeho náhled; samotné video se nestahuje) se kopíruje do `images/uxstudio-instagram/ig-<sha1(id)[:20]>.<ext>`. Název i
  přípona se generují lokálně podle obsahu (`getimagesizefromstring`: jen jpeg/png/webp/gif, max 8 MB), nic ze vzdálené strany. Stahování jde přes
  core `Http` (SSRF guard, https), max 40 obrázků na běh (zbytek další běh). Při zapnutém "Ukládat obrázky" se příspěvek bez lokální kopie na webu **nezobrazí** (návštěvník nikdy nekontaktuje Instagram/Metu); vzdálený https odkaz z CDN se použije
  jen když je ukládání vypnuté (a administrace na to upozorní) a v náhledu příspěvků v administraci. Příspěvek, jehož stažení selže 3x, se přeskakuje (sloupec `dl_fail`, migrace 2), po týdnu dostane nový pokus; neúspěšné jdou v řadě až za ostatní.
- Příspěvek smazaný na Instagramu zmizí z cache i s obrázkem (v okně načtených příspěvků; prázdná odpověď CA nikdy nic nemaže).
  Souběžně vygenerované `.webp` / `.avif` kopie (optimalizátor obrázků) se mažou s původním souborem.
- Cache se při vypnutí modulu nemaže, jen tlačítkem "Odpojit" (`POST disconnect`, `core.admin`: smaže cache, obrázky, `connection_id`
  a požádá CA o zrušení připojení).

## Zobrazení na webu
- **Modul `UX1 Studio - Instagram`** (`mod_uxstudio_instagram`): nadpis, rozložení, počet, sloupce, popisky, odkaz pro sledování, hashtagy;
  parametry přepisují výchozí hodnoty z UX1 Studia (prázdné = výchozí). Cache modulu je výchozí vypnutá (assety se registrují při renderu).
- **Značka** `{uxstudio_instagram layout=carousel count=8 columns=4 gap=8 show_caption=1 show_follow=0 hashtags=kava exclude=reklama heading="..."}` v článku / modulu Vlastní.
- Rozložení: `grid` (CSS grid) a `carousel` (scroll-snap + šipky z `instagram.js`; bez JS jde posouvat prstem/kolečkem). Sloupce 1-8; na tabletu a telefonu nastavitelné (`columns_tablet`, `columns_mobile`: Automaticky = max 3 / 2, nebo 1-6) přes třídy `uxs-ig--tcols-N` / `uxs-ig--mcols-N`. `show_likes` zobrazí srdíčko s počtem (když ho Instagram vrátil).
- CSP: žádný inline `style`, `<script>` ani `on*`; rozložení řídí třídy `uxs-ig--cols-N` / `uxs-ig--gap-N`, assety přes WebAssetManager, ikony jsou inline SVG.
- Výkon/přístupnost: `loading="lazy"`, `decoding="async"`, `width`/`height` (bez posunu rozložení), `alt` z popisku (jinak "Příspěvek na Instagramu od @účet"),
  `rel="noopener noreferrer"`, tlačítka šipek s `aria-label`, respektuje `prefers-reduced-motion`.
- **Bez dat:** návštěvník nevidí nic; přihlášený správce (`core.manage`) vidí hlášku, co chybí (není připojený účet / web není spárovaný s CA / ještě není nic synchronizováno).
- Filtry: typy příspěvků (foto / video+reels / alba), jen příspěvky s hashtagy, skrýt příspěvky s hashtagy (filtr běží nad oknem 200 nejnovějších), ruční skrytí jednotlivého příspěvku v záložce Příspěvky.

## Administrace
Záložky Přehled (stav CA a účtu, Připojit / Synchronizovat / Odpojit, čísla cache, poslední synchronizace, návod), Příspěvky (náhledy, skrytí/zobrazení), Nastavení.
Bez spárované CA je tlačítko Připojit neaktivní a stránka vysvětluje proč (s odkazem na modul UX1 Network app).

Routy (`administrator/index.php?option=com_uxstudio&task=api.dispatch&format=json&route=instagram-feed/...`):
`GET status`, `POST connect`, `POST disconnect` (`core.admin`), `POST sync`, `GET media?page&per_page`, `POST media/{id}/toggle-hidden`.
Veřejná: `GET callback` (limit 20/min, jednorázové nonce + HMAC). Query se čte přes `ApiRequest::queryAll()` (surová, kvůli podpisu). Nepodepsaný `error` v query nonce nespotřebuje (jinak by ho mohl kdokoli zahodit); spotřebuje ho až ověřená odpověď. `connect` i `disconnect` vyžadují `core.admin`.

## Plánovač
Úloha `instagram-feed.sync` (hodinová; respektuje nastavení "synchronizovat každých" 1 / 6 / 12 / 24 h a "synchronizovat automaticky"). Po neúspěchu se zkouší
při dalším hodinovém běhu, po úspěchu až po intervalu. Chyba se zapíše do stavu (vidět v Přehledu) a úloha vrátí text `error: ...` místo výjimky, takže
Joomla nerozesílá adminům e-mail při každém výpadku CA. Souběžné běhy blokuje atomický zámek (`acquireLock`: INSERT na primární klíč tabulky stavu, zastaralý po 5 min).

## Rozdíly proti WP verzi
- WP verze je samostatná (vlastní Meta aplikace, app id/secret na webu, přímé volání Graph API, token šifrovaně na webu, WP-Cron, shortcode).
  Joomla verze podle zadání jde přes CA: web neřeší Meta aplikaci ani token, jen `connection_id`.
- Vědomě chybí (mimo zadání mřížka + karusel): motivy highlight/showcase/masonry/slider, vlastní CSS na feed, pojmenované feedy (CRUD `feeds`, shortcode `id=`;
  v Joomle je jeden výchozí výpis + parametry modulu/značky), komentáře v překryvu, videa se nepřehrávají (odkaz na Instagram).
  Doplněno: počet lajků (`show_likes`) a sloupce pro tablet / telefon.
- Značka `{uxstudio_instagram}` zůstane v textu viditelná, když je modul vypnutý (bez `boot()` ji modul nemůže odstranit).
- Přibylo: ruční skrytí příspěvku, filtry hashtagů, karusel s CSS scroll-snap, hláška pro správce, stav a stránka Příspěvky.

## Ověřeno (J5 5.4.10 i J6 6.2.0)
- `php -l`, `npm run typecheck`, `npm run build -w admin-app`; zapnutí modulu, migrace, dev-link modulu `mod_uxstudio_instagram`, úloha plánovače (`scheduler:run`).
- Reálná lokální CA (`_ca-wt-joomla`): podpis `instagram_api` projde (odpověď "Meta App není nakonfigurována", protože v lokální CA nejsou klíče Mety),
  `instagram_auth init` s podpisem projde až po kontrolu konfigurace Mety; opakované použití téhož podpisu CA odmítne.
- Úspěšnou cestu (stránkování, profil, odpojení, chybné klíče) ověřil testovací stub CA s vlastní kontrolou podpisu, `Syncer` s reálnou DB a reálným stažením obrázků
  (viz skripty mimo repo): nové / aktualizované / smazané příspěvky, zachování skrytí, prázdná odpověď nemaže, SSRF (interní obrázek se nestáhne), `javascript:` odkaz zahozen, XSS v popisku.
- Callback: neplatný podpis, vypršelé nonce a opakování končí `ig=error`, platný podpis uloží `connection_id` (ověřeno živě s podpisem vypočteným klíčem spárování).
- Frontend curlem i v prohlížeči: modul i značka, mřížka i karusel (JS inicializován, bez chyb v konzoli), CSS/JS assety 200, bez inline stylů.
- J6 s vypnutým pluginem "Behaviour - Backward Compatibility" (byl už vypnutý) bez chyb. Apache error log bez záznamů modulu.
- **Neověřeno:** skutečné přihlášení k Instagramu (lokální CA nemá Meta aplikaci) a skutečná odpověď Graph API; tvar `media` / `profile` vychází z kódu `InstagramApiController` a `InstagramGraphClient`.

## Požadavky na jádro / CA (`core_requests`)
- CA: `InstagramApiController::authorize()` ověřuje jen starší podpis bez nonce. Až bude akceptovat i `HmacAuth::signWithNonce` (hlavička `X-Content-Sync-Nonce`),
  může `CaSource` používat `CaClient::get/post` místo vlastního podpisu (tím odpadne duplikace `follow_redirects` / `site_uid`).
- CA: chyby `instagram_api` nemají strojový kód, `connection_lost` se pozná podle textu zprávy (`CaSource::isConnectionLost`). Stabilní pole `error_code` by bylo spolehlivější.
- Jádro (hotovo): `ApiRequest::queryAll()` se už používá.
