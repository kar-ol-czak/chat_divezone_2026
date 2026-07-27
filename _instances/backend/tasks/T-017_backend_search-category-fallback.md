# T-017: Search auto-fallback bez kategorii (fix case 90 Crystal Vu)

**Instancja:** backend
**Powiązane:** Arkusz3 case 90 (Crystal Vu "nie mamy" mimo że istnieje), case 91 (BZ 4000)
**Priorytet:** P1 (realny produkt którego klient nie dostaje)
**Czas:** ~1.5h CC
**Plik:** standalone/src/Tools/ProductSearch.php (równoległy z T-018 prompt — różne pliki)

## Diagnoza (potwierdzona w PG)

Case 90: klient "skubapro krystal vu". Bot wywołał search_products z:
- query="Scubapro Crystal Vu", exact_keywords=["Scubapro","Crystal Vu"] (OK)
- category="Maski jednoszybowe" (BŁĄD — Crystal Vu to maska PANORAMICZNA)

Crystal Vu w bazie: category_name="Maski panoramiczne"/"Zestawy Maska+Fajka", parent="Maski i fajki". Filtr category="Maski jednoszybowe" odciął wszystko → 0 wyników → bot "nie mamy". Produkt ISTNIEJE (3 SKU, m.in. 7442, 4926).

Root cause: model przy navigational query zgadł złą podkategorię. Reguła "uprość query gdy 0 wyników" jest w prompcie, ale model jej nie wykonał. Potrzebne deterministyczne zabezpieczenie search-side.

## Cel

Gdy search z filtrem kategorii zwraca 0 wyników, automatycznie ponów search BEZ filtra kategorii (zanim zwrócisz "nie znaleziono"). Deterministyczne, nie zależy od posłuszeństwa modelu.

## KROK 0. Read

- standalone/src/Tools/ProductSearch.php — szczególnie execute() linie ~110-170 (gdzie 5 torów + mergeRRF + `if (empty($merged['products']))`)
- buildFilters() linie ~150-220 (jak category trafia do WHERE)
- _docs/24_analiza_testow_pracownikow_arkusz3.md (case 90, 91)

## KROK 1. Auto-fallback bez kategorii

W execute(), w bloku `if (empty($merged['products']))` (linia ~160) — ZANIM zwrócisz pusty wynik:

Jeśli `$normalized['category']` było ustawione (nie null/empty), ponów CAŁY pipeline bez kategorii:

```php
if (empty($merged['products'])) {
    // T-017: auto-fallback — kategoria mogła odciąć trafny produkt
    // (np. model zgadł złą podkategorię dla konkretnego modelu)
    if (!empty($normalized['category'])) {
        $fallbackNormalized = $normalized;
        $fallbackNormalized['category'] = null;
        $fallbackFilters = $this->buildFilters($fallbackNormalized);

        $fbName = $this->searchSemanticColumn('embedding_name', $vectorStr, $fallbackFilters);
        $fbDesc = $this->searchSemanticColumn('embedding_desc', $vectorStr, $fallbackFilters);
        $fbJargon = $this->searchSemanticColumn('embedding_jargon', $vectorStr, $fallbackFilters);
        $fbFulltext = $expandedQuery !== '' ? $this->searchFullText($expandedQuery, $fallbackFilters) : [];
        $fbTrigram = $this->searchTrigram($trigramQuery, $fallbackFilters);

        $merged = $this->mergeRRF(
            $fbName, $fbDesc, $fbJargon, $fbFulltext, $fbTrigram,
            $limit, $inStockOnly, null, $query, $exactKeywords
        );
        $merged['search_debug']['category_fallback'] = true;
        $merged['search_debug']['original_category'] = $normalized['category'];

        // dołącz search_plan + meta jak w głównej ścieżce
        if (!empty($searchPlan)) {
            $merged['search_debug']['search_plan'] = $searchPlan;
        }
        foreach ($fallbackFilters['meta'] ?? [] as $key => $value) {
            $merged['search_debug'][$key] = $value;
        }
    }
}

// Dopiero teraz sprawdź czy nadal pusto
if (empty($merged['products'])) {
    return [
        'products' => [],
        'message' => 'Nie znaleziono produktów pasujących do zapytania.',
        'search_debug' => $merged['search_debug'] ?? [],
    ];
}
```

CC dostosuje do faktycznej struktury (refaktor 5 torów do prywatnej metody runTracks($filters) jeśli czytelniejsze — DRY zamiast duplikacji 5 linii).

## KROK 2. Flaga dla promptu

`search_debug.category_fallback=true` + `original_category` — informuje że wynik pochodzi z poszerzonego wyszukiwania. T-018 (prompt) wykorzysta: bot może zaznaczyć "znalazłem w innej kategorii" zamiast udawać że szukał dokładnie.

## KROK 3. PHP lint + smoke lokalny

```bash
php -l standalone/src/Tools/ProductSearch.php
```

Jeśli PG dostępne: test execute z query="Scubapro Crystal Vu" + category="Maski jednoszybowe" → powinien zwrócić Crystal Vu (przez fallback).

## KROK 4. STOP point — diff do review Karol

Status: "READY FOR REVIEW v1". Wklej diff execute() + plan smoke prod. NIE deploy bez akceptacji.

## KROK 5. Deploy

scp ProductSearch.php, php -l prod, md5 verify.

## KROK 6. Smoke prod (chat)

1. "Macie skubapro krystal vu?" → bot ZNAJDUJE Crystal Vu (panoramiczna), NIE mówi "nie mamy"
2. "Santi BZ 4000 suchy skafander" → sprawdź czy trigram bez kategorii łapie BZ400 ocieplacz (literówka 4000→400). Jeśli tak, bot proponuje BZ400. Jeśli nie (za duża literówka), to case dla T-018 prompt (powiedz wprost "nie ma BZ 4000")
3. Regression: "płetwy" → kategoria Płetwy działa normalnie (fallback NIE uruchamia się gdy są wyniki)

## KROK 7. Git workflow

```bash
git status
git add standalone/src/Tools/ProductSearch.php
git commit -m "T-017: search auto-fallback bez kategorii (fix case 90 Crystal Vu)

Gdy search z filtrem kategorii zwraca 0 wyników, auto-ponawia bez
kategorii zanim zwroci 'nie znaleziono'. Naprawia przypadek gdy model
zgadl zla podkategorie dla konkretnego modelu (Crystal Vu = panoramiczna,
model szukal w jednoszybowych -> 0 wynikow -> bledne 'nie mamy').
Deterministyczne, nie zalezy od posluszenstwa modelu.

Flaga search_debug.category_fallback dla T-018 prompt.
Arkusz3 case 90, 91."
git push origin main
```

## KROK 8. Raport + status

_instances/backend/handoff/T-017_done.md + update _docs/21_STATUS_PROJEKTU.md (T-017 DEPLOYED). Osobny commit docs:.

## Out of scope

- Reguly promptowe (nie zgaduj kategorii, nie fabrykuj awarii) → T-018
- Fonetyka zaawansowana (soundex/metaphone) — trigram obecnie wystarcza dla "krystal"→"crystal"; jeśli smoke pokaże braki, osobny task
