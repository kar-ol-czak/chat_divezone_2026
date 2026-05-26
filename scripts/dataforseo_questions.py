#!/usr/bin/env python3
"""
TASK-ENC-006: Pobieranie pytań (PAA, Autocomplete, Related Searches)
z DataForSEO API dla seed keywords sprzętu nurkowego.

Użycie:
  python3 scripts/dataforseo_questions.py --phase test
  python3 scripts/dataforseo_questions.py --phase full
"""

import argparse
import csv
import json
import os
import sys
import time
from datetime import datetime
from pathlib import Path

import requests
from dotenv import load_dotenv

PROJECT_ROOT = Path(__file__).resolve().parent.parent
OUTPUT_DIR = PROJECT_ROOT / "data" / "dataforseo" / "questions"

# --- Seed keywords ---

PHASE1_SEEDS = {
    "test": [
        "automat nurkowy",
        "komputer nurkowy",
        "maska do nurkowania",
        "suchy skafander",
        "skrzydło nurkowe",
    ]
}

PHASE2_SEEDS = {
    "automaty": [
        "automat nurkowy", "regulator nurkowy", "octopus nurkowy",
        "pierwszy stopień automatu", "drugi stopień automatu",
    ],
    "weze": [
        "wąż nurkowy", "wąż LP", "wąż HP", "wąż do inflatora", "wąż miflex",
    ],
    "butle": [
        "butla nurkowa", "butla stalowa nurkowanie", "butla aluminiowa nurkowanie",
        "butla stage", "twinset nurkowy",
    ],
    "zawory": [
        "zawór do butli nurkowej", "manifold nurkowy", "zawór DIN",
    ],
    "bcd": [
        "skrzydło nurkowe", "jacket nurkowy", "kamizelka nurkowa",
        "BCD nurkowanie", "backplate wing",
    ],
    "balast": [
        "balast nurkowy", "kieszenie balastowe", "kieszenie trymujące",
        "ciężarki do nurkowania",
    ],
    "komputery": [
        "komputer nurkowy", "komputer nurkowy zegarkowy", "suunto nurkowanie",
        "shearwater peregrine", "garmin descent",
    ],
    "maski": [
        "maska do nurkowania", "maska nurkowa korekcyjna", "maska panoramiczna",
        "maska frameless", "zestaw maska fajka",
    ],
    "pianki": [
        "pianka do nurkowania", "pianka nurkowa 5mm", "pianka nurkowa 7mm",
        "shorty nurkowanie", "suchy skafander nurkowy",
    ],
    "ochrona_termiczna": [
        "buty nurkowe", "rękawice nurkowe", "kaptur nurkowy",
        "ocieplacz do suchego", "rękawice suche nurkowanie",
    ],
    "pletwy": [
        "płetwy nurkowe", "płetwy do nurkowania", "płetwy jet fin",
        "płetwy paskowe", "płetwy kaloszowe",
    ],
    "bezpieczenstwo": [
        "bojka dekompresyjna", "szpulka nurkowa", "nóż nurkowy",
        "kołowrotek nurkowy", "linka nurkowa",
    ],
    "latarki": [
        "latarka nurkowa", "latarka do nurkowania", "latarka backup nurkowanie",
    ],
    "kompasy_manometry": [
        "kompas nurkowy", "manometr nurkowy", "konsola nurkowa",
    ],
    "akcesoria": [
        "karabinki nurkowe", "retraktor nurkowy", "tabliczka nurkowa",
        "antifog do maski",
    ],
    "fotografia": [
        "obudowa podwodna smartfon", "fotografia podwodna",
        "latarka video nurkowa",
    ],
    "torby": [
        "torba nurkowa", "torba na automat", "torba na sprzęt nurkowy",
    ],
    "ogrzewanie": [
        "ogrzewanie nurkowe", "rękawice grzewcze nurkowanie",
    ],
    "sidemount": [
        "sidemount nurkowanie", "konfiguracja sidemount", "xdeep stealth",
    ],
    "ogolne": [
        "sprzęt do nurkowania", "sprzęt nurkowy sklep",
        "nurkowanie dla początkujących sprzęt",
        "jaki sprzęt do nurkowania", "kurs nurkowania sprzęt",
    ],
}

COST_SERP = 0.002
COST_AUTOCOMPLETE = 0.002  # autocomplete/live/advanced kosztuje tyle samo co SERP
BUDGET = 50.0


def setup_session(auth_base64: str) -> requests.Session:
    session = requests.Session()
    session.headers.update({
        "Authorization": f"Basic {auth_base64}",
        "Content-Type": "application/json",
    })
    return session


def api_request(session: requests.Session, url: str, payload: list,
                max_retries: int = 3) -> dict | None:
    for attempt in range(1, max_retries + 1):
        try:
            resp = session.post(url, json=payload, timeout=60)
            if resp.status_code == 200:
                return resp.json()
            print(f"    [!] HTTP {resp.status_code} (próba {attempt}/{max_retries})")
        except requests.RequestException as e:
            print(f"    [!] Błąd: {e} (próba {attempt}/{max_retries})")
        if attempt < max_retries:
            wait = 2 ** attempt
            print(f"    ... czekam {wait}s")
            time.sleep(wait)
    print("    [BŁĄD] Wyczerpano próby!")
    return None


def parse_serp_response(data: dict) -> tuple[list[str], list[str]]:
    """Parsuje SERP response — zwraca (pytania_PAA, frazy_related)."""
    paa_questions = []
    related_phrases = []

    if not data or "tasks" not in data:
        return paa_questions, related_phrases

    for task in data.get("tasks", []):
        if task.get("status_code") != 20000:
            print(f"    [!] SERP task status: {task.get('status_code')} {task.get('status_message')}")
            continue
        for result in (task.get("result") or []):
            for item in (result.get("items") or []):
                item_type = item.get("type", "")

                if item_type == "people_also_ask":
                    # PAA ma zagnieżdżone items z pytaniami
                    for sub in (item.get("items") or []):
                        title = sub.get("title", "").strip()
                        if title:
                            paa_questions.append(title)

                elif item_type == "related_searches":
                    for sub in (item.get("items") or []):
                        # Related searches items to stringi, nie obiekty
                        if isinstance(sub, str):
                            text = sub.strip()
                        else:
                            text = sub.get("title", "").strip()
                        if text:
                            related_phrases.append(text)

    return paa_questions, related_phrases


def parse_autocomplete_response(data: dict) -> list[str]:
    """Parsuje autocomplete response — zwraca listę sugestii."""
    suggestions = []
    if not data or "tasks" not in data:
        return suggestions

    for task in data.get("tasks", []):
        if task.get("status_code") != 20000:
            print(f"    [!] AC task status: {task.get('status_code')} {task.get('status_message')}")
            continue
        for result in (task.get("result") or []):
            for item in (result.get("items") or []):
                text = item.get("suggestion", "").strip()
                if not text:
                    text = item.get("title", "").strip()
                if text:
                    suggestions.append(text)

    return suggestions


def get_seed_group(keyword: str, seeds_dict: dict) -> str:
    """Znajduje grupę dla danego seed keyword."""
    for group, keywords in seeds_dict.items():
        if keyword in keywords:
            return group
    return "test"


def main():
    parser = argparse.ArgumentParser(description="TASK-ENC-006: DataForSEO Questions")
    parser.add_argument("--phase", choices=["test", "full"], required=True,
                        help="test = 5 seedów, full = ~100 seedów")
    args = parser.parse_args()

    env_path = PROJECT_ROOT / ".env"
    if not env_path.exists():
        print(f"[BŁĄD] Brak .env: {env_path}")
        sys.exit(1)
    load_dotenv(env_path)

    auth_b64 = os.getenv("DATAFORSEO_API_PASSWORD-BASE64")
    if not auth_b64:
        print("[BŁĄD] Brak DATAFORSEO credentials w .env")
        sys.exit(1)

    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)

    # Zbierz seed keywords
    if args.phase == "test":
        seeds_dict = PHASE1_SEEDS
        csv_name = "atp_questions_test.csv"
    else:
        seeds_dict = PHASE2_SEEDS
        csv_name = "atp_questions_all.csv"

    all_seeds = []
    for group, keywords in seeds_dict.items():
        for kw in keywords:
            all_seeds.append((kw, group))

    # Deduplikacja seedów (ten sam keyword może być w wielu grupach)
    seen_seeds = set()
    unique_seeds = []
    for kw, group in all_seeds:
        if kw not in seen_seeds:
            seen_seeds.add(kw)
            unique_seeds.append((kw, group))

    total_seeds = len(unique_seeds)
    print(f"=== TASK-ENC-006: DataForSEO Questions ({args.phase}) ===")
    print(f"Seedów: {total_seeds}")
    print(f"Szacowany koszt: ${total_seeds * (COST_SERP + COST_AUTOCOMPLETE):.2f}")
    print()

    session = setup_session(auth_b64)
    total_cost = 0.0
    total_requests = 0
    all_rows = []  # (seed, question_or_phrase, source, group)
    seen_questions = set()  # globalna deduplikacja

    stats = {"paa": 0, "autocomplete": 0, "related": 0, "empty_serp": 0, "empty_ac": 0}

    url_serp = "https://api.dataforseo.com/v3/serp/google/organic/live/advanced"
    url_ac = "https://api.dataforseo.com/v3/serp/google/autocomplete/live/advanced"

    for idx, (seed, group) in enumerate(unique_seeds, 1):
        print(f"[{idx}/{total_seeds}] {seed} (grupa: {group})")

        # --- SERP (PAA + Related) ---
        payload_serp = [{
            "keyword": seed,
            "language_code": "pl",
            "location_code": 2616,
            "depth": 100,
        }]
        data_serp = api_request(session, url_serp, payload_serp)
        total_requests += 1
        total_cost += COST_SERP

        paa_count = 0
        related_count = 0
        if data_serp:
            paa_questions, related_phrases = parse_serp_response(data_serp)
            for q in paa_questions:
                key = q.lower().strip()
                if key not in seen_questions:
                    seen_questions.add(key)
                    all_rows.append((seed, q, "PAA", group))
                    paa_count += 1
                    stats["paa"] += 1
            for r in related_phrases:
                key = r.lower().strip()
                if key not in seen_questions:
                    seen_questions.add(key)
                    all_rows.append((seed, r, "related", group))
                    related_count += 1
                    stats["related"] += 1
            if not paa_questions and not related_phrases:
                stats["empty_serp"] += 1
        else:
            stats["empty_serp"] += 1

        time.sleep(1)

        # --- Autocomplete ---
        payload_ac = [{
            "keyword": seed,
            "language_code": "pl",
            "location_code": 2616,
        }]
        data_ac = api_request(session, url_ac, payload_ac)
        total_requests += 1
        total_cost += COST_AUTOCOMPLETE

        ac_count = 0
        if data_ac:
            suggestions = parse_autocomplete_response(data_ac)
            for s in suggestions:
                key = s.lower().strip()
                if key not in seen_questions:
                    seen_questions.add(key)
                    all_rows.append((seed, s, "autocomplete", group))
                    ac_count += 1
                    stats["autocomplete"] += 1
            if not suggestions:
                stats["empty_ac"] += 1
        else:
            stats["empty_ac"] += 1

        print(f"    PAA={paa_count}, related={related_count}, AC={ac_count}  "
              f"(koszt: ${total_cost:.3f})")

        if idx < total_seeds:
            time.sleep(1)

    # --- Zapis CSV ---
    csv_path = OUTPUT_DIR / csv_name
    with open(csv_path, "w", newline="", encoding="utf-8") as f:
        writer = csv.writer(f)
        writer.writerow(["seed_keyword", "question_or_phrase", "source", "group"])
        for row in all_rows:
            writer.writerow(row)
    print(f"\nCSV: {csv_path} ({len(all_rows)} wierszy)")

    # --- Raport ---
    summary_lines = [
        f"# DataForSEO Questions — {args.phase}",
        f"Data: {datetime.now().strftime('%Y-%m-%d %H:%M')}",
        "",
        "## Podsumowanie",
        f"- Seed keywords: **{total_seeds}**",
        f"- Requestów API: **{total_requests}**",
        f"- Łączny koszt: **${total_cost:.3f}** z ${BUDGET} budżetu",
        f"- Unikalne pytania PAA: **{stats['paa']}**",
        f"- Unikalne autocomplete: **{stats['autocomplete']}**",
        f"- Unikalne related: **{stats['related']}**",
        f"- Razem unikalnych: **{len(all_rows)}**",
        f"- Puste SERP (brak PAA/related): {stats['empty_serp']}",
        f"- Puste autocomplete: {stats['empty_ac']}",
        "",
        "## Wyniki per grupa",
    ]

    # Per-group stats
    from collections import defaultdict
    by_group = defaultdict(lambda: {"PAA": 0, "autocomplete": 0, "related": 0})
    for _, _, source, group in all_rows:
        by_group[group][source] += 1

    summary_lines.append("| Grupa | PAA | AC | Related | Razem |")
    summary_lines.append("|-------|-----|----|---------|-------|")
    for group in sorted(by_group.keys()):
        g = by_group[group]
        total = g["PAA"] + g["autocomplete"] + g["related"]
        summary_lines.append(f"| {group} | {g['PAA']} | {g['autocomplete']} | {g['related']} | {total} |")

    # Top pytania PAA
    paa_rows = [r for r in all_rows if r[2] == "PAA"]
    if paa_rows:
        summary_lines.append("")
        summary_lines.append(f"## Przykładowe pytania PAA (pierwszych {min(30, len(paa_rows))})")
        for seed, q, _, group in paa_rows[:30]:
            summary_lines.append(f"- [{group}] **{q}** ← {seed}")

    summary_path = OUTPUT_DIR / "atp_summary.md"
    summary_path.write_text("\n".join(summary_lines), encoding="utf-8")
    print(f"Summary: {summary_path}")

    # Podsumowanie końcowe
    print(f"\n=== GOTOWE ===")
    print(f"Requestów: {total_requests}")
    print(f"Koszt: ${total_cost:.3f} z ${BUDGET} ({total_cost/BUDGET*100:.1f}%)")
    print(f"PAA: {stats['paa']}, AC: {stats['autocomplete']}, Related: {stats['related']}")
    print(f"Razem unikalnych: {len(all_rows)}")


if __name__ == "__main__":
    main()
