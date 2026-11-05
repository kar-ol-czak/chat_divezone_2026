#!/bin/bash
# Testy alertu CHAT-T-196. Uruchamiac: env -i PATH=/usr/bin:/bin bash /dev/shm/t196/run_tests.sh
set -u
D=/dev/shm/t196
G=$D/guard.sh                         # dokladna kopia artefaktu z repo
ST=$D/alert.state
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "PASS $*"; }
bad()  { FAIL=$((FAIL+1)); echo "FAIL $*"; }
mails(){ cat $D/mail_count 2>/dev/null || echo 0; }
reset(){ rm -f $D/mail_* $ST $D/out.log; echo 0 > $D/fake_rc; }
# przebieg trybu testowego (atrapa sondy = ALERT_TEST_PROBE), ponowienie gdy flock zajety przez crona prod
run() {
    local i o
    for i in 1 2 3 4 5; do
        o=$(ALERT_TEST_PROBE=$1 ALERT_STATE=$ST ALERT_ENV_FILE=$D/fake.env ALERT_SENDMAIL=$D/fake_sendmail \
            ALERT_HEALTH_URL=https://chat.divezone.pl/api/health ALERT_SUBJECT_TAG="[TEST CHAT-T-196]" \
            env ${EXTRA:-} bash $G 2>&1; echo "rc=$?")
        if [ "$o" != "rc=0" ] || [ "${QUIET_OK:-0}" = 1 ]; then break; fi
        sleep 1
    done
    echo "$o" >> $D/out.log; echo "$o"
}
setst(){ printf '%s\n' "$*" > $ST; }
now=$(date +%s); today=$(date +%F)

echo "== T1 krotka awaria (< prog): 2 przebiegi nie-OK, potem OK -> 0 maili"
reset; QUIET_OK=1
run CISZA >/dev/null; run ODMOWA >/dev/null
[ "$(mails)" = 0 ] && ok "T1a brak maila w trakcie" || bad "T1a maile=$(mails)"
read -r f t s d c < $ST; [ "$f" -gt 0 ] && [ "$t" = 0 ] && ok "T1b epizod liczony od pierwszego nie-OK ($f), bez proby" || bad "T1b stan: $(cat $ST)"
run OK >/dev/null
[ "$(mails)" = 0 ] && ok "T1c powrot bez ALARM -> brak POWROT" || bad "T1c maile=$(mails)"
read -r f t s d c < $ST; [ "$f $t $s" = "0 0 0" ] && ok "T1d epizod wyzerowany" || bad "T1d stan: $(cat $ST)"

echo "== T2 dluga awaria: pierwszy nie-OK 301 s temu -> 1 ALARM"
reset; setst "$((now-301)) 0 0 $today 0"
o=$(run CEL:CISZA)
[ "$(mails)" = 1 ] && ok "T2a jeden mail" || bad "T2a maile=$(mails)"
grep -q '^Subject: \[DiveChat\] tunel Railway NIE DZIALA od 5 min (.*) \[TEST CHAT-T-196\]$' $D/mail_1.txt && ok "T2b temat ALARM z hostem i tagiem" || bad "T2b $(grep Subject $D/mail_1.txt)"
grep -q 'sonda=CEL:CISZA, wynik=TEST' $D/mail_1.txt && ok "T2c stan sondy w tresci" || bad T2c
grep -q '^/api/health ze smarthost: {.*"postgres":true.*\[HTTP 200\]' $D/mail_1.txt && ok "T2d wynik /api/health w tresci" || bad "T2d $(grep api/health $D/mail_1.txt)"
[ "$(sed -n '/^Ostatnie 10 wpisow/,/^$/p' $D/mail_1.txt | sed '1d;$d' | wc -l)" = 10 ] && ok "T2e 10 wpisow straznika" || bad T2e
grep -q '^Ostatnia linia .*railway_tunnel_ssh.out' $D/mail_1.txt && ok "T2f linia ssh.out" || bad T2f
grep -q 'ARGS: -t -oi' $D/mail_1.txt && grep -q '^To: alert-test@example.invalid$' $D/mail_1.txt && ok "T2g sendmail -t -oi, adres z .env" || bad T2g
grep -q 'TAJNE_NIE_WOLNO' $D/mail_1.txt $D/out.log && bad "T2h wartosc z .env w mailu/logu" || ok "T2h zadna inna wartosc z .env w mailu ani logu"
grep -q 'alert-test@example.invalid' $D/out.log && bad "T2i adres w logu" || ok "T2i log bez adresu"
echo "$o" | grep -q 'alert: ALARM wyslany do adresu z .env, sendmail rc=0, mail 1/6' && ok "T2j wpis w logu" || bad "T2j $o"
grep -q '^Rozstrzyga pole wynik (stan PO naprawie w tym przebiegu). Pole sonda to stan PRZED naprawa.$' $D/mail_1.txt && ok "T2l legenda: rozstrzyga pole wynik" || bad T2l
read -r f t s d c < $ST; [ "$s" = 1 ] && [ "$c" = 1 ] && ok "T2k stan: wyslany, licznik 1" || bad "T2k $(cat $ST)"

echo "== T3 awaria trwa dalej (kolejne przebiegi < ALERT_REPEAT_S) -> brak duplikatu"
QUIET_OK=1; run CISZA >/dev/null; run EOF >/dev/null; run BRAK_PID >/dev/null
[ "$(mails)" = 1 ] && ok "T3 nadal 1 mail" || bad "T3 maile=$(mails)"

echo "== T4 przypomnienie po ALERT_REPEAT_S"
read -r f t s d c < $ST; setst "$f $((now-21601)) 1 $d $c"
run CISZA >/dev/null
[ "$(mails)" = 2 ] && grep -q 'NADAL NIE DZIALA' $D/mail_2.txt && ok "T4a jedno przypomnienie" || bad "T4a maile=$(mails)"
run CISZA >/dev/null
[ "$(mails)" = 2 ] && ok "T4b bez drugiego przypomnienia" || bad "T4b maile=$(mails)"

echo "== T5 powrot -> 1 POWROT z czasem trwania"
o=$(run OK)
[ "$(mails)" = 3 ] && ok "T5a jeden POWROT" || bad "T5a maile=$(mails)"
grep -q '^Subject: \[DiveChat\] tunel Railway WROCIL po 6 min' $D/mail_3.txt && ok "T5b temat z czasem trwania" || bad "T5b $(grep Subject $D/mail_3.txt)"
grep -q 'Awaria trwala ok. 6 min: od ' $D/mail_3.txt && ok "T5c czas w tresci" || bad T5c
run OK >/dev/null
[ "$(mails)" = 3 ] && ok "T5d kolejny OK bez maila" || bad "T5d maile=$(mails)"
read -r f t s d c < $ST; [ "$f $t $s $c" = "0 0 0 3" ] && ok "T5e epizod wyzerowany, licznik dnia zachowany (3)" || bad "T5e $(cat $ST)"

echo "== T6 limit dobowy: licznik 6/6 -> ALARM pominiety, wpis w logu, bez spamu co minute"
reset; setst "$((now-400)) 0 0 $today 6"
o=$(run CISZA)
[ "$(mails)" = 0 ] && ok "T6a brak maila" || bad "T6a maile=$(mails)"
echo "$o" | grep -q 'limit 6 maili/dobe .* wyczerpany, ALARM pominiety' && ok "T6b wpis o limicie" || bad "T6b $o"
o=$(QUIET_OK=1 run CISZA)
[ "$o" = "rc=0" ] && ok "T6c kolejny przebieg bez wpisu (ponowienie co ALERT_AFTER_S)" || bad "T6c $o"
run OK >/dev/null; [ "$(mails)" = 0 ] && ok "T6d powrot po niewyslanym ALARM -> bez POWROT" || bad "T6d maile=$(mails)"

echo "== T7 nowa doba zeruje licznik"
reset; setst "$((now-400)) 0 0 2026-01-01 6"
run CISZA >/dev/null
read -r f t s d c < $ST; [ "$(mails)" = 1 ] && [ "$d $c" = "$today 1" ] && ok "T7 nowa doba, mail wyslany, licznik 1" || bad "T7 $(cat $ST) maile=$(mails)"

echo "== T8 blad wysylki (sendmail rc=75): wpis w logu, rc straznika 0, ponowienie po ALERT_AFTER_S"
reset; echo 75 > $D/fake_rc; setst "$((now-400)) 0 0 $today 0"
o=$(run CISZA)
echo "$o" | grep -q 'BLAD wysylki ALARM, sendmail rc=75' && echo "$o" | grep -q 'rc=0$' && ok "T8a wpis bledu, straznik rc=0" || bad "T8a $o"
read -r f t s d c < $ST; [ "$s" = 0 ] && ok "T8b ALARM nieuznany za wyslany" || bad "T8b $(cat $ST)"
setst "$f $((now-301)) 0 $d $c"; echo 0 > $D/fake_rc
run CISZA >/dev/null
read -r f t s d c < $ST; [ "$(mails)" = 2 ] && [ "$s" = 1 ] && ok "T8c ponowienie po 300 s doszlo" || bad "T8c $(cat $ST)"

echo "== T9 brak adresu w .env -> wpis, bez maila"
reset; setst "$((now-400)) 0 0 $today 0"
o=$(EXTRA="ALERT_ENV_FILE=$D/empty.env" run CISZA)
echo "$o" | grep -q 'BRAK DIVECHAT_COST_ALERT_EMAIL w .env, ALARM pominiety' && [ "$(mails)" = 0 ] && ok "T9" || bad "T9 $o"

echo "== T10 tryb testowy z domyslnym ALERT_STATE odmawia"
o=$(ALERT_TEST_PROBE=CISZA bash $G 2>&1)
echo "$o" | grep -q 'wymaga osobnego ALERT_STATE, test przerwany' && ok "T10" || bad "T10 '$o'"

echo "== T11 stan nieczytelny -> wpis i nowy epizod"
reset; setst "smieci x y"
o=$(run CISZA)
echo "$o" | grep -q 'nieczytelny' && read -r f t s d c < $ST && [ "$f" -gt 0 ] && ok "T11" || bad "T11 $o / $(cat $ST)"

echo "== T12 ALERT_AFTER_S=0 (jak test realny KROK 5d): pierwszy nie-OK -> ALARM, potem OK -> POWROT"
reset
o=$(EXTRA="ALERT_AFTER_S=0" run CISZA); o2=$(run OK)
[ "$(mails)" = 2 ] && grep -q 'NIE DZIALA od 0 min' $D/mail_1.txt && grep -q 'WROCIL po' $D/mail_2.txt && ok "T12" || bad "T12 maile=$(mails)"

echo "== T13 POWROT niedoreczony (sendmail rc=75) -> ponowiony w nastepnym przebiegu -> doreczony"
reset; setst "$((now-600)) $((now-300)) 1 $today 1"
echo 75 > $D/fake_rc
o=$(run OK)
echo "$o" | grep -q 'BLAD wysylki POWROT, sendmail rc=75' && ok "T13a pierwsza proba POWROT nieudana, wpis w logu" || bad "T13a $o"
read -r f t s d c < $ST; [ "$f" -gt 0 ] && [ "$s" = 2 ] && ok "T13b epizod NIE wyzerowany, POWROT oczekuje (stan $f $t $s $d $c)" || bad "T13b $(cat $ST)"
rt=$t; f13=$f; sleep 2; echo 0 > $D/fake_rc
o=$(run OK)
echo "$o" | grep -q 'alert: POWROT wyslany do adresu z .env, sendmail rc=0, mail 3/6' && ok "T13c POWROT ponowiony i doreczony" || bad "T13c $o"
grep -q "do $(date -d "@$rt" '+%F %T')\." $D/mail_2.txt && grep -q 'POWROT ponowiony .*, poprzednia proba nie doszla' $D/mail_2.txt && ok "T13d czas powrotu z pierwszego zdrowego przebiegu, adnotacja o ponowieniu" || bad "T13d $(sed -n '/Awaria/,/ponowiony/p' $D/mail_2.txt)"
exp=$(( (rt - f13 + 59) / 60 ))   # zaokraglenie w gore jak w strazniku, od pierwszego nie-OK do pierwszego zdrowego przebiegu
grep -q "WROCIL po ${exp} min" $D/mail_2.txt && [ "$exp" -ge 10 ] && [ "$exp" -le 11 ] && ok "T13e czas trwania ${exp} min ($((rt - f13)) s, od pierwszego nie-OK do powrotu)" || bad "T13e exp=$exp $(grep Subject $D/mail_2.txt)"
read -r f t s d c < $ST; [ "$f $t $s" = "0 0 0" ] && ok "T13f epizod wyzerowany po doreczeniu" || bad "T13f $(cat $ST)"
o=$(QUIET_OK=1 run OK); [ "$o" = "rc=0" ] && [ "$(mails)" = 2 ] && ok "T13g kolejny OK bez maila" || bad "T13g $o"

echo "== T14 POWROT przy wyczerpanym limicie -> oczekuje -> nowa doba -> doreczony"
reset; setst "$((now-600)) $((now-300)) 1 $today 6"
o=$(run OK)
echo "$o" | grep -q 'limit 6 maili/dobe .* wyczerpany, POWROT pominiety' && [ "$(mails)" = 0 ] && ok "T14a POWROT pominiety przez limit" || bad "T14a $o"
read -r f t s d c < $ST; [ "$s" = 2 ] && ok "T14b POWROT oczekuje" || bad "T14b $(cat $ST)"
setst "$f $t 2 2026-01-01 6"
o=$(run OK)
echo "$o" | grep -q 'POWROT wyslany .* mail 1/6' && ok "T14c nowa doba: POWROT doreczony" || bad "T14c $o"

echo "== T15 POWROT oczekuje, a tunel znow pada -> epizod trwa, bez nowego ALARM przed ALERT_REPEAT_S"
reset; setst "$((now-600)) $((now-300)) 1 $today 1"; echo 75 > $D/fake_rc
run OK >/dev/null; echo 0 > $D/fake_rc; n0=$(mails)
o=$(QUIET_OK=1 run CISZA)
read -r f t s d c < $ST; [ "$s" = 1 ] && [ "$f" = "$((now-600))" ] && [ "$(mails)" = "$n0" ] && ok "T15a stan wraca do ALARM doreczony, bez maila" || bad "T15a $(cat $ST) $o"
o=$(run OK)
echo "$o" | grep -q 'POWROT wyslany' && ok "T15b kolejny powrot -> POWROT doreczony" || bad "T15b $o"

echo "== T16 sendmail ignoruje TERM -> timeout -k 5 30 konczy go KILL-em, straznik rc=0"
reset; setst "$((now-400)) 0 0 $today 0"
t0=$(date +%s)
o=$(EXTRA="ALERT_SENDMAIL=$D/fake_sendmail_hang" run CISZA)
el=$(( $(date +%s) - t0 ))
echo "$o" | grep -q 'BLAD wysylki ALARM, sendmail rc=137' && echo "$o" | grep -q 'rc=0$' && [ "$el" -ge 34 ] && [ "$el" -le 45 ] && ok "T16 rc=137 po ${el}s, straznik rc=0" || bad "T16 el=${el}s $o"
pgrep -u "$(id -u)" -f 'fake_sendmail_hang' >/dev/null && bad "T16b atrapa zyje po KILL" || ok "T16b atrapa nie zyje"

echo "== WYNIK: PASS=$PASS FAIL=$FAIL"
