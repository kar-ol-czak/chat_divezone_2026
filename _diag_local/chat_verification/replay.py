#!/usr/bin/env python3
"""
replay.py — odtworzenie rozmowy na PRODUKCJI przez POST /api/chat.

Powstal 2026-07-25 (CHAT-T-169): kryteria akceptacji tasków wymagaja odtworzenia
rozmowy na prod i sprawdzenia, co bot FAKTYCZNIE wywolal. Dotad wymagalo to Karola
klikajacego w widget. Ten skrypt wysyla wiadomosc tak samo, jak robi to widget:
podpisany token HMAC + naglowki, POST na https://chat.divezone.pl/api/chat.

Czwarte narzedzie obok sql.py, mysql.py i check_deploy.py.

Uzycie:
    python3 replay.py -m "tresc wiadomosci"                  # nowa rozmowa
    python3 replay.py -m "..." --session <uuid>              # kontynuacja
    python3 replay.py -m "..." --customer 0                  # domyslnie 0
    python3 replay.py -m "..." --show-tools                  # + pelny przebieg (show_conversation.py --tools)
    python3 replay.py --from-conversation 829                # powtorz 1. pytanie user z rozmowy 829
    python3 replay.py --from-conversation 829 --no-marker    # bez prefiksu [REPLAY]

SEKRET (ADR-088): DIVECHAT_SECRET pobierany przez SSH + ea-php84 uzywajac realnej
klasy Config::load() (jak mysql.py), NIGDY parsowaniem .env. Sekret zyje wylacznie
w pamieci procesu — nie trafia do stdout, logow, plikow ani repo.

OZNACZANIE TESTOW: rozmowy z replayu wpadaja do tej samej tabeli co klienckie i
zasmiecilyby panel recenzji. Domyslnie doklejamy widoczny dla recenzenta prefiks
"[REPLAY] " do tresci — to jedyna droga MOZLIWA BEZ zmiany backendu (ps_customer_id
nie jest nigdzie filtrem panelu, wiec zarezerwowany customer nic nie oznacza).
--no-marker wylacza prefiks, gdy potrzebna jest wierna reprodukcja bledu.

LIMIT: jedno wywolanie modelu na uruchomienie. Bez petli, bez batcha.
"""
from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import os
import subprocess
import sys
import time
import urllib.request

from _conn import connect

# Wzorzec SSH skopiowany z mysql.py (base64 + ea-php84 + realna klasa aplikacji).
SSH_HOST = "divezone@divezonededyk.smarthost.pl"
SSH_PORT = "5739"
REMOTE_APP = "~/public_html/chat.divezone.pl"

CHAT_URL = "https://chat.divezone.pl/api/chat"
REPLAY_MARKER = "[REPLAY] "

# Jednolinijkowy PHP: laduje .env realna klasa Config (ADR-088) i wypisuje sekret.
# Trafia przez STDOUT SSH prosto do pamieci procesu python — nie do plikow ani logow.
_REMOTE_PHP = (
    r'require __DIR__."/vendor/autoload.php";'
    r'\DiveChat\Config::load(__DIR__);'
    r'echo \DiveChat\Config::get("DIVECHAT_SECRET", "");'
)


def _shell_single_quote(s: str) -> str:
    """Bezpieczne pojedyncze cudzyslowie dla powloki zdalnej (jak mysql.py)."""
    return "'" + s.replace("'", "'\\''") + "'"


def get_secret() -> str:
    """DIVECHAT_SECRET z serwera przez Config::load(). Tylko w pamieci."""
    remote_cmd = f"cd {REMOTE_APP} && ea-php84 -r {_shell_single_quote(_REMOTE_PHP)}"
    proc = subprocess.run(
        ["ssh", "-p", SSH_PORT, SSH_HOST, remote_cmd],
        capture_output=True, text=True, timeout=60,
    )
    secret = proc.stdout.strip()
    if not secret:
        sys.exit(f"Nie udalo sie pobrac DIVECHAT_SECRET z serwera. stderr:\n{proc.stderr.strip()}")
    return secret


def build_headers(customer_id: int, secret: str) -> dict:
    """Naglowki HMAC wg HmacVerifier.php:27 — hash nad '<customer>:<timestamp>'."""
    ts = int(time.time())
    token = hmac.new(
        secret.encode("utf-8"),
        f"{customer_id}:{ts}".encode("utf-8"),
        hashlib.sha256,
    ).hexdigest()
    return {
        "X-DiveChat-Token": token,
        "X-DiveChat-Customer": str(customer_id),
        "X-DiveChat-Time": str(ts),
        "Content-Type": "application/json",
    }


def post_chat(message: str, session_id: str | None, customer_id: int, secret: str) -> dict:
    """Wysyla POST /api/chat (jak widget). Timeout 120 s — model potrafi myslec dlugo."""
    headers = build_headers(customer_id, secret)
    body = json.dumps({"message": message, "session_id": session_id}).encode("utf-8")
    req = urllib.request.Request(CHAT_URL, data=body, headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=120) as resp:
            raw = resp.read().decode("utf-8")
    except urllib.error.HTTPError as e:
        detail = e.read().decode("utf-8", "replace")
        sys.exit(f"HTTP {e.code} z /api/chat:\n{detail}")
    except urllib.error.URLError as e:
        sys.exit(f"Blad polaczenia z /api/chat: {e.reason}")
    try:
        return json.loads(raw)
    except json.JSONDecodeError:
        sys.exit(f"Odpowiedz nie jest JSON-em:\n{raw}")


def first_user_message(conv_id: int) -> str:
    """Pierwsza wiadomosc roli 'user' z divechat_conversations.messages (Railway)."""
    conn = connect()
    try:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT messages FROM divechat_conversations WHERE id = %s", (conv_id,)
            )
            row = cur.fetchone()
    finally:
        conn.close()
    if not row or not isinstance(row[0], list):
        sys.exit(f"Brak rozmowy albo pustych messages dla conv_id={conv_id}")
    for m in row[0]:
        if isinstance(m, dict) and m.get("role") == "user":
            return _flatten_content(m.get("content", ""))
    sys.exit(f"Rozmowa {conv_id} nie ma wiadomosci roli 'user'")


def _flatten_content(content) -> str:
    """content bywa stringiem albo lista blokow (jak w show_conversation.py)."""
    if isinstance(content, list):
        parts = []
        for b in content:
            if isinstance(b, dict):
                parts.append(b.get("text") or "")
            else:
                parts.append(str(b))
        return " ".join(p for p in parts if p).strip()
    return str(content).strip()


def fetch_diagnostics(session_id: str) -> dict | None:
    """Po session_id dociaga conv_id, tools_used, search_diagnostics z Railway."""
    conn = connect()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """SELECT id, tools_used, search_diagnostics
                   FROM divechat_conversations
                   WHERE session_id = %s
                   ORDER BY id DESC LIMIT 1""",
                (session_id,),
            )
            row = cur.fetchone()
    finally:
        conn.close()
    if not row:
        return None
    return {"conv_id": row[0], "tools_used": row[1], "search_diagnostics": row[2]}


def render_queries(diagnostics) -> list[str]:
    """Linie 'zapytania' z search_diagnostics — q/kw/cnt dla search_products, tool+q dla reszty."""
    lines = []
    if not isinstance(diagnostics, list):
        return lines
    for d in diagnostics:
        if not isinstance(d, dict):
            continue
        tool = d.get("tool", "?")
        q = d.get("query_text", "")
        plan = d.get("search_plan") or {}
        kw = plan.get("exact_keywords", []) if isinstance(plan, dict) else []
        cnt = d.get("result_count")
        if tool == "search_products":
            lines.append(f'  {tool} q="{q}" kw={json.dumps(kw, ensure_ascii=False)} cnt={cnt}')
        else:
            extra = f' q="{q}"' if q else ""
            extra += f" cnt={cnt}" if cnt is not None else ""
            lines.append(f"  {tool}{extra}")
    return lines


def main() -> int:
    ap = argparse.ArgumentParser(
        description="Odtworz rozmowe na PROD przez /api/chat (HMAC jak widget).",
    )
    src = ap.add_mutually_exclusive_group(required=True)
    src.add_argument("-m", "--message", help="tresc wiadomosci")
    src.add_argument(
        "--from-conversation", type=int, metavar="N",
        help="powtorz 1. wiadomosc roli 'user' z rozmowy N (Railway)",
    )
    ap.add_argument("--session", help="UUID v4 istniejacej rozmowy (kontynuacja)")
    ap.add_argument("--customer", type=int, default=0, help="ps_customer_id (domyslnie 0 = niezalogowany)")
    ap.add_argument("--show-tools", action="store_true", help="dodatkowo pelny przebieg (show_conversation.py --tools)")
    ap.add_argument("--no-marker", action="store_true", help="nie doklejaj prefiksu [REPLAY] do tresci")
    args = ap.parse_args()

    if args.from_conversation:
        message = first_user_message(args.from_conversation)
        print(f"[from-conversation {args.from_conversation}] pierwsze pytanie user:\n  {message}\n")
    else:
        message = args.message

    if not message.strip():
        sys.exit("Pusta wiadomosc.")

    if not args.no_marker:
        message = REPLAY_MARKER + message

    secret = get_secret()
    result = post_chat(message, args.session, args.customer, secret)

    if not result.get("success", False):
        print("UWAGA: success=false (gate/blad aplikacyjny) — odpowiedz ponizej to komunikat, nie odpowiedz modelu.")

    session_id = result.get("session_id", "")
    diag = fetch_diagnostics(session_id) if session_id else None

    print("=" * 90)
    print(f"session_id : {session_id}")
    if diag:
        print(f"conv_id    : {diag['conv_id']}")
        tu = diag["tools_used"]
        print(f"narzedzia  : {json.dumps(tu, ensure_ascii=False) if tu is not None else '[]'}")
        qlines = render_queries(diag["search_diagnostics"])
        if qlines:
            print("zapytania  :")
            for ln in qlines:
                print(ln)
        else:
            print("zapytania  : (brak search_diagnostics)")
    else:
        print("conv_id    : (nie znaleziono w Railway po session_id — replikacja moze byc opozniona)")
        # tools_used z odpowiedzi HTTP jako fallback
        tu = result.get("tools_used")
        if tu is not None:
            print(f"narzedzia  : {json.dumps(tu, ensure_ascii=False)}")
    print("odpowiedz  :")
    print(result.get("response", "(brak pola response)"))
    print("=" * 90)

    if args.show_tools and diag:
        print("\n--- pelny przebieg (show_conversation.py --tools) ---\n")
        sys.stdout.flush()  # kolejnosc z podprocesem, gdy stdout jest buforowany (pipe)
        here = os.path.dirname(os.path.abspath(__file__))
        subprocess.run(
            [sys.executable, os.path.join(here, "show_conversation.py"), str(diag["conv_id"]), "--tools"],
            cwd=here,
        )

    return 0


if __name__ == "__main__":
    sys.exit(main())
