# 44. Słownik pól i metryk czatu — stan faktyczny

**Wersja:** 1.0 | **Data:** 2026-07-16 | **Autor:** Claude Code (instancja backend) | **Task:** CHAT-T-147
**Decyzje Karola:** 116a (utworzenie), 117a (sekcja „PUŁAPKI" dopisze architekt)

> **Zasada tego dokumentu:** każde twierdzenie ma źródło — plik+linia, tabela+kolumna
> albo wynik zapytania. Opisuje **stan faktyczny kodu i bazy na 2026-07-16**, nie stan
> projektowany w ADR-ach. Gdzie kod rozjeżdża się z dokumentacją — zgłoszone w sekcji 7.
> Czego nie udało się ustalić — w sekcji 8 („NIE USTALONO").
>
> Weryfikacja PostgreSQL: Railway `switchback.proxy.rlwy.net:14368/railway` (jedyna żywa
> baza czatu — Aiven martwy). Weryfikacja MySQL: przez odczyt kodu `standalone/src/`
> (baza `divezone_2025`, read-only). Numery linii wg stanu repo na commicie roboczym T-147.

---

## 1. Po co ten dokument (i sześć błędów, które go wymusiły)

Architekt w jednej sesji (2026-07-15/16) sześć razy **wziął nazwę pola za jego znaczenie**.
Każdy błąd kosztował czas i każdy byłby wyeliminowany jednym akapitem tego słownika:

| # | Założenie z nazwy | Stan faktyczny | Sekcja |
|---|---|---|---|
| 1 | `visibility='none'` = produkt ukryty | Wyszukiwarką sklepu jest **Luigi's Box**, która IGNORUJE to pole. Kryterium „czy bot poleca" to `available_for_order` (ADR-122 nota 3, ADR-123). | 3.1 |
| 2 | `pr_orders.valid=0` = niezapłacone | `valid` to flaga księgowa („zamówienie ważne"). Zapłata = `current_state` → `pr_order_state.paid`. | 3.2 |
| 3 | `total_paid_real` = ile wpłynęło | **Zawyżone 2× dla 1246/1259 zamówień Tpay** (moduł zapisuje płatność dwa razy). Liczyć `total_paid` (ADR-124). | 3.2 |
| 4 | `pr_stock_available.quantity` = stan magazynowy | Bywa zaślepką (9999999, 29998). Źródłem prawdy o stanie jest **Subiekt**. Czat czyta stan tylko jako 0/>0. | 3.3 |
| 5 | `similarity` w `search_products` = cosine (0–1) | To **`rrf_score`** (Reciprocal Rank Fusion), skala ~0–0.066. Wynik idealny wygląda jak „7% dopasowania". | 4.1, 5 |
| 6 | „sprzedaż" = kiedykolwiek | Dane sprzedażowe są **oknem czasowym** (PopularProducts: domyślnie 6 mies.; `divechat_product_sales_popularity`: 12 mies. z Subiekta). | 3.2, 4.6 |

**Sprawdzone przed napisaniem:** `rrf_score` i `knowledge_gap` NIE występowały wcześniej ani
w `_docs/00_architektura_projektu.md`, ani w `CLAUDE.md`. `14_architektura_wyszukiwania_rozwiazanie.md`
opisuje RRF, ale jest to dokument **projektowy sprzed wdrożenia**. Ten dokument opisuje kod.

---

## 2. PostgreSQL (Railway) — tabele i kolumny

Baza `railway`, 30 tabel `divechat_*` + `encyclopedia_chunks`. Wszystkie kolumny `vector`
to **`vector(1536)`**, wyjątek: `encyclopedia_chunks.embedding = vector(3072)` (zweryfikowane
`format_type` w `pg_attribute`). Model embeddingów: **OpenAI `text-embedding-3-large`**
(`EmbeddingService.php:44`), NIE Gemini jak pisze `02_schemat_bazy.md` (rozjazd R-1, sekcja 7).

Kolumna „ref w kodzie" = liczba plików w `standalone/src`+`config`, które nazwę tabeli
zawierają (grep). `0` = tabela nieużywana przez backend czatu (zasilana/czytana gdzie indziej).

### 2.1 `divechat_conversations` — historia rozmów (644 wiersze) — ref: 7

Rdzeń panelu recenzji. Zapis: `ConversationStore::startOrResume`/`save`.

| kolumna | typ | znaczenie / pułapka |
|---|---|---|
| `id` | serial PK | klucz rozmowy; FK z `divechat_message_usage`, `divechat_conversation_review` |
| `session_id` | varchar(64) | UUID v4 (lub legacy 32-hex); sekret sesji gościa (dec. 145a) |
| `ps_customer_id` | int NULL | id klienta PS; 0/NULL = gość |
| `messages` | jsonb `[]` | **LEGACY** pełna historia (system-filtered). Dual-write z `divechat_messages`; bywa niespójne — patrz [[dual-write-jsonb-bug]] |
| `tools_used` | jsonb `[]` | tablica **nazw** narzędzi, np. `["get_expert_knowledge","search_products"]` (string, nie obiekt) |
| `tokens_input` / `tokens_output` | int | tokeny sumaryczne (aktualizuje `UsageLogger`) |
| `estimated_cost` | numeric(8,6) | koszt USD rozmowy |
| **`started_at`** | timestamptz | **czas powstania rozmowy — NIE `created_at` (tej kolumny NIE MA)** |
| `updated_at` | timestamptz | ostatnia aktywność (panel sortuje po tym) |
| `closed_at` | timestamptz NULL | zamknięcie; `IS NULL` = aktywna (warunek resume) |
| `model_used` | varchar(64) | model ostatniej odpowiedzi |
| `response_times` | jsonb | `{ai_ms, tool_ms, embedding_ms, total_ms}` (`ChatService.php:131,370`) |
| `search_diagnostics` | jsonb | tablica diagnostyk per wywołanie search-toola; kształt: `buildSearchDiagnostic` (`ChatService.php:448-467`) |
| **`knowledge_gap`** | bool `false` | flaga „luka wiedzy" — **mechanizm i błąd w sekcji 4.7**; STICKY (`ConversationStore.php:189`) |
| `admin_status` | varchar(20) `new` | **legacy** oś statusu; w bazie tylko `new` (626) / `reviewed` (18). Oddzielna od `divechat_conversation_review` (ADR-102) |
| `admin_notes` | text NULL | notatka legacy panelu |
| `cache_read_tokens` / `cache_creation_tokens` | int | tokeny cache Claude |
| **`nudge_sid`** | varchar(64) NULL | atrybucja **zaczepki proaktywnej** (nudge); UUID z ekspozycji nudge; zapisywany TYLKO przy INSERT rozmowy (ADR-091). Rozłączny z `chip_path` |
| **`chip_path`** | jsonb NULL | strukturalna ścieżka **klikniętych chipów**; kształt: `[{"node_key","label","level"}]` — np. `[{"label":"Dobór sprzętu","level":2,"node_key":"dobor"},{"label":"Komputer nurkowy","level":3,"node_key":"komputer"}]` (ADR-110). Utrwalany raz na rozmowę |

**`nudge_sid` vs `chip_path` (częsta pomyłka):** `nudge_sid` to atrybucja *skąd klient wszedł*
(proaktywna zaczepka), zwykły UUID. `chip_path` to *którą gałąź drzewa chipów kliknął* —
lista węzłów. To dwa różne mechanizmy, nie warianty jednego.

### 2.2 `divechat_conversation_review` — recenzja rozmów (177) — ref: 4

OSOBNA tabela od `divechat_conversations` (ADR-102). Oś pracy recenzenta w panelu PS.

| kolumna | typ | wartości (z **bazy**, nie kodu) |
|---|---|---|
| `id` | serial PK | |
| `conversation_id` | int | FK → `divechat_conversations.id` |
| `status` | text, default `do_weryfikacji` | **`zamkniety` (119), `do_weryfikacji` (50), `nowy` (8)**. Enum kodu `ReviewStatus` ma dodatkowo `w_trakcie` (0 wierszy w bazie) — `ReviewStatus.php:14-17` |
| `verdict` | text NULL | **NULL (79), `problem_rozwiazany` (55), `problem_do_rozwiazania` (34), `ok` (9)**. Enum `ReviewVerdict.php:17-19` (bez NULL) |
| `note` | text NULL | notatka recenzenta |
| `updated_by` | int NULL | id pracownika PS |
| `created_at` / `updated_at` | timestamptz | |

### 2.3 `divechat_product_embeddings` — embeddingi produktów (2606) — ref: 2

Zasilana pipeline'em Python (`embeddings/`), czytana przez `ProductSearch`. **Klucz produktu
to `ps_product_id` (NIE `product_id`).**

| kolumna | typ | znaczenie |
|---|---|---|
| `id` | serial PK | |
| **`ps_product_id`** | int UNIQUE | id produktu PrestaShop |
| `product_name` | text | nazwa (zasila wektor `name`) |
| `product_description` | text NULL | opis (zasila wektor `desc`) |
| `category_name` | text NULL | **konkatenacja** kategorii `" + "` (ADR-122); zasila wektor `desc` (patrz niżej) |
| `parent_category_name` | varchar(255) NULL | kategoria-rodzic (filtr ADR-027) |
| `brand_name` | text NULL | marka (zasila wektor `name`) |
| `features` | jsonb NULL | cechy (zasilają wektor `desc`) |
| `search_phrases` | jsonb `[]` | frazy języka klienta (zasilają wektor `jargon`) |
| `synonyms_pl` / `synonyms_en` | jsonb `[]` | synonimy |
| `price` | numeric(10,2) NULL | cena z pipeline'u (**nieaktualna** — realna cena z MySQL enrich) |
| `is_active` | bool | jedyny filtr strukturalny w pgvector (`buildFilters`, `ProductSearch.php:526`) |
| `in_stock` | bool | stan z pipeline'u (**nieaktualny** — realny z MySQL) |
| `product_url` / `image_url` | text NULL | |
| `document_text` | text | pełny tekst (zasila **single-vector** `embedding`) |
| `fts_vector` | tsvector NULL | indeks full-text (tor FTS) |
| `embedding` | vector(1536) NULL | single-vector z `document_text` |
| `embedding_name` | vector(1536) NULL | patrz tabela źródeł niżej |
| `embedding_desc` | vector(1536) NULL | |
| `embedding_jargon` | vector(1536) NULL | |

**Z czego budowana jest KAŻDA kolumna wektorowa** — `embed_target_products.py`,
`build_multivector_texts()` (linie 74–93):

| kolumna | źródło (dokładnie) | linie |
|---|---|---|
| `embedding_name` | `product_name + " " + brand_name` — **BEZ kategorii** | 76–79 |
| `embedding_desc` | `"Kategoria: {category_name}. {product_description[:500]}. Cechy: {k: v, …}"` — **kategoria TYLKO tu** | 81–89 |
| `embedding_jargon` | `", ".join(search_phrases)` — **BEZ kategorii** | 91 |
| `embedding` (single) | pełny `document_text` | (etap single-vector) |

To jest sedno ADR-125: konkatenacja kategorii (ADR-122) poprawia **tylko tor `desc`**;
`name` i `jargon` jej nie zawierają (Δ=0.0000 w bramie pomiarowej).

### 2.4 Pozostałe tabele `divechat_*`

| tabela | wiersze | ref | przeznaczenie / źródło (plik) |
|---|---|---|---|
| `divechat_messages` | 5722 | 4 | dual-write historii (role/content/tool_calls/rating); `ConversationStore::appendMessage` |
| `divechat_message_usage` | 2711 | 6 | audyt tokenów+kosztu per wywołanie providera; `UsageLogger` |
| `divechat_settings` | 16 | 4 | strojenie panelu PS (klucz→jsonb); `SettingsStore`. Klucze: `model_primary`, `model_escalation`, `knowledge_gap_threshold`, `temperature`, `reasoning_effort`, `max_tokens`, `emoji_enabled`, `ai_provider`, `protect_*` (7 kluczy ochrony) |
| `divechat_model_pricing` | 9 | 3 | cennik + flagi modeli (`is_active`, `is_escalation`, `supports_temperature`, `supports_reasoning_effort`); `PricingService` |
| `divechat_exchange_rates` | — | 2 | kursy USD→PLN (koszt); `ExchangeRateService` |
| `divechat_synonyms` | 189 | 1 | ekspansja FTS (canonical↔synonym); `SynonymExpander` (`divechat_synonyms`, `SynonymExpander.php:118`) |
| `divechat_curated_recommendations` | 10 | 2 | ręczne rekomendacje ekspertów; `CuratedRecommendations` |
| `divechat_editorial_picks` | 2 | 1 | boost rankingu (`boost_factor` 1.0–2.5, ADR-054); `EditorialPicksService` |
| `divechat_product_sales_popularity` | 1511 | 1 | popularność z **Subiekt CSV** (`source='subiekt_csv'`, `qty_12m`, `net_value_12m`, `period_label`) — **okno 12 mies.** |
| `divechat_chip_nodes` | 32 | 3 | drzewo chipów (bot_text/buttons/ai_prompt/label); `ChipTreeService` |
| `divechat_nudge_events` | 119925 | 3 | telemetria zaczepek (event_type/bucket/ab_active); `NudgeEventStore` |
| `divechat_shipping_rates` | 4 | 1 | koszty dostawy (zone PL/EU); `ShippingInfo` |
| `divechat_shop_config` | 18 | 3 | konta bankowe + linki + progi darmowej wysyłki (key→value); `GetShopLinks`, `ShippingInfo` |
| `divechat_shop_calendar_overrides` | — | 1 | wyjątki kalendarza sklepu; `ShopCalendar`/`DbOverrideProvider` |
| `divechat_mobile_sessions` | — | 1 | sesje aplikacji mobilnej pracownika; `MobileSessionStore` |
| `divechat_admin_roles` | — | 11 | role pracowników w panelu; middleware/kontrolery admin |
| `divechat_rate_limit` | — | 1 | licznik rate-limitu (key/window/count); `RateLimiter` |
| `divechat_cost_alerts` | — | 1 | dedup alertów kosztowych; `CostGuard` |
| `divechat_db_alerts` | — | 1 | dedup alertów awarii DB (ADR-088); `DbHealthAlert` |
| `divechat_brand_blacklist` | — | 1 | marki wycinane z wyników; `ProductSearch::getBrandBlacklist` |
| `divechat_size_charts` / `_size_chart_rows` / `_product_size_chart` / `_size_label_alias` | 5/…/… | **0** | tabele rozmiarówek na PG — **nieużywane przez backend** (SizeRecommender czyta MySQL `divezone_attr_*`, patrz 4.7). Rozjazd R-4 |
| `divechat_category_aliases` | — | **0** | aliasy kategorii — **nieużywane w `standalone/src`** (pipeline embeddingów) |
| **`divechat_knowledge`** | 37 | **0** | Q&A z embeddingiem — **NIEUŻYWANE przez kod czatu**. `get_expert_knowledge` czyta `encyclopedia_chunks`, nie tę tabelę. Rozjazd R-2 |
| `divechat_product_embeddings_backup_20260514` | — | 0 | backup |

### 2.5 `encyclopedia_chunks` — encyklopedia sprzętu (530 chunków, 106 haseł) — ref: 1

Faktyczne źródło narzędzia `get_expert_knowledge` (NIE `divechat_knowledge`).

| kolumna | typ | znaczenie |
|---|---|---|
| `id` | int PK | |
| `concept_key` | varchar | klucz hasła, np. `AUTOMAT_ODDECHOWY` (filtr opcjonalny) |
| `concept_number` | int | |
| `name_pl` / `name_en` | varchar | nazwa hasła |
| `chunk_type` | varchar | `definition`/`synonyms`/`purchase`/`faq`/`seller` (`ExpertKnowledge.php:49`) |
| `content` | text | treść zwracana do bota |
| **`embedding`** | **vector(3072)** | cosine; `ExpertKnowledge` embeduje zapytanie z `dimensions=3072` (`ExpertKnowledge.php:16,75`) |
| `metadata` | jsonb | |

---

## 3. MySQL PrestaShop — pola używane przez czat (read-only, baza `divezone_2025`, prefix `pr_`)

Główny konsument: `MysqlProductEnrichmentService::enrich()` — cena/dostępność real-time
dla `ProductSearch`, `ProductDetails`, `CuratedRecommendations`, `PopularProducts`.
Wszystkie zapytania na `id_shop = 1`, języki `id_lang = 1` (PL), `id_lang = 3` (EN).

### 3.1 Dostępność i widoczność produktu — sześć pól, trzy różne znaczenia

| pole (tabela) | czytane w | co ZNACZY | pułapka |
|---|---|---|---|
| `pr_product_shop.active` | `enrich` (`MysqlProductEnrichmentService.php:90`) → `active` | produkt włączony w sklepie | filtr twardy we WSZYSTKICH narzędziach (nieaktywny = out) |
| **`pr_product_shop.visibility`** | `enrich:91` → `visible = (visibility !== 'none')` (`:152`) | pozycja w katalogu/menu **PrestaShop** | **NIE jest kryterium widoczności dla klienta** — wyszukiwarką sklepu jest **Luigi's Box**, która ignoruje to pole (ADR-122 nota 3). Produkt `vis='none'` jest normalnie wyszukiwalny i kupowalny |
| **`pr_product_shop.available_for_order`** | `enrich:92` → `available_for_order` (`:155`) | **czy da się kupić** (szary przycisk „Dodaj do koszyka" + „Powiadom mnie") | **właściwe** kryterium „czy bot poleca" (ADR-123). `afo=0` = wycofany ze sprzedaży. Skala PROD: 520 aktywnych `afo=0`, z czego 472 mają `vis='none'`, ale **47 ma `vis='both'`** (widoczne, lecz niekupowalne) i 11 odwrotnie — pola **się nie pokrywają** |

**Konsekwencja praktyczna:** kryterium „czy produkt kwalifikuje się do polecenia" = `active`
**AND** `available_for_order`. `visibility` **przestało** być kryterium w `ProductSearch`
(ADR-123 nota 93a), ale **nadal** filtruje w `CuratedRecommendations:135` i `PopularProducts:258`
(rozjazd R-3, sekcja 7).

### 3.2 Zamówienia — `valid`, `paid`, `total_paid`, `total_paid_real`

`pr_orders` czytane w dwóch miejscach: `OrderStatus` (status dla klienta) i `PopularProducts`
(agregat sprzedaży). Pola i pułapki:

| pole | czytane w | co ZNACZY | pułapka |
|---|---|---|---|
| `pr_orders.current_state` | `OrderStatus.php:60,65` (JOIN `pr_order_state_lang`) | aktualny stan zamówienia (nazwa) | **właściwe** źródło „czy zapłacone": `current_state` → `pr_order_state.paid` (flaga stanu). NIE `valid` |
| `pr_orders.valid` | `PopularProducts.php:314` (`o.valid = 1`) | flaga **księgowa**: „zamówienie ważne" (liczy się do statystyk/stanów) | **`valid=0` ≠ niezapłacone.** Tu użyte poprawnie: liczy tylko ważne zamówienia do rankingu sprzedaży |
| `pr_orders.total_paid` | `OrderStatus.php:59,98` → `total` | kwota brutto zamówienia | **liczyć TO** przy atrybucji/przychodzie (ADR-124) |
| **`pr_orders.total_paid_real`** | **nigdzie w kodzie czatu** (0 trafień w `standalone/src`) | ile realnie wpłynęło | **zawyżone 2× dla 1246/1259 zamówień Tpay** (moduł Tpay zapisuje płatność dwa razy — ADR-124). Nie używać do sum przychodu. Pułapka dotyczy zapytań analitycznych/atrybucji (świat SHOP), nie kodu backendu — patrz też sekcja 8 |

**Pułapka #6 („sprzedaż = kiedykolwiek"):** dane sprzedażowe są **oknem czasowym**.
`PopularProducts` liczy `SUM(product_quantity)` z okna `DATE_SUB(NOW(), INTERVAL ? MONTH)`,
domyślnie 6 miesięcy (`PopularProducts.php:57,315`). `divechat_product_sales_popularity`
to okno **12 miesięcy** z Subiekta (`net_value_12m`, `qty_12m`, `period_label`). Pytanie
„czy się sprzedaje" bez okna jest niedookreślone.

### 3.3 Stan magazynowy — `pr_stock_available.quantity` + `out_of_stock`

Czytane w `enrich` (`MysqlProductEnrichmentService.php:98-104`) jako podzapytanie
`MAX(quantity) AS total_qty, MAX(out_of_stock) AS allow_oos` grupowane po `id_product`.

- **`quantity`** → `enrich` mapuje na `quantity` (int) i `in_stock = (availability !== 'unavailable')`.
  **Pułapka:** wartości w `quantity` bywają **zaślepkami** (9999999, 29998) — źródłem prawdy
  o stanie jest **Subiekt**, nie PrestaShop. Czat traktuje stan binarnie (`>0` vs `0`), więc
  zaślepka „działa" jako „jest na stanie", ale liczba nie ma sensu. `get_product_details`
  zwraca **surowe `quantity`** (`ProductDetails`, klucz `quantity`) — tu zaślepka wypływa do bota.
- **`out_of_stock`** — 3 stany PrestaShop (komentarz `MysqlProductEnrichmentService.php:76`,
  `CASE` w `:83-89`):
  - `0` = **deny** (gdy `quantity=0` → `unavailable`, nie da się zamówić),
  - `1` = **allow** (gdy `quantity=0` → `available_to_order`, „zamówimy"),
  - `2` = **use default** — bierze globalny `PS_ORDER_OUT_OF_STOCK` z `pr_configuration` (`:67-71`).
- `availability` (string) ma 3 wartości: `in_stock` / `available_to_order` / `unavailable`.
  To NIE to samo co `available_for_order`: `quantity=0` = „chwilowo nie ma, sprowadzimy",
  `afo=0` = „wycofany na zawsze". Bot mówi o nich inaczej (ADR-123 dec. 91a).

### 3.4 Cena — netto → brutto + promocje

- `pr_product_shop.price` (netto) + `pr_tax` (stawka VAT, JOIN po `id_country=14` = Polska,
  fallback 23%) → brutto (`computeBruttoPrice`, `MysqlProductEnrichmentService.php:226`).
- `pr_specific_price` (`fetchSpecificPrices`, `:274-315`): rabaty; priorytet PS `id_shop>0`,
  `id_group>0`. Reguła `reduction_tax` (ADR-093): rabat kwotowy brutto vs netto liczony różnie.
- EUR: `pr_currency.conversion_rate` dla `id_currency=2` (`:198`) → `price_eur = brutto × kurs`.

### 3.5 Pozostałe pola MySQL używane przez czat

| pole (tabela) | czytane w | znaczenie |
|---|---|---|
| `pr_product_lang.name` / `.link_rewrite` (id_lang 1 PL, 3 EN) | enrich `:93-95,108-111`, wiele narzędzi | nazwa + slug (URL) |
| `pr_manufacturer.name` | ProductDetails, PopularProducts, ProductCombinations | marka |
| `pr_order_state_lang.name` (id_lang=1) | OrderStatus `:65,78` | nazwa statusu zamówienia |
| `pr_order_history`, `pr_order_carrier`, `pr_carrier` | OrderStatus `:75-92` | historia statusów + numer przesyłki |
| `pr_feature_product` + `_lang`, `pr_feature_value_lang` | ProductDetails | cechy produktu |
| `pr_image` (cover=1) | ProductDetails | zdjęcie |
| `pr_product_attribute` + `_combination`, `pr_attribute(_group)_lang`, `pr_product_attribute_image` | ProductCombinations | warianty (kolor×rozmiar) |
| `divezone_attr_size_charts` / `_size_chart_rows` / `_product_chart` / `_size_label_alias` / `divezone_attr_color` | SizeRecommender, ProductCombinations | **rozmiarówki na MySQL** (nie PG!) + czytelne nazwy kolorów |
| `pr_category_product`, `pr_product.date_add` | PopularProducts | kategoria + data dodania (nowości <90 dni) |

---

## 4. Kontrakty tool_result

12 narzędzi zarejestrowanych w `standalone/config/tools.php`. **Uwaga:** repozytoryjny
`tools.php` rejestruje `ProductCombinations` (linia 42) — narzędzie **celowo niewdrożone na PROD**
(czeka na kolumnę `nazwa_pl`); deploy 1:1 tego pliku = fatal 500 (patrz [[tools-php-drift-t129]]
i rozjazd R-5). Poniżej faktyczny kod narzędzi w repo.

Do LLM trafia `tool_result` **bez klucza `search_debug`** (`ChatService.php:283-284` usuwa go
przed wysłaniem — diagnostyka zbyt duża). `search_debug` trafia tylko do `search_diagnostics`
w bazie.

### 4.1 `search_products` (`ProductSearch.php`) — WYSZUKIWARKA HYBRYDOWA

**Parametry:** `query` (string, wym.), `search_plan` (obiekt, wym.: `intent` ∈
{navigational, exploratory}, `reasoning`, opc. `exact_keywords[]`), `category` (string),
`filters` (`price_min`, `price_max`, `brand`, `in_stock_only` **domyślnie true**,
`include_discontinued` **domyślnie false**, `exclude_categories[]`), `limit` (int, max 10, dom. 5),
`sort` ∈ {relevance, price_asc, price_desc}.

**Zwracany JSON (sukces, ścieżka RRF):**
```
{
  "products": [ {
    "id": int, "name": str, "brand": str|null, "category": str|null,
    "price": float,                  // brutto real-time z MySQL
    "price_eur": float|null,
    "in_stock": bool, "availability": str,   // in_stock|available_to_order|unavailable
    "url": str, "url_en": str|null, "image_url": str|null,
    "similarity": float,             // ← UWAGA: to rrf_score, NIE cosine (round 4dp, ProductSearch.php:1020)
    "price_before_discount": float?, "price_before_discount_eur": float?|null,  // gdy promocja
    "available_for_order": false?    // dokładany TYLKO gdy afo=0 (wycofany, ProductSearch.php:1032)
  } ],
  "count": int,
  "search_debug": {...}              // usuwane przed LLM; ma tracks/rrf_k/items/filtered_out/...
}
```
Pustka: `{"products":[], "message":"Nie znaleziono...", "search_debug":{...}}`.
Ścieżka `sort=price_asc|desc` (`searchByPrice:272`) omija RRF — **bez** klucza `similarity`.

**Pole `similarity` = `rrf_score`** — patrz sekcja 5 (krytyczna). Skala ~0–0.066, nie 0–1.

### 4.2 `get_expert_knowledge` (`ExpertKnowledge.php`) — ENCYKLOPEDIA

**Parametry:** `query` (string, wym.), `chunk_types[]` (dom. `[definition, faq, purchase]`),
`concept_key` (string). Źródło: `encyclopedia_chunks` (vector 3072).

**Zwracany JSON:**
```
{ "knowledge": [ {
    "concept_key": str, "name": str, "chunk_type": str, "content": str,
    "similarity": float             // ← PRAWDZIWY cosine 0–1 (round 3dp, ExpertKnowledge.php:128)
  } ], "count": int }
```
Pustka: `{"knowledge":[], "message":"Nie znaleziono..."}`.

**KLUCZOWA ASYMETRIA:** `similarity` tutaj to **prawdziwy cosine** `1 - (embedding <=> vector)`
(`ExpertKnowledge.php:103`). SQL już odsiewa `> 0.45` (`:96`), więc zwrócone chunki mają
cosine ∈ (0.45, 1]. Dlatego próg `knowledge_gap_threshold=0.5` **jest sensowny dla tego
narzędzia** (0.45–0.50 to wąskie pasmo „słabego trafienia"), a **bezsensowny dla
`search_products`** (rrf_score nigdy nie sięga 0.5). To sedno błędu z sekcji 4.7.

### 4.3 `get_product_details` (`ProductDetails.php`) — SZCZEGÓŁY PRODUKTU
**Param:** `product_id` (int, wym.). MySQL. Zwraca: `id, name, brand, description_short,
description, price, price_eur, availability, quantity, url, url_en, image_url,
features[{name,value}]` + opc. `price_before_discount(_eur)`, `special_price{reduction,type}`.
**`quantity` = surowy stan z `pr_stock_available`** (może być zaślepką, sekcja 3.3). Bez `similarity`.

### 4.4 `get_product_combinations` (`ProductCombinations.php`) — WARIANTY (niewdrożone na PROD)
**Param:** `product_id` (int, wym.). MySQL. Zwraca: `product_id, grupy[], domyslny_wariant,
warianty[{id_pa, reference, atrybuty{}, kolor{kod,nazwa,nieznany_kolor}, dostepny, quantity,
na_zamowienie, image_url}], podsumowanie{kolorow_dostepnych, wariantow_ogolem}`. Bez `similarity`.

### 4.5 `check_order_status` (`OrderStatus.php`) — STATUS ZAMÓWIENIA
**Param:** `order_reference` (string, wym.), `customer_email` (string, wym.). MySQL.
Zwraca: `reference, date, status` (nazwa `current_state`), `total` (= `total_paid`),
`history[{status,date}]`, opc. `tracking{carrier,number,url}`. Weryfikacja tożsamości = email.
Bez `similarity`, bez `valid`/`paid`/`total_paid_real`.

### 4.6 `get_popular_products` (`PopularProducts.php`) — SPRZEDAŻ/NOWOŚCI
**Param:** `category` (enum: `fins_recreational`/`fins_jet`/`fins_snorkel`, wym.), `max_price`,
`months` (dom. 6, clamp 1–24). MySQL (`pr_orders` + `pr_order_detail`, `o.valid=1`).
Zwraca: `category, category_label, status, months_window, skipped[], bestsellers[{product_id,
name, brand, price, price_eur, price_before_discount, availability, url, url_en, sold_qty,
is_new?}], new_arrivals[{...,added_date}]`. Liczy **sztuki** (`SUM(product_quantity)`), nie kwoty
— pułapka `total_paid_real` go nie dotyczy. **Filtruje `visible`** (rozjazd R-3).

### 4.7 `get_curated_recommendations` (`CuratedRecommendations.php`) — REKOMENDACJE EKSPERTA
**Param:** `category` (enum z DB, wym.). PG `divechat_curated_recommendations` + MySQL enrich.
Zwraca: `category, category_label, status, curated_count, available_count, skipped[],
products[{product_id, name, priority, rationale_pl, price, price_eur, price_before_discount(_eur),
availability, url, verified_at}]`. Sort po `priority`, nie score. **Filtruje `visible`** (`:135`,
rozjazd R-3). Bez `similarity`.

### 4.8 Narzędzia okołosklepowe
- **`get_shipping_info`** (`ShippingInfo.php`): param `cart_total`, `zone` ∈ {PL,EU} (dom. PL).
  PG `divechat_shipping_rates` + `divechat_shop_config`. Zwraca `zone, methods[{carrier_name,
  price, cod_price, delivery_days}], free_shipping_threshold, cart_total, free_shipping, note`.
- **`get_shop_links`** (`GetShopLinks.php`): param `topic` (enum 6 wartości). PG `divechat_shop_config`.
  Zwraca `accounts{pln,eur,swift}` + `links{…}` + `note` (kształt zależny od `topic`).
- **`get_shop_schedule`** (`GetShopSchedule.php`): param `relative` (enum 17) / `date`.
  Bez DB bezpośrednio (`ShopCalendar`). Zwraca `date, working_day, is_currently_open,
  holiday_name, closed_reason, opens_at, closes_at, next_working_day, server_today`.
- **`recommend_wetsuit_size`** (`SizeRecommender.php`): param `gender` (wym.), `product_id`,
  `brand` ∈ {Scubapro,Bare}, `chest/waist/hip/height/weight`. **MySQL `divezone_attr_*`**
  (nie PG). Deterministyczny (ADR-099). Zwraca `decision, sizes[], consult, reason, size_full[],
  brand, gender` (+ `graniczny` pointwise, `aliases` gdy są). Bez `similarity`.

**`SynonymExpander`** (`SynonymExpander.php`) — helper `ProductSearch`, nie narzędzie LLM.
`expandForFts(query)` → string tsquery z grupami OR z `divechat_synonyms`.

---

## 5. Metryki i ich skale (RRF vs cosine) — SEKCJA KRYTYCZNA

**Dwa różne narzędzia zwracają pole o tej samej nazwie `similarity`, ale w dwóch różnych
skalach.** To jest źródło błędu #5 i błędu w `knowledge_gap`.

### 5.1 `search_products.similarity` = `rrf_score` (Reciprocal Rank Fusion)

**Nie jest to cosine.** To wynik fuzji rankingowej z 5 torów. Kod: `ProductSearch::mergeRRF`
(`ProductSearch.php:769-1074`).

- **Stała `RRF_K = 60`** (`ProductSearch.php:19`).
- **5 torów** (`:787-793`): `name` (cosine `embedding_name`), `desc` (cosine `embedding_desc`),
  `jargon` (cosine `embedding_jargon`), `fts` (`ts_rank` na `fts_vector`), `trigram`
  (`similarity()` pg_trgm na nazwie/marce). Każdy tor: `LIMIT 30` (`TRACK_LIMIT`, `:20`).
- **Wzór** (`:798`): dla każdego produktu
  `rrf_score = Σ_torów  1 / (K + rank_toru)`  gdzie `rank` to pozycja w danym torze (1..30).
  **Score toru zależy tylko od RANGI, nie od wartości cosine.** Cosine z torów semantycznych
  służy wyłącznie do posortowania toru — do sumy RRF wchodzi już tylko rank.
- Po RRF nakładany jest jeszcze editorial boost (`× boost_factor` 1.0–2.5, `:816`) i
  boolean re-rank multi-atrybutowy (`× (1 + 0.5·ratio)`, max +50%, `:1122`).

**Realny sufit skali:**
- Wszystkie 5 torów na 1. miejscu: `5 × 1/(60+1) = 5/61 ≈ 0.0820` (baza, bez boostów).
- Realnie tor `fts` często pusty → 4 tory na miejscu 1: `4/61 ≈ 0.0656`.
- **ZMIERZONY sufit produkcyjny: `0.1230`** — patrz „Pomiar z produkcji" niżej. Boosty
  (editorial ×1.15, multi-atrybut do ×1.5) podnoszą wynik **wyraźnie powyżej sumy rang**.
- **Nigdy nie zbliża się do 1.0.** To jest jedyna teza, która ma tu znaczenie.

**Przykład teoretyczny:** produkt z `name_rank=1, jargon_rank=1, trigram_rank=1, desc_rank=4`:
`3/61 + 1/64 = 0.04918 + 0.01563 = 0.0648` (bez boostów). Z boostem ~1.1× daje **≈0.0713** —
a w polu `similarity` wygląda jak „7% dopasowania".

> **KOREKTA 2026-07-16 (architekt, przy CHAT-T-148).** Wcześniejsza wersja tej sekcji podawała
> **„praktyczny sufit ≈0.0656"** jako liczbę operacyjną. **To było zaniżone** — wyliczenie
> teoretyczne nie uwzględniało realnego wpływu boostów. **Pomiar z produkcji (niżej) daje 0.1230.**
> Liczby `0.0656`/`0.0713` zostawione jako ilustracja arytmetyki RRF, **nie jako sufit**.
> Wniosek dla diagnoz: **nie cytuj sufitu z wyliczenia, cytuj pomiar.** Rząd wielkości (setne,
> nie jedności) był i jest poprawny — i tylko on jest potrzebny do tezy „próg 0,5 nigdy nie zgaśnie".

**POMIAR Z PRODUKCJI (Railway, 2026-07-16, źródło rozstrzygające):**
Wszystkie pozycje ze wszystkich wywołań `search_products` w `search_diagnostics`
(`jsonb_array_elements(d->'search_debug'->'items')`, `d->>'tool' = 'search_products'`):

| metryka | wartość |
|---|---|
| pozycji w próbie | **1605** |
| `rrf_score` **max** | **0.122951** |
| `rrf_score` **min** | **0.028629** |
| pozycji `>= 0.5` (próg `knowledge_gap`) | **0** |

**Zero na 1605.** Najlepszy wynik w całej historii produkcji to **1/4 progu 0,5**. Dowód, że
`knowledge_gap` dla `search_products` **nie może zgasnąć** — empiryczny, nie teoretyczny (ADR-126).

**Dowód z produkcji** (Railway, `divechat_conversations.search_diagnostics`, conv 636 —
zapytanie „maska snorkeling lustrzana szyba mirror"): najlepszy wynik miał `rrf_score = 0.048799`
(`product_id=3372`, `dominant_track=desc`), kolejne 0.0472, 0.0472, 0.0447… To były **trafne
wyniki**, a `knowledge_gap` tej rozmowy = `true` (bo max 0.0488 < 0.5).

### 5.2 `get_expert_knowledge.similarity` = prawdziwy cosine (0–1)

`1 - (embedding <=> vector)` (`ExpertKnowledge.php:103,128`). Odsiew SQL `> 0.45` (`:96`).
Skala pełna 0–1, typowe trafienia 0.45–0.90 (por. ADR-125: „Shearwater Perdix 3" = 0.9137).
**Tu próg 0.5 ma sens.**

### 5.3 Inne skale w kodzie (żeby nie pomylić)
- `editorial_picks.boost_factor`: 1.0–2.5 (mnożnik RRF).
- `pg_trgm similarity()` w torze trigram: 0–1, progi `>0.15` (nazwa), `>0.25` (marka) (`:743`).
- `ts_rank` (FTS): niekalibrowany, dowolna dodatnia liczba — do RRF wchodzi tylko rank.

---

## 6. Przepływ zapytania end-to-end

Ścieżka od wiadomości klienta do odpowiedzi. Każdy etap z plikiem+liniami.

1. **Widget → HTTP.** `POST /api/chat` (JSON) lub `POST /api/chat/stream` (SSE). Nagłówki HMAC:
   `X-DiveChat-Token`, `X-DiveChat-Customer`, `X-DiveChat-Time`. Body: `message`, `session_id`,
   opc. `nudge_sid`, `chip_context`, `chip_path`.
2. **`ChatController::handle`/`stream`** (`ChatController.php:248` / `:360`):
   - weryfikacja HMAC (`:264-267`), walidacja `session_id` (`resolveSessionId:564`),
   - limit długości inputu (`:300`), **cost guard** dzienny cap (`enforceCostGuard:118`, próg
     z panelu PS→.env), **rate-limit** per sesja+IP (`rateLimitExceeded:163`) — wszystko PRZED LLM,
   - → `ChatService::handle(...)` (`:322`).
3. **`ChatService::handle`** (`ChatService.php:56`):
   - `startOrResume` — wczytaj/utwórz rozmowę, utrwal `nudge_sid`/`chip_path` (`:73`),
   - przytnij historię do 10 wiadomości (`trimHistory:491`), zbuduj `system` prompt (`SystemPrompt::build`),
     doklej blok chipów jeśli jest (`:99`),
   - **wybór modelu — panel PS jest źródłem prawdy, NIE `.env`** (`:133-151`, CHAT-T-068):
     `settings['model_primary']` → `AIModel::tryFrom` → `provider()`. Dopiero gdy panel pusty
     lub model spoza enuma → fallback `.env` `AI_PROVIDER` (`:143-146`). `temperature`/`effort`/
     `max_tokens` też z panelu wg flag modelu (`:161-174`).
4. **Tool loop** (`:176-305`, max 5 iteracji `MAX_TOOL_ITERATIONS`):
   `aiProvider->chat()` → jeśli tool_calls: wykonaj każde (`executeTool:516` → `ToolRegistry`),
   zbierz `products` (`:269`), zbuduj diagnostykę (`buildSearchDiagnostic:433`), dołącz wynik
   (bez `search_debug`) i wróć do modelu. Brak tool_calls = finalna odpowiedź.
5. **`ProductSearch::execute`** (`ProductSearch.php:120`) — gdy wywołane:
   - `sort=price_*` → osobna ścieżka `searchByPrice` (bez RRF, `:272`),
   - inaczej: embedding zapytania (OpenAI, `:169`), ekspansja synonimów FTS (`:174`),
   - **5 torów** (`runTracksAndMerge:435` → `searchSemanticColumn` ×3 `:447-449`,
     `searchFullText:450`, `searchTrigram:451`),
   - **RRF merge** (`mergeRRF:453`) + editorial boost + boolean re-rank,
   - **filtry POST-HOC z MySQL, nie z pgvector:** `enrich()` pobiera świeże dane (`:870`),
     potem filtr `active` (`:901`), `in_stock_only` (`:921`), `available_for_order`
     (`!includeDiscontinued`, `:946`). **W pgvector filtruje tylko `is_active`** (`buildFilters:526`)
     + kategoria/cena/marka/blacklist. Dostępność i wycofanie NIE są w pgvector — to enrich MySQL.
     (To miejsce, na którym architekt się przejechał: `in_stock_only`/`include_discontinued`
     działają na wynikach MySQL po RRF, nie zawężają zapytania wektorowego.)
   - auto-fallback bez kategorii gdy 0 wyników lub navigational-miss (`:207`).
6. **`ConversationStore::save`** (`ConversationStore.php:173`): zapis historii, `tools_used`,
   `search_diagnostics`, `knowledge_gap` (STICKY OR, `:189`). Koszt liczy `UsageLogger`.
7. **Odpowiedź** → `ChatController` zwraca JSON/SSE `done`: `response, session_id, tools_used,
   products, usage, conversation_cost, diagnostics{model_used, response_times, search_diagnostics,
   knowledge_gap}` (`ChatController.php:332`/`:456`).

**Degradacja:** `DbUnavailableException` (Railway padło) → grzeczna wiadomość bota HTTP 200,
NIE 500 (`:342`, ADR-104/CHAT-T-107).

### 6.b `knowledge_gap` — mechanizm, błąd i KTO tego używa

**Mechanizm** (`ChatService::buildSearchDiagnostic`, `ChatService.php:433-467`):
- dotyczy 2 narzędzi: `search_products`, `get_expert_knowledge` (`:435`),
- `items = result['products'] ?? result['knowledge']`; `similarities = map(item.similarity)` (`:441-442`),
- `maxSim = max(similarities)`; **`gap = empty(items) || (maxSim < threshold)`** (`:446`),
- **próg `knowledge_gap_threshold` = 0.5** (default, `loadSettings:418`; strojony w
  `divechat_settings`), **skala cosine**,
- flaga **STICKY**: `knowledge_gap = (?::boolean OR COALESCE(knowledge_gap,false))`
  (`ConversationStore.php:189`) — raz zapalona nie gaśnie.

**BŁĄD (potwierdzony zapytaniem):** `search_products` zwraca w `similarity` **`rrf_score`**
(sufit ~0.066, sekcja 5), a próg to **0.5 w skali cosine**. Więc `maxSim < 0.5` jest
**ZAWSZE prawdą** dla `search_products` — flaga zapala się przy KAŻDYM wyszukiwaniu produktu,
niezależnie od jakości wyniku.

**Weryfikacja na PROD (Railway, całość 644 rozmów):**

| knowledge_gap | użyto `search_products` | liczba |
|---|---|---|
| false | nie | 313 |
| true | tak | 229 |
| true | nie | 102 |
| false | **tak** | **0** |

Zero rozmów z `search_products` ma `knowledge_gap=false` (229/229 → true). Ostatnie 30 dni:
126 true / 91 false — **hipoteza architekta potwierdzona**: `false` mają WYŁĄCZNIE rozmowy
**bez** `search_products`. (102 rozmowy true-bez-search to te, gdzie `get_expert_knowledge`
zwrócił max cosine 0.45–0.50 lub pustkę — tam próg działa poprawnie.)

**KTO używa flagi (ustalone z kodu, nie zgadnięte):**
1. **Panel PS** (`modules/divezone_chat/controllers/admin/AdminDivezoneChatController.php`) —
   panel, którego używa Karol:
   - checkbox filtra **„Luki wiedzy"** (`:1661`) → wysyła `knowledge_gap=1` → `filters['knowledge_gap']='true'` (`:1580-1581`),
   - **badge na liście** rozmów (`:1683,1719`),
   - **detal rozmowy**: `<dt>Luka wiedzy</dt><dd>TAK/nie</dd>` — czerwone „TAK" gdy true (`:1828,1843`).
2. **Backend API** `ConversationsController::list` (`:47,50`) — query param `knowledge_gap` →
   `ConversationStore::list(..., $knowledgeGap, ...)` → `WHERE knowledge_gap = ?` (`ConversationStore.php:225-227`).
3. **Aplikacja mobilna pracownika** `MobileConversationsController::list` (`:44,47`) —
   filtr „wymagające uwagi" (`:26`).

**Wniosek dla decyzji 115a (naprawa — POZA zakresem T-147):** flaga JEST realnie
eksponowana w panelu Karola (filtr + badge + detal). Ponieważ zapala się przy każdym
`search_products`, filtr „Luki wiedzy" pokazuje ~wszystkie rozmowy produktowe, a czerwone
„TAK" jest przy niemal każdej rozmowie z wyszukiwaniem — **sygnał jest zaszumiony do
bezużyteczności**. Naprawa ma wartość, bo psuje realnie używane narzędzie recenzji.

---

## 7. Rozjazdy kod vs ADR / dokumentacja

Format: co mówi dokument — co jest w kodzie — plik+linia. **Nie naprawiane, tylko zgłoszone.**

- **R-1 — model embeddingów.** `02_schemat_bazy.md:62-63` twierdzi „Google Gemini embedding-001,
  output_dimensionality=1536". Kod: **OpenAI `text-embedding-3-large`** (`EmbeddingService.php:44`),
  `dimensions=1536` (produkty) / `3072` (encyklopedia, `ExpertKnowledge.php:16,75`). Doc mówi też
  o `vector(3072)→vector(1536)` migracji, a `encyclopedia_chunks` jest realnie 3072.

- **R-2 — `divechat_knowledge` martwa.** `02_schemat_bazy.md:65-95` i `CLAUDE.md` opisują
  `divechat_knowledge` (37 Q&A) jako bazę wiedzy eksperta. Kod: **0 trafień** w `standalone/src`.
  `get_expert_knowledge` czyta `encyclopedia_chunks` (530 chunków, `ExpertKnowledge.php:105`).
  `divechat_knowledge` jest osierocona.

- **R-3 — `visibility` filtruje NIEspójnie między narzędziami.** ADR-122 nota 3 + ADR-123 nota 93a:
  `visibility` NIE jest kryterium (Luigi's Box), usunięte z `ProductSearch` (zweryfikowane: 0
  trafień `visible` w `ProductSearch.php`). ALE nadal filtruje w **`CuratedRecommendations.php:135`**
  (`!$data['visible']` → skip, reason `visibility=none`) i **`PopularProducts.php:258`** (to samo).
  Oba narzędzia POLECAJĄ produkty, więc wg logiki ADR-123 powinny też pominąć to kryterium
  (albo ADR powinien odnotować rozbieżność). Efekt: produkt `vis='none'` + `afo=1` (jest ich 11
  na PROD) wypada z rekomendacji i popularnych, choć da się go kupić.

- **R-4 — rozmiarówki: dwa źródła.** PG ma `divechat_size_charts`/`_size_chart_rows`/
  `_product_size_chart`/`_size_label_alias` (**0 trafień w `standalone/src`**). `SizeRecommender`
  czyta MySQL `divezone_attr_*` (`SizeRecommender.php:170-213`, CHAT-T-103). Tabele PG rozmiarówek
  wyglądają na porzucone/legacy — do potwierdzenia, czy coś je zasila (sekcja 8).

- **R-5 — dryf `config/tools.php` (repo ≠ prod).** Repo `tools.php:42` rejestruje
  `ProductCombinations` (CHAT-T-129, celowo niewdrożone — czeka na `nazwa_pl`). Klasy nie ma na
  PROD → rsync repo 1:1 = fatal „Class not found" + `/api/health` 500 (incydent 2026-07-14,
  [[tools-php-drift-t129]]). To dryf deploy, nie błąd logiki — odnotowany, bo dotyczy kontraktu
  narzędzi z sekcji 4.

- **R-6 — próg encyklopedii: 0.45 w SQL vs 0.5 w diagnostyce.** `ExpertKnowledge.php:96`
  odsiewa cosine `> 0.45`, a `buildSearchDiagnostic` liczy `knowledge_gap` względem `0.5`
  (`ChatService.php:446` + próg `:418`). Nie jest to błąd (0.45–0.50 = pasmo słabego trafienia),
  ale dwie różne stałe dla „granicy trafności" tego samego narzędzia warto ujednolicić świadomie.

- **R-7 — `02_schemat_bazy.md` nie opisuje realnego schematu.** Doc (wersja 1.2, 2026-02-20)
  wymienia 3 tabele i przykładowe zapytanie z `WHERE is_active AND in_stock` + `similarity` jako
  cosine. Realnie: 30 tabel `divechat_*`, `divechat_conversations` ma 21 kolumn (doc: 11 — brak
  `model_used, response_times, search_diagnostics, knowledge_gap, admin_status, admin_notes,
  cache_*, nudge_sid, chip_path`), a `ProductSearch` nie używa `in_stock` z pgvector (post-hoc
  MySQL). Doc jest projektowy, nie odzwierciedla wdrożenia.

---

## 8. NIE USTALONO

Rzeczy, których NIE dało się ustalić z kodu/bazy w ramach tego taska (świadomie niezgadywane):

- **`total_paid_real` — dokładny mechanizm podwójnego zapisu Tpay.** Ustalone: pole
  **nieużywane w kodzie czatu** (0 trafień), a wg ADR-124 zawyżone 2× dla 1246/1259 zamówień Tpay.
  NIE zweryfikowano samodzielnie zapytaniem na MySQL PS (brak bezpośredniego dostępu do
  `divezone_2025` z tej sesji — inwentarz MySQL zrobiony z kodu). Liczby przyjęte za ADR-124.
- **Skala zaślepek w `pr_stock_available.quantity`** (ile produktów ma 9999999/29998) — nie
  zweryfikowana zapytaniem (brak dostępu do MySQL PS w tej sesji). Fakt zaślepek i „Subiekt =
  źródło prawdy" przyjęty za `CLAUDE.md`/kontekst; kod czyta stan binarnie, więc konkretna
  liczba i tak nie wpływa na logikę.
- **Kto/co zasila PG `divechat_size_*`** (rozjazd R-4) — tabele istnieją (5 wierszy w
  `divechat_size_charts`), ale 0 odczytów w `standalone/src`. Nie sprawdzono skryptów
  importujących poza backendem (embeddings/inne narzędzia). Możliwe, że to martwy schemat po
  wcześniejszym podejściu przed CHAT-T-103.
- **Źródło danych `ShopCalendar`** dla `get_shop_schedule` — narzędzie nie czyta DB wprost
  (`ShopCalendar` + `DbOverrideProvider(divechat_shop_calendar_overrides)`); pełnej ścieżki
  (skąd stałe godziny/święta) nie prześledzono do końca — poza zakresem pytań T-147.
- **Realna wartość globalnego `PS_ORDER_OUT_OF_STOCK`** (wpływa na `availability` przy
  `out_of_stock=2`) — czytana per-request z `pr_configuration` (`MysqlProductEnrichmentService.php:67`),
  nie odczytana (brak dostępu MySQL). Nie zmienia to logiki mapowania, tylko wynik dla konkretnych
  produktów z `out_of_stock=2`.

Poza powyższym inwentarz PG (schemat, typy, enumy, skale, `knowledge_gap`) jest ustalony z żywej
bazy Railway, a inwentarz MySQL i kontrakty narzędzi — z kodu z numerami linii.

---

## PUŁAPKI — ŚCIĄGA. CZYTAJ PRZED DIAGNOZĄ

*Napisana 2026-07-16 przez architekta na bazie inwentarza CC (decyzja 117a). Ściąga do czytania PRZED diagnozą, nie wykład. Każda pozycja to realny błąd, który kosztował czas Karola.*

**Zasada nadrzędna: nazwa pola nie jest jego znaczeniem.** W jednej sesji (2026-07-15/16) architekt popełnił SIEDEM błędów tego samego typu — wnioskował z nazwy kolumny zamiast sprawdzić, co realnie zawiera i **kto ją czyta**. Drugie pytanie jest ważniejsze od pierwszego: pole może mieć poprawną zawartość i być martwe.

| pole / obiekt | odruch (BŁĘDNY) | stan faktyczny | źródło prawdy |
|---|---|---|---|
| `pr_product_shop.visibility` | `'none'` = klient tego nie znajdzie | wyszukiwarką sklepu jest **Luigi's Box** (zewnętrzna) i **ignoruje to pole**. Produkt `vis='none'` JEST w wynikach. Dowód Karola: `divezone.pl/szukaj?s=Torba MARES Cruise` → produkt 3920 | rozjazd R-3, ADR-123 nota 93a |
| `pr_product_shop.available_for_order` | — | **właściwe** kryterium „czy można kupić". `afo=0` = wycofany ze sprzedaży | ADR-123 |
| `pr_orders.valid` | `0` = niezapłacone | flaga **księgowa** (logable), nie ma nic wspólnego z zapłatą. Dowód: QETUBCWYS `valid=0`, stan „Zapłacone", Tpay | `current_state` → `pr_order_state.paid` |
| `pr_orders.total_paid_real` | ile faktycznie wpłynęło | **2× zawyżone dla 1246/1259 zamówień Tpay** (99%). Moduł zapisuje płatność dwa razy: raz z `transaction_id`, raz z pustym. Inne bramki (Przelewy24, Revolut, PayPal, PayU = 624 zam.): **zero** podwojeń | **licz `total_paid`**. Karta Sklep - 31 |
| `pr_stock_available.quantity` | stan magazynowy | **zaślepki** (9999999, 29998). Zestawy mają `quantity=0`, bo Firmes wiąże SKU literalnie z Subiektem, a zestawy mają sklejone SKU | **Subiekt ERP** |
| `pr_stock_available.out_of_stock` | — | `2` = „użyj domyślnego zachowania sklepu" → **zamówienie przechodzi mimo `quantity=0`**. To jest mechanizm, nie obejście | ADR-123, karta Chat - 21 |
| **`similarity` w tool_result `search_products`** | cosine, skala 0-1 | to **`rrf_score`** — Reciprocal Rank Fusion, `1/(k+rank)`, `rrf_k=60`. **Sufit ZMIERZONY na produkcji: `0,1230`** (1605 pozycji, max 0,122951, min 0,028629; **0 pozycji ≥ 0,5**). Nie mylić z wyliczeniem teoretycznym ≈0,0656 — było zaniżone, bo pomijało boosty. Liczy się rząd wielkości: **setne, nie jedności** | sekcja 5.1 — pomiar (`ProductSearch.php:19,769-1074`) |
| `similarity` w `get_expert_knowledge` | — | **tu jest prawdziwy cosine** (0-1). Dlatego próg 0,5 działa tam, a w `search_products` nie. **Dwa narzędzia, to samo pole, dwie różne skale** | `ExpertKnowledge.php` |
| **`divechat_knowledge`** | baza wiedzy eksperta (tak mówi `02_schemat_bazy.md` i `CLAUDE.md`) | **MARTWA.** 37 wpisów, najnowszy 2026-02-19, **zero odczytów w `standalone/src`**. Wpis tam = praca w błoto | **`encyclopedia_chunks`** (`ExpertKnowledge.php:105`). Rozjazd R-2 |
| `divechat_conversations.created_at` | — | **nie istnieje**. Kolumna nazywa się `started_at` | sekcja 2 |
| `divechat_product_embeddings.product_id` | — | **nie istnieje**. Kolumna nazywa się `ps_product_id` | sekcja 2 |
| recenzje rozmów | kolumny w `divechat_conversations` | **osobna tabela** `divechat_conversation_review` (`note`, `verdict`, `status`, `updated_by`) | sekcja 2 |
| `chip_path` vs `nudge_sid` | to samo | **dwa różne mechanizmy.** `chip_path` = drzewo chipów, `nudge_sid` = zaczepka proaktywna. Rozmowa z `nudge_sid` i pustym `chip_path` to **nie** regres chipów | conv 636 |
| `knowledge_gap` | bot nie znalazł odpowiedzi | **NAPRAWIONE 2026-07-17** (ADR-126, CHAT-T-148, wdrożone). Było: próg 0,5 (skala cosine) porównywany z `rrf_score` (sufit zmierzony **0,1230**) → **zawsze `true`** (0 z 1605 pozycji osiągnęło 0,5; 237/237 rozmów z flagą). Jest: dla `search_products` **`gap = zero wyników`, bez progu** (`ChatService:452`); dla `get_expert_knowledge` próg 0,5 **bez zmian** (`:454` — tam to prawdziwy cosine). Panel: **339 → 196**. **Nadal sticky** (`ConversationStore:191`) — zamierzone. **Historia NIE przeliczona w całości**: migracja 042 ruszyła 191 rozmów z pełną diagnostyką; **86** (migawka) + **94** (brak diagnostyki) zostały z `true` jako nieprzeliczalne — to **fałszywy alarm w panelu, nie utrata sygnału**. **Flaga NIE łapie „znalazł, ale bzdurę"** — nie da się tego złapać żadnym progiem, od oceny trafności jest `verdict` | sekcja 4, ADR-126 + noty 1-2, decyzje 128b/130b/132a |
| „sprzedaż" w pytaniu | kiedykolwiek | dopytaj o okres. 344/483 produktów `vis=none` nie sprzedanych **od roku**, 106 nigdy, tylko 7 w ostatnich 3 miesiącach | — |

**Pułapki proceduralne (nie dotyczą pól, ale kosztują tak samo):**

- **`*_rollback.sql` bywa PRZYBLIŻENIEM, nie odwrotnością.** Gdy forward niszczy informację (`UPDATE` kasuje poprzednią wartość), **żadna reguła jej nie odtworzy** — po migracji rozmowy zgaszone i te od początku `false` są nieodróżnialne. Dowód: ADR-126 nota nr 2, scope 191 = 160 `true` + 31 `false`, rollback whole-scope over-restore'uje te 31. **`pg_dump` przed migracją to jedyny dokładny rollback**, nie formalność.
- **`search_diagnostics`: rozmowy sprzed 2026-07-17 to MIGAWKA ostatniej tury, nie historia.** **NAPRAWIONE dla nowych rozmów** (ADR-127, CHAT-T-149, wdrożone 2026-07-17 ~10:03): `ConversationStore:190` akumuluje przez `COALESCE(search_diagnostics,'[]') || ?::jsonb`. **Ale historia NIE została odtworzona** (decyzja 135a) — **86 z 277 starych rozmów ma diagnostykę uboższą niż `messages`** (200 wywołań bez śladu). **Wniosek dla diagnoz: przy rozmowach sprzed 2026-07-17 nie ufaj `search_diagnostics` jako historii.** Test rozstrzygający: `jsonb_array_length(search_diagnostics)` vs liczba `messages[].tool_calls[]` — dla nowych zgodne, dla starych bywa mniejsze. Dowód: ADR-126 nota nr 1, ADR-127.
- **`messages` używa formatu `tool_calls[]`, NIE `content[].type='tool_use'`.** Zły parser zwraca **0 i test przechodzi fałszywie**. Kształt: `{role, content, tool_calls:[{id,name,arguments}]}` + osobna wiadomość `{role:'tool_result', name, content}`. `COALESCE(m->'tool_calls','[]'::jsonb)` konieczny — `user`/`tool_result` nie mają tego klucza.

- **`newtmp2` to PRODUKCJA sklepu**, mimo nazwy „tmp". Nie katalog przejściowy.
- **Kod backendu na serwerze nie ma prefiksu `standalone/`** — w repo jest, na serwerze nie.
- **Panel PS jest źródłem prawdy dla wyboru modelu**, nie `.env` (CHAT-T-068).
- **Pipeline embeddingów JEST cronowany od CHAT-T-150** (cron 02:15 delta po hashu + watchdog 08:30, `/home/divezone/scripts/embeddings/`, ADR-128). Wcześniej nie był (stąd historyczny dług „140 braków od 15 maja"). Nowy/zmieniony produkt wchodzi automatycznie następnej nocy.
- **`config/tools.php` ma DWUKIERUNKOWY dryf repo↔prod** (repo `ProductCombinations` martwa, prod `GetProductCombinations` żywa, ATTR-T-052). Blanket-rsync = fatal 500 + zabicie cudzej rejestracji. Deploy tools.php TYLKO wariantem „prod + nowe linie". Karta Chat - 42.
- **Filtry `in_stock_only` / `include_discontinued` działają post-hoc z MySQL**, nie w pgvector. To nie to samo miejsce w przepływie.
- **Hasło MySQL w `.env` jest w apostrofach.** `tr -d '"'` ich nie usuwa → `Access denied`. Najprościej: skrypt PHP w katalogu aplikacji (`vendor/autoload` + `Dotenv` + `PDO`), nie CLI.
- **Apostrofy giną w łańcuchu SSH→zsh→bash→psql.** Zapisuj SQL do pliku na serwerze (`cat > /tmp/q.sql << "EOF"`), literały przez `chr()||`. `interval` nie przyjmuje wyrażeń → `make_interval(days => 30)`.

- **Nazwy krajów/stref w PrestaShop bywają poprzedzone twardą spacją U+00A0 (nbsp, bajty C2A0).** SQL `TRIM` usuwa tylko 0x20, więc „Austria" wraca jako „ Austria" i nie matchuje po nazwie. Czyść w PHP: `preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u','',$s)`. Dowód: `pr_country_lang` id_lang=1, CHAT-T-151 `InternationalShipping::cleanName()`.

- **Rozmiarówki mają DWIE tabele, nie jedną.** `divezone_attr_size_chart_rows` (zakresy min/max per wymiar) obsługuje wyłącznie `chart_type='progowy'`. Dla `chart_type='tresciowy'` ta tabela jest PUSTA, a treść leży w `divezone_attr_size_chart_content` (`content_html`, `note`). Zapytanie tylko do pierwszej daje fałszywy wniosek „brak danych" (bot zwracał „Tabela rozmiarów jest pusta" dla 14 produktów, które dane miały). Osiem chartów tresciowych ma pięć różnych układów kolumn (1-5), dlatego ADR-133: surowy HTML do modelu, bez parsera.
- **Pusty wynik `grep` po komunikacie błędu NIE jest dowodem nieobecności.** 2026-07-24 uznałem, że SystemPrompt nie ma reguły o walucie, bo `grep -n -i 'EUR|waluta|PLN'` zwrócił zero. Bez `-E` grep szukał literalnego ciągu z pionowymi kreskami, a shell wypisał `grep: Błędny odnośnik wstecz`, co zignorowałem. Reguła istniała: blok `LINK I CENA WG JĘZYKA (CHAT-T-115)`, linie 146-151, wraz ze zdaniem o walucie wg języka rozmowy. Wyłapało CC. **Alternatywa w grep wymaga `-E`; komunikat błędu unieważnia wynik.**
- **Odczyt z `divezone_attr_*` ma KRÓTKĄ WAŻNOŚĆ.** Tabelami zarządza równoległa sesja projektu atrybutów i zmienia je w trakcie dnia. 2026-07-24 produkty 1146/4553/4554 miały rano chart 4 (Bare, K, wzrost od 157 cm — dorosła kobieta), po południu mapowań już nie było (160→157 powiązań, ATTR-T-057). Cytowanie własnego pomiaru sprzed godziny jako stanu obecnego = błąd. **Przy każdej tezie opartej na tych tabelach powtórz odczyt bezpośrednio przed wnioskiem**, a w tasku zapisuj datę ORAZ godzinę pomiaru.
- **Przecięcia wymiarów NIE licz w głowie z dwóch osobnych zapytań.** Suma trafień `chest=93` (XS, S, ST) i `height=172` (XS, MS) kusi wnioskiem „przecięcie = XS, MS". Prawdziwe przecięcie to **XS**: MS ma `chest` 96-101, więc 93 do niego nie należy. Błąd popełniony trzykrotnie w trzech dokumentach (ATTR-T-055 §3.1, ADR-032 aneks 1, CHAT-T-161 kryterium 4), wyłapany dopiero przez CC. **Licz przecięcie w SQL** (`HAVING COUNT(*) = <liczba podanych wymiarów>`), nie przez zestawianie wyników. Dowód: `divezone_attr_size_chart_rows` id_chart=1, pomiar 2026-07-23.
- **`divezone_attr_size_charts`: sama marka NIE identyfikuje chartu.** `Scubapro` trafia w 7 chartów, po filtrze `chart_type='progowy' AND category_hint='skafander'` nadal w **3** (M, K, DZIECI). Jednoznaczność domyka dopiero **płeć**. Bez filtrów kategorii klient pytający o skafander dostanie tabelę butów lub rękawic. Dowód: pomiar 2026-07-23, CHAT-T-161 sekcja 2.4.
- **`SizeRecommender::matchSize()` nie ma własnego guardu na pusty `$dims`.** Przecięcie pustego zbioru warunków zwraca **wszystkie** rozmiary chartu (16 dla chartu 1). Chroni je wyłącznie guard w `execute()` (linie 143-149). Nowy wołający `matchSize` bez sprawdzenia pustki dostanie cichą listę zamiast błędu. Dowód: CHAT-T-161, weryfikacja PROD 2026-07-23.

- **`exact_keywords` gubi nazwę modelu między kolejnymi wywołaniami `search_products` w jednej rozmowie.** Rozmowa 817 (2026-07-24): pierwsze wywołanie `["TUSA","Intega"]`, drugie `["TUSA","korekcyjne"]` — model zniknął, bot nie znalazł szkieł MC211/BF211 i orzekł klientowi, że ich nie ma. Rozmowa 770 (2026-07-20) z zapytaniem „szkła korekcyjne TUSA Intega" zwróciła 6994 (rrf 0.088), 6573 (0.087), 6577 (0.086). Ten sam indeks, ta sama wyszukiwarka — **przy pudle na akcesorium sprawdzaj najpierw `search_diagnostics[].search_plan.exact_keywords`, nie embeddingi.** Naprawa: ADR-134 / CHAT-T-167.
- **Lista kompatybilności w opisie produktu opisuje TYLKO ten produkt.** Brak modelu X na liście produktu Y dowodzi jedynie, że Y do X nie pasuje, a NIE że do X nic nie ma. Rozmowa 817: bot przeczytał w opisie MC-7500 (id 5125) listę „Ceos, Geminus, Splendive II/IV", nie znalazł Integi i zamknął temat — mimo że 6573/6577/6993/6994 mają Integę w nazwie. Dowód: `pr_product_lang` LIKE '%INTEGA%' → 7 pozycji, pomiar 2026-07-24.
- **Skale wariantów lewego i prawego szkła mogą się różnić.** MC211 prawe (6573) ma plusy +1…+3,0 i +4,0…+4,5 (brak +3,5), lewe (6577) ma pełne. BF211 (6993, 6994) mają pełne +1…+4,5. Przy komplecie „lewe + prawe" sprawdzaj `get_product_combinations` dla OBU produktów. Dowód: `pr_product_attribute` + selektor na stronie produktu, pomiar 2026-07-24.

- **`get_product_combinations` widzi TYLKO grupy atrybutów 23 (KOLOR) i 27 (ROZMIAR).** Filtr w INNER JOIN (`AND a.id_attribute_group IN (23,27)`) odrzuca kombinacje z pozostałych 21 grup, więc produkt zwraca `liczba_wariantow: 0` mimo istniejących wariantów. Szkła korekcyjne (grupy 34/35): 0 widocznych z 61 kombinacji. **180 aktywnych produktów całkowicie niewidocznych**, w tym 51 odzieżowych z grup 29/30 (ROZMIAR MĘSKI/DAMSKI). `liczba_wariantow: 0` NIE znaczy „produkt bez wariantów" — sprawdź `pr_product_attribute` bezpośrednio. Dowód: rozmowa 829, pomiar 2026-07-24. Naprawa: ADR-135 / CHAT-T-168.
- **5976 kombinacji ma więcej niż jedną grupę atrybutów.** Każde pole opisujące wariant musi być tablicą, nie skalarem. Dowód: `pr_product_attribute_combination` GROUP BY z `HAVING COUNT(DISTINCT id_attribute_group)>1`, pomiar 2026-07-24.

**Jak korzystać:** przed każdą diagnozą przejrzyj tabelę. Gdy wniosek opiera się na polu, którego tu nie ma — sprawdź w inwentarzu (sekcje 2-5), **co zawiera i kto to czyta**. Gdy odkryjesz nową pułapkę, dopisz ją tutaj: jedna linijka, z dowodem.
