# Zakázkový list — Trello Power-Up

První uživatelský prototyp pro evidenci výkonů na Trello kartě.

## Co umí

- Zobrazí se jako `card-back-section` v detailu karty.
- Pokusí se načíst UID z custom fieldu se jménem `UID` (fallback: první custom field).
- Má demo data pro ANID/ceník a MNLOT/šarže.
- Ukládá záznamy **jen** do Trello plugin data konkrétní karty pod klíčem `zakazkovyListEntries`.
- Umožní vložit, upravit, zobrazit detail a smazat výkon.

## Co tato verze nedělá

- Nezapisuje do EVIDENCE.
- Nezapisuje do SQL.
- Neodepisuje sklad.
- Negeneruje ANLOT.

## GitHub Pages

1. V GitHub repozitáři otevři **Settings → Pages**.
2. V části **Build and deployment** vyber `Deploy from a branch`.
3. Vyber branch `main` a složku `/(root)`.
4. Po publikaci bude Connector URL ve tvaru:
   `https://alveol-lab.github.io/trello-zakazkovy-list/index.html`

## Trello Power-Up

1. Otevři https://trello.com/apps/admin
2. Vytvoř nebo otevři Power-Up ve správném workspace.
3. Do **Connector URL** vlož GitHub Pages URL na `index.html`.
4. V capabilities zapni **Card back section**.
5. Přidej Power-Up na testovací board.

## Archiv vydaných prohlášení (krok 31)

- `archiv-lib.php` – uložení originálu prohlášení, dat sledovatelnosti a soupisu šarží (PDF/A) při vydání; snímek karty a fotky žádanky z Trella; týdenní manifest; kontrola řetězu otisků.
- `archiv-tyden.php` – týdenní doplnění (Timer). Token: `php archiv-tyden.php token`, kontrola: `php archiv-tyden.php kontrola`.
- Zapíná se souborem `home/autolab-archiv.json` (`{"zapis": true, "den": 7}`). Bez něj se nic neukládá a prohlášení funguje jako dřív.
- Soubory leží mimo web v `home/archiv/`, knihovna mPDF v `home/vendor/` (`archiv-composer.json`), databázové schéma není součástí repozitáře.

## Poznámka

Před ostrým provozem se v `section.html` nahradí demo pole `PRODUCTS` a `LOTS` bezpečným zdrojem z backendu/API. Teprve potom se naváže Evidence/SQL.