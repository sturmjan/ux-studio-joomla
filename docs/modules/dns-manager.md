# dns-manager (WP: DnsManager)

Správa DNS záznamů domény webu přes broker `dns_manage` Centrální aplikace (`CaClient`). Token Cloudflare je jen v CA, web nikdy
neposílá, kterou zónu upravit: CA ji odvodí z podepsané `site_url`. Modul je tedy strana webu: kdo smí, pojistky kolem kritických
záznamů, čerstvé potvrzení hesla, lokální auditní log a kontrola propagace. Vzor ve WP: `includes/Modules/DnsManager/*`,
`src/modules/dns-manager/Page.tsx`; protokol brokeru: `_ca-wt-joomla/controllers/DnsManageController.php`.

Soubory: `packages/lib_uxstudio/src/Modules/DnsManager/` (`Module`, `Guard`, `Journal`, `Propagation`, `meta.json`, `language/{en-GB,cs-CZ}`),
`admin-app/src/modules/dns-manager/` (`Page.tsx`, `i18n/{en,cs}.json`). Migrace verze 1: `#__uxstudio_dns_log` (retence 365 dní).

## Stavy (bez spárování to nespadne)

`GET status` vrací `state`: `connector_off` (modul ca-connector vypnutý), `not_paired`, `ca_unreachable`, `ca_error`, `inactive`
(CA zná web, ale DNS není zapnutá: `reason` = `not_configured | disabled | zone_mismatch | broken`) a `active`. SPA ke každému stavu
ukáže srozumitelný text (cs + en) a u prvních dvou tlačítko na modul Centrální aplikace. `records` vrací v neaktivním stavu prázdný
seznam, zápisy `409 not_active`, historie spadne na lokální kopii.

## Oprávnění

- Čtení (`status, records, log, propagation`): ACL modulu `core.manage`.
- Zápisy (`ack, reauth, records, records/{id}, records/{id}/delete, revert`): trasa vyžaduje `core.admin` **a** uživatel musí být v nastavení
  `manage_user_ids` (výchozí nikdo, jako ve WP). Možnosti výběru = aktivní uživatelé ve skupinách s `core.admin`.
- Bezpečnostní přepínače (`manage_user_ids`, `allow_critical_delete`) **nejsou v obecném nastavení modulu** (`settingsSchema()` je prázdné), protože to ukládá
  každý s `core.manage` (skupina Administrator). Jsou v `CoreStore` modulu a mění je jen trasa `POST config` (`core.admin` + potvrzení hesla, zapíše se do logu
  jako akce `config`); `GET config` (`core.admin`) vrací hodnoty a seznam správců. Dřívější hodnoty z obecného nastavení se záměrně nepřebírají.

## Pojistky zápisu (pořadí kontrol na serveru)

1. `core.admin` + seznam správců (`403 not_allowed`), stav `active` (`409 not_active`), rate limit 40 zápisů/min (`429`).
2. Jednorázové potvrzení rizik uživatelem (`POST ack`, zapíše se i do CA; `403 ack_required`).
3. **Čerstvé potvrzení hesla** (ve WP "čerstvé přihlášení"): `POST reauth {password}` ověří heslo uživatele (`UserHelper::verifyPassword`),
   uloží čas do session a platí 15 minut (`403 reauth_required`). Čas posílá CA jako `actor.auth_time` (CA hlídá stejný limit).
   Rate limit 5 pokusů/15 min, špatné heslo se loguje. Účet bez hesla (SSO) vrací `409 reauth_unavailable`.
4. `confirm: true` v těle (`422 confirm_required`), validace záznamu (`Guard::record`: typy A/AAAA/CNAME/MX/TXT, veřejné IP, názvy, priorita,
   TTL, žádné wildcardy), CA validuje znovu.
5. **Ochrana kritických záznamů** (společná funkce `protect()` pro `update`, `delete` i `revert`) (`Guard::risks`): `mx`, `email_auth` (SPF/DKIM/DMARC), `web` (kořen zóny, host webu a `www`), `service` (ostatní A/AAAA/CNAME).
   - Smazání nebo přejmenování / změna typu záznamu `mx` a `web` je zakázané (`403 critical_protected`), dokud správce nezapne
     nastavení `allow_critical_delete`.
   - Smazání jakéhokoli rizikového záznamu a změna záznamu `mx / web / email_auth` vyžaduje přepsání plného názvu (`confirm_name`, `422 name_required`).
   - Odmítnutí se zapíše do logu se stavem `denied`. Potvrzený název se porovnává přesně s plným FQDN záznamu (bez doplňování zóny, `@` ani krátký label neprojdou).
   - `revert`: modul si z `GET log` CA dohledá položku (prvních 1000, jinak `404 log_not_found`), odvodí operaci stejně jako CA (before null = smazat, after null = znovu vytvořit,
     jinak update) a použije stejná pravidla; vrácení vytvoření MX / záznamu webu tedy vyžaduje `allow_critical_delete` a přepsání názvu. `GET log` přidává řádkům
     `revert_risk`, `revert_name`, `revert_blocked` (SPA podle nich ukáže varování, název k přepsání a zablokuje tlačítko).
6. Pokud CA odmítne zápis kvůli `reauth_required` (posun času), modul to vrátí jako `403 reauth_required`, zapomene lokální potvrzení a SPA si vyžádá heslo znovu.
7. Konflikt (záznam mezitím změněn v Cloudflare) vrací `409 conflict` s textem z CA, SPA nabídne načtení záznamů znovu.

## API (`administrator/index.php?option=com_uxstudio&task=api.dispatch&route=dns-manager/...`)

| Routa | Účel |
|---|---|
| `GET status` | stav spojení, `zone`, práva uživatele, `acknowledged`, `reauth_left` (s) |
| `GET records[?fresh=1]` | záznamy zóny + `risk[]`, `critical`, `delete_blocked`, zámky z CA |
| `GET log` | historie z CA (`source: central`), jinak lokální kopie (`source: local`) |
| `GET propagation?type&name&content&priority&proxied&absent` | odpovědi veřejných resolverů (DNS over HTTPS: Cloudflare, Google); název musí být v zóně |
| `POST ack`, `POST reauth {password}` | potvrzení rizik / hesla |
| `POST records {record, confirm}` | přidat |
| `POST records/{id} {record, modified_on, confirm, confirm_name?}` | změnit (optimistický zámek `modified_on`) |
| `POST records/{id}/delete {modified_on?, confirm, confirm_name?}` | smazat (DELETE jádro API nezná, proto POST) |
| `GET config`, `POST config {manage_user_ids, allow_critical_delete, password}` | bezpečnostní přepínače, jen `core.admin` |
| `POST revert {log_id, confirm, confirm_name?}` | vrátit změnu z logu CA |

## Kontrola propagace

Dotaz na veřejné resolvery přes JSON DoH (pevné hosty, SSRF guard jádra). Porovná se očekávaná hodnota (TXT bez uvozovek, MX jako
`priorita host`, hostnames bez tečky); u proxovaných záznamů se jen ukáže odpověď, u smazaného záznamu se čeká prázdná odpověď (`absent=1`).
SPA ji nabízí v řádku záznamu a po každé uložené změně.

## Audit log

`#__uxstudio_dns_log`: čas (UTC), uživatel, akce (`create|update|delete|revert|acknowledged|reauth`), stav (`success|error|conflict|denied`),
záznam před/po (JSON), chyba. IP adresa se do lokálního logu neukládá (CA ji drží v autoritativním logu a posílá e-maily správcům).
Dashboard widget ukazuje počet změn za 30 dní.

## Ověřeno (J5 5.4.10 i J6 6.2.0, `compat6` vypnutý)

- Testovací prostředí: CA `_ca-wt-joomla` + `tools/fake-cloudflare.php` (`CF_DNS_API_BASE` v `.env` CA, zóna `localhost` pro oba weby, po testu vráceno).
- Přes `jadmin`: `status` v každém stavu (aktivní, `not_configured`, `connector_off`), seznam, přidání, změna, `409 conflict` při starém
  `modified_on`, smazání, `revert` (včetně smazaného MX), `403 ack_required / reauth_required / critical_protected`, `422 name_required / confirm_required /
  invalid_record`, špatné i správné heslo, lokální log, propagace (DoH odpovídá).
- Prohlížeč (Playwright, J5 i J6): přehled, formulář, potvrzovací dialog s heslem a diffem, uložení, propagace, historie s Revert, mazání MX zakázané; bez chyb v konzoli.
- `php -l`, `npm run typecheck`, `npm run build:spa`, překlady cs + en (41 klíčů v ini, všechny řetězce SPA v `cs.json`), Apache error log bez chyb modulu.

## Odchylky od WP verze

- Čerstvé přihlášení je nahrazeno potvrzením hesla v dialogu (Joomla nemá čas přihlášení session jako WP tokeny).
- Navíc oproti WP: pojistka kritických záznamů na serveru (`allow_critical_delete`), kontrola propagace, lokální log odmítnutých akcí.
- `CaClient` zahazuje tělo chybových odpovědí CA (`results`), do SPA jde jen text `error`; pro `dns_manage` to stačí.
