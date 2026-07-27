"""Walidacja i merge encyklopedii sprzętowej (TASK-014, Faza 3).

7 automatycznych gate'ów walidacji + lint terminologiczny.
"""

from __future__ import annotations

import argparse
import json
import logging
import re
from collections import defaultdict
from pathlib import Path
from typing import Optional

from pydantic import ValidationError

from models import ConceptEntry, GoldenTestSet

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
logger = logging.getLogger(__name__)

ROOT = Path(__file__).resolve().parent.parent.parent
DATA = ROOT / "data" / "encyclopedia"
RAW_DIR = DATA / "raw"

# Angielskie słowa dozwolone w PL (zakorzenione anglicyzmy)
PL_ALLOWED_EN = {
    "bcd", "smb", "dsmb", "din", "int", "nitrox", "trimix", "ean", "eanx",
    "spg", "ccr", "scr", "tmx", "nx", "hpg", "lpg", "jet", "sidemount",
    "backplate", "wing", "stage", "octopus", "octo", "yoke", "rebreather",
    "jacket", "inflator", "retractor", "bolt snap", "ao", "rdp",
}

# Proste EN słowa do detekcji w polach PL
EN_WORD_PATTERN = re.compile(
    r"\b(?:the|and|or|is|are|was|were|has|have|not|with|from|for|that|this|"
    r"which|when|what|how|can|will|should|must|may|diving|diver|scuba|"
    r"breathing|apparatus|valve|buoyancy|control|device|surface|marker|"
    r"pressure|gauge|cylinder|tank|safety|weight|belt|system|suit|"
    r"wetsuit|drysuit|mask|fin|snorkel|knife|light|torch|compass|"
    r"computer|hose|glove|boot|hood|spool|reel|line|cutter)\b",
    re.IGNORECASE,
)


class ValidationResult:
    def __init__(self, concept_key: str):
        self.concept_key = concept_key
        self.gates = {}
        self.errors = []
        self.warnings = []

    def pass_gate(self, gate: str, details: str = ""):
        self.gates[gate] = {"result": "pass", "details": details}

    def fail_gate(self, gate: str, details: str):
        self.gates[gate] = {"result": "fail", "details": details}
        self.errors.append(f"[{gate}] {details}")

    def warn_gate(self, gate: str, details: str):
        self.gates[gate] = {"result": "warning", "details": details}
        self.warnings.append(f"[{gate}] {details}")

    @property
    def passed(self) -> bool:
        return all(g["result"] != "fail" for g in self.gates.values())

    def to_dict(self) -> dict:
        return {
            "concept_key": self.concept_key,
            "gates": self.gates,
            "errors": self.errors,
            "warnings": self.warnings,
            "passed": self.passed,
        }


def load_raw_entries() -> dict[str, dict]:
    """Ładuje surowe JSONy z raw/."""
    entries = {}
    for path in sorted(RAW_DIR.glob("*.json")):
        if path.stem.endswith("_error"):
            continue
        data = json.loads(path.read_text(encoding="utf-8"))
        entries[path.stem] = data
    return entries


def load_concept_keys() -> set[str]:
    path = DATA / "concept_keys.json"
    data = json.loads(path.read_text(encoding="utf-8"))
    return {c["concept_key"] for c in data}


def load_golden_test_set() -> Optional[GoldenTestSet]:
    path = DATA / "golden_test_set.json"
    if not path.exists():
        return None
    return GoldenTestSet(**json.loads(path.read_text(encoding="utf-8")))


# --- GATE 1: Schema validation ---
def gate_schema(data: dict) -> tuple[Optional[ConceptEntry], str]:
    try:
        entry = ConceptEntry(**data)
        return entry, ""
    except ValidationError as e:
        return None, str(e)[:500]


# --- GATE 2: Language isolation ---
def gate_language(entry: ConceptEntry) -> list[str]:
    issues = []

    # Sprawdź synonimy PL na angielskie słowa
    for syn in entry.synonyms:
        if syn.language == "pl" and syn.type not in ("anglicyzm", "brand_term"):
            term_lower = syn.term.lower()
            if term_lower in PL_ALLOWED_EN:
                continue
            en_words = EN_WORD_PATTERN.findall(term_lower)
            if en_words:
                issues.append(f"PL synonim '{syn.term}' zawiera EN słowa: {en_words}")

    return issues


# --- GATE 3: Global synonym uniqueness ---
def gate_uniqueness(all_entries: dict[str, ConceptEntry]) -> dict[str, list[str]]:
    """Buduje index (language, term) → [concept_keys]. Zwraca konflikty."""
    index = defaultdict(list)
    for ck, entry in all_entries.items():
        for syn in entry.synonyms:
            if syn.type == "exact_synonym":
                key = (syn.language, syn.term.lower())
                index[key].append(ck)

    conflicts = {}
    for (lang, term), cks in index.items():
        if len(cks) > 1:
            conflicts[f"{lang}:{term}"] = cks

    return conflicts


# --- GATE 4: Symetria nie_mylic_z ---
def gate_symmetry(all_entries: dict[str, ConceptEntry]) -> list[str]:
    """Sprawdza czy A→B implikuje B→A."""
    missing = []

    for ck, entry in all_entries.items():
        for rel in entry.relations:
            if rel.type != "nie_mylic_z":
                continue
            target = rel.target_concept_key
            if target not in all_entries:
                missing.append(f"{ck} → {target}: target nie istnieje")
                continue

            target_entry = all_entries[target]
            has_reverse = any(
                r.type == "nie_mylic_z" and r.target_concept_key == ck
                for r in target_entry.relations
            )
            if not has_reverse:
                missing.append(f"{target} → {ck}: brak odwrotnej relacji (wymagana symetria)")

    return missing


# --- GATE 5: Min evidence ---
def gate_evidence(entry: ConceptEntry) -> str:
    sources = {e.source_id for e in entry.evidence}
    if len(entry.evidence) == 0:
        return "Brak evidencji"
    if len(sources) < 2:
        return f"Tylko 1 źródło: {sources}"
    return ""


# --- GATE 6: Cross-field consistency ---
def gate_cross_field(all_entries: dict[str, ConceptEntry]) -> list[str]:
    """Sprawdza spójność relacji nadrzedny/podrzedny."""
    issues = []

    for ck, entry in all_entries.items():
        for rel in entry.relations:
            target = rel.target_concept_key
            if target not in all_entries:
                continue

            target_entry = all_entries[target]

            if rel.type == "podrzedny":
                has_nadrzedny = any(
                    r.type == "nadrzedny" and r.target_concept_key == ck
                    for r in target_entry.relations
                )
                if not has_nadrzedny:
                    issues.append(f"{ck} podrzedny→{target} ale {target} nie ma nadrzedny→{ck}")

            elif rel.type == "nadrzedny":
                has_podrzedny = any(
                    r.type == "podrzedny" and r.target_concept_key == ck
                    for r in target_entry.relations
                )
                if not has_podrzedny:
                    issues.append(f"{ck} nadrzedny→{target} ale {target} nie ma podrzedny→{ck}")

    return issues


# --- GATE 7: Golden test regression ---
def gate_golden(
    all_entries: dict[str, ConceptEntry],
    golden: Optional[GoldenTestSet],
) -> list[str]:
    if not golden:
        return ["Brak golden test set"]

    issues = []

    # 7a: Pary mylące muszą mieć nie_mylic_z
    for pair in golden.contrast_pairs:
        a = pair.concept_a
        b = pair.concept_b

        if a in all_entries:
            has_rel = any(
                r.type == "nie_mylic_z" and r.target_concept_key == b
                for r in all_entries[a].relations
            )
            if not has_rel:
                issues.append(f"Brak nie_mylic_z: {a} → {b}")

    # 7b: misleading_term check
    for query in golden.customer_queries:
        if query.expected_flag != "misleading_term":
            continue
        concept = query.expected_concept
        if concept not in all_entries:
            continue

        # Szukaj czy termin z query jest w synonimach jako misleading_term
        entry = all_entries[concept]
        has_misleading = any(
            s.type == "misleading_term" for s in entry.synonyms
        )
        if not has_misleading:
            issues.append(
                f"Concept {concept} ma zapytanie misleading ('{query.query}') "
                f"ale brak misleading_term w synonimach"
            )

    return issues


# --- Lint terminologiczny ---
def lint_terminology(all_entries: dict[str, ConceptEntry]) -> list[str]:
    warnings = []

    # Buduj index synonimów
    syn_index = {}
    for ck, entry in all_entries.items():
        for syn in entry.synonyms:
            if syn.type == "exact_synonym":
                syn_index[(syn.language, syn.term.lower())] = ck

    for ck, entry in all_entries.items():
        # Termin w exact_synonym i jednocześnie w nie_mylic_z innego pojęcia
        for syn in entry.synonyms:
            if syn.type != "exact_synonym":
                continue
            for other_ck, other_entry in all_entries.items():
                if other_ck == ck:
                    continue
                for rel in other_entry.relations:
                    if rel.type == "nie_mylic_z" and rel.target_concept_key == ck:
                        # Ten synonim jest w exact_synonym CK ale CK jest w nie_mylic_z innego
                        pass  # OK — to jest poprawne, ck i other_ck się mylą

        # Pojęcie z grupy ryzyka bez nie_mylic_z
        has_nie_mylic = any(r.type == "nie_mylic_z" for r in entry.relations)
        if not has_nie_mylic:
            warnings.append(f"[lint] {ck}: brak relacji nie_mylic_z (rozważ dodanie)")

        # Zbyt ogólne terminy (1 słowo bez kontekstu)
        for syn in entry.synonyms:
            words = syn.term.split()
            if len(words) == 1 and syn.type == "exact_synonym" and syn.language == "pl":
                if syn.term.lower() not in PL_ALLOWED_EN:
                    warnings.append(f"[lint] {ck}: jednosłowny synonim PL '{syn.term}' — rozważ uściślenie")

    return warnings


# --- Auto-fix: symetria nie_mylic_z ---
def autofix_symmetry(all_entries: dict[str, ConceptEntry]) -> int:
    """Dodaje brakujące odwrotne relacje nie_mylic_z."""
    fixed = 0

    for ck, entry in list(all_entries.items()):
        for rel in entry.relations:
            if rel.type != "nie_mylic_z":
                continue
            target = rel.target_concept_key
            if target not in all_entries:
                continue

            target_entry = all_entries[target]
            has_reverse = any(
                r.type == "nie_mylic_z" and r.target_concept_key == ck
                for r in target_entry.relations
            )

            if not has_reverse:
                from models import Relation
                reverse_rel = Relation(
                    type="nie_mylic_z",
                    target_concept_key=ck,
                    why=f"Odwrotna relacja: {rel.why}" if rel.why else f"Symetryczna relacja do {ck}",
                    disambiguation_clues=rel.disambiguation_clues,
                )
                target_entry.relations.append(reverse_rel)
                fixed += 1

    return fixed


def run_validation(autofix: bool = True) -> dict:
    """Uruchamia pełną walidację."""
    raw_data = load_raw_entries()
    valid_keys = load_concept_keys()
    golden = load_golden_test_set()

    if not raw_data:
        logger.error("Brak plików w %s", RAW_DIR)
        return {}

    logger.info("Walidacja: %d wpisów (oczekiwane: %d)", len(raw_data), len(valid_keys))

    # GATE 1: Schema validation
    parsed = {}
    results = {}

    for ck, data in raw_data.items():
        vr = ValidationResult(ck)
        entry, error = gate_schema(data)

        if entry:
            vr.pass_gate("1_schema")
            parsed[ck] = entry
        else:
            vr.fail_gate("1_schema", error)

        results[ck] = vr

    logger.info("Gate 1 (schema): %d/%d pass", len(parsed), len(raw_data))

    # GATE 2: Language isolation
    for ck, entry in parsed.items():
        issues = gate_language(entry)
        if issues:
            results[ck].warn_gate("2_language", "; ".join(issues))
        else:
            results[ck].pass_gate("2_language")

    # GATE 3: Global synonym uniqueness
    conflicts = gate_uniqueness(parsed)
    for term, cks in conflicts.items():
        for ck in cks:
            if ck in results:
                results[ck].warn_gate("3_uniqueness", f"Duplikat exact_synonym '{term}' w: {cks}")

    for ck in parsed:
        if "3_uniqueness" not in results[ck].gates:
            results[ck].pass_gate("3_uniqueness")

    logger.info("Gate 3 (uniqueness): %d konfliktów", len(conflicts))

    # Auto-fix symetria PRZED Gate 4
    if autofix:
        fixed = autofix_symmetry(parsed)
        if fixed:
            logger.info("Auto-fix: dodano %d brakujących relacji nie_mylic_z", fixed)

    # GATE 4: Symetria nie_mylic_z
    sym_issues = gate_symmetry(parsed)
    reported_4 = set()
    for issue in sym_issues:
        ck = issue.split(" → ")[0] if " → " in issue else issue.split(":")[0]
        if ck in results:
            results[ck].warn_gate("4_symmetry", issue)
            reported_4.add(ck)

    for ck in parsed:
        if ck not in reported_4:
            results[ck].pass_gate("4_symmetry")

    logger.info("Gate 4 (symmetry): %d problemów", len(sym_issues))

    # GATE 5: Min evidence
    for ck, entry in parsed.items():
        issue = gate_evidence(entry)
        if issue:
            if "Brak evidencji" in issue:
                results[ck].fail_gate("5_evidence", issue)
            else:
                results[ck].warn_gate("5_evidence", issue)
                # Obniż confidence
                if entry.confidence == "high":
                    entry.confidence = "medium"
        else:
            results[ck].pass_gate("5_evidence")

    # GATE 6: Cross-field consistency
    cf_issues = gate_cross_field(parsed)
    reported_6 = set()
    for issue in cf_issues:
        ck = issue.split(" ")[0]
        if ck in results:
            results[ck].warn_gate("6_cross_field", issue)
            reported_6.add(ck)

    for ck in parsed:
        if ck not in reported_6:
            results[ck].pass_gate("6_cross_field")

    logger.info("Gate 6 (cross-field): %d problemów", len(cf_issues))

    # GATE 7: Golden test regression
    golden_issues = gate_golden(parsed, golden)
    reported_7 = set()
    for issue in golden_issues:
        # Wyciągnij concept_key z issue
        for ck in parsed:
            if ck in issue:
                results[ck].warn_gate("7_golden", issue)
                reported_7.add(ck)
                break

    for ck in parsed:
        if ck not in reported_7:
            results[ck].pass_gate("7_golden")

    logger.info("Gate 7 (golden test): %d problemów", len(golden_issues))

    # Lint terminologiczny
    lint_warnings = lint_terminology(parsed)
    logger.info("Lint: %d ostrzeżeń", len(lint_warnings))

    # Ustaw status
    for ck, entry in parsed.items():
        if results[ck].passed and not results[ck].warnings:
            entry.status = "validated"
        else:
            entry.status = "needs_review"

    # Podsumowanie
    validated = sum(1 for e in parsed.values() if e.status == "validated")
    needs_review = sum(1 for e in parsed.values() if e.status == "needs_review")
    missing = valid_keys - set(raw_data.keys())

    # Zapisz raport
    report = {
        "total_raw": len(raw_data),
        "total_parsed": len(parsed),
        "validated": validated,
        "needs_review": needs_review,
        "missing_concepts": sorted(missing),
        "synonym_conflicts": {k: v for k, v in conflicts.items()},
        "symmetry_issues": sym_issues,
        "golden_issues": golden_issues,
        "lint_warnings": lint_warnings,
        "per_concept": {ck: vr.to_dict() for ck, vr in results.items()},
    }

    report_path = DATA / "validation_report.json"
    report_path.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    logger.info("Raport: %s", report_path)

    # Zapisz zmergowaną encyklopedię
    encyclopedia = [entry.model_dump() for entry in parsed.values()]
    encyclopedia.sort(key=lambda x: x["concept_key"])

    enc_path = DATA / "equipment_encyclopedia.json"
    enc_path.write_text(json.dumps(encyclopedia, ensure_ascii=False, indent=2), encoding="utf-8")
    logger.info("Encyklopedia: %s (%d wpisów)", enc_path, len(encyclopedia))

    # Wydruk
    print(f"\n{'=' * 60}")
    print(f"WALIDACJA ENCYKLOPEDII - PODSUMOWANIE")
    print(f"{'=' * 60}")
    print(f"Plików raw: {len(raw_data)}/{len(valid_keys)}")
    print(f"Schema OK: {len(parsed)}/{len(raw_data)}")
    print(f"Validated: {validated}")
    print(f"Needs review: {needs_review}")
    print(f"Missing concepts: {len(missing)}")
    if missing:
        for m in sorted(missing):
            print(f"  - {m}")
    print(f"Synonym conflicts: {len(conflicts)}")
    print(f"Symmetry issues (po autofix): {len(sym_issues)}")
    print(f"Golden test issues: {len(golden_issues)}")
    print(f"Lint warnings: {len(lint_warnings)}")

    return report


def main():
    parser = argparse.ArgumentParser(description="Walidacja encyklopedii sprzętowej")
    parser.add_argument("--no-autofix", action="store_true", help="Nie naprawiaj automatycznie symetrii")
    args = parser.parse_args()

    run_validation(autofix=not args.no_autofix)


if __name__ == "__main__":
    main()
