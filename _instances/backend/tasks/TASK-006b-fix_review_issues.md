# TASK-006b-fix: Fixy z review kodu TASK-006b
# Data: 2026-02-20
# Priorytet: KRYTYCZNY (przed jakimkolwiek testem E2E)
# Zależności: TASK-006b ukończony

## Kontekst
Review kodu TASK-006b wykrył 2 bugi krytyczne, 3 poważne problemy i 4 mniejsze poprawki.
Wszystko do naprawienia przed deployem i testami.

---

## 🔴 FIX-1: SystemPrompt — interpolacja marek (KRYTYCZNY)
**Plik:** standalone/src/Chat/SystemPrompt.php
**Problem:** Heredoc `{brands}` i `{banned}` to nie zmienne PHP, lecz literalny tekst. AI dostaje string "{brands}" zamiast listy marek.
**Fix:** Przypisz stałe do zmiennych lokalnych przed heredocem:
```php
$brands = self::ALLOWED_BRANDS;
$banned = self::BANNED_BRANDS;
return <<<PROMPT
...Dozwolone marki: {$brands}
ZAKAZANE marki: {$banned}...
PROMPT;
```
**Test:** Wywołaj SystemPrompt::build(), sprawdź że output zawiera "TECLINE" i "Cressi".

---

## 🔴 FIX-2: Rehydratacja tool_calls z historii (KRYTYCZNY)
**Plik:** standalone/src/Chat/ChatService.php + standalone/src/AI/ClaudeProvider.php
**Problem:** Historia z ConversationStore zwraca tool_calls jako tablice asocjacyjne (po json_decode). ClaudeProvider::formatAssistantMessage() próbuje czytać `$tc->id` na tablicy = fatal error przy wznowieniu rozmowy.
**Fix — dwie opcje (wybierz jedną):**
A) W ChatService::handle(), po wczytaniu historii, rehydratuj ToolCall objects:
```php
foreach ($history as &$msg) {
    if (!empty($msg['tool_calls'])) {
        $msg['tool_calls'] = array_map(
            fn(array $tc) => new ToolCall($tc['id'], $tc['name'], $tc['arguments']),
            $msg['tool_calls']
        );
    }
}
```
B) W ClaudeProvider::formatAssistantMessage(), obsłuż oba typy (array i ToolCall):
```php
$id = $tc instanceof ToolCall ? $tc->id : $tc['id'];
```
**Rekomendacja:** Opcja A (rehydratacja w jednym miejscu, reszta kodu nie musi się martwić).
**UWAGA:** OpenAIProvider::formatAssistantMessage() ma ten sam bug — type hint `fn(ToolCall $tc)` crashuje na tablicach. Rehydratacja w ChatService (opcja A) naprawia oba providery naraz.
**Test:** Utwórz sesję, wykonaj zapytanie z tool call, wyślij drugą wiadomość w tej samej sesji.

---

## 🟠 FIX-3: Tool result grouping dla Claude API
**Plik:** standalone/src/AI/ClaudeProvider.php
**Problem:** Wiele tool_result messages z jednego turnu asystenta powinno być w jednym user message z wieloma content blocks. Aktualnie każdy to osobna wiadomość role: user.
**Fix:** W formatowaniu wiadomości (metoda chat()), grupuj kolejne tool_result w jedną wiadomość user:
```php
// Zamiast osobnych wiadomości:
// {role: user, content: [{type: tool_result, tool_use_id: "1", ...}]}
// {role: user, content: [{type: tool_result, tool_use_id: "2", ...}]}
// Złącz w jedną:
// {role: user, content: [{type: tool_result, tool_use_id: "1", ...}, {type: tool_result, tool_use_id: "2", ...}]}
```
**Uwaga:** To dotyczy TYLKO ClaudeProvider. OpenAI API oczekuje osobnych wiadomości `role: tool` per wynik — tam obecny format jest poprawny.
**Test:** Wymuś scenariusz gdzie AI wywołuje 2 narzędzia naraz (np. search_products + expert_knowledge).

---

## 🟠 FIX-4: Context window management
**Plik:** standalone/src/Chat/ChatService.php
**Problem:** Cała historia leci do API. Przy dłuższych rozmowach z tool calls łatwo przekroczyć limit tokenów.
**Fix:** Przed wysłaniem do AI, przycinaj historię:
- System prompt: zawsze
- Ostatnich 10 wiadomości user/assistant (z ich tool_calls/tool_results)
- Starsze wiadomości: pomijane
- Stała MAX_HISTORY_MESSAGES = 10 (konfigurowalna)
**Test:** Symuluj rozmowę z >15 wiadomościami, sprawdź że nie crashuje.

---

## 🟠 FIX-5: Wydzielenie EmbeddingService z AIProvider
**Pliki do utworzenia:** standalone/src/AI/EmbeddingService.php
**Pliki do edycji:** standalone/src/AI/AIProviderInterface.php, standalone/src/AI/ClaudeProvider.php, standalone/src/Tools/ProductSearch.php
**Problem:** ClaudeProvider::getEmbedding() woła OpenAI API — naruszenie SRP. Nowy GuzzleHttp Client przy każdym wywołaniu.
**Fix:**
1. Utwórz `EmbeddingService` z metodą `getEmbedding(string $text): array`
2. Wewnątrz: OpenAI text-embedding-3-large, dimensions=1536, jeden Client reusowany
3. Usuń `getEmbedding()` z `AIProviderInterface` i implementacji (ClaudeProvider, OpenAIProvider)
4. `ProductSearch` dostaje `EmbeddingService` przez konstruktor zamiast `AIProviderInterface`
5. `ExpertKnowledge` (jeśli używa embeddingów) analogicznie
**Test:** Wywołaj ProductSearch z zapytaniem, sprawdź czy embedding generowany poprawnie.

---

## 🟠 FIX-6: DI dla PostgresConnection w ProductSearch
**Plik:** standalone/src/Tools/ProductSearch.php
**Problem:** `PostgresConnection::getInstance()` wywoływane wewnątrz execute() — niespójne z resztą (aiProvider wstrzykiwany w konstruktorze). Nietestowalne.
**Fix:** Dodaj PostgresConnection do konstruktora:
```php
public function __construct(
    private readonly EmbeddingService $embeddingService, // po FIX-5
    private readonly PostgresConnection $db,
) {}
```
Analogicznie sprawdź ExpertKnowledge i inne narzędzia korzystające z DB.

---

## 🟡 FIX-7: Mniejsze poprawki

### 7a) Logowanie wyczerpania tool loop
**Plik:** standalone/src/Chat/ChatService.php
Po pętli for, jeśli `$response->hasToolCalls() === true` (loop wyczerpany), loguj ostrzeżenie (Monolog). AI utknął w pętli narzędzi.

### 7b) Parametr limit w ProductSearch
**Plik:** standalone/src/Tools/ProductSearch.php
Dodaj parametr `limit` do schema (type: integer, description: "Liczba wyników", default: 5, max: 10). Użyj w SQL LIMIT.

### 7c) Usage w response API
**Plik:** standalone/src/Controller/ChatController.php
Dodaj `'usage' => $result['usage']` do Response::json(). Przydatne do debugowania i monitoringu kosztów.

### 7d) Retry z backoffem dla API calls
**Plik:** standalone/src/AI/ClaudeProvider.php i OpenAIProvider.php (oraz nowy EmbeddingService po FIX-5)
Dodaj prostą logikę retry: 1 retry po 2s przy HTTP 429 lub 5xx. Guzzle middleware lub ręczny try/catch.

---

## Kolejność wykonania
1. FIX-1 (SystemPrompt) — trywialne, 2 minuty
2. FIX-2 (rehydratacja tool_calls) — 10 minut
3. FIX-5 (EmbeddingService) — refactor, 20 minut
4. FIX-6 (DI PostgresConnection) — 10 minut
5. FIX-3 (tool result grouping) — 15 minut
6. FIX-4 (context window) — 15 minut
7. FIX-7a-d (mniejsze) — 20 minut łącznie

## Pliki do zmodyfikowania (podsumowanie)
- standalone/src/Chat/SystemPrompt.php (FIX-1)
- standalone/src/Chat/ChatService.php (FIX-2, FIX-4, FIX-7a)
- standalone/src/AI/AIProviderInterface.php (FIX-5)
- standalone/src/AI/ClaudeProvider.php (FIX-3, FIX-5, FIX-7d)
- standalone/src/AI/OpenAIProvider.php (FIX-3, FIX-5, FIX-7d)
- standalone/src/Tools/ProductSearch.php (FIX-5, FIX-6, FIX-7b)
- standalone/src/Tools/ExpertKnowledge.php (FIX-5, FIX-6 — sprawdzić)
- standalone/src/Controller/ChatController.php (FIX-7c)
- **NOWY:** standalone/src/AI/EmbeddingService.php (FIX-5)
