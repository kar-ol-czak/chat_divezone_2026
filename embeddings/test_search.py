"""
Test wyszukiwania semantycznego na divechat_knowledge.
Pytania testowe z _docs/08_testy_i_ewaluacja.md, sekcja B: nr 16-21.
Model: OpenAI text-embedding-3-large (dim=1536).
"""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from generate_embeddings import get_openai_client, get_embedding, get_db_connection

# Pytania testowe (sekcja B, nr 16-21)
TEST_QUERIES = [
    {
        "nr": 16,
        "query": "Czym się różni jacket od skrzydła?",
        "expected": "wyjaśnienie różnic BCD jacket vs wing",
    },
    {
        "nr": 17,
        "query": "Kiedy powinienem oddać automat do serwisu?",
        "expected": "informacja o serwisie co 12 miesięcy/100-200 nurkowań",
    },
    {
        "nr": 18,
        "query": "Czy mogę nurkować z astmą?",
        "expected": "ogólna info + zalecenie konsultacji z lekarzem medycyny nurkowej",
    },
    {
        "nr": 19,
        "query": "Jaka jest różnica między nitroksem a zwykłym powietrzem?",
        "expected": "wyjaśnienie EANx, korzyści, wymagany kurs",
    },
    {
        "nr": 20,
        "query": "Jak dobrać rozmiar płetw? Mam buty neoprenowe 5mm",
        "expected": "porada o doborze rozmiaru z uwzględnieniem butów",
    },
    {
        "nr": 21,
        "query": "Jak przechowywać piankę neoprenową żeby dłużej wytrzymała?",
        "expected": "porady dot. pielęgnacji neoprenu",
    },
]

TOP_K = 3


def search_knowledge(conn, query_embedding: list[float], top_k: int = TOP_K):
    """Wyszukiwanie wektorowe (cosine similarity) w divechat_knowledge."""
    cur = conn.cursor()
    cur.execute(
        """
        SELECT
            id,
            question,
            content,
            category,
            1 - (embedding <=> %s::vector) AS similarity
        FROM divechat_knowledge
        WHERE active = true
        ORDER BY embedding <=> %s::vector
        LIMIT %s;
        """,
        (str(query_embedding), str(query_embedding), top_k),
    )
    results = cur.fetchall()
    cur.close()
    return results


def main():
    client = get_openai_client()
    conn = get_db_connection()

    cur = conn.cursor()
    cur.execute("SELECT count(*) FROM divechat_knowledge WHERE active = true;")
    total = cur.fetchone()[0]
    cur.close()

    print("=" * 70)
    print("TEST WYSZUKIWANIA SEMANTYCZNEGO - divechat_knowledge")
    print(f"Model: text-embedding-3-large (dim=1536)")
    print(f"Metryka: cosine similarity")
    print(f"Wpisów w bazie: {total}")
    print("=" * 70)

    for test in TEST_QUERIES:
        print(f"\n{'─' * 70}")
        print(f"Pytanie #{test['nr']}: \"{test['query']}\"")
        print(f"Oczekiwanie: {test['expected']}")
        print(f"{'─' * 70}")

        query_emb = get_embedding(client, test["query"])
        results = search_knowledge(conn, query_emb, top_k=TOP_K)

        if not results:
            print("  Brak wyników!")
            continue

        for i, (row_id, question, content, category, similarity) in enumerate(results, 1):
            content_preview = content[:100] + "..." if len(content) > 100 else content
            print(f"\n  #{i} [similarity: {similarity:.4f}]")
            print(f"     Q: {question}")
            print(f"     Kategoria: {category}")
            print(f"     Treść: {content_preview}")

    conn.close()


if __name__ == "__main__":
    main()
