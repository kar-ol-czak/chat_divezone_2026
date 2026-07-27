# TASK-CHAT-006: Diagnoza i naprawa — trigram/FTS zwracają 0 na serwerze
# Data: 2026-03-09
# Status: PILNE
# Instancja: backend
# Kontekst: Produkt "Płetwy POSEIDON Trident Fin" (ID 3892) jest w pgvector,
#   trigram=0.296, FTS matchuje, ale serwer zwraca fts_count:0, trigram_count:0.
#   Semantic search (name/desc/jargon) działa. Problem tylko z FTS i trigram.

---

## PROBLEM

Na serwerze chat.divezone.pl search_products zwraca fts_count:0 i trigram_count:0
dla query "płetwy Trident", mimo że bezpośrednie query do bazy zwraca wyniki.

Dowody z diagnostyki (sesja 145f15da):
```
tracks: {fts_count: 0, desc_count: 30, name_count: 30, jargon_count: 30, trigram_count: 0}
```

Bezpośredni test na bazie (z lokalnego Pythona):
```
TRIGRAM: #3892 Płetwy POSEIDON Trident Fin trgm=0.296
FTS: #3892 Płetwy POSEIDON Trident Fin rank=0.7083
```

## ZADANIE

### Krok 1: Sprawdź plik na serwerze

SSH na serwer i sprawdź:
```bash
wc -l standalone/src/Tools/ProductSearch.php
md5sum standalone/src/Tools/ProductSearch.php
```

Porównaj z lokalnym:
- Lokalny: 631 linii, 25216 bajtów
- Jeśli różnica → plik się obciął przy uploadzię → wgraj ponownie

### Krok 2: Sprawdź czy FTS i trigram w ogóle się wywołują

Dodaj tymczasowe logowanie w execute() PRZED wywołaniem mergeRRF:

```php
// TYMCZASOWY DEBUG — usunąć po diagnozowaniu
error_log("[DiveChat DEBUG] Query: {$query}");
error_log("[DiveChat DEBUG] TrigramQuery: {$trigramQuery}");
error_log("[DiveChat DEBUG] ExpandedQuery: {$expandedQuery}");
error_log("[DiveChat DEBUG] Semantic name: " . count($semanticName) . " results");
error_log("[DiveChat DEBUG] Semantic desc: " . count($semanticDesc) . " results");
error_log("[DiveChat DEBUG] Semantic jargon: " . count($semanticJargon) . " results");
error_log("[DiveChat DEBUG] FTS: " . count($fulltext) . " results");
error_log("[DiveChat DEBUG] Trigram: " . count($trigram) . " results");
```

Wgraj, przetestuj "płetwy trident", sprawdź logi PHP:
```bash
tail -50 /var/log/php*.log
# lub
grep "DiveChat DEBUG" /var/log/php*.log
```

### Krok 3: Sprawdź czy FTS/trigram rzucają exception

W searchFullText() jest try/catch który cicho łapie PDOException.
W searchTrigram() NIE MA try/catch — może rzucić exception.

Dodaj tymczasowy try/catch do obu metod:

```php
// W searchTrigram(), owiń $this->db->fetchAll() w try/catch:
try {
    $rows = $this->db->fetchAll($sql, $params);
} catch (\Throwable $e) {
    error_log("[DiveChat DEBUG] Trigram ERROR: " . $e->getMessage());
    return [];
}
```

```php
// W searchFullText(), w istniejącym catch dodaj log:
catch (\PDOException $e) {
    error_log("[DiveChat DEBUG] FTS primary ERROR: " . $e->getMessage());
    // ... existing fallback code ...
}
```

### Krok 4: Sprawdź PostgreSQL connection

Może problem z prepared statements lub connection pooling.
Sprawdź logi PostgreSQL na Railway:
```bash
# Dashboard Railway → PostgreSQL → Logs
```

### Krok 5: Sprawdź expandForFts()

SynonymExpander::expandForFts() może zwracać pusty string dla "płetwy Trident"
co powoduje że FTS się w ogóle nie uruchamia:

```php
$fulltext = $expandedQuery !== '' ? $this->searchFullText($expandedQuery, $filters) : [];
```

Loguj expandedQuery:
```php
error_log("[DiveChat DEBUG] expandedQuery = '{$expandedQuery}'");
```

### Krok 6: Sprawdź trigramQuery

AI wysłał exact_keywords=["Trident"]. Kod:
```php
$trigramQuery = !empty($exactKeywords) ? implode(' ', $exactKeywords) : $query;
```

Więc trigramQuery = "Trident". To powinno działać (testowaliśmy bezpośrednio).
Loguj żeby potwierdzić:
```php
error_log("[DiveChat DEBUG] trigramQuery = '{$trigramQuery}'");
```

## PO DIAGNOZIE

Gdy znajdziesz przyczynę:
1. Napraw problem
2. Usuń tymczasowe logi DEBUG
3. Przetestuj "płetwy trident" — Trident powinien być #1 w wynikach
4. Przetestuj "szukam automatu na początek" — powinny być wyniki

## DODATKOWE ZMIANY DO WGRANIA

Lokalny ProductSearch.php ma zmiany których jeszcze NIE MA na serwerze:
1. Fix out_of_stock: MAX(quantity) + out_of_stock flag → availability
2. Fix boost: boost ×0.3 TYLKO gdy in_stock_only=true
3. Pole availability w wynikach (in_stock / available_to_order / unavailable)

TE ZMIANY SĄ W LOKALNYM PLIKU. Po diagnozie i naprawie FTS/trigram,
wgraj CAŁY lokalny plik na serwer (ma wszystkie fixy).

Lokalny SystemPrompt.php też ma zmiany:
- Sekcja DOSTĘPNOŚĆ z rozróżnieniem ogólne/konkretny model
- availability: in_stock / available_to_order / unavailable

## OCZEKIWANY WYNIK PO NAPRAWIE

Test "płetwy trident":
- fts_count: ≥1
- trigram_count: ≥1
- Trident #1 w candidates_before_mysql z rrf ≈ 0.08
- availability: "available_to_order" (quantity=0, out_of_stock=1)
- AI mówi: "Tak, mamy Płetwy POSEIDON Trident Fin — 849 zł, dostępne na zamówienie (2-5 dni)"

Test "szukam automatu na początek":
- Wyniki >0 (automaty z aktualnego stanu)
- Ceny aktualne z MySQL

→ STOP po naprawie i testach. Raportuj co było przyczyną.
