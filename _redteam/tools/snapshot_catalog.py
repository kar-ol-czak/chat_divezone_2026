"""
snapshot_catalog.py — deterministyczny dump katalogu DiveChat dla red-team ground truth.

Cel (ADR-060, T-021):
- Sędzia LLM nie wie, co jest w katalogu. Bez snapshotu klasa "halucynacje produktowe" to zgadywanie.
- Snapshot odzwierciedla widok katalogu, jaki bot ma przez ProductSearch.enrichWithMySQLData
  w momencie T → reference answer dla rubryki halucynacji.

Źródła:
- PostgreSQL (divechat_product_embeddings) — metadane: nazwa, brand, kategoria, parent, cena bazowa.
- MySQL PrestaShop (pr_product_shop + pr_stock_available + pr_specific_price)
  — real-time: active, visibility, in_stock/available_to_order/unavailable, cena z promo.

Wyłączenia (PII / RODO):
- Zero danych z pr_customer, pr_orders, pr_address.
- Tylko katalog produktów + statusy.

Użycie:
    # Dump (wymaga DATABASE_URL + MYSQL_* w .env projektu)
    python tools/snapshot_catalog.py --output fixtures/catalog_snapshot_$(date +%Y-%m-%d).json

    # Walidacja istniejącego snapshotu (case 90 + case 91)
    python tools/snapshot_catalog.py --validate fixtures/catalog_snapshot_2026-05-26.json
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import os
import subprocess
import sys
from pathlib import Path
from typing import Any

try:
    import psycopg2
    import psycopg2.extras
    import pymysql
    from dotenv import load_dotenv
except ImportError as exc:
    sys.stderr.write(
        f"Brak zależności ({exc}). Zainstaluj: pip install psycopg2-binary pymysql python-dotenv\n"
    )
    raise

PROJECT_ROOT = Path(__file__).resolve().parents[2]
ENV_PATH = PROJECT_ROOT / ".env"

# Case 90: Crystal Vu (panoramiczne maski / zestawy). Realne ID z testów manualnych.
CASE_90_IDS = {7316, 7442, 4926}
CASE_90_NAME_TOKEN = "crystal vu"
CASE_90_EXPECTED_CATEGORIES = {
    "maski panoramiczne",
    "zestawy maska+fajka",
    "zestawy maska + fajka",
}

# Case 91: SANTI BZ400 ocieplacz (mylony przez bota z poliestrowym BZ100).
CASE_91_NAME_TOKEN = "bz400"
CASE_91_BRAND_TOKEN = "santi"


def load_env() -> None:
    if ENV_PATH.exists():
        load_dotenv(ENV_PATH)
    else:
        sys.stderr.write(f"UWAGA: brak {ENV_PATH} — używam zmiennych środowiska.\n")


def connect_pg():
    url = os.getenv("DATABASE_URL")
    if not url:
        raise RuntimeError("Brak DATABASE_URL w środowisku.")
    return psycopg2.connect(url)


def connect_mysql():
    cfg = {
        "host": os.getenv("MYSQL_HOST", "localhost"),
        "port": int(os.getenv("MYSQL_PORT", "3306")),
        "user": os.getenv("MYSQL_USER", ""),
        "password": os.getenv("MYSQL_PASSWORD", ""),
        "database": os.getenv("MYSQL_DATABASE", ""),
        "charset": "utf8mb4",
        "cursorclass": pymysql.cursors.DictCursor,
    }
    if not cfg["user"] or not cfg["database"]:
        raise RuntimeError("Brak MYSQL_USER / MYSQL_DATABASE w środowisku.")
    return pymysql.connect(**cfg)


def fetch_pg_catalog(pg_conn) -> dict[int, dict[str, Any]]:
    """Metadane wszystkich aktywnych produktów z divechat_product_embeddings."""
    with pg_conn.cursor(cursor_factory=psycopg2.extras.DictCursor) as cur:
        cur.execute(
            """
            SELECT ps_product_id, product_name, brand_name,
                   category_name, parent_category_name,
                   price, in_stock, is_active
            FROM divechat_product_embeddings
            WHERE is_active = TRUE
            ORDER BY ps_product_id
            """
        )
        return {
            int(r["ps_product_id"]): {
                "ps_product_id": int(r["ps_product_id"]),
                "product_name": r["product_name"],
                "brand_name": r["brand_name"],
                "category_name": r["category_name"],
                "parent_category_name": r["parent_category_name"],
                "pg_price": float(r["price"]) if r["price"] is not None else None,
                "pg_in_stock": bool(r["in_stock"]),
                "pg_is_active": bool(r["is_active"]),
            }
            for r in cur.fetchall()
        }


def fetch_mysql_state(mysql_conn, product_ids: list[int]) -> dict[int, dict[str, Any]]:
    """
    Real-time stan z PrestaShop: active, visibility, availability, cena netto+brutto.
    Logika 1:1 z standalone/src/Tools/ProductSearch.php::enrichWithMySQLData (CASE availability).
    """
    if not product_ids:
        return {}

    with mysql_conn.cursor() as cur:
        cur.execute(
            "SELECT value FROM pr_configuration WHERE name = 'PS_ORDER_OUT_OF_STOCK' LIMIT 1"
        )
        row = cur.fetchone()
        global_allow_oos = int(row["value"]) if row else 0

        placeholders = ",".join(["%s"] * len(product_ids))

        cur.execute(
            f"""
            SELECT
                p.id_product,
                ps.price AS price_netto,
                COALESCE(t.rate, 23) AS tax_rate,
                COALESCE(sa.total_qty, 0) AS quantity,
                CASE
                    WHEN COALESCE(sa.total_qty, 0) > 0 THEN 'in_stock'
                    WHEN sa.allow_oos = 1 THEN 'available_to_order'
                    WHEN sa.allow_oos = 0 THEN 'unavailable'
                    WHEN (sa.allow_oos IS NULL OR sa.allow_oos = 2) AND %s = 1 THEN 'available_to_order'
                    ELSE 'unavailable'
                END AS availability,
                ps.active,
                ps.visibility
            FROM pr_product p
            JOIN pr_product_shop ps ON p.id_product = ps.id_product AND ps.id_shop = 1
            LEFT JOIN (
                SELECT id_product,
                       MAX(quantity) AS total_qty,
                       MAX(out_of_stock) AS allow_oos
                FROM pr_stock_available
                GROUP BY id_product
            ) sa ON p.id_product = sa.id_product
            LEFT JOIN pr_tax_rule tr ON p.id_tax_rules_group = tr.id_tax_rules_group
                AND tr.id_country = 14
            LEFT JOIN pr_tax t ON tr.id_tax = t.id_tax
            WHERE p.id_product IN ({placeholders})
            """,
            (global_allow_oos, *product_ids),
        )
        base_rows = cur.fetchall()

        cur.execute(
            f"""
            SELECT sp.id_product, sp.price AS sp_price, sp.reduction, sp.reduction_type,
                   sp.id_shop, sp.id_group
            FROM pr_specific_price sp
            WHERE sp.id_product IN ({placeholders})
              AND sp.id_shop IN (0, 1)
              AND sp.id_customer = 0
              AND sp.id_group IN (0, 1)
              AND sp.from_quantity <= 1
              AND sp.id_product_attribute = 0
              AND (sp.`from` = '0000-00-00 00:00:00' OR sp.`from` <= NOW())
              AND (sp.`to`   = '0000-00-00 00:00:00' OR sp.`to`   >= NOW())
            ORDER BY sp.id_shop DESC, sp.id_group DESC
            """,
            tuple(product_ids),
        )
        promo_rows = cur.fetchall()

    best_promo: dict[int, dict[str, Any]] = {}
    for r in promo_rows:
        pid = int(r["id_product"])
        if pid in best_promo:
            continue
        best_promo[pid] = {
            "sp_price": float(r["sp_price"]),
            "reduction": float(r["reduction"]),
            "reduction_type": r["reduction_type"],
        }

    result: dict[int, dict[str, Any]] = {}
    for r in base_rows:
        pid = int(r["id_product"])
        price_netto = float(r["price_netto"])
        tax_rate = float(r["tax_rate"])
        availability = r["availability"]
        final_netto = price_netto
        has_promo = False

        if pid in best_promo:
            sp = best_promo[pid]
            has_promo = True
            base = sp["sp_price"] if sp["sp_price"] > 0 else price_netto
            if sp["reduction"] > 0:
                if sp["reduction_type"] == "percentage":
                    final_netto = base * (1 - sp["reduction"])
                elif sp["reduction_type"] == "amount":
                    final_netto = base - sp["reduction"]
                else:
                    final_netto = base
            else:
                final_netto = base

        price_brutto = round(final_netto * (1 + tax_rate / 100), 2)
        base_brutto = round(price_netto * (1 + tax_rate / 100), 2)

        entry = {
            "price_brutto": price_brutto,
            "availability": availability,
            "in_stock": availability != "unavailable",
            "quantity": int(r["quantity"]),
            "active": bool(r["active"]),
            "visible": r["visibility"] != "none",
        }
        if has_promo and base_brutto > price_brutto:
            entry["price_before_discount"] = base_brutto
        result[pid] = entry

    return result


def git_short_sha() -> str | None:
    try:
        out = subprocess.run(
            ["git", "-C", str(PROJECT_ROOT), "rev-parse", "--short", "HEAD"],
            capture_output=True, text=True, check=True,
        )
        return out.stdout.strip()
    except (subprocess.CalledProcessError, FileNotFoundError):
        return None


def build_snapshot() -> dict[str, Any]:
    load_env()
    pg_conn = connect_pg()
    mysql_conn = connect_mysql()
    try:
        pg_data = fetch_pg_catalog(pg_conn)
        mysql_data = fetch_mysql_state(mysql_conn, list(pg_data.keys()))
    finally:
        pg_conn.close()
        mysql_conn.close()

    products = []
    for pid in sorted(pg_data.keys()):
        pg = pg_data[pid]
        ms = mysql_data.get(pid)
        active = ms["active"] if ms else pg["pg_is_active"]
        visible = ms["visible"] if ms else True

        if not active or not visible:
            # Snapshot powinien odzwierciedlać widok bota — filtruje on
            # nieaktywne / niewidoczne w runTracksAndMerge. Pomijamy.
            continue

        entry = {
            "ps_product_id": pid,
            "name": pg["product_name"],
            "brand": pg["brand_name"],
            "category_name": pg["category_name"],
            "parent_category_name": pg["parent_category_name"],
            "active": active,
            "visible": visible,
            "availability": ms["availability"] if ms else (
                "in_stock" if pg["pg_in_stock"] else "unavailable"
            ),
            "in_stock": ms["in_stock"] if ms else pg["pg_in_stock"],
            "price_brutto": ms["price_brutto"] if ms else pg["pg_price"],
        }
        if ms and "price_before_discount" in ms:
            entry["price_before_discount"] = ms["price_before_discount"]
        products.append(entry)

    return {
        "snapshot_meta": {
            "generated_at_utc": dt.datetime.now(dt.UTC).isoformat(),
            "products_count": len(products),
            "source": {
                "pg_table": "divechat_product_embeddings (is_active=true)",
                "mysql_tables": "pr_product_shop, pr_stock_available, pr_specific_price (id_shop=1)",
            },
            "filters": {
                "active": True,
                "visible": True,
                "pii": "none",
            },
            "repo_git_sha": git_short_sha(),
            "tool_version": "snapshot_catalog.py@1.0",
        },
        "products": products,
    }


def cmd_dump(output_path: Path) -> int:
    snapshot = build_snapshot()
    output_path.parent.mkdir(parents=True, exist_ok=True)
    # sort_keys + indent dla diff-friendly outputu (paired evaluation)
    output_path.write_text(
        json.dumps(snapshot, ensure_ascii=False, indent=2, sort_keys=True),
        encoding="utf-8",
    )
    meta = snapshot["snapshot_meta"]
    print(
        f"OK: zapisano {meta['products_count']} produktów do {output_path}\n"
        f"    git sha: {meta['repo_git_sha']}, generated_at: {meta['generated_at_utc']}"
    )
    return 0


def cmd_validate(input_path: Path) -> int:
    """
    Smoke walidacji T-021 KROK 2:
    - Crystal Vu (7316/7442/4926) present, kategorie Maski panoramiczne / Zestawy Maska+Fajka.
    - SANTI BZ400 (case 91) — produkt obecny po marce SANTI + tokenie BZ400 w nazwie.
    """
    data = json.loads(input_path.read_text(encoding="utf-8"))
    products = data.get("products", [])
    by_id = {int(p["ps_product_id"]): p for p in products}

    errors: list[str] = []
    warnings: list[str] = []

    # ---- Case 90: Crystal Vu ----
    missing_ids = [pid for pid in CASE_90_IDS if pid not in by_id]
    if missing_ids:
        errors.append(f"Case 90: brak ID Crystal Vu w snapshocie: {missing_ids}")
    for pid in CASE_90_IDS & by_id.keys():
        p = by_id[pid]
        name_lc = (p.get("name") or "").lower()
        if CASE_90_NAME_TOKEN not in name_lc:
            warnings.append(
                f"Case 90: ps_product_id={pid} nie zawiera '{CASE_90_NAME_TOKEN}' w nazwie "
                f"({p.get('name')!r}). Możliwa zmiana nazwy produktu."
            )
        cat_lc = (p.get("category_name") or "").lower()
        parent_lc = (p.get("parent_category_name") or "").lower()
        if not any(exp in cat_lc or exp in parent_lc for exp in CASE_90_EXPECTED_CATEGORIES):
            warnings.append(
                f"Case 90: ps_product_id={pid} kategoria '{p.get('category_name')}' / "
                f"parent '{p.get('parent_category_name')}' nie pasuje do oczekiwanych "
                f"{sorted(CASE_90_EXPECTED_CATEGORIES)}."
            )

    # ---- Case 91: SANTI BZ400 ----
    bz400_hits = [
        p for p in products
        if CASE_91_NAME_TOKEN in (p.get("name") or "").lower()
        and CASE_91_BRAND_TOKEN in (p.get("brand") or "").lower()
    ]
    if not bz400_hits:
        errors.append(
            f"Case 91: brak produktu SANTI BZ400 w snapshocie "
            f"(szukałem brand∋'{CASE_91_BRAND_TOKEN}' AND name∋'{CASE_91_NAME_TOKEN}')."
        )
    else:
        # nazwa nie powinna ostatnio zmienić "ocieplacz" → "polar/poliester" (case 91 bug)
        for p in bz400_hits:
            print(f"  Case 91 hit: id={p['ps_product_id']} name={p['name']!r} brand={p['brand']!r}")

    # ---- Wynik ----
    print(f"\nWalidacja {input_path}:")
    print(f"  produktów w snapshot: {len(products)}")
    print(f"  case 90 (Crystal Vu): {len(CASE_90_IDS & by_id.keys())}/{len(CASE_90_IDS)} ID obecnych")
    print(f"  case 91 (SANTI BZ400): {len(bz400_hits)} match(y)")

    if warnings:
        print("\nOSTRZEŻENIA:")
        for w in warnings:
            print(f"  ! {w}")
    if errors:
        print("\nBŁĘDY:")
        for e in errors:
            print(f"  X {e}")
        return 2
    print("\nOK: smoke walidacji T-021 KROK 2 przeszedł.")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description="Snapshot katalogu DiveChat (ground truth red-team).")
    parser.add_argument("--output", type=Path, help="Ścieżka pliku JSON do zapisu snapshotu.")
    parser.add_argument("--validate", type=Path, help="Waliduj istniejący snapshot (case 90 + case 91).")
    args = parser.parse_args()

    if args.validate and args.output:
        sys.stderr.write("Wybierz tylko jeden tryb: --output ALBO --validate.\n")
        return 1
    if args.validate:
        return cmd_validate(args.validate)
    if args.output:
        return cmd_dump(args.output)

    parser.print_help()
    return 1


if __name__ == "__main__":
    sys.exit(main())
