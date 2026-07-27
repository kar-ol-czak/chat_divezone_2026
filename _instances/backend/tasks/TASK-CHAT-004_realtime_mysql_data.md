# TASK-CHAT-004: Real-time dane produktów z MySQL (cena, stan, visibility)
# Data: 2026-03-09
# Status: DO ZROBIENIA
# Instancja: backend
# Priorytet: KRYTYCZNY — bez tego czat podaje nieaktualne ceny i stany
# ADR: do zapisania po implementacji

---

## PROBLEM

ProductSearch zwraca cenę, stan i dostępność z pgvector (divechat_product_embeddings),
które są zamrożone od 20 lutego 2026. Efekty:
- Produkty dostępne dziś mają in_stock=false w pgvector → nie pojawiają się
- Ceny mogą być nieaktualne (zmienione w ciągu 3 tygodni)
- Produkty z visibility=none (ukryte w sklepie) pojawiają się w wynikach
- in_stock_only=true (poprawne architektonicznie) nie działa → cofnięte na false

## ARCHITEKTURA DOCELOWA

pgvector przechowuje TYLKO dane statyczne (do semantic search):
- embedding vectors (nie zmieniają się)
- product_name, category_name, brand_name (rzadko się zmieniają)
- fts_vector (do fulltext search)

MySQL PrestaShop dostarcza dane RUNTIME (przy każdym wyszukiwaniu):
- price (aktualna cena brutto)
- quantity / in_stock (aktualny stan)
- visibility (czy widoczny)
- active (czy aktywny)

## ZMIANA W ProductSearch.php

### Nowa metoda: enrichWithMySQLData()

Po RRF fusion (mamy listę product IDs), PRZED zwróceniem wyników:

```php
/**
 * Wzbogaca wyniki wyszukiwania o aktualne dane z MySQL PrestaShop.
 * Zastępuje zamrożone dane z pgvector real-time danymi.
 * 
 * UWAGA: PrestaShop przechowuje ceny NETTO w ps.price, nawet jeśli
 * w backoffice podaje się brutto. JOIN na pr_tax jest KONIECZNY
 * żeby przeliczyć na brutto dla klienta.
 */
private function enrichWithMySQLData(array $productIds): array
{
    if (empty($productIds)) {
        return [];
    }

    $mysql = MysqlConnection::getInstance();
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));

    $rows = $mysql->fetchAll(
        "SELECT 
            p.id_product,
            ROUND(ps.price * (1 + COALESCE(t.rate, 23) / 100), 2) AS price_brutto,
            COALESCE(sa.quantity, 0) AS quantity,
            ps.active,
            ps.visibility
        FROM pr_product p
        JOIN pr_product_shop ps ON p.id_product = ps.id_product AND ps.id_shop = 1
        LEFT JOIN pr_stock_available sa ON p.id_product = sa.id_product 
            AND sa.id_product_attribute = 0
        LEFT JOIN pr_tax_rule tr ON p.id_tax_rules_group = tr.id_tax_rules_group 
            AND tr.id_country = 14
        LEFT JOIN pr_tax t ON tr.id_tax = t.id_tax
        WHERE p.id_product IN ({$placeholders})",
        $productIds
    );

    $dataById = [];
    foreach ($rows as $row) {
        $dataById[(int) $row['id_product']] = [
            'price' => (float) $row['price_brutto'],
            'in_stock' => ((int) $row['quantity']) > 0,
            'quantity' => (int) $row['quantity'],
            'active' => (bool) $row['active'],
            'visible' => $row['visibility'] !== 'none',
        ];
    }

    return $dataById;
}
```

### Integracja w execute() / mergeRRF()

W metodzie mergeRRF(), po uzyskaniu $topIds (lista product IDs z RRF):

```php
// Po: $topIds = array_slice(array_keys($scores), 0, $limit);

// Wzbogać o real-time dane z MySQL
$mysqlData = $this->enrichWithMySQLData($topIds);

// Filtruj: ukryte i nieaktywne produkty
$topIds = array_filter($topIds, function (int $id) use ($mysqlData) {
    $data = $mysqlData[$id] ?? null;
    if ($data === null) return true; // brak danych MySQL = zachowaj (fallback)
    return $data['active'] && $data['visible'];
});
$topIds = array_values($topIds);

// Filtruj in_stock_only (teraz z AKTUALNYCH danych!)
if (!empty($normalized['in_stock_only'])) {
    $topIds = array_filter($topIds, function (int $id) use ($mysqlData) {
        return ($mysqlData[$id]['in_stock'] ?? false);
    });
    $topIds = array_values($topIds);
}
```

Potem w sekcji budowania $products[] array, zastąp dane z pgvector danymi z MySQL:

```php
$products[] = [
    'id' => (int) $row['ps_product_id'],
    'name' => $row['product_name'],
    'brand' => $row['brand_name'],
    'category' => $row['category_name'],
    // Real-time z MySQL (fallback na pgvector jeśli brak)
    'price' => $mysqlData[$id]['price'] ?? (float) $row['price'],
    'in_stock' => $mysqlData[$id]['in_stock'] ?? (bool) $row['in_stock'],
    'url' => $row['product_url'],
    'image_url' => $row['image_url'],
    'similarity' => round($rrfScore, 4),
];
```

### Przywróć in_stock_only default na TRUE

Po implementacji enrichWithMySQLData(), in_stock_only filtruje po AKTUALNYCH
danych z MySQL (nie po zamrożonych z pgvector). Teraz default TRUE jest bezpieczny:

```php
'in_stock_only' => $filtersInput['in_stock_only'] ?? $params['in_stock_only'] ?? true,
```

### Dodaj MysqlConnection do constructora

ProductSearch aktualnie używa tylko PostgresConnection. Dodaj MysqlConnection:

```php
public function __construct(
    private readonly EmbeddingService $embeddingService,
    private readonly PostgresConnection $db,
    private readonly SynonymExpander $synonymExpander,
) {}
```

MysqlConnection jest singletonem (MysqlConnection::getInstance()), więc nie
potrzebuje DI — możesz wywołać bezpośrednio w enrichWithMySQLData().
Sprawdź jak OrderStatus.php to robi (ten sam pattern).

### Boost w RRF: zamień pgvector in_stock na MySQL in_stock

Obecny boost (z TASK-CHAT-003) pobiera in_stock z pgvector:
```php
$stockRows = $this->db->fetchAll(
    "SELECT ps_product_id, in_stock FROM divechat_product_embeddings..."
);
```

Zamień na dane z MySQL (mysqlData). Przenieś boost PO enrichWithMySQLData():

```php
// Boost dostępnych (teraz z AKTUALNYCH danych MySQL)
foreach ($scores as $id => &$score) {
    if (!($mysqlData[$id]['in_stock'] ?? false)) {
        $score *= 0.3;
    }
}
unset($score);
arsort($scores);
```

Usuń stary boost który query'ował pgvector o in_stock.

## ZMIANA W SystemPrompt.php

Zaktualizuj sekcję DOSTĘPNOŚĆ:

```
DOSTĘPNOŚĆ PRODUKTÓW:
- search_products domyślnie zwraca TYLKO dostępne produkty (in_stock_only=true)
- Dane o cenach i stanach magazynowych są AKTUALNE (pobierane w real-time ze sklepu)
- NIGDY nie polecaj produktu niedostępnego jako pierwszej opcji
- Jeśli klient pyta ogólnie ("szukam płetw"), szukaj TYLKO dostępnych
- Jeśli klient pyta o konkretny model ("macie Mares Avanti?"), szukaj z in_stock_only=false
- Jeśli masz 0 wyników: UPROŚĆ query, zmień kategorię — NIE mów "nie mamy" zanim nie spróbujesz prostszego query
```

## ZMIANA W config/tools.php (jeśli potrzebna)

Sprawdź czy MysqlConnection jest już importowany/dostępny w kontekście
ProductSearch. Jeśli nie — dodaj odpowiedni use/import.

## TESTY

### Test 1: Cena aktualna
Znajdź produkt którego cenę zmieniono w PrestaShop od 20 lutego.
Czat powinien podać AKTUALNĄ cenę, nie tę z 20 lutego.

### Test 2: Stan magazynowy aktualny
Produkt który był niedostępny 20 lutego ale teraz jest dostępny.
Z in_stock_only=true powinien się pojawić.

### Test 3: Visibility=none
Produkt z visibility=none w PrestaShop (np. Mares Volo Power).
NIE powinien pojawić się w wynikach nawet z in_stock_only=false.

### Test 4: "Szukam automatu na początek"
Oczekiwane: lista automatów z aktualnymi cenami i stanami.
Niedopuszczalne: "Nie mamy automatów"

### Test 5: Wydajność
Dodatkowe query do MySQL nie powinno dodawać >50ms.
Sprawdź response_times w diagnostyce.

## NIE RÓB

- Nie usuwaj pól price/in_stock z pgvector (fallback jeśli MySQL niedostępny)
- Nie zmieniaj logiki 5-track RRF
- Nie zmieniaj ExpertKnowledge.php
- Nie modyfikuj embeddingów
