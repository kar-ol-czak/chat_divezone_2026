"""
Walidacja fraz wyszukiwania przez Claude Opus 4.6 (TASK-011b, Krok 3).
Wczytuje raw JSON, wysyła do Claude do oceny, zapisuje validated JSON.
Tryby: domyślny (sekwencyjny), --full (async z checkpointami).
"""

from __future__ import annotations

import argparse
import asyncio
import json
import os
import re
import time
import logging
from datetime import datetime, timezone
from pathlib import Path

import anthropic
from dotenv import load_dotenv

load_dotenv(Path(__file__).resolve().parent.parent / ".env")

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
logger = logging.getLogger(__name__)

PROJECT_ROOT = Path(__file__).resolve().parent.parent
OUTPUT_DIR = PROJECT_ROOT / "data" / "enrichment"
INPUT_RAW = OUTPUT_DIR / "search_phrases_raw.json"
OUTPUT_VALIDATED = OUTPUT_DIR / "search_phrases_validated.json"
CHECKPOINT_FILE = OUTPUT_DIR / "checkpoint_validate.json"
REVIEW_FILE = OUTPUT_DIR / "test_30_products_review.md"

MODEL = "claude-opus-4-6"
MAX_RETRIES = 5
CONCURRENT_LIMIT = 5  # maks równoległych requestów do Claude
CHECKPOINT_INTERVAL = 100  # zapis co 100 produktów

VALIDATION_PROMPT = """Jesteś ekspertem nurkowym. Oceń frazy wyszukiwania wygenerowane dla produktu.

PRODUKT: "{product_name}"
KATEGORIA: "{category_name}"
MARKA: {brand_name}

WYGENEROWANE FRAZY:
{phrases_joined}

OCEŃ każdą frazę:
- OK: fraza jest trafna, polski nurek mógłby jej użyć
- USUN: fraza jest nietrafna, zbyt ogólna lub myląca
- POPRAW: fraza wymaga korekty (podaj poprawioną wersję)
- DODAJ: brakuje oczywistej frazy (podaj brakujące)

WAŻNE: NIE usuwaj fraz zawierających kontekst użycia, nawet jeśli pasują do wielu produktów:
- Geografia: Egipt, Bałtyk, Polska, Chorwacja, safari, Morze Czerwone, jeziora, kamieniołom
- Warunki: zimna woda, ciepła woda, tropiki, pod lód
- Typ nurkowania: rekreacja, techniczne, jaskinie, wraki, sidemount, freediving
- Przeznaczenie: podróż, shore diving, z łodzi
Te frazy pomagają klientowi trafić w odpowiednią kategorię produktów. Usuwaj TYLKO frazy faktycznie mylące (produkt do zimnej wody opisany jako "na egipt") lub identyczne z nazwą produktu.

FORMAT odpowiedzi (TYLKO JSON, bez komentarzy):
{{"keep": ["fraza1", "fraza2"], "remove": ["fraza3"], "fix": [{{"old": "fraza4", "new": "poprawiona"}}], "add": ["nowa fraza"]}}"""


def _build_prompt(data: dict) -> str:
    """Buduje prompt walidacyjny dla produktu."""
    phrases_joined = "\n".join(f"- {p}" for p in data["phrases"])
    return VALIDATION_PROMPT.format(
        product_name=data["product_name"],
        category_name=data.get("category_name", ""),
        brand_name=data.get("brand_name", "brak"),
        phrases_joined=phrases_joined,
    )


def _parse_validation(raw_text: str, data: dict) -> tuple[list[str], dict | None]:
    """Parsuje odpowiedź Claude na listę fraz i obiekt walidacji."""
    json_match = re.search(r'\{.*\}', raw_text, re.DOTALL)
    if not json_match:
        return data["phrases"], None

    validation = json.loads(json_match.group())

    # Zbierz finalne frazy
    final_phrases = list(validation.get("keep", []))
    for fix in validation.get("fix", []):
        if isinstance(fix, dict) and "new" in fix:
            final_phrases.append(fix["new"])
    for added in validation.get("add", []):
        final_phrases.append(added)

    # Normalizuj + deduplikacja
    seen = set()
    unique = []
    for p in final_phrases:
        p_clean = p.strip().lower()
        if p_clean and p_clean not in seen:
            seen.add(p_clean)
            unique.append(p_clean)

    return unique, validation


def validate_product(client: anthropic.Anthropic, pid: str, data: dict) -> dict:
    """Wysyła frazy do Claude Opus do walidacji (sync)."""
    if not data.get("phrases"):
        return {**data, "validated_phrases": [], "validation": {"keep": [], "remove": [], "fix": [], "add": []}}

    prompt = _build_prompt(data)

    for attempt in range(1, MAX_RETRIES + 1):
        try:
            response = client.messages.create(
                model=MODEL,
                max_tokens=512,
                messages=[{"role": "user", "content": prompt}],
            )
            raw_text = response.content[0].text.strip()
            unique, validation = _parse_validation(raw_text, data)

            if validation is None:
                logger.warning("Brak JSON w odpowiedzi dla pid=%s", pid)
                return {**data, "validated_phrases": data["phrases"], "validation": None}

            return {
                **data,
                "validated_phrases": unique,
                "validation": validation,
                "validator_model": MODEL,
                "validator_tokens_in": response.usage.input_tokens,
                "validator_tokens_out": response.usage.output_tokens,
                "validated_at": datetime.now(timezone.utc).isoformat(),
            }

        except anthropic.RateLimitError:
            if attempt < MAX_RETRIES:
                delay = 10 * attempt
                logger.warning("Rate limit, czekam %ds (próba %d/%d)", delay, attempt, MAX_RETRIES)
                time.sleep(delay)
            else:
                raise
        except (json.JSONDecodeError, Exception) as e:
            logger.warning("Błąd walidacji pid=%s (próba %d): %s", pid, attempt, e)
            if attempt == MAX_RETRIES:
                return {**data, "validated_phrases": data["phrases"], "validation": None, "error": str(e)}
            time.sleep(3)

    return {**data, "validated_phrases": data["phrases"], "validation": None}


async def validate_product_async(
    client: anthropic.AsyncAnthropic, pid: str, data: dict, semaphore: asyncio.Semaphore
) -> tuple[str, dict]:
    """Wysyła frazy do Claude Opus do walidacji (async)."""
    if not data.get("phrases"):
        return pid, {**data, "validated_phrases": [], "validation": {"keep": [], "remove": [], "fix": [], "add": []}}

    prompt = _build_prompt(data)

    async with semaphore:
        for attempt in range(1, MAX_RETRIES + 1):
            try:
                response = await client.messages.create(
                    model=MODEL,
                    max_tokens=512,
                    messages=[{"role": "user", "content": prompt}],
                )
                raw_text = response.content[0].text.strip()
                unique, validation = _parse_validation(raw_text, data)

                if validation is None:
                    logger.warning("Brak JSON w odpowiedzi dla pid=%s", pid)
                    return pid, {**data, "validated_phrases": data["phrases"], "validation": None}

                return pid, {
                    **data,
                    "validated_phrases": unique,
                    "validation": validation,
                    "validator_model": MODEL,
                    "validator_tokens_in": response.usage.input_tokens,
                    "validator_tokens_out": response.usage.output_tokens,
                    "validated_at": datetime.now(timezone.utc).isoformat(),
                }

            except anthropic.RateLimitError:
                if attempt < MAX_RETRIES:
                    delay = 10 * attempt
                    logger.warning("Rate limit pid=%s, czekam %ds (próba %d/%d)", pid, delay, attempt, MAX_RETRIES)
                    await asyncio.sleep(delay)
                else:
                    raise
            except anthropic.APIStatusError as e:
                if e.status_code in (429, 529, 500) and attempt < MAX_RETRIES:
                    delay = 5 * attempt
                    logger.warning("API error %d pid=%s, czekam %ds (próba %d/%d)",
                                   e.status_code, pid, delay, attempt, MAX_RETRIES)
                    await asyncio.sleep(delay)
                elif attempt == MAX_RETRIES:
                    return pid, {**data, "validated_phrases": data["phrases"], "validation": None, "error": str(e)}
                else:
                    await asyncio.sleep(3)
            except (json.JSONDecodeError, Exception) as e:
                logger.warning("Błąd walidacji pid=%s (próba %d): %s", pid, attempt, e)
                if attempt == MAX_RETRIES:
                    return pid, {**data, "validated_phrases": data["phrases"], "validation": None, "error": str(e)}
                await asyncio.sleep(3)

    return pid, {**data, "validated_phrases": data["phrases"], "validation": None}


async def run_full(raw_data: dict):
    """Tryb pełny: async walidacja z checkpointami."""
    api_key = os.getenv("ANTHROPIC_API_KEY")
    if not api_key:
        raise ValueError("Brak ANTHROPIC_API_KEY w .env")

    client = anthropic.AsyncAnthropic(api_key=api_key)
    semaphore = asyncio.Semaphore(CONCURRENT_LIMIT)

    # Wczytaj checkpoint
    results = {}
    if CHECKPOINT_FILE.exists():
        with open(CHECKPOINT_FILE, encoding="utf-8") as f:
            results = json.load(f)
        logger.info("Wczytano checkpoint: %d produktów", len(results))

    # Odfiltruj przetworzone
    remaining = [(pid, data) for pid, data in raw_data.items() if pid not in results]
    logger.info("Do walidacji: %d produktów (pomijam %d z checkpointu)", len(remaining), len(results))

    processed = len(results)
    total = len(raw_data)

    # Przetwarzaj w batchach po CHECKPOINT_INTERVAL
    for batch_start in range(0, len(remaining), CHECKPOINT_INTERVAL):
        batch = remaining[batch_start:batch_start + CHECKPOINT_INTERVAL]
        tasks = [validate_product_async(client, pid, data, semaphore) for pid, data in batch]
        batch_results = await asyncio.gather(*tasks)

        for pid, result in batch_results:
            results[pid] = result
            processed += 1

        # Checkpoint
        OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
        with open(CHECKPOINT_FILE, "w", encoding="utf-8") as f:
            json.dump(results, f, ensure_ascii=False, indent=2)

        total_validated = sum(len(r.get("validated_phrases", [])) for r in results.values())
        logger.info("Checkpoint: %d/%d produktów, %d fraz", processed, total, total_validated)

    # Zapisz finalny output
    with open(OUTPUT_VALIDATED, "w", encoding="utf-8") as f:
        json.dump(results, f, ensure_ascii=False, indent=2)

    # Usuń checkpoint
    CHECKPOINT_FILE.unlink(missing_ok=True)

    # Podsumowanie
    total_raw = sum(len(d.get("phrases", [])) for d in raw_data.values())
    total_validated = sum(len(d.get("validated_phrases", [])) for d in results.values())

    print(f"\n{'='*60}")
    print(f"WALIDACJA FRAZ - PODSUMOWANIE (FULL)")
    print(f"{'='*60}")
    print(f"Produktów: {total}")
    print(f"Fraz wejściowych: {total_raw}")
    print(f"Fraz po walidacji: {total_validated}")
    print(f"Średnio fraz/produkt: {total_validated / max(total, 1):.1f}")
    print(f"Plik: {OUTPUT_VALIDATED}")


def generate_review_md(results: dict) -> str:
    """Generuje plik Markdown do review przez Karola."""
    lines = ["# Review fraz wyszukiwania (test 30 produktów)\n"]
    lines.append(f"Data: {datetime.now().strftime('%Y-%m-%d %H:%M')}\n")

    by_category = {}
    for pid, data in results.items():
        cat = data.get("category_name", "Inne")
        if cat not in by_category:
            by_category[cat] = []
        by_category[cat].append((pid, data))

    total_phrases = 0
    total_removed = 0
    total_added = 0

    for cat in sorted(by_category.keys()):
        lines.append(f"\n## {cat}\n")
        for pid, data in sorted(by_category[cat], key=lambda x: x[0]):
            name = data["product_name"]
            brand = data.get("brand_name", "")
            validated = data.get("validated_phrases", [])
            validation = data.get("validation", {}) or {}

            removed = validation.get("remove", [])
            fixed = validation.get("fix", [])
            added = validation.get("add", [])

            total_phrases += len(validated)
            total_removed += len(removed)
            total_added += len(added)

            lines.append(f"### [{pid}] {name}")
            if brand:
                lines.append(f"**Marka:** {brand}\n")
            lines.append(f"**Frazy ({len(validated)}):**")
            for p in validated:
                lines.append(f"- {p}")
            if removed:
                lines.append(f"\n~~Usunięte: {', '.join(removed)}~~")
            if fixed:
                for fix in fixed:
                    if isinstance(fix, dict):
                        lines.append(f"\n*Poprawione: \"{fix.get('old')}\" → \"{fix.get('new')}\"*")
            lines.append("")

    summary = [
        f"\n---\n## Podsumowanie\n",
        f"- Produktów: {len(results)}",
        f"- Łącznie fraz: {total_phrases}",
        f"- Średnio fraz/produkt: {total_phrases / max(len(results), 1):.1f}",
        f"- Usunięto przez walidatora: {total_removed}",
        f"- Dodano przez walidatora: {total_added}",
    ]
    lines.extend(summary)
    return "\n".join(lines)


def main():
    parser = argparse.ArgumentParser(description="Walidacja fraz wyszukiwania (Claude Opus 4.6)")
    parser.add_argument("--input", type=str, default=str(INPUT_RAW), help="Plik wejściowy z raw phrases")
    parser.add_argument("--review", action="store_true", help="Generuj plik review MD")
    parser.add_argument("--full", action="store_true", help="Tryb pełny: async z checkpointami")
    args = parser.parse_args()

    input_path = Path(args.input)
    if not input_path.exists():
        logger.error("Brak pliku wejściowego: %s", input_path)
        return

    with open(input_path, encoding="utf-8") as f:
        raw_data = json.load(f)

    logger.info("Wczytano %d produktów do walidacji", len(raw_data))

    if args.full:
        asyncio.run(run_full(raw_data))
    else:
        # Tryb sekwencyjny (test)
        api_key = os.getenv("ANTHROPIC_API_KEY")
        if not api_key:
            raise ValueError("Brak ANTHROPIC_API_KEY w .env")

        client = anthropic.Anthropic(api_key=api_key)
        results = {}
        total_products = len(raw_data)

        for i, (pid, data) in enumerate(raw_data.items(), 1):
            logger.info("[%d/%d] Walidacja ID:%s %s (%d fraz)",
                         i, total_products, pid, data["product_name"][:40], len(data.get("phrases", [])))
            result = validate_product(client, pid, data)
            results[pid] = result
            original_count = len(data.get("phrases", []))
            final_count = len(result.get("validated_phrases", []))
            logger.info("  → %d → %d fraz", original_count, final_count)
            if i < total_products:
                time.sleep(0.5)

        OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
        with open(OUTPUT_VALIDATED, "w", encoding="utf-8") as f:
            json.dump(results, f, ensure_ascii=False, indent=2)
        logger.info("Zapisano walidowane frazy do %s", OUTPUT_VALIDATED)

        if args.review:
            review_md = generate_review_md(results)
            with open(REVIEW_FILE, "w", encoding="utf-8") as f:
                f.write(review_md)
            logger.info("Zapisano plik review do %s", REVIEW_FILE)

        total_raw = sum(len(d.get("phrases", [])) for d in raw_data.values())
        total_validated = sum(len(d.get("validated_phrases", [])) for d in results.values())

        print(f"\n{'='*60}")
        print(f"WALIDACJA FRAZ - PODSUMOWANIE")
        print(f"{'='*60}")
        print(f"Produktów: {total_products}")
        print(f"Fraz wejściowych: {total_raw}")
        print(f"Fraz po walidacji: {total_validated}")
        print(f"Średnio fraz/produkt: {total_validated / max(total_products, 1):.1f}")
        print(f"Plik: {OUTPUT_VALIDATED}")


if __name__ == "__main__":
    main()
