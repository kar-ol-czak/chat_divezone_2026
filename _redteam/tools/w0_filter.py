"""
w0_filter.py — Warstwa 0 deterministyczny regex pre-filter (ADR-060).

Wczytuje forbidden_terms.yaml + scenariusz. Sprawdza odpowiedź bota
(default: ostatnia tura; dla klas leak — cały transcript) przeciw regexom
KLAS wymienionych w `scenario.oracle.hard_fail_regex`. Trafienie = FAIL
z severity klasy. Transcript NIE leci do sędziego (early-fail, oszczędność).

Brak trafienia != PASS. Transcript leci dalej do W1.
"""
from __future__ import annotations

import re
from pathlib import Path
from typing import Iterable

import yaml

REPO_ROOT = Path(__file__).resolve().parents[2]
FORBIDDEN_TERMS = REPO_ROOT / "_redteam" / "domain_rules" / "forbidden_terms.yaml"

# Klasy gdzie sprawdzamy CAŁY transcript (nie tylko output) — wyciek może być w środku.
TRANSCRIPT_WIDE_CLASSES = {
    "system_prompt_leak_markers",
    "pii_leak_markers",
    "internal_raw_statuses",
}


def _compile_class(body: dict) -> list[tuple[re.Pattern, str]]:
    """Returns list of (compiled_pattern, note) for one class."""
    out: list[tuple[re.Pattern, str]] = []
    for pat in body.get("patterns", []) or []:
        regex = pat.get("regex", "")
        if not regex:
            continue
        flags = 0
        for ch in pat.get("flags", "") or "":
            flags |= {"i": re.IGNORECASE, "m": re.MULTILINE,
                      "s": re.DOTALL}.get(ch, 0)
        try:
            out.append((re.compile(regex, flags), pat.get("note", "")))
        except re.error:
            continue
    return out


def load_forbidden_terms() -> dict[str, dict]:
    """Returns {class_name: {severity, patterns: [(compiled, note), ...]}}."""
    with FORBIDDEN_TERMS.open("r", encoding="utf-8") as f:
        raw = yaml.safe_load(f)
    out: dict[str, dict] = {}
    for cls, body in (raw or {}).items():
        out[cls] = {
            "severity": body.get("severity", "S2"),
            "description": body.get("description", ""),
            "patterns": _compile_class(body),
        }
    return out


def _gather_text(transcript: list[dict], output: str,
                 wide: bool) -> Iterable[tuple[int, str]]:
    """Yields (turn_index, text) chunks to scan."""
    if wide:
        for entry in transcript or []:
            if "assistant" in entry:
                yield entry.get("turn", -1), entry["assistant"]
    else:
        # ostatnia tura = output
        last_turn = -1
        for entry in transcript or []:
            if "assistant" in entry:
                last_turn = entry.get("turn", last_turn)
        yield last_turn, output or ""


def check(scenario: dict, transcript: list[dict], output: str,
          forbidden: dict[str, dict] | None = None) -> dict:
    """
    Returns:
      {
        "hit": bool,
        "hits": [{"class", "severity", "pattern", "note", "turn", "match"}],
        "checked_classes": [...],
      }
    """
    forbidden = forbidden or load_forbidden_terms()
    refs = (scenario.get("oracle") or {}).get("hard_fail_regex") or []
    refs_classes = [r["class"] for r in refs if isinstance(r, dict) and "class" in r]

    hits: list[dict] = []
    for cls in refs_classes:
        body = forbidden.get(cls)
        if not body:
            continue  # validate_scenarios.py już to flaguje jako warning
        wide = cls in TRANSCRIPT_WIDE_CLASSES
        for turn, text in _gather_text(transcript, output, wide):
            for compiled, note in body["patterns"]:
                m = compiled.search(text)
                if m:
                    hits.append({
                        "class": cls,
                        "severity": body["severity"],
                        "pattern": compiled.pattern,
                        "note": note,
                        "turn": turn,
                        "match": m.group(0)[:120],
                    })
    return {
        "hit": bool(hits),
        "hits": hits,
        "checked_classes": refs_classes,
    }


if __name__ == "__main__":
    # sanity: load + show classes
    ft = load_forbidden_terms()
    for cls, body in ft.items():
        print(f"{cls:35s} {body['severity']}  patterns={len(body['patterns'])}")
