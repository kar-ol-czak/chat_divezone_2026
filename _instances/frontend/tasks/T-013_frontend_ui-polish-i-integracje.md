# T-013: Frontend UI polish Editorial Picks + integracje 3 nowych endpointów

**Instancja:** frontend
**Powiązany:** smoke T-011 feedback Karola (15.05), T-012 backend (3 endpointy + bypass stock)
**Priorytet:** P2 (UX improvement, nie blocker)
**Czas estymowany:** ~3-4h CC

## Cel

Polish UI Editorial Picks na bazie smoke T-011 feedback Karola + integracja 3 nowych endpointów backend z T-012 (`pending-reviews`, `products/search`, `last_review_at` sort).

## KROK 0. Read

- `_instances/backend/handoff/T-012_done.md` (spec endpointów + przykłady curl)
- `_instances/frontend/tasks/T-011_frontend_editorial-picks-ui.md` (kontekst struktury UI)
- `standalone/public/admin/js/admin-editorial.js` (główny plik do modyfikacji)
- `standalone/public/admin/index.html` + `css/admin.css` (markup + styling)

## KROK 1. Layout tabeli — UX feedback Karola

### 1a. Kolumna "Produkt" — większa szerokość

Obecnie 9 kolumn rozkładem ~11% każda. Karol prosi więcej miejsca dla "Produkt".

Nowy rozkład CSS (szerokości kolumn, sumują do 100%):

| Kolumna | Szerokość | Komentarz |
|---|---|---|
| Produkt | 28% | Wzrost (był ~11%) |
| Kategoria | 10% |  |
| Boost | 7% | Zmniejszone (był ~11%, usuń pasek visualizer) |
| Powód | 15% | 2-wiersze max + tooltip pełny |
| Dodał / Kiedy | 10% | "Karol / 2h" (bez "temu") |
| Wygasa | 9% | "za 14 dni" / "bezterminowo" |
| Last review | 9% | "5 dni" / "—" |
| Status | 7% | badge |
| Akcje | 5% | ikony |

Mobile media query (< 768px): collapse do listy kart zamiast tabeli (per ADR-054).

### 1b. Kolumna "Boost" — tylko liczba, bez paska

Zamiast `×1.5 ████░░░░` pokaż tylko `×1.5` (pogrubione). Usuń `boost-bar` DOM element + CSS.

### 1c. Kolumna "Akcje" — ikony zamiast tekstu

Wszystkie 5 akcji do JEDNEJ kolumny "Akcje":

- ✏️ (ołówek) Edit — emoji lub Unicode `\u270F\uFE0F` (preferred: SVG inline z lucide-react path lub heroicons; jeśli framework-free, użyj emoji)
- ✓ (check) Mark reviewed
- +30d (tekst) Extend
- ⏸ (pause) Deactivate
- 🗑 (trash) Delete

Każda ikona w span/button z `title="Edit"` (tooltip native). 5 ikon obok siebie, gap 4px.

ALTERNATYWA jeśli emoji wyglądają nieprofesjonalnie: użyj inline SVG z heroicons (24x24, currentColor). 5 SVG ścieżek to ~50 linii kodu.

CC wybiera wariant na bazie spójności z istniejącym dashboardem (TASK-055). Domyślny: emoji jeśli pasują, SVG jeśli nie.

### 1d. Kolumna "Powód" — 2 wiersze max + tooltip

CSS:

```css
.editorial-reason {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 200px;
}
```

`title` attribute z pełną treścią → native tooltip.

### 1e. Kolumna "Dodał / Kiedy" — usuń "temu"

Zmiana formattera relative time:

- `"5 minut temu"` → `"5 minut"`
- `"2 godziny temu"` → `"2 godziny"`
- `"3 dni temu"` → `"3 dni"`

Funkcja `fmt.relativeTime()` lub równoważna — zmień implementację.

### 1f. Header tabeli — przyciski sortujące

Każdy header kolumny (oprócz Akcje) jest klikalny. Klik → sort wg tej kolumny. Drugi klik → odwrotna kolejność (ASC ↔ DESC). Wizualnie: ▲ ▼ obok aktywnego sort header.

Kolumny sortowalne (whitelist z backend):
- added_at (Dodał / Kiedy)
- expires_at (Wygasa)
- boost_factor (Boost)
- product_name (Produkt)
- last_review_at (Last review) — NOWE z T-012

Stan sortu w URL hash: `#/editorial-picks?status=active&sort=expires_at&dir=desc`.

Filter dropdown "Sort" nad tabelą — można zostawić jako alternatywę, lub usunąć i polegać tylko na headerach klikalnych. Rekomendacja: zostawić dropdown bo jest mobile-friendly (mobile collapse do kart nie ma headera tabeli do klikania).

## KROK 2. Integracja `GET /api/admin/products/search` (autocomplete)

Modal "Dodaj pick" — zamień `product_id` (number input) + `product_name` (text input) na **autocomplete**:

UI:
- Pojedynczy input "Produkt" z placeholder "Wpisz nazwę lub ID produktu"
- Po wpisaniu ≥ 2 znaków: debounce 300ms → `GET /api/admin/products/search?q={value}`
- Wyniki w dropdown poniżej input (max 20 pozycji): `{nazwa} — {price} zł` (in_stock indicator po prawej)
- Klik → wybór produktu, zapełnia `product_id` (hidden) + `product_name` (display) w state
- Wybrany produkt: badge z nazwą i ID + przycisk X żeby zmienić

Fallback: jeśli endpoint zwraca 5xx, pokaż "Błąd wyszukiwania, wpisz ID manualnie" + 2 inputy (jak było w T-011).

Manual input ID nadal dostępne jako alternatywa — np. link "Wpisz ID manualnie" pod autocomplete.

## KROK 3. Banner pending-reviews — pełna implementacja

W `admin.js` lub `admin-editorial.js`, przy load głównego dashboardu (`#/koszty` lub `#/editorial-picks`):

```javascript
DiveAdmin.api('/api/admin/editorial-picks/pending-reviews')
    .then(function(data) {
        if (data.total > 0) {
            showBanner(data);
        }
    })
    .catch(function(err) {
        // graceful — banner zostaje ukryty, błąd loguje się do console
        console.warn('pending-reviews unavailable:', err);
    });
```

Format banneru (zgodnie z ADR-054):

```
⚠️ {total} picków wymaga review: {expired_this_week} wygasłych w tym tygodniu, {long_unreviewed} bezterminowych bez review > 30 dni.
[Zobacz wszystkie]
```

CSS: top głównego dashboardu (#/koszty), kolor żółty (#fff3cd background, #856404 text). Klik [Zobacz wszystkie] → `#/editorial-picks?status=needs_review`.

Filter "needs_review" w sekcji Editorial Picks (KROK 1f rozszerzenie):
- Pokaż picki które są:
  - Aktywne + (expires_at IS NULL i last_review_at < NOW() - 30 dni) — bezterminowe bez review
  - LUB Wygasłe w ostatnich 7 dniach
- Frontend filter (po fetch wszystkich z `?active=all` lub `?active=true`) lub backend (nowa wartość `status=needs_review` w endpoint — wymaga zmiany backend, nie ma w T-012, raczej client-side filter)

Rekomendacja: **client-side filter** — backend już daje wszystkie potrzebne dane (expires_at, last_review_at, active). T-013 frontend dodaje filter logic bez nowej zmiany backend.

## KROK 4. Integracja "Sort last_review_at"

5. opcja w dropdown "Sort": "Bez review najdłużej". Query param `?order_by=last_review_at` — backend T-012 obsługuje.

Dodaj do header tabeli sortable (KROK 1f).

## KROK 5. PUT/DELETE method override w frontend

T-012 backend obsługuje `X-HTTP-Method-Override`. Frontend `admin.js` `DiveAdmin.send()` już zaimplementowany w T-012 (PUT/DELETE → POST + header). Sprawdź że w `admin-editorial.js` używa się `send()` (a nie direct fetch). Jeśli direct fetch z PUT — przepisz na `send()`.

## KROK 6. PHP/JS lint (na ile możliwe)

```bash
# JS lint przez Node (jeśli ESLint zainstalowany):
npx eslint standalone/public/admin/js/admin-editorial.js || echo "eslint not configured, skip"
# CSS lint:
npx stylelint standalone/public/admin/css/admin.css || echo "stylelint not configured, skip"
```

Plus ręczna weryfikacja:
- Console errors w przeglądarce (open DevTools → Console)
- Network tab: requests do `/api/admin/*` z 200/304

## KROK 7. STOP point — review przez Karol

Status: "READY FOR REVIEW v1". Wklej:

- Lista plików zmienionych + diff stat
- Screenshot listy picków po polish (lub opis renderowanych elementów + sortable headerów + ikon)
- Screenshot modal "Dodaj pick" z autocomplete
- Test scenariusze (manualne) z PASS/FAIL — minimum 8 punktów:
  1. Lista renderuje się z nowym layoutem (szerokości kolumn)
  2. Boost pokazuje tylko liczbę bez paska
  3. Akcje jako ikony, tooltip natywny po hover
  4. Powód truncate 2 wiersze + tooltip
  5. "5 minut" zamiast "5 minut temu"
  6. Klik header "Produkt" → sort ASC, klik znowu → DESC, wskaźnik ▲▼
  7. Autocomplete działa: wpisz "santi" → dropdown z 20 pozycjami → klik → wybór
  8. Banner pending-reviews: gdy pending-reviews zwraca total > 0, banner widoczny w #/koszty

NIE deploy bez akceptacji.

## KROK 8. Deploy

scp zmienionych plików (lista per KROK 1-5):
- standalone/public/admin/js/admin-editorial.js
- standalone/public/admin/js/admin.js (jeśli zmieniony — relative time fmt)
- standalone/public/admin/css/admin.css (nowy layout + tooltipy)
- standalone/public/admin/index.html (banner DOM + sort headers)

md5 verify per plik. UI smoke prod (otwórz panel admin, sprawdź renderowanie).

## KROK 9. Git workflow

```bash
git status
git add standalone/public/admin/js/admin-editorial.js
git add standalone/public/admin/js/admin.js
git add standalone/public/admin/css/admin.css
git add standalone/public/admin/index.html
git commit -m "T-013: Editorial Picks UI polish + integracja 3 nowych endpointów

UX polish per feedback Karola (smoke T-011 15.05):
- Kolumny: produkt 28% (większy), boost tylko liczba (bez paska), akcje ikony
- Powód 2-wiersze + tooltip; 'temu' usunięte z relative time
- Sortable headers tabeli (klik nagłówek → sort ASC/DESC, wskaźnik ▲▼)
- Mobile media query: collapse do kart

Integracja endpointów T-012:
- Autocomplete produktów (GET /api/admin/products/search)
- Banner pending-reviews (GET /api/admin/editorial-picks/pending-reviews)
- Sort 'Bez review najdłużej' (last_review_at w whitelist)

Powiązany: T-011 (baza UI), T-012 (3 endpointy backend)"
git push origin main
```

## KROK 10. Smoke test produkcyjny dla Karola

1. `chat.divezone.pl/admin/` → Editorial Picks → sprawdź nowy layout (kolumny, ikony, boost numeric, "temu" usunięte)
2. Klik header "Boost" → sort ASC, klik znowu → DESC, wskaźnik ▲▼
3. + Dodaj pick → autocomplete wpisz "santi" → dropdown 20 pozycji → klik wybór
4. Wróć do #/koszty → jeśli pending-reviews total > 0, banner widoczny żółty
5. Dropdown sort → "Bez review najdłużej" → pick z NULL last_review_at na górze
6. Mobile (DevTools responsive, ~375px szerokość) → lista jako karty zamiast tabeli

## KROK 11. Raport + status update

### Utworz `_instances/frontend/handoff/T-013_done.md`:
- Lista plików + diff stat
- Screenshot/opis 6 scenariuszy smoke
- Decyzja emoji vs SVG ikony
- Git commit hash

### Update `_docs/21_STATUS_PROJEKTU.md`:
- "Co działa na produkcji" → T-013 DEPLOYED (UI polish complete)
- "Aktywne instancje CC" → frontend T-013 DONE
- "Kolejka tasków" → usunąć T-013
- Editorial Picks: pełna funkcjonalność (backend + frontend + integracje) — production-ready

### Osobny commit "docs:"

```bash
git add _docs/21_STATUS_PROJEKTU.md
git commit -m "docs: T-013 DEPLOYED — Editorial Picks UI polish complete"
git push origin main
```

## Out of scope

- Bulk operations (mark all reviewed, batch delete)
- Eksport listy do CSV/JSON
- Analytics konwersji picków (ile sprzedaży na pick)
- Weekly notifications cron (email backend trigger — osobny task)
- A/B testing różnych layoutów
- Search w sekcji Editorial Picks (filter po nazwie produktu) — jeśli potrzebne, follow-up
