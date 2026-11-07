# Modul security-optimization (Optimalizace zabezpečení)

WP předloha: `includes/Modules/SecurityOptimization` (+ `Core/Security.php`). Joomla verze je psaná nativně, přebírá logiku
a UX firewallu, bezpečnostních hlaviček, CSP, ochrany přihlášení a monitoru pro Centrální aplikaci.

Soubory: `packages/lib_uxstudio/src/Modules/SecurityOptimization/` (`Module.php`, `ClientIp.php`, `IpBans.php`, `LoginGuard.php`,
`CspPolicy.php`, `Hardening.php`, `language/`), SPA `admin-app/src/modules/security-optimization/` (`Page.tsx`, `i18n/`).

## Co modul dělá

| Funkce | Popis |
|---|---|
| Bezpečnostní hlavičky | `X-Content-Type-Options`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy` (výchozí zapnuto); volitelně HSTS (jen přes HTTPS) a `Permissions-Policy`. Nastavuje se v `onAfterRender` s nejnižší prioritou, takže přepíše hlavičky pluginu "HTTP Headers". |
| Skrytí verze | Prázdný generator (`<meta name="generator">`) a `header_remove('X-Powered-By')`. Statické soubory (`administrator/manifests/files/joomla.xml`, `README.txt`, `htaccess.txt` ...) obsluhuje web server, ne PHP: modul je jen **hlásí** a umí otestovat HTTP HEAD, co se opravdu vydává. |
| CSP | Režimy vypnuto / jen hlásit (`Content-Security-Policy-Report-Only`) / vynucovat. Úroveň základní (inline + eval) nebo přísná (bez eval, skripty jen s nonce). Joomla nonce sama přidává ke skriptům a stylům, které vykresluje (`$app->get('csp_nonce')` + `HtmlDocument::$cspNonce`); modul si nonce vygeneruje, pokud ho nevytvořil plugin HTTP Headers. Předvolby služeb (GA, GTM, Fonts, Maps, reCAPTCHA, Turnstile, YouTube, Vimeo, Meta Pixel, Hotjar, Stripe, PayPal, Mapy.cz ...), vlastní povolené zdroje a další direktivy; každý token se validuje, takže překlep nikdy nerozbije hlavičku. CSP se nikdy neposílá v administraci. Hlášení porušení (`report-uri`) sbírá veřejná routa `security-optimization/csp-report` do agregované tabulky (strop 2000 řádků, limit 120 hlášení/min na IP). |
| IP firewall | Bany jedné IP nebo CIDR (IPv4 i IPv6) v tabulkách modulu; při shodě 403 (web, administrace i Web Services `/api/` přes `onAfterApiRoute`). Rychlá cesta: příznak `cache/uxs-secopt-bans.flag`, bez banů se nedělá žádný dotaz. Loopback, povolené adresy a přihlášený super uživatel (`core.admin`) se nikdy neblokují, vlastní IP nelze zabanovat. Bany z CA (`ip-bans/sync`) se vynucují stejně. |
| Klientská IP | `ClientIp::resolve()`: hlavičky `CF-Connecting-IP` / `X-Forwarded-For` se berou **jen když spojení přišlo z Cloudflare (vestavěné rozsahy) nebo z uvedené důvěryhodné proxy**; XFF se prochází zprava a přeskakuje důvěryhodné hopy, takže hodnota podstrčená klientem se nepoužije. IPv4 mapované v IPv6 se normalizuje. |
| Ochrana přihlášení | Počítání neúspěšných přihlášení (administrace i `com_users`), zámek IP na X minut, volitelně postupně prodlužovaný (násobek, strop v hodinách), po N zámcích do 24 h automatický ban IP (rozsah času nastavitelný), e-mail administrátorovi (max 1 / 5 min). Zámek se kontroluje před autentizací (`onAfterRoute`, task se normalizuje na malá písmena a bere se část za tečkou, takže `USER.LOGIN` neobejde) **a znovu v `onUserLogin`** (přihlášení z uzamčené IP se zamítne i se správným heslem, nezávisle na tvaru požadavku; cookie přihlášení se nepočítá). Počítá se přes `onUserLoginFailure`, resetuje přes `onUserAfterLogin`. Loopback a povolené adresy se neuzamykají. Auto-ban se nikdy nevytvoří pro adresu z rozsahů Cloudflare ani z důvěryhodných proxy. Log 30 dní, záznam `secret` max. 1 za IP / 10 min. |
| Turnstile | Volitelně na přihlášení do `/administrator`: skript přes WebAssetManager (nonce), widget vložen před tlačítko Přihlásit, ověření server-side proti `siteverify`. Widget se vkládá před tlačítko (Atum), jinak na konec formuláře `#form-login`; **když se do stránky vložit nepodaří, token se nevyžaduje** (příznak v `cache/uxs-secopt-turnstile-widget.flag`, varování do logu `uxstudio`), takže cizí šablona správce nezamkne. Selže-li spojení na Cloudflare, přihlášení zůstane otevřené; odmítnutý token blokuje. Secret key je šifrované pole. |
| Tajná adresa administrace | Volitelně: nepřihlášení dostanou 404 (nebo přesměrování na web), dokud neotevřou `/administrator/index.php?<tajný_parametr>`; příznak se uloží do session. Neplatný klíč bránu vypne (fail-open). Výjimka pro `?uxstudio_sso=` platí jen když je zapnutý modul ca-connector a hodnota má tvar tokenu (64 hex); jinak se parametr ignoruje. |
| Zjišťování uživatelů | Volitelně 404 pro nepřihlášené u parametrů `author`, `author_id`, `created_by`, `filter[author_id]` a `com_users&view=profile&user_id=`. |
| Přehled zabezpečení | `hardening` route a `security-monitor` pro CA: debug, error_reporting, zapisovatelný `configuration.php`, složka `installation`, uniklé soubory, `force_ssl`, verze PHP, počet super uživatelů, super uživatelé bez MFA (`#__user_mfa`), uživatel `admin`, veřejná registrace + stav vlastních ochran. Doporučená pravidla `.htaccess` se jen **zobrazí**, nic se nezapisuje. |

## Nastavení

Sekce Firewall, Hlavičky a úniky, CSP, Ochrana přihlášení (klíče v `settingsSchema()`; texty `UXS_SECURITY_OPTIMIZATION_*` cs + en).
Výchozí zdroj IP je **Za Cloudflare** (hlavičce se věří jen z oficiálních rozsahů CF, jinak `REMOTE_ADDR`, takže je volba bezpečná i bez Cloudflare). Výchozí hodnoty: firewall zapnutý, hlavičky zapnuté, generator skrytý, omezení přihlášení zapnuté (5 pokusů, 15 min, ban po 3 zámcích na 24 h),
CSP vypnuté, Turnstile a tajná adresa vypnuté.

**Záchrana při zamknutí:** `define('UXSTUDIO_DISABLE_LOGIN_PROTECTION', true);` v `administrator/defines.php` vypne zámky, Turnstile i tajnou adresu; nebo
`UPDATE #__uxstudio_modules SET enabled = 0 WHERE id = 'security-optimization'`. Zámek konkrétní IP jde také zrušit v záložce Přihlášení.

## Tabulky (migrace v1)

`#__uxstudio_secopt_ip_bans`, `#__uxstudio_secopt_ip_ranges` (binární rozsahy start/end pro rychlé porovnání), `#__uxstudio_secopt_lockouts`,
`#__uxstudio_secopt_login_log` (retence 30 dní), `#__uxstudio_secopt_csp` (retence 90 dní). Úloha plánovače `housekeeping` (6 h) maže nečinné zámky a bany
prošlé před více než týdnem.

## API (administrace, CSRF + ACL)

`GET overview`, `GET ip-bans` (`search`, `origin`, `page`), `POST ip-bans` (core.admin), `POST ip-bans/{id}/toggle|delete` (core.admin), `GET login-log`,
`POST login/unlock` (core.admin), `GET csp`, `POST csp/clear` (core.admin), `GET hardening`, `GET hardening/probe`. Celý modul vyžaduje `core.admin` (`acl()`), takže nastavení, log s IP a tajnou adresu nevidí správce jen s `core.manage`.
Veřejná routa: `POST csp-report` (bez přihlášení, kontrola Origin, rate limit).

## Napojení na CA (`caFeed`)

`caFeed(string $route, array $query, array $body)` obsluhuje `security-monitor`, `ip-bans` (GET) a `ip-bans/sync` (POST `{bans:[]}`).

* `security-monitor`: stejné klíče jako WP (`available`, `score` = počet incidentů (zatím vždy 0, Joomla nemá zdroj incidentů), `posture_problems` = počet nálezů v `checks`, `cron`, `files`, `integrity`, `new_users`, `admin_security`,
  `hardening.risk_count`, `checked_at`) + `checks[]`, `login`, `firewall`, `csp`. Sekce, které modul nezná (cron watcher, upload guard, integrita), mají `available:false`.
* `ip-bans`: `{bans, local, central, local_count, central_count, firewall_active}`.
* `ip-bans/sync`: nahradí **všechny** bany původu `central` (místní se nedotknou), země přicházejí s předpočtenými CIDR `ranges`; položky pokrývající IP volajícího se
  přeskočí. Celé v jedné transakci, rozsahy se vkládají hromadně (1500 rozsahů cca 0,4 s); rozsahy nad limit 5000 na ban se ořežou a vrátí v `truncated` (a zapíšou do logu). Odpověď `{success, synced, skipped, truncated, central}`.
* `admin_security.available = false` (chybí data o nových adminech a eskalacích, dá se doplnit z action logu Joomly). `hardening.disallow_file_edit = true` a `xmlrpc_enabled = false` jsou záměrně konstanty (v Joomle XML-RPC neexistuje a WP doporučení o `DISALLOW_FILE_EDIT` se nemá zobrazovat).

## Rozdíly proti WP verzi / co chybí

* Není Upload Guard (skener souborů), kontrola integrity, 2FA vlastní implementace (Joomla má MFA v jádru, modul jen hlásí uživatele bez něj), blokování zemí
  stahováním rozsahů (země jdou jen z CA jako hotové rozsahy), vlastní CAPTCHA kromě Turnstile, přepisování `.htaccess` (Joomla se jen hlásí, nic se nezapisuje),
  režim "Inventář" CSP (nahrazuje ho report-only s tabulkou hlášení), automatický návrat CSP z Enforce na report-only.
* Joomla nemá WP-ovské `?author=N`, `xmlrpc.php` ani REST seznam uživatelů; blokování enumerace proto pokrývá jen parametry com_content / com_users uvedené výše.
  Web Services API (`/api/`) vyžaduje token; modul na něj uplatňuje jen IP firewall.
* Chybí také: Turnstile auto-provisioning přes CA (TurnstileCaProvisioner / SelfProvisioner; čeká na `CaClient::post` a rozhodnutí o další fázi), CSP nástroje (inventura inline skriptů, CspRisk, RDAP, rerate, dashboard widget CSP), CAPTCHA na veřejných formulářích (CaptchaForms), upozornění na verzi PHP a e-maily o aktualizacích.
* Bezpečnostní hlavičky: základní sada (nosniff, XFO, Referrer-Policy, HSTS) jde i na přesměrování, 403/404 stránky a 429/503 z bot-throttle; plná sada včetně CSP a Permissions-Policy jen na vykreslené stránky.
* Přihlašovací lock-out počítá podle IP, ne podle uživatelského jména.
* Klientská IP v režimu `none` je vždy `REMOTE_ADDR`. Za jinou reverzní proxy než Cloudflare je třeba zvolit `xff` a vyplnit důvěryhodné proxy, jinak se zámky aplikují na IP proxy. `overview.edge_warning` upozorní, když se klient přeložil na adresu Cloudflare / důvěryhodné proxy.
* Hlavička `Server: Apache/...` je věc konfigurace serveru (`ServerTokens Prod`), modul ji nemění.

## Ověřeno (J5 5.4.10 i J6 6.2.0)

Viz závěrečná zpráva agenta; shrnutí: migrace, firewall (single, CIDR, IPv6, XFF spoofing, central sync, země), lock-out + progresivní prodloužení + auto-ban, úspěšné přihlášení,
tajná adresa, Turnstile (testovací klíče Cloudflare, doopravdy volané `siteverify`), CSP report-only + strict nonce + sběr hlášení, `security-monitor`, hardening/probe,
dashboard widget, úloha plánovače, cs/en klíče.
