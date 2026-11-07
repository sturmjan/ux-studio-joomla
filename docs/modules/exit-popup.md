# exit-popup (WP: ExitPopup)

Jeden popup (nastavení modulu) s volitelným sběrem e-mailů, který se ukáže, když chce návštěvník stránku opustit. Sdílí pomocnou třídu `Modules\PopupManager\Support`
(sanitizace HTML/URL/obrázku, časová pásma, cílení na položky menu, podepsaný náhled); třída se načítá podle namespace, popup-manager k tomu nemusí být zapnutý.

- **Detekce odchodu:** myš opustí okno horním okrajem (s prodlevou, vrátí-li se, ruší se), přepnutí záložky, ztráta fokusu okna (ignoruje přesun do iframe), nečinnost, rychlý posun nahoru
  (limity zvlášť pro počítač a telefon), plus nezávislý spouštěč „po N sekundách na stránce“. Detekce se zbrojí po `delay_seconds`.
- **Obsah a vzhled:** nadpis (prázdný = přeložený výchozí text), text (HTML, whitelist), obrázek + alt, rozložení svislé/vodorovné, tlačítko (CTA), barvy, ztmavení, šířka. Bez inline stylů (CSSOM + CSS proměnné).
- **Frekvence:** relace / jednou za N dní / jen jednou / vždy, úložiště localStorage nebo cookie. Po úspěšném odeslání e-mailu se popup už nezobrazí (`uxs_ep_done`).
- **Cílení:** všude / jen vybrané / všude kromě (položky menu + fragmenty URL), zařízení, publikum (všichni / hosté / přihlášení), plán od-do (server i prohlížeč). Seznam položek menu se do schématu
  nastavení načítá jen v administraci (na webu se na stránku nepřidává žádný dotaz).
- **Sběr e-mailů:** pole, text tlačítka, zpráva po úspěchu, volitelný **text souhlasu** (povinné zaškrtávátko, uloží se, že souhlas byl dán), honeypot, validace na serveru, idempotentní odpověď
  (existující adresa odpoví jako nová, nelze zjišťovat odběry). Veřejná routa `exit-popup/subscribe`: 5 pokusů / 10 min / IP, Origin musí být tento web, tělo max 4 kB. Adresy se ukládají v lower-case,
  jedinečný klíč; místo IP se ukládá jen HMAC hash (klíč `$secret` webu).
- **Autoresponder:** pokud je zapnutý a vyplněný předmět + text, odešle se novému odběrateli Joomla mailerem (HTML whitelist), strop 30 zpráv za hodinu na web, chyba se jen zaloguje (`uxstudio`).
- **Odběratelé:** záložka se stránkováním po 50, hledáním, smazáním jednoho, exportem CSV (sestavuje prohlížeč, ochrana proti formulím `= + - @`) a „Smazat vše“ (jen `core.admin`).
  Retence odběratelů volitelná (výchozí 0 = navždy).
- **Náhled:** podepsaný odkaz (`uxs_preview=exit`), ukáže popup ihned, formulář nic neukládá.

## API (administrace)

`GET subscribers?page&q`, `GET subscribers/export`, `POST subscribers/{id}/delete`, `POST subscribers/purge` (core.admin), `GET preview-url`. Veřejná: `POST subscribe`.

## Data

`#__uxstudio_exit_popup_emails (id, created, email unique, page_url, ip_hash, consent)`. Vypnutí modulu data nemaže.

## Rozdíly proti WP verzi

- Cílení podle položek menu / URL / zařízení / publika / plánu místo „typy příspěvků + hide_for_logged_in“.
- Přibyl souhlas (GDPR), honeypot, sitewide limit autorespondéru, retence, smazání jednotlivých adres, hledání a stránkování, náhled, frekvence „jen jednou“ a volba úložiště,
  nastavitelná šířka. Ikona (`popup_icon`) se nepřebírá.
- CSV export jde přes prohlížeč (JSON API nevrací soubor), proto je sloupec `created_utc`.
- Texty výchozích hodnot (nadpis, tlačítko ...) jsou v překladových souborech, takže se přeloží podle jazyka webu; prázdné pole = výchozí text.
- Mailer je Joomla (`MailerFactoryInterface`), tedy i SMTP z globální konfigurace; nepoužívá wp_mail.

## Co chybí / omezení

- Na lokálním webu bez funkční pošty autorespondér selže (varování v logu `uxstudio`), přihlášení se přesto uloží.
- `richtext` je v administraci textarea s HTML, obrázek se zadává cestou k souboru.
- Při `frequency_mode=always` se při přepnutí záložek/oken může popup opakovat při každém načtení stránky.

## Bezpečné výchozí chování a rizika

- Po zapnutí modulu se popup **nezobrazuje**, dokud není v obsahu zapnuté „Zobrazovat na webu“ (`active`, výchozí vypnuto); stejně tak veřejná routa `subscribe` odpovídá 404. Sběr e-mailů tak nezačne bez vědomé konfigurace (text souhlasu, obsah).
- `subscribe` odmítá požadavky mimo plán od-do. Není double opt-in: kdokoli může zadat cizí adresu (limit 5 / 10 min / IP, autoresponder max 30 / h na celý web). Pokud je to rizikové, nezapínejte autoresponder, nebo žádejte souhlas v textu.
- Export a mazání odběratelů vyžaduje `core.admin` (osobní údaje); v SPA se tlačítka skrývají podle `core/meta.canAdmin`.
- Nedotýká se překrytí s popup-managerem: viz docs/modules/popup-manager.md.
