═══ CHAT-T-196 · INTEGRATION · DEPLOYED ═══

# CHAT-T-196: alert mailowy strażnika tunelu Railway i zamknięcie rozmowy testowej T-195

**Instancja:** INTEGRATION. **Data:** 2026-11-05. **Karta:** Chat - 150. **Decyzje Karola:** 3a (zlecenie), 5a (trzy poprawki przed wdrożeniem), 6b (verdict NULL), „deployuj” 15:51.
**Zlecenie:** `_instances/integration/tasks/CHAT-T-196_INTEGRATION_alert-straznika-tunelu.md`. **Kontekst:** ADR-141, CHAT-T-195, recenzja Codex T-195 uwaga 2.
**Commity:** `81ef7a8` fix (strażnik, recenzja Codex, harness i wyniki testów), `449629b` docs (STATUS 4.37, CLAUDE.md), commit docs z tym raportem.
**Czasy:** CET (serwer), o ile nie zaznaczono UTC.

---

## WERDYKT

Strażnik tunelu wysyła maila, gdy tunel nie wraca. Na produkcji `~/_diag/railway_tunnel_guard.sh` ma md5 `51ba5429b36a98fe7dfc9514fda49b58`, równe lokalnemu, prawa `-rwxr-xr-x`.
Przebieg crona po wdrożeniu (15:56:01) był czysty: tunel OK, brak maila, brak nowych linii w logu.
Test realny dał dokładnie 2 maile `[TEST CHAT-T-196]` (ALARM 15:56:15, POWROT 15:56:19), oba `sendmail rc=0`, bez ruszania tunelu produkcyjnego.
Rozmowa 535 (conv 1709) jest zamknięta: `zamkniety`, verdict NULL, marker w `note`.
Odbioru maili w skrzynce nie widziałem. Potwierdza go Karol.

## 1. KROK 0

- Repo czatu, `main`, `git rev-list --count HEAD..origin/main` = 0 na starcie i przed wdrożeniem.
- Cudze zmiany w drzewie (`railway_monitor_guard.sh` tryb pliku, `purge_litespeed.php`, `routes.php`) nietknięte i niezacommitowane.
- Przeczytane: zlecenie, ADR-141, raport T-195, `railway_tunnel_guard.sh`, `embeddings/watchdog.sh`, `_docs/42` §5, `_docs/44` PUŁAPKI (cronowy PATH, `rsync -a` i prawa, `pgrep -f`).

## 2. Zmiana strażnika

Plik `_docs/scripts/railway_tunnel_guard.sh` → `~/_diag/railway_tunnel_guard.sh`. Logika naprawcza bez zmian. W `restart()` trzy istniejące wpisy do `STATEFILE` dostały dopisek `RESTART_RESULT=…`. Reszta to nowe linie: stałe alertu, funkcje `alert_*`, tryb testowy, cztery wywołania `alert_check` przed wyjściami. Linia crona bez zmian.

| element | zachowanie |
|---|---|
| przebieg zdrowy | sonda `OK` albo restart w tym przebiegu zakończony `OK`. Każdy inny przebieg to nie-OK |
| ALARM | nie-OK nieprzerwanie co najmniej `ALERT_AFTER_S` (300 s) od pierwszego nie-OK, jeden na epizod |
| PRZYPOMNIENIE | najwcześniej co `ALERT_REPEAT_S` (21600 s) w trwającym epizodzie |
| POWROT | pierwszy zdrowy przebieg po doręczonym ALARM, czas trwania od pierwszego nie-OK do pierwszego zdrowego przebiegu. Niedoręczony jest ponawiany w kolejnych zdrowych przebiegach, a epizod zeruje się dopiero po doręczeniu (decyzja 5a, Codex 1) |
| ALARM niedoręczony | błąd sendmaila, brak adresu albo limit: ponowienie co `ALERT_AFTER_S` |
| limit | `ALERT_MAX_PER_DAY` (6) wywołań sendmaila na dobę kalendarzową, dla wszystkich rodzajów maili |
| stan | `~/_diag/railway_tunnel_alert.state`, jedna linia `pierwszy_nieOK ostatnia_proba alarm_wyslany dzien licznik` (`alarm_wyslany`: 0, 1, 2 = POWROT oczekuje). Zapis przez plik tymczasowy i `mv`, bez zapisu, gdy nic się nie zmieniło |
| adres | `DIVECHAT_COST_ALERT_EMAIL` z `.env` czatu, parsowany jak w `watchdog.sh`. Log mówi „do adresu z .env”, bez adresu |
| treść | stan sondy i wynik przebiegu, legenda „rozstrzyga pole wynik” (decyzja 5a, Codex 11), pierwszy nie-OK, `/api/health` ze smarthost, ostatnia linia `railway_tunnel_ssh.out`, ostatnie 10 wpisów `railway_tunnel_guard.log`, trasa |
| procesy | sendmail pod `timeout -k 5 30`, curl pod `timeout -k 5 15` (decyzja 5a, Codex 4), oba z `9>&-`. Błąd wysyłki daje wpis w logu, strażnik kończy się kodem 0 |
| tryb testowy | `ALERT_TEST_PROBE=<wynik>` pomija sondę i naprawę. Odmawia przy domyślnym `ALERT_STATE` |

Parametry nadpisywalne zmienną środowiskową: `ALERT_AFTER_S`, `ALERT_REPEAT_S`, `ALERT_MAX_PER_DAY`, `ALERT_STATE`, `ALERT_ENV_FILE`, `ALERT_SENDMAIL`, `ALERT_HEALTH_URL`, `ALERT_SUBJECT_TAG`, `ALERT_TEST_PROBE`.

`bash -n` OK. shellcheck `-S warning` czysty, jedyna uwaga `info` (SC2016 w sondzie) istniała w HEAD przed zmianą.

## 3. Testy (bash 4.4.20 serwera, `env -i PATH=/usr/bin:/bin`, katalog `/dev/shm/t196`)

Harness i wyniki są w `_diag_local/t196/` (`run_tests.sh`, `wiring.sh`, `emu.py`, `fake_sendmail`, `fake_sendmail_hang`, `A.out`, `B.out`).
Testowana była dokładna kopia artefaktu: md5 kopii na serwerze `51ba5429…` == repo.
Atrapa sendmaila zapisuje mail do pliku. `.env` atrapa ma adres `alert-test@example.invalid` i kanarek `TAJNE_NIE_WOLNO` w innych kluczach. Upływ czasu symulowany wpisem do pliku stanu testu.

**A. Logika alertu, tryb `ALERT_TEST_PROBE` jako atrapa sondy: 50/50 PASS** (bieg końcowy po poprawkach 5a).

| # | przypadek | wynik |
|---|---|---|
| T1 | krótka awaria (< próg), potem OK | 0 maili, epizod od pierwszego nie-OK, wyzerowany, bez POWROT |
| T2 | pierwszy nie-OK 301 s temu | 1 ALARM. Temat z hostem i tagiem, stan sondy, `/api/health` `postgres:true` HTTP 200, 10 wpisów, linia `ssh.out`, `-t -oi`, adres z `.env`, legenda „Rozstrzyga pole wynik”. Kanarka brak w mailu i logu, adresu brak w logu |
| T3 | awaria trwa (CISZA, EOF, BRAK_PID) | brak duplikatu |
| T4 | 6 h po ALARM | 1 PRZYPOMNIENIE, następny przebieg bez maila |
| T5 | powrót | 1 POWROT z czasem trwania, kolejny OK bez maila, licznik dnia zachowany |
| T6 | limit 6/6 | ALARM pominięty z wpisem, następna minuta bez wpisu, bez POWROT po niedoręczonym ALARM |
| T7 | nowa doba | licznik wyzerowany, ALARM wysłany |
| T8 | sendmail rc=75 | wpis `BLAD wysylki`, strażnik rc=0, ponowienie po 300 s doszło |
| T9 | brak klucza w `.env` | wpis, bez maila |
| T10 | tryb testowy z domyślnym stanem | odmowa |
| T11 | stan nieczytelny | wpis, nowy epizod |
| T12 | `ALERT_AFTER_S=0` | ALARM, potem POWROT |
| **T13** | **POWROT niedoręczony (rc=75) → ponowiony w następnym przebiegu → doręczony** | pierwsza próba: wpis `BLAD wysylki POWROT, sendmail rc=75`, epizod niewyzerowany (`alarm_wyslany=2`). Druga: `POWROT wyslany … mail 3/6`, czas powrotu z pierwszego zdrowego przebiegu, adnotacja „POWROT ponowiony”, epizod wyzerowany, kolejny OK bez maila |
| T14 | POWROT przy limicie 6/6 | pominięty, oczekuje, po zmianie doby doręczony |
| T15 | POWROT oczekuje, tunel znów pada | epizod trwa od pierwotnego pierwszego nie-OK, bez nowego ALARM przed `ALERT_REPEAT_S`, następny powrót doręcza POWROT |
| T16 | sendmail ignoruje TERM | `timeout -k 5 30` kończy go KILL-em po 35 s, wpis `sendmail rc=137`, strażnik rc=0, atrapa nie żyje |

**B. Wpięcie w realny przebieg: 6/6 PASS.** Kopia strażnika różni się od artefaktu 4 stałymi (`diff` = 4 linie): `DIAG`, `KEY`, `LISTEN_PORT=24368`, `SSH=/bin/false`. Atrapą tunelu był emulator PG na `127.0.0.1:24368`.

| # | przebieg | wynik |
|---|---|---|
| W1 | sonda OK | brak wpisu i maila |
| W2 | EOF → restart nieudany | `restart NIEUDANY (sonda=SSH_PADL)`, potem ALARM `sonda=EOF, wynik=START_FAIL:SSH_PADL`. Emulator przeżył `stop_tunnel` |
| W3 | OK po ALARM | POWROT |
| W4 | brak pid | epizod startuje, bez maila przed progiem |
| W5 | `CEL MILCZY` | ALARM z `wynik=BEZ_RESTARTU`, nowa legenda w treści |

**Błędy harnessu (żaden nie dotyczył strażnika):**
- Pierwszy bieg przed STOP: 33/35. `EXTRA` trafiało do powłoki jako komenda (rc=127). Poprawka harnessu, potem 35/35.
- Bieg po poprawkach 5a: 49/50. T13e oczekiwało `10 min`, strażnik podał `11 min`. Stan testu: pierwszy nie-OK `1793889793`, powrót `1793890395`, czyli 602 s. Strażnik zaokrągla w górę, więc 11 min jest poprawne. Harness zakładał równe 600 s. Oczekiwanie liczone teraz ze stanu, potem pełny ponowny bieg A 50/50 i B 6/6.

Produkcja w czasie testów: port 24368 wolny przed i po teście, tunel `127.0.0.1:14368` słuchał cały czas, md5 strażnika prod i brak pliku stanu sprawdzone przed wdrożeniem.

## 4. Wdrożenie (KROK 5)

| krok | wynik |
|---|---|
| 5a kopia | `~/public_html/chat.divezone.pl/_deploy_bak/CHAT-T-196_20261105-155528/railway_tunnel_guard.sh.bak`, `cmp` OK, md5 `1259ea20890f95449ecce3e876ac0e4a` |
| 5b wgranie | scp do `railway_tunnel_guard.sh.new`, `chmod 755`, `mv` o 15:55:35 (sekunda 35, poza przebiegiem crona). `-rwxr-xr-x 17874`, md5 prod `51ba5429b36a98fe7dfc9514fda49b58` == lokalne, `bash -n` OK |
| 5c cron | przebieg 15:56:01 utworzył stan `0 0 0 2026-11-05 0`. Log bez nowych linii (67), brak maila, `/api/health` `postgres:true`. Przebieg 15:57 nie przepisał stanu (mtime 15:56:01) |
| 5d test realny | patrz niżej |
| 5e UPDATE | patrz sekcja 5 |
| 5f Sentinel | nie dotyczy: strażnik to skrypt bash w `~/_diag/`, poza drzewami `chat.divezone.pl` i `prod`, żaden plik PHP nie został wgrany |
| 5g sprzątanie | `/dev/shm/t196` usunięty (tmpfs, artefakty testu). Wyniki skopiowane do repo. Emulator nie żyje, port 24368 wolny. Plik stanu testu przeniesiony `mv` do katalogu kopii |

**5d.** Dwa ręczne przebiegi z `ALERT_STATE=~/_diag/railway_tunnel_alert.test.state`, `ALERT_SUBJECT_TAG='[TEST CHAT-T-196]'`, pod `env -i PATH=/usr/bin:/bin`, wyjście do logu strażnika. Najpierw `ALERT_TEST_PROBE=CISZA ALERT_AFTER_S=0`, potem `ALERT_TEST_PROBE=OK`. Oba rc=0. Wpisy w `railway_tunnel_guard.log`:
```
[tunnel-guard] 2026-11-05 15:56:15 alert: ALARM wyslany do adresu z .env, sendmail rc=0, mail 1/6 dnia 2026-11-05: tunel Railway NIE DZIALA od 0 min [TEST CHAT-T-196]
[tunnel-guard] 2026-11-05 15:56:19 alert: POWROT wyslany do adresu z .env, sendmail rc=0, mail 2/6 dnia 2026-11-05: tunel Railway WROCIL po 1 min [TEST CHAT-T-196]
```
- Stan testu po ALARM `1793890575 1793890575 1 2026-11-05 1`, po POWROT `0 0 0 2026-11-05 2`.
- Stan produkcyjny bez zmian (`0 0 0 2026-11-05 0`, mtime bez zmian).
- Stan testu przeniesiony do `_deploy_bak/CHAT-T-196_20261105-155528/railway_tunnel_alert.test.state`.
- `/var/log/exim_mainlog` jest dla konta `divezone` nieczytelny (`mailnull:mail 640`), więc doręczenia przez MTA nie potwierdziłem.

## 5. Rozmowa 535 (KROK 2 i 5e)

**KROK 2, odczyt read-only przez `Config::load` aplikacji, baza `railway`:**
- `status IN ('nowy','do_weryfikacji','w_trakcie','zamkniety')`.
- `verdict IS NULL OR verdict IN ('ok','problem_do_rozwiazania','problem_rozwiazany')`.
- Tabela bez triggerów. Wiersz 535 przed zmianą: `do_weryfikacji`, verdict NULL, note `[test CHAT-T-195, nie klient]`, `updated_at 2026-11-05 13:13:19.493242+00`.

**5e.** Verdict NULL zgodnie z decyzją 6b, a nie `ok` z propozycji STOP. Wykonane przez PDO aplikacji w transakcji, COMMIT przy dokładnie 1 wierszu (skrypt `_diag_local/t196/k5e_update.php`):
```sql
UPDATE divechat_conversation_review
   SET status='zamkniety', verdict=NULL, updated_by=NULL,
       note = coalesce(note,'') || ' [zamkniete przez Claude, CHAT-T-196, 2026-11-05: rozmowa testowa T-195]',
       updated_at = now()
 WHERE id=535 AND conversation_id=1709 AND status='do_weryfikacji'
RETURNING id, conversation_id, status, verdict, note, updated_by, updated_at;
```
Wynik: `COMMIT, wierszy 1`.
```
RETURNING: {"id":535,"conversation_id":1709,"status":"zamkniety","verdict":null,"note":"[test CHAT-T-195, nie klient] [zamkniete przez Claude, CHAT-T-196, 2026-11-05: rozmowa testowa T-195]","updated_by":null,"updated_at":"2026-11-05 14:57:29.579631+00"}
```
Odczyt po COMMIT jest identyczny.

## 6. Recenzja Codex

Plik `_docs/reviews/CODEX_REVIEW_20261105_chat-t-196-alert.md` (gpt-5.6-sol, reasoning xhigh, read-only, przed poprawkami 5a).

Przyjęte decyzją 5a i wdrożone:
- **1 (WYSOKA)** POWROT niedoręczony przepadał. Teraz ponawiany do skutku (T13, T14, T15).
- **4 (WYSOKA)** `timeout` bez `-k`. Teraz `-k 5` przy sendmailu i curl (T16).
- **11 (ŚREDNIA)** legenda mogła brzmieć sprzecznie. Teraz mówi wprost, że rozstrzyga pole `wynik`.

**NIEROZSTRZYGNIĘTE (decyzja 5a, bez zmian w kodzie):**
- **2 (KRYTYCZNA wg Codex)** `ALERT_TEST_PROBE` w środowisku crona wyłączyłby naprawę. Dziś crontab ma 0 wystąpień `ALERT_` (odczyt filtrowany). Moim zdaniem waga zawyżona, wymaga zmiany crontabu.
- **3 (WYSOKA)** odmowa domyślnego stanu w trybie testowym porównuje tekst ścieżki (alias albo symlink ją omija).
- **5 (WYSOKA)** błąd zapisu stanu psuje próg, deduplikację i limit (daje tylko wpis w logu). Nietestowane.
- **6 (ŚREDNIA)** semantyka „co najmniej raz”: kill między sendmailem a zapisem stanu daje duplikat.
- **7 (ŚREDNIA)** test ma osobny licznik dobowy, niezależny od produkcyjnego.
- **8 (ŚREDNIA)** brak walidacji parametrów env, nowa linia w `ALERT_SUBJECT_TAG` wstrzyknęłaby nagłówek.
- **9 (ŚREDNIA)** health, `ssh.out` i log idą do maila bez redakcji, a stderr sendmaila przy błędzie MTA może wpisać adres do logu.
- **10 (NISKA)** fd 9 dziedziczą krótkie procesy potoku.
- **12 (NISKA)** epizod liczony od końca przebiegu z restartem, różnica do ok. 22 s.

## 7. Uwagi i skutki do wiadomości

- **POWROT przy wyczerpanym limicie.** Ponawianie do skutku przy limicie 6/6 daje wpis `limit … POWROT pominiety` co minutę aż do zmiany doby (T14). Przy błędzie sendmaila każda próba zużywa limit, więc po 6 próbach skutek jest ten sam. Log nie ma rotacji (Codex T-195 uwaga 11, poza zakresem).
- **Czas w temacie POWROT** jest zaokrąglany w górę do pełnej minuty (602 s = 11 min).
- **Stara linia stderr w mailu.** Pierwsze maile pokażą wśród „ostatnich 10 wpisów” linie `linia 81: /proc/…` z błędu naprawionego w T-195, dopóki nie wypchną ich nowe wpisy.
- Plik `_reports/CHAT-T-196_STOP.md` (STOP przed decyzjami) zostaje niezacommitowany, zgodnie z poleceniem „bez commita”. Jego propozycje `verdict='ok'` i md5 `aa48d4ff…` są nieaktualne.

## 8. Czego NIE sprawdziłem

- Doręczenia maili do skrzynki i wpisów MTA (`exim_mainlog` nieczytelny). Dowodem jest `sendmail rc=0`.
- Prawdziwej awarii tunelu z alertem: test realny szedł trybem testowym, bez sondy i naprawy (wymóg „bez zabijania tunelu”).
- Zachowania przy niezapisywalnym pliku stanu (Codex 5).
- Przebiegu `ODMOWA → restart OK` z alertem na kopii. Restart udany ćwiczyły wcześniej testy T-195, alert przy wyniku `OK` ćwiczą T5, T12 i W3.
- Czy panel PS pokazuje rozmowę 535 poza kolejką. Stan w bazie potwierdzony odczytem po COMMIT, ekranu panelu nie oglądałem.

raport: _reports/CHAT-T-196_alert-straznika-tunelu.md
═══ CHAT-T-196 · INTEGRATION · DEPLOYED ═══
