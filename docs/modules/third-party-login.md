# Modul third-party-login (Přihlášení přes třetí strany)

Přihlášení do administrace i na web přes **Google, Microsoft, Facebook, Seznam** a obecného poskytovatele **OpenID Connect**
(Keycloak, Authentik, Azure B2C...). OAuth 2.0 authorization code flow s **PKCE (S256)**, `state` a `nonce`. Modul má vlastní
přihlašovací endpoint, nejde přes Centrální aplikaci (WP verze používala CA jako OAuth proxy s HMAC; tady má web vlastní
`client_id`/`client_secret` u každého poskytovatele).

- **Kód:** `packages/lib_uxstudio/src/Modules/ThirdPartyLogin` (`Module.php` nastavení/tlačítka/migrace, `Flow.php` start + callback + přihlášení,
  `Providers.php` katalog poskytovatelů a čtení identity, `Store.php` tabulky, `Api.php` routy pro administraci), CSS tlačítek
  `packages/lib_uxstudio/media/modules/third-party-login/login.css`, obrazovka `admin-app/src/modules/third-party-login/Page.tsx`.
- **ACL modulu:** `core.admin` (nastavení klíčů poskytovatelů a správa propojení).

## Tok přihlášení

1. Tlačítko na formuláři je obyčejný odkaz `.../index.php?uxs_tpl=start&provider=<id>` (administrace: `administrator/index.php?...`).
   Žádný JavaScript ani inline styl, takže strict CSP nevadí; ikony jsou inline SVG s presentation atributy, styl je v externím `login.css`
   (`<link>` se vloží jen na stránku, kde se tlačítka opravdu vykreslí).
2. `start` (běží v `onAfterInitialise`, před routováním): limit 30 startů / 10 min na IP (`RateLimiter::hitClient`, kontrola jako první, nad limit se nic nelogguje), vygeneruje `state`, `nonce`
   a PKCE `code_verifier`, uloží je **do session aplikace, která flow zahájila**, a přesměruje na poskytovatele s `code_challenge` (S256).
   Do session se vejde max. 5 souběžných flow (karty prohlížeče), platnost 10 minut, `state` je jednorázový.
3. Poskytovatel vrací na **přesměrovací URI**, která je path-based (bez query stringu, kvůli omezením Microsoftu):
   - web: `https://example.com/index.php/uxs-oauth/callback`
   - administrace: `https://example.com/administrator/index.php/uxs-oauth/callback`
   Obě se zobrazují (s tlačítkem Kopírovat) na záložce Poskytovatelé. Administrace a web mají oddělené session, proto každá aplikace
   zpracuje svůj callback sama a přihlásí uživatele do své session.
4. `callback`: ověří `state` proti session (login CSRF: callback z cizího prohlížeče nemá session ani state), vymění `code` za tokeny
   serverem (`client_secret` + `code_verifier` jdou jen přes TLS na token endpoint), přečte identitu a zvaliduje `id_token`
   (aud, iss, exp, `nonce`). Podpis `id_token` se neověřuje, protože přichází přímo z token endpointu přes TLS (OIDC Core 3.1.3.7).
5. Přihlášení proběhne standardním řetězcem uživatelských pluginů (`onUserLogin` + `onUserAfterLogin`, stejně jako `CMSApplication::login()`),
   takže platí zámky z modulů security-optimization/bot-throttle, `core.login.admin`/`core.login.site`, blokace účtu. **Vícefázové
   ověření Joomly se nepřeskakuje**: uživatel s MFA ho po poskytovateli ještě projde.

## Párování účtů a zakládání

Pořadí: (1) propojená identita `(provider, sub)` v `#__uxstudio_tpl_identities`; (2) uživatel se stejným e-mailem, **jen když poskytovatel
e-mail potvrdil** (a je zapnuté "Párovat podle e-mailu"); při shodě se identita uloží; (3) volitelné založení účtu (**výchozí vypnuto**).

| Poskytovatel | Subject | Co se bere jako ověřený e-mail |
|---|---|---|
| Google | `sub` z id_tokenu | `email_verified: true` |
| Microsoft | `sub` z id_tokenu | osobní účty (tenant `9188040d-...`) a přihlášení omezené na jeden tenant; u `common`/`organizations` pracovní účty NE (nOAuth); `xms_edov: false` ruší |
| Facebook | `id` z Graph `/me` (s `appsecret_proof`) | e-mail jen při zapnutém přepínači "Důvěřovat e-mailu z Facebooku" (výchozí vypnuto, Graph ověření adresy nezaručuje) |
| Seznam | `oauth_user_id` a `account_name` z odpovědi token endpointu (doplněno z `/api/v1/user`: `username` + `domain`) | adresa účtu na doméně Seznamu (seznam.cz, email.cz, post.cz, spoluzaci.cz, stream.cz, firmy.cz); volný `contact-email` ne |
| OIDC | `sub` | `email_verified: true`, nebo přepínač "Věřit e-mailové adrese" |

Další pojistky: více účtů se stejným e-mailem = odmítnutí (`ambiguous`); identita už patřící jinému uživateli = odmítnutí (`taken`);
zablokovaný/neaktivovaný účet = odmítnutí; **administrátorské účty** (super user, správa uživatelů/rozšíření/pluginů/šablon/konfigurace)
se tímto způsobem nepřihlásí, dokud správce nezapne "Povolit administrátory a super uživatele"; omezení na **e-mailové domény**
(prázdné = bez omezení) platí pro každé přihlášení.

Zakládání účtu: jen když je zapnuto "Zakládat účty automaticky"; skupina musí existovat, nesmí mít administrátorská práva
(`core.admin`, `core.manage` com_installer/com_users/com_plugins/com_templates ...) a musí mít právo přihlásit se do aplikace, ze které
návštěvník přišel (jinak by zůstal osiřelý účet). Heslo je náhodné (nikdo ho nezná, přihlašuje poskytovatel).

## Nastavení

Záložky: Obecné (přihlášení do administrace / na web, párování, administrátoři, domény, zakládání účtů), Google, Microsoft (tenant),
Facebook, Seznam, OpenID Connect (popisek, authorize/token/userinfo URL, issuer, důvěra e-mailu). `client_secret` je typ `password`
(šifrovaně, do SPA se nevrací). Poskytovatel se zobrazí, když je zapnutý a má `client_id` + `client_secret`. Endpointy vlastního OIDC smí
být v privátní síti (nastavuje je jen `core.admin`, `Http` s `allow_private`), ostatní poskytovatelé jen https a s SSRF guardem.

## Data a API

- `#__uxstudio_tpl_identities` (`user_id`, `provider`, `subject` utf8mb4_bin, `email`, `created`, `last_login`; unikát `(provider, subject)`);
  mazání uživatele (`onUserAfterDelete`) smaže jeho propojení.
- `#__uxstudio_tpl_log` (přihlášení, odmítnutí s důvodem, založení, propojení; IP), retence 90 dní.
- Routy (core.admin + CSRF): `GET status` (poskytovatelé, redirect URI, moje propojení), `GET identities`, `GET log`,
  `POST link/{provider}` (vrátí URL, která s form tokenem spustí propojení přihlášeného správce), `POST identities/{id}/unlink`.
- Propojení vlastního účtu správce: tlačítko "Propojit můj účet" -> `uxs_tpl=start&mode=link&<form token>=1` (jen administrace, vyžaduje
  přihlášení a token, callback ověří, že se identita přihlášeného uživatele shoduje).

## Ověřeno (J5.4.10 i J6.2.0, mock OIDC poskytovatel na localhostu s kontrolou PKCE)

Přihlášení na web a do administrace, párování podle e-mailu, opakované přihlášení přes uloženou identitu, zakládání účtu (a odmítnutí
skupiny Super Users / skupiny bez práva přihlášení), odmítnutí: neověřený e-mail, špatný `nonce`/`aud`/`iss`/`exp`, cizí `state`, replay callbacku,
callback bez session, zamítnutí poskytovatelem, doména mimo seznam, zablokovaný uživatel, administrátor bez povolení, žádný účet; limit
startů; propojení bez form tokenu odmítnuto; `return` na web: interní URL ano, cizí ne; vypnutý modul = žádné tlačítko ani endpoint.
Offline testy validace id_tokenu a mapování identity Google/Microsoft (20 kontrol). Vše běží i s vypnutým pluginem Behaviour - Backward Compatibility (J6).

**Neověřeno proti ostrým poskytovatelům** (nejsou k dispozici přihlašovací údaje): URL a tvary odpovědí Google/Microsoft/Facebook jsou podle
jejich dokumentace; u **Seznamu** jsou názvy polí (`oauth_user_id`, `account_name`, `contact-email`) z paměti dokumentace a parser je tolerantní
(zkusí i `id`/`username`/`email`), ale je potřeba ho jednou ověřit s reálným účtem.

### Rozdíly proti WP verzi
- WP: OAuth proxy přes Centrální aplikaci (HMAC `sig`, nonce v transientu, cookie vázaná na prohlížeč, role gating, link/unlink v profilu, upozornění
  "propojte účet"). Joomla: přímý OAuth2 s PKCE (secrety poskytovatelů na webu), vazba na prohlížeč přes session, tlačítka i na webu.
- Navíc Microsoft a obecný OIDC; **Apple není** (vyžaduje JWT client secret podepsaný ES256 klíčem, nemá smysl bez účtu Apple Developer).
- Chybí: self-service propojení pro běžné (nesprávcovské) uživatele na webu; párují se jen podle ověřeného e-mailu.
- Zabezpečení: účet se u poskytovatele spáruje podle e-mailu jen s potvrzením; na straně Joomly se e-mail neověřuje, proto může útočník
  s předem zaregistrovaným (neověřeným) účtem na cizí e-mail předběhnout vlastníka. Pokud web povoluje veřejnou registraci bez aktivace,
  vypněte "Párovat podle e-mailu" nebo vyžadujte aktivaci.

## Změny po revizi

- Limit 30 callbacků / 10 min na IP se kontroluje jako první věc v `callback()` i `start()`, před validací `state` a před zápisem do logu; nad limit se požadavek zahodí bez řádku v `#__uxstudio_tpl_log`.
- Nastavení `allowed_groups` (více skupin, prázdné = bez omezení): přihlášení přes poskytovatele smí jen člen vybraných skupin (včetně dědění); ekvivalent WP `allowed_roles`.
- Smazání uživatele: `AfterDeleteEvent` nese uživatele v argumentu `subject`; identity se mažou hned.
- Návrat po přihlášení na webu: na stránce com_users login se přebírá `return` z požadavku; relativní cesty se před `Uri::isInternal` převádějí na absolutní.
- Vědomé rozdíly proti WP verzi: žádný Apple, žádná notice "propojte si účet" a propojení vlastního účtu je jen ze SPA (`core.admin`); self-service propojení pro běžné uživatele webu zůstává otevřený úkol.
