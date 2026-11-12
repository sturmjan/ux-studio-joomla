# Modul image-optimizer (Optimalizace obrázků)

Kód: `packages/lib_uxstudio/src/Modules/ImageOptimizer`, obrazovka `admin-app/src/modules/image-optimizer`. Skupina `performance`.
Protějšek WP modulu ImageOptimizer (v `_uxs-wt-csp`), přepsaný pro Joomla Správce médií a plánovač.

## Co dělá

1. **Komprese při nahrání.** Poslouchá `onContentBeforeSave` / `onContentAfterSave` kontextu `com_media.file` (stejné události
   jako core pluginy Media Action, např. Resize). V `BeforeSave` přepíše bajty (`$item->data`) zkomprimovanou verzí, takže na disk
   se ihned zapíše výsledek; v `AfterSave` (už je znám finální název souboru) uloží zálohu originálu, WebP/AVIF a záznam do tabulky.
   Platí pro nahrání i pro úpravu obrázku (otočení, ořez) ve Správci médií. Smazání souboru ve Správci médií
   (`onContentAfterDelete`) smaže i WebP/AVIF, zálohu a záznam.
2. **Hromadná optimalizace existujících obrázků** ve složce `images/`: tlačítko na obrazovce modulu (smyčka dávek po 10, lze zastavit)
   a plánovaná úloha `image-optimizer.bulk` (každých 15 min, zpracuje `bulk_batch` obrázků, jen když je zapnuté `bulk_enabled`).
   Stav je v tabulce, ne v paměti: běh lze kdykoli přerušit a navázat.
3. **Nikdy podruhé.** Řádek v `#__uxstudio_image_optimizer` si pamatuje velikost souboru po zpracování (`file_size`). Soubor je
   „hotový“, dokud jeho velikost odpovídá; teprve když ho někdo nahradí, zpracuje se znovu. Výsledek, který není aspoň o 2 % menší
   (a nebylo zmenšeno/otočeno), se zahodí a soubor zůstane (`status = skipped`, poznámka `not_smaller`). Znovu zpracovat s novým
   nastavením jde jen výslovně (**Optimalizovat znovu**), a to vždy z uložené zálohy originálu, nikdy z už komprimovaného souboru
   (bez zálohy vrací 409 `no_backup`).
4. **Formáty.** JPEG a PNG. JPEG: progresivní, kvalita `jpeg_quality`, EXIF orientace se aplikuje na pixely (metadata a ICC se odstraní),
   maximální šířka/výška (`max_width` výchozí 2560, jen zmenšuje). PNG: bezeztrátově (zlib 9, všechny filtry), průhlednost zachována.
   Přeskakuje: animované PNG, GIF, WebP, AVIF, soubory menší než `min_size_kb` (10), soubory, které se nevejdou do `memory_limit`
   (poznámka `too_large`), obsah neodpovídající příponě.
5. **WebP / AVIF.** Vedle obrázku vznikne `obrazek.jpg.webp` / `obrazek.jpg.avif` (přípona se přidává, takže `a.jpg` a `a.png` nekolidují),
   jen když je sourozenec menší než hlavní soubor. AVIF je volitelný a pomalý (GD `imageavif`, speed 6). Starý sourozenec, který
   se při novém zpracování nevytvořil, se smaže.
6. **Doručování.** Volitelně (`htaccess_delivery`) blok `# BEGIN/END UX1 Studio image-optimizer` v `images/.htaccess` (mod_rewrite:
   podle `Accept` interně přepíše na `.avif` / `.webp`, pokud existuje; `Vary: Accept`; AddType). Zbytek souboru se nikdy nemění.
   Synchronizace při každém načtení administrace (levná kontrola souboru) a při zapnutí modulu; při vypnutí modulu se blok odstraní.
   Jen Apache/LiteSpeed (nginx pravidla ignoruje, je třeba nastavit ručně).
7. **Zálohy originálů** (`keep_backup`): `media/uxstudio-originals/<cesta>` (zrcadlí cestu od kořene webu). Složka dostane
   `.htaccess` (`Require all denied`) a `index.html`; ověřeno, že vrací 403. Pozor: na nginx `.htaccess` neplatí, složku je nutné zablokovat v konfiguraci.
8. **Statistiky:** ušetřené bajty a procenta, počet optimalizovaných, počet WebP/AVIF souborů, kolik zbývá zpracovat (sken `images/`, limit 60 000 souborů).

## Knihovna

GD i Imagick (`library` auto/gd/imagick; auto = Imagick, když je načten). Za běhu (`Optimizer::capabilities`) se zjistí podpora WebP a AVIF.
**Ověřeno jen s GD** (lokální PHP nemá Imagick); `ImagickCodec` je napsaný stejným rozhraním (`Codec`), ale nebyl spuštěn.

## Bezpečnost

- Cesty z API (`optimize`, `restore`) projdou `Optimizer::abs()`: bez `..`, jen přípony jpg/jpeg/png, nejvyšší složka musí být `images`
  nebo adresář z pluginu Filesystem – Local, `realpath` musí zůstat uvnitř té složky (symbolické odkazy ven odmítnuty). Chyba = 422 `invalid_path`.
- Zápis je atomický (`.uxs-tmp` + `rename`). Hromadný sken přeskakuje skryté složky a symlinky.
- Všechny API routy: `core.manage` + CSRF (jádro), dotazy parametrizované. Destruktivní `POST bulk/run`, `POST optimize` a `POST restore` mají navíc `acl` `core.admin` (přepisují soubory v `images/` bez ohledu na práva com_media).

## Tabulka

`#__uxstudio_image_optimizer` (migrace 1): `path_hash` (sha1 cesty, unikát), `path`, `status` (optimized|skipped|failed|restored),
`original_size`, `file_size`, `webp_size`, `avif_size`, `width`, `height`, `backup`, `source` (upload|bulk|manual|auto-image-upload), `note`, `created`, `updated`.
Bez retence (je to „paměť“, aby se nekomprimovalo podruhé).

## Nastavení

`jpeg_quality` 82, `max_width` 2560, `max_height` 0, `min_size_kb` 10, `keep_backup` ano, `library` auto, `convert_webp` ano, `webp_quality` 80,
`convert_avif` ne, `avif_quality` 55, `htaccess_delivery` ne, `auto_optimize` ano (změna se projeví od dalšího načtení), `bulk_enabled` ne,
`bulk_batch` 10, `exclude_paths` (složky relativně k `images/`).

## API (administrace, `core.manage`)

`GET overview`, `GET scan`, `GET items?page=&per=&status=&q=`, `POST bulk/run {limit}` (jedna dávka, max 50, rozpočet 20 s; vrací `done`),
`POST optimize {path, force}`, `POST restore {path}`.

## Chyby, přerušení a úklid

- Soubor, který se nepodaří zpracovat (zápis, čtení, výjimka), dostane řádek `status = failed` s velikostí souboru na disku, takže přestane být
  „čekající“ a hromadná optimalizace se na něm nezasekne; zkusí se znovu až po jeho nahrazení nebo ručním **Optimalizovat znovu**.
  Smyčka na obrazovce končí, když dávka nic nezpracovala. Před náročným zpracováním se zapíše řádek `failed` / `interrupted`, takže soubor, který
  shodí PHP (memory_limit), není v každé dávce znovu. Odhad paměti je 10 B na pixel.
- Záloha originálu se po neúspěšném zápisu hlavního souboru smaže (žádné osiřelé zálohy).
- Smazání složky ve Správci médií (`com_media.folder`) uklidí řádky a zálohy obrázků uvnitř.
- Správce médií při přesunu / přejmenování nespouští události. `GET scan` proto při zobrazení přehledu smaže řádky a zálohy souborů, které už neexistují,
  a WebP/AVIF sourozence starší než jejich obrázek. Přesunutý soubor se bere jako nový (u souboru bez řádku se do zálohy uloží už zkomprimovaná verze;
  známé omezení, proto přesouvejte obrázky raději před zapnutím hromadné optimalizace).
- Imagick: pokud chybí `autoOrient`, EXIF orientace se aplikuje ručně (rotate/flop jako v GD); cesta přes Imagick nebyla na tomto stroji spuštěna (rozšíření chybí).

## Doručování WebP/AVIF: upozornění

- Pravidla jsou nezávislá na velikosti písmen přípony (`.JPG`, `.PNG`).
- Když se obrázek nahradí mimo Správce médií (FTP, šablona), starý sourozenec `.webp` / `.avif` se dál doručuje, dokud neproběhne `scan` / bulk,
  který sourozence starší než originál smaže. Chcete-li to mít jisté, spusťte po ručních zásazích do `images/` hromadný běh nebo `scan`.
- **CDN (Cloudflare apod.) ignoruje `Vary: Accept` u obrázků.** Prohlížeč bez podpory AVIF pak může dostat AVIF z cache. Za CDN vypněte
  cache obrázků podle `Accept` (Cloudflare: Cache Rule / Polish) nebo místo `.htaccess` doručování použijte `<picture>`.

## Vědomé odchylky od WP verze

Chybí `UnusedScanner` (hledání nepoužitých obrázků s vratným smazáním) a volba `convert_png_to_jpg`. Obojí je mimo rozsah tohoto portu; v Joomle je detekce
použití obrázků nespolehlivá (obsah v článcích, modulech, šablonách, vlastních polích) a převod PNG na JPG mění URL, takže by se musely přepisovat odkazy.

## Napojení na ostatní moduly

- `auto-image-upload` po stažení obrázku zavolá `Module::optimizer()->optimizeFile()`, je-li tento modul zapnutý (komprese + zálohy + WebP/AVIF; zdroj `auto-image-upload`).
- CA: modul zatím nic nedodává (`caFeed` neimplementován).

## Ověřeno (J5 5.4.10 i J6 6.2.0)

`php -l`; zapnutí modulu + migrace; `GET overview/scan/items`; hromadný běh nad `images/` (20 souborů, ušetřeno ~3,2 MB);
druhý běh = 0 zpracovaných (nikdy podruhé); nahrání přes Správce médií API (`com_media&task=api.files`) → zkomprimováno + záloha + WebP/AVIF + řádek,
smazání přes stejné API vše uklidí; EXIF orientace 6 (600x400 → 400x600); `optimize force` z zálohy; `restore`; průhledné PNG zachová alfa;
`curl -H "Accept: image/avif"` / `image/webp` / `*/*` vrací `image/avif` / `image/webp` / `image/jpeg` s `Vary: Accept`; záloha vrací 403;
traversal (`../configuration.php`) → 422; plánovaná úloha (`scheduler:run`) vypnutá = skipped, zapnutá zpracuje dávku; typecheck a build SPA čisté.
Na J6 je plugin Behaviour – Backward Compatibility 6 vypnutý (modul nic z BC nepotřebuje).
