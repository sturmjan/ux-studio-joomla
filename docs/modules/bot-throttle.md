# Modul bot-throttle

WP předloha: `includes/Modules/BotThrottle`. Joomla verze přebírá detektor botů, adaptivní zátěžové stupně, Throttler, mikrocache, hit log, `Guard`
(burst guard pro jiné moduly) i notifikace banů; PHP vrstva je nativní.

Soubory: `packages/lib_uxstudio/src/Modules/BotThrottle/` (`Module.php`, `Signatures.php`, `Detector.php`, `LoadSampler.php`, `Throttler.php`, `Microcache.php`,
`Store.php`, `Kv.php`, `Guard.php`, `language/`), SPA `admin-app/src/modules/bot-throttle/`.

## Co modul dělá

Pouze frontend (site), jen GET/HEAD, v `onAfterRoute` s vysokou prioritou. Nikdy se nedotkne administrace, `com_uxstudio` (veřejné API) ani kanálu CA (`?uxstudio_node=`).
Přihlášený správce (`core.manage` / `core.admin`) je vyňat; přihlášení uživatelé podléhají jen pevnému limitu.

1. **Ban** – hash IP (HMAC se `secret` z configuration.php) v tabulce banů, jeden indexovaný dotaz. Zabanovaný dostane 429 + `Retry-After`.
2. **Pevný limit na IP** (`requests_per_minute`, výchozí 120, 0 = vypnuto): APCu, jinak `Core\RateLimiter`. Překročení → 429/503. Když je IP odmítnuta víc než
   `ban_after_blocks` krát za 10 minut (výchozí 20), vznikne **ban** na `ban_minutes` (výchozí 60), zapíše se do logu a pošle se e-mail (max 1 / 5 min).
3. **Detekce robota** podle User-Agentu (kategorie vyhledávače, AI asistenti, AI trénink, SEO nástroje, sociální sítě, archivátory, obecné crawlery), seznam povolených UA/IP/CIDR,
   seznam blokovaných UA, volitelně ověření reverzním DNS pro Googlebot/Bingbot/Yandex/DuckDuckBot/Applebot/Seznam (podvrh se řadí k obecným crawlerům; výsledek se cachuje).
4. **Zátěžový stupeň** GREEN/YELLOW/ORANGE/RED: z posledních 60 s (odezva, počet dotazů, špičková paměť, na Linuxu i load average) se vypočte skóre 0-100;
   RED se drží 30 s proti blikání. Měří se každý 10. požadavek (`#__uxstudio_bt_load`, 10s koše); **vlastní záměrná prodleva se od odezvy odečítá**, takže zpomalování botů zátěž nezvyšuje.
5. **Plán** podle kategorie a stupně: pustit, zpomalit (usleep, jen GREEN/YELLOW), mikrocache (při ORANGE/RED; při `block_on_miss` bez kopie 429/503), blokovat. Vyhledávače se nikdy nezablokují.
6. **Mikrocache** pro roboty: `cache/uxs-bot-cache/*.cache` s `.htaccess` (deny all) a `index.html`; klíč = host + URI + kategorie, TTL podle stupně; lidé ji nikdy nevidí.
7. **Guard::exceeded($context, $max, $window, $banFor)** – opakovaně použitelný burst guard pro jiné moduly; ban je vázán na `context`, respektuje zapnutí modulu.

IP adresy se ukládají jen jako hash. Volitelné `central_report_secret` (šifrované pole, stejná hodnota na všech webech flotily) dává `report_hash` (HMAC) – jen takové bany se hlásí CA.

## Nastavení

Sekce Obecné (zdroj IP: převzít z Optimalizace zabezpečení / přímé / Cloudflare / XFF + důvěryhodné proxy, rDNS, limit, odpověď, bany, notifikace, mikrocache, retence, sdílený klíč),
Kategorie robotů (pravidlo pro každou), Zátěž a prodlevy (prahy, rozsahy prodlev), Seznamy (povolené/blokované UA, povolené IP).

## Tabulky (migrace v1)

`#__uxstudio_bt_log` (retence `retention_days`, výchozí 14), `#__uxstudio_bt_bans` (retence 90 dní), `#__uxstudio_bt_load`, `#__uxstudio_bt_kv` (rDNS odpovědi a stupeň).
Úloha plánovače `cleanup` (6 h).

## API (administrace)

`GET overview`, `GET log`, `POST log/clear` (core.admin), `GET bans`, `POST bans/{id}/delete` (core.admin; ban se zruší nastavením konce do minulosti, řádek zůstává pro kurzor CA),
`POST test {user_agent, ip}` (simulace, nic se neukládá), `POST cache/clear` (core.admin).

## Napojení na CA

`caFeed('bot-throttle/bans', ['since'=>N], [])` (přijme i `bans`) → `{items:[{id, ip_hash, reason, banned_at}], last_id}`; `ip_hash` je klíčovaný flotilový hash (`report_hash`),
bany bez nastaveného klíče se nehlásí; kurzor `since` = poslední načtené `id`, max 200 položek.

## Rozdíly proti WP verzi / co chybí

* Hit log, bany a zátěž jsou v tabulkách místo WP options/transientů. Bucket tabulka WP (`buckets`) je nahrazena APCu nebo `Core\RateLimiter` (2 dotazy na požadavek bez APCu);
  ban kontrola je 1 dotaz. Na velmi vytížených webech doporučujeme APCu.
* Není samostatná stránka s grafem; přehled ukazuje stupeň, počty, top roboty, pravidla.
* Pevný limit a banování platí jen pro GET/HEAD (jako WP), POST záplavy řeší RateLimiter u veřejných endpointů a `Guard`.
* Mikrocache používá `ob_start()` na stránce robota; na stránkách se `Content-Type` jiným než HTML se nepoužije (ukládá se jen odpověď 200 s `</html>`).
* Celý modul vyžaduje `core.admin` (`acl()`). Výchozí zdroj IP (`inherit`) přebírá nastavení Optimalizace zabezpečení, jehož výchozí je Cloudflare.
* Adresa z rozsahů Cloudflare nebo z důvěryhodných proxy (sdílený hop, ne jeden návštěvník) se nikdy neomezuje limitem požadavků ani nebanuje; `overview.edge_warning` na to upozorní.
* Mikrocache: klíč používá seřazený query string, strop 2000 souborů / 100 MB (kontrola vzorkovaně + marker `.full` na 60 s), odpověď nad 2 MB se neukládá. Při stupni RED se
  hlavní vyhledávače ověří rDNS i když je `verify_rdns` vypnuté (podvržený "Googlebot" nedostane štědrý plán). `X-UXS-Tier` se posílá jen s `debug` v Global Configuration.
* 429/503 a hit z mikrocache mají `X-Content-Type-Options: nosniff`.
* Jednotný ban "podle země" ani sdílení banů do firewallu SecurityOptimization zatím není.

## Ověřeno (J5 5.4.10 i J6 6.2.0)

Viz závěrečná zpráva agenta; shrnutí: migrace, detekce UA (GPTBot, Googlebot, AhrefsBot z blocklistu, běžný prohlížeč), plány při GREEN a RED, mikrocache (miss → uložení → hit,
cache soubor nedostupný přes web = 403), pevný limit + ban + log + e-mail, kurzor `bans?since=`, vypnutí/zapnutí modulu, úloha plánovače, dashboard widget, cs/en klíče.
