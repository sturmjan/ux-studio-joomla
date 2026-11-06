# UX Studio pro Joomla - plán

Edice UX Studia pro Joomla 5 a 6. Architektura a kontrakty: `ARCHITECTURE.md`.
Založeno 2026-11-06. Lokální testovací weby: http://localhost/joomla5 (5.4.10), http://localhost/joomla6 (6.2.0).

## F0 Prostředí
- [x] Testovací Joomla 5.4.10 a 6.2.0 nainstalované přes CLI
- [x] ARCHITECTURE.md (kontrakty jádra a modulů)

## F1 Jádro
- [ ] Balíček pkg_uxstudio (lib, com, plg_system, plg_task) se instaluje na J5 i J6
- [ ] Kernel, ModuleRegistry (auto-discovery), Settings (šifrovaná hesla), Migrator, Api endpoint s CSRF+ACL
- [ ] Admin SPA: shell, mřížka modulů, zapnutí/vypnutí, generický settings renderer, dark mode, cs/en
- [ ] Scheduler úlohy z modulů, Retention
- [ ] bin/build.mjs, bin/dev-link.mjs, bin/jadmin.mjs

## F2 Moduly (MVP)
- [ ] E-mail: SMTP, log e-mailů, zdraví e-mailu (SPF/DKIM/DMARC)
- [ ] Bezpečnost: hlavičky, CSP, zabezpečení loginu, BotThrottle
- [ ] Přesměrování + log 404
- [ ] Formuláře
- [ ] Recenze: agregátor + žádost o Google recenzi
- [ ] Popupy + exit popup
- [ ] Notice board / horní lišta + otevírací doba
- [ ] Kontrola zranitelností (Joomla VEL + dostupné aktualizace)
- [ ] Analytika + Pixel/Tag manager

## F3 Integrace
- [ ] Všechny moduly ověřené na J5 i J6, build zipu prochází
- [ ] Napojení na Centrální aplikaci
- [ ] Update server a vydání v0.1.0
