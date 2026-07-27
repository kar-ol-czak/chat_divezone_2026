#!/usr/bin/env python3
"""
validate_scenarios.py — walidator schematu scenariuszy red-team (T-023).

Sprawdza:
  1. Schema: wymagane pola obecne, typy poprawne.
  2. severity ∈ {S0, S1, S2}.
  3. category ∈ 10 klas + zgodność z katalogiem.
  4. id format ^[A-Z]+-\\d{3}$, unikalność globalna, prefiks per klasa.
  5. hard_fail_regex.class istnieje w forbidden_terms.yaml.
  6. expected.must_refuse=true dla wszystkich S0 (warning jeśli false).
  7. IDOR: referencje w treści attack_steps tylko z synthetic_orders.json.
  8. Regex w forbidden_terms.yaml kompiluje się (raz).
  9. phase ∈ {probe, reframe, authority_pressure, ratchet, override}.

Zwraca exit:
  0 — wszystko OK
  1 — błędy
  2 — tylko warningi
"""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

try:
    import yaml
except ImportError:
    print("FATAL: brak PyYAML. pip install pyyaml", file=sys.stderr)
    sys.exit(1)


REPO_ROOT = Path(__file__).resolve().parents[2]
SCENARIOS_DIR = REPO_ROOT / "_redteam" / "scenarios"
FORBIDDEN_TERMS = REPO_ROOT / "_redteam" / "domain_rules" / "forbidden_terms.yaml"
SYNTHETIC_ORDERS = REPO_ROOT / "_redteam" / "fixtures" / "synthetic_orders.json"

VALID_CATEGORIES = {
    "jailbreak_framing": "JAIL",
    "medical_out_of_scope": "MED",
    "hallucination_product": "HALLU",
    "domain_errors_diving": "DOMAIN",
    "data_leak_internal": "LEAK",
    "out_of_competence": "SCOPE",
    "sales_manipulation": "SALES",
    "indirect_prompt_injection": "INJECT",
    "function_calling_idor": "IDOR",
    "multilingual_encoding": "LANG",
}
VALID_SEVERITY = {"S0", "S1", "S2"}
VALID_PHASES = {"probe", "reframe", "authority_pressure", "ratchet", "override"}
VALID_STRATEGY_TYPE = {"scripted", "semi_scripted", "dynamic"}
ID_PATTERN = re.compile(r"^[A-Z]+-\d{3}$")
REQUIRED_FIELDS = {"id", "title", "category", "severity", "introduced_in",
                   "introduced_by", "target", "strategy", "attack_steps",
                   "oracle", "expected"}


def load_forbidden_classes() -> tuple[set[str], list[str]]:
    """Returns (set_of_class_names, list_of_regex_compile_errors)."""
    errors = []
    with FORBIDDEN_TERMS.open("r", encoding="utf-8") as f:
        data = yaml.safe_load(f)
    classes = set(data.keys())
    for cls, body in data.items():
        for pat in body.get("patterns", []) or []:
            regex = pat.get("regex", "")
            if not regex:
                continue
            flags = 0
            for ch in pat.get("flags", "") or "":
                flags |= {"i": re.IGNORECASE, "m": re.MULTILINE,
                          "s": re.DOTALL}.get(ch, 0)
            try:
                re.compile(regex, flags)
            except re.error as e:
                errors.append(f"forbidden_terms[{cls}] regex={regex!r}: {e}")
    return classes, errors


def load_synthetic_refs() -> set[str]:
    with SYNTHETIC_ORDERS.open("r", encoding="utf-8") as f:
        data = json.load(f)
    return {r["reference"] for r in data["fake_references"]}


def validate_one(path: Path, all_ids: dict[str, Path],
                 forbidden_classes: set[str],
                 synthetic_refs: set[str]) -> tuple[list[str], list[str], set[str]]:
    """Returns (errors, warnings, referenced_classes_missing_in_forbidden_terms)."""
    errs, warns = [], []
    missing_classes: set[str] = set()
    try:
        with path.open("r", encoding="utf-8") as f:
            scn = yaml.safe_load(f)
    except yaml.YAMLError as e:
        return ([f"{path}: YAML parse error: {e}"], [], missing_classes)
    if not isinstance(scn, dict):
        return ([f"{path}: root must be mapping"], [], missing_classes)

    # 1) required fields
    missing = REQUIRED_FIELDS - scn.keys()
    if missing:
        errs.append(f"{path}: missing required: {sorted(missing)}")

    scn_id = scn.get("id", "")
    category = scn.get("category", "")
    severity = scn.get("severity", "")

    # 2) severity
    if severity not in VALID_SEVERITY:
        errs.append(f"{path}: severity={severity!r} not in {VALID_SEVERITY}")

    # 3) category + directory match
    if category not in VALID_CATEGORIES:
        errs.append(f"{path}: category={category!r} not in {sorted(VALID_CATEGORIES)}")
    else:
        parent_dir = path.parent.name
        if parent_dir != category:
            errs.append(f"{path}: category={category!r} != parent dir {parent_dir!r}")

    # 4) id format + uniqueness + prefix match
    if not ID_PATTERN.match(scn_id):
        errs.append(f"{path}: id={scn_id!r} doesn't match ^[A-Z]+-\\d{{3}}$")
    else:
        if scn_id in all_ids:
            errs.append(f"{path}: duplicate id {scn_id} (also in {all_ids[scn_id]})")
        else:
            all_ids[scn_id] = path
        if category in VALID_CATEGORIES:
            expected_prefix = VALID_CATEGORIES[category]
            actual_prefix = scn_id.split("-", 1)[0]
            if actual_prefix != expected_prefix:
                errs.append(f"{path}: id prefix {actual_prefix!r} != expected "
                            f"{expected_prefix!r} for category {category!r}")

    # 5) hard_fail_regex classes
    oracle = scn.get("oracle") or {}
    for ref in (oracle.get("hard_fail_regex") or []):
        if not isinstance(ref, dict) or "class" not in ref:
            errs.append(f"{path}: hard_fail_regex entry must be {{class: <name>}}, got {ref!r}")
            continue
        cls = ref["class"]
        if cls not in forbidden_classes:
            missing_classes.add(cls)
            warns.append(f"{path}: hard_fail_regex.class={cls!r} not in forbidden_terms.yaml")

    # 6) S0 must_refuse warning
    expected = scn.get("expected") or {}
    if severity == "S0" and expected.get("must_refuse") is not True:
        # Some S0 are FAIL-checking (e.g. HALLU-002, IDOR-003, INJECT-001) where
        # the bot should respond normally — must_refuse=false is intentional.
        # Surface as warning, not error.
        warns.append(f"{path}: S0 with must_refuse={expected.get('must_refuse')!r} "
                     f"(intentional for tool-correctness S0? confirm)")

    # 7) IDOR: references in attack_steps content
    if category == "function_calling_idor":
        order_ref_pattern = re.compile(r"\b(?:TEST-FAKE-\S*|AODMYANNV0|\.\./\.\./\.\./etc/\S+)")
        for i, step in enumerate(scn.get("attack_steps") or []):
            content = step.get("content", "") if isinstance(step, dict) else ""
            for match in order_ref_pattern.findall(content):
                cleaned = match.rstrip(".,;:'\"`)")
                if cleaned not in synthetic_refs:
                    # tolerate enumeration patterns like TEST-FAKE-003..TEST-FAKE-010
                    # (used in IDOR-002 to simulate the attack); flag as warning, not error.
                    if cleaned.startswith("TEST-FAKE-") and cleaned not in synthetic_refs:
                        warns.append(f"{path}: step[{i}] references {cleaned!r} "
                                     f"not in synthetic_orders.json — OK only if "
                                     f"simulating enumeration attack")
                    else:
                        errs.append(f"{path}: step[{i}] references {cleaned!r} "
                                    f"not in synthetic_orders.json")

    # 9) phase enum
    for i, step in enumerate(scn.get("attack_steps") or []):
        if not isinstance(step, dict):
            errs.append(f"{path}: attack_steps[{i}] not a mapping")
            continue
        if "phase" in step and step["phase"] not in VALID_PHASES:
            errs.append(f"{path}: attack_steps[{i}].phase={step['phase']!r} "
                        f"not in {VALID_PHASES}")
        if step.get("role") != "user":
            errs.append(f"{path}: attack_steps[{i}].role must be 'user'")

    # extra: strategy.type
    strategy = scn.get("strategy") or {}
    stype = strategy.get("type")
    if stype not in VALID_STRATEGY_TYPE:
        errs.append(f"{path}: strategy.type={stype!r} not in {VALID_STRATEGY_TYPE}")

    return errs, warns, missing_classes


def main() -> int:
    if not SCENARIOS_DIR.is_dir():
        print(f"FATAL: {SCENARIOS_DIR} not found", file=sys.stderr)
        return 1

    forbidden_classes, ft_errors = load_forbidden_classes()
    if ft_errors:
        print("forbidden_terms.yaml errors:")
        for e in ft_errors:
            print(f"  ERROR: {e}")
        return 1

    synthetic_refs = load_synthetic_refs()

    yaml_files = sorted(SCENARIOS_DIR.rglob("*.yaml"))
    all_ids: dict[str, Path] = {}
    total_errs: list[str] = []
    total_warns: list[str] = []
    all_missing_classes: set[str] = set()

    for path in yaml_files:
        errs, warns, missing = validate_one(
            path, all_ids, forbidden_classes, synthetic_refs)
        total_errs.extend(errs)
        total_warns.extend(warns)
        all_missing_classes |= missing

    # Summary
    by_class: dict[str, list[str]] = {}
    by_severity: dict[str, int] = {"S0": 0, "S1": 0, "S2": 0}
    for sid, p in all_ids.items():
        cat = p.parent.name
        by_class.setdefault(cat, []).append(sid)
        with p.open("r", encoding="utf-8") as f:
            sev = (yaml.safe_load(f) or {}).get("severity", "?")
        by_severity[sev] = by_severity.get(sev, 0) + 1

    print(f"\n=== validate_scenarios.py — wynik ===")
    print(f"Plików YAML: {len(yaml_files)}")
    print(f"Unikalnych ID: {len(all_ids)}")
    print(f"Rozkład per klasa:")
    for cat in sorted(VALID_CATEGORIES):
        ids = sorted(by_class.get(cat, []))
        print(f"  {cat:30s} {len(ids):2d} : {', '.join(ids)}")
    print(f"Rozkład severity: S0={by_severity['S0']}, "
          f"S1={by_severity['S1']}, S2={by_severity['S2']}")
    print(f"\nErrors: {len(total_errs)}")
    for e in total_errs:
        print(f"  ERROR: {e}")
    print(f"\nWarnings: {len(total_warns)}")
    for w in total_warns:
        print(f"  WARN:  {w}")
    if all_missing_classes:
        print(f"\nBrakujące klasy w forbidden_terms.yaml (do uzupełnienia):")
        for c in sorted(all_missing_classes):
            print(f"  - {c}")

    if total_errs:
        return 1
    if total_warns:
        return 2
    return 0


if __name__ == "__main__":
    sys.exit(main())
