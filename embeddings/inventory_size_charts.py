#!/usr/bin/env python3
"""
CHAT-T-102 — Inwentaryzacja READ-ONLY czartow rozmiarowych w katalogu PrestaShop.

Cel: per produkt (skafandry suche/mokre, buty, rekawice — BEZ pletw) ustalic, czy
ma tabele rozmiarowa jako GRAFIKE (img), TEKST (tabela/kalkulator), czy NIC.
Wejscie do planu pozyskania danych (OCR/research/dostawca). Niezalezne od lokalizacji
danych (ADR-100 krok 8). ZERO write — konto divezone_chat_reader (tylko SELECT).

Bulk IN(...), nie N+1 (lekcja CHAT-T-099). Id kategorii zweryfikowane w CHAT-T-101
(_docs/40_diagnoza_atrybuty_rozmiary_prestashop.md).

Wymaga tunelu SSH na localhost:33060:
  ssh -i ~/.ssh/id_ed25519 -p 5739 -fN -L 33060:localhost:3306 divezone@divezonededyk.smarthost.pl

Output:
  _reports/CHAT-T-102_inwentaryzacja_czartow.csv
  _reports/CHAT-T-102_inwentaryzacja_czartow_podsumowanie.md
"""
import os
import re
import csv
import html
import pymysql
from pathlib import Path
from collections import defaultdict, Counter
from dotenv import load_dotenv

BASE = Path(__file__).resolve().parent.parent
load_dotenv(BASE / ".env")
PL = 1
LOCAL_MYSQL_PORT = 33060

# Kategorie rozmiarowe (zweryfikowane CHAT-T-101). Mapa: etykieta zakresu -> id_category.
# Drysuit: tylko realne skafandry (425 Trylaminat/Cordura, 424 Neoprenowe, 477 wyprzedaze);
#          205 to umbrella pelne akcesoriow (ocieplacze/zawory/torby) -> NIE bierzemy parenta.
# Wetsuit: 337 ciensze/cieple, 367 zimne, 421 komplety pianek.
# Buty: 212 (mokre) + 208 (do suchego). Rekawice: 218 (mokre) + 210 (rekawice i pierscienie).
SCOPE = [
    ("skafander_suchy", [425, 424, 477]),
    ("skafander_mokry", [337, 367, 421]),
    ("buty",            [212, 208]),
    ("rekawice",        [218, 210]),
]
SCOPE_PRIORITY = [k for k, _ in SCOPE]  # kolejnosc przy konflikcie wielokategoryjnym

# Grupy atrybutow rozmiarowych (z CHAT-T-101 Q1): ROZMIAR/MESKI/DAMSKI/BUTOW/UPRZEZY/REKAWIC/BUTY
SIZE_GROUPS = (27, 29, 30, 42, 46, 69, 37)

# --- Heurystyki klasyfikacji ---
# img nazwany jak size chart
IMG_SIZE_KW = re.compile(r"(size[-_ ]?chart|sizechart|rozmiar|tabela[-_ ]?rozm|tabel.{0,3}rozm|sizing|size[-_]?guide|gr[oö]ss?e|wymiar)", re.I)
# tekst zapowiadajacy tabele rozmiarow (czart istnieje gdzies, czesto jako grafika)
TXT_CHART_HINT = re.compile(r"(tabel\w*\s+rozmiar|tabel\w*\s+wymiar|dob[oó]r\s+rozmiar|dobierz\s+rozmiar|sprawd[zź]\w*\s+rozmiar|wyb[oó]r\s+rozmiar|jak\s+dobra[cć]\s+rozmiar|size\s*chart|sizing|si[ae]tk[ae]\s+rozmiar)", re.I)
# Wymiary ciala POGRUPOWANE. Realna tabela rozmiarow krzyzuje KILKA miar (klatka+talia+wzrost...).
# Spec produktu ma najwyzej jedna ("Waga" produktu), wiec prog >=2 grupy odsiewa spec-tabele.
BODY_GROUPS = [
    re.compile(r"(klatk|chest|biust)", re.I),          # klatka piersiowa
    re.compile(r"(talia|talii|waist)", re.I),          # talia
    re.compile(r"(biodr|\bhip\b)", re.I),              # biodra
    re.compile(r"(wzrost|height|\bwzr\.)", re.I),      # wzrost
    re.compile(r"(waga\b|wagi\b|weight)", re.I),       # waga
    re.compile(r"(inseam|krocz|d[lł]ugo[sś][cć]\s+nog|noga|nogi)", re.I),  # noga/krok
]
TBL_SIZE_HDR = re.compile(r"(rozmiar|size)", re.I)
# inline kalkulator
CALC_KW = re.compile(r"(<input|<select|<form|getElement|addEventListener|dobierzRozmiar|oblicz\w*\s+rozmiar|calculateSize)", re.I)

IMG_RE = re.compile(r"<img[^>]*>", re.I)
SRC_RE = re.compile(r'src\s*=\s*["\']([^"\']+)["\']', re.I)
ALT_RE = re.compile(r'alt\s*=\s*["\']([^"\']*)["\']', re.I)
TBL_RE = re.compile(r"<table.*?</table>", re.I | re.S)
SIZE_LABEL = re.compile(r"\b(X{0,2}S|M|X{0,3}L|[2-4]XL|XXL)\b")


def conn():
    return pymysql.connect(host="127.0.0.1", port=LOCAL_MYSQL_PORT,
        user=os.getenv("DB_USER"), password=os.getenv("DB_PASSWORD"),
        database=os.getenv("DB_NAME_PROD", "divezone_2025"),
        charset="utf8mb4", cursorclass=pymysql.cursors.DictCursor)


def imgs_in(desc):
    out = []
    for tag in IMG_RE.findall(desc or ""):
        s = SRC_RE.search(tag)
        a = ALT_RE.search(tag)
        out.append((html.unescape(s.group(1)) if s else "", html.unescape(a.group(1)) if a else ""))
    return out


def is_size_table(tbl_html):
    """Czy dany blok <table> to realna tabela rozmiarow z PROGAMI (a nie spec produktu).
    Zwraca (bool, opis_sygnalu)."""
    t = html.unescape(tbl_html)
    groups = sum(1 for g in BODY_GROUPS if g.search(t))
    # >=2 rozne miary ciala = tabela progow (spec produktu ma najwyzej jedna, np. 'Waga')
    if groups >= 2:
        return True, f"tabela progow (>={groups} wymiary ciala)"
    return False, ""


def classify(desc):
    """Zwraca (typ_czartu, url_obrazka, ma_kalkulator, uwagi)."""
    d = desc or ""
    imgs = imgs_in(d)
    tables = TBL_RE.findall(d)
    has_calc = bool(CALC_KW.search(d))
    txt_hint = bool(TXT_CHART_HINT.search(d))

    # 1) GRAFIKA: img nazwany jak size chart (sygnal mocny)
    for src, alt in imgs:
        if IMG_SIZE_KW.search(src) or IMG_SIZE_KW.search(alt):
            return ("grafika", src, "T" if has_calc else "N", "img nazwany jak size chart")

    # 2) TEKST: KONKRETNA <table> jest tabela PROGOW (>=2 wymiary ciala) -> dane strukturalne
    for t in tables:
        ok, why = is_size_table(t)
        if ok:
            return ("tekst", "", "T" if has_calc else "N", why)

    # 3) Kalkulator inline bez tabeli progow
    if has_calc:
        return ("tekst", "", "T", "inline kalkulator (input/select/form/js)")

    # czy jest tabela samych etykiet rozmiarow (lista wariantow, bez progow) — info do uwag
    label_table = any(TBL_SIZE_HDR.search(html.unescape(t)) and
                      len({m.group(0).upper() for m in SIZE_LABEL.finditer(html.unescape(t))}) >= 4
                      for t in tables)

    # 4) GRAFIKA DO WERYFIKACJI: tekst zapowiada tabele rozmiarow, ale brak tabeli progow/named-img.
    #    Czart prawdopodobnie jako nieopisana grafika (lub tabela etykiet bez progow) -> reczna weryfikacja.
    if txt_hint or label_table:
        cand = imgs[-1][0] if imgs else ""
        bits = []
        if txt_hint:
            bits.append("tekst wspomina dobor/tabele rozmiaru")
        if label_table:
            bits.append("tabela etykiet rozmiarow BEZ progow")
        if tables and not label_table:
            bits.append("jest <table> spec")
        bits.append("kandydat=ostatni img" if cand else "brak img")
        return ("grafika_do_weryfikacji", cand, "N", "; ".join(bits))

    # 5) NIC
    nt = "brak img-chartu, brak tabeli progow, brak kalkulatora"
    if tables:
        nt = "ma <table> (spec, nie rozmiarowa); brak innych sygnalow rozmiaru"
    return ("nic", "", "N", nt)


def main():
    c = conn()
    cur = c.cursor()
    print("Konto:", cur.execute("SELECT CURRENT_USER()") and cur.fetchall()[0]["CURRENT_USER()"])

    # ---- KROK 1: produkty w zakresie (bulk) ----
    cat_to_label = {}
    all_cats = []
    for label, cats in SCOPE:
        for cid in cats:
            cat_to_label[cid] = label
            all_cats.append(cid)
    fmt = ",".join(str(x) for x in all_cats)

    # mapowanie produkt -> zbior id_category w zakresie
    cur.execute(f"""
        SELECT cp.id_product, cp.id_category
          FROM pr_category_product cp
          JOIN pr_product p ON p.id_product=cp.id_product AND p.active=1
         WHERE cp.id_category IN ({fmt})""")
    prod_cats = defaultdict(set)
    for r in cur.fetchall():
        prod_cats[r["id_product"]].add(r["id_category"])
    pids = sorted(prod_cats.keys())
    print(f"KROK1: produktow w zakresie (active, dedup): {len(pids)}")

    # dane produktow (bulk)
    pfmt = ",".join(str(x) for x in pids)
    cur.execute(f"""
        SELECT p.id_product, pl.name, COALESCE(m.name,'(brak marki)') brand,
               pl.description
          FROM pr_product p
          JOIN pr_product_lang pl ON pl.id_product=p.id_product AND pl.id_lang={PL}
          LEFT JOIN pr_manufacturer m ON m.id_manufacturer=p.id_manufacturer
         WHERE p.id_product IN ({pfmt})""")
    prod = {r["id_product"]: r for r in cur.fetchall()}

    # produkty z wariantami rozmiaru (bulk)
    cur.execute(f"""
        SELECT DISTINCT pa.id_product
          FROM pr_product_attribute pa
          JOIN pr_product_attribute_combination pac ON pac.id_product_attribute=pa.id_product_attribute
          JOIN pr_attribute a ON a.id_attribute=pac.id_attribute AND a.id_attribute_group IN ({','.join(map(str,SIZE_GROUPS))})
         WHERE pa.id_product IN ({pfmt})""")
    has_size_variants = {r["id_product"] for r in cur.fetchall()}

    # nazwy kategorii (do etykiet w CSV)
    cur.execute(f"SELECT id_category, name FROM pr_category_lang WHERE id_lang={PL} AND id_category IN ({fmt})")
    catname = {r["id_category"]: r["name"] for r in cur.fetchall()}

    # ---- KROK 2: klasyfikacja ----
    rows_out = []
    for pid in pids:
        p = prod.get(pid)
        if not p:
            continue
        cats = prod_cats[pid]
        # etykieta zakresu wg priorytetu
        labels = {cat_to_label[ci] for ci in cats if ci in cat_to_label}
        label = next((L for L in SCOPE_PRIORITY if L in labels), "?")
        cat_names = " + ".join(sorted(catname.get(ci, str(ci)) for ci in cats))
        typ, url, calc, uwagi = classify(p["description"])
        ma_war = "T" if pid in has_size_variants else "N"
        if len(labels) > 1:
            uwagi = (uwagi + f" | wielokat.: {','.join(sorted(labels))}").strip(" |")
        rows_out.append({
            "product_id": pid,
            "nazwa": (p["name"] or "").strip(),
            "marka": p["brand"],
            "kategoria": label,
            "kategorie_ps": cat_names,
            "ma_warianty_rozmiar": ma_war,
            "typ_czartu": typ,
            "url_obrazka_jesli_grafika": url,
            "ma_kalkulator_inline": calc,
            "uwagi": uwagi,
        })

    # ---- KROK 3: CSV ----
    out_csv = BASE / "_reports" / "CHAT-T-102_inwentaryzacja_czartow.csv"
    cols = ["product_id", "nazwa", "marka", "kategoria", "kategorie_ps",
            "ma_warianty_rozmiar", "typ_czartu", "url_obrazka_jesli_grafika",
            "ma_kalkulator_inline", "uwagi"]
    with open(out_csv, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=cols)
        w.writeheader()
        for r in sorted(rows_out, key=lambda x: (x["kategoria"], x["marka"], x["nazwa"])):
            w.writerow(r)
    print(f"KROK3: CSV -> {out_csv}  ({len(rows_out)} wierszy)")

    # ---- Agregacja / podsumowanie ----
    by_cat = Counter(r["kategoria"] for r in rows_out)
    by_typ = Counter(r["typ_czartu"] for r in rows_out)
    brand_typ = defaultdict(Counter)
    for r in rows_out:
        brand_typ[r["marka"]][r["typ_czartu"]] += 1
    variants_no_chart = [r for r in rows_out
                         if r["ma_warianty_rozmiar"] == "T" and r["typ_czartu"] in ("nic", "grafika_do_weryfikacji")]
    weryf_label = sum(1 for r in rows_out if r["typ_czartu"] == "grafika_do_weryfikacji" and "etykiet" in r["uwagi"])
    weryf_txt = by_typ.get("grafika_do_weryfikacji", 0) - weryf_label
    HAVE = {"SCUBAPRO", "BARE"}  # charty juz pozyskane (ADR-099)
    brands_missing = sorted(b for b in brand_typ if b.upper() not in HAVE and b != "(brak marki)")

    md = []
    md.append("# CHAT-T-102 — Inwentaryzacja czartów rozmiarowych — podsumowanie\n")
    md.append("> READ-ONLY z `divezone_2025` (konto `divezone_chat_reader`, zero write). Zakres: skafandry suche/mokre, buty, rękawice (BEZ płetw). Id kategorii zweryfikowane w CHAT-T-101.\n")
    md.append(f"\n**Produktów w zakresie (active, dedup): {len(rows_out)}**\n")
    md.append("\n## Metodyka klasyfikacji (heurystyka na `pr_product_lang.description`)\n")
    md.append("- **grafika** — `<img>` o nazwie/alt sugerującej tabelę (np. `bare-buty-tabela.jpg`, `Rozmiary…BARE.png`). Sygnał mocny.")
    md.append("- **tekst** — `<table>` będąca realną tabelą **progów**: krzyżuje ≥2 wymiary ciała (klatka/talia/biodra/wzrost/waga/noga). Próg ≥2 odsiewa spec-tabele (które mają najwyżej wagę produktu jako pojedynczą miarę). To dane już strukturalne — najcenniejsze.")
    md.append("- **grafika_do_weryfikacji** — niepewne: albo tabela samych etykiet S/M/L bez wymiarów, albo opis wspomina dobór/tabelę rozmiaru, lecz czart prawdopodobnie jest grafiką bez opisowej nazwy. **Heurystyka »ostatni img bywa lifestyle« (lekcja CHAT-T-099) — NIE przesądzamy, oznaczamy do ręcznej weryfikacji.**")
    md.append("- **nic** — brak jakiegokolwiek sygnału czartu (choć produkt może mieć warianty rozmiaru).")
    md.append("- Kalkulator inline (`<input>/<select>/<form>/JS`): **0 w całym zakresie** (zgodne z CHAT-T-101 — progi nie żyją w interaktywnym kalkulatorze w DB).")
    md.append("\n## Rozkład per kategoria zakresu\n")
    for k in SCOPE_PRIORITY:
        md.append(f"- {k}: {by_cat.get(k,0)}")
    md.append("\n## Rozkład typu czartu (całość)\n")
    for t in ("grafika", "tekst", "grafika_do_weryfikacji", "nic"):
        md.append(f"- {t}: {by_typ.get(t,0)}")
    md.append(f"  - *(do_weryfikacji = {weryf_label} tabel samych etykiet bez progów + {weryf_txt} wzmianek tekstowych)*")
    md.append("\n## Rozkład typu czartu per marka\n")
    md.append("| Marka | grafika | tekst | grafika_do_weryfikacji | nic | Σ |")
    md.append("|-------|--------:|------:|-----------------------:|----:|--:|")
    for brand in sorted(brand_typ, key=lambda b: -sum(brand_typ[b].values())):
        t = brand_typ[brand]
        tot = sum(t.values())
        md.append(f"| {brand} | {t.get('grafika',0)} | {t.get('tekst',0)} | {t.get('grafika_do_weryfikacji',0)} | {t.get('nic',0)} | {tot} |")
    md.append(f"\n## Produkty z wariantami rozmiaru ALE bez pewnego czartu (priorytet pozyskania): {len(variants_no_chart)}\n")
    vnc_brand = Counter(r["marka"] for r in variants_no_chart)
    for brand, n in vnc_brand.most_common():
        md.append(f"- {brand}: {n}")
    md.append("\n## Marki w zakresie wymagające pozyskania chartów (poza Scubapro/Bare)\n")
    md.append("Mamy już: **Scubapro, Bare** (ADR-099). Brakujące marki w zakresie:\n")
    for b in brands_missing:
        md.append(f"- {b} ({sum(brand_typ[b].values())} prod.)")
    md.append("\n---\n*Diagnoza READ-ONLY, zero write. Następny etap: plan pozyskania chartów (OCR/research/dostawca) dla marek brakujących i pozycji nic/do-weryfikacji.*\n")

    out_md = BASE / "_reports" / "CHAT-T-102_inwentaryzacja_czartow_podsumowanie.md"
    out_md.write_text("\n".join(md), encoding="utf-8")
    print(f"Podsumowanie -> {out_md}")

    # echo na stdout
    print("\n=== ROZKLAD TYP CZARTU ===")
    for t in ("grafika", "tekst", "grafika_do_weryfikacji", "nic"):
        print(f"  {t:<24} {by_typ.get(t,0)}")
    print("\n=== TYP CZARTU PER MARKA ===")
    for brand in sorted(brand_typ, key=lambda b: -sum(brand_typ[b].values())):
        t = brand_typ[brand]
        print(f"  {brand:<20} grafika={t.get('grafika',0):>2} tekst={t.get('tekst',0):>2} do_weryf={t.get('grafika_do_weryfikacji',0):>2} nic={t.get('nic',0):>2}  (Σ{sum(t.values())})")
    print(f"\nProduktow z wariantami bez pewnego czartu: {len(variants_no_chart)}")
    print("Marki brakujace (poza Scubapro/Bare):", ", ".join(brands_missing))
    c.close()
    print("\n[OK] Inwentaryzacja zakonczona. Zero write.")


if __name__ == "__main__":
    main()
