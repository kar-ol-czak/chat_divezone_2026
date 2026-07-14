# CHAT-T-131 BACKEND — dobor automatow: budzet + gotowy zestaw z manometrem

**Instancja:** backend
**Swiat:** BACKEND standalone (chat.divezone.pl). Zmiany w SystemPrompt + ewentualnie
`divechat_curated_recommendations` (Railway PG). ZERO zmian w module PS.
**ADR:** ADR-114 (dopisz do `_docs/10_decyzje_projektowe.md`; ostatni w pliku ADR-111,
112/113 zajete przez CHAT-T-129/130 — bierz 114).
**Karta Trello:** "Chat - Dobor zestawow: ATX40 wciskany wszystkim..." (Backlog).
Na start przesun do "W trakcie" (move_card WYMAGA jawnego boardId=6a55e07bc2193b7dfc53297e).

## Problem (zweryfikowany na danych)

1. **Permanentny ATX40.** `divechat_curated_recommendations` dla `regulator_recreational`
   ma prio 1 = pid 2368 (APEKS ATX40, ~2079 zl). Bot bierze prio 1 i prowadzi nim
   NIEZALEZNIE od budzetu klienta. Rozmowy: conv 609 (budzet 3500, bot poleca 2079).
2. **"Gotowy zestaw" mylony z zestawem bez manometru.** Rozmowa conv 596: klient chce
   gotowy zestaw, bot proponuje bez manometru.

### Ustalenia domenowe (na danych PROD, MySQL)
- Kategoria "Zestawy rekreacyjne" = **id 416** (17 aktywnych). Karol robi w niej
  porzadek (2026-07-14) — po uporzadkowaniu to ma byc pierwsze zrodlo gotowych zestawow.
- BRAK cechy/atrybutu "manometr" w sklepie. Jedyny sygnal "z manometrem" = slowo
  "manometr" lub "konsola" w NAZWIE produktu. Przyklady z 416: 2369, 5189, 6992, 6014,
  7383 (z manometrem); 6816/6817 MTX-RC, 6824/6826 Hollis (bez).
- Dostepnosc w 416 jest niska (prawie nic od reki) — dlatego regula 8b z fallbackiem.

## Decyzje Karola (do wdrozenia)

**9a — dopasowanie do budzetu.** Gdy klient podal budzet, dobierz produkt NAJBLIZSZY
GORNEJ GRANICY budzetu spelniajacy potrzebe — NIE domyslnie najtanszy z listy.
Koniec z ATX40 dla kazdego. Jesli budzet pozwala na lepszy automat z listy/wyszukania,
prowadz nim; ATX40 tylko gdy budzet nizszy.

**8b — definicja "gotowego zestawu".** "Gotowy zestaw" = zestaw automatu Z MANOMETREM
(I st. + II st. + octopus + manometr/konsola). Procedura bota:
1. Szukaj gotowego z manometrem w kategorii "Zestawy rekreacyjne" (416), sygnal =
   "manometr"/"konsola" w nazwie, przefiltrowane przez dostepnosc (search_products
   z kategoria + in_stock, potem get_product_details).
2. Jesli brak dostepnego z manometrem → zaproponuj bazowy zestaw + osobny manometr do
   skompletowania (to realna praktyka sklepu; prompt linia ~96 juz mowi ze montujemy
   manometry i klient odbiera gotowy zestaw — wykorzystaj to w narracji).

## Zakres implementacji

### A. SystemPrompt (`standalone/src/Chat/SystemPrompt.php`)
Istniejace zaczepy: budzet dla prezentow ~366-389 (rozszerz na dobor automatow),
serwis/montaz manometrow ~96. Dodaj sekcje doboru automatu:
- regula budzetu 9a (najblizej gornej granicy, nie najtanszy);
- definicja gotowego zestawu 8b + fallback (bazowy + manometr osobno);
- kolejnosc zrodel: curated (get_curated_recommendations) i/lub search_products z
  kategoria 416; przy braku dostepnego z manometrem — fallback.
Trzymaj sie stylu pliku (reguly + "Bug do unikniecia" z conv id).

### B. (opcjonalnie) `divechat_curated_recommendations`
Rozwaz dolozenie do `regulator_recreational` wariantow z wyzszej polki (dostepnych,
z manometrem), zeby przy wiekszym budzecie bot mial co polecic ponad ATX40. To zmiana
danych (SQL seed), nie kodu. Jesli robisz — osobny STOP przed zapisem do PG (ADR-089),
pokaz Karolowi proponowane pid + rationale przed INSERT.

## Kryteria akceptacji
1. Scenariusz budzetowy: klient "budzet 3500 na automat" → bot NIE prowadzi domyslnie
   ATX40 (2079); proponuje automat blizszy 3500 jesli dostepny.
2. "Chce gotowy zestaw" → bot proponuje zestaw z manometrem (nazwa zawiera
   manometr/konsola) LUB, gdy brak dostepnego, bazowy + manometr osobno — nie milczy,
   nie proponuje samego automatu bez octopusa/manometru jako "gotowego".
3. Brak regresji reguly budzetu prezentowego (~366-389).

## Deploy (ADR-089 — STOP przed rsync, jawne "deployuj")
Backend: rsync `SystemPrompt.php` (+ ewentualny seed SQL osobno) → chat.divezone.pl/src/Chat/
+ backup + md5 + `php -l` ea-php84 + smoke /api/health. SystemPrompt bez deployu nie
dziala (to nie repo na serwerze). Test PROD: 2 scenariusze z kryteriow przez realny czat.

## Git
`git status`; `git add` per sciezka (SystemPrompt.php + ADR); commit
`CHAT-T-131 backend: dobor automatow budzet + gotowy zestaw z manometrem (czat 609/596, ADR-114)`;
push. Po deployu osobny `docs:` commit (status 21_STATUS + karta Trello → "Do weryfikacji").

## Domkniecie
Po zweryfikowanym deployu: karta → "Zrobione", rozmowy 609/596 w
divechat_conversation_review → problem_rozwiazany (updated_by=NULL + marker w note),
wg procedury `_docs/42_weryfikacja_czatow_procedura.md`.

## Wynik (2026-07-14, CC backend)

**A. SystemPrompt** (`standalone/src/Chat/SystemPrompt.php`, `php -l` clean):
- Nowa sekcja `DOBÓR POD BUDŻET KLIENTA (CHAT-T-131, decyzja 9a)` po bloku curated:
  rekomendacja wiodąca = produkt najbliższy GÓRNEJ granicy budżetu; `priority` curated
  = kolejność kuratorska, NIE ranking; do ~10% ponad budżet z jawnym zaznaczeniem;
  budżet poniżej najtańszej pozycji → najtańsza jako minimum. Bug: czat 609.
- Nowa sekcja `"GOTOWY ZESTAW" AUTOMATU = ZESTAW Z MANOMETREM (decyzja 8b)`:
  definicja (I st.+II st.+octopus+manometr/konsola), procedura search_products
  category="Automaty Oddechowe" + exact_keywords ["manometr"]/["konsola"] +
  in_stock_only, fallback bazowy zestaw + osobny manometr z ceną łączną (montaż
  przy odbiorze, zaczep ~96). Bug: czat 596.
  UWAGA odkrycie: w embeddingach większość produktów z kat. 416 ma category_name=MARKA
  (APEKS/TECLINE), nie "Zestawy rekreacyjne" — dlatego procedura idzie przez kategorię
  nadrzędną + exact_keywords, nie nazwę podkategorii.
- Odnośnik w `UŻYJ PODANEGO PARAMETRU` (budżet sprzętowy → nowa reguła).
- Reguła budżetu prezentowego (~366-389) NIETKNIĘTA (kryterium 3).

**B. Seed WYKONANY (akceptacja Karola, decyzja 23a):**
`sql/041_curated_regulator_midrange.sql` (+rollback) wykonany na Railway PG:
pid 3192 APEKS XTX50/DST + Octopus XTX40 (3566 zł brutto, qty=3 od ręki) jako
prio 2; EVX200 (7421) → prio 3. Stan po: 2368→1, 3192→2, 5983+7421→3 (4 pozycje).

**Dokumentacja:** ADR-114 w `_docs/10_decyzje_projektowe.md`; status 3.81 w
`_docs/21_STATUS_PROJEKTU.md`.

**Deploy 2026-07-14 (ADR-089, jawne "deployuj" Karola):** backup
`~/_deploy_bak/SystemPrompt.php.20260714_163805`, rsync → chat.divezone.pl/src/Chat/,
md5 prod==local (10bcef97...), `php -l` ea-php84 clean, `/api/health` 200.

**Test PROD (realny czat, /api/chat z HMAC):**
1. "budżet 3500 zł na automat" → bot prowadzi XTX50/DST 3133 zł (curated 3192,
   od ręki), Legend 3400 zł jako alternatywa w budżecie, ATX40 2080 zł tylko jako
   opcja oszczędnościowa. Kryterium 1 ✓
2. "gotowy zestaw" (dopytanie → budżet 3000, Polska, OWD) → bazowy XTX50/DST +
   manometr OMS SPG 340 zł, cena łączna ~3473 zł z jawnym zaznaczeniem przekroczenia
   budżetu + montaż przy odbiorze; alternatywa ATX40+manometr ~2420 zł. Kryterium 2 ✓
3. Reguła budżetu prezentowego nietknięta (commit zmienia tylko sekcje doboru). ✓

**Domknięcie:** rozmowy 609/596 w `divechat_conversation_review` →
`problem_rozwiazany`/`zamkniety` (updated_by=NULL, marker w note); karta Trello
"Dobór zestawów" → "Zrobione" (weryfikacja PROD wykonana, zasada z _docs/42 sekcja 4).
UWAGA: wątek płetw z karty (conv 605, Mares/Tusa first) NIE był w zakresie
CHAT-T-131 — decyzja 4 z karty pozostaje nieobsłużona (ewentualny osobny task).

Commity: `cdb9f7b` (kod+ADR+seed SQL), docs: status 3.81 (osobny).

---

## NOTA (2026-07-14, po implementacji CC + weryfikacji architekta)

**Korekta sciezki 8b w prompcie.** Pierwotne zalozenie w tym tasku ("search_products
z kategoria Zestawy rekreacyjne 416") jest NIEWYKONALNE przy dzisiejszym stanie danych.
Zweryfikowane na Railway + kodzie:
- `category_name` w `divechat_product_embeddings` pochodzi z `p.id_category_default`
  (extract_products.py, linie 48-61), NIE z przypisania do kat. 416.
- Zestawy automatow maja `id_category_default` = marka/"Automaty Oddechowe"
  (pid 2369/3192 → "APEKS", 6816 → "ZESTAWY Apeks"); tylko 7383 ma "Zestawy rekreacyjne".
- Filtr search_products po kategorii "Zestawy rekreacyjne" znalazlby wiec ~1 produkt.

**Rozwiazanie wdrozone przez CC (poprawne):** procedura 8b idzie przez
`category="Automaty Oddechowe"` + `exact_keywords=["manometr"]` (druga proba ["konsola"]),
co trafia w NAZWY produktow niezaleznie od rozjechanego category_name.

**DLUG TECHNICZNY (do wykonania PO uporzadkowaniu kat. 416 przez Karola):**
Karol zlecil rozbudowe kategorii "Zestawy rekreacyjne" — trafia tam komplet gotowych
zestawow. Samo dodanie do 416 NIE wystarczy, by przelaczyc sciezke 8b na filtr po
kategorii, bo category_name zalezy od `id_category_default`. Warunki przelaczenia:
1. ustawic `id_category_default = 416` dla gotowych zestawow (czesc porzadku w sklepie),
2. przegenerowac embeddingi (extract_products.py → re-embed), by category_name sie odswiezyl,
3. dopiero wtedy uproscic 8b: search_products z kategoria "Zestawy rekreacyjne" zamiast
   obejscia przez exact_keywords.
Do tego czasu obejscie przez exact_keywords ZOSTAJE. Osobna karta Trello ponizej.
