# Ocena metodologii MVP i propozycja lepszego pipeline (encyklopedia nurkowa / synonimy produktowe)

## 1. Moje podsumowanie

Kierunek jest bardzo dobry i trafia w realny problem, czyli halucynacje terminologiczne modelu przy sprzęcie nurkowym. Najważniejsza wartość MVP nie polega na "zbudowaniu encyklopedii", tylko na zbudowaniu **kontrolowanego słownika pojęć z relacjami** (synonim, quasi-synonim, nie_mylić_z, PL/EN, marka vs typ produktu, część vs całość).

Największe ryzyko w takich projektach to nie brak danych, tylko **mieszanie poziomów pojęć**. Przykład: produkt handlowy, kategoria sklepu, nazwa potoczna, nazwa techniczna, nazwa marketingowa producenta. Jeśli te poziomy nie są rozdzielone w schemacie, model zacznie przeciekać synonimami między kategoriami i generować błędne mapowania.

Dla Twojego przypadku kluczowe są trzy rzeczy:
1. **Terminologia jako produkt danych** (wersjonowana, testowana, z ownerem zmian).
2. **Regresja semantyczna** (po każdej zmianie słownika i promptów).
3. **Twarde reguły negatywne** (nie_mylić_z) ważniejsze niż same synonimy.

MVP powinno więc być zbudowane nie jako „encyklopedia tekstowa”, tylko jako **pipeline jakości terminologii**, gdzie artykuły/definicje są warstwą wtórną.

## 2. Krótka ocena punkt po punkcie metodologii MVP (zostawić / poprawić / wyrzucić)

Poniżej ocena metodologii MVP w ujęciu praktycznym dla celu: precyzyjna referencja dla AI generującego synonimy produktowe.

### 2.1. Cel MVP: encyklopedia sprzętowa jako baza referencyjna
**Ocena: poprawić**

Cel jest dobry, ale warto go doprecyzować. W MVP celem nie powinna być "encyklopedia" sama w sobie, tylko **silnik rozróżniania pojęć** dla AI (termin + definicja operacyjna + relacje + testy).

### 2.2. Start od ograniczonej liczby kategorii (zamiast całego nurkowania)
**Ocena: zostawić**

To jest poprawne podejście. Zakres trzeba ograniczyć do kategorii o najwyższym ryzyku pomyłek i najwyższym wpływie na wyszukiwanie/SEO/sprzedaż.

### 2.3. Konsolidacja wielu źródeł wiedzy do jednej bazy
**Ocena: zostawić**

To konieczne. Trzeba jednak od razu wprowadzić **hierarchię zaufania źródeł** (np. instrukcje producentów > normy/standardy > opisy sklepowe > fora > potoczne użycie).

### 2.4. Generowanie synonimów przez AI na bazie encyklopedii
**Ocena: poprawić**

Tak, ale AI nie powinno generować synonimów "swobodnie". Powinno działać w trybie:
- proponowanie kandydatów,
- klasyfikacja typu relacji,
- odrzucanie konfliktów,
- walidacja regułami i testami regresji.

### 2.5. Użycie embeddingów / wyszukiwania semantycznego jako głównego mechanizmu terminologii
**Ocena: poprawić**

Embeddingi są przydatne do odnajdywania kandydatów podobnych terminów, ale nie są wystarczające do rozstrzygania znaczeń bliskich. W terminologii produktowej muszą być tylko **warstwą pomocniczą**, nie źródłem prawdy.

### 2.6. Opisowe artykuły/hasła jako podstawowy format MVP
**Ocena: poprawić**

Artykuły są dobre dla ludzi i późniejszej encyklopedii. Do MVP lepszy jest format rekordowy (structured entries). Tekst opisowy może być generowany później z danych strukturalnych.

### 2.7. Ręczna walidacja ekspercka kluczowych pojęć
**Ocena: zostawić**

Niezbędne. Szczególnie dla par problematycznych (np. elementy podobne wizualnie lub potocznie mylone).

### 2.8. Jedna lista synonimów bez rozróżnienia typu relacji
**Ocena: wyrzucić**

To najczęstsze źródło błędów. Muszą być osobne relacje, np.:
- exact_synonym
- near_synonym
- colloquial
- legacy_name
- brand_term
- misleading_term
- not_same_as (nie_mylić_z)
- part_of / has_part

### 2.9. Brak osobnej obsługi PL/EN i zapożyczeń
**Ocena: wyrzucić**

W Twoim przypadku to krytyczny błąd. Nurkowanie ma dużo terminów mieszanych. Bez osobnej warstwy PL/EN model będzie robił przecieki i błędne mapowania.

### 2.10. Brak testów regresji po zmianach w słowniku/promptach
**Ocena: wyrzucić**

To musi wejść do MVP od pierwszej wersji. Bez regresji każda poprawka może psuć wcześniejsze rozróżnienia.

### 2.11. Wersjonowanie rekordów i zmian terminologii
**Ocena: zostawić**

Konieczne. Minimum: wersja rekordu, data zmiany, autor, powód zmiany, źródło zmiany, wpływ na testy.

### 2.12. Zbieranie przykładów błędów z realnych zapytań klientów
**Ocena: zostawić**

To jeden z najlepszych kanałów danych do rozwoju słownika. Te błędy powinny trafiać do osobnej kolejki „confusion cases”.

## 3. Lepsza wersja pipeline dla Twojego przypadku

## Założenie
Celem nie jest tylko „wiedza o sprzęcie”, ale **precyzyjny, odporny na halucynacje system terminologiczny** dla AI w sklepie nurkowym.

## 3.1. Pipeline docelowy (MVP+) z naciskiem na precyzję terminologiczną

### Etap 0. Zakres i priorytetyzacja kategorii
Wybierz najpierw 10–15 kategorii o największym ryzyku pomyłek i największym wolumenie zapytań.

Priorytet daj kategoriom, gdzie często występują:
- nazwy potoczne i techniczne równolegle,
- skróty,
- PL/EN mix,
- podobne akcesoria o innym zastosowaniu,
- marka jako nazwa typu produktu.

### Etap 1. Normalizacja źródeł do formatu roboczego
Każdy termin/kandydat z każdego źródła zapisuj do wspólnego formatu z metadanymi:
- `term_raw`
- `language`
- `source_type`
- `source_name`
- `context_snippet`
- `category_hint`
- `brand_hint`
- `confidence_source`

Cel tego etapu: niczego jeszcze nie „uznawać za prawdę”, tylko zebrać materiał do rozstrzygania.

### Etap 2. Kanoniczne rekordy pojęć (source of truth)
Tworzysz rekord **pojęcia**, nie rekordu słowa. Jedno pojęcie może mieć wiele nazw.

Proponowany minimalny schemat rekordu pojęcia:

```json
{
  "concept_id": "dz_concept_000123",
  "canonical_name_pl": "szpulka nurkowa",
  "canonical_name_en": "finger spool",
  "definition_operational_pl": "Mała szpulka do linki, bez korby, używana m.in. z bojką dekompresyjną.",
  "category_primary": "akcesoria_nawigacja_i_sygnalizacja",
  "product_scope": "equipment",
  "term_status": "approved",
  "relations": {
    "exact_synonyms": ["finger spool"],
    "near_synonyms": [],
    "colloquial": [],
    "legacy_terms": [],
    "brand_terms": [],
    "not_same_as": ["kołowrotek nurkowy", "reel"],
    "has_part": [],
    "part_of": []
  },
  "pl_en_mapping": {
    "pl_terms": ["szpulka nurkowa", "szpulka"],
    "en_terms": ["finger spool", "spool"],
    "false_friends": []
  },
  "evidence": [
    {
      "source": "instrukcja_producenta_X",
      "quote_or_note": "termin użyty dla produktu bez korby",
      "weight": "high"
    }
  ],
  "review": {
    "reviewed_by": "human",
    "review_date": "25.02.2026",
    "version": 3
  }
}
```

### Etap 3. Relacje negatywne jako warstwa obowiązkowa (nie_mylić_z)
To jest krytyczny etap i powinien być wymagany dla każdego pojęcia z grup ryzyka.

Wprowadź pole obowiązkowe:
- `not_same_as`
- `why_not_same_as` (krótki opis różnicy)
- `disambiguation_clues` (cechy odróżniające)

Przykład (schematycznie):
- szpulka vs kołowrotek
- maska pełnotwarzowa vs maska + automat
- płetwy paskowe vs kaloszowe
- jacket BCD vs skrzydło (wing) jako konfiguracja/typ

### Etap 4. PL/EN i kontrola przecieków językowych
Zamiast jednej listy nazw zrób trzy warstwy:
1. **mapowanie semantyczne** (pojęcie ↔ nazwy PL/EN),
2. **mapowanie handlowe** (jak użytkownicy faktycznie piszą),
3. **mapowanie ryzykowne** (słowa wieloznaczne i przeciekające).

Dodatkowo wprowadź flagi:
- `ambiguous_single_token` (np. pojedyncze słowo za szerokie)
- `requires_context_for_match`
- `category_restrictions`

To ograniczy błędy typu dopasowanie krótkiego terminu do złej kategorii.

### Etap 5. Generowanie kandydatów synonimów przez AI, ale w trybie kontrolowanym
Model AI powinien zwracać nie tylko propozycje, ale też klasyfikację i uzasadnienie.

Wymagany format wyniku kandydata:
- termin
- typ relacji (exact/near/colloquial/misleading)
- język
- pewność
- uzasadnienie
- potencjalny konflikt z istniejącym pojęciem
- wymaga review człowieka (tak/nie)

Reguła: **AI nie może samo promować terminu do exact_synonym** bez walidacji reguł i testów.

### Etap 6. Walidacja regułami (lint terminologiczny)
Przed zapisaniem zmian uruchamiasz walidator, który wykrywa m.in.:
- ten sam termin przypisany jako exact_synonym do dwóch różnych pojęć,
- termin w `exact_synonyms` i jednocześnie w `not_same_as`,
- brak `not_same_as` dla pojęć z grupy ryzyka,
- termin EN bez mapowania PL (i odwrotnie),
- pojęcie bez definicji operacyjnej,
- zbyt ogólne terminy bez flagi `requires_context_for_match`.

### Etap 7. Testy regresji terminologicznej (obowiązkowe)
To najważniejszy element dla stabilności.

Wydziel 3 zestawy testów:

#### A. Testy `nie_mylic_z` (kontrasty)
Każdy test sprawdza, czy system poprawnie rozróżnia dwie podobne rzeczy.

Format testu:
- wejście użytkownika,
- oczekiwane pojęcie,
- pojęcia zakazane,
- minimalne cechy rozróżniające w odpowiedzi.

Przykład:
- Wejście: „szpulka do bojki”
- Oczekiwane: `finger_spool`
- Zakazane: `diving_reel`
- Cechy wymagane: „bez korby” lub „szpulka ręczna”

#### B. Testy PL/EN (mapowania i mieszania języków)
Sprawdzasz zapytania:
- po polsku,
- po angielsku,
- mieszane,
- z błędami/potoczne.

Celem jest stabilne mapowanie do tego samego `concept_id` albo bezpieczne dopytanie, gdy termin jest niejednoznaczny.

#### C. Testy wycieków synonimów
To testy, które pilnują, aby synonim z jednego pojęcia nie zaczął „przyklejać się” do sąsiedniego.

Przykłady kontroli:
- precision@1 dla grup mylących,
- brak nowych konfliktów exact/exact,
- spadek jakości po zmianie promptu lub słownika.

### Etap 8. Złoty zestaw przypadków (golden set) z realnych rozmów
Zbierasz realne zapytania klientów i oznaczasz ręcznie:
- intencja,
- pojęcie docelowe,
- czy wymagane doprecyzowanie,
- poprawna odpowiedź skrócona.

Ten zestaw służy do regresji po każdej zmianie:
- słownika,
- promptów,
- modelu,
- embeddingów,
- rankera.

### Etap 9. Publikacja dwóch warstw wyjściowych
Z jednego źródła danych publikujesz:
1. **Warstwę maszynową** do AI (JSON, relacje, reguły, testy),
2. **Warstwę ludzką** (hasła encyklopedii, definicje, przykłady, grafiki).

To eliminuje konflikt między jakością dla użytkownika a precyzją dla modelu.

## 3.2. Minimalna wersja MVP, która da efekt szybko

Jeśli chcesz szybko dojść do wartości biznesowej, zrób MVP w tej kolejności:

1. **Top 200–400 pojęć** zamiast pełnej encyklopedii.
2. Dla każdego pojęcia: definicja operacyjna + PL/EN + `not_same_as`.
3. **Top 100 par mylących** jako testy regresji.
4. Walidator konfliktów relacji.
5. Dopiero potem rozbudowa opisowych haseł encyklopedii.

To da szybciej poprawę jakości niż pisanie długich artykułów od początku.

## 3.3. Rekomendowane reguły jakości (praktyczne)

### Reguła 1
Każde pojęcie musi mieć definicję operacyjną w 1–2 zdaniach, która pozwala odróżnić je od najbliższego pojęcia mylonego.

### Reguła 2
`not_same_as` jest ważniejsze niż liczba synonimów.

### Reguła 3
Nie dodawaj `exact_synonym`, jeśli termin jest poprawny tylko w części kontekstów. Taki termin trafia do `near_synonym` albo `requires_context_for_match`.

### Reguła 4
Nazwy marketingowe producentów trzymaj osobno od nazw rodzajowych.

### Reguła 5
Każda zmiana w słowniku uruchamia testy regresji na zestawie kontrastów i PL/EN.

## 3.4. Co bym zrobił konkretnie u Ciebie jako następny krok

1. Zdefiniował schemat rekordu pojęcia i relacji (w tym obowiązkowe `not_same_as`, PL/EN, `requires_context_for_match`).
2. Zbudował pierwszy golden set: 100–150 realnych zapytań + 50–100 par `nie_mylic_z`.
3. Dopiero potem uruchamiał generowanie kandydatów synonimów przez AI w trybie kontrolowanym.

## 3.5. Najczęstsze błędy, których tu trzeba uniknąć

- Budowanie encyklopedii tekstowej bez struktury relacji.
- Mieszanie „jak klienci mówią” z „jak należy klasyfikować”.
- Traktowanie embeddingów jako arbitra znaczenia.
- Brak testów regresji po zmianach promptu/modelu.
- Brak wersjonowania decyzji terminologicznych.

## 3.6. Krótki werdykt końcowy

Metodologia MVP jest sensowna jako kierunek, ale wymaga przesunięcia środka ciężkości z „treści encyklopedii” na „inżynierię terminologii + testy regresji”. Jeśli zrobisz to w tej kolejności, szybciej ograniczysz halucynacje i zbudujesz solidny fundament pod docelową polską encyklopedię nurkowania.

