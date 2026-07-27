#!/bin/bash
# CHAT-T-108/109 — cron-guard: pilnuje, ze railway_monitor.php (trasa serwer->Railway) dziala CIAGLE.
# Wskrzesza monitor w DWOCH przypadkach:
#   1) proces padl/zniknal (restart serwera, OOM, crash)
#   2) CICHY ZGON: proces ZYJE, ale ZAMARL na wiszacym polaczeniu i nie loguje (incydent 29-06,
#      monitor 2340898 wisial 46 min w ps, log stal). Wykrywane po SWIEZOSCI logu (mtime > 60s).
# Idempotentny: proces zyje I log swiezy (<60s) -> nic nie rob.
# Cron co 1 min:
#   * * * * * /home/divezone/_diag/railway_monitor_guard.sh >> /home/divezone/_diag/guard.log 2>&1
set -u
DIAG=/home/divezone/_diag
PHP=$(command -v ea-php84 || command -v php)
SCRIPT="$DIAG/railway_monitor.php"
PIDFILE="$DIAG/railway_monitor.pid"
OUT="$DIAG/monitor_nohup.out"
STALE_S=60   # log starszy niz to = ZAMARCIE (interwal 5s -> 60s = 12 brakujacych cykli, pewny sygnal)

now=$(date +%s)

# --- swiezosc logu: najnowszy plik po MTIME (odporne na granice polnocy: jak monitor nie
#     wszedl w nowy dzien, najnowszy log to wczorajszy i jego mtime bedzie stary) ---
newest_log=$(ls -1t "$DIAG"/railway_monitor_*.log 2>/dev/null | head -1)
if [ -n "$newest_log" ]; then
    mtime=$(stat -c %Y "$newest_log" 2>/dev/null || echo 0)
    age=$(( now - mtime ))
else
    age=999999   # brak jakiegokolwiek logu = traktuj jak zamarcie/brak startu
fi

# --- czy proces zyje? (pidfile lub pgrep) ---
proc_alive=0
if [ -f "$PIDFILE" ]; then
    PID=$(cat "$PIDFILE" 2>/dev/null)
    if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then proc_alive=1; fi
fi
if [ "$proc_alive" -eq 0 ] && pgrep -f "railway_monitor\.php" >/dev/null 2>&1; then
    proc_alive=1
fi

# --- ZDROWY: proces zyje I log swiezy -> nic nie rob ---
if [ "$proc_alive" -eq 1 ] && [ "$age" -lt "$STALE_S" ]; then
    exit 0
fi

# --- NIEZDROWY: albo proces padl, albo ZAMARL (log stary mimo zywego procesu) ---
if [ "$proc_alive" -eq 1 ]; then
    reason="ZAMARCIE wykryte (log stary ${age}s, proces zyl) -> kill -9 + restart"
    # ubij zawieszony proces (kill -9 dziala tez na wiszacym/zatrzymanym; SIGKILL nieblokowalny)
    pkill -9 -f "railway_monitor\.php" 2>/dev/null
    sleep 1
else
    reason="proces martwy (log ${age}s) -> restart"
fi
rm -f "$PIDFILE"

# wskrzeszenie. nohup w kontekscie cron daje $!=pid php (nohup exec'uje komende), pidfile spojny.
nohup "$PHP" "$SCRIPT" >> "$OUT" 2>&1 &
echo $! > "$PIDFILE"
echo "[guard] $(date '+%Y-%m-%d %H:%M:%S') $reason pid=$(cat "$PIDFILE")"
