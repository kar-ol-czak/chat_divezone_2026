#!/usr/bin/env python3
"""CLI: pipeline generacji encyklopedii sprzetu nurkowego.

Uzycie:
    python run.py --group C                # generacja + walidacja grupy C
    python run.py --group C --dry-run      # pokaz prompt bez wysylania
    python run.py --group C --gen-only     # tylko generacja (bez walidacji)
    python run.py --group C --val-only     # tylko walidacja (uzyj istniejacego original)
    python run.py --group C --retry        # powtorz z nowym seedem
"""

import argparse
import logging
import sys

from pipeline import Pipeline
from group_metadata import GROUP_NAMES


def main():
    parser = argparse.ArgumentParser(
        description="Pipeline generacji encyklopedii sprzetu nurkowego"
    )
    parser.add_argument(
        "--group",
        required=True,
        type=str,
        help="Litera grupy (A-M) lub 'all' (wylaczony)",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Generuj prompt bez wysylania do API",
    )
    parser.add_argument(
        "--gen-only",
        action="store_true",
        help="Tylko generacja (bez walidacji)",
    )
    parser.add_argument(
        "--val-only",
        action="store_true",
        help="Tylko walidacja (uzyj istniejacego original.json)",
    )
    parser.add_argument(
        "--retry",
        action="store_true",
        help="Powtorz generacje z nowym seedem",
    )

    args = parser.parse_args()

    # Konfiguracja logowania
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s [%(levelname)s] %(message)s",
        datefmt="%H:%M:%S",
    )

    group = args.group.upper()

    # Walidacja --group all
    if group == "ALL":
        print("BLAD: tryb --group all jest WYLACZONY.")
        print("Workflow z gate'ami wymaga review po kazdej grupie.")
        print("Uzyj: python run.py --group C, potem review, potem --group D itd.")
        sys.exit(1)

    # Walidacja grupy
    if group not in GROUP_NAMES:
        print(f"BLAD: nieznana grupa '{group}'. Dostepne: {', '.join(sorted(GROUP_NAMES.keys()))}")
        sys.exit(1)

    # Walidacja konfliktow flag
    if args.gen_only and args.val_only:
        print("BLAD: nie mozna uzyc --gen-only i --val-only jednoczesnie")
        sys.exit(1)

    if args.dry_run and args.val_only:
        print("BLAD: --dry-run nie ma sensu z --val-only")
        sys.exit(1)

    # Uruchom pipeline
    pipeline = Pipeline()
    pipeline.run(
        group_id=group,
        dry_run=args.dry_run,
        gen_only=args.gen_only,
        val_only=args.val_only,
        retry=args.retry,
    )


if __name__ == "__main__":
    main()
