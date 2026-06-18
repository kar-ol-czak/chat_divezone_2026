"""
Generator seed SQL dla tabel rozmiarow skafandrow mokrych (CHAT-T-099, ADR-099).

ZRODLO PRAWDY: cztery tabele progow dostarczone przez dev (dev_ocr_2026-06),
uzyte WPROST (ADR-099 P42a) — bez ponownego OCR. Dane sa zaszyte ponizej jako
literaly Python (audytowalne 1:1 z task spec / ADR-099).

Long format (ADR-099 pkt 2): jeden wiersz = (chart, rozmiar, wymiar).
- Bare:     size_label == etykieta, size_full = NULL.
- Scubapro: "MT - 98" -> size_label='MT', size_full='MT - 98'.
- Bare damskie: wymiar `leg` (noga) wartosc punktowa -> min==max.

Emituje: sql/035_product_sizing_seed.sql

Uruchomienie:
    python3 embeddings/gen_size_charts_seed.py
"""
from pathlib import Path

SOURCE = "dev_ocr_2026-06"

# Kolejnosc wymiarow w wierszu danych ponizej.
# Bare meskie:  weight, height, chest, waist, hip
# Bare damskie: height, chest, waist, hip, leg   (BRAK weight)
# Scubapro:     chest, waist, hip, height, weight

UNIT = {
    "chest": "cm", "waist": "cm", "hip": "cm",
    "height": "cm", "leg": "cm", "weight": "kg",
}

# --- BARE MESKIE: (label, weight, height, chest, waist, hip) jako pary (min,max) ---
BARE_M = [
    ("S",    (61, 70),  (168, 173), (89, 94),   (74, 79),   (89, 94)),
    ("MS",   (63, 75),  (165, 170), (94, 99),   (79, 84),   (94, 99)),
    ("M",    (68, 79),  (173, 178), (94, 99),   (79, 84),   (94, 99)),
    ("MT",   (70, 80),  (180, 184), (94, 99),   (79, 84),   (94, 99)),
    ("MLS",  (72, 84),  (168, 173), (99, 104),  (84, 89),   (99, 104)),
    ("ML",   (77, 88),  (178, 183), (99, 104),  (84, 89),   (99, 104)),
    ("MLT",  (79, 91),  (183, 188), (99, 104),  (84, 89),   (99, 104)),
    ("LS",   (82, 93),  (170, 175), (104, 109), (89, 94),   (104, 109)),
    ("L",    (86, 98),  (180, 185), (104, 109), (89, 94),   (104, 109)),
    ("LT",   (88, 100), (185, 191), (104, 109), (89, 94),   (104, 109)),
    ("XLS",  (91, 102), (173, 178), (109, 114), (94, 99),   (109, 114)),
    ("XL",   (95, 107), (183, 188), (109, 114), (94, 99),   (109, 114)),
    ("XLT",  (98, 109), (188, 193), (109, 114), (94, 99),   (109, 114)),
    ("2XLS", (100, 111),(173, 178), (114, 119), (99, 104),  (114, 119)),
    ("2XL",  (104, 116),(185, 191), (114, 119), (99, 104),  (114, 119)),
    ("3XL",  (113, 125),(188, 193), (119, 124), (104, 109), (119, 124)),
]
BARE_M_DIMS = ["weight", "height", "chest", "waist", "hip"]

# --- BARE DAMSKIE: (label, height, chest, waist, hip, leg) ; leg punktowy -> (v,v) ---
BARE_K = [
    ("2",   (157, 163), (76, 81),   (58, 64),   (84, 89),   (75, 75)),
    ("4",   (160, 165), (81, 86),   (64, 69),   (89, 94),   (77, 77)),
    ("4T",  (165, 170), (81, 86),   (69, 69),   (89, 94),   (81, 81)),
    ("6",   (163, 168), (86, 91),   (69, 74),   (94, 99),   (79, 79)),
    ("6T",  (168, 173), (86, 91),   (69, 74),   (94, 99),   (84, 84)),
    ("6+",  (163, 168), (94, 99),   (84, 89),   (109, 114), (79, 79)),
    ("8",   (165, 170), (91, 97),   (74, 79),   (99, 104),  (81, 81)),
    ("8T",  (175, 180), (91, 97),   (74, 79),   (99, 104),  (86, 86)),
    ("8+",  (165, 170), (99, 104),  (89, 94),   (114, 119), (81, 81)),
    ("10",  (168, 173), (97, 102),  (79, 84),   (104, 109), (82, 82)),
    ("10+", (168, 173), (104, 109), (94, 99),   (119, 125), (82, 82)),
    ("12",  (170, 175), (102, 107), (84, 89),   (109, 114), (84, 84)),
    ("12+", (170, 175), (109, 114), (99, 104),  (125, 130), (84, 84)),
    ("14",  (173, 178), (107, 112), (89, 94),   (114, 119), (85, 85)),
]
BARE_K_DIMS = ["height", "chest", "waist", "hip", "leg"]

# --- SCUBAPRO MESKIE: (size_full, chest, waist, hip, height, weight) ---
SCUBA_M = [
    ("XS - 46",   (91, 96),   (79, 84),   (99, 104),  (170, 175), (70, 80)),
    ("S - 48",    (91, 96),   (79, 84),   (99, 104),  (175, 180), (70, 80)),
    ("ST - 94",   (91, 96),   (79, 84),   (99, 104),  (175, 185), (70, 85)),
    ("MS - 25",   (91, 96),   (79, 84),   (99, 104),  (170, 175), (75, 85)),
    ("M - 50",    (96, 101),  (84, 89),   (104, 109), (175, 180), (75, 85)),
    ("MT - 98",   (96, 101),  (84, 89),   (104, 109), (180, 185), (75, 85)),
    ("LS - 26",   (101, 107), (89, 94),   (109, 114), (175, 180), (80, 90)),
    ("L - 52",    (101, 107), (89, 94),   (109, 114), (180, 185), (80, 90)),
    ("LT - 102",  (101, 107), (89, 94),   (109, 114), (185, 190), (85, 95)),
    ("XLS - 27",  (107, 112), (94, 99),   (114, 119), (180, 185), (85, 95)),
    ("XL - 54",   (107, 112), (94, 99),   (114, 119), (185, 190), (85, 95)),
    ("XLT - 106", (107, 112), (94, 99),   (114, 119), (190, 195), (85, 95)),
    ("2XLS - 28", (112, 117), (99, 104),  (119, 124), (185, 190), (90, 100)),
    ("2XL - 56",  (112, 117), (99, 104),  (119, 124), (190, 195), (90, 100)),
    ("3XL - 58",  (117, 122), (104, 109), (124, 129), (195, 200), (95, 105)),
    ("4XL - 60",  (122, 129), (109, 114), (129, 135), (195, 200), (95, 110)),
]
SCUBA_DIMS = ["chest", "waist", "hip", "height", "weight"]

# --- SCUBAPRO DAMSKIE: (size_full, chest, waist, hip, height, weight) ---
SCUBA_K = [
    ("XS - 36",  (81, 86),   (69, 76),  (86, 91),   (155, 160), (45, 60)),
    ("S - 38",   (86, 91),   (71, 76),  (89, 94),   (160, 165), (50, 65)),
    ("ST - 76",  (86, 91),   (71, 76),  (89, 94),   (165, 170), (50, 65)),
    ("MS - 20",  (89, 94),   (76, 81),  (94, 99),   (160, 165), (55, 70)),
    ("M - 40",   (89, 94),   (76, 81),  (94, 99),   (165, 170), (55, 70)),
    ("MT - 80",  (89, 94),   (76, 81),  (94, 99),   (170, 175), (55, 70)),
    ("LS - 21",  (94, 99),   (81, 86),  (99, 104),  (165, 170), (60, 75)),
    ("L - 42",   (94, 99),   (81, 86),  (99, 104),  (170, 175), (60, 75)),
    ("LT - 84",  (94, 99),   (81, 86),  (99, 104),  (175, 180), (60, 75)),
    ("XLS - 22", (96, 101),  (84, 89),  (101, 107), (170, 175), (65, 80)),
    ("XL - 44",  (96, 101),  (84, 89),  (101, 107), (175, 180), (65, 80)),
    ("2XL - 46", (101, 107), (89, 94),  (107, 112), (180, 185), (70, 85)),
]


def sql_str(v):
    if v is None:
        return "NULL"
    return "'" + str(v).replace("'", "''") + "'"


def emit_rows(brand, gender, label_of, full_of, table, dims):
    """Zwraca liste krotek VALUES (brand,gender,size_label,size_full,dimension,min,max,unit,sort)."""
    out = []
    for sort, row in enumerate(table, start=1):
        first = row[0]
        label = label_of(first)
        full = full_of(first)
        ranges = row[1:]
        for dim, (mn, mx) in zip(dims, ranges):
            out.append((brand, gender, label, full, dim, mn, mx, UNIT[dim], sort))
    return out


def main():
    rows = []
    # Bare meskie: label == size, size_full = NULL
    rows += emit_rows("Bare", "M", lambda s: s, lambda s: None, BARE_M, BARE_M_DIMS)
    # Bare damskie: label == size, size_full = NULL
    rows += emit_rows("Bare", "K", lambda s: s, lambda s: None, BARE_K, BARE_K_DIMS)
    # Scubapro: size_full = pelne, label = czesc przed " - "
    rows += emit_rows("Scubapro", "M", lambda s: s.split(" - ")[0], lambda s: s, SCUBA_M, SCUBA_DIMS)
    rows += emit_rows("Scubapro", "K", lambda s: s.split(" - ")[0], lambda s: s, SCUBA_K, SCUBA_DIMS)

    n_rows = len(rows)
    n_charts = 4

    lines = []
    lines.append("-- ============================================")
    lines.append("-- DIVEZONE CHAT AI - Seed migracji 035 (CHAT-T-099, ADR-099)")
    lines.append("-- Charty rozmiarowe skafandrow mokrych: Scubapro M/K, Bare M/K.")
    lines.append("-- Data: 2026-06-18 | Zrodlo: dev_ocr_2026-06 (uzyte WPROST, ADR-099 P42a)")
    lines.append("--")
    lines.append(f"-- WYGENEROWANE przez embeddings/gen_size_charts_seed.py (NIE edytuj recznie).")
    lines.append(f"-- Charty: {n_charts} | Wiersze progow: {n_rows}")
    lines.append("--")
    lines.append("-- HARD STOP (ADR-099): seed to write na Railway PROD — wykonac po akceptacji Karola.")
    lines.append("-- Zaklada puste tabele (po migracji 035). Charty: ON CONFLICT DO NOTHING (idempotentne).")
    lines.append("-- ============================================")
    lines.append("")
    lines.append("-- 1) Charty (UNIQUE brand+gender).")
    lines.append("INSERT INTO divechat_size_charts (brand, gender, source, note) VALUES")
    lines.append(f"    ('Scubapro', 'M', '{SOURCE}', NULL),")
    lines.append(f"    ('Scubapro', 'K', '{SOURCE}', NULL),")
    lines.append(f"    ('Bare',     'M', '{SOURCE}', NULL),")
    lines.append(f"    ('Bare',     'K', '{SOURCE}', 'damskie: wymiar leg/noga wartosc punktowa (min==max)')")
    lines.append("ON CONFLICT (brand, gender) DO NOTHING;")
    lines.append("")
    lines.append("-- 2) Wiersze progow (long format), powiazane po (brand, gender) z chartem.")
    lines.append("INSERT INTO divechat_size_chart_rows")
    lines.append("    (chart_id, size_label, size_full, dimension, min_val, max_val, unit, sort_order)")
    lines.append("SELECT c.id, v.size_label, v.size_full, v.dimension, v.min_val, v.max_val, v.unit, v.sort_order")
    lines.append("FROM divechat_size_charts c")
    lines.append("JOIN (VALUES")
    val_lines = []
    for (brand, gender, label, full, dim, mn, mx, unit, sort) in rows:
        val_lines.append(
            f"    ({sql_str(brand)}, {sql_str(gender)}, {sql_str(label)}, {sql_str(full)}, "
            f"{sql_str(dim)}, {mn}, {mx}, {sql_str(unit)}, {sort})"
        )
    lines.append(",\n".join(val_lines))
    lines.append(") AS v(brand, gender, size_label, size_full, dimension, min_val, max_val, unit, sort_order)")
    lines.append("  ON c.brand = v.brand AND c.gender = v.gender;")
    lines.append("")

    out_path = Path(__file__).resolve().parent.parent / "sql" / "035_product_sizing_seed.sql"
    out_path.write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(f"Zapisano {out_path}")
    print(f"Charty: {n_charts} | Wiersze progow: {n_rows}")
    # sanity per chart
    from collections import Counter
    cnt = Counter((b, g) for (b, g, *_rest) in rows)
    for k, v in sorted(cnt.items()):
        print(f"  {k[0]}/{k[1]}: {v} wierszy")


if __name__ == "__main__":
    main()
