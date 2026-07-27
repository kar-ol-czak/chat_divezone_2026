# TASK-055: Admin dashboard - faza 1 (Koszty)

**Status:** TODO  
**Instancja:** backend + frontend  
**Powiązany ADR:** ADR-052  
**Zależności:** TASK-053 i TASK-054 zmergowane i zdeployowane

---

## Cel

Zbudować admin dashboard pod `chat.divezone.pl/admin/` z sekcją A (Koszty) z ADR-052:
- A1: Wykres trendu wydatków (daily/weekly/monthly)
- A2: Cost per Resolution (KPI w nagłówku)
- A3: Top 10 najdroższych rozmów
- A4: Breakdown kosztów per model

Autoryzacja: HTTP Basic Auth przez .htaccess (1 użytkownik MVP).

---

## Architektura

```
standalone/
├── public/
│   ├── index.html        (konsola testowa - bez zmian)
│   ├── admin/
│   │   ├── .htaccess     (basic auth + nginx alternative)
│   │   ├── .htpasswd     (lokalnie, w .gitignore)
│   │   ├── index.html    (dashboard layout)
│   │   ├── css/
│   │   │   └── admin.css
│   │   └── js/
│   │       ├── admin.js          (init, routing zakładek)
│   │       ├── admin-charts.js   (Chart.js trendy)
│   │       ├── admin-tables.js   (top N rozmów, breakdown per model)
│   │       └── admin-conversation.js (modal podgląd rozmowy)
│   └── ...
├── src/
│   ├── Controller/
│   │   └── AdminController.php   (NOWY - endpointy /api/admin/*)
│   └── Admin/
│       ├── CostAnalytics.php     (NOWY - agregaty kosztów)
│       └── ConversationViewer.php (NOWY - pobranie pełnej rozmowy)
```

---

## Backend (PHP)

### 1. `standalone/public/admin/.htaccess`

```apacheconf
AuthType Basic
AuthName "DiveChat Admin"
AuthUserFile /home/divezone/public_html/chat.divezone.pl/admin/.htpasswd
Require valid-user

# Cache busting dla JS/CSS
<FilesMatch "\.(js|css)$">
    Header set Cache-Control "no-cache, must-revalidate"
</FilesMatch>
```

### 2. Generowanie `.htpasswd`

CC pyta Karola o hasło lub generuje losowe i zapisuje w handoff:

```bash
# Generuj losowe hasło 24-znakowe
ADMIN_PASSWORD=$(openssl rand -base64 18)
htpasswd -bc /home/divezone/public_html/chat.divezone.pl/admin/.htpasswd karol "$ADMIN_PASSWORD"
echo "Hasło dla logina 'karol': $ADMIN_PASSWORD" 
# Zapisz w _instances/backend/handoff/055_admin_credentials.md (NIE commituj - .gitignore)
```

Dodaj `.htpasswd` do `.gitignore` jeśli nie jest już ignored przez `_instances/*/handoff/`.

### 3. Defense in depth - sprawdzanie auth w PHP

Wszystkie endpointy `/api/admin/*` mają middleware sprawdzające basic auth:

```php
// W src/Http/AdminAuthMiddleware.php (lub bezpośrednio w AdminController)
public function checkAuth(): void
{
    if (!isset($_SERVER['PHP_AUTH_USER'])) {
        header('WWW-Authenticate: Basic realm="DiveChat Admin"');
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    // Verify against .htpasswd (htpasswd_check function lub use Apache)
    if (!$this->verifyAgainstHtpasswd($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}
```

### 4. Endpointy w `AdminController.php`

#### 4.1. `GET /api/admin/cost/trend?period=daily|weekly|monthly&days=30`

Trend kosztów dla wykresu A1.

**Response:**
```json
{
  "period": "daily",
  "currency_pln_rate": 4.05,
  "data": [
    {"date": "2026-04-01", "cost_usd": 0.234, "cost_pln": 0.948, "messages": 42, "conversations": 8},
    {"date": "2026-04-02", "cost_usd": 0.567, "cost_pln": 2.296, "messages": 89, "conversations": 15},
    ...
  ],
  "totals": {
    "cost_usd": 12.456,
    "cost_pln": 50.45,
    "messages": 1234,
    "conversations": 234
  }
}
```

SQL (daily):
```sql
SELECT 
    DATE(mu.created_at) AS date,
    SUM(mu.cost_total_usd) AS cost_usd,
    COUNT(*) AS messages,
    COUNT(DISTINCT mu.conversation_id) AS conversations
FROM divechat_message_usage mu
WHERE mu.created_at >= NOW() - INTERVAL ':days days'
GROUP BY DATE(mu.created_at)
ORDER BY date ASC
```

Weekly: `DATE_TRUNC('week', mu.created_at)`. Monthly: `DATE_TRUNC('month', ...)`.

#### 4.2. `GET /api/admin/cost/kpi`

KPI w nagłówku A2.

**Response:**
```json
{
  "today": {"cost_usd": 0.45, "cost_pln": 1.82, "conversations": 12},
  "this_week": {"cost_usd": 3.21, "cost_pln": 13.00, "conversations": 87},
  "this_month": {"cost_usd": 12.45, "cost_pln": 50.43, "conversations": 234},
  "cost_per_resolution": {
    "this_month_usd": 0.053,
    "this_month_pln": 0.215,
    "industry_benchmark_usd": "0.30 - 1.50",
    "vs_human_agent_usd": "5.00 - 15.00"
  }
}
```

#### 4.3. `GET /api/admin/conversations/top?limit=10&days=30`

Top N najdroższych rozmów A3.

**Response:**
```json
{
  "conversations": [
    {
      "id": 234,
      "session_id": "abc123",
      "started_at": "2026-04-29T14:23:00Z",
      "ended_at": "2026-04-29T14:38:00Z",
      "model_primary": "claude-opus-4-7",
      "messages_count": 42,
      "cost_usd": 1.234,
      "cost_pln": 5.00,
      "first_user_message": "Szukam suchego skafandra do nurkowań w Polsce..."
    },
    ...
  ]
}
```

SQL:
```sql
SELECT 
    c.id, c.session_id, c.started_at, c.ended_at,
    c.estimated_cost AS cost_usd,
    COUNT(m.id) AS messages_count,
    (SELECT content FROM divechat_messages 
     WHERE conversation_id = c.id AND role = 'user' 
     ORDER BY created_at LIMIT 1) AS first_user_message,
    (SELECT model_id FROM divechat_message_usage 
     WHERE conversation_id = c.id 
     ORDER BY created_at LIMIT 1) AS model_primary
FROM divechat_conversations c
LEFT JOIN divechat_messages m ON m.conversation_id = c.id
WHERE c.started_at >= NOW() - INTERVAL ':days days'
  AND c.estimated_cost > 0
GROUP BY c.id
ORDER BY c.estimated_cost DESC
LIMIT :limit
```

#### 4.4. `GET /api/admin/cost/by-model?days=30`

Breakdown per model A4.

**Response:**
```json
{
  "models": [
    {
      "model_id": "claude-haiku-4-5",
      "label": "Claude Haiku 4.5",
      "provider": "claude",
      "uses": 1234,
      "input_tokens": 45678,
      "output_tokens": 12345,
      "cache_read_tokens": 8901,
      "cost_usd": 1.23,
      "cost_pln": 4.98,
      "avg_cost_per_use_usd": 0.001,
      "avg_latency_ms": 1234
    },
    ...
  ]
}
```

#### 4.5. `GET /api/admin/conversations/:id`

Pełna rozmowa do modala B2.

**Response:**
```json
{
  "id": 234,
  "session_id": "abc123",
  "started_at": "...",
  "ended_at": "...",
  "messages": [
    {
      "id": 1001,
      "role": "user",
      "content": "Szukam suchego...",
      "created_at": "...",
      "tool_calls": null,
      "rating": null
    },
    {
      "id": 1002,
      "role": "assistant",
      "content": "Polecam Santi Avatar...",
      "created_at": "...",
      "tool_calls": [{"name": "search_products", "args": {"query": "suchy skafander"}}],
      "rating": null,
      "usage": {
        "model_id": "claude-haiku-4-5",
        "input_tokens": 234,
        "output_tokens": 89,
        "latency_ms": 1234,
        "cost_usd": 0.0023
      }
    }
  ]
}
```

---

## Frontend (HTML/JS/CSS)

### 1. `public/admin/index.html` - layout

```
+--------------------------------------------------------+
| DiveChat Admin                          karol [logout] |
+--------------------------------------------------------+
| [Koszty]  [Rozmowy]*  [Cennik]*  [Ustawienia]*         |  (* = grayed out, "Wkrótce")
+--------------------------------------------------------+
| KPI                                                    |
| ┌──────────┬──────────┬───────────┬─────────────────┐  |
| │ Dziś     │ Tydzień  │ Miesiąc   │ CPR (vs human)  │  |
| │ 1.82 zł  │ 13.00 zł │ 50.43 zł  │ 0.22 zł / 5-15$ │  |
| │ 12 rozm. │ 87 rozm. │ 234 rozm. │                 │  |
| └──────────┴──────────┴───────────┴─────────────────┘  |
+--------------------------------------------------------+
| Trend wydatków       [daily] [weekly] [monthly]        |
| ┌────────────────────────────────────────────────────┐ |
| │                                                    │ |
| │  (Chart.js line chart - PLN na Y, daty na X)       │ |
| │                                                    │ |
| └────────────────────────────────────────────────────┘ |
+--------------------------------------------------------+
| Top 10 najdroższych rozmów (30 dni)                    |
| ┌────────┬───────┬───────┬────────┬──────────────────┐ |
| │ Data   │ Model │ Wiad. │ Koszt  │ Pierwsza wiad.   │ |
| ├────────┼───────┼───────┼────────┼──────────────────┤ |
| │ 04-29  │ Opus  │ 42    │ 5.00 zł│ "Szukam suche..."│→  | klik = modal
| │ ...    │       │       │        │                  │ |
| └────────┴───────┴───────┴────────┴──────────────────┘ |
+--------------------------------------------------------+
| Breakdown per model (30 dni)                           |
| ┌─────────────┬──────┬─────────┬──────────┬─────────┐  |
| │ Model       │ Użyć │ Tokens  │ Koszt    │ Avg/use │  |
| ├─────────────┼──────┼─────────┼──────────┼─────────┤  |
| │ Haiku 4.5   │ 234  │ 45k/12k │ 4.98 zł  │ 0.02 zł │  |
| │ Sonnet 4.6  │ 89   │ 23k/8k  │ 12.45 zł │ 0.14 zł │  |
| │ ...         │      │         │          │         │  |
| └─────────────┴──────┴─────────┴──────────┴─────────┘  |
+--------------------------------------------------------+
```

### 2. Chart.js z CDN

W `<head>`:
```html
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
```

Bez build, bez bundlera. Vanilla JS spójne z konsolą testową.

### 3. Modal podglądu rozmowy

Klik na wiersz w "Top 10" otwiera modal:
- Header: data, model, koszt, liczba wiadomości
- Body: lista wiadomości w stylu chatu (user prawo, assistant lewo)
- Per assistant: małe metadane (model, latency, koszt) w bocznej kolumnie
- Footer: przycisk "Zamknij"

### 4. Format liczb

- USD: 4 miejsca po przecinku do $0.01, 2 miejsca powyżej (np. `$0.0234`, `$1.23`)
- PLN: 2 miejsca po przecinku zawsze (np. `0.09 zł`, `5.18 zł`)
- Tokens: separator tysięcy (np. `1 234`)
- Daty: `2026-04-29 14:23` (bez timezone, lokalna)

---

## Kryteria akceptacji

1. URL `https://chat.divezone.pl/admin/` wymaga basic auth
2. Po zalogowaniu widoczne 4 sekcje (KPI, Trend, Top 10, Breakdown)
3. Wykres trendu się aktualizuje przy zmianie daily/weekly/monthly
4. Klik na wiersz w "Top 10" otwiera modal z pełną rozmową
5. KPI pokazuje koszt w USD i PLN (kurs z `divechat_exchange_rates`)
6. Cost per Resolution jest liczony jako `total_cost_pln / conversations` z bieżącego miesiąca
7. Breakdown per model sumuje się do KPI miesięcznego (kontrola spójności danych)
8. Stan pusty (jeśli brak danych) - ładny komunikat "Brak rozmów w tym okresie", nie błędy JS

---

## Sub-STOPy

1. .htaccess + endpoint `/api/admin/cost/kpi` + KPI w UI - test pierwszej wersji
2. Wykres trendu (daily) - dodanie endpointu i Chart.js
3. Toggle weekly/monthly
4. Top 10 + modal rozmowy
5. Breakdown per model
6. Smoke test E2E

Po każdym sub-STOP commit + push + deploy + sygnał do Karola żeby sprawdził.

---

## Czego NIE robić

- NIE buduj sekcji B/C/D/E (są planowane na kolejne fazy)
- NIE dodawaj live updates (websockets, SSE) - polling co 60s przy aktywnym dashboardzie wystarczy, MVP może być nawet bez polling
- NIE dodawaj filtrów daty custom (preset 30 dni wystarczy)
- NIE rób eksportu CSV/Excel (faza 2)
- NIE dodawaj logowania innego niż basic auth
- NIE modyfikuj konsoli testowej `/`
- NIE rotuj kluczy API ani haseł

---

## Deploy

```bash
# rsync standalone na serwer
rsync -avz --exclude='.env' --exclude='vendor/' --exclude='.DS_Store' --exclude='.htpasswd' \
  -e "ssh -p 5739" \
  ./standalone/ \
  divezone@divezonededyk.smarthost.pl:/home/divezone/public_html/chat.divezone.pl/

# Wgraj .htpasswd osobno (NIE jest w repo)
scp -P 5739 ./standalone/public/admin/.htpasswd \
  divezone@divezonededyk.smarthost.pl:/home/divezone/public_html/chat.divezone.pl/admin/.htpasswd

# Test
curl -u karol:HASLO https://chat.divezone.pl/api/admin/cost/kpi
# Oczekiwane: 200 + JSON
curl https://chat.divezone.pl/api/admin/cost/kpi
# Oczekiwane: 401 Unauthorized
```

---

## Raport końcowy

W `_instances/backend/handoff/055_deploy_report.md`:
1. Login + hasło dla Karola (basic auth)
2. URL dashboardu
3. Lista zaimplementowanych endpointów z przykładowymi response
4. Lista commitów
5. Status sub-STOPów (PASS/FAIL)
6. Lista TODO dla Karola - sprawdź dashboard pod URL z hasłem
