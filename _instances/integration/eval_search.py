#!/usr/bin/env python3
"""
Ewaluacja jakości wyszukiwania produktów na golden dataset.
Uruchamiaj PRZED i PO każdej zmianie w architekturze wyszukiwania.

Użycie:
  python eval_search.py --tag "baseline_before_changes"
  python eval_search.py --tag "after_task_012_rrf" --mode hybrid
  python eval_search.py --tag "baseline_before_changes" --limit 5  # zmień top K
  python eval_search.py --tag "vector_only" --mode vector
"""

from __future__ import annotations

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

# RRF parameters (identyczne jak w PHP ProductSearch)
RRF_K = 60
TRACK_LIMIT = 30


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


# --- Wyszukiwanie ---

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


def load_synonym_map(conn) -> dict[str, list[str]]:
    """Ładuje mapę synonimów z divechat_synonyms (identycznie jak SynonymExpander.php)."""
    cur = conn.cursor()
    cur.execute("SELECT canonical_term, synonym FROM divechat_synonyms ORDER BY canonical_term")
    rows = cur.fetchall()
    cur.close()

    # Grupuj: canonical → [canonical, syn1, syn2, ...]
    groups: dict[str, list[str]] = {}
    for canonical, synonym in rows:
        c = canonical.lower()
        s = synonym.lower()
        if c not in groups:
            groups[c] = [c]
        if s not in groups[c]:
            groups[c].append(s)

    # Buduj mapę: każde słowo/fraza → cała grupa
    syn_map: dict[str, list[str]] = {}
    for group_words in groups.values():
        for word in group_words:
            syn_map[word] = group_words

    return syn_map


def expand_query_with_synonyms(query: str, syn_map: dict[str, list[str]]) -> str:
    """Ekspansja zapytania o synonimy — identyczna logika jak SynonymExpander.php."""
    tokens = query.strip().split()
    if not tokens:
        return ""

    parts = []
    consumed = set()

    for i in range(len(tokens)):
        if i in consumed:
            continue

        matched_group = None

        # Próbuj bigram
        if i + 1 < len(tokens):
            bigram = f"{tokens[i]} {tokens[i+1]}".lower()
            if bigram in syn_map:
                matched_group = syn_map[bigram]
                consumed.add(i)
                consumed.add(i + 1)

        # Próbuj single token
        if matched_group is None:
            token_lower = tokens[i].lower()
            if token_lower in syn_map:
                matched_group = syn_map[token_lower]
                consumed.add(i)

        if matched_group is not None:
            # Rozbij multi-word synonimy na pojedyncze słowa
            all_words = set()
            for phrase in matched_group:
                for w in phrase.split():
                    w = w.strip()
                    if w:
                        all_words.add(w.lower())
            parts.append("(" + " | ".join(sorted(all_words)) + ")")
        else:
            consumed.add(i)
            parts.append(tokens[i])

    return " & ".join(parts)


def search_semantic(conn, embedding: list[float], limit: int = TRACK_LIMIT) -> list[dict]:
    """Tor 1: Embedding cosine similarity."""
    vector_str = str(embedding)
    cur = conn.cursor()
    cur.execute(
        """
        SELECT ps_product_id,
               1 - (embedding <=> %s::vector) AS similarity
        FROM divechat_product_embeddings
        WHERE is_active = true
        ORDER BY embedding <=> %s::vector
        LIMIT %s
        """,
        (vector_str, vector_str, limit),
    )
    rows = cur.fetchall()
    cur.close()
    results = []
    for rank, (pid, sim) in enumerate(rows, 1):
        results.append({"ps_product_id": pid, "rank": rank, "similarity": float(sim)})
    return results


def search_fulltext(conn, expanded_query: str, limit: int = TRACK_LIMIT) -> list[dict]:
    """Tor 2: Full-text search z unaccent (diving_simple config)."""
    if not expanded_query:
        return []

    cur = conn.cursor()
    try:
        cur.execute(
            """
            SELECT ps_product_id,
                   ts_rank(fts_vector, to_tsquery('diving_simple', %s)) AS ts_rank
            FROM divechat_product_embeddings
            WHERE is_active = true
              AND fts_vector @@ to_tsquery('diving_simple', %s)
            ORDER BY ts_rank DESC
            LIMIT %s
            """,
            (expanded_query, expanded_query, limit),
        )
    except psycopg2.Error:
        conn.rollback()
        # Fallback na plainto_tsquery
        cur.execute(
            """
            SELECT ps_product_id,
                   ts_rank(fts_vector, plainto_tsquery('diving_simple', %s)) AS ts_rank
            FROM divechat_product_embeddings
            WHERE is_active = true
              AND fts_vector @@ plainto_tsquery('diving_simple', %s)
            ORDER BY ts_rank DESC
            LIMIT %s
            """,
            (expanded_query, expanded_query, limit),
        )
    rows = cur.fetchall()
    cur.close()
    results = []
    for rank, (pid, ts_r) in enumerate(rows, 1):
        results.append({"ps_product_id": pid, "rank": rank, "ts_rank": float(ts_r)})
    return results


def search_trigram(conn, query: str, limit: int = TRACK_LIMIT) -> list[dict]:
    """Tor 3: Trigram fuzzy matching na product_name i brand_name."""
    cur = conn.cursor()
    cur.execute(
        """
        SELECT ps_product_id,
               GREATEST(
                   similarity(product_name, %s),
                   similarity(brand_name, %s)
               ) AS trgm_score
        FROM divechat_product_embeddings
        WHERE is_active = true
          AND (similarity(product_name, %s) > 0.15 OR similarity(brand_name, %s) > 0.25)
        ORDER BY trgm_score DESC
        LIMIT %s
        """,
        (query, query, query, query, limit),
    )
    rows = cur.fetchall()
    cur.close()
    results = []
    for rank, (pid, score) in enumerate(rows, 1):
        results.append({"ps_product_id": pid, "rank": rank, "trgm_score": float(score)})
    return results


def merge_rrf(semantic: list[dict], fulltext: list[dict], trigram: list[dict],
              limit: int) -> tuple[list[dict], dict]:
    """Reciprocal Rank Fusion — identyczna logika jak PHP mergeRRF()."""
    k = RRF_K
    scores: dict[int, float] = {}
    track_info: dict[int, dict] = {}

    for item in semantic:
        pid = item["ps_product_id"]
        scores[pid] = scores.get(pid, 0.0) + 1.0 / (k + item["rank"])
        track_info.setdefault(pid, {})["semantic_rank"] = item["rank"]
        track_info[pid]["semantic_sim"] = item["similarity"]

    for item in fulltext:
        pid = item["ps_product_id"]
        scores[pid] = scores.get(pid, 0.0) + 1.0 / (k + item["rank"])
        track_info.setdefault(pid, {})["fulltext_rank"] = item["rank"]
        track_info[pid]["fulltext_ts_rank"] = item["ts_rank"]

    for item in trigram:
        pid = item["ps_product_id"]
        scores[pid] = scores.get(pid, 0.0) + 1.0 / (k + item["rank"])
        track_info.setdefault(pid, {})["trigram_rank"] = item["rank"]
        track_info[pid]["trigram_score"] = item["trgm_score"]

    if not scores:
        return [], {}

    # Sortuj po RRF score malejąco
    sorted_ids = sorted(scores, key=lambda pid: scores[pid], reverse=True)[:limit]

    # Pobierz pełne dane produktów
    return sorted_ids, scores, track_info


def fetch_products_by_ids(conn, product_ids: list[int]) -> dict[int, dict]:
    """Pobiera pełne dane produktów po ID."""
    if not product_ids:
        return {}
    placeholders = ",".join(["%s"] * len(product_ids))
    cur = conn.cursor()
    cur.execute(
        f"""
        SELECT ps_product_id, product_name, brand_name, category_name,
               price, in_stock
        FROM divechat_product_embeddings
        WHERE ps_product_id IN ({placeholders})
        """,
        product_ids,
    )
    columns = ["product_id", "product_name", "brand_name", "category_name", "price", "in_stock"]
    rows = cur.fetchall()
    cur.close()
    result = {}
    for row in rows:
        d = dict(zip(columns, row))
        result[d["product_id"]] = d
    return result


def search_hybrid_rrf(conn, embedding: list[float], query: str,
                      syn_map: dict[str, list[str]], limit: int = DEFAULT_LIMIT) -> tuple[list[dict], dict]:
    """Hybrid search: 3 tory + RRF fusion. Zwraca (results, debug_info)."""
    # Ekspansja synonimów
    expanded_query = expand_query_with_synonyms(query, syn_map)

    # 3 tory
    sem = search_semantic(conn, embedding)
    fts = search_fulltext(conn, expanded_query) if expanded_query else []
    trg = search_trigram(conn, query)

    # RRF fusion
    sorted_ids, scores, track_info = merge_rrf(sem, fts, trg, limit)

    # Pobierz dane produktów
    products_map = fetch_products_by_ids(conn, sorted_ids)

    results = []
    debug_items = []
    for pid in sorted_ids:
        if pid not in products_map:
            continue
        prod = products_map[pid]
        rrf_score = scores[pid]
        info = track_info.get(pid, {})

        # Dominujący tor
        dominant = "semantic"
        best_contrib = 0.0
        for track_name, rank_key in [("semantic", "semantic_rank"),
                                      ("fulltext", "fulltext_rank"),
                                      ("trigram", "trigram_rank")]:
            if rank_key in info:
                contrib = 1.0 / (RRF_K + info[rank_key])
                if contrib > best_contrib:
                    best_contrib = contrib
                    dominant = track_name

        prod["similarity"] = float(rrf_score)
        results.append(prod)

        debug_items.append({
            "product_id": pid,
            "rrf_score": round(rrf_score, 6),
            "dominant_track": dominant,
            "semantic_rank": info.get("semantic_rank"),
            "fulltext_rank": info.get("fulltext_rank"),
            "trigram_rank": info.get("trigram_rank"),
        })

    debug_info = {
        "expanded_query": expanded_query,
        "tracks": {
            "semantic_count": len(sem),
            "fulltext_count": len(fts),
            "trigram_count": len(trg),
        },
        "items": debug_items,
    }

    return results, debug_info


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

def evaluate_query(query_spec: dict, results: list[dict], k: int,
                   debug_info: dict | None = None) -> dict:
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

    # Debug info z hybrid search
    if debug_info:
        items = debug_info.get("items", [])
        if items:
            metrics["dominant_track"] = items[0].get("dominant_track", "unknown")
        metrics["semantic_rrf"] = sum(
            1 for it in items if it.get("semantic_rank") is not None
        )
        metrics["fulltext_rrf"] = sum(
            1 for it in items if it.get("fulltext_rank") is not None
        )
        metrics["trigram_rrf"] = sum(
            1 for it in items if it.get("trigram_rank") is not None
        )

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


def run_evaluation(dataset: list[dict], tag: str, limit: int, mode: str):
    """Uruchom pełną ewaluację i zapisz raport."""
    print(f"=== Ewaluacja: {tag} (mode={mode}) ===")
    print(f"Zapytań: {len(dataset)}, limit: {limit}")
    print()

    conn = get_db_connection()
    client = get_openai_client()

    # Ładuj synonimy dla trybu hybrid
    syn_map = {}
    if mode == "hybrid":
        syn_map = load_synonym_map(conn)
        print(f"Załadowano {len(syn_map)} wpisów w mapie synonimów")
        print()

    all_metrics = []
    track_dominance = {"semantic": 0, "fulltext": 0, "trigram": 0}

    for i, q in enumerate(dataset):
        print(f"  [{i+1}/{len(dataset)}] {q['id']}: \"{q['query']}\"", end="", flush=True)

        embedding = get_embedding(client, q["query"])

        if mode == "hybrid":
            results, debug_info = search_hybrid_rrf(conn, embedding, q["query"], syn_map, limit=limit)
            metrics = evaluate_query(q, results, k=limit, debug_info=debug_info)
            # Track dominance
            if debug_info.get("items"):
                dom = debug_info["items"][0].get("dominant_track", "semantic")
                track_dominance[dom] = track_dominance.get(dom, 0) + 1
        else:
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
        if metrics.get("dominant_track"):
            status_parts.append(f"dom={metrics['dominant_track']}")
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

    aggregate_metrics = {
        "zero_results_rate": round(zero_results_rate, 4),
        "category_hit_at_5": round(cat_hit_at_5, 4) if cat_hit_at_5 >= 0 else "N/A",
        "category_hit_at_10": round(cat_hit_at_10, 4) if cat_hit_at_10 >= 0 else "N/A",
        "brand_hit_at_5": round(brand_hit_at_5, 4) if brand_hit_at_5 >= 0 else "N/A",
        "brand_hit_at_10": round(brand_hit_at_10, 4) if brand_hit_at_10 >= 0 else "N/A",
        "avg_product_mrr": round(avg_mrr, 4) if avg_mrr >= 0 else "N/A",
        "avg_product_recall_at_5": round(avg_recall_at_5, 4) if avg_recall_at_5 >= 0 else "N/A",
        "avg_top_similarity": round(avg_top_sim, 4),
        "avg_similarity_top5": round(avg_sim_top5, 4),
    }

    if mode == "hybrid":
        aggregate_metrics["track_dominance"] = track_dominance

    report = {
        "tag": tag,
        "timestamp": datetime.now().isoformat(),
        "config": {
            "embedding_model": EMBEDDING_MODEL,
            "embedding_dimensions": EMBEDDING_DIMENSIONS,
            "search_limit": limit,
            "search_mode": mode,
            "total_queries": total,
            "rrf_k": RRF_K if mode == "hybrid" else None,
            "track_limit": TRACK_LIMIT if mode == "hybrid" else None,
        },
        "aggregate_metrics": aggregate_metrics,
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
    print(f"  Mode: {mode}")
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
    if mode == "hybrid":
        print(f"  Track Dominance:       sem={track_dominance['semantic']} fts={track_dominance['fulltext']} trg={track_dominance['trigram']}")
    print("=" * 60)
    print(f"  Raport: {filename}")
    print()

    return report, filename


def main():
    parser = argparse.ArgumentParser(description="Ewaluacja wyszukiwania produktów")
    parser.add_argument("--tag", required=True, help="Etykieta ewaluacji (np. baseline_before_changes)")
    parser.add_argument("--limit", type=int, default=DEFAULT_LIMIT, help=f"Liczba wyników (top K), domyślnie {DEFAULT_LIMIT}")
    parser.add_argument("--dataset", type=str, default=str(DATASET_PATH), help="Ścieżka do golden dataset JSON")
    parser.add_argument("--mode", choices=["vector", "hybrid"], default="hybrid",
                        help="Tryb wyszukiwania: vector (tylko embedding) lub hybrid (3 tory + RRF)")
    args = parser.parse_args()

    dataset_path = Path(args.dataset)
    if not dataset_path.exists():
        print(f"BŁĄD: Nie znaleziono golden dataset: {dataset_path}")
        sys.exit(1)

    with open(dataset_path, "r", encoding="utf-8") as f:
        dataset = json.load(f)

    print(f"Załadowano {len(dataset)} zapytań z {dataset_path.name}")
    run_evaluation(dataset, tag=args.tag, limit=args.limit, mode=args.mode)


if __name__ == "__main__":
    main()
