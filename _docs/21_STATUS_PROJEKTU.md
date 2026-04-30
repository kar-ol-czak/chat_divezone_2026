# STATUS PROJEKTU: Czat AI divezone.pl
# Wersja: 3.1 | Data: 2026-04-30
# Aktualizowany ręcznie po każdej sesji architekta

---

## OSTATNIA SESJA (2026-04-30)

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
