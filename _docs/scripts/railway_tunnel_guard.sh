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
#
# CHAT-T-196 — alert mailowy (logika naprawcza bez zmian, alert ocenia WYNIK przebiegu):
#   przebieg zdrowy = sonda OK albo restart w tym przebiegu zakonczony OK; kazdy inny = nie-OK.
#   ALARM    nie-OK nieprzerwanie >= ALERT_AFTER_S od pierwszego nie-OK (jeden na epizod),
#            przypomnienie najwczesniej co ALERT_REPEAT_S
#   POWROT   pierwszy zdrowy przebieg po wyslanym ALARM, z czasem trwania epizodu; niedoreczony
#            POWROT ponawiany w kolejnych zdrowych przebiegach do skutku (epizod zerowany po doreczeniu)
#   limit    ALERT_MAX_PER_DAY maili straznika na dobe kalendarzowa (liczy kazde wywolanie sendmaila)
#   Stan: ALERT_STATE, jedna linia "pierwszy_nieOK ostatnia_proba alarm_wyslany dzien licznik".
#   Adres: DIVECHAT_COST_ALERT_EMAIL z .env czatu (jak embeddings/watchdog.sh), brak = wpis w logu.
#   Blad wysylki = wpis w logu, straznik konczy sie normalnie.
#   Test bez naprawy i bez sondy: ALERT_TEST_PROBE=<wynik> (OK = zdrowy, inny = nie-OK),
#   z osobnym ALERT_STATE i ALERT_SUBJECT_TAG; tunel produkcyjny nie jest wtedy ruszany.
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

# --- alert mailowy (CHAT-T-196), nadpisywalne zmienna srodowiskowa ---
ALERT_AFTER_S=${ALERT_AFTER_S:-300}          # nie-OK nieprzerwanie tyle sekund -> ALARM
ALERT_REPEAT_S=${ALERT_REPEAT_S:-21600}      # przypomnienie w trwajacym epizodzie najwczesniej co 6 h
ALERT_MAX_PER_DAY=${ALERT_MAX_PER_DAY:-6}    # twardy limit wszystkich maili straznika na dobe
ALERT_STATE=${ALERT_STATE:-$DIAG/railway_tunnel_alert.state}
ALERT_ENV_FILE=${ALERT_ENV_FILE:-/home/divezone/public_html/chat.divezone.pl/.env}
ALERT_SENDMAIL=${ALERT_SENDMAIL:-/usr/sbin/sendmail}
ALERT_HEALTH_URL=${ALERT_HEALTH_URL:-https://chat.divezone.pl/api/health}
ALERT_SUBJECT_TAG=${ALERT_SUBJECT_TAG:-}     # np. "[TEST CHAT-T-196]"
ALERT_TEST_PROBE=${ALERT_TEST_PROBE:-}       # tryb testowy: wynik zamiast sondy, bez naprawy
GUARD_LOG="$DIAG/railway_tunnel_guard.log"   # cel przekierowania z crona
CURL=/usr/bin/curl
RESTART_RESULT=""  # wynik restart() w biezacym przebiegu: OK|CEL|START_FAIL

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
            echo "OK $(date +%s)" > "$STATEFILE"; RESTART_RESULT=OK ;;
        CISZA|EOF)
            log "$reason -> restart: ssh zestawiony pid=${newpid:-?}, ale CEL nie odpowiada przez swiezy tunel (sonda=$r po ${secs}s) -> problem po stronie s81->Railway, nie lacza smarthost->s81. ssh: $(tail -1 "$SSH_OUT" 2>/dev/null)"
            echo "CEL $(date +%s)" > "$STATEFILE"; RESTART_RESULT="CEL:$r" ;;
        *)
            log "$reason -> restart NIEUDANY (sonda=$r po ${secs}s) pid=${newpid:-brak}. ssh: $(tail -1 "$SSH_OUT" 2>/dev/null)"
            echo "START_FAIL $(date +%s)" > "$STATEFILE"; RESTART_RESULT="START_FAIL:$r" ;;
    esac
}

# ---------------- alert mailowy (CHAT-T-196) ----------------
# Stan alertu w zmiennych A_*: pierwszy nie-OK (0 = zdrowo), ostatnia proba wysylki ALARM w epizodzie
# (przy POWROT oczekujacym: czas powrotu), alarm_wyslany (0 nie, 1 tak, 2 tak i POWROT oczekuje
# na doreczenie), dzien licznika (RRRR-MM-DD), licznik maili w tym dniu.
alert_load() {
    A_FIRST=0; A_TRY=0; A_SENT=0; A_DAY=""; A_CNT=0; A_LOADED=""
    local line f t s d c
    [ -f "$ALERT_STATE" ] || return 0
    line=$(head -1 "$ALERT_STATE" 2>/dev/null)
    read -r f t s d c <<< "$line"
    case "$f$t$s$c" in
        ''|*[!0-9]*) log "alert: stan $ALERT_STATE nieczytelny ('$line') -> zeruje epizod${ALERT_SUBJECT_TAG:+ $ALERT_SUBJECT_TAG}"; return 0 ;;
    esac
    A_FIRST=$f; A_TRY=$t; A_SENT=$s; A_DAY=$d; A_CNT=$c
    A_LOADED="$A_FIRST $A_TRY $A_SENT $A_DAY $A_CNT"
}

alert_save() {
    # bez zmian (zdrowy przebieg co minute) = bez zapisu
    [ "$A_FIRST $A_TRY $A_SENT $A_DAY $A_CNT" = "${A_LOADED:-}" ] && return 0
    if ! { printf '%s %s %s %s %s\n' "$A_FIRST" "$A_TRY" "$A_SENT" "$A_DAY" "$A_CNT" > "$ALERT_STATE.tmp" \
           && mv -f "$ALERT_STATE.tmp" "$ALERT_STATE"; } 2>/dev/null; then
        log "alert: BLAD zapisu stanu $ALERT_STATE${ALERT_SUBJECT_TAG:+ $ALERT_SUBJECT_TAG}"
    fi
}

# Adres z .env czatu (wzorzec embeddings/watchdog.sh): bez cudzyslowow, CR i spacji brzegowych.
alert_to_addr() {
    [ -r "$ALERT_ENV_FILE" ] || return 0
    grep -E '^DIVECHAT_COST_ALERT_EMAIL=' "$ALERT_ENV_FILE" 2>/dev/null | head -n1 \
        | cut -d= -f2- | tr -d '"'"'"'\r' | awk '{$1=$1; print}'
}

# Wynik /api/health widziany ze smarthost (jedna linia, max 300 znakow).
alert_health() {
    "$TIMEOUT" -k 5 15 "$CURL" -sS -m 10 -w ' [HTTP %{http_code}]' "$ALERT_HEALTH_URL" 2>&1 9>&- | tr '\n' ' ' | cut -c1-300
}

# Tresc wspolna: stan zdrowia, ssh, ostatnie wpisy straznika. Bez wartosci z .env.
alert_context() {
    echo "/api/health ze smarthost: $(alert_health)"
    echo "Ostatnia linia $SSH_OUT: $(tail -1 "$SSH_OUT" 2>/dev/null)"
    echo ""
    echo "Ostatnie 10 wpisow $GUARD_LOG:"
    tail -10 "$GUARD_LOG" 2>/dev/null
    echo ""
    echo "Trasa: $LISTEN_HOST:$LISTEN_PORT (smarthost) -> ${REMOTE#*@} -> $TARGET (ADR-141, CHAT-T-195)."
    echo "Monitor railway_monitor.php mierzy trase BEZPOSREDNIA, nie tunel (_docs/44)."
}

# alert_send <rodzaj> <temat> <tresc>. Zwraca 0 = sendmail rc 0, 1 = blad wysylki, 2 = pominiety.
alert_send() {
    local kind=$1 subject=$2 body=$3 to rc host
    to=$(alert_to_addr)
    if [ -z "$to" ]; then
        log "alert: BRAK DIVECHAT_COST_ALERT_EMAIL w .env, $kind pominiety: $subject"; return 2
    fi
    if [ ! -x "$ALERT_SENDMAIL" ]; then
        log "alert: brak $ALERT_SENDMAIL, $kind pominiety: $subject"; return 2
    fi
    if [ "$A_CNT" -ge "$ALERT_MAX_PER_DAY" ]; then
        log "alert: limit $ALERT_MAX_PER_DAY maili/dobe ($A_DAY) wyczerpany, $kind pominiety: $subject"; return 2
    fi
    host=$(/usr/bin/hostname 2>/dev/null || echo unknown)
    A_CNT=$((A_CNT + 1))
    {
        echo "To: $to"
        echo "From: DiveChat tunnel guard <noreply@chat.divezone.pl>"
        echo "Subject: [DiveChat] ${subject} (${host})${ALERT_SUBJECT_TAG:+ $ALERT_SUBJECT_TAG}"
        echo "Content-Type: text/plain; charset=utf-8"
        echo ""
        echo "Host: $host"
        echo "$body"
    } | "$TIMEOUT" -k 5 30 "$ALERT_SENDMAIL" -t -oi 9>&-   # 9>&-: sendmail i jego potomkowie bez blokady flock
    rc=$?
    if [ "$rc" -eq 0 ]; then
        log "alert: $kind wyslany do adresu z .env, sendmail rc=0, mail $A_CNT/$ALERT_MAX_PER_DAY dnia $A_DAY: $subject${ALERT_SUBJECT_TAG:+ $ALERT_SUBJECT_TAG}"
        return 0
    fi
    log "alert: BLAD wysylki $kind, sendmail rc=$rc, mail $A_CNT/$ALERT_MAX_PER_DAY dnia $A_DAY: $subject${ALERT_SUBJECT_TAG:+ $ALERT_SUBJECT_TAG}"
    return 1
}

# alert_check <sonda> <wynik_przebiegu>. Zdrowy przebieg = wynik OK.
alert_check() {
    local probe_r=$1 outcome=$2 t today since mins kind subject body
    t=$(date +%s); today=$(date +%F)
    alert_load
    if [ "$A_DAY" != "$today" ]; then A_DAY=$today; A_CNT=0; fi

    if [ "$outcome" = OK ]; then
        if [ "$A_SENT" = 1 ] || [ "$A_SENT" = 2 ]; then
            # pierwszy zdrowy przebieg po ALARM: czas powrotu do A_TRY, POWROT oczekuje (A_SENT=2)
            if [ "$A_SENT" = 1 ]; then A_SENT=2; A_TRY=$t; fi
            mins=$(( (A_TRY - A_FIRST + 59) / 60 ))
            body=$(
                echo "Tunel do Railway znowu odpowiada (sonda=$probe_r, wynik=$outcome)."
                echo "Awaria trwala ok. $mins min: od $(date -d "@$A_FIRST" '+%F %T') do $(date -d "@$A_TRY" '+%F %T')."
                [ "$A_TRY" != "$t" ] && echo "POWROT ponowiony $(date -d "@$t" '+%F %T'), poprzednia proba nie doszla."
                echo ""
                alert_context
            )
            if ! alert_send POWROT "tunel Railway WROCIL po ${mins} min" "$body"; then
                alert_save; return 0   # niedoreczony: ponowienie w kolejnym zdrowym przebiegu
            fi
        fi
        A_FIRST=0; A_TRY=0; A_SENT=0
        alert_save
        return 0
    fi

    [ "$A_FIRST" -eq 0 ] && A_FIRST=$t
    # ponowna awaria przy oczekujacym POWROT: epizod trwa dalej, ALARM byl doreczony
    [ "$A_SENT" = 2 ] && A_SENT=1
    since=$((t - A_FIRST))
    if [ "$A_TRY" -eq 0 ]; then
        [ "$since" -ge "$ALERT_AFTER_S" ] || { alert_save; return 0; }
        kind=ALARM
    elif [ "$A_SENT" = 1 ]; then
        [ $((t - A_TRY)) -ge "$ALERT_REPEAT_S" ] || { alert_save; return 0; }
        kind=PRZYPOMNIENIE
    else
        # ALARM jeszcze nie doszedl (blad wysylki, brak adresu, limit): ponowienie co ALERT_AFTER_S
        [ $((t - A_TRY)) -ge "$ALERT_AFTER_S" ] || { alert_save; return 0; }
        kind=ALARM
    fi
    mins=$(( since / 60 ))
    if [ "$kind" = ALARM ]; then subject="tunel Railway NIE DZIALA od ${mins} min"
    else subject="tunel Railway NADAL NIE DZIALA od ${mins} min"; fi
    body=$(
        echo "Czat czyta baze Railway przez tunel (DATABASE_URL = $LISTEN_HOST:$LISTEN_PORT). Tunel nie odpowiada."
        echo "Stan w tym przebiegu: sonda=$probe_r, wynik=$outcome"
        echo "Rozstrzyga pole wynik (stan PO naprawie w tym przebiegu). Pole sonda to stan PRZED naprawa."
        echo "  wynik=START_FAIL:*        ssh do s81 nie wstal po restarcie (lacze smarthost->s81, klucz, s81)."
        echo "  wynik=CEL:EOF / CEL:CISZA ssh zestawiony, Railway nie odpowiada z s81 (strona s81->Railway)."
        echo "  wynik=BEZ_RESTARTU        ssh zyje, straznik czeka CEL_RETRY_S po uznanym CEL (sonda EOF/CISZA)"
        echo "                            albo sonda dala nieoczekiwany bajt (INNY)."
        echo "Pierwszy nie-OK: $(date -d "@$A_FIRST" '+%F %T') (${mins} min temu, prog ${ALERT_AFTER_S} s)."
        echo "Kolejny mail: POWROT po odtworzeniu albo przypomnienie najwczesniej za $((ALERT_REPEAT_S / 3600)) h. Limit ${ALERT_MAX_PER_DAY} maili na dobe."
        echo ""
        alert_context
    )
    A_TRY=$t
    if alert_send "$kind" "$subject" "$body"; then A_SENT=1; fi
    alert_save
}

# Tryb testowy alertu (CHAT-T-196): bez sondy i bez naprawy, tunel produkcyjny nietkniety.
# Wymaga osobnego ALERT_STATE, zeby test nie mieszal w epizodzie produkcyjnym.
if [ -n "$ALERT_TEST_PROBE" ]; then
    if [ "$ALERT_STATE" = "$DIAG/railway_tunnel_alert.state" ]; then
        log "alert: ALERT_TEST_PROBE wymaga osobnego ALERT_STATE, test przerwany"
        exit 0
    fi
    if [ "$ALERT_TEST_PROBE" = OK ]; then alert_check OK OK; else alert_check "$ALERT_TEST_PROBE" TEST; fi
    exit 0
fi

now=$(date +%s)
pid=$(tunnel_pid)
state=$(cut -d' ' -f1 "$STATEFILE" 2>/dev/null); state=${state:-NONE}
state_ts=$(cut -d' ' -f2 "$STATEFILE" 2>/dev/null); state_ts=${state_ts:-0}

if [ -z "$pid" ]; then
    restart "proces ssh martwy (brak pid albo cudzy pid w pidfile)"
    alert_check BRAK_PID "$RESTART_RESULT"
    exit 0
fi

r=$(probe)
case "$r" in
    OK)
        [ "$state" != OK ] && log "sonda OK pid=$pid (poprzedni stan $state)"
        [ "$state" != OK ] && echo "OK $now" > "$STATEFILE"
        alert_check OK OK
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
alert_check "$r" "${RESTART_RESULT:-BEZ_RESTARTU}"
exit 0
