"""
reparse_run.py — re-parse istniejącego runu z aktualnym normalizerem (T-024d).

Ładuje JSON runu, dla każdego rekordu re-normalizuje w1 + w2.verdicts,
przelicza W2 consensus, przelicza final_verdict i fail_axes, agreguje
i generuje nowy JSON + summary MD. **Bez wywołań LLM** — używa surowych
verdyktów zapisanych w polu w1.

Użycie:
  python reparse_run.py reports/run_NNN.json
    → reports/run_NNN_reparsed.json + reports/run_NNN_reparsed_summary.md
"""
from __future__ import annotations

import argparse
import copy
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import judges  # noqa: E402
import run_redteam  # noqa: E402


META_KEYS = ("_usage", "_judge_error", "_raw", "_safety_net",
             "scenario_id", "judge_model")


def _renormalize(verdict: dict | None) -> dict | None:
    """Re-normalize zachowując meta klucze."""
    if not verdict:
        return verdict
    # zachowaj tylko surowy szkielet (bez kluczy które normalizer dorobi)
    raw = {k: v for k, v in verdict.items() if k not in META_KEYS}
    meta = {k: v for k, v in verdict.items() if k in META_KEYS}
    normalized = judges._normalize_verdict(raw)
    # jeśli normalizator nie dał overall, to znaczy że to prawdziwy UV — wpisujemy failsafe
    if normalized.get("overall") not in ("pass", "fail", "unable_to_verify"):
        normalized = {
            **normalized,
            "overall": "unable_to_verify",
            "criteria": normalized.get("criteria") or [],
            "overall_confidence": 0.0,
            "summary": normalized.get("summary", ""),
            "_failsafe_offline_reparse": True,
        }
    normalized.update(meta)
    return normalized


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("src_json", help="reports/run_*.json")
    parser.add_argument("--out", help="docelowy JSON (default: <src>_reparsed.json)")
    args = parser.parse_args()

    src = Path(args.src_json)
    if not src.exists():
        print(f"FATAL: {src} not found", file=sys.stderr)
        return 1

    with src.open("r", encoding="utf-8") as f:
        data = json.load(f)
    records = copy.deepcopy(data["records"])

    changes: list[dict] = []
    for r in records:
        old_final = r.get("final_verdict")
        old_layer = r.get("final_layer")

        # Re-normalize W1
        r["w1"] = _renormalize(r.get("w1"))

        # Re-normalize W2 verdicts
        w2 = r.get("w2") or {}
        for jm, ver in (w2.get("verdicts") or {}).items():
            w2["verdicts"][jm] = _renormalize(ver)

        # Re-compute W2 consensus jeśli W2 było
        if w2.get("verdicts"):
            scenario_stub = {"id": r["scenario_id"], "severity": r["severity"]}
            opus = w2["verdicts"].get(judges.W2_OPUS_MODEL, {})
            gpt = w2["verdicts"].get(judges.W2_GPT_MODEL, {})
            w1_ver = r.get("w1") or {}
            w2["consensus"] = judges._aggregate_consensus(scenario_stub, w1_ver, opus, gpt)
            r["w2"] = w2

        # Re-compute final_verdict + fail_axes
        if old_layer == "w0":
            pass  # W0 hard hit zostaje
        elif r.get("w2"):
            consensus = (r["w2"] or {}).get("consensus") or {}
            r["final_verdict"] = consensus.get("overall", "unable_to_verify")
            r["fail_axes"] = [c["axis"] for c in (consensus.get("criteria") or [])
                              if c.get("decision") == "fail"]
        elif r.get("w1"):
            ov = (r["w1"] or {}).get("overall", "unable_to_verify")
            r["final_verdict"] = ov if isinstance(ov, str) else "unable_to_verify"
            r["fail_axes"] = [c["axis"] for c in (r["w1"].get("criteria") or [])
                              if c.get("decision") == "fail"]

        if old_final != r.get("final_verdict"):
            changes.append({
                "id": r["scenario_id"],
                "severity": r["severity"],
                "category": r["category"],
                "old": old_final,
                "new": r["final_verdict"],
                "layer": r.get("final_layer"),
            })

    agg = run_redteam._aggregate(records)
    data["records"] = records
    data["aggregate"] = agg
    data["_reparsed_at"] = "T-024d (5 form schemy + siatka bezpieczeństwa overall)"

    # Wyjście
    dst = Path(args.out) if args.out else src.with_name(src.stem + "_reparsed.json")
    with dst.open("w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2, default=str)
    md = dst.with_name(dst.stem + "_summary.md")
    run_redteam._write_summary_md(md, data["run_id"], records, agg,
                                  data.get("ground_truth_available", False))

    # Tabela zmian na konsoli
    print(f"=== Re-parse {src.name} ===")
    print(f"Zmian: {len(changes)} / {len(records)} rekordów")
    print()
    print(f"{'ID':14s} {'Sev':5s} {'Klasa':25s} {'Stary':22s} → {'Nowy':22s}")
    print("-" * 100)
    for ch in sorted(changes, key=lambda x: (x["severity"], x["id"])):
        old = ch["old"] or "?"
        new = ch["new"] or "?"
        print(f"{ch['id']:14s} {ch['severity']:5s} {ch['category']:25s} {old:22s} → {new:22s}")
    print()
    print(f"Pass/Fail/UV przed reparse: (z oryginalnego JSON)")
    old_agg = data.get("aggregate_original") or run_redteam._aggregate(
        copy.deepcopy(data["records"]))  # dla porównania szybkiego
    # właściwe stare liczby — z agregatu PRZED zmianami; już go nadpisalismy. Najprosciej:
    # przeladuj source dla statystyki przed
    with src.open("r", encoding="utf-8") as f:
        original = json.load(f)
    orig_agg = run_redteam._aggregate(original["records"])
    print(f"  Pass: S0={orig_agg['by_severity']['S0']['pass']}/{orig_agg['by_severity']['S0']['total']} "
          f"S1={orig_agg['by_severity']['S1']['pass']}/{orig_agg['by_severity']['S1']['total']} "
          f"S2={orig_agg['by_severity']['S2']['pass']}/{orig_agg['by_severity']['S2']['total']}")
    print(f"  Fail: S0={orig_agg['by_severity']['S0']['fail']} "
          f"S1={orig_agg['by_severity']['S1']['fail']} "
          f"S2={orig_agg['by_severity']['S2']['fail']}")
    print(f"  UV:   S0={orig_agg['by_severity']['S0']['uv']} "
          f"S1={orig_agg['by_severity']['S1']['uv']} "
          f"S2={orig_agg['by_severity']['S2']['uv']}")
    print(f"\nPo reparse:")
    print(f"  Pass: S0={agg['by_severity']['S0']['pass']}/{agg['by_severity']['S0']['total']} "
          f"S1={agg['by_severity']['S1']['pass']}/{agg['by_severity']['S1']['total']} "
          f"S2={agg['by_severity']['S2']['pass']}/{agg['by_severity']['S2']['total']}")
    print(f"  Fail: S0={agg['by_severity']['S0']['fail']} "
          f"S1={agg['by_severity']['S1']['fail']} "
          f"S2={agg['by_severity']['S2']['fail']}")
    print(f"  UV:   S0={agg['by_severity']['S0']['uv']} "
          f"S1={agg['by_severity']['S1']['uv']} "
          f"S2={agg['by_severity']['S2']['uv']}")
    print(f"\nZapisano: {dst} + {md}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
