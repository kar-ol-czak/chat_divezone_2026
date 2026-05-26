# HANDOFF: Widget czatu na sklep divezone.pl (nowa konwersacja)

**Data:** 2026-05-15 | **Cel konwersacji:** design + wdrożenie produkcyjnego widgetu czatu AI osadzonego na sklepie divezone.pl (PrestaShop 1.7.6)
**Poprzednia konwersacja:** cykl napraw T-001…T-016 (backend + admin), wszystko DEPLOYED. Ta konwersacja przenosi projekt z "backend gotowy" do "widget dla klientów na sklepie".

## Kim jesteś (rola w nowej konwersacji)

Jesteś architektem projektu. Decyzje architektoniczne, code review, planowanie tasków dla Claude Code (CC), specyfikacje, ADR. NIE kodujesz — od kodowania są instancje CC. Do designu UI/UX widgetu Karol chce użyć Claude Design (osobne narzędzie do mockupów/projektowania interfejsu).

Konwencje (BEZ ZMIAN z poprzedniej konwersacji):
- Taski numerowane narastająco T-NNN, plik `T-NNN_INSTANCJA_opis.md` w `_instances/{instancja}/tasks/`
- Cała treść w pliku, prompt CC w czacie max 3 linie
- Nagłówek promptu CC: `>>> T-NNN — INSTANCJA: {backend|frontend|embeddings|integration} <<<`
- Git workflow w każdym prompcie CC (git status, add per ścieżka, commit "T-NNN: opis", push origin main; osobny commit docs:)
- STOP point przed deploy, czekaj na akceptację Karola
- Każde pytanie do Karola = numerowane + Twoja rekomendacja
- Polski, zwięźle, bez ścian tekstu
- Numeracja pytań kontynuowana (ostatnie w poprzedniej konwersacji: ~81)
- ADR w `_docs/10_decyzje_projektowe.md` (ostatni: ADR-059)

## Folder projektu

`/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026`
Dostęp przez Desktop Commander. Git repo aktywne (origin/main).

## STAN OBECNY — co działa na produkcji

Backend chatu w pełni funkcjonalny na `chat.divezone.pl`. Pakiet zamknięty:

**Pakiet hotfixów (T-001…T-007):** D2-hybrid mapping kategorii, SystemPrompt patche (PORADY PREZENTOWE, PL/EN, bold ceny, NAZEWNICTWO, krój/płeć, available_to_order, linki, generalizacja statusów).

**Editorial Picks (T-008…T-013):** manualny boost rankingu produktów. Backend (tabela divechat_editorial_picks, RRF integration, cron auto-expire, API CRUD) + admin UI (panel chat.divezone.pl/admin).

**D1 ETL (T-009, T-010):** parent_category_name z pr_category + tabela aliasów divechat_category_aliases. 96.7% pokrycia. D2-hybrid deprecated.

**Naprawy z testów pracowników (T-014, T-015, T-016):**
- T-014: dane wysyłki z tabeli PG (divechat_shipping_rates + divechat_shop_config), tool get_shipping_info. ADR-059.
- T-015: blacklista marek (divechat_brand_blacklist, Aquazone), price floor (budżet ×0.25), boolean re-rank.
- T-016: SystemPrompt v7 — 9 patchy (EN wzmocnienie, instrukcja wysyłki PL/EU, brand_blacklisted, zwięzłość, brand fidelity, CTA, fakty domenowe, logika kontekstu).

## ARCHITEKTURA (kluczowa dla widgetu)

**Backend:** PHP 8.4 standalone app na VPS (chat.divezone.pl), serwowany przez `standalone/public/index.php`. Routing w `standalone/config/routes.php`.

**Bazy:**
- PostgreSQL (Railway, GCP) — pgvector embeddingi produktów (divechat_product_embeddings), konwersacje (divechat_conversations), pricing (divechat_model_pricing), Editorial Picks, aliasy kategorii, shipping rates, brand blacklist, shop config.
- MySQL PrestaShop (prefix pr_, shop ID 1) — real-time stock/ceny przez enrichWithMySQLData().

**API endpointy czatu (już istnieją, gotowe dla widgetu):**
- `POST /api/chat/stream` — SSE streaming (główny kanał, token-based)
- `POST /api/chat` — klasyczny fetch fallback
- `GET /api/conversations`, `/api/conversations/{session_id}` — historia
- Token auth: nagłówki `X-DiveChat-Token`, `X-DiveChat-Customer`, `X-DiveChat-Time`

**CORS JUŻ SKONFIGUROWANY** (standalone/src/Http/Response.php::setCorsHeaders):
allowed origins: `https://divezone.pl`, `https://www.divezone.pl`, `https://dev.divezone.pl`, `https://chat.divezone.pl`, `http://localhost:3000`.
To znaczy: widget osadzony na divezone.pl MOŻE wołać API na chat.divezone.pl cross-origin OD RAZU.

**Frontend tools (AI):** search_products (5-track RRF hybrid), get_expert_knowledge (encyklopedia 105 wpisów), check_order_status, get_shop_schedule, get_shipping_info, product details.

**SystemPrompt:** standalone/src/Chat/SystemPrompt.php (520 linii, v7).

## TRZY INTERFEJSY (nie myl)

1. **Panel admin** chat.divezone.pl/admin — Editorial Picks, koszty, podgląd konwersacji. Basic auth. GOTOWY, nie ruszać.
2. **Czat testowy** chat.divezone.pl (standalone/public/index.html + js/chat.js) — surowy interfejs do QA. SSE streaming. To NIE jest docelowy widget.
3. **Widget produkcyjny na sklep** divezone.pl — DOCELOWY, NIE ISTNIEJE jeszcze. Cel tej konwersacji.

## PUNKT WYJŚCIA DLA WIDGETU

Istnieje PUSTY szkielet modułu PrestaShop: `modules/divezone_chat/` (controllers/front, views/css+js, classes/tools) — 0 plików, przygotowany ale niewypełniony. Decyzja architektoniczna do podjęcia: czy widget jako moduł PrestaShop, czy jako wstrzykiwany skrypt JS (snippet w theme), czy iframe.

## OTWARTE KWESTIE DO ZAPROJEKTOWANIA (agenda nowej konwersacji)

1. **Sposób osadzenia w PrestaShop 1.7.6:**
   - Moduł PrestaShop (hook displayFooter / displayHeader) — czysty, ale wymaga instalacji modułu
   - Snippet JS w theme (custom.js lub hook) — prostszy, ale modyfikuje theme
   - Iframe — izolowany, ale ograniczenia UX (cookie, wysokość)
   Rekomendacja wstępna: moduł PrestaShop z displayFooter wstrzykujący floating widget (czysty, deinstalowalny, nie modyfikuje core theme).

2. **Forma widgetu:** floating bubble (prawy dolny róg) rozwijany do okna czatu. Mobile: fullscreen overlay. Standard e-commerce.

3. **Branding:** kolory divezone.pl, logo, ton. Do ustalenia z Claude Design.

4. **Token/auth dla widgetu:** jak generować X-DiveChat-Token dla anonimowego klienta sklepu (obecnie token-based). Czy sesja per visitor, czy powiązanie z zalogowanym klientem PrestaShop (id_customer).

5. **RODO/cookie consent:** widget czatu zbiera dane konwersacji. Integracja z cookie banner sklepu, polityka prywatności, retencja konwersacji.

6. **Kontekst klienta:** czy widget przekazuje do bota kontekst (zalogowany klient, zawartość koszyka, oglądany produkt) przez X-DiveChat-Customer. To otwiera personalizację ("widzę że oglądasz SANTI E.Lite, chętnie doradzę").

7. **Streaming w widgecie:** SSE działa cross-origin? Token w nagłówku przy EventSource (EventSource nie wspiera custom headers — może wymagać query param token lub fetch-based streaming).

8. **Performance:** lazy-load widgetu (nie blokować ładowania sklepu), bundle size, CDN.

## BACKLOG (z poprzedniej konwersacji, niezwiązany z widgetem)

- Combination stock per wariant (test 102) — ProductSearch MAX(quantity) GROUP BY pr_product_attribute
- Encyklopedia gaps (Peregrine TX, Ammonite latarki — testy 42/47)
- Seed stawek EU dla shipping (Karol poda dane)
- UI do edycji shop_config/shipping_rates/blacklisty/aliasów
- Leaf cat alias mechanism w D1 ETL (Karabinki/Retraktory → Bezpieczeństwo)
- Weekly notifications cron dla Editorial Picks
- T-004 refresh_stock cron, T-005 SynonymExpander multi-word (P2/P3)

## PIERWSZE KROKI W NOWEJ KONWERSACJI

1. Przeczytaj ten handoff + `_docs/21_STATUS_PROJEKTU.md` (sekcja AKTUALNY STAN)
2. Potwierdź zrozumienie architektury (3 interfejsy, CORS gotowy, API endpointy)
3. Z Karolem przejdź agendę (8 otwartych kwestii) — zacznij od kwestii 1 (sposób osadzenia) bo determinuje resztę
4. Po decyzjach architektonicznych → ADR + taski CC + ewentualnie sesja Claude Design dla mockupów
