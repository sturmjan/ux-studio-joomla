# popup-manager (WP: PopupManager)

Libovolný počet popupů s obsahem, spouštěči, cílením, plánováním a statistikou. Žádný Joomla modul (`mod_`) není potřeba:
markup se vkládá do stránky v `onAfterRender`, assety jen na stránkách, kde nějaký popup vyhoví.

- **Obsah:** nadpis, text (HTML, na serveru ořezané whitelistem tagů/atributů přes `InputFilter` + odstranění `javascript:`/`data:` URL, `rel="noopener noreferrer"`
  u `target=_blank`), obrázek (cesta v mediích Joomly nebo https URL) s alt textem, tlačítko (CTA) s adresou (`#` jen zavře, `/cesta`, http(s), mailto:, tel:).
- **Vzhled:** umístění (střed = modální okno s ztmavením, lišta nahoře/dole, roh vpravo/vlevo dole), animace (fade/slide/žádná), šířka, 4 barvy, síla ztmavení.
  Barvy a šířka se aplikují přes CSSOM (`style.setProperty` na CSS proměnné), takže v markupu není žádný inline `style=` ani `on*=` (CSP).
- **Spouštěče:** ihned, po zpoždění (s), po posunu stránky (%), exit intent (myš opustí okno horním okrajem; na dotykových zařízeních rychlý posun zpět nahoru; zbrojí se po N s).
- **Cílení:** režim stránek (všude / jen vybrané / všude kromě) podle **položek menu** Joomly a/nebo **fragmentů URL** (jeden na řádek), zařízení (podle šířky okna: telefon pod 768 px, tablet 768-1023 px, počítač od 1024 px; prázdné = všechna),
  publikum (všichni / nepřihlášení / přihlášení), priorita. Stránka bez vlastní položky menu (login, URL komponenty) se nepovažuje za Home, i když Joomla jako aktivní položku
  podstrčí výchozí: položka se počítá, jen když její komponenta stránku skutečně vykresluje (`Support::activeMenuItem`).
- **Plánování:** „zobrazovat od / do“ v čase webu (uloženo v UTC). Vyhodnocuje se na serveru (popup se vůbec nevykreslí) **a** v prohlížeči (`from`/`until`), takže funguje i s page cache.
- **Frekvence:** vždy / jednou za relaci (sessionStorage) / jednou za N dní / jen jednou; úložiště `localStorage` nebo cookie (nastavení modulu, při zablokovaném storage automaticky cookie);
  „po kliknutí na tlačítko už nikdy“ (výchozí zapnuto).
- **Přístupnost:** `role="dialog"`, `aria-modal` (jen střed), `aria-labelledby`/`aria-label`, fokus se přesune do dialogu a je uvězněn (Tab/Shift+Tab), Esc zavírá, po zavření návrat fokusu,
  zámek scrollu, zavírací tlačítko s popiskem, respektuje `prefers-reduced-motion`. Rohy a lišty stránku neblokují (nekradou fokus). Víc popupů se řadí do fronty (otevřený je vždy jen jeden).
- **Statistika:** události shown / closed / clicked (`#__uxstudio_popup_stats`, bez osobních údajů), veřejná routa `popup-manager/track` (limit 120/min/IP, Origin musí být tento web,
  neprozradí, zda popup existuje, počítá jen publikované). Retence výchozí 365 dní (nastavení, 0 = navždy), denní úloha jádra.
- **Náhled:** tlačítko „Náhled na webu“ otevře podepsaný odkaz (`uxs_preview=popup`, HMAC z `$secret` webu, platí hodinu), který popup ukáže ihned bez ohledu na stav, cílení, plán a frekvenci a nic nepočítá.

## Nastavení modulu

`tracking` (sledování zobrazení/kliků), `storage` (localStorage / cookie), `max_per_page` (1-5 popupů načtených na stránku, vyhrají nejvyšší priority), `stats_retention_days`.

## API (administrace, `core.manage`)

`GET popups`, `POST popups`, `GET popups/{id}`, `POST popups/{id}` (neposlané klíče zůstanou), `POST popups/{id}/status`, `/duplicate`, `/delete`, `/reset-stats`,
`GET popups/{id}/preview-url`, `GET menu-items`. Veřejná: `POST track`. Chyby validace 422 (název, URL tlačítka, plán).

## Data

`#__uxstudio_popups` (title, status, priority, starts_at, ends_at, config JSON, created, modified), `#__uxstudio_popup_stats`. Vypnutí modulu data nemaže.

## Rozdíly proti WP verzi

- Úložiště je vlastní tabulka (WP: CPT + post meta); žádná migrace ze starých dat (stejně jako ve WP).
- Cílení podle **položek menu a URL** místo ID stránek a rolí WP (role nahrazuje publikum host/přihlášený); nově zařízení, plánování, priorita, frekvence, pozice, animace, obrázek, náhled.
- Obsah je HTML (WP: jen čistý text), WP kreslilo inline `<style>`/`<script>`, tady jsou statické CSS/JS přes WebAssetManager (CSP).
- Editor je vlastní obrazovka (seznam + editor se sekcemi), statistiky v seznamu (zobrazeno / kliknuto / CTR).
- `cache_compat` (data-cache-ignore pro WP Rocket) se nepřebírá; page cache Joomly cachuje jen hosty, a proto je cílení „host/přihlášený“ bezpečné, plán se navíc hlídá v JS.

## Co chybí / omezení

- Scroll spouštěč se nikdy nespustí na stránce, která je příliš krátká na posun.
- Pole „text“ je prostý `<textarea>` s HTML (core nemá WYSIWYG editor; `richtext` je v SPA jen textarea). Obrázek se zadává cestou, ne výběrem z Médií.
- Cílení podle kategorií/článků a jazyka není; stránka se pozná podle položky menu nebo fragmentu URL.
- Pokud se tytéž soubory přidají do page cache třetí stranou na úrovni serveru (mimo Joomlu), plán se hlídá jen v JS.
- Menu cílení: položka menu se uzná, jen když request opravdu patří jejímu obsahu. Výchozí položka (Home) platí jen pro svou stránku (shodný view/id), takže článek bez vlastní položky se jako Home nepočítá.
- Statistiky (zobrazeno/zavřeno/kliknuto) jde nafouknout: veřejná routa `track` je bez přihlášení a bez hlavičky Origin (curl) přijme libovolný publikovaný popup_id, 120 událostí/min/IP. CTR je orientační marketingová metrika, ne auditní údaj.
- Destruktivní akce (smazání popupu, reset statistik) vyžadují `core.admin`; v SPA se tlačítka bez tohoto práva skryjí.
- Popup-manager a exit-popup se vzájemně nepřekrývají: modální okno druhého modulu počká (kontrola každou sekundu), dokud je otevřené první; Esc a focus trap obsluhuje vždy jen otevřené okno.
- Odkazy `target=_blank` v obsahu dostanou vždy `rel="noopener noreferrer"` (vlastní `rel` se přepíše); atribut `class` se ze sanitizace odstraňuje.
