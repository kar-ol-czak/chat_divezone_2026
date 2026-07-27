# CHAT-T-153 — BACKEND: Dokręcenie wykluczeń manometru w regule 8b (luka „moduł")

**Status:** DO WYKONANIA
**Instancja:** backend (PHP)
**Powiązane:** ADR-130 nota 1 (decyzja 168a), CHAT-T-152 (regułę 8b, którą dokręcamy)
**Karta Trello:** Chat - 14

---

## ŚWIAT WDROŻENIOWY

**BACKEND `chat.divezone.pl`.** WYŁĄCZNIE `src/Chat/SystemPrompt.php`.
**ZERO tools.php (dryf Chat - 42), ZERO narzędzi, ZERO migracji, ZERO re-embedu.** Jeden plik, mikro-zmiana reguły 165a.

---

## KONTEKST

Test PROD CHAT-T-152 (S3) wykrył lukę w regule doboru manometru: bot wskazał „Manometr TECLINE 300 bar, 52mm, nikiel - moduł" (id 4266, 236 zł) jako najtańszy pasujący. Opis produktu (potwierdzony): „To moduł, czyli sama głowica manometru... Do montażu potrzebne osobne wrzeciono, łącznik między manometrem a wężem HP". **To NIE kompletny manometr — sama głowica bez węża.** Klient dostałby niekompletną część.

Przegląd kat. 107 dostępnych po cenie (2026-07-18) — przed pierwszym poprawnym manometrem (TERMO 300bar/**60cm** 249 zł) stoją do odrzucenia: wrzeciono/króciec (20), pony (108), wąż **15cm** (235), **moduł** (236). Plus OMS „SPG 52/63 mm" (340) — sam SPG bez węża, ta sama klasa.

**Wzorzec:** kompletny manometr ma w nazwie DŁUGI WĄŻ (60cm/80cm). Niekompletne = „moduł", „SPG" (samo), „wrzeciono", „króciec", krótki wąż 15cm.

---

## KROK 0 — PULL / READ
1. `git pull --rebase origin main`, `git status`.
2. Przeczytaj ADR-130 nota 1 (`_docs/10`, koniec).
3. Znajdź w `src/Chat/SystemPrompt.php` fragment reguły 8b o doborze manometru (wykluczenia 165a: pony/tlenowy/O2/wąż 15cm).

## KROK 1 — Dokręć wykluczenia manometru (reguła 165a)
Rozszerz kryterium „pasującego manometru" o wymóg KOMPLETNOŚCI. Do istniejących wykluczeń (pony, tlenowy/O2, wąż 15cm) DODAJ:
- **„moduł" / „sama głowica" / „SPG" (samo, bez węża) / „wrzeciono" / „króciec"** — to niekompletne części (głowica bez węża HP, wymaga dokupienia wrzeciona i węża).

Sformułuj jako kryterium POZYTYWNE (czytelniejsze niż rosnąca lista): manometr proponowany do gotowego zestawu MUSI być kompletny — z wężem wysokiego ciśnienia (w nazwie zwykle długość węża 60cm/80cm). Jeśli nazwa zawiera „moduł", „SPG" bez węża, „wrzeciono", „króciec" albo krótki wąż 15cm — POMIŃ, to część niekompletna lub techniczna, nie manometr rekreacyjny do kompletu.
Zachowaj resztę reguły 165a bez zmian (jeden najtańszy pasujący, reszta na żądanie).

## KROK 2 — `ea-php84 -l` clean (na serwerze przy deployu; brak lokalnego php).

## KROK 3 — STOP. Deploy (ADR-089)
Nie bez „deployuj". Świat BACKEND, JEDEN plik `src/Chat/SystemPrompt.php`. Backup `_deploy_bak/CHAT-T-153/`, rsync per ścieżka, md5 prod==local, `ea-php84 -l`, `/api/health` 200. NIE dotykaj tools.php.

**PO DEPLOYU — DEKLARACJA SENTINELA (obowiązkowe, patrz CLAUDE.md).** Na końcu raportu wypisz gotowy blok DO WKLEJENIA przez operatora, drzewo `chat.divezone.pl`, plik: `/home/divezone/public_html/chat.divezone.pl/src/Chat/SystemPrompt.php`. NIE odpalaj rebaseline sam.

## KROK 4 — Test PROD (reguła E, `[test CHAT-T-153, nie klient]`)
Powtórz S3 z T-152: „chcę zacząć nurkować, co potrzebuję do oddychania" → manometr w komplecie ma być KOMPLETNY (TERMO 300bar/60cm 249 zł lub podobny z wężem), NIE „moduł" 236 zł, NIE wrzeciono, NIE SPG samo.
Kontrola: „a są inne manometry?" → lista bez modułów/wrzecion/pony/tlenowych.

## KROK 5 — Status + raport
`_docs/21` NA GÓRZE. git: `add` per ścieżka, commit `CHAT-T-153 backend: dokrecenie wykluczen manometru - kompletny z wezem (ADR-130 nota 1)`, push. Osobny commit `docs:` po deployu. Raport jako recenzja + blok deklaracji Sentinela.

---
## CZEGO NIE RUSZAĆ
- `config/tools.php` (dryf Chat - 42), narzędzia, Railway/embeddingi, `routes.php`, `purge_litespeed.php`, ADR-y (pisze architekt).

---
**Instancja: BACKEND (PHP)**
