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
# Sekrety osobiste/infrastrukturalne wspolne dla wszystkich projektow I OBU maszyn
# (komputer glowny 'karol', maszyna wirtualna 'vm1-karol'). Lezy na dysku
# sieciowym, ktory montuje sie roznie na kazdej maszynie — stad LISTA kandydatow,
# bierzemy pierwszy istniejacy plik (wzorzec alternujacych sciezek /Volumes vs /Users).
# Czytany ZANIM .env projektu; .env projektu ma priorytet.
GLOBAL_ENV_CANDIDATES = [
    "/Volumes/karol/Documents/3_DIVEZONE/.divezone_secrets/secrets.env",   # maszyna wirtualna (SMB mount)
    "/Users/karol/Documents/3_DIVEZONE/.divezone_secrets/secrets.env",     # komputer glowny (dysk lokalny)
    os.path.expanduser("~/.config/divezone/secrets.env"),                  # zapas zgodnosci (per-maszyna)
]


def _parse_env_file(path, env):
    """Wczytuje plik .env do slownika env (in-place). Brak pliku = pomin."""
    if not path or not os.path.exists(path):
        return
    with open(path) as fh:
        for line in fh:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, v = line.split("=", 1)
            env[k] = v.strip().strip("'").strip('"')


def _first_existing_global():
    """Pierwszy istniejacy plik sekretow globalnych z listy kandydatow (albo None)."""
    for p in GLOBAL_ENV_CANDIDATES:
        if os.path.exists(p):
            return p
    return None


def load_env(path=ENV_PATH):
    """Laczy sekrety: najpierw globalne (pierwszy istniejacy z GLOBAL_ENV_CANDIDATES,
    dysk sieciowy widoczny z obu maszyn), potem projektowe (path). Projekt nadpisuje
    globalne przy kolizji klucza. Wsteczna zgodnosc: load_env() i load_env(path)
    dzialaja jak dotad."""
    env = {}
    _parse_env_file(_first_existing_global(), env)
    _parse_env_file(path, env)
    return env


def connect(timeout=15):
    env = load_env()
    conn = psycopg2.connect(env["DATABASE_URL"], connect_timeout=timeout)
    conn.autocommit = True
    return conn
