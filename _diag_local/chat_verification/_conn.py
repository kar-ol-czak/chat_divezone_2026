"""
Wspolne polaczenie do Railway PG (baza czatu) dla narzedzi weryfikacji rozmow.
Parsuje .env recznie (linia po linii) — NIE zalezy od phpdotenv ani python-dotenv,
odporne na 1 zepsuty klucz (ADR-088). Zero sekretow na sztywno — wszystko z .env.

Uzycie:
    from _conn import connect
    conn = connect(); cur = conn.cursor()
"""
import os
import psycopg2

PROJECT_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
ENV_PATH = os.path.join(PROJECT_ROOT, ".env")


def load_env(path=ENV_PATH):
    env = {}
    with open(path) as fh:
        for line in fh:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, v = line.split("=", 1)
            env[k] = v.strip().strip("'").strip('"')
    return env


def connect(timeout=15):
    env = load_env()
    conn = psycopg2.connect(env["DATABASE_URL"], connect_timeout=timeout)
    conn.autocommit = True
    return conn
