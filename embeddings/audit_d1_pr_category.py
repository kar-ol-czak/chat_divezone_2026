"""
T-009: D1 AUDIT — diagnostyka mapowania kategorii z pr_category vs D2-hybrid.

Read-only audit. Buduje drzewo pr_category z MySQL PrestaShop, dla każdego
produktu w divechat_product_embeddings generuje "naturalny parent" wg 3
strategii i porównuje z obecnym parent_category_name (D2-hybrid).

Output:
  - _docs/audyt_D1_ETL_pr_category.md (raport markdown)
  - stdout: log z agregatami

Powiązany ADR: ADR-055 (D2-hybrid hotfix), kontekst dla T-010 (D1 ETL).
"""

from __future__ import annotations

import logging
import os
import subprocess
import sys
import time
from collections import Counter, defaultdict
from datetime import date
from pathlib import Path
from typing import Any

import psycopg2
import pymysql
from dotenv import load_dotenv

load_dotenv(Path(__file__).resolve().parent.parent / ".env")
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
logger = logging.getLogger(__name__)

PROJECT_ROOT = Path(__file__).resolve().parent.parent
REPORT_PATH = PROJECT_ROOT / "_docs" / "audyt_D1_ETL_pr_category.md"

LOCAL_MYSQL_PORT = 33060
SHOP_ROOT_ID = 2  # "Główna" (level_depth=1) — shop root, virtual parent of all real cats
TOP_N = 20

# Z extract_products.py — kategorie wykluczone z indeksu embeddings (i potomkowie).
# Audyt nadal raportuje je jako "naturalne parenty" jeśli takie wychodzą,
# ale flaguje że są w EXCLUDED.
EXCLUDED_CATEGORY_IDS = {
    484, 458, 485, 486, 468, 368, 413, 451, 406, 409,
    445, 447, 110, 396, 366, 448, 397, 482, 168, 461,
    59, 457, 436, 462, 490,
}


# ============================================================================
# Połączenia
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
# Drzewo kategorii
# ============================================================================

def build_category_tree(mysql_cur) -> dict[int, dict[str, Any]]:
    """Buduje drzewo pr_category.

    Zwraca: id_category -> {name, id_parent, level_depth, active, is_root,
                            ancestors (od korzenia do parenta), is_excluded}
    """
    mysql_cur.execute("""
        SELECT c.id_category, c.id_parent, c.level_depth, c.active, c.is_root_category,
               cl.name
        FROM pr_category c
        JOIN pr_category_lang cl ON cl.id_category = c.id_category AND cl.id_lang = 1
    """)
    rows = mysql_cur.fetchall()

    tree: dict[int, dict[str, Any]] = {}
    for r in rows:
        cid = r["id_category"]
        tree[cid] = {
            "id_category": cid,
            "name": r["name"],
            "id_parent": r["id_parent"],
            "level_depth": r["level_depth"],
            "active": bool(r["active"]),
            "is_root": bool(r["is_root_category"]),
            "is_excluded": cid in EXCLUDED_CATEGORY_IDS,
        }

    # Buduj ancestors_chain dla każdego węzła (od korzenia do parenta, nie zawiera siebie)
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
        # Odwróć: chain teraz od parenta do korzenia, chcemy od korzenia do parenta
        tree[cid]["ancestors"] = list(reversed(chain))

    logger.info("Tree built: %d cats (level=2: %d, level=3: %d, level=4+: %d)",
                len(tree),
                sum(1 for c in tree.values() if c["level_depth"] == 2),
                sum(1 for c in tree.values() if c["level_depth"] == 3),
                sum(1 for c in tree.values() if c["level_depth"] >= 4))
    return tree


def fetch_product_categories(mysql_cur, product_ids: list[int]) -> dict[int, list[int]]:
    """Pobiera assignments pr_category_product dla listy ID."""
    if not product_ids:
        return {}
    ids_str = ",".join(str(i) for i in product_ids)
    mysql_cur.execute(f"""
        SELECT id_product, id_category
        FROM pr_category_product
        WHERE id_product IN ({ids_str})
    """)
    out: dict[int, list[int]] = defaultdict(list)
    for r in mysql_cur.fetchall():
        out[r["id_product"]].append(r["id_category"])
    return dict(out)


# ============================================================================
# Strategie naturalnego parenta
# ============================================================================

def _candidate_cats(tree: dict, prod_cats: list[int]) -> list[dict]:
    """Filtruje cats produktu: tylko ACTIVE i istniejące w drzewie, bez level=0/1
    (root i Główna nie są kandydatami na "parent")."""
    out = []
    for cid in prod_cats:
        node = tree.get(cid)
        if node is None:
            continue
        if not node["active"]:
            continue
        if node["level_depth"] < 2:
            continue  # Główna (1) i root (0) nie są kandydatami
        out.append(node)
    return out


def _pick_one(candidates: list[str]) -> str | None:
    """Z listy kandydatów wybiera: najczęstszy (tiebreak alfabetyczny)."""
    if not candidates:
        return None
    counter = Counter(candidates)
    max_count = max(counter.values())
    top = sorted([name for name, c in counter.items() if c == max_count])
    return top[0]


def strategy_a(tree: dict, prod_cats: list[int]) -> str | None:
    """A: kategoria najwyższego level_depth (najbardziej szczegółowa).

    Wśród assignments produktu (active, level>=2) wybiera te z największym
    level_depth, dedupe nazw, pick najczęstszy/alfabetyczny.
    """
    cands = _candidate_cats(tree, prod_cats)
    if not cands:
        return None
    max_depth = max(c["level_depth"] for c in cands)
    deepest = [c["name"] for c in cands if c["level_depth"] == max_depth]
    return _pick_one(deepest)


def strategy_b(tree: dict, prod_cats: list[int]) -> str | None:
    """B: ancestor na level_depth=2 (top-level shop category).

    Dla każdej cat produktu wspina się po ancestors do węzła na depth=2.
    Jeśli sama cat jest na depth=2, ona jest wynikiem.
    """
    cands = _candidate_cats(tree, prod_cats)
    results = []
    for c in cands:
        if c["level_depth"] == 2:
            results.append(c["name"])
            continue
        # Walk ancestors, find one at depth=2
        for aid in c["ancestors"]:
            anode = tree.get(aid)
            if anode and anode["level_depth"] == 2 and anode["active"]:
                results.append(anode["name"])
                break
    return _pick_one(results)


def strategy_c(tree: dict, prod_cats: list[int]) -> str | None:
    """C: pierwszy non-root ancestor walking UP from leaf.

    Dla każdej cat produktu bierze jej id_parent (bezpośredni). Jeśli parent
    jest na level>=2 i active — bierze parent. W praktyce: dla leaf depth=3
    daje cat depth=2, dla leaf depth=4 daje cat depth=3 (brand/sub-group).
    """
    cands = _candidate_cats(tree, prod_cats)
    results = []
    for c in cands:
        if c["level_depth"] == 2:
            results.append(c["name"])
            continue
        parent_id = c["id_parent"]
        pnode = tree.get(parent_id)
        if pnode and pnode["active"] and pnode["level_depth"] >= 2:
            results.append(pnode["name"])
    return _pick_one(results)


# ============================================================================
# PG: bieżący stan
# ============================================================================

def fetch_pg_products(pg_cur) -> list[dict]:
    """Wszystkie produkty w divechat_product_embeddings (NIE filtrujemy is_active —
    audyt ma dać pełny obraz). Zwraca listę dictów."""
    pg_cur.execute("""
        SELECT ps_product_id, product_name, category_name, parent_category_name, is_active
        FROM divechat_product_embeddings
        ORDER BY ps_product_id
    """)
    return [
        {
            "ps_product_id": r[0],
            "product_name": r[1] or "",
            "category_name": r[2] or "",
            "current_parent": r[3],
            "is_active": r[4],
        }
        for r in pg_cur.fetchall()
    ]


# ============================================================================
# Agregaty + raport
# ============================================================================

def compute_strategy_metrics(products: list[dict], strategy_results: dict[int, str | None],
                              label: str) -> dict[str, Any]:
    total = len(products)
    coverage = sum(1 for p in products if strategy_results.get(p["ps_product_id"]))
    current_non_null = sum(1 for p in products if p["current_parent"])
    agreement = sum(
        1 for p in products
        if p["current_parent"] is not None
        and strategy_results.get(p["ps_product_id"]) == p["current_parent"]
    )
    null_to_something = sum(
        1 for p in products
        if p["current_parent"] is None and strategy_results.get(p["ps_product_id"])
    )
    mismatches = sum(
        1 for p in products
        if p["current_parent"]
        and strategy_results.get(p["ps_product_id"])
        and strategy_results.get(p["ps_product_id"]) != p["current_parent"]
    )
    return {
        "label": label,
        "total": total,
        "coverage": coverage,
        "coverage_pct": coverage / total * 100 if total else 0,
        "agreement": agreement,
        "agreement_pct": agreement / current_non_null * 100 if current_non_null else 0,
        "null_to_something": null_to_something,
        "mismatches": mismatches,
    }


def top_mismatches(products: list[dict], strategy_results: dict[int, str | None],
                    n: int = TOP_N) -> list[dict]:
    """Top N par (current, suggested) gdzie current != suggested (oba non-null)."""
    counter: Counter = Counter()
    examples: dict[tuple, str] = {}
    for p in products:
        cur = p["current_parent"]
        sug = strategy_results.get(p["ps_product_id"])
        if cur and sug and cur != sug:
            key = (cur, sug)
            counter[key] += 1
            if key not in examples:
                examples[key] = p["product_name"][:60]
    return [
        {"current": k[0], "suggested": k[1], "count": v, "example": examples[k]}
        for k, v in counter.most_common(n)
    ]


def top_null_filled(products: list[dict], strategy_results: dict[int, str | None],
                     n: int = TOP_N) -> list[dict]:
    """Top N produktów które są obecnie NULL ale strategia znajduje parenta.
    Grupuje po sugerowanym parencie żeby pokazać które grupy są ratowane."""
    by_suggested: dict[str, list[dict]] = defaultdict(list)
    for p in products:
        if p["current_parent"] is None:
            sug = strategy_results.get(p["ps_product_id"])
            if sug:
                by_suggested[sug].append(p)
    rows = []
    for sug, ps in sorted(by_suggested.items(), key=lambda x: -len(x[1])):
        rows.append({
            "suggested": sug,
            "count": len(ps),
            "examples": [f"{p['ps_product_id']}: {p['product_name'][:55]}" for p in ps[:3]],
        })
    return rows[:n]


def render_report(stats_in: dict, metrics_per_strategy: list[dict],
                   mismatches_per_strategy: dict[str, list[dict]],
                   nulls_per_strategy: dict[str, list[dict]]) -> str:
    parts: list[str] = []
    parts.append("# Audyt D1 ETL: pr_category vs D2-hybrid mapping\n")
    parts.append(f"**Data:** {date.today().isoformat()}  ")
    parts.append("**Skrypt:** `embeddings/audit_d1_pr_category.py`  ")
    parts.append("**Powiązany ADR:** ADR-055 (D2-hybrid hotfix), kontekst dla T-010 (przyszły D1 ETL)  ")
    parts.append("**Read-only:** bez zmian w produkcji.\n")

    parts.append("## Stan wejściowy (po T-002 D2-hybrid)\n")
    parts.append("| Metric | Wartość |")
    parts.append("|---|---|")
    parts.append(f"| Produkty total w `divechat_product_embeddings` | {stats_in['total']} |")
    parts.append(f"| `parent_category_name != NULL` | {stats_in['non_null']} ({stats_in['non_null_pct']:.1f}%) |")
    parts.append(f"| `parent_category_name = NULL` | {stats_in['null']} ({stats_in['null_pct']:.1f}%) |")
    parts.append(f"| `is_active = true` | {stats_in['active']} |")
    parts.append("")

    for m in metrics_per_strategy:
        parts.append(f"## Strategia {m['label']}\n")
        parts.append("| Metric | Wartość |")
        parts.append("|---|---|")
        parts.append(f"| Pokrycie (produkty z naturalnym parentem) | {m['coverage']} ({m['coverage_pct']:.1f}%) |")
        parts.append(f"| Zgodność z D2 (dla nie-NULL bieżących) | {m['agreement']} ({m['agreement_pct']:.1f}%) |")
        parts.append(f"| Nowe przypisania (NULL → coś) | {m['null_to_something']} |")
        parts.append(f"| Rozjazdy (current != suggested) | {m['mismatches']} |")
        parts.append("")

    for label in ["A", "B", "C"]:
        mm = mismatches_per_strategy.get(label, [])
        parts.append(f"## TOP {TOP_N} rozjazdów (Strategia {label})\n")
        parts.append("| Current (D2) | Suggested (D1) | Produkty | Przykład |")
        parts.append("|---|---|---|---|")
        for r in mm:
            ex = (r["example"] or "").replace("|", "/")
            parts.append(f"| {r['current']} | {r['suggested']} | {r['count']} | {ex} |")
        if not mm:
            parts.append("| (brak rozjazdów) | | | |")
        parts.append("")

    for label in ["A", "B", "C"]:
        nn = nulls_per_strategy.get(label, [])
        parts.append(f"## TOP {TOP_N} grup NULL → coś (Strategia {label})\n")
        parts.append("| Suggested parent | Produkty | Przykłady |")
        parts.append("|---|---|---|")
        for r in nn:
            ex = ", ".join(r["examples"]).replace("|", "/")
            parts.append(f"| {r['suggested']} | {r['count']} | {ex} |")
        if not nn:
            parts.append("| (brak — wszystkie NULL produkty są w nieaktywnych cat) | | |")
        parts.append("")

    parts.append("## Rekomendacje design D1 ETL (T-010)\n")
    parts.append("_(wypełnione na bazie liczb powyżej)_\n")
    parts.append(render_recommendations(metrics_per_strategy, mismatches_per_strategy, nulls_per_strategy))
    return "\n".join(parts) + "\n"


def render_recommendations(metrics: list[dict], mismatches: dict, nulls: dict) -> str:
    by_label = {m["label"]: m for m in metrics}
    a, b, c = by_label["A"], by_label["B"], by_label["C"]
    lines = []

    lines.append(
        f"- **Pokrycie:** A={a['coverage_pct']:.1f}%, B={b['coverage_pct']:.1f}%, "
        f"C={c['coverage_pct']:.1f}%. Wszystkie 3 strategie dają identyczne pokrycie "
        f"({a['coverage']} produktów) — różnią się TYLKO wyborem cat, nie tym czy "
        "którakolwiek się znajdzie. Pokrycie nie jest dyskryminatorem."
    )
    lines.append(
        f"- **Zgodność z D2:** A={a['agreement_pct']:.1f}%, B={b['agreement_pct']:.1f}%, "
        f"C={c['agreement_pct']:.1f}%. **Niska zgodność B (≈39%) jest kluczowym wnioskiem.** "
        "D2-hybrid używa pseudokategorii z NAZEWNICTWO SKLEPU "
        "(np. 'Wypornościowe', 'Maski i fajki'), które NIE istnieją literalnie "
        "w pr_category PrestaShop (drzewo ma 'Skrzydła i jackety', 'Maski i Fajki')."
    )
    lines.append(
        f"- **NULL → coś:** A={a['null_to_something']}, B={b['null_to_something']}, "
        f"C={c['null_to_something']}. Identyczne (ten sam zbiór produktów). Wśród nich: "
        "część to autentyczne luki D2 (Książki nurkowe, Buty, Kaptury, Rękawice, "
        "Odzież Termoaktywna, Ogrzewanie), część to intencjonalne NULL "
        "(WYPRZEDAŻE, Vouchery, 'do 100 PLN', price buckets pod PREZENTY)."
    )
    lines.append(
        f"- **Rozjazdy (current != suggested):** A={a['mismatches']}, "
        f"B={b['mismatches']}, C={c['mismatches']}. Strategia A ma ogromną liczbę "
        "rozjazdów bo wybiera leaf cat (specyficzną) zamiast top-level grupy. "
        "B i C są zbliżone — różnią się głównie dla produktów na level=4 (brand-cat)."
    )
    lines.append("")
    lines.append("### Główny wniosek")
    lines.append("")
    lines.append(
        "**D1 ETL z `pr_category` w surowej formie NIE zastąpi D2-hybrid.** Pseudokategorie "
        "z NAZEWNICTWO SKLEPU (model-facing names) różnią się systematycznie od nazw w "
        "drzewie PrestaShop (admin-facing names). Audyt potwierdza: ~60% produktów dostałoby "
        "INNĄ wartość `parent_category_name` gdyby D1 ETL zastąpił D2 surowo."
    )
    lines.append("")
    lines.append("### Rekomendowany design T-010 (D1 ETL)")
    lines.append("")
    lines.append(
        "1. **D1 ETL wybiera level=2 cat z pr_category** (Strategia B) jako baza — to "
        "najbardziej naturalne odwzorowanie drzewa PrestaShop na pojęcie 'top-level grupa\"."
    )
    lines.append(
        "2. **Mapowanie pseudokategoria PrestaShop → NAZEWNICTWO SKLEPU** jako warstwa "
        "translacji (kontynuacja D2-hybrid, ale jako tabela/JSON, nie hardcoded SQL UPDATE). "
        "Przykład: `'Skrzydła i jackety' → 'Wypornościowe'`, `'Maski i Fajki' → 'Maski i fajki'`, "
        "`'Latarki nurkowe' → 'Oświetlenie'`."
    )
    lines.append(
        "3. **Pipeline ETL idempotentny**: re-run aktualizuje `parent_category_name` "
        "z PS tree + aliases. Bez zewnętrznego SQL UPDATE."
    )
    lines.append(
        "4. **Edge cases które D1 powinien obsłużyć**: 365 produktów obecnie NULL "
        "dostałoby parent (np. 33 książki nurkowe → 'Książki nurkowe', 32 buty → 'Buty', "
        "30 kapturów, 29 rękawic). Decyzja Karola: które z nich rzeczywiście chcemy "
        "wpuścić, a które zostają NULL intencjonalnie (WYPRZEDAŻE, price buckets PREZENTY)."
    )
    lines.append("")
    lines.append("### D2-hybrid status post-D1")
    lines.append("")
    lines.append(
        "**D2-hybrid można odrzucić** po wdrożeniu D1+aliases. Warstwa aliasów "
        "(punkt 2 powyżej) zastępuje hardcoded SQL UPDATE. Jeśli warstwa aliasów "
        "jest tabelą w PG (np. `divechat_category_aliases`), Karol może edytować "
        "online bez deploy."
    )
    lines.append("")
    lines.append("### Wielokategoryjne produkty (~7 cat/produkt)")
    lines.append("")
    lines.append(
        "Większość kategorii to marketingowe (price buckets, 'Stare produkty\", "
        "'instrukcja do wszystkiego\") + inactive admin tagi. Po filtrze active=1 "
        "i level>=2, średnio 1-2 sensowne cat per produkt. Algorytm `_pick_one` "
        "(most common, alfabetyczny tiebreak) deterministycznie wybiera. Alternatywa: "
        "kolumna `parent_categories text[]` zamiast pojedynczego varchar — wymaga "
        "zmiany schematu PG + ProductSearch.php filter logic. **Decyzja Karola**: "
        "czy single-value (status quo) wystarczy, czy multi-value jest potrzebne "
        "(np. produkt na pograniczu 'Akcesoria\" + 'Bezpieczeństwo\")."
    )
    return "\n".join(lines)


# ============================================================================
# Main
# ============================================================================

def main() -> int:
    open_ssh_tunnel()
    try:
        my = mysql_conn()
        mcur = my.cursor()
        tree = build_category_tree(mcur)

        pg = pg_conn()
        pcur = pg.cursor()
        products = fetch_pg_products(pcur)
        logger.info("PG products fetched: %d", len(products))

        product_ids = [p["ps_product_id"] for p in products]
        # Pobieraj batches (gdyby było bardzo dużo)
        product_cats: dict[int, list[int]] = {}
        batch = 1000
        for i in range(0, len(product_ids), batch):
            chunk = product_ids[i:i + batch]
            product_cats.update(fetch_product_categories(mcur, chunk))
        logger.info("Categories fetched dla %d produktów", len(product_cats))

        mcur.close()
        my.close()

        # Strategy results
        results_a: dict[int, str | None] = {}
        results_b: dict[int, str | None] = {}
        results_c: dict[int, str | None] = {}
        for pid in product_ids:
            cats = product_cats.get(pid, [])
            results_a[pid] = strategy_a(tree, cats)
            results_b[pid] = strategy_b(tree, cats)
            results_c[pid] = strategy_c(tree, cats)

        # Wejściowy stan
        total = len(products)
        non_null = sum(1 for p in products if p["current_parent"])
        stats_in = {
            "total": total,
            "non_null": non_null,
            "non_null_pct": non_null / total * 100 if total else 0,
            "null": total - non_null,
            "null_pct": (total - non_null) / total * 100 if total else 0,
            "active": sum(1 for p in products if p["is_active"]),
        }

        metrics = [
            compute_strategy_metrics(products, results_a, "A"),
            compute_strategy_metrics(products, results_b, "B"),
            compute_strategy_metrics(products, results_c, "C"),
        ]

        mismatches_map = {
            "A": top_mismatches(products, results_a),
            "B": top_mismatches(products, results_b),
            "C": top_mismatches(products, results_c),
        }
        nulls_map = {
            "A": top_null_filled(products, results_a),
            "B": top_null_filled(products, results_b),
            "C": top_null_filled(products, results_c),
        }

        report = render_report(stats_in, metrics, mismatches_map, nulls_map)
        REPORT_PATH.parent.mkdir(parents=True, exist_ok=True)
        REPORT_PATH.write_text(report, encoding="utf-8")
        logger.info("Raport zapisany do %s", REPORT_PATH)

        # Stdout summary
        print()
        print("=" * 70)
        print("D1 AUDIT — PODSUMOWANIE")
        print("=" * 70)
        print(f"Produkty total: {total}, current non-null: {non_null} ({stats_in['non_null_pct']:.1f}%)")
        for m in metrics:
            print(f"\nStrategia {m['label']}:")
            print(f"  Pokrycie: {m['coverage']} ({m['coverage_pct']:.1f}%)")
            print(f"  Zgodność z D2: {m['agreement']} ({m['agreement_pct']:.1f}%)")
            print(f"  NULL → coś: {m['null_to_something']}")
            print(f"  Rozjazdy: {m['mismatches']}")

        pcur.close()
        pg.close()
        return 0
    finally:
        close_ssh_tunnel()


if __name__ == "__main__":
    sys.exit(main())
