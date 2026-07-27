"""
Krok 2: Wzbogacenie szkieletów v2 o marki i kandydatów synonimów.
- Parsuje mapę marek → marki_w_sklepie
- Ładuje frazy klientów (DataForSEO, Luigi's Box, GSC) → _candidate_synonyms
"""

import csv
import json
import re
from pathlib import Path

from config import (
    BRAND_MAP_FILE,
    BRAND_CATEGORY_TO_CONCEPTS,
    CONCEPT_TO_BRAND_CATEGORIES,
    DATAFORSEO_CSV,
    GSC_QUERIES,
    LUIGISBOX_ALL,
    LUIGISBOX_SEARCH,
    MASTER_CONCEPTS,
    OUTPUT_DIR,
)


# ---------------------------------------------------------------------------
# 2a. Marki
# ---------------------------------------------------------------------------

def parse_brand_map(filepath: Path) -> dict[str, list[str]]:
    """
    Parsuje 11_mapa_marek-reviewed.md, sekcja 'Rekomendowane marki wg kategorii'.
    Zwraca: {kategoria: [marka1, marka2, ...]}
    """
    brands: dict[str, list[str]] = {}
    with open(filepath, "r", encoding="utf-8") as f:
        content = f.read()

    # szukaj linii w formacie: Kategoria -> [MARKA1, MARKA2, ...]
    pattern = re.compile(r'^([A-ZĘÓĄŚŁŻŹĆŃa-ząćęłńóśźż\s]+?)\s*->\s*\[(.+)\]\s*$', re.MULTILINE)
    for match in pattern.finditer(content):
        category = match.group(1).strip()
        brands_raw = match.group(2)
        brand_list = [b.strip() for b in brands_raw.split(",") if b.strip()]
        brands[category] = brand_list

    return brands


def assign_brands_to_concepts(brand_map: dict[str, list[str]]) -> dict[str, list[str]]:
    """
    Mapuje marki z kategorii na concept keys.
    Zwraca: {concept_key: [marka1, marka2, ...]}
    """
    result: dict[str, list[str]] = {}

    for concept_key, categories in CONCEPT_TO_BRAND_CATEGORIES.items():
        merged_brands: list[str] = []
        for cat in categories:
            for brand in brand_map.get(cat, []):
                if brand not in merged_brands:
                    merged_brands.append(brand)
        if merged_brands:
            result[concept_key] = merged_brands

    return result


# ---------------------------------------------------------------------------
# 2b. Frazy klientów jako kandydaci synonimów
# ---------------------------------------------------------------------------

def load_dataforseo(filepath: Path) -> list[dict]:
    """Ładuje DataForSEO CSV: keyword, search_volume."""
    results = []
    if not filepath.exists():
        return results
    with open(filepath, "r", encoding="utf-8") as f:
        reader = csv.DictReader(f)
        for row in reader:
            keyword = row.get("keyword", "").strip()
            volume = int(row.get("search_volume", 0) or 0)
            if keyword:
                results.append({"keyword": keyword, "search_volume": volume, "source": "dataforseo"})
    return results


def load_luigisbox(filepath: Path) -> list[dict]:
    """Ładuje Luigi's Box CSV: query, profiles (= liczba użyć)."""
    results = []
    if not filepath.exists():
        return results
    with open(filepath, "r", encoding="utf-8") as f:
        reader = csv.DictReader(f)
        for row in reader:
            query = row.get("query", "").strip()
            profiles = int(row.get("profiles", 0) or 0)
            if query and len(query) >= 3:
                results.append({"keyword": query, "search_volume": profiles, "source": "luigisbox"})
    return results


def load_gsc_queries(filepath: Path) -> list[dict]:
    """Ładuje GSC CSV: zapytanie, kliknięcia."""
    results = []
    if not filepath.exists():
        return results
    with open(filepath, "r", encoding="utf-8") as f:
        reader = csv.DictReader(f)
        for row in reader:
            query = row.get("Najczęstsze zapytania", "").strip()
            clicks = row.get("Kliknięcia", "0").strip().replace(",", "")
            try:
                clicks_int = int(clicks)
            except ValueError:
                clicks_int = 0
            if query and len(query) >= 3:
                results.append({"keyword": query, "search_volume": clicks_int, "source": "gsc"})
    return results


def build_concept_keywords(concept_key: str, skeleton: dict) -> set[str]:
    """
    Buduje zbiór słów kluczowych dla pojęcia (do matchowania fraz klientów).
    Zawiera: nazwa_pl, nazwa_en, synonimy exact/near/potoczne/anglicyzmy.
    """
    keywords: set[str] = set()

    # nazwa
    nazwa_pl = skeleton.get("nazwa_pl", "")
    if nazwa_pl:
        keywords.add(nazwa_pl.lower())
        # rozbij na tokeny
        for token in re.split(r'[/(),\s\-–]+', nazwa_pl):
            tok = token.strip().lower()
            if len(tok) >= 3:
                keywords.add(tok)

    nazwa_en = skeleton.get("nazwa_en", "")
    if nazwa_en:
        keywords.add(nazwa_en.lower())
        for token in re.split(r'[/(),\s\-–]+', nazwa_en):
            tok = token.strip().lower()
            if len(tok) >= 3:
                keywords.add(tok)

    # synonimy
    for group_name in ("synonimy_pl", "synonimy_en"):
        group = skeleton.get(group_name, {})
        for bucket_terms in group.values():
            for term in bucket_terms:
                keywords.add(term.lower())

    # concept_key tokens
    for part in concept_key.lower().split("_"):
        if len(part) >= 3:
            keywords.add(part)

    return keywords


def match_phrases_to_concepts(
    all_phrases: list[dict],
    skeletons: dict[str, dict],
) -> dict[str, list[dict]]:
    """
    Fuzzy match fraz klientów do concept keys.
    Prosta metoda: keyword overlap.
    Zwraca: {concept_key: [{keyword, search_volume, source, match_score}, ...]}
    """
    # Buduj indeks słów kluczowych per pojęcie
    concept_kws: dict[str, set[str]] = {}
    for key, skel in skeletons.items():
        concept_kws[key] = build_concept_keywords(key, skel)

    # Filtruj frazy — skip branding queries (divezone, dive zone, itp.)
    skip_phrases = {"divezone", "dive zone", "divezone.pl", "diving zone", "divezone pl"}

    candidates: dict[str, list[dict]] = {k: [] for k in skeletons}

    for phrase_data in all_phrases:
        keyword = phrase_data["keyword"].lower()

        # skip branding
        if keyword in skip_phrases:
            continue

        # skip zbyt krótkie
        if len(keyword) < 3:
            continue

        keyword_tokens = set(re.split(r'[\s\-/(),]+', keyword))
        keyword_tokens = {t for t in keyword_tokens if len(t) >= 2}

        best_match: str | None = None
        best_score: float = 0.0

        for concept_key, kws in concept_kws.items():
            # sprawdź czy cała fraza matchuje jakiś synonim/nazwę
            if keyword in kws:
                score = 1.0
            else:
                # token overlap
                overlap = keyword_tokens & kws
                if not overlap:
                    continue
                score = len(overlap) / max(len(keyword_tokens), 1)

            if score > best_score:
                best_score = score
                best_match = concept_key

        if best_match and best_score >= 0.3:
            candidates[best_match].append({
                "keyword": phrase_data["keyword"],
                "search_volume": phrase_data["search_volume"],
                "source": phrase_data["source"],
                "match_score": round(best_score, 2),
            })

    # Sortuj kandydatów per pojęcie wg search_volume desc
    for key in candidates:
        candidates[key].sort(key=lambda x: x["search_volume"], reverse=True)
        # ogranicz do top 20 per pojęcie
        candidates[key] = candidates[key][:20]

    return candidates


# ---------------------------------------------------------------------------
# Główny pipeline krok 2
# ---------------------------------------------------------------------------

def run_enrich():
    """Uruchom wzbogacanie szkieletów."""
    # 1. Wczytaj szkielety
    skeletons: dict[str, dict] = {}
    for filepath in sorted(OUTPUT_DIR.glob("*.json")):
        with open(filepath, "r", encoding="utf-8") as f:
            data = json.load(f)
        skeletons[data["id"]] = data

    print(f"Wczytano {len(skeletons)} szkieletów")

    # 2a. Marki
    brand_map = parse_brand_map(BRAND_MAP_FILE)
    print(f"Sparsowano {len(brand_map)} kategorii marek")

    concept_brands = assign_brands_to_concepts(brand_map)
    brands_assigned = 0
    for key, skel in skeletons.items():
        if key in concept_brands:
            skel["marki_w_sklepie"] = concept_brands[key]
            brands_assigned += 1
        else:
            skel["marki_w_sklepie"] = []

    print(f"Przypisano marki do {brands_assigned}/{len(skeletons)} pojęć "
          f"({brands_assigned/len(skeletons)*100:.0f}%)")

    # 2b. Frazy klientów
    all_phrases: list[dict] = []

    dataforseo = load_dataforseo(DATAFORSEO_CSV)
    print(f"DataForSEO: {len(dataforseo)} fraz")
    all_phrases.extend(dataforseo)

    luigisbox = load_luigisbox(LUIGISBOX_ALL)
    print(f"Luigi's Box (all): {len(luigisbox)} fraz")
    all_phrases.extend(luigisbox)

    luigisbox_search = load_luigisbox(LUIGISBOX_SEARCH)
    print(f"Luigi's Box (search): {len(luigisbox_search)} fraz")
    all_phrases.extend(luigisbox_search)

    gsc = load_gsc_queries(GSC_QUERIES)
    print(f"GSC: {len(gsc)} fraz")
    all_phrases.extend(gsc)

    print(f"\nŁącznie fraz klientów: {len(all_phrases)}")

    # Match
    candidates = match_phrases_to_concepts(all_phrases, skeletons)

    matched_count = sum(1 for v in candidates.values() if v)
    total_candidates = sum(len(v) for v in candidates.values())
    print(f"Zmatchowano frazy do {matched_count}/{len(skeletons)} pojęć")
    print(f"Łącznie kandydatów: {total_candidates}")

    # Dodaj kandydatów do szkieletów
    for key, skel in skeletons.items():
        if candidates.get(key):
            skel["_candidate_synonyms"] = candidates[key]

    # 3. Zapisz wzbogacone szkielety
    for key, skel in skeletons.items():
        out_path = OUTPUT_DIR / f"{key.lower()}.json"
        with open(out_path, "w", encoding="utf-8") as f:
            json.dump(skel, f, ensure_ascii=False, indent=2)

    print(f"\nZapisano {len(skeletons)} wzbogaconych szkieletów")

    # 4. Statystyki
    print("\n--- Top 10 pojęć z największą liczbą kandydatów ---")
    top = sorted(candidates.items(), key=lambda x: len(x[1]), reverse=True)[:10]
    for key, cands in top:
        if cands:
            print(f"  {key}: {len(cands)} kandydatów "
                  f"(top: {cands[0]['keyword']} vol={cands[0]['search_volume']})")

    return skeletons


if __name__ == "__main__":
    run_enrich()
