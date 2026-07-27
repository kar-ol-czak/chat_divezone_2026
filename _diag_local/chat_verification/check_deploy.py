#!/usr/bin/env python3
"""
check_deploy.py — kontrola wdrozenia: md5 local<->prod + php -l + smoke.

Powstal 2026-07-17 (_docs/46): te trzy sprawdzenia byly pisane od zera przy
kazdym deployu. "Rsync pokazal transfer" NIE znaczy "wdrozone we wlasciwym
miejscu" — to sprawdza, czy naprawde.

Uzycie:
    python3 check_deploy.py standalone/src/Chat/ChatService.php
    python3 check_deploy.py standalone/src/Chat/ChatService.php --grep "ADR-126"
    python3 check_deploy.py modules/divezone_chat/controllers/admin/AdminDivezoneChatController.php
    python3 check_deploy.py --smoke-only

Mapowanie repo -> serwer (dwa OSOBNE swiaty, patrz _docs/46 §3):
    standalone/<x>  -> ~/public_html/chat.divezone.pl/<x>     (BEZ prefiksu standalone/)
    modules/<x>     -> ~/public_html/newtmp2/modules/<x>      (newtmp2 = PRODUKCJA)
"""
from __future__ import annotations

import argparse
import hashlib
import os
import subprocess
import sys

PROJECT_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
SSH = ["ssh", "-p", "5739", "-o", "ConnectTimeout=20", "divezone@divezonededyk.smarthost.pl"]
HEALTH_URL = "https://chat.divezone.pl/api/health"


def map_to_server(rel_path: str) -> tuple[str, str]:
    """Zwraca (sciezka_na_serwerze, nazwa_swiata). Nie zgaduje z nazwy katalogu."""
    rel_path = rel_path.lstrip("./")
    if rel_path.startswith("standalone/"):
        return "$HOME/public_html/chat.divezone.pl/" + rel_path[len("standalone/"):], "BACKEND"
    if rel_path.startswith("modules/"):
        return "$HOME/public_html/newtmp2/" + rel_path, "SKLEP/MODUL PS"
    raise SystemExit(
        f"Nie wiem, do ktorego swiata nalezy '{rel_path}'.\n"
        "Znam: standalone/... (backend) i modules/... (sklep PS). Patrz _docs/46 §3."
    )


def ssh_run(cmd: str) -> tuple[int, str]:
    res = subprocess.run(SSH + [cmd], capture_output=True, text=True)
    return res.returncode, (res.stdout + res.stderr).strip()


def md5_local(path: str) -> str:
    with open(path, "rb") as fh:
        return hashlib.md5(fh.read()).hexdigest()


def main() -> int:
    ap = argparse.ArgumentParser(description="Kontrola wdrozenia (md5 + php -l + smoke).")
    ap.add_argument("path", nargs="?", help="sciezka w repo, np. standalone/src/Chat/X.php")
    ap.add_argument("--grep", help="marker, ktory MUSI byc w pliku na produkcji (np. numer taska)")
    ap.add_argument("--smoke-only", action="store_true", help="tylko /api/health")
    args = ap.parse_args()

    ok = True

    if not args.smoke_only:
        if not args.path:
            ap.error("podaj sciezke albo --smoke-only")

        local_path = os.path.join(PROJECT_ROOT, args.path)
        if not os.path.exists(local_path):
            raise SystemExit(f"BRAK pliku lokalnie: {local_path}")

        remote_path, world = map_to_server(args.path)
        print(f"SWIAT: {world}")
        print(f"repo : {args.path}")
        print(f"prod : {remote_path}\n")

        # 1. md5
        local_sum = md5_local(local_path)
        rc, remote_sum = ssh_run(f'md5sum "{remote_path}" 2>/dev/null | cut -d" " -f1')
        remote_sum = remote_sum.strip()
        zgodne = bool(remote_sum) and local_sum == remote_sum
        print(f"md5 local : {local_sum}")
        print(f"md5 prod  : {remote_sum or '(BRAK PLIKU NA PROD)'}")
        print(f"md5       : {'ZGODNE' if zgodne else 'ROZNE — NIE WDROZONE'}\n")
        ok &= zgodne

        # 2. php -l (tylko PHP, przez ea-php84 — domyslny CLI to 8.3)
        if args.path.endswith(".php"):
            rc, out = ssh_run(f'ea-php84 -l "{remote_path}" 2>&1')
            czysto = "No syntax errors" in out
            print(f"php -l    : {out.splitlines()[0] if out else '(brak wyjscia)'}")
            ok &= czysto

        # 3. marker taska
        if args.grep:
            rc, out = ssh_run(f'grep -c -- "{args.grep}" "{remote_path}" 2>/dev/null')
            found = out.strip().isdigit() and int(out.strip()) > 0
            print(f"grep '{args.grep}' : {'OBECNY' if found else 'BRAK NA PROD'}")
            ok &= found

    # 4. smoke
    rc, out = ssh_run(f'curl -s -o /dev/null -w "%{{http_code}}" {HEALTH_URL}')
    code = out.strip().splitlines()[-1] if out else "?"
    rc2, body = ssh_run(f'curl -s {HEALTH_URL}')
    print(f"\nsmoke     : HTTP {code}")
    print(f"            {body.strip()[:120]}")
    ok &= code == "200"

    print("\n" + ("=== WSZYSTKO OK ===" if ok else "=== SA PROBLEMY (patrz wyzej) ==="))
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
