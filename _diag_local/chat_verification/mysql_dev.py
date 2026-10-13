#!/usr/bin/env python3
"""
mysql_dev.py - SQL READ-ONLY na bazie DEV PrestaShop (divezone_dev) przez paramiko.

Powod: mysql_pmk.py idzie przez aplikacje czatu i trafia na baze PROD (divezone_2025);
user divezone_chat_reader NIE ma uprawnien do divezone_dev. Architekt musi moc niezaleznie
weryfikowac raporty CC z prac na DEV, stad osobny czytnik.

ZRODLO POSWIADCZEN: app/config/parameters.php instalacji DEV, czytany PO STRONIE SERWERA.
Haslo nie opuszcza serwera i nie przechodzi przez ten skrypt. Nie uzywamy .env - zweryfikowano
2026-07-27, ze DB_PASSWORD_DEV w pliku .env jest nieaktualne (19 znakow wobec 18 w parameters.php,
inny md5), wiec parameters.php jest jedynym zrodlem prawdy.

BEZPIECZENSTWO (user divezone_sklep_tmp2 ma dostep takze do PROD):
- bramka po stronie serwera: jesli database_name != 'divezone_dev' -> blad przed polaczeniem,
- druga bramka po polaczeniu: SELECT DATABASE() musi zwrocic divezone_dev,
- read-only: dozwolone wylacznie SELECT / SHOW / DESCRIBE / DESC / EXPLAIN / WITH,
  jedna instrukcja na wywolanie. Flagi --write nie ma i nie nalezy jej dodawac,
- na serwerze na czas wykonania powstaje plik /tmp/_hades_ro.php: sam kod PHP (sciezka do
  parameters.php DEV i dwie bramki), bez hasla i bez SQL. SQL idzie w komendzie zdalnej jako
  base64 i trafia do PHP przez STDIN. Plik kasuje `rm -f` w tej samej komendzie zaraz po
  wykonaniu PHP, takze gdy PHP zakonczy sie bledem. Sprzatanie jest best effort: wyniku rm
  skrypt nie sprawdza, wiec plik zostaje w /tmp, gdy sesja SSH zostanie zerwana przed rm
  albo rm zawiedzie. Nazwa pliku jest stala, wiec rownolegle wywolania moga sobie wchodzic
  w droge,
- host weryfikowany wobec ~/.ssh/known_hosts (RejectPolicy). Brak klucza albo known_hosts
  to blad przed polaczeniem.

Uzycie:
    python mysql_dev.py -c "SELECT COUNT(*) FROM divezone_hades_product"
    python mysql_dev.py --file zapytanie.sql
    python mysql_dev.py -c "SELECT ..." --json
"""
from __future__ import annotations

import argparse
import base64
import json
import os
import re
import sys

import paramiko

SSH_HOST = "divezonededyk.smarthost.pl"
SSH_PORT = 5739
SSH_USER = "divezone"
# Sciezki skladane przez os.path.join: separator wlasciwy dla systemu (macOS i Windows).
SSH_KEY = os.path.join(os.path.expanduser("~"), ".ssh", "id_ed25519")
KNOWN_HOSTS = os.path.join(os.path.expanduser("~"), ".ssh", "known_hosts")
DEV_PARAMS = "/home/divezone/public_html/dev.divezone.pl/app/config/parameters.php"
EXPECTED_DB = "divezone_dev"

ALLOWED = re.compile(r"^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|WITH)\b", re.IGNORECASE)


# Skrypt PHP wykonywany na serwerze. Czyta SQL ze STDIN (base64), poswiadczenia bierze
# z parameters.php DEV. Dwie bramki na nazwe bazy. Zwraca JSON.
_REMOTE_PHP = r'''<?php
$sql = base64_decode(stream_get_contents(STDIN));
$p = require "__PARAMS__";
$d = $p["parameters"];
if ($d["database_name"] !== "divezone_dev") {
    echo json_encode(["ok"=>false,"error"=>"BRAMKA 1: parameters.php wskazuje ".$d["database_name"]]);
    exit;
}
try {
    $dsn = "mysql:host=".$d["database_host"].";dbname=".$d["database_name"].";charset=utf8mb4";
    $pdo = new PDO($dsn, $d["database_user"], $d["database_password"], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
    if ($db !== "divezone_dev") {
        echo json_encode(["ok"=>false,"error"=>"BRAMKA 2: polaczono z ".$db]);
        exit;
    }
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();
    $cols = [];
    if ($rows) { $cols = array_keys($rows[0]); }
    elseif ($stmt->columnCount() > 0) {
        for ($i=0; $i<$stmt->columnCount(); $i++) {
            $m = $stmt->getColumnMeta($i); $cols[] = $m["name"] ?? "col$i";
        }
    }
    echo json_encode(["ok"=>true,"db"=>$db,"columns"=>$cols,"rows"=>$rows], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    echo json_encode(["ok"=>false,"error"=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
'''


def _require_file(path: str, label: str) -> None:
    """Brak pliku klucza albo known_hosts = glosny blad przed proba polaczenia."""
    if not os.path.isfile(path):
        sys.exit(f"BRAK PLIKU {label}: {path}")


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
    php = _REMOTE_PHP.replace("__PARAMS__", DEV_PARAMS)
    php_b64 = base64.b64encode(php.encode("utf-8")).decode("ascii")
    sql_b64 = base64.b64encode(sql.encode("utf-8")).decode("ascii")
    # Skrypt PHP ladowany do /tmp na czas wykonania i kasowany od razu po nim.
    # /tmp jest poza szescioma drzewami Sentinela, wiec nie wymaga deklaracji.
    remote = (
        f"echo {php_b64} | base64 -d > /tmp/_hades_ro.php && "
        f"echo {sql_b64} | ea-php84 /tmp/_hades_ro.php; "
        f"rm -f /tmp/_hades_ro.php"
    )

    _require_file(SSH_KEY, "klucza SSH")
    _require_file(KNOWN_HOSTS, "known_hosts")
    cli = paramiko.SSHClient()
    cli.load_host_keys(KNOWN_HOSTS)
    # Host spoza known_hosts = odrzucenie polaczenia, bez cichego dopisywania klucza.
    cli.set_missing_host_key_policy(paramiko.RejectPolicy())
    pkey = paramiko.Ed25519Key.from_private_key_file(SSH_KEY)
    cli.connect(SSH_HOST, port=SSH_PORT, username=SSH_USER, pkey=pkey,
                timeout=20, look_for_keys=False, allow_agent=False)
    try:
        _in, out, err = cli.exec_command(remote, timeout=60)
        stdout_text = out.read().decode("utf-8", "replace").strip()
        stderr_text = err.read().decode("utf-8", "replace").strip()
    finally:
        cli.close()

    if not stdout_text:
        sys.exit(f"Pusta odpowiedz z serwera. stderr:\n{stderr_text}")
    try:
        return json.loads(stdout_text)
    except json.JSONDecodeError:
        sys.exit(f"Odpowiedz nie jest JSON-em:\n{stdout_text}\n---stderr---\n{stderr_text}")


def _render_table(cols, rows) -> None:
    if not rows:
        print("(0 wierszy)")
        return
    widths = {c: len(str(c)) for c in cols}
    for r in rows:
        for c in cols:
            widths[c] = max(widths[c], len(str(r.get(c, ""))))
    print(" | ".join(str(c).ljust(widths[c]) for c in cols))
    print("-+-".join("-" * widths[c] for c in cols))
    for r in rows:
        print(" | ".join(str(r.get(c, "")).ljust(widths[c]) for c in cols))
    print(f"\n({len(rows)} wierszy)")


def main() -> None:
    ap = argparse.ArgumentParser(description="SQL read-only na bazie DEV (divezone_dev).")
    ap.add_argument("-c", "--command", help="Zapytanie SQL")
    ap.add_argument("--file", help="Plik z zapytaniem SQL")
    ap.add_argument("--json", action="store_true", help="Surowy JSON zamiast tabelki")
    args = ap.parse_args()

    sql = _read_sql(args).strip()
    if not ALLOWED.match(sql):
        sys.exit("READ-ONLY: dozwolone tylko SELECT / SHOW / DESCRIBE / EXPLAIN / WITH.")
    if ";" in sql.rstrip().rstrip(";"):
        sys.exit("READ-ONLY: jedna instrukcja na wywolanie (srednik w srodku zapytania).")

    res = _run_remote(sql)
    if not res.get("ok"):
        sys.exit(f"BLAD: {res.get('error')}")

    if args.json:
        print(json.dumps(res["rows"], ensure_ascii=False, indent=2))
    else:
        print(f"[baza: {res.get('db')}]")
        _render_table(res["columns"], res["rows"])


if __name__ == "__main__":
    main()
