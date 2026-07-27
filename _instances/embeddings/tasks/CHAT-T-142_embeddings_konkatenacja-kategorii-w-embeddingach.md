# CHAT-T-142 (embeddings): konkatenacja kategorii w `category_name` + fallback dla root-cat

**Instancja:** embeddings
**ADR:** ADR-122 (zapisany w `_docs/10_decyzje_projektowe.md`). ADR-120 nadal zarezerwowany
przez niewdrozony CHAT-T-138 — nie nadpisywac.
**Karta Trello:** 24 (do zalozenia przez architekta)
**Swiat:** ZADEN z dwoch swiatow wdrozeniowych. Pipeline uruchamiany LOKALNIE (tunel SSH).
Zero rsync, zero deployu. Jak CHAT-T-141.

---

## KONTEKST — problem ZWERYFIKOWANY, nie diagnozuj od nowa

`PRODUCTS_SQL` (`extract_products.py` ~61) bierze `category_name` **wylacznie z
`id_category_default`**: `LEFT JOIN pr_category_lang cl ON p.id_category_default = cl.id_category`.
Tekst trafia do embeddingu (~209: `parts.append(f"Kategoria: {product['category_name']}")`),
czyli do wektorow, po ktorych bot szuka.

**Skala (zmierzona na PROD): 2251 z 2610 aktywnych produktow (86%) nalezy do WIECEJ NIZ
jednej dozwolonej kategorii** (1710 ma 2, 417 ma 3, 104 ma 4, 20 ma >=5). Wszystkie traca
dzis te informacje. Przyklad: 7559 „Automat Scubapro MK17 + R095 OCTO" ma w embeddingu
`Kategoria: Automaty Oddechowe` — gubi „SCUBAPRO".

**Drzewo jest PLASKIE, nie hierarchiczne.** Kategoria 286 „Automaty Oddechowe" (d2) ma
12 dzieci na tym samym poziomie d3: marki (APEKS, SCUBAPRO, TECLINE...) OBOK typow
(Zestawy rekreacyjne, Automaty stage). Nie ma sciezki „Automaty → SCUBAPRO → Zestawy".
Produkt 7648 nalezy do dwoch rownorzednych galezi dzielacych rodzica.

**Powod (Karol):** sama marka nie mowi, czy to pianki czy automaty; sam typ gubi konkret.
Dopiero zlaczenie niesie pelna informacje.

---

## DECYZJE — czego NIE robic

- **67:** **NIE ruszac `id_category_default` w PrestaShop.** Kategoria 2 to root PS, element
  konstrukcji bazy. Zmiana przestawilaby URL produktu (`PS_ROUTE_product_rule` =
  `{category:/}{id}...{rewrite}.html`, `PS_CANONICAL_REDIRECT=2`) → 301 na produktach
  realnie sprzedajacych (7545: 32 szt., sprzedaz dzis). Zero zapisow do MySQL sklepu.
- **70a:** konkatenacja WSZYSTKICH dozwolonych kategorii, nie wybor jednej.
- **75b:** limit **4** kategorie (nie 3 — uciecie ATX40 tam, gdzie oczekiwany pelny zapis).
- **76a:** `463` (Polecane) dopisane do `EXCLUDED_CATEGORY_IDS`.
- **71b:** re-embed wszystkich **40** root-cat (33 brakujacych + 7 override'owanych przez CC
  w T-141 ze scratchpada).
- **72b:** **NAJPIERW PROBKA 30-50 + porownanie similarity, POTEM reszta.** Nie puszczac
  calego katalogu na raz.
- **68b — JUZ WYKONANE przez architekta**, nie powtarzac: wektor 7602 (`visibility='none'`)
  usuniety, tabela 2574 → **2573**.

---

## KROK 0 — pull + czytaj

```
git pull origin main
```
Przeczytaj: `_docs/10_decyzje_projektowe.md` → **ADR-122** (cala reguła + weryfikacja na danych).
Otworz: `embeddings/extract_products.py` — CALY, ze szczegolnym uwzglednieniem
`PRODUCTS_SQL` (~45-70), `ALLOWED_CATEGORIES_SQL` (~95), `get_allowed_categories` (~105),
`is_allowed` (~258-266), override whitelist (~268-275), `build_text` (~205-215).

## KROK 1 — `EXCLUDED_CATEGORY_IDS`: dodac 463

Dopisac `463` (Polecane) z komentarzem: kategoria marketingowa d2 pod Glowna, semantycznie
pusta. Zweryfikowane: **zero produktow ma `id_category_default=463`**, wiec nikt nie wypada
z indeksu; wszystkie 18 zachowuje sensowne kategorie.

## KROK 2 — `PRODUCTS_SQL`: `category_name` z konkatenacji

Zastapic `LEFT JOIN pr_category_lang cl ON p.id_category_default = cl.id_category`
podzapytaniem budujacym `category_name` wg reguły ADR-122:

- zrodlo: **`pr_category_product`** (wszystkie kategorie produktu), nie `id_category_default`
- tylko `c.active = 1`
- odfiltruj kategorie z `EXCLUDED_CATEGORY_IDS` **oraz wszystkich ich potomkow** (nested set):
  `NOT EXISTS (SELECT 1 FROM pr_category ex WHERE ex.id_category IN (...) AND c.nleft > ex.nleft AND c.nright < ex.nright)`
  — to odsiewa kategorie cenowe (dzieci PREZENTY 368) i wyprzedazowe (dzieci WYPRZEDAZE 467)
  **bez zadnej listy nazw**. Mechanizm identyczny jak w istniejacym `ALLOWED_CATEGORIES_SQL` (~97).
- odfiltruj root (`id_category = 2`) i kategorie z `EXCLUDED_CATEGORY_IDS` bezposrednio
- **sortowanie: `c.level_depth, cl.name`** — drugi klucz OBOWIAZKOWY (bez niego kolejnosc
  przy remisie d3 jest niedeterministyczna → inny tekst → inny wektor → ciche rozjazdy)
- **limit 4**, zlaczenie separatorem `" + "`

## KROK 3 — `is_allowed()`: fallback dla root-cat

Dzis (~262): `if p.get("id_category_default") in allowed_categories: return True`.
Rozszerzyc: produkt wchodzi rowniez, gdy `id_category_default` jest niedozwolona,
ale **reguła z KROKU 2 znalazla mu >=1 dozwolona kategorie** (czyli `category_name` niepuste).

**Grupa smieci odsiewa sie SAMA, bez listy wyjatkow:** 8 produktow (`Prowizja za PayPal` ×3,
`Testowy 2`, `Koszt dostawy zagranicznej`, kursy IANTD 3248/3252, `Odswiezacz` 7394) nie
nalezy do zadnej aktywnej kategorii → `category_name` puste → odrzucone. Nie dodawac ich
recznie do `EXCLUDED_PRODUCT_IDS`.

Zachowac istniejacy mechanizm whitelist (~268-275) — nie psuc voucherow (476).

## KROK 4 — weryfikacja reguły SQL-em, PRZED embeddowaniem

Uruchom nowe `PRODUCTS_SQL` (sam SELECT, bez embeddowania) i sprawdz, czy daje **dokladnie** to:

| id | oczekiwane `category_name` |
|---|---|
| 2369 | `Automaty Oddechowe + APEKS + Zestawy rekreacyjne + ZESTAWY Apeks` |
| 7641 | `Komputery Nurkowe + Komputery SHEARWATER` |
| 6803 | `Komputery Nurkowe + Komputery MARES + Zegarki nurkowe` |
| 7545 | `Skafandry mokre + Skafandry suche` |
| 7648 | `Automaty Oddechowe + SCUBAPRO + Zestawy rekreacyjne` |

Sprawdz tez, ze **znikly**: `Polecane`, `1000 PLN i wiecej`, `od 100 do 500 PLN`, `do 100 PLN`,
`WYPRZEDAZE` i jej dzieci.
**Jesli ktorykolwiek wiersz sie nie zgadza — STOP i zaraportuj. Nie embedduj.**

## KROK 5 — STOP: probka 30-50 + porownanie similarity (decyzja 72b)

**To jest brama. Bez zgody Karola nie idz dalej.**

1. `pg_dump` tabeli przed czymkolwiek (jak w T-141).
2. Wybierz probke **30-50 produktow**, ktora MUSI zawierac: 2369, 7641, 7648, 7559, 6803,
   7545 + kilka jednokategoryjnych (kontrola, ze im sie nie pogorszylo).
3. **Zmierz similarity PRZED** (stan obecny) dla fraz z CHAT-T-141:
   - `"Scubapro MK17 zestaw"` — bazowo **0.861** (name), **0.860** (jargon)
   - `"Shearwater Perdix 3"` — bazowo **0.914**
   - `"zestaw automatu Apeks MTX-RC"` — bazowo **0.768** (jargon)
4. Re-embedduj probke nowa regula.
5. **Zmierz similarity PO** na tych samych frazach.
6. **Zaraportuj tabele PRZED/PO i ZATRZYMAJ SIE.** Czekaj na decyzje Karola.

Interpretacja: jesli similarity spada, dluzszy opis kategorii rozmywa wektor zamiast
wzbogacac — reguła do korekty (np. limit 2-3 zamiast 4). Jesli rosnie lub bez zmian —
zielone swiatlo na reszte.

## KROK 6 — reszta katalogu (TYLKO po jawnej zgodzie Karola)

Pelny przebieg. Batch API dozwolone przy calym katalogu (~2610) — inaczej niz w T-141,
gdzie 50 produktow nie uzasadnialo `--full`.

## KROK 7 — git

`git status` przed commitem. `git add` PER SCIEZKA (nigdy `git add .`):
```
git add embeddings/extract_products.py
git add _instances/embeddings/tasks/CHAT-T-142_embeddings_konkatenacja-kategorii-w-embeddingach.md
git add _docs/10_decyzje_projektowe.md
```
**NIE commituj:** `_backups/`, `standalone/config/routes.php` (cudza zmiana, CHAT-T-090).
Commit: `CHAT-T-142 embeddings: konkatenacja kategorii w category_name (ADR-122)`
`git push origin main`. Osobny commit `docs:` ze statusem.

## KROK 8 — status + raport

`_docs/21_STATUS_PROJEKTU.md` (najnowsze na gorze).
Raport: tabela weryfikacji z KROKU 4, tabela similarity PRZED/PO z KROKU 5, liczba produktow
ktore weszly do indeksu dzieki fallbackowi, liczba odrzuconych z pustym `category_name`.

---

## KRYTERIA AKCEPTACJI

1. **2369 ATX40** ma `category_name` = `Automaty Oddechowe + APEKS + Zestawy rekreacyjne + ZESTAWY Apeks`.
2. Zadna kategoria cenowa/marketingowa (`Polecane`, `* PLN *`, `WYPRZEDAZE`, `PREZENTY`)
   nie wystepuje w `category_name` zadnego produktu.
3. Filtr smieci dziala przez **nested set**, nie przez liste nazw kategorii.
4. Sortowanie deterministyczne (`level_depth, name`) — dwa przebiegi daja identyczny tekst.
5. 40 produktow root-cat wchodzi do indeksu; 8 smieciowych (bez kategorii) nadal odrzuconych
   — **bez recznego dopisywania do `EXCLUDED_PRODUCT_IDS`**.
6. Vouchery (whitelist 476) dzialaja jak dotad.
7. Tabela similarity PRZED/PO w raporcie, decyzja Karola przed pelnym przebiegiem.
8. Zero zapisow do MySQL sklepu. Zero rsync.

## POZA ZAKRESEM

- Zmiana `id_category_default` w PS (decyzja 67 — ryzyko URL/301).
- Automatyzacja/cron/refaktor tunelu SSH (karta 23).
- `visibility='none'` / 7602 — zrobione (68b).
- Naprawa `ProductDetails` guard (ADR-121, znany dlug).

---

## Wynik (CC, 2026-07-15) — DONE

**Kod (`extract_products.py`, 4 zmiany):** (1) `463` Polecane + `467` WYPRZEDAŻE dodane do
`EXCLUDED_CATEGORY_IDS`; (2) `PRODUCTS_SQL` — `category_name` z korelowanego `GROUP_CONCAT`
(pr_category_product, filtr nested-set na wykluczone+potomków, root wykluczony, sort
`level_depth, name`, `SUBSTRING_INDEX(...,4)`, sep `" + "`, whitelist 476 w OR); (3) `is_allowed()`
fallback: root-cat wchodzi gdy `category_name` niepuste; (4) `get_wyprzedaz_products()` +
`WYPRZEDAZ_ROOT_ID` — produkt z poddrzewa WYPRZEDAŻE wchodzi do indeksu mimo pustej kategorii.

**KROK 4 (weryfikacja SELECT) — wszystkie kontrolki EXACT:** 2369 → `Automaty Oddechowe + APEKS +
Zestawy rekreacyjne + ZESTAWY Apeks`; 7641 → `Komputery Nurkowe + Komputery SHEARWATER`;
6803 → `Komputery Nurkowe + Komputery MARES + Zegarki nurkowe`; 7545 → `Skafandry mokre +
Skafandry suche`; 7648 → `Automaty Oddechowe + SCUBAPRO + Zestawy rekreacyjne`. Zniknęły
Polecane / `* PLN *` / PREZENTY / WYPRZEDAŻE (0 trafień).

**ODSTĘPSTWO 1 — 467 WYPRZEDAŻE nie było w EXCLUDED (wbrew ADR).** ADR zakładał, że oba śmieciowe
korzenie są wykluczone — było tylko 368 PREZENTY. Bez 467 WYPRZEDAŻE + 4 dzieci (477/478/479/480)
zostawały w 31 produktach. Dodano 467.

**ODSTĘPSTWO 2 — 14 produktów outletowych osieroconych.** Wykluczenie 467 zostawia bez kategorii
14 sprzedażowych produktów (jedyne kategorie w poddrzewie WYPRZEDAŻE). **Decyzja Karola: trzymamy
w indeksie** (`get_wyprzedaz_products`; embed na nazwie+marce+opisie). Śmieci bez żadnej aktywnej
kategorii (26 = 25 puste + 5910) nadal odrzucone; wszystkie 25 nigdy nie były w indeksie → zero regresu.

**KROK 5 (brama 72b) — pg_dump + próbka 32 + similarity PRZED/PO:** backup
`_backups/divechat_product_embeddings_20260715_przed_T142.sql` (~197 MB, 2573 wiersze). Ustalenie:
`category_name` zasila TYLKO `embedding_desc` + single `embedding` (`build_multivector_texts`),
NIE `embedding_name`/`embedding_jargon`. PRZED→PO: name Δ=0.0000, jargon Δ=0.0000, **desc rośnie**
(7641 +0.0005, 7648 +0.0079, 7647 **+0.0421**), single ±0.007. Zero regresu → **zielone światło Karola**.

**ODSTĘPSTWO 3 — visibility=none NIE ukrywa produktu (korekta Karola).** Wyszukiwarką sklepu jest
Luigi's Box (zewnętrzna), ignoruje `visibility` PS → 444 produktów visibility=none to NORMALNE
produkty, nie zombie. NIE usunięto. Właściwe kryterium „bot poleca" = `available_for_order`
(osobny CHAT-T-143). Decyzja 68b (usunięcie 7602) oparta na tej samej fałszywej przesłance →
**7602 przywrócony**. `embed_target_products.py --ids 7602` NIE zadziałał (extract_products filtruje
`visibility!='none'`); override po ID → 7602 z `Automaty Oddechowe + Automaty stage + TECLINE`.

**KROK 6 (pełny re-embed, zgoda Karola):** kryt.#2 domknął architekt — usunął 9 wpisów
`category_name='WYPRZEDAŻE'` (visibility=none): 5424/5772/5874/5876/6182/6386/6578/6580/7474,
2599→2590. Single: `batch_embed_products.py --full` (Batch API) 2155 upsertów, 0 błędów. Multi:
Batch API (7770 req) UTKNĄŁ 0/7770 40 min (kolejka OpenAI) → anulowany; sync RESUMABLE dla 2155
dozwolonych, 2155/2155, 0 błędów (444 visibility=none pominięte — ich kategoria bez zmian).

**Weryfikacja PO:** tabela **2591** (2590 + 7602), forbidden=0, PREZENT=5 (vouchery), wielokategoryjnych
1867, NULL name/desc/jargon/single = 0/0/0/0. Semantyka: 7647 desc rank3 (0.791↑) / jargon rank1;
7648 name rank3 / jargon rank2; 7641 rank1 wszędzie; 7602 pełne wektory.

**KRYTERIA AKCEPTACJI:** 1 ✅, 2 ✅, 3 ✅, 4 ✅, 5 ✅, 6 ✅, 7 ✅, 8 ✅.

**git:** commit per-ścieżka `embeddings/extract_products.py` + ten task. `_docs/10_decyzje_projektowe.md`
NIE edytowany przeze mnie (moją notę cofnąłem — architekt dopisuje własne noty). NIE commitowane:
`_backups/`, `*.jsonl`, `standalone/config/routes.php`, skrypty scratchpad.
