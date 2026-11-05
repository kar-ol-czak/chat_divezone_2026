#!/bin/bash
# CHAT-T-195 (ADR-141) — cron-guard tunelu SSH smarthost -> s81.mydevil.net -> Railway.
#   127.0.0.1:14368 (smarthost) --ssh--> s81.mydevil.net --tcp--> switchback.proxy.rlwy.net:14368
# Tymczasowe obejscie blokady IP Railway na smarthost (od 2026-11-04). Wyjscie: ADR-141 "Wyjscie".
#
# Kryterium zdrowia NIE jest "proces ssh zyje", tylko ODPOWIEDZ POSTGRESA przez tunel:
# sonda wysyla SSLRequest (8 bajtow) na 127.0.0.1:14368 i czeka na 1 bajt ('N' albo 'S').
# Postgres nie loguje sondy zakonczonej EOF po SSLRequest (traktuje ja jak skaner portow).
# Wyniki sondy i reakcja:
#   OK         bajt 'N'/'S'                      -> nic nie rob
#   ODMOWA     connect refused na 127.0.0.1      -> ssh padl albo zgubil nasluch -> restart
#   EOF        polaczenie zamkniete bez bajtu    -> zwykle ssh i lacze ZYJA, a s81 odrzucil kanal
#                                                   (cel odmowil / permitopen) -> jeden restart probny;
#                                                   jesli swiezy tunel tez daje EOF, to cel -> wpis CEL,
#                                                   kolejne restarty najwyzej co CEL_RETRY_S
#   CISZA      brak bajtu w PROBE_S              -> zamarle lacze ALBO cel milczy -> restart;
#                                                   jesli swiezy tunel tez milczy, to cel -> wpis CEL,
#                                                   kolejne restarty najwyzej co CEL_RETRY_S
# Martwe lacze bez ruchu wykrywa sam ssh (ServerAliveInterval*ServerAliveCountMax = 30 s) i konczy
# proces, a guard w nastepnej minucie widzi ODMOWA.
# Cron co 1 min:
#   * * * * * /home/divezone/_diag/railway_tunnel_guard.sh >> /home/divezone/_diag/railway_tunnel_guard.log 2>&1
set -u
# Binarki pelna sciezka: pod cronem PATH=/usr/bin:/bin (lekcja CHAT-T-186/190, _docs/44 PULAPKI).
SSH=/usr/bin/ssh
FLOCK=/usr/bin/flock
TIMEOUT=/usr/bin/timeout

DIAG=/home/divezone/_diag
KEY=/home/divezone/.ssh/id_ed25519_railway_tunnel
KNOWN_HOSTS=/home/divezone/.ssh/known_hosts
REMOTE=karlsenconsultng@s81.mydevil.net
LISTEN_HOST=127.0.0.1          # WYLACZNIE loopback, nigdy 0.0.0.0
LISTEN_PORT=14368
TARGET=switchback.proxy.rlwy.net:14368   # musi byc identyczne z permitopen w authorized_keys na s81
PIDFILE="$DIAG/railway_tunnel.pid"
STATEFILE="$DIAG/railway_tunnel.state"
LOCKFILE="$DIAG/railway_tunnel_guard.lock"
SSH_OUT="$DIAG/railway_tunnel_ssh.out"
PROBE_S=5          # czas na odpowiedz Postgresa przez tunel (RTT smarthost->Railway przez s81 ~0,1 s)
START_WAIT_S=20    # ile czekamy po starcie ssh na pierwsza poprawna sonde
CEL_RETRY_S=600    # przy uznanym CEL milczacym: restart tunelu najwyzej co 10 min

log() { echo "[tunnel-guard] $(date '+%Y-%m-%d %H:%M:%S') $*"; }

# --- jedna instancja naraz (cron co minute, restart trwa do START_WAIT_S) ---
exec 9>"$LOCKFILE"
if ! "$FLOCK" -n 9; then
    exit 0
fi

# Sonda: echo OK|ODMOWA|EOF|CISZA|INNY:<bajt>
probe() {
    "$TIMEOUT" $((PROBE_S + 3)) bash -c '
        exec 3<>/dev/tcp/'"$LISTEN_HOST"'/'"$LISTEN_PORT"' 2>/dev/null || { echo ODMOWA; exit 0; }
        printf "\x00\x00\x00\x08\x04\xd2\x16\x2f" >&3 2>/dev/null
        IFS= read -r -t '"$PROBE_S"' -N 1 -u 3 b; rc=$?
        exec 3<&- 3>&-
        if [ $rc -gt 128 ]; then echo CISZA
        elif [ $rc -ne 0 ] || [ -z "$b" ]; then echo EOF
        elif [ "$b" = N ] || [ "$b" = S ]; then echo OK
        else echo "INNY:$b"; fi
    ' 2>/dev/null || echo CISZA
}

# PID tunelu: z pidfile, sprawdzony po /proc/<pid>/cmdline (nie pgrep: _docs/44, self-match).
tunnel_pid() {
    local p
    p=$(cat "$PIDFILE" 2>/dev/null)
    if [ -n "$p" ] && { tr '\0' ' ' < "/proc/$p/cmdline"; } 2>/dev/null | grep -q -- "$KEY"; then
        echo "$p"
    fi
}

# Wszystkie procesy ssh z kluczem tunelu (sieroty po zgubionym pidfile), bez wlasnego procesu.
all_tunnel_pids() {
    local d p
    for d in /proc/[0-9]*; do
        p=${d#/proc/}
        [ "$p" = "$$" ] && continue
        # { } 2>/dev/null: proces moze zniknac miedzy globem a odczytem (blad przekierowania idzie do powloki)
        { tr '\0' ' ' < "$d/cmdline"; } 2>/dev/null | grep -q -- "^$SSH .*-i $KEY " && echo "$p"
    done
}

stop_tunnel() {
    local p
    for p in $(all_tunnel_pids); do kill -TERM "$p" 2>/dev/null; done
    sleep 2
    for p in $(all_tunnel_pids); do kill -9 "$p" 2>/dev/null; done
    rm -f "$PIDFILE"
}

start_tunnel() {
    # -N bez komendy zdalnej, -T bez pty, -n stdin z /dev/null. setsid: ssh poza grupa procesow crona.
    echo "--- $(date '+%Y-%m-%d %H:%M:%S') start" >> "$SSH_OUT"
    setsid "$SSH" -N -T -n \
        -i "$KEY" \
        -o IdentitiesOnly=yes \
        -o BatchMode=yes \
        -o StrictHostKeyChecking=yes \
        -o UserKnownHostsFile="$KNOWN_HOSTS" \
        -o ExitOnForwardFailure=yes \
        -o ServerAliveInterval=10 \
        -o ServerAliveCountMax=2 \
        -o ConnectTimeout=15 \
        -o ControlMaster=no \
        -o ControlPath=none \
        -L "$LISTEN_HOST:$LISTEN_PORT:$TARGET" \
        "$REMOTE" >> "$SSH_OUT" 2>&1 < /dev/null 9>&- &   # 9>&-: ssh nie dziedziczy blokady flock
    echo $! > "$PIDFILE"
}

# Czeka na pierwsza sonde rozna od ODMOWA (nasluch wstal). Echo: wynik_sondy sekundy
wait_ready() {
    local i r
    for i in $(seq 1 "$START_WAIT_S"); do
        sleep 1
        if [ -z "$(tunnel_pid)" ]; then echo "SSH_PADL $i"; return; fi
        r=$(probe)
        if [ "$r" != ODMOWA ]; then echo "$r $i"; return; fi
    done
    echo "ODMOWA $START_WAIT_S"
}

restart() {
    local reason=$1 old res r secs newpid
    old=$(tunnel_pid)
    stop_tunnel
    start_tunnel
    res=$(wait_ready); r=${res% *}; secs=${res##* }
    newpid=$(tunnel_pid)
    case "$r" in
        OK)
            log "$reason -> restart OK po ${secs}s pid=${newpid:-?} (stary pid=${old:--})"
            echo "OK $(date +%s)" > "$STATEFILE" ;;
        CISZA|EOF)
            log "$reason -> restart: ssh zestawiony pid=${newpid:-?}, ale CEL nie odpowiada przez swiezy tunel (sonda=$r po ${secs}s) -> problem po stronie s81->Railway, nie lacza smarthost->s81. ssh: $(tail -1 "$SSH_OUT" 2>/dev/null)"
            echo "CEL $(date +%s)" > "$STATEFILE" ;;
        *)
            log "$reason -> restart NIEUDANY (sonda=$r po ${secs}s) pid=${newpid:-brak}. ssh: $(tail -1 "$SSH_OUT" 2>/dev/null)"
            echo "START_FAIL $(date +%s)" > "$STATEFILE" ;;
    esac
}

now=$(date +%s)
pid=$(tunnel_pid)
state=$(cut -d' ' -f1 "$STATEFILE" 2>/dev/null); state=${state:-NONE}
state_ts=$(cut -d' ' -f2 "$STATEFILE" 2>/dev/null); state_ts=${state_ts:-0}

if [ -z "$pid" ]; then
    restart "proces ssh martwy (brak pid albo cudzy pid w pidfile)"
    exit 0
fi

r=$(probe)
case "$r" in
    OK)
        [ "$state" != OK ] && log "sonda OK pid=$pid (poprzedni stan $state)"
        [ "$state" != OK ] && echo "OK $now" > "$STATEFILE"
        exit 0 ;;
    ODMOWA)
        restart "nasluch $LISTEN_HOST:$LISTEN_PORT nie przyjmuje TCP, ssh zyl pid=$pid" ;;
    EOF)
        # Zwykle kanal odrzucony przez s81 (ssh i lacze zyja). Jeden restart probny rozstrzyga,
        # czy to cel; przy uznanym CEL kolejne restarty najwyzej co CEL_RETRY_S.
        if [ "$state" = CEL ] && [ $((now - state_ts)) -lt "$CEL_RETRY_S" ]; then
            log "CEL ODRZUCA: ssh zyje pid=$pid, s81 zamknal kanal do $TARGET (Railway odmawia z s81 albo permitopen), swiezy tunel tez $((now - state_ts))s temu. Bez restartu. ssh: $(tail -1 "$SSH_OUT" 2>/dev/null)"
        else
            restart "EOF: kanal do $TARGET zamkniety bez odpowiedzi, ssh zyl pid=$pid -> restart probny"
        fi ;;
    CISZA)
        if [ "$state" = CEL ] && [ $((now - state_ts)) -lt "$CEL_RETRY_S" ]; then
            log "CEL MILCZY: sonda bez odpowiedzi ${PROBE_S}s pid=$pid, swiezy tunel tez milczal $((now - state_ts))s temu. Bez restartu (kolejny za $((CEL_RETRY_S - now + state_ts))s)."
        else
            restart "ZAMARCIE albo CEL MILCZY: sonda bez odpowiedzi ${PROBE_S}s, ssh zyl pid=$pid"
        fi ;;
    *)
        log "sonda: nieoczekiwana odpowiedz $r pid=$pid. Bez restartu." ;;
esac
exit 0
