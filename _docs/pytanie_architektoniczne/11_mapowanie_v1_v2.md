## Mapowanie pól: schema v1 (raw) → schema v2 (docelowy)

### Pola v1 i ich odpowiedniki w v2:

| Pole v1 | Pole v2 | Typ transformacji |
|---------|---------|-------------------|
| concept_key | id | Rename + UPPER_CASE |
| canonical_term_pl | nazwa_pl | Rename |
| canonical_term_en | nazwa_en | Rename |
| definition_operational_pl | definicja | Rename, ewentualnie skrócenie |
| synonyms[type=exact_synonym, lang=pl] | synonimy_pl.exact | Filtruj + grupuj |
| synonyms[type=colloquial, lang=pl] | synonimy_pl.potoczne | Filtruj + grupuj |
| synonyms[type=anglicyzm] | synonimy_pl.near? | Klasyfikacja niejednoznaczna |
| synonyms[lang=en] | synonimy_en.exact/near | Filtruj + klasyfikuj |
| relations[type=nie_mylic_z] | nie_mylic_z | Filtruj + restructure |
| relations[type=czesc_zestawu] | powiazane_produkty (częściowo) | Filtruj |
| — brak w v1 — | podtypy | NOWE, wymaga generacji |
| — brak w v1 — | parametry_zakupowe | NOWE, wymaga generacji |
| — brak w v1 — | marki_w_sklepie | LOOKUP z mapy marek |
| — brak w v1 — | faq | NOWE, wymaga generacji |
| — brak w v1 — | uwagi_dla_ai | NOWE, wymaga generacji |
| — brak w v1 — | synonimy_pl.archaiczne | NOWE, wymaga klasyfikacji |
| — brak w v1 — | synonimy_pl.bledne_ale_popularne | NOWE, wymaga generacji |

### Estymacja: transformacja vs generacja

- Bezpośrednie mapowanie (rename/filtruj): ~40% pól
- Restrukturyzacja (grupowanie synonimów po typie/języku): ~15%
- Lookup z zewnętrznych danych (marki z whitelist, frazy DataForSEO): ~15%
- Generacja wymagająca LLM (podtypy, parametry, FAQ, uwagi, archaiczne, bledne_ale_popularne): ~30%

### Pytanie kluczowe:
Czy te 30% generacji uzasadnia przebudowę 100% danych od zera przez LLM?
Czy nie lepiej: deterministycznie zmapować 70%, a LLM użyć TYLKO do brakujących 30%?
