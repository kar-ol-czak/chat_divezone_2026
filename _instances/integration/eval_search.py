#!/usr/bin/env python3
"""
Ewaluacja jakości wyszukiwania produktów na golden dataset.
Uruchamiaj PRZED i PO każdej zmianie w architekturze wyszukiwania.

Użycie:
  python eval_search.py --tag "baseline_before_changes"
  python eval_search.py --tag "after_task_012_rrf"
  python eval_search.py --tag "baseline_before_changes" --limit 5  # zmień top K
"""

import argparse
import json
import os
import sys
import time
from datetime import datetime
from pathlib import Path

import psycopg2
from dotenv import load_dotenv
from openai import OpenAI, RateLimitError

# Ładowanie .env z roota projektu
PROJECT_ROOT = Path(__file__).resolve().parent.parent.parent
load_dotenv(PROJECT_ROOT / ".env")

# Konfiguracja
EMBEDDING_MODEL = "text-embedding-3-large"
EMBEDDING_DIMENSIONS = 1536
RESULTS_DIR = Path(__file__).resolve().parent / "eval_results"
DATASET_PATH = Path(__file__).resolve().parent / "golden_dataset.json"
DEFAULT_LIMIT = 10  # top K wyników do ewaluacji


def get_db_connection():
    """Połączenie z PostgreSQL (Railway pgvector)."""
    url = os.getenv("DATABASE_URL")
    if not url:
        print("BŁĄD: Brak DATABASE_URL w .env")
        sys.exit(1)
    return psycopg2.connect(url)


def get_openai_client() -> OpenAI:
    """Klient OpenAI do generowania embeddingów."""
    key = os.getenv("OPENAI_API_KEY")
    if not key:
        print("BŁĄD: Brak OPENAI_API_KEY w .env")
        sys.exit(1)
    return OpenAI(api_key=key)


def get_embedding(client: OpenAI, text: str, retries: int = 3) -> list[float]:
    """Generuje embedding z retry na rate limit."""
    for attempt in range(1, retries + 1):
        try:
            resp = client.embeddings.create(
                input=text,
                model=EMBEDDING_MODEL,
                dimensions=EMBEDDING_DIMENSIONS,
            )
            return resp.data[0].embedding
        except RateLimitError:
            if attempt == retries:
                raise
            delay = 2 ** attempt
            print(f"  Rate limit, czekam {delay}s...")
            time.sleep(delay)
    return []


def search_products(conn, embedding: list[float], limit: int = DEFAULT_LIMIT) -> list[dict]:
    """Wyszukiwanie wektorowe w divechat_product_embeddings (identyczne jak ProductSearch.php)."""
    vector_str = str(embedding)
    cur = conn.cursor()
    cur.execute(
        """
        SELECT ps_product_id, product_name, brand_name, category_name,
               price, in_stock,
               1 - (embedding <=> %s::vector) AS similarity
        FROM divechat_product_embeddings
        WHERE is_active = true
        ORDER BY embedding <=> %s::vector
        LIMIT %s
        """,
        (vector_str, vector_str, limit),
    )
    columns = ["product_id", "product_name", "brand_name", "category_name",
               "price", "in_stock", "similarity"]
    rows = cur.fetchall()
    cur.close()
    return [dict(zip(columns, row)) for row in rows]


# --- Metryki ---

def recall_at_k(expected: list[str], results: list[str], k: int) -> float:
    """Ile z oczekiwanych elementów znaleziono w top K wynikach (substring match)."""
    if not expected:
        return -1.0  # brak oczekiwań = nie dotyczy
    top_k = [r.lower() for r in results[:k]]
    hits = sum(
        1 for e in expected
        if any(e.lower() in r for r in top_k)
    )
    return hits / len(expected)


def mrr(expected: list[str], results: list[str]) -> float:
    """Mean Reciprocal Rank: 1/pozycja pierwszego trafnego wyniku."""
    if not expected:
        return -1.0
    for i, r in enumerate(results):
        if any(e.lower() in r.lower() for e in expected):
            return 1.0 / (i + 1)
    return 0.0


def category_hit_at_k(expected_cats: list[str], result_cats: list[str], k: int) -> bool:
    """Czy którakolwiek z oczekiwanych kategorii pojawia się w top K wynikach."""
    top_k = [c.lower() for c in result_cats[:k]]
    return any(
        ec.lower() in rc
        for ec in expected_cats
        for rc in top_k
    )


def brand_hit_at_k(expected_brands: list[str], result_brands: list[str], k: int) -> bool:
    """Czy oczekiwana marka pojawia się w top K wynikach."""
    top_k = [b.lower() if b else "" for b in result_brands[:k]]
    return any(
        eb.lower() in rb
        for eb in expected_brands
        for rb in top_k
    )


# --- Główna ewaluacja ---

def evaluate_query(query_spec: dict, results: list[dict], k: int) -> dict:
    """Oblicz metryki dla jednego zapytania."""
    result_names = [r["product_name"] for r in results]
    result_cats = [r["category_name"] or "" for r in results]
    result_brands = [r["brand_name"] or "" for r in results]
    similarities = [float(r["similarity"]) for r in results]

    metrics = {
        "query_id": query_spec["id"],
        "query": query_spec["query"],
        "intent": query_spec.get("intent", "unknown"),
        "results_count": len(results),
        "zero_result": len(results) == 0,
        "top_similarity": round(similarities[0], 4) if similarities else 0.0,
        "avg_similarity_top5": round(sum(similarities[:5]) / min(len(similarities), 5), 4) if similarities else 0.0,
    }

    # Category hit
    expected_cats = query_spec.get("expected_categories", [])
    if expected_cats:
        metrics["category_hit_at_5"] = category_hit_at_k(expected_cats, result_cats, 5)
        metrics["category_hit_at_10"] = category_hit_at_k(expected_cats, result_cats, 10)
        metrics["category_recall_at_5"] = recall_at_k(expected_cats, result_cats, 5)

    # Brand hit
    expected_brands = query_spec.get("expected_brands", [])
    if expected_brands:
        metrics["brand_hit_at_5"] = brand_hit_at_k(expected_brands, result_brands, 5)
        metrics["brand_hit_at_10"] = brand_hit_at_k(expected_brands, result_brands, 10)

    # Product match (partial name match)
    expected_prods = query_spec.get("expected_products_partial", [])
    if expected_prods:
        metrics["product_mrr"] = round(mrr(expected_prods, result_names), 4)
        metrics["product_recall_at_5"] = recall_at_k(expected_prods, result_names, 5)
        metrics["product_recall_at_10"] = recall_at_k(expected_prods, result_names, 10)

    # Top 5 wyników (do ręcznej inspekcji)
    metrics["top_5_results"] = [
        {
            "name": r["product_name"],
            "brand": r["brand_name"],
            "category": r["category_name"],
            "similarity": round(float(r["similarity"]), 4),
        }
        for r in results[:5]
    ]

    return metrics


def run_evaluation(dataset: list[dict], tag: str, limit: int):
    """Uruchom pełną ewaluację i zapisz raport."""
    print(f"=== Ewaluacja: {tag} ===")
    print(f"Zapytań: {len(dataset)}, limit: {limit}")
    print()

    conn = get_db_connection()
    client = get_openai_client()

    all_metrics = []
    for i, q in enumerate(dataset):
        print(f"  [{i+1}/{len(dataset)}] {q['id']}: \"{q['query']}\"", end="", flush=True)

        embedding = get_embedding(client, q["query"])
        results = search_products(conn, embedding, limit=limit)

        metrics = evaluate_query(q, results, k=limit)
        all_metrics.append(metrics)

        # Krótki status
        status_parts = [f"{metrics['results_count']} wyników"]
        if metrics.get("category_hit_at_5") is not None:
            status_parts.append(f"cat@5={'✓' if metrics['category_hit_at_5'] else '✗'}")
        if metrics.get("brand_hit_at_5") is not None:
            status_parts.append(f"brand@5={'✓' if metrics['brand_hit_at_5'] else '✗'}")
        if metrics.get("product_mrr") is not None:
            status_parts.append(f"MRR={metrics['product_mrr']:.2f}")
        print(f"  → {', '.join(status_parts)}")

    conn.close()

    # Agregaty
    total = len(all_metrics)
    zero_results_rate = sum(1 for m in all_metrics if m["zero_result"]) / total

    # Category hit rate (tylko dla zapytań z expected_categories)
    cat_queries = [m for m in all_metrics if "category_hit_at_5" in m]
    cat_hit_at_5 = sum(1 for m in cat_queries if m["category_hit_at_5"]) / len(cat_queries) if cat_queries else -1
    cat_hit_at_10 = sum(1 for m in cat_queries if m["category_hit_at_10"]) / len(cat_queries) if cat_queries else -1

    # Brand hit rate
    brand_queries = [m for m in all_metrics if "brand_hit_at_5" in m]
    brand_hit_at_5 = sum(1 for m in brand_queries if m["brand_hit_at_5"]) / len(brand_queries) if brand_queries else -1
    brand_hit_at_10 = sum(1 for m in brand_queries if m["brand_hit_at_10"]) / len(brand_queries) if brand_queries else -1

    # MRR (tylko navigational z expected_products_partial)
    mrr_queries = [m for m in all_metrics if "product_mrr" in m]
    avg_mrr = sum(m["product_mrr"] for m in mrr_queries) / len(mrr_queries) if mrr_queries else -1

    # Product recall
    recall_queries = [m for m in all_metrics if "product_recall_at_5" in m]
    avg_recall_at_5 = sum(m["product_recall_at_5"] for m in recall_queries) / len(recall_queries) if recall_queries else -1

    # Średnie similarity
    avg_top_sim = sum(m["top_similarity"] for m in all_metrics) / total
    avg_sim_top5 = sum(m["avg_similarity_top5"] for m in all_metrics) / total

    report = {
        "tag": tag,
        "timestamp": datetime.now().isoformat(),
        "config": {
            "embedding_model": EMBEDDING_MODEL,
            "embedding_dimensions": EMBEDDING_DIMENSIONS,
            "search_limit": limit,
            "total_queries": total,
        },
        "aggregate_metrics": {
            "zero_results_rate": round(zero_results_rate, 4),
            "category_hit_at_5": round(cat_hit_at_5, 4) if cat_hit_at_5 >= 0 else "N/A",
            "category_hit_at_10": round(cat_hit_at_10, 4) if cat_hit_at_10 >= 0 else "N/A",
            "brand_hit_at_5": round(brand_hit_at_5, 4) if brand_hit_at_5 >= 0 else "N/A",
            "brand_hit_at_10": round(brand_hit_at_10, 4) if brand_hit_at_10 >= 0 else "N/A",
            "avg_product_mrr": round(avg_mrr, 4) if avg_mrr >= 0 else "N/A",
            "avg_product_recall_at_5": round(avg_recall_at_5, 4) if avg_recall_at_5 >= 0 else "N/A",
            "avg_top_similarity": round(avg_top_sim, 4),
            "avg_similarity_top5": round(avg_sim_top5, 4),
        },
        "queries_evaluated": {
            "with_expected_categories": len(cat_queries),
            "with_expected_brands": len(brand_queries),
            "with_expected_products": len(mrr_queries),
        },
        "details": all_metrics,
    }

    # Zapis
    RESULTS_DIR.mkdir(parents=True, exist_ok=True)
    filename = RESULTS_DIR / f"eval_{tag}_{datetime.now():%Y%m%d_%H%M}.json"
    with open(filename, "w", encoding="utf-8") as f:
        json.dump(report, f, indent=2, ensure_ascii=False, default=str)

    # Podsumowanie
    print()
    print("=" * 60)
    print(f"  TAG: {tag}")
    print(f"  Zapytań: {total}")
    print(f"  Zero Results Rate:     {zero_results_rate:.1%}")
    print(f"  Category Hit @5:       {cat_hit_at_5:.1%}" if cat_hit_at_5 >= 0 else "  Category Hit @5:       N/A")
    print(f"  Category Hit @10:      {cat_hit_at_10:.1%}" if cat_hit_at_10 >= 0 else "  Category Hit @10:      N/A")
    print(f"  Brand Hit @5:          {brand_hit_at_5:.1%}" if brand_hit_at_5 >= 0 else "  Brand Hit @5:          N/A")
    print(f"  Brand Hit @10:         {brand_hit_at_10:.1%}" if brand_hit_at_10 >= 0 else "  Brand Hit @10:         N/A")
    print(f"  Avg Product MRR:       {avg_mrr:.4f}" if avg_mrr >= 0 else "  Avg Product MRR:       N/A")
    print(f"  Avg Product Recall@5:  {avg_recall_at_5:.1%}" if avg_recall_at_5 >= 0 else "  Avg Product Recall@5:  N/A")
    print(f"  Avg Top Similarity:    {avg_top_sim:.4f}")
    print(f"  Avg Similarity Top5:   {avg_sim_top5:.4f}")
    print("=" * 60)
    print(f"  Raport: {filename}")
    print()

    return report, filename


def main():
    parser = argparse.ArgumentParser(description="Ewaluacja wyszukiwania produktów")
    parser.add_argument("--tag", required=True, help="Etykieta ewaluacji (np. baseline_before_changes)")
    parser.add_argument("--limit", type=int, default=DEFAULT_LIMIT, help=f"Liczba wyników (top K), domyślnie {DEFAULT_LIMIT}")
    parser.add_argument("--dataset", type=str, default=str(DATASET_PATH), help="Ścieżka do golden dataset JSON")
    args = parser.parse_args()

    dataset_path = Path(args.dataset)
    if not dataset_path.exists():
        print(f"BŁĄD: Nie znaleziono golden dataset: {dataset_path}")
        sys.exit(1)

    with open(dataset_path, "r", encoding="utf-8") as f:
        dataset = json.load(f)

    print(f"Załadowano {len(dataset)} zapytań z {dataset_path.name}")
    run_evaluation(dataset, tag=args.tag, limit=args.limit)


if __name__ == "__main__":
    main()
