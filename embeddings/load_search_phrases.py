"""
Ładowanie zwalidowanych fraz wyszukiwania do PostgreSQL (TASK-011b, Krok 4).
Dodaje kolumnę search_phrases JSONB do divechat_product_embeddings.
"""

import argparse
import json
import os
import logging
from pathlib import Path

import psycopg2
from dotenv import load_dotenv

load_dotenv(Path(__file__).resolve().parent.parent / ".env")

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
logger = logging.getLogger(__name__)

PROJECT_ROOT = Path(__file__).resolve().parent.parent
INPUT_FILE = PROJECT_ROOT / "data" / "enrichment" / "search_phrases_validated.json"


def get_db_connection():
    """Zwraca połączenie z PostgreSQL."""
    database_url = os.getenv("DATABASE_URL")
    if not database_url:
        raise ValueError("Brak DATABASE_URL w .env")
    return psycopg2.connect(database_url)


def ensure_column(conn):
    """Dodaje kolumnę search_phrases jeśli nie istnieje."""
    cur = conn.cursor()
    cur.execute("""
        ALTER TABLE divechat_product_embeddings
        ADD COLUMN IF NOT EXISTS search_phrases JSONB DEFAULT '[]'::jsonb
    """)
    conn.commit()
    cur.close()
    logger.info("Kolumna search_phrases OK")


def load_phrases(input_path: Path, dry_run: bool = False):
    """Wczytuje zwalidowane frazy i aktualizuje bazę."""
    if not input_path.exists():
        logger.error("Brak pliku: %s", input_path)
        return

    with open(input_path, encoding="utf-8") as f:
        data = json.load(f)

    conn = get_db_connection()
    ensure_column(conn)

    cur = conn.cursor()
    updated = 0
    skipped = 0

    for pid, entry in data.items():
        phrases = entry.get("validated_phrases", [])
        if not phrases:
            skipped += 1
            continue

        if dry_run:
            logger.info("DRY-RUN: ID:%s → %d fraz: %s", pid, len(phrases), phrases[:3])
            updated += 1
            continue

        cur.execute(
            "UPDATE divechat_product_embeddings SET search_phrases = %s WHERE ps_product_id = %s",
            (json.dumps(phrases, ensure_ascii=False), int(pid)),
        )
        updated += 1

    if not dry_run:
        conn.commit()

    cur.close()
    conn.close()

    logger.info("Zaktualizowano %d produktów, pominięto %d (brak fraz)", updated, skipped)


def main():
    parser = argparse.ArgumentParser(description="Ładowanie fraz wyszukiwania do PostgreSQL")
    parser.add_argument("--input", type=str, default=str(INPUT_FILE), help="Plik z validated phrases")
    parser.add_argument("--dry-run", action="store_true", help="Tylko wyświetl, nie zapisuj do bazy")
    args = parser.parse_args()

    load_phrases(Path(args.input), dry_run=args.dry_run)


if __name__ == "__main__":
    main()
