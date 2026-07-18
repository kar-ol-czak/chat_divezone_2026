"""
Batch embeddingi produktów PrestaShop -> PostgreSQL (pgvector).
Tryby: --test N (sync API), --full (OpenAI Batch API), --mode changed (delta po hashu).
Model: text-embedding-3-large, dimensions=1536.

CHAT-T-150 (ADR-128): tryb `--mode changed` — extract z MySQL zawsze pełny (0 kosztu API),
do embeddingu kwalifikowane tylko produkty o zmienionym document_text (hash) lub nieobecne w PG.
"""

import argparse
import json
import time
import logging
import sys
from pathlib import Path

from extract_products import (
    extract_products,
    open_mysql_access,
    close_mysql_access,
    document_text_hash,
)
from generate_embeddings import get_openai_client, get_embedding, get_db_connection

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
logger = logging.getLogger(__name__)

BATCH_SIZE = 100  # ile embeddingów w jednym batch request (sync)
BATCH_API_POLL_INTERVAL = 30  # sekundy między sprawdzeniami statusu Batch API

# CHAT-T-150: text-embedding-3-large — 0.13 USD / 1M tokenów (do szacunku kosztu w logu).
PRICE_PER_1M_TOKENS = 0.13


def _estimate_tokens(text: str) -> int:
    """Zgrubny szacunek tokenów (~4 znaki/token). Tylko do orientacyjnego kosztu w logu."""
    return max(1, len(text or "") // 4)


def upsert_product(conn, product: dict, embedding: list[float]):
    """Wstawia lub aktualizuje produkt z embeddingiem w divechat_product_embeddings."""
    cur = conn.cursor()
    cur.execute(
        """
        INSERT INTO divechat_product_embeddings
            (ps_product_id, product_name, product_description, category_name,
             brand_name, features, price, is_active, in_stock,
             product_url, image_url, document_text, embedding)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s::vector)
        ON CONFLICT (ps_product_id) DO UPDATE SET
            product_name = EXCLUDED.product_name,
            product_description = EXCLUDED.product_description,
            category_name = EXCLUDED.category_name,
            brand_name = EXCLUDED.brand_name,
            features = EXCLUDED.features,
            price = EXCLUDED.price,
            is_active = EXCLUDED.is_active,
            in_stock = EXCLUDED.in_stock,
            product_url = EXCLUDED.product_url,
            image_url = EXCLUDED.image_url,
            document_text = EXCLUDED.document_text,
            embedding = EXCLUDED.embedding,
            updated_at = NOW();
        """,
        (
            product["ps_product_id"],
            product["product_name"],
            product.get("product_description"),
            product.get("category_name"),
            product.get("brand_name"),
            json.dumps(product.get("features", {}), ensure_ascii=False),
            product.get("price"),
            product.get("is_active", True),
            product.get("in_stock", False),
            product.get("product_url"),
            product.get("image_url"),
            product["document_text"],
            str(embedding),
        ),
    )
    conn.commit()
    cur.close()


def deduplicate_products(products: list[dict]) -> list[dict]:
    """Usuwa duplikaty produktow po ps_product_id (zachowuje pierwszy)."""
    seen = set()
    unique = []
    for p in products:
        pid = p["ps_product_id"]
        if pid not in seen:
            seen.add(pid)
            unique.append(p)
    if len(unique) < len(products):
        logger.warning("Usunieto %d duplikatow produktow", len(products) - len(unique))
    return unique


def run_test_mode(products: list[dict], count: int):
    """Tryb --test: sync API, przetwarza N pierwszych produktów."""
    products = products[:count]
    logger.info("Tryb TEST: przetwarzam %d produktów (sync API)", len(products))

    client = get_openai_client()
    conn = get_db_connection()

    success = 0
    errors = 0

    for i, product in enumerate(products, 1):
        try:
            embedding = get_embedding(client, product["document_text"])
            upsert_product(conn, product, embedding)
            success += 1
            if i % 10 == 0 or i == len(products):
                logger.info("Postep: %d/%d (OK: %d, bledy: %d)", i, len(products), success, errors)
        except Exception as e:
            errors += 1
            logger.error("Blad produktu %d (%s): %s", product["ps_product_id"], product["product_name"], e)

    conn.close()
    logger.info("GOTOWE: %d wgranych, %d bledow", success, errors)
    return success


def fetch_pg_hashes(conn) -> dict[int, str]:
    """CHAT-T-150: pobiera z PG {ps_product_id: hash(document_text)} — hash liczony w locie."""
    cur = conn.cursor()
    cur.execute("SELECT ps_product_id, document_text FROM divechat_product_embeddings")
    result = {row[0]: document_text_hash(row[1] or "") for row in cur.fetchall()}
    cur.close()
    return result


def run_changed_mode(dry_run: bool = False) -> dict:
    """CHAT-T-150 (ADR-128): delta po hashu document_text.

    Wymaga wcześniejszego open_mysql_access() (robi to caller: main() lub run_nightly.py).
    Extract z MySQL zawsze pełny (0 kosztu API). Do embeddingu kwalifikowane produkty
    o zmienionym document_text (hash) lub nieobecne w PG. Embedding sync (delta jest mała).

    Returns: dict statystyk z 'changed_pids' (lista do przeliczenia multivectora).
    """
    t0 = time.time()

    products = deduplicate_products(extract_products())
    extracted_count = len(products)
    logger.info("[changed] Wyekstrahowano %d produktów z MySQL", extracted_count)

    conn = get_db_connection()
    pg_hashes = fetch_pg_hashes(conn)
    logger.info("[changed] W PG obecnych: %d produktów", len(pg_hashes))

    changed: list[dict] = []
    new_count = 0
    for p in products:
        pid = p["ps_product_id"]
        h = document_text_hash(p["document_text"])
        if pid not in pg_hashes:
            new_count += 1
            changed.append(p)
        elif pg_hashes[pid] != h:
            changed.append(p)

    qualified = len(changed)
    changed_pids = [p["ps_product_id"] for p in changed]
    logger.info(
        "[changed] Zakwalifikowano do delty: %d (nowe: %d, zmienione: %d)",
        qualified, new_count, qualified - new_count,
    )

    stats = {
        "extracted": extracted_count,
        "pg_present": len(pg_hashes),
        "qualified": qualified,
        "new": new_count,
        "changed_pids": changed_pids,
        "embedded": 0,
        "api_calls": 0,
        "est_tokens": 0,
        "est_cost_usd": 0.0,
        "dry_run": dry_run,
    }

    if dry_run:
        conn.close()
        stats["elapsed_s"] = round(time.time() - t0, 1)
        logger.info(
            "[changed][DRY-RUN] BEZ wywołań API. Zakwalifikowano %d/%d. Czas %.1fs",
            qualified, extracted_count, stats["elapsed_s"],
        )
        return stats

    if qualified == 0:
        conn.close()
        stats["elapsed_s"] = round(time.time() - t0, 1)
        logger.info("[changed] Brak zmian — baza aktualna. Czas %.1fs", stats["elapsed_s"])
        return stats

    client = get_openai_client()
    embedded = 0
    errors = 0
    est_tokens = 0
    for i, product in enumerate(changed, 1):
        try:
            embedding = get_embedding(client, product["document_text"])
            upsert_product(conn, product, embedding)
            embedded += 1
            est_tokens += _estimate_tokens(product["document_text"])
            if i % 10 == 0 or i == len(changed):
                logger.info("[changed] Postęp: %d/%d (OK: %d, błędy: %d)", i, len(changed), embedded, errors)
        except Exception as e:
            errors += 1
            logger.error("[changed] Błąd produktu %s: %s", product["ps_product_id"], e)

    conn.close()
    stats["embedded"] = embedded
    stats["api_calls"] = embedded  # 1 wywołanie sync = 1 embedding
    stats["errors"] = errors
    stats["est_tokens"] = est_tokens
    stats["est_cost_usd"] = round(est_tokens / 1_000_000 * PRICE_PER_1M_TOKENS, 6)
    stats["elapsed_s"] = round(time.time() - t0, 1)
    logger.info(
        "[changed] GOTOWE: %d embeddingów (%d błędów), ~%d tok, ~%.6f USD, %.1fs",
        embedded, errors, est_tokens, stats["est_cost_usd"], stats["elapsed_s"],
    )
    if errors:
        # Sygnalizuj częściowy błąd — runner potraktuje to jako niepowodzenie.
        raise RuntimeError(f"[changed] {errors} błędów embeddingu produktów")
    return stats


def run_full_mode(products: list[dict]):
    """Tryb --full: OpenAI Batch API (.jsonl upload, poll, download)."""
    products = deduplicate_products(products)
    logger.info("Tryb FULL: przetwarzam %d produktow (Batch API)", len(products))

    client = get_openai_client()

    # 1. Przygotuj plik .jsonl
    jsonl_path = Path(__file__).parent / "batch_input.jsonl"
    with open(jsonl_path, "w", encoding="utf-8") as f:
        for product in products:
            request = {
                "custom_id": str(product["ps_product_id"]),
                "method": "POST",
                "url": "/v1/embeddings",
                "body": {
                    "model": "text-embedding-3-large",
                    "input": product["document_text"],
                    "dimensions": 1536,
                },
            }
            f.write(json.dumps(request, ensure_ascii=False) + "\n")
    logger.info("Zapisano %d requestow do %s", len(products), jsonl_path)

    # 2. Upload pliku
    with open(jsonl_path, "rb") as f:
        batch_file = client.files.create(file=f, purpose="batch")
    logger.info("Plik uploadowany: %s", batch_file.id)

    # 3. Utworz batch
    batch = client.batches.create(
        input_file_id=batch_file.id,
        endpoint="/v1/embeddings",
        completion_window="24h",
    )
    logger.info("Batch utworzony: %s (status: %s)", batch.id, batch.status)

    # 4. Poll statusu
    while batch.status not in ("completed", "failed", "cancelled", "expired"):
        time.sleep(BATCH_API_POLL_INTERVAL)
        batch = client.batches.retrieve(batch.id)
        completed = batch.request_counts.completed if batch.request_counts else 0
        total = batch.request_counts.total if batch.request_counts else len(products)
        logger.info("Batch %s: status=%s, %d/%d", batch.id, batch.status, completed, total)

    if batch.status != "completed":
        logger.error("Batch zakonczony ze statusem: %s", batch.status)
        return 0

    # 5. Pobierz wyniki
    output_file = client.files.content(batch.output_file_id)
    results_lines = output_file.text.strip().split("\n")
    logger.info("Pobrano %d wynikow z Batch API", len(results_lines))

    # Mapa produktow po ps_product_id
    products_map = {str(p["ps_product_id"]): p for p in products}

    # 6. Wgraj do PostgreSQL
    conn = get_db_connection()
    success = 0
    errors = 0

    for line in results_lines:
        result = json.loads(line)
        custom_id = result["custom_id"]
        product = products_map.get(custom_id)

        if not product:
            logger.warning("Brak produktu dla custom_id=%s", custom_id)
            errors += 1
            continue

        if result.get("error"):
            logger.error("Blad API dla produktu %s: %s", custom_id, result["error"])
            errors += 1
            continue

        embedding = result["response"]["body"]["data"][0]["embedding"]
        try:
            upsert_product(conn, product, embedding)
            success += 1
        except Exception as e:
            errors += 1
            logger.error("Blad zapisu produktu %s: %s", custom_id, e)

    conn.close()

    # Posprzataj plik
    jsonl_path.unlink(missing_ok=True)
    logger.info("GOTOWE (Batch): %d wgranych, %d bledow", success, errors)
    return success


def main():
    parser = argparse.ArgumentParser(description="Batch embeddingi produktow PrestaShop")
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--test", type=int, metavar="N", help="Przetworz N pierwszych produktow (sync API)")
    group.add_argument("--full", action="store_true", help="Wszystkie produkty (OpenAI Batch API)")
    group.add_argument("--mode", choices=["changed"], help="changed = delta po hashu document_text")
    parser.add_argument("--dry-run", action="store_true", help="(z --mode changed) tylko raport, zero API")
    args = parser.parse_args()

    # Otworz dostep do MySQL (tunel tylko w trybie lokalnym)
    open_mysql_access()

    try:
        if args.mode == "changed":
            run_changed_mode(dry_run=args.dry_run)
            return

        limit = args.test if args.test else None
        products = extract_products(limit=limit)

        if not products:
            logger.error("Brak produktow do przetworzenia!")
            return

        logger.info("Pobrano %d produktow z MySQL", len(products))

        if args.test:
            success = run_test_mode(products, args.test)
        else:
            success = run_full_mode(products)

        logger.info("Zakonczono: %d produktow z embeddingami w bazie", success)
    finally:
        close_mysql_access()


if __name__ == "__main__":
    main()
