#!/bin/bash
# Test wpiecia alertu w glowny przebieg (CHAT-T-196). Kopia straznika z podmienionymi 4 stalymi.
set -u
D=/dev/shm/t196; W=$D/diag; G=$D/guard_wiring.sh; PORT=24368
PASS=0; FAIL=0
ok(){ PASS=$((PASS+1)); echo "PASS $*"; }; bad(){ FAIL=$((FAIL+1)); echo "FAIL $*"; }
mkdir -p $W; rm -f $W/* $D/mail_*; echo 0 > $D/fake_rc
sed -e 's|^DIAG=/home/divezone/_diag$|DIAG=/dev/shm/t196/diag|' \
    -e 's|^KEY=/home/divezone/.ssh/id_ed25519_railway_tunnel$|KEY=/dev/shm/t196/fake_key|' \
    -e 's|^LISTEN_PORT=14368$|LISTEN_PORT=24368|' \
    -e 's|^SSH=/usr/bin/ssh$|SSH=/bin/false|' $D/guard.sh > $G
echo "diff kopii (oczekiwane 4 linie):"; diff $D/guard.sh $G | grep -c '^>'
for i in 1 2 3 4 5 6 7 8 9 10; do echo "[tunnel-guard] wpis testowy $i" >> $W/railway_tunnel_guard.log; done
echo N > $W/mode
/usr/bin/python3.12 $D/emu.py $PORT $W/mode /dev/shm/t196/fake_key & EMU=$!
sleep 1
g(){ env ALERT_ENV_FILE=$D/fake.env ALERT_SENDMAIL=$D/fake_sendmail ALERT_SUBJECT_TAG="[TEST CHAT-T-196]" "$@" bash $G 2>&1; }
mails(){ ls $D/mail_[0-9]*.txt 2>/dev/null | wc -l; }

echo "== W1 sonda OK przez emulator -> cisza, brak maila"
echo $EMU > $W/railway_tunnel.pid; echo "OK $(date +%s)" > $W/railway_tunnel.state
o=$(g); [ -z "$o" ] && [ "$(mails)" = 0 ] && ok "W1 brak wpisu i maila, stan: $(cat $W/railway_tunnel_alert.state)" || bad "W1 '$o'"

echo "== W2 sonda EOF -> restart (ssh=/bin/false) NIEUDANY -> alert z wynikiem restartu (ALERT_AFTER_S=0)"
echo EOF > $W/mode
o=$(g ALERT_AFTER_S=0); echo "$o"
echo "$o" | grep -q 'restart NIEUDANY' && echo "$o" | grep -q 'alert: ALARM wyslany' && grep -q 'sonda=EOF, wynik=START_FAIL:SSH_PADL' $D/mail_1.txt && ok "W2" || bad W2
kill -0 $EMU 2>/dev/null && ok "W2b emulator nietkniety przez stop_tunnel" || bad W2b

echo "== W3 powrot: sonda OK -> POWROT"
echo N > $W/mode; echo $EMU > $W/railway_tunnel.pid
o=$(g); echo "$o"
echo "$o" | grep -q 'sonda OK pid=' && echo "$o" | grep -q 'alert: POWROT wyslany' && [ "$(mails)" = 2 ] && ok "W3" || bad "W3 maile=$(mails)"

echo "== W4 brak pid -> restart nieudany -> epizod startuje, przy progu 300 s brak maila"
rm -f $W/railway_tunnel.pid
o=$(g); echo "$o"
read -r f t s d c < $W/railway_tunnel_alert.state
echo "$o" | grep -q 'proces ssh martwy' && [ "$f" -gt 0 ] && [ "$t" = 0 ] && [ "$(mails)" = 2 ] && ok "W4" || bad "W4 $(cat $W/railway_tunnel_alert.state)"

echo "== W5 CEL MILCZY bez restartu -> wynik BEZ_RESTARTU, po progu ALARM"
echo CISZA > $W/mode; echo $EMU > $W/railway_tunnel.pid; echo "CEL $(date +%s)" > $W/railway_tunnel.state
printf '%s 0 0 %s %s\n' $(( $(date +%s) - 301 )) "$d" "$c" > $W/railway_tunnel_alert.state
o=$(g); echo "$o"
echo "$o" | grep -q 'CEL MILCZY' && grep -q 'sonda=CISZA, wynik=BEZ_RESTARTU' $D/mail_3.txt && ok "W5" || bad W5

kill $EMU; wait $EMU 2>/dev/null
echo "== WYNIK WIRING: PASS=$PASS FAIL=$FAIL"
