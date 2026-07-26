#!/usr/bin/env python3
"""
trello.py — deterministyczne zarzadzanie kartami Chat przez REST API Trello.

Powstal 2026-07-25 (CHAT-T-171): MCP Trello zawodzi jako kanal z sesji roboczej
(pakiet @delorenj padl pod Node 25, narzedzia MCP nie laduja sie do sesji desktop,
konektor OAuth zyje tylko w przegladarce). To narzedzie idzie przez ten sam kanal
co sql.py/mysql.py/replay.py — Desktop Commander + urllib — wiec dziala identycznie
dla architekta i CC, niezaleznie od warstwy MCP.

Piate narzedzie katalogu (obok sql.py, mysql.py, check_deploy.py, replay.py).

Uzycie — ODCZYT (domyslnie, bez flagi):
    python3 trello.py --list-lists                    # listy boardu Projekty2026
    python3 trello.py --list-cards <idList>           # karty listy
    python3 trello.py --card <idCard>                 # szczegoly karty

Uzycie — ZAPIS (wymaga --write, jak sql.py):
    python3 trello.py --write --new-card <idList> --name "opis" --desc "..."
    python3 trello.py --write --new-card <idList> --name "opis" --chat-prefix
        # tworzy + od razu czyta idShort z odpowiedzi + rename na "Chat - <idShort> - ..."
    python3 trello.py --write --rename <idCard> --name "Chat - NN - opis [T-NNN]"
    python3 trello.py --write --move <idCard> --to-list <idList>

SEKRETY (ADR-088): TRELLO_API_KEY / TRELLO_TOKEN / TRELLO_BOARD_ID_PROJEKTY2026
czytane przez _conn.load_env() (odporny na 1 zepsuty klucz), NIGDY wlasnym parserem.
Klucz+token ida w query stringu WYLACZNIE do api.trello.com — komunikaty bledow
pokazuja sciezke endpointu, NIGDY pelnego URL-a z query (sekret w query).

BEZPIECZNIKI:
- kazda mutacja wymaga --write;
- --to-list / --new-card na liste spoza boardu Projekty2026 -> odmowa;
- jedna karta na wywolanie (bez batcha);
- read-modify (--chat-prefix) sekwencyjnie (rate limit 100 req/10s).
"""
from __future__ import annotations

import argparse
import json
import sys
import urllib.error
import urllib.parse
import urllib.request

from _conn import load_env

API_BASE = "https://api.trello.com/1"
TIMEOUT = 30


class TrelloError(Exception):
    pass


def _creds() -> tuple[str, str, str]:
    """(key, token, board_id) z .env. Sekret tylko w pamieci procesu."""
    env = load_env()
    key = env.get("TRELLO_API_KEY", "")
    token = env.get("TRELLO_TOKEN", "")
    board = env.get("TRELLO_BOARD_ID_PROJEKTY2026", "")
    if not key or not token:
        sys.exit(
            "Brak TRELLO_API_KEY / TRELLO_TOKEN w .env.\n"
            "Sekrety dokłada Karol (patrz task CHAT-T-171 §3) — CC ich nie wpisuje."
        )
    if not board:
        sys.exit("Brak TRELLO_BOARD_ID_PROJEKTY2026 w .env.")
    return key, token, board


def _request(method: str, path: str, params: dict, key: str, token: str) -> dict | list:
    """
    Wywolanie REST. `path` np. "/cards/abc". `params` bez key/token — dokladamy tu.
    Komunikat bledu pokazuje TYLKO method+path, NIGDY pelnego URL (sekret w query).
    """
    query = dict(params)
    query["key"] = key
    query["token"] = token
    url = f"{API_BASE}{path}?{urllib.parse.urlencode(query)}"
    req = urllib.request.Request(url, method=method)
    try:
        with urllib.request.urlopen(req, timeout=TIMEOUT) as resp:
            raw = resp.read().decode("utf-8")
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8", "replace")[:500]
        raise TrelloError(f"HTTP {e.code} na {method} {path}: {body}")
    except urllib.error.URLError as e:
        raise TrelloError(f"Blad polaczenia na {method} {path}: {e.reason}")
    if not raw:
        return {}
    try:
        return json.loads(raw)
    except json.JSONDecodeError:
        raise TrelloError(f"Odpowiedz nie jest JSON-em na {method} {path}: {raw[:300]}")


# ---------- odczyty ----------

def get_lists(key: str, token: str, board: str) -> list:
    return _request("GET", f"/boards/{board}/lists", {"fields": "name"}, key, token)


def get_cards(key: str, token: str, id_list: str) -> list:
    return _request("GET", f"/lists/{id_list}/cards", {"fields": "idShort,name,idList"}, key, token)


def get_card(key: str, token: str, id_card: str) -> dict:
    return _request(
        "GET", f"/cards/{id_card}",
        {"fields": "idShort,name,idList,desc,idBoard"}, key, token,
    )


def _board_list_ids(key: str, token: str, board: str) -> set[str]:
    return {lst["id"] for lst in get_lists(key, token, board)}


def _assert_list_on_board(id_list: str, key: str, token: str, board: str) -> None:
    """Bezpiecznik §5.2 — lista musi nalezec do boardu Projekty2026."""
    if id_list not in _board_list_ids(key, token, board):
        sys.exit(
            f"ODMOWA: lista {id_list} nie nalezy do boardu Projekty2026 ({board}).\n"
            "Bezpiecznik chroni przed pomylka listy z cudzej tablicy (§5.2)."
        )


# ---------- zapisy ----------

def new_card(key: str, token: str, id_list: str, name: str, desc: str, board: str,
             chat_prefix: bool) -> dict:
    _assert_list_on_board(id_list, key, token, board)
    params = {"idList": id_list, "name": name}
    if desc:
        params["desc"] = desc
    card = _request("POST", "/cards", params, key, token)
    if chat_prefix:
        # idShort znamy juz z odpowiedzi POST — bez drugiego zapytania na slepo.
        short = card.get("idShort")
        new_name = f"Chat - {short} - {name}"
        card = _request("PUT", f"/cards/{card['id']}", {"name": new_name}, key, token)
    return card


def rename_card(key: str, token: str, id_card: str, name: str) -> dict:
    return _request("PUT", f"/cards/{id_card}", {"name": name}, key, token)


def move_card(key: str, token: str, id_card: str, to_list: str, board: str) -> dict:
    _assert_list_on_board(to_list, key, token, board)
    # Czyste API potrzebuje TYLKO idList — bez boardId (znika pulapka MCP move_card).
    return _request("PUT", f"/cards/{id_card}", {"idList": to_list}, key, token)


# ---------- render ----------

def _render_table(rows: list, cols: list) -> None:
    if not rows:
        print("(0 wierszy)")
        return
    widths = {c: len(c) for c in cols}
    for r in rows:
        for c in cols:
            widths[c] = max(widths[c], len(str(r.get(c, ""))))
    print(" | ".join(c.ljust(widths[c]) for c in cols))
    print("-+-".join("-" * widths[c] for c in cols))
    for r in rows:
        print(" | ".join(str(r.get(c, "")).ljust(widths[c]) for c in cols))
    print(f"\n({len(rows)} wierszy)")


def main() -> int:
    ap = argparse.ArgumentParser(
        description="Karty Trello (board Projekty2026) przez REST — odczyt domyslnie, --write dla mutacji.",
    )
    op = ap.add_mutually_exclusive_group(required=True)
    op.add_argument("--list-lists", action="store_true", help="listy boardu Projekty2026")
    op.add_argument("--list-cards", metavar="idList", help="karty listy")
    op.add_argument("--card", metavar="idCard", help="szczegoly karty")
    op.add_argument("--new-card", metavar="idList", help="[write] utworz karte na liscie")
    op.add_argument("--rename", metavar="idCard", help="[write] zmien nazwe karty")
    op.add_argument("--move", metavar="idCard", help="[write] przenies karte")

    ap.add_argument("--write", action="store_true", help="wymagane dla kazdej mutacji")
    ap.add_argument("--name", help="nazwa karty (--new-card / --rename)")
    ap.add_argument("--desc", default="", help="opis karty (--new-card)")
    ap.add_argument("--to-list", help="lista docelowa (--move)")
    ap.add_argument("--chat-prefix", action="store_true",
                    help="po --new-card nadaj 'Chat - <idShort> - ...' w jednym wywolaniu")
    args = ap.parse_args()

    key, token, board = _creds()

    try:
        # --- odczyty ---
        if args.list_lists:
            lists = get_lists(key, token, board)
            _render_table([{"id": l["id"], "name": l["name"]} for l in lists], ["id", "name"])
            return 0
        if args.list_cards:
            cards = get_cards(key, token, args.list_cards)
            _render_table(
                [{"idShort": c.get("idShort"), "id": c["id"], "name": c["name"]} for c in cards],
                ["idShort", "id", "name"],
            )
            return 0
        if args.card:
            c = get_card(key, token, args.card)
            print(f"idShort : {c.get('idShort')}")
            print(f"id      : {c.get('id')}")
            print(f"idList  : {c.get('idList')}")
            print(f"name    : {c.get('name')}")
            print(f"desc    : {c.get('desc')}")
            return 0

        # --- mutacje: wymagaja --write ---
        is_mutation = args.new_card or args.rename or args.move
        if is_mutation and not args.write:
            sys.exit("To jest mutacja — dodaj --write (jak w sql.py). Bez niej tylko odczyt.")

        if args.new_card:
            if not args.name:
                sys.exit("--new-card wymaga --name")
            c = new_card(key, token, args.new_card, args.name, args.desc, board, args.chat_prefix)
            print("Utworzono karte:")
            print(f"  idShort : {c.get('idShort')}")
            print(f"  id      : {c.get('id')}")
            print(f"  name    : {c.get('name')}")
            print(f"  idList  : {c.get('idList')}")
            return 0

        if args.rename:
            if not args.name:
                sys.exit("--rename wymaga --name")
            c = rename_card(key, token, args.rename, args.name)
            print(f"Zmieniono nazwe: {c.get('name')}  (idShort {c.get('idShort')})")
            return 0

        if args.move:
            if not args.to_list:
                sys.exit("--move wymaga --to-list")
            c = move_card(key, token, args.move, args.to_list, board)
            print(f"Przeniesiono karte {c.get('idShort')} '{c.get('name')}' na liste {c.get('idList')}")
            return 0
    except TrelloError as e:
        sys.exit(f"BLAD Trello: {e}")

    return 0


if __name__ == "__main__":
    sys.exit(main())
