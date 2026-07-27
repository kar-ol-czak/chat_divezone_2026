#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Nocny monitor lacza Mac(lokalny) -> Railway (READ-ONLY, diagnostyka incydentu 2026-06-28).
Blizniak serwerowego railway_monitor.php — ten sam interwal, ten sam format logu,
zeby porownac trase Mac->Railway vs serwer->Railway linia w linie.
NIE modyfikuje bazy: tylko TCP connect + PG SELECT 1 + github TCP (kontrola lacza).
Log: _diag_local/railway_monitor_local_YYYYMMDD.log (dopisywany).
Czyta DATABASE_URL z .env projektu (nie hardkoduje sekretu w skrypcie).
"""
import os, sys, time, socket, datetime
from urllib.parse import urlparse, parse_qs

BASE = os.path.dirname(os.path.abspath(__file__))
PROJ = os.path.dirname(BASE)
ENV_PATH = os.path.join(PROJ, '.env')

def load_dburl(path):
    with open(path, encoding='utf-8') as f:
        for line in f:
            line = line.strip()
            if line.startswith('DATABASE_URL='):
                v = line.split('=', 1)[1].strip()
                if (v.startswith('"') and v.endswith('"')) or (v.startswith("'") and v.endswith("'")):
                    v = v[1:-1]
                return v
    raise SystemExit('Brak DATABASE_URL w .env')

DBURL = load_dburl(ENV_PATH)
p = urlparse(DBURL)
q = parse_qs(p.query)
RHOST = p.hostname or 'switchback.proxy.rlwy.net'
RPORT = p.port or 14368
SSL = (q.get('sslmode', ['disable'])[0])
DBNAME = (p.path or '/railway').lstrip('/')
DBUSER = p.username or ''
DBPASS = p.password or ''
INTERVAL = 25
TMO = 8

today = datetime.datetime.now().strftime('%Y%m%d')
LOG = os.path.join(BASE, f'railway_monitor_local_{today}.log')

def tcp(host, port, tmo):
    t = time.time()
    try:
        s = socket.create_connection((host, port), timeout=tmo)
        s.close()
        return True, (time.time()-t)*1000, 0
    except Exception as e:
        return False, (time.time()-t)*1000, getattr(e, 'errno', -1) or -1

def pg_select1(tmo):
    t = time.time()
    try:
        import psycopg2
        conn = psycopg2.connect(host=RHOST, port=RPORT, dbname=DBNAME,
                                user=DBUSER, password=DBPASS, sslmode=SSL,
                                connect_timeout=tmo)
        cur = conn.cursor(); cur.execute('SELECT 1'); cur.fetchone()
        cur.close(); conn.close()
        return True, (time.time()-t)*1000
    except Exception:
        return False, (time.time()-t)*1000

def utc_now():
    return datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%d %H:%M:%S')

def waw_now():
    # Mac ma strefe lokalna; zakladamy Europe/Warsaw na maszynie Karola
    return datetime.datetime.now().strftime('%H:%M:%S')

# okno: do ~07:15 lokalnie (jak serwerowy: mail@07:00 + stop 07:15)
now = datetime.datetime.now()
stop_at = now.replace(hour=7, minute=15, second=0, microsecond=0)
if stop_at <= now:
    stop_at += datetime.timedelta(days=1)

with open(LOG, 'a', encoding='utf-8') as f:
    f.write(f"# START {now.strftime('%Y-%m-%d %H:%M:%S')} LOCAL | stop@ {stop_at.strftime('%Y-%m-%d %H:%M')} | "
            f"interval {INTERVAL}s | host {RHOST}:{RPORT} | TRASA: Mac->Railway\n")

while True:
    if datetime.datetime.now() >= stop_at:
        break
    rtok, rtms, errno = tcp(RHOST, RPORT, TMO)
    pgok, pgms = pg_select1(TMO)
    ghok, ghms, _ = tcp('api.github.com', 443, TMO)
    line = (f"{utc_now()} UTC | {waw_now()} WAW | "
            f"railway_tcp {'OK' if rtok else 'FAIL':<4} {rtms:6.0f}ms | "
            f"railway_pg {'OK' if pgok else 'FAIL':<4} {pgms:6.0f}ms | "
            f"github {'OK' if ghok else 'FAIL':<4} {ghms:6.0f}ms | errno={errno}\n")
    with open(LOG, 'a', encoding='utf-8') as f:
        f.write(line)
    time.sleep(INTERVAL)
