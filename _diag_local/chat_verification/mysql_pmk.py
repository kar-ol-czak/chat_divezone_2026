#!/usr/bin/env python3
"""
mysql_pmk.py - odpowiednik mysql.py dla Windows pod Desktop Commander. Dziala tez na macOS.

Powod: na VM Windows ssh.exe uruchamiany przez Desktop Commander konczy sie
exit 255 bez wyjscia (brak konsoli przy spawnie), wiec transport oparty o
ssh.exe (jak w mysql.py) nie dziala. Tu transport realizuje paramiko (SSH w
czystym Pythonie), reszta identyczna: ten sam zdalny PHP (ea-php84 +
MysqlConnection), to samo wyjscie JSON.

READ-ONLY z zalozenia: user divezone_chat_reader ma tylko SELECT/SHOW VIEW.

Uzycie (identyczne z mysql.py):
    python mysql_pmk.py -c "SELECT COUNT(*) FROM pr_product"
    python mysql_pmk.py --file zapytanie.sql
    python mysql_pmk.py -c "SELECT ..." --json
"""
from __future__ import annotations

import argparse
import base64
import json
import os
import sys

import paramiko

SSH_HOST = "divezonededyk.smarthost.pl"
SSH_PORT = 5739
SSH_USER = "divezone"
# Sciezki skladane przez os.path.join: separator wlasciwy dla systemu (macOS i Windows).
SSH_KEY = os.path.join(os.path.expanduser("~"), ".ssh", "id_ed25519")
KNOWN_HOSTS = os.path.join(os.path.expanduser("~"), ".ssh", "known_hosts")
REMOTE_APP = "~/public_html/chat.divezone.pl"

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


def _require_file(path: str, label: str) -> None:
    """Brak pliku klucza albo known_hosts = glosny blad przed proba polaczenia."""
    if not os.path.isfile(path):
        sys.exit(f"BRAK PLIKU {label}: {path}")


def _sq(s: str) -> str:
    """Bezpieczne pojedyncze cudzyslowie dla powloki zdalnej."""
    return "'" + s.replace("'", "'\\''") + "'"


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
    sql_b64 = base64.b64encode(sql.encode("utf-8")).decode("ascii")
    remote_cmd = f"cd {REMOTE_APP} && ea-php84 -r {_sq(_REMOTE_PHP)}"

    _require_file(SSH_KEY, "klucza SSH")
    _require_file(KNOWN_HOSTS, "known_hosts")
    cli = paramiko.SSHClient()
    cli.load_host_keys(KNOWN_HOSTS)
    # Host spoza known_hosts = odrzucenie polaczenia, bez cichego dopisywania klucza.
    cli.set_missing_host_key_policy(paramiko.RejectPolicy())
    pkey = paramiko.Ed25519Key.from_private_key_file(SSH_KEY)
    cli.connect(
        SSH_HOST, port=SSH_PORT, username=SSH_USER, pkey=pkey,
        timeout=20, look_for_keys=False, allow_agent=False,
    )
    try:
        stdin, stdout, stderr = cli.exec_command(remote_cmd, timeout=60)
        stdin.write(sql_b64)
        stdin.channel.shutdown_write()
        out = stdout.read().decode("utf-8", "replace").strip()
        err = stderr.read().decode("utf-8", "replace").strip()
    finally:
        cli.close()

    if not out:
        sys.exit(f"Pusta odpowiedz z serwera. stderr:\n{err}")
    try:
        return json.loads(out)
    except json.JSONDecodeError:
        sys.exit(f"Odpowiedz nie jest JSON-em:\n{out}\n---stderr---\n{err}")


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
    ap = argparse.ArgumentParser(
        description="SQL read-only na MySQL PrestaShop przez paramiko + ea-php84."
    )
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
