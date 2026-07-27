# T-020: Strict exact-match fallback (case 90) + int-cast fix (case 91)

**Instancja:** backend
**Powiązane:** T-017 (auto-fallback bez kategorii), T-015 (boolean re-rank — źródło buga case 91), Arkusz3 case 90/91, decyzja 92a
**Priorytet:** P1 (case 90: realny produkt Crystal Vu nadal niewidoczny; case 91: PHP TypeError przy numeric tokenach)
**Czas:** ~2h CC
**Plik:** standalone/src/Tools/ProductSearch.php

## Kontekst

T-017 dodał fallback bez kategorii TYLKO gdy 0 wyników. Smoke wykrył że to nie wystarcza dla case 90: query="Scubapro Crystal Vu" + category="Maski jednoszybowe" zwraca 5 INNYCH masek Scubapro (semantic match na brand), więc fallback nie odpala (są wyniki — ale to substytuty, nie Crystal Vu, który jest w "Maski panoramiczne"). Decyzja 92a: rozszerzyć trigger fallback o warunek exact-match-miss.

Osobno: case 91 to pre-existing bug z T-015 — extractSignificantTokens zwraca int dla numeric tokenu "4000" (PHP auto-cast klucza string→int), countTokenMatches wybucha w str_contains (PHP 8.4 wymaga string).

## KROK 0. Read

- standalone/src/Tools/ProductSearch.php — execute() linie ~130-180 (T-017 fallback), extractSignificantTokens (824-852), countTokenMatches (859-872), stripDiacritics (874+)
- Pole nazwy w $merged['products'] to 'name' (potwierdzone: linie 573, 703)

## CZĘŚĆ A — case 90 strict fallback (decyzja 92a)

### KROK 1. Nowa metoda hasExactKeywordMatch

Dodaj prywatną metodę (obok countTokenMatches):

```php
/**
 * Czy któryś produkt zawiera WSZYSTKIE exact_keywords w nazwie (case+accent-insensitive).
 * Wieloczłonowe keywords (np. "Crystal Vu") sprawdzane jako całość (substring).
 * Używane do wykrycia "exact match miss" — gdy navigational query zwraca tylko substytuty.
 */
private function hasExactKeywordMatch(array $products, array $exactKeywords): bool
{
    if (empty($exactKeywords)) {
        return true; // brak keywords = nie blokuj (nie nasz przypadek)
    }
    $normKeywords = [];
    foreach ($exactKeywords as $kw) {
        $n = mb_strtolower($this->stripDiacritics((string) $kw));
        if ($n !== '') {
            $normKeywords[] = $n;
        }
    }
    if (empty($normKeywords)) {
        return true;
    }
    foreach ($products as $p) {
        $name = mb_strtolower($this->stripDiacritics((string) ($p['name'] ?? '')));
        if ($name === '') {
            continue;
        }
        $allMatch = true;
        foreach ($normKeywords as $kw) {
            if (!str_contains($name, $kw)) {
                $allMatch = false;
                break;
            }
        }
        if ($allMatch) {
            return true;
        }
    }
    return false;
}
```

### KROK 2. Rozszerz trigger fallback w execute()

Obecny blok (T-017, linie ~145-160):
```php
$usedFilters = $filters;
if (empty($merged['products']) && !empty($normalized['category'])) {
    // fallback bez kategorii
    ...
}
```

Zmień na (dodaj warunek navigational-miss + flagę po fallbacku):
```php
$usedFilters = $filters;
$intent = $searchPlan['intent'] ?? '';

// T-017: fallback gdy 0 wyników. T-020: także gdy navigational + exact_keywords
// zwróciło tylko substytuty (żaden wynik nie zawiera wszystkich exact_keywords w nazwie).
$needFallback = empty($merged['products']);
if (
    !$needFallback
    && $intent === 'navigational'
    && !empty($exactKeywords)
    && !empty($normalized['category'])
    && !$this->hasExactKeywordMatch($merged['products'], $exactKeywords)
) {
    $needFallback = true;
    $merged['search_debug']['navigational_miss'] = true;
}

if ($needFallback && !empty($normalized['category'])) {
    $fallbackNormalized = $normalized;
    $fallbackNormalized['category'] = null;
    $fallbackFilters = $this->buildFilters($fallbackNormalized);

    $merged = $this->runTracksAndMerge(
        $vectorStr, $expandedQuery, $trigramQuery, $fallbackFilters,
        $limit, $inStockOnly, null,
        $query, $exactKeywords,
    );
    $merged['search_debug']['category_fallback'] = true;
    $merged['search_debug']['original_category'] = $normalized['category'];
    $usedFilters = $fallbackFilters;
    if (isset($navMiss)) { /* zachowaj jeśli ustawione wcześniej */ }
}

// T-020: sygnał dla promptu — czy mimo wszystko brak dokładnego dopasowania.
// Gdy true, prompt powie "nie znalazłem dokładnie X, oto podobne" (PATCH 11 z T-018).
if ($intent === 'navigational' && !empty($exactKeywords)) {
    $merged['search_debug']['exact_match_miss'] =
        !$this->hasExactKeywordMatch($merged['products'] ?? [], $exactKeywords);
}
```

UWAGA: po fallbacku `$merged` jest nadpisywany, więc flagę `navigational_miss` ustaw przed nadpisaniem LUB ponownie po (CC zdecyduje czytelnie — flaga navigational_miss jest tylko debug, niekrytyczna; exact_match_miss jest kluczowa i liczona na końcu na finalnym $merged).

## CZĘŚĆ B — case 91 int-cast fix

### KROK 3. Fix u źródła + defensywnie

extractSignificantTokens linia ~851:
```php
return array_keys($significant);
```
→
```php
return array_map('strval', array_keys($significant)); // T-020: numeric token "4000" nie może być int (PHP key cast)
```

countTokenMatches linia ~864 (defensywnie):
```php
if ($t !== '' && str_contains($normalized, $t)) {
```
→
```php
if ($t !== '' && str_contains($normalized, (string) $t)) {
```

## KROK 4. PHP lint + STOP point

```bash
php -l standalone/src/Tools/ProductSearch.php
```

Status: "READY FOR REVIEW v1". Wklej diff (część A + B). NIE deploy bez akceptacji.

## KROK 5. Deploy (po akceptacji)

scp ProductSearch.php → prod, php -l prod (ea-php84), md5 verify.

## KROK 6. Smoke prod (scripts/t017_smoke.php lub analogiczny)

1. **case 90:** query="Scubapro Crystal Vu", category="Maski jednoszybowe", intent=navigational, exact_keywords=["Scubapro","Crystal Vu"] → fallback ODPALA (navigational_miss, bo żaden z 5 substytutów nie ma "Crystal Vu") → po fallbacku Crystal Vu (panoramiczna, id 7442/4926) JEST w wynikach, exact_match_miss=false
2. **case 91:** query="Santi BZ 4000", exact_keywords=["Santi","BZ","4000"] → NIE wybucha (int-cast OK). Sprawdź czy trigram bez kategorii łapie BZ400 (ocieplacz). Jeśli nie matchuje exact → exact_match_miss=true (prompt powie "nie ma BZ 4000, czy BZ400?")
3. **case 91b:** query="obręcze do twina 2x12" (numeric token "2", "12") → nie wybucha
4. **regresja navigational trafny:** query istniejącego modelu z dobrą kategorią → fallback NIE odpala (hasExactKeywordMatch=true)
5. **regresja exploratory:** query="płetwy paskowe" (brak exact_keywords lub intent≠navigational) → normalna ścieżka, fallback nie wymuszony

## KROK 7. Git

```bash
git status
git add standalone/src/Tools/ProductSearch.php
git commit -m "T-020: strict exact-match fallback (case 90) + int-cast fix (case 91)

Case 90: rozszerzenie triggera fallback (T-017) — odpala takze gdy
navigational + exact_keywords zwrocilo tylko substytuty (zaden wynik
nie zawiera wszystkich exact_keywords w nazwie). Crystal Vu (panoramiczna)
byl maskowany przez 5 substytutow Scubapro w blednej kategorii. Nowa metoda
hasExactKeywordMatch + flaga exact_match_miss dla promptu (PATCH 11 T-018).

Case 91 (pre-existing T-015): extractSignificantTokens zwracal int dla
numeric tokenu '4000' (PHP key auto-cast) -> TypeError w str_contains PHP 8.4.
Fix: array_map strval u zrodla + (string) cast defensywnie w countTokenMatches.

Decyzja 92a. Arkusz3 case 90, 91, 95(2x12)."
git push origin main
```

## KROK 8. Raport + status

_instances/backend/handoff/T-020_done.md + update _docs/21_STATUS_PROJEKTU.md (T-020 DEPLOYED, case 90/91 zamknięte — Arkusz3 w pełni domknięty). Osobny commit docs:.

## Out of scope

- Dodanie scripts/t017_smoke.php do gita (świadomie lokalny; jeśli chcesz wersjonować smoke → osobna decyzja)
- Red-team harness (następny projekt po Arkusz3)
- Soundex/metaphone fonetyka (trigram + fallback wystarcza dla obecnych case'ów)
