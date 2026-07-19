#!/usr/bin/env python3
"""
mysql.py — SQL na MySQL PrestaShop (read-only) bez pulapki apostrofow/hasla.

Powstal 2026-07-19 (Chat-37): odpowiednik sql.py, ale dla drugiej bazy.
MySQL PrestaShop ma DB_HOST=localhost, wiec jest dostepny WYLACZNIE na serwerze
(nie zdalnie). Zapytania pisane od zera przez SSH+heredoc gina w lancuchu
SSH -> zsh -> bash -> mysql: haslo z ^ i innymi znakami specjalnymi jest ucinane,
a ~/.my.cnf na serwerze nadpisuje usera. Rozwiazanie: ten skrypt przez SSH odpala
ea-php84 uzywajac realnej klasy aplikacji MysqlConnection (ADR-088) — te same
zmienne DB_* co produkcja, zero recznego parsowania .env.

WAZNE — nazwy zmiennych .env (sprawdzone w src/Database/MysqlConnection.php:33-39):
    MySQL PrestaShop = DB_HOST / DB_PORT / DB_NAME_PROD / DB_USER / DB_PASSWORD
    (NIE PS_DB_* — te sa martwe, uzywane tylko dla prefiksu w MobileAuthenticator)
    Railway PG        = DATABASE_URL  (obsluguje sql.py, nie ten skrypt)

Uzycie:
    python3 mysql.py -c "SELECT COUNT(*) FROM pr_product"
    python3 mysql.py --file zapytanie.sql
    cat zapytanie.sql | python3 mysql.py
    python3 mysql.py -c "SELECT ..." --json     # surowy JSON do dalszej obrobki

READ-ONLY z zalozenia: user divezone_chat_reader ma tylko SELECT/SHOW VIEW.
Zadnej flagi --write tu nie ma i nie bedzie.
"""
from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys

SSH_HOST = "divezone@divezonededyk.smarthost.pl"
SSH_PORT = "5739"
REMOTE_APP = "~/public_html/chat.divezone.pl"

# Skrypt PHP wykonywany na serwerze. Czyta SQL ze STDIN (base64, zeby zadne
# apostrofy/cudzyslowy/nowe linie nie ginely w transporcie), wykonuje przez
# MysqlConnection i wypisuje wynik jako JSON (rows + columns).
_REMOTE_PHP = r'''
require __DIR__."/vendor/autoload.php";
\DiveChat\Config::load(__DIR__);
$sql = base64_decode(stream_get_contents(STDIN));
try {
    $c = \DiveChat\Database\MysqlConnection::getInstance();
    $stmt = $c->query($sql);
    $rows = $stmt->fetchAll();
    $cols = [];
    if ($rows) { $cols = array_keys($rows[0]); }
    elseif ($stmt->columnCount() > 0) {
        for ($i=0; $i<$stmt->columnCount(); $i++) {
            $m = $stmt->getColumnMeta($i); $cols[] = $m["name"] ?? "col$i";
        }
    }
    echo json_encode(["ok"=>true,"columns"=>$cols,"rows"=>$rows], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    echo json_encode(["ok"=>false,"error"=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
'''


def _read_sql(args) -> str:
    if args.command:
        return args.command
    if args.file:
        with open(args.file, encoding="utf-8") as fh:
            return fh.read()
    if not sys.stdin.isatty():
        return sys.stdin.read()
    sys.exit("Brak SQL: podaj -c, --file albo przekaz przez STDIN.")


def _run_remote(sql: str) -> dict:
    import base64
    sql_b64 = base64.b64encode(sql.encode("utf-8")).decode("ascii")
    # PHP odpalany przez ea-php84; SQL wchodzi base64 przez STDIN (echo | php).
    remote_cmd = (
        f"cd {REMOTE_APP} && "
        f"echo {sql_b64} | ea-php84 -r {shell_single_quote(_REMOTE_PHP)}"
    )
    proc = subprocess.run(
        ["ssh", "-p", SSH_PORT, SSH_HOST, remote_cmd],
        capture_output=True, text=True, timeout=60,
    )
    out = proc.stdout.strip()
    # SSH czasem dokleja ostrzezenie o pseudo-terminalu na stderr — ignorujemy.
    if not out:
        sys.exit(f"Pusta odpowiedz z serwera. stderr:\n{proc.stderr.strip()}")
    try:
        return json.loads(out)
    except json.JSONDecodeError:
        sys.exit(f"Odpowiedz nie jest JSON-em:\n{out}\n---stderr---\n{proc.stderr.strip()}")


def shell_single_quote(s: str) -> str:
    """Bezpieczne pojedyncze cudzyslowie dla powloki zdalnej."""
    return "'" + s.replace("'", "'\\''") + "'"


def _render_table(cols, rows) -> None:
    if not rows:
        print("(0 wierszy)")
        return
    widths = {c: len(str(c)) for c in cols}
    for r in rows:
        for c in cols:
            widths[c] = max(widths[c], len(str(r.get(c, ""))))
    line = " | ".join(str(c).ljust(widths[c]) for c in cols)
    print(line)
    print("-+-".join("-" * widths[c] for c in cols))
    for r in rows:
        print(" | ".join(str(r.get(c, "")).ljust(widths[c]) for c in cols))
    print(f"\n({len(rows)} wierszy)")


def main() -> None:
    ap = argparse.ArgumentParser(description="SQL read-only na MySQL PrestaShop przez SSH+ea-php84.")
    ap.add_argument("-c", "--command", help="Zapytanie SQL")
    ap.add_argument("--file", help="Plik z zapytaniem SQL")
    ap.add_argument("--json", action="store_true", help="Surowy JSON zamiast tabelki")
    args = ap.parse_args()

    sql = _read_sql(args)
    res = _run_remote(sql)

    if not res.get("ok"):
        sys.exit(f"BLAD MySQL: {res.get('error')}")

    if args.json:
        print(json.dumps(res["rows"], ensure_ascii=False, indent=2))
    else:
        _render_table(res["columns"], res["rows"])


if __name__ == "__main__":
    main()
