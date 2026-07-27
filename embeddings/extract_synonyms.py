"""
Ekstrakcja słownika synonimów nurkowych z artykułów nurkomania.pl.
Używa Claude Sonnet do analizy tekstu i wyciągania grup synonimów.
Wynik: data/synonyms/diving_synonyms_draft.json (do review).
"""

import json
import os
import re
import time
import logging
from pathlib import Path
from collections import defaultdict

import anthropic
from dotenv import load_dotenv

load_dotenv(Path(__file__).resolve().parent.parent / ".env")

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
logger = logging.getLogger(__name__)

PROJECT_ROOT = Path(__file__).resolve().parent.parent
SPRZET_JSON = PROJECT_ROOT / "_docs" / "wiedza_nurkowa" / "sprzet_do_nurkowania.json"
TEORIA_JSON = PROJECT_ROOT / "_docs" / "wiedza_nurkowa" / "teoria_nurkowania_pelny.json"
OUTPUT_DIR = PROJECT_ROOT / "data" / "synonyms"
OUTPUT_FILE = OUTPUT_DIR / "diving_synonyms_draft.json"

MODEL = "claude-sonnet-4-20250514"
MAX_CHUNK_CHARS = 8000  # ~2000 tokenów na chunk
MIN_ARTICLE_CHARS = 200
MAX_RETRIES = 3

EXTRACTION_PROMPT = """Przeanalizuj poniższy tekst o nurkowaniu. Wypisz WSZYSTKIE grupy synonimów
które znajdziesz, tzn. różne słowa/frazy oznaczające TO SAMO.

Format wyjścia - TYLKO JSON array of arrays, bez żadnego innego tekstu:
[
  ["pianka", "skafander mokry", "wetsuit", "kombinezon neoprenowy"],
  ["automat oddechowy", "regulator", "lung"],
  ["jacket", "BCD", "kamizelka wypornościowa"]
]

ZASADY:
- Tylko synonimy z dziedziny nurkowania
- Uwzględnij polskie i angielskie warianty
- Uwzględnij skróty (BCD, AO, HP, LP, DIN, INT, SMB, DSMB, itp.)
- Jedna grupa = jedno pojęcie, różne nazwy
- Minimum 2 elementy w grupie
- Jeśli tekst nie zawiera synonimów, zwróć pustą tablicę []
- TYLKO JSON, bez wyjaśnień

TEKST:
{text}"""


def load_articles() -> list[dict]:
    """Wczytuje artykuły z obu plików JSON, normalizuje format."""
    articles = []

    # sprzet_do_nurkowania.json - płaska tablica
    with open(SPRZET_JSON, encoding="utf-8") as f:
        sprzet = json.load(f)
    for a in sprzet:
        articles.append({
            "tytul": a.get("tytul", ""),
            "tresc": a.get("tresc", ""),
            "source": "sprzet",
        })

    # teoria_nurkowania_pelny.json - obiekt z kluczem "dane"
    with open(TEORIA_JSON, encoding="utf-8") as f:
        teoria = json.load(f)
    for a in teoria.get("dane", []):
        articles.append({
            "tytul": a.get("tytul", ""),
            "tresc": a.get("tresc", ""),
            "source": "teoria",
        })

    logger.info("Wczytano %d artykułów (sprzet: %d, teoria: %d)",
                len(articles), len(sprzet), len(teoria.get("dane", [])))
    return articles


def deduplicate_articles(articles: list[dict]) -> list[dict]:
    """Usuwa duplikaty po treści (dokładne dopasowanie lub substring)."""
    # Sortuj od najdłuższych - krótsze mogą być substringami dłuższych
    articles.sort(key=lambda a: len(a["tresc"]), reverse=True)

    unique = []
    seen_texts = set()

    for a in articles:
        tresc = a["tresc"].strip()
        if len(tresc) < MIN_ARTICLE_CHARS:
            continue

        # Sprawdź duplikat (hash pierwszych 200 znaków)
        text_key = tresc[:200]
        if text_key in seen_texts:
            continue
        seen_texts.add(text_key)
        unique.append(a)

    logger.info("Po deduplikacji: %d artykułów (usunięto %d)",
                len(unique), len(articles) - len(unique))
    return unique


def build_chunks(articles: list[dict]) -> list[dict]:
    """Łączy artykuły w chunki do ~MAX_CHUNK_CHARS."""
    chunks = []
    current_texts = []
    current_titles = []
    current_len = 0

    for a in articles:
        text = a["tresc"].strip()
        title = a["tytul"]

        if current_len + len(text) > MAX_CHUNK_CHARS and current_texts:
            chunks.append({
                "text": "\n\n---\n\n".join(current_texts),
                "titles": list(current_titles),
            })
            current_texts = []
            current_titles = []
            current_len = 0

        current_texts.append(text)
        current_titles.append(title)
        current_len += len(text)

    # Ostatni chunk
    if current_texts:
        chunks.append({
            "text": "\n\n---\n\n".join(current_texts),
            "titles": list(current_titles),
        })

    logger.info("Podzielono na %d chunków (avg %.0f znaków)",
                len(chunks), sum(len(c["text"]) for c in chunks) / max(len(chunks), 1))
    return chunks


def extract_synonyms_from_chunk(client: anthropic.Anthropic, chunk: dict) -> list[list[str]]:
    """Wysyła chunk do Claude i parsuje wynik jako JSON array of arrays."""
    prompt = EXTRACTION_PROMPT.format(text=chunk["text"])

    for attempt in range(1, MAX_RETRIES + 1):
        try:
            response = client.messages.create(
                model=MODEL,
                max_tokens=1024,
                messages=[{"role": "user", "content": prompt}],
            )
            raw = response.content[0].text.strip()

            # Wyciągnij JSON z odpowiedzi (czasem model dodaje markdown)
            json_match = re.search(r'\[.*\]', raw, re.DOTALL)
            if not json_match:
                logger.warning("Brak JSON w odpowiedzi dla: %s", chunk["titles"][:2])
                return []

            groups = json.loads(json_match.group())

            # Walidacja: lista list stringów
            valid_groups = []
            for g in groups:
                if isinstance(g, list) and len(g) >= 2 and all(isinstance(s, str) for s in g):
                    # Normalizuj: lowercase, strip
                    valid_groups.append([s.strip().lower() for s in g])
            return valid_groups

        except anthropic.RateLimitError:
            if attempt < MAX_RETRIES:
                delay = 5 * attempt
                logger.warning("Rate limit, czekam %ds (próba %d/%d)", delay, attempt, MAX_RETRIES)
                time.sleep(delay)
            else:
                raise
        except (json.JSONDecodeError, Exception) as e:
            logger.warning("Błąd parsowania (próba %d/%d): %s", attempt, MAX_RETRIES, e)
            if attempt == MAX_RETRIES:
                return []
            time.sleep(2)

    return []


class UnionFind:
    """Union-Find do mergowania grup synonimów ze wspólnymi elementami."""

    def __init__(self):
        self.parent = {}

    def find(self, x):
        if x not in self.parent:
            self.parent[x] = x
        if self.parent[x] != x:
            self.parent[x] = self.find(self.parent[x])
        return self.parent[x]

    def union(self, x, y):
        rx, ry = self.find(x), self.find(y)
        if rx != ry:
            self.parent[rx] = ry


def merge_synonym_groups(all_groups: list[list[str]], source_map: dict) -> list[dict]:
    """Merguje grupy synonimów które mają wspólne elementy."""
    uf = UnionFind()

    # Union wszystkich elementów w obrębie każdej grupy
    for group in all_groups:
        for term in group[1:]:
            uf.union(group[0], term)

    # Zbierz merged grupy
    merged = defaultdict(set)
    for group in all_groups:
        for term in group:
            root = uf.find(term)
            merged[root].add(term)

    # Zbierz source articles dla każdej grupy
    group_sources = defaultdict(set)
    for group in all_groups:
        root = uf.find(group[0])
        for title in source_map.get(id(group), []):
            group_sources[root].add(title)

    # Konwertuj do listy dict
    result = []
    for root, terms in merged.items():
        terms_list = sorted(terms)
        # Canonical: najkrótszy termin polski (heurystyka)
        canonical = min(terms_list, key=lambda t: (not any(c in t for c in 'ąćęłńóśźż'), len(t)))
        synonyms = [t for t in terms_list if t != canonical]

        if synonyms:  # min 1 synonim
            result.append({
                "canonical": canonical,
                "synonyms": synonyms,
                "source_articles": sorted(group_sources.get(root, set()))[:5],
            })

    result.sort(key=lambda x: x["canonical"])
    return result


def main():
    api_key = os.getenv("ANTHROPIC_API_KEY")
    if not api_key:
        raise ValueError("Brak ANTHROPIC_API_KEY w .env")

    client = anthropic.Anthropic(api_key=api_key)

    # 1. Wczytaj artykuły
    articles = load_articles()

    # 2. Deduplikuj i filtruj
    articles = deduplicate_articles(articles)

    # 3. Podziel na chunki
    chunks = build_chunks(articles)

    # 4. Ekstrakcja synonimów z każdego chunka
    all_groups = []
    source_map = {}  # id(group) -> titles
    total_chunks = len(chunks)

    for i, chunk in enumerate(chunks, 1):
        logger.info("Chunk %d/%d (%d znaków, artykuły: %s)",
                     i, total_chunks, len(chunk["text"]), chunk["titles"][:3])

        groups = extract_synonyms_from_chunk(client, chunk)

        for g in groups:
            source_map[id(g)] = chunk["titles"]
            all_groups.append(g)

        logger.info("  → %d grup synonimów", len(groups))

        # Rate limiting: ~0.5s pauza między requestami
        if i < total_chunks:
            time.sleep(0.5)

    logger.info("Łącznie wyekstrahowano %d surowych grup synonimów", len(all_groups))

    # 5. Merge i deduplikacja
    result = merge_synonym_groups(all_groups, source_map)
    logger.info("Po merge: %d unikalnych grup synonimów", len(result))

    # 6. Zapis do pliku draft
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    with open(OUTPUT_FILE, "w", encoding="utf-8") as f:
        json.dump(result, f, ensure_ascii=False, indent=2)

    logger.info("Zapisano draft do %s", OUTPUT_FILE)

    # Podsumowanie
    print(f"\n{'='*60}")
    print(f"EKSTRAKCJA SYNONIMÓW - PODSUMOWANIE")
    print(f"{'='*60}")
    print(f"Artykułów przetworzonych: {len(articles)}")
    print(f"Chunków wysłanych do API: {total_chunks}")
    print(f"Surowych grup: {len(all_groups)}")
    print(f"Po merge/dedup: {len(result)} grup synonimów")
    print(f"\nPlik draft: {OUTPUT_FILE}")
    print(f"\nPrzykłady:")
    for entry in result[:10]:
        print(f"  {entry['canonical']} → {', '.join(entry['synonyms'])}")


if __name__ == "__main__":
    main()
