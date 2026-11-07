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
- [ ] Požadavky modulů na jádro (izolace bootu modulů, sdílený ClientIp, Core\Mail, SafeRegex, repeater/media/richtext pole, multipart v PublicApi, Http redirecty, Store, CSP zdroje, admin upozornění)

## F1b Centrální aplikace
- [x] CMS adaptér v CA (WordPress vs Joomla) - větev `feature/joomla-sites` ve worktree `D:\xampp\htdocs\_ca-wt-joomla`, migrace 121, detail v CA PLAN.md §39 (souhlas uživatele 2026-11-06)
- [x] Modul ca-connector (párování, HMAC endpoint ?uxstudio_node, SSO, dispatch na caFeed modulů, CaClient) - commit cbac5f9
- [x] End-to-end párování skutečného Joomla webu s CA a ověření health/SSO/banů v CA (2026-11-07, J5 i J6)
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

## F2b Moduly - 2. dávka
- [ ] Přihlášení přes Google/Seznam/Microsoft/Facebook (third-party-login)
- [ ] Optimalizace obrázků + auto-image-upload
- [ ] AI markdown / llms.txt
- [ ] Instagram feed + mod_uxstudio_instagram
- [ ] Servisní požadavky + dashboard widgety
- [ ] Výkon + měření načítání stránky
- [ ] DNS manager
- [ ] Souhlas s cookies (consent) - čeká na rozhodnutí: WP verze je zatím jen plán (WP PLAN §26), pixel-tag-manager bez něj jen upozorňuje

## F3 Integrace
- [x] Čistá instalace produkčního zipu (nová Joomla 5.4.10, web `joomla5clean`): instalace bez chyb, zapnutí všech 29 modulů, migrace, 17 úloh Scheduleru, frontend 200, admin SPA bez chyb v konzoli, update stejným zipem (data zůstala), odinstalace (tabulky `#__uxstudio_*` zůstávají záměrně) a znovu instalace; hello-check v zipu není
- [x] Všechny moduly zapnuté současně na J5 i J6: 104 GET rout bez chyby a bez PHP varování, CSP enforce basic (J5) i strict (J6) se všemi frontendovými moduly bez porušení (pixel-tag-manager a forms s Turnstile si hosty do CSP přidávají samy přes hook), defer/minify HTML z výkonu, tajná URL administrace + SSO, Apache log bez nových chyb
- [x] UI v prohlížeči (headless Chromium): mřížka, nastavení, průvodce a stránka každého modulu na J5, J6 i clean, světlý/tmavý režim, cs-CZ (nainstalovaný jazykový balíček) i en-GB, konzole bez chyb, v cs nenalezen nepřeložený text
- [x] End-to-end párování J5 i J6 s CA (větev feature/joomla-sites): párování, ping/capabilities, health refresh (updates, php-errors, email, security-monitor, vulnerabilities), SSO do administrace (jednorázový token), push IP banů, pull botích banů (opraveno v CA), inventář v site_software
- [x] bin/build.mjs: verze z kořenového package.json (do manifestů i asset registru), pkg obsahuje všech 9 rozšíření, manifesty validní XML, zip 985 kB bez .map/.env
- [ ] Update ze skutečně starší verze balíčku (změna verze, nové migrace) - ověřen jen update stejným zipem
- [ ] Skutečné odeslání e-mailů (lokální PHP mail() neumí; SMTP/Brevo ověřit na webu s odchozí poštou), reálná výzva Turnstile na veřejné doméně
- [ ] Podpis odpovědí konektoru ověřovat v CA a otestovat proti reálnému webu (viz F1b), pull botích banů přes cron tick CA (cursor) - ověřena jen ruční cesta
- [ ] Prohlídka všech vnořených oken, záložek a chybových stavů ve stránkách modulů v cs (automatický průchod pokryl jen výchozí stav stránky)
- [ ] GitHub repo (sturmjan/ux-studio-joomla), update server a vydání v0.1.0 - čeká na rozhodnutí
