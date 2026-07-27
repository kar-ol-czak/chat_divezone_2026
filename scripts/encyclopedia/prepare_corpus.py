"""Przygotowanie korpusu CiC (Corpus-in-Context) dla Gemini GCIE (TASK-014, Faza 1c).

Łączy źródła w jeden plik z XML tagami per źródło.
Kolejność bottom-heavy: dane na początku, instrukcje na końcu.
"""

from __future__ import annotations

import json
import logging
from pathlib import Path

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
logger = logging.getLogger(__name__)

ROOT = Path(__file__).resolve().parent.parent.parent
WIEDZA = ROOT / "_docs" / "wiedza_nurkowa"
DATA = ROOT / "data" / "encyclopedia"

# Kategorie z nurkomania teorii istotne dla sprzętu
EQUIPMENT_THEORY_CATEGORIES = {
    "automat oddechowy", "butle nurkowe", "sprzęt do nurkowania",
    "Encyklopedia nurkowania", "szkoła nurkowania", "nurkowanie techniczne",
    "komputery nurkowe", "sidemount", "rebreather", "nitrox", "trimix",
    "fizyka nurkowania", "dekompresja", "sprzęt", "sprzęt nurkowy",
}

# Progi filtrowania: minimum znaków tresc
MIN_CONTENT_LENGTH = 200


def load_padi_ch3() -> str:
    """Ładuje PADI Encyclopedia Ch3 - Dive Equipment."""
    path = WIEDZA / "Encyclopedia of Recreational Diving" / "Encyclopedia of Recreational Diving Ch3-Dive-equipment.md"
    if not path.exists():
        logger.warning("Brak pliku PADI Ch3: %s", path)
        return ""
    text = path.read_text(encoding="utf-8")
    logger.info("PADI Ch3: %d znaków", len(text))
    return text


def load_iantd_owd() -> str:
    """Ładuje książkę OWD IANTD (6 części markdown)."""
    owd_dir = WIEDZA / "Książka OWD"
    if not owd_dir.exists():
        logger.warning("Brak katalogu IANTD OWD: %s", owd_dir)
        return ""

    parts = sorted(owd_dir.glob("*.md"))
    chunks = []
    for part in parts:
        text = part.read_text(encoding="utf-8")
        if text.strip():
            chunks.append(f"### {part.name}\n\n{text}")

    combined = "\n\n---\n\n".join(chunks)
    logger.info("IANTD OWD: %d plików, %d znaków", len(parts), len(combined))
    return combined


def load_nurkomania_sprzet() -> str:
    """Ładuje artykuły sprzętowe z nurkomania.pl."""
    path = WIEDZA / "sprzet_do_nurkowania.json"
    if not path.exists():
        logger.warning("Brak nurkomania sprzęt: %s", path)
        return ""

    data = json.loads(path.read_text(encoding="utf-8"))
    articles = []

    for item in data:
        title = item.get("tytul", "")
        category = item.get("kategoria", "")
        content = item.get("tresc", "")

        if len(content) < MIN_CONTENT_LENGTH:
            continue

        articles.append(f"## {title}\nKategoria: {category}\n\n{content}")

    combined = "\n\n---\n\n".join(articles)
    logger.info("Nurkomania sprzęt: %d artykułów, %d znaków", len(articles), len(combined))
    return combined


def load_nurkomania_teoria() -> str:
    """Ładuje teorię nurkowania - TYLKO sekcje istotne dla sprzętu."""
    path = WIEDZA / "teoria_nurkowania_pelny.json"
    if not path.exists():
        logger.warning("Brak nurkomania teoria: %s", path)
        return ""

    raw = json.loads(path.read_text(encoding="utf-8"))
    entries = raw.get("dane", []) if isinstance(raw, dict) else raw

    articles = []
    skipped = 0

    for item in entries:
        category = (item.get("kategoria") or "").lower()
        title = item.get("tytul", "")
        content = item.get("tresc", "")

        if len(content) < MIN_CONTENT_LENGTH:
            skipped += 1
            continue

        # Filtruj: bierz tylko kategorie związane ze sprzętem
        is_relevant = any(cat in category for cat in EQUIPMENT_THEORY_CATEGORIES)

        # Dodatkowy fallback: szukaj słów kluczowych w tytule
        if not is_relevant:
            title_lower = title.lower()
            equipment_words = ["sprzęt", "automat", "butl", "komp", "piank", "skafandr",
                               "maska", "płetw", "boj", "latark", "nóż", "manometr",
                               "skrzydło", "jacket", "bcd", "rebreather", "sidemount",
                               "nitrox", "trimix", "dekompresj"]
            is_relevant = any(w in title_lower for w in equipment_words)

        if is_relevant:
            articles.append(f"## {title}\nKategoria: {category}\n\n{content}")
        else:
            skipped += 1

    combined = "\n\n---\n\n".join(articles)
    logger.info("Nurkomania teoria: %d artykułów (pominięto %d), %d znaków",
                len(articles), skipped, len(combined))
    return combined


def load_divezone_metadata() -> str:
    """Ładuje metadane sklepowe: concept keys + golden test pairs."""
    parts = []

    # Concept keys
    ck_path = DATA / "concept_keys.json"
    if ck_path.exists():
        ck = json.loads(ck_path.read_text(encoding="utf-8"))
        lines = ["## Lista pojęć encyklopedycznych (concept keys)\n"]
        for item in ck:
            lines.append(
                f"- {item['concept_key']} | PL: {item['canonical_term_pl']} | "
                f"EN: {item['canonical_term_en']} | Grupa: {item['category_group']}"
            )
        parts.append("\n".join(lines))

    # Golden test set — contrast pairs
    gt_path = DATA / "golden_test_set.json"
    if gt_path.exists():
        gt = json.loads(gt_path.read_text(encoding="utf-8"))
        lines = ["\n## Znane pary mylące (do relacji nie_mylic_z)\n"]
        for pair in gt.get("contrast_pairs", []):
            lines.append(
                f"- {pair['concept_a']} ↔ {pair['concept_b']}: {pair['disambiguation']}"
            )
        parts.append("\n".join(lines))

    combined = "\n\n".join(parts)
    logger.info("Divezone metadata: %d znaków", len(combined))
    return combined


def build_system_suffix() -> str:
    """Buduje końcowy blok instrukcji (bottom-heavy)."""
    schema_path = Path(__file__).resolve().parent / "models.py"
    schema_text = schema_path.read_text(encoding="utf-8") if schema_path.exists() else "(brak)"

    return f"""
<instructions>
## ROLA
Jesteś ekspertem nurkowym z 20-letnim doświadczeniem w nauczaniu (PADI, IANTD, CMAS)
i specjalistą od terminologii nurkowej polskiej i angielskiej.

## ZADANIE
Na podstawie załadowanego korpusu generujesz ustrukturyzowane wpisy encyklopedyczne
dla pojęć ze sprzętu nurkowego. Każdy wpis musi być zgodny z Pydantic schematem poniżej.

## HIERARCHIA ŹRÓDEŁ (od najwyższego priorytetu)
1. PADI Encyclopedia of Recreational Diving — autorytet globalny EN
2. Książka OWD IANTD — autorytet PL, terminologia polska
3. nurkomania.pl — polski żargon nurkowy, nazwy potoczne
4. Metadane divezone.pl — kontekst sklepowy, znane pary mylące

Przy konflikcie: wyższe źródło wygrywa. Jeśli różnica dotyczy żargonu PL
(nie terminologii formalnej), nurkomania.pl ma priorytet nad PADI.

## CHAIN-OF-DICTIONARY (dla anglicyzmów)
Przy terminach takich jak BCD, jacket, octopus, stage, wing, backplate:
1. Podaj oryginalny termin EN
2. Podaj oficjalny polski odpowiednik (jeśli istnieje)
3. Podaj potoczny termin PL używany w polskim nurkowaniu
4. Oznacz typ: exact_synonym, anglicyzm, colloquial

## ZASADY SYNOMIMÓW
- Lista PL: TYLKO polskie słowa. Angielskie → typ "anglicyzm"
- NIE tłumacz dosłownie slangu EN→PL (np. "safety sausage" ≠ "kiełbasa bezpieczeństwa")
- Każdy synonim: 1-4 słowa, małe litery
- misleading_term: termin który WYDAJE SIĘ synonimem ale nim nie jest
- niezalecany: termin przestarzały lub nieprecyzyjny (np. "lung" dla automatu)

## RELACJE nie_mylic_z
OBOWIĄZKOWE gdy w korpusie istnieją pojęcia łatwe do pomylenia.
Pole "why" i "disambiguation_clues" WYMAGANE.

## EVIDENCE
Minimum 1 cytat per definicja. Minimum 2 źródła jeśli dostępne.
confidence=high TYLKO gdy 2+ źródeł potwierdza.

## PYDANTIC SCHEMA
```python
{schema_text}
```
</instructions>
"""


def estimate_tokens(text: str) -> int:
    """Szacuje liczbę tokenów (~3.5 char/token dla tekstu PL/EN mieszanego)."""
    return len(text) // 3


def main():
    logger.info("Przygotowanie korpusu CiC...")

    sections = []

    # 1. PADI Ch3 (EN)
    padi = load_padi_ch3()
    if padi:
        sections.append(f"<source_data_1_padi_en>\n{padi}\n</source_data_1_padi_en>")

    # 2. IANTD OWD (PL)
    iantd = load_iantd_owd()
    if iantd:
        sections.append(f"<source_data_2_iantd_pl>\n{iantd}\n</source_data_2_iantd_pl>")

    # 3. Nurkomania sprzęt (PL)
    sprzet = load_nurkomania_sprzet()
    if sprzet:
        sections.append(f"<source_data_4_nurkomania_sprzet_pl>\n{sprzet}\n</source_data_4_nurkomania_sprzet_pl>")

    # 4. Nurkomania teoria — filtrowane (PL)
    teoria = load_nurkomania_teoria()
    if teoria:
        sections.append(f"<source_data_4_nurkomania_teoria_pl>\n{teoria}\n</source_data_4_nurkomania_teoria_pl>")

    # 5. Divezone metadata
    meta = load_divezone_metadata()
    if meta:
        sections.append(f"<source_data_5_divezone_metadata>\n{meta}\n</source_data_5_divezone_metadata>")

    # 6. Instrukcje (bottom-heavy — na końcu)
    suffix = build_system_suffix()
    sections.append(suffix)

    corpus = "\n\n".join(sections)
    tokens_est = estimate_tokens(corpus)

    output_path = DATA / "corpus_cached.txt"
    output_path.write_text(corpus, encoding="utf-8")

    logger.info("=" * 60)
    logger.info("CORPUS READY")
    logger.info("=" * 60)
    logger.info("Rozmiar: %d znaków (~%dk tokenów)", len(corpus), tokens_est // 1000)
    logger.info("Output: %s", output_path)

    if tokens_est > 900_000:
        logger.warning("Korpus przekracza 900k tokenów! Rozważ dodatkowe filtrowanie.")
    elif tokens_est > 650_000:
        logger.info("Korpus w zakresie 650-900k tokenów — OK dla Gemini 1M context.")
    else:
        logger.info("Korpus poniżej 650k tokenów — optymalnie.")


if __name__ == "__main__":
    main()
