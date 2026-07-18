"""
Generate embeddings - pipeline do generowania embeddingów OpenAI.
Model: text-embedding-3-large z dimensions=1536 (Matryoshka, ADR-012).

CHAT-T-150 (ADR-128): mode-aware wczytanie .env (server → ścieżka bezwzględna z docrootu),
żeby moduł działał także z /home/divezone/scripts/embeddings/ (poza układem repo).
"""

import os
import time
import logging
from pathlib import Path

import psycopg2
from dotenv import load_dotenv
from openai import OpenAI, RateLimitError


# CHAT-T-150 (ADR-128 dec. 145a): na serwerze .env poza układem repo — ścieżka bezwzględna.
def _resolve_env_file() -> str:
    explicit = os.getenv("DIVECHAT_ENV_FILE")
    if explicit:
        return explicit
    if os.getenv("EMBEDDINGS_ENV", "local").strip().lower() == "server":
        return "/home/divezone/public_html/chat.divezone.pl/.env"
    return str(Path(__file__).resolve().parent.parent / ".env")


load_dotenv(_resolve_env_file())

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
logger = logging.getLogger(__name__)

DEFAULT_MODEL = "text-embedding-3-large"
DEFAULT_DIMENSIONS = 1536
MAX_RETRIES = 5
RETRY_BASE_DELAY = 2  # sekundy


def get_openai_client() -> OpenAI:
    """Zwraca klienta OpenAI API."""
    api_key = os.getenv("OPENAI_API_KEY")
    if not api_key:
        raise ValueError("Brak OPENAI_API_KEY w .env")
    return OpenAI(api_key=api_key)


def get_db_connection():
    """Zwraca połączenie z PostgreSQL (Railway pgvector)."""
    database_url = os.getenv("DATABASE_URL")
    if not database_url:
        raise ValueError("Brak DATABASE_URL w .env")
    return psycopg2.connect(database_url)


def get_embedding(
    client: OpenAI,
    text: str,
    model: str = DEFAULT_MODEL,
    dimensions: int = DEFAULT_DIMENSIONS,
) -> list[float]:
    """
    Generuje embedding dla tekstu.

    Args:
        client: klient OpenAI
        text: tekst do embeddingu
        model: nazwa modelu (text-embedding-3-large lub text-embedding-3-small)
        dimensions: wymiar wektora (1536 domyślnie)

    Returns:
        lista floatów (wektor embeddingu)
    """
    for attempt in range(1, MAX_RETRIES + 1):
        try:
            response = client.embeddings.create(
                input=text,
                model=model,
                dimensions=dimensions,
            )
            return response.data[0].embedding
        except RateLimitError:
            if attempt == MAX_RETRIES:
                raise
            delay = RETRY_BASE_DELAY * (2 ** (attempt - 1))
            logger.warning(f"Rate limit (próba {attempt}/{MAX_RETRIES}), czekam {delay}s...")
            time.sleep(delay)
        except Exception as e:
            logger.error(f"Błąd generowania embeddingu: {e}")
            raise

    return []


def insert_knowledge_entry(
    conn,
    chunk_type: str,
    question: str,
    content: str,
    category: str,
    embedding: list[float],
    is_direct_answer: bool = False,
):
    """Wstawia wpis do divechat_knowledge z embeddingiem."""
    cur = conn.cursor()
    cur.execute(
        """
        INSERT INTO divechat_knowledge
            (chunk_type, question, content, category, embedding, is_direct_answer)
        VALUES (%s, %s, %s, %s, %s::vector, %s)
        RETURNING id;
        """,
        (chunk_type, question, content, category, str(embedding), is_direct_answer),
    )
    row_id = cur.fetchone()[0]
    conn.commit()
    cur.close()
    return row_id


if __name__ == "__main__":
    client = get_openai_client()
    test_text = "Jaki komputer nurkowy dla początkującego?"
    emb = get_embedding(client, test_text)
    logger.info(f"Test OK: model={DEFAULT_MODEL}, dim={len(emb)}, pierwsze 5={emb[:5]}")
