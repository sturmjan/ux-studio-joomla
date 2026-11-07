# email-health (WP: EmailHealth)

- **Test doručení:** pošle zkušební e-mail stejným mailerem jako ostatní pošta (smtp-email spojení i email-log se uplatní), zapíše výsledek,
  způsob (smtp (host) / brevo (API) / joomla mail) a pokusy do `#__uxstudio_email_health` (retence 180 dní).
- **Kontrola DNS** domény skutečného odesílatele (nebo `dns_domain`): MX, SPF (počet lookupů max 10, +all/?all, více záznamů, ptr,
  zda je autorizován Brevo), DKIM (zkouší běžné selektory + vlastní z nastavení; slabý 1024bit klíč, odvolaný klíč), DMARC (p=, rua, pct, dědění z nadřazené domény).
  Jen DNS dotazy, žádná spojení.
- **Plánovač:** úloha `test` (hodinový tik, interval 1-24 h v nastavení, vypnuto výchozí) a `dns` (denně, zapnuto).
- Modul je `core.admin`; `POST test` max 5x a `POST dns` max 10x za 10 minut na uživatele (429). Souhrny a poznámky DNS se ukládají jako klíč + argumenty a překládají se až při zobrazení.
- API: `GET status`, `POST test {recipient?}`, `POST dns {domain?}`.
- `caFeed('email-health')` -> tvar WP (`available,success,error,method,tested_at,age_hours,mail_tester`, časy v časové zóně webu jako ve WP, navíc `tested_at_iso`) + `dns {domain,checked_at,overall,mx,spf,dkim,dmarc}`.

Rozdíly: DNS kontrola je nová (WP verze ji nemá); mail-tester se nepřebírá (jako ve WP).
Omezení: selektory DKIM nelze v DNS vypsat, proto jen odhad podle seznamu; organizační doména bez public suffix listu (heuristika).
