"""
CHAT-T-099 / ADR-099 — referencyjna implementacja algorytmu przedzialowego doboru
rozmiaru skafandra mokrego. To KONTRAKT LOGICZNY dla backendu (function calling).

ZASADA (ADR-099 pkt 4): NIE "najbliszy srodek". Dopasowanie przedzialowe, klatka
piersiowa (chest) jako wymiar WIODACY (P37a), reszta weryfikujaca. Gdy klatka wypada
miedzy rozmiary albo poza skale -> NIE zgaduj, podaj dwa najblizsze + konsultacja.
ZERO ekstrapolacji poza zakres tabeli (jak wypornosc).

Ta funkcja jest czysta (operuje na wierszach charta). Loader z PG ponizej (load_chart)
uzywany przez bota; backend (PHP ChatService) portuje TE logike — patrz handoff.

Self-test (NIE wymaga seedu na Railway — uzywa danych z generatora):
    python3 embeddings/size_matcher.py --selftest
Lookup na zywo (po zaaplikowaniu seedu 035 na Railway):
    python3 embeddings/size_matcher.py --brand Scubapro --gender M --chest 104 --height 182 --weight 88
"""
from __future__ import annotations

import argparse
import os
from collections import defaultdict
from pathlib import Path

# Wymiary weryfikujace (poza wiodaca klatka). Waga/wzrost rozrozniaja nakladajace sie chest.
VERIFY_DIMS = ["waist", "hip", "height", "weight", "leg"]


def build_sizes(rows: list[dict]) -> list[dict]:
    """Z listy wierszy (size_label, dimension, min_val, max_val, sort_order) buduje
    liste rozmiarow: [{label, full, sort, dims:{dim:(min,max)}}], posortowana sort_order.
    """
    by_label: dict[str, dict] = {}
    for r in rows:
        lbl = r["size_label"]
        s = by_label.setdefault(lbl, {"label": lbl, "full": r.get("size_full") or lbl,
                                       "sort": r.get("sort_order", 0), "dims": {}})
        s["dims"][r["dimension"]] = (float(r["min_val"]), float(r["max_val"]))
    return sorted(by_label.values(), key=lambda s: s["sort"])


def _in_range(val: float, rng: tuple[float, float]) -> bool:
    return rng[0] <= val <= rng[1]


def _dist_to_range(val: float, rng: tuple[float, float]) -> float:
    if val < rng[0]:
        return rng[0] - val
    if val > rng[1]:
        return val - rng[1]
    return 0.0


def match_size(sizes: list[dict], chest: float, *, waist=None, hip=None,
               height=None, weight=None) -> dict:
    """Algorytm przedzialowy. Zwraca dict:
       {decision: 'match'|'ambiguous'|'out_of_scale', sizes:[labels], reason, detail, consult:bool}
    """
    client = {"waist": waist, "hip": hip, "height": height, "weight": weight}
    given = {k: v for k, v in client.items() if v is not None}

    def verify_score(s: dict) -> tuple[int, int]:
        """(ile wymiarow weryfikujacych pasuje, ile sprawdzono)."""
        ok = checked = 0
        for dim, val in given.items():
            if dim in s["dims"]:
                checked += 1
                if _in_range(val, s["dims"][dim]):
                    ok += 1
        return ok, checked

    # 1. Rozmiary, w ktorych chest klienta ∈ [min,max].
    chest_hits = [s for s in sizes if "chest" in s["dims"] and _in_range(chest, s["dims"]["chest"])]

    if len(chest_hits) == 1:
        s = chest_hits[0]
        ok, checked = verify_score(s)
        if checked == 0 or ok * 2 >= checked:  # brak weryfikacji LUB wiekszosc zgodna
            return {"decision": "match", "sizes": [s["label"]], "consult": False,
                    "reason": "chest trafia w jeden rozmiar, wymiary weryfikujace zgodne",
                    "detail": {"verify_ok": ok, "verify_checked": checked}}
        # chest pasuje, ale reszta nie -> ostroznie, podaj + konsultacja
        return {"decision": "ambiguous", "sizes": [s["label"]], "consult": True,
                "reason": "chest trafia w rozmiar, ale wymiary weryfikujace sie nie zgadzaja",
                "detail": {"verify_ok": ok, "verify_checked": checked}}

    if len(chest_hits) >= 2:
        # 3. chest trafia w >=2 rozmiary -> rozroznij wzrostem/waga (potem reszta).
        scored = []
        for s in chest_hits:
            ok, checked = verify_score(s)
            scored.append((ok, checked, s))
        best_ok = max(s[0] for s in scored)
        winners = [s for (ok, checked, s) in scored if ok == best_ok]
        if len(winners) == 1 and best_ok > 0:
            return {"decision": "match", "sizes": [winners[0]["label"]], "consult": False,
                    "reason": "chest w kilku rozmiarach, rozroznienie po wzroscie/wadze",
                    "detail": {"verify_ok": best_ok}}
        # nadal niejednoznacznie -> podaj wszystkich kandydatow + konsultacja
        labels = [s["label"] for s in chest_hits]
        return {"decision": "ambiguous", "sizes": labels, "consult": True,
                "reason": "chest pasuje do kilku rozmiarow, brak jednoznacznego rozroznienia",
                "detail": {"candidates": labels}}

    # 4. chest NIE trafia w zaden -> NIE zgaduj. Dwa najblizsze po chest + konsultacja.
    by_dist = sorted(sizes, key=lambda s: _dist_to_range(chest, s["dims"].get("chest", (0, 0))))
    nearest = [s["label"] for s in by_dist[:2]]
    return {"decision": "out_of_scale", "sizes": nearest, "consult": True,
            "reason": "klatka piersiowa miedzy rozmiarami lub poza skala — bez ekstrapolacji",
            "detail": {"nearest": nearest}}


def is_pointwise(sizes: list[dict], dim: str = "height") -> bool:
    """Czy chart jest punktowy na wymiarze `dim` (min==max we wszystkich wierszach),
    np. chart dzieciecy Rebel (Scubapro/DZIECI) — pojedyncze wartosci wzrostu."""
    rows = [s for s in sizes if dim in s["dims"]]
    return bool(rows) and all(s["dims"][dim][0] == s["dims"][dim][1] for s in rows)


def match_pointwise(sizes: list[dict], value: float, dim: str = "height") -> dict:
    """Dobor po wymiarze PUNKTOWYM (CHAT-T-099b sekcja F.1 — chart dzieciecy Rebel).
    NIE BETWEEN: rozmiary to pojedyncze wartosci wzrostu. Logika:
      - trafienie dokladne -> 'match' (1 rozmiar),
      - wzrost MIEDZY dwoma rozmiarami -> 'boundary': dwa najblizsze + flaga graniczny
        (bot: pianka musi przylegac; wiekszy dopuszczalny jako swiadomy kompromis, nie default),
      - ponizej najmniejszego / powyzej najwiekszego -> 'out_of_scale' (zero ekstrapolacji).
    """
    pts = sorted([(s["dims"][dim][0], s) for s in sizes if dim in s["dims"]], key=lambda x: x[0])
    if not pts:
        return {"decision": "out_of_scale", "sizes": [], "consult": True, "graniczny": False,
                "reason": f"chart nie ma wymiaru {dim}", "detail": {}}

    exact = [s for v, s in pts if v == value]
    if exact:
        return {"decision": "match", "sizes": [exact[0]["label"]], "consult": False,
                "graniczny": False, "reason": f"{dim} trafia dokladnie w rozmiar",
                "detail": {dim: value}}

    lo, hi = pts[0][0], pts[-1][0]
    if value < lo or value > hi:
        nearest = sorted(pts, key=lambda x: abs(x[0] - value))[:2]
        return {"decision": "out_of_scale", "sizes": [s["label"] for _, s in nearest],
                "consult": True, "graniczny": False,
                "reason": f"{dim} poza skala dzieciecego charta — bez ekstrapolacji",
                "detail": {"nearest": [s["label"] for _, s in nearest]}}

    # value miedzy dwoma kolejnymi rozmiarami -> graniczny.
    for i in range(len(pts) - 1):
        if pts[i][0] < value < pts[i + 1][0]:
            pair = [pts[i][1]["label"], pts[i + 1][1]["label"]]
            return {"decision": "boundary", "sizes": pair, "consult": True, "graniczny": True,
                    "reason": "wzrost miedzy dwoma rozmiarami — dwa najblizsze, wiekszy to "
                              "swiadomy kompromis (pianka musi przylegac, decyzja rodzica)",
                    "detail": {"between": pair, dim: value}}
    # nie powinno sie zdarzyc (wartosci unikalne), ale defensywnie:
    return {"decision": "out_of_scale", "sizes": [pts[0][1]["label"]], "consult": True,
            "graniczny": False, "reason": "nierozstrzygniete", "detail": {}}


def load_aliases(brand: str, gender: str) -> dict:
    """Mapa alias_label -> canonical_label dla charta (CHAT-T-099b 45c). {} gdy brak."""
    import psycopg2
    import psycopg2.extras
    from dotenv import load_dotenv
    load_dotenv(Path(__file__).resolve().parent.parent / ".env")
    conn = psycopg2.connect(os.environ["DATABASE_URL"])
    try:
        cur = conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor)
        cur.execute("""
            SELECT a.alias_label, a.canonical_label
            FROM divechat_size_label_alias a
            JOIN divechat_size_charts c ON a.chart_id = c.id
            WHERE c.brand = %s AND c.gender = %s
        """, (brand, gender))
        return {r["alias_label"]: r["canonical_label"] for r in cur.fetchall()}
    finally:
        conn.close()


def resolve_label(label: str, aliases: dict) -> str:
    """Normalizacja etykiety wariantu PrestaShop do etykiety charta przez warstwe aliasow."""
    return aliases.get(label, label)


def load_chart(brand: str, gender: str) -> list[dict]:
    """Loader z PostgreSQL/Railway (po zaaplikowaniu seedu 035)."""
    import psycopg2
    import psycopg2.extras
    from dotenv import load_dotenv
    load_dotenv(Path(__file__).resolve().parent.parent / ".env")
    dsn = os.environ["DATABASE_URL"]
    conn = psycopg2.connect(dsn)
    try:
        cur = conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor)
        cur.execute("""
            SELECT r.size_label, r.size_full, r.dimension, r.min_val, r.max_val, r.sort_order
            FROM divechat_size_chart_rows r
            JOIN divechat_size_charts c ON r.chart_id = c.id
            WHERE c.brand = %s AND c.gender = %s
            ORDER BY r.sort_order
        """, (brand, gender))
        return [dict(r) for r in cur.fetchall()]
    finally:
        conn.close()


def _selftest():
    """Testuje logike na danych z generatora (bez PG)."""
    import gen_size_charts_seed as g

    def rows_for(table, dims, label_of, full_of):
        out = []
        for sort, row in enumerate(table, start=1):
            lbl, full = label_of(row[0]), full_of(row[0])
            for dim, (mn, mx) in zip(dims, row[1:]):
                out.append({"size_label": lbl, "size_full": full, "dimension": dim,
                            "min_val": mn, "max_val": mx, "sort_order": sort})
        return out

    scuba_m = build_sizes(rows_for(g.SCUBA_M, g.SCUBA_DIMS,
                                   lambda s: s.split(" - ")[0], lambda s: s))
    bare_k = build_sizes(rows_for(g.BARE_K, g.BARE_K_DIMS, lambda s: s, lambda s: None))

    cases = [
        # (opis, kwargs, oczekiwana decyzja, oczekiwany rozmiar/w zbiorze)
        ("Scubapro M chest 104 h182 w88 -> L", dict(chest=104, height=182, weight=88), "match", "L"),
        ("Scubapro M chest 98 h182 -> MT/M(L?) match", dict(chest=98, height=182), "match", None),
        ("Scubapro M chest 200 -> poza skala", dict(chest=200), "out_of_scale", None),
        ("Scubapro M chest 88 -> ponizej skali (min 91)", dict(chest=88), "out_of_scale", None),
        ("Bare K chest 88 h165 -> 6", dict(chest=88, height=165), "match", "6"),
    ]
    print("SELF-TEST algorytmu przedzialowego:")
    ok_all = True
    for desc, kw, exp_dec, exp_sz in cases:
        chart = scuba_m if "Scubapro" in desc else bare_k
        res = match_size(chart, **kw)
        good = res["decision"] == exp_dec and (exp_sz is None or exp_sz in res["sizes"])
        ok_all &= good
        flag = "OK " if good else "FAIL"
        print(f"  [{flag}] {desc}: -> {res['decision']} {res['sizes']} ({res['reason']})")

    # CHAT-T-099b: chart dzieciecy Rebel — wartosci punktowe (height).
    rebel = build_sizes([
        {"size_label": l, "size_full": None, "dimension": "height",
         "min_val": h, "max_val": h, "sort_order": i}
        for i, (l, h) in enumerate(
            [("XXS", 104), ("XS", 116), ("S", 128), ("M", 140), ("L", 152), ("XL", 164)], 1)
    ])
    assert is_pointwise(rebel, "height"), "rebel powinien byc punktowy"
    pcases = [
        ("DZIECI height 134 -> [S,M] graniczny", 134, "boundary", {"S", "M"}, True),
        ("DZIECI height 140 -> M", 140, "match", {"M"}, False),
        ("DZIECI height 170 -> out_of_scale (>XL)", 170, "out_of_scale", None, False),
        ("DZIECI height 100 -> out_of_scale (<XXS)", 100, "out_of_scale", None, False),
    ]
    print("SELF-TEST algorytmu punktowego (dzieciecy):")
    for desc, h, exp_dec, exp_set, exp_gran in pcases:
        res = match_pointwise(rebel, h, "height")
        good = (res["decision"] == exp_dec
                and (exp_set is None or set(res["sizes"]) == exp_set)
                and res["graniczny"] == exp_gran)
        ok_all &= good
        flag = "OK " if good else "FAIL"
        print(f"  [{flag}] {desc}: -> {res['decision']} {res['sizes']} graniczny={res['graniczny']}")
    print("WYNIK:", "wszystkie OK" if ok_all else "SA BLEDY")
    return ok_all


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--selftest", action="store_true")
    ap.add_argument("--brand"); ap.add_argument("--gender")
    ap.add_argument("--chest", type=float)
    ap.add_argument("--waist", type=float); ap.add_argument("--hip", type=float)
    ap.add_argument("--height", type=float); ap.add_argument("--weight", type=float)
    a = ap.parse_args()
    if a.selftest:
        raise SystemExit(0 if _selftest() else 1)
    if a.brand and a.gender:
        sizes = build_sizes(load_chart(a.brand, a.gender))
        # Chart dzieciecy / punktowy -> dobor po wzroscie (NIE chest).
        if a.gender == "DZIECI" or is_pointwise(sizes, "height"):
            if a.height is None:
                ap.error("chart dzieciecy/punktowy wymaga --height")
            print(match_pointwise(sizes, a.height, "height"))
        elif a.chest is not None:
            print(match_size(sizes, chest=a.chest, waist=a.waist, hip=a.hip,
                             height=a.height, weight=a.weight))
        else:
            ap.error("chart doroslego wymaga --chest")
    else:
        ap.print_help()
