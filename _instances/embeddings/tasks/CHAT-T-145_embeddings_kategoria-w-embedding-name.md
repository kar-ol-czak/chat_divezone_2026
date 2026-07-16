# CHAT-T-145 (embeddings): kategoria w `embedding_name` — eksperyment z bramą

**Instancja:** embeddings
**ADR:** ADR-125 (`_docs/10_decyzje_projektowe.md`, commit 3bb3c8b)
**Karta Trello:** Chat - 26
**Swiat:** ZADEN. Pipeline lokalny (tunel SSH). Zero rsync, zero deployu, zero zmian w PHP.

**STATUS ADR: EKSPERYMENT.** Wynik rozstrzyga brama pomiarowa. Zmiana moze zostac
ODRZUCONA — i to jest dopuszczalny wynik, nie porazka.

---

## KONTEKST — zweryfikowany, nie diagnozuj od nowa

`build_multivector_texts()` (`embed_target_products.py` ~74-93) buduje 3 teksty:
- `text_name` = `product_name + " " + brand_name` — **BEZ kategorii**
- `text_desc` = `Kategoria: {category_name}. {opis[:500]}. Cechy: {...}` — kategoria TYLKO tu
- `text_jargon` = `", ".join(search_phrases)` — **BEZ kategorii**

`ProductSearch.php` (~413-415) odpytuje wszystkie trzy tory **rownolegle** i laczy (RRF).
Skutek: konkatenacja z ADR-122 poprawila tylko tor `desc` (7647: +0.0421), a `name`/`jargon`
zostaly nietkniete (Δ=0.0000 w bramie T-142 — nie mogly sie zmienic).

**Pytanie eksperymentu:** czy dolozenie `category_name` do `text_name` poprawia trafnosc,
czy **rozmywa** sygnal?

**RYZYKO (uzasadnia brame):** `text_name` jest dzis NAJCELNIEJSZYM torem — 0.9137 dla
„Shearwater Perdix 3". Jest krotki (2-6 slow), wiec kazde slowo wazy duzo. Doklejenie do
4 nazw kategorii moze sprawic, ze nazwa produktu przestanie dominowac. **Ryzykujemy
popsucie tego, co dziala najlepiej.**

---

## DECYZJE — czego NIE robic

- **101a:** testujemy **WYLACZNIE `text_name`**. Jeden wariant, jeden pomiar.
- **NIE RUSZAC `text_jargon`** — musi zostac nietkniety jako **KONTROLA**. Jesli `name`
  spadnie a `jargon` nie, wiemy ze przyczyna jest zmiana, nie szum pomiaru.
- **NIE RUSZAC `text_desc`** — dziala, ma +0.0421 z T-142.
- **NIE RUSZAC** `extract_products.py` (skonczone w T-142/T-144), `ProductSearch.php`
  (inny swiat, PHP), `category_name` w bazie.
- Zero deployu. Zero rsync.

---

## KROK 0 — pull + czytaj

```
git pull origin main
```
Przeczytaj **ADR-125** (commit 3bb3c8b) — cale, ze szczegolnym uwzglednieniem bramy
i kryterium rozstrzygajacego. Otworz `embeddings/embed_target_products.py`,
funkcja `build_multivector_texts()` (~74-93).

## KROK 1 — pg_dump PRZED czymkolwiek

Backup tabeli `divechat_product_embeddings`. Podaj sciezke i rozmiar w raporcie.
Wzor: `_backups/divechat_product_embeddings_20260715_przed_T145.sql`.

## KROK 2 — zmiana w `build_multivector_texts()`

W `text_name` dolozyc `category_name` **po** marce:
```
name_parts = [p["product_name"]]
if p["brand_name"]: name_parts.append(p["brand_name"])
if p["category_name"]: name_parts.append(p["category_name"])   # ADR-125, eksperyment
text_name = " ".join(name_parts)
```
Zachowac istniejacy separator (spacja). `category_name` jest juz obciete do 4 kategorii
przez `extract_products.py` (ADR-122, decyzja 75b) — **nie obcinaj drugi raz**.

Efekt dla 7641: `Shearwater Perdix 3 SHEARWATER` → `Shearwater Perdix 3 SHEARWATER Komputery Nurkowe + Komputery SHEARWATER`

## KROK 3 — probka + pomiar PRZED

Probka **30-50**, obowiazkowo zawierajaca: **7641, 7648, 7647, 2369, 7545** + kilka
jednokategoryjnych jako kontrola.

Zmierz similarity PRZED (stan obecny) dla frazy bazowych — te liczby sa z bramy T-142,
zweryfikowane:

| fraza | cel | `name` PRZED | `jargon` PRZED |
|---|---|---|---|
| „Shearwater Perdix 3" | 7641 | **0.9137** | 0.9137 |
| „Scubapro MK17 zestaw" | 7648 | **0.8612** | 0.8600 |
| „zestaw automatu Apeks MTX-RC" | 7647 | **0.7737** | 0.7686 |

Jesli Twoj pomiar PRZED **nie zgadza sie** z powyzszymi — STOP, zaraportuj. Znaczy to,
ze cos sie zmienilo miedzy T-142 a teraz i pomiar nie bylby porownywalny.

## KROK 4 — re-embed probki + pomiar PO

Re-embedduj **tylko probke**, sync API. Zmierz PO na tych samych frazach.
Zmierz **oba** tory: `name` (zmieniony) i `jargon` (kontrola, ma byc Δ=0.0000).

## KROK 5 — STOP. BRAMA.

**Zaraportuj tabele PRZED/PO i ZATRZYMAJ SIE.** Czekaj na decyzje Karola.

Kryterium (ADR-125):
- `name` **rosnie lub bez zmian** → zielone swiatlo na pelny katalog
- `name` **spada** → **ODRZUCAMY zmiane**, przywracamy z `pg_dump`, ADR dostaje status
  ODRZUCONA z liczbami. To dopuszczalny wynik.
- `jargon` musi miec Δ=0.0000. Jesli nie — cos poszlo nie tak, zaraportuj.

**NIE podejmuj decyzji sam. NIE ruszaj reszty katalogu bez jawnej zgody.**

## KROK 6 — pelny katalog (TYLKO po jawnej zgodzie Karola)

Re-embed `embedding_name` calego katalogu (2591). Uwaga: zmiana dotyczy **wszystkich**
produktow, nie tylko wielokategoryjnych. Batch API dozwolone przy calym katalogu.

## KROK 7 — git

`git status` przed commitem. `git pull --rebase origin main` (inne okno moze pracowac).
`git add` PER SCIEZKA:
```
git add embeddings/embed_target_products.py
git add _instances/embeddings/tasks/CHAT-T-145_embeddings_kategoria-w-embedding-name.md
```
**NIE commituj:** `_docs/10_decyzje_projektowe.md` (ADR-y pisze architekt), `_backups/`,
`*.jsonl`, `standalone/config/routes.php`.
Commit: `CHAT-T-145 embeddings: kategoria w embedding_name (ADR-125)`
Push odrzucony → `git pull --rebase`, push ponownie.

## KROK 8 — status + raport

`_docs/21_STATUS_PROJEKTU.md` — dopisz **NA GORZE**, nie nadpisuj (drugie okno tez pisze).
Sprawdz `git diff` przed commitem.

Raport: sciezka pg_dump, tabela PRZED/PO dla `name` i `jargon`, wniosek, koszt.

---

## KRYTERIA AKCEPTACJI

1. `pg_dump` wykonany PRZED zmiana, sciezka w raporcie.
2. Probka zawiera 7641, 7648, 7647, 2369, 7545.
3. Pomiar PRZED zgadza sie z liczbami z T-142 (0.9137 / 0.8612 / 0.7737).
4. `text_jargon` i `text_desc` **nietkniete** — `jargon` Δ=0.0000 w pomiarze.
5. Tabela PRZED/PO w raporcie, STOP przed pelnym katalogiem.
6. Zero zmian w `extract_products.py`, `ProductSearch.php`, `category_name` w bazie.
7. Zero rsync, zero deployu.

## POZA ZAKRESEM

- `text_jargon` (decyzja 101a — odrzucone, miesza jezyk klienta z taksonomia).
- `text_desc` (dziala, +0.0421 z T-142).
- Widok atrybucji (CHAT-T-146, inny swiat).
- Automatyzacja pipeline (karta Chat - 23).

---

## WYNIK — EKSPERYMENT ODRZUCONY (decyzja Karola 126a, 2026-07-16)

**Status ADR-125: ODRZUCONA.** Brama pomiarowa wypadla negatywnie na frazach
kanonicznych — kategoria w `text_name` rozmywa tor `name`, ktory jest sensem
ortogonalnosci RRF. Kategoria juz zasila `text_desc` (ADR-122); to tam ma pracowac.

**Dump PRZED:** `_backups/divechat_product_embeddings_20260716_przed_T145.sql`
(215 287 970 B ~205 MB, serwer PG 18.3, pg_dump libpq 18.4).

**Kontrola pomiaru:** PRZED zgodne co do 4. miejsca z brama T-142
(7641 0.9137/0.9137, 7648 0.8612/0.8600, 7647 0.7737/0.7686). Pomiar porownywalny.

**Tabela PRZED / PO** (probka 35, re-embed multi-vector `--skip-single`):

| fraza | cel | typ | name PRZED | name PO | Δ name | jargon PRZED | jargon PO | Δ jargon |
|---|---|---|---|---|---|---|---|---|
| Shearwater Perdix 3 | 7641 | canonical | 0.9137 | 0.7722 | **−0.1415** | 0.9137 | 0.9140 | +0.0003 |
| Scubapro MK17 zestaw | 7648 | canonical | 0.8612 | 0.8329 | **−0.0283** | 0.8600 | 0.8600 | 0.0000 |
| zestaw automatu Apeks MTX-RC | 7647 | canonical | 0.7737 | 0.7740 | +0.0003 | 0.7686 | 0.7685 | −0.0001 |
| Apeks ATX40 zestaw automatu | 2369 | multi-nat | 0.8125 | 0.8541 | +0.0416 | 0.7201 | 0.7201 | 0.0000 |
| Tecline szorty cargo 4mm | 7545 | multi-nat | 0.8825 | 0.8513 | **−0.0312** | 0.9365 | 0.9365 | 0.0000 |
| obudowa podwodna do smartfona | 7643 | single-ctrl | 0.7752 | 0.7397 | **−0.0355** | 0.7933 | 0.7933 | 0.0000 |
| balast nerka 2kg | 7634 | single-ctrl | 0.6859 | 0.7121 | +0.0262 | 0.7304 | 0.7305 | +0.0001 |
| koszulka merino do suchego | 7628 | single-ctrl | 0.6720 | 0.7424 | +0.0704 | 0.6903 | 0.6903 | 0.0000 |

**Odczyt:** kontrola `jargon` Δ≤±0.0003 (szum float) → pomiar wiarygodny. `name`
spada na krotkich, celnych nazwach (7641 flagowiec −0.1415 — dokladnie ryzyko z ADR),
rosnie tylko na frazach opisowych/rozmytych. Kryterium ADR-125 „name spada → odrzucamy".

**Rollback (KROK 1–3):**
1. Przywrocone `embedding_name` dla 35 ID probki z dumpa (staging temp + UPDATE 35).
2. Weryfikacja pomiarem: 7641 name **0.9137** ✓, 7648 **0.8612** ✓, 7647 **0.7736**
   (target 0.7737, Δ0.0001 = szum zapytania po stronie OpenAI; wektor z dumpa).
3. Zmiana w `build_multivector_texts()` cofnieta — `git diff` pliku pusty wzgledem HEAD.

**Koszt:** ~0,02 USD (35×3 embed + 3× 8 fraz pomiarowych, text-embedding-3-large @1536).

**Commitowane:** ten task. NIE commitowane: `embed_target_products.py` (diff pusty),
ADR-125 (status wpisuje architekt), `_backups/`, `*.jsonl`, cudze zmiany w drzewie.
