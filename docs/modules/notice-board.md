# Notice Board (`notice-board`)

Veřejná úřední deska: oznámení s textem, daty vyvěšení a sejmutí, automatická archivace, přílohy, kategorie, odběr novinek
e-mailem (double opt-in), RSS a otevřená data. Kód: `packages/lib_uxstudio/src/Modules/NoticeBoard` (`Module`, `Store`, `Frontend`),
assety `media/modules/notice-board/`, obrazovka `admin-app/src/modules/notice-board`.

POZOR k zadání: WP modul NoticeBoard je **veřejná úřední deska** (shortcode, odběratelé, RSS, OFN open data), ne zprávy pro
administrátory. Portováno je to, co dělá WP verze. Pro adminy je jen dlaždice na přehledu UX Studia (aktivní / archivovaná
oznámení, odběratelé); zprávy v `com_cpanel` modul neposílá.

## Co modul dělá

- Oznámení (`#__uxstudio_notice_board`): název, HTML text (filtrovaný `InputFilter`), kategorie, číslo jednací, přílohy,
  datum vyvěšení (prázdné = dnes, budoucí = skryté), datum sejmutí (po něm automaticky do archivu), ruční archivace.
- Přílohy jsou cesty k souborům v `images/` (Joomla média), server ověří existenci, `realpath` uvnitř `images/` a whitelist
  přípon (dokumenty, obrázky, zip; žádný php/exe). Neexistující soubor se při uložení zahodí.
- Kategorie (`..._categories`) se slugem z `ApplicationHelper::stringURLSafe`.
- Odběr (`..._subscriptions`): veřejný formulář `POST notice-board/subscribe` (limit 5 / 5 min na IP, kontrola Origin) vždy
  odpoví stejně (nelze zjišťovat adresy). Nepotvrzená adresa dostane potvrzovací e-mail; u potvrzené se změna kategorií jen
  zaparkuje a použije až po kliknutí na nový odkaz; potvrzovací e-maily jedné adrese max. jednou za 6 hodin. Odkazy
  `index.php?uxs_nb=confirm|unsubscribe&token=...` zpracuje listener `onAfterRoute`, zobrazí hlášení Joomly a přesměruje na desku.
- Upozornění při zveřejnění: každé zveřejněné a ještě neohlášené oznámení (`notified`) se rozešle potvrzeným odběratelům
  odpovídající kategorie (prázdný výběr = všechny). Oznámení s budoucím datem se rozešle až ve chvíli zveřejnění (úloha
  plánovače `notice-board.housekeeping`, 1 h). Je-li upozorňování vypnuté, oznámení se jen označí jako ohlášené.
- Úloha `housekeeping`: archivace prošlých, rozeslání upozornění, mazání archivu starého než `retention_days`.
- Web: `{uxs-notice-board category="slug" limit="20" subscribe="1" filters="1"}` v článku nebo vlastním modulu (alias
  `{uxstudio_notice_board}` jako ve WP). Záložky Aktuální / Archiv, hledání, typ dokumentu, od-do, stránkování, detail
  (`?dokument=ID`), formulář odběru (JS asset z data atributů, bez inline kódu).
- RSS 2.0: `index.php?uxs_nb=feed[&cat=slug]`, otevřená data OFN Úřední deska (JSON-LD): `index.php?uxs_nb=open-data`
  (stejná struktura jako WP; i jako veřejná routa `notice-board/open-data` v JSON obálce).

## API (administrace)

`GET notices`, `GET notices/{id}`, `POST notices`, `POST notices/{id}`, `POST notices/{id}/delete`, `GET categories`,
`POST categories`, `POST categories/{id}`, `POST categories/{id}/delete`, `GET subscribers`, `POST subscribers/{id}/delete`.
Nastavení: `notify_on_publish`, `from_email`, `per_page`, `retention_days`, `board_url` (adresa stránky s deskou pro e-maily, RSS
a open data; prázdné = první článek obsahující značku, jinak úvodní stránka).

## Rozdíly proti WP verzi

- Přílohy z Joomla médií (cesta v `images/`), ne ID z knihovny médií WP; výběr souboru je textové pole s cestou.
- Shortcode `[uxstudio_notice_board]` -> `{uxs-notice-board}`; legacy `[uredni_nastenka]` a legacy REST namespace `wpextended/v1` se neportovaly.
- Feed a open data jsou na `?uxs_nb=...` (WP `?feed=` a REST).
- Potvrzení / odhlášení přesměruje na desku s hláškou Joomly (WP vracel JSON).
- Nově: upozornění i u oznámení s budoucím datem, hodinová úloha plánovače (WP archivoval při renderu).
- E-maily jdou přes Joomla mailer (a tedy přes modul SMTP, pokud je zapnutý).

## Co chybí

- Na lokálních testovacích webech nejde odeslat e-mail (PHP `mail()` není nakonfigurované), takže doručení potvrzovacího a
  notifikačního e-mailu je ověřeno jen do volání mailer API (chyba se loguje do `uxstudio.php`).
- Výběr příloh přímo z Joomla Media Manageru (zatím jen cesta).
- Editor textu oznámení je `<textarea>` (HTML filtrované serverem).

## Revize (oprava po review)

- **Odchylka od zadání (otevřené rozhodnutí vlastníka):** zadání mluvilo o zprávách pro administrátory na dashboardu Joomly, portována je veřejná úřední deska podle WP. Admin modul `mod_uxstudio_notice` (client=administrator, pozice cpanel) **není** implementován; doplní se, pokud ho vlastník chce.
- ACL: `GET subscribers`, `POST notices|categories|subscribers/{id}/delete` vyžadují `core.admin` (Manager je nesmí mazat ani číst e-maily odběratelů).
- Adresa webu v e-mailech: při CLI/cronu (`scheduler:run`) Joomla vymýšlí hostitele `joomla.invalid`. Modul si proto při požadavku z webu/administrace zapamatuje `Uri::root()` (tabulka `#__uxstudio_notice_board_meta`, migrace 2) a v CLI použije `live_site` nebo zapamatovanou hodnotu. Odhlašovací odkazy a přílohy v e-mailu z úlohy tedy fungují; podmínka je, že web aspoň jednou navštívila administrace (otevření obrazovky Úřední deska to zajistí).
- Odběrový formulář funguje i bez JS: klasický POST na `?uxs_nb=subscribe` (Joomla form token, rate limit 5 / 5 min na IP, přesměrování s hláškou).
- Opakované kliknutí na potvrzovací odkaz už neukáže chybu.
- Neexistující kategorie oznámení = 422. Z administračního požadavku se odešle nejvýše 50 e-mailů; větší rozesílka zůstává na hodinové úloze.
