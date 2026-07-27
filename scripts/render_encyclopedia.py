#!/usr/bin/env python3
"""
TASK-ENC-011d: Markdown Renderer + Master Report.

Renderuje JSON-y z Gemini do markdown z deterministycznymi tagami zrodlowymi.
RED hasla → quarantine/. GREEN/YELLOW → rendered/.

Uzycie:
    python3 scripts/render_encyclopedia.py --all
    python3 scripts/render_encyclopedia.py --concept AUTOMAT_ODDECHOWY
    python3 scripts/render_encyclopedia.py --report-only
"""

import argparse
import hashlib
import json
import sys
from datetime import datetime, timezone
from pathlib import Path

PROJECT_ROOT = Path(__file__).resolve().parent.parent
DOCS = PROJECT_ROOT / "_docs"
DATA = PROJECT_ROOT / "data"
GEN_V2 = DATA / "encyclopedia" / "v3" / "gen_v2"
RAW_DIR = GEN_V2 / "raw"
EVIDENCE_DIR = GEN_V2 / "evidence"
VALIDATION_DIR = GEN_V2 / "validation"
RENDERED_DIR = GEN_V2 / "rendered"
QUARANTINE_DIR = GEN_V2 / "quarantine"
MANIFESTS_DIR = GEN_V2 / "manifests"
CONCEPT_LIST_FILE = GEN_V2 / "concept_list.json"

PROMPT_FILE = DOCS / "PROMPT_gemini_encyklopedia_v4_json.md"
KEYWORDS_FILE = DATA / "dataforseo" / "processed" / "all_keywords.csv"
PAA_FILE = DATA / "dataforseo" / "questions" / "atp_questions_all.csv"
EXPERT_FILE = DOCS / "wiedza_nurkowa" / "transkrypt_kwestionariusza_eksperta.md"
CROSSSELL_FILE = DOCS / "dane_sprzedazowe_crosssell_12m.md"
BESTSELLERS_FILE = DOCS / "dane_sprzedazowe_bestsellery_12m.md"


def load_json(path: Path) -> dict | list:
    return json.loads(path.read_text(encoding="utf-8"))


def sha256_file(path: Path) -> str:
    if not path.exists():
        return "N/A"
    return hashlib.sha256(path.read_bytes()).hexdigest()[:16]


# -------------------------------------------------------------------
# Tag renderer (KRYTYCZNA — tagi budowane z evidence, NIE od Gemini)
# -------------------------------------------------------------------

def render_tag(evidence_id: str, evidence: dict) -> str:
    ev = evidence.get(evidence_id)
    if not ev:
        return ""
    source = ev.get("source", "")
    if source == "GSC":
        vol = ev.get("volume", "?")
        return f"[GSC, {vol} vol]"
    elif source == "PAA":
        return "[PAA]"
    elif source == "autocomplete":
        return "[AC]"
    elif source in ("crosssell", "bestsellers"):
        return "[dane sprzedazowe]"
    return f"[{source}]"


# -------------------------------------------------------------------
# Markdown renderer per haslo
# -------------------------------------------------------------------

def render_concept(data: dict, evidence: dict) -> str:
    md = []
    num = data.get("concept_number", "?")
    name_pl = data.get("name_pl", "?")
    name_en = data.get("name_en", "")
    header = f"## {num}. {name_pl}"
    if name_en:
        header += f" / {name_en}"
    md.append(header)
    md.append("")

    # Definicja
    md.append("### Definicja i zasada dzialania")
    md.append(data.get("definition", ""))
    md.append("")

    # Podtypy
    md.append("### Podtypy i konstrukcje")
    md.append("**Podtypy klienckie (decyzje zakupowe):**")
    for st in data.get("subtypes_client", []):
        md.append(f"* **{st['name']}:** {st['description']}")
    md.append("")
    if data.get("subtypes_technical"):
        md.append("**Podtypy techniczne (edukacyjne):**")
        for st in data.get("subtypes_technical", []):
            md.append(f"* **{st['name']}:** {st['description']}")
        md.append("")

    # Synonimy z tagami
    md.append("### Synonimy i slowa kluczowe")
    for category, label in [
        ("official", "Oficjalne"), ("close", "Bliskie"),
        ("slang", "Potoczne / Slang"), ("anglicisms", "Anglicyzmy w polskim uzyciu"),
        ("misspelled", "Bledne, ale popularne zapytania klientow"),
    ]:
        items = data.get("synonyms", {}).get(category, [])
        if items:
            rendered = []
            for item in items:
                tag = render_tag(item.get("evidence_id", ""), evidence)
                entry = item["text"]
                if tag:
                    entry += f" {tag}"
                rendered.append(entry)
            md.append(f"* **{label}:** {', '.join(rendered)}")
        else:
            md.append(f"* **{label}:** Brak danych w zrodlach.")
    md.append("")

    # Long-tail
    if data.get("longtail_phrases"):
        md.append("**Frazy long-tail z wyszukiwarek:**")
        for phrase in data["longtail_phrases"]:
            tag = render_tag(phrase.get("evidence_id", ""), evidence)
            line = f"* `{phrase['text']}`"
            if tag:
                line += f" {tag}"
            md.append(line)
        md.append("")

    # Nie mylic z
    if data.get("not_to_confuse"):
        md.append("### Nie mylic z")
        for item in data["not_to_confuse"]:
            md.append(f"* **{item['concept_key']}:** {item['explanation']}")
        md.append("")

    # Parametry zakupowe
    if data.get("purchase_parameters"):
        md.append("### Parametry zakupowe")
        for param in data["purchase_parameters"]:
            md.append(f"* **{param['name']}:** {param['description']}")
        md.append("")

    # Cross-sell
    if data.get("cross_sell"):
        md.append("### Powiazane produkty (Cross-selling)")
        for item in data["cross_sell"]:
            tag = render_tag(item.get("evidence_id", ""), evidence)
            concept_link = f"(-> {item['concept_key']})" if item.get("concept_key") else ""
            line = f"* **{item['product']} {concept_link}:** {item['description']}"
            if tag:
                line += f" {tag}"
            md.append(line.strip())
        md.append("")

    # FAQ
    if data.get("faq"):
        md.append("### FAQ klienta")
        for faq in data["faq"]:
            md.append(f"* **{faq['question']}**")
            md.append(faq["answer"])
            md.append("")

    # Uwagi dla sprzedawcy
    md.append("### Uwagi dla sprzedawcy")
    md.append(data.get("seller_notes", ""))
    md.append("")

    return "\n".join(md)


# -------------------------------------------------------------------
# Master Report
# -------------------------------------------------------------------

def generate_master_report(all_keys: list[str]) -> str:
    lines = []
    lines.append("# ENCYCLOPEDIA V3 — GENERATION REPORT")
    lines.append(f"# Pipeline: v2 (evidence registry + JSON Schema)")
    lines.append(f"# Date: {datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M UTC')}")
    lines.append(f"# Model: gemini-3.1-pro-preview")
    lines.append("")

    # Zbierz dane
    summary_file = GEN_V2 / "validation_summary.json"
    summary = load_json(summary_file) if summary_file.exists() else {}

    green = summary.get("green", 0)
    yellow = summary.get("yellow", 0)
    red = summary.get("red", 0)

    # Koszt i czas z manifestow
    total_input = 0
    total_output = 0
    total_time = 0
    for ck in all_keys:
        mf = MANIFESTS_DIR / f"{ck}.json"
        if mf.exists():
            m = load_json(mf)
            total_input += m.get("input_tokens", 0)
            total_output += m.get("output_tokens", 0)
            total_time += m.get("duration_s", 0)

    # Gemini pricing (rough): input $1.25/M, output $10/M for >128k context
    # but most calls are <128k so: input $0.3125/M, output $2.5/M
    cost_in = total_input / 1_000_000 * 1.25
    cost_out = total_output / 1_000_000 * 10.0
    total_cost = cost_in + cost_out

    lines.append("## PODSUMOWANIE")
    lines.append(f"- Hasel: {len(all_keys)}")
    lines.append(f"- GREEN (gotowe do publikacji): {green}")
    lines.append(f"- YELLOW (wymaga review): {yellow}")
    lines.append(f"- RED (zablokowane): {red}")
    lines.append(f"- Tokeny: {total_input:,} in / {total_output:,} out")
    lines.append(f"- Koszt szacowany: ${total_cost:.2f}")
    lines.append(f"- Czas: {total_time:.0f}s ({total_time/60:.1f} min)")
    lines.append("")

    # RED
    lines.append("## RED — ZABLOKOWANE (napraw przed publikacja)")
    red_concepts = summary.get("red_concepts", [])
    if red_concepts:
        for rc in red_concepts:
            lines.append(f"- **{rc['key']}**: {len(rc.get('fabricated', []))} fabricated evidence")
            for f in rc.get("fabricated", []):
                lines.append(f"  - {f['field']}: `{f['id']}` ({f['text'][:50]})")
    else:
        lines.append("Brak.")
    lines.append("")

    # YELLOW
    lines.append("## YELLOW — DO SPRAWDZENIA")
    yellow_concepts = summary.get("yellow_concepts", [])
    if yellow_concepts:
        for yc in yellow_concepts:
            lines.append(f"- **{yc['key']}**: {', '.join(yc['reasons'])}")
    else:
        lines.append("Brak.")
    lines.append("")

    # Evidence usage
    lines.append("## POKRYCIE EVIDENCE")
    lines.append(f"- Evidence usage rate: {summary.get('evidence_usage_rate', 'N/A')}")
    lines.append("")

    # Completeness — zbierz z walidacji
    low_subtypes = []
    low_faq = []
    low_longtail = []
    low_crosssell = []
    all_missing_data = []
    all_ungrounded = []

    for ck in all_keys:
        vf = VALIDATION_DIR / f"{ck}.json"
        if not vf.exists():
            continue
        v = load_json(vf)
        comp = v.get("checks", {}).get("completeness", {})
        if comp.get("subtypes_client", 0) < 3:
            low_subtypes.append(f"{ck} ({comp.get('subtypes_client', 0)})")
        if comp.get("faq", 0) < 4:
            low_faq.append(f"{ck} ({comp.get('faq', 0)})")
        if comp.get("longtail", 0) < 6:
            low_longtail.append(f"{ck} ({comp.get('longtail', 0)})")
        if comp.get("cross_sell", 0) < 2:
            low_crosssell.append(f"{ck} ({comp.get('cross_sell', 0)})")
        for md_item in v.get("model_missing_data", []):
            all_missing_data.append(f"- **{ck}**: {md_item}")
        for ug in v.get("model_ungrounded", []):
            all_ungrounded.append(f"- **{ck}**: `{ug['text']}` — {ug['reason']}")

    lines.append("## KOMPLETNOSC TRESCI")
    lines.append(f"- Hasla z <3 subtypes: {len(low_subtypes)}")
    if low_subtypes:
        for s in low_subtypes:
            lines.append(f"  - {s}")
    lines.append(f"- Hasla z <4 FAQ: {len(low_faq)}")
    if low_faq:
        for s in low_faq:
            lines.append(f"  - {s}")
    lines.append(f"- Hasla z <6 longtail: {len(low_longtail)}")
    if low_longtail:
        for s in low_longtail[:15]:
            lines.append(f"  - {s}")
        if len(low_longtail) > 15:
            lines.append(f"  - ... i {len(low_longtail) - 15} wiecej")
    lines.append(f"- Hasla z <2 cross-sell: {len(low_crosssell)}")
    if low_crosssell:
        for s in low_crosssell:
            lines.append(f"  - {s}")
    lines.append("")

    # Missing data
    lines.append("## BRAKI ZGLOSZONE PRZEZ MODEL")
    if all_missing_data:
        lines.extend(all_missing_data)
    else:
        lines.append("Brak.")
    lines.append("")

    # Ungrounded
    lines.append("## UZUPELNIENIA BEZ DOWODOW (ungrounded)")
    if all_ungrounded:
        lines.extend(all_ungrounded)
    else:
        lines.append("Brak.")
    lines.append("")

    # Reprodukowalnosc
    lines.append("## REPRODUKOWALNOSC")
    lines.append(f"- Prompt: PROMPT_gemini_encyklopedia_v4_json.md ({sha256_file(PROMPT_FILE)})")
    lines.append(f"- Model: gemini-3.1-pro-preview")
    lines.append(f"- Source hashes:")
    lines.append(f"  - all_keywords.csv: {sha256_file(KEYWORDS_FILE)}")
    lines.append(f"  - atp_questions_all.csv: {sha256_file(PAA_FILE)}")
    lines.append(f"  - transkrypt_eksperta.md: {sha256_file(EXPERT_FILE)}")
    lines.append(f"  - crosssell_12m.md: {sha256_file(CROSSSELL_FILE)}")
    lines.append(f"  - bestsellery_12m.md: {sha256_file(BESTSELLERS_FILE)}")
    lines.append("")

    # Spis tresci
    lines.append("## SPIS TRESCI (105 hasel)")
    for ck in all_keys:
        vf = VALIDATION_DIR / f"{ck}.json"
        status = "?"
        if vf.exists():
            v = load_json(vf)
            status = v.get("status", "?")
        icon = {"GREEN": "G", "YELLOW": "Y", "RED": "R"}.get(status, "?")
        rf = RAW_DIR / f"{ck}.json"
        num = "?"
        if rf.exists():
            r = load_json(rf)
            num = r.get("concept_number", "?")
            name = r.get("name_pl", ck)
        else:
            name = ck
        lines.append(f"{num}. [{icon}] {name} (`{ck}`)")
    lines.append("")

    return "\n".join(lines)


# -------------------------------------------------------------------
# Main
# -------------------------------------------------------------------

def main():
    parser = argparse.ArgumentParser(description="Render encyclopedia markdown + master report")
    parser.add_argument("--all", action="store_true")
    parser.add_argument("--concept", help="Single concept key")
    parser.add_argument("--report-only", action="store_true")
    args = parser.parse_args()

    all_keys = load_json(CONCEPT_LIST_FILE)

    if args.report_only:
        report = generate_master_report(all_keys)
        report_file = GEN_V2 / "MASTER_REPORT.md"
        report_file.write_text(report, encoding="utf-8")
        print(f"Zapisano: {report_file.relative_to(PROJECT_ROOT)}")
        return

    if args.concept:
        concepts = [args.concept]
    elif args.all:
        concepts = all_keys
    else:
        print("Podaj --all, --concept KEY, lub --report-only")
        sys.exit(1)

    RENDERED_DIR.mkdir(parents=True, exist_ok=True)
    QUARANTINE_DIR.mkdir(parents=True, exist_ok=True)

    rendered_count = 0
    quarantined_count = 0
    rendered_mds = []  # (concept_number, concept_key, md_text) for master file

    for ck in concepts:
        raw_file = RAW_DIR / f"{ck}.json"
        ev_file = EVIDENCE_DIR / f"{ck}.json"
        val_file = VALIDATION_DIR / f"{ck}.json"

        if not raw_file.exists():
            print(f"  [SKIP] {ck}: brak raw file")
            continue

        data = load_json(raw_file)
        evidence = load_json(ev_file) if ev_file.exists() else {}
        status = "GREEN"
        if val_file.exists():
            val = load_json(val_file)
            status = val.get("status", "GREEN")

        md = render_concept(data, evidence)

        if status == "RED":
            # Quarantine
            q_header = f"<!-- QUARANTINED: status RED -->\n"
            q_header += f"<!-- Reason: fabricated evidence -->\n\n"
            (QUARANTINE_DIR / f"{ck}.md").write_text(q_header + md, encoding="utf-8")
            quarantined_count += 1
            print(f"  [Q] {ck} -> quarantine/ (RED)")
        else:
            (RENDERED_DIR / f"{ck}.md").write_text(md, encoding="utf-8")
            rendered_count += 1
            num = data.get("concept_number", 999)
            rendered_mds.append((num if isinstance(num, int) else 999, ck, md))
            print(f"  [R] {ck} -> rendered/ ({status})")

    # Master file — scal rendered w kolejnosci concept_number
    rendered_mds.sort(key=lambda x: x[0])
    master_md = "# Encyklopedia Sprzetu Nurkowego v3\n"
    master_md += f"# Wygenerowano: {datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M UTC')}\n"
    master_md += f"# Pipeline: v2 (evidence registry + JSON Schema + Gemini 3.1 Pro)\n"
    master_md += f"# Hasel: {len(rendered_mds)}\n\n---\n\n"
    for _, ck, md in rendered_mds:
        master_md += md + "\n---\n\n"
    master_file = GEN_V2 / "encyclopedia_v3_all.md"
    master_file.write_text(master_md, encoding="utf-8")

    print(f"\nRendered: {rendered_count}")
    print(f"Quarantined: {quarantined_count}")
    print(f"Master file: {master_file.relative_to(PROJECT_ROOT)}")

    # Master report
    report = generate_master_report(all_keys)
    report_file = GEN_V2 / "MASTER_REPORT.md"
    report_file.write_text(report, encoding="utf-8")
    print(f"Report: {report_file.relative_to(PROJECT_ROOT)}")


if __name__ == "__main__":
    main()
