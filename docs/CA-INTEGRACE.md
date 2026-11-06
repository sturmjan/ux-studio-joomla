# Napojení na Centrální aplikaci (CA) - kontrakt protokolu

Cíl: Joomla weby s UX Studiem spravovat v CA stejně jako WordPress weby. CA repo:
`D:\xampp\htdocs\centrani-app` (main = produkce), práce na Joomle probíhá ve worktree
`D:\xampp\htdocs\_ca-wt-joomla`, větev `feature/joomla-sites` (vychází z origin/main e9ace87).

Na straně WordPressu protokol implementuje `_uxs-wt-csp/includes/Modules/ContentSync`
(`NodeController.php`, `NodeHealth.php`, `HmacAuth.php`, `Sso*`) a
`includes/Core/Onboarding/CentralApp.php` (párování). Joomla verze ho kopíruje, aby CA
mohla použít stejný transport a lišila se jen adaptérem.

## Identifikace v CA
- `sites.type` = `joomla` (nová hodnota ENUMu vedle `wordpress`, `generic`)
- `sites.protocol` = `uxjoomla` (nová hodnota vedle `legacy`, `uxstudio`)

## Endpoint na Joomla webu (modul `ca-connector`)
- URL: `{site}/index.php?uxstudio_node=<route>` (query `<route>` např. `ping`, `updates`).
  Zachytí ho modul v `onAfterInitialise` dřív, než Joomla routuje, takže funguje se SEF i bez něj.
  Další query parametry se předávají dál (`since=` apod.).
- Odpověď JSON, HTTP kódy jako WP verze (401 špatný podpis, 404 neznámá routa).

## Podpis (stejný jako WP protokol `uxstudio`)
- Sdílený klíč `node_api_key` (64 hex), na webu uložený šifrovaně v nastavení modulu.
- Hlavičky `X-Content-Sync-Signature`, `X-Content-Sync-Timestamp`, `X-Content-Sync-Nonce`, `X-Content-Sync-Version`.
- Podpis `hex(hmac_sha256("METHOD\nURL\nTS\nNONCE\nsha256hex(body)", key))`, URL = přesná absolutní URL požadavku vč. query.
- Okno 300 s, nonce jednorázové (tabulka na webu), rate limit neúspěšných pokusů.
- Web → CA (security_api, ticket_api, turnstile_provision, dns_manage...) podepisuje stejně,
  identifikuje se `site_url` (vzor `CA HmacAuth::sign` s nonce).

## Párování
Stejné jako WP: tlačítko „Připojit k Centrální aplikaci“ v UX Studiu odešle z prohlížeče POST na
`{CA}/?page=connect_site` s `site_url, site_name, api_key, timestamp, site_type=joomla, return_url, signature`
(`hmac_sha256(site_url|site_name|timestamp|site_type, api_key)`). CA zavolá podepsaný `ping`
(challenge), po potvrzení web uloží. Návrat na `return_url` = stránka modulu v administraci.

## Routy (MVP)
| Routa | Metoda | Obsah odpovědi |
|---|---|---|
| `ping` | GET | `{site, cms:"joomla", cms_version, php_version, connector:"uxjoomla", connector_version, enabled_modules:[], capabilities:[]}` |
| `updates` | GET | `{core:{current,latest}, extensions:[{type,element,name,current,latest}]}` z `#__updates` (po `Updater::findUpdates`) |
| `php-errors` | GET | poslední chyby z PHP/Joomla logu (stejný tvar jako WP) |
| `email-health` | GET | z modulu email-health, pokud je zapnutý |
| `security-monitor` | GET | stav bezpečnostních hlaviček/loginu z modulu security |
| `vulnerabilities` | GET | inventář software + nálezy z modulu vulnerability-scanner |
| `email-log` | GET | z modulu email-log |
| `ip-bans`, `ip-bans/sync` | GET/POST | globální bany z CA |
| `bot-throttle/bans?since=` | GET | lokální bany pro CA |
| `sso/issue`, `sso/revoke-all` | POST | jednorázový přihlašovací token → `login_url` = `{site}/administrator/index.php?uxstudio_sso=TOKEN` |

**Závazné tvary odpovědí (doplněno po implementaci CA 2026-11-06):** CA je hotová na větvi
`feature/joomla-sites` a normalizuje odpovědi přesně v tvaru referenčního stubu
`D:\xampp\htdocs\_scratch-jconn\index.php` (jen ke čtení, testovací klíč v něm nepoužívat).
Konektor musí vracet stejné klíče: `ping` (CA ho přijme jen s `connector:"uxjoomla"` a `cms:"joomla"`,
jinak párování neprojde), `updates`, `php-errors {total_count,type_counts,errors[]}`,
`vulnerabilities {available,vuln_count,severity_level,inventory[{type,slug,name,version,active}],vulnerabilities[]}`,
`email-log {items,total}`, `ip-bans`, `ip-bans/sync` (POST `{bans:[]}`), `bot-throttle/bans {items[{id,ip_hash,reason,banned_at}],last_id}`,
`sso/issue` (POST tělo `{operator_email, target_role, target_wp_role, cms}`, čti `target_role`; odpověď
`{success,token,expires_in,login_url}`, `login_url` MUSÍ být na stejném schématu/hostu/portu jako web a cesta
pod `/administrator/`, jinak ho CA odmítne), `sso/revoke-all {success,revoked}`.
Párovací formulář může nést volitelné `site_id` (pak se podepisuje `site_url|site_name|timestamp|site_type|site_id`).

**Podpis odpovědi:** konektor ke každé odpovědi přidá hlavičku `X-Content-Sync-Response-Signature`
= `hex(hmac_sha256(nonce + "|" + sha256hex(tělo odpovědi), key))`. CA ji zatím neověřuje (úkol v CA PLAN §39.3b),
ale konektor ji posílá od začátku.

`capabilities` řídí, co CA u webu nabídne (`updates, php_errors, email_health, security_monitor,
vulnerabilities, email_log, ip_bans, bot_bans, sso`). Obsahové funkce CA (články, média, AI přepisy)
jsou v MVP mimo, Joomla je neohlásí.

## SSO
- Role v CA pro Joomla: skupiny `Super Users`, `Administrator`, `Manager`, `Editor` (mapování na ID skupin na webu).
- Token jednorázový, platnost 60 s, váže se na operátora z CA; uplatní ho `onAfterInitialise` v administraci,
  přihlásí (případně založí) uživatele a přesměruje na `/administrator/`.

## Strana CA (adaptér)
Viz průzkum v ARCHITECTURE kontextu: `core/ApiClient.php` (base URL podle protokolu), `models/Site.php`
(`supports()` místo `isWordPress()`, továrna `getClient()`, `cmsAdminUrl()`), `ConnectController`
(`site_type=joomla`, challenge přes `uxjoomla`), `SiteHealthController`/`VulnerabilityController`
(normalizace), `SsoConfig` (role podle CMS), `views/sites/*`, migrace ENUMů.
