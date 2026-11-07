# email-log (WP: EmailLog)

Zaznamenává každý e-mail odeslaný Joomlou a rozšířeními (včetně jádra, např. reset hesla) do `#__uxstudio_email_log`.

- Zachycení přes `MailHub` (viz smtp-email.md): řádek `pending` před odesláním, po odeslání `success`/`error` + `mailer` + `attempts` (JSON).
- Zdroj (`source`): plugin/komponenta/modul/šablona z backtrace, `UX Studio: <Modul>` pro jiné moduly UX Studia.
- Detail (text, hlavičky, názvy příloh), opětovné odeslání (nový řádek se zdrojem "resend of #id"; přílohy se nepřipojují),
  smazání záznamu, vyčištění, export CSV (UTF-8 BOM, ochrana proti formula injection), filtr stav/hledání/data.
- Nastavení: retence ve dnech (0 = navždy; denní úloha jádra), ukládat text zprávy, detekce zdroje.
- Zprávy z jádra s jednorázovými kódy (`com_users` reset/připomenutí, MFA pluginy) se standardně logují bez textu (volba `mask_sensitive`, výchozí zapnuto); takové nejdou odeslat znovu.
- Náhled HTML těla blokuje obrázky z internetu (CSP v iframe), volitelně jde zapnout; opakované odeslání vyžaduje potvrzení.
- Modul je `core.admin` (v textech bývají jednorázové odkazy).
- `caFeed('email-log', query)` -> `{items:[{id,created_at,to_email,subject,status(sent|failed|pending),error_message,source}], total}`;
  query `limit,status,search,date_from,date_to`.

Rozdíly: stránkování po stránkách místo offsetu, resend vytváří nový záznam místo přepsání původního, hromadné mazání jen "vše".
