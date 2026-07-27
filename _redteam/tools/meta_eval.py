"""
meta_eval.py — Cohen kappa + analiza rozbieżności sędzia LLM vs ekspert (T-025).

Parsuje:
  - _redteam/golden/golden_REVIEW.md (Karol wypełnia HUMAN_VERDICT/AXES/NOTE)
  - _redteam/golden/golden_set.jsonl (oryginalne werdykty sędziego)

Liczy:
  - Cohen kappa (3-klasy: pass/fail/uv) human vs W1 (lub final)
  - Confusion matrix per oś rubryki
  - Lista false-positive (human PASS / judge FAIL — najbardziej kosztowne dla bramki)
  - Lista false-negative (human FAIL / judge PASS — najgroźniejsze: ukryte luki)

Wyjście: _redteam/reports/meta_eval_YYYY-MM-DD.md (commitable, BEZ transcriptów —
tylko ID + werdykty + 1-zdaniowa nota Karola).
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path

PROJECT_ROOT = Path(__file__).resolve().parents[2]
GOLDEN_DIR = PROJECT_ROOT / "_redteam" / "golden"
REPORTS_DIR = PROJECT_ROOT / "_redteam" / "reports"

VALID_VERDICTS = {"PASS", "FAIL", "UV"}
JUDGE_TO_HUMAN = {"pass": "PASS", "fail": "FAIL", "unable_to_verify": "UV"}


def parse_review_md(md_path: Path) -> dict[str, dict]:
    """
    Parsuje golden_REVIEW.md. Per scenariusz wyciąga sekcję OCENA EKSPERCKA:
       HUMAN_VERDICT: <PASS|FAIL|UV>
       HUMAN_AXES_FAIL: <lista lub puste>
       HUMAN_NOTE: <1 zdanie>
    Zwraca {scenario_id: {verdict, axes, note}}.

    Tolerancyjnie — pomija scenariusze z niewypełnioną sekcją (HUMAN_VERDICT: <PASS...>).
    Akceptuje dwa formaty nagłówków sekcji (T-025c):
      - `## [N/T] <ID> — Title`          (auto-gen z build_golden_set.py)
      - `## <ID> (Sev / klasa)`           (eksport ręczny / oceniarka)
    """
    text = md_path.read_text(encoding="utf-8")
    section_re = re.compile(
        r"^##\s+(?:\[\d+/\d+\]\s+)?([A-Z]+-\d{3})\b",
        re.MULTILINE,
    )
    sections: list[tuple[str, int]] = [(m.group(1), m.start())
                                       for m in section_re.finditer(text)]
    sections.append(("__END__", len(text)))

    out: dict[str, dict] = {}
    for i, (sid, start) in enumerate(sections[:-1]):
        end = sections[i + 1][1]
        chunk = text[start:end]
        verdict = _extract_field(chunk, "HUMAN_VERDICT")
        axes_str = _extract_field(chunk, "HUMAN_AXES_FAIL")
        note = _extract_field(chunk, "HUMAN_NOTE")

        if not verdict:
            continue
        v = verdict.strip().upper()
        if v.startswith("<") or v not in VALID_VERDICTS:
            continue  # niewypełnione lub błędne

        axes = []
        if axes_str:
            # tolerujemy: "[]", "scope_adherence, safety_policy", "scope_adherence",
            cleaned = axes_str.strip().strip("[]").strip()
            if cleaned and not cleaned.startswith("<") and cleaned.lower() != "pusta":
                axes = [a.strip() for a in cleaned.split(",") if a.strip()]

        if note and (note.strip().startswith("<") or not note.strip()):
            note = ""

        out[sid] = {"verdict": v, "axes": axes, "note": (note or "").strip()}
    return out


def _extract_field(chunk: str, field: str) -> str | None:
    """Wyciąga wartość pola z bloku ```...```."""
    pattern = re.compile(rf"^{field}:\s*(.+?)$", re.MULTILINE)
    m = pattern.search(chunk)
    return m.group(1) if m else None


def load_jsonl(jsonl_path: Path) -> dict[str, dict]:
    """Returns {scenario_id: jsonl_entry}."""
    out: dict[str, dict] = {}
    with jsonl_path.open("r", encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            entry = json.loads(line)
            out[entry["scenario_id"]] = entry
    return out


def cohen_kappa(rater_a: list[str], rater_b: list[str],
                labels: list[str]) -> float:
    """Cohen kappa dla dwóch list etykiet. Założenie: równa długość, te same kategorie."""
    n = len(rater_a)
    if n == 0:
        return 0.0
    # observed agreement
    obs = sum(1 for a, b in zip(rater_a, rater_b) if a == b) / n
    # expected agreement (chance)
    p_a = Counter(rater_a)
    p_b = Counter(rater_b)
    exp = sum((p_a[l] * p_b[l]) for l in labels) / (n * n)
    if exp == 1.0:
        return 1.0
    return (obs - exp) / (1.0 - exp)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--review", default=str(GOLDEN_DIR / "golden_REVIEW.md"),
                        help="MD z ocenami eksperckimi")
    parser.add_argument("--jsonl", default=str(GOLDEN_DIR / "golden_set.jsonl"),
                        help="JSONL oryginalnych werdyktów sędziego")
    parser.add_argument("--out", help="path raportu MD (default: reports/meta_eval_YYYY-MM-DD.md)")
    parser.add_argument("--judge-source", choices=["final", "w1"], default="final",
                        help="czy porównywać z final_verdict (po W2 panel) czy z W1 (przed panelem)")
    args = parser.parse_args()

    review_path = Path(args.review)
    jsonl_path = Path(args.jsonl)

    if not review_path.exists():
        print(f"FATAL: {review_path} not found", file=sys.stderr)
        return 1
    if not jsonl_path.exists():
        print(f"FATAL: {jsonl_path} not found", file=sys.stderr)
        return 1

    human = parse_review_md(review_path)
    judge = load_jsonl(jsonl_path)

    common_ids = sorted(set(human.keys()) & set(judge.keys()))
    missing_human = sorted(set(judge.keys()) - set(human.keys()))

    if not common_ids:
        print("FATAL: brak wypełnionych ocen Karola — wypełnij golden_REVIEW.md",
              file=sys.stderr)
        return 2

    # Porównanie verdyktów
    human_v: list[str] = []
    judge_v: list[str] = []
    false_pos: list[dict] = []  # human PASS / judge FAIL — kosztowne dla bramki
    false_neg: list[dict] = []  # human FAIL / judge PASS — UKRYTE LUKI
    agreement_uv: list[dict] = []  # oba UV
    other_mismatch: list[dict] = []

    for sid in common_ids:
        h = human[sid]
        j = judge[sid]
        if args.judge_source == "w1":
            j_verdict_raw = (j.get("judge_w1_overall") or "").lower()
        else:
            j_verdict_raw = (j.get("judge_final_verdict") or "").lower()
        j_verdict = JUDGE_TO_HUMAN.get(j_verdict_raw, "UV")
        h_verdict = h["verdict"]
        human_v.append(h_verdict)
        judge_v.append(j_verdict)

        if h_verdict == j_verdict:
            if h_verdict == "UV":
                agreement_uv.append({"id": sid, "note": h["note"]})
            continue
        # mismatch
        entry = {
            "id": sid,
            "human": h_verdict,
            "judge": j_verdict,
            "human_axes": h["axes"],
            "judge_axes": j.get("judge_fail_axes", []),
            "human_note": h["note"],
            "category": j.get("category"),
            "severity": j.get("severity"),
            "judge_layer": j.get("judge_final_layer"),
        }
        if h_verdict == "PASS" and j_verdict == "FAIL":
            false_pos.append(entry)
        elif h_verdict == "FAIL" and j_verdict == "PASS":
            false_neg.append(entry)
        else:
            other_mismatch.append(entry)

    # Cohen kappa
    labels = ["PASS", "FAIL", "UV"]
    kappa_overall = cohen_kappa(human_v, judge_v, labels)

    # Per-class kappa (S0/S1/S2)
    kappa_by_sev: dict[str, dict] = {}
    for sev in ["S0", "S1", "S2"]:
        idx = [i for i, sid in enumerate(common_ids)
               if judge[sid].get("severity") == sev]
        if not idx:
            continue
        h_sub = [human_v[i] for i in idx]
        j_sub = [judge_v[i] for i in idx]
        agree = sum(1 for a, b in zip(h_sub, j_sub) if a == b)
        kappa_by_sev[sev] = {
            "n": len(idx),
            "kappa": cohen_kappa(h_sub, j_sub, labels),
            "agreement": agree / len(idx),
        }

    # Confusion matrix 3x3 (human → judge)
    confusion: dict[str, dict[str, int]] = defaultdict(lambda: defaultdict(int))
    for h, j in zip(human_v, judge_v):
        confusion[h][j] += 1

    # Per-os rozbieżności (które osie sędzia myli najczęściej)
    axis_mismatches: dict[str, Counter] = defaultdict(Counter)
    for entry in false_pos + false_neg + other_mismatch:
        # judge wskazał fail axes — Karol nie. lub odwrotnie
        for axis in entry["judge_axes"]:
            axis_mismatches["judge_only"][axis] += 1
        for axis in entry["human_axes"]:
            if axis not in entry["judge_axes"]:
                axis_mismatches["human_only"][axis] += 1

    # Raport MD
    now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M UTC")
    out_path = Path(args.out) if args.out else (
        REPORTS_DIR / f"meta_eval_{datetime.now(timezone.utc).strftime('%Y-%m-%d')}.md")
    REPORTS_DIR.mkdir(parents=True, exist_ok=True)

    md: list[str] = []
    md.append(f"# Meta-eval — sędzia LLM vs ekspert Karol")
    md.append("")
    md.append(f"**Wygenerowano:** {now}")
    md.append(f"**Źródło ocen Karola:** `{review_path.name}`")
    md.append(f"**Sędzia compared with:** `{args.judge_source}` "
              f"({'pełna kaskada W0/W1/W2' if args.judge_source == 'final' else 'tylko W1, pre-panel'})")
    md.append(f"**Scenariusze ocenione:** {len(common_ids)} / "
              f"{len(common_ids) + len(missing_human)} w golden_set")
    if missing_human:
        md.append(f"**Niewypełnione przez eksperta:** {len(missing_human)} "
                  f"({', '.join(missing_human[:10])}{'…' if len(missing_human) > 10 else ''})")
    md.append("")
    md.append("## Cohen kappa")
    md.append("")
    md.append(f"**Overall kappa = {kappa_overall:.3f}** (target: ≥ 0.7)")
    md.append("")
    interpretation = (
        "wybitna (≥0.81)" if kappa_overall >= 0.81 else
        "substancjalna (0.61-0.80)" if kappa_overall >= 0.61 else
        "umiarkowana (0.41-0.60)" if kappa_overall >= 0.41 else
        "słaba (0.21-0.40)" if kappa_overall >= 0.21 else
        "marginalna (<0.21)"
    )
    md.append(f"Interpretacja Landis-Koch: **{interpretation}**.")
    md.append("")
    if kappa_by_sev:
        md.append("Per severity:")
        md.append("")
        md.append("| Sev | N | Agreement | Kappa |")
        md.append("|---|---:|---:|---:|")
        for sev, d in kappa_by_sev.items():
            md.append(f"| {sev} | {d['n']} | {d['agreement']:.0%} | {d['kappa']:.3f} |")
        md.append("")
    md.append("## Confusion matrix")
    md.append("")
    md.append("| Human \\ Judge | PASS | FAIL | UV |")
    md.append("|---|---:|---:|---:|")
    for h_lbl in labels:
        row = [f"**{h_lbl}**"]
        for j_lbl in labels:
            row.append(str(confusion[h_lbl][j_lbl]))
        md.append("| " + " | ".join(row) + " |")
    md.append("")
    md.append(f"## False-positive sędziego (human PASS / judge FAIL) — {len(false_pos)}")
    md.append("")
    md.append("Najbardziej kosztowne dla bramki jakości — sędzia odrzuca poprawne odpowiedzi.")
    md.append("Klucz do kalibracji rubryki o brakujące polityki DiveZone.")
    md.append("")
    if false_pos:
        md.append("| ID | Sev | Klasa | Judge fail axes | Nota eksperta |")
        md.append("|---|---|---|---|---|")
        for e in false_pos:
            axes = ", ".join(e["judge_axes"][:4])
            note = e["human_note"].replace("|", "/").replace("\n", " ")[:200]
            md.append(f"| {e['id']} | {e['severity']} | {e['category']} | {axes} | {note} |")
        md.append("")
    else:
        md.append("_Brak false-positive — bramka nie odrzuca poprawnych odpowiedzi._")
        md.append("")
    md.append(f"## False-negative sędziego (human FAIL / judge PASS) — {len(false_neg)}")
    md.append("")
    md.append("**GROŹNIEJSZE niż false-positive** — sędzia przepuszcza realne luki bota.")
    md.append("")
    if false_neg:
        md.append("| ID | Sev | Klasa | Human fail axes | Nota eksperta |")
        md.append("|---|---|---|---|---|")
        for e in false_neg:
            axes = ", ".join(e["human_axes"][:4])
            note = e["human_note"].replace("|", "/").replace("\n", " ")[:200]
            md.append(f"| {e['id']} | {e['severity']} | {e['category']} | {axes} | {note} |")
        md.append("")
    else:
        md.append("_Brak false-negative — sędzia nie przepuszcza ukrytych luk._")
        md.append("")
    if other_mismatch:
        md.append(f"## Inne niezgodności (UV vs PASS/FAIL) — {len(other_mismatch)}")
        md.append("")
        md.append("| ID | Human | Judge | Sev | Klasa | Nota eksperta |")
        md.append("|---|---|---|---|---|---|")
        for e in other_mismatch:
            note = e["human_note"].replace("|", "/").replace("\n", " ")[:200]
            md.append(f"| {e['id']} | {e['human']} | {e['judge']} | {e['severity']} | {e['category']} | {note} |")
        md.append("")
    md.append("## Osie rubryki najczęściej mylone")
    md.append("")
    if axis_mismatches["judge_only"] or axis_mismatches["human_only"]:
        md.append("**Sędzia wskazał osie których ekspert nie wskazał** (potencjalne false-positive osi):")
        for axis, n in axis_mismatches["judge_only"].most_common():
            md.append(f"- `{axis}`: {n}x")
        md.append("")
        md.append("**Ekspert wskazał osie których sędzia nie wskazał** (potencjalne ukryte luki):")
        for axis, n in axis_mismatches["human_only"].most_common():
            md.append(f"- `{axis}`: {n}x")
        md.append("")
    else:
        md.append("_Brak rozbieżności per-oś (lub brak danych o osiach)._")
        md.append("")

    md.append("## Wnioski (do uzupełnienia ręcznie)")
    md.append("")
    md.append("- Polityki DiveZone do dopisania w rubryce W1 (na podstawie nota eksperta przy false-positive):")
    md.append("  - [ ] ...")
    md.append("- Czy kappa ≥ 0.7? Jeśli nie — co iterować w prompcie:")
    md.append("- Rekomendacja: czy potrzebna 2. runda golden set po kalibracji?")
    md.append("")

    out_path.write_text("\n".join(md), encoding="utf-8")

    # Konsola
    print(f"=== Meta-eval {now} ===")
    print(f"Scenariuszy ocenionych: {len(common_ids)} (niewypełnionych: {len(missing_human)})")
    print(f"Cohen kappa = {kappa_overall:.3f} ({interpretation})")
    print(f"False-positive: {len(false_pos)} | False-negative: {len(false_neg)} | Inne: {len(other_mismatch)}")
    print(f"Raport: {out_path}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
