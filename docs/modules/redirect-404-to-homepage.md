# Modul `redirect-404-to-homepage`

Port WP modulu Redirect404ToHomepage. Plný popis (společně s modulem Redirects) je v [redirects.md](redirects.md#redirect-404-to-homepage-redirect-404-to-homepage).

## Co dělá

Na `onError` s kódem 404 (nejnižší priorita, `Priority::MIN`) přesměruje na úvodní stránku (301, volitelně 302). Přeskočí se, když:

- požadavek už vyřídilo pravidlo modulu Redirects (včetně 410; zjistí se přes `Engine::handled()`, jen když je třída načtená),
- přesměroval plugin „System - Redirect“ Joomly (po přesměrování skončí dřív),
- jde o ne-HTML formát (`format=json`...) nebo o POST,
- chybí sama úvodní stránka (smyčka),
- adresa vypadá jako statický soubor (`skip_assets`, výchozí zapnuto).

## Nastavení

| Klíč | Výchozí | Popis |
|---|---|---|
| `code` | 301 | 301 nebo 302 |
| `skip_assets` | zapnuto | chybějící obrázek, CSS, JS, PDF apod. zůstane skutečnou 404 |

## Rozdíly proti WP verzi

- Přibyly `code` a `skip_assets`.
- Parsování `REQUEST_URI` je v modulu lokální, modul nezávisí na třídách modulu Redirects (na `Engine::handled()` jen volitelně přes `class_exists`).
- Google bere hromadná přesměrování na úvodku jako soft 404; modul je pro weby, které to chtějí přesto.

## Co chybí

Nic z WP verze; žádná UI stránka kromě nastavení.
