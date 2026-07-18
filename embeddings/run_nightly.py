#!/usr/bin/env python3
"""
CHAT-T-150 (ADR-128): nocny runner pipeline'u embeddingów produktów.

Jeden punkt wejścia dla crona. Kolejność: KROK 2 (delta produktów po hashu) → KROK 3
(multivector na dokładnie tym samym zbiorze). Błąd etapu 1 → etap 2 NIE odpala.

Zabezpieczenia:
- lock file (fcntl) — przebieg nie wystartuje, gdy poprzedni żyje;
- heartbeat — znacznik ostatniego sukcesu; brak sukcesu > 48 h → alert (cisza ≠ sukces);
- alert mailem (DIVECHAT_COST_ALERT_EMAIL) przy błędzie i przy przeterminowanym heartbeacie.

Log: skrypt pisze na stdout/stderr z timestampem; cron przekierowuje `>> .../divechat_embeddings.log`.
Uruchomienie serwerowe: EMBEDDINGS_ENV domyślnie ustawiane na `server` (patrz niżej).
Lokalny dry-run:  EMBEDDINGS_ENV=local python run_nightly.py --mode changed --dry-run
"""

import os
import sys
import fcntl
import time
import socket
import logging
import argparse
import subprocess
from pathlib import Path

# --- KOLEJNOŚĆ KRYTYCZNA ---
# Tryb i .env MUSZĄ być ustalone PRZED importem modułów pipeline'u (one wczytują .env
# na poziomie modułu, wg EMBEDDINGS_ENV). Runner to serwerowy punkt wejścia — domyślnie
# server; można nadpisać z zewnątrz (np. lokalny dry-run: EMBEDDINGS_ENV=local ...).
os.environ.setdefault("EMBEDDINGS_ENV", "server")

from extract_products import open_mysql_access, close_mysql_access  # noqa: E402
from batch_embed_products import run_changed_mode  # noqa: E402
from batch_embed_multivector import run_for_pids  # noqa: E402

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s [nightly] %(message)s",
    stream=sys.stdout,
)
logger = logging.getLogger("run_nightly")

# Ścieżki stanu (katalog ~/logs już używany przez inne crony czatu, ADR-128 dec. 144a).
LOG_DIR = Path(os.getenv("DIVECHAT_LOG_DIR", "/home/divezone/logs"))
LOCK_PATH = LOG_DIR / "divechat_embeddings.lock"
LAST_SUCCESS_PATH = LOG_DIR / "divechat_embeddings.last_success"

HEARTBEAT_MAX_AGE_S = 48 * 3600  # 48 h bez udanego przebiegu → alert


def send_alert(subject: str, body: str) -> None:
    """Wysyła alert mailem przez /usr/sbin/sendmail (Exim/cPanel). Nigdy nie wywraca runnera.

    Adres z DIVECHAT_COST_ALERT_EMAIL (ADR-128 dec. 144a). Zero sekretów w treści.
    """
    to_addr = os.getenv("DIVECHAT_COST_ALERT_EMAIL")
    if not to_addr:
        logger.error("Alert POMINIĘTY — brak DIVECHAT_COST_ALERT_EMAIL w .env. Temat: %s", subject)
        return
    from_addr = os.getenv("DIVECHAT_ALERT_FROM", "noreply@chat.divezone.pl")
    host = socket.gethostname()
    msg = (
        f"To: {to_addr}\r\n"
        f"From: DiveChat embeddings <{from_addr}>\r\n"
        f"Subject: [DiveChat embeddings] {subject}\r\n"
        f"Content-Type: text/plain; charset=utf-8\r\n"
        f"\r\n"
        f"Host: {host}\r\n"
        f"{body}\r\n"
    )
    for sendmail in ("/usr/sbin/sendmail", "/usr/lib/sendmail"):
        if os.path.exists(sendmail):
            try:
                p = subprocess.run(
                    [sendmail, "-t", "-oi"],
                    input=msg.encode("utf-8"),
                    capture_output=True,
                    timeout=30,
                )
                if p.returncode == 0:
                    logger.info("Alert wysłany do %s (temat: %s)", to_addr, subject)
                else:
                    logger.error("sendmail zwrócił %d: %s", p.returncode, p.stderr.decode("utf-8", "replace"))
                return
            except Exception as e:  # noqa: BLE001
                logger.error("Nie udało się wysłać alertu przez %s: %s", sendmail, e)
                return
    logger.error("Alert POMINIĘTY — brak sendmail. Temat: %s", subject)


def check_heartbeat() -> None:
    """KROK 4: cisza ≠ sukces. Jeśli ostatni sukces starszy niż 48 h — alert (przy starcie)."""
    if not LAST_SUCCESS_PATH.exists():
        logger.warning("Brak znacznika last_success — pierwszy przebieg albo skasowany. Heartbeat pominięty.")
        return
    age = time.time() - LAST_SUCCESS_PATH.stat().st_mtime
    if age > HEARTBEAT_MAX_AGE_S:
        hours = int(age // 3600)
        logger.error("HEARTBEAT: brak udanego przebiegu od %d h (> 48 h).", hours)
        send_alert(
            "heartbeat: brak udanego przebiegu > 48 h",
            f"Ostatni udany przebiegu embeddingów był {hours} h temu.\n"
            f"Znacznik: {LAST_SUCCESS_PATH}\n"
            f"Przebiegi crona odpalają się, ale nie kończą sukcesem — sprawdź log.",
        )
    else:
        logger.info("Heartbeat OK — ostatni sukces %d min temu.", int(age // 60))


def mark_success(summary: str) -> None:
    LAST_SUCCESS_PATH.parent.mkdir(parents=True, exist_ok=True)
    LAST_SUCCESS_PATH.write_text(f"{int(time.time())} {summary}\n", encoding="utf-8")


def run(dry_run: bool) -> int:
    logger.info("=== START przebiegu (dry_run=%s, env=%s) ===", dry_run, os.environ["EMBEDDINGS_ENV"])
    check_heartbeat()

    open_mysql_access()
    try:
        # --- KROK 2: delta produktów po hashu ---
        prod_stats = run_changed_mode(dry_run=dry_run)
        changed_pids = prod_stats.get("changed_pids", [])
        logger.info(
            "KROK2 produkty: extract=%d qualified=%d (nowe=%d) embedded=%d api=%d ~%.6f USD",
            prod_stats.get("extracted", 0), prod_stats.get("qualified", 0),
            prod_stats.get("new", 0), prod_stats.get("embedded", 0),
            prod_stats.get("api_calls", 0), prod_stats.get("est_cost_usd", 0.0),
        )
    finally:
        close_mysql_access()

    # --- KROK 3: multivector na dokładnie tym samym zbiorze (pusty → no-op sukces) ---
    mv_stats = run_for_pids(changed_pids, dry_run=dry_run)
    logger.info(
        "KROK3 multivector: requested=%d updated=%d api=%d",
        mv_stats.get("requested", 0), mv_stats.get("updated", 0), mv_stats.get("api_calls", 0),
    )

    summary = (
        f"produkty embedded={prod_stats.get('embedded', 0)}/{prod_stats.get('qualified', 0)} "
        f"multivector updated={mv_stats.get('updated', 0)} "
        f"api={prod_stats.get('api_calls', 0) + mv_stats.get('api_calls', 0)}"
    )

    if dry_run:
        logger.info("=== DRY-RUN zakończony (bez zapisu, bez znacznika sukcesu): %s ===", summary)
        return 0

    mark_success(summary)
    logger.info("=== KONIEC przebiegu OK: %s ===", summary)
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description="Nocny runner embeddingów produktów (CHAT-T-150)")
    parser.add_argument("--mode", choices=["changed"], default="changed",
                        help="changed = delta po hashu (jedyny tryb crona)")
    parser.add_argument("--dry-run", action="store_true", help="Tylko raport delty, zero API i zero zapisu")
    args = parser.parse_args()

    LOG_DIR.mkdir(parents=True, exist_ok=True)

    # Lock: przebieg nie startuje, gdy poprzedni żyje (uchwyt trzymany do końca procesu).
    lock_file = open(LOCK_PATH, "w")
    try:
        fcntl.flock(lock_file, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        logger.warning("Poprzedni przebieg wciąż żyje (lock %s zajęty) — pomijam.", LOCK_PATH)
        return 0

    try:
        lock_file.write(f"{os.getpid()} {int(time.time())}\n")
        lock_file.flush()
        return run(dry_run=args.dry_run)
    except Exception as e:  # noqa: BLE001
        logger.exception("Przebieg PADŁ: %s", e)
        send_alert(
            "przebieg embeddingów PADŁ",
            f"Wyjątek: {type(e).__name__}: {e}\nSprawdź log divechat_embeddings.log.",
        )
        return 1
    finally:
        fcntl.flock(lock_file, fcntl.LOCK_UN)
        lock_file.close()


if __name__ == "__main__":
    sys.exit(main())
