"""
CHAT-T-099 / 099b — raport pokrycia rozmiarow z AUTORYTATYWNYCH mapowan w PG.

W odroznieniu od map_size_products.py (generator PROPOZYCJI mapowan z heurystyki),
ten skrypt liczy pokrycie z RZECZYWISTYCH wpisow w divechat_product_size_chart (Railway)
zlaczonych z lista produktow Scubapro/Bare w kategoriach skafandrow mokrych (MySQL RO).

Wymaga: tunel SSH na localhost:33060 (MySQL) + DATABASE_URL (PG).
Emituje: _reports/CHAT-T-099_pokrycie-rozmiarow.csv  (ADR-099 pkt 7)
Kolumny: product_id, nazwa, marka, plec_chart, ma_chart, size_variants_niepuste,
         rozmiary_bez_progu, status
"""
import csv
import os
import re
from collections import defaultdict
from pathlib import Path

import pymysql
import psycopg2
import psycopg2.extras
from dotenv import load_dotenv

load_dotenv(Path(__file__).resolve().parent.parent / ".env")

LOCAL_MYSQL_PORT = 33060
BRANDS = {11: "Bare", 18: "Scubapro"}

# Produkty oddane na liste Janka (NIE bledy mapowania — do poprawki u zrodla).
JANEK = {
    4245: "lista Janka — 0 wariantow rozmiaru mimo rozmiaru w nazwie (cel #1)",
    7331: "lista Janka — plec damska, ale MESKIE etykiety rozmiarow (konflikt)",
    4553: "lista Janka — skafander dzieciecy Bare (brak charta dzieciecego Bare)",
    4554: "lista Janka — skafander dzieciecy Bare (brak charta dzieciecego Bare)",
}


def norm_label(lbl: str) -> str:
    s = lbl.strip()
    m = re.match(r"^(\d+)\s*Plus$", s, re.IGNORECASE)
    return (m.group(1) + "+") if m else s


def main():
    # --- PG: charty, wiersze (etykiety per chart), aliasy, mapowania ---
    pg = psycopg2.connect(os.environ["DATABASE_URL"])
    pc = pg.cursor(cursor_factory=psycopg2.extras.RealDictCursor)
    pc.execute("SELECT id, brand, gender FROM divechat_size_charts")
    chart = {r["id"]: (r["brand"], r["gender"]) for r in pc.fetchall()}
    pc.execute("SELECT chart_id, size_label FROM divechat_size_chart_rows")
    chart_labels = defaultdict(set)
    for r in pc.fetchall():
        chart_labels[r["chart_id"]].add(r["size_label"])
    pc.execute("SELECT chart_id, alias_label, canonical_label FROM divechat_size_label_alias")
    alias = defaultdict(dict)
    for r in pc.fetchall():
        alias[r["chart_id"]][r["alias_label"]] = r["canonical_label"]
    pc.execute("SELECT product_id, chart_id FROM divechat_product_size_chart")
    prod_charts = defaultdict(list)
    for r in pc.fetchall():
        prod_charts[r["product_id"]].append(r["chart_id"])
    pg.close()

    # --- MySQL: produkty w zakresie + etykiety rozmiarow (g27) ---
    my = pymysql.connect(host="127.0.0.1", port=LOCAL_MYSQL_PORT, user=os.getenv("DB_USER"),
        password=os.getenv("DB_PASSWORD"), database=os.getenv("DB_NAME_PROD", "divezone_2025"),
        charset="utf8mb4", cursorclass=pymysql.cursors.DictCursor)
    mc = my.cursor()
    mc.execute("""
        SELECT DISTINCT p.id_product, pl.name, p.id_manufacturer AS mfr
        FROM pr_product p
        JOIN pr_product_lang pl ON p.id_product=pl.id_product AND pl.id_lang=1
        WHERE p.id_manufacturer IN (11,18)
          AND p.id_product IN (SELECT id_product FROM pr_category_product WHERE id_category IN (337,367))
        ORDER BY p.id_manufacturer, pl.name
    """)
    products = mc.fetchall()
    mc.execute("""
        SELECT pa.id_product, al.name AS val
        FROM pr_product_attribute pa
        JOIN pr_product_attribute_combination pac ON pa.id_product_attribute=pac.id_product_attribute
        JOIN pr_attribute a ON pac.id_attribute=a.id_attribute
        JOIN pr_attribute_lang al ON a.id_attribute=al.id_attribute AND al.id_lang=1
        WHERE a.id_attribute_group IN (27,29,30)
          AND pa.id_product IN (SELECT id_product FROM pr_category_product WHERE id_category IN (337,367))
    """)
    psize = defaultdict(set)
    for r in mc.fetchall():
        psize[r["id_product"]].add(r["val"])
    my.close()

    rows = []
    for p in products:
        pid = p["id_product"]
        brand = BRANDS[p["mfr"]]
        labels = psize[pid]
        sv = "T" if labels else "N"
        charts = prod_charts.get(pid, [])
        ma = "T" if charts else "N"
        plec = " + ".join(sorted(f"{chart[c][0]}/{chart[c][1]}" for c in charts)) if charts else "—"

        # rozmiary bez progu: tylko gdy 1 chart (jednoznaczny), po aliasach + normalizacji "+"
        no_thr = []
        if len(charts) == 1:
            cid = charts[0]
            cl = chart_labels[cid]
            al = alias[cid]
            for raw in sorted(labels):
                canon = al.get(raw, norm_label(raw))
                if canon not in cl:
                    no_thr.append(raw)

        if charts:
            status = "ZMAPOWANY"
        elif pid in JANEK:
            status = JANEK[pid]
        else:
            status = "niezmapowany (poza zakresem iteracji 1)"

        rows.append({
            "product_id": pid, "nazwa": p["name"], "marka": brand,
            "plec_chart": plec, "ma_chart": ma, "size_variants_niepuste": sv,
            "rozmiary_bez_progu": ", ".join(no_thr), "status": status,
        })

    out = Path(__file__).resolve().parent.parent / "_reports" / "CHAT-T-099_pokrycie-rozmiarow.csv"
    with open(out, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=list(rows[0].keys()))
        w.writeheader(); w.writerows(rows)

    total = len(rows)
    mapped = sum(1 for r in rows if r["ma_chart"] == "T")
    janek = sum(1 for r in rows if r["product_id"] in JANEK)
    print(f"Produktow w zakresie: {total}")
    print(f"  zmapowanych (ma_chart=T): {mapped}  ({mapped/total*100:.0f}%)")
    print(f"  na liscie Janka: {janek}")
    print(f"  niezmapowanych (poza iter.1): {total-mapped-janek}")
    print(f"Zapisano: {out}")


if __name__ == "__main__":
    main()
