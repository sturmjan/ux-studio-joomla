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
- [ ] End-to-end párování skutečného Joomla webu s CA a ověření health/SSO/banů v CA
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
- [ ] Čistá instalace produkčního zipu na novou Joomlu, update a odinstalace
- [ ] Všechny moduly zapnuté současně na J5 i J6 bez konfliktů (CSP, eventy), UI v prohlížeči cs/en, světlý/tmavý režim
- [ ] GitHub repo (sturmjan/ux-studio-joomla), update server a vydání v0.1.0 - čeká na rozhodnutí
