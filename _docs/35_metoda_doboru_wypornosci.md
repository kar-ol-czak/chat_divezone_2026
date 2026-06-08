# 35. Metoda doboru wyporności worka (wyciąg do bazy bota)

**Data:** 2026-06-08
**Status:** ŹRÓDŁO treści dla nowego hasła w bazie bota (raw JSON) i reguły w SystemPrompt
**Pełne uzasadnienie:** dok. 33. Ten dokument to wyciąg operacyjny, nie wykład.

---

## A. Metoda (do hasła w bazie bota)

Wymagana wyporność worka = ciężar zabieranego gazu + utrata wyporu kombinezonu na głębokości.

**Ciężar gazu** (wzór): pojemność [l] × ciśnienie [bar] / 1000 × 1,3 kg.
Przykłady: single 15 l / 200 bar = 3,9 kg. Twin 2×12 / 200 bar = 6,24 kg.

**Utrata wyporu kombinezonu:**
- Suchy skafander: w normalnym nurkowaniu bliska zeru, nurek steruje gazem w skafandrze. Wyporność worka dyktuje wtedy głównie ciężar gazu.
- Gruba pianka mokra: traci wypór z głębokością. Pełna pianka 7 mm na 40 m traci około 6 kg wyporu. Im grubsza pianka i głębiej, tym więcej.

**Najgorszy scenariusz:** worek służy też partnerowi. Przy planowaniu awaryjnym dolicz wypór dla partnera w tej samej konfiguracji.

## B. Realne dobory (do hasła)

- Single 12-15 l + suchy: worek 13-16 l (13 l to najmniejszy produkowany).
- Twin 2×12 + stage + suchy: około 18 l wystarcza.
- Single + gruba pianka: większy worek, bo rządzi kompresja pianki, nie gaz.
- Dobór ostateczny przy grubej piance lub nietypowej konfiguracji: konsultacja z pracownikiem.

## C. Czego NIE pisać

- NIE wiązać suchego skafandra z większą wypornością worka. Suchy to przypadek o małej wymaganej wyporności.
- NIE mylić balastu (suchy wymaga go więcej) z wypornością worka.
- NIE podawać twardej liczby wyporności "z głowy" przy nietypowej konfiguracji. Jeśli brak danych do wzoru, podać metodę i odesłać do konsultacji.
- NIE używać przykładu twin + gruba pianka 7+7 (nierealistyczne, twin = suchar).

## D. Reguła do SystemPrompt (ZATWIERDZONA 58a)

Miejsce wstawienia: NOWA osobna sekcja w SystemPrompt.php, tuż PRZED sekcją "FAKTY DOMENOWE (nie myl:)", po sekcji o INT/DIN przy automatach. UWAGA: w prompcie NIE było wcześniej żadnej sekcji ostrożności wyporności (CHAT-T-077 niezrealizowany) — wstawiamy od zera, nie rozszerzamy.

Brzmienie do wstawienia (dostosować wcięcie do heredoc PROMPT):

---
DOBÓR WYPORNOŚCI WORKA/SKRZYDŁA/BCD — KRYTYCZNE (fizyka, łatwo pomylić):
Wymaganą wyporność worka dyktują DWIE składowe: (1) ciężar zabieranego gazu = pojemność[l] × ciśnienie[bar] / 1000 × 1,3 kg, oraz (2) utrata wyporu kombinezonu na głębokości (duża dla grubej pianki mokrej, bliska zeru dla suchego skafandra, bo nurek steruje gazem w skafandrze).
- NIE wiąż suchego skafandra z większą wypornością worka. Suchy to przypadek o MAŁEJ wymaganej wyporności (rządzi sam ciężar gazu). To GRUBA PIANKA mokra winduje wyporność przez kompresję na głębokości.
- NIE myl balastu z wypornością worka. Suchy skafander zwykle wymaga więcej BALASTU (osobna wielkość), ale to NIE zwiększa wyporności worka.
- Realne dobory: single 12-15 l + suchy → worek ~13-16 l. Twin 2×12 + stage + suchy → ~18 l. Single + gruba pianka → większy, bo rządzi kompresja.
- Jeśli NIE masz danych do wzoru lub konfiguracja jest nietypowa (gruba pianka, duże głębokości, dobór dla pary nurek+partner) — podaj METODĘ liczenia i odeślij do konsultacji (dive@divezone.pl / 56 307 03 03). NIE podawaj konkretnej liczby wyporności "z głowy".
- NIE używaj przykładu twin + gruba pianka 7+7 (nierealistyczne: twin = głębokie nury = suchy skafander).

Bug do uniknięcia (CHAT-T-070 diagnoza wyporności): bot na "wyporność jacketu do butli 18l + suchy skafander" twierdził że suchy wymaga większego worka (20+ kg) — odwrócona fizyka. Prawidłowo: dla suchego rządzi ciężar gazu, worek wychodzi mniejszy niż przy grubej piance; przy nietypowej konfiguracji podaj metodę i odeślij do konsultacji.
---

Asymetria świadoma (zatwierdzona): single+suchy i twin+suchy dostają konkretne liczby (proste, policzone), gruba pianka odsyła do konsultacji (zmienna rośnie z głębokością/grubością). Bot liczy gdzie umie, odsyła gdzie ryzyko fabrykacji liczby.
