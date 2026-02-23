#!/usr/bin/env python3
"""
Ładuje synonimy nurkowe z pliku JSON do tabeli divechat_synonyms w PostgreSQL.

Użycie:
  python load_synonyms.py                              # domyślny plik curated
  python load_synonyms.py --file path/to/synonyms.json
  python load_synonyms.py --dry-run                    # tylko pokaż co załaduje
  python load_synonyms.py --drop                       # wyczyść tabelę przed ładowaniem
"""

import argparse
import json
import sys
from pathlib import Path

import psycopg2
from dotenv import load_dotenv
import os

# Ładowanie .env z roota projektu
PROJECT_ROOT = Path(__file__).resolve().parent.parent
load_dotenv(PROJECT_ROOT / ".env")

DEFAULT_FILE = PROJECT_ROOT / "data" / "synonyms" / "diving_synonyms_curated.json"
MIGRATION_FILE = PROJECT_ROOT / "sql" / "003_create_synonyms_table.sql"


def get_db_connection():
    url = os.getenv("DATABASE_URL")
    if not url:
        print("BŁĄD: Brak DATABASE_URL w .env")
        sys.exit(1)
    return psycopg2.connect(url)


def ensure_table(conn):
    """Upewnij się, że tabela istnieje (uruchom migrację jeśli trzeba)."""
    cur = conn.cursor()
    cur.execute(
        "SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_name = 'divechat_synonyms')"
    )
    exists = cur.fetchone()[0]
    cur.close()

    if not exists:
        print(f"Tabela nie istnieje, uruchamiam migrację: {MIGRATION_FILE.name}")
        with open(MIGRATION_FILE, "r", encoding="utf-8") as f:
            sql = f.read()
        cur = conn.cursor()
        cur.execute(sql)
        conn.commit()
        cur.close()
        print("Tabela divechat_synonyms utworzona.")
    else:
        print("Tabela divechat_synonyms już istnieje.")


def load_synonyms(conn, synonyms_file: Path, drop: bool = False, dry_run: bool = False):
    """Załaduj synonimy z JSON do tabeli."""
    with open(synonyms_file, "r", encoding="utf-8") as f:
        groups = json.load(f)

    # Przygotuj wiersze: (canonical_term, synonym, language, category)
    rows = []
    for group in groups:
        canonical = group["canonical"]
        category = group.get("notes", None)
        for syn in group["synonyms"]:
            lang = "en" if all(ord(c) < 128 for c in syn) else "pl"
            rows.append((canonical, syn, lang, category))

    print(f"Plik: {synonyms_file.name}")
    print(f"Grup synonimów: {len(groups)}")
    print(f"Wierszy do załadowania: {len(rows)}")

    if dry_run:
        print("\n--- DRY RUN (nic nie zapisano) ---")
        for canonical, syn, lang, cat in rows:
            print(f"  [{lang}] {canonical} → {syn}")
        return

    cur = conn.cursor()

    if drop:
        cur.execute("DELETE FROM divechat_synonyms")
        print(f"Wyczyszczono tabelę divechat_synonyms.")

    inserted = 0
    skipped = 0
    for canonical, syn, lang, cat in rows:
        try:
            cur.execute(
                """
                INSERT INTO divechat_synonyms (canonical_term, synonym, language, category)
                VALUES (%s, %s, %s, %s)
                ON CONFLICT (canonical_term, synonym) DO NOTHING
                """,
                (canonical, syn, lang, cat),
            )
            if cur.rowcount > 0:
                inserted += 1
            else:
                skipped += 1
        except Exception as e:
            print(f"  BŁĄD: {canonical} → {syn}: {e}")
            conn.rollback()
            raise

    conn.commit()
    cur.close()

    print(f"\nWynik: {inserted} dodanych, {skipped} pominięte (duplikaty)")

    # Weryfikacja
    cur = conn.cursor()
    cur.execute("SELECT COUNT(*) FROM divechat_synonyms")
    total = cur.fetchone()[0]
    cur.execute("SELECT COUNT(DISTINCT canonical_term) FROM divechat_synonyms")
    groups_count = cur.fetchone()[0]
    cur.close()

    print(f"Stan tabeli: {total} wierszy, {groups_count} grup")


def main():
    parser = argparse.ArgumentParser(description="Załaduj synonimy nurkowe do PostgreSQL")
    parser.add_argument("--file", type=str, default=str(DEFAULT_FILE), help="Plik JSON z synonimami")
    parser.add_argument("--drop", action="store_true", help="Wyczyść tabelę przed ładowaniem")
    parser.add_argument("--dry-run", action="store_true", help="Tylko pokaż co załaduje")
    args = parser.parse_args()

    synonyms_file = Path(args.file)
    if not synonyms_file.exists():
        print(f"BŁĄD: Plik nie istnieje: {synonyms_file}")
        sys.exit(1)

    conn = get_db_connection()
    ensure_table(conn)
    load_synonyms(conn, synonyms_file, drop=args.drop, dry_run=args.dry_run)
    conn.close()


if __name__ == "__main__":
    main()
