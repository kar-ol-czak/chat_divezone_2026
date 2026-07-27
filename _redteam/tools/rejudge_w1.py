"""
rejudge_w1.py — re-judge W1 dla istniejącego runu z NOWĄ rubryką (T-025c).

Ładuje reports/run_*.json (ma transcripty + W2 verdicts), dla każdego rekordu:
  1. Wywołuje judges.judge_w1(...) z aktualną rubryką w1_default_v1.md
  2. Re-aggregate W2 consensus z nowym W1 + istniejące opus/gpt verdicts (offline)
  3. Re-compute final_verdict + fail_axes
  4. Dump nowy run JSON + nowy golden_set.jsonl

Koszt: ~50 × W1 call gpt-5.4 ~ $0.50. W2 panel NIE wołany (kalibrujemy tylko W1).
"""
from __future__ import annotations

import argparse
import copy
import json
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import judges  # noqa: E402
import run_redteam  # noqa: E402
import w0_filter  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("src_json", help="reports/run_*.json")
    parser.add_argument("--out-json", required=True, help="docelowy run JSON")
    parser.add_argument("--out-jsonl", required=True, help="nowy golden_set.jsonl")
    args = parser.parse_args()

    src = Path(args.src_json)
    if not src.exists():
        print(f"FATAL: {src} not found", file=sys.stderr)
        return 1

    with src.open("r", encoding="utf-8") as f:
        data = json.load(f)
    records = copy.deepcopy(data["records"])
    forbidden = w0_filter.load_forbidden_terms()

    snapshot_path = Path(__file__).resolve().parents[2] / "_redteam" / "fixtures" / "catalog_snapshot.json"
    ground_truth_available = snapshot_path.exists()

    total_w1_in = 0
    total_w1_out = 0
    print(f"=== Re-judge W1 (nowa rubryka) {len(records)} scenariuszy ===\n")

    for i, r in enumerate(records, 1):
        sid = r["scenario_id"]
        sev = r.get("severity", "?")
        cat = r.get("category", "?")
        print(f"[{i:2d}/{len(records)}] {sid:14s} ({sev}/{cat}) ...", end=" ", flush=True)

        # Re-build scenario stub for judge_w1
        # Złóż dossier z dostępnych pól w rekordzie. Załaduj scenariusz YAML
        # dla pełnego kontekstu.
        scen_yaml = _load_scenario_yaml(sid)
        if not scen_yaml:
            print(f"SKIP — brak YAML")
            continue

        transcript = r.get("transcript", []) or []
        output = r.get("output", "") or ""
        tools_used = r.get("tools_used", []) or []
        w0_soft = (r.get("w0") or {}).get("soft_signals", [])

        t0 = time.time()
        w1 = judges.judge_w1(scen_yaml, transcript, output, tools_used,
                             ground_truth_available,
                             w0_soft_signals=w0_soft)
        usage = w1.get("_usage", {})
        total_w1_in += usage.get("input_tokens", 0)
        total_w1_out += usage.get("output_tokens", 0)
        elapsed = time.time() - t0

        r["w1"] = w1

        # Re-aggregate W2 consensus jeśli W2 verdicts istnieją
        w2 = r.get("w2") or {}
        verdicts = w2.get("verdicts") or {}
        if verdicts:
            scenario_stub = {"id": sid, "severity": sev}
            opus = verdicts.get(judges.W2_OPUS_MODEL, {})
            gpt = verdicts.get(judges.W2_GPT_MODEL, {})
            w2["consensus"] = judges._aggregate_consensus(scenario_stub, w1, opus, gpt)
            r["w2"] = w2

        # Re-compute final_verdict + fail_axes
        if r.get("final_layer") == "w0":
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

        # Sanity
        if not isinstance(r.get("final_verdict"), str):
            r["final_verdict"] = "unable_to_verify"

        mark = {"pass": "OK", "fail": "FAIL",
                "unable_to_verify": "UV"}.get(r["final_verdict"], "?")
        print(f"{mark} [{r.get('final_layer')}] {elapsed:.1f}s")

    # Dump nowy run JSON
    data["records"] = records
    data["aggregate"] = run_redteam._aggregate(records)
    data["_rejudged_at"] = "T-025c — re-judge W1 v1.2 (polityki DiveZone)"

    out_json = Path(args.out_json)
    out_json.parent.mkdir(parents=True, exist_ok=True)
    with out_json.open("w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2, default=str)

    # Nowy golden_set.jsonl
    out_jsonl = Path(args.out_jsonl)
    out_jsonl.parent.mkdir(parents=True, exist_ok=True)
    with out_jsonl.open("w", encoding="utf-8") as f:
        for r in records:
            entry = {
                "scenario_id": r.get("scenario_id"),
                "category": r.get("category"),
                "severity": r.get("severity"),
                "title": r.get("title"),
                "introduced_by": r.get("introduced_by"),
                "transcript_turns": r.get("transcript_turns"),
                "tools_used": r.get("tools_used"),
                "transcript": r.get("transcript"),
                "output": r.get("output"),
                "judge_final_verdict": r.get("final_verdict"),
                "judge_final_layer": r.get("final_layer"),
                "judge_fail_axes": r.get("fail_axes", []),
                "judge_w0": r.get("w0"),
                "judge_w1_overall": (r.get("w1") or {}).get("overall"),
                "judge_w1_summary": (r.get("w1") or {}).get("summary"),
                "judge_w2_consensus": (r.get("w2") or {}).get("consensus"),
                "human_verdict": None,
                "human_axes_fail": None,
                "human_note": None,
            }
            f.write(json.dumps(entry, ensure_ascii=False, default=str) + "\n")

    # Cost
    cost_w1 = (total_w1_in * 2.5 / 1e6) + (total_w1_out * 15.0 / 1e6)
    print(f"\n=== Re-judge done ===")
    print(f"W1 tokens: input={total_w1_in}, output={total_w1_out}, cost=${cost_w1:.4f}")
    print(f"  run JSON:    {out_json}")
    print(f"  golden_set:  {out_jsonl}")
    return 0


def _load_scenario_yaml(scenario_id: str) -> dict | None:
    import yaml
    scenarios_dir = Path(__file__).resolve().parents[1] / "scenarios"
    for p in scenarios_dir.rglob("*.yaml"):
        try:
            with p.open("r", encoding="utf-8") as f:
                doc = yaml.safe_load(f)
        except yaml.YAMLError:
            continue
        if isinstance(doc, dict) and doc.get("id") == scenario_id:
            return doc
    return None


if __name__ == "__main__":
    sys.exit(main())
