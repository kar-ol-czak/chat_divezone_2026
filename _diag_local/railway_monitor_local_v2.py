#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Monitor lacza lokalny (Mac/VM) -> Railway (TRASA KONTROLNA, inne IP niz serwer).
CHAT-T-108: mierzy TO, CO REALNIE PADA pod obciazeniem, nie tylko SELECT 1.

Metryki na cykl (kazda OK/FAIL + ms osobno):
  railway_tcp  — TCP connect do Railway proxy (jak w v1)
  pg_select1   — SELECT 1 (baseline)
  pg_settings  — SELECT value FROM divechat_settings WHERE key='model_primary' (jak ChatController/SettingsStore)
  pg_chiptree  — SELECT count(*) FROM divechat_chip_nodes WHERE active (proxy budowy drzewa)
  pg_upsert    — INSERT ... ON CONFLICT na kluczu '__monitor_probe__' (sciezka ZAPISU, RateLimiter/NudgeEventStore)
  github       — TCP do api.github.com:443 (kontrola lacza wyjsciowego)

Cechy:
  - interwal 5 s (gesto — awaria 28-06 dawala 5-17 bledow/min)
  - dziala CIAGLE (dni), bez okna stop; log rotuje sie dziennie (plik per YYYYMMDD)
  - heartbeat co 100 cykli (linia "# alive N...")
  - ALERT przy >=3 FAIL z rzedu na DOWOLNEJ metryce PG (nie tcp/github), dedup per-epizod,
    recovery gdy wroci OK. Mail best-effort przez /usr/sbin/sendmail (na Macu moze nie dojsc
    bez relaya — glowny, pewny mail leci z trasy serwerowej; tu alert jest ZAWSZE widoczny w logu/konsoli).
  - probe ZAPISU tylko na kluczu '__monitor_probe__' — NIE rusza danych produkcyjnych.

DATABASE_URL: czytany z .env projektu (katalog wyzej).
Connect+query w osobnym watku z twardym limitem czasu — jeden zawieszony connect NIE blokuje petli.
Zatrzymanie: Ctrl+C.
"""
import os, sys, time, socket, datetime, threading, subprocess
from urllib.parse import urlparse, parse_qs

BASE = os.path.dirname(os.path.abspath(__file__))      # _diag_local
PROJ = os.path.dirname(BASE)                            # katalog projektu
ENV_PATH = os.path.join(PROJ, '.env')

INTERVAL = 5            # gesto (P35c)
TMO = 8                 # connect/query timeout
PROBE_KEY = '__monitor_probe__'
ALERT_STREAK = 3        # >=3 FAIL z rzedu => alert
RECOVERY_OK = 3         # tyle pelnych OK cykli => recovery
COOLDOWN_S = 15 * 60    # min. odstep miedzy alertami tej samej metryki
HEARTBEAT_EVERY = 100
MAIL_TO = 'k.susicki@divezone.pl, k.susicki@gmail.com'
PG_METRICS = ['pg_select1', 'pg_settings', 'pg_chiptree', 'pg_upsert']


def load_dburl(path):
    with open(path, encoding='utf-8') as f:
        for line in f:
            line = line.strip()
            if line.startswith('DATABASE_URL='):
                v = line.split('=', 1)[1].strip()
                if v and v[0] in '"\'' and v[-1] in '"\'':
                    v = v[1:-1]
                return v
    sys.exit(f'Brak DATABASE_URL w {path}')


DBURL = load_dburl(ENV_PATH)
p = urlparse(DBURL)
q = parse_qs(p.query)
RHOST = p.hostname or 'switchback.proxy.rlwy.net'
RPORT = p.port or 14368
SSL = q.get('sslmode', ['disable'])[0]
DBNAME = (p.path or '/railway').lstrip('/')
DBUSER = p.username or ''
DBPASS = p.password or ''


def log_path():
    """Sciezka logu wg biezacej daty — rotacja dzienna bez restartu."""
    today = datetime.datetime.now().strftime('%Y%m%d')
    return os.path.join(BASE, f'railway_monitor_local_{today}.log')


def log(msg):
    """Pisze i na konsole (od razu), i do pliku dnia."""
    print(msg, flush=True)
    try:
        with open(log_path(), 'a', encoding='utf-8') as f:
            f.write(msg + '\n')
    except Exception as e:
        print(f'[WARN] zapis do logu nieudany: {e}', flush=True)


def tcp(host, port, tmo):
    t = time.time()
    try:
        s = socket.create_connection((host, port), timeout=tmo)
        s.close()
        return True, (time.time() - t) * 1000, 0
    except Exception as e:
        return False, (time.time() - t) * 1000, getattr(e, 'errno', -1) or -1


def pg_probe(tmo):
    """
    Jeden connect na cykl, potem 4 realne zapytania mierzone osobno.
    Connect+zapytania w osobnym watku; jak watek wisi > tmo+2s -> wszystkie PG FAIL,
    petla idzie dalej. Zwraca dict metryka -> (ok: bool, ms: float).
    """
    res = {m: [False, 0.0] for m in PG_METRICS}
    state = {'done': False}

    def worker():
        try:
            import psycopg2
            c = psycopg2.connect(host=RHOST, port=RPORT, dbname=DBNAME,
                                 user=DBUSER, password=DBPASS, sslmode=SSL,
                                 connect_timeout=tmo)
            c.autocommit = True
            cur = c.cursor()

            def timed(name, sql, params=None):
                t = time.time()
                try:
                    cur.execute(sql, params or [])
                    cur.fetchone()
                    res[name][0] = True
                except Exception:
                    res[name][0] = False
                finally:
                    res[name][1] = (time.time() - t) * 1000

            timed('pg_select1', 'SELECT 1')
            timed('pg_settings', "SELECT value FROM divechat_settings WHERE key = %s", ['model_primary'])
            timed('pg_chiptree', 'SELECT count(*) FROM divechat_chip_nodes WHERE active')
            # Probe ZAPISU — tylko klucz __monitor_probe__, dane produkcyjne nietkniete.
            timed('pg_upsert',
                  "INSERT INTO divechat_rate_limit (key, window_start, count) VALUES (%s, NOW(), 1) "
                  "ON CONFLICT (key) DO UPDATE SET count = divechat_rate_limit.count + 1, window_start = NOW() "
                  "RETURNING count",
                  [PROBE_KEY])

            cur.close()
            c.close()
        except Exception:
            # connect padl -> wszystkie PG metryki zostaja FAIL (domyslne)
            pass
        finally:
            state['done'] = True

    th = threading.Thread(target=worker, daemon=True)
    t0 = time.time()
    th.start()
    th.join(tmo + 2)
    if not state['done']:
        # zawieszony handshake — caly cykl PG = FAIL, ms = czas oczekiwania
        wait_ms = (time.time() - t0) * 1000
        return {m: (False, wait_ms) for m in PG_METRICS}
    return {m: (res[m][0], res[m][1]) for m in PG_METRICS}


def cleanup_probe():
    """Usuwa klucz probe (porzadek). Best-effort, krotki timeout."""
    def worker():
        try:
            import psycopg2
            c = psycopg2.connect(host=RHOST, port=RPORT, dbname=DBNAME,
                                 user=DBUSER, password=DBPASS, sslmode=SSL, connect_timeout=TMO)
            c.autocommit = True
            cur = c.cursor()
            cur.execute("DELETE FROM divechat_rate_limit WHERE key = %s", [PROBE_KEY])
            cur.close(); c.close()
        except Exception:
            pass
    th = threading.Thread(target=worker, daemon=True)
    th.start(); th.join(TMO + 2)


def send_mail(subject, body):
    """Best-effort mail przez sendmail. Na Macu bez relaya moze nie dojsc — zwraca bool."""
    try:
        msg = (f"To: {MAIL_TO}\nFrom: railway-monitor-local@divezone.pl\n"
               f"Subject: {subject}\nContent-Type: text/plain; charset=UTF-8\n\n{body}\n")
        pr = subprocess.run(['/usr/sbin/sendmail', '-t'], input=msg.encode('utf-8'),
                            timeout=20, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        return pr.returncode == 0
    except Exception:
        return False


def utc_now():
    return datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%d %H:%M:%S')


def loc_now():
    return datetime.datetime.now().strftime('%H:%M:%S')


# ---- alert state ----
streak = {m: 0 for m in PG_METRICS}     # biezacy ciag FAIL per metryka PG
ok_streak = 0                            # ciag pelnych OK cykli (wszystkie PG OK)
alert_active = False                     # czy trwa zaalarmowany epizod
last_alert_ts = 0.0                      # cooldown
episode_info = ''                        # opis epizodu do recovery


def handle_alert(metrics):
    """metrics: dict metryka -> (ok, ms). Aktualizuje streaki, wysyla alert/recovery."""
    global ok_streak, alert_active, last_alert_ts, episode_info
    all_pg_ok = True
    worst = None
    for m in PG_METRICS:
        ok = metrics[m][0]
        if ok:
            streak[m] = 0
        else:
            streak[m] += 1
            all_pg_ok = False
            if worst is None or streak[m] > streak[worst]:
                worst = m

    if all_pg_ok:
        ok_streak += 1
    else:
        ok_streak = 0

    now = time.time()
    # --- trigger alertu ---
    if worst is not None and streak[worst] >= ALERT_STREAK:
        if not alert_active and (now - last_alert_ts) >= COOLDOWN_S:
            ts = f"{utc_now()} UTC / {loc_now()} WAW"
            episode_info = f"{worst} FAIL x{streak[worst]} od ~{ts}"
            subj = f"[DIVECHAT MONITOR] Railway degradacja (lokalny): {worst} FAIL x{streak[worst]}"
            body = (f"TRASA KONTROLNA lokalny->Railway.\n"
                    f"Metryka {worst}: {streak[worst]} FAIL z rzedu, od {ts}.\n"
                    f"Host: {RHOST}:{RPORT}\nLog: {log_path()}\n"
                    f"(To kontrola z innego IP niz serwer. Glowny dowod = trasa serwerowa.)")
            sent = send_mail(subj, body)
            log(f"### ALERT {ts} | {episode_info} | mail={'sent' if sent else 'FAILED(local-sendmail)'}")
            alert_active = True
            last_alert_ts = now
    # --- recovery ---
    if alert_active and ok_streak >= RECOVERY_OK:
        ts = f"{utc_now()} UTC / {loc_now()} WAW"
        subj = "[DIVECHAT MONITOR] Railway recovery (lokalny): PG znow OK"
        body = (f"TRASA KONTROLNA lokalny->Railway.\n"
                f"Po epizodzie [{episode_info}] wszystkie metryki PG OK przez {ok_streak} cykli.\n"
                f"Powrot: {ts}\nLog: {log_path()}")
        sent = send_mail(subj, body)
        log(f"### RECOVERY {ts} | po [{episode_info}] | mail={'sent' if sent else 'FAILED(local-sendmail)'}")
        alert_active = False


def main():
    cleanup_probe()  # czysty start klucza probe
    now = datetime.datetime.now()
    log(f"# START {now.strftime('%Y-%m-%d %H:%M:%S')} LOCAL | CIAGLY (bez stop) | interval {INTERVAL}s | "
        f"host {RHOST}:{RPORT} | log {log_path()} | TRASA: lokalny->Railway (kontrolna)")
    log(f"# metryki: railway_tcp, {', '.join(PG_METRICS)}, github | alert >= {ALERT_STREAK} FAIL/rzad na metryce PG")
    log(f"# (Ctrl+C aby przerwac. Kazdy pomiar widoczny ponizej na zywo.)")

    i = 0
    try:
        while True:
            i += 1
            rtok, rtms, errno = tcp(RHOST, RPORT, TMO)
            pg = pg_probe(TMO)
            ghok, ghms, _ = tcp('api.github.com', 443, TMO)

            def fmt(name):
                ok, ms = pg[name]
                return f"{name} {'OK' if ok else 'FAIL':<4} {ms:6.0f}ms"

            log(f"#{i:05d} {utc_now()} UTC | {loc_now()} WAW | "
                f"railway_tcp {'OK' if rtok else 'FAIL':<4} {rtms:6.0f}ms | "
                f"{fmt('pg_select1')} | {fmt('pg_settings')} | {fmt('pg_chiptree')} | {fmt('pg_upsert')} | "
                f"github {'OK' if ghok else 'FAIL':<4} {ghms:6.0f}ms | errno={errno}")

            handle_alert(pg)

            if i % HEARTBEAT_EVERY == 0:
                log(f"# alive {i:05d} {utc_now()} UTC | alert_active={alert_active} ok_streak={ok_streak}")
                cleanup_probe()  # okresowe czyszczenie klucza probe

            time.sleep(INTERVAL)
    except KeyboardInterrupt:
        log(f"# STOP (Ctrl+C) po {i} pomiarach, {datetime.datetime.now().strftime('%H:%M:%S')}")
        cleanup_probe()


if __name__ == '__main__':
    main()
