"""
Krok 1: Deterministyczna transformacja v1 → v2.
Mapuje 46 plików JSON z data/encyclopedia/raw/ na szkielety v2.
Generuje puste szkielety dla 60 pojęć bez v1.
"""

import json
import re
import unicodedata
from pathlib import Path

from config import (
    ACRONYM_WHITELIST,
    MASTER_CONCEPTS,
    RAW_V1_DIR,
    OUTPUT_DIR,
    REPORT_FILE,
    SYNONYM_TYPE_MAP,
    RELATION_TYPES_POWIAZANE,
    V1_TO_V2_KEY_MAP,
)
from schema_v2 import ConceptV2, SynonymsPL, SynonymsEN, NieMylic, EvidenceSource, EvidenceField


# --- Polskie znaki diakrytyczne ---
POLISH_DIACRITICS = set("ąćęłńóśźżĄĆĘŁŃÓŚŹŻ")


def has_polish_diacritics(text: str) -> bool:
    """Czy tekst zawiera polskie znaki diakrytyczne."""
    return bool(POLISH_DIACRITICS & set(text))


def is_ascii_only(text: str) -> bool:
    """Czy tekst składa się wyłącznie z liter ASCII (+ cyfry, spacje, myślniki)."""
    return all(ord(c) < 128 for c in text)


def v1_key_to_v2_key(v1_key: str) -> str:
    """Mapuje v1 concept_key na v2 id (UPPER)."""
    if v1_key in V1_TO_V2_KEY_MAP:
        return V1_TO_V2_KEY_MAP[v1_key]
    return v1_key.upper()


def map_v1_relation_target(target_key: str) -> str:
    """Mapuje v1 relation target_concept_key na v2 id."""
    return v1_key_to_v2_key(target_key)


def load_v1_files() -> list[dict]:
    """Wczytuje wszystkie pliki v1 z katalogu raw."""
    v1_data = []
    for filepath in sorted(RAW_V1_DIR.glob("*.json")):
        with open(filepath, "r", encoding="utf-8") as f:
            data = json.load(f)
        data["_source_file"] = filepath.name
        v1_data.append(data)
    return v1_data


def classify_synonym(syn: dict, en_terms: set[str]) -> tuple[str, str, str]:
    """
    Klasyfikuje synonim v1 do bucketu v2.

    Zwraca: (lang_group, bucket, term)
    lang_group: 'pl' lub 'en'
    bucket: 'exact', 'near', 'potoczne', 'anglicyzmy', 'archaiczne', 'bledne_ale_popularne'
    """
    term = syn["term"]
    syn_type = syn.get("type", "exact_synonym")
    lang = syn.get("language", "pl")
    term_upper = term.upper()

    # 1) Typ "anglicyzm" z v1 → synonimy_pl.anglicyzmy (ZAWSZE)
    # Whitelist akronimów dotyczy tylko ukrytej detekcji (krok 5 niżej)
    if syn_type == "anglicyzm":
        return "pl", "anglicyzmy", term

    # 2) misleading_term / niezalecany → bledne_ale_popularne
    if syn_type in ("misleading_term", "niezalecany"):
        return "pl", "bledne_ale_popularne", term

    # 3) legacy_name → archaiczne
    if syn_type == "legacy_name":
        return "pl", "archaiczne", term

    # 4) Standardowe mapowanie wg typu i języka
    bucket = SYNONYM_TYPE_MAP.get(syn_type, "exact")

    if lang == "en":
        # Synonimy EN → synonimy_en
        return "en", bucket if bucket in ("exact", "near") else "exact", term

    # 5) Detekcja ukrytych anglicyzmów w PL:
    # synonim PL bez diakrytyków + matchuje angielski termin → anglicyzmy
    # (chyba że na whitelist akronimów)
    if lang == "pl" and bucket == "exact":
        if is_ascii_only(term) and not has_polish_diacritics(term):
            if term_upper in {a.upper() for a in ACRONYM_WHITELIST}:
                return "pl", "exact", term
            # sprawdź czy matchuje jakiś EN term
            if term.lower() in {t.lower() for t in en_terms}:
                return "pl", "anglicyzmy", term

    return "pl", bucket, term


def transform_one(v1: dict) -> tuple[ConceptV2, dict]:
    """
    Transformuje jeden plik v1 do szkieletu v2.
    Zwraca: (concept_v2, evidence_dict)
    """
    v2_id = v1_key_to_v2_key(v1["concept_key"])

    # --- Nazwa ---
    nazwa_pl = v1.get("canonical_term_pl", "")
    nazwa_en = v1.get("canonical_term_en")

    # --- Definicja ---
    def_operational = v1.get("definition_operational_pl", "") or ""
    def_full = v1.get("definition_pl", "") or ""
    # Wybierz dłuższy ≥50 znaków; jeśli operational < 50, użyj def_full
    if len(def_operational) >= 50:
        definicja = def_operational
    elif len(def_full) >= 50:
        definicja = def_full
    else:
        definicja = def_operational or def_full or None

    # --- Zbierz EN terms do detekcji anglicyzmów ---
    en_terms: set[str] = set()
    if nazwa_en:
        # rozbij nazwę EN na tokeny
        for part in re.split(r'[/(),\s]+', nazwa_en):
            if part.strip():
                en_terms.add(part.strip())
    for syn in v1.get("synonyms", []):
        if syn.get("language") == "en":
            en_terms.add(syn["term"])

    # --- Synonimy ---
    syn_pl = SynonymsPL()
    syn_en = SynonymsEN()

    for syn in v1.get("synonyms", []):
        lang_group, bucket, term = classify_synonym(syn, en_terms)

        if lang_group == "pl":
            getattr(syn_pl, bucket).append(term)
        else:  # en
            if bucket in ("exact", "near"):
                getattr(syn_en, bucket).append(term)
            else:
                syn_en.exact.append(term)

    # --- Relacje: nie_mylic_z ---
    nie_mylic: list[NieMylic] = []
    for rel in v1.get("relations", []):
        if rel["type"] == "nie_mylic_z":
            target = map_v1_relation_target(rel["target_concept_key"])
            why = rel.get("why", "")
            nie_mylic.append(NieMylic(concept=target, dlaczego=why))

    # --- Relacje: powiazane_produkty ---
    powiazane: list[str] = []
    for rel in v1.get("relations", []):
        if rel["type"] in RELATION_TYPES_POWIAZANE:
            target = map_v1_relation_target(rel["target_concept_key"])
            if target not in powiazane:
                powiazane.append(target)
    # dodaj również nadrzedny/podrzedny jako powiazane
    for rel in v1.get("relations", []):
        if rel["type"] in ("nadrzedny", "podrzedny"):
            target = map_v1_relation_target(rel["target_concept_key"])
            if target not in powiazane:
                powiazane.append(target)

    # --- Buduj ConceptV2 ---
    concept = ConceptV2(
        id=v2_id,
        nazwa_pl=nazwa_pl,
        nazwa_en=nazwa_en,
        definicja=definicja,
        podtypy=None,          # krok 3 (LLM)
        synonimy_pl=syn_pl,
        synonimy_en=syn_en,
        nie_mylic_z=nie_mylic,
        parametry_zakupowe=None,  # krok 3
        marki_w_sklepie=None,    # krok 2
        powiazane_produkty=powiazane,
        faq=None,                # krok 3
        uwagi_dla_ai=None,      # krok 3
    )

    # --- Evidence sidecar ---
    evidence_dict: dict[str, EvidenceField] = {}
    for ev in v1.get("evidence", []):
        source = EvidenceSource(
            source_id=ev.get("source_id", ""),
            fragment=ev.get("quote", ""),
            language=ev.get("language"),
        )
        # przypisz do definicji (domyślnie)
        if "definicja" not in evidence_dict:
            evidence_dict["definicja"] = EvidenceField(
                value=definicja,
                sources=[source],
            )
        else:
            evidence_dict["definicja"].sources.append(source)

    return concept, evidence_dict


def create_empty_skeleton(concept_key: str) -> ConceptV2:
    """Tworzy pusty szkielet v2 dla pojęcia bez danych v1."""
    meta = MASTER_CONCEPTS.get(concept_key, {})
    return ConceptV2(
        id=concept_key,
        nazwa_pl=meta.get("nazwa_pl", concept_key.lower().replace("_", " ")),
        nazwa_en=meta.get("nazwa_en"),
        definicja=None,
        podtypy=None,
        synonimy_pl=SynonymsPL(),
        synonimy_en=SynonymsEN(),
        nie_mylic_z=[],
        parametry_zakupowe=None,
        marki_w_sklepie=None,
        powiazane_produkty=[],
        faq=None,
        uwagi_dla_ai=None,
    )


def concept_to_dict(concept: ConceptV2) -> dict:
    """Serializuje ConceptV2 do dict (JSON-friendly)."""
    d = concept.model_dump(exclude_none=False)
    # usuwaj puste listy w synonimach żeby JSON był czytelniejszy
    for syn_group in ("synonimy_pl", "synonimy_en"):
        group = d.get(syn_group, {})
        d[syn_group] = {k: v for k, v in group.items() if v}
        if not d[syn_group]:
            d[syn_group] = {}
    return d


def compute_completeness(concept_dict: dict) -> dict:
    """Oblicza ile pól jest wypełnionych vs null."""
    fields = [
        "definicja", "podtypy", "parametry_zakupowe", "marki_w_sklepie",
        "faq", "uwagi_dla_ai",
    ]
    filled = 0
    total = len(fields)
    for f in fields:
        val = concept_dict.get(f)
        if val is not None and val != [] and val != "":
            filled += 1

    # synonimy
    syn_pl = concept_dict.get("synonimy_pl", {})
    syn_en = concept_dict.get("synonimy_en", {})
    has_syn_pl = any(syn_pl.values())
    has_syn_en = any(syn_en.values())
    has_relations = bool(concept_dict.get("nie_mylic_z"))
    has_powiazane = bool(concept_dict.get("powiazane_produkty"))

    return {
        "id": concept_dict["id"],
        "filled_main": filled,
        "total_main": total,
        "has_synonimy_pl": has_syn_pl,
        "has_synonimy_en": has_syn_en,
        "has_nie_mylic_z": has_relations,
        "has_powiazane": has_powiazane,
        "completeness_pct": round(filled / total * 100, 1),
    }


def run_transform():
    """Główna funkcja: transformacja + puste szkielety."""
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)

    # 1. Wczytaj v1
    v1_files = load_v1_files()
    print(f"Wczytano {len(v1_files)} plików v1")

    # 2. Transformacja v1 → v2
    all_evidence: dict[str, dict] = {}
    transformed_keys: set[str] = set()
    report_entries: list[dict] = []

    for v1 in v1_files:
        concept, evidence = transform_one(v1)
        transformed_keys.add(concept.id)

        # Zapisz szkielet
        out_dict = concept_to_dict(concept)
        out_path = OUTPUT_DIR / f"{concept.id.lower()}.json"
        with open(out_path, "w", encoding="utf-8") as f:
            json.dump(out_dict, f, ensure_ascii=False, indent=2)

        # Evidence sidecar
        all_evidence[concept.id] = {
            k: v.model_dump() for k, v in evidence.items()
        }

        report_entries.append(compute_completeness(out_dict))
        print(f"  ✓ {concept.id} ← {v1['_source_file']}")

    # 3. Puste szkielety dla pojęć bez v1
    empty_count = 0
    for concept_key in sorted(MASTER_CONCEPTS.keys()):
        if concept_key not in transformed_keys:
            concept = create_empty_skeleton(concept_key)
            out_dict = concept_to_dict(concept)
            out_path = OUTPUT_DIR / f"{concept_key.lower()}.json"
            with open(out_path, "w", encoding="utf-8") as f:
                json.dump(out_dict, f, ensure_ascii=False, indent=2)

            report_entries.append(compute_completeness(out_dict))
            empty_count += 1

    print(f"\nWygenerowano {len(transformed_keys)} szkieletów z v1")
    print(f"Wygenerowano {empty_count} pustych szkieletów (bez v1)")
    print(f"Łącznie: {len(transformed_keys) + empty_count} plików w {OUTPUT_DIR}")

    # 4. Evidence sidecar
    evidence_path = OUTPUT_DIR.parent / "encyclopedia_v2_evidence.json"
    with open(evidence_path, "w", encoding="utf-8") as f:
        json.dump(all_evidence, f, ensure_ascii=False, indent=2)
    print(f"\nEvidence sidecar: {evidence_path}")

    # 5. Raport kompletności
    with open(REPORT_FILE, "w", encoding="utf-8") as f:
        json.dump(report_entries, f, ensure_ascii=False, indent=2)
    print(f"Raport kompletności: {REPORT_FILE}")

    # 6. Statystyki
    from_v1 = [r for r in report_entries if r["completeness_pct"] > 0]
    avg_completeness = (
        sum(r["completeness_pct"] for r in from_v1) / len(from_v1)
        if from_v1 else 0
    )
    print(f"\nŚrednia kompletność (z v1): {avg_completeness:.1f}%")

    return report_entries


if __name__ == "__main__":
    run_transform()
