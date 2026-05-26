# TASK-CHAT-005: Rozbudowane logowanie search_products
# Data: 2026-03-09
# Status: DO ZROBIENIA
# Instancja: backend
# Priorytet: WYSOKI — bez tego nie możemy diagnozować problemów z wyszukiwaniem

---

## PROBLEM

Diagnostyka search_products zwraca tylko: count, max/min similarity, gap flag.
Nie widzimy: jaki query AI wysłał, ile matchów per track, które produkty odfiltrowano
przez MySQL enrichment i dlaczego. Niemożliwe do zdiagnozowania dlaczego produkt
który jest w bazie i matchuje w trigram/FTS nie pojawia się w wynikach.

## ZMIANA W ProductSearch.php — metoda mergeRRF()

### Rozbuduj search_debug w output

Obecna struktura:
```json
{
  "tracks": {"name_count": 5, "desc_count": 5, ...},
  "rrf_k": 60,
  "items": [{"product_id": 123, "rrf_score": 0.046, ...}]
}
```

Nowa struktura:
```json
{
  "query": "płetwy Trident",
  "search_plan": {...},
  "tracks": {
    "name_count": 5, "desc_count": 5, "jargon_count": 3,
    "fts_count": 1, "trigram_count": 1
  },
  "rrf_k": 60,
  "candidates_before_mysql": [
    {"id": 3892, "name": "Płetwy POSEIDON Trident Fin", "rrf_score": 0.046,
     "tracks": {"trigram": 1, "fts": 1, "name": null, "desc": 15, "jargon": null}}
  ],
  "mysql_enrichment": {
    "success": true,
    "products": {
      "3892": {"price": 599.0, "in_stock": true, "active": true, "visible": true}
    }
  },
  "filtered_out": [
    {"id": 9999, "name": "Produkt X", "reason": "visibility=none"}
  ],
  "items": [
    {"product_id": 3892, "rrf_score": 0.046, "dominant_track": "trigram",
     "mysql_price": 599.0, "mysql_in_stock": true}
  ]
}
```

### Implementacja krok po kroku

#### 1. Zbieraj kandydatów PRZED filtrowaniem MySQL

Po `arsort($scores)` i przed filtrowaniem, zapisz snapshot:

```php
// Snapshot kandydatów przed MySQL enrichment
$candidatesBeforeMySQL = [];
foreach (array_slice(array_keys($scores), 0, 20) as $id) {
    $pgRow = $rowsById[$id] ?? null;
    $candidatesBeforeMySQL[] = [
        'id' => $id,
        'name' => $pgRow['product_name'] ?? 'unknown',
        'rrf_score' => round($scores[$id], 6),
        'tracks' => $trackInfo[$id] ?? [],
    ];
}
```

UWAGA: $rowsById jest budowany PÓŹNIEJ w kodzie. Musisz albo przesunąć
pobieranie danych produktów wcześniej, albo zbudować mapę nazw z wyników
poszczególnych torów. Najprościej: pobierz nazwy osobno:

```php
$candidateIdsList = array_slice(array_keys($scores), 0, $candidateLimit);
$namesPlaceholders = implode(',', array_fill(0, count($candidateIdsList), '?'));
$nameRows = $this->db->fetchAll(
    "SELECT ps_product_id, product_name FROM divechat_product_embeddings WHERE ps_product_id IN ({$namesPlaceholders})",
    $candidateIdsList
);
$namesById = [];
foreach ($nameRows as $row) {
    $namesById[(int) $row['ps_product_id']] = $row['product_name'];
}
```

#### 2. Loguj wyniki enrichWithMySQLData

```php
$mysqlLog = [
    'success' => !empty($mysqlData),
    'count' => count($mysqlData),
    'products' => [],
];
foreach ($candidateIdsList as $id) {
    if (isset($mysqlData[$id])) {
        $mysqlLog['products'][$id] = $mysqlData[$id];
    }
}
```

#### 3. Loguj odfiltrowane produkty

```php
$filteredOut = [];
// W filtrze active/visible:
$filteredIds = array_filter(array_keys($scores), function (int $id) use ($mysqlData, &$filteredOut, $namesById) {
    $data = $mysqlData[$id] ?? null;
    if ($data === null) return true;
    $keep = $data['active'] && $data['visible'];
    if (!$keep) {
        $filteredOut[] = [
            'id' => $id,
            'name' => $namesById[$id] ?? 'unknown',
            'reason' => !$data['active'] ? 'active=false' : 'visibility=none',
            'mysql_data' => $data,
        ];
    }
    return $keep;
});

// Analogicznie dla in_stock_only
if ($inStockOnly) {
    $filteredIds = array_filter($filteredIds, function (int $id) use ($mysqlData, &$filteredOut, $namesById) {
        $inStock = $mysqlData[$id]['in_stock'] ?? false;
        if (!$inStock) {
            $filteredOut[] = [
                'id' => $id,
                'name' => $namesById[$id] ?? 'unknown',
                'reason' => 'in_stock_only=true, quantity=0',
                'mysql_data' => $mysqlData[$id] ?? null,
            ];
        }
        return $inStock;
    });
}
```

#### 4. Dołącz do search_debug

```php
return [
    'products' => $products,
    'count' => count($products),
    'search_debug' => [
        'query' => $query ?? null,
        'tracks' => [...],
        'rrf_k' => $k,
        'candidates_before_mysql' => array_slice($candidatesBeforeMySQL, 0, 10),
        'mysql_enrichment' => $mysqlLog,
        'filtered_out' => $filteredOut,
        'items' => $debugItems,
    ],
];
```

## ZMIANA W ChatService.php — zapisz pełny debug

Upewnij się że search_diagnostics zapisywane do bazy zawierają pełny search_debug:

```php
// W buildSearchDiagnostic()
if ($toolCall->name === 'search_products' && !empty($result['search_debug'])) {
    $diag['search_debug'] = $result['search_debug'];
}
```

## ZMIANA W konsolce testowej (frontend)

Nie wymagane w tym tasku, ale nice-to-have: wyświetlanie search_debug
w "Pokaż szczegóły". Osobny task dla frontendu.

## TESTY

1. Wyślij "a dostane u was płetwy trident?"
2. Otwórz sesję w konsolce → Pokaż szczegóły
3. Sprawdź w bazie search_diagnostics:
   - candidates_before_mysql: Trident powinien być na liście z rrf_score
   - mysql_enrichment: co MySQL zwrócił dla Tridenta (active, visible)
   - filtered_out: czy Trident został odfiltrowany i DLACZEGO

## NIE RÓB

- Nie zmieniaj logiki wyszukiwania (5-track, RRF, MySQL enrichment)
- Nie zmieniaj SystemPrompt.php
- Logi mają być w search_debug (JSONB), nie w error_log
