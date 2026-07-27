# TASK-CHAT-002: Fix rekomendacji produktów — dostępność + encyklopedia + popularność
# Data: 2026-03-09
# Status: DO ZROBIENIA
# Instancja: backend
# Zależność: TASK-CHAT-001 DONE (encyklopedia zintegrowana)

---

## PROBLEM

Czat polecił 4 pary płetw, z czego 3 niedostępne. Dostępne bestsellery (Mares Avanti
Quattro, Mares Avanti Pure, Aqualung Express) nie zostały polecone.
Przyczyny:
1. `in_stock_only` domyślnie FALSE — AI szuka wszystkich w tym niedostępnych
2. AI nie użył encyklopedii przed szukaniem — nie wiedział co jest popularne
3. Brak priorytetyzacji po popularności — similarity decyduje, nie sprzedaż

## ZMIANA 1: in_stock_only domyślnie TRUE

Plik: `standalone/src/Tools/ProductSearch.php`

W `getParametersSchema()` zmień opis parametru `in_stock_only`:

```php
'in_stock_only' => [
    'type' => 'boolean',
    'description' => 'Filtruj tylko dostępne produkty. DOMYŚLNIE TRUE. '
        . 'Ustaw na false TYLKO gdy klient pyta o konkretny model który może być niedostępny '
        . '(np. "macie Mares Avanti Quattro?").',
],
```

W `normalizeParams()` zmień default:

```php
'in_stock_only' => $filtersInput['in_stock_only'] ?? $params['in_stock_only'] ?? true,
```

Było: `?? false` → teraz: `?? true`

## ZMIANA 2: Boost dostępnych produktów w RRF

Plik: `standalone/src/Tools/ProductSearch.php`

W metodzie `mergeRRF()`, po obliczeniu RRF score, dodaj boost dla dostępnych:

Znajdź sekcję gdzie budujesz `$products[]` array (linia ~375-400) i dodaj
pobranie `in_stock` z `$rowsById`:

```php
// Po: arsort($scores);
// Przed: $topIds = array_slice(...)

// Boost dostępnych produktów (in_stock = true → score × 1.0, false → score × 0.3)
// Pobierz status in_stock dla wszystkich kandydatów
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
            $score *= 0.3; // niedostępne 3× niżej
        }
    }
    unset($score);
    arsort($scores); // re-sort po boost
}

$topIds = array_slice(array_keys($scores), 0, $limit);
```

UWAGA: Ten boost działa NAWET gdy in_stock_only=false (np. klient pyta o konkretny model).
Dostępne będą wyżej, niedostępne niżej ale nie wykluczone.

## ZMIANA 3: System prompt — workflow i dostępność

Plik: `standalone/src/Chat/SystemPrompt.php`

### 3a. Dodaj sekcję DOSTĘPNOŚĆ PRODUKTÓW po sekcji BAZA WIEDZY EKSPERCKIEJ:

```
DOSTĘPNOŚĆ PRODUKTÓW:
- search_products domyślnie zwraca TYLKO dostępne produkty (in_stock_only=true)
- NIGDY nie polecaj produktu niedostępnego jako pierwszej opcji
- Jeśli klient pyta ogólnie ("szukam płetw"), szukaj TYLKO dostępnych
- Jeśli klient pyta o konkretny model ("macie Mares Avanti?"), szukaj z in_stock_only=false
- Jeśli wyników jest mało (<3), poszerz kategorię lub zmień query — NIE wyłączaj filtra dostępności
- Gdy produkt jest niedostępny, ZAWSZE zaproponuj dostępną alternatywę
```

### 3b. Zaktualizuj sekcję WORKFLOW w BAZA WIEDZY EKSPERCKIEJ:

Zmień workflow na bardziej precyzyjny:

```
WORKFLOW DLA PYTAŃ "JAKI SPRZĘT WYBRAĆ":
1. NAJPIERW get_expert_knowledge — dowiedz się co jest popularne, polecane, jakie są podtypy
2. POTEM search_products z in_stock_only=true — znajdź DOSTĘPNE produkty z polecanych kategorii
3. Jeśli mało wyników — zmień query lub kategorię, ale ZACHOWAJ in_stock_only=true
4. Połącz wiedzę ekspercką z wynikami w spójną rekomendację
5. Proponuj 2-4 DOSTĘPNE produkty, NIE wymieniaj niedostępnych chyba że klient pyta o konkretny model
```

### 3c. Zaktualizuj przykłady w sekcji JAK SZUKAĆ PRODUKTÓW:

Dodaj do każdego przykładu `in_stock_only: true` gdzie relevantne:

Zmień przykład z pianką:
```
Klient: "Szukam pianki na nurkowanie w Polsce"
→ get_expert_knowledge: query="pianka nurkowa Polska zimna woda", chunk_types=["faq","purchase"]
→ Z wyniku: 5mm uniwersalna, 7mm wymiera, półsuchy martwy → kieruj na 5mm lub suchy
→ search_plan: intent="exploratory", reasoning="Klient szuka pianki na Polskę = zimna woda."
→ category: "Skafandry Na ZIMNE wody"
→ filters: in_stock_only=true
→ query: "skafander 5mm zimna woda Polska"
```

Dodaj nowy przykład z płetwami (bo to był konkretny case):
```
Klient: "Szukam płetw do nurkowania"
→ get_expert_knowledge: query="płetwy nurkowe wybór", chunk_types=["faq","purchase"]
→ Z wyniku: paskowe wymagają butów, Mares Quattro/Avanti top 3, jet fins rośnie
→ Pytanie doprecyzowujące: "Czy nurkujesz w butach neoprenowych (płetwy paskowe) czy na gołą stopę (kaloszowe)?"
→ search_plan: intent="exploratory", reasoning="Klient szuka płetw paskowych rekreacyjnych"
→ category: "Płetwy Paskowe na Buta"
→ filters: in_stock_only=true
→ query: "płetwy paskowe rekreacyjne"
```

## KROK 4: Test

Przetestuj na czacie (manual lub curl):

### Test 1: "Szukam płetw do nurkowania"
Oczekiwane:
- AI najpierw wywołuje get_expert_knowledge
- AI pyta o paskowe vs kaloszowe (lub od razu poleca paskowe jeśli kontekst jasny)
- search_products z in_stock_only=true
- Wyniki: TYLKO dostępne płetwy
- Mares Avanti Quattro / Avanti Pure powinny być w wynikach (bestsellery, dostępne)

### Test 2: "Macie Mares Avanti Quattro?"
Oczekiwane:
- search_products z in_stock_only=false (klient pyta o konkretny model)
- Jeśli dostępne → potwierdź cenę i dostępność
- Jeśli niedostępne → pokaż ale zaproponuj alternatywę dostępną

### Test 3: "Jaki automat oddechowy na początek?"
Oczekiwane:
- get_expert_knowledge PRZED search_products
- Encyklopedia: zestaw rekreacyjny, DIN, ACD
- search_products: dostępne automaty, in_stock_only=true
- Rekomendacja łączy wiedzę + produkty

## NIE RÓB

- Nie zmieniaj logiki 5-track RRF (semantic, FTS, trigram)
- Nie modyfikuj ExpertKnowledge.php (TASK-CHAT-001 zrobione)
- Nie zmieniaj ChatService.php
- Nie usuwaj parametru in_stock_only (tylko zmień default)
