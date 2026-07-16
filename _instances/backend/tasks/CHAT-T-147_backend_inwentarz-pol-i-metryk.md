# CHAT-T-147 (backend): inwentarz pól i metryk — `_docs/44_slownik_pol_i_metryk.md`

**Instancja:** backend
**Karta Trello:** Chat - 33
**Swiat:** ZADEN. To dokumentacja. Zero zmian w kodzie, zero deployu, zero rsync.
**Decyzje Karola:** 116a, 117a

---

## PO CO TO — przeczytaj, bo to zmienia sposob pisania

Architekt w jednej sesji (2026-07-15/16) popelnil **szesc bledow tego samego typu**: wzial
**nazwe pola za jego znaczenie**. Kazdy kosztowal czas Karola. Kazdy bylby wyeliminowany
jednym akapitem dokumentacji:

| zalozenie architekta | stan faktyczny |
|---|---|
| `visibility='none'` = produkt ukryty | wyszukiwarka sklepu to **Luigi's Box**, ktora IGNORUJE to pole — produkt jest wyszukiwalny |
| `pr_orders.valid=0` = niezaplacone | to flaga ksiegowa; zaplata = `current_state` → `pr_order_state.paid` |
| `total_paid_real` = ile wplynelo | zawyzone **2x** dla 1246/1259 zamowien Tpay |
| `pr_stock_available.quantity` = stan magazynowy | zaslepki (9999999, 29998); zrodlo prawdy to **Subiekt** |
| `similarity` w tool_result = cosine (0-1) | to **`rrf_score`**, skala ~0-0.065 przy 4 torach |
| „sprzedaz" = kiedykolwiek | pytanie bylo o „ostatnio" — 344/483 nie sprzedane od roku |

**Sprawdzone:** `rrf_score` i `knowledge_gap` NIE wystepuja w `_docs/00_architektura_projektu.md`
ani w `CLAUDE.md` (zero trafien). `14_architektura_wyszukiwania_rozwiazanie.md` opisuje RRF,
ale to dokument **projektowy sprzed wdrozenia** — nie wiadomo, ile z tego jest w kodzie.

**Ten dokument ma opisywac STAN FAKTYCZNY, nie projektowany.** Roznica jest calym sensem
zadania.

---

## ZASADA NACZELNA — twarda

**Kazde twierdzenie ma miec zrodlo:** sciezka + numer linii, albo nazwa tabeli + kolumna,
albo wynik zapytania. **Zero „prawdopodobnie", zero „powinno", zero wnioskowania z nazwy.**

Jesli czegos nie ustalisz z kodu lub bazy — napisz wprost **„NIE USTALONO"** i wyjasnij,
czego brakuje. To jest **poprawny wynik**, nie porazka. Zgadywanie jest porazka.

Nie opisuj, jak cos **powinno** dzialac wg ADR-a. Opisuj, jak **dziala**. Gdy kod rozjezdza
sie z ADR-em — **zglos to jawnie**, to najcenniejsze znalezisko, jakie mozesz zrobic.

---

## KROK 0 — pull + rozpoznanie

```
git pull origin main
```
Przeczytaj: `CLAUDE.md`, `_docs/00_architektura_projektu.md`, `_docs/02_schemat_bazy.md`.
Nie musisz czytac calego `10_decyzje_projektowe.md` (390 KB) — ale przeczytaj **ADR-122
(nota nr 3), ADR-123 + nota 93a, ADR-125**. Tam sa opisane trzy z szesciu pulapek.

## KROK 1 — inwentarz: PostgreSQL (Railway)

Dla **kazdej** tabeli `divechat_*`: nazwa, przeznaczenie, kolumny (nazwa, typ, znaczenie,
skala/jednostka jesli liczba, wartosci dopuszczalne jesli enum).

Szczegolna uwaga:
- **`divechat_conversations`** — kolumny mylace: `started_at` (NIE `created_at` — tej nie ma),
  `chip_path` vs `nudge_sid` (roznica: chip = drzewo chipow, nudge = zaczepka proaktywna),
  `knowledge_gap` (patrz KROK 4), `admin_status`, `search_diagnostics`
- **`divechat_conversation_review`** — OSOBNA tabela; `note`, `verdict`, `status`, `updated_by`.
  Jakie wartosci przyjmuja `verdict` i `status` (wypisz WSZYSTKIE z bazy, nie z kodu)
- **`divechat_product_embeddings`** — `ps_product_id` (NIE `product_id`), cztery kolumny
  wektorowe: `embedding`, `embedding_name`, `embedding_desc`, `embedding_jargon` — **z czego
  kazda jest budowana** (`embed_target_products.py` `build_multivector_texts()` ~74-93)
- reszta tabel `divechat_*`

## KROK 2 — inwentarz: MySQL PrestaShop (read-only)

Tylko pola, ktorych czat faktycznie uzywa. Dla kazdego: gdzie jest czytane (plik+linia),
co znaczy, jakie sa **pulapki**.

Obowiazkowo udokumentuj (to zrodla realnych bledow):
- `pr_product_shop.visibility` — **NIE jest kryterium widocznosci dla klienta** (Luigi's Box)
- `pr_product_shop.available_for_order` — **wlasciwe** kryterium „czy mozna kupic" (ADR-123)
- `pr_product_shop.active`
- `pr_stock_available.quantity` + `out_of_stock` — wartosci 0/1/2, co znaczy `2`;
  **zaslepki** w `quantity`; zrodlo prawdy = Subiekt
- `pr_orders.valid` vs `current_state` → `pr_order_state.paid` — **co naprawde znaczy zaplacone**
- `pr_orders.total_paid` vs `total_paid_real` — **ktore liczyc i dlaczego** (Tpay 2x)

## KROK 3 — kontrakty tool_result

Dla **kazdego** narzedzia (`standalone/config/tools.php` + `standalone/src/Tools/`):
nazwa, parametry wejsciowe (z domyslnymi), **dokladny ksztalt zwracanego JSON-a**, znaczenie
kazdego klucza.

**KRYTYCZNE — pole `similarity`:** udokumentuj jawnie, ze w `search_products` zawiera
**`rrf_score`**, nie cosine. Podaj: wzor RRF (`1/(k+rank)`), wartosc `rrf_k` (znajdz w kodzie),
liczbe torow, **realny sufit skali**. Przyklad z produkcji do zacytowania: conv 636, produkt
5560 mial `name_rank=1, jargon_rank=1, trigram_rank=1, desc_rank=4` → `rrf_score = 0.0713`
— czyli **wynik najlepszy z mozliwych**, a wyglada jak „7% dopasowania".

Sprawdz, czy `get_expert_knowledge` zwraca w polu `similarity` **prawdziwy cosine** (podejrzenie:
tak — i to dlatego prog 0.5 tam dziala, a w `search_products` nie). Ustal to z kodu.

## KROK 4 — `knowledge_gap`: opisz mechanizm I JEGO BLAD

Architekt zdiagnozowal (zweryfikuj i uzupelnij):
- `ChatService::buildSearchDiagnostic()` ~447: `$gap = empty($items) || ($maxSim !== null && $maxSim < $threshold)`
- prog `knowledge_gap_threshold` = **0.5** (~418) — skala **cosine**
- ale `search_products` zwraca w `similarity` **`rrf_score`** (sufit ~0.065)
- => `maxSim < 0.5` jest **ZAWSZE prawda** dla `search_products`
- `ConversationStore` ~189: `knowledge_gap = (? ::boolean OR COALESCE(knowledge_gap, false))`
  — flaga **sticky**, raz zapalona nie gasnie
- zmierzone na PROD (30 dni): **126 true / 91 false**. Hipoteza architekta: `false` maja tylko
  rozmowy **bez** `search_products`. **ZWERYFIKUJ TO ZAPYTANIEM**, nie przyjmuj na wiare.

**Ustal i udokumentuj: KTO tej flagi uzywa.** Panel PS? Filtr w `ConversationStore::list`
(~225-227)? Nudge? Raport? SystemPrompt? Od tego zalezy, czy naprawa jest warta pracy.
To wejdzie do osobnej decyzji (115a) — Ty tylko **ustalasz fakty**, nie naprawiasz.

## KROK 5 — przeplyw zapytania (end-to-end)

Sciezka od wiadomosci klienta do odpowiedzi: widget → `/api/chat` → `ChatService` → wybor
modelu (**panel PS jest zrodlem prawdy, NIE `.env`** — CHAT-T-068, sprawdz ~133-136) →
tool calls → `ProductSearch` (tory: name/desc/jargon/trigram/fts — ile ich realnie jest?) →
RRF merge → filtry post-hoc z MySQL (`in_stock_only`, `include_discontinued`) → enrichment
→ odpowiedz.

Dla kazdego etapu: plik + linie. **Gdzie dokladnie** dzieje sie filtrowanie (pgvector czy
post-hoc z MySQL — to nie to samo i architekt sie na tym przejechal).

## KROK 6 — sekcja „ROZJAZDY KOD vs ADR"

Wszystko, co znajdziesz, a co nie zgadza sie z dokumentacja. Format: co mowi ADR, co jest
w kodzie, plik+linia. **Nie naprawiaj — tylko zglos.**

## KROK 7 — zapis

`_docs/44_slownik_pol_i_metryk.md`. Struktura:
```
1. Po co ten dokument (i szesc bledow, ktore go wymusily)
2. PostgreSQL (Railway) — tabele i kolumny
3. MySQL PrestaShop — pola uzywane przez czat
4. Kontrakty tool_result
5. Metryki i ich skale (RRF vs cosine — sekcja krytyczna)
6. Przeplyw zapytania end-to-end
7. Rozjazdy kod vs ADR
8. NIE USTALONO — czego nie udalo sie ustalic i dlaczego
```
Sekcje „PULAPKI" **NIE piszesz** — dopisze ja architekt na bazie Twojego inwentarza
(decyzja 117a).

## KROK 8 — git

`git status`. `git pull --rebase origin main` (inne okna pracuja rownolegle).
`git add` PER SCIEZKA:
```
git add _docs/44_slownik_pol_i_metryk.md
git add _instances/backend/tasks/CHAT-T-147_backend_inwentarz-pol-i-metryk.md
```
**NIE commituj:** `_docs/10_decyzje_projektowe.md` (ADR-y pisze architekt), `_backups/`,
`standalone/config/routes.php`, `_diag_local/`.
Commit: `docs(CHAT-T-147): inwentarz pol i metryk czatu (44_slownik_pol_i_metryk)`
Push odrzucony → `git pull --rebase`, push ponownie.

## KROK 9 — raport

Ile pol udokumentowanych, ile rozjazdow kod↔ADR, lista „NIE USTALONO", odpowiedz na pytanie
z KROKU 4 (kto uzywa `knowledge_gap`).

---

## KRYTERIA AKCEPTACJI

1. **Kazde** twierdzenie ma zrodlo (plik+linia / tabela+kolumna / wynik zapytania).
2. Sekcja o `similarity` = `rrf_score` z wzorem, `rrf_k`, sufitem skali i przykladem 5560.
3. `knowledge_gap`: mechanizm + prog + skala + sticky + **kto uzywa** (ustalone, nie zgadniete).
4. Szesc pulapek z naglowka udokumentowanych w sekcjach 2-5.
5. Sekcja „NIE USTALONO" **istnieje** (jesli pusta — uzasadnij, ze naprawde wszystko ustalono).
6. Zero zmian w kodzie. Zero deployu.
7. Dokument opisuje **stan faktyczny**, nie projektowany — rozjazdy jawnie zgloszone.

## POZA ZAKRESEM

- **Naprawa** `knowledge_gap` (decyzja 115a, osobny task po Twoim raporcie).
- Naprawa czegokolwiek innego, co znajdziesz — **zglos, nie naprawiaj**.
- Sekcja „PULAPKI" — pisze architekt (117a).
- Encyklopedia, atrybucja (T-146), embeddingi (T-145).
