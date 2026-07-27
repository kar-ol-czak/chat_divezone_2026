# TASK-014: Golden Dataset + Ewaluacja
# Instancja: integration (Python)
# Zależności: żadne (można robić równolegle z innymi)
# Priorytet: WYSOKI (baseline PRZED zmianami)

## CEL
Zbudować zbiór testowy z realnymi zapytaniami i oczekiwanymi wynikami.
Mierzyć jakość wyszukiwania PRZED i PO każdej zmianie.

## KROK 1: Golden Dataset

Plik: integration/golden_dataset.json

```json
[
  {
    "id": "Q001",
    "query": "pianka",
    "intent": "exploratory",
    "expected_categories": ["Skafandry Na ZIMNE wody", "Skafandry Na CIEPŁE wody"],
    "expected_products": [],
    "notes": "Ogólne zapytanie, powinno zwrócić skafandry"
  },
  {
    "id": "Q002",
    "query": "pianka na zimną wodę",
    "intent": "exploratory",
    "expected_categories": ["Skafandry Na ZIMNE wody"],
    "expected_products": [],
    "notes": "Zawężone, tylko zimne wody (7mm, semidry, suchy)"
  },
  {
    "id": "Q003",
    "query": "BARE 7mm",
    "intent": "navigational",
    "expected_categories": ["Skafandry Na ZIMNE wody"],
    "expected_brands": ["BARE"],
    "notes": "Marka + parametr, trigram powinien łapać"
  },
  {
    "id": "Q004",
    "query": "Shearwater Teric",
    "intent": "navigational",
    "expected_products_partial": ["Teric"],
    "notes": "Dokładna nazwa produktu"
  },
  {
    "id": "Q005",
    "query": "komputer do trimixu",
    "intent": "exploratory",
    "expected_categories": ["Komputery Nurkowe"],
    "notes": "Wymaga wiedzy: trimix = wielogazowy"
  }
]
```

ŹRÓDŁA zapytań (priorytet):
1. Luigi's Box zero-results queries (najcenniejsze, system ich nie łapie)
2. Luigi's Box top queries (system już łapie, weryfikacja)
3. GSC top queries (jak ludzie szukają w Google)
4. Ręczne scenariusze (edge cases, negacje, dwuznaczności)

CEL: 30-50 zapytań. Min 10 navigational, min 10 exploratory, min 5 edge cases.

## KROK 2: Skrypt ewaluacji

integration/eval_search.py:

```python
"""
Ewaluacja jakości wyszukiwania na golden dataset.
Uruchamiaj PRZED i PO każdej zmianie w architekturze.

Użycie:
  python eval_search.py --tag "baseline_v1"
  python eval_search.py --tag "after_task_012_rrf"
"""

import json
import asyncio
from datetime import datetime

# Metryki:
# - Recall@K: ile z expected_products/categories jest w top K wynikach
# - MRR: pozycja pierwszego trafnego wyniku (1/rank)
# - Zero Results Rate: % zapytań bez wyników
# - Category Hit Rate: % zapytań gdzie expected_category w top 5

def recall_at_k(expected: list, results: list, k: int = 5) -> float:
    """Ile oczekiwanych produktów znaleziono w top K"""
    top_k = results[:k]
    hits = sum(1 for e in expected if any(e.lower() in r.lower() for r in top_k))
    return hits / len(expected) if expected else 1.0

def mrr(expected: list, results: list) -> float:
    """Mean Reciprocal Rank: 1/pozycja pierwszego trafnego wyniku"""
    for i, r in enumerate(results):
        if any(e.lower() in r.lower() for e in expected):
            return 1.0 / (i + 1)
    return 0.0

def evaluate(golden_dataset: list, search_fn, tag: str):
    """Uruchom ewaluację i zapisz raport"""
    results = []
    for q in golden_dataset:
        search_results = search_fn(q["query"])
        result_names = [r["product_name"] for r in search_results]
        result_categories = [r["category_name"] for r in search_results]

        metrics = {
            "query_id": q["id"],
            "query": q["query"],
            "results_count": len(search_results),
            "zero_result": len(search_results) == 0,
        }

        if q.get("expected_categories"):
            metrics["category_hit"] = any(
                ec.lower() in rc.lower()
                for ec in q["expected_categories"]
                for rc in result_categories[:5]
            )

        if q.get("expected_products_partial"):
            metrics["mrr"] = mrr(q["expected_products_partial"], result_names)

        results.append(metrics)

    # Agregaty
    report = {
        "tag": tag,
        "timestamp": datetime.now().isoformat(),
        "total_queries": len(results),
        "zero_results_rate": sum(1 for r in results if r["zero_result"]) / len(results),
        "avg_category_hit": ...,
        "avg_mrr": ...,
        "details": results
    }

    # Zapis
    filename = f"data/eval/eval_{tag}_{datetime.now():%Y%m%d_%H%M}.json"
    with open(filename, "w") as f:
        json.dump(report, f, indent=2, ensure_ascii=False)

    print(f"=== Eval: {tag} ===")
    print(f"Zero Results Rate: {report['zero_results_rate']:.1%}")
    print(f"Saved: {filename}")
```

## KROK 3: Baseline

PRZED jakimikolwiek zmianami (TASK-011, 012, 013):
```bash
python eval_search.py --tag "baseline_before_changes"
```

PO każdym tasku:
```bash
python eval_search.py --tag "after_task_011_enrichment"
python eval_search.py --tag "after_task_012_rrf"
python eval_search.py --tag "after_task_012b_multivector"
python eval_search.py --tag "after_task_013_planning"
```

## PLIKI WYJŚCIOWE
- integration/golden_dataset.json
- integration/eval_search.py
- data/eval/ (katalog na raporty)

## KRYTERIA AKCEPTACJI
- [ ] Min 30 zapytań w golden dataset
- [ ] Baseline zmierzony PRZED zmianami
- [ ] Skrypt uruchamialny jednym poleceniem
- [ ] Raport czytelny (zero_results_rate, category_hit, mrr)
