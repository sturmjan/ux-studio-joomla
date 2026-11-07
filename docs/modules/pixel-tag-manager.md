# Modul pixel-tag-manager (Pixely a značky)

GA4, Google Ads (AW-), Google Tag Manager, Meta Pixel, Microsoft Clarity, Pinterest Tag, Seznam retargeting (Sklik).

- Do stránky se přidá jen jediný soubor `ptm.js` (WebAssetManager, nonce doplní Joomla); validovaná ID a pravidla souhlasu nese atribut `data-config`.
  Žádný inline skript. Loader dynamicky vloží skripty poskytovatelů (s tím samým nonce) až po souhlasu pro jejich kategorii.
- Kategorie: **analytics** = GA4, Clarity; **marketing** = Ads, GTM, Meta, Pinterest, Seznam.
- Zdroj souhlasu (nastavení): `uxstudio` (výchozí; `window.UXSConsent.has(kategorie)` nebo cookie `uxs_consent` s JSON `{"analytics":true,"marketing":false}`;
  událost `uxs:consent` na `window` spustí nové vyhodnocení), `cookie` (cookie cizího nástroje: název + text, který musí obsahovat, zvlášť pro analytiku a marketing),
  `off` (bez souhlasu, v adminu varování). Pomalý polling (10 min) pokryje nástroje, které jen zapíšou cookie.
- Admin přehled: stav každé značky (aktivní / neplatné ID / nenastaveno), varování když web nemá žádné řešení souhlasu (hledá aktivní rozšíření cookie/consent/gdpr/
  cookiebot/complianz/iubenda...; Joomla "Privacy consent" se nepočítá) nebo když je souhlas vypnutý, a seznam hostů pro CSP (`script-src`, `img-src`, `connect-src`).
- ID se validují regexem na serveru při vykreslení (neplatné se ignoruje a hlásí v přehledu); správci UX Studia skripty nenačítají (nastavení).
- **Google Consent Mode v2:** před prvním Google tagem (GA4/Ads/GTM) se pošle `consent default` vše `denied`, po souhlasu `consent update` (`analytics_storage` pro analytiku; `ad_storage`, `ad_user_data`, `ad_personalization` pro marketing); při změně souhlasu se update opakuje.
- **Seznam/Sklik:** po načtení `rc.js` (`onload`) se zavolá `window.rc.retargetingHit({rtgId, consent:1})`.
- Přehled hlásí i neplatný/prázdný název cookie v režimu `cookie` u kategorie s aktivními značkami (taková kategorie by se nikdy nepovolila). Seznam CSP hostů používá wildcardy (`*.google-analytics.com`, `*.analytics.google.com`, `*.clarity.ms`...).
- Routa: `GET pixel-tag-manager/status`.

### Rozdíly proti WP verzi
WP verze uměla jen GA, Facebook Pixel a Pinterest a vkládala je inline bez souhlasu. Zde přibyly Ads, GTM, Clarity, Seznam, brána souhlasu,
externí loader (CSP) a varování. Chybí: noscript fallbacky (nejdou podmínit souhlasem), vlastní konverzní události,
propojení s připravovaným modulem consent (kontrakt `uxs_consent` / `UXSConsent.has` / `uxs:consent` je připraven).
