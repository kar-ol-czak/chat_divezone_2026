# T-011: Editorial Picks frontend admin UI

**Instancja:** frontend
**Powiązany:** ADR-054 (specyfikacja Editorial Picks), T-008 (backend deployed 15.05 06:55, commit 92caec0)
**Replaces:** TASK-CHAT-009b (legacy spec z poprzedniej sesji, treść kompatybilna, ten task ją odświeża po deploy T-008)
**Priorytet:** P1
**Czas estymowany:** ~3h CC

## Cel

UI sekcji "Editorial Picks" w panelu admin `chat.divezone.pl/admin` integrujący się z dashboardem (TASK-055 vanilla JS + Chart.js). Zakres: lista picków + form dodawania + akcje (edit/extend/deactivate/mark-reviewed/delete) + banner przypomnień. Backend (T-008) gotowy z API endpointami.

## KROK 0. Read

- `_docs/10_decyzje_projektowe.md` sekcja ADR-054 (specyfikacja UI per ADR)
- `_instances/frontend/tasks/TASK-CHAT-009b_editorial_picks_frontend.md` (legacy spec — wzorzec UI, treść w 95% aktualna, T-011 ją refinuje)
- `_instances/backend/handoff/T-008_done.md` (lista API endpointów + przykłady curl POST/GET/PUT/DELETE)
- `standalone/admin/` — istniejący panel admin (struktura HTML/JS/CSS, gdzie dodać nową sekcję)
- `standalone/src/Controller/AdminEditorialPicksController.php` (po stronie backend, contract API endpointów)

## KROK 1. Struktura w panelu admin

Nowa zakładka "Editorial Picks" w `standalone/admin/index.html` (lub odpowiedniku — sprawdź strukturę istniejącego panelu po deploy TASK-055).

Pozycja: obok obecnej sekcji "Koszty"/dashboard. Routing po stronie frontendu (vanilla JS, hash-based np. `#/editorial-picks`).

## KROK 2. Komponenty UI

### 2a. Banner ostrzegawczy w głównym dashboardzie

Endpoint backend: ADR-054 mówi o tygodniowym przypomnieniu. T-008 backend NIE zaimplementował tego endpointu (out of scope T-008). Dla T-011 frontend:

- Jeśli endpoint `/api/admin/editorial-picks/pending-reviews` istnieje (GET) → wyświetl banner z licznikiem
- Jeśli endpoint zwraca 404 (nieobsługiwany) → frontend NIE wyświetla bannera, NIE crashuje. Banner pojawia się dopiero po implementacji backend endpointu (T-XXX w przyszłości)

Komunikat (gdy endpoint dostępny + count > 0):

```
⚠️ X picków wymaga review: Y wygasłych w tym tygodniu, Z bezterminowych bez review > 30 dni.
[Zobacz wszystkie]
```

Pozycja: top dashboardu, nad wykresem kosztów. Klik [Zobacz wszystkie] → przejście do sekcji Editorial Picks z filtrem `status=needs_review`.

### 2b. Sekcja Editorial Picks — lista (GET /api/admin/editorial-picks)

Tabela z kolumnami:

- **Produkt** — nazwa + link do strony sklepu (https://divezone.pl/{product_url}; product_url może wymagać osobnego endpointu lub może być w response z T-008 — sprawdź payload, jeśli brak, link do search po `product_name`)
- **Kategoria** — `category_hint` lub badge "wszystkie kategorie"
- **Boost** — visual slider/bar (`1.5× ████░░░░` skala 1.0-2.5)
- **Powód** — truncate do ~60 znaków, full text na hover (tooltip)
- **Dodał / Kiedy** — `added_by` + relative time (np. "2 dni temu")
- **Wygasa** — `expires_at` jako relative ("za 14 dni") lub "bezterminowy" gdy NULL; kolor czerwony gdy < 7 dni
- **Last review** — `last_review_at` jako relative; szary gdy NULL
- **Status** — badge "active" (zielony) / "expired" (szary)
- **Akcje** — [Edit] [Mark reviewed] [Extend +30d] [Deactivate] (jeden mniejszy `...` rozwijany)

Filtry nad tabelą:

- **Status:** All / Active / Expired (default Active)
- **Sort:** dodane (default) / wygasające / bez review najdłużej

Query string sync: `?status=active&sort=expires_at` żeby URL był deep-linkable.

### 2c. Form dodawania picka (POST /api/admin/editorial-picks)

Modal lub osobna strona. Pola:

- **Produkt** — autocomplete z search produktów (endpoint po stronie backend — sprawdź czy istnieje `/api/admin/products/search?q=...` lub użyj raw call do ProductSearch toola; jeśli brak, manual input `product_id` + `product_name`; spec ADR-054 wymaga search ale T-008 backend tego nie dostarcza explicite — fallback do manual w T-011, autocomplete jako follow-up jeśli endpoint pojawi się)
- **Category hint** — input text (opcjonalny, free text z hint że może być empty = wszystkie kategorie)
- **Boost factor** — slider 1.0-2.5 step 0.1, default 1.5, label "boost ×1.5"
- **Reason** — textarea (wymagane, min 10 znaków, max 500)
- **TTL** — radio: 15 dni / 30 dni / 60 dni (default) / 90 dni / bezterminowo
- **Submit** — przycisk "Dodaj pick"

Walidacja po stronie frontendu (przed wysłaniem POST):
- Produkt: wybrany lub `product_id` + `product_name` wypełnione
- Reason: ≥ 10 znaków
- Boost: 1.0 ≤ x ≤ 2.5

Po sukcesie POST → toast "Pick dodany" + odśwież listę + zamknij modal.

Błąd POST (np. 409 conflict gdy pick na ten product+category_hint już istnieje) → wyświetl error message inline w modalu.

### 2d. Akcja Edit (PUT /api/admin/editorial-picks/{id})

Modal z polami: boost, reason, expires_at (date picker), active toggle. Submit → PUT z body subset.

### 2e. Akcja Extend +30d

Quick action — wywołuje PUT z `{ttl_extend_days: 30}`. Toast "Pick przedłużony o 30 dni" + odśwież listę.

### 2f. Akcja Mark reviewed

PUT z `{mark_reviewed: true}`. Toast "Oznaczono jako przejrzane" + odśwież listę.

### 2g. Akcja Deactivate

PUT z `{active: false}`. Toast "Pick zdezaktywowany" + odśwież listę. (Pick zostaje w bazie, ale niewidoczny przy `active=1` filter.)

### 2h. Akcja Delete (twarde usunięcie)

Confirm dialog "Usunąć pick {product_name} permanentnie?" → DELETE /api/admin/editorial-picks/{id}. Toast "Pick usunięty" + odśwież listę.

## KROK 3. Autoryzacja

Panel admin ma już AdminAuthMiddleware (basic auth) skonfigurowany w nagłówkach żądań. T-011 dziedziczy — żadne nowe credentials. Sprawdź wzorzec w istniejącym admin/js (jak dashboard koszty się autoryzuje).

## KROK 4. UX details

- Loading state: spinner przy pierwszym GET + przy każdej akcji
- Empty state: gdy 0 picków → "Brak Editorial Picks. [Dodaj pierwszy]" z CTA
- Error handling: gdy API zwraca 5xx → toast "Błąd: {message}" + retry button
- Mobile-friendly: tabela responsive (scroll horizontal na małych ekranach, lub karty)
- Vanilla JS, no framework. Styl spójny z dashboardem (zachowaj typografię, kolory, komponenty buttonów)

## KROK 5. Integration test

Test scenariusze manualnie przez UI (lokalnie + na prod po deploy):

1. Wejście do sekcji → GET picki → tabela renderuje
2. Empty state przy 0 picków
3. Dodaj nowy pick przez form → POST → lista odświeża → pick widoczny
4. Edit → PUT → wartości zmienione
5. Extend +30d → expires_at przesunięte
6. Mark reviewed → last_review_at = teraz
7. Deactivate → pick znika z active filter, widoczny w "All" jako expired
8. Delete → confirm → pick znika permanentnie z bazy
9. Filtr Status: All/Active/Expired działa
10. Sort: dodane / wygasające / bez review działa
11. Banner: jeśli endpoint pending-reviews istnieje i count > 0 → widoczny; jeśli 404 → brak crashu

## KROK 6. STOP point — review przez Karol

Status: "READY FOR REVIEW v1". Wklej:

- Lista nowych/zmienionych plików w `standalone/admin/`
- Screenshot listy picków (1 pick testowy) — może być terminal-friendly opisem, jeśli CC nie ma screenshot capability, podaj listę renderowanych elementów
- Wyniki 11 scenariuszy manualnych (PASS/FAIL)
- Otwarte pytania (np. brak endpointu autocomplete produktów → fallback manual input — czy OK?)

NIE deploy bez akceptacji.

## KROK 7. Deploy

scp całego folderu `standalone/admin/` (lub tylko zmienione pliki — sprawdź diff). Md5 weryfikacja per plik.

Smoke prod:

- Otwórz https://chat.divezone.pl/admin (basic auth)
- Wejdź do Editorial Picks
- Dodaj test pick (Powystawowy SANTI 6865, boost 1.8, TTL 7 dni, reason "smoke test T-011")
- Sprawdź pick na liście
- UI chat: "Szukam suchego skafandra Santi, damski" — Powystawowy WYŻEJ
- Usuń test pick

## KROK 8. Git workflow

```bash
git status
# Dodaj konkretne ścieżki - sprawdź które pliki w standalone/admin/ zostały zmodyfikowane lub utworzone
git add standalone/admin/[konkretne pliki]
git commit -m "T-011: Editorial Picks frontend admin UI

Sekcja Editorial Picks w panelu admin chat.divezone.pl/admin:
- Lista picków z filtrami status/sort + 8 kolumn (produkt/boost/reason/wygasa/etc)
- Form dodawania (slider boost 1.0-2.5, TTL select, reason textarea)
- Akcje: Edit / Extend +30d / Mark reviewed / Deactivate / Delete
- Banner ostrzegawczy (gdy backend dostarczy pending-reviews endpoint)
- Empty state, loading state, error handling
- Vanilla JS, spójne z TASK-055 dashboardem

Integracja z API z T-008 (commit 92caec0).

Powiązany ADR: ADR-054"
git push origin main
```

## KROK 9. Raport + status update

### Utworz `_instances/frontend/handoff/T-011_done.md`:

- Lista plików + diff stat
- Wyniki 11 scenariuszy manualnych
- Git commit hash
- Smoke prod result
- Otwarte issues / follow-up (autocomplete endpoint, pending-reviews endpoint)

### Update `_docs/21_STATUS_PROJEKTU.md`:

- "Co działa na produkcji" → T-011 DEPLOYED, Editorial Picks UI funkcjonalne
- "Aktywne instancje CC" → frontend T-011 DONE
- "Kolejka tasków" → usunąć T-011, dodać do backlogu: pending-reviews endpoint backend (T-XXX), autocomplete produktów endpoint (T-XXX)

### Osobny commit "docs:"

```bash
git add _docs/21_STATUS_PROJEKTU.md
git commit -m "docs: T-011 DEPLOYED — Editorial Picks UI"
git push origin main
```

## Out of scope

- Pending-reviews endpoint backend (osobny task; T-011 frontend graceful gdy 404)
- Autocomplete produktów endpoint (osobny task; T-011 fallback manual input)
- Weekly notifications cron (email/banner backend trigger)
- Analytics konwersji picków (czy boost przekłada się na sprzedaż)
- A/B testing różnych boost factors
- Eksport listy picków do CSV/JSON
- Bulk operations (mark all reviewed)
