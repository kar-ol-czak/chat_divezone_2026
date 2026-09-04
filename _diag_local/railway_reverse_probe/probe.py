#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
CHAT-T-184 — sonda LUSTRZANA: puka z Railway do serwera divezone.

Po co: caly nasz dotychczasowy material (monitor CHAT-T-108/119, zrzuty incydentow,
traceroute) startuje z serwera w strone Railway. Smarthost slusznie zauwazyl w zgloszeniu
167585, ze to nie mowi nic o trasie powrotnej (trasowanie asymetryczne). Ta sonda chodzi
PO STRONIE RAILWAY, wiec ruch inicjuje sie z drugiego konca.

CZYM TEN POMIAR JEST, A CZYM NIE JEST (poprawka po recenzji krzyzowej 2026-09-04):
  JEST: osiagalnosc i RTT mierzone Z RAILWAY, czyli z polaczeniem inicjowanym z tamtej strony.
  NIE JEST: pomiarem jednego kierunku. Handshake TCP leci tam I z powrotem, wiec pojedynczy
  FAIL nie wskazuje, ktora noga zgubila pakiet. Wartosc dowodowa jest w ZESTAWIENIU CZASOW:
  jesli w tej samej minucie nasz monitor widzi FAIL, a sonda z Railway widzi OK (albo
  odwrotnie), to jest fakt, ktorego z jednej strony nie da sie zobaczyc.

Zalozenia:
  - WYLACZNIE biblioteka standardowa — dziala na obrazie python:3 bez instalowania niczego,
  - jedna linia na cykl na stdout (logi Railway), flush po kazdej linii,
  - kazda metryka ma WLASNY pomiar czasu i TWARDY budzet; zaden zawieszony connect nie
    zatrzymuje petli (to awaria, ktora po naszej stronie naprawial CHAT-T-109: wiszace
    polaczenie zostawilo zywy proces, ktory przez 46 minut nic nie logowal),
  - w petli NIE MA zadnego wywolania DNS: laczymy sie po IP, nazwa idzie tylko w SNI.
    getaddrinfo() nie ma timeoutu w stdlib, wiec informacyjne sprawdzenie DNS na starcie
    leci w watku demona z limitem czasu i nie moze zablokowac startu.

Czego sonda CELOWO nie robi:
  - nie odpytuje /api/health (ten endpoint sam laczy sie z Railway PG, wiec pomiar bieglby
    tam i z powrotem po podejrzanej trasie i nic by nie znaczyl),
  - nie pisze do zadnej bazy (diagnostyka tymczasowa, zero migracji na PROD).

Format linii (wzorowany na railway_monitor.php, zeby dalo sie zestawiac minuta w minute):
  #00001 2026-09-04 18:00:00 UTC | tcp443 OK     32ms | tls443 OK     84ms | tcp5739 OK     33ms | ctrl_leaseweb OK     12ms | errno=0 | span=0.2s
Pola:
  errno=      errno metryki tcp443 (jak w naszym monitorze). Pozostale bledy ida w err_*.
  span=       ile trwal CALY cykl. Cztery metryki mierzone sa PO KOLEI, wiec przy duzym span
              ostatnia metryka jest o tyle pozniejsza niz znacznik czasu linii.
  err_<m>=    powod bledu metryki + offset od poczatku cyklu, np. err_tcp443=110/ETIMEDOUT@0.0s
"""

import os
import signal
import socket
import ssl
import sys
import threading
import time
from datetime import datetime, timezone

TARGET_IP = "193.93.88.95"        # chat.divezone.pl (A: 193.93.88.95, sprawdzone 2026-09-04)
TARGET_SNI = "chat.divezone.pl"
PORT_HTTPS = 443
PORT_SSH = 5739
CTRL_IP = "5.79.108.33"           # Leaseweb AMS — kontrola: inny cel, inna trasa
CTRL_PORT = 80

DNS_CHECK_TIMEOUT_S = 3.0         # tylko informacyjnie, na starcie, w watku demona


def _cfg(name: str, default: float, lo: float, hi: float) -> float:
    """Konfiguracja z ENV z walidacja. Bezsensowna wartosc = default + GLOSNY komunikat,
    zeby literowka w panelu nie zrobila z sondy generatora FAIL-i albo nieskonczonego snu."""
    raw = os.environ.get(name)
    if raw is None:
        return default
    try:
        val = float(raw)
    except (TypeError, ValueError):
        print("# UWAGA %s=%r nie jest liczba — biore %s" % (name, raw, default), flush=True)
        return default
    if not (lo <= val <= hi):
        print("# UWAGA %s=%s poza zakresem [%s,%s] — biore %s" % (name, val, lo, hi, default), flush=True)
        return default
    return val


INTERVAL_S = _cfg("PROBE_INTERVAL_S", 30.0, 1.0, 3600.0)
TIMEOUT_S = _cfg("PROBE_TIMEOUT_S", 5.0, 0.5, 60.0)
MAX_CYCLES = int(_cfg("PROBE_MAX_CYCLES", 0.0, 0.0, 1e9))   # 0 = bez konca

# Kontekst TLS budowany RAZ: wczytanie listy CA to praca lokalna, ktora nie ma prawa
# zjadac budzetu pomiaru sieci (i nie ma po co powtarzac jej co 30 s przez trzy doby).
_TLS_CTX = ssl.create_default_context()

_STOP = False
_STOP_SIGNAL = 0


def _utc_now_str() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


def errno_label(num: int) -> str:
    import errno as _e
    return _e.errorcode.get(num, "?")


def _errno_name(exc: Exception) -> str:
    """'110/ETIMEDOUT' albo 'timeout' albo 'SSL/<powod>'. ssl.SSLError ma wlasne pole errno
    z numeracja OpenSSL (np. 1), ktore NIE jest errno POSIX — tlumaczenie go na EPERM
    byloby mylace, wiec ma osobna galaz."""
    if isinstance(exc, socket.timeout):
        return "timeout"
    if isinstance(exc, ssl.CertificateError):
        return "SSL/CERT"
    if isinstance(exc, ssl.SSLError):
        return "SSL/%s" % (getattr(exc, "reason", None) or type(exc).__name__)
    err = getattr(exc, "errno", None)
    if err:
        return "%d/%s" % (err, errno_label(err))
    return type(exc).__name__


def measure_tcp(ip: str, port: int):
    """Czysty TCP connect po ADRESIE IP (bez DNS). Zwraca (ok, ms, errno_int, detail).
    Lapiemy Exception, nie BaseException: MemoryError/KeyboardInterrupt/SystemExit maja
    zabic proces, a nie zostac zaraportowane jako 'siec nie dziala'."""
    start = time.monotonic()
    sock = None
    try:
        sock = socket.create_connection((ip, port), timeout=TIMEOUT_S)
        return True, (time.monotonic() - start) * 1000.0, 0, ""
    except Exception as exc:                          # noqa: BLE001
        return False, (time.monotonic() - start) * 1000.0, int(getattr(exc, "errno", 0) or 0), _errno_name(exc)
    finally:
        if sock is not None:
            try:
                sock.close()
            except OSError:
                pass


def measure_tls(ip: str, port: int, sni: str):
    """TCP connect + handshake TLS z SNI, po adresie IP. Handshake dostaje TO, CO ZOSTALO
    z budzetu TIMEOUT_S — inaczej metryka moglaby trwac dwa razy dluzej niz deklarowany limit."""
    start = time.monotonic()
    sock = None
    tls = None
    try:
        sock = socket.create_connection((ip, port), timeout=TIMEOUT_S)
        left = TIMEOUT_S - (time.monotonic() - start)
        if left <= 0:
            raise socket.timeout("budzet czasu wyczerpany po connect")
        sock.settimeout(left)
        tls = _TLS_CTX.wrap_socket(sock, server_hostname=sni, do_handshake_on_connect=True)
        sock = None                                   # wlasnosc gniazda przejal obiekt TLS
        return True, (time.monotonic() - start) * 1000.0, 0, ""
    except Exception as exc:                          # noqa: BLE001
        return False, (time.monotonic() - start) * 1000.0, int(getattr(exc, "errno", 0) or 0), _errno_name(exc)
    finally:
        for s in (tls, sock):
            if s is not None:
                try:
                    s.close()
                except OSError:
                    pass


def _handle_stop(signum, _frame):
    """Handler robi TYLKO tyle, ile wolno w handlerze: ustawia flagi. Zadnego I/O."""
    global _STOP, _STOP_SIGNAL
    _STOP = True
    _STOP_SIGNAL = signum


def _dns_check_async() -> None:
    """Informacyjne sprawdzenie DNS w watku demona z limitem czasu.
    getaddrinfo() nie ma timeoutu, wiec wywolane wprost potrafiloby zawiesic start sondy —
    czyli dac dokladnie ten stan, ktory mamy wykryc: proces zyje i milczy."""
    result = {}

    def _resolve():
        try:
            result["ips"] = sorted({ai[4][0] for ai in socket.getaddrinfo(TARGET_SNI, PORT_HTTPS, socket.AF_INET)})
        except Exception as exc:                      # noqa: BLE001
            result["err"] = _errno_name(exc)

    th = threading.Thread(target=_resolve, daemon=True)
    th.start()
    th.join(DNS_CHECK_TIMEOUT_S)
    if th.is_alive():
        print("# dns: brak odpowiedzi w %.0fs — pomijam (sonda i tak laczy sie po IP %s)"
              % (DNS_CHECK_TIMEOUT_S, TARGET_IP), flush=True)
    elif "ips" in result:
        zgodne = "ZGODNE" if TARGET_IP in result["ips"] else "ROZJAZD! sonda i tak uzywa " + TARGET_IP
        print("# dns: %s -> %s (%s)" % (TARGET_SNI, ",".join(result["ips"]), zgodne), flush=True)
    else:
        print("# dns: blad rozwiazywania %s (%s) — bez znaczenia, laczymy sie po IP"
              % (TARGET_SNI, result.get("err", "?")), flush=True)


def _print_environment() -> None:
    """Tozsamosc srodowiska do pisma: z KTOREGO miejsca Railway to bylo mierzone.
    UWAGA merytoryczna: to jest serwis obliczeniowy Railway, ktory NIE MUSI wychodzic
    tym samym laczem co publiczny proxy PostgreSQL (switchback.proxy.rlwy.net) —
    i to trzeba przy interpretacji powiedziec wprost."""
    keys = ("RAILWAY_REGION", "RAILWAY_REPLICA_REGION", "RAILWAY_ENVIRONMENT_NAME",
            "RAILWAY_PROJECT_NAME", "RAILWAY_SERVICE_NAME", "RAILWAY_DEPLOYMENT_ID")
    env = ", ".join("%s=%s" % (k, os.environ[k]) for k in keys if os.environ.get(k)) or "brak zmiennych RAILWAY_*"
    print("# srodowisko: %s" % env, flush=True)
    local_ip = "?"
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.settimeout(1.0)
        s.connect((TARGET_IP, PORT_HTTPS))            # UDP connect = tylko wybor trasy, zero pakietow
        local_ip = s.getsockname()[0]
        s.close()
    except Exception:                                 # noqa: BLE001
        pass
    print("# host: python %s | openssl %s | ip lokalne %s (adres publiczny moze byc inny — NAT)"
          % (sys.version.split()[0], ssl.OPENSSL_VERSION, local_ip), flush=True)


def main() -> int:
    signal.signal(signal.SIGTERM, _handle_stop)
    signal.signal(signal.SIGINT, _handle_stop)

    print("# START %s UTC | sonda z Railway do %s (%s) | interwal %.0fs | timeout %.0fs"
          % (_utc_now_str(), TARGET_SNI, TARGET_IP, INTERVAL_S, TIMEOUT_S), flush=True)
    print("# metryki: tcp443, tls443 (SNI %s), tcp5739, ctrl_leaseweb (%s:%d) — mierzone PO KOLEI"
          % (TARGET_SNI, CTRL_IP, CTRL_PORT), flush=True)
    _print_environment()
    _dns_check_async()

    cycle = 0
    t0 = time.monotonic()
    next_tick = t0
    while not _STOP:
        cycle += 1
        cycle_start = time.monotonic()
        ts = _utc_now_str()

        results = []
        for name, fn in (("tcp443", lambda: measure_tcp(TARGET_IP, PORT_HTTPS)),
                         ("tls443", lambda: measure_tls(TARGET_IP, PORT_HTTPS, TARGET_SNI)),
                         ("tcp5739", lambda: measure_tcp(TARGET_IP, PORT_SSH)),
                         ("ctrl_leaseweb", lambda: measure_tcp(CTRL_IP, CTRL_PORT))):
            offset = time.monotonic() - cycle_start
            if _STOP:
                # SIGTERM w polowie cyklu: nie dokladamy kolejnych budzetow po 5 s,
                # zeby zmiescic sie w oknie na zamkniecie, ktore daje Railway.
                results.append((name, None, 0.0, 0, "przerwane sygnalem", offset))
                continue
            ok, ms, en, det = fn()
            results.append((name, ok, ms, en, det, offset))

        span = time.monotonic() - cycle_start
        cells, details, errno_lead = [], [], 0
        for name, ok, ms, en, det, offset in results:
            cells.append("%s %-4s %6.0fms" % (name, ("OK" if ok else "FAIL") if ok is not None else "SKIP", ms))
            if name == "tcp443":
                errno_lead = en
            if ok is not True and det:
                details.append(" | err_%s=%s@%.1fs" % (name, det, offset))
        print("#%05d %s UTC | %s | errno=%d | span=%.1fs%s"
              % (cycle, ts, " | ".join(cells), errno_lead, span, "".join(details)), flush=True)

        if MAX_CYCLES and cycle >= MAX_CYCLES:
            break
        if _STOP:
            break

        # Staly rytm: kolejne tykniecia co INTERVAL_S od startu. Cykl dluzszy niz interwal
        # NIE kumuluje dlugu — przepadle sloty pomijamy i mowimy o tym GLOSNO.
        missed = 0
        now = time.monotonic()
        next_tick += INTERVAL_S
        while next_tick <= now:
            next_tick += INTERVAL_S
            missed += 1
        if missed:
            print("# UWAGA cykl %d trwal %.1fs — przepadlo %d slotow pomiarowych"
                  % (cycle, span, missed), flush=True)
        # sen w kawalkach: SIGTERM ma dzialac od razu, a nie po pelnym interwale
        while not _STOP:
            left = next_tick - time.monotonic()
            if left <= 0:
                break
            time.sleep(min(1.0, max(0.0, left)))

    print("# KONIEC %s UTC | cykli: %d%s"
          % (_utc_now_str(), cycle, (" | sygnal %d" % _STOP_SIGNAL) if _STOP_SIGNAL else ""), flush=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
