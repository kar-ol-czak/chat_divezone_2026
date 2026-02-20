# TASK-008f: Frontend testowy chat.divezone.pl
# Data: 2026-02-20
# Instancja: frontend
# Priorytet: WYSOKI
# Zależności: TASK-008 (backend endpointy API)

## Kontekst
Strona testowa na chat.divezone.pl do ręcznego testowania czatu, podglądu diagnostyki
i przeglądania historii rozmów. Narzędzie wewnętrzne, bez autentykacji.

## Layout — trzy panele

```
┌─────────────────────────────────┬──────────────────────────┐
│                                 │   USTAWIENIA             │
│                                 │   Model: [dropdown]      │
│          CZAT (60%)             │   Temp: [slider]         │
│                                 │   Emoji: [toggle]        │
│   Wiadomości user/assistant     │                          │
│   Karty produktów               │   KONSOLA DEBUG          │
│   Input + wyślij                │   17:43:30 → sending...  │
│                                 │   17:43:30 embedding 120ms│
│                                 │   17:43:31 tool: search  │
│                                 │   17:43:33 AI 2300ms     │
│                                 │   17:43:33 total 3200ms  │
│                                 │                          │
│                                 │   JSON RESPONSE          │
│                                 │   { raw json toggle }    │
│                                 ├──────────────────────────┤
│                                 │   HISTORIA CZATÓW        │
│                                 │   [search box]           │
│                                 │   #cdc1f6 | 5 msg | 0.02$│
│                                 │   #68790a | 3 msg | 0.01$│
│                                 │   → klik = podgląd      │
│                                 │     + "pokaż szczegóły"  │
├─────────────────────────────────┴──────────────────────────┤
```

Lewy panel (60%): czat pełnoekranowy
Prawy panel (40%): góra = ustawienia + debug, dół = historia

## Pliki

Wszystkie w `standalone/public/`:
```
standalone/public/
├── index.html          # strona testowa (ładowana gdy GET /)
├── css/
│   └── chat-test.css   # wszystkie style
├── js/
│   ├── chat.js         # logika czatu (wysyłanie, odbieranie, renderowanie)
│   ├── settings.js     # panel ustawień (load/save, dropdown, slider, toggle)
│   ├── console.js      # konsola debug (logi, timings, JSON viewer)
│   └── history.js      # historia czatów (lista, search, podgląd)
└── .htaccess           # (istniejący) serwuje statyczne pliki bezpośrednio
```

## Routing

Plik `standalone/public/index.html` musi być serwowany jako strona domyślna.
Obecny .htaccess przekierowuje wszystko co nie jest plikiem do index.php (API router).
Pliki statyczne (html, css, js) serwowane bezpośrednio przez Apache.

**UWAGA:** Dodaj do Router.php lub index.php logikę: jeśli request to GET / i nie /api/*,
serwuj index.html. Albo po prostu: Apache znajdzie index.html przed index.php
(dodaj DirectoryIndex index.html index.php do .htaccess).

## Autentykacja do API

API wymaga HMAC token. Strona testowa nie ma modułu PS który generuje token.
**Rozwiązanie:** Dodaj endpoint GET /api/test-token (tylko w APP_ENV=dev):
```json
{
  "token": "07af776b...",
  "customer_id": 0,
  "timestamp": 1771609419,
  "expires_in": 300
}
```
JS pobiera token z tego endpointu przed każdym requestem do /api/chat.
W produkcji ten endpoint jest wyłączony.

## Panel czatu (chat.js)

### Renderowanie wiadomości
- Wiadomości user: bąbelek po prawej, kolor tła jasny
- Wiadomości AI: bąbelek po lewej, kolor tła biały
- Typing indicator: trzy kropki animowane podczas oczekiwania na odpowiedź
- Auto-scroll do najnowszej wiadomości

### Karty produktów
Gdy response zawiera `products[]`, renderuj pod wiadomością AI:
- Zdjęcie (image_url), nazwa, marka, cena, dostępność (zielony/czerwony badge)
- Klik na kartę = otwiera product_url w nowej karcie
- Layout: horyzontalny scroll jeśli > 2 produkty

### Input
- Textarea z auto-resize (rośnie do max 4 linii)
- Enter = wyślij, Shift+Enter = nowa linia
- Przycisk "Wyślij" po prawej
- Disabled podczas oczekiwania na odpowiedź
- Placeholder: "Napisz wiadomość..."

### Sesja
- session_id w sessionStorage (nowa sesja per odświeżenie strony)
- Przycisk "Nowa rozmowa" czyści czat i generuje nowy session_id

## Panel ustawień (settings.js)

### Elementy
- **Provider:** dropdown (OpenAI / Anthropic)
- **Model główny:** dropdown (zależy od providera: gpt-4.1, gpt-5-mini / claude-sonnet-4-6, claude-haiku-4-5)
- **Model eskalacji:** dropdown (gpt-5.2 / claude-opus-4-6)
- **Temperature:** slider 0.0 - 1.0, wartość obok (domyślnie 0.6)
- **Emoji:** toggle on/off
- **Knowledge gap threshold:** slider 0.0 - 1.0 (domyślnie 0.5)

### Logika
- Na load: GET /api/settings → wypełnij pola
- Na zmianę: POST /api/settings → zapisz (debounce 500ms)
- Wskaźnik "Zapisano ✓" po udanym zapisie

## Konsola debug (console.js)

### Logi
Każdy request do /api/chat generuje wpisy z timestampami:
```
[17:43:30.123] → Wysyłanie: "Pokaż mi komputery Suunto" (session: cdc1f6)
[17:43:33.456] ← Odpowiedź: 3333ms total
[17:43:33.456]   Model: claude-sonnet-4-6
[17:43:33.456]   Embedding: 120ms | AI: 2300ms | Tools: 780ms
[17:43:33.456]   Tokeny: 5427 in / 525 out (~$0.019)
[17:43:33.456]   Tools: search_products (3 wyniki, sim 0.51-0.74)
[17:43:33.456]   Knowledge gap: nie
```

Dane z response.diagnostics (TASK-008).

### JSON viewer
- Toggle "Pokaż JSON" / "Ukryj JSON"
- Surowy JSON ostatniego response z kolorowaniem składni
- Kopiuj do schowka (przycisk)

### Czyszczenie
- Przycisk "Wyczyść konsolę"
- Auto-scroll do najnowszego wpisu
- Max 100 wpisów (starsze usuwane)

## Historia czatów (history.js)

### Lista
- Na load: GET /api/conversations?per_page=20 → renderuj listę
- Każda pozycja pokazuje:
  - Session ID (skrócony, 8 znaków)
  - Data/godzina
  - Liczba wiadomości
  - Model użyty (badge)
  - Tools (ikony)
  - Koszt ($0.019)
  - Knowledge gap (🔴 jeśli true)
  - Admin status (badge: new/reviewed/knowledge_created)

### Search
- Input z searchem (filtruje po treści wiadomości)
- Debounce 300ms, GET /api/conversations?search=...

### Podgląd rozmowy
- Klik na pozycję → ładuje GET /api/conversations/{session_id}
- Wyświetla się w lewym panelu (zastępuje aktywny czat, z przyciskiem "Powrót do czatu")
- Wiadomości user/assistant jak w czacie, ale read-only
- Przycisk "Pokaż szczegóły" przy każdej wiadomości AI:
  - Rozwija się: model, tokeny, czas, tool calls z parametrami i wynikami
  - Similarity scores z kolorowaniem (zielony > 0.6, żółty 0.4-0.6, czerwony < 0.4)
  - Surowy JSON tool call i tool result

### Paginacja
- "Załaduj więcej" na dole listy (infinite scroll lub przycisk)

## Styl

### Ogólne
- Ciemny top bar z logo "DiveChat Test Console"
- Jasne tło paneli (#f5f5f5)
- Font: system-ui (bez dodatkowych fontów)
- Responsive: na wąskim ekranie (<1024px) panele stack vertically (czat na górze, reszta pod spodem)

### Kolory
- Primary: #0066cc (niebieski divezone)
- Success: #28a745
- Warning: #ffc107
- Danger: #dc3545
- Tło czatu: #ffffff
- Bąbelek user: #e3f2fd
- Bąbelek AI: #ffffff z border
- Konsola debug: #1e1e1e (ciemna), tekst #d4d4d4 (monospace)

### Karty produktów
- Biała karta z cieniem, zaokrąglone rogi
- Zdjęcie 80x80px, nazwa bold, cena duża, marka mała szara
- Badge "Od ręki" (zielony) lub "Na zamówienie" (żółty)

## Wymagania techniczne
- Vanilla JS (bez frameworków, bez jQuery)
- Fetch API do requestów
- CSS Grid/Flexbox do layoutu
- Brak bundlera, brak node_modules
- Działa na Chrome, Firefox, Safari (ostatnie 2 wersje)

## CORS
Nie dotyczy — frontend i API na tej samej domenie (chat.divezone.pl).

## Definition of Done
- [ ] GET chat.divezone.pl/ wyświetla stronę testową
- [ ] Czat wysyła wiadomości i otrzymuje odpowiedzi
- [ ] Karty produktów renderowane poprawnie
- [ ] Panel ustawień: load/save z /api/settings
- [ ] Konsola: timestampy, tokeny, koszty, tool calls
- [ ] JSON viewer: toggle, kolorowanie, kopiowanie
- [ ] Historia: lista z searchem, podgląd rozmowy, "pokaż szczegóły"
- [ ] Responsive na < 1024px
- [ ] GET /api/test-token działa (APP_ENV=dev only)
