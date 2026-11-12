# Modul service-requests (servisní požadavky)

Správce webu z administrace zadá servisní požadavek (ticket), ten se přes `CaClient` (stránka `ticket_api` Centrální aplikace) odešle
do CA, a správce vidí jeho stav a konverzaci a může odpovídat. Vzor ve WP verzi: `includes/Modules/ServiceRequests/{Module,Sync,CentralClient}.php`
a `ContentSync/NodeHealth::service_requests()`. Protokol CA strany: `_ca-wt-joomla/controllers/TicketApiController.php`.

Soubory: `packages/lib_uxstudio/src/Modules/ServiceRequests/` (`Module`, `Store`, `Sync`, `meta.json`, `language/{en-GB,cs-CZ}`),
`admin-app/src/modules/service-requests/` (`Page.tsx`, `i18n/{en,cs}.json`). Migrace verze 1: tabulky `#__uxstudio_service_requests`
(požadavek + zrcadlo CA: číslo, stav, vlákno) a `#__uxstudio_service_request_outbox` (fronta odpovědí klienta). Časy jsou UTC.

## Jak to funguje

- **Vlastníkem požadavku je CA.** Lokálně je řádek, aby správce své požadavky viděl i při výpadku CA; číslo (`TCK-2026-0001`), stav (`new, triaged,
  in_progress, waiting_client, waiting_vendor, resolved, closed, rejected`) a konverzace se berou z CA (`central_*` sloupce, vlákno jako JSON).
- **Fronta s opakováním.** Zápis se uloží lokálně, hned se zkusí odeslat (`ticket_api?action=create|message`) a když to selže, opakuje ho
  úloha plánovače `service-requests.sync` (každých 5 minut: odešle čekající požadavky a odpovědi, obnoví nevyřešené tickety přes `action=detail`).
  Backoff 0, 1, 5, 15, 60, 180, 360, 720 minut, po 8 pokusech se přestane. Trvalá chyba (HTTP 4xx kromě 429/408: web neznámý, špatný klíč, špatná
  data) opakování okamžitě zastaví a řádek zůstane ve stavu `error` s důvodem (viditelné v UI, tlačítko Obnovit pokus resetuje).
- **Idempotence.** `external_id = uxs-<lokální id>` (u zpráv `msg-<id>-<hex>`), CA dělá upsert, takže opakování nevytvoří duplicitu.
- **Prostředí.** Při odeslání se jednou sebere snímek: stránka, Joomla, PHP, šablona, jazyk, skupina uživatele, prohlížeč a seznam ne-core
  rozšíření (do 30). Posílá se v `environment` (CA ho zploští na `klíč => text`).
- **Bez CA to funguje také:** `CaClient::fromContext()` vrátí `null` (konektor vypnutý / nespárováno), požadavek zůstane `pending`, e-mail
  s upozorněním odejde (nastavení `delivery_email`, jinak adresa odesílatele z globální konfigurace; `notify_new`), SPA ukáže upozornění.
  Po spárování ho odešle úloha plánovače.
- **Přílohy zatím ne** (WP verze posílá multipart přes `action=attachment`; `CaClient` multipart neumí, viz core_requests).

## API (`index.php?option=com_uxstudio&task=api.dispatch&route=service-requests/...`, ACL `core.manage`)

| Routa | Účel |
|---|---|
| `GET items[?status=open\|in_progress\|done]` | seznam (max. 500) + `connected` + počty |
| `GET items/{id}` | detail včetně vlákna a prostředí |
| `POST items` `{title, description, type, priority, page_url, requester_email}` | založí a hned zkusí odeslat; `type` bug/change/question/task, `priority` low/normal/high/critical (jinak výchozí) |
| `POST items/{id}/reply` `{body}` | odpověď do vlákna (409 `not_synced`, dokud ticket nedorazil do CA) |
| `POST items/{id}/resync` | odeslat / obnovit, vyprázdnit frontu odpovědí |
| `POST items/{id}/status` `{status}` | lokální stav požadavku, který CA ještě nezná (jinak 409 `central_owns_status`) |
| `POST items/{id}/delete` | smaže jen lokální kopii (ticket zůstává v CA) |
| `GET state` | `connected` + počty |

## Feed pro CA

`Module::caFeed('service-requests', $query, $body)` vrací seznam v "legacy" tvaru, který CA zrcadlí (stejný jako WP `NodeHealth::service_requests`):
`id, subject, status (new|in_progress|completed), user_name, user_email, url, budget, deadline, admin_note, created_at, updated_at`;
filtr `?status=new|in_progress|completed` (`rejected` vrací `[]`). **Aby ho CA mohla volat, musí ho `CaConnector\Node` zveřejnit** (viz core_requests).

## Ověřeno (J5 5.4.10 i J6 6.2.0, proti lokální CA `http://127.0.0.1/_ca-wt-joomla/`)

- vytvoření požadavku (české znaky) -> `TCK-2026-000x`, `synced`; odpověď klienta se objeví ve vláknu CA; `resync`; 404/409/422 chybové cesty;
- výpadek CA (`ca_url` dočasně na nedostupný port): požadavek `pending` + `sync_error`, po obnovení adresy ho odeslala úloha plánovače
  (`scheduler:run`) po vypršení backoffu; hotový řádek má `sync_attempts = 0`;
- `caFeed` zavolán přímo v CLI bootstrapu Joomly (tvar výše, filtr stavu); `php -l`, `tsc --noEmit`, `vite build`;
- Backward Compatibility plugin na J6 je vypnutý (`compat6` enabled = 0), modul nepoužívá žádné zastaralé API.

## Omezení

- Ruční klikání v prohlížeči neproběhlo (Playwright MCP nebyl k dispozici), SPA prošla typecheckem a buildem, API routy ověřeny přes `jadmin`.
- Notifikační e-mail na testovacích webech selhává ("Could not instantiate mail function") - jen se zaloguje, požadavek tím není dotčen.
- Maže se jen lokální kopie; stejně jako ve WP verzi se ticket v CA nemaže.

## Revize (oprava nálezů reviewera)

- Fronta odpovědí: tlačítko Obnovit (`items/{id}/resync`) vynuluje pokusy odpovědí daného požadavku; odpovědi, kterým došly pokusy, vrací `format()` jako `failed_replies` + `failed_reply_error` a SPA je ukáže chybovým pruhem s tlačítkem Zkusit znovu. `pending_replies` počítá už jen odpovědi, které se ještě zkoušejí.
- Po vyčerpání 8 přechodných pokusů se požadavek přepne na `sync_state = error` ("Gave up after 8 attempts: ...") a je vidět jako Nedoručeno (počítá se do `counts.failed`); Obnovit začne znovu.
- 401 / 403 / 404 z CA už nejsou trvalé chyby (problém párování, ne zprávy), jdou běžným backoffem. HTTP 200 se `success = false` je trvalá chyba daného požadavku (neopakuje se 8x).
- Ruční Obnovit vrací při chybě CA pole `warning` (SPA ukáže toast).
- Bez spojení s CA (nebo u nedoručeného požadavku) SPA ukazuje lokální stav (Otevřený / Řeší se / Hotovo), v detailu je select stavu (`items/{id}/status`) a potvrzení mazání říká, že lokální kopie je jediná.
- `items/{id}/delete` vyžaduje `core.admin`.
- Notifikace jde přes `Core\Mail::send()` (zdroj "UX1 Studio: Service requests").
- Prostředí: jádrová rozšíření se vyřazují přes `ExtensionHelper::checkIfCoreExtension`, názvy jsou elementy (`com_x`, `plg_skupina_x`, `mod_x`, `lib_x`) + verze.
- Feed `service-requests` pro CA je v modulu hotový, ale `CaConnector\Node::FEEDS` ani `CAPABILITY_MODULES` ho neobsahují, takže ho CA dnes nedosáhne (viz core_requests). Pozor: Node vrací 404, když je modul vypnutý, WP čte tabulku i při vypnutém modulu.
