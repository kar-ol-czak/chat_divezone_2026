#!/usr/bin/env python3
"""
sql.py — SQL na Railway PG bez pulapki apostrofow.

Powstal 2026-07-17 (_docs/46): zapytania diagnostyczne byly pisane od zera przy
kazdej sesji, przez heredoc + SSH, gdzie apostrofy gina w lancuchu
SSH -> zsh -> bash -> psql. Ten skrypt laczy sie bezposrednio przez psycopg2,
wiec problem nie istnieje.

Uzycie:
    python3 sql.py -c "SELECT count(*) FROM divechat_conversations"
    python3 sql.py --file zapytanie.sql
    cat zapytanie.sql | python3 sql.py
    python3 sql.py -c "SELECT ..." --csv          # do dalszej obrobki
    python3 sql.py -c "UPDATE ..." --write        # zapis WYMAGA jawnej flagi

Domyslnie READ-ONLY: bez --write transakcja jest wycofywana (ROLLBACK), wiec
SELECT dziala, a przypadkowy UPDATE/DELETE nie zostawi sladu.
"""
from __future__ import annotations

import argparse
import csv
import sys

from _conn import connect


def _render(cur) -> None:
    """Wypisuje wynik jako tabelke (kolumny wyrownane)."""
    if cur.description is None:
        print(f"OK, wierszy dotknietych: {cur.rowcount}")
        return

    cols = [d[0] for d in cur.description]
    rows = cur.fetchall()
    if not rows:
        print("(0 wierszy)")
        return

    widths = [len(c) for c in cols]
    for row in rows:
        for i, val in enumerate(row):
            widths[i] = max(widths[i], len(str(val)))

    print(" | ".join(c.ljust(widths[i]) for i, c in enumerate(cols)))
    print("-+-".join("-" * w for w in widths))
    for row in rows:
        print(" | ".join(str(v).ljust(widths[i]) for i, v in enumerate(row)))
    print(f"\n({len(rows)} wierszy)")


def _render_csv(cur) -> None:
    if cur.description is None:
        return
    writer = csv.writer(sys.stdout)
    writer.writerow([d[0] for d in cur.description])
    writer.writerows(cur.fetchall())


def main() -> int:
    parser = argparse.ArgumentParser(description="SQL na Railway PG (domyslnie read-only).")
    src = parser.add_mutually_exclusive_group()
    src.add_argument("-c", "--command", help="zapytanie inline")
    src.add_argument("--file", help="plik z SQL")
    parser.add_argument("--csv", action="store_true", help="wynik jako CSV")
    parser.add_argument(
        "--write",
        action="store_true",
        help="ZATWIERDZA zmiany (COMMIT). Bez tego wszystko jest wycofywane.",
    )
    args = parser.parse_args()

    if args.command:
        sql = args.command
    elif args.file:
        with open(args.file, encoding="utf-8") as fh:
            sql = fh.read()
    elif not sys.stdin.isatty():
        sql = sys.stdin.read()
    else:
        parser.error("podaj -c, --file albo SQL na stdin")

    if not sql.strip():
        parser.error("puste zapytanie")

    conn = connect()
    # UWAGA: _conn.connect() ustawia autocommit=True. Przy autocommit rollback()
    # nie ma czego cofac — kazdy UPDATE zapisalby sie natychmiast. Wylaczamy je,
    # zeby guard --write byl realny, a nie pozorny.
    conn.autocommit = False
    try:
        with conn.cursor() as cur:
            cur.execute(sql)
            _render_csv(cur) if args.csv else _render(cur)

        if args.write:
            conn.commit()
            print("\n[COMMIT — zmiany zatwierdzone]", file=sys.stderr)
        else:
            conn.rollback()
    finally:
        conn.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
