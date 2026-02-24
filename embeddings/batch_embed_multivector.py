"""
Multi-vector embeddingi: 3 kolumny (name, desc, jargon) per produkt (TASK-012b).
Używa OpenAI Batch API dla wydajności. Model: text-embedding-3-large, dim=1536.
"""

import argparse
import json
import time
import logging
import os
from pathlib import Path

import psycopg2
from dotenv import load_dotenv
from openai import OpenAI

load_dotenv(Path(__file__).resolve().parent.parent / ".env")

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
logger = logging.getLogger(__name__)

PROJECT_ROOT = Path(__file__).resolve().parent.parent
BATCH_API_POLL_INTERVAL = 30
MODEL = "text-embedding-3-large"
DIMENSIONS = 1536


def get_db_connection():
    database_url = os.getenv("DATABASE_URL")
    if not database_url:
        raise ValueError("Brak DATABASE_URL w .env")
    return psycopg2.connect(database_url)


def fetch_products(conn) -> list[dict]:
    """Pobiera produkty z bazy z danymi potrzebnymi do 3 embeddingów."""
    cur = conn.cursor()
    cur.execute("""
        SELECT ps_product_id, product_name, product_description,
               category_name, brand_name, features::text, search_phrases::text
        FROM divechat_product_embeddings
        ORDER BY ps_product_id
    """)
    rows = cur.fetchall()
    cur.close()

    products = []
    for r in rows:
        features = json.loads(r[5]) if r[5] else {}
        search_phrases = json.loads(r[6]) if r[6] else []

        products.append({
            "ps_product_id": r[0],
            "product_name": r[1] or "",
            "product_description": r[2] or "",
            "category_name": r[3] or "",
            "brand_name": r[4] or "",
            "features": features,
            "search_phrases": search_phrases,
        })

    return products


def build_texts(product: dict) -> dict[str, str]:
    """Buduje 3 teksty do embeddingu."""
    # embedding_name: nazwa + marka
    name_parts = [product["product_name"]]
    if product["brand_name"]:
        name_parts.append(product["brand_name"])
    text_name = " ".join(name_parts)

    # embedding_desc: kategoria + opis + cechy
    desc_parts = []
    if product["category_name"]:
        desc_parts.append(f"Kategoria: {product['category_name']}")
    if product["product_description"]:
        desc_parts.append(product["product_description"][:500])
    if product["features"]:
        features_str = ", ".join(f"{k}: {v}" for k, v in product["features"].items())
        desc_parts.append(f"Cechy: {features_str}")
    text_desc = ". ".join(desc_parts) if desc_parts else product["product_name"]

    # embedding_jargon: search_phrases
    text_jargon = ", ".join(product["search_phrases"]) if product["search_phrases"] else product["product_name"]

    return {
        "name": text_name,
        "desc": text_desc,
        "jargon": text_jargon,
    }


def run_batch(products: list[dict]):
    """OpenAI Batch API: 3 embeddingi per produkt."""
    client = OpenAI(api_key=os.getenv("OPENAI_API_KEY"))

    # Buduj .jsonl z 3 requestami per produkt
    jsonl_path = Path(__file__).parent / "batch_multivector.jsonl"
    total_requests = 0

    with open(jsonl_path, "w", encoding="utf-8") as f:
        for product in products:
            texts = build_texts(product)
            pid = product["ps_product_id"]

            for vec_type, text in texts.items():
                request = {
                    "custom_id": f"{pid}_{vec_type}",
                    "method": "POST",
                    "url": "/v1/embeddings",
                    "body": {
                        "model": MODEL,
                        "input": text,
                        "dimensions": DIMENSIONS,
                    },
                }
                f.write(json.dumps(request, ensure_ascii=False) + "\n")
                total_requests += 1

    logger.info("Przygotowano %d requestów (%d produktów × 3) → %s",
                total_requests, len(products), jsonl_path)

    # Upload
    with open(jsonl_path, "rb") as f:
        batch_file = client.files.create(file=f, purpose="batch")
    logger.info("Plik uploadowany: %s", batch_file.id)

    # Utwórz batch
    batch = client.batches.create(
        input_file_id=batch_file.id,
        endpoint="/v1/embeddings",
        completion_window="24h",
    )
    logger.info("Batch utworzony: %s (status: %s)", batch.id, batch.status)

    # Poll
    while batch.status not in ("completed", "failed", "cancelled", "expired"):
        time.sleep(BATCH_API_POLL_INTERVAL)
        batch = client.batches.retrieve(batch.id)
        completed = batch.request_counts.completed if batch.request_counts else 0
        total = batch.request_counts.total if batch.request_counts else total_requests
        logger.info("Batch %s: status=%s, %d/%d", batch.id, batch.status, completed, total)

    if batch.status != "completed":
        logger.error("Batch zakończony ze statusem: %s", batch.status)
        return

    # Pobierz wyniki
    output_file = client.files.content(batch.output_file_id)
    results_lines = output_file.text.strip().split("\n")
    logger.info("Pobrano %d wyników z Batch API", len(results_lines))

    # Parsuj wyniki: {pid: {name: [...], desc: [...], jargon: [...]}}
    embeddings_map: dict[int, dict[str, list[float]]] = {}
    errors = 0

    for line in results_lines:
        result = json.loads(line)
        custom_id = result["custom_id"]

        if result.get("error"):
            logger.error("Błąd API dla %s: %s", custom_id, result["error"])
            errors += 1
            continue

        # Parsuj custom_id: "1234_name"
        parts = custom_id.rsplit("_", 1)
        pid = int(parts[0])
        vec_type = parts[1]

        embedding = result["response"]["body"]["data"][0]["embedding"]

        if pid not in embeddings_map:
            embeddings_map[pid] = {}
        embeddings_map[pid][vec_type] = embedding

    logger.info("Sparsowano embeddingi dla %d produktów (%d błędów)", len(embeddings_map), errors)

    # Zapisz do bazy
    conn = get_db_connection()
    cur = conn.cursor()
    updated = 0

    for pid, vectors in embeddings_map.items():
        if len(vectors) != 3:
            logger.warning("Produkt %d: tylko %d/3 wektorów, pomijam", pid, len(vectors))
            continue

        cur.execute(
            """UPDATE divechat_product_embeddings
               SET embedding_name = %s::vector,
                   embedding_desc = %s::vector,
                   embedding_jargon = %s::vector,
                   updated_at = NOW()
               WHERE ps_product_id = %s""",
            (
                str(vectors["name"]),
                str(vectors["desc"]),
                str(vectors["jargon"]),
                pid,
            ),
        )
        updated += 1

        if updated % 500 == 0:
            conn.commit()
            logger.info("Checkpoint: %d/%d produktów zapisanych", updated, len(embeddings_map))

    conn.commit()
    cur.close()
    conn.close()

    # Posprzątaj
    jsonl_path.unlink(missing_ok=True)

    logger.info("GOTOWE: %d produktów z 3 wektorami, %d błędów", updated, errors)


def run_test(count: int):
    """Tryb testowy: sync API, N pierwszych produktów."""
    client = OpenAI(api_key=os.getenv("OPENAI_API_KEY"))
    conn = get_db_connection()
    products = fetch_products(conn)[:count]

    updated = 0
    cur = conn.cursor()

    for i, product in enumerate(products, 1):
        texts = build_texts(product)
        pid = product["ps_product_id"]

        vectors = {}
        for vec_type, text in texts.items():
            resp = client.embeddings.create(model=MODEL, input=text, dimensions=DIMENSIONS)
            vectors[vec_type] = resp.data[0].embedding

        cur.execute(
            """UPDATE divechat_product_embeddings
               SET embedding_name = %s::vector,
                   embedding_desc = %s::vector,
                   embedding_jargon = %s::vector,
                   updated_at = NOW()
               WHERE ps_product_id = %s""",
            (str(vectors["name"]), str(vectors["desc"]), str(vectors["jargon"]), pid),
        )
        updated += 1

        if i % 10 == 0 or i == len(products):
            conn.commit()
            logger.info("Postęp: %d/%d", i, len(products))

    conn.commit()
    cur.close()
    conn.close()
    logger.info("GOTOWE (test): %d produktów z 3 wektorami", updated)


def main():
    parser = argparse.ArgumentParser(description="Multi-vector embeddingi (name, desc, jargon)")
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--test", type=int, metavar="N", help="Test N produktów (sync)")
    group.add_argument("--full", action="store_true", help="Wszystkie produkty (Batch API)")
    args = parser.parse_args()

    if args.test:
        run_test(args.test)
    else:
        conn = get_db_connection()
        products = fetch_products(conn)
        conn.close()
        logger.info("Pobrano %d produktów", len(products))
        run_batch(products)


if __name__ == "__main__":
    main()
