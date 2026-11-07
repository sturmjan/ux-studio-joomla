# Modul auto-image-upload (Automatické stahování obrázků)

Kód: `packages/lib_uxstudio/src/Modules/AutoImageUpload`, obrazovka `admin-app/src/modules/auto-image-upload`. Skupina `content`.
Protějšek WP modulu AutoImageUpload. Na rozdíl od WP nemá JS pro editor (vkládání se řeší při uložení, v Joomle není jednotný editor/hook).

## Co dělá

Při uložení položky (`onContentBeforeSave`) najde v textu `<img>` mířící na jiný web, stáhne obrázek do `images/<složka>/`,
přepíše `src` na lokální cestu a odstraní `srcset`/`sizes`/`data-srcset`/`data-src` (ukazovaly na cizí varianty).

- Kontexty (`contexts`): `articles` (`com_content.article` a `com_content.form` = frontendový editor; `introtext`, `fulltext` a volitelně obrázky
  `image_intro`/`image_fulltext` z `images`), `categories` (popis), `modules` (jen `mod_custom`, pole `content`; com_modules neposílá `onContentBeforeSave`, ale `onExtensionBeforeSave`, proto modul poslouchá obě události; ověřeno živě na J5 i J6). Výchozí jen články.
- Obrázky vložené jako `data:image/...;base64` se uloží jako soubory (`process_data_uri`).
- Limit stažení na jedno uložení (`max_per_save`, 10) a 20 s celkem (strop se navíc snižuje podle `max_execution_time`, každé stažení je zkráceno na zbývající čas a před stahováním se zkusí `set_time_limit`); zbytek se dotáhne při dalším uložení nebo hromadným nástrojem.
  Uložení se kvůli modulu nikdy nezhatí (výjimky se jen zalogují do `uxstudio`).
- **Mapa adres** (`#__uxstudio_auto_image_upload`): jedna adresa se stáhne jednou, i když je v mnoha článcích. Neúspěšná adresa se po dobu
  6 hodin nezkouší znovu (uložení nečeká na mrtvý server pořád dokola). Retence 180 dní.
- Název souboru (`file_pattern`: `%filename% %image_alt% %title% %alias% %id% %year% %month% %day% %date% %timestamp% %random% %host%`),
  složka (`folder`, výchozí `external/%year%/%month%`, každý segment očištěn, max 4 úrovně), alt (`alt_pattern`, výchozí zachová stávající),
  `base_url` (adresa CDN místo cesty webu; prázdné = kořenově relativní cesta `images/...` tak, jak ji píše editor, takže web lze přesunout do jiné podsložky). Pole `image_intro` / `image_fulltext` dostanou i příponu `#joomlaImage://local-images/...?width=&height=` s rozměry (proti posunu layoutu).
  Při shodném názvu a shodném obsahu (sha1) se soubor znovu nepoužije/nepřepíše, při neshodě dostane příponu `-1`, `-2` ...
- Přípona se bere ze **skutečného typu obsahu** (`getimagesizefromstring`), ne z adresy: povoleno JPEG, PNG, GIF, WebP, AVIF. SVG a vše ostatní se odmítne.
- Je-li zapnutá **Optimalizace obrázků**, předá se jí každý stažený JPEG/PNG (komprese, záloha originálu, WebP/AVIF). Změna velikosti
  stahovaných obrázků je proto tam (`max_width`/`max_height`), modul ji nezdvojuje.
- **Hromadný nástroj** (obrazovka): „Prohledat články“ (dry run, nic nestahuje) a „Stáhnout obrázky stávajících článků“; po 5 článcích na volání,
  rozpočet 8 stažení a 20 s na volání, klient volá dokola s `next_after_id`, dokud `done`. Článek, který ještě má co stahovat, se drží do dalšího volání.
  Zapisuje přímo do `#__content` jen změněná pole (`introtext`, `fulltext`, `images`), **nemění** `modified` ani nezakládá verzi, a vyčistí cache `com_content`.

## SSRF a bezpečnost

Stahování jde výhradně přes `Core\Http` (`$ctx->http()`): jen http(s), žádné přihlašovací údaje v URL, všechny přeložené adresy musí být veřejné,
ověřená adresa se připne (`CURLOPT_RESOLVE`, ochrana proti DNS rebindingu), přesměrování (max 3) se kontrolují znovu, limit velikosti (`max_file_size_mb`, výchozí 5).
Ověřeno: `http://127.0.0.1/...` → „This address is not allowed.“ (v historii jako failed). Každý, kdo smí uložit obsah, tak může vyvolat stažení z
**veřejné** adresy (jako ve WP verzi); do `images/` se ale dostane jen ověřený obrázek. Vypnout lze celé „Články“ v `contexts`.
API: `core.manage` + CSRF. `POST bulk/run` hromadně přepisuje `#__content` přímo SQL, proto má `acl` `core.admin`; články, které má jiný uživatel otevřené v editoru (`checked_out`), přeskočí a vykáže jako `locked`. Vstup jsou jen čísla (`after_id`), žádná cesta se nepoužívá. Hromadné přepsání nevytváří verzi článku (obsahová historie Joomly se tím obchází; omezení).

## Nastavení

`contexts` [articles], `include_images` ano, `process_data_uri` ano, `excluded_domains`, `max_per_save` 10, `max_file_size_mb` 5, `folder`,
`file_pattern` `%filename%`, `alt_pattern` `%image_alt%`, `base_url`.

## API (administrace, `core.manage`)

`GET overview`, `GET log?page=&status=`, `POST bulk/run {after_id, dry_run}`.

## Ověřeno (J5 5.4.10 i J6 6.2.0)

`php -l`; zapnutí + migrace; uložení článku přes formulář administrace (`article.save`) s: dvakrát stejným PNG (jedno stažení), PNG z Wikimedie,
SVG (odmítnuto), `http://127.0.0.1/...` (SSRF odmítnuto), lokálním obrázkem (beze změny), `data:` GIF (uložen jako soubor), `srcset` (odstraněn);
hromadný nástroj dry run i ostrý běh včetně `image_intro` v JSON `images`; druhý běh = nic nenalezeno; stažené JPEG/PNG prošly Optimalizací obrázků
(WebP/AVIF vedle); typecheck a build SPA čisté.

## Opravy po revizi (7. 11. 2026)

- Kontext `modules` skutečně funguje (událost `onExtensionBeforeSave`).
- Pravidlo vyloučení `*.example.com` vyřadí i samotnou doménu `example.com`.
- Přepsaná adresa je relativní `images/...`; úvodní obrázek má rozměry v `#joomlaImage://` příponě.
- Obrazovka překládá názvy kontextů (Články / Kategorie / Vlastní HTML moduly / Hromadný nástroj).
