"""
TASK-CHAT-010: Embedduje N konkretnych produktów (sync API).
Wykonuje: single-vector (upsert wiersz + embedding) + multi-vector (name/desc/jargon).

Użycie:
  python embed_target_products.py --ids 4649,4650,4651,4652,4653,1868,5260,...

Wywołać:
  1. PO modyfikacji extract_products.py (KROK 4) — żeby whitelistowane produkty się załadowały.
  2. PO update_synonyms_logbook_voucher.py — żeby search_phrases miały kanoniczne frazy
     (multi-vector jargon używa search_phrases).
"""

from __future__ import annotations

import argparse
import json
import logging
import os
from pathlib import Path

import psycopg2
from dotenv import load_dotenv
from openai import OpenAI

from extract_products import extract_products, open_ssh_tunnel, close_ssh_tunnel
from batch_embed_products import upsert_product

load_dotenv(Path(__file__).resolve().parent.parent / ".env")
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
logger = logging.getLogger(__name__)

MODEL = "text-embedding-3-large"
DIMENSIONS = 1536


def get_db_connection():
    return psycopg2.connect(os.getenv("DATABASE_URL"))


def get_openai_client() -> OpenAI:
    return OpenAI(api_key=os.getenv("OPENAI_API_KEY"))


def get_embedding(client: OpenAI, text: str) -> list[float]:
    resp = client.embeddings.create(model=MODEL, input=text, dimensions=DIMENSIONS)
    return resp.data[0].embedding


def fetch_multivector_texts(conn, ids: list[int]) -> dict[int, dict]:
    """Pobiera dane potrzebne do multi-vector (name/desc/jargon) po upserci."""
    cur = conn.cursor()
    cur.execute(
        """SELECT ps_product_id, product_name, product_description,
                  category_name, brand_name, features::text, search_phrases::text
           FROM divechat_product_embeddings
           WHERE ps_product_id = ANY(%s)""",
        (ids,),
    )
    out = {}
    for r in cur.fetchall():
        out[r[0]] = {
            "product_name": r[1] or "",
            "product_description": r[2] or "",
            "category_name": r[3] or "",
            "brand_name": r[4] or "",
            "features": json.loads(r[5]) if r[5] else {},
            "search_phrases": json.loads(r[6]) if r[6] else [],
        }
    cur.close()
    return out


def build_multivector_texts(p: dict) -> dict[str, str]:
    """3 teksty per produkt (z batch_embed_multivector.py)."""
    name_parts = [p["product_name"]]
    if p["brand_name"]:
        name_parts.append(p["brand_name"])
    text_name = " ".join(name_parts)

    desc_parts = []
    if p["category_name"]:
        desc_parts.append(f"Kategoria: {p['category_name']}")
    if p["product_description"]:
        desc_parts.append(p["product_description"][:500])
    if p["features"]:
        features_str = ", ".join(f"{k}: {v}" for k, v in p["features"].items())
        desc_parts.append(f"Cechy: {features_str}")
    text_desc = ". ".join(desc_parts) if desc_parts else p["product_name"]

    text_jargon = ", ".join(p["search_phrases"]) if p["search_phrases"] else p["product_name"]

    return {"name": text_name, "desc": text_desc, "jargon": text_jargon}


def main():
    parser = argparse.ArgumentParser(description="Re-embed wybranych produktów (single + multi-vector)")
    parser.add_argument("--ids", required=True, help="Lista ID po przecinku, np. 4649,4650")
    parser.add_argument("--skip-single", action="store_true", help="Pomiń etap single-vector (jeśli już zrobione)")
    parser.add_argument("--skip-multi", action="store_true", help="Pomiń etap multi-vector (zrobimy później)")
    args = parser.parse_args()

    target_ids = [int(x) for x in args.ids.split(",") if x.strip()]
    logger.info("Target IDs: %s (skip_single=%s, skip_multi=%s)",
                target_ids, args.skip_single, args.skip_multi)

    client = get_openai_client()
    conn = get_db_connection()
    matched_count = 0

    # === Etap 1: single-vector (extract + embedding + upsert) ===
    if not args.skip_single:
        open_ssh_tunnel()
        try:
            all_products = extract_products()
        finally:
            close_ssh_tunnel()

        matched = [p for p in all_products if p["ps_product_id"] in target_ids]
        matched_count = len(matched)
        logger.info("Znaleziono %d/%d produktów w extract_products", matched_count, len(target_ids))

        missing = set(target_ids) - {p["ps_product_id"] for p in matched}
        if missing:
            logger.warning("Brak w extract: %s", sorted(missing))

        for p in matched:
            emb = get_embedding(client, p["document_text"])
            upsert_product(conn, p, emb)
            logger.info("[single] id=%d %s — embedded", p["ps_product_id"], p["product_name"][:50])

    # === Etap 2: multi-vector (re-fetch po upserci, użyj search_phrases) ===
    multi_updated = 0
    if not args.skip_multi:
        data = fetch_multivector_texts(conn, target_ids)
        cur = conn.cursor()
        for pid in target_ids:
            if pid not in data:
                logger.warning("[multi] id=%d brak w bazie, pomijam", pid)
                continue
            texts = build_multivector_texts(data[pid])
            vecs = {k: get_embedding(client, t) for k, t in texts.items()}
            cur.execute(
                """UPDATE divechat_product_embeddings
                   SET embedding_name = %s::vector,
                       embedding_desc = %s::vector,
                       embedding_jargon = %s::vector,
                       updated_at = NOW()
                   WHERE ps_product_id = %s""",
                (str(vecs["name"]), str(vecs["desc"]), str(vecs["jargon"]), pid),
            )
            multi_updated += 1
            logger.info("[multi] id=%d — name/desc/jargon updated (sp=%d)",
                        pid, len(data[pid]["search_phrases"]))
        conn.commit()
        cur.close()

    conn.close()
    logger.info("GOTOWE: %d single-vector, %d multi-vector", matched_count, multi_updated)


if __name__ == "__main__":
    main()
