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
