# TASK-CHAT-001: Integracja encyklopedii z czatem AI
# Data: 2026-03-06
# Status: DO ZROBIENIA
# Instancja: backend
# Zależność: TASK-ENC-012 DONE (525 chunków w encyclopedia_chunks)

---

## CEL

Podłączenie encyklopedii sprzętu nurkowego (525 chunków, 105 haseł) do czatu AI.
Aktualizacja narzędzia ExpertKnowledge + SystemPrompt.

## KROK 1: Aktualizacja ExpertKnowledge.php

Plik: `standalone/src/Tools/ExpertKnowledge.php`

### Zmiana tabeli

Stara tabela: `divechat_knowledge` (Q&A format, stara baza wiedzy)
Nowa tabela: `encyclopedia_chunks` (5 typów chunków per hasło)

### Nowy schemat parametrów

```php
public function getParametersSchema(): array
{
    return [
        'type' => 'object',
        'properties' => [
            'query' => [
                'type' => 'string',
                'description' => 'Pytanie lub temat do wyszukania w encyklopedii sprzętu nurkowego',
            ],
            'chunk_types' => [
                'type' => 'array',
                'items' => [
                    'type' => 'string',
                    'enum' => ['definition', 'synonyms', 'purchase', 'faq', 'seller'],
                ],
                'description' => 'Typy chunków do przeszukania. '
                    . 'definition: co to jest, jak działa. '
                    . 'synonyms: nazwy, slang, frazy wyszukiwania. '
                    . 'purchase: parametry zakupowe, cross-sell, porównania. '
                    . 'faq: odpowiedzi na typowe pytania klientów. '
                    . 'seller: wewnętrzne porady sprzedawcy (nie cytuj klientowi). '
                    . 'Domyślnie: ["definition", "faq", "purchase"].',
            ],
            'concept_key' => [
                'type' => 'string',
                'description' => 'Opcjonalny filtr na konkretne hasło, np. "AUTOMAT_ODDECHOWY". '
                    . 'Użyj gdy wiesz dokładnie jakiego sprzętu dotyczy pytanie.',
            ],
        ],
        'required' => ['query'],
    ];
}
```

### Nowa logika execute()

```php
public function execute(array $params): array
{
    $query = $params['query'] ?? '';
    $chunkTypes = $params['chunk_types'] ?? ['definition', 'faq', 'purchase'];
    $conceptKey = $params['concept_key'] ?? null;
    
    $embedding = $this->embeddingService->getEmbedding($query);
    $vectorStr = '[' . implode(',', $embedding) . ']';
    
    // Warunki
    $conditions = [];
    $sqlParams = [];
    
    // Filtr chunk_type
    if (!empty($chunkTypes)) {
        $placeholders = implode(',', array_fill(0, count($chunkTypes), '?'));
        $conditions[] = "chunk_type IN ({$placeholders})";
        $sqlParams = array_merge($sqlParams, $chunkTypes);
    }
    
    // Filtr concept_key
    if ($conceptKey !== null) {
        $conditions[] = 'concept_key = ?';
        $sqlParams[] = $conceptKey;
    }
    
    // Similarity threshold
    $conditions[] = '1 - (embedding <=> ?::vector) > 0.45';
    $sqlParams[] = $vectorStr;
    
    $where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
    
    // Dodaj vector param na koniec (dla ORDER BY)
    $sqlParams[] = $vectorStr;
    
    $sql = "SELECT concept_key, chunk_type, content, name_pl,
                   1 - (embedding <=> ?::vector) AS similarity,
                   metadata
            FROM encyclopedia_chunks
            {$where}
            ORDER BY embedding <=> ?::vector
            LIMIT 5";
    
    // Uwaga: sqlParams musi mieć vector na pozycji SELECT i ORDER BY
    // Zbuduj prawidłową listę parametrów z wektorem na początku i końcu
    $finalParams = [$vectorStr, ...$sqlParams]; // SELECT vector
    // Nie — trzeba to przemyśleć. Vector jest w:
    // 1. SELECT: 1 - (embedding <=> ?::vector)  
    // 2. WHERE: 1 - (embedding <=> ?::vector) > 0.45
    // 3. ORDER BY: embedding <=> ?::vector
    // Użyj numerowanych placeholderów ($1, $2...) zamiast ? 
    // albo powtórz vectorStr w params.
    
    // WAŻNE: PostgresConnection używa PDO z ? placeholders.
    // Trzeba dostarczyć vectorStr tyle razy ile jest ? dla niego.
    
    $results = $this->db->fetchAll($sql, $finalParams);
    
    if (empty($results)) {
        return [
            'knowledge' => [],
            'message' => 'Nie znaleziono wiedzy na ten temat w encyklopedii.',
        ];
    }
    
    return [
        'knowledge' => array_map(fn(array $row) => [
            'concept_key' => $row['concept_key'],
            'name' => $row['name_pl'],
            'chunk_type' => $row['chunk_type'],
            'content' => $row['content'],
            'similarity' => round((float) $row['similarity'], 3),
        ], $results),
        'count' => count($results),
    ];
}
```

UWAGA dla CC: powyższy kod to SZKIC. Dopasuj do faktycznego API PostgresConnection
(sprawdź jak ProductSearch buduje query z ? placeholderami i wektorem).

### Aktualizacja opisu narzędzia

```php
public function getDescription(): string
{
    return 'Przeszukuje encyklopedię sprzętu nurkowego (105 haseł). '
         . 'Zawiera definicje, podtypy, parametry zakupowe, FAQ klientów, cross-sell i porady sprzedawcy. '
         . 'UŻYWAJ PRZED search_products gdy klient pyta o porady, porównania lub "jaki sprzęt wybrać". '
         . 'Wynik daje kontekst ekspercki który pomaga lepiej dobrać produkty.';
}
```

## KROK 2: Aktualizacja SystemPrompt.php

Dodaj sekcję po "JAK SZUKAĆ PRODUKTÓW":

```
BAZA WIEDZY EKSPERCKIEJ (get_expert_knowledge):
Masz dostęp do encyklopedii 105 rodzajów sprzętu nurkowego z wiedzą ekspercką.
KIEDY UŻYWAĆ:
- Klient pyta "co to jest X" lub "jak działa X" → query="X", chunk_types=["definition"]
- Klient pyta "jaki X wybrać" lub "X dla początkującego" → query="jaki X wybrać", chunk_types=["faq", "purchase"]  
- Klient porównuje produkty "jacket czy skrzydło" → query="jacket skrzydło różnice", chunk_types=["definition", "purchase"]
- Potrzebujesz wiedzy o cross-sell → chunk_types=["purchase"]
- Potrzebujesz porad wewnętrznych → chunk_types=["seller"] (NIE cytuj klientowi dosłownie!)

WORKFLOW DLA PYTAŃ EKSPLORACYJNYCH:
1. NAJPIERW get_expert_knowledge (zdobądź wiedzę o kategorii)
2. POTEM search_products (znajdź konkretne produkty pasujące do potrzeb klienta)
3. Połącz wiedzę ekspercką z wynikami wyszukiwania w spójną rekomendację

PRZYKŁADY:
Klient: "Jaki automat oddechowy na początek?"
→ get_expert_knowledge: query="jaki automat oddechowy wybrać początek", chunk_types=["faq", "purchase"]
→ Z wyniku dowiadujesz się: zestaw rekreacyjny, DIN, EN250A, ACD jako wyróżnik
→ search_products: query="zestaw automat oddechowy rekreacyjny DIN", category="Automaty Oddechowe"

Klient: "Czym się różni jacket od skrzydła?"
→ get_expert_knowledge: query="jacket skrzydło różnice BCD", chunk_types=["definition", "purchase"]
→ Odpowiadasz na bazie wiedzy encyklopedycznej, BEZ szukania produktów (chyba że klient poprosi)

Klient: "Szukam suchego skafandra"
→ get_expert_knowledge: query="suchy skafander wybór", chunk_types=["faq", "purchase"]
→ Dowiadujesz się: trylaminat dominuje, ocieplacz konieczny, buty+rękawice cross-sell
→ search_products: query="suchy skafander trylaminat", category="Skafandry suche"
→ Proponujesz skafander + ocieplacz + rękawice (bo encyklopedia mówi o cross-sell)
```

## KROK 3: Aktualizacja diagnostyki w ChatService

W metodzie `buildSearchDiagnostic()` dodaj `get_expert_knowledge` do listy `$searchTools` 
(UWAGA: to JUŻ jest zrobione — sprawdź linię ~261):
```php
$searchTools = ['search_products', 'get_expert_knowledge'];
```

## KROK 4: Test

Napisz test w `tests/` który:

1. Tworzy ExpertKnowledge z prawdziwym PostgresConnection
2. Wywołuje execute() z:
   - query="jaki automat oddechowy wybrać", chunk_types=["faq"]
   - query="jacket skrzydło różnica", chunk_types=["definition"]  
   - query="suchy skafander ocieplacz", chunk_types=["purchase"]
3. Sprawdza: wyniki >0, similarity >0.45, concept_keys sensowne

## NIE RÓB

- Nie modyfikuj ProductSearch.php
- Nie usuwaj starej tabeli divechat_knowledge (deprecated, usunie się później)
- Nie zmieniaj ToolRegistry (ExpertKnowledge już jest zarejestrowane)
- Nie modyfikuj ChatService.php (diagnostyka już obsługuje get_expert_knowledge)
