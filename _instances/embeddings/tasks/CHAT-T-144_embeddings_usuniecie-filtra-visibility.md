# CHAT-T-144 (embeddings): usunięcie filtra visibility z pipeline (ADR-123 nota 93a)

**Instancja:** embeddings
**Karta Trello:** 30. **Decyzja Karola:** 94a. **ADR:** ADR-123 nota 93a (commit b12e627).
**Świat:** ŻADEN z dwóch światów wdrożeniowych. Pipeline lokalny (tunel SSH), zero rsync/deployu.
**Kontekst:** follow-up z raportu CHAT-T-142 (część „extract_products"). NIE mylić z CHAT-T-143
(backend PHP, inna instancja, usuwa filtr visibility z `ProductSearch`).

---

## PROBLEM

`extract_products.py` `PRODUCTS_SQL` miał `AND ps.visibility != 'none'` — filtr visibility
w SAMYM PIPELINIE. Dlatego w T-142 `embed_target_products.py --ids 7602` nie zadziałał i trzeba
było override po ID. Sprzeczność: ADR-123 nota 93a każe botowi znać produkty vis='none'
(Luigi's Box pokazuje je klientom), a pipeline odmawiał im wektorów / świeżej reguły category_name.

**Skala (PROD, po T-142):** 436 produktów vis='none' MA wektor; 397 z nich ma STARĄ regułę
category_name (zero konkatenacji); 428 to afo=0, ale 8 to afo=1 (sprzedawalne).

## KROKI

1. Usuń `AND ps.visibility != 'none'` z `PRODUCTS_SQL`. Zachowaj `ps.visibility` w SELECT
   i filtr `ps.active = 1`.
2. `pg_dump` przed operacją.
3. Re-embed 436 vis='none' nową regułą (sync API, `--ids`; NIE `--full`).
4. Weryfikacja: 8 afo=1 ma konkatenację; kontrolki T-142 nietknięte; zero śmieci; count 2591.
5. git per-ścieżka: `extract_products.py` + ten task.
6. Status + raport.

---

## Wynik (CC, 2026-07-15) — DONE

**KROK 1 — kod (`extract_products.py`):** usunięty `AND ps.visibility != 'none'` z `WHERE`
`PRODUCTS_SQL`; `ps.visibility` ZOSTAJE w SELECT, `ps.active = 1` bez zmian. Komentarz z
odnośnikiem do ADR-123 nota 93a. Efekt: `extract_products()` zwraca teraz **2606** produktów
(było 2155) — vis='none' przechodzą filtr (jeśli mają dozwoloną kategorię / są w poddrzewie
WYPRZEDAŻE). Syntax OK.

**KROK 2 — backup:** `_backups/divechat_product_embeddings_20260715_przed_T144.sql`
(213 096 516 B ≈ 203 MB, 2591 wierszy COPY).

**KROK 3 — re-embed 436 (dynamicznie z PROD, nie ze sztywnej listy):** cross-ref PG×MySQL →
436 vis='none' z wektorem (zgodne z pomiarem Karola). `embed_target_products.py --ids <436>`
(sync): „Znaleziono 436/436 w extract_products" → **436 single + 436 multi, 0 błędów**.
8 afo=1: 4270, 4682, 4710, 5256, 6331, 6809, 6810, 6811.

**KROK 4 — weryfikacja:**
- **count 2591 / 2591 / max_updated 2026-07-15** — bez zmian (upsert istniejących, 0 insertów).
- 8 afo=1 vis='none': **5 ma konkatenację** (4270 `Instrumenty pomiarowe + Konsole`,
  4682 `Skrzydła i jackety + Jackety (BCD) + KLASYCZNE`, 4710 `Latarki nurkowe + Duże z Głowicą`,
  5256 `Odzież nurkowa + Skafandry suche + Bluzy i kurtki + Ocieplacze do Suchych`,
  6331 `Butle nurkowe + Butle Aluminiowe`); **3 (6809/6810/6811) mają pustą kategorię** —
  to WYPRZEDAŻE-outlet („NOWE pianki … Box, Zwroty konsumenckie"), WSZYSTKIE ich kategorie są
  w poddrzewie WYPRZEDAŻE (wykluczone z tekstu), więc empty category_name jest POPRAWNE (T-142
  decyzja); trzymane w indeksie przez `get_wyprzedaz_products`, embed na nazwie+marce+opisie.
  Wszystkie 8 mają `embedding_desc` NOT NULL — bot je znajdzie.
- 3920 (Torba MARES Cruise, test PROD z ADR-123) → `Torby i Skrzynie + Torby na Sprzęt i
  Plecaki Plażowe` (świeże).
- Kontrolki T-142 NIETKNIĘTE: 2369, 7641, 7545, 7648, 7602 — identyczne category_name.
- Śmieci (WYPRZEDAŻE/Polecane/PLN) = **0**. Wielokategoryjnych 1867 → **2186** (436 vis='none'
  dostało konkatenację). NULL desc/single = 0/0.

**DELTA do odnotowania (poza zakresem T-144):** `extract_products()` zwraca 2606, a w tabeli
2591 — różnica to ~15 produktów vis='none' indeksowalnych nową regułą, ale BEZ istniejącego
wektora. Zgodnie z zakresem (KROK 4: „count 2591 bez zmian") NIE embedowane; wejdą przy
najbliższym `--full`. Follow-up dla CHAT-T-143 / kolejnego pełnego przebiegu.

**KROK 5 — git:** commit per-ścieżka `embeddings/extract_products.py` + ten task. NIE commitowane:
`_docs/10_decyzje_projektowe.md` (ADR-y pisze architekt), `_backups/`, `*.jsonl`,
`standalone/config/routes.php`.

**Kryteria:** filtr usunięty ✅, 436 re-embedowane ✅, 8 afo=1 z wektorem (5 konkat + 3 outlet
poprawnie puste) ✅, kontrolki T-142 nietknięte ✅, zero śmieci ✅, count 2591 ✅, zero rsync ✅.
