# TASK-011: SSE streaming statusów w czacie
# Data: 2026-02-20
# Instancje: backend + frontend
# Priorytet: WYSOKI (UX, postrzegany czas oczekiwania)
# Status: DO ZROBIENIA

## Problem
Gdy AI wywołuje narzędzia (search_products x3), czas odpowiedzi sięga 30-40s.
Użytkownik widzi tylko animację trzech kropek, co jest frustrujące.

## Rozwiązanie: Server-Sent Events (SSE)
Backend streamuje statusy w trakcie przetwarzania zamiast jednej odpowiedzi na końcu.

## Architektura

### Backend: nowy endpoint POST /api/chat/stream
Zamiast `Response::json()` na końcu, `ChatService` emituje eventy:
```
Content-Type: text/event-stream

event: status
data: {"text": "Analizuję Twoje pytanie..."}

event: status
data: {"text": "Przeszukuję ofertę nurkową — chwilka..."}

event: status
data: {"text": "Dobieram najlepsze produkty..."}

event: done
data: {pełna odpowiedź jak dotychczas: response, products, diagnostics}

event: error
data: {"error": "treść błędu"}
```

### Kiedy emitować statusy (ChatService):
1. Po otrzymaniu pytania → "Analizuję Twoje pytanie..."
2. Przed pierwszym tool call → "Przeszukuję ofertę nurkową — chwilka..."
3. Po tool results, przed finalnym AI call → "Przygotowuję odpowiedź..."
4. Po zakończeniu → event: done z pełnym JSON

### Frontend: zamiana fetch na EventSource
- chat.js: nowy tryb SSE zamiast fetch POST
- Wyświetlanie statusów w bąbelku AI (zamiast typing indicator)
- Fallback na zwykły fetch jeśli SSE nie zadziała

### PHP SSE helper:
```php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

function emitStatus(string $text): void {
    echo "event: status\ndata: " . json_encode(['text' => $text]) . "\n\n";
    ob_flush(); flush();
}
```

## Bonus: przygotowanie pod token-by-token streaming
SSE otwiera drogę do streamowania tokenów z AI API w przyszłości.
Na razie tylko statusy, bez streamowania treści odpowiedzi.

## Pliki do zmiany
- standalone/src/Controller/ChatController.php (nowy endpoint stream)
- standalone/src/Chat/ChatService.php (callback statusów)
- standalone/public/js/chat.js (EventSource)
- standalone/public/index.html (bez zmian)
