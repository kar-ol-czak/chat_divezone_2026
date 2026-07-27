# CHAT-T-099 — INSTANCJA: embeddings — Dobór rozmiaru skafandrów mokrych (product_sizing, deterministyczny)

> **Powiązane:** ADR-099 (`_docs/10_decyzje_projektowe.md`), ADR-098 (`size_variants` dyskryminator), ADR-097 (chip_context). Wzorzec trzykomponentowy: TASK-ENC-014.
> **Status wejściowy:** spec gotowy, dane wejściowe (4 tabele progów) dołączone niżej jako źródło prawdy.

## ⚠️ UWAGA NA NAZWĘ INSTANCJI
To zadanie realizuje instancja `embeddings`, ALE rozmiary NIE są embeddingami. To deterministyczny ETL do tabeli RELACYJNEJ. Lookup przez SQL `BETWEEN`, zero wektoryzacji. Jeśli w trakcie pracy pojawia się myśl „zwektoryzuj progi" — to błąd, czytaj ADR-099 pkt 1.

## CEL
Zasilić deterministyczną tabelę rozmiarów w Postgresie/Railway danymi progów dla Scubapro + Bare (skafandry mokre), tak by chat dobierał rozmiar przez function calling z algorytmem przedziałowym. Wygenerować raport pokrycia (CSV).

## ZAKRES ITERACJI 1 (ścisły — ADR-099 pkt 8)
- TYLKO marki: **Scubapro, Bare**.
- TYLKO kategorie: **skafandry mokre** (Skafandry Na ZIMNE wody + Skafandry Na CIEPŁE wody).
- Reszta marek/kategorii = NIE w tym tasku.
- Suche skafandry = WYKLUCZONE (reguła do SystemPrompt, nie do danych).

## MODEL DANYCH (long format — ADR-099 pkt 2)
Trzy tabele (nazwy do potwierdzenia z konwencją `divechat_*`):

```
divechat_size_charts
  id            BIGSERIAL PK
  brand         TEXT NOT NULL            -- 'Scubapro' | 'Bare'
  gender        TEXT NOT NULL            -- 'M' | 'K'
  source        TEXT NOT NULL            -- 'dev_ocr_2026-06' (traceability, zero fabrykacji)
  note          TEXT NULL
  created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
  UNIQUE(brand, gender)

divechat_size_chart_rows
  id            BIGSERIAL PK
  chart_id      BIGINT NOT NULL REFERENCES divechat_size_charts(id) ON DELETE CASCADE
  size_label    TEXT NOT NULL            -- etykieta handlowa: 'MT' (do matchu z pr_product_attribute)
  size_full     TEXT NULL                -- pełna nazwa z charta: 'MT - 98' (Scubapro); NULL gdy == label
  dimension     TEXT NOT NULL            -- 'chest'|'waist'|'hip'|'height'|'weight'|'leg'
  min_val       NUMERIC NOT NULL
  max_val       NUMERIC NOT NULL
  unit          TEXT NOT NULL            -- 'cm' | 'kg'
  sort_order    INT NOT NULL DEFAULT 0

divechat_product_size_chart
  product_id    BIGINT NOT NULL          -- pr_product.id_product
  chart_id      BIGINT NOT NULL REFERENCES divechat_size_charts(id)
  PRIMARY KEY (product_id, chart_id)
```

UWAGA: jeden produkt skafandra mokrego ma JEDNĄ płeć → jeden chart. Ale produkt „męski" mapuje się do charta M danej marki. Mapowanie marka+płeć→chart (ADR-099 pkt 3) — lista do akceptacji Karola, NIE auto-zapis.

## ALGORYTM DOPASOWANIA (ADR-099 pkt 4 — przedziałowy, NIE „najbliższy środek")
Klatka piersiowa = wymiar WIODĄCY (P37a). Logika:
1. Znajdź rozmiar(y), w których `chest` klienta ∈ [min,max].
2. Jeśli dokładnie jeden → kandydat; zweryfikuj pozostałymi wymiarami (waist/hip/height/weight); jeśli większość się zgadza → zwróć rozmiar.
3. Jeśli `chest` trafia w DWA rozmiary (nakładające się zakresy, np. Scubapro S/ST mają identyczny chest) → rozróżnij wzrostem/wagą; jeśli nadal niejednoznacznie → podaj OBA i zaproponuj konsultację.
4. Jeśli `chest` NIE trafia w żaden (między rozmiarami lub poza skalę) → NIE zgaduj. Podaj dwa najbliższe rozmiary + komunikat o konsultacji.
5. NIGDY nie ekstrapoluj poza zakres tabeli.

To kontrakt logiczny — implementacja (SQL vs PHP w ChatService) do ustalenia w KROK 5; preferencja: czysty lookup SQL + cienka logika decyzyjna.

## KROKI

**KROK 0 — pull/read (ZAWSZE).**
- `git pull origin main`
- Przeczytaj: ADR-099 i ADR-098 w `_docs/10_decyzje_projektowe.md`, `_docs/21_STATUS_PROJEKTU.md`.
- Zweryfikuj REALNY ostatni numer migracji w katalogu migracji (NIE zakładaj) i nadaj kolejny. Zapisz nadany numer w raporcie.
- Zweryfikuj nazwę grupy atrybutu rozmiaru w `pr_attribute_group_lang` (ADR-098 wskazywał „Rozmiar" do potwierdzenia).

**KROK 1 — migracja schematu (Railway, ⚠️ STOP przed wykonaniem na PROD).**
- Napisz migrację tworzącą trzy tabele wyżej.
- To NOWE tabele (nie zmiana istniejących) → niskie ryzyko, ale i tak STOP: pokaż SQL Karolowi, czekaj na akceptację przed wykonaniem.

**KROK 2 — seed chartów z danych wejściowych (sekcja DANE niżej).**
- Wprowadź 4 charty (Scubapro M, Scubapro K, Bare M, Bare K) i ich wiersze.
- Źródło = `dev_ocr_2026-06`. Liczby UŻYJ WPROST (P42a), bez ponownego OCR.
- Scubapro: rozbij „MT - 98" na size_label='MT', size_full='MT - 98'.
- Bare: size_label == size_full (brak podwójnego oznaczenia), size_full=NULL.
- Bare damskie ma wymiar `leg` (noga) — uwzględnij.

**KROK 3 — mapowanie produktów (półautomat, lista do akceptacji).**
- Z MySQL (read-only `divezone_chat_reader`): wyciągnij produkty marki Scubapro i Bare w kategoriach skafandrów mokrych, z atrybutem płci.
- Zbuduj propozycję mapowania product_id → chart_id (marka+płeć).
- ⚠️ STOP: wygeneruj listę do akceptacji Karola PRZED zapisem do `divechat_product_size_chart`.

**KROK 4 — raport pokrycia CSV (ADR-099 pkt 7).**
- Plik CSV w projekcie (ścieżkę zaproponuj, np. `_reports/CHAT-T-099_pokrycie-rozmiarow.csv`).
- Kolumny: product_id, nazwa, marka, płeć, ma_chart (T/N), size_variants_niepuste (T/N, z ADR-098), status.
- To realizacja pierwotnych celów: (1) brak rozmiarów mimo że powinny, (2) rozmiary bez wymiarów.

**KROK 5 — kontrakt function calling (NIE implementacja w bocie tu).**
- Zdefiniuj sygnaturę funkcji dla chatu: input (brand, gender, chest, waist?, hip?, height?, weight?), output (rozmiar | dwa kandydaci + flaga konsultacji | poza skalą).
- Zaimplementuj lookup + algorytm przedziałowy (KROK „Algorytm" wyżej) jako funkcję wywoływalną.
- Integracja z ChatService (function calling) + reguły SystemPrompt (płeć-pytanie, suche-konsultacja) = HANDOFF do instancji backend (napisz handoff w `_instances/embeddings/handoff/`).

**KROK 6 — status + raport.**
- Zaktualizuj `_docs/21_STATUS_PROJEKTU.md`.
- `git status` → `git add` PER ŚCIEŻKA (migracja, seed, raport, handoff; bez `.gitignore`/plików ignorowanych) → commit wg konwencji (sprawdź `git log`) → `git push origin main`.
- Po (ewentualnym) deploy: osobny commit `docs:` ze statusem.
- Raport końcowy: nadany nr migracji, liczba chartów/wierszy, liczba zmapowanych produktów, pokrycie %, punkty STOP w których czekasz na Karola.

## HARD STOP (NIE przekraczać bez zgody Karola)
- Wykonanie migracji na Railway PROD (KROK 1).
- Zapis mapowania produktów (KROK 3).
- Jakikolwiek re-embedding (NIE dotyczy tego taska, ale gdyby kusiło).

---

## DANE WEJŚCIOWE — TABELE PROGÓW (źródło: dev_ocr_2026-06, użyć WPROST — P42a)

### BARE męskie (chest=klp, waist=pas, hip=biodra; cm; weight kg; height cm)
```
S    waga 61-70   wzrost 168-173  klp 89-94    pas 74-79    biodra 89-94
MS   waga 63-75   wzrost 165-170  klp 94-99    pas 79-84    biodra 94-99
M    waga 68-79   wzrost 173-178  klp 94-99    pas 79-84    biodra 94-99
MT   waga 70-80   wzrost 180-184  klp 94-99    pas 79-84    biodra 94-99
MLS  waga 72-84   wzrost 168-173  klp 99-104   pas 84-89    biodra 99-104
ML   waga 77-88   wzrost 178-183  klp 99-104   pas 84-89    biodra 99-104
MLT  waga 79-91   wzrost 183-188  klp 99-104   pas 84-89    biodra 99-104
LS   waga 82-93   wzrost 170-175  klp 104-109  pas 89-94    biodra 104-109
L    waga 86-98   wzrost 180-185  klp 104-109  pas 89-94    biodra 104-109
LT   waga 88-100  wzrost 185-191  klp 104-109  pas 89-94    biodra 104-109
XLS  waga 91-102  wzrost 173-178  klp 109-114  pas 94-99    biodra 109-114
XL   waga 95-107  wzrost 183-188  klp 109-114  pas 94-99    biodra 109-114
XLT  waga 98-109  wzrost 188-193  klp 109-114  pas 94-99    biodra 109-114
2XLS waga 100-111 wzrost 173-178  klp 114-119  pas 99-104   biodra 114-119
2XL  waga 104-116 wzrost 185-191  klp 114-119  pas 99-104   biodra 114-119
3XL  waga 113-125 wzrost 188-193  klp 119-124  pas 104-109  biodra 119-124
```

### BARE damskie (UWAGA: ma wymiar `leg`/noga; brak `weight` w tabeli)
```
2    wzrost 157-163  klp 76-81    pas 58-64    biodra 84-89    noga 75
4    wzrost 160-165  klp 81-86    pas 64-69    biodra 89-94    noga 77
4T   wzrost 165-170  klp 81-86    pas 69-69    biodra 89-94    noga 81
6    wzrost 163-168  klp 86-91    pas 69-74    biodra 94-99    noga 79
6T   wzrost 168-173  klp 86-91    pas 69-74    biodra 94-99    noga 84
6+   wzrost 163-168  klp 94-99    pas 84-89    biodra 109-114  noga 79
8    wzrost 165-170  klp 91-97    pas 74-79    biodra 99-104   noga 81
8T   wzrost 175-180  klp 91-97    pas 74-79    biodra 99-104   noga 86
8+   wzrost 165-170  klp 99-104   pas 89-94    biodra 114-119  noga 81
10   wzrost 168-173  klp 97-102   pas 79-84    biodra 104-109  noga 82
10+  wzrost 168-173  klp 104-109  pas 94-99    biodra 119-125  noga 82
12   wzrost 170-175  klp 102-107  pas 84-89    biodra 109-114  noga 84
12+  wzrost 170-175  klp 109-114  pas 99-104   biodra 125-130  noga 84
14   wzrost 173-178  klp 107-112  pas 89-94    biodra 114-119  noga 85
```
UWAGA: w danych dev `noga` ma postać [75,75] (wartość punktowa) — zapis min==max. `pas` dla 4T to [69,69] — analogicznie.

### SCUBAPRO męskie (size_label = część przed „ - ”; size_full = pełne)
```
XS - 46    chest 91-96    waist 79-84    hip 99-104   height 170-175  weight 70-80
S - 48     chest 91-96    waist 79-84    hip 99-104   height 175-180  weight 70-80
ST - 94    chest 91-96    waist 79-84    hip 99-104   height 175-185  weight 70-85
MS - 25    chest 91-96    waist 79-84    hip 99-104   height 170-175  weight 75-85
M - 50     chest 96-101   waist 84-89    hip 104-109  height 175-180  weight 75-85
MT - 98    chest 96-101   waist 84-89    hip 104-109  height 180-185  weight 75-85
LS - 26    chest 101-107  waist 89-94    hip 109-114  height 175-180  weight 80-90
L - 52     chest 101-107  waist 89-94    hip 109-114  height 180-185  weight 80-90
LT - 102   chest 101-107  waist 89-94    hip 109-114  height 185-190  weight 85-95
XLS - 27   chest 107-112  waist 94-99    hip 114-119  height 180-185  weight 85-95
XL - 54    chest 107-112  waist 94-99    hip 114-119  height 185-190  weight 85-95
XLT - 106  chest 107-112  waist 94-99    hip 114-119  height 190-195  weight 85-95
2XLS - 28  chest 112-117  waist 99-104   hip 119-124  height 185-190  weight 90-100
2XL - 56   chest 112-117  waist 99-104   hip 119-124  height 190-195  weight 90-100
3XL - 58   chest 117-122  waist 104-109  hip 124-129  height 195-200  weight 95-105
4XL - 60   chest 122-129  waist 109-114  hip 129-135  height 195-200  weight 95-110
```

### SCUBAPRO damskie
```
XS - 36    chest 81-86   waist 69-76   hip 86-91    height 155-160  weight 45-60
S - 38     chest 86-91   waist 71-76   hip 89-94    height 160-165  weight 50-65
ST - 76    chest 86-91   waist 71-76   hip 89-94    height 165-170  weight 50-65
MS - 20    chest 89-94   waist 76-81   hip 94-99    height 160-165  weight 55-70
M - 40     chest 89-94   waist 76-81   hip 94-99    height 165-170  weight 55-70
MT - 80    chest 89-94   waist 76-81   hip 94-99    height 170-175  weight 55-70
LS - 21    chest 94-99   waist 81-86   hip 99-104   height 165-170  weight 60-75
L - 42     chest 94-99   waist 81-86   hip 99-104   height 170-175  weight 60-75
LT - 84    chest 94-99   waist 81-86   hip 99-104   height 175-180  weight 60-75
XLS - 22   chest 96-101  waist 84-89   hip 101-107  height 170-175  weight 65-80
XL - 44    chest 96-101  waist 84-89   hip 101-107  height 175-180  weight 65-80
2XL - 46   chest 101-107 waist 89-94   hip 107-112  height 180-185  weight 70-85
```

UWAGA jakości danych: tabele dev miały „wysoką skuteczność, nie 100%" (stąd gwiazdka „porównaj z tabelą"). Zakresy chest w niektórych rozmiarach Scubapro nakładają się (S/ST/MS/XS dzielą chest 91-96) — to NORMALNE, rozróżnienie przez height/weight. Algorytm przedziałowy (pkt 4) musi to obsłużyć przez wymiary weryfikujące, nie traktować jako błąd.

---

## Wynik (CC, 2026-06-18) — PRZYGOTOWANE, czeka na akceptację Karola (HARD STOP)

**Status:** artefakty gotowe i zweryfikowane lokalnie; aplikacja na Railway PROD + zapis mapowania = HARD STOP, czekam na zgodę.

### KROK 0 — weryfikacja w bazie (nie założenia)
- Ostatnia migracja w `sql/` = **034** → nadany numer **035** (sufiks `_seed` jak 029/030).
- Nazwa grupy atrybutu rozmiaru w `pr_attribute_group_lang` = **`ROZMIAR` (id=27)** (ADR-098 zapowiadał „Rozmiar" — realnie wielkimi literami). Dodatkowo istnieją `ROZMIAR MĘSKI` (29) i `ROZMIAR DAMSKI` (30) — używane tylko przez OneFlex (bi-gender).
- Manufacturer: BARE=11, SCUBAPRO=18. Kategorie skafandrów mokrych: CIEPŁE wody=337, ZIMNE wody=367.

### KROK 1 — migracja schematu
- `sql/035_product_sizing.sql` (+`_rollback`): 3 NOWE tabele zgodnie z modelem long format.

### KROK 2 — seed
- `sql/035_product_sizing_seed.sql` (+`_rollback`), generowany deterministycznie przez `embeddings/gen_size_charts_seed.py` (dane `dev_ocr_2026-06` zaszyte WPROST, P42a).
- **4 charty, 290 wierszy**: Bare/M 80, Bare/K 70 (z `leg` punktowym min==max), Scubapro/M 80, Scubapro/K 60. Scubapro `size_full` = pełne („MT - 98"), `size_label` = „MT"; Bare `size_full`=NULL.

### KROK 3 — mapowanie (propozycja, NIC nie zapisane)
- `_reports/CHAT-T-099_mapowanie_propozycja.csv` (READ-ONLY z MySQL, `embeddings/map_size_products.py`): 68 produktów; 52 auto-OK (płeć z nazwy), 2 bi-gender (OneFlex 4243/4244), 16 DO DECYZJI (dziecięce, unisex, niejednoznaczne).

### KROK 4 — raport pokrycia
- `_reports/CHAT-T-099_pokrycie-rozmiarow.csv` (ADR-099 pkt 7). Realizuje cele: (1) brak rozmiarów mimo że powinny — `[4245]` OneFlex Vest (rozmiar w nazwie, 0 wariantów); (2) rozmiary bez progu — `[7331]` Bare Sport Vest **Damski** z MĘSKIMI etykietami (konflikt danych do decyzji), `[2278]` „M tall"≈MT, `[5075]` „XXL"≈2XL, `[5373]` „L short/tall"≈LS/LT.

### KROK 5 — kontrakt function calling (implementacja w bocie = HANDOFF do backend)
- `embeddings/size_matcher.py` — referencyjny algorytm przedziałowy (ADR-099 pkt 4); self-test 5/5 OK (chest 104/Scubapro M→L; chest 200→out_of_scale 4XL/3XL; chest 88→out_of_scale; Bare K chest 88→6).
- Handoff: `_instances/embeddings/handoff/HANDOFF_CHAT-T-099_function-calling-rozmiar.md` (tool `recommend_wetsuit_size` + JSON Schema + reguły SystemPrompt + normalizacja `6 Plus`→`6+`).

### HARD STOP — czekam na Karola
- **(A)** wykonanie `sql/035_product_sizing.sql` na Railway,
- **(B)** wykonanie `sql/035_product_sizing_seed.sql` na Railway,
- **(C)** zapis mapowania produkt→chart (`divechat_product_size_chart`) po akceptacji listy z `_reports/CHAT-T-099_mapowanie_propozycja.csv`.

Commit: patrz git log (CHAT-T-099 + docs:). Aplikacja A/B/C → osobny commit po wykonaniu.
