"""Metadane grup encyklopedii: pojecia, marki, frazy, reguly domenowe."""

import csv
import json
import re
import logging
from dataclasses import dataclass, field
from pathlib import Path

from config import (
    FAZA1_PATH,
    BRANDS_PATH,
    DOMAIN_RULES_PATH,
    DATAFORSEO_CSV_PATH,
    RAW_DIR,
)

logger = logging.getLogger(__name__)


# ===========================================================================
# Dataclass
# ===========================================================================

@dataclass
class Concept:
    """Pojedyncze pojecie z FAZA1."""
    key: str           # np. "JACKET"
    name_pl: str       # np. "jacket (BCD)"
    source: str = ""   # np. "ORG", "NEW-3"
    seo_vol: str = ""  # wolumen SEO
    notes: str = ""    # uwagi


@dataclass
class GroupMetadata:
    """Kompletne metadane grupy."""
    group_id: str                                        # np. "C"
    group_name: str                                      # np. "Kontrola plywalnosci"
    concepts: list[Concept] = field(default_factory=list)
    concept_count: int = 0
    brands_allowed: dict[str, list[str]] = field(default_factory=dict)  # kategoria -> [marki]
    brands_forbidden: list[str] = field(default_factory=list)
    all_allowed_brands: set[str] = field(default_factory=set)
    dataforseo_phrases: list[dict] = field(default_factory=list)  # [{keyword, search_volume}]
    domain_rules: list[str] = field(default_factory=list)
    critical_rules: list[str] = field(default_factory=list)
    v1_known_errors: dict[str, dict] = field(default_factory=dict)  # concept_key -> {pola}


# ===========================================================================
# Stale: Nazwy grup
# ===========================================================================

GROUP_NAMES: dict[str, str] = {
    "A": "Oddychanie",
    "B": "Butle i zawory",
    "C": "Kontrola plywalnosci",
    "D": "Instrumenty i nawigacja",
    "E": "Maski i fajki",
    "F": "Ochrona termiczna - mokra",
    "G": "Ochrona termiczna - sucha",
    "H": "Obuwie i pletwy",
    "I": "Bezpieczenstwo i sygnalizacja",
    "J": "Oswietlenie i foto/video",
    "K": "Klipsy, mocowania, transport",
    "L": "Konserwacja i akcesoria",
    "M": "Lifestyle, edukacja, inne",
}


# ===========================================================================
# Stale: Filtrowanie fraz DataForSEO per grupa
# ===========================================================================

GROUP_KEYWORD_FILTERS: dict[str, list[str]] = {
    "A": [
        "automat", "regulator", "akwalung", "oddech", "rebreather", "nitrox",
        "octopus", "waz lp", "waz hp", "manometr", "spg", "serwis automat",
        "analizator tlen", "zestaw automat",
    ],
    "B": [
        "butla", "butl", "twinset", "twin set", "manifold", "mostek",
        "zawor butl", "zlacze din", "zlacze int", "yoke", "adapter din",
        "butla z tlenem", "butla tlenow", "stage",
    ],
    "C": [
        "jacket", "bcd", "skrzydl", "wing", "backplate", "plyta",
        "uprzaz", "harness", "inflator", "waz inflat", "waz karbowany",
        "dump valve", "sidemount", "side mount", "balast", "ciezar",
        "pas balast", "kieszen", "trymow", "szelki stage",
    ],
    "D": [
        "komputer nurkow", "transmiter", "konsola", "kompas",
        "zegarek nurkow", "tabliczka", "instrument", "algorytm deko",
    ],
    "E": [
        "maska", "fajka", "snorkel", "okulary do nurk", "panoram",
        "pelnotwarz", "korekcyj", "zestaw maska",
    ],
    "F": [
        "pianka", "neopren", "mokr", "shorty", "komplet pian",
        "polsuch", "docieplacz", "kaptur", "kamizelka pod",
    ],
    "G": [
        "suchy skafander", "suchy", "drysuit", "dry suit", "zawor suchego",
        "waz suchy", "manszet", "rekawic", "pierscien", "buty suchego",
        "ogrzewanie nurk", "ocieplacz", "undersuit", "termoaktywn",
    ],
    "H": [
        "pletw", "fin", "buty neo", "buty nurk", "kaloszow", "paskow",
        "jet fin", "sprezyn", "rashguard",
    ],
    "I": [
        "boja", "dsmb", "smb", "szpulk", "spool", "kolowrot", "reel",
        "worek podnosz", "lift bag", "noz nurk", "line cutter", "sekator",
        "swiatlo chemicz", "cyalume",
    ],
    "J": [
        "latark", "lampa foto", "lampa video", "obudowa podwodn",
        "housing", "gopro", "kamera",
    ],
    "K": [
        "karabin", "retract", "torba nurk", "plecak nurk",
        "skrzynia transport",
    ],
    "L": [
        "antifog", "parowan", "pasek do maski", "smar silik",
        "klej neopren", "o-ring", "uszczelk", "suszarka", "wieszak",
        "zestaw serwis",
    ],
    "M": [
        "odziez nurk", "koszulk", "bluz", "czapk", "ksiazk nurk",
        "logbook", "morsow", "zestaw do nurkow",
    ],
}


# ===========================================================================
# Stale: Mapowanie grup enc. -> kategorie marek z reviewed file
# ===========================================================================

GROUP_BRAND_CATEGORIES: dict[str, list[str]] = {
    "A": ["Automaty oddechowe", "Instrumenty pomiarowe"],
    "B": ["Automaty oddechowe"],  # butle uzywaja marek recznie
    "C": ["Skrzydła i jackety"],
    "D": ["Komputery nurkowe", "Instrumenty pomiarowe"],
    "E": ["Maski i Fajki"],
    "F": ["Skafandry mokre"],
    "G": ["Skafandry suche"],
    "H": ["Płetwy", "Skafandry mokre"],  # buty neoprenowe w skaf. mokrych
    "I": ["Noże"],  # brak dedykowanej kategorii w marki
    "J": ["Latarki nurkowe", "Fotografia i Video"],
    "K": ["Torby i Skrzynie"],
    "L": [],  # brak dedykowanej kategorii
    "M": ["Odzież nurkowa", "Odzież Termoaktywna"],
}


# ===========================================================================
# Stale: Mapowanie grup enc. -> numery regul domenowych (spec sekcja 8.2)
# ===========================================================================

GROUP_DOMAIN_RULES_MAP: dict[str, list[int]] = {
    "C": [6, 7],
    "D": [13, 14, 15],
    "E": [3, 4, 5],
    "F": [1, 2],
    "G": [1, 2],
    "H": [11, 12],
    "I": [18, 19, 20],
    "J": [16, 17, 21],
    "K": [],
    "L": [],
    "M": [],
}


# ===========================================================================
# Stale: Reguly krytyczne (dolaczane do KAZDEGO promptu)
# ===========================================================================

CRITICAL_RULES: list[str] = [
    "DIN to JEDYNY aktualny standard przylacza automatu do butli. "
    "INT/yoke to martwy standard, nie produkowany od ~10 lat. "
    "W Europie nigdy nie byl powszechny. "
    "Nie traktowac INT jako rownorzednej opcji zakupowej.",

    "Jedna definicja = jeden typ SKU. Nigdy nie laczyc roznych produktow "
    "(rozne koncowki, cisnienia robocze, funkcje = rozne SKU).",

    "Synonimy potoczne sa WAZNIEJSZE niz techniczne. "
    "Klienci pisza potocznie, nie uzywa technicznego zargonu.",

    "'Nie mylic z' musi zawierac tylko pary ktore klienci REALNIE myla. "
    "Nie wymyslaj absurdalnych par.",

    "Marki w marki_w_sklepie tylko z whitelisty divezone.pl.",

    "FAQ oparte na realnych frazach klientow (DataForSEO), nie wymyslonych.",

    "Jesli pomylka miedzy produktami moze byc niebezpieczna, MUSI byc ostrzezenie "
    "w uwagi_dla_ai.",

    "'Butla z tlenem' = blad terminologiczny klientow. Butle nurkowe zawieraja "
    "sprezony powietrze lub nitrox, NIE czysty tlen. AI musi delikatnie korygowac.",

    "Szpulka (spool/finger spool) != kolowrotek (reel). "
    "Szpulka nie ma korby, kolowrotek ma. Rozne zastosowania i ceny.",

    "Komputer nurkowy != zegarek sportowy z funkcja nurkowania. "
    "Klienci pytaja o 'zegarek do nurkowania' majac na mysli komputer nurkowy.",

    "Pianka polsucha != suchy skafander. Roznica jest fundamentalna "
    "(neopren z uszczelnieniami vs wodoszczelna skorupa).",

    "'Maska z tlenem' = blad terminologiczny. "
    "Maska nie ma nic wspolnego z tlenem.",
]


# ===========================================================================
# Mapowanie concept_key -> nazwa pliku w raw/
# ===========================================================================

CONCEPT_KEY_TO_RAW_FILE: dict[str, str] = {
    "AUTOMAT_ODDECHOWY": "automat_oddechowy.json",
    "PIERWSZY_STOPIEN": "pierwszy_stopien.json",
    "DRUGI_STOPIEN": "drugi_stopien.json",
    "OCTOPUS": "octopus.json",
    "REBREATHER": "rebreather.json",
    "NITROX": "nitrox.json",
    "MANOMETR": "manometr.json",
    "BUTLA_NURKOWA": "butla_nurkowa.json",
    "BUTLA_STAGE": "butla_stage.json",
    "TWINSET": "twinset.json",
    "MANIFOLD": "manifold.json",
    "ZAWOR_BUTLOWY": "zawor_butlowy.json",
    "ZLACZE_DIN": "zlacze_din.json",
    "ZLACZE_INT": "zlacze_int.json",
    "JACKET": "jacket.json",
    "SKRZYDLO": "skrzydlo.json",
    "BACKPLATE": "backplate.json",
    "UPRZAZ": "uprzaz.json",
    "INFLATOR": "inflator.json",
    "SIDEMOUNT": "sidemount.json",
    "BALAST": "balast.json",
    "KOMPUTER_NURKOWY": "komputer_nurkowy.json",
    "KONSOLA": "konsola.json",
    "KOMPAS": "kompas.json",
    "MASKA_JEDNOSZYBOWA": "maska_jednoszybowa.json",
    "MASKA_DWUSZYBOWA": "maska_dwuszybowa.json",
    "MASKA_PELNOTWARZOWA": "maska_pelnotwarzowa.json",
    "FAJKA": "fajka.json",
    "PIANKA_MOKRA": "pianka_mokra.json",
    "PIANKA_POLSUCHA": "pianka_polsucha.json",
    "KAPTUR": "kaptur.json",
    "SUCHY_SKAFANDER": "suchy_skafander.json",
    "OCIEPLACZ": "ocieplacz.json",
    "REKAWICE": "rekawice.json",
    "BUTY_NEOPRENOWE": "buty_nurkowe.json",
    "PLETWY_PASKOWE": "pletwy_paskowe.json",
    "PLETWY_KALOSZOWE": "pletwy_kaloszowe.json",
    "PLETWY_JET": "pletwy_jet.json",
    "BOJA_NURKOWA": "boja_dekompresyjna.json",
    "SZPULKA": "szpulka.json",
    "KOLOWROTEK": "kolowrotek.json",
    "NOZ_NURKOWY": "noz_nurkowy.json",
    "LATARKA_NURKOWA": "latarka_nurkowa.json",
    "KARABINEK": "karabinek.json",
    "RETRACTOR": "retractor.json",
}


# ===========================================================================
# Parsery plikow wejsciowych
# ===========================================================================

def parse_concepts(faza1_path: Path | None = None) -> dict[str, list[Concept]]:
    """Parsuje tabele markdown z FAZA1_concept_keys_v2.md.

    Zwraca dict: grupa_id -> lista Concept.
    """
    path = faza1_path or FAZA1_PATH
    text = path.read_text(encoding="utf-8")

    groups: dict[str, list[Concept]] = {}
    current_group = None

    # Szukaj naglowkow grup
    for line in text.splitlines():
        # Match: ## GRUPA X: Nazwa (N pojec)
        group_match = re.match(r"^## GRUPA ([A-M]):", line)
        if group_match:
            current_group = group_match.group(1)
            groups[current_group] = []
            continue

        # Match: wiersz tabeli z danymi
        if current_group and line.startswith("|") and not line.startswith("|---") and not line.startswith("| #"):
            parts = [p.strip() for p in line.split("|")]
            # parts: ['', '#', 'Concept key', 'Nazwa PL', 'Zrodlo', 'SEO vol', 'Uwagi', '']
            if len(parts) >= 7:
                try:
                    concept_key = parts[2].strip()
                    name_pl = parts[3].strip()
                    source = parts[4].strip()
                    seo_vol = parts[5].strip()
                    notes = parts[6].strip() if len(parts) > 6 else ""

                    if concept_key and concept_key != "Concept key":
                        groups[current_group].append(Concept(
                            key=concept_key,
                            name_pl=name_pl,
                            source=source,
                            seo_vol=seo_vol,
                            notes=notes,
                        ))
                except (IndexError, ValueError):
                    continue

    return groups


def parse_brands(brands_path: Path | None = None) -> tuple[set[str], list[str], dict[str, list[str]]]:
    """Parsuje whitelist/blacklist marek z 11_mapa_marek-reviewed.md.

    Zwraca: (all_active_brands, forbidden_brands, category_brands)
    """
    path = brands_path or BRANDS_PATH
    text = path.read_text(encoding="utf-8")

    all_active: set[str] = set()
    forbidden: list[str] = []
    category_brands: dict[str, list[str]] = {}

    section = None
    for line in text.splitlines():
        stripped = line.strip()

        if "## Wszystkie aktywne marki" in line:
            section = "active"
            continue
        elif "## Marki ZAKAZANE" in line:
            section = "forbidden"
            continue
        elif "## Rekomendowane marki wg kategorii" in line:
            section = "categories"
            continue
        elif stripped.startswith("## "):
            section = None
            continue

        if section == "active":
            # Format: MARKA | liczba
            if "|" in stripped and not stripped.startswith("#"):
                brand = stripped.split("|")[0].strip()
                if brand:
                    all_active.add(brand)
        elif section == "forbidden":
            if stripped and not stripped.startswith("#") and not stripped.startswith("---"):
                # Czytaj marki z obu sekcji zakazanych
                brand = stripped.strip()
                if brand:
                    forbidden.append(brand)
        elif section == "categories":
            # Format: Kategoria -> [MARKA1, MARKA2, ...]
            match = re.match(r"^(.+?)\s*->\s*\[(.+?)\]", stripped)
            if match:
                cat_name = match.group(1).strip()
                brands_str = match.group(2)
                brands_list = [b.strip() for b in brands_str.split(",") if b.strip()]
                category_brands[cat_name] = brands_list

    return all_active, forbidden, category_brands


def parse_domain_rules(rules_path: Path | None = None) -> dict[int, str]:
    """Parsuje reguly domenowe z 17_reguly_domenowe_grupy_C-M.md.

    Zwraca: dict numer_reguly -> tresc reguly.
    """
    path = rules_path or DOMAIN_RULES_PATH
    text = path.read_text(encoding="utf-8")

    rules: dict[int, str] = {}
    current_rule_num = None
    current_rule_text = ""

    for line in text.splitlines():
        stripped = line.strip()

        # Match: numerowana regula np. "1. Pianka polsucha..."
        rule_match = re.match(r"^(\d+)\.\s+(.+)$", stripped)
        if rule_match:
            # Zapisz poprzednia regule
            if current_rule_num is not None:
                rules[current_rule_num] = current_rule_text.strip()
            current_rule_num = int(rule_match.group(1))
            current_rule_text = rule_match.group(2)
            continue

        # Kontynuacja reguly (nie-pusta linia, nie naglowek)
        if current_rule_num is not None and stripped and not stripped.startswith("##") and not stripped.startswith("|"):
            current_rule_text += " " + stripped

    # Zapisz ostatnia regule
    if current_rule_num is not None:
        rules[current_rule_num] = current_rule_text.strip()

    return rules


def parse_dataforseo(csv_path: Path | None = None) -> list[dict]:
    """Czyta all_keywords.csv i zwraca liste dict.

    Zwraca: [{keyword, search_volume, cpc, competition, source}, ...]
    """
    path = csv_path or DATAFORSEO_CSV_PATH
    results = []

    with open(path, "r", encoding="utf-8") as f:
        reader = csv.DictReader(f)
        for row in reader:
            try:
                vol = int(row.get("search_volume", "0"))
            except ValueError:
                vol = 0
            results.append({
                "keyword": row.get("keyword", ""),
                "search_volume": vol,
            })

    # Sortuj malejaco po wolumenie
    results.sort(key=lambda x: x["search_volume"], reverse=True)
    return results


def filter_dataforseo_for_group(all_keywords: list[dict], group_id: str) -> list[dict]:
    """Filtruje frazy DataForSEO pasujace do grupy."""
    filters = GROUP_KEYWORD_FILTERS.get(group_id, [])
    if not filters:
        return []

    matched = []
    for kw in all_keywords:
        keyword_lower = kw["keyword"].lower()
        for filt in filters:
            if filt.lower() in keyword_lower:
                matched.append(kw)
                break

    # Usun duplikaty zachowujac kolejnosc (po wolumenie)
    seen = set()
    unique = []
    for kw in matched:
        if kw["keyword"] not in seen:
            seen.add(kw["keyword"])
            unique.append(kw)

    return unique


def extract_v1_errors(concept_keys: list[str], raw_dir: Path | None = None) -> dict[str, dict]:
    """Wyciaga kluczowe pola z raw/*.json dla znanych bledow v1.

    Zwraca: concept_key -> {definicja, synonimy, nie_mylic_z}
    """
    directory = raw_dir or RAW_DIR
    errors: dict[str, dict] = {}

    for key in concept_keys:
        filename = CONCEPT_KEY_TO_RAW_FILE.get(key)
        if not filename:
            continue

        filepath = directory / filename
        if not filepath.exists():
            continue

        try:
            data = json.loads(filepath.read_text(encoding="utf-8"))
            errors[key] = {
                "definicja": data.get("definition_operational_pl", data.get("definition_pl", ""))[:300],
                "synonimy": [
                    s.get("term", "") for s in data.get("synonyms", [])
                    if isinstance(s, dict)
                ][:10],
                "nie_mylic_z": [
                    r.get("target_concept_key", "")
                    for r in data.get("relations", [])
                    if isinstance(r, dict) and r.get("type") == "nie_mylic_z"
                ],
            }
        except (json.JSONDecodeError, KeyError) as e:
            logger.warning(f"Blad parsowania raw/{filename}: {e}")

    return errors


# ===========================================================================
# Glowna funkcja
# ===========================================================================

def get_group_metadata(group_id: str) -> GroupMetadata:
    """Zwraca kompletny obiekt metadanych dla danej grupy.

    Laduje i parsuje wszystkie pliki wejsciowe.
    """
    group_id = group_id.upper()

    if group_id not in GROUP_NAMES:
        raise ValueError(f"Nieznana grupa: {group_id}. Dostepne: {list(GROUP_NAMES.keys())}")

    # 1. Pojecia
    all_concepts = parse_concepts()
    concepts = all_concepts.get(group_id, [])

    # 2. Marki
    all_active, forbidden, category_brands = parse_brands()

    # Filtruj marki per grupa
    brand_categories = GROUP_BRAND_CATEGORIES.get(group_id, [])
    brands_allowed: dict[str, list[str]] = {}
    all_allowed: set[str] = set()
    for cat in brand_categories:
        if cat in category_brands:
            brands_allowed[cat] = category_brands[cat]
            all_allowed.update(category_brands[cat])

    # Jesli nie mamy marek z kategorii, uzyj wszystkich aktywnych (minus zakazane)
    if not all_allowed:
        forbidden_lower = {b.lower() for b in forbidden}
        all_allowed = {b for b in all_active if b.lower() not in forbidden_lower}

    # 3. Frazy DataForSEO
    all_keywords = parse_dataforseo()
    dataforseo_phrases = filter_dataforseo_for_group(all_keywords, group_id)

    # 4. Reguly domenowe
    all_rules = parse_domain_rules()
    rule_numbers = GROUP_DOMAIN_RULES_MAP.get(group_id, [])
    domain_rules = [all_rules[n] for n in rule_numbers if n in all_rules]

    # 5. Znane bledy v1
    concept_keys = [c.key for c in concepts]
    v1_errors = extract_v1_errors(concept_keys)

    return GroupMetadata(
        group_id=group_id,
        group_name=GROUP_NAMES[group_id],
        concepts=concepts,
        concept_count=len(concepts),
        brands_allowed=brands_allowed,
        brands_forbidden=forbidden,
        all_allowed_brands=all_allowed,
        dataforseo_phrases=dataforseo_phrases,
        domain_rules=domain_rules,
        critical_rules=CRITICAL_RULES,
        v1_known_errors=v1_errors,
    )
