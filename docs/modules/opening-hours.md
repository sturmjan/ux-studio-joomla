# Opening Hours (`opening-hours`)

Pobočky, týdenní otevírací doba, výjimky, svátky, oznámení, stav "otevřeno / zavřeno" a schema.org. Kód:
`packages/lib_uxstudio/src/Modules/OpeningHours` (`Module`, `Renderer`, `Holidays`), assety `media/modules/opening-hours/`,
frontendový Joomla modul `packages/mod_uxstudio_opening_hours`, obrazovka `admin-app/src/modules/opening-hours`.

## Datový model

Tabulka `#__uxstudio_oh_locations` (migrace 1): `title, address, phone, lat, lng, hours` (JSON `mon..sun` = seznam
`{open, close}`), `exceptions` (JSON `{date, closed, label, hours[]}`), `announcements` (JSON `{text, from, to, severity}`),
`override_until` (UNIX čas) + `override_reason`, `ordering`.

## Logika "otevřeno teď"

Časová zóna je `offset` z globální konfigurace Joomly. Pro den platí: výjimka na datum > státní svátek (pokud
`holidays_closed`) > týdenní doba. Rozsah, kde `close <= open` (20:00-02:00), přesahuje do dalšího dne; počítá se i noční "ocas"
včerejšího dne. Ruční "zavřít na N minut" (1 min až 7 dní, volitelný veřejný důvod) má přednost před vším. Odpověď
`{open, location_id, checked_at, next_change, override}`. České státní svátky včetně velikonočních (`Holidays`, bez ext-calendar).

## Zobrazení na webu

- Joomla modul **UX1 Studio - Otevírací doba** (`mod_uxstudio_opening_hours`): parametr pobočka (konkrétní / první / všechny),
  zobrazení karta / jen odznak; nastavení čte z UX1 Studia.
- V obsahu (článek, vlastní modul s "Prepare content"): `{opening-hours id=1}`, `{opening-hours id=all}`, `{opening-hours-status id=1}`.
- Karta: název, odznak Otevřeno/Zavřeno, "Otevřeno do 17:00" / "Otevírá zítra v 08:00", oznámení podle závažnosti
  (info / warning / critical, barvy v nastavení), adresa, telefon (`tel:`), týdenní tabulka s dneškem, nadcházející výjimky a svátky
  (30 dní). Volitelný výběr pobočky u `id=all` (nastavení `show_location_dropdown`, JS asset, bez inline kódu).
- **schema.org JSON-LD** (`schema_enabled`): `LocalBusiness` + `OpeningHoursSpecification` (týdenní doba, a navíc výjimky a svátky
  na 12 měsíců dopředu s `validFrom/validThrough`; zavřený den = `opens=closes=00:00`). Vypisuje se na úvodní stránce a na
  zadaných položkách menu přes `addScriptDeclaration(..., 'application/ld+json')`.

## API

Administrace: `GET locations` (včetně stavu), `POST locations`, `POST locations/{id}`, `POST locations/{id}/delete`,
`POST locations/{id}/override {minutes, reason}`, `POST locations/{id}/override/clear`, `GET locations/{id}/status`,
`GET geocode?address=` (Google Maps, jinak Mapy.cz; klíče jsou šifrovaná pole `password`, ven se nevrací).
Veřejně bez přihlášení (limit 60/min na IP): `index.php?option=com_uxstudio&task=public.dispatch&format=json&route=opening-hours/locations/{id}/status`.

## Rozdíly proti WP verzi

- Lokality nejsou CPT, ale vlastní tabulka; Joomla nemá post meta.
- Shortcody `[opening_hours]` -> `{opening-hours id=N}`; navíc Joomla modul. Dekorativní widgety (hodiny, mapy) se neportovaly (jako ani ve WP verzi).
- Nově: výjimky a svátky v JSON-LD, text "otevírá zítra v...", dlaždice na přehledu, `id=all`.
- Názvy dnů a svátků jsou jazykové klíče (cs + en), ne `wp_date`.
- WP "banner_color" (výchozí barva) vypuštěna, zůstaly tři barvy podle závažnosti.

## Co chybí

- Žádný ověřený živý test geokódování (potřebuje skutečný API klíč); chybové větve (bez klíče 424, neplatná adresa 502) jsou ošetřené.
- Svátky jsou jen české (stejně jako ve WP verzi).

## Revize (oprava po review)

- `POST locations/{id}/delete` vyžaduje `core.admin`.
- JSON-LD se vkládá přes WebAssetManager (`addInlineScript`, `type=application/ld+json`), ne přes zastaralé `addScriptDeclaration`.
- Souřadnice mimo rozsah (šířka ±90, délka ±180) vrací 422 místo tichého oříznutí. Datum nadcházejících výjimek se formátuje jazykovým klíčem `UXS_OPENING_HOURS_DATE_SHORT` (cs `j. n.`, en `j M`).
- Důvod ručního uzavření je veřejný (vrací ho i veřejná routa `status`); pole v administraci je označeno "Public reason".

## Revize (browser E2E, `tests/e2e/notice.mjs`)

- Okna "Přidat / upravit pobočku" a "Zavřít na...": tělo okna nově `uxs-form uxs-form--grid` (dřív bez stylů formuláře, v tmavém režimu bílá pole a nečitelné popisky).
- Scénář prochází: vytvoření pobočky přes UI (validace souřadnic, geokódování bez klíče = chyba), týden + kopie pondělí, výjimka na dnes (zavřeno) i budoucí, oznámení, ruční uzavření a znovuotevření, veřejné JSON API, Joomla modul vytvořený v administraci Joomly (karta i jen odznak), `{opening-hours}` / `{opening-hours-status}` / `id=all` s výběrem pobočky a schema.org JSON-LD na úvodní stránce.
