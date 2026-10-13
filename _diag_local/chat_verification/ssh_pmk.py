#!/usr/bin/env python3
"""ssh_pmk.py - uruchamia zdalna komende na serwerze divezone przez paramiko.

Odpowiednik `ssh host "cmd"` dla Windows pod Desktop Commander, gdzie ssh.exe
zwraca exit 255 bez wyjscia. Dziala tez na macOS. Do RECONU (odczyt): ls, grep, cat, php -l, find.
Nie uzywaj do zmian na serwerze.

Uzycie:
    python ssh_pmk.py -c "ls ~/public_html/newtmp2/modules | head"
"""
from __future__ import annotations

import argparse
import os
import sys

import paramiko

SSH_HOST = "divezonededyk.smarthost.pl"
SSH_PORT = 5739
SSH_USER = "divezone"
# Sciezki skladane przez os.path.join: separator wlasciwy dla systemu (macOS i Windows).
SSH_KEY = os.path.join(os.path.expanduser("~"), ".ssh", "id_ed25519")
KNOWN_HOSTS = os.path.join(os.path.expanduser("~"), ".ssh", "known_hosts")


def _require_file(path: str, label: str) -> None:
    """Brak pliku klucza albo known_hosts = glosny blad przed proba polaczenia."""
    if not os.path.isfile(path):
        sys.exit(f"BRAK PLIKU {label}: {path}")


def run(cmd: str, timeout: int = 60):
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
        _in, out, err = cli.exec_command(cmd, timeout=timeout)
        o = out.read().decode("utf-8", "replace")
        e = err.read().decode("utf-8", "replace")
        rc = out.channel.recv_exit_status()
    finally:
        cli.close()
    return rc, o, e


def main() -> None:
    ap = argparse.ArgumentParser(description="Zdalna komenda read-only przez paramiko.")
    ap.add_argument("-c", "--command", required=True)
    ap.add_argument("--timeout", type=int, default=60)
    args = ap.parse_args()
    rc, o, e = run(args.command, args.timeout)
    if o:
        sys.stdout.write(o)
    if e:
        sys.stderr.write("\n--- stderr ---\n" + e)
    sys.exit(rc)


if __name__ == "__main__":
    main()
