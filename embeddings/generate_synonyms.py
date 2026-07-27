"""
Generowanie synonimów produktowych PL + EN (TASK-013).
Krok 1: Sonnet + słownik referencyjny z divechat_synonyms.
Krok 2: Walidacja Opusem (--validate).
"""

from __future__ import annotations

import argparse
import csv
import json
import os
import re
import time
import logging
from pathlib import Path

import anthropic
import psycopg2
from dotenv import load_dotenv

load_dotenv(Path(__file__).resolve().parent.parent / ".env")

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
logger = logging.getLogger(__name__)

MODEL_GENERATE = os.getenv("SYNONYM_MODEL", "claude-sonnet-4-6")
MODEL_VALIDATE = "claude-opus-4-6"
MAX_RETRIES = 5
BATCH_SIZE = 10
RATE_LIMIT_DELAY = 1.2  # ~50 req/min


def get_db_connection():
    database_url = os.getenv("DATABASE_URL")
    if not database_url:
        raise ValueError("Brak DATABASE_URL w .env")
    return psycopg2.connect(database_url)


def load_reference_dict(conn) -> str:
    """Ładuje słownik synonimów z divechat_synonyms i formatuje jako tekst referencyjny."""
    cur = conn.cursor()
    cur.execute("""
        SELECT canonical_term, string_agg(synonym, ', ' ORDER BY synonym)
        FROM divechat_synonyms
        GROUP BY canonical_term
        ORDER BY canonical_term
    """)
    rows = cur.fetchall()
    cur.close()

    lines = []
    for canonical, synonyms in rows:
        lines.append(f"- {canonical}: {synonyms}")
    return "\n".join(lines)


def build_system_prompt(reference_dict: str) -> str:
    """Buduje system prompt ze słownikiem referencyjnym."""
    return f"""Jesteś ekspertem nurkowym i lingwistą. Generujesz synonimy nazw produktów i kategorii sprzętu nurkowego.

SŁOWNIK REFERENCYJNY (sprawdzone terminy polskiego żargonu nurkowego):
{reference_dict}

ZASADY:
- Generuj synonimy NAZW PRODUKTOWYCH i KATEGORII, nie cech (kolor, rozmiar)
- PL: 3-6 synonimów polskich, EN: 3-5 angielskich
- KORZYSTAJ ze słownika referencyjnego — dobieraj terminy pasujące do produktu
- NIE WYMYŚLAJ terminów, których nie znasz. Używaj TYLKO słów faktycznie stosowanych w polskim nurkowaniu
- Lista PL musi zawierać WYŁĄCZNIE polskie słowa. Angielskie terminy (sausage, wetsuit) idą do listy EN
  Wyjątek: angielskie terminy zakorzenione w polskim żargonie (BCD, SMB, DIN, INT, nitrox) mogą być w PL
- NIE twórz kalek z angielskiego (np. "znacznik powierzchniowy" z "surface marker" — nikt tak nie mówi)
- NIE powtarzaj nazwy produktu ani marki jako synonimu
- Każdy synonim: 1-4 słowa, małe litery

PRZYKŁADY:

Produkt: "Pianka Mares Flexa 8.6.5" | Kategoria: "Pianki neoprenowe"
→ {{"pl": ["skafander mokry", "pianka do nurkowania", "pianka neoprenowa", "neopren"], "en": ["wetsuit", "neoprene suit", "wet suit", "diving suit"]}}

Produkt: "Jacket Aqualung Axiom" | Kategoria: "Jackety"
→ {{"pl": ["kamizelka wypornościowa", "BCD", "jacket nurkowy", "kompensator pływalności"], "en": ["BCD", "buoyancy compensator", "buoyancy control device", "dive jacket"]}}

Produkt: "Automat oddechowy Apeks XTX 200" | Kategoria: "Automaty oddechowe"
→ {{"pl": ["aparat oddechowy", "regulator", "automat", "lung"], "en": ["regulator", "breathing apparatus", "demand valve", "scuba regulator"]}}

Produkt: "HALCYON Duża boja nurkowa" | Kategoria: "Boje"
→ {{"pl": ["boja dekompresyjna", "bojka deco", "bojka deko", "SMB", "boja sygnalizacyjna"], "en": ["SMB", "surface marker buoy", "DSMB", "deco buoy", "safety sausage"]}}

Produkt: "Komputer SUUNTO D5" | Kategoria: "Komputery nurkowe"
→ {{"pl": ["komputer nurkowy", "komputer do nurkowania", "komp"], "en": ["dive computer", "diving computer", "decompression computer"]}}"""


USER_PROMPT_TEMPLATE = """Wygeneruj synonimy PL i EN dla każdego produktu poniżej.
Korzystaj ze słownika referencyjnego. NIE wymyślaj terminów — używaj tylko faktycznie stosowanych.

{products_block}

Odpowiedz TYLKO JSON-em — tablica obiektów, jeden per produkt:
[
  {{"id": <ps_product_id>, "pl": ["synonim1", ...], "en": ["synonym1", ...]}},
  ...
]"""


VALIDATE_PROMPT = """Jesteś doświadczonym polskim nurkiem i instruktorem z 20-letnim stażem.
Zweryfikuj synonimy wygenerowane dla produktu nurkowego.

PRODUKT: "{product_name}"
KATEGORIA: "{category_name}"
MARKA: {brand_name}

SYNONIMY PL: {pl_list}
SYNONIMY EN: {en_list}

OCEŃ każdy synonim:
- OK: synonim jest poprawny, faktycznie używany w polskim/angielskim nurkowaniu
- USUN: synonim jest zmyślony, jest kalką z angielskiego, lub nikt tak nie mówi
- POPRAW: synonim wymaga korekty (podaj poprawioną wersję)
- DODAJ: brakuje oczywistego synonimu (podaj)

WAŻNE:
- W liście PL mogą być TYLKO polskie słowa (wyjątek: zakorzenione skróty jak BCD, SMB, DIN)
- Angielskie słowa (sausage, wetsuit, regulator) idą do EN, nie PL
- NIE akceptuj kalek z angielskiego (np. "znacznik powierzchniowy")
- Sprawdź czy nie brakuje standardowych terminów ze słownika nurkowego

FORMAT odpowiedzi (TYLKO JSON):
{{"keep_pl": ["ok1", ...], "remove_pl": ["zly1"], "fix_pl": [{{"old": "x", "new": "y"}}], "add_pl": ["nowy"],
  "keep_en": ["ok1", ...], "remove_en": ["zly1"], "fix_en": [{{"old": "x", "new": "y"}}], "add_en": ["nowy"]}}"""


def fetch_products(conn, only_missing: bool = False, limit: int = None) -> list[dict]:
    """Pobiera produkty z bazy.

    Opis pobierany osobno — kilka produktów ma uszkodzone UTF-8 w product_description
    (importowane z MySQL latin1). Dla nich desc_short = "".
    """
    cur = conn.cursor()
    where = "is_active = true"
    if only_missing:
        where += " AND (synonyms_pl = '[]'::jsonb OR synonyms_pl IS NULL)"

    sql = f"""SELECT ps_product_id, product_name, category_name, brand_name
              FROM divechat_product_embeddings
              WHERE {where}
              ORDER BY ps_product_id"""
    if limit:
        sql += f" LIMIT {int(limit)}"

    cur.execute(sql)
    rows = cur.fetchall()

    products = []
    for r in rows:
        pid = r[0]
        desc = ""
        try:
            cur.execute(
                "SELECT LEFT(product_description, 200) FROM divechat_product_embeddings WHERE ps_product_id = %s",
                (pid,),
            )
            desc = cur.fetchone()[0] or ""
        except Exception:
            conn.rollback()
            logger.warning("Uszkodzone UTF-8 w opisie pid=%d, pomijam opis", pid)

        products.append({
            "ps_product_id": pid,
            "product_name": r[1] or "",
            "category_name": r[2] or "",
            "brand_name": r[3] or "",
            "desc_short": desc,
        })

    cur.close()
    return products


def fetch_products_with_synonyms(conn, limit: int = None) -> list[dict]:
    """Pobiera produkty z istniejącymi synonimami (do walidacji)."""
    cur = conn.cursor()
    sql = """SELECT ps_product_id, product_name, category_name, brand_name,
                    synonyms_pl::text, synonyms_en::text
             FROM divechat_product_embeddings
             WHERE is_active = true AND synonyms_pl <> '[]'::jsonb
             ORDER BY ps_product_id"""
    if limit:
        sql += f" LIMIT {int(limit)}"

    cur.execute(sql)
    rows = cur.fetchall()
    cur.close()

    return [
        {
            "ps_product_id": r[0],
            "product_name": r[1] or "",
            "category_name": r[2] or "",
            "brand_name": r[3] or "",
            "synonyms_pl": json.loads(r[4]) if r[4] else [],
            "synonyms_en": json.loads(r[5]) if r[5] else [],
        }
        for r in rows
    ]


def build_products_block(products: list[dict]) -> str:
    """Buduje blok produktów do promptu."""
    lines = []
    for p in products:
        lines.append(
            f"[{p['ps_product_id']}] \"{p['product_name']}\" | "
            f"Kategoria: {p['category_name']} | Marka: {p['brand_name']}\n"
            f"  Opis: {p['desc_short'][:150]}"
        )
    return "\n\n".join(lines)


def parse_response(text: str, expected_ids: list[int]) -> dict[int, dict]:
    """Parsuje odpowiedź Claude na mapę {pid: {pl: [...], en: [...]}}."""
    json_match = re.search(r'\[[\s\S]*\]', text)
    if not json_match:
        logger.warning("Brak JSON tablicy w odpowiedzi")
        return {}

    data = json.loads(json_match.group())
    result = {}

    for item in data:
        pid = item.get("id")
        if pid not in expected_ids:
            continue

        pl = [s.strip().lower() for s in item.get("pl", []) if isinstance(s, str) and s.strip()]
        en = [s.strip().lower() for s in item.get("en", []) if isinstance(s, str) and s.strip()]

        result[pid] = {"pl": list(dict.fromkeys(pl)), "en": list(dict.fromkeys(en))}

    return result


def postprocess_synonyms(synonyms_map: dict[int, dict], products: list[dict]) -> dict[int, dict]:
    """Post-processing: hard rules niezależne od modelu.

    1. Usuwa "kiełbasa" z synonyms_pl.
    2. "aparat oddechowy" — kontekstowo:
       a) automat/stopień → samodzielny synonim "aparat oddechowy"
       b) akcesorium do automatu (torba, pokrowiec, zawór odcinający) → kontekstowy, np. "torba na aparat oddechowy"
       c) zawory do suchego skafandra i inne niezwiązane → pomijaj
    3. Przenosi "lung" z PL do EN (to angielski termin).
    """
    # Zbuduj mapy pid → name/category
    prod_map = {
        p["ps_product_id"]: {
            "name": (p.get("product_name") or "").lower(),
            "category": (p.get("category_name") or "").lower(),
        }
        for p in products
    }

    # Kategorie wykluczone z reguły "aparat oddechowy"
    SKIP_CATEGORIES = {"zawory do suchego skafandra", "skafandry suche", "pianki neoprenowe",
                       "maski", "płetwy", "boje", "bojki dekompresyjne", "komputery nurkowe",
                       "latarki"}

    # Kategorie bezpośrednio związane z automatami (trigger nawet bez "automat" w nazwie)
    AUTOMAT_CATEGORIES = {"automaty oddechowe", "1 stopnie", "2 stopnie",
                          "akcesoria do automatów", "torby na automaty"}

    # Słowa w nazwie identyfikujące sam automat/stopień (→ samodzielny synonim)
    AUTOMAT_NAME_KW = {"automat oddechowy", "stopień", "stage"}

    # Słowa w nazwie identyfikujące akcesorium (→ kontekstowy synonim)
    # Sprawdzane PRZED automat keywords, bo "torba na automat" zawiera "automat"
    ACCESSORY_MARKERS = {
        "torba": "torba na aparat oddechowy",
        "pokrowiec": "pokrowiec na aparat oddechowy",
        "etui": "etui na aparat oddechowy",
        "zawór odcinający": "akcesoria do aparatu oddechowego",
        "freeflow": "akcesoria do aparatu oddechowego",
    }

    for pid, syns in synonyms_map.items():
        info = prod_map.get(pid, {"name": "", "category": ""})
        name = info["name"]
        category = info["category"]

        # Rule 1: usuń "kiełbasa" z PL
        syns["pl"] = [s for s in syns["pl"] if s != "kiełbasa"]

        # Rule 2: "aparat oddechowy" — kontekstowo
        # Czy produkt jest w ogóle związany z automatami?
        related = category in AUTOMAT_CATEGORIES or any(kw in name for kw in AUTOMAT_NAME_KW)

        if category not in SKIP_CATEGORIES and related:
            # Sprawdź akcesorium NAJPIERW (bo "torba na automat" zawiera "automat")
            is_accessory = False
            for marker, contextual in ACCESSORY_MARKERS.items():
                if marker in name:
                    is_accessory = True
                    if contextual not in syns["pl"]:
                        syns["pl"].append(contextual)
                    break

            if not is_accessory:
                # Produkt jest automatem/stopniem → samodzielny "aparat oddechowy"
                if "aparat oddechowy" not in syns["pl"]:
                    syns["pl"].insert(0, "aparat oddechowy")

        # Rule 3: przenieś "lung" z PL do EN
        if "lung" in syns["pl"]:
            syns["pl"] = [s for s in syns["pl"] if s != "lung"]
            if "lung" not in syns["en"]:
                syns["en"].append("lung")

    return synonyms_map


def parse_validation(raw_text: str) -> dict | None:
    """Parsuje odpowiedź walidacyjną Claude Opus."""
    json_match = re.search(r'\{[\s\S]*\}', raw_text)
    if not json_match:
        return None
    return json.loads(json_match.group())


def generate_batch(client: anthropic.Anthropic, products: list[dict], system_prompt: str) -> dict[int, dict]:
    """Generuje synonimy dla batcha produktów."""
    products_block = build_products_block(products)
    user_prompt = USER_PROMPT_TEMPLATE.format(products_block=products_block)
    expected_ids = [p["ps_product_id"] for p in products]

    for attempt in range(1, MAX_RETRIES + 1):
        try:
            response = client.messages.create(
                model=MODEL_GENERATE,
                max_tokens=2048,
                system=system_prompt,
                messages=[{"role": "user", "content": user_prompt}],
            )
            raw_text = response.content[0].text.strip()
            result = parse_response(raw_text, expected_ids)
            result = postprocess_synonyms(result, products)

            if len(result) == 0 and attempt < MAX_RETRIES:
                logger.warning("0 produktów w odpowiedzi (próba %d)", attempt)
                time.sleep(2)
                continue

            return result

        except anthropic.RateLimitError:
            delay = 10 * attempt
            logger.warning("Rate limit, czekam %ds (próba %d/%d)", delay, attempt, MAX_RETRIES)
            time.sleep(delay)
        except anthropic.APIStatusError as e:
            if e.status_code in (429, 529, 500) and attempt < MAX_RETRIES:
                time.sleep(5 * attempt)
            elif attempt == MAX_RETRIES:
                logger.error("Wyczerpano próby: %s", e)
                return {}
            else:
                time.sleep(3)
        except (json.JSONDecodeError, Exception) as e:
            logger.warning("Błąd parsowania (próba %d): %s", attempt, e)
            if attempt == MAX_RETRIES:
                return {}
            time.sleep(3)

    return {}


def validate_product(client: anthropic.Anthropic, product: dict) -> dict:
    """Waliduje synonimy jednego produktu przez Opus."""
    prompt = VALIDATE_PROMPT.format(
        product_name=product["product_name"],
        category_name=product["category_name"],
        brand_name=product["brand_name"] or "brak",
        pl_list=", ".join(product["synonyms_pl"]),
        en_list=", ".join(product["synonyms_en"]),
    )

    for attempt in range(1, MAX_RETRIES + 1):
        try:
            response = client.messages.create(
                model=MODEL_VALIDATE,
                max_tokens=1024,
                messages=[{"role": "user", "content": prompt}],
            )
            raw_text = response.content[0].text.strip()
            validation = parse_validation(raw_text)

            if validation is None:
                logger.warning("Brak JSON w odpowiedzi walidacji pid=%d", product["ps_product_id"])
                return {"pl": product["synonyms_pl"], "en": product["synonyms_en"], "validation": None}

            # Zbierz finalne listy
            final_pl = list(validation.get("keep_pl", []))
            for fix in validation.get("fix_pl", []):
                if isinstance(fix, dict) and "new" in fix:
                    final_pl.append(fix["new"])
            final_pl.extend(validation.get("add_pl", []))

            final_en = list(validation.get("keep_en", []))
            for fix in validation.get("fix_en", []):
                if isinstance(fix, dict) and "new" in fix:
                    final_en.append(fix["new"])
            final_en.extend(validation.get("add_en", []))

            # Normalizuj
            final_pl = list(dict.fromkeys(s.strip().lower() for s in final_pl if s.strip()))
            final_en = list(dict.fromkeys(s.strip().lower() for s in final_en if s.strip()))

            # Post-processing hard rules
            temp = {product["ps_product_id"]: {"pl": final_pl, "en": final_en}}
            temp = postprocess_synonyms(temp, [product])
            final_pl = temp[product["ps_product_id"]]["pl"]
            final_en = temp[product["ps_product_id"]]["en"]

            return {
                "pl": final_pl,
                "en": final_en,
                "validation": validation,
                "tokens_in": response.usage.input_tokens,
                "tokens_out": response.usage.output_tokens,
            }

        except anthropic.RateLimitError:
            delay = 10 * attempt
            logger.warning("Rate limit walidacja, czekam %ds", delay)
            time.sleep(delay)
        except anthropic.APIStatusError as e:
            if e.status_code in (429, 529, 500) and attempt < MAX_RETRIES:
                time.sleep(5 * attempt)
            elif attempt == MAX_RETRIES:
                return {"pl": product["synonyms_pl"], "en": product["synonyms_en"], "validation": None}
            else:
                time.sleep(3)
        except (json.JSONDecodeError, Exception) as e:
            logger.warning("Błąd walidacji pid=%d (próba %d): %s", product["ps_product_id"], attempt, e)
            if attempt == MAX_RETRIES:
                return {"pl": product["synonyms_pl"], "en": product["synonyms_en"], "validation": None}
            time.sleep(3)

    return {"pl": product["synonyms_pl"], "en": product["synonyms_en"], "validation": None}


def save_to_db(conn, synonyms_map: dict[int, dict]):
    """Zapisuje synonimy do bazy."""
    cur = conn.cursor()
    for pid, syns in synonyms_map.items():
        cur.execute(
            """UPDATE divechat_product_embeddings
               SET synonyms_pl = %s::jsonb,
                   synonyms_en = %s::jsonb,
                   updated_at = NOW()
               WHERE ps_product_id = %s""",
            (json.dumps(syns["pl"], ensure_ascii=False),
             json.dumps(syns["en"], ensure_ascii=False),
             pid),
        )
    conn.commit()
    cur.close()


def export_csv(conn, output_path: str):
    """Eksportuje synonimy do CSV."""
    cur = conn.cursor()
    cur.execute("""
        SELECT ps_product_id, product_name, category_name, brand_name,
               synonyms_pl::text, synonyms_en::text
        FROM divechat_product_embeddings
        WHERE is_active = true AND synonyms_pl <> '[]'::jsonb
        ORDER BY ps_product_id
    """)
    rows = cur.fetchall()
    cur.close()

    with open(output_path, "w", encoding="utf-8", newline="") as f:
        writer = csv.writer(f)
        writer.writerow(["ps_product_id", "product_name", "category_name", "brand_name",
                         "synonyms_pl", "synonyms_en"])
        for r in rows:
            pl = ", ".join(json.loads(r[4])) if r[4] else ""
            en = ", ".join(json.loads(r[5])) if r[5] else ""
            writer.writerow([r[0], r[1], r[2], r[3], pl, en])

    logger.info("Eksportowano %d produktów do %s", len(rows), output_path)


def run_generate(products: list[dict], conn, system_prompt: str):
    """Krok 1: generowanie synonimów (Sonnet + słownik)."""
    try:
        from tqdm import tqdm
        has_tqdm = True
    except ImportError:
        has_tqdm = False

    client = anthropic.Anthropic(api_key=os.getenv("ANTHROPIC_API_KEY"))
    total = len(products)
    total_batches = (total + BATCH_SIZE - 1) // BATCH_SIZE
    processed = 0
    total_pl = 0
    total_en = 0
    errors = 0

    logger.info("Generowanie: %d produktów, %d batchów, model=%s", total, total_batches, MODEL_GENERATE)

    iterator = range(0, total, BATCH_SIZE)
    if has_tqdm:
        iterator = tqdm(iterator, total=total_batches, desc="Generowanie", unit="batch")

    for batch_start in iterator:
        batch = products[batch_start:batch_start + BATCH_SIZE]
        result = generate_batch(client, batch, system_prompt)

        if result:
            save_to_db(conn, result)
            for syns in result.values():
                total_pl += len(syns["pl"])
                total_en += len(syns["en"])
            processed += len(result)
        else:
            errors += len(batch)

        if has_tqdm:
            iterator.set_postfix(ok=processed, err=errors, pl=total_pl, en=total_en)
        elif (batch_start // BATCH_SIZE + 1) % 10 == 0:
            logger.info("Postęp: %d/%d batchów (%d ok, %d err)",
                        batch_start // BATCH_SIZE + 1, total_batches, processed, errors)

        time.sleep(RATE_LIMIT_DELAY)

    print(f"\n{'='*60}")
    print(f"GENEROWANIE SYNONIMÓW - PODSUMOWANIE")
    print(f"{'='*60}")
    print(f"Produktów: {processed}/{total}")
    print(f"Błędów: {errors}")
    print(f"Synonimów PL: {total_pl} (śr. {total_pl / max(processed, 1):.1f}/produkt)")
    print(f"Synonimów EN: {total_en} (śr. {total_en / max(processed, 1):.1f}/produkt)")


def run_validate(conn, limit: int = None):
    """Krok 2: walidacja synonimów (Opus)."""
    try:
        from tqdm import tqdm
        has_tqdm = True
    except ImportError:
        has_tqdm = False

    products = fetch_products_with_synonyms(conn, limit=limit)
    if not products:
        logger.info("Brak produktów do walidacji")
        return

    client = anthropic.Anthropic(api_key=os.getenv("ANTHROPIC_API_KEY"))
    total = len(products)
    validated = 0
    total_removed_pl = 0
    total_removed_en = 0
    total_added_pl = 0
    total_added_en = 0

    logger.info("Walidacja: %d produktów, model=%s", total, MODEL_VALIDATE)

    iterator = enumerate(products, 1)
    if has_tqdm:
        iterator = tqdm(iterator, total=total, desc="Walidacja", unit="prod")

    for i, product in iterator:
        pid = product["ps_product_id"]
        result = validate_product(client, product)

        validation = result.get("validation")
        if validation:
            total_removed_pl += len(validation.get("remove_pl", []))
            total_removed_en += len(validation.get("remove_en", []))
            total_added_pl += len(validation.get("add_pl", []))
            total_added_en += len(validation.get("add_en", []))

        save_to_db(conn, {pid: {"pl": result["pl"], "en": result["en"]}})
        validated += 1

        if has_tqdm:
            iterator.set_postfix(
                ok=validated, rm_pl=total_removed_pl, add_pl=total_added_pl
            )
        elif i % 50 == 0:
            logger.info("Walidacja: %d/%d", i, total)

        time.sleep(0.5)  # rate limit Opus

    print(f"\n{'='*60}")
    print(f"WALIDACJA SYNONIMÓW - PODSUMOWANIE")
    print(f"{'='*60}")
    print(f"Produktów: {validated}/{total}")
    print(f"Usunięto PL: {total_removed_pl}, EN: {total_removed_en}")
    print(f"Dodano PL: {total_added_pl}, EN: {total_added_en}")


def run_dry(products: list[dict], system_prompt: str):
    """Dry-run: pokaż prompt dla 3 produktów."""
    sample = products[:3]
    products_block = build_products_block(sample)
    prompt = USER_PROMPT_TEMPLATE.format(products_block=products_block)

    print(f"\n{'='*60}")
    print(f"DRY-RUN: prompt dla {len(sample)} produktów")
    print(f"Model generowania: {MODEL_GENERATE}")
    print(f"Model walidacji: {MODEL_VALIDATE}")
    print(f"{'='*60}")
    print(f"\n[SYSTEM]\n{system_prompt[:500]}...\n")
    print(f"[USER]\n{prompt}")
    print(f"\n{'='*60}")
    print(f"Produktów w bazie: {len(products)}")
    print(f"Batchów: {(len(products) + BATCH_SIZE - 1) // BATCH_SIZE}")


def main():
    parser = argparse.ArgumentParser(description="Generowanie + walidacja synonimów produktowych")
    parser.add_argument("--dry-run", action="store_true", help="Pokaż prompt, nie wysyłaj")
    parser.add_argument("--limit", type=int, default=None, help="Przetworz tylko N produktów")
    parser.add_argument("--only-missing", action="store_true", help="Pomiń produkty z synonimami")
    parser.add_argument("--validate", action="store_true", help="Krok 2: walidacja Opusem")
    parser.add_argument("--export-csv", type=str, metavar="FILE", help="Eksport do CSV")
    args = parser.parse_args()

    conn = get_db_connection()

    if args.export_csv:
        export_csv(conn, args.export_csv)
        conn.close()
        return

    if args.validate:
        run_validate(conn, limit=args.limit)
        conn.close()
        return

    # Załaduj słownik referencyjny
    reference_dict = load_reference_dict(conn)
    system_prompt = build_system_prompt(reference_dict)

    products = fetch_products(conn, only_missing=args.only_missing, limit=args.limit)
    logger.info("Pobrano %d produktów", len(products))

    if not products:
        logger.info("Brak produktów do przetworzenia")
        conn.close()
        return

    if args.dry_run:
        run_dry(products, system_prompt)
        conn.close()
        return

    run_generate(products, conn, system_prompt)
    conn.close()


if __name__ == "__main__":
    main()
