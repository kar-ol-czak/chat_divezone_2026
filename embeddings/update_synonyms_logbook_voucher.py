"""
TASK-CHAT-010: Aktualizuje synonyms_pl/en i search_phrases dla produktów
docelowych (logbook, wet notes, voucher). Idempotentne.

- 7 logbooków + 4 wet notes — produkty już w embeddings, UPDATE synonyms i prepend phrases
- 5 voucherów — wykonać PO re-extract (KROK 4); skrypt obsłuży je gdy będą w bazie

Uruchamiać po update'cie divechat_synonyms (load_synonyms.py).
"""

from __future__ import annotations

import json
import logging
import os
import sys
from pathlib import Path

import psycopg2
from dotenv import load_dotenv

load_dotenv(Path(__file__).resolve().parent.parent / ".env")
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
logger = logging.getLogger(__name__)


LOGBOOK_IDS = [3574, 5261, 5262, 5263, 6645, 6646, 6805]
WET_NOTES_IDS = [1868, 5260, 6241, 6263]
VOUCHER_IDS = [4649, 4650, 4651, 4652, 4653]

SYNONYMS = {
    "logbook": {
        "pl": [
            "logbook",
            "log book",
            "dziennik nurkowań",
            "dziennik nurkowy",
            "książeczka nurkowa",
            "książeczka do wpisywania nurkowań",
            "karta nurkowań",
        ],
        "en": [
            "logbook",
            "log book",
            "dive logbook",
            "diving log",
            "dive journal",
            "scuba log",
        ],
    },
    "wet notes": {
        "pl": [
            "wet notes",
            "mokry notes",
            "podwodny notatnik",
            "notes nurkowy",
            "notes pod wodą",
        ],
        "en": [
            "wet notes",
            "underwater notebook",
            "dive slate alternative",
        ],
    },
    "voucher": {
        "pl": [
            "voucher",
            "voucher prezentowy",
            "bon",
            "bon prezentowy",
            "kupon",
            "karta podarunkowa",
            "prezent",
            "prezent dla nurka",
            "upominek",
        ],
        "en": [
            "voucher",
            "gift voucher",
            "gift card",
            "gift certificate",
        ],
    },
}


def get_db_connection():
    url = os.getenv("DATABASE_URL")
    if not url:
        raise RuntimeError("Brak DATABASE_URL w .env")
    return psycopg2.connect(url)


def merge_unique(prefix: list[str], existing: list[str]) -> list[str]:
    """Prepend `prefix` to `existing`, zachowując unikalność (case-insensitive)."""
    seen = set()
    result = []
    for item in prefix + existing:
        key = item.strip().lower()
        if not key or key in seen:
            continue
        seen.add(key)
        result.append(item.strip())
    return result


def update_product(cur, ps_product_id: int, syns: dict[str, list[str]]) -> bool:
    """UPDATE jednego produktu. Zwraca True jeśli wiersz istnieje."""
    cur.execute(
        "SELECT search_phrases::text, synonyms_pl::text, synonyms_en::text "
        "FROM divechat_product_embeddings WHERE ps_product_id = %s",
        (ps_product_id,),
    )
    row = cur.fetchone()
    if row is None:
        logger.warning("Produkt id=%d nie istnieje w divechat_product_embeddings", ps_product_id)
        return False

    current_phrases = json.loads(row[0]) if row[0] else []
    current_pl = json.loads(row[1]) if row[1] else []
    current_en = json.loads(row[2]) if row[2] else []

    new_phrases = merge_unique(syns["pl"] + syns["en"], current_phrases)
    new_pl = merge_unique(syns["pl"], current_pl)
    new_en = merge_unique(syns["en"], current_en)

    cur.execute(
        """UPDATE divechat_product_embeddings
           SET search_phrases = %s::jsonb,
               synonyms_pl = %s::jsonb,
               synonyms_en = %s::jsonb,
               updated_at = NOW()
           WHERE ps_product_id = %s""",
        (
            json.dumps(new_phrases, ensure_ascii=False),
            json.dumps(new_pl, ensure_ascii=False),
            json.dumps(new_en, ensure_ascii=False),
            ps_product_id,
        ),
    )
    logger.info(
        "id=%d: phrases %d→%d, syn_pl %d→%d, syn_en %d→%d",
        ps_product_id,
        len(current_phrases), len(new_phrases),
        len(current_pl), len(new_pl),
        len(current_en), len(new_en),
    )
    return True


def main():
    conn = get_db_connection()
    cur = conn.cursor()

    plan = [
        ("logbook", LOGBOOK_IDS),
        ("wet notes", WET_NOTES_IDS),
        ("voucher", VOUCHER_IDS),
    ]

    total_updated = 0
    total_missing = 0
    for canonical, ids in plan:
        syns = SYNONYMS[canonical]
        print(f"\n=== {canonical.upper()} ({len(ids)} produktów) ===")
        for pid in ids:
            if update_product(cur, pid, syns):
                total_updated += 1
            else:
                total_missing += 1

    conn.commit()
    cur.close()
    conn.close()

    print(f"\nGOTOWE: {total_updated} zaktualizowanych, {total_missing} brakujących (czekają na re-extract)")
    if total_missing:
        print("Uruchom ponownie po re-extract voucherów (KROK 4).")


if __name__ == "__main__":
    main()
