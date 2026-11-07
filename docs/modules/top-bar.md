# Top Bar (`top-bar`)

Plánovaná oznamovací lišta nahoře na webu. Kód: `packages/lib_uxstudio/src/Modules/TopBar`, assety
`packages/lib_uxstudio/media/modules/top-bar/`, obrazovka `admin-app/src/modules/top-bar`.

## Co modul dělá

- Za otevírací tag `<body>` vloží `<div id="uxstudio-top-bar" role="region">` (listener `onAfterRender`, jen HTML stránky webu,
  ne `tmpl=component`).
- Zprávy: text lišty z nastavení (`content`, richtext) je vždy první, další zprávy jsou v tabulce
  `#__uxstudio_top_bar_slides` (zveřejněno, rozvržení "jen text" / "nadpis a text", vlastní "zobrazit od / do"). Při dvou a více
  aktivních zprávách se lišta přepíná jako prezentace (interval 2-60 s, pauza při najetí myší a při fokusu), jediná zpráva
  širší než okno se posouvá (marquee). `prefers-reduced-motion` animace vypne.
- Plánování celé lišty: `schedule_publish` / `schedule_deactivate` (čas webu = časová zóna z globální konfigurace Joomly).
- Barvy jako CSS proměnné `--uxs-tb-bg` / `--uxs-tb-fg` přes `WebAssetManager::addInlineStyle` (CSP nonce řeší Joomla). Režim
  "Pozadí ze šablony" použije `var(--cassiopeia-color-primary, var(--primary, <barva>))`.
- Zavíratelná lišta (nastavení `dismissible`, `dismiss_days`): tlačítko zavření uloží cookie `uxs_tb_dismiss` s otiskem obsahu.
  PHP cookie čte a lištu pak vůbec nevykreslí (žádné probliknutí), JS ji kontroluje také. Změna textu zpráv otisk změní a lišta
  se zobrazí znovu. Volitelně `sticky` (přilepená nahoře při posouvání).
- Žádný inline JS ani `onclick`; CSS a JS jsou assety modulu.

## API (administrace)

| Routa | Účel |
|---|---|
| `GET top-bar/slides` | seznam dalších zpráv |
| `POST top-bar/slides` | nová zpráva `{published, mode, title, text, from, to}` |
| `POST top-bar/slides/{id}` | úprava (částečná, chybějící klíče zůstanou) |
| `POST top-bar/slides/{id}/delete` | smazání |
| `GET top-bar/preview` | co web ukazuje právě teď (`active`, `count`, `html`) |

Validace na serveru: text projde `InputFilter` (HTML), povinný neprázdný text (422), data `YYYY-MM-DDTHH:MM` (422 jinak).

## Rozdíly proti WP verzi

- Místo `wp_head` se lišta vkládá po `<body>`; `role="banner"` nahrazeno `role="region"` + `aria-label`.
- Zprávy "další zprávy" (WP repeater v nastavení) jsou v samostatné tabulce, protože jádro neumí typ pole `repeater`;
  obrazovka má kvůli tomu záložku "Další zprávy" s tabulkou a modalem.
- Nové: zavíratelná lišta (cookie), sticky, barvy ze šablony, náhled stavu na webu, dlaždice na přehledu UX Studia.
- Barvy se v WP vypisovaly inline `<style>`, tady přes WebAssetManager (CSP).

## Co chybí / poznámky

- Při zapnuté "System - Page Cache" nebo cache modulu se lišta (a plánování) cachuje spolu se stránkou.
- Editor text lišty v obrazovce je prostý `<textarea>` (jako u všech richtext polí jádra), HTML je filtrované.

## Revize (oprava po review)

- `POST slides/{id}/delete` vyžaduje `core.admin`.
- Text delší než 5000 znaků se odmítne (422) před filtrováním, nikdy se neřeže HTML uprostřed tagu.
- `GET preview` už nevrací markup (`html`), jen `active` a `count`; SPA zobrazuje stav.
