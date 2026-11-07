# UX Studio pro Joomla - plán

Edice UX Studia pro Joomla 5 a 6. Architektura a kontrakty: `ARCHITECTURE.md`, návod pro moduly `docs/MODULE-GUIDE.md`,
protokol s CA `docs/CA-INTEGRACE.md`, popis jednotlivých modulů `docs/modules/<id>.md`.
Založeno 2026-11-06. Lokální testovací weby: http://localhost/joomla5 (5.4.10), http://localhost/joomla6 (6.2.0).
Postup práce: každou oblast staví Sonnet agent, kontroluje Opus (bezpečnost, funkčnost, J5/J6, kontrakt) a nálezy opravuje Sonnet.

## F0 Prostředí
- [x] Testovací Joomla 5.4.10 a 6.2.0 nainstalované přes CLI
- [x] ARCHITECTURE.md (kontrakty jádra a modulů)

## F1 Jádro
- [x] Balíček pkg_uxstudio (lib, com, plg_system, plg_task) se instaluje na J5 i J6
- [x] Kernel, ModuleRegistry (auto-discovery), Settings (šifrovaná hesla), Migrator, Api endpoint s CSRF+ACL
- [x] Admin SPA: shell, mřížka modulů, zapnutí/vypnutí, generický settings renderer, dark mode, cs/en
- [x] Scheduler úlohy z modulů, Retention
- [x] bin/build.mjs, bin/dev-link.mjs, bin/jadmin.mjs
- [x] Veřejný endpoint (PublicApi) s rate limitem, SSRF guard s připnutím IP, ochrana repa před instalací zipu na nalinkovaný web
- [x] Průvodce nastavením (onboarding wizard jako WP verze) - commit 0f6e23c
- [x] Požadavky modulů na jádro (izolace bootu modulů, sdílený ClientIp, Core\Mail, SafeRegex, repeater/media/richtext pole, multipart v PublicApi, Http redirecty, Store, CSP zdroje, admin upozornění)

## F1b Centrální aplikace
- [x] CMS adaptér v CA (WordPress vs Joomla) - větev `feature/joomla-sites` ve worktree `D:\xampp\htdocs\_ca-wt-joomla`, migrace 121, detail v CA PLAN.md §39 (souhlas uživatele 2026-11-06)
- [x] Modul ca-connector (párování, HMAC endpoint ?uxstudio_node, SSO, dispatch na caFeed modulů, CaClient) - commit cbac5f9
- [x] End-to-end párování skutečného Joomla webu s CA a ověření health/SSO v CA (2026-11-07, J5 i J6)
- [x] End-to-end bany: ban na J5 i J6 -> tick CA (CronController::doBotThrottleBans) -> řádek v site_bot_bans a posunutý cursor (2026-11-07, viz F3)
- [ ] Podpis odpovědí konektoru ověřovat v CA ApiClient (CA PLAN §39.3b)

## F2 Moduly - 1. dávka (postaveno, zrevidováno Opusem, nálezy opraveny)
- [x] E-mail: smtp-email, email-log, email-health - ef45eae
- [x] Bezpečnost: security-optimization (hlavičky, CSP, login, tajná URL administrace, Turnstile), bot-throttle - b46a2ba
- [x] Přesměrování + log 404 + 404 na úvodní stránku - 8d8a4ab
- [x] Formuláře + mod_uxstudio_form - 1c60b86
- [x] Recenze: review-aggregator + google-review-request + mod_uxstudio_reviews - b050bd4
- [x] Popupy + exit popup - e45f4ef
- [x] Notice board, horní lišta, otevírací doba + mod_uxstudio_opening_hours - 6cbdbf6
- [x] Kontrola zranitelností (Joomla VEL, Security Centre RSS, EOL, aktualizace) - 737bc7b
- [x] Analytika + Pixel/Tag manager - 19a494f
- [ ] Brevo API ověřit s platným klíčem (testovací klíč není)

## F2b Moduly - 2. dávka (postaveno, zrevidováno Opusem, opraveno; commity 2026-11-07 až 56d6735)
- [x] Přihlášení přes Google/Seznam/Microsoft/Facebook (third-party-login)
- [x] Optimalizace obrázků + auto-image-upload
- [x] AI markdown / llms.txt
- [x] Instagram feed + mod_uxstudio_instagram
- [x] Servisní požadavky + dashboard widgety
- [x] Výkon + měření načítání stránky
- [x] DNS manager
- [ ] Souhlas s cookies (consent) - čeká na rozhodnutí: WP verze je zatím jen plán (WP PLAN §26), pixel-tag-manager bez něj jen upozorňuje

## F3 Integrace
- [x] Čistá instalace produkčního zipu - ověřeno na working tree k 7.11. (2. dávka necommitnutá, zip tedy neodpovídá commitu; opakovat po commitu 2. dávky) (nová Joomla 5.4.10, web `joomla5clean`): instalace bez chyb, zapnutí všech 29 modulů, migrace, 17 úloh Scheduleru, frontend 200, admin SPA bez chyb v konzoli, update stejným zipem (data zůstala), odinstalace (tabulky `#__uxstudio_*` zůstávají záměrně) a znovu instalace; hello-check v zipu není
- [x] Všechny moduly zapnuté současně na J5 i J6 (working tree k 7.11., opakovat po commitu 2. dávky; CSP test s falešnými ID): 104 GET rout bez chyby a bez PHP varování, CSP enforce basic (J5) i strict (J6) se všemi frontendovými moduly bez porušení (pixel-tag-manager a forms s Turnstile si hosty do CSP přidávají samy přes hook), defer/minify HTML z výkonu, tajná URL administrace + SSO, Apache log bez nových chyb
- [x] UI v prohlížeči (headless Chromium): mřížka, nastavení, průvodce a stránka každého modulu na J5, J6 i clean, světlý/tmavý režim, cs-CZ (nainstalovaný jazykový balíček) i en-GB, konzole bez chyb, v cs nenalezen nepřeložený text
- [x] End-to-end párování J5 i J6 s CA (větev feature/joomla-sites): párování, ping/capabilities, health refresh (updates, php-errors, email, security-monitor, vulnerabilities), SSO do administrace (jednorázový token), push IP banů, inventář v site_software; pull botích banů zatím neověřen (viz F1b)
- [x] bin/build.mjs: verze z kořenového package.json (do manifestů i asset registru), pkg obsahuje všech 9 rozšíření, manifesty validní XML, zip 985 kB bez .map/.env
- [x] Čistá instalace a běh všech 29 modulů na zipu z commitu 56d6735 (2026-11-07): nové čisté weby `joomla5clean` (5.4.10) a `joomla6clean` (6.2.0), instalace přes CLI bez chyb, všech 29 modulů zapnuto (vč. 2. dávky) bez chyby startu, 32 migrací, 17 úloh Scheduleru (všech 17 spuštěno `scheduler:run`, exit 0), 104 GET rout + core/settings + core/dashboard + core/onboarding bez chyby a PHP varování (očekávané 404/409 jen u ai-markdown/preview bez id článku a dns-manager/propagation bez aktivního DNS), frontend 200 se všemi frontendovými moduly, popupem, horní lištou a vynuceným CSP (basic J5, strict J6) bez porušení v konzoli, admin SPA (32 stránek včetně všech záložek) v cs-CZ i en-GB, světlý i tmavý (8 kombinací) bez chyby v konzoli, llms.txt a markdown článku (`?format=md` i `Accept: text/markdown`), update stejným zipem (data zůstala), odinstalace a reinstalace (stav modulů a data zůstala, 17 úloh znovu založeno)
- [ ] Ověřit CSP pixel-tag-manageru s reálnými ID (GTM/GA4/Meta) na veřejné doméně; GTM vlastní HTML tagy ve strict režimu (nonce bez strict-dynamic) nemusí projít
- [x] Update ze starší verze (2026-11-07): zip z commitu 2bffb56 (19 modulů) na `joomla5clean` se seedem dat (formulář, pobočka, recenze, popup, nastavení) -> update zipem z HEAD: nové migrace jádra 3 a 4 aplikovány (20 -> 22), `_core` nastavení přemigrováno, data modulů zůstala, po zapnutí 10 modulů 2. dávky 17 úloh a vše bez chyby. Verze balíčku je v obou zipech 0.1.0, takže skutečnou změnu čísla verze (vydání) to ještě neověřilo
- [ ] Skutečné odeslání e-mailů (lokální PHP mail() neumí; SMTP/Brevo ověřit na webu s odchozí poštou), reálná výzva Turnstile na veřejné doméně
- [x] Pull botích banů přes cron tick CA (2026-11-07): ban vznikl skutečnou cestou (rate limit přes X-Forwarded-For, nastavené `central_report_secret`) na J5 i J6; tick `?page=cron&key=` (`cron_api_key` v DB worktree CA, mimo git) -> řádky v `site_bot_bans` (remote_id 6 a 3) a cursory posunuty (web 68: 5 -> 6, web 69: 2 -> 3). Health refresh (updates, php_errors, email, security_monitor, vulnerabilities) pro J5 i J6 znovu success (PageSpeed na localhostu neměřen)
- [ ] Podpis odpovědí konektoru ověřovat v CA a otestovat proti reálnému webu (viz F1b)
- [ ] Prohlídka všech vnořených oken, záložek a chybových stavů ve stránkách modulů v cs (automatický průchod pokryl jen výchozí stav stránky)
- [ ] GitHub repo (sturmjan/ux-studio-joomla), update server a vydání v0.1.0 - čeká na rozhodnutí

## F4 Bezpečnostní audit (Opus, 2026-11-07)
- [x] Průřezový audit všech veřejných vstupů, ACL, XSS, SSRF, uploadů, HMAC; 11 z 12 nálezů opraveno (commity 3c544e7..aaa5997)
- [ ] Rozhodnout: má čtení odpovědí formulářů a odběratelů exit popupu vyžadovat core.admin (dnes core.manage = skupina Administrator)?
