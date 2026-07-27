## Schema docelowy JSON encyklopedii (z spec.md sekcja 5.3)

Kazdy entry musi miec:
- id: string, UPPER_SNAKE_CASE
- nazwa_pl: string, niepusty
- nazwa_en: string, niepusty
- definicja: string, min 50 znakow
- podtypy: array of strings
- synonimy_pl: object z kluczami exact, near, potoczne, archaiczne
- synonimy_pl.bledne_ale_popularne: array (opcjonalny, ale zalecany)
- synonimy_en: object z kluczami exact, near
- nie_mylic_z: array of objects z kluczami concept, dlaczego
- parametry_zakupowe: array of strings, min 1
- marki_w_sklepie: array of strings
- powiazane_produkty: array of strings
- faq: array of objects z kluczami pytanie, odpowiedz
- uwagi_dla_ai: string, niepusty
