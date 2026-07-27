"""Generacja GCIE z Gemini — encyklopedia sprzętowa (TASK-014, Faza 2).

Krok 1: Cache Write — cały korpus do Gemini Context Caching.
Krok 2: 46 iteracyjnych zapytań (1 per concept key).
Krok 3: Pydantic validation + zapis do raw/.
"""

from __future__ import annotations

import argparse
import json
import logging
import os
import time
from datetime import datetime
from pathlib import Path
from typing import Optional

from dotenv import load_dotenv
from google import genai
from google.genai import types
from pydantic import ValidationError

from models import ConceptEntry

load_dotenv(Path(__file__).resolve().parent.parent.parent / ".env")

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
logger = logging.getLogger(__name__)

ROOT = Path(__file__).resolve().parent.parent.parent
DATA = ROOT / "data" / "encyclopedia"
RAW_DIR = DATA / "raw"
RAW_DIR.mkdir(parents=True, exist_ok=True)

MODEL = os.getenv("GEMINI_MODEL", "gemini-2.5-pro")
CACHE_TTL = "7200s"  # 2h bufor
MAX_RETRIES = 3
RATE_LIMIT_DELAY = 4.0  # Gemini Pro rate limit


def get_client() -> genai.Client:
    api_key = os.getenv("GOOGLE_GEMINI_API")
    if not api_key:
        raise ValueError("Brak GOOGLE_GEMINI_API w .env")
    return genai.Client(api_key=api_key)


def load_concept_keys() -> list[dict]:
    path = DATA / "concept_keys.json"
    return json.loads(path.read_text(encoding="utf-8"))


def load_golden_test_set() -> dict:
    path = DATA / "golden_test_set.json"
    return json.loads(path.read_text(encoding="utf-8"))


def load_corpus() -> str:
    path = DATA / "corpus_cached.txt"
    return path.read_text(encoding="utf-8")


def get_relevant_contrast_pairs(concept_key: str, golden: dict) -> str:
    """Wyciąga pary mylące istotne dla danego concept_key."""
    pairs = golden.get("contrast_pairs", [])
    relevant = [
        p for p in pairs
        if p["concept_a"] == concept_key or p["concept_b"] == concept_key
    ]
    if not relevant:
        return "(brak znanych par mylących dla tego pojęcia)"

    lines = []
    for p in relevant:
        other = p["concept_b"] if p["concept_a"] == concept_key else p["concept_a"]
        lines.append(f"- NIE MYLIĆ Z: {other} — {p['disambiguation']}")
        lines.append(f"  Zapytania testowe: {', '.join(p['test_queries'])}")
    return "\n".join(lines)


def build_query_prompt(concept: dict, contrast_text: str) -> str:
    """Buduje prompt iteracyjny per concept key."""
    schema_example = json.dumps({
        "concept_key": concept["concept_key"],
        "canonical_term_pl": concept["canonical_term_pl"],
        "canonical_term_en": concept["canonical_term_en"],
        "definition_operational_pl": "1-2 zdania odróżniające od najbliższego mylonego pojęcia",
        "definition_pl": "pełna definicja PL",
        "definition_en": "pełna definicja EN",
        "synonyms": [
            {"term": "przykład", "type": "exact_synonym", "language": "pl", "note": None},
        ],
        "relations": [
            {"type": "nie_mylic_z", "target_concept_key": "xxx", "why": "dlaczego", "disambiguation_clues": ["cecha"]},
        ],
        "evidence": [
            {"source_id": "padi_ch3", "quote": "cytat min 10 znaków", "language": "en"},
        ],
        "confidence": "high",
        "status": "needs_review",
        "version": 1,
    }, ensure_ascii=False, indent=2)

    return f"""Na podstawie załadowanego korpusu, wygeneruj ustrukturyzowany obiekt JSON
wyłącznie dla kategorii: "{concept['canonical_term_pl']}" ({concept['canonical_term_en']}).

ZASADY:
1. Użyj TYLKO informacji z korpusu. Jeśli brak danych, ustaw null/[].
2. definition_operational_pl: 1-2 zdania odróżniające od najbliższego mylonego pojęcia.
3. Synonimy PL: TYLKO polskie. Anglicyzmy z etykietą "anglicyzm".
4. NIE tłumacz dosłownie slangu EN→PL (np. "safety sausage" ≠ "kiełbasa").
5. Relacje nie_mylic_z: OBOWIĄZKOWE jeśli w korpusie istnieją podobne pojęcia.
   Podaj why i disambiguation_clues.
6. Evidence: minimum 1 cytat per definicja, minimum 2 źródła jeśli dostępne.
7. confidence=high tylko jeśli 2+ źródeł potwierdza.

ZNANE PARY MYLĄCE (sprawdź relacje):
{contrast_text}

WAŻNE typy synonimów:
- exact_synonym: identyczne znaczenie (np. "automat oddechowy" = "regulator")
- near_synonym: bliskie ale nie identyczne (np. "pianka" dla "skafander mokry")
- colloquial: potoczne (np. "suchacz" dla "suchy skafander")
- anglicyzm: angielski termin używany w polskim nurkowaniu (np. "BCD", "wing")
- misleading_term: WYDAJE SIĘ synonimem ale NIE jest (np. "gogle" dla "maska")
- niezalecany: przestarzały (np. "lung" dla "automat oddechowy")

WAŻNE source_id:
- padi_ch3: PADI Encyclopedia Ch3
- iantd_owd: Książka OWD IANTD
- nurkomania_sprzet: nurkomania.pl artykuły sprzętowe
- nurkomania_teoria: nurkomania.pl artykuły teoretyczne
- divezone: metadane sklepowe

Zwróć TYLKO JSON zgodny z tym schematem:
{schema_example}"""


def create_or_reuse_cache(client: genai.Client, corpus: str, force_new: bool = False) -> str:
    """Tworzy cache lub reużywa istniejący."""
    cache_ref_path = DATA / ".cache_name.txt"

    # Spróbuj reużyć istniejący cache
    if not force_new and cache_ref_path.exists():
        cache_name = cache_ref_path.read_text().strip()
        try:
            cache = client.caches.get(name=cache_name)
            logger.info("Reużywam istniejący cache: %s", cache_name)
            return cache_name
        except Exception:
            logger.info("Cache %s wygasł lub nie istnieje, tworzę nowy", cache_name)

    logger.info("Tworzenie nowego cache (korpus: %d znaków)...", len(corpus))

    cache = client.caches.create(
        model=MODEL,
        config=types.CreateCachedContentConfig(
            contents=[types.Content(
                role="user",
                parts=[types.Part(text=corpus)]
            )],
            ttl=CACHE_TTL,
        ),
    )

    cache_name = cache.name
    cache_ref_path.write_text(cache_name)
    logger.info("Cache utworzony: %s (TTL=%s)", cache_name, CACHE_TTL)
    return cache_name


def generate_concept(
    client: genai.Client,
    cache_name: str,
    concept: dict,
    contrast_text: str,
) -> Optional[dict]:
    """Generuje wpis encyklopedyczny dla jednego concept_key."""
    prompt = build_query_prompt(concept, contrast_text)

    for attempt in range(1, MAX_RETRIES + 1):
        try:
            response = client.models.generate_content(
                model=MODEL,
                contents=prompt,
                config=types.GenerateContentConfig(
                    cached_content=cache_name,
                    response_mime_type="application/json",
                    temperature=0.3,
                ),
            )

            raw_text = response.text.strip()
            tokens_in = getattr(response.usage_metadata, "prompt_token_count", 0)
            tokens_out = getattr(response.usage_metadata, "candidates_token_count", 0)
            cached = getattr(response.usage_metadata, "cached_content_token_count", 0)

            # Parsuj i waliduj Pydantic
            data = json.loads(raw_text)
            if isinstance(data, list):
                data = data[0]
            entry = ConceptEntry(**data)

            return {
                "entry": entry.model_dump(),
                "tokens_in": tokens_in,
                "tokens_out": tokens_out,
                "tokens_cached": cached,
                "attempt": attempt,
            }

        except ValidationError as e:
            logger.warning(
                "[%s] Pydantic validation failed (attempt %d/%d): %s",
                concept["concept_key"], attempt, MAX_RETRIES, str(e)[:200],
            )
            if attempt < MAX_RETRIES:
                # Retry z feedbackiem o błędzie
                prompt = (
                    f"Poprzednia odpowiedź miała błąd walidacji:\n{str(e)[:500]}\n\n"
                    f"Popraw i zwróć prawidłowy JSON.\n\n{prompt}"
                )
                time.sleep(2)
            else:
                # Zapisz surowy output do debugowania
                error_path = RAW_DIR / f"{concept['concept_key']}_error.json"
                error_path.write_text(raw_text, encoding="utf-8")
                return None

        except json.JSONDecodeError as e:
            logger.warning(
                "[%s] JSON parse error (attempt %d/%d): %s",
                concept["concept_key"], attempt, MAX_RETRIES, str(e)[:100],
            )
            if attempt == MAX_RETRIES:
                return None
            time.sleep(2)

        except Exception as e:
            logger.error(
                "[%s] API error (attempt %d/%d): %s",
                concept["concept_key"], attempt, MAX_RETRIES, str(e)[:200],
            )
            if attempt == MAX_RETRIES:
                return None
            time.sleep(5 * attempt)

    return None


def run_generation(
    limit: Optional[int] = None,
    skip_existing: bool = True,
    force_cache: bool = False,
    concept_filter: Optional[str] = None,
):
    """Główna pętla generacji."""
    client = get_client()
    concepts = load_concept_keys()
    golden = load_golden_test_set()
    corpus = load_corpus()

    if concept_filter:
        concepts = [c for c in concepts if c["concept_key"] == concept_filter]
        if not concepts:
            logger.error("Nie znaleziono concept_key: %s", concept_filter)
            return

    if limit:
        concepts = concepts[:limit]

    # Filtruj istniejące
    if skip_existing:
        existing = {p.stem for p in RAW_DIR.glob("*.json") if not p.stem.endswith("_error")}
        before = len(concepts)
        concepts = [c for c in concepts if c["concept_key"] not in existing]
        if before != len(concepts):
            logger.info("Pominięto %d istniejących, zostało %d", before - len(concepts), len(concepts))

    if not concepts:
        logger.info("Brak pojęć do wygenerowania")
        return

    # Cache
    cache_name = create_or_reuse_cache(client, corpus, force_new=force_cache)

    # Log
    log_path = DATA / "generation_log.jsonl"
    total = len(concepts)
    ok = 0
    failed = 0
    total_tokens_in = 0
    total_tokens_out = 0

    logger.info("Generacja: %d pojęć, model=%s", total, MODEL)

    for i, concept in enumerate(concepts, 1):
        ck = concept["concept_key"]
        logger.info("[%d/%d] %s (%s)", i, total, ck, concept["canonical_term_pl"])

        contrast_text = get_relevant_contrast_pairs(ck, golden)
        result = generate_concept(client, cache_name, concept, contrast_text)

        log_entry = {
            "timestamp": datetime.now().isoformat(),
            "concept_key": ck,
            "model": MODEL,
        }

        if result:
            # Zapisz raw JSON
            out_path = RAW_DIR / f"{ck}.json"
            out_path.write_text(
                json.dumps(result["entry"], ensure_ascii=False, indent=2),
                encoding="utf-8",
            )

            log_entry["status"] = "ok"
            log_entry["tokens_in"] = result["tokens_in"]
            log_entry["tokens_out"] = result["tokens_out"]
            log_entry["tokens_cached"] = result["tokens_cached"]
            log_entry["attempt"] = result["attempt"]
            total_tokens_in += result["tokens_in"]
            total_tokens_out += result["tokens_out"]
            ok += 1
            logger.info("  OK: %d syn, %d rel, confidence=%s",
                        len(result["entry"]["synonyms"]),
                        len(result["entry"]["relations"]),
                        result["entry"]["confidence"])
        else:
            log_entry["status"] = "failed"
            failed += 1
            logger.error("  FAILED: %s", ck)

        with open(log_path, "a", encoding="utf-8") as f:
            f.write(json.dumps(log_entry, ensure_ascii=False) + "\n")

        # Rate limit
        if i < total:
            time.sleep(RATE_LIMIT_DELAY)

    print(f"\n{'=' * 60}")
    print(f"GENERACJA GCIE - PODSUMOWANIE")
    print(f"{'=' * 60}")
    print(f"Model: {MODEL}")
    print(f"Pojęć: {ok}/{total} (failed: {failed})")
    print(f"Tokeny IN: {total_tokens_in:,} | OUT: {total_tokens_out:,}")
    print(f"Raw JSONy: {RAW_DIR}")
    print(f"Log: {log_path}")

    if failed > 0:
        pct = failed / total * 100
        if pct > 20:
            logger.warning("Ponad 20%% failed — rozważ fallback na Claude Opus")
        print(f"\nFailed concepts:")
        for c in concepts:
            p = RAW_DIR / f"{c['concept_key']}.json"
            if not p.exists():
                print(f"  - {c['concept_key']}")


def main():
    parser = argparse.ArgumentParser(description="GCIE: generacja encyklopedii z Gemini")
    parser.add_argument("--limit", type=int, help="Ogranicz do N pojęć")
    parser.add_argument("--concept", type=str, help="Generuj tylko jedno pojęcie")
    parser.add_argument("--force-cache", action="store_true", help="Wymuś nowy cache")
    parser.add_argument("--no-skip", action="store_true", help="Nie pomijaj istniejących")
    args = parser.parse_args()

    run_generation(
        limit=args.limit,
        skip_existing=not args.no_skip,
        force_cache=args.force_cache,
        concept_filter=args.concept,
    )


if __name__ == "__main__":
    main()
