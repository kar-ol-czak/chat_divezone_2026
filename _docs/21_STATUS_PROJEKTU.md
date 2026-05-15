# STATUS PROJEKTU: Czat AI divezone.pl
# Wersja: 3.7 | Data: 2026-05-14 (koniec sesji architekt #4)
# Aktualizowany ręcznie po każdej sesji architekta

---

## AKTUALNY STAN (koniec sesji 2026-05-14, przed przeniesieniem rozmowy)

### Co działa na produkcji chat.divezone.pl

| Komponent | Status | Commit |
|---|---|---|
| TASK-CHAT-007a SystemPrompt hardening | DEPLOYED | `92083b7` + `f26927f` |
| TASK-CHAT-007b ShopCalendar + tool get_shop_schedule | DEPLOYED | `b26fe39` |
| Mini-patch v2 SystemPrompt (6 reguł: język, marka niedostępna, pełnotwarzowe, logbook, voucher) | DEPLOYED | `23de13e` |
| TASK-CHAT-011 get_shop_schedule trigger fix (Fix C: 4 grupy triggerów + few-shot) | DEPLOYED | `93f9fe8` |
| TASK-CHAT-007c frontend Markdown parser | DEPLOYED z 2 follow-up bugami | `446beae` |
| TASK-CHAT-007c follow-up (goły URL + CSS link color) | DEPLOYED | (status zwrócony, weryfikacja Karol) |
| TASK-CHAT-010 synonimy logbook/wet notes/voucher + whitelist sub-cat 476 | DEPLOYED z regresją | `6171157` |
| **T-001 regresja logbook → wet notes fix** (stale in_stock dla id=5263) | DEPLOYED | `95edf2e` |
| **T-002 D2-hybrid mapping 100% pokrycia (ADR-055)** | DEPLOYED | `f8cf156` + `1461d82` |
| **T-003 Mini-patch v3 SystemPrompt (7 patchy: PORADY PREZENTOWE, PL/EN, bold ceny, NAZEWNICTWO, krój, available_to_order, linki)** | DEPLOYED 18:50 CEST | `60db230` |
| **T-006 fix availability logic — respektuj out_of_stock=2 (ADR-056, 1043 SKU "ożywa")** | DEPLOYED 21:20 CEST | `cbc8f30` |
| **T-007 mini-patch v5 SystemPrompt — Patch H (PYTANIE O PŁEĆ KRYTYCZNE) + Patch I (ZAKAZ GENERALIZACJI STATUSÓW)** | DEPLOYED 21:39 CEST | `becfcb1` |
| **T-008 Editorial Picks backend (ADR-054) — migracja 011 + EditorialPicksService + RRF integration + cron + API** | DEPLOYED 2026-05-15 06:55 CEST | `92caec0` |

### Aktywne instancje CC

| Instancja | Task | Stan |
|---|---|---|
| frontend | TASK-CHAT-007c follow-up | DEPLOYED, weryfikacja Karol przez UI |
| embeddings | T-001 | **DONE** |
| backend | T-008 Editorial Picks backend (ADR-054) | **DONE** — DEPLOYED 2026-05-15 06:55, czeka smoke + crontab |

### Smoke test produkcyjny po T-001 i T-002 (14.05)

Karol potwierdził:
- T-001 logbook regression: ✅ działa, "Macie logbook?" zwraca prawdziwe logbooki, nie wet notes
- T-002 SANTI bug: ✅ SANTI znalezione (Santi Edge + E.Motion Plus)
- T-002 Komputery Nurkowe: ✅ wszystkie marki widoczne (SUUNTO/SHEARWATER/SCUBAPRO/MARES/GARMIN)

ALE wykryte 3 follow-up bugi w prod:
1. Bot mówi "obecnie niedostępne" dla `available_to_order` (E.Lite Plus, Ladies First) zamiast "na zamówienie" — **CLOSED by T-006** (root cause PHP, nie SystemPrompt — tool zwracał `unavailable` zamiast `available_to_order` dla out_of_stock=2)
2. Bot wybiórczo linkuje produkty (linkuje tylko in_stock, pomija available_to_order) — **CLOSED by T-006** (objaw tego samego buga — model dostawał `unavailable` więc nie linkował)
3. Bot polecił skafander męski bez pytania o płeć (reguła obecnie pokrywa "pianki/skafandry", nie "skafandry suche") — **CLOSED by T-007 patch H** (PYTANIE O PŁEĆ KRYTYCZNE: ZAWSZE przed pierwszą rekomendacją, NIEZALEŻNIE od nazw produktów typu "Męski"/"Ladies First")
4. Bot pisał w intro "nie mamy żadnego dostępnego od ręki" mimo że w swojej liście wymieniał produkt in_stock (Powystawowy) — **CLOSED by T-007 patch I** (ZAKAZ GENERALIZACJI STATUSÓW: policz statusy przed wstępem, intro spójne z listą)

**PAKIET HOTFIXÓW POST-T-002 ZAMKNIĘTY** (T-001/T-002/T-003/T-006/T-007).

### T-003 spec (gotowy do puszczenia)

Plik: `_instances/backend/tasks/T-003_backend_systemprompt-v3.md`
7 patchy:
- A. Sekcja PORADY PREZENTOWE z budżetem + 4 kategorie cenowe + voucher
- B. Język statusów PL/EN (in_stock / available_to_order / unavailable)
- C. Bold ceny + status dostępności
- D. NAZEWNICTWO Logbooki + Tabliczki + Prezenty subkategorie
- E. Krój damski/męski rozszerzony (skafandry suche, pianki mokre, ocieplacze, odzież)
- F. **KRYTYCZNY**: available_to_order ZAWSZE "na zamówienie", NIGDY "niedostępny"
- G. **KRYTYCZNY**: linkuj WSZYSTKIE wymienione produkty niezależnie od dostępności

Prompt CC: `wykonaj _instances/backend/tasks/T-003_backend_systemprompt-v3.md`

### Konwencja numeracji (NOWA, od T-001)

- Każdy task = nowy numer narastający T-NNN (T-001, T-002, T-003, ...)
- Nazwa pliku: `T-NNN_INSTANCJA_krotki-opis.md` (instancja = backend/frontend/embeddings/integration)
- Cała treść tasku w pliku w `_instances/{instancja}/tasks/`
- Prompt CC w czacie: max 3 linie typu "wykonaj plik X"
- Nie ma faz (jak "faza 2", "v2"). Każda iteracja = nowy numer T-NNN

Stara konwencja (TASK-CHAT-007a/007b/007c, TASK-CHAT-010/011/012) zostaje w handoff i historycznych raportach. Numeracja T-NNN od 14.05.

### Kolejka tasków (po deploy T-008)

| Numer | Task | Priorytet | Status |
|---|---|---|---|
| T-XXX frontend admin UI Editorial Picks | UI pod /admin: wyszukiwarka produktu, slider boost, dropdown TTL, action buttons | P1 | spec do napisania (legacy TASK-CHAT-009b) |
| T-XXX weekly notifications Editorial Picks | poniedziałek 9:00 CEST email + banner, 4 sekcje raportu | P2 | spec do napisania |
| T-004 (proponowany) | refresh_stock_only.py cron daily (CC propozycja po T-001) | P1 | propozycja, czeka na decyzję |
| T-005 (proponowany) | SynonymExpander rozbija multi-word frazy → FTS noise (CC propozycja po T-001) | P2 | propozycja, czeka na decyzję |
| T-XXX D1 ETL z pr_category | po hotfixach, trwałe rozwiązanie zastępujące D2-hybrid mapping | P2 | planowane |
| TASK-CHAT-014 audyt EXCLUDED_CATEGORY_IDS | po hotfixach, proaktywny audyt | P2 | spec gotowy |
| TASK-CHAT-008 alias map statusów BARTEK/LESZEK w OrderStatus.php | po hotfixach, defense in depth | P1 | nie zaczęte |

### Ostatni numer pytania Karola: 57

W konwersacji architekt zadał 57 ponumerowanych pytań decyzyjnych z rekomendacjami. Karol odpowiedział na wszystkie aktywne. Nieodpowiedziane w trakcie: 56 (T-004 refresh_stock), 57 (T-005 SynonymExpander) — czekają na decyzję.

### Ważne decyzje sesji 14.05

- ADR-053 SystemPrompt hardening (3 warstwy off-topic, anti-injection, statusy)
- ADR-054 Editorial Picks (manualny boost rankingu, wstrzymane do końca hotfixów)
- ADR-055 D2-hybrid mapping pseudokategorii (DEPLOYED jako T-002)
- Rezygnacja z cold-start auto-boost (Karol: "producenci wypuszczają nowości marketingowo, nie wszystkie są dobre")
- WYPRZEDAŻE jako kategoria PG zostają NULL (nie indeksujemy, decyzja Karol)

---

## OSTATNIA SESJA (2026-05-14 - T-002 D2-hybrid mapping 100% pokrycia)

**Status:** T-002 → DEPLOYED 2026-05-14, commit `f8cf156`. TASK-CHAT-012 zamknięty po tym fixie.

**Co zrobione:**
- Migracja `sql/010_pseudocategory_mapping.sql` (14 UPDATE statements, idempotentna)
- Backup table `divechat_product_embeddings_backup_20260514` (2561 wierszy) przed apply
- Hipoteza Karola zweryfikowana w MySQL: brand-only kategorie (TECLINE/SCUBAPRO/APEKS/POSEIDON/MARES/AQUALUNG/ATOMIC/XDEEP/SCUBATECH = 168 produktów) są subkategoriami "Automaty Oddechowe" w PrestaShop (id_parent=286) → dorzucone pod parent='Automaty Oddechowe'
- KLASYCZNE (16) + TURYSTYCZNE, LEKKIE (4) pod Wypornościowe (jackety) per decyzja Karola
- Ocieplacze do Suchych (70), Buty do suchego, Zawory do suchego, Torby na Suche, Manszety pod Skafandry suche (od scope rozszerzenie)
- Konsole/Manometry/Kompasy/Interfejsy/Węże do Manometrów/Analizatory tlenowe pod Komputery Nurkowe
- WYPRZEDAŻE (24) zostaje NULL (decyzja Karol — nie indeksujemy)

**Statystyki post-apply:**
- 2193 produkty (86%) pod parent_category_name dla 14 pseudokategorii zbiorczych
- 368 produktów (14%) bez parent — z czego 24 WYPRZEDAŻE + ~344 literal-only (działa przez ADR-027 first half OR)
- Wszystkie 14 UPDATE wykonane bez błędu (UPDATE 322/214/140/121/205/95/337/182/81/156/216/42/67/15)

**Integration tests:**
- SANTI w Skafandry suche: 36 produktów (przed: 0) ✅
- Komputery Nurkowe: SUUNTO 29, TECLINE 27, SHEARWATER 23, SCUBAPRO 23, MARES 20, GARMIN 15 + reszta ✅
- Regression literal (Maski jednoszybowe 68, Książki nurkowe 15) działa ✅

**Otwarte pytania:**
- Karol smoke test 5 zapytań przez UI (SANTI suchy, SHEARWATER komputer, akcesoria, BCD, latarka backup)
- Plus regression: maska jednoszybowa Tecline, książka nurkowa
- TASK-XXX D1 ETL z `pr_category` MySQL → PG parent_category_name jako trwałe rozwiązanie (zastąpi hardcoded mapping z D2-hybrid)

---

## OSTATNIA SESJA (2026-05-14 - TASK-CHAT-011 fix get_shop_schedule trigger)

**Status:** TASK-CHAT-011 → DEPLOYED 2026-05-14 16:14 CEST, commit `93f9fe8`

**Bug:** Po mini-patch v2 model produkcyjny halucynował godziny pracy (9-17) zamiast wywołać `get_shop_schedule` dla pośrednich form pytań typu "Chciałbym wpaść 6 czerwca po odbiór" (sobota, sklep zamknięty). Regresja bezpieczeństwa odpowiedzi.

**Diagnoza:**
- FAZA 1 (tool registration): ✅ OK — tool zarejestrowany, w 6 narzędziach exposed do LLM
- FAZA 3 (cache): ✅ OK — md5 lokalne = prod dla wszystkich plików, brak stale deploy
- FAZA 2 (wording): ⚠ root cause. Trigger "Gdy klient pyta o godziny pracy" nie pokrywa "wpadnę 6 czerwca" (klient pyta o odbiór, nie wprost o godziny)

**Fix C (A+B kombinacja, wybrany przez Karola):**
- Zastąpiono jednolinijkowy trigger rozbudowanym blokiem: "ZAWSZE wywołaj" + 4 grupy triggerów (plany przyjazdu / pytania o pracę / bieżący stan / cut-off wysyłki) + "NIGDY nie halucynuj godzin pracy bez tool call"
- Dodano 2 few-shot examples ("Chciałbym wpaść 6 czerwca po odbiór", "Pracujecie jutro?")
- Liczba wzmianek `get_shop_schedule` w prompcie: 1 → 5 (silniejszy sygnał dla modelu)
- Diff 31 linii, deploy via scp, backup hash zachowany w handoff dla rollbacku

**Otwarte pytania:**
- Karol smoke test 3 zapytania: (1) "Chcę przyjść 6 czerwca po odbiór" → tool call + "sobota zamknięte", (2) "Pracujecie jutro?" → tool call + odpowiedź, (3) "Jakie macie godziny pracy?" → bez toola, standard pon-pt 9-17
- Regression check: 16 ataków z poprzedniego retestu nadal przechodzi
- Opcjonalna zmiana description w `GetShopSchedule.php` (nie wdrożona — Karol nie wybrał)

---

## OSTATNIA SESJA (2026-05-14 - Mini-patch v2 SystemPrompt)

**Status:** Mini-patch v2 SystemPrompt → DEPLOYED 2026-05-14 15:48 CEST, commit `23de13e`

**Co zrobione:**
- 6 zmian w jednej paczce na `SystemPrompt.php`:
  1. Aktywacja `get_shop_schedule` (po deploy 007b — referencja przywrócona do DANE FIRMY)
  2. Język adaptywny PL/EN/inne — Bug A test #7
  3. Maski pełnotwarzowe (Ocean Reef Aria) wykluczone z rekomendacji do nurkowania ze sprzętem — Problem D test #1
  4. NAZEWNICTWO "Inne:" rozszerzone o Akcesoria nurkowe (logbooki), Prezenty, Vouchery prezentowe — Bug B test #14 + Test #8
  5. Nowa sekcja MAPOWANIE TERMINÓW KLIENTOWSKICH (logbook/voucher/prezent → kategorie + sugestia linku)
  6. Nowa sekcja MARKA KONKRETNA NIEDOSTĘPNA (najpierw info o pytanej marce na zamówienie, dopiero potem alternatywy) — Problem C test #6
- Deploy via scp na chat.divezone.pl. Backup hash przed: `a0c8990d...`, po: `00a64155...`. Verify: md5 lokalny=remote, `php -l` OK, wszystkie 6 fraz acceptance + Storczykowa 5 (regression 007a) potwierdzone.

**Otwarte pytania:**
- Karol robi smoke test 6 nowych zachowań przez UI + regression TOP 15 ataków.
- TASK-CHAT-007c (frontend) — bug formatowania linków produktów (osobny task).
- TASK-CHAT-008 — aliasy statusów (BARTEK→pakowanie) po deploy 007a.

---

## OSTATNIA SESJA (2026-05-14 - TASK-CHAT-007b ShopCalendar + tool get_shop_schedule)

**Status:** TASK-CHAT-007b → DEPLOYED 2026-05-14, commit `b26fe39`, awaiting mini-patch v2 SystemPrompt

**Co zrobione:**
- Klasa `ShopCalendar` z polskimi świętami stałymi (10) + ruchomymi (algorytm Gaussa, Wielkanoc/Poniedziałek Wielkanocny/Zielone Świątki/Boże Ciało). Stałe godziny pon-pt 9:00-17:00, strefa Europe/Warsaw.
- Interfejs `OverrideProvider` + adapter `DbOverrideProvider` (PG) — clean DI, testowalne offline bez DB.
- Tool `get_shop_schedule` zarejestrowany w `ToolRegistry`.
- Migracja 009 `divechat_shop_calendar_overrides` (urlopy/inwentaryzacje) — applied na Railway, struktura zweryfikowana.
- 39/39 testów OK (24 ShopCalendar + 15 GetShopSchedule), w tym weryfikacja Wielkanocy 2026-2030.
- Deploy: 6 plików PHP via scp + `composer dump-autoload` na prod. Smoke test produkcyjny OK.

**Stan toola:**
- Tool zarejestrowany ale **uśpiony** — model nie wywoła go dopóki SystemPrompt nie referuje. Po 007a (mini-patch forward-ref) sekcja DANE FIRMY zawiera fallback do standardowych godzin + odsyłki kontakt.
- Wymagany osobny mini-patch v2 SystemPrompt żeby aktywować `get_shop_schedule`.

**Otwarte pytania:**
- Mini-patch v2 SystemPrompt — kiedy uruchamiamy.
- Smoke test przez UI po mini-patch v2 ("czy będziecie pracowali 6 czerwca?" → bot powinien wywołać tool).
- TASK-CHAT-007c (frontend) — bug formatowania linków produktów.

---

## OSTATNIA SESJA (2026-05-14 - TASK-CHAT-007a SystemPrompt hardening)

**Status:** TASK-CHAT-007a → DEPLOYED 2026-05-14 13:54 CEST, commit `92083b7`

**Co zrobione:**
- Wykonano P0 hardening SystemPrompt.php pod ADR-053: dane firmy (Storczykowa 5 Toruń, 56 307 03 03, dive@divezone.pl, godziny pracy), naprawa list marek (FOURTH ELEMENT usunięte z ALLOWED, dodane DUI + Fourth Element do BANNED), 3-warstwowy off-topic (rozwiązuje case "kurczak"), TEMATY MEDYCZNE, STATUSY ZAMÓWIEŃ z few-shot, rozdzielenie dostępności od doręczenia (rozwiązuje N3), ZABEZPIECZENIA anti-injection, FORMAT ODPOWIEDZI z linkami w każdej odpowiedzi.
- Plik zmieniony: `standalone/src/Chat/SystemPrompt.php` (diff 220 linii, smoke test OK, 20984 bajtów).
- Artefakty: `/tmp/007a_diff.patch`, `/tmp/system_prompt_built.txt`.
- Raport: `_instances/backend/handoff/TASK-CHAT-007a_done.md`.

**Decyzja w trakcie wykonania (opcja A, Karol potwierdził):**
- ADR-053 pkt 2 ma błędną premisę. Backend tool to faktycznie `get_expert_knowledge` (nie `search_encyclopedia`) i `check_order_status` (nie `get_order_status`).
- KROK 2 zadania (rename tool name w prompcie) pominięty — wykonanie zepsułoby function calling.
- Do rozważenia osobny task na rename narzędzi w backendzie lub aktualizacja ADR-053.

**Stan aliasów statusów:**
- `_docs/aliasy_statusow_propozycja.csv` istnieje, ale NIE jest zaimplementowany w `OrderStatus.php`. Tool zwraca raw `osl.name` (BARTEK/LESZEK trafiają wprost do modelu). Pierwsza warstwa obrony przez prompt; defensywne alias map po stronie tool to osobny task (sugestia: TASK-CHAT-007d).

**Otwarte pytania:**
- Review diffa SystemPrompt.php przed deployem.
- TASK-CHAT-007b ShopCalendar (równoległa sesja CC) — prompt już referuje `get_shop_schedule`.
- TASK-CHAT-007c fix formatowania frontend (osobny task).
- Decyzja: ADR-053 pkt 2 fix vs backend tool rename.

---

## OSTATNIA SESJA (2026-04-30 - sesja 3, planowanie admin dashboard)

**Co przerobione:**
- Smoke test TASK-052 wykrył dwa problemy:
  1. Bug: dropdown "Reasoning effort" nie pokazuje się dla modeli rozumujących
  2. Brak admin dashboardu (analityka kosztów, lista rozmów)
- ADR-052 podjęty: osobna aplikacja chat.divezone.pl/admin/, basic auth na MVP,
  docelowo moduł PrestaShop. Faza 1 = tylko sekcja A (Koszty).
- Research wykonany: best practices admin dashboardów chatbotowych 2026
  (Langfuse, Helicone, LiteLLM, AI Vyuh FinOps). CPR benchmark: AI $0.30-1.50 vs
  human $5-15 per resolution.

**Taski wystawione (sekwencyjne):**
- TASK-053 (backend+frontend) - fix bug effort dropdown, P1
- TASK-054 (backend) - migracja 008: latency_ms, tool_calls, divechat_messages, ratings
- TASK-055 (backend+frontend) - admin dashboard faza 1, sekcja A (Koszty):
  KPI, wykres trendu (daily/weekly/monthly), top 10 najdroższych rozmów,
  breakdown per model. Modal podglądu rozmowy. Chart.js z CDN.

**Otwarte pytania:**
- Smoke test TASK-053/054/055 po wykonaniu
- Faza 2 dashboardu (sekcje B/C/D/E z ADR-052) - po fazie 1

---

## OSTATNIA SESJA (2026-04-30 - kontynuacja po incydencie secret scanning)

**Co przerobione:**
- Zbudowany arkusz testów pre-launch (138 scenariuszy w 11 kategoriach, plik `divezone_chat_testy.xlsx`).
- ADR-051 podjęty: panel admina – aktualizacja modeli (8 modeli z cenami), dual-control reasoning,
  kalkulacja kosztu rozmowy (USD + PLN/NBP), prompt caching uwzględniony.
- Decyzja: NIE używać DeepSeek do produkcji (GDPR risk: serwery w Chinach, brak SCC,
  zablokowane we Włoszech, badane w Belgii/Francji/Irlandii). Realna optymalizacja kosztów
  to zejście z GPT-4.1 na GPT-5 mini lub Haiku 4.5 (oszczędność ~3000 zł/mies bez ryzyka GDPR).

**Taski wystawione (sekwencyjne, każdy z STOP do review):**
- TASK-052a (backend) – migracja PG: tabele `divechat_model_pricing`, `divechat_message_usage`,
  `divechat_exchange_rates` + rozszerzenie `divechat_conversations` o agregaty kosztów.
- TASK-052b (backend) – `PricingService`, `UsageLogger`, `ExchangeRateService`,
  mapowanie reasoning effort (OpenAI string ↔ Claude budget_tokens), aktualizacja `AIModel` enum
  do 8 modeli, rozszerzenie response chat o `conversation_cost`.
- TASK-052c (frontend) – fix bug filtrowania providera, dropdown z cenami in/out,
  dual-control temperature+effort z reaktywnością na zmianę modelu, widget sumarycznego
  kosztu rozmowy w nagłówku, panel edycji cennika.

**Otwarte pytania do następnej sesji:**
- Status TASK-fix-promotional-prices (ADR-050) – czy zmergowany?
- Czy uruchamiamy Faza 1 implementacji order status (vision z poprzedniej sesji)?
- Pre-launch testing framework – kto wykonuje testy z pliku xlsx, kiedy.

---

## PODSUMOWANIE

Czat AI dla divezone.pl (PrestaShop 1.7.6). Wyszukiwanie hybrydowe produktów działa (95.7%).
Backend API (chat.divezone.pl, PHP 8.4) funkcjonuje z 5 narzędziami AI (function calling).
Encyklopedia sprzętu nurkowego: 105 haseł DONE, pipeline v2 (Evidence Registry + JSON Schema),
525 chunków w pgvector, zintegrowana z czatem przez ExpertKnowledge tool.
Aktualnie: fix rekomendacji produktów (dostępność + workflow encyklopedia→produkty).

## CO MAMY (DONE)

### Infrastruktura
- [x] PostgreSQL + pgvector na Railway (switchback.proxy.rlwy.net:14368)
- [x] Standalone API na chat.divezone.pl (PHP 8.4)
- [x] Embeddingi ~2500 produktów (text-embedding-3-large, 1536 dim)
- [x] Wyszukiwanie hybrydowe 5-track: semantic×3 + fulltext + trigram via RRF (95.7%)
- [x] LLM enrichment: search phrases ~2500 produktów
- [x] Security TASK-007 v2 (HMAC, nonce, XSS, RAG injection, medical disclaimers)
- [x] Golden dataset eval framework (integration)

### Encyklopedia sprzętu nurkowego (KOMPLETNA)
- [x] 105 haseł, 7043 linii markdown, 525 chunków w pgvector
- [x] Pipeline v2: Evidence Registry (8579 IDs) → Gemini JSON Schema → Validator → Renderer
- [x] 105/105 GREEN, 0 RED, 0 YELLOW, 0 fabricated evidence
- [x] Koszt generacji: $4.74, embedding: $0.03 — total $4.77
- [x] Tagi źródłowe deterministyczne (z evidence registry, nie od LLM)
- [x] Zintegrowana z czatem: ExpertKnowledge tool → encyclopedia_chunks (3072 dim)
- [x] UPSERT na (concept_key, chunk_type) — poprawki trywialne
- [x] Prompt v4 (JSON Schema): PROMPT_gemini_encyklopedia_v4_json.md

### Dane i wiedza
- [x] DataForSEO keywords: 1404 fraz (all_keywords.csv), koszt $0.45
- [x] DataForSEO questions (PAA + autocomplete): 1060 fraz, 137 PAA, koszt $0.33
- [x] Luigi's Box: dane wyszukiwania wewnętrznego
- [x] GSC: dane Google Search Console
- [x] Mapa marek: 79 marek z rekomendacjami (11_mapa_marek-reviewed.md)
- [x] NotebookLM v2: 130 haseł, 184KB (draft wejściowy)
- [x] Wiedza nurkowa: PADI, IANTD, nurkomania.pl
- [x] Dane sprzedażowe: cross-sell + bestsellery z MySQL (12 mies., 8680 zamówień)
- [x] Kwestionariusz eksperta: 21 grup (1-17, 19-21, brak 18), w tym sidemount (Gr.21)

### Pipeline encyklopedii (historia)
- [x] TASK-ENC-006: DataForSEO questions — DONE, $0.33
- [x] TASK-ENC-007: Cleanup transkrypcji — DONE, 21 grup
- [x] TASK-ENC-008/a/b/c: Skrypt + testy porównawcze → wybór Gemini 3.1 Pro (ADR-045)
- [x] TASK-ENC-009/a/b: Pipeline v1 batch (105 haseł) — odkryto problem halucynowanych tagów
- [x] TASK-ENC-011a: Completeness gate + evidence registry (8579 IDs, 105/105 zmapowanych)
- [x] TASK-ENC-011b: Gemini JSON Schema, 1 hasło/call, 105/105, $4.74 (ADR-046)
- [x] TASK-ENC-011c: Deterministic validator — 103 GREEN, 1 YELLOW, 1 RED → naprawione → 105 GREEN
- [x] TASK-ENC-011d: Markdown renderer + master report
- [x] TASK-ENC-012: Embedding 525 chunków do pgvector (text-embedding-3-large, 3072 dim)
- [x] TASK-CHAT-001: Integracja z czatem — ExpertKnowledge tool na encyclopedia_chunks (ADR-047)

### Dokumentacja
- [x] ADR-001 do ADR-047 w 10_decyzje_projektowe.md
- [x] Schemat bazy (02_schemat_bazy.md)
- [x] Reguły domenowe grup C-M (17_reguly_domenowe_grupy_C-M.md)
- [x] Cross-validation safeguards (prompt_cross_validation_safeguards.md)

## CO ROBIMY TERAZ (IN PROGRESS)

### TASK-CHAT-002: Fix rekomendacji produktów
Problem: czat poleca niedostępne produkty, nie używa encyklopedii przed szukaniem,
nie kieruje się popularnością/sprzedażą.
Zmiany:
1. in_stock_only domyślnie TRUE (było FALSE)
2. Boost dostępnych w RRF (score × 0.3 dla niedostępnych)
3. SystemPrompt: workflow encyklopedia → produkty, sekcja dostępność
Status: task napisany, czeka na CC backend

## CO CHCEMY MIEĆ (TODO)

### Priorytet 1: Czat (jakość odpowiedzi)
- [ ] TASK-CHAT-002: Fix dostępności + workflow encyklopedia→produkty — W TOKU
- [ ] Human review encyklopedii (ongoing, poprawki via --mode single)
- [ ] Testy end-to-end czatu z encyklopedią (scenariusze klienckie)

### Priorytet 2: Backend
- [ ] TASK-006c: thin PrestaShop module
- [ ] TASK_sales_sync: CRON synchronizacja danych sprzedażowych
- [ ] Order status: auto-show recent orders (logged-in), email+nr (non-logged)
- [ ] Group pricing: logged-in customers widzą swoje ceny

### Priorytet 3: Admin panel + monitoring
- [ ] Panel z tagowaniem konwersacji (wrong_product, wrong_info, etc.)
- [ ] Dashboard z metrykami: similarity scores, knowledge gaps, tool usage

### Priorytet 4: Frontend + integracja + beta
### Priorytet 5: SEO (blog, synonimy w opisach)

## KLUCZOWE DECYZJE (ostatnie)

| # | Data | Decyzja | ADR |
|---|------|---------|-----|
| 1 | 2026-03-03 | Gemini jako generator encyklopedii | ADR-038 |
| 2 | 2026-03-03 | Dane sprzedażowe MySQL jako kontekst | ADR-039 |
| 3 | 2026-03-03 | Honest parameters | ADR-040 |
| 4 | 2026-03-03 | Dual subtypes klienckie/techniczne | ADR-041 |
| 5 | 2026-03-03 | DataForSEO zamiast ATP | ADR-042 |
| 6 | 2026-03-05 | Max 5 haseł na partię | ADR-044 |
| 7 | 2026-03-05 | Gemini 3.1 Pro z zasadami #17-#20 | ADR-045 |
| 8 | 2026-03-06 | Pipeline v2: Evidence Registry + JSON Schema + Validator | ADR-046 |
| 9 | 2026-03-06 | Integracja encyklopedii przez ExpertKnowledge tool | ADR-047 |

## INSTANCJE CLAUDE CODE

| Instancja | Aktywny task | Status |
|-----------|-------------|--------|
| backend | TASK-CHAT-002 fix rekomendacji | NASTĘPNY |
| embeddings | — | Encyklopedia DONE |
| frontend | — | Czeka na backend |
| integration | — | Eval framework gotowy |

## PLIKI REFERENCYJNE

| Plik | Status |
|------|--------|
| 21_STATUS_PROJEKTU.md | TEN PLIK, v3.0 |
| HANDOFF_sesja_2026-03-09.md | Aktualny handoff |
| 10_decyzje_projektowe.md | ADR-001 do ADR-047 |
| PROMPT_gemini_encyklopedia_v4_json.md | Pipeline v2, JSON Schema |
| TASK-CHAT-002_fix_product_recommendations.md | AKTYWNY |

## KLUCZOWE PLIKI DANYCH

```
data/encyclopedia/v3/gen_v2/
├── MASTER_REPORT.md              ← raport generacji encyklopedii
├── encyclopedia_v3_all.md        ← 105 haseł, 7043 linii (do human review)
├── evidence/                     ← 105 plików evidence registry
├── raw/                          ← 105 plików JSON z Gemini
├── validation/                   ← 105 plików walidacji (105 GREEN)
├── rendered/                     ← 105 plików markdown
└── validation_summary.json       ← podsumowanie walidacji

standalone/src/
├── Tools/ExpertKnowledge.php     ← query na encyclopedia_chunks (3072 dim)
├── Tools/ProductSearch.php       ← 5-track hybrid search (1536 dim)
├── Chat/SystemPrompt.php         ← instrukcje workflow
└── Chat/ChatService.php          ← orkiestrator z tool loop

SQL tabele:
├── divechat_product_embeddings   ← ~2500 produktów, 1536 dim
└── encyclopedia_chunks           ← 525 chunków, 3072 dim, UPSERT ready
```

## OSTATNIA SESJA (2026-04-30 - kontynuacja po incydencie secret scanning)

**Co przerobione:**
- Incydent secret scanning Github (Aiven password w handoffie z lutego)
- Reset hard 4 commitow 052, czysty replay tylko produkcyjnego kodu
- Globalny audyt historii git: 13 plików handoff/sesja/deploy_log usuniętych przez `git filter-repo --invert-paths`
- `git filter-repo --replace-text`: zastąpienie żywych sekretów (Railway password, Aiven host) na placeholdery w plikach produkcyjnych (CLAUDE.md, ADRs, schemat_bazy.md)
- Rozszerzony .gitignore (handoffy, deploy logi, audyty zostają lokalne)
- ADR-051 (panel modeli, dual-control, koszty) i ADR-051a (korekta migracji)
- Migracja 007 wykonana na PG, cennik 8 modeli zaseedowany
- Backend deploy: PricingService, UsageLogger, ExchangeRateService, reasoning effort mapping, conversation_cost w response
- Frontend deploy: filtr providera, dropdown z cenami, dual-control, widget kosztu, panel cennika
- Cron NBP zainstalowany (09:00 daily). Kurs 30.04: 3.6460 PLN/USD.
- Push do GitHub: ✅ origin/main = HEAD = c8471c2

**Status taskow:**
- TASK-052a, TASK-052b, TASK-052c: DONE

**Otwarte pytania:**
- Smoke test w UI panelu admina (do wykonania przez Karola)
