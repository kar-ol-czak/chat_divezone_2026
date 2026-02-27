"""Sanityzacja JSON z odpowiedzi modeli AI.

KRYTYCZNY komponent - modele generuja JSON z niedozwolonymi znakami Unicode.
"""

import json
import re
import logging
from dataclasses import dataclass, field

logger = logging.getLogger(__name__)

# Tabela zamian Unicode (spec sekcja 5.2)
UNICODE_REPLACEMENTS: dict[str, str] = {
    "\u201E": "'",   # otwierajacy cudz. polski
    "\u201D": "'",   # zamykajacy cudz. polski / angielski
    "\u201C": "'",   # otwierajacy cudz. angielski
    "\u2019": "'",   # apostrof typograficzny
    "\u2018": "'",   # otwierajacy apostrof typograficzny
    "\u2192": "->",  # strzalka w prawo
    "\u2190": "<-",  # strzalka w lewo
    "\u2191": "^",   # strzalka gora
    "\u2193": "v",   # strzalka dol
    "\u2014": "-",   # pautierka (em dash)
    "\u2013": "-",   # en dash
    "\u2026": "...", # wielokropek
    "\u2260": "!=",  # nie-rowne
    "\u00A0": " ",   # spacja nierozerywalna
}

# Wymagane pola w schema (spec sekcja 5.3)
REQUIRED_FIELDS = {
    "id": str,
    "nazwa_pl": str,
    "nazwa_en": str,
    "definicja": str,
    "podtypy": list,
    "synonimy_pl": dict,
    "synonimy_en": dict,
    "nie_mylic_z": list,
    "parametry_zakupowe": list,
    "marki_w_sklepie": list,
    "powiazane_produkty": list,
    "faq": list,
    "uwagi_dla_ai": str,
}

SYNONIMY_PL_KEYS = {"exact", "near", "potoczne", "archaiczne"}
SYNONIMY_EN_KEYS = {"exact", "near"}


@dataclass
class SanitizeResult:
    """Wynik sanityzacji JSON."""
    entries: list[dict] = field(default_factory=list)
    json_valid: bool = False
    schema_errors: list[str] = field(default_factory=list)
    brand_warnings: list[str] = field(default_factory=list)
    unicode_replacements: dict[str, int] = field(default_factory=dict)
    entries_count: int = 0
    raw_json: str = ""


def extract_json_block(text: str) -> str:
    """Wyciaga blok JSON z odpowiedzi modelu.

    Szuka ```json ... ``` lub pierwszego [ ... ostatniego ].
    """
    # Szukaj bloku markdown ```json ... ```
    pattern = r"```json\s*\n?(.*?)\n?\s*```"
    match = re.search(pattern, text, re.DOTALL)
    if match:
        return match.group(1).strip()

    # Szukaj bloku ``` ... ``` (bez 'json')
    pattern = r"```\s*\n?(.*?)\n?\s*```"
    match = re.search(pattern, text, re.DOTALL)
    if match:
        content = match.group(1).strip()
        if content.startswith("["):
            return content

    # Fallback: szukaj pierwszego [ i ostatniego ]
    first_bracket = text.find("[")
    last_bracket = text.rfind("]")
    if first_bracket != -1 and last_bracket != -1 and last_bracket > first_bracket:
        return text[first_bracket:last_bracket + 1]

    return text.strip()


def sanitize_unicode(text: str) -> tuple[str, dict[str, int]]:
    """Zamienia znaki Unicode na bezpieczne odpowiedniki ASCII.

    Zwraca: (sanitized_text, replacement_counts)
    """
    counts: dict[str, int] = {}
    for char, replacement in UNICODE_REPLACEMENTS.items():
        n = text.count(char)
        if n > 0:
            code = f"U+{ord(char):04X}"
            counts[code] = n
            text = text.replace(char, replacement)
    return text, counts


def remove_trailing_commas(text: str) -> str:
    """Usuwa trailing commas przed ] i }."""
    # Trailing comma przed ]
    text = re.sub(r",\s*]", "]", text)
    # Trailing comma przed }
    text = re.sub(r",\s*}", "}", text)
    return text


def validate_schema(entries: list[dict]) -> list[str]:
    """Waliduje schemat kazdego entry. Zwraca liste bledow."""
    errors: list[str] = []

    for i, entry in enumerate(entries):
        entry_id = entry.get("id", f"entry_{i}")

        # Sprawdz wymagane pola
        for field_name, field_type in REQUIRED_FIELDS.items():
            if field_name not in entry:
                errors.append(f"[{entry_id}] Brak pola: {field_name}")
            elif not isinstance(entry[field_name], field_type):
                errors.append(
                    f"[{entry_id}] Pole {field_name}: oczekiwano {field_type.__name__}, "
                    f"jest {type(entry[field_name]).__name__}"
                )

        # Walidacja id: UPPER_SNAKE_CASE
        if "id" in entry and entry["id"]:
            if not re.match(r"^[A-Z][A-Z0-9_]*$", entry["id"]):
                errors.append(f"[{entry_id}] id nie jest UPPER_SNAKE_CASE: {entry['id']}")

        # Walidacja definicji: min 50 znakow
        if "definicja" in entry and isinstance(entry["definicja"], str):
            if len(entry["definicja"]) < 50:
                errors.append(f"[{entry_id}] definicja za krotka: {len(entry['definicja'])} znakow (min 50)")

        # Walidacja synonimy_pl
        if "synonimy_pl" in entry and isinstance(entry["synonimy_pl"], dict):
            for key in SYNONIMY_PL_KEYS:
                if key not in entry["synonimy_pl"]:
                    errors.append(f"[{entry_id}] synonimy_pl: brak klucza '{key}'")
                elif not isinstance(entry["synonimy_pl"][key], list):
                    errors.append(f"[{entry_id}] synonimy_pl.{key}: oczekiwano list")

        # Walidacja synonimy_en
        if "synonimy_en" in entry and isinstance(entry["synonimy_en"], dict):
            for key in SYNONIMY_EN_KEYS:
                if key not in entry["synonimy_en"]:
                    errors.append(f"[{entry_id}] synonimy_en: brak klucza '{key}'")

        # Walidacja nie_mylic_z
        if "nie_mylic_z" in entry and isinstance(entry["nie_mylic_z"], list):
            for j, pair in enumerate(entry["nie_mylic_z"]):
                if not isinstance(pair, dict):
                    errors.append(f"[{entry_id}] nie_mylic_z[{j}]: oczekiwano dict")
                else:
                    if "concept" not in pair:
                        errors.append(f"[{entry_id}] nie_mylic_z[{j}]: brak klucza 'concept'")
                    if "dlaczego" not in pair:
                        errors.append(f"[{entry_id}] nie_mylic_z[{j}]: brak klucza 'dlaczego'")

        # Walidacja parametry_zakupowe: min 1
        if "parametry_zakupowe" in entry and isinstance(entry["parametry_zakupowe"], list):
            if len(entry["parametry_zakupowe"]) < 1:
                errors.append(f"[{entry_id}] parametry_zakupowe: pusta lista (min 1)")

        # Walidacja faq
        if "faq" in entry and isinstance(entry["faq"], list):
            for j, faq_item in enumerate(entry["faq"]):
                if not isinstance(faq_item, dict):
                    errors.append(f"[{entry_id}] faq[{j}]: oczekiwano dict")
                else:
                    if "pytanie" not in faq_item:
                        errors.append(f"[{entry_id}] faq[{j}]: brak klucza 'pytanie'")
                    if "odpowiedz" not in faq_item:
                        errors.append(f"[{entry_id}] faq[{j}]: brak klucza 'odpowiedz'")

        # Walidacja nazwa_pl i nazwa_en: niepuste
        for field_name in ("nazwa_pl", "nazwa_en"):
            if field_name in entry and isinstance(entry[field_name], str):
                if not entry[field_name].strip():
                    errors.append(f"[{entry_id}] {field_name}: pusty string")

        # Walidacja uwagi_dla_ai: niepusty
        if "uwagi_dla_ai" in entry and isinstance(entry["uwagi_dla_ai"], str):
            if not entry["uwagi_dla_ai"].strip():
                errors.append(f"[{entry_id}] uwagi_dla_ai: pusty string")

    return errors


def validate_brands(entries: list[dict], allowed_brands: set[str]) -> list[str]:
    """Sprawdza czy marki z entries sa na whiteliscie."""
    warnings: list[str] = []
    # Normalizacja do lowercase
    allowed_lower = {b.lower().strip() for b in allowed_brands}

    for entry in entries:
        entry_id = entry.get("id", "?")
        brands = entry.get("marki_w_sklepie", [])
        if not isinstance(brands, list):
            continue
        for brand in brands:
            if brand.lower().strip() not in allowed_lower:
                warnings.append(f"[{entry_id}] Marka '{brand}' nie jest na whiteliscie")

    return warnings


def sanitize_and_parse(raw_text: str, allowed_brands: set[str] | None = None) -> SanitizeResult:
    """Orchestracja: extract -> sanitize -> parse -> validate.

    Glowna funkcja sanityzacji.
    """
    result = SanitizeResult()

    # 1. Wyciagnij blok JSON
    json_block = extract_json_block(raw_text)

    # 2. Sanityzuj Unicode
    sanitized, counts = sanitize_unicode(json_block)
    result.unicode_replacements = counts
    if counts:
        total = sum(counts.values())
        logger.info(f"Zamieniono {total} znakow Unicode: {counts}")

    # 3. Usun trailing commas
    sanitized = remove_trailing_commas(sanitized)
    result.raw_json = sanitized

    # 4. Parsuj JSON
    try:
        data = json.loads(sanitized)
    except json.JSONDecodeError as e:
        logger.error(f"JSON parse error: {e}")
        result.json_valid = False
        result.schema_errors.append(f"JSON parse error: {e}")
        return result

    result.json_valid = True

    # Sprawdz czy to tablica
    if not isinstance(data, list):
        result.schema_errors.append("Oczekiwano tablicy JSON, otrzymano " + type(data).__name__)
        return result

    result.entries = data
    result.entries_count = len(data)

    # 5. Schema validation
    result.schema_errors = validate_schema(data)

    # 6. Brand validation
    if allowed_brands:
        result.brand_warnings = validate_brands(data, allowed_brands)

    return result
