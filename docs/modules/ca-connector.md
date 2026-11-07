# Modul ca-connector (konektor Centrální aplikace)

Strana webu protokolu Centrální aplikace (CA), viz `docs/CA-INTEGRACE.md`. Vzor ve WP verzi:
`includes/Modules/ContentSync/{NodeController,NodeHealth,HmacAuth,Sso*}.php` a `includes/Core/Onboarding/CentralApp.php`.

Soubory: `packages/lib_uxstudio/src/Modules/CaConnector/` (`Module`, `Node`, `Hmac`, `Pairing`, `Sso`, `Health`, `Store`, `CaClient`),
`admin-app/src/modules/ca-connector/` (`Page.tsx`, i18n cs/en). Migrace verze 1 (tabulky `#__uxstudio_caconn_kv|nonce|sso|log`).

## Co modul dělá

1. **Párování.** Tlačítko "Připojit k Centrální aplikaci" (`POST ca-connector/prepare`): uloží adresu CA, vygeneruje 64hex klíč
   (nastavení `node_api_key`, typ password = šifrovaně), stálé ID webu (UUID, `site_id`) a vrátí pole formuláře. SPA je odešle
   z prohlížeče metodou POST na `{CA}/?page=connect_site` (`site_type=joomla`, `return_url` = stránka modulu v administraci,
   podpis `site_url|site_name|timestamp|site_type|site_id`). CA zavolá podepsaný `ping` (živá výzva), po potvrzení vrátí prohlížeč
   na `return_url` s `ux1_connected=1` a SPA zavolá `POST ca-connector/connected`. Web se za spárovaný považuje také po prvním podepsaném
   volání jiné routy než `ping`. Odpojení (`disconnect`) smaže klíč a zneplatní SSO odkazy.
2. **Koncový bod** `index.php?uxstudio_node=<routa>` (`Node.php`): obsluhuje se v `boot()` modulu, tedy v `onAfterInitialise` před
   routováním Joomly (funguje se SEF i bez; stránková cache ho nevidí). Ověření: hlavičky `X-Content-Sync-Signature|Timestamp|Nonce`,
   podpis `hmac_sha256("METHOD\nURL\nTS\nNONCE\nsha256hex(body)")` nad přesnou URL požadavku (schéma podle HTTPS / `X-Forwarded-Proto` /
   `CF-Visitor`, host `HTTP_HOST`, `REQUEST_URI`), okno 300 s, nonce jednorázové (tabulka `caconn_nonce`), neúspěšné pokusy se počítají
   na IP (60 za minutu, pak 429; platný podpis se nikdy neomezuje), volitelný seznam povolených IP. Každá odpověď na ověřený požadavek nese
   `X-Content-Sync-Response-Signature = hmac_sha256(nonce|sha256hex(tělo))`. Odpovědi jsou `Cache-Control: no-store`.
3. **Routy:** `ping`, `updates`, `php-errors`, `sso/issue`, `sso/revoke-all` jsou vlastní. `email-health`, `email-log`,
   `security-monitor`, `ip-bans`, `ip-bans/sync`, `vulnerabilities`, `bot-throttle/bans` se předají metodě
   `caFeed(string $route, array $query, array $body): array` modulů `email-health`, `email-log`, `security-optimization`,
   `vulnerability-scanner`, `bot-throttle`. Když modul není zapnutý, routa vrátí 404 (podepsané) a `ping` capability neohlásí
   (`capabilities`: `updates, php_errors` vždy, `sso` pokud `sso_mapping_strategy` není `disabled`; `email_health, email_log, security_monitor + ip_bans, vulnerabilities, bot_bans` podle modulů).
   `updates` čte `#__updates` + `#__extensions` (verze z `manifest_cache`), jádro jako `{current, latest}`; čerstvá data zajišťuje úloha
   plánovače `ca-connector.refresh-updates` (12 h, `Updater::findUpdates`). `php-errors` čte konec `ini error_log` a `administrator/logs/error.php`
   (jen ERROR a vyšší), vrací tvar WP routy (`total_count`, `type_counts`, `errors[]`).
4. **SSO** (`Sso.php`): `sso/issue` namapuje operátora (e-mail) na uživatele Joomly, uloží jednorázový token (jen SHA-256, platnost 60 s,
   nastavitelná) a vrátí `login_url = {web}/administrator/index.php?uxstudio_sso=TOKEN`. V administraci `boot()` token uplatní: kontrola
   formátu, limit 5 neúspěchů/min/IP, platnost, jednorázovost (atomicky), shoda User-Agent (volitelně IP), uživatel neblokovaný, právo
   `core.login.admin`; přihlášení proběhne standardním řetězcem pluginů `user` (`onUserLogin`, `onUserAfterLogin`, fork session) a přesměruje
   do `/administrator/` (jen cesta pod `/administrator/`). Role z `target_role` (`Super Users|Administrator|Manager|Editor`) se hledá podle
   názvu skupiny (záloha ID 8/7/6/4). Existující uživatel se hledá podle e-mailu a přihlásí se se SVÝMI právy; neexistující se založí
   (`central_<místní část>`) až při uplatnění odkazu (ne při `sso/issue`), podle `sso_mapping_strategy` (`auto` zakládá, `strict` jen existující,
   `disabled` => `sso/issue` vrací 403 `sso_disabled` a `ping` capability `sso` neohlásí) ve skupině `sso_provision_role` (**Manager/Editor**, nikdy
   administrátorská, role z CA se ignoruje). Už `sso/issue` ověří, že cílový účet / skupina má `core.login.admin`, jinak vrátí 422 `no_admin_access`
   (žádný mrtvý účet). Přihlášení do účtu s právy správce (core.admin, com_config, instalátor, uživatelé, pluginy, šablony) je **opt-in** (`sso_allow_admin`, výchozí vypnuto, stejně jako ve WP). SSO relace přeskočí MFA captive
   stránku Joomly (druhým faktorem je přihlášení v CA). Vše se zapisuje do auditního logu (záložka Aktivita, retence 90 dní).
5. **CaClient** (web -> CA): `CaClient::fromContext($ctx): ?self`, `get(page, query)`, `post(page, query, body)`; podepisuje stejně jako opačný směr
   (hlavičky `X-Content-Sync-*`, `site_url` v query, `X-UXStudio-Site-Id`), při chybě HTTP/ne-JSON hází `\RuntimeException`.
   `null` = konektor vypnutý nebo web není spárovaný. SSRF guard jádra se zvedne jen pro hostitele nastavené CA, a jen když je loopback/privátní
   (lokální vývoj); veřejná CA jde přes běžný guard.

## Nastavení

Připojení: adresa CA (prázdné = `https://app.ux1.cz/`; http jen pro localhost), sdílený klíč (generuje se při párování), povolené IP.
SSO: strategie mapování (auto/strict/disabled), skupina pro nově založené účty, povolit admin účty, shoda prohlížeče/IP, platnost odkazu (10-300 s), limit odkazů na operátora a hodinu.
Modul vyžaduje `core.admin`.

## Rozdíly proti WP verzi

- Párování se potvrdí, až dorazí podepsaný požadavek z CA po `prepare` (samotný návrat `ux1_connected=1` nestačí; do té doby stav "čeká na potvrzení").
- `site_url` se pamatuje z webového požadavku (`caconn_kv.site_url`); v CLI (cron) s prázdným `live_site` Joomla jinak vymyslí `joomla.invalid`.
- Neúspěšná ověření se logují max. 3x/5 min/IP, po omezení vůbec (anonym nenaplní tabulku).
- Endpoint je `?uxstudio_node=<routa>` místo REST namespace; podpis odpovědi navíc (CA ho zatím neověřuje).
- Role: skupiny Joomly místo rolí WP; Editor ve výchozím nastavení Joomly nemá `core.login.admin`, takže SSO jako Editor skončí `no_admin_access`.
- Bez synchronizace operátorů (`sso/sync-operators`) a cache `operator_id` -> uživatel (mapuje se jen podle e-mailu, `operator_id` jde do tokenu a logu), bez `sso_max_failed_redemptions` (pevně 5/min/IP), bez obsahových rout, balíčků, cron-control, pagespeed, guide, service-requests.
- `php-errors` nečte Apache log (na sdíleném hostingu není dostupný), jen PHP `error_log` a log Joomly.

## Co chybí / upozornění

- Seznam povolených IP, limit neúspěšných ověření i limit uplatnění SSO používají jen `REMOTE_ADDR`. Za reverzní proxy / Cloudflare je to adresa proxy: allowlist tam nepoužívejte a limity sdílí všichni za proxy (jádro zatím nemá helper důvěryhodné klientské IP, viz core_requests).
- `allow_private` v core `Http` platí i pro přesměrování: CA na privátní adrese (jen lokální vývoj) by mohla klienta přesměrovat na jinou interní adresu. Veřejná CA jde přes plný guard. Potřebuje změnu jádra (core_requests).
- Odeslání párovacího formuláře blokuje CSP `form-action 'self'`, pokud je v administraci vynucená (modul security-optimization): do `form-action` je třeba přidat adresu CA.
- UI (`Page.tsx`) prošlo typecheckem a buildem, ale ne ručním proklikáním v prohlížeči (Playwright MCP nebyl k dispozici); API tras stránky jsou ověřeny přes `jadmin`.
- CA: výchozí uložená matice rolí SSO nemá klíč `joomla_role`, takže je nutné ho uložit v Nastavení - Přímé přihlášení (CA záměrně selže uzavřeně).
- `return_to_whitelist` v CA je jen `/wp-admin/` (CA ho nevynucuje, konektor přijímá jen `/administrator/...`).
