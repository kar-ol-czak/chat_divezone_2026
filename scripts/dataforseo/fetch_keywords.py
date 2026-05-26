#!/usr/bin/env python3
"""
TASK-017: Pobieranie danych keyword z DataForSEO (Google Ads Keyword Planner)
dla ~90 seed keywords sprzętu nurkowego divezone.pl.

Wyniki: data/dataforseo/raw/*.json, data/dataforseo/processed/{csv,json,md}
"""

import csv
import json
import os
import sys
import time
from collections import defaultdict
from datetime import datetime
from pathlib import Path

import requests
from dotenv import load_dotenv

# --- Ścieżki ---
PROJECT_ROOT = Path(__file__).resolve().parent.parent.parent
RAW_DIR = PROJECT_ROOT / "data" / "dataforseo" / "raw"
PROCESSED_DIR = PROJECT_ROOT / "data" / "dataforseo" / "processed"

# --- Seed keywords (5 batchów po 20) ---
BATCHES = [
    # Batch 1: Oddychanie + butle
    [
        "automat nurkowy", "automat oddechowy", "pierwszy stopień automatu",
        "drugi stopień automatu", "octopus nurkowy", "zestaw automatów nurkowych",
        "wąż nurkowy LP", "wąż HP do manometru", "wąż do inflatora",
        "butla nurkowa", "butla aluminiowa nurkowa", "butla stage",
        "twinset nurkowy", "manifold nurkowy", "zawór butlowy",
        "złącze DIN nurkowe", "złącze INT yoke", "rebreather nurkowy",
        "nitrox nurkowy", "analizator tlenu nurkowy",
    ],
    # Batch 2: Kontrola pływalności + sidemount
    [
        "jacket nurkowy", "skrzydło nurkowe wing", "backplate nurkowy",
        "uprząż nurkowa harness", "system BPW nurkowy", "inflator nurkowy",
        "sidemount nurkowy", "balast nurkowy ciężarki", "pas balastowy nurkowy",
        "kieszenie balastowe zintegrowane", "manometr nurkowy", "konsola nurkowa",
        "kompas nurkowy", "komputer nurkowy", "transmiter ciśnienia nurkowy",
        "boja dekompresyjna DSMB", "szpulka nurkowa spool",
        "kołowrotek nurkowy reel", "karabinek nurkowy", "retractor nurkowy",
    ],
    # Batch 3: Ochrona termiczna + ochrona ciała
    [
        "pianka nurkowa mokra", "pianka nurkowa shorty", "pianka półsucha nurkowa",
        "suchy skafander nurkowy", "ocieplacz pod skafander", "kaptur nurkowy",
        "rękawice nurkowe", "suche rękawice nurkowe system",
        "buty nurkowe neoprenowe", "zawory suchego skafandra", "maska nurkowa",
        "maska nurkowa dwuszybowa", "maska pełnotwarzowa", "fajka nurkowa",
        "nóż nurkowy", "sekator nurkowy line cutter", "latarka nurkowa",
        "płetwy nurkowe", "płetwy paskowe nurkowe", "płetwy jet fins",
    ],
    # Batch 4: Akcesoria + konserwacja
    [
        "płetwy kaloszowe nurkowe", "sprężyny do płetw", "trymówka nurkowa",
        "szelki do butli stage", "worek wypornościowy lift bag",
        "światło chemiczne nurkowe", "klej neoprenowy",
        "antifog do maski nurkowej", "prep do maski nurkowej",
        "pasek do maski nurkowej", "zestaw serwisowy automatu",
        "smar silikonowy nurkowy", "torba nurkowa", "skrzynia nurkowa",
        "kamizelka do snorkelingu", "kamizelka ocieplająca nurkowa",
        "suszarka do skafandra", "wieszak nurkowy",
        "maska wieloszybowa nurkowa", "nożyce nurkowe",
    ],
    # Batch 5: Frazy ogólne + marki + intencje zakupowe
    [
        "sprzęt nurkowy sklep", "sprzęt do nurkowania", "wyposażenie nurka",
        "nurkowanie sprzęt dla początkujących", "sklep nurkowy online",
        "sprzęt nurkowy używany", "serwis automatów nurkowych",
        "kurs nurkowania OWD", "nurkowanie techniczne sprzęt",
        "nurkowanie w suchym skafandrze", "freediving sprzęt",
        "snorkeling zestaw", "aparat oddechowy nurkowy", "akwalung",
        "sprzęt nurkowy Mares", "sprzęt nurkowy Scubapro",
        "sprzęt nurkowy Apeks", "sprzęt nurkowy Aqualung",
        "sprzęt nurkowy Cressi", "zestaw do nurkowania kompletny",
    ],
]

# Kategorie do grupowania fraz w raporcie
CATEGORIES = {
    "Automaty oddechowe": ["automat", "oddechow", "octopus", "aparat oddechowy", "akwalung"],
    "Węże": ["wąż", "hose", "inflator"],
    "Butle": ["butla", "twinset", "manifold", "zawór butl", "DIN", "INT", "yoke", "stage"],
    "Rebreather / Nitrox": ["rebreather", "nitrox", "analizator tlen"],
    "BCD / Jacket / Wing": ["jacket", "skrzydło", "wing", "backplate", "BPW", "uprząż", "harness", "inflator"],
    "Sidemount": ["sidemount"],
    "Balast": ["balast", "ciężar", "kieszeni"],
    "Instrumenty": ["manometr", "konsola", "kompas", "komputer", "transmiter"],
    "Akcesoria techniczne": ["boja", "DSMB", "szpulka", "spool", "kołowrotek", "reel", "karabinek", "retractor"],
    "Pianki": ["pianka", "shorty", "półsuch"],
    "Skafandry suche": ["skafandr", "ocieplacz", "zawory suchego"],
    "Ochrona głowy / rąk / stóp": ["kaptur", "rękawic", "buty neopr"],
    "Maski": ["maska"],
    "Fajki": ["fajka", "snorkel"],
    "Noże / narzędzia": ["nóż", "sekator", "cutter", "nożyce"],
    "Latarki": ["latark"],
    "Płetwy": ["płetw", "jet fins", "sprężyn"],
    "Torby / transport": ["torba", "skrzynia"],
    "Konserwacja": ["serwis", "smar", "klej neopr", "suszark", "wieszak"],
    "Antifog / prep": ["antifog", "prep"],
    "Kamizelki": ["kamizelk"],
    "Ogólne / marki": ["sprzęt nurk", "sprzęt do nurk", "wyposażenie nurk", "sklep nurk",
                        "nurkowanie sprzęt", "Mares", "Scubapro", "Apeks", "Aqualung", "Cressi",
                        "zestaw do nurk", "freediving", "snorkeling"],
    "Szkolenia": ["kurs", "OWD"],
    "Trymówki / paski": ["trymówk", "pasek do mask", "szelki"],
    "Inne akcesoria": ["worek wyporn", "lift bag", "światło chem"],
}

COST_PER_REQUEST = 0.075
BUDGET = 50.0

MONTH_NAMES_PL = {
    1: "styczeń", 2: "luty", 3: "marzec", 4: "kwiecień",
    5: "maj", 6: "czerwiec", 7: "lipiec", 8: "sierpień",
    9: "wrzesień", 10: "październik", 11: "listopad", 12: "grudzień",
}


def setup_session(auth_base64: str) -> requests.Session:
    """Konfiguruje sesję HTTP z auth i retry."""
    session = requests.Session()
    session.headers.update({
        "Authorization": f"Basic {auth_base64}",
        "Content-Type": "application/json",
    })
    return session


def api_request(session: requests.Session, url: str, payload: list, max_retries: int = 3) -> dict | None:
    """Wykonuje request z retry i backoff."""
    for attempt in range(1, max_retries + 1):
        try:
            resp = session.post(url, json=payload, timeout=60)
            if resp.status_code == 200:
                data = resp.json()
                return data
            print(f"  [!] HTTP {resp.status_code} (próba {attempt}/{max_retries})")
        except requests.RequestException as e:
            print(f"  [!] Błąd połączenia: {e} (próba {attempt}/{max_retries})")

        if attempt < max_retries:
            wait = 2 ** attempt
            print(f"  ... czekam {wait}s przed retry")
            time.sleep(wait)

    print("  [BŁĄD] Wyczerpano próby!")
    return None


def parse_keywords(raw_data: dict) -> list[dict]:
    """Parsuje surową odpowiedź API na listę słów kluczowych.

    DataForSEO zwraca dane płasko w tasks[].result[] — każdy element result
    to bezpośrednio obiekt keyword z polami search_volume, cpc, etc.
    """
    keywords = []
    if not raw_data or "tasks" not in raw_data:
        return keywords

    for task in raw_data.get("tasks", []):
        status = task.get("status_code")
        if status != 20000:
            print(f"  [!] Task status {status}: {task.get('status_message')}")
            continue

        results = task.get("result") or []
        for item in results:
            kw = item.get("keyword", "")
            sv = item.get("search_volume")
            cpc = item.get("cpc")
            comp = item.get("competition")
            comp_idx = item.get("competition_index")

            # Sezonowość — peak month
            monthly = item.get("monthly_searches") or []
            peak_month = None
            peak_volume = None
            if monthly:
                best = max(monthly, key=lambda m: m.get("search_volume", 0) or 0)
                peak_month = best.get("month")
                peak_volume = best.get("search_volume")

            if kw:
                keywords.append({
                    "keyword": kw,
                    "search_volume": sv,
                    "cpc": cpc,
                    "competition": comp,
                    "competition_index": comp_idx,
                    "peak_month": peak_month,
                    "peak_volume": peak_volume,
                })
    return keywords


def categorize_keyword(keyword: str) -> str:
    """Przypisuje frazę do kategorii sprzętowej."""
    kw_lower = keyword.lower()
    for category, patterns in CATEGORIES.items():
        for pat in patterns:
            if pat.lower() in kw_lower:
                return category
    return "(bez kategorii)"


def generate_report(all_kw: list[dict], total_cost: float, stats: dict) -> str:
    """Generuje raport markdown."""
    lines = []
    lines.append("# Raport TASK-017: DataForSEO Keywords")
    lines.append(f"\nData: {datetime.now().strftime('%Y-%m-%d %H:%M')}")
    lines.append(f"\n## Podsumowanie")
    lines.append(f"- Unikalnych fraz: **{len(all_kw)}**")
    lines.append(f"- Requestów API: **{stats['requests']}**")
    lines.append(f"- Koszt: **${total_cost:.3f}** z ${BUDGET} budżetu")
    lines.append(f"- Źródła: site={stats.get('site', 0)}, batche={stats.get('batches', 0)}")

    # Top 50 wg wolumenu
    sorted_kw = sorted(all_kw, key=lambda k: k.get("search_volume") or 0, reverse=True)
    lines.append(f"\n## Top 50 fraz wg wolumenu")
    lines.append(f"| # | Keyword | Vol. | CPC | Comp. | Peak |")
    lines.append(f"|---|---------|------|-----|-------|------|")
    for i, kw in enumerate(sorted_kw[:50], 1):
        sv = kw.get("search_volume") or 0
        cpc = f"${kw['cpc']:.2f}" if kw.get("cpc") else "–"
        comp = kw.get("competition") or "–"
        pm = MONTH_NAMES_PL.get(kw.get("peak_month"), "–")
        lines.append(f"| {i} | {kw['keyword']} | {sv:,} | {cpc} | {comp} | {pm} |")

    # Grupowanie per kategoria
    by_cat = defaultdict(list)
    for kw in all_kw:
        cat = categorize_keyword(kw["keyword"])
        by_cat[cat].append(kw)

    lines.append(f"\n## Frazy per kategoria")
    for cat in sorted(by_cat.keys()):
        kws = by_cat[cat]
        total_vol = sum(k.get("search_volume") or 0 for k in kws)
        top3 = sorted(kws, key=lambda k: k.get("search_volume") or 0, reverse=True)[:3]
        top3_str = ", ".join(
            f"{k['keyword']} ({k.get('search_volume') or 0})" for k in top3
        )
        lines.append(f"\n### {cat} ({len(kws)} fraz, łączny vol: {total_vol:,})")
        lines.append(f"Top 3: {top3_str}")

    # Frazy bez kategorii
    uncat = by_cat.get("(bez kategorii)", [])
    if uncat:
        lines.append(f"\n## Frazy bez dopasowania ({len(uncat)})")
        lines.append("Mogą wskazywać brakujące kategorie:")
        for kw in sorted(uncat, key=lambda k: k.get("search_volume") or 0, reverse=True)[:30]:
            sv = kw.get("search_volume") or 0
            lines.append(f"- {kw['keyword']} (vol: {sv})")

    # Sezonowość
    lines.append(f"\n## Sezonowość — kategorie z peakiem letnim (V–IX)")
    summer_months = {5, 6, 7, 8, 9}
    for cat in sorted(by_cat.keys()):
        if cat == "(bez kategorii)":
            continue
        kws = by_cat[cat]
        with_peak = [k for k in kws if k.get("peak_month") in summer_months]
        if len(with_peak) > len(kws) * 0.4 and len(kws) >= 3:
            pct = len(with_peak) / len(kws) * 100
            lines.append(f"- **{cat}**: {pct:.0f}% fraz z peakiem V–IX")

    return "\n".join(lines)


def reprocess_from_raw():
    """Przetwarza istniejące pliki raw bez nowych requestów API."""
    print("=== REPROCESS: przetwarzam istniejące pliki raw ===\n")
    all_keywords = {}
    stats = {"requests": 0, "site": 0, "batches": 0}
    total_cost = 0.0

    # Site
    site_path = RAW_DIR / "keywords_for_site_divezone.json"
    if site_path.exists():
        data = json.loads(site_path.read_text(encoding="utf-8"))
        kws = parse_keywords(data)
        for kw in kws:
            kw["source"] = "site"
            all_keywords[kw["keyword"]] = kw
        stats["site"] = len(kws)
        stats["requests"] += 1
        total_cost += COST_PER_REQUEST
        print(f"[site] {len(kws)} fraz")

    # Batche
    for i in range(1, 6):
        batch_path = RAW_DIR / f"keywords_for_keywords_batch_{i}.json"
        if batch_path.exists():
            data = json.loads(batch_path.read_text(encoding="utf-8"))
            kws = parse_keywords(data)
            new_count = 0
            for kw in kws:
                kw["source"] = f"batch_{i}"
                if kw["keyword"] not in all_keywords:
                    all_keywords[kw["keyword"]] = kw
                    new_count += 1
            stats["batches"] += len(kws)
            stats["requests"] += 1
            total_cost += COST_PER_REQUEST
            print(f"[batch_{i}] {len(kws)} fraz ({new_count} nowych)")

    return all_keywords, total_cost, stats


def main():
    reprocess = "--reprocess" in sys.argv

    # Załaduj .env
    env_path = PROJECT_ROOT / ".env"
    if not env_path.exists():
        print(f"[BŁĄD] Brak pliku .env: {env_path}")
        sys.exit(1)
    load_dotenv(env_path)

    if reprocess:
        all_keywords, total_cost, stats = reprocess_from_raw()
    else:
        login = os.getenv("DATAFORSEO_API_LOGIN")
        auth_b64 = os.getenv("DATAFORSEO_API_PASSWORD-BASE64")
        if not login or not auth_b64:
            print("[BŁĄD] Brak DATAFORSEO credentials w .env")
            sys.exit(1)

        print(f"=== TASK-017: DataForSEO Keywords ===")
        print(f"Login: {login}")
        print(f"Batchów: {len(BATCHES)}, seed keywords: {sum(len(b) for b in BATCHES)}")
        print()

        session = setup_session(auth_b64)
        total_cost = 0.0
        total_requests = 0
        all_keywords = {}  # keyword -> dict (deduplikacja)
        stats = {"requests": 0, "site": 0, "batches": 0}

        # --- Krok 2: Keywords for Site ---
        print("[1/6] Keywords for Site: divezone.pl")
        url_site = "https://api.dataforseo.com/v3/keywords_data/google_ads/keywords_for_site/live"
        payload_site = [{"location_code": 2616, "language_code": "pl", "target": "divezone.pl"}]

        data_site = api_request(session, url_site, payload_site)
        total_requests += 1
        total_cost += COST_PER_REQUEST

        if data_site:
            raw_path = RAW_DIR / "keywords_for_site_divezone.json"
            raw_path.write_text(json.dumps(data_site, ensure_ascii=False, indent=2), encoding="utf-8")

            kws = parse_keywords(data_site)
            for kw in kws:
                kw["source"] = "site"
                all_keywords[kw["keyword"]] = kw
            stats["site"] = len(kws)
            print(f"  → {len(kws)} fraz, koszt kumulowany: ${total_cost:.3f}")
        else:
            print("  → BRAK DANYCH (błąd API)")

        time.sleep(1)

        # --- Krok 3: Keywords for Keywords (5 batchów) ---
        url_kw = "https://api.dataforseo.com/v3/keywords_data/google_ads/keywords_for_keywords/live"

        for i, batch in enumerate(BATCHES, 1):
            print(f"[{i+1}/6] Keywords for Keywords — batch {i} ({len(batch)} seeds)")
            payload_kw = [{"location_code": 2616, "language_code": "pl", "keywords": batch}]

            data_batch = api_request(session, url_kw, payload_kw)
            total_requests += 1
            total_cost += COST_PER_REQUEST

            if data_batch:
                raw_path = RAW_DIR / f"keywords_for_keywords_batch_{i}.json"
                raw_path.write_text(json.dumps(data_batch, ensure_ascii=False, indent=2), encoding="utf-8")

                kws = parse_keywords(data_batch)
                new_count = 0
                for kw in kws:
                    kw["source"] = f"batch_{i}"
                    if kw["keyword"] not in all_keywords:
                        all_keywords[kw["keyword"]] = kw
                        new_count += 1
                stats["batches"] += len(kws)
                print(f"  → {len(kws)} fraz ({new_count} nowych), koszt kumulowany: ${total_cost:.3f}")
            else:
                print("  → BRAK DANYCH (błąd API)")

            if i < len(BATCHES):
                time.sleep(1)

        stats["requests"] = total_requests

    # --- Krok 4: Konsolidacja ---
    print(f"\n=== Konsolidacja ===")
    kw_list = sorted(all_keywords.values(), key=lambda k: k.get("search_volume") or 0, reverse=True)
    print(f"Unikalnych fraz: {len(kw_list)}")

    # CSV
    csv_path = PROCESSED_DIR / "all_keywords.csv"
    with open(csv_path, "w", newline="", encoding="utf-8") as f:
        writer = csv.DictWriter(f, fieldnames=[
            "keyword", "search_volume", "cpc", "competition",
            "competition_index", "peak_month", "peak_volume", "source",
        ])
        writer.writeheader()
        writer.writerows(kw_list)
    print(f"CSV: {csv_path}")

    # JSON
    json_path = PROCESSED_DIR / "all_keywords.json"
    json_path.write_text(json.dumps(kw_list, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"JSON: {json_path}")

    # --- Krok 5: Raport ---
    report = generate_report(kw_list, total_cost, stats)
    report_path = PROCESSED_DIR / "raport_keywords.md"
    report_path.write_text(report, encoding="utf-8")
    print(f"Raport: {report_path}")

    # Podsumowanie końcowe
    print(f"\n=== GOTOWE ===")
    print(f"Requestów: {stats['requests']}")
    print(f"Koszt: ${total_cost:.3f} z ${BUDGET} budżetu ({total_cost/BUDGET*100:.1f}%)")
    print(f"Unikalnych fraz: {len(kw_list)}")

    # Top 20
    print(f"\n--- Top 20 wg wolumenu ---")
    for i, kw in enumerate(kw_list[:20], 1):
        sv = kw.get("search_volume") or 0
        print(f"  {i:2d}. {kw['keyword']:40s} vol={sv:>6,}  src={kw['source']}")


if __name__ == "__main__":
    main()
