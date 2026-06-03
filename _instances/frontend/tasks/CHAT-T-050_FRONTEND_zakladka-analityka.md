# CHAT-T-050 — FRONTEND/PS: zakładka „Analityka" (KPI + trend + by-model)

**Instancja:** frontend (moduł PrestaShop)
**Powiązane:** CHAT-T-049 (backend Analityki — kanał serwerowy admin-only, WDROŻONY), ADR-074, CHAT-T-048 (wzorzec zakładki + callBackend), CHAT-T-052 (wzorzec layoutu/CSS).
**Decyzje:** 107a/108a (admin-only), 109a (top→link do Rozmów), 119a (Chart.js CDN), 120a (PHP osadza dane, JS tylko rysuje).
**Plik:** modules/divezone_chat/controllers/admin/AdminDivezoneChatController.php. Backend GOTOWY. Karol wgrywa ręcznie (116b).

## Architektura (120a — kluczowe)
Kontroler woła przez callBackend (admin-only): GET /api/admin/cost/kpi, /api/admin/cost/trend?period={p}&days={d}, /api/admin/cost/by-model?days={d}, /api/admin/conversations/top?limit={l}&days={d}.
KPI/by-model/top renderowane server-side (PHP). Dane trendu osadzane jako JSON (<script type="application/json" id="dz-trend-data">), JS czyta i rysuje. NIE fetch z przeglądarki do API (brak HMAC w JS). Filtry days(7/30/90)/period(daily/weekly/monthly) przez RELOAD z URL (?tab=analytics&days=30&period=daily).

## NOTA O ROLI (admin-only) — sprawdzić w KROK 0
Endpointy są admin-only (403 dla operatora). callBackend wysyła employee_id zalogowanego pracownika PS. CC ma najpierw sprawdzić, czy kontroler zna lokalnie rolę employee:
- jeśli zna → ukryć link „Analityka" w nav dla nie-adminów;
- niezależnie → przy 403 z któregokolwiek z wywołań pokazać czytelny komunikat „Analityka dostępna tylko dla administratorów", NIE biały ekran/błąd.
Jeśli niejasne jak poznać rolę — zadać pytanie w raporcie, nie zgadywać.

## Kontrakt danych (zweryfikowany w CostAnalytics)
- kpi: today/this_week/this_month {cost_usd,cost_pln,conversations,messages}; cost_per_resolution {this_month_usd,this_month_pln,industry_benchmark_usd,vs_human_agent_usd}; currency_pln_rate.
- trend: {period,days,currency_pln_rate, data:[{date,cost_usd,cost_pln,messages,conversations}], totals:{cost_usd,cost_pln,messages,conversations}}.
- by-model: {days, models:[{model_id,label,provider,uses,input_tokens,output_tokens,cache_read_tokens,cost_usd,cost_pln,avg_cost_per_use_usd,avg_latency_ms}]}.
- conversations/top: {limit,days, conversations:[{id,session_id,started_at,updated_at,model_used,messages_count,cost_usd,cost_pln,first_user_message}]}.

## Zakres
1. Zakładka: const TAB_ANALYTICS='analytics' + stałe endpointów; whitelist w initContent + routing renderAnalyticsSection($employeeId); nav (118a): Rozmowy | Rekomendacje | Analityka | Modele | Konfiguracja (Rozmowy zostają pierwsze/domyślne).
2. renderAnalyticsSection: czyta days (7/30/90, dflt 30) i period (daily/weekly/monthly, dflt daily) z Tools::getValue (whitelist!). Woła 4 endpointy. Obsługa 403 jak wyżej. Sekcje:
   a) Filtry (GET form, hidden controller/token/tab): select days, select period, submit „Pokaż". Zachowane w URL.
   b) Karty KPI: 3 karty (Dziś/Tydzień/Miesiąc) — koszt PLN (gł.)+USD, rozmowy, wiadomości. + karta „Koszt na rozmowę (miesiąc)": cost_per_resolution PLN/USD + benchmark tekstowy jako podpis.
   c) Wykres trendu (Chart.js 119a): <canvas> + osadzony JSON z trend.data. Odtworzyć config ze standalone/public/admin/js/admin-charts.js (type line, dataset PLN borderColor #0066cc fill, tooltip PLN+USD+rozmowy, oś Y PLN, oś X daty). CDN: https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js. Guard typeof Chart==='undefined'. Puste data → „Brak danych w okresie".
   d) Tabela by-model: Model(label), Provider, Użycia, Tokeny in/out(+cache), Koszt PLN/USD, Śr.koszt/użycie, Śr.latencja ms. Sort wg kosztu (backend już sortuje). Pusta → „Brak danych".
   e) TOP rozmów (109a): tabela Data, Model, Wiad., Koszt PLN/USD, Pierwsza wiadomość (~80 zn). Wiersz = LINK do &tab=conversations&session_id={session_id}. limit 10, days=filtr. NIE osobny widok rozmowy.
3. CSS: klasy dz-analytics-* w renderTabsStyles (karty, siatka, wykres-wrapper), spójne z dz-*. JS: pierwszy JS w kontrolerze admina (dotąd server-side) — inline <script> w renderze, tylko init wykresu z osadzonego JSON. Chart.js przez <script src=CDN>. CSP/CDN: jeśli CDN nie załaduje (CSP), wykres degraduje gracefully (guard), reszta zakładki działa — CC odnotuje w raporcie.

## Granice
Bez zmian backendu. NIE osobny widok rozmowy (109a→link). NIE live-fetch z przeglądarki (120a). Bez ruszania innych zakładek poza linkiem w nav. Bez wdrażania modułu (116b). PHP 7.2/PS 1.7.6 (bez typed props, bez match).

## Kryteria akceptacji
1. Zakładka Analityka w nav, renderuje dane z backendu.
2. Karty KPI: dziś/tydzień/miesiąc (PLN+USD+rozmowy+wiadomości) + koszt na rozmowę.
3. Wykres trendu rysuje trend.data; pusty → komunikat; brak Chart.js (CSP) → graceful.
4. Tabela by-model: wszystkie pola, sort wg kosztu.
5. TOP rozmów linkuje do zakładki Rozmowy po session_id (109a).
6. Filtry days/period działają przez reload, zachowane w URL.
7. Operator (403) → czytelny komunikat, nie błąd.
8. JS tylko rysuje osadzony JSON; zero fetch do API z przeglądarki.
9. php -l clean; PHP 7.2/PS 1.7.6.
