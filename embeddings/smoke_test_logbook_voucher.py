"""
TASK-CHAT-010: Smoke test wyszukiwania dla logbook / wet notes / voucher.

Symuluje 3 tory multi-vector (name/desc/jargon) + RRF fuzja — bez FTS i trigram.
Wystarczy do oceny czy nowe synonimy/search_phrases poprawiły dyskryminację.
"""

from __future__ import annotations

import os
from pathlib import Path

import psycopg2
from dotenv import load_dotenv
from openai import OpenAI

load_dotenv(Path(__file__).resolve().parent.parent / ".env")

MODEL = "text-embedding-3-large"
DIMENSIONS = 1536
TRACK_LIMIT = 30
RRF_K = 60
TOP_DISPLAY = 8


TARGET_LOGBOOKS = {3574, 5261, 5262, 5263, 6645, 6646, 6805}
TARGET_WET_NOTES = {1868, 5260, 6241, 6263}
TARGET_VOUCHERS = {4649, 4650, 4651, 4652, 4653}


def tag(pid: int) -> str:
    if pid in TARGET_LOGBOOKS:
        return "[LOGBOOK]"
    if pid in TARGET_WET_NOTES:
        return "[WET NOTES]"
    if pid in TARGET_VOUCHERS:
        return "[VOUCHER]"
    return "          "


def get_embedding(client: OpenAI, text: str) -> list[float]:
    resp = client.embeddings.create(model=MODEL, input=text, dimensions=DIMENSIONS)
    return resp.data[0].embedding


def search_column(conn, column: str, vec: list[float]) -> list[tuple[int, float]]:
    cur = conn.cursor()
    cur.execute(
        f"""SELECT ps_product_id, 1 - ({column} <=> %s::vector) AS sim
            FROM divechat_product_embeddings
            WHERE is_active = true AND {column} IS NOT NULL
            ORDER BY {column} <=> %s::vector
            LIMIT %s""",
        (str(vec), str(vec), TRACK_LIMIT),
    )
    rows = cur.fetchall()
    cur.close()
    return [(r[0], float(r[1])) for r in rows]


def rrf_fuse(*lists) -> list[tuple[int, float]]:
    scores: dict[int, float] = {}
    for ranked in lists:
        for rank, (pid, _) in enumerate(ranked, 1):
            scores[pid] = scores.get(pid, 0.0) + 1.0 / (RRF_K + rank)
    return sorted(scores.items(), key=lambda x: -x[1])


def fetch_names(conn, ids: list[int]) -> dict[int, dict]:
    if not ids:
        return {}
    cur = conn.cursor()
    cur.execute(
        "SELECT ps_product_id, product_name, category_name "
        "FROM divechat_product_embeddings WHERE ps_product_id = ANY(%s)",
        (ids,),
    )
    out = {r[0]: {"name": r[1], "cat": r[2]} for r in cur.fetchall()}
    cur.close()
    return out


def run_query(client, conn, label: str, query: str, expected_set: set[int]):
    print(f"\n{'=' * 80}")
    print(f"QUERY: {query}")
    print(f"EXPECTED top results from: {label}")
    print(f"{'=' * 80}")

    vec = get_embedding(client, query)
    s_name = search_column(conn, "embedding_name", vec)
    s_desc = search_column(conn, "embedding_desc", vec)
    s_jargon = search_column(conn, "embedding_jargon", vec)
    fused = rrf_fuse(s_name, s_desc, s_jargon)

    top_ids = [pid for pid, _ in fused[:TOP_DISPLAY]]
    names = fetch_names(conn, top_ids)

    expected_in_top = 0
    for i, (pid, score) in enumerate(fused[:TOP_DISPLAY], 1):
        info = names.get(pid, {"name": "?", "cat": "?"})
        t = tag(pid)
        is_expected = "✓" if pid in expected_set else " "
        if pid in expected_set:
            expected_in_top += 1
        print(f"  {is_expected} #{i:<2} RRF={score:.4f} {t} id={pid:<5} | {info['cat'][:25]:25} | {info['name'][:60]}")

    print(f"\n  → {expected_in_top}/{TOP_DISPLAY} z oczekiwanej puli {label}")


def main():
    client = OpenAI(api_key=os.getenv("OPENAI_API_KEY"))
    conn = psycopg2.connect(os.getenv("DATABASE_URL"))

    run_query(client, conn, "LOGBOOKS",
              "Macie logbook nurkowy?", TARGET_LOGBOOKS)
    run_query(client, conn, "WET NOTES",
              "Macie mokry notes?", TARGET_WET_NOTES)
    run_query(client, conn, "VOUCHERS",
              "Macie voucher prezentowy 500 zł?", TARGET_VOUCHERS)
    run_query(client, conn, "LOGBOOKS (sanity, query: dziennik nurkowań)",
              "dziennik nurkowań", TARGET_LOGBOOKS)
    run_query(client, conn, "WET NOTES (sanity, query: wet notes)",
              "wet notes", TARGET_WET_NOTES)
    run_query(client, conn, "VOUCHERS (sanity, query: karta podarunkowa)",
              "karta podarunkowa", TARGET_VOUCHERS)

    conn.close()


if __name__ == "__main__":
    main()
