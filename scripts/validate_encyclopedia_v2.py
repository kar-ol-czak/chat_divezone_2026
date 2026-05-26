#!/usr/bin/env python3
"""
TASK-ENC-011c: Deterministic Validator for encyclopedia v2 JSON outputs.

Checks: evidence integrity, concept key links, domain rules (DIN/INT), completeness.
Status: GREEN / YELLOW / RED per haslo.

Uzycie:
    python3 scripts/validate_encyclopedia_v2.py --all
    python3 scripts/validate_encyclopedia_v2.py --concept AUTOMAT_ODDECHOWY
"""

import argparse
import json
import re
import sys
from pathlib import Path

PROJECT_ROOT = Path(__file__).resolve().parent.parent
DATA = PROJECT_ROOT / "data"
GEN_V2 = DATA / "encyclopedia" / "v3" / "gen_v2"
RAW_DIR = GEN_V2 / "raw"
EVIDENCE_DIR = GEN_V2 / "evidence"
VALIDATION_DIR = GEN_V2 / "validation"
CONCEPT_LIST_FILE = GEN_V2 / "concept_list.json"


def load_json(path: Path) -> dict | list:
    return json.loads(path.read_text(encoding="utf-8"))


def save_json(path: Path, data: dict | list):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, indent=2, ensure_ascii=False), encoding="utf-8")


# -------------------------------------------------------------------
# CHECK 1: Evidence integrity (fail = RED)
# -------------------------------------------------------------------

def check_evidence_integrity(gemini: dict, evidence: dict) -> dict:
    valid_ids = set(evidence.keys())
    fabricated = []

    # Synonimy
    for cat_name, items in gemini.get("synonyms", {}).items():
        if not isinstance(items, list):
            continue
        for item in items:
            eid = item.get("evidence_id", "")
            if eid and eid not in valid_ids:
                fabricated.append({"field": f"synonyms.{cat_name}", "id": eid, "text": item.get("text", "")})

    # Longtail
    for item in gemini.get("longtail_phrases", []):
        eid = item.get("evidence_id", "")
        if eid and eid not in valid_ids:
            fabricated.append({"field": "longtail", "id": eid, "text": item.get("text", "")})

    # Cross-sell
    for item in gemini.get("cross_sell", []):
        eid = item.get("evidence_id", "")
        if eid and eid not in valid_ids:
            fabricated.append({"field": "cross_sell", "id": eid, "text": item.get("product", "")})

    # FAQ evidence_ids
    for faq in gemini.get("faq", []):
        for eid in faq.get("evidence_ids", []):
            if eid and eid not in valid_ids:
                fabricated.append({"field": "faq", "id": eid, "text": faq.get("question", "")})

    return {"passed": len(fabricated) == 0, "fabricated": fabricated}


# -------------------------------------------------------------------
# CHECK 2: Concept key integrity (fail = YELLOW)
# -------------------------------------------------------------------

def check_concept_keys(gemini: dict, all_keys: set[str]) -> dict:
    broken = []

    for rk in gemini.get("related_concept_keys", []):
        if rk not in all_keys:
            broken.append({"field": "related_concept_keys", "key": rk})

    for item in gemini.get("not_to_confuse", []):
        ck = item.get("concept_key", "")
        if ck and ck not in all_keys:
            broken.append({"field": "not_to_confuse", "key": ck})

    for item in gemini.get("cross_sell", []):
        ck = item.get("concept_key", "")
        if ck and ck not in all_keys:
            broken.append({"field": "cross_sell", "key": ck})

    return {"passed": len(broken) == 0, "broken": broken}


# -------------------------------------------------------------------
# CHECK 3: Domain rules — DIN/INT (fail = YELLOW)
# -------------------------------------------------------------------

BAD_INT_PATTERNS = [
    r"INT\s+lub\s+DIN", r"DIN\s+lub\s+INT",
    r"DIN/INT\s+do\s+wyboru", r"kompatybilny\s+z\s+INT",
    r"INT\s+i\s+DIN", r"DIN\s+i\s+INT",
    r"w\s+wersji\s+INT",
]
OK_INT_PATTERNS = [
    r"INT\s+(jest\s+)?archaiczn", r"INT\s+(jest\s+)?martw",
    r"wyłącznie\s+DIN", r"jedyny\s+aktualny\s+standard",
    r"INT.*nieprodukowany", r"INT.*odradzamy",
    r"standard\s+INT.*archaiczn",
]


def check_din_int(gemini: dict) -> dict:
    # Zbierz caly tekst do sprawdzenia
    text_parts = [
        gemini.get("definition", ""),
        gemini.get("seller_notes", ""),
    ]
    for faq in gemini.get("faq", []):
        text_parts.append(faq.get("answer", ""))
    for param in gemini.get("purchase_parameters", []):
        text_parts.append(param.get("description", ""))
    for st in gemini.get("subtypes_client", []):
        text_parts.append(st.get("description", ""))
    for st in gemini.get("subtypes_technical", []):
        text_parts.append(st.get("description", ""))

    full_text = " ".join(text_parts)
    warnings = []

    # Czy w ogole mowi o INT?
    if not re.search(r"\bINT\b", full_text):
        return {"passed": True, "warnings": []}

    # Sprawdz bad patterns
    for pat in BAD_INT_PATTERNS:
        m = re.search(pat, full_text, re.IGNORECASE)
        if m:
            # Sprawdz czy kontekst nie jest OK
            context_start = max(0, m.start() - 100)
            context_end = min(len(full_text), m.end() + 100)
            context = full_text[context_start:context_end]
            is_ok = any(re.search(ok, context, re.IGNORECASE) for ok in OK_INT_PATTERNS)
            if not is_ok:
                warnings.append(f"INT as alternative: '{m.group()}'")

    return {"passed": len(warnings) == 0, "warnings": warnings}


# -------------------------------------------------------------------
# CHECK 4: Completeness (INFO — nie blokuje)
# -------------------------------------------------------------------

def check_completeness(gemini: dict) -> dict:
    counts = {
        "subtypes_client": len(gemini.get("subtypes_client", [])),
        "faq": len(gemini.get("faq", [])),
        "longtail": len(gemini.get("longtail_phrases", [])),
        "cross_sell": len(gemini.get("cross_sell", [])),
    }
    thresholds = {"subtypes_client": 3, "faq": 4, "longtail": 6, "cross_sell": 2}
    warnings = []
    for field, threshold in thresholds.items():
        if counts[field] < threshold:
            warnings.append(f"{field}: {counts[field]} (min {threshold})")

    return {**counts, "warnings": warnings}


# -------------------------------------------------------------------
# CHECK 5: Model self-reporting
# -------------------------------------------------------------------

def check_self_reporting(gemini: dict) -> tuple[list, list]:
    missing = gemini.get("missing_data", [])
    ungrounded = gemini.get("ungrounded_additions", [])
    return missing, ungrounded


# -------------------------------------------------------------------
# Evidence usage stats
# -------------------------------------------------------------------

def evidence_usage(gemini: dict, evidence: dict) -> dict:
    valid_ids = set(evidence.keys())
    used_ids = set()

    for cat_items in gemini.get("synonyms", {}).values():
        if not isinstance(cat_items, list):
            continue
        for item in cat_items:
            eid = item.get("evidence_id", "")
            if eid:
                used_ids.add(eid)
    for item in gemini.get("longtail_phrases", []):
        eid = item.get("evidence_id", "")
        if eid:
            used_ids.add(eid)
    for item in gemini.get("cross_sell", []):
        eid = item.get("evidence_id", "")
        if eid:
            used_ids.add(eid)
    for faq in gemini.get("faq", []):
        for eid in faq.get("evidence_ids", []):
            if eid:
                used_ids.add(eid)

    used_valid = used_ids & valid_ids
    unused = sorted(valid_ids - used_valid)

    return {
        "total_available": len(valid_ids),
        "total_used": len(used_valid),
        "unused": unused,
    }


# -------------------------------------------------------------------
# Validate single concept
# -------------------------------------------------------------------

def validate_concept(concept_key: str, all_keys: set[str]) -> dict:
    raw_file = RAW_DIR / f"{concept_key}.json"
    ev_file = EVIDENCE_DIR / f"{concept_key}.json"

    if not raw_file.exists():
        return {"concept_key": concept_key, "status": "RED",
                "error": f"Brak raw file: {raw_file}"}
    if not ev_file.exists():
        return {"concept_key": concept_key, "status": "RED",
                "error": f"Brak evidence file: {ev_file}"}

    gemini = load_json(raw_file)
    evidence = load_json(ev_file)

    # Checks
    ev_check = check_evidence_integrity(gemini, evidence)
    ck_check = check_concept_keys(gemini, all_keys)
    din_check = check_din_int(gemini)
    comp_check = check_completeness(gemini)
    missing, ungrounded = check_self_reporting(gemini)
    ev_usage = evidence_usage(gemini, evidence)

    # Status
    if not ev_check["passed"]:
        status = "RED"
    elif not ck_check["passed"] or not din_check["passed"]:
        status = "YELLOW"
    else:
        status = "GREEN"

    result = {
        "concept_key": concept_key,
        "status": status,
        "checks": {
            "evidence_integrity": ev_check,
            "concept_keys": ck_check,
            "domain_din_int": din_check,
            "completeness": comp_check,
        },
        "model_missing_data": missing,
        "model_ungrounded": [{"text": u.get("text", ""), "reason": u.get("reason", "")} for u in ungrounded],
        "evidence_usage": ev_usage,
    }

    return result


# -------------------------------------------------------------------
# Main
# -------------------------------------------------------------------

def main():
    parser = argparse.ArgumentParser(description="Validate encyclopedia v2 JSON outputs")
    parser.add_argument("--all", action="store_true")
    parser.add_argument("--concept", help="Single concept key")
    args = parser.parse_args()

    all_keys_list = load_json(CONCEPT_LIST_FILE)
    all_keys = set(all_keys_list)

    if args.concept:
        concepts = [args.concept]
    elif args.all:
        concepts = all_keys_list
    else:
        print("Podaj --all lub --concept KEY")
        sys.exit(1)

    VALIDATION_DIR.mkdir(parents=True, exist_ok=True)

    results = []
    green = yellow = red = 0
    total_fabricated = 0
    total_broken_keys = 0
    total_domain_warnings = 0
    total_evidence_available = 0
    total_evidence_used = 0

    for ck in concepts:
        result = validate_concept(ck, all_keys)
        results.append(result)

        save_json(VALIDATION_DIR / f"{ck}.json", result)

        status = result["status"]
        icon = {"GREEN": "G", "YELLOW": "Y", "RED": "R"}[status]
        fab_count = len(result.get("checks", {}).get("evidence_integrity", {}).get("fabricated", []))
        brk_count = len(result.get("checks", {}).get("concept_keys", {}).get("broken", []))
        comp = result.get("checks", {}).get("completeness", {})
        comp_warns = len(comp.get("warnings", []))

        print(f"  [{icon}] {ck}: fab={fab_count} brk={brk_count} comp_warn={comp_warns}")

        if status == "GREEN":
            green += 1
        elif status == "YELLOW":
            yellow += 1
        else:
            red += 1

        total_fabricated += fab_count
        total_broken_keys += brk_count
        total_domain_warnings += len(result.get("checks", {}).get("domain_din_int", {}).get("warnings", []))
        eu = result.get("evidence_usage", {})
        total_evidence_available += eu.get("total_available", 0)
        total_evidence_used += eu.get("total_used", 0)

    # Summary
    usage_rate = f"{total_evidence_used / total_evidence_available * 100:.1f}% ({total_evidence_used}/{total_evidence_available})" if total_evidence_available else "0%"

    red_concepts = [r for r in results if r["status"] == "RED"]
    yellow_concepts = []
    for r in results:
        if r["status"] == "YELLOW":
            reasons = []
            for brk in r["checks"]["concept_keys"].get("broken", []):
                reasons.append(f"broken key: {brk['key']} in {brk['field']}")
            for w in r["checks"]["domain_din_int"].get("warnings", []):
                reasons.append(f"DIN/INT: {w}")
            yellow_concepts.append({"key": r["concept_key"], "reasons": reasons})

    summary = {
        "total": len(concepts),
        "green": green,
        "yellow": yellow,
        "red": red,
        "red_concepts": [{"key": r["concept_key"],
                          "fabricated": r["checks"]["evidence_integrity"]["fabricated"]}
                         for r in red_concepts],
        "yellow_concepts": yellow_concepts,
        "total_fabricated_evidence": total_fabricated,
        "total_broken_concept_keys": total_broken_keys,
        "total_domain_warnings": total_domain_warnings,
        "evidence_usage_rate": usage_rate,
    }

    save_json(GEN_V2 / "validation_summary.json", summary)

    print(f"\n{'='*60}")
    print(f"VALIDATION SUMMARY")
    print(f"{'='*60}")
    print(f"Total: {len(concepts)}")
    print(f"GREEN:  {green}")
    print(f"YELLOW: {yellow}")
    print(f"RED:    {red}")
    print(f"Fabricated evidence: {total_fabricated}")
    print(f"Broken concept keys: {total_broken_keys}")
    print(f"Domain warnings: {total_domain_warnings}")
    print(f"Evidence usage: {usage_rate}")

    if red_concepts:
        print(f"\nRED concepts ({len(red_concepts)}):")
        for r in red_concepts:
            fabs = r["checks"]["evidence_integrity"]["fabricated"]
            print(f"  - {r['concept_key']}: {len(fabs)} fabricated")
            for f in fabs[:5]:
                print(f"      {f['field']}: {f['id']} ({f['text'][:40]})")

    if yellow_concepts:
        print(f"\nYELLOW concepts ({len(yellow_concepts)}):")
        for yc in yellow_concepts:
            print(f"  - {yc['key']}: {', '.join(yc['reasons'][:3])}")


if __name__ == "__main__":
    main()
