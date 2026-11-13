# Průvodce nastavením (wizard) - část jádra

Není modul, ale funkce jádra. Soubory: `packages/lib_uxstudio/src/Core/Onboarding/` (OnboardingApi, OnboardingCatalog, OnboardingStore),
`admin-app/src/app/wizard/`, jedna řádka v `Core/Api.php` (sloučení rout), klíče `UXS_CORE_WIZ_*` v `lib_uxstudio.ini`, překlady v `app/i18n/cs.json`.

## Co dělá
SPA route `#/wizard`. Kroky: Úvod, Typ webu (firemní web, e-shop, ubytování, blog, jiné), Moduly, Základní nastavení,
UX1 Network app (jen když v registru existuje `ca-connector`), Shrnutí (zapnutí po jednom přes `core/modules/<id>`, průběh, výsledek).

- Seznam modulů se bere z registru (`GET core/onboarding`, bez dev modulů a bez `ca-connector`). Doporučení = mapa id -> typy webu
  v `OnboardingCatalog::recommendations()`; neexistující id se prostě neukážou, moduly mimo mapu jsou pod "Další moduly".
- Závislosti (`requires`) se přidají automaticky a zapnou se jako první.
- Základní nastavení (e-mail a jméno odesílatele, kontaktní e-mail) se uloží a vyplní do nastavení modulů, které takový klíč mají
  (`OnboardingCatalog::profileTargets()`, ověřuje se schématem a ACL modulu). Hodnota nastavená správcem se nepřepíše (přepíše se jen prázdná
  nebo ta, kterou předtím zapsal průvodce).
- Stav je v `#__uxstudio_settings` pod klíčem `_onboarding` (status new|started|dismissed|completed, typ webu, zapnuté moduly, profil).
- Nabídka: banner na obrazovce Moduly, když stav je new/started a není zapnutý žádný běžný modul; první návštěvu průvodce otevře sám jednou.
  Jde přeskočit (křížek / "Přeskočit průvodce") a znovu spustit (odkaz "Spustit průvodce nastavením" na Modulech).

## Routy (core, ACL core.manage, CSRF)
`GET core/onboarding`, `POST core/onboarding/state {action:start|dismiss}`, `POST core/onboarding/profile`, `POST core/onboarding/complete {site_type, modules}`.

## Rozdíly proti WP verzi
- Nový krok Typ webu (WP ho nemá); chybí kroky specifické pro WP (převzetí ux1 pluginu, vlastní login URL, detekce prostředí, migrace pluginů).
- Není náhled bez zápisu (steps-preview), předvolby nastavení modulů podle typu webu (WP StarterPack `settings`) ani "Vrátit vše zpět" s 30denním snapshotem (revert); průvodce moduly jen zapne s výchozím nastavením a vyplní e-mail/jméno odesílatele. Kontaktní e-mail je jen výchozí adresa, odesílání upozornění se zapíná v každém modulu zvlášť; moduly se vypínají ručně, data se nemažou.
- Krok CA jen zapne konektor a po dokončení nabídne otevření jeho stránky, kde se připojení provede (párování vlastní konektor).

## Co chybí
Revert, detekce prostředí (server, Cloudflare), CA krok nezná stav spárování (konektor zatím nevystavuje API).
