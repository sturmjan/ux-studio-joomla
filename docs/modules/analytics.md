# Modul analytics (Statistiky návštěvnosti)

Vlastní měření návštěvnosti bez cookies a bez osobních údajů.

- **Sběr:** malý externí skript `media/lib_uxstudio/modules/analytics/analytics.js` (přes WebAssetManager, bez inline kódu, CSP friendly)
  po vykreslení stránky pošle `fetch keepalive` na veřejnou routu `analytics/hit` (`index.php?option=com_uxstudio&task=public.dispatch&format=json&route=analytics/hit`).
  Same-origin kontrola Originu, max. tělo 4 kB. Limit jádra (`PublicApi`) počítá surovou `REMOTE_ADDR`, proto je u této routy jen záchytný (30000/hod); skutečný limit na návštěvníka dělá sdílený `BotThrottle\Guard` s IP určenou přes důvěryhodné proxy (viz core_requests).
- **Filtr:** boti (regex na User-Agent, prázdný UA), prefetch/prerender, vyloučené cesty (nastavení), administrace, volitelně Do Not Track,
  přihlášení správci UX1 Studia (nastavení "Nepočítat administrátory"). Zahozený hit vrací stejné `ok`.
- **Soukromí:** návštěvník = `sha256(IP|UA|den|náhodná sůl dne)`. Sůl je v `#__uxstudio_analytics_salt`, mažou se soli starší než 2 dny,
  takže hashe nejdou zpětně spárovat ani zrekonstruovat. Surová IP ani UA se neukládají. Interní prokliky (referer = vlastní web) se nepočítají jako zdroj.
- **Data:** surové hity v bufferu `#__uxstudio_analytics_hits`; denní úloha plánovače `analytics.rollup` (a samoopravně při každém načtení přehledu)
  je slévá (pod zámkem `GET_LOCK`, každý den v transakci; prázdný den se nikdy nezapíše jako 0/0) do `daily`, `daily_pages`, `daily_refs`, `daily_dims` a surové řádky maže. Retence denních souhrnů: nastavení (výchozí 730 dní, 0 = navždy). Surový buffer má jen záchrannou retenci 400 dní, aby se hity nesmazaly dřív, než je zpracuje rollup.
- **Cesty:** ukládají se bez podsložky instalace (`/joomla5` se odečte), takže pravidla vyloučení platí stejně na kořenové instalaci i v podsložce; prázdná cesta se zahodí.
- **GeoIP:** volitelně, veřejná databáze zemí (ip-location-db), stahuje se jedním tlačítkem (POST vyžaduje `core.admin`), uložená v
  `<log_path>/uxs-analytics-geoip/` (výchozí `administrator/logs`, zamčeno .htaccess; záměrně ne v `cache`, protože "Vyčistit mezipaměť" maže všechny podsložky; starou polohu v cache modul při prvním čtení přesune), jen IPv4. Za proxy/Cloudflare (nastavení "Web běží za proxy") se IP určuje sdíleným resolverem `SecurityOptimization\ClientIp` (stejný režim a seznam důvěryhodných proxy jako firewall/BotThrottle; hlavičky se berou jen od Cloudflare/uvedené proxy, XFF zprava) a `CF-IPCountry` se věří jen tehdy. Úloha `analytics.geoip` databázi týdně kontroluje a po 30 dnech obnoví.
- **Admin:** záložky Přehled (období dnes/včera/7/14/30 dní/měsíc, karty Návštěvníci/Zobrazení/Právě teď s delta %, sloupcový graf, top stránky,
  zdroje, donuty zařízení/prohlížeč/OS, jazyk, země, GeoIP karta, tlačítka jen pro `core.admin` přes `can_manage`) a Nastavení. Vzhled převzatý z WP verze.
- **Routy:** `GET analytics/summary?period=`, `GET analytics/geoip`, `POST analytics/geoip {action:update|disable}` (core.admin), veřejná `POST analytics/hit`.

### Rozdíly proti WP verzi
- Beacon jde přes veřejnou routu jádra (celý boot Joomly, žádná "fast path" před bootem CMS); stránka kvůli tomu není zpožděná (asynchronní).
- Náhodná denní sůl v DB místo `wp_salt()`; vlastní limit přes `Core\RateLimiter` (žádná vlastní tabulka `rate`).
- Interní referery se nepočítají; filtr botů je v modulu (WP spoléhal jen na Guard); nastavení pro vyloučené cesty, administrátory, DNT, retenci.
- Neznámá hodnota dimenze se ukládá jako `-` (UI ji zobrazí jako "Neznámé"). Den = den v časové zóně webu.
- Chybí: CA feed (CA-INTEGRACE.md pro analytics routu nedefinuje), nahrazení IPv6 v GeoIP (jako ve WP), export dat.
