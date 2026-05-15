"""
T-010: D1 ETL — parent_category_name z pr_category + tabela aliasów + override.

Idempotentny. Czyta hierarchię pr_category z MySQL, dla każdego produktu:
  1. Strategia B (level_depth=2 ancestor)
  2. Edge case override (level=3 cat z whitelist override → użyj level=3 name)
  3. Alias lookup (normalized PS name → NAZEWNICTWO SKLEPU)
  4. Blacklist intencjonalnych NULL (vouchery, price buckets, segmenty)
  5. Conditional UPDATE (IS DISTINCT FROM) → liczy updated rows

Powiązany ADR: ADR-057. Zastępuje D2-hybrid (sql/010_pseudocategory_mapping.sql).

Użycie:
  python etl_d1_parent_category.py --dry-run    # tylko log decyzji per produkt, bez UPDATE
  python etl_d1_parent_category.py              # full ETL
"""

from __future__ import annotations

import argparse
import logging
import os
import subprocess
import sys
import time
import unicodedata
from collections import Counter, defaultdict
from pathlib import Path
from typing import Any

import psycopg2
import pymysql
from dotenv import load_dotenv

load_dotenv(Path(__file__).resolve().parent.parent / ".env")
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
logger = logging.getLogger(__name__)

LOCAL_MYSQL_PORT = 33060
SHOP_HOME_DEPTH = 1  # "Główna" cat — virtual root for shop

# Strategia B picks level=2 (top-level shop category). Niższe poziomy są
# zbyt szczegółowe (np. brand subcat), wyższe to "Główna" root.
STRATEGY_B_DEPTH = 2

# Edge case override: gdy Strategia B wskaże dany model_facing_name jako parent,
# a produkt ma równolegle level=3 cat w wymienionym zbiorze nazw — użyj tej
# level=3 nazwy zamiast nadkategorii (per ADR-057 pkt 2).
# Klucze: nazwy PS po Strategii B (przed alias lookup — bo override patrzy na PS tree).
EDGE_CASE_OVERRIDES: dict[str, set[str]] = {
    "Skafandry mokre": {"Kaptury", "Rękawice", "Buty"},
    "Skafandry suche": {"Ocieplacze do Suchych"},
}

# Blacklist intencjonalnych NULL (per ADR-057 pkt 3, normalized = lower+unaccent).
INTENTIONAL_NULL_CATEGORIES: set[str] = {
    "vouchery prezentowe", "voucher",
    "do 100 pln", "od 100 do 500 pln", "od 500 do 1000 pln", "powyzej 1000 pln",
    "prezenty", "prezent",
    "wyprzedaze", "wyprzedaz", "outlet",
    "morsowanie",
    "dla dzieci i juniorow",
}


# Polskie litery które NIE rozkładają się w NFD (są pojedynczymi codepointami).
# Trzeba zrobić ręczny mapping żeby Python normalize() == PG lower(unaccent()).
_MANUAL_TRANSLIT = str.maketrans({
    "ł": "l", "Ł": "L",
    "ø": "o", "Ø": "O",
    "ð": "d", "Ð": "D",
    "þ": "th", "Þ": "Th",
    "đ": "d", "Đ": "D",
})


def normalize(s: str | None) -> str:
    """Pythonowy odpowiednik PG `lower(unaccent(s))`. NFD strip + manual translit
    dla liter bez NFD decomposition (ł, ø, đ itp.)."""
    if not s:
        return ""
    s = s.translate(_MANUAL_TRANSLIT)
    nfd = unicodedata.normalize("NFD", s)
    no_diacritic = "".join(c for c in nfd if unicodedata.category(c) != "Mn")
    return no_diacritic.casefold().strip()


# ============================================================================
# Połączenia (wzorzec z audit_d1_pr_category.py)
# ============================================================================

def open_ssh_tunnel() -> None:
    subprocess.run(["pkill", "-f", f"ssh.*{LOCAL_MYSQL_PORT}.*divezonededyk"], capture_output=True)
    time.sleep(0.5)
    cmd = [
        "ssh",
        "-i", os.getenv("SSH_KEY_PATH", "/Users/karol/.ssh/id_ed25519"),
        "-p", os.getenv("SSH_PORT", "5739"),
        "-L", f"{LOCAL_MYSQL_PORT}:127.0.0.1:3306",
        "-f", "-N",
        "-o", "StrictHostKeyChecking=no",
        "-o", "ConnectTimeout=10",
        f"{os.getenv('SSH_USER', 'divezone')}@{os.getenv('SSH_HOST', 'divezonededyk.smarthost.pl')}",
    ]
    r = subprocess.run(cmd, capture_output=True, text=True)
    if r.returncode != 0:
        raise ConnectionError(f"SSH tunnel failed: {r.stderr}")
    logger.info("SSH tunnel open na localhost:%d", LOCAL_MYSQL_PORT)
    time.sleep(1)


def close_ssh_tunnel() -> None:
    subprocess.run(["pkill", "-f", f"ssh.*{LOCAL_MYSQL_PORT}.*divezonededyk"], capture_output=True)
    logger.info("SSH tunnel closed")


def mysql_conn():
    return pymysql.connect(
        host="127.0.0.1", port=LOCAL_MYSQL_PORT,
        user=os.getenv("DB_USER"), password=os.getenv("DB_PASSWORD"),
        database=os.getenv("DB_NAME_PROD", "divezone_2025"),
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
    )


def pg_conn():
    url = os.getenv("DATABASE_URL")
    if not url:
        raise RuntimeError("Brak DATABASE_URL w .env")
    return psycopg2.connect(url)


# ============================================================================
# Load drzewa + aliasów
# ============================================================================

def build_category_tree(mysql_cur) -> dict[int, dict[str, Any]]:
    """Zwraca id_category -> {name, id_parent, level_depth, active, ancestors}."""
    mysql_cur.execute("""
        SELECT c.id_category, c.id_parent, c.level_depth, c.active, cl.name
        FROM pr_category c
        JOIN pr_category_lang cl ON cl.id_category = c.id_category AND cl.id_lang = 1
    """)
    tree: dict[int, dict[str, Any]] = {}
    for r in mysql_cur.fetchall():
        tree[r["id_category"]] = {
            "id_category": r["id_category"],
            "name": r["name"],
            "id_parent": r["id_parent"],
            "level_depth": r["level_depth"],
            "active": bool(r["active"]),
        }
    # Buduj ancestors_chain (od korzenia do parenta, bez siebie)
    for cid in tree:
        chain: list[int] = []
        cur_id = tree[cid]["id_parent"]
        seen: set[int] = set()
        while cur_id and cur_id != 0 and cur_id not in seen:
            seen.add(cur_id)
            chain.append(cur_id)
            parent = tree.get(cur_id)
            if parent is None:
                break
            cur_id = parent["id_parent"]
        tree[cid]["ancestors"] = list(reversed(chain))
    return tree


def load_aliases(pg_cur) -> dict[str, str]:
    """{normalized_ps_name -> model_facing_name}. Klucz to ps_name_normalized
    z tabeli (już znormalizowany lower(unaccent(...)) w migracji 012)."""
    pg_cur.execute("SELECT ps_name_normalized, model_facing_name FROM divechat_category_aliases")
    return {row[0]: row[1] for row in pg_cur.fetchall()}


def fetch_product_categories(mysql_cur, product_ids: list[int]) -> dict[int, list[int]]:
    """Zwraca {id_product: [id_category, ...]}. Pobiera batchowo."""
    out: dict[int, list[int]] = defaultdict(list)
    batch = 1000
    for i in range(0, len(product_ids), batch):
        chunk = product_ids[i:i + batch]
        ids_str = ",".join(str(x) for x in chunk)
        mysql_cur.execute(f"""
            SELECT id_product, id_category
            FROM pr_category_product
            WHERE id_product IN ({ids_str})
        """)
        for r in mysql_cur.fetchall():
            out[r["id_product"]].append(r["id_category"])
    return dict(out)


def fetch_pg_products(pg_cur) -> list[dict[str, Any]]:
    pg_cur.execute("""
        SELECT ps_product_id, product_name, category_name, parent_category_name
        FROM divechat_product_embeddings
        ORDER BY ps_product_id
    """)
    return [
        {
            "ps_product_id": r[0],
            "product_name": r[1] or "",
            "category_name": r[2] or "",
            "current_parent": r[3],
        }
        for r in pg_cur.fetchall()
    ]


# ============================================================================
# Strategia B + override + alias + blacklist
# ============================================================================

def strategy_b_with_levels(tree: dict, prod_cats: list[int]) -> tuple[str | None, set[str]]:
    """Zwraca (parent_name_per_strategy_B, level3_names_set).

    parent_name: kandydat na parent (level=2 ancestor, most common z product's cats).
    level3_names: nazwy active cats produktu na level=3 (do edge override).
    """
    candidates: list[str] = []
    level3_names: set[str] = set()
    for cid in prod_cats:
        node = tree.get(cid)
        if node is None or not node["active"]:
            continue
        if node["level_depth"] < STRATEGY_B_DEPTH:
            continue  # Główna (1) i root — nie są kandydatami
        if node["level_depth"] == 3:
            level3_names.add(node["name"])
        # Znajdź ancestor na level=2 (lub samego siebie jeśli już na 2)
        if node["level_depth"] == STRATEGY_B_DEPTH:
            candidates.append(node["name"])
        else:
            for aid in node["ancestors"]:
                anode = tree.get(aid)
                if anode and anode["level_depth"] == STRATEGY_B_DEPTH and anode["active"]:
                    candidates.append(anode["name"])
                    break
    if not candidates:
        return None, level3_names
    counter = Counter(candidates)
    max_count = max(counter.values())
    top = sorted([n for n, c in counter.items() if c == max_count])
    return top[0], level3_names


def apply_edge_override(parent_name: str, level3_names: set[str]) -> str:
    """Zwraca level=3 name z override jeśli pasuje, inaczej oryginalny parent."""
    if parent_name not in EDGE_CASE_OVERRIDES:
        return parent_name
    allowed_overrides = EDGE_CASE_OVERRIDES[parent_name]
    matches = sorted(level3_names & allowed_overrides)
    if matches:
        return matches[0]  # deterministyczne (alfabetyczne)
    return parent_name


def apply_alias(name: str, aliases: dict[str, str]) -> str:
    """Lookup w alias table po znormalizowanej nazwie. Brak aliasu → zwraca oryginał."""
    normalized = normalize(name)
    return aliases.get(normalized, name)


def apply_blacklist(name: str) -> str | None:
    """None jeśli name jest w INTENTIONAL_NULL_CATEGORIES (znormalizowany)."""
    if normalize(name) in INTENTIONAL_NULL_CATEGORIES:
        return None
    return name


def compute_parent(prod_cats: list[int], tree: dict, aliases: dict[str, str]
                    ) -> tuple[str | None, dict[str, Any]]:
    """Pełny pipeline: Strategia B → override → alias → blacklist.

    Zwraca (final_value_or_None, debug_dict z etapami).
    """
    debug: dict[str, Any] = {
        "stage_b": None,
        "override_fired": False,
        "stage_after_override": None,
        "alias_fired": False,
        "stage_after_alias": None,
        "blacklisted": False,
    }
    stage_b, level3 = strategy_b_with_levels(tree, prod_cats)
    debug["stage_b"] = stage_b
    if stage_b is None:
        return None, debug

    after_override = apply_edge_override(stage_b, level3)
    debug["override_fired"] = (after_override != stage_b)
    debug["stage_after_override"] = after_override

    after_alias = apply_alias(after_override, aliases)
    debug["alias_fired"] = (after_alias != after_override)
    debug["stage_after_alias"] = after_alias

    after_blacklist = apply_blacklist(after_alias)
    if after_blacklist is None:
        debug["blacklisted"] = True
        return None, debug

    return after_blacklist, debug


# ============================================================================
# UPDATE PG (idempotentny)
# ============================================================================

def update_pg(pg_cur, ps_product_id: int, new_value: str | None) -> bool:
    """Conditional UPDATE — IS DISTINCT FROM. Zwraca True jeśli wiersz został zmieniony."""
    pg_cur.execute(
        """UPDATE divechat_product_embeddings
           SET parent_category_name = %s, updated_at = NOW()
           WHERE ps_product_id = %s
             AND parent_category_name IS DISTINCT FROM %s""",
        (new_value, ps_product_id, new_value),
    )
    return pg_cur.rowcount > 0


# ============================================================================
# Main
# ============================================================================

def main() -> int:
    parser = argparse.ArgumentParser(description="T-010 D1 ETL parent_category_name")
    parser.add_argument("--dry-run", action="store_true",
                         help="Symuluj bez UPDATE; loguj per-produkt decyzje")
    parser.add_argument("--verbose", action="store_true",
                         help="Loguj decyzję per produkt nawet w trybie pełnym")
    args = parser.parse_args()

    open_ssh_tunnel()
    try:
        my = mysql_conn()
        mcur = my.cursor()
        tree = build_category_tree(mcur)
        logger.info("Tree built: %d cats", len(tree))

        pg = pg_conn()
        pcur = pg.cursor()
        aliases = load_aliases(pcur)
        logger.info("Aliases loaded: %d", len(aliases))

        products = fetch_pg_products(pcur)
        logger.info("PG products: %d", len(products))

        product_ids = [p["ps_product_id"] for p in products]
        product_cats = fetch_product_categories(mcur, product_ids)
        logger.info("Categories fetched dla %d produktów", len(product_cats))

        mcur.close()
        my.close()

        # Liczniki
        updated = 0
        unchanged = 0
        final_null = 0
        final_non_null = 0
        alias_hits = 0
        override_hits = 0
        blacklisted = 0
        no_strategy_b = 0
        alias_usage: Counter = Counter()
        override_usage: Counter = Counter()
        blacklist_usage: Counter = Counter()
        null_to_something_groups: Counter = Counter()
        per_strategy_target: Counter = Counter()
        changes_diff: Counter = Counter()  # (current_parent, new_parent) -> count

        for p in products:
            pid = p["ps_product_id"]
            cats = product_cats.get(pid, [])
            new_value, debug = compute_parent(cats, tree, aliases)

            if debug["stage_b"] is None:
                no_strategy_b += 1
            if debug["alias_fired"]:
                alias_hits += 1
                alias_usage[(debug["stage_after_override"], debug["stage_after_alias"])] += 1
            if debug["override_fired"]:
                override_hits += 1
                override_usage[(debug["stage_b"], debug["stage_after_override"])] += 1
            if debug["blacklisted"]:
                blacklisted += 1
                blacklist_usage[normalize(debug["stage_after_alias"])] += 1

            if new_value is None:
                final_null += 1
            else:
                final_non_null += 1
                per_strategy_target[new_value] += 1

            current = p["current_parent"]
            if current is None and new_value is not None:
                null_to_something_groups[new_value] += 1
            if current != new_value:
                changes_diff[(current, new_value)] += 1

            if args.dry_run:
                continue

            if update_pg(pcur, pid, new_value):
                updated += 1
            else:
                unchanged += 1

        if not args.dry_run:
            pg.commit()

        # Stdout summary
        print()
        print("=" * 78)
        print("T-010 D1 ETL — PODSUMOWANIE")
        print(f"Mode: {'DRY-RUN' if args.dry_run else 'FULL UPDATE'}")
        print("=" * 78)
        total = len(products)
        print(f"Total produktów: {total}")
        print(f"Bez Strategii B (brak active cat level>=2): {no_strategy_b}")
        print(f"Final NULL (intencjonalne lub no-strategy): {final_null}")
        print(f"Final non-NULL: {final_non_null}")
        print(f"Alias hits: {alias_hits}")
        print(f"Override hits: {override_hits}")
        print(f"Blacklisted (set NULL by intentional rule): {blacklisted}")
        if not args.dry_run:
            print(f"UPDATED rows (conditional IS DISTINCT FROM): {updated}")
            print(f"UNCHANGED rows: {unchanged}")
        nullify_count = sum(null_to_something_groups.values())
        print(f"NULL → coś (current NULL → new non-NULL): {nullify_count}")

        print("\nTOP 30 final parent_category_name distribution:")
        for name, c in per_strategy_target.most_common(30):
            print(f"  {c:5} | {name}")

        print("\nAlias hits per (PS_name → model_name):")
        for (ps, model), c in alias_usage.most_common():
            print(f"  {c:5} | {ps}  →  {model}")

        if override_usage:
            print("\nOverride hits per (parent → level3):")
            for (par, lvl3), c in override_usage.most_common():
                print(f"  {c:5} | {par}  →  {lvl3}")

        if blacklist_usage:
            print("\nBlacklisted (intentional NULL) per normalized name:")
            for norm, c in blacklist_usage.most_common():
                print(f"  {c:5} | {norm}")

        if null_to_something_groups:
            print("\nNULL → coś (per group):")
            for name, c in null_to_something_groups.most_common():
                print(f"  {c:5} | {name}")

        print("\nTOP 20 zmian (current → new):")
        for (cur_p, new_p), c in changes_diff.most_common(20):
            cur_s = cur_p if cur_p is not None else "(NULL)"
            new_s = new_p if new_p is not None else "(NULL)"
            print(f"  {c:5} | {cur_s}  →  {new_s}")

        pcur.close()
        pg.close()
        return 0
    finally:
        close_ssh_tunnel()


if __name__ == "__main__":
    sys.exit(main())
