#!/usr/bin/env python3
"""
CHAT-T-101 — Diagnoza READ-ONLY schematu atrybutow rozmiarow w PrestaShop.

Cel: zobaczyc z REALNEJ bazy sklepu, jak Presta przechowuje rozmiary/warianty,
zeby przy projekcie mini-modulu (ADR-100) NIE stworzyc trzeciego bytu obok
natywnych atrybutow. ZERO write — uzywa konta divezone_chat_reader (tylko SELECT).

Wymaga otwartego tunelu SSH na localhost:33060:
  ssh -i ~/.ssh/id_ed25519 -p 5739 -fN -L 33060:localhost:3306 divezone@divezonededyk.smarthost.pl

Uruchom:  python3 diagnose_size_attributes.py
Wyniki sa drukowane na stdout (sekcjami Q1..Q4). Surowe zapytania: zobacz
sql/diag_101_atrybuty_rozmiary.sql (te same SELECT-y, do recenzji/odtworzenia).
"""
import os
import pymysql
from pathlib import Path
from dotenv import load_dotenv

load_dotenv(Path(__file__).resolve().parent.parent / ".env")

LOCAL_MYSQL_PORT = 33060
PL = 1  # id_lang Polski (2=Deutsch nieaktywny, 3=English)

# Grupy atrybutow zwiazane z rozmiarem (wyselekcjonowane po nazwie PL w Q1).
SIZE_GROUP_IDS = (27, 29, 30, 37, 42, 46, 65, 66, 69, 74)

# Kategorie rozmiarowe (id potwierdzane w Q4).
WETSUIT_WARM = 337
WETSUIT_COLD = 367


def conn():
    return pymysql.connect(
        host="127.0.0.1", port=LOCAL_MYSQL_PORT,
        user=os.getenv("DB_USER"), password=os.getenv("DB_PASSWORD"),
        database=os.getenv("DB_NAME_PROD", "divezone_2025"),
        charset="utf8mb4", cursorclass=pymysql.cursors.DictCursor)


def h(title):
    print("\n" + "=" * 78)
    print(title)
    print("=" * 78)


def rows(cur, sql, params=None):
    cur.execute(sql, params or ())
    return cur.fetchall()


def main():
    c = conn()
    cur = c.cursor()

    # ---- KONTEKST: tozsamosc + uprawnienia (dowod read-only) ----
    h("KONTEKST — tozsamosc i uprawnienia (dowod read-only)")
    print(rows(cur, "SELECT CURRENT_USER() u, VERSION() v")[0])
    for g in rows(cur, "SHOW GRANTS"):
        print(" ", list(g.values())[0])

    # ================= Q1 =================
    h("Q1.a — Wszystkie grupy atrybutow (PL), z liczba wartosci i uzyciem")
    for r in rows(cur, f"""
        SELECT ag.id_attribute_group gid, agl.name,
               (SELECT COUNT(*) FROM pr_attribute a WHERE a.id_attribute_group=ag.id_attribute_group) n_vals,
               (SELECT COUNT(DISTINCT pac.id_product_attribute)
                  FROM pr_attribute a2
                  JOIN pr_product_attribute_combination pac ON pac.id_attribute=a2.id_attribute
                 WHERE a2.id_attribute_group=ag.id_attribute_group) n_combos
          FROM pr_attribute_group ag
          JOIN pr_attribute_group_lang agl
            ON agl.id_attribute_group=ag.id_attribute_group AND agl.id_lang={PL}
         ORDER BY ag.id_attribute_group"""):
        print(f"  gid={r['gid']:>3}  {r['name']:<34} vals={r['n_vals']:>4}  combos={r['n_combos']:>5}")

    h("Q1.b — Wartosci atrybutow w grupach rozmiarowych (probka do 40/grupa)")
    for gid in SIZE_GROUP_IDS:
        gname = rows(cur, f"SELECT name FROM pr_attribute_group_lang WHERE id_attribute_group=%s AND id_lang={PL}", (gid,))
        gname = gname[0]['name'] if gname else '?'
        vals = rows(cur, f"""
            SELECT al.name
              FROM pr_attribute a
              JOIN pr_attribute_lang al ON al.id_attribute=a.id_attribute AND al.id_lang={PL}
             WHERE a.id_attribute_group=%s
             ORDER BY a.position LIMIT 40""", (gid,))
        labels = [v['name'] for v in vals]
        print(f"\n  [gid={gid}] {gname} ({len(labels)} pokazanych):")
        print("    " + " | ".join(labels))

    h("Q1.c — Jak rozmiar konkretnego produktu jest reprezentowany (przyklady)")
    # Przyklad: produkt z grupy ROZMIAR MESKI (29) — pokaz kombinacje
    sample = rows(cur, f"""
        SELECT p.id_product, pl.name, m.name brand
          FROM pr_product p
          JOIN pr_product_lang pl ON pl.id_product=p.id_product AND pl.id_lang={PL}
          LEFT JOIN pr_manufacturer m ON m.id_manufacturer=p.id_manufacturer
         WHERE p.id_product IN (
            SELECT pa.id_product
              FROM pr_product_attribute pa
              JOIN pr_product_attribute_combination pac ON pac.id_product_attribute=pa.id_product_attribute
              JOIN pr_attribute a ON a.id_attribute=pac.id_attribute
             WHERE a.id_attribute_group=29)
         LIMIT 3""")
    for s in sample:
        print(f"\n  PRODUKT {s['id_product']} | {s['brand']} | {s['name'][:60]}")
        combos = rows(cur, f"""
            SELECT pa.id_product_attribute pa_id, pa.reference, pa.price impact,
                   agl.name grp, al.name val
              FROM pr_product_attribute pa
              JOIN pr_product_attribute_combination pac ON pac.id_product_attribute=pa.id_product_attribute
              JOIN pr_attribute a ON a.id_attribute=pac.id_attribute
              JOIN pr_attribute_lang al ON al.id_attribute=a.id_attribute AND al.id_lang={PL}
              JOIN pr_attribute_group_lang agl ON agl.id_attribute_group=a.id_attribute_group AND agl.id_lang={PL}
             WHERE pa.id_product=%s
             ORDER BY pa.id_product_attribute, agl.name LIMIT 30""", (s['id_product'],))
        for cb in combos:
            print(f"     pa={cb['pa_id']:>6} ref={str(cb['reference'])[:14]:<14} {cb['grp']} = {cb['val']}")

    # ================= Q2 =================
    h("Q2.a — pr_feature / pr_feature_value: czy ktos trzyma tam wymiary liczbowe")
    feats = rows(cur, f"""
        SELECT f.id_feature fid, fl.name,
               (SELECT COUNT(*) FROM pr_feature_value fv WHERE fv.id_feature=f.id_feature) n_vals,
               (SELECT COUNT(*) FROM pr_feature_product fp WHERE fp.id_feature=f.id_feature) n_used
          FROM pr_feature f
          JOIN pr_feature_lang fl ON fl.id_feature=f.id_feature AND fl.id_lang={PL}
         ORDER BY n_used DESC""")
    print(f"  Cech (features) ogolem: {len(feats)}")
    for r in feats:
        print(f"    fid={r['fid']:>3} {r['name']:<40} vals={r['n_vals']:>4} uzyc={r['n_used']:>5}")
    # Czy wartosci cech zawieraja liczby/zakresy wymiarow (chest/waist/cm)?
    h("Q2.b — Wartosci cech wygladajace na wymiary (LIKE cm / klatka / pas / wzrost / liczby-zakresy)")
    dimlike = rows(cur, f"""
        SELECT fl.name feature, fvl.value
          FROM pr_feature_value fv
          JOIN pr_feature_value_lang fvl ON fvl.id_feature_value=fv.id_feature_value AND fvl.id_lang={PL}
          JOIN pr_feature_lang fl ON fl.id_feature=fv.id_feature AND fl.id_lang={PL}
         WHERE fvl.value REGEXP 'klatk|obw[oó]d|pas|biodr|wzrost|[0-9]{{2,3}} ?- ?[0-9]{{2,3}}|[0-9]{{2,3}} ?cm'
         LIMIT 40""")
    if not dimlike:
        print("  (brak wartosci cech wygladajacych na progi wymiarowe)")
    for r in dimlike:
        print(f"    [{r['feature']}] {r['value'][:70]}")

    h("Q2.c — Opisy produktow: czy istnieje inline kalkulator/tabela progow (markery)")
    for pat, lab in [("%<script%", "inline <script>"),
                     ("%function%", "JS function("),
                     ("%onclick%", "onclick handler"),
                     ("%<input%", "<input> (formularz kalkulatora)"),
                     ("%<select%", "<select> (formularz kalkulatora)"),
                     ("%<form%", "<form> (formularz kalkulatora)"),
                     ("%getElement%", "getElement (JS DOM)"),
                     ("%kalkulat%", "slowo 'kalkulator'"),
                     ("%<table%", "<table> w opisie (statyczna tabela)"),
                     ("%tabela rozmiar%", "tekst 'tabela rozmiar'"),
                     ("%<img%", "<img> w opisie (grafika, np. size chart)"),
                     ("%id_product_attribute%", "odwolanie do wariantu (kalkulator)"),
                     ("%klatk%", "slowo 'klatk' (klatka piersiowa)")]:
        n = rows(cur, f"""
            SELECT COUNT(DISTINCT pl.id_product) n
              FROM pr_product_lang pl
              JOIN pr_product p ON p.id_product=pl.id_product AND p.active=1
             WHERE pl.id_lang={PL} AND (pl.description LIKE %s OR pl.description_short LIKE %s)""",
                   (pat, pat))[0]['n']
        print(f"    {lab:<42} produktow aktywnych: {n}")

    # ================= Q3 =================
    h("Q3.a — Marki produktow w kategoriach rozmiarowych (skafandry mokre 337/367)")
    for r in rows(cur, f"""
        SELECT m.id_manufacturer mid, m.name brand, COUNT(DISTINCT p.id_product) n_prod
          FROM pr_product p
          JOIN pr_category_product cp ON cp.id_product=p.id_product AND cp.id_category IN ({WETSUIT_WARM},{WETSUIT_COLD})
          LEFT JOIN pr_manufacturer m ON m.id_manufacturer=p.id_manufacturer
         WHERE p.active=1
         GROUP BY m.id_manufacturer, m.name
         ORDER BY n_prod DESC"""):
        print(f"    mid={str(r['mid']):>4} {str(r['brand']):<28} produktow={r['n_prod']}")

    h("Q3.b — Czy produkty tej samej marki dziela te same wartosci atrybutu rozmiaru")
    # Dla marki Scubapro (18) i Bare (11): ile distinct id_attribute rozmiaru (gid 27/29/30) uzywanych,
    # ile produktow, czy wspoldzielone.
    for mid, bname in [(11, "Bare"), (18, "Scubapro")]:
        r = rows(cur, f"""
            SELECT COUNT(DISTINCT pac.id_attribute) n_size_attrs,
                   COUNT(DISTINCT pa.id_product) n_products,
                   COUNT(DISTINCT pac.id_product_attribute) n_combos
              FROM pr_product_attribute pa
              JOIN pr_product p ON p.id_product=pa.id_product AND p.id_manufacturer=%s AND p.active=1
              JOIN pr_product_attribute_combination pac ON pac.id_product_attribute=pa.id_product_attribute
              JOIN pr_attribute a ON a.id_attribute=pac.id_attribute AND a.id_attribute_group IN (27,29,30)
            """, (mid,))[0]
        print(f"    {bname:<10} mid={mid}: distinct_size_attr={r['n_size_attrs']} | produktow_z_rozmiarem={r['n_products']} | kombinacji={r['n_combos']}")
        # Top wspoldzielone id_attribute (uzyte przez >1 produkt)
        shared = rows(cur, f"""
            SELECT al.name val, COUNT(DISTINCT pa.id_product) n_prod
              FROM pr_product_attribute pa
              JOIN pr_product p ON p.id_product=pa.id_product AND p.id_manufacturer=%s AND p.active=1
              JOIN pr_product_attribute_combination pac ON pac.id_product_attribute=pa.id_product_attribute
              JOIN pr_attribute a ON a.id_attribute=pac.id_attribute AND a.id_attribute_group IN (27,29,30)
              JOIN pr_attribute_lang al ON al.id_attribute=a.id_attribute AND al.id_lang={PL}
             GROUP BY a.id_attribute, al.name
             HAVING n_prod>1
             ORDER BY n_prod DESC LIMIT 8""", (mid,))
        for s in shared:
            print(f"        wartosc '{s['val']}' wspoldzielona przez {s['n_prod']} produktow")

    # ================= Q4 =================
    h("Q4 — Identyfikacja kategorii rozmiarowych (skafandry suche/mokre, buty, rekawice)")
    cats = rows(cur, f"""
        SELECT c.id_category cid, cl.name, c.id_parent,
               (SELECT COUNT(*) FROM pr_category_product cp
                  JOIN pr_product p ON p.id_product=cp.id_product AND p.active=1
                 WHERE cp.id_category=c.id_category) n_prod
          FROM pr_category c
          JOIN pr_category_lang cl ON cl.id_category=c.id_category AND cl.id_lang={PL}
         WHERE cl.name REGEXP 'skafand|pianka|suche|mokre|but|rekawic|r[eę]kawic|kapt'
         ORDER BY n_prod DESC""")
    for r in cats:
        print(f"    cid={r['cid']:>4} parent={r['id_parent']:>4} prod={r['n_prod']:>4}  {r['name']}")

    c.close()
    print("\n[OK] Diagnoza zakonczona. Zero write.")


if __name__ == "__main__":
    main()
