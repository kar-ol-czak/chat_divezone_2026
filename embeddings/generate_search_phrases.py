"""
Generowanie alternatywnych fraz wyszukiwania dla produktów (TASK-011b).
Model: GPT-5.2, 5-8 fraz per produkt.
Tryby: --test 30 (sekwencyjnie, z wyświetleniem), --full (async, z checkpointami).
"""

import argparse
import asyncio
import json
import os
import time
import logging
from datetime import datetime, timezone
from pathlib import Path

import psycopg2
from dotenv import load_dotenv
from openai import OpenAI, AsyncOpenAI, RateLimitError

load_dotenv(Path(__file__).resolve().parent.parent / ".env")

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
logger = logging.getLogger(__name__)

PROJECT_ROOT = Path(__file__).resolve().parent.parent
OUTPUT_DIR = PROJECT_ROOT / "data" / "enrichment"
OUTPUT_RAW = OUTPUT_DIR / "search_phrases_raw.json"
CHECKPOINT_FILE = OUTPUT_DIR / "checkpoint_generate.json"

MODEL = os.getenv("OPENAI_CHAT_MODEL", "gpt-5.2")
TEMPERATURE = 0.7
MAX_TOKENS = 200
MAX_RETRIES = 3
CONCURRENT_LIMIT = 10  # maks równoległych requestów
CHECKPOINT_INTERVAL = 100  # zapis co 100 produktów

PROMPT_TEMPLATE = """Jesteś ekspertem nurkowym, instruktorem płetwonurkowania oraz sprzedawcą sprzętu
nurkowego z 20-letnim stażem pracującym dla divezone.pl, największego sklepu
nurkowego w Polsce. Wiesz wszystko o sprzęcie do nurkowania i technice nurkowej.
Znasz realia polskiego rynku nurkowego i wiesz jak klienci szukają sprzętu.

Budujemy czat AI, który odczytuje intencje klientów i mapuje je na produkty w sklepie.
Twoim zadaniem jest podać 5-8 alternatywnych fraz wyszukiwania, którymi POLSKI NUREK
mógłby szukać poniższego produktu.

PRODUKT: "{product_name}"
KATEGORIA: "{category_name}"
MARKA: {brand_name}
CECHY: {features}
OPIS: {description}

ZASADY:
- Frazy po polsku, potoczne i branżowe (jak nurek mówi do kolegi)
- Uwzględnij synonimy (pianka/skafander/wetsuit), kontekst użycia (zimna woda/Polska/Egipt),
  parametry techniczne (7mm, DIN, nitrox) i przeznaczenie (rekreacja/tech/jaskinie)
- Frazy 2-6 słów, bez powtarzania nazwy produktu ani marki
- Uwzględnij WSZYSTKIE regiony gdzie produkt ma zastosowanie
- Jeśli produkt ma warianty płciowe, uwzględnij frazy z "damski/męski"
- NIE generuj fraz negatywnych (bez "nie jest", "to nie")

FORMAT: jedna fraza per linia, bez numeracji, bez dodatkowych komentarzy."""


def get_db_connection():
    """Zwraca połączenie z PostgreSQL."""
    database_url = os.getenv("DATABASE_URL")
    if not database_url:
        raise ValueError("Brak DATABASE_URL w .env")
    return psycopg2.connect(database_url)


def fetch_products(conn, category_patterns: list[str] = None, limit: int = None) -> list[dict]:
    """Pobiera produkty z bazy. Opcjonalnie filtruje po kategoriach."""
    cur = conn.cursor()

    if category_patterns:
        # Buduj WHERE z ILIKE per pattern
        conditions = " OR ".join(["category_name ILIKE %s"] * len(category_patterns))
        sql = f"""SELECT ps_product_id, product_name, product_description,
                         category_name, brand_name, features
                  FROM divechat_product_embeddings
                  WHERE {conditions}
                  ORDER BY ps_product_id"""
        cur.execute(sql, category_patterns)
    else:
        sql = """SELECT ps_product_id, product_name, product_description,
                        category_name, brand_name, features
                 FROM divechat_product_embeddings
                 ORDER BY ps_product_id"""
        cur.execute(sql)

    rows = cur.fetchall()
    cur.close()

    products = []
    for r in rows:
        products.append({
            "ps_product_id": r[0],
            "product_name": r[1],
            "product_description": r[2] or "",
            "category_name": r[3] or "",
            "brand_name": r[4] or "",
            "features": r[5] if isinstance(r[5], dict) else json.loads(r[5]) if r[5] else {},
        })

    if limit:
        products = products[:limit]

    return products


def build_prompt(product: dict) -> str:
    """Buduje prompt dla produktu."""
    features_str = ", ".join(f"{k}: {v}" for k, v in product["features"].items()) if product["features"] else "brak"
    desc = product["product_description"][:300] if product["product_description"] else "brak"

    return PROMPT_TEMPLATE.format(
        product_name=product["product_name"],
        category_name=product["category_name"],
        brand_name=product["brand_name"] or "brak",
        features=features_str,
        description=desc,
    )


def parse_phrases(text: str) -> list[str]:
    """Parsuje odpowiedź LLM na listę fraz."""
    lines = text.strip().split("\n")
    phrases = []
    for line in lines:
        # Usuń numerację, myślniki, gwiazdki
        clean = line.strip().lstrip("0123456789.-)*•► ").strip()
        if clean and len(clean) >= 5 and len(clean) <= 80:
            phrases.append(clean.lower())
    return phrases


def generate_for_product_sync(client: OpenAI, product: dict) -> dict:
    """Generuje frazy dla jednego produktu (sync)."""
    prompt = build_prompt(product)

    for attempt in range(1, MAX_RETRIES + 1):
        try:
            response = client.chat.completions.create(
                model=MODEL,
                temperature=TEMPERATURE,
                max_completion_tokens=MAX_TOKENS,
                messages=[{"role": "user", "content": prompt}],
            )
            raw_text = response.choices[0].message.content.strip()
            phrases = parse_phrases(raw_text)

            return {
                "product_name": product["product_name"],
                "category_name": product["category_name"],
                "brand_name": product["brand_name"],
                "phrases": phrases,
                "model": MODEL,
                "tokens_in": response.usage.prompt_tokens,
                "tokens_out": response.usage.completion_tokens,
                "timestamp": datetime.now(timezone.utc).isoformat(),
            }
        except RateLimitError:
            if attempt < MAX_RETRIES:
                delay = 5 * attempt
                logger.warning("Rate limit, czekam %ds (próba %d/%d)", delay, attempt, MAX_RETRIES)
                time.sleep(delay)
            else:
                raise
        except Exception as e:
            logger.error("Błąd dla produktu %s (próba %d): %s",
                         product["ps_product_id"], attempt, e)
            if attempt == MAX_RETRIES:
                return {
                    "product_name": product["product_name"],
                    "phrases": [],
                    "error": str(e),
                    "timestamp": datetime.now(timezone.utc).isoformat(),
                }
            time.sleep(2)

    return {"product_name": product["product_name"], "phrases": [], "error": "max retries"}


async def generate_for_product_async(client: AsyncOpenAI, product: dict, semaphore: asyncio.Semaphore) -> tuple[int, dict]:
    """Generuje frazy dla jednego produktu (async)."""
    prompt = build_prompt(product)
    pid = product["ps_product_id"]

    async with semaphore:
        for attempt in range(1, MAX_RETRIES + 1):
            try:
                response = await client.chat.completions.create(
                    model=MODEL,
                    temperature=TEMPERATURE,
                    max_completion_tokens=MAX_TOKENS,
                    messages=[{"role": "user", "content": prompt}],
                )
                raw_text = response.choices[0].message.content.strip()
                phrases = parse_phrases(raw_text)

                return pid, {
                    "product_name": product["product_name"],
                    "category_name": product["category_name"],
                    "brand_name": product["brand_name"],
                    "phrases": phrases,
                    "model": MODEL,
                    "tokens_in": response.usage.prompt_tokens,
                    "tokens_out": response.usage.completion_tokens,
                    "timestamp": datetime.now(timezone.utc).isoformat(),
                }
            except RateLimitError:
                if attempt < MAX_RETRIES:
                    delay = 5 * attempt
                    logger.warning("Rate limit pid=%s, czekam %ds", pid, delay)
                    await asyncio.sleep(delay)
                else:
                    raise
            except Exception as e:
                logger.error("Błąd pid=%s (próba %d): %s", pid, attempt, e)
                if attempt == MAX_RETRIES:
                    return pid, {"product_name": product["product_name"], "phrases": [], "error": str(e)}
                await asyncio.sleep(2)

    return pid, {"product_name": product["product_name"], "phrases": [], "error": "max retries"}


def fetch_test_products(conn) -> list[dict]:
    """Pobiera 30 produktów testowych: 10 Skafandry, 10 Komputery, 10 Automaty."""
    test_products = []
    for pattern, limit in [
        ("%Skafandr%ZIMNE%", 10),
        ("%Komputer%", 10),
        ("%Automat%Oddech%", 10),
    ]:
        products = fetch_products(conn, [pattern], limit=limit)
        test_products.extend(products)
        logger.info("Kategoria '%s': %d produktów", pattern, len(products))
    return test_products


def run_test(count: int = 30):
    """Tryb testowy: generuje frazy dla 30 produktów i wyświetla wyniki."""
    client = OpenAI(api_key=os.getenv("OPENAI_API_KEY"))
    conn = get_db_connection()

    if count == 30:
        products = fetch_test_products(conn)
    else:
        products = fetch_products(conn, limit=count)

    conn.close()

    logger.info("Generuję frazy dla %d produktów (model: %s)", len(products), MODEL)

    results = {}
    total_tokens_in = 0
    total_tokens_out = 0

    for i, product in enumerate(products, 1):
        pid = product["ps_product_id"]
        result = generate_for_product_sync(client, product)
        results[str(pid)] = result
        total_tokens_in += result.get("tokens_in", 0)
        total_tokens_out += result.get("tokens_out", 0)

        logger.info("[%d/%d] ID:%s %s → %d fraz",
                     i, len(products), pid, product["product_name"][:40], len(result["phrases"]))

    # Zapisz raw output
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    with open(OUTPUT_RAW, "w", encoding="utf-8") as f:
        json.dump(results, f, ensure_ascii=False, indent=2)

    # Podsumowanie
    print(f"\n{'='*60}")
    print(f"GENEROWANIE FRAZ - PODSUMOWANIE")
    print(f"{'='*60}")
    print(f"Produktów: {len(products)}")
    print(f"Tokenów input: {total_tokens_in:,}")
    print(f"Tokenów output: {total_tokens_out:,}")
    print(f"Plik: {OUTPUT_RAW}")

    return results


async def run_full():
    """Tryb pełny: async generowanie dla wszystkich produktów z checkpointami."""
    client = AsyncOpenAI(api_key=os.getenv("OPENAI_API_KEY"))
    conn = get_db_connection()
    products = fetch_products(conn)
    conn.close()

    # Wczytaj checkpoint jeśli istnieje
    results = {}
    if CHECKPOINT_FILE.exists():
        with open(CHECKPOINT_FILE, encoding="utf-8") as f:
            results = json.load(f)
        logger.info("Wczytano checkpoint: %d produktów", len(results))

    # Odfiltruj już przetworzone
    remaining = [p for p in products if str(p["ps_product_id"]) not in results]
    logger.info("Do przetworzenia: %d produktów (pomijam %d z checkpointu)", len(remaining), len(results))

    semaphore = asyncio.Semaphore(CONCURRENT_LIMIT)
    total_tokens_in = sum(r.get("tokens_in", 0) for r in results.values())
    total_tokens_out = sum(r.get("tokens_out", 0) for r in results.values())
    processed = len(results)

    # Przetwarzaj w batchach po CHECKPOINT_INTERVAL
    for batch_start in range(0, len(remaining), CHECKPOINT_INTERVAL):
        batch = remaining[batch_start:batch_start + CHECKPOINT_INTERVAL]
        tasks = [generate_for_product_async(client, p, semaphore) for p in batch]
        batch_results = await asyncio.gather(*tasks)

        for pid, result in batch_results:
            results[str(pid)] = result
            total_tokens_in += result.get("tokens_in", 0)
            total_tokens_out += result.get("tokens_out", 0)
            processed += 1

        # Checkpoint
        OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
        with open(CHECKPOINT_FILE, "w", encoding="utf-8") as f:
            json.dump(results, f, ensure_ascii=False, indent=2)

        logger.info("Checkpoint: %d/%d produktów (tokens in:%d out:%d)",
                     processed, len(products), total_tokens_in, total_tokens_out)

    # Zapisz finalny output
    with open(OUTPUT_RAW, "w", encoding="utf-8") as f:
        json.dump(results, f, ensure_ascii=False, indent=2)

    # Usuń checkpoint po sukcesie
    CHECKPOINT_FILE.unlink(missing_ok=True)

    print(f"\n{'='*60}")
    print(f"GENEROWANIE FRAZ - PODSUMOWANIE (FULL)")
    print(f"{'='*60}")
    print(f"Produktów: {len(results)}")
    print(f"Tokenów input: {total_tokens_in:,}")
    print(f"Tokenów output: {total_tokens_out:,}")
    print(f"Plik: {OUTPUT_RAW}")

    return results


def main():
    parser = argparse.ArgumentParser(description="Generowanie fraz wyszukiwania (GPT-5.2)")
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--test", type=int, metavar="N", default=None,
                       help="Tryb testowy: N produktów (30 = test kategorii)")
    group.add_argument("--full", action="store_true", help="Wszystkie produkty (async)")
    args = parser.parse_args()

    if args.test:
        run_test(args.test)
    else:
        asyncio.run(run_full())


if __name__ == "__main__":
    main()
