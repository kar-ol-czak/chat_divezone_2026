# CHAT-T-141 (embeddings): backfill 50 produktow 2026 + czyszczenie 37 zombie

**Instancja:** embeddings
**ADR:** BRAK — to operacja na danych, nie decyzja architektoniczna. Nie dopisuj ADR.
**Karta Trello:** 22 `6a577b42bc4369213bb0a6c5` (W trakcie)
**Swiat:** ZADEN z dwoch swiatow wdrozeniowych. Pipeline uruchamiany LOKALNIE z laptopa
Karola (tunel SSH). Zero rsync, zero deployu na serwer. Nie mylic z CHAT-T-139.

---

## KONTEKST — stan ZWERYFIKOWANY na PROD 2026-07-15, nie diagnozuj od nowa

Bot nie zna 50 produktow dodanych w 2026 (ID **7561-7648**), w tym zestawow **7647**
(APEKS MTX-RC + manometr) i **7648** (Scubapro MK17/C370) — a CHAT-T-140 wlasnie udostepnil
zestawy do zamowienia. Klient pyta o zestaw, bot go nie widzi.

**Liczby (zrodlo: Railway PG + MySQL PROD, porownanie zbiorow `comm`):**
- `divechat_product_embeddings`: **2561** wpisow (2561 unikalnych `ps_product_id`, 1 wiersz/produkt)
- Aktywnych w sklepie (`pr_product` + `pr_product_shop ps.active=1 AND ps.id_shop=1`): **2664**
- **Bez wektora: 140.** Netto 2664-2561=103 MASKUJE prawde — bo 37 wektorow to zombie.
- **Zombie (wektor jest, produkt nieaktywny/nieistniejacy): 37**

**UWAGA — handoff `_docs/43` twierdzil "10 nowych produktow". NIEPRAWDA.** Liczyl z roznicy
dat (`updated_at`), nie z porownania zbiorow. Data `2026-05-15` to ostatni PRZEBIEG pipeline'u,
**nie granica kompletnosci** — 50 z brakujacych 140 pochodzi z 2015 roku, dekade przed
jakimkolwiek embeddowaniem. Pipeline NIGDY nie pokrywal calego katalogu.

**Kolumna to `ps_product_id`, NIE `product_id`.** (`divechat_product_embeddings`).

---

## DECYZJE KAROLA — czego NIE robic

- **59b:** backfill TYLKO produktow z `date_add >= 2026-01-01` = **dokladnie 50 ID (7561-7648)**.
  Pozostale 90 brakow (starsze, m.in. 47 szt. z 2015-05-15 typu "SSI Try Scuba", "Sportowa
  Fotografia Podwodna" — kursy/importy startowe) **swiadomie POMIJAMY**. Nie embedowac ich.
- **60a:** zombie czyscimy w TYM SAMYM tasku (ta sama tabela, ta sama synchronizacja).
- **65a:** sync API przez `embed_target_products.py --ids`. **NIE uzywac `--full` /
  Batch API** — `--full` przeliczylby CALY katalog (2600+) zeby zaoszczedzic na 50. Odwrotnie
  do intencji. Koszt sync przy 50 produktach = grosze.
- **66b:** zbior zombie wyliczany **DYNAMICZNIE w momencie wykonania**, nie ze sztywnej listy.
  Powod: karta 22 i CHAT-T-140 (Janek) pracuja na tych samych tabelach — miedzy napisaniem
  tasku a wykonaniem produkt moze wrocic do aktywnych. Stale listy rozjezdzaja sie cicho.
- **63/64 — ZERO zmian w `extract_products.py`.** Sprawdzone: wszystkie 50 przechodzi filtr.
  Maski 7615/7616 maja `id_category_default=401` ("Maski jednoszybowe", `active=1`, DOZWOLONA).
  To, ze naleza tez do wykluczonej 461, jest bez znaczenia — `is_allowed()` (~262) patrzy
  **wylacznie na `id_category_default`**. Kategoria 461 ma `active=0` i zwraca 404 na produkcji.
  **Nie ruszac `EXCLUDED_CATEGORY_IDS`.**
- **62b:** automatyzacja = OSOBNA karta 23. Tu NIE refaktorujemy tunelu SSH, NIE stawiamy crona.

---

## KROK 0 — pull + czytaj

```
git pull origin main
```
Przeczytaj (otworz, nie greppuj):
- `embeddings/embed_target_products.py` — CALY (to glowne narzedzie, `--ids`)
- `embeddings/extract_products.py` — `is_allowed()` ~258-266, `open_ssh_tunnel()` ~149-170
- `embeddings/batch_embed_products.py` — `upsert_product()` (uzywany przez embed_target)

## KROK 1 — snapshot PRZED (do porownania w raporcie)

Przez tunel SSH do Railway PG. Zapisz i wypisz w raporcie:
```sql
SELECT count(*) AS wpisow, count(DISTINCT ps_product_id) AS unikalnych,
       min(updated_at)::date, max(updated_at)::date
FROM divechat_product_embeddings;
```
Oczekiwane PRZED: 2561 / 2561 / 2026-02-23 / 2026-05-15. **Jesli liczby sie nie zgadzaja —
STOP i zaraportuj** (znaczy, ze ktos ruszal tabele od czasu pisania tasku).

## KROK 2 — pg_dump tabeli (OBOWIAZKOWY, przed jakimkolwiek DELETE)

Backup calej `divechat_product_embeddings` do pliku lokalnego z data w nazwie
(np. `_backups/divechat_product_embeddings_20260715_przed_T141.sql`).
**Wypisz sciezke i rozmiar pliku w raporcie.** Bez tego kroku nie wolno isc dalej.

## KROK 3 — backfill 50 produktow (sync API)

```
cd embeddings
python embed_target_products.py --ids 7561,7563,7567,7595,7596,7597,7598,7600,7601,7602,7604,7605,7606,7607,7609,7610,7611,7612,7613,7614,7615,7616,7618,7619,7620,7621,7622,7624,7625,7627,7628,7629,7631,7632,7633,7634,7635,7636,7637,7638,7639,7640,7641,7642,7643,7644,7645,7646,7647,7648
```
(50 ID — policz, ze jest ich 50.)

Model: `text-embedding-3-large`, `dimensions=1536` (z `batch_embed_products.py`).
Skrypt robi single-vector (`embedding`) + multi-vector (`embedding_name`/`embedding_desc`/
`embedding_jargon`). Tunel SSH otwiera sam (`open_ssh_tunnel`).

**Jesli ktorykolwiek z 50 zostanie odrzucony przez filtr kategorii — zaraportuj ktory i
dlaczego. Wg mojej weryfikacji nie powinien zaden.**

## KROK 4 — czyszczenie zombie (DYNAMICZNIE, decyzja 66b)

1. Pobierz aktualna liste aktywnych z MySQL:
   `SELECT p.id_product FROM pr_product p JOIN pr_product_shop ps ON p.id_product=ps.id_product
    AND ps.id_shop=1 WHERE ps.active=1`
2. Wylicz zombie: `ps_product_id` z PG, ktorych NIE MA w tym zbiorze.
3. **Wypisz liste zombie PRZED usunieciem** (ID + `product_name` z tabeli).
   Moj pomiar z 2026-07-15 dal **37** — jesli CC wyliczy istotnie inna liczbe, **STOP i zapytaj**.
   Roznica ±kilka = OK (T-140 rusza stanami), roznica rzedu dziesiatek = cos jest nie tak.
4. DELETE wylacznie wyliczonego zbioru.

## KROK 5 — weryfikacja PO

```sql
SELECT count(*), count(DISTINCT ps_product_id), max(updated_at)::date
FROM divechat_product_embeddings;
-- kontrola: czy 50 nowych faktycznie jest
SELECT ps_product_id, product_name, updated_at::date
FROM divechat_product_embeddings
WHERE ps_product_id IN (7647,7648,7641,7646,7561) ORDER BY ps_product_id;
-- kontrola: czy multi-vector wypelniony (nie NULL)
SELECT count(*) FILTER (WHERE embedding IS NULL) AS brak_single,
       count(*) FILTER (WHERE embedding_name IS NULL) AS brak_name,
       count(*) FILTER (WHERE embedding_desc IS NULL) AS brak_desc,
       count(*) FILTER (WHERE embedding_jargon IS NULL) AS brak_jargon
FROM divechat_product_embeddings WHERE ps_product_id BETWEEN 7561 AND 7648;
```
Oczekiwane PO: **2561 + 50 - 37 = 2574** (jesli zombie = dokladnie 37).
**Kazde odchylenie od tej arytmetyki wyjasnij w raporcie** — nie zaokraglaj, nie pomijaj.

## KROK 6 — test semantyczny na zestawach (kryterium akceptacji)

Sprawdz, czy bot faktycznie znajduje nowe zestawy. Uzyj `embeddings/test_search.py`
(lub rownowaznego) na frazach: `"zestaw automatu Apeks MTX-RC"`, `"Scubapro MK17 zestaw"`,
`"Shearwater Perdix 3"`.
**Oczekiwane:** 7647, 7648, 7641 w wynikach. Wypisz similarity.

## KROK 7 — git

`git status` przed commitem. `git add` PER SCIEZKA (nigdy `git add .`):
```
git add _instances/embeddings/tasks/CHAT-T-141_embeddings_backfill-50-produktow-i-zombie.md
```
**NIE commituj:** `_backups/` (pg_dump), `standalone/config/routes.php` (cudza zmiana,
CHAT-T-090 — nie tykac).
Commit wg konwencji z `git log`:
`CHAT-T-141 embeddings: backfill 50 produktow 2026 + usuniecie 37 zombie`
`git push origin main`. Osobny commit `docs:` ze statusem.

## KROK 8 — status update + raport

`_docs/21_STATUS_PROJEKTU.md` (najnowsze na gorze).
Raport MUSI zawierac: snapshot PRZED/PO (liczby), sciezke pg_dump, liste zombie (ID),
liczbe faktycznie zembeddowanych z 50, wynik testu semantycznego (similarity dla 7647/7648),
arytmetyke 2561+50-37=2574 z wyjasnieniem ewentualnych odchylen.

---

## KRYTERIA AKCEPTACJI

1. **7647 i 7648 maja wektor** (`embedding IS NOT NULL`) i wychodza w tescie semantycznym.
2. Wszystkie 50 ID z zakresu ma wpis w `divechat_product_embeddings` (albo jawnie
   wyjasnione, dlaczego ktores nie — z podaniem przyczyny z kodu, nie hipotezy).
3. Multi-vector wypelniony dla nowych (`embedding_name`/`_desc`/`_jargon` NOT NULL).
4. Zombie usuniete, liczba zgodna z wyliczeniem dynamicznym, lista w raporcie.
5. `pg_dump` wykonany PRZED DELETE, sciezka w raporcie.
6. Liczba koncowa zgadza sie arytmetycznie (2574 przy 37 zombie) lub odchylenie wyjasnione.
7. **Zero zmian w `extract_products.py`** i w `EXCLUDED_CATEGORY_IDS`.

## POZA ZAKRESEM (nie robic)

- **90 starszych brakow** (2015-2025) — decyzja 59b, swiadomie pominiete.
- **Automatyzacja / cron / refaktor tunelu SSH** — karta 23, decyzja 62b.
- **`--full` / Batch API** — decyzja 65a.
- Zmiany w `EXCLUDED_CATEGORY_IDS`, `extract_products.py`, filtrze kategorii.
- Jakikolwiek rsync/deploy na serwer. Ten task NIE dotyka zadnego ze swiatow wdrozeniowych.

---

## Wynik (CC, 2026-07-15) — DONE

**Snapshot PRZED:** 2561 / 2561 unikalnych / updated_at 2026-02-23…2026-05-15 — zgodny z zalozeniem.

**KROK 2 backup (przed DELETE):** `_backups/divechat_product_embeddings_20260715_przed_T141.sql`,
205 843 028 B (~196 MB), 2561 wierszy COPY zweryfikowane. Uwaga: lokalny `pg_dump` 14 odrzuca
serwer PG 18.2 (major mismatch) — uzyto binarki `pg_dump` 18.4 z `libpq` (Homebrew Cellar).

**KROK 3 backfill — 42/50 przez standardowy pipeline, 8 odrzuconych przez filtr (WBREW zalozeniu tasku).**
`extract_products()` odfiltrowal 8 ID. Przyczyny z KODU (nie hipotezy):
- **7 × `id_category_default=2` (root „Glowna")**: 7561, 7563, 7567, 7597, 7598, 7640, **7648**.
  `ALLOWED_CATEGORIES_SQL` bierze tylko scislych potomkow roota (`nleft > root.nleft`), wiec produkt
  z domyslna kategoria = root jest odrzucany, mimo ze wszystkie 7 naleza do poprawnych kategorii
  przez `pr_category_product` (7648 → 286 Automaty / 303 SCUBAPRO / 416 Zestawy rekreacyjne).
- **1 × `visibility='none'`**: 7602 (TECLINE R1) — aktywny, w dozwolonych kategoriach, ale ukryty
  z katalogu; odrzuca go `PRODUCTS_SQL` (`ps.visibility != 'none'`).
Wszystkie 8 `active=1`. Zalozenie tasku „wszystkie 50 przejda" bylo bledne (weryfikacja sprawdzila
tylko maski 7615/7616).

**Decyzja Karola (AskUserQuestion):** override-embed wszystkich 8 (7648 wymagany kryterium #1).
Rozwiazanie: jednorazowy skrypt w scratchpadzie (NIE commitowany), ktory **NIE modyfikuje**
`extract_products.py` ani `EXCLUDED_CATEGORY_IDS` — pobiera 8 po ID z pominieciem wylacznie filtru
kategorii/visibility, podmienia `category_name` na najnizsza-id DOZWOLONA realna kategorie, robi
single-vector upsert; multi-vector dorobione `embed_target_products.py --skip-single`. Efekt:
**wszystkie 50 maja wpis, single+name+desc+jargon NOT NULL (0 NULL)**.

**DLUG (osobna karta / decyzja Karola):** trwaly fix = poprawic `id_category_default` tych 7 produktow
w PrestaShop (root → wlasciwa kategoria) + decyzja o `visibility` 7602. Bez fixu zrodla przy nastepnym
pelnym przebiegu pipeline'u wroca do odrzucenia.

**KROK 4 zombie (dynamicznie, decyzja 66b):** aktywnych w sklepie = 2664; zombie = **dokladnie 37**
(zgodne z pomiarem tasku). DELETE dokladnie 37. Lista: 3809, 4373, 4582, 4596, 4742, 4977, 5058,
5069, 5070, 5183, 5506, 5705, 5844, 6191, 6324, 6355, 6356, 6741, 6755, 6880, 6881, 6914, 7084,
7431, 7432, 7433, 7462, 7472, 7473, 7477, 7483, 7564, 7565, 7566, 7568, 7572, 7584. Zaden z 50
targetow ani 8 override NIE byl zombie.

**KROK 5 PO:** 2574 / 2574 / max updated_at 2026-07-15. Arytmetyka DOKLADNA: 2561 + 50 − 37 = 2574.
Multi-vector NULL w zakresie 7561–7648: single=0 name=0 desc=0 jargon=0.

**KROK 6 test semantyczny (real bot path = multi-vector; `ProductSearch.php` odpytuje `embedding_name`/
`_desc`/`_jargon`, NIE single `embedding`):**
- „zestaw automatu Apeks MTX-RC" → **7647** name rank3 (0.774), jargon rank1 (0.768)
- „Scubapro MK17 zestaw" → **7648** name rank2 (0.861), jargon rank1 (0.860)
- „Shearwater Perdix 3" → **7641** rank1 wszedzie (0.914)
Kryterium akceptacji #1 (7647 i 7648 wychodza) SPELNIONE. Na nieuzywanej przez bota kolumnie single
`embedding` 7648 jest poza top5 (outrankowany przez warianty MK17 EVO) — bez znaczenia, bot tej kolumny
do produktow nie odpytuje.

**Kryteria akceptacji:** 1 ✅, 2 ✅ (50/50 z wpisem, 8 override jawnie wyjasnione), 3 ✅, 4 ✅ (37, lista),
5 ✅ (pg_dump przed DELETE), 6 ✅ (2574), 7 ✅ (zero zmian w `extract_products.py`/`EXCLUDED_CATEGORY_IDS`).
