#!/usr/bin/env python3
"""
TASK-ENC-012: Embedding encyklopedii do pgvector.
Chunking 105 haseł × 5 typów → 525 chunków → text-embedding-3-large → PostgreSQL.
"""

import argparse
import json
import os
import sys
import time
from pathlib import Path

import psycopg2
from psycopg2.extras import execute_values
from openai import OpenAI
from dotenv import load_dotenv

# Ścieżki
PROJECT_ROOT = Path(__file__).resolve().parent.parent
RAW_DIR = PROJECT_ROOT / "data" / "encyclopedia" / "v3" / "gen_v2" / "raw"
REPORT_PATH = PROJECT_ROOT / "data" / "encyclopedia" / "v3" / "gen_v2" / "embedding_report.md"

# Stałe
EMBEDDING_MODEL = "text-embedding-3-large"
EMBEDDING_DIM = 3072
BATCH_SIZE = 20
CHUNK_TYPES = ["definition", "synonyms", "purchase", "faq", "seller"]


def get_db_connection():
    load_dotenv(PROJECT_ROOT / ".env")
    url = os.getenv("DATABASE_URL")
    return psycopg2.connect(url)


def get_openai_client():
    load_dotenv(PROJECT_ROOT / ".env")
    return OpenAI(api_key=os.getenv("OPENAI_API_KEY"))


def create_table(conn):
    """KROK 1: Tworzenie tabeli encyclopedia_chunks."""
    with conn.cursor() as cur:
        cur.execute("""
            CREATE TABLE IF NOT EXISTS encyclopedia_chunks (
                id SERIAL PRIMARY KEY,
                concept_key VARCHAR(100) NOT NULL,
                concept_number INTEGER,
                name_pl VARCHAR(200),
                name_en VARCHAR(200),
                chunk_type VARCHAR(50) NOT NULL,
                content TEXT NOT NULL,
                embedding VECTOR(3072),
                metadata JSONB DEFAULT '{}',
                created_at TIMESTAMPTZ DEFAULT NOW(),
                updated_at TIMESTAMPTZ DEFAULT NOW()
            );
        """)
        cur.execute("""
            CREATE UNIQUE INDEX IF NOT EXISTS idx_enc_concept_type
                ON encyclopedia_chunks (concept_key, chunk_type);
        """)
        cur.execute("""
            CREATE INDEX IF NOT EXISTS idx_enc_concept
                ON encyclopedia_chunks (concept_key);
        """)
        cur.execute("""
            CREATE INDEX IF NOT EXISTS idx_enc_type
                ON encyclopedia_chunks (chunk_type);
        """)
        conn.commit()
        print("[OK] Tabela encyclopedia_chunks gotowa.")


def create_vector_index(conn):
    """pgvector 0.8.x: max 2000 dim dla IVFFlat/HNSW. 525 wierszy → exact scan wystarczy."""
    print("[INFO] Brak indeksu wektorowego (3072 dim > limit 2000). Exact scan przy 525 wierszach <1ms.")


def safe_join(items, key="text"):
    """Bezpiecznie łączy listy dict-ów po kluczu."""
    if not items:
        return ""
    return ", ".join(item.get(key, str(item)) if isinstance(item, dict) else str(item) for item in items)


def safe_join_names(items):
    """Łączy listę dict-ów po kluczu 'name'."""
    if not items:
        return ""
    return ", ".join(item.get("name", "") for item in items)


def build_chunk_definition(entry):
    parts = [
        f"{entry.get('name_pl', '')} / {entry.get('name_en', '')}",
        "",
        entry.get("definition", ""),
    ]
    subtypes_client = entry.get("subtypes_client", [])
    subtypes_tech = entry.get("subtypes_technical", [])
    if subtypes_client:
        parts.append(f"\nPodtypy klienckie: {safe_join_names(subtypes_client)}")
    if subtypes_tech:
        parts.append(f"Podtypy techniczne: {safe_join_names(subtypes_tech)}")
    return "\n".join(parts)


def build_chunk_synonyms(entry):
    name_pl = entry.get("name_pl", "")
    syns = entry.get("synonyms", {})
    parts = [f"Synonimy i frazy wyszukiwania dla: {name_pl}", ""]

    for label, key in [("Oficjalne", "official"), ("Bliskie", "close"),
                       ("Slang", "slang"), ("Anglicyzmy", "anglicisms"),
                       ("Błędne zapytania", "misspelled")]:
        items = syns.get(key, [])
        if items:
            parts.append(f"{label}: {safe_join(items)}")

    longtail = entry.get("longtail_phrases", [])
    if longtail:
        parts.append(f"\nFrazy long-tail: {safe_join(longtail)}")

    return "\n".join(parts)


def build_chunk_purchase(entry):
    name_pl = entry.get("name_pl", "")
    parts = [f"Parametry zakupowe: {name_pl}", ""]

    for param in entry.get("purchase_parameters", []):
        parts.append(f"{param.get('name', '')}: {param.get('description', '')}")

    cross = entry.get("cross_sell", [])
    if cross:
        parts.append("\nPowiązane produkty:")
        for cs in cross:
            parts.append(f"{cs.get('product', '')}: {cs.get('description', '')}")

    confuse = entry.get("not_to_confuse", [])
    if confuse:
        parts.append("\nNie mylić z:")
        for ntc in confuse:
            parts.append(f"{ntc.get('concept_key', '')}: {ntc.get('explanation', '')}")

    return "\n".join(parts)


def build_chunk_faq(entry):
    name_pl = entry.get("name_pl", "")
    parts = [f"FAQ: {name_pl}", ""]
    for faq in entry.get("faq", []):
        parts.append(f"Q: {faq.get('question', '')}")
        parts.append(f"A: {faq.get('answer', '')}")
        parts.append("")
    return "\n".join(parts)


def build_chunk_seller(entry):
    name_pl = entry.get("name_pl", "")
    parts = [f"Uwagi dla sprzedawcy: {name_pl}", ""]
    parts.append(entry.get("seller_notes", ""))
    related = entry.get("related_concept_keys", [])
    if related:
        parts.append(f"\nPowiązane hasła: {', '.join(related)}")
    return "\n".join(parts)


CHUNK_BUILDERS = {
    "definition": build_chunk_definition,
    "synonyms": build_chunk_synonyms,
    "purchase": build_chunk_purchase,
    "faq": build_chunk_faq,
    "seller": build_chunk_seller,
}


def load_entries(concept_key=None):
    """Wczytuje hasła z raw JSON-ów."""
    entries = []
    if concept_key:
        path = RAW_DIR / f"{concept_key}.json"
        if not path.exists():
            print(f"[BŁĄD] Nie znaleziono: {path}")
            sys.exit(1)
        with open(path, "r", encoding="utf-8") as f:
            entries.append(json.load(f))
    else:
        for path in sorted(RAW_DIR.glob("*.json")):
            with open(path, "r", encoding="utf-8") as f:
                entries.append(json.load(f))
    print(f"[OK] Wczytano {len(entries)} haseł.")
    return entries


def build_chunks(entries):
    """KROK 2: Budowanie chunków z haseł."""
    chunks = []
    for entry in entries:
        concept_key = entry["concept_key"]
        metadata = {
            "concept_number": entry.get("concept_number"),
            "name_pl": entry.get("name_pl"),
            "name_en": entry.get("name_en"),
            "related_keys": entry.get("related_concept_keys", []),
            "pipeline_version": "v2",
        }
        for chunk_type in CHUNK_TYPES:
            content = CHUNK_BUILDERS[chunk_type](entry)
            chunks.append({
                "concept_key": concept_key,
                "concept_number": entry.get("concept_number"),
                "name_pl": entry.get("name_pl"),
                "name_en": entry.get("name_en"),
                "chunk_type": chunk_type,
                "content": content,
                "metadata": metadata,
            })
    print(f"[OK] Zbudowano {len(chunks)} chunków.")
    return chunks


def embed_chunks(client, chunks):
    """KROK 3: Generowanie embeddingów batch-ami."""
    total_tokens = 0
    t0 = time.time()

    for i in range(0, len(chunks), BATCH_SIZE):
        batch = chunks[i:i + BATCH_SIZE]
        texts = [c["content"] for c in batch]
        resp = client.embeddings.create(model=EMBEDDING_MODEL, input=texts, dimensions=EMBEDDING_DIM)
        for j, emb_data in enumerate(resp.data):
            batch[j]["embedding"] = emb_data.embedding
        total_tokens += resp.usage.total_tokens
        batch_num = i // BATCH_SIZE + 1
        total_batches = (len(chunks) + BATCH_SIZE - 1) // BATCH_SIZE
        print(f"  Batch {batch_num}/{total_batches} → {len(batch)} embeddingów")

    elapsed = time.time() - t0
    cost = total_tokens / 1_000_000 * 0.13  # $0.13/1M tokens for text-embedding-3-large
    print(f"[OK] Embedding: {total_tokens} tokenów, ${cost:.4f}, {elapsed:.1f}s")
    return total_tokens, cost, elapsed


def upsert_chunks(conn, chunks):
    """KROK 4: UPSERT do PostgreSQL."""
    sql = """
        INSERT INTO encyclopedia_chunks
            (concept_key, concept_number, name_pl, name_en, chunk_type, content, embedding, metadata)
        VALUES %s
        ON CONFLICT (concept_key, chunk_type)
        DO UPDATE SET
            content = EXCLUDED.content,
            embedding = EXCLUDED.embedding,
            metadata = EXCLUDED.metadata,
            updated_at = NOW()
    """
    rows = []
    for c in chunks:
        emb_str = "[" + ",".join(str(v) for v in c["embedding"]) + "]"
        rows.append((
            c["concept_key"],
            c["concept_number"],
            c["name_pl"],
            c["name_en"],
            c["chunk_type"],
            c["content"],
            emb_str,
            json.dumps(c["metadata"], ensure_ascii=False),
        ))

    with conn.cursor() as cur:
        execute_values(cur, sql, rows, page_size=50)
    conn.commit()
    print(f"[OK] Upsert: {len(rows)} chunków.")


def verify(conn):
    """KROK 5: Weryfikacja."""
    with conn.cursor() as cur:
        cur.execute("SELECT COUNT(*) FROM encyclopedia_chunks;")
        total = cur.fetchone()[0]
        print(f"\nWeryfikacja:")
        print(f"  Chunków total: {total}")

        cur.execute("SELECT chunk_type, COUNT(*) FROM encyclopedia_chunks GROUP BY chunk_type ORDER BY chunk_type;")
        for row in cur.fetchall():
            print(f"  {row[0]}: {row[1]}")

        cur.execute("SELECT COUNT(*) FROM encyclopedia_chunks WHERE embedding IS NOT NULL;")
        with_emb = cur.fetchone()[0]
        print(f"  Z embeddingami: {with_emb}")

    return total


def test_query(conn, client, query, top_n=5):
    """Test semantic search."""
    resp = client.embeddings.create(model=EMBEDDING_MODEL, input=[query], dimensions=EMBEDDING_DIM)
    q_emb = resp.data[0].embedding
    q_str = "[" + ",".join(str(v) for v in q_emb) + "]"

    with conn.cursor() as cur:
        cur.execute("""
            SELECT concept_key, chunk_type, name_pl,
                   1 - (embedding <=> %s::vector) as similarity
            FROM encyclopedia_chunks
            ORDER BY embedding <=> %s::vector
            LIMIT %s;
        """, (q_str, q_str, top_n))
        results = cur.fetchall()

    print(f"\nQuery: \"{query}\"")
    for i, (key, ctype, name, sim) in enumerate(results, 1):
        print(f"  {i}. {key} [{ctype}] ({name}) → sim={sim:.4f}")

    return results


def write_report(total_chunks, total_tokens, cost, elapsed, test_results):
    """KROK 6: Raport."""
    REPORT_PATH.parent.mkdir(parents=True, exist_ok=True)
    lines = [
        "# TASK-ENC-012: Embedding Report",
        "",
        f"- Chunków total: {total_chunks}",
        f"- Embedding model: {EMBEDDING_MODEL}",
        f"- Dimensions: {EMBEDDING_DIM}",
        f"- Koszt embedding: ${cost:.4f} ({total_tokens} tokenów)",
        f"- Czas embedding: {elapsed:.1f}s",
        "",
        "## Wyniki testowych query",
        "",
    ]

    for query, results in test_results:
        lines.append(f"### \"{query}\"")
        lines.append("")
        lines.append("| # | concept_key | chunk_type | name_pl | similarity |")
        lines.append("|---|------------|-----------|---------|-----------|")
        for i, (key, ctype, name, sim) in enumerate(results, 1):
            lines.append(f"| {i} | {key} | {ctype} | {name} | {sim:.4f} |")
        lines.append("")

    with open(REPORT_PATH, "w", encoding="utf-8") as f:
        f.write("\n".join(lines))
    print(f"\n[OK] Raport zapisany: {REPORT_PATH}")


def main():
    parser = argparse.ArgumentParser(description="Embed encyclopedia to pgvector")
    parser.add_argument("--mode", choices=["full", "single", "test-query"], default="full")
    parser.add_argument("--concept", help="Concept key dla trybu single")
    parser.add_argument("--query", help="Query dla trybu test-query")
    args = parser.parse_args()

    if args.mode == "test-query":
        if not args.query:
            print("[BŁĄD] Podaj --query")
            sys.exit(1)
        conn = get_db_connection()
        client = get_openai_client()
        test_query(conn, client, args.query, top_n=10)
        conn.close()
        return

    if args.mode == "single" and not args.concept:
        print("[BŁĄD] Podaj --concept dla trybu single")
        sys.exit(1)

    conn = get_db_connection()
    client = get_openai_client()

    # Krok 1: Tabela
    create_table(conn)

    # Krok 2: Chunking
    concept_key = args.concept if args.mode == "single" else None
    entries = load_entries(concept_key)
    chunks = build_chunks(entries)

    # Krok 3: Embedding
    total_tokens, cost, elapsed = embed_chunks(client, chunks)

    # Krok 4: Upsert
    upsert_chunks(conn, chunks)

    # Indeks IVFFlat (po wstawieniu danych)
    if args.mode == "full":
        create_vector_index(conn)

    # Krok 5: Weryfikacja
    total_chunks = verify(conn)

    # Krok 5b: Test query
    test_queries = [
        "jaki automat do nurkowania wybrać",
        "suchy skafander ocieplacz",
        "bojka dekompresyjna szpulka",
    ]
    test_results = []
    for q in test_queries:
        results = test_query(conn, client, q, top_n=5)
        test_results.append((q, results))

    # Krok 6: Raport
    write_report(total_chunks, total_tokens, cost, elapsed, test_results)

    conn.close()
    print("\n[DONE] TASK-ENC-012 zakończony.")


if __name__ == "__main__":
    main()
