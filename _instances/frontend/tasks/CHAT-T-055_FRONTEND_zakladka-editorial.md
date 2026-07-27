# CHAT-T-055 — FRONTEND/PS: zakładka „Editorial Picks" w panelu PS (pełny CRUD)

**Instancja:** frontend (moduł PrestaShop)
**Powiązane:** CHAT-T-054 (backend editorial — kanał serwerowy any-role, WDROŻONY), ADR-076, CHAT-T-048/052 (wzorce zakładki/CSS/callBackend), CHAT-T-050 (wzorzec sekcji z 4 wywołaniami + 403 guard).
**Decyzje:** 127b (any-role), 128a (aliasy POST update/delete), 131a (pełny CRUD od razu), 132a (wyszukiwarka server-side, zero JS, zero proxy).
**Plik:** modules/divezone_chat/controllers/admin/AdminDivezoneChatController.php. Backend GOTOWY. Karol wgrywa ręcznie (116b).

## Cel
Nowa zakładka Editorial Picks w PS: lista picków, dodawanie (z wyszukiwarką produktów server-side), edycja (boost/reason/ttl/active), usuwanie, panel „pending reviews". Pełny CRUD na server-side formularzach (GET filtry, POST akcje przez callBackend). ANY-ROLE (operator+admin) — bez ukrywania linku wg roli (inaczej niż Analityka).

## Kontrakt backendu (zweryfikowany)
- GET /api/admin/editorial-picks?active={1|0|puste}&order_by={added_at|expires_at|boost_factor|product_name|last_review_at}
  → {picks:[{id, product_id, product_name, category_hint, boost_factor, reason, added_by, added_at, expires_at, last_review_at, active}], count}
- GET /api/admin/editorial-picks/pending-reviews → {expired_this_week, long_unreviewed, total}
- GET /api/admin/products/search?q={min 2 znaki} → {products:[{id, name, price, in_stock}], count} (q<2: {products:[], message})
- POST /api/admin/editorial-picks (add) → body {product_id, product_name, reason, boost_factor (1.0-2.5, dflt 1.5), category_hint?, ttl_days?} → {pick} 201
- POST /api/admin/editorial-picks/{id} (update) → body: dowolne z {boost_factor, reason, expires_at, active, mark_reviewed:true, ttl_extend_days:int} → {success, id}
- POST /api/admin/editorial-picks/{id}/delete → {success, id}
WSZYSTKIE przez callBackend (GET lub POST). callBackend wysyła body tylko dla POST — używać aliasów POST, NIE PUT/DELETE.

## Architektura (132a — server-side, zero JS)
Wyszukiwarka produktów: pole + przycisk „Szukaj" → reload zakładki z ?q=... → kontroler woła products/search przez callBackend → lista wyników jako klikalne pozycje (link wypełnia formularz dodawania przez ?ep_product_id=...&ep_product_name=...). BEZ JS, BEZ proxy, BEZ live-fetch. Spójne z CHAT-T-048/050.

## Zakres

### 1. Zakładka
- const TAB_EDITORIAL = 'editorial' + stałe endpointów. Whitelist w initContent + routing renderEditorialSection($employeeId).
- Nav (118a): Rozmowy | Rekomendacje | Analityka | Editorial | Modele | Konfiguracja (Rozmowy domyślne; Editorial blisko Rekomendacji/Analityki — pokrewne treściowo). ANY-ROLE: link widoczny dla operatora I admina (NIE ukrywać wg roli, inaczej niż Analityka).
- Detekcja submitów: submitDivezoneChatEpAdd, submitDivezoneChatEpUpdate, submitDivezoneChatEpDelete → handlery → reload na TAB_EDITORIAL.

### 2. renderEditorialSection($employeeId)
Czyta filtry z Tools::getValue: active (1/0/'' = wszystkie), order_by (whitelist 5 wartości), q (fraza wyszukiwarki), ep_product_id/ep_product_name (wybrany produkt do formularza dodawania). Woła list + pending-reviews (i products/search jeśli q≠''). 403 guard jak CHAT-T-050 (any-role, więc 403 mało prawdopodobne, ale obsłużyć no_role → komunikat). Sekcje:

**a) Panel pending reviews** (z pending-reviews): pasek u góry — „Do przeglądu: X wygasłych w tym tygodniu, Y długo nieweryfikowanych (łącznie Z)". Jeśli total=0 → dyskretny komunikat „Brak picków do przeglądu" lub pominąć.

**b) Formularz dodawania picka** + wyszukiwarka produktu:
   - Wyszukiwarka (GET form, hidden controller/token/tab=editorial): input q + submit „Szukaj produkt". Po reloadzie z q: lista wyników products/search jako pozycje (nazwa, cena, znacznik „w magazynie"/„brak"), każda = link „Wybierz" z ?ep_product_id=...&ep_product_name=...&tab=editorial (zachowuje ewentualne inne filtry). Pusta/q<2 → komunikat z backendu.
   - Formularz add (POST, submitDivezoneChatEpAdd, hidden controller/token/tab): pola — wybrany produkt (ukryte ep_product_id + widoczna nazwa, prefill z query po „Wybierz"; jeśli brak wybranego → komunikat „Najpierw wyszukaj i wybierz produkt"), reason (textarea, wymagane), boost_factor (number 1.0-2.5 krok 0.1, dflt 1.5), category_hint (text opcjonalne), ttl_days (number opcjonalne, puste=bezterminowo). Submit „Dodaj pick". Walidacja lokalna: product_id>0, reason≠'', boost 1.0-2.5 (odrzucić przed POST).

**c) Lista/tabela picków** (z list): filtry (GET): active (wszystkie/aktywne/nieaktywne), order_by (5 opcji). Tabela kolumny: Produkt (product_name + #product_id), Boost, Powód (reason skrócony), Kategoria (category_hint lub „wszystkie"), Dodane (added_at + added_by), Wygasa (expires_at lub „bezterminowo"), Ost. przegląd (last_review_at lub „—"), Status (active → badge aktywny/nieaktywny). Akcje per wiersz (patrz d). Pusta → „Brak picków".

**d) Akcje edycji/usuwania per wiersz** (POST formularze, każdy z hidden id+controller+token+tab):
   - Edycja inline minimalna: boost_factor (number), reason (text), active (checkbox/toggle), expires_at lub przedłużenie (np. ttl_extend_days select 30/90/365 dni) — submitDivezoneChatEpUpdate. Może być kompaktowy formularz rozwijany per wiersz (<details>) ALBO osobny mini-formularz w komórce akcji. Wybrać czytelniejsze przy wąskiej tabeli.
   - „Oznacz przejrzane" (mark_reviewed:true) — osobny submit (szybka akcja, częsta przy pending).
   - „Usuń" — submitDivezoneChatEpDelete (POST /{id}/delete), z potwierdzeniem (np. onsubmit confirm — to jedyny dopuszczalny mikro-JS inline, jak confirm; jeśli unikać JS całkiem → druga strona potwierdzenia, ale confirm jest standardem PS i akceptowalny).

### 3. Handlery (wzorzec handleModelsSave/handleConvStatusSave)
- handleEpAdd($employeeId): zbierz pola, walidacja lokalna (product_id/reason/boost), json_encode, callBackend(ENDPOINT_EDITORIAL, POST). Flash $epFlash. 201 = sukces.
- handleEpUpdate($employeeId): id z POST, zbierz zmienione pola (boost_factor/reason/active/ttl_extend_days/mark_reviewed), callBackend(ENDPOINT_EDITORIAL.'/'.rawurlencode(id), POST). Flash.
- handleEpDelete($employeeId): id z POST, callBackend(ENDPOINT_EDITORIAL.'/'.rawurlencode(id).'/delete', POST, '{}'). Flash.
- Obsługa http_status (401/403/400 walidacja backendu/inne) jak w istniejących handlerach. Properties $epFlash/$epFlashType.

### 4. CSS
Klasy dz-ep-* w renderTabsStyles (pasek pending, formularz dodawania, wyniki wyszukiwarki, tabela, akcje wiersza, badge status). Spójne z dz-*.

## Granice
- Bez zmian backendu. Bez JS (jedyny dopuszczalny: confirm() przy usuwaniu). Bez proxy/live-fetch (132a). Bez autocomplete.
- Używać aliasów POST (NIE PUT/DELETE) — callBackend body tylko dla POST.
- Hidden controller/token/tab we WSZYSTKICH formularzach (routing PS).
- Bez ruszania innych zakładek poza linkiem w nav. Bez wdrażania (116b).
- PHP 7.2 / PS 1.7.6 (bez typed props, bez match).

## Kryteria akceptacji
1. Zakładka Editorial w nav (widoczna dla operatora I admina), renderuje listę picków + pending reviews.
2. Wyszukiwarka: „Szukaj produkt" → lista wyników (nazwa/cena/magazyn); „Wybierz" wypełnia formularz dodawania.
3. Dodanie picka (produkt+reason+boost) → POST → 201 → flash sukces → pick na liście po reloadzie.
4. Edycja (boost/reason/active/przedłużenie TTL) → POST → flash → zmiana widoczna. „Oznacz przejrzane" działa.
5. Usunięcie → POST /{id}/delete (z confirm) → flash → pick znika z listy.
6. Filtry active/order_by działają (GET reload, zachowane w URL).
7. Walidacja lokalna (boost 1.0-2.5, reason wymagane, product wybrany) przed POST; błędy backendu (400) pokazane jako flash.
8. php -l clean; PHP 7.2/PS 1.7.6.
