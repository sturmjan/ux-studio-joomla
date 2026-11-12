# Modul forms (Tvůrce formulářů)

Port WP modulu `Modules/Forms`. Kód: `packages/lib_uxstudio/src/Modules/Forms/`, obrazovky `admin-app/src/modules/forms/`,
frontendový Joomla modul `packages/mod_uxstudio_form/`, assety `packages/lib_uxstudio/media/modules/forms/` (`forms.js`, `forms.css`).

## Co modul dělá

- **Editor formuláře** v administraci: seznam formulářů (stav, počet polí, odpovědí, nepřečtených, zkratka), duplikace (jako koncept), mazání
  (včetně odpovědí a souborů). Pole: text, e-mail, telefon, URL, číslo, datum, dlouhý text, rozbalovací seznam, přepínače, zaškrtávací
  skupina, jedno políčko, souhlas (GDPR, volitelný odkaz na podmínky), nahrání souboru, skrytá hodnota, textový blok (HTML jen z whitelistu značek).
  U pole: popisek, klíč, nápověda, placeholder, výchozí hodnota, povinnost, šířka (25-100 %), CSS třída, min/max délka či rozsah,
  **podmínky zobrazení** (rovná se / nerovná / obsahuje / prázdné / neprázdné, všechny nebo kterákoli).
- **Validace na serveru** (`Submitter`): typy, povinná pole, délky, rozsahy, whitelist voleb, datum, e-mail, URL, telefon; skrytá (podmíněná) pole
  se neověřují ani neukládají. Chyby se vrací po polích (`data.errors`), frontend je ukáže u polí.
- **Ochrana proti spamu**: honeypot (pole skryté přes CSS třídu, ne inline), časová kontrola (podepsaný token s časem vykreslení, HMAC z `$secret`,
  minimální doba vyplnění nastavitelná u formuláře), rate limit (generický limit rout v PublicApi + limit na IP a formulář za hodinu
  v nastavení modulu, přes `Core\RateLimiter`), volitelně **Cloudflare Turnstile** (klíče v nastavení modulu, tajný klíč šifrovaně,
  ověření na serveru přes `Core\Http`). Odeslání jde přes PublicApi `forms/submit` s kontrolou Origin.
- **E-maily** přes Joomla mailer: upozornění (příjemci, předmět, Reply-To z pole s e-mailem, volitelně přílohy) a potvrzení návštěvníkovi.
  Zástupné výrazy `{form_title}`, `{site_name}`, `{submission_id}`, `{all_fields}`, `{klíč_pole}`. Selhání e-mailu odpověď neztratí
  (stav `failed` u odpovědi), jen bez archivu a bez doručení návštěvník dostane chybu.
- **Archiv odpovědí**: seznam se stránkováním, filtr formuláře, hledání (LIKE s escapovanými zástupnými znaky), jen nepřečtené,
  detail (pole podle editoru včetně popisků, soubory ke stažení), označit přečtené/nepřečtené, smazat, **export CSV** (UTF-8 BOM, středník,
  ochrana proti CSV injection). IP se ukládá anonymizovaná (poslední oktet / 80 bitů IPv6). Retence ve dnech v nastavení (0 = navždy),
  maže úloha plánovače `forms.cleanup` (denně) i se soubory.
- **Po odeslání**: text poděkování nebo přesměrování (jen cesta na webu nebo `http(s)://`, `javascript:` a `//host` se zahodí).
- **Soubory**: kontrola přípony (whitelist, nikdy php/html/js/svg/exe...), velikosti (max 5 MB, na poli nižší) i skutečného MIME přes `finfo`.
  Ukládají se do `<log_path>/uxs-forms/` (výchozí `administrator/logs/uxs-forms/`) pod náhodným názvem bez přípony, složka má
  `.htaccess` (deny all), `web.config`, `index.html`. Stažení jen přes admin API (ACL + CSRF). Ověřeno: přímý přístup vrací 403.

## Vložení na web

- **Zkratka** `{uxsform N}` (i `{uxsform id=N}`) v článku, modulu Vlastní HTML a čemkoli, co projde `onContentPrepare`.
- **Joomla modul „UX1 Studio - Formulář“** (`mod_uxstudio_form`): výběr publikovaného formuláře v parametrech (pole typu `sql`), pozice šablony.
- Výstup je CSP-friendly: žádný inline skript ani styl, `forms.js`/`forms.css` přes `$ctx->useScript/useStyle`, konfigurace v `data-*`,
  barva zvýraznění přes `addInlineStyle` WebAssetManageru (nonce řeší Joomla). Turnstile se načítá jako externí skript.
  Výchozí CSS lze vypnout v nastavení (třídy `uxs-form*`, proměnné `--uxs-form-*`).

## Nastavení modulu

Výchozí příjemce, načítat výchozí CSS, barva zvýraznění, max. velikost souboru (1-5 MB), mazání odpovědí po N dnech, limit odeslání za hodinu,
Turnstile site/secret key. Nastavení jednotlivého formuláře (příjemci, předmět, potvrzení, přesměrování, archiv ano/ne, min. doba, Turnstile)
je přímo v editoru formuláře.

## Datový model

`#__uxstudio_forms_forms` (id, title, status published|draft, fields JSON, settings JSON, created, modified),
`#__uxstudio_forms_submissions` (id, form_id, created, is_read, ip, user_agent, page_url, data JSON, files JSON, mail_status).
Migrace verze 1 v `Module::migrations()`.

## Rozdíly proti WP verzi

- Zatím **není**: vícekrokové formuláře, pole podpis a captcha jako typ pole (je jen Turnstile na úrovni formuláře), AI generování formulářů,
  galerie šablon, revize formuláře, šablony HTML e-mailu (e-mail je jednoduchá tabulka), akce webhook a vytvoření příspěvku, sledování
  (Pixel/GA události), Elementor/Gutenberg widgety (nahrazeno zkratkou a Joomla modulem), vzhled formuláře po polích (jen barva zvýraznění
  a CSS proměnné), widget na dashboardu je jen počet nepřečtených.
- **Soubory jdou v JSON jako base64** (PublicApi umí jen JSON tělo, ne multipart). Strop těla je 8 MB, proto max. 5 MB na soubor a 5 souborů na pole,
  prohlížeč soubory čte přes FileReader. Návrh na jádro: podpora `multipart/form-data` v PublicApi.
- Formulář nefunguje bez JavaScriptu (`<form action="#">` se nativně neodešle a `<noscript>` to návštěvníkovi řekne; surový JSON se nezobrazí).
- Po odeslání se jen vypíše stav a formulář se resetuje (WP umí režim „nahradit formulář poděkováním“).
- Chybí (priorita od nejvyšší): režim náhrady formuláře poděkováním, akce (webhook, další e-maily), šablony HTML e-mailů, vícekrokové formuláře, šablony formulářů,
  revize, captcha/podpis jako pole, sledování konverzí.
- Seznam polí v editoru je vlastní `<table>`, ne sdílený `DataTable`: potřebuje řazení tlačítky nahoru/dolů a akce v řádku, které `DataTable` nenabízí.
- Destruktivní a hromadné routy (`form/{id}/delete`, `submissions/delete`, `form/{id}/export`) vyžadují `core.admin`.
- Ochrana proti spamu: podepsaný časový token platí 48 h (starší se odmítne), hodinový limit IP+formulář se počítá jen pro platná odeslání, honeypot je skrytý atributem `hidden` (funguje i s vypnutým výchozím CSS).
  Potvrzovací e-mail může obsahovat hodnoty z formuláře (`{pole}`), proto u takových šablon doporučujeme zapnout Turnstile.
- Soubory: klient i server hlídají max. 5 souborů na pole a celkovou velikost těla (přes limit server vrací chybu pole, nic se tiše neořezává).
- Hodnota políčka souhlasu se ukládá jako „Ano/Yes“ podle jazyka návštěvníka.
- Napojení na CA (`caFeed`) modul neimplementuje, `docs/CA-INTEGRACE.md` formuláře nezmiňuje.

## Ověřeno (J5 5.4.10 i J6 6.2.0, bez pluginu Backward Compatibility)

php -l, `tsc`, `vite build`, zapnutí modulu přes API a migrace, všechny admin routy přes `jadmin`, veřejný submit (OK, nevalidní, podmínky, honeypot,
chybný/chybějící token, Origin cizího webu 403, rate limit 429, příliš rychlé odeslání, Turnstile bez tokenu 400, soubor OK / `.php` / falešné PNG /
příliš velký), skutečný e-mail zachycený SMTP výlevkou (příjemci, předmět s placeholdery, Reply-To, tabulka, potvrzení), CSV export, smazání odpovědi
smaže soubor, plánovaná úloha retence, přístup k uloženému souboru 403, prohlížeč (Playwright): podmíněné pole, nativní validace, odeslání s přílohou,
obrazovky administrace.
