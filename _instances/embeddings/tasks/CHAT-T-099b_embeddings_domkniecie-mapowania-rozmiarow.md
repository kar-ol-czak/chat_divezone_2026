# CHAT-T-099b — INSTANCJA: embeddings — Domknięcie mapowania rozmiarów (iteracja 1) + chart dziecięcy Rebel

> **Powiązane:** CHAT-T-099 (runda 1, schemat+seed+52 mapowania), ADR-099 (model danych, algorytm przedziałowy), ADR-098 (uniwersalny przez regulację — jacket Rebel). Werdykt Karola z `_reports/CHAT-T-099_mapowanie_propozycja.csv`.
> **Charakter:** krótka runda DOMYKAJĄCA. Zapis zatwierdzonych mapowań + nowy chart dziecięcy + aliasy + reguły SystemPrompt. Jedna runda, jeden STOP przed zapisem na Railway.

## ⚠️ PRZYPOMNIENIE
Dane deterministyczne, NIE embeddingi (ADR-099 pkt 1). To zapis do tabel relacyjnych na Railway (PROD) → HARD STOP przed wykonaniem, pokaż SQL Karolowi.

## CO WCHODZI (wszystko zatwierdzone przez Karola)

### A. Mapowania pojedyncze — Bare niejednoznaczne, płeć z etykiet rozmiarów (decyzja 50a)
Zasada: Bare liczby (2,4,6…)=K, litery (S,M,L…)=M.
- [5914] Bare Sport S-Flex Shorty → **Bare/M**
- [6838] Bare Evoke Full → **Bare/K**
- [6831] Bare Reactive Full → **Bare/M**
- [6834] Bare 7mm Reactive Full → **Bare/M**
- [1146] Bare Dolphin Floaty 1mm → **Bare/K**
- [1890] Bare Sprint Shorty 1,5mm → **Bare/K**

### B. Mapowania podwójne — bi-gender / unisex (decyzja 47a, bot pyta o płeć)
Każdy → DWA wpisy w `divechat_product_size_chart` (chart M ORAZ chart K tej marki):
- [4243] Scubapro OneFlex Overal 5mm → **Scubapro/M + Scubapro/K**
- [4244] Scubapro OneFlex Overal 7mm → **Scubapro/M + Scubapro/K**
- [6681] Scubapro Hooded Vest Unisex → **Scubapro/M + Scubapro/K**

### C. Aliasy etykiet (decyzja 45c — alias teraz, poprawka u źródła w warstwie 3)
Normalizacja przy matchu wariant→wiersz charta (NIE zmiana danych w PrestaShop):
- [2278] „M tall" → `MT` (chart Scubapro/K)
- [5075] „XXL" → `2XL` (chart Scubapro/M)
- [5373] „L short" → `LS`, „L tall" → `LT` (chart Scubapro/M)
Mechanizm: warstwa aliasów (np. tabela/słownik `size_label_alias` lub mapa w size_matcher). Zaproponuj najprostsze rozwiązanie spójne z istniejącym kodem; alias rośnie, więc ma być edytowalny w jednym miejscu.

### D. NOWY chart dziecięcy Rebel — wzrostowy, wartości PUNKTOWE (decyzje 52/53a/54c/55a/56a/57a)
- Marka `Scubapro`, **gender = `DZIECI`** (jedno, bez rozróżnienia płci — 57a).
- Źródło: strona produktu Rebel Shorty 2mm + potwierdzenie Karola że skala WSPÓLNA dla wszystkich dziecięcych pianek Rebel (55a).
- **Jeden wymiar: `height`, wartości PUNKTOWE (min==max):**
```
XXS  height 104   (cm)
XS   height 116
S    height 128
M    height 140
L    height 152
XL   height 164
```
- Mapowanie do charta Scubapro/DZIECI — dziecięce PIANKI Rebel:
  - [3365] Scubapro Rebel Shorty 2mm
  - [4851] Scubapro Rebel Overal 6mm
  - [3027] Scubapro Rebel Overall 2,5mm
  - [7563] Scubapro Rebel Kamizelka z Kapturem dla dzieci — ⚠️ ZWERYFIKUJ: jeśli to pianka/kamizelka neoprenowa z wariantami wzrostowymi → mapuj; jeśli zachowuje się jak kaptur (rozmiary zbiorcze) → patrz sekcja F.
- Litery L/M/S/XL przy 4851/3027 = ten sam system, węższy dostępny zakres (56a).

### E. Korekta schematu — `gender` nie jest już binarne
- `divechat_size_charts.gender` musi dopuścić `DZIECI` obok `M`/`K`. Jeśli jest CHECK constraint binarny → migracja rozszerzająca (nadaj kolejny realny nr, zweryfikuj w katalogu). Jeśli wolny TEXT → bez zmian, tylko seed.

### F. Reguły SystemPrompt (komponent metodologiczny — handoff do backend)
1. **Dobór dziecięcy po wzroście (54c + przyleganie).** Algorytm zwraca dwa najbliższe rozmiary + flaga „graniczny". Bot: podaje dwa najbliższe; informuje WPROST że pianka musi PRZYLEGAĆ żeby grzała, więc nie brać „na zapas" jak ubrania; przy wzroście dokładnie pośrodku i dziecku w fazie wzrostu — większy DOPUSZCZALNY jako świadomy kompromis (gorsza termika teraz vs żywotność), ale jako INFORMACJA, nie domyślna rekomendacja. Decyzja należy do rodzica.
2. **Kaptur Rebel — „wymień i konsultacja".** Rozmiary zbiorcze S/M, L/XL bez wymiarów (dobór po obwodzie głowy, danych brak). Bot wymienia dostępne rozmiary, mówi że dobór przybliżony, kieruje do konsultacji. NIE liczy. NIE w `product_sizing`.
3. **Jacket Rebel — uniwersalny przez regulację (analogia ADR-098).** Jeden rozmiar, dopasowanie przez wymienny pas brzuszny (S/M) regulowany pod wzrost. NIE chart wzrostowy. Traktować jak skrzydło/uprząż z ADR-098 (uniwersalny przez regulację). Bot informuje o pasach S/M jako opcji regulacji, nie jako doborze rozmiaru.

## CO NIE WCHODZI (wykluczone / na inne listy)
- Kaptur Rebel, Jacket Rebel — sekcja F, bez mapowania do `product_sizing`.
- [4245] OneFlex Vest — 0 wariantów → lista Janka (`_reports/poprawki_zrodlowe_dla_janka.md`, cel #1).
- [7331] Bare Sport Vest Damski — konflikt płeć/etykiety → lista Janka (sekcja A), niezmapowany do rozstrzygnięcia.

## KROKI

**KROK 0 — pull/read.**
- `git pull origin main`
- Przeczytaj: ten task, CHAT-T-099 (Wynik), ADR-099 + ADR-098, `_reports/CHAT-T-099_mapowanie_propozycja.csv`, `_reports/poprawki_zrodlowe_dla_janka.md`.
- Zweryfikuj realny ostatni nr migracji i czy `gender` ma CHECK constraint. Zapisz w raporcie.

**KROK 1 — schemat (jeśli potrzeba) + STOP.**
- Jeśli `gender` ma binarny CHECK → migracja rozszerzająca o `DZIECI` (sekcja E). Jeśli wolny TEXT → pomiń.
- ⚠️ STOP: pokaż SQL Karolowi przed Railway.

**KROK 2 — seed charta dziecięcego.**
- Dodaj chart Scubapro/DZIECI + 6 wierszy `height` punktowych (sekcja D). Źródło: `rebel_product_page_2026-06`.

**KROK 3 — aliasy (sekcja C).**
- Zaimplementuj warstwę aliasów (jedno edytowalne miejsce). Zasil 4 wpisami.

**KROK 4 — zapis mapowań + STOP.**
- Przygotuj wszystkie wpisy do `divechat_product_size_chart`: sekcja A (6 pojedynczych), B (3×2 podwójne), D (mapowania dziecięce, po weryfikacji 7563).
- ⚠️ STOP: pokaż pełną listę wpisów Karolowi przed zapisem na Railway.

**KROK 5 — algorytm: obsługa wartości punktowych.**
- Rozszerz `size_matcher.py`: gdy chart ma wymiar punktowy (min==max, np. height dziecięcy) → logika „dwa najbliższe + flaga graniczny" (sekcja F.1), NIE przedziałowa BETWEEN. Self-test: wzrost 134 → [S=128, M=140] graniczny; 140 → M; 170 → out_of_scale (powyżej XL).

**KROK 6 — handoff reguł SystemPrompt do backend.**
- Dopisz do handoffu function-calling (lub nowy) reguły F.1/F.2/F.3. Katalog handoff/ jest gitignored — plik lokalny.

**KROK 7 — aktualizacja raportu pokrycia + status.**
- Przelicz pokrycie po nowych mapowaniach (dorośli + dziecięce). Zaktualizuj CSV pokrycia i `_docs/21_STATUS_PROJEKTU.md`.
- `git status` → `git add` per ścieżka (migracja jeśli jest, seed, alias, size_matcher, raport CSV; BEZ handoff/gitignored) → commit wg konwencji (sprawdź `git log`) → `git push origin main`.
- Po zapisie na Railway: osobny commit `docs:` ze statusem.
- Raport: nadane nr migracji, liczba nowych mapowań (pojedyncze/podwójne/dziecięce), status 7563, nowe pokrycie %, punkty STOP gdzie czekasz.

## HARD STOP
- Migracja na Railway PROD (KROK 1).
- Zapis mapowań na Railway (KROK 4).

---

## Wynik (CC, 2026-06-18) — WYKONANE NA RAILWAY (akceptacja Karola)

### KROK 0 — weryfikacja w bazie (nie założenia)
- Ostatnia migracja w `sql/` = **035** → nadany **036**.
- `divechat_size_charts.gender` MIAŁ **binarny CHECK** `('M','K')` (`divechat_size_charts_gender_chk`) → wymagana migracja rozszerzająca (sekcja E).
- [7563] zweryfikowany: etykiety **XS/S/M/L/XL** (literowe, nie zbiorcze „S/M") → dziecięca pianka neoprenowa → MAPUJ do Scubapro/DZIECI (NIE kaptur F.2).

### KROK 1 — migracja 036 (schemat) ✅
- `sql/036_product_sizing_dzieci_alias.sql` (+rollback): (E) CHECK gender → `('M','K','DZIECI')`; (C) tabela `divechat_size_label_alias` (jedno edytowalne źródło, wspólne chat↔kalkulator).

### KROK 2+3 — seed 036 ✅
- `sql/036_product_sizing_dzieci_alias_seed.sql` (+rollback): chart `Scubapro/DZIECI` (height punktowy XXS=104, XS=116, S=128, M=140, L=152, XL=164; źródło `rebel_product_page_2026-06`) + 4 aliasy (`M tall`→MT [Sc/K], `XXL`→2XL, `L short`→LS, `L tall`→LT [Sc/M]).

### KROK 4 — mapowania ✅ (+16, −1)
- A (6 single): 5914/6831/6834→Bare/M; 6838/1146/1890→Bare/K.
- B (3 bi-gender ×2): 4243/4244/6681→Scubapro/M + Scubapro/K.
- D (4 dziecięce): 3365/4851/3027/7563→Scubapro/DZIECI.
- Korekta: usunięto błędne 7331→Bare/K (męskie etykiety → lista Janka).
- **Razem mapowań: 67** (Scubapro/M 17, Scubapro/K 16, Bare/M 13, Bare/K 17, Scubapro/DZIECI 4).

### KROK 5 — algorytm punktowy ✅
- `embeddings/size_matcher.py`: `match_pointwise` (NIE BETWEEN) + `is_pointwise` + warstwa aliasów (`load_aliases`/`resolve_label`). Self-test 9/9 (5 przedziałowy + 4 punktowy: 134→[S,M] graniczny, 140→M, 170→out_of_scale, 100→out_of_scale). Live na Railway potwierdzone.

### KROK 6 — handoff ✅
- ADDENDUM CHAT-T-099b w `_instances/embeddings/handoff/HANDOFF_CHAT-T-099_function-calling-rozmiar.md` (lokalny, gitignored): chart dziecięcy + algorytm punktowy + aliasy + reguły SystemPrompt F.1/F.2/F.3.

### KROK 7 — pokrycie + status ✅
- `embeddings/coverage_report.py` (z autorytatywnych mapowań PG): **68 produktów → 64 zmapowane (94%), 4 na liście Janka (4245/7331/4553/4554), 0 niezmapowanych**. Po aliasach `rozmiary_bez_progu` puste dla wszystkich zmapowanych.

### HARD STOP — oba przeszły z akceptacją Karola (CC zaaplikował przez DATABASE_URL)
KROK 1 (migracja 036) i KROK 4 (pełna lista mapowań +16/−1) pokazane Karolowi przed Railway → zatwierdzone.
