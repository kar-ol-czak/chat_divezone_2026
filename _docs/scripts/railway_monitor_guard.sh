#!/bin/bash
# CHAT-T-108 — cron-guard: pilnuje, ze railway_monitor.php (trasa serwer->Railway) dziala CIAGLE.
# Jak proces padl (restart serwera, OOM, crash) -> startuje go ponownie pod nohup.
# Idempotentny: jak dziala, nic nie robi. Cron co 1 min:
#   * * * * * /home/divezone/_diag/railway_monitor_guard.sh >> /home/divezone/_diag/guard.log 2>&1
set -u
DIAG=/home/divezone/_diag
PHP=$(command -v ea-php84 || command -v php)
SCRIPT="$DIAG/railway_monitor.php"
PIDFILE="$DIAG/railway_monitor.pid"
OUT="$DIAG/monitor_nohup.out"

# Czy zywy? (pidfile + realny proces)
if [ -f "$PIDFILE" ]; then
    PID=$(cat "$PIDFILE" 2>/dev/null)
    if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then
        exit 0   # dziala — nic nie rob
    fi
fi

# Dodatkowy bezpiecznik: nie odpalaj drugiej instancji, jak juz lata gdzies bez pidfile
if pgrep -f "railway_monitor.php" >/dev/null 2>&1; then
    exit 0
fi

# Padl -> restart
nohup "$PHP" "$SCRIPT" >> "$OUT" 2>&1 &
echo $! > "$PIDFILE"
echo "[guard] $(date '+%Y-%m-%d %H:%M:%S') restart monitora, pid=$(cat "$PIDFILE")"
