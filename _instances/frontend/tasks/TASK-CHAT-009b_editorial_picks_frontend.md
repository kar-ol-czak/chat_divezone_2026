# TASK-CHAT-009b: Editorial Picks — frontend admin UI (P1)

**Instancja:** frontend
**Powiązany ADR:** ADR-054
**Zależność:** wymaga deployu TASK-CHAT-009a (backend + API)
**Priorytet:** P1
**Czas estymowany:** ~3h

## Cel

UI sekcji "Editorial Picks" w panelu admina pod `chat.divezone.pl/admin`, integracja z istniejącym dashboard (TASK-055).

## Lokalizacja

`standalone/admin/` — istniejący panel admin z TASK-055 (vanilla JS + Chart.js).

Nowa zakładka/sekcja: "Editorial Picks" obok obecnej sekcji "Koszty".

## Komponenty UI

### 1. Banner ostrzegawczy w głównym dashboard'cie

Gdy `GET /api/admin/editorial-picks/pending-reviews` zwraca > 0:

```
⚠️ X picków wymaga review: Y wygasłych w tym tygodniu, Z bezterminowych bez review > 30 dni.
[Zobacz wszystkie]
```

Pozycja: top dashboard'a, nad wykresem kosztów. Klik [Zobacz wszystkie] → sekcja Editorial Picks.

### 2. Sekcja Editorial Picks — lista

Tabela z kolumnami:
- Produkt (nazwa + link do strony sklepu)
- Kategoria (hint lub "wszystkie")
- Boost (visualizer slider, np. "1.5× ████░░")
- Powód (truncate, full na hover)
- Dodał / Kiedy
- Wygasa (lub "bezterminowy")
- Last review
- Status (active/expired)
- Akcje: [Edit] [Mark reviewed] [Extend] [Deactivate]

Filtry nad tabelą:
- Status: All / Active / Expired (default Active)
- Sort: dodane / wygasające / bez review

### 3. Form dodawania picka

Przycisk "+ Add pick" → modal/expandable form:

- **Produkt:** autocomplete (live search w `pr_product` przez `/api/admin/editorial-picks/product-search?q=...`)
  - Pokazuje nazwę + SKU + obecną cenę + dostępność
- **Kategoria (opcjonalna):** dropdown z listy kategorii (te same co w SystemPrompt NAZEWNICTWO SKLEPU). Default: "wszystkie kategorie".
- **Boost factor:** slider 1.0 — 2.5 step 0.1, default 1.5. Visualizer pokazuje jak wpływa na ranking.
- **Powód:** textarea, required, placeholder "np. Nowy bestseller pre-launch, klasyk redakcji, mocna promocja Q2".
- **TTL:** dropdown:
  - 15 dni
  - 30 dni
  - 60 dni (default)
  - 90 dni
  - Bezterminowo
- Przycisk "Add pick" → POST do API.

Walidacja:
- Powód niepusty
- Produkt wybrany z autocomplete (nie surowy text)
- Boost factor w zakresie

### 4. Akcje na wierszu

**Mark as reviewed:**
- POST `/api/admin/editorial-picks/{id}/mark-reviewed`
- Aktualizuje `last_review_at` na NOW()
- Toast "Reviewed ✓"

**Extend:**
- Mini-modal z dropdown nowego TTL (15/30/60/90/bezterminowo)
- POST `/api/admin/editorial-picks/{id}/extend`
- Toast "TTL extended"

**Edit:**
- Otwiera form jak dodawanie, prefilled
- PATCH `/api/admin/editorial-picks/{id}`

**Deactivate:**
- Confirm dialog "Disable this pick? It can be reactivated later from Expired list."
- DELETE `/api/admin/editorial-picks/{id}` (soft delete, active=false)

### 5. Styling

Spójny z istniejącym admin dashboard. Vanilla JS, brak frameworków. CSS w `standalone/admin/css/`.

Kolor statusu:
- Active: zielony badge
- Expired: szary
- Bezterminowy: niebieski badge

Ostrzeżenia (boost > 2.0): czerwony border w wierszu — sygnał "bardzo agresywny boost, sprawdź czy zasadny".

## Acceptance criteria

1. Banner w dashboard pokazuje się gdy są pending reviews, znika gdy 0.
2. Klik na produkt w autocomplete pre-fills form danymi z `pr_product`.
3. Po dodaniu picka, lista odświeża się automatycznie i nowy wpis widoczny.
4. Mark as reviewed aktualizuje kolumnę "Last review" bez przeładowania strony.
5. Filtr Active/Expired/All działa.
6. Modal/form ma walidację po stronie klienta i pokazuje błędy z API (400) inline.
7. Wszystkie akcje są autoryzowane przez header Authorization (jak inne endpointy admin).

## Out of scope

- Bulk edit (zaznacz N picków, jedno wspólne action) — przyszła iteracja
- Eksport listy picków do CSV — przyszła iteracja
- Historia zmian per pick (audit log) — backend ma audit trail, ale UI go nie pokazuje
- Notyfikacje push (slack/telegram) — out of scope ADR-054
