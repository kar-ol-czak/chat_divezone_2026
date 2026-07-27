# TASK-013: Agentic Query Planning
# Instancja: backend (PHP)
# Zależności: TASK-012 (hybrid search musi działać)
# Priorytet: ŚREDNI
# Status: ZROBIONE

## CEL
Zamienić prosty search_reasoning (string) na strukturalny JSON.
LLM staje się "Query Plannerem" przed wywołaniem search.

## NOWY TOOL SCHEMA (search_products)

```json
{
  "name": "search_products",
  "description": "Wyszukaj produkty w sklepie divezone.pl. ZAWSZE wypełnij search_plan przed wyszukaniem.",
  "input_schema": {
    "type": "object",
    "required": ["query", "search_plan"],
    "properties": {
      "query": {
        "type": "string",
        "description": "Tekst zapytania do wyszukiwarki."
      },
      "search_plan": {
        "type": "object",
        "required": ["intent", "reasoning"],
        "properties": {
          "intent": {
            "type": "string",
            "enum": ["navigational", "exploratory"],
            "description": "navigational: klient zna produkt/markę/model. exploratory: szuka porady, nie wie czego dokładnie szuka."
          },
          "reasoning": {
            "type": "string",
            "description": "1-2 zdania: co klient potrzebuje, dlaczego taki query, jaka kategoria. Np: 'Klient szuka pianki na Polskę. Zimna woda = semidry 7mm min. Kat: Skafandry Na ZIMNE wody.'"
          },
          "exact_keywords": {
            "type": "array",
            "items": {"type": "string"},
            "description": "Nazwy własne, modele, marki, parametry do literalnego dopasowania. Np: ['Shearwater', 'Teric', 'DIN']"
          }
        }
      },
      "category": {
        "type": "string",
        "description": "Nazwa kategorii z listy w system prompcie. WYMAGANE przy intent=exploratory. Opcjonalne przy navigational."
      },
      "filters": {
        "type": "object",
        "properties": {
          "price_min": {"type": "number"},
          "price_max": {"type": "number"},
          "brand": {"type": "string"},
          "in_stock_only": {"type": "boolean", "default": true},
          "exclude_categories": {
            "type": "array",
            "items": {"type": "string"},
            "description": "Kategorie do WYKLUCZENIA. Używaj gdy klient mówi 'nie szukam X'."
          }
        }
      },
      "limit": {
        "type": "integer",
        "default": 5,
        "description": "Liczba wyników (1-10)."
      }
    }
  }
}
```

## ZMIANY W SYSTEM PROMPCIE

Dodaj sekcję z przykładami planowania:

```
## JAK SZUKAĆ PRODUKTÓW

ZAWSZE wypełnij search_plan zanim wywołasz search_products.

PRZYKŁADY:

Klient: "Szukam pianki na nurkowanie w Polsce"
→ search_plan:
  intent: "exploratory"
  reasoning: "Klient szuka pianki na Polskę. Polska = zimna woda 4-10°C = semidry/suchy 7mm minimum. Kategoria: Skafandry Na ZIMNE wody."
  exact_keywords: []
→ category: "Skafandry Na ZIMNE wody"
→ query: "skafander mokry semidry 7mm Polska zimna woda"

Klient: "Ile kosztuje Shearwater Teric?"
→ search_plan:
  intent: "navigational"
  reasoning: "Klient szuka konkretnego komputera Shearwater Teric. Szukam po nazwie."
  exact_keywords: ["Shearwater", "Teric"]
→ query: "Shearwater Teric"

Klient: "Potrzebuję czegoś do oświetlania pod wodą, ale nie szukam latarki głównej"
→ search_plan:
  intent: "exploratory"
  reasoning: "Klient szuka oświetlenia pomocniczego/backup, nie głównego. Wykluczam latarki główne."
  exact_keywords: []
→ category: "Latarki"
→ filters: { exclude_categories: ["Duże z Głowicą"] }
→ query: "latarka backup nurkowa mała"
```

## LOGOWANIE search_plan

Zapisuj search_plan do istniejącej kolumny search_diagnostics (JSONB)
w divechat_conversations:

```json
{
  "search_plan": { ... },
  "rrf_scores": { ... },
  "results_count": 5,
  "dominant_tor": "semantic"
}
```

## PLIKI DO ZMIANY
- standalone/src/Tools/ProductSearch.php (nowy schema, parsowanie search_plan)
- standalone/src/Chat/SystemPrompt.php (przykłady planowania)
- standalone/src/Chat/ConversationLogger.php (logowanie search_plan)

## KRYTERIA AKCEPTACJI
- [ ] Tool schema zaktualizowany
- [ ] System prompt zawiera 5+ przykładów planowania
- [ ] search_plan logowany w search_diagnostics
- [ ] intent poprawnie rozpoznawany (test 10 scenariuszy)
- [ ] exclude_categories generuje WHERE ... NOT IN (...)
- [ ] exact_keywords wpływa na trigram boost
