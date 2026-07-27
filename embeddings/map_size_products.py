"""
CHAT-T-099 / ADR-099 — propozycja mapowania produkt->chart + raport pokrycia CSV.

READ-ONLY na MySQL PrestaShop (uzytkownik divezone_chat_reader przez SSH tunnel).
NIC nie zapisuje do PostgreSQL — generuje DWA artefakty do akceptacji Karola (HARD STOP):
  _reports/CHAT-T-099_mapowanie_propozycja.csv   (KROK 3 — lista do akceptacji)
  _reports/CHAT-T-099_pokrycie-rozmiarow.csv      (KROK 4 — raport pokrycia, ADR-099 pkt 7)

Zakres (ADR-099 pkt 8): marki Scubapro(18) + Bare(11), kategorie skafandrow mokrych
337 (CIEPLE wody) + 367 (ZIMNE wody). Suche WYKLUCZONE (inna kategoria).

Plec produktu czytana z NAZWY (Damski/Meski/dzieci/Unisex) — to plec PRODUKTU dla
mapowania do charta. Regula "bot pyta o plec" (ADR-099 pkt 3) dotyczy runtime dialogu,
nie statycznego mapowania produktu gendered.

Wymaga otwartego tunelu SSH na localhost:33060 (jak extract_products.py).
"""
import csv
import os
import re
from collections import defaultdict
from pathlib import Path

import pymysql
from dotenv import load_dotenv

load_dotenv(Path(__file__).resolve().parent.parent / ".env")

LOCAL_MYSQL_PORT = 33060
BRANDS = {11: "Bare", 18: "Scubapro"}
WETSUIT_CATS = (337, 367)  # CIEPLE, ZIMNE wody (mokre)

# Etykiety rozmiarow obecne w kazdym charcie (do detekcji "rozmiary bez progu").
CHART_LABELS = {
    ("Bare", "M"): {"S","MS","M","MT","MLS","ML","MLT","LS","L","LT","XLS","XL","XLT","2XLS","2XL","3XL"},
    ("Bare", "K"): {"2","4","4T","6","6T","6+","8","8T","8+","10","10+","12","12+","14"},
    ("Scubapro", "M"): {"XS","S","ST","MS","M","MT","LS","L","LT","XLS","XL","XLT","2XLS","2XL","3XL","4XL"},
    ("Scubapro", "K"): {"XS","S","ST","MS","M","MT","LS","L","LT","XLS","XL","2XL"},
}


def conn():
    return pymysql.connect(host="127.0.0.1", port=LOCAL_MYSQL_PORT,
        user=os.getenv("DB_USER"), password=os.getenv("DB_PASSWORD"),
        database=os.getenv("DB_NAME_PROD", "divezone_2025"),
        charset="utf8mb4", cursorclass=pymysql.cursors.DictCursor)


def detect_gender(name: str):
    """Plec produktu z nazwy. Zwraca ('M'|'K'|'DZIECI'|'UNISEX'|'?', powod)."""
    n = name.lower()
    if "dla dzieci" in n or "dziec" in n:
        return "DZIECI", "nazwa: dla dzieci"
    if "unisex" in n:
        return "UNISEX", "nazwa: unisex"
    has_k = bool(re.search(r"damsk|damski|damska|damskie", n))
    has_m = bool(re.search(r"m[ęe]sk", n))
    if has_k and not has_m:
        return "K", "nazwa: damski"
    if has_m and not has_k:
        return "M", "nazwa: meski"
    if has_k and has_m:
        return "?", "nazwa: oba znaczniki plci"
    return "?", "nazwa: brak znacznika plci"


def norm_label(lbl: str) -> str:
    """Normalizacja etykiety PS do etykiety charta. '6 Plus'->'6+', trim."""
    s = lbl.strip()
    m = re.match(r"^(\d+)\s*Plus$", s, re.IGNORECASE)
    if m:
        return m.group(1) + "+"
    return s


def main():
    c = conn()
    cur = c.cursor()

    # Produkty w zakresie.
    cur.execute("""
        SELECT DISTINCT p.id_product, pl.name, p.id_manufacturer AS mfr,
               ps.active, ps.visibility
        FROM pr_product p
        JOIN pr_product_lang pl ON p.id_product=pl.id_product AND pl.id_lang=1
        JOIN pr_product_shop ps ON p.id_product=ps.id_product AND ps.id_shop=1
        WHERE p.id_manufacturer IN (11,18)
          AND p.id_product IN (SELECT id_product FROM pr_category_product WHERE id_category IN (337,367))
        ORDER BY p.id_manufacturer, pl.name
    """)
    products = cur.fetchall()

    # Etykiety rozmiarow per produkt (grupy ROZMIAR=27, ROZMIAR MESKI=29, ROZMIAR DAMSKI=30).
    cur.execute("""
        SELECT pa.id_product, ag.id_attribute_group AS gid, al.name AS val
        FROM pr_product_attribute pa
        JOIN pr_product_attribute_combination pac ON pa.id_product_attribute=pac.id_product_attribute
        JOIN pr_attribute a ON pac.id_attribute=a.id_attribute
        JOIN pr_attribute_group ag ON a.id_attribute_group=ag.id_attribute_group
        JOIN pr_attribute_lang al ON a.id_attribute=al.id_attribute AND al.id_lang=1
        WHERE ag.id_attribute_group IN (27,29,30)
          AND pa.id_product IN (SELECT id_product FROM pr_category_product
                                WHERE id_category IN (337,367))
    """)
    sizes = defaultdict(lambda: defaultdict(set))  # pid -> gid -> {labels}
    for r in cur.fetchall():
        sizes[r["id_product"]][r["gid"]].add(r["val"])
    c.close()

    mapping_rows = []   # KROK 3
    coverage_rows = []  # KROK 4

    for p in products:
        pid = p["id_product"]
        brand = BRANDS[p["mfr"]]
        name = p["name"]
        gender, why = detect_gender(name)
        ps_labels_27 = sizes[pid].get(27, set())
        has_male_grp = bool(sizes[pid].get(29))
        has_female_grp = bool(sizes[pid].get(30))
        size_variants_nonempty = bool(ps_labels_27 or has_male_grp or has_female_grp)

        # Propozycja charta + status.
        proposed = []   # lista (brand,gender)
        status = ""

        if gender == "DZIECI":
            status = "DZIECI — brak charta dorosłego (poza zakresem M/K)"
        elif has_male_grp or has_female_grp:
            # OneFlex: bi-gender (osobne grupy rozmiar meski/damski)
            if has_male_grp:
                proposed.append((brand, "M"))
            if has_female_grp:
                proposed.append((brand, "K"))
            status = "BI-GENDER (grupy ROZMIAR MĘSKI/DAMSKI) — DO DECYZJI: 2 charty?"
        elif gender in ("M", "K"):
            proposed.append((brand, gender))
            status = "OK — plec z nazwy"
        elif gender == "UNISEX":
            status = "UNISEX — DO DECYZJI (chart M? K? oba? bot i tak pyta o plec)"
        else:  # '?'
            # Heurystyka pomocnicza z etykiet (info dla Karola, NIE auto-decyzja).
            norm = {norm_label(x) for x in ps_labels_27}
            looks_female = bool(norm & CHART_LABELS.get((brand, "K"), set())) and not (norm & {"S","M","L","XL"})
            hint = "K?" if looks_female else "M?"
            status = f"PŁEĆ NIEJEDNOZNACZNA ({why}) — DO DECYZJI, sugestia {hint}"

        # Pokrycie etykiet: ktore rozmiary w PS nie maja progu w proponowanym charcie.
        labels_no_threshold = []
        if len(proposed) == 1:
            chart_lbls = CHART_LABELS.get(proposed[0], set())
            for raw in sorted(ps_labels_27):
                if norm_label(raw) not in chart_lbls:
                    labels_no_threshold.append(raw)

        proposed_str = " + ".join(f"{b}/{g}" for b, g in proposed) if proposed else "—"
        ma_chart = "T" if proposed else "N"

        mapping_rows.append({
            "product_id": pid,
            "nazwa": name,
            "marka": brand,
            "active": p["active"],
            "visibility": p["visibility"],
            "plec_wykryta": gender,
            "podstawa_plci": why,
            "proponowany_chart": proposed_str,
            "rozmiary_PS": ", ".join(sorted(ps_labels_27)) if ps_labels_27 else
                           ("MESKI:[" + ",".join(sorted(sizes[pid].get(29, []))) + "] DAMSKI:[" +
                            ",".join(sorted(sizes[pid].get(30, []))) + "]" if (has_male_grp or has_female_grp) else ""),
            "status": status,
        })
        coverage_rows.append({
            "product_id": pid,
            "nazwa": name,
            "marka": brand,
            "plec": gender,
            "ma_chart": ma_chart,
            "size_variants_niepuste": "T" if size_variants_nonempty else "N",
            "rozmiary_bez_progu": ", ".join(labels_no_threshold),
            "status": status,
        })

    reports = Path(__file__).resolve().parent.parent / "_reports"
    reports.mkdir(exist_ok=True)

    map_path = reports / "CHAT-T-099_mapowanie_propozycja.csv"
    with open(map_path, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=list(mapping_rows[0].keys()))
        w.writeheader(); w.writerows(mapping_rows)

    cov_path = reports / "CHAT-T-099_pokrycie-rozmiarow.csv"
    with open(cov_path, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=list(coverage_rows[0].keys()))
        w.writeheader(); w.writerows(coverage_rows)

    # Podsumowanie na stdout.
    total = len(mapping_rows)
    with_chart = sum(1 for r in coverage_rows if r["ma_chart"] == "T")
    sv_yes = sum(1 for r in coverage_rows if r["size_variants_niepuste"] == "T")
    print(f"Produktow w zakresie: {total}")
    print(f"  z proponowanym chartem (1 plec): {sum(1 for r in mapping_rows if ' + ' not in r['proponowany_chart'] and r['proponowany_chart'] != '—')}")
    print(f"  ma_chart=T (w tym bi-gender): {with_chart}")
    print(f"  size_variants niepuste: {sv_yes}/{total}")
    print(f"  pokrycie (ma_chart): {with_chart/total*100:.0f}%")
    print(f"\nZapisano:\n  {map_path}\n  {cov_path}")
    # statusy do decyzji
    print("\nPRODUKTY DO DECYZJI (nie auto-OK):")
    for r in mapping_rows:
        if not r["status"].startswith("OK"):
            print(f"  [{r['product_id']}] {r['marka']:9} {r['nazwa'][:50]:50} -> {r['status']}")


if __name__ == "__main__":
    main()
