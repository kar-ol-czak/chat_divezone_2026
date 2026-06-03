# CHAT-T-048 — FRONTEND/PS: zakładka „Rozmowy" + ustawienie jako domyślnej

**Instancja:** frontend (moduł PrestaShop, render natywny PHP)
**Powiązane:** handoff `_docs/25_handoff_migracja_panelu_do_PS.md` (117a/118a), ADR-070 (panel PS jedyny front), ADR-068 (kanał serwerowy), CHAT-T-046 (backend `/api/conversations/*` za kanałem, any-role), CHAT-T-045 (wzorzec zakładek + POST w callBackend).
**Decyzje tej sesji:** 104b (odczyt + zmiana statusu od razu), 105a (4 statusy backendu, polskie etykiety), 106a (Rozmowy = domyślna zakładka, pasek wg częstości).

## Cel
Pilotaż migracji ciężkiej zakładki z danymi z backendu. Dodać zakładkę **Rozmowy** do `AdminDivezoneChatController` (lista + szczegóły + zmiana statusu), ustawić ją jako domyślną. Backend GOTOWY — to czysto UI w PS. Ta zakładka ustala wzorzec dla Analityki i Editorial.

---

## Kontrakt backendu (zweryfikowany w kodzie — NIE zmieniać backendu)

Wszystkie 3 endpointy: kanał serwerowy `ServerHmacVerifier`, **any-role** (operator+admin). Wołać przez istniejące `callBackend($endpoint, $employeeId, $method, $body)`.

### `GET /api/conversations` (lista, paginacja, filtry)
Query: `page` (int, dflt 1), `per_page` (int, max 100, dflt 20), `search` (string, ILIKE po treści), `knowledge_gap` (bool), `admin_status` (string).
Odpowiedź:
```
{
  "conversations": [
    { "id", "session_id", "customer_id", "message_count", "model_used",
      "tools_used": [...], "tokens_input", "tokens_output",
      "cache_read_tokens", "cache_creation_tokens", "estimated_cost" (float, USD),
      "knowledge_gap" (bool), "admin_status", "started_at", "updated_at" }
  ],
  "total": int, "page": int, "per_page": int
}
```
UWAGA: klucz to `conversations` (NIE `items`).

### `GET /api/conversations/{session_id}` (szczegóły)
Odpowiedź (pola istotne dla UI):
```
{
  "id", "session_id", "customer_id",
  "messages": [ { "role": "user"|"assistant"|"tool_result", "content": string,
                  "tool_calls"?: ..., "products"?: ... } ],
  "tools_used": [...], "tokens_input", "tokens_output",
  "cache_read_tokens", "cache_creation_tokens", "estimated_cost",
  "model_used", "response_times": {...}, "search_diagnostics": [...],
  "knowledge_gap" (bool), "admin_status", "admin_notes",
  "started_at", "updated_at", "closed_at",
  "conversation_cost": { ... USD + PLN ... } | null
}
```
Render wiadomości (wzorzec ze starego `standalone/public/js/history.js`):
- `role === 'user'` → bąbel użytkownika.
- `role === 'assistant'` z `content` → bąbel AI.
- `role === 'tool_result'` → NIE renderować jako wiadomość (pominąć).
`content` jako tekst, escapować (`htmlspecialchars`). NIE parsować markdown po stronie PHP (render po stronie operatora ma być surowy, czytelny — bez zależności od JS widgetu).

### `POST /api/conversations/{session_id}/status` (zmiana statusu — decyzja 104b)
Body JSON: `{ "status": "...", "notes": "..."|null }`.
Walidacja backendu (`$allowed`) — dozwolone DOKŁADNIE: `new`, `reviewed`, `knowledge_created`, `ignored`. Inny status = 400.
Odpowiedź sukcesu: `{ "success": true, "session_id", "status" }`.

## Mapowanie statusów w UI (decyzja 105a — etykiety PL, wartość = klucz EN)
| klucz (wysyłany) | etykieta PL (wyświetlana) | kolor badge (sugestia) |
|---|---|---|
| `new` | nowa | szary/niebieski |
| `reviewed` | przejrzana | zielony |
| `knowledge_created` | wiedza utworzona | teal (#1a5e5a) |
| `ignored` | zignorowana | wyblakły/szary |

Te 4 statusy w: (a) filtrze listy (dropdown `admin_status`, + opcja „wszystkie"), (b) dropdownie zmiany statusu na widoku szczegółów. Wartość OPTION = klucz EN, label = PL. Brak `admin_status` w danych → traktować jak `new`.

---

## Zakres implementacji (plik: `modules/divezone_chat/controllers/admin/AdminDivezoneChatController.php`)

### 1. Nowa stała zakładki + default = Rozmowy (decyzja 106a)
- Dodać `const TAB_CONVERSATIONS = 'conversations';` oraz stałe endpointów:
  `const ENDPOINT_CONVERSATIONS = '/api/conversations';`
  (szczegóły i status budować dynamicznie: `ENDPOINT_CONVERSATIONS . '/' . rawurlencode($sessionId)` i `... . '/status'`).
- W `initContent()` zmienić DEFAULT z `TAB_RECOMMENDATIONS` na `TAB_CONVERSATIONS` w 3 miejscach:
  a) `Tools::getValue('tab', self::TAB_CONVERSATIONS)`,
  b) lista `in_array(..., array(TAB_CONVERSATIONS, TAB_RECOMMENDATIONS, TAB_MODELS, TAB_CONFIG), true)` + fallback `= self::TAB_CONVERSATIONS`,
  c) gałąź routingu treści — Rozmowy jako pierwszy `if`, pozostałe jako `elseif`, `else` może zostać Rekomendacje.

### 2. Pasek zakładek wg częstości (decyzja 118a)
W `renderTabsNav()` kolejność linków: **Rozmowy, Rekomendacje, Modele, Konfiguracja** (Analityka/Drzewo dojdą w kolejnych taskach). Dodać link Rozmowy z `is-active` gdy aktywne.

### 3. Submit zmiany statusu (decyzja 104b)
W `initContent()`, analogicznie do `submitDivezoneChatModels`:
- wykryć `Tools::isSubmit('submitDivezoneChatConvStatus')` → wywołać nowy `handleConvStatusSave($employeeId)` → zostać na `TAB_CONVERSATIONS`.
- `handleConvStatusSave`: zebrać `session_id` (string, walidacja niepusty), `status` (whitelist 4 wartości — odrzucić inne LOKALNIE zanim poleci POST), `notes` (opcjonalne, trim). Złożyć body `json_encode(['status'=>..,'notes'=>..])`, wysłać `callBackend(ENDPOINT .'/'. rawurlencode($sid) .'/status', $employeeId, 'POST', $body)`. Flash success/error wzorem `handleModelsSave` (pola `$convFlash`/`$convFlashType`, obsłużyć 401/403/inny http_status).

### 4. `renderConversationsSection()` — dwa tryby wg `?session_id`
Jeśli w query jest `session_id` (niepusty) → **widok szczegółów**, inaczej → **lista**.

**Lista (`renderConversationsList`):**
- Czyta `page`, `per_page` (dflt 20), `search`, `admin_status`, `knowledge_gap` z `Tools::getValue`.
- Wywołuje `callBackend(ENDPOINT_CONVERSATIONS . '?' . http_build_query($filters), $employeeId)`.
- Pasek filtrów (GET form, `method="get"`, hidden `controller`+`tab=conversations`): input `search`, select `admin_status` (4 statusy + „wszystkie"), checkbox `knowledge_gap`, submit „Filtruj".
- Tabela: kolumny — `started_at`/`updated_at` (sformatowane), klient (`customer_id` lub „gość" gdy 0), liczba wiadomości, model, koszt (`estimated_cost` jako `$X.XXXX` USD; PLN tylko w szczegółach), badge statusu (mapowanie PL), znacznik `knowledge_gap` (np. ikonka/„luka wiedzy"). Każdy wiersz link „Otwórz" → `&tab=conversations&session_id={session_id}`.
- Paginacja: prev/next + „strona X z ceil(total/per_page)". Linki zachowują filtry.
- Pusta lista → komunikat „Brak rozmów".

**Szczegóły (`renderConversationDetail`):**
- `callBackend(ENDPOINT_CONVERSATIONS . '/' . rawurlencode($sessionId), $employeeId)`.
- Link „← wróć do listy" (`&tab=conversations`, bez `session_id`).
- Nagłówek meta: session_id, klient, model_used, started/updated/closed, knowledge_gap.
- Bloczek kosztów: tokeny in/out + cache, `estimated_cost` USD oraz `conversation_cost` (USD+PLN gdy != null).
- Przebieg rozmowy: iteracja `messages[]` wg reguł renderu wyżej (user/ai bąble, tool_result pominięty). Style bąbli inline jak reszta panelu (spójne z `dz-*`).
- Formularz statusu (POST, `submitDivezoneChatConvStatus`): hidden `session_id` + `tab=conversations`, select `status` (4, preselect bieżący `admin_status`), textarea `notes` (prefill `admin_notes`), submit „Zapisz status". Flash `$convFlash` nad formularzem.
- Opcjonalnie (jeśli proste): `search_diagnostics`/`response_times` w zwijanym `<details>` na dole — diagnostyka, nie blokujące.

### 5. Style
Dodać minimalny CSS do `renderTabsStyles()` dla bąbli rozmowy i badge'y statusów (klasy `dz-conv-*`, `dz-status-*`). Trzymać konwencję inline-scoped, bez kolizji z theme PS. NIE dokładać zależności JS — pełen reload przez `?tab`/`?session_id` (spójne z decyzją „bez JS toggle" z CHAT-T-045).

### 6. Czytelność (zasada z 117a)
Sekcja Rozmów to osobne, dobrze wydzielone metody (`renderConversationsSection` → `renderConversationsList` / `renderConversationDetail` / `renderConvRow` / `renderConvMessages` / `handleConvStatusSave`). NIE upychać w jeden blok. Kontroler ma pozostać czytelny mimo rosnącej liczby zakładek.

## Granice (czego NIE robić)
- NIE zmieniać backendu (`/api/conversations/*` gotowe).
- NIE ruszać ekranu Konfiguracja (`getContent`/`renderConfigSection`) ani Modele/Rekomendacje poza dodaniem linku w nav i przestawieniem defaultu.
- NIE dodawać markdown-parsera ani JS widgetu do renderu wiadomości.
- NIE wdrażać modułu samodzielnie (116b — wgrywa Karol). Standalone backend nietknięty w tym tasku.

## Kryteria akceptacji
1. Wejście w panel „DiveZone Chat" bez `?tab` → od razu Rozmowy (zero kliknięć).
2. Pasek: Rozmowy | Rekomendacje | Modele | Konfiguracja; aktywna podświetlona.
3. Lista ładuje dane z backendu, filtry (search/status/knowledge_gap) i paginacja działają (zachowują się w URL).
4. „Otwórz" pokazuje przebieg rozmowy (user/ai), koszty USD+PLN, meta.
5. Zmiana statusu zapisuje się (POST → success flash), po reloadzie nowy status widoczny na liście i w szczegółach. Status spoza whitelisty odrzucony lokalnie.
6. Whoami i pozostałe zakładki nadal działają.
7. PHP 7.2 / PS 1.7.6 — bez typed properties, bez `match`, explicit type hints (jak reszta pliku).
