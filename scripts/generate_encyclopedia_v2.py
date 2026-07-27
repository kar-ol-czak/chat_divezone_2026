#!/usr/bin/env python3
"""
TASK-ENC-011b: Generacja encyklopedii v2 — Gemini JSON Schema, 1 haslo per wywolanie.

Uzycie:
    python3 scripts/generate_encyclopedia_v2.py --mode test
    python3 scripts/generate_encyclopedia_v2.py --mode single --concept AUTOMAT_ODDECHOWY
    python3 scripts/generate_encyclopedia_v2.py --mode full
"""

import argparse
import hashlib
import json
import os
import re
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

import httpx
from dotenv import load_dotenv

# -------------------------------------------------------------------
# Sciezki
# -------------------------------------------------------------------
PROJECT_ROOT = Path(__file__).resolve().parent.parent
DOCS = PROJECT_ROOT / "_docs"
DATA = PROJECT_ROOT / "data"

PROMPT_FILE = DOCS / "PROMPT_gemini_encyklopedia_v4_json.md"
CONCEPT_KEYS_FILE = DOCS / "FAZA1_concept_keys_v2.md"
EXPERT_FILE = DOCS / "wiedza_nurkowa" / "transkrypt_kwestionariusza_eksperta.md"
NOTEBOOK_FILE = DOCS / "wiedza_nurkowa" / "Encyklopedia_Nurkowania_NotebookLM_v2.md"
BRANDS_FILE = DOCS / "11_mapa_marek-reviewed.md"
DOMAIN_RULES_FILE = DOCS / "17_reguly_domenowe_grupy_C-M.md"

GEN_V2 = DATA / "encyclopedia" / "v3" / "gen_v2"
EVIDENCE_DIR = GEN_V2 / "evidence"
MAPPINGS_DIR = GEN_V2 / "mappings"
RAW_DIR = GEN_V2 / "raw"
MANIFESTS_DIR = GEN_V2 / "manifests"
CONCEPT_LIST_FILE = GEN_V2 / "concept_list.json"

load_dotenv(PROJECT_ROOT / ".env")

MODEL = "gemini-3.1-pro-preview"
API_KEY_ENV = "GOOGLE_GEMINI_API"

TEST_CONCEPTS = ["AUTOMAT_ODDECHOWY", "JACKET", "SUCHY_SKAFANDER"]

# -------------------------------------------------------------------
# JSON Schema dla Gemini Structured Output
# -------------------------------------------------------------------
ENTRY_SCHEMA = {
    "type": "object",
    "properties": {
        "concept_number": {"type": "integer"},
        "concept_key": {"type": "string"},
        "name_pl": {"type": "string"},
        "name_en": {"type": "string"},
        "definition": {"type": "string"},
        "subtypes_client": {"type": "array", "items": {
            "type": "object",
            "properties": {
                "name": {"type": "string"},
                "description": {"type": "string"},
            },
            "required": ["name", "description"],
        }},
        "subtypes_technical": {"type": "array", "items": {
            "type": "object",
            "properties": {
                "name": {"type": "string"},
                "description": {"type": "string"},
            },
            "required": ["name", "description"],
        }},
        "synonyms": {"type": "object", "properties": {
            "official": {"type": "array", "items": {"type": "object", "properties": {
                "text": {"type": "string"}, "evidence_id": {"type": "string"},
            }, "required": ["text", "evidence_id"]}},
            "close": {"type": "array", "items": {"type": "object", "properties": {
                "text": {"type": "string"}, "evidence_id": {"type": "string"},
            }, "required": ["text", "evidence_id"]}},
            "slang": {"type": "array", "items": {"type": "object", "properties": {
                "text": {"type": "string"}, "evidence_id": {"type": "string"},
            }, "required": ["text", "evidence_id"]}},
            "anglicisms": {"type": "array", "items": {"type": "object", "properties": {
                "text": {"type": "string"}, "evidence_id": {"type": "string"},
            }, "required": ["text", "evidence_id"]}},
            "misspelled": {"type": "array", "items": {"type": "object", "properties": {
                "text": {"type": "string"}, "evidence_id": {"type": "string"},
            }, "required": ["text", "evidence_id"]}},
        }, "required": ["official", "close", "slang", "anglicisms", "misspelled"]},
        "longtail_phrases": {"type": "array", "items": {
            "type": "object",
            "properties": {
                "text": {"type": "string"},
                "evidence_id": {"type": "string"},
            },
            "required": ["text", "evidence_id"],
        }},
        "not_to_confuse": {"type": "array", "items": {
            "type": "object",
            "properties": {
                "concept_key": {"type": "string"},
                "explanation": {"type": "string"},
            },
            "required": ["concept_key", "explanation"],
        }},
        "purchase_parameters": {"type": "array", "items": {
            "type": "object",
            "properties": {
                "name": {"type": "string"},
                "description": {"type": "string"},
            },
            "required": ["name", "description"],
        }},
        "cross_sell": {"type": "array", "items": {
            "type": "object",
            "properties": {
                "product": {"type": "string"},
                "concept_key": {"type": "string"},
                "description": {"type": "string"},
                "evidence_id": {"type": "string"},
            },
            "required": ["product", "concept_key", "description"],
        }},
        "faq": {"type": "array", "items": {
            "type": "object",
            "properties": {
                "question": {"type": "string"},
                "answer": {"type": "string"},
                "evidence_ids": {"type": "array", "items": {"type": "string"}},
            },
            "required": ["question", "answer"],
        }},
        "seller_notes": {"type": "string"},
        "related_concept_keys": {"type": "array", "items": {"type": "string"}},
        "missing_data": {"type": "array", "items": {"type": "string"}},
        "ungrounded_additions": {"type": "array", "items": {
            "type": "object",
            "properties": {
                "text": {"type": "string"},
                "reason": {"type": "string"},
            },
            "required": ["text", "reason"],
        }},
    },
    "required": [
        "concept_key", "name_pl", "definition", "subtypes_client",
        "synonyms", "longtail_phrases", "purchase_parameters",
        "cross_sell", "faq", "seller_notes",
    ],
}


# -------------------------------------------------------------------
# Ladowanie danych
# -------------------------------------------------------------------

def load_file(path: Path) -> str:
    if not path.exists():
        print(f"  [WARN] Brak pliku: {path}")
        return ""
    return path.read_text(encoding="utf-8")


def load_json(path: Path) -> dict | list:
    return json.loads(path.read_text(encoding="utf-8"))


def sha256_file(path: Path) -> str:
    if not path.exists():
        return ""
    return hashlib.sha256(path.read_bytes()).hexdigest()[:16]


def sha256_str(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()[:16]


# Concept info from FAZA1 file
def parse_concept_info(concept_key: str) -> dict:
    """Wyciaga metadane concept key z FAZA1_concept_keys_v2.md."""
    text = load_file(CONCEPT_KEYS_FILE)
    pattern = re.compile(
        r"^\|\s*(\d+b?)\s*\|\s*" + re.escape(concept_key) + r"\s*\|\s*(.+?)\s*\|",
        re.MULTILINE,
    )
    m = pattern.search(text)
    if not m:
        return {"num": 0, "name_pl": concept_key, "name_en": "", "full_name": concept_key}
    name_raw = m.group(2).strip()
    name_pl = name_raw.split("/")[0].strip() if "/" in name_raw else name_raw.split("(")[0].strip()
    name_en = name_raw.split("/")[1].strip() if "/" in name_raw else ""
    return {"num": m.group(1), "name_pl": name_pl, "name_en": name_en, "full_name": name_raw}


# Expert transcript extraction
def extract_expert_sections(group_names: list[str]) -> str:
    text = load_file(EXPERT_FILE)
    if not text:
        return ""
    sections = []
    for gname in group_names:
        pattern = re.compile(
            r"(## " + re.escape(gname) + r".*?)(?=\n## Grupa |\Z)",
            re.DOTALL,
        )
        match = pattern.search(text)
        if match:
            sections.append(match.group(1).strip())
    return "\n\n---\n\n".join(sections)


# NotebookLM draft extraction
def extract_notebook_draft(concept_name_pl: str) -> str:
    text = load_file(NOTEBOOK_FILE)
    if not text:
        return ""
    pattern = re.compile(
        r"(## \*?\*?\d+\.\s+" + re.escape(concept_name_pl) + r".*?)(?=\n## \*?\*?\d+\.\s+|\Z)",
        re.DOTALL | re.IGNORECASE,
    )
    match = pattern.search(text)
    if match:
        return match.group(1).strip()
    return ""


# Determine which domain rule groups apply
def get_relevant_domain_rules(concept_key: str) -> str:
    """Zwraca reguly domenowe relevantne dla concept key."""
    text = load_file(DOMAIN_RULES_FILE)
    if not text:
        return ""

    # Mapowanie concept key → grupy regul domenowych
    key_to_groups = {
        # Grupa C: Pianki
        "PIANKA_MOKRA": ["C"], "PIANKA_ZIMNE_WODY": ["C"], "PIANKA_CIEPLE_WODY": ["C"],
        "PIANKA_SHORTY": ["C"], "KOMPLET_PIANEK": ["C"], "PIANKA_POLSUCHA": ["C"],
        "DOCIEPLACZ": ["C"], "KAPTUR": ["C"],
        # Grupa D: Maski
        "MASKA_JEDNOSZYBOWA": ["D"], "MASKA_DWUSZYBOWA": ["D"], "MASKA_PELNOTWARZOWA": ["D"],
        "MASKA_KOREKCYJNA": ["D"], "MASKA_PANORAMICZNA": ["D"], "MASKA_DLA_DZIECI": ["D"],
        "ZESTAW_MASKA_FAJKA": ["D"], "FAJKA": ["D"],
        # Grupa E: BCD
        "JACKET": ["E"], "SKRZYDLO": ["E"], "BACKPLATE": ["E"], "UPRZAZ": ["E"],
        "ZESTAW_SKRZYDLO_SINGLE": ["E"], "ZESTAW_SKRZYDLO_TWIN": ["E"],
        "INFLATOR": ["E"], "WAZ_INFLATORA": ["E"], "WAZ_KARBOWANY": ["E"],
        "DUMP_VALVE_BCD": ["E"], "SIDEMOUNT": ["E"],
        # Grupa F: Butle
        "BUTLA_NURKOWA": ["F"], "BUTLA_STAGE": ["F"], "BUTLA_ARGONU": ["F"],
        "TWINSET": ["F"], "MANIFOLD": ["F"], "ZAWOR_BUTLOWY": ["F"],
        "ZLACZE_DIN": ["F"], "ZLACZE_INT": ["F"], "ADAPTER_DIN_INT": ["F"],
        # Grupa H: Pletwy
        "PLETWY_PASKOWE": ["H"], "PLETWY_KALOSZOWE": ["H"], "PLETWY_JET": ["H"],
        "SPREZYNY_DO_PLETW": ["H"], "BUTY_NEOPRENOWE": ["H"],
        # Grupa I: Komputery
        "KOMPUTER_NURKOWY": ["I"], "TRANSMITER": ["I"], "KONSOLA": ["I"],
        "KOMPAS": ["I"], "ZEGAREK_NURKOWY": ["I"],
        # Grupa J: Latarki
        "LATARKA_NURKOWA": ["J"], "LAMPA_FOTO_VIDEO": ["J"],
        # Grupa K: Akcesoria
        "BOJA_NURKOWA": ["K"], "SZPULKA": ["K"], "KOLOWROTEK": ["K"],
        "WOREK_PODNOSZACY": ["K"],
        # Grupa L: Fotografia
        "OBUDOWA_PODWODNA": ["L"], "KAMERA_GOPRO": ["L"],
    }

    groups = key_to_groups.get(concept_key, [])
    if not groups:
        return ""

    # Zwroc caly plik — jest krotki (65 linii)
    return text


# -------------------------------------------------------------------
# Budowanie kontekstu per haslo
# -------------------------------------------------------------------

def build_context(concept_key: str, evidence: dict, concept_info: dict,
                  all_concept_keys: list[str]) -> str:
    """Buduje pelny kontekst do wyslania Gemini."""
    parts = []

    # 1. Instrukcja
    parts.append(f"## HASLO DO WYGENEROWANIA\n\n"
                 f"Concept key: {concept_key}\n"
                 f"Nazwa PL: {concept_info['name_pl']}\n"
                 f"Nazwa EN: {concept_info.get('name_en', '')}\n"
                 f"Numer: {concept_info.get('num', '')}")

    # 2. Evidence registry
    parts.append(f"## EVIDENCE REGISTRY ({len(evidence)} entries)\n\n"
                 f"```json\n{json.dumps(evidence, indent=2, ensure_ascii=False)}\n```")

    # 3. Lista 105 concept keys (do linkowania)
    keys_text = "\n".join(f"- {k}" for k in all_concept_keys)
    parts.append(f"## PELNA LISTA 105 CONCEPT KEYS (do linkowania)\n\n{keys_text}")

    # 4. Wiedza eksperta
    expert_mapping = load_json(MAPPINGS_DIR / "expert_mapping.json")
    expert_groups = expert_mapping.get(concept_key, {}).get("groups", [])
    if expert_groups:
        expert_text = extract_expert_sections(expert_groups)
        if expert_text:
            parts.append(f"## WIEDZA EKSPERTA\n\n{expert_text}")

    # 5. Draft z NotebookLM
    draft = extract_notebook_draft(concept_info["name_pl"])
    if draft:
        parts.append(f"## DRAFT Z NOTEBOOKLM (do przebudowy)\n\n{draft}")

    # 6. Reguly domenowe
    domain_rules = get_relevant_domain_rules(concept_key)
    if domain_rules:
        parts.append(f"## REGULY DOMENOWE\n\n{domain_rules}")

    # 7. Mapa marek
    brands = load_file(BRANDS_FILE)
    if brands:
        parts.append(f"## MAPA MAREK AKTYWNYCH W SKLEPIE\n\n{brands}")

    return "\n\n" + "=" * 60 + "\n\n".join(parts)


# -------------------------------------------------------------------
# Wywolanie Gemini z JSON Schema
# -------------------------------------------------------------------

def call_gemini_structured(system_prompt: str, user_message: str,
                           schema: dict, model: str = MODEL) -> dict:
    """Wywolanie Gemini z response_mime_type=application/json i response_schema."""
    api_key = os.getenv(API_KEY_ENV)
    if not api_key:
        return {"error": f"Brak klucza API: {API_KEY_ENV}"}

    url = f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent"
    payload = {
        "contents": [
            {"role": "user", "parts": [{"text": user_message}]},
        ],
        "systemInstruction": {"parts": [{"text": system_prompt}]},
        "generationConfig": {
            "temperature": 0.4,
            "maxOutputTokens": 16384,
            "responseMimeType": "application/json",
            "responseSchema": schema,
        },
    }

    t0 = time.time()
    resp = httpx.post(url, params={"key": api_key}, json=payload, timeout=300.0)
    duration = time.time() - t0

    resp.raise_for_status()
    data = resp.json()

    text = data["candidates"][0]["content"]["parts"][0]["text"]
    usage = data.get("usageMetadata", {})

    result_json = json.loads(text)
    result_json["_meta"] = {
        "model": model,
        "input_tokens": usage.get("promptTokenCount", 0),
        "output_tokens": usage.get("candidatesTokenCount", 0),
        "duration_s": round(duration, 1),
    }
    return result_json


# -------------------------------------------------------------------
# Manifest builder
# -------------------------------------------------------------------

def build_manifest(concept_key: str, evidence: dict, model: str,
                   result_meta: dict, context_sources: list[str]) -> dict:
    """Buduje manifest per haslo."""
    # Policz evidence per typ
    ev_counts = {"K": 0, "P": 0, "A": 0, "S": 0, "B": 0}
    for eid in evidence:
        prefix = eid.split("-")[1] if "-" in eid else ""
        if prefix in ev_counts:
            ev_counts[prefix] += 1

    return {
        "concept_key": concept_key,
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "model": model,
        "prompt_version_hash": sha256_file(PROMPT_FILE),
        "evidence_count": ev_counts,
        "context_sources": context_sources,
        "input_tokens": result_meta.get("input_tokens", 0),
        "output_tokens": result_meta.get("output_tokens", 0),
        "duration_s": result_meta.get("duration_s", 0),
        "source_hashes": {
            "all_keywords.csv": sha256_file(DATA / "dataforseo" / "processed" / "all_keywords.csv"),
            "atp_questions_all.csv": sha256_file(DATA / "dataforseo" / "questions" / "atp_questions_all.csv"),
            "transkrypt_eksperta.md": sha256_file(EXPERT_FILE),
            "crosssell_12m.md": sha256_file(DOCS / "dane_sprzedazowe_crosssell_12m.md"),
        },
    }


# -------------------------------------------------------------------
# Walidacja wyniku
# -------------------------------------------------------------------

def validate_result(concept_key: str, result: dict, evidence: dict) -> list[str]:
    """Prosta walidacja — sprawdza evidence_ids, wymagane pola."""
    issues = []
    valid_eids = set(evidence.keys())

    # Sprawdz evidence_ids w synonyms
    for cat_name in ["official", "close", "slang", "anglicisms", "misspelled"]:
        items = result.get("synonyms", {}).get(cat_name, [])
        for item in items:
            eid = item.get("evidence_id", "")
            if eid and eid not in valid_eids:
                issues.append(f"synonyms.{cat_name}: invalid evidence_id '{eid}'")

    # Sprawdz evidence_ids w longtail_phrases
    for item in result.get("longtail_phrases", []):
        eid = item.get("evidence_id", "")
        if eid and eid not in valid_eids:
            issues.append(f"longtail_phrases: invalid evidence_id '{eid}'")

    # Sprawdz evidence_ids w faq
    for faq_item in result.get("faq", []):
        for eid in faq_item.get("evidence_ids", []):
            if eid and eid not in valid_eids:
                issues.append(f"faq: invalid evidence_id '{eid}'")

    # Sprawdz cross_sell evidence_ids
    for cs in result.get("cross_sell", []):
        eid = cs.get("evidence_id", "")
        if eid and eid not in valid_eids:
            issues.append(f"cross_sell: invalid evidence_id '{eid}'")

    # Sprawdz minimalne ilosci
    if len(result.get("faq", [])) < 5:
        issues.append(f"faq: tylko {len(result.get('faq', []))} pytan (min 5)")
    if len(result.get("longtail_phrases", [])) < 8:
        issues.append(f"longtail: tylko {len(result.get('longtail_phrases', []))} fraz (min 8)")
    if len(result.get("cross_sell", [])) < 3:
        issues.append(f"cross_sell: tylko {len(result.get('cross_sell', []))} (min 3)")

    # Sprawdz concept_key
    if result.get("concept_key") != concept_key:
        issues.append(f"concept_key mismatch: '{result.get('concept_key')}' != '{concept_key}'")

    return issues


# -------------------------------------------------------------------
# Generacja jednego hasla
# -------------------------------------------------------------------

def generate_single(concept_key: str, all_concept_keys: list[str],
                    system_prompt: str) -> dict:
    """Generuje jedno haslo encyklopedii."""
    print(f"\n{'='*60}")
    print(f"Generuje: {concept_key}")
    print(f"{'='*60}")

    # Ladowanie danych
    evidence_file = EVIDENCE_DIR / f"{concept_key}.json"
    if not evidence_file.exists():
        print(f"  [ERROR] Brak evidence: {evidence_file}")
        return {"error": f"Brak evidence file: {concept_key}"}

    evidence = load_json(evidence_file)
    concept_info = parse_concept_info(concept_key)
    print(f"  Info: {concept_info['name_pl']} (#{concept_info['num']})")
    print(f"  Evidence: {len(evidence)} entries")

    # Budowanie kontekstu
    context = build_context(concept_key, evidence, concept_info, all_concept_keys)
    print(f"  Kontekst: {len(context)} zn.")

    # Wywolanie Gemini
    print(f"  Wywolanie Gemini ({MODEL})...")
    try:
        result = call_gemini_structured(system_prompt, context, ENTRY_SCHEMA)
    except Exception as e:
        print(f"  [ERROR] {e}")
        return {"error": str(e)}

    meta = result.pop("_meta", {})
    print(f"  OK: {meta.get('input_tokens', 0)} in / {meta.get('output_tokens', 0)} out / {meta.get('duration_s', 0)}s")

    # Walidacja
    issues = validate_result(concept_key, result, evidence)
    if issues:
        print(f"  WALIDACJA — {len(issues)} problemow:")
        for iss in issues[:10]:
            print(f"    - {iss}")
    else:
        print(f"  WALIDACJA OK")

    # Zapisz raw output
    RAW_DIR.mkdir(parents=True, exist_ok=True)
    raw_file = RAW_DIR / f"{concept_key}.json"
    raw_file.write_text(json.dumps(result, indent=2, ensure_ascii=False), encoding="utf-8")
    print(f"  Zapisano: {raw_file.relative_to(PROJECT_ROOT)}")

    # Zapisz manifest
    MANIFESTS_DIR.mkdir(parents=True, exist_ok=True)
    context_sources = []
    expert_mapping = load_json(MAPPINGS_DIR / "expert_mapping.json")
    if expert_mapping.get(concept_key, {}).get("groups"):
        context_sources.append("expert_" + "_".join(
            g.split(":")[0].replace("Grupa ", "grupa_").replace(" ", "_")
            for g in expert_mapping[concept_key]["groups"]
        ))
    if extract_notebook_draft(concept_info["name_pl"]):
        context_sources.append("notebooklm_draft")
    if get_relevant_domain_rules(concept_key):
        context_sources.append("domain_rules")

    manifest = build_manifest(concept_key, evidence, MODEL, meta, context_sources)
    manifest["validation_issues"] = issues
    manifest_file = MANIFESTS_DIR / f"{concept_key}.json"
    manifest_file.write_text(json.dumps(manifest, indent=2, ensure_ascii=False), encoding="utf-8")

    return result


# -------------------------------------------------------------------
# Main
# -------------------------------------------------------------------

def main():
    parser = argparse.ArgumentParser(description="Generacja encyklopedii v2 (Gemini JSON)")
    parser.add_argument("--mode", choices=["test", "single", "full"], required=True)
    parser.add_argument("--concept", help="Concept key (dla --mode single)")
    args = parser.parse_args()

    # Ladowanie danych
    all_concept_keys = load_json(CONCEPT_LIST_FILE)
    system_prompt = load_file(PROMPT_FILE)

    print(f"=== Generacja encyklopedii v2 ===")
    print(f"Model: {MODEL}")
    print(f"Prompt: {PROMPT_FILE.name} (hash: {sha256_file(PROMPT_FILE)})")
    print(f"Concept keys: {len(all_concept_keys)}")

    if args.mode == "test":
        concepts = TEST_CONCEPTS
        print(f"\nTRYB TEST: {concepts}")
    elif args.mode == "single":
        if not args.concept:
            print("ERROR: --concept wymagany dla --mode single")
            sys.exit(1)
        if args.concept not in all_concept_keys:
            print(f"ERROR: '{args.concept}' nie jest na liscie concept keys")
            sys.exit(1)
        concepts = [args.concept]
    elif args.mode == "full":
        concepts = all_concept_keys
        print(f"\nTRYB FULL: {len(concepts)} hasel")
        print("UWAGA: ~2.5h, ~$15. Ctrl+C aby przerwac.")
        time.sleep(3)

    results = {}
    errors = []

    for i, concept_key in enumerate(concepts, 1):
        print(f"\n[{i}/{len(concepts)}]")
        result = generate_single(concept_key, all_concept_keys, system_prompt)
        if "error" in result:
            errors.append((concept_key, result["error"]))
        else:
            results[concept_key] = result

        # Rate limit
        if i < len(concepts):
            time.sleep(2)

    # Podsumowanie
    print(f"\n{'='*60}")
    print(f"PODSUMOWANIE")
    print(f"{'='*60}")
    print(f"Wygenerowano: {len(results)}/{len(concepts)}")
    if errors:
        print(f"Bledy ({len(errors)}):")
        for ck, err in errors:
            print(f"  - {ck}: {err}")

    # Podsumowanie walidacji
    all_issues = []
    for ck in results:
        manifest_file = MANIFESTS_DIR / f"{ck}.json"
        if manifest_file.exists():
            m = load_json(manifest_file)
            for iss in m.get("validation_issues", []):
                all_issues.append(f"{ck}: {iss}")

    if all_issues:
        print(f"\nProblemy walidacji ({len(all_issues)}):")
        for iss in all_issues[:20]:
            print(f"  - {iss}")
    else:
        print(f"\nWalidacja: 0 problemow")


if __name__ == "__main__":
    main()
