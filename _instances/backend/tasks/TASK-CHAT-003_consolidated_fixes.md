# TASK-CHAT-003: Zbiorczy fix czatu — 4 problemy
# Data: 2026-03-09
# Status: DO ZROBIENIA
# Instancja: backend
# Zastępuje: TASK-CHAT-002 (wchłonięty)

---

## KONTEKST

4 problemy wykryte podczas testów na żywo. Część zmian już naniesiona
lokalnie przez architekta (SystemPrompt.php, OrderStatus.php) — CC musi
je zweryfikować i uzupełnić o zmiany w ProductSearch.php i ChatService.php.

## PROBLEM 1: Niedostępne produkty w rekomendacjach

AI poleca produkty "Na zamówienie" jako pierwsze, pomijając dostępne bestsellery.

### Fix 1a: ProductSearch.php — default in_stock_only

UWAGA: Default COFNIĘTY na false przez architekta (stany magazynowe w pgvector
nieaktualne — z 20 lutego). In_stock_only=true spowodowało 0 wyników.
Zostawiamy false do czasu TASK-CHAT-004 (real-time dane z MySQL).

Zmień opis parametru w getParametersSchema():
```php
'in_stock_only' => [
    'type' => 'boolean',
    'description' => 'Filtruj tylko dostępne produkty. Domyślnie false (stany mogą nie być aktualne). '
        . 'UWAGA: stany magazynowe w bazie wektorowej mogą być nieaktualne.',
],
```

### Fix 1b: ProductSearch.php — boost dostępnych w RRF

W mergeRRF(), PO `arsort($scores)` a PRZED `$topIds = array_slice(...)`:

```php
// Boost: dostępne produkty wyżej, niedostępne ×0.3
$candidateIds = array_keys($scores);
if (!empty($candidateIds)) {
    $placeholders = implode(',', array_fill(0, count($candidateIds), '?'));
    $stockRows = $this->db->fetchAll(
        "SELECT ps_product_id, in_stock FROM divechat_product_embeddings WHERE ps_product_id IN ({$placeholders})",
        $candidateIds
    );
    $stockMap = [];
    foreach ($stockRows as $row) {
        $stockMap[(int) $row['ps_product_id']] = (bool) $row['in_stock'];
    }
    foreach ($scores as $id => &$score) {
        if (!($stockMap[$id] ?? false)) {
            $score *= 0.3;
        }
    }
    unset($score);
    arsort($scores);
}
```

---

## PROBLEM 2: Query przeładowany cechami standardowymi

AI dodaje "DIN", "certyfikacja zimne wody", "EN250A", "sucha komora" do query.
Te cechy to standard rynkowy — nie występują w nazwach produktów → 0 wyników.

### Fix 2: SystemPrompt.php — JUŻ NANIESIONY PRZEZ ARCHITEKTA

Zweryfikuj że w pliku jest sekcja "KRYTYCZNE ZASADY BUDOWANIA QUERY" z listą
zakazanych terminów (DIN, EN250A, sucha komora, membranowy, tłokowy).

Zweryfikuj też że przykład APEKS nie zawiera "zimna woda DIN" w query.

Jeśli zmiany architekta NIE są w pliku (bo nadpisałeś go w TASK-CHAT-001/002),
nałóż je ponownie. Pełna treść zmian:

Dodaj PO linii "query: w terminologii sklepu, NIE słowa klienta.":
```
KRYTYCZNE ZASADY BUDOWANIA QUERY:
NIE dodawaj do query cech które są STANDARDEM w danej kategorii:
- NIE pisz "DIN" — WSZYSTKIE automaty w sklepie są DIN, to jedyny standard
- NIE pisz "certyfikacja zimne wody" ani "EN250A" — większość automatów to ma, to nie jest w nazwie produktu
- NIE pisz "sucha komora" — to nie występuje w nazwach produktów
- NIE pisz "membranowy" ani "tłokowy" — klienci tak nie szukają
Query musi zawierać słowa które FAKTYCZNIE występują w nazwach i opisach produktów w sklepie.
Dobre query: "zestaw automat oddechowy Apeks", "płetwy paskowe Mares"
Złe query: "automat oddechowy DIN certyfikacja zimne wody membranowy odciążony"
Jeśli masz 0 wyników, UPROŚĆ query — usuń przymiotniki i filtry, zostaw rdzeń.
```

Zmień przykład APEKS na:
```
Klient: "Automat na zimną wodę, najlepiej APEKS"
→ search_plan: intent="navigational", reasoning="Klient szuka automatu APEKS. Zimna woda = standard w Polsce, nie dodawać do query.", exact_keywords=["APEKS"]
→ category: "Automaty Oddechowe"
→ filters: brand="APEKS"
→ query: "automat oddechowy APEKS"
```

W sekcji dostępności zmień "Jeśli wyników jest mało" na:
```
- Jeśli wyników jest mało (<3) lub 0: UPROŚĆ query (usuń przymiotniki, filtry, cechy standardowe), zmień kategorię — NIE mów klientowi że nie mamy produktów zanim nie spróbujesz prostszego query
- Jeśli nadal 0: szukaj bez kategorii, z samą nazwą typu sprzętu
```

---

## PROBLEM 3: Format kodu zamówienia

PrestaShop używa kodów literowych (np. AODMYANNV), nie numerów.
Klienci nie wiedzą gdzie go znaleźć.

### Fix 3: OrderStatus.php — JUŻ NANIESIONY PRZEZ ARCHITEKTA

Zweryfikuj 4 zmiany:

1. getDescription(): "kod referencyjny zamówienia (ciąg liter, np. AODMYANNV)"
2. getParametersSchema() order_reference description:
   "Kod referencyjny zamówienia — ciąg liter w formacie np. \"AODMYANNV\"
   (klient znajdzie go u góry maila z potwierdzeniem zamówienia)"
3. execute() error empty: "Wymagany kod referencyjny zamówienia i email"
4. execute() error not found: "Nie znaleziono zamówienia o podanym kodzie referencyjnym i emailu."

Zweryfikuj w SystemPrompt.php:
```
- Przy pytaniach o zamówienie, poproś o kod referencyjny zamówienia
  (ciąg liter w formacie AODMYANNV — znajdziesz go u góry maila
  z potwierdzeniem zamówienia) oraz adres email użyty przy zakupie
```

---

## PROBLEM 4: Historia konwersacji ucinana

Rozmowy w panelu admina nie zaczynają się od początku. Bug: trimHistory()
przycina historię do 10 wiadomości DLA KONTEKSTU LLM, ale potem save()
nadpisuje CAŁĄ historię w bazie tą przyciętą wersją.

### Fix 4: ChatService.php

Problem jest w metodzie handle(). Obecny flow:

```php
// 1. Wczytaj historię z bazy
$history = $this->conversationStore->startOrResume($sessionId, $customerId);

// 2. Przytnij do 10 (dla LLM)
$history = $this->trimHistory($history);  // ← PROBLEM: nadpisuje $history

// 3. Zbuduj messages z przyciętej historii
foreach ($history as $msg) { $messages[] = $msg; }

// ... tool loop ...

// 4. Zapisz — ale $messages bazuje na przyciętej historii!
$historyToSave = array_values(array_filter($messages, ...));
$this->conversationStore->save($sessionId, $historyToSave, ...);
// ← ZAPISUJE PRZYCIĘTĄ WERSJĘ, stare wiadomości znikają
```

Fix: zachowaj pełną historię osobno, trimuj tylko dla LLM:

```php
// 1. Wczytaj PEŁNĄ historię z bazy
$fullHistory = $this->conversationStore->startOrResume($sessionId, $customerId);

// 2. Rehydratuj ToolCall objects
foreach ($fullHistory as &$msg) {
    if (!empty($msg['tool_calls']) && is_array($msg['tool_calls'])) {
        $msg['tool_calls'] = array_map(
            fn(array $tc) => new ToolCall($tc['id'], $tc['name'], $tc['arguments'] ?? []),
            $msg['tool_calls'],
        );
    }
}
unset($msg);

// 3. Przytnij KOPIĘ dla LLM kontekstu (pełna historia nienaruszona)
$trimmedHistory = $this->trimHistory($fullHistory);

// 4. Zbuduj messages z PRZYCIĘTEJ historii (dla LLM)
$messages = [
    ['role' => 'system', 'content' => SystemPrompt::build($settings['emoji_enabled'])],
];
foreach ($trimmedHistory as $msg) {
    if ($msg['role'] !== 'system') {
        $messages[] = $msg;
    }
}

// ... dodaj user message, tool loop jak teraz ...

// 5. ZAPISZ: pełna historia + nowe wiadomości z tego turnu
//    Wyciągnij TYLKO nowe wiadomości (te których nie było w fullHistory)
$newMessages = [];
// Nowa wiadomość user
$newMessages[] = ['role' => 'user', 'content' => $message];
// Wiadomości z tool loop (assistant z tool_calls + tool_results)
// ... zbieraj w pętli tool loop ...
// Finalna odpowiedź assistant
if (!$response->hasToolCalls()) {
    $assistantMsg = ['role' => 'assistant', 'content' => $finalContent];
    if (!empty($products)) {
        $assistantMsg['products'] = $products;
    }
    $newMessages[] = $assistantMsg;
}

// Serializuj tool_calls w nowych wiadomościach
$newMessages = array_map(function (array $m) {
    if (!empty($m['tool_calls'])) {
        $m['tool_calls'] = array_map(fn($tc) => [
            'id' => $tc->id,
            'name' => $tc->name,
            'arguments' => $tc->arguments,
        ], $m['tool_calls']);
    }
    return $m;
}, $newMessages);

// Serializuj pełną historię (zamień ToolCall objects na arrays)
$fullHistorySerialized = array_map(function (array $m) {
    if (!empty($m['tool_calls'])) {
        $m['tool_calls'] = array_map(function ($tc) {
            if ($tc instanceof ToolCall) {
                return ['id' => $tc->id, 'name' => $tc->name, 'arguments' => $tc->arguments];
            }
            return $tc;
        }, $m['tool_calls']);
    }
    return $m;
}, $fullHistory);

// Złącz: pełna historia + nowe wiadomości
$historyToSave = array_merge(
    array_values(array_filter($fullHistorySerialized, fn(array $m) => $m['role'] !== 'system')),
    $newMessages
);

$this->conversationStore->save($sessionId, $historyToSave, ...);
```

UWAGA: Powyższy kod to SZKIC logiki. Dopasuj do istniejącej struktury ChatService.
Kluczowa zasada: `fullHistory` (z bazy) NIGDY nie jest trimowana.
`trimHistory()` działa TYLKO na kopii wysyłanej do LLM.

---

## TESTY

### Test 1: Dostępność
Pytanie: "Szukam płetw paskowych do nurkowania"
Oczekiwane: TYLKO dostępne ("Od ręki"), Mares Avanti w wynikach
Niedopuszczalne: produkty "Na zamówienie" jako pierwsza rekomendacja

### Test 2: Query bez DIN
Pytanie: "Jaki automat oddechowy do 4000 zł?"
Oczekiwane: wyniki (automaty w ofercie), query BEZ "DIN" i "EN250A"
Niedopuszczalne: "Nie mamy automatów DIN" / 0 wyników

### Test 3: Kod zamówienia
Pytanie: "Jaki jest status mojego zamówienia?"
Oczekiwane: "Podaj kod referencyjny zamówienia (ciąg liter w formacie AODMYANNV
— znajdziesz go u góry maila z potwierdzeniem) oraz adres email"
Niedopuszczalne: "Podaj numer zamówienia"

### Test 4: Historia
1. Wyślij 15+ wiadomości w jednej sesji
2. Sprawdź w bazie: SELECT jsonb_array_length(messages) FROM divechat_conversations WHERE session_id = ?
Oczekiwane: ≥30 elementów (15 user + 15 assistant + tool calls)
Niedopuszczalne: ≤20 (ucięte przez trimHistory)

---

## PLIKI DO ZMODYFIKOWANIA

1. `standalone/src/Tools/ProductSearch.php` — Fix 1a + 1b (in_stock default + RRF boost)
2. `standalone/src/Chat/SystemPrompt.php` — Fix 2 + częściowo Fix 3 (ZWERYFIKUJ czy zmiany architekta są)
3. `standalone/src/Tools/OrderStatus.php` — Fix 3 (ZWERYFIKUJ czy zmiany architekta są)
4. `standalone/src/Chat/ChatService.php` — Fix 4 (historia)

## NIE RÓB

- Nie modyfikuj ExpertKnowledge.php (TASK-CHAT-001 zrobione)
- Nie modyfikuj ToolRegistry.php
- Nie zmieniaj logiki 5-track RRF (tylko dodaj boost)
- Nie usuwaj trimHistory() — jest potrzebne dla LLM kontekstu


---

## PROBLEM 5: Produkty z visibility="Nigdzie" w wynikach wyszukiwania

W PrestaShop produkty mogą mieć active=1 i stan > 0 ale visibility="none" (Nigdzie).
Takie produkty nie powinny być widoczne w sklepie ani w czacie.
Pipeline embeddingów nie sprawdza pola visibility.

### Fix 5a: extract_products.py — dodaj filtr visibility

Plik: `embeddings/extract_products.py`

W PRODUCTS_SQL, do WHERE dodaj:

```sql
WHERE ps.active = 1
  AND ps.visibility != 'none'
```

Opcjonalnie dodaj `ps.visibility` do SELECT żeby mieć tę informację w danych:
```sql
SELECT
    ...
    ps.active,
    ps.visibility,
    COALESCE(sa.quantity, 0) AS quantity
```

### Fix 5b: Re-embed po zmianie

Po zmodyfikowaniu extract_products.py trzeba przebudować embeddingi produktów.
To jest osobna operacja (instancja embeddings, nie backend).

Opcja szybka: w PostgreSQL ustaw is_active=false dla produktów z visibility=none:
```sql
UPDATE divechat_product_embeddings dpe
SET is_active = false
FROM (
    SELECT p.id_product
    FROM pr_product p
    JOIN pr_product_shop ps ON p.id_product = ps.id_product AND ps.id_shop = 1
    WHERE ps.visibility = 'none'
) AS hidden
WHERE dpe.ps_product_id = hidden.id_product;
```

UWAGA: To wymaga dostępu do MySQL PrestaShop. Jeśli pipeline embeddings
nie ma bezpośredniego dostępu do MySQL (SSH tunnel), zrób to ręcznie:
1. Wyciągnij listę ID produktów z visibility=none z MySQL
2. Ustaw is_active=false w PostgreSQL dla tych ID

### Fix 5c: ProductSearch.php — już filtruje po is_active

Sprawdź w buildFilters():
```php
$conditions = ['is_active = true'];
```
To już jest — więc po ustawieniu is_active=false w Fix 5b, produkty znikną z wyników.


### Test 5: Produkty z visibility=none
1. Sprawdź w MySQL: SELECT id_product, visibility FROM pr_product_shop WHERE visibility = 'none' AND active = 1;
2. Sprawdź w PostgreSQL czy te produkty mają is_active=true:
   SELECT ps_product_id, product_name, is_active FROM divechat_product_embeddings WHERE ps_product_id IN (...);
3. Po fixie: te produkty powinny mieć is_active=false
Niedopuszczalne: produkt z visibility=none pojawia się w wynikach search_products


---

## PROBLEM 6: Brak linku śledzenia mimo numeru przesyłki w PrestaShop

Zamówienie MXDPRXUYG ma numer śledzenia w backoffice (tab WYSYŁKA), ale czat
nie wyświetla go klientowi lub wyświetla generyczny tekst bez URL.

Przyczyna: `pr_carrier.url` może nie zawierać znaku `@` (placeholder) lub być
pusty — wtedy `str_replace('@', tracking_number, url)` zwraca null/pusty string.

### Fix 6: OrderStatus.php — fallback URL dla InPost

W sekcji budowania tracking URL (linia ~104), po obecnym kodzie:

```php
if ($carrier && !empty($carrier['tracking_number'])) {
    $trackingUrl = $carrier['tracking_url']
        ? str_replace('@', $carrier['tracking_number'], $carrier['tracking_url'])
        : null;

    // Fallback: jeśli URL jest pusty lub nie zawiera numeru, zbuduj z nazwy przewoźnika
    if (empty($trackingUrl) || $trackingUrl === $carrier['tracking_url']) {
        $carrierLower = strtolower($carrier['carrier_name'] ?? '');
        if (str_contains($carrierLower, 'inpost') || str_contains($carrierLower, 'paczkomat')) {
            $trackingUrl = 'https://inpost.pl/sledzenie-przesylek?number=' . urlencode($carrier['tracking_number']);
        } elseif (str_contains($carrierLower, 'dpd')) {
            $trackingUrl = 'https://tracktrace.dpd.com.pl/parcelDetails?typ=1&p1=' . urlencode($carrier['tracking_number']);
        } elseif (str_contains($carrierLower, 'dhl')) {
            $trackingUrl = 'https://www.dhl.com/pl-pl/home/tracking.html?tracking-id=' . urlencode($carrier['tracking_number']);
        }
    }

    $result['tracking'] = [
        'carrier' => $carrier['carrier_name'],
        'number' => $carrier['tracking_number'],
        'url' => $trackingUrl,
    ];
}
```

Kluczowa zmiana: `$trackingUrl === $carrier['tracking_url']` wykrywa przypadek gdy
`str_replace` nie zrobił nic (bo nie było `@` w template) — URL wygląda normalnie
ale nie zawiera numeru przesyłki.

### Test 6: Śledzenie przesyłki
Sprawdź oba zamówienia:
- AODMYANNV: powinien zwracać pełny URL InPost z numerem (jak teraz)
- MXDPRXUYG: powinien zwracać pełny URL InPost z numerem (fallback)
Oba powinny mieć format: https://inpost.pl/sledzenie-przesylek?number=XXXXX
