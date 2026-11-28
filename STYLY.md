# Styly – barvy podle významu peněz

Platí pro všechny nástroje Dentea (VÝKAZ, Katalog, Zakázkový list, METRIKA/MATRIX, PDF sestavy).
Rozhodnuto 28. 11. 2026. Barva vždy říká, o jaké peníze jde.

| Význam | Barva | Tmavý motiv | Světlý motiv | CSS proměnná |
|---|---|---|---|---|
| Mzdy, výměr, nárok, odměna (hlavní tón) | fialová | `#8B6CF0` | `#6E4FD8` | `--mzda` |
| Mzdy – světlejší tón (text částek, uhrazeno) | fialová světlá | `#C2B2FF` | `#A48DF2` | `--mzda-l` |
| Mzdy – tmavší tón (body, odlišení) | fialová tmavá | `#5E43C2` | `#4A2FA8` | `--mzda-d` |
| Bonus (mentorský, ABX) | lila | `#E3A6EE` | `#B256B8` | `--bon` |
| Obrat obecně | tmavě modrá | `#2F6BD8` | `#1C4FA8` | `--obrat` |
| Čistý obrat | světle modrá | `#7DB4FF` | `#4C8DEB` | `--obrat-l` |
| Náklady (srážky, materiál, režie) | červená | `#E2686B` | `#C9372C` | `--naklad` |
| Náklady – světlejší tón | červená světlá | `#F2A3A5` | `#E07A73` | `--naklad-l` |
| Zisk do Dentea | tyrkysová | `#1FC7B6` | `#00897B` | `--zisk` |
| Zisk – světlejší tón | tyrkysová světlá | `#7FE8DC` | `#26B3A3` | `--zisk-l` |
| Investice | limetková (zelenožlutá) | `#C8E64A` | `#7FA00E` | `--investice` |
| Zbývá doplatit, průběžný stav, upozornění | oranžová | `#F28C38` | `#C25100` | `--warn` |
| V pořádku, přeplaceno, spárováno | zelená | `#5CE4AB` | `#1F845A` | `--good` |
| Chyba | červená | `#E2686B` | `#C9372C` | `--bad` |

Logika: náklady červené, zisk tyrkysový, mzdy fialové, protože stojí mezi nimi.
Ovládání (vybraná záložka, hlavní tlačítko) zůstává v zelené METRIKY
`#00A794` / text `#0C343D` (světlý motiv `#046E62`). Žlutá se pro peníze nepoužívá.
