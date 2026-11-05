═══ CHAT-T-195 · REVIEW · STOP ═══

Werdykt: przed wdrożeniem należy zamknąć co najmniej ustalenia 1–4.

## Ustalenia

1. **[KRYTYCZNY] Klucz tunelowy nadal pozwala uruchamiać polecenia na s81.**

   Planowany wpis zawiera `restrict,port-forwarding,permitopen=...`, ale nie ma `command="/usr/bin/false"` ([task:31](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_instances/integration/tasks/CHAT-T-195_INTEGRATION_tunel-railway-przez-mydevil.md:31)), mimo że ADR deklaruje klucz „bez powłoki” ([ADR-141:4684](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/10_decyzje_projektowe.md:4684)). `restrict` wyłącza forwarding, PTY, agenta, X11 i user-rc, ale nie blokuje wykonywania poleceń; `port-forwarding` ponownie włącza forwarding. Ponadto `permitopen` ogranicza `-L`, natomiast `-R` ogranicza osobne `permitlisten` ([oficjalny manual OpenSSH](https://man.openbsd.org/sshd.8#AUTHORIZED_KEYS_FILE_FORMAT)). Bezhasłowy klucz po kradzieży daje więc większy zakres niż zakładano.

2. **[WYSOKI] Brakuje ciągłego alertowania o awarii rzeczywistej trasy produkcyjnej.**

   Monitor zostaje celowo przepięty na `DATABASE_URL_DIRECT` ([railway_monitor.php:81](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/scripts/railway_monitor.php:81)), natomiast guard tunelu w stanach `EOF`, `CISZA` i nieoczekiwanej odpowiedzi jedynie zapisuje lokalny log/state ([railway_tunnel_guard.sh:161](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/scripts/railway_tunnel_guard.sh:161), [railway_tunnel_guard.sh:165](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/scripts/railway_tunnel_guard.sh:165), [railway_tunnel_guard.sh:171](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/scripts/railway_tunnel_guard.sh:171)). Plan przewiduje tylko jednorazowe testy `/api/health` i odtworzenia tunelu ([task:52](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_instances/integration/tasks/CHAT-T-195_INTEGRATION_tunel-railway-przez-mydevil.md:52), [task:54](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_instances/integration/tasks/CHAT-T-195_INTEGRATION_tunel-railway-przez-mydevil.md:54)). Po późniejszej awarii tunelu monitor bezpośredni może nadal pozostawać w tym samym, już znanym stanie FAIL, więc nie powstanie nowy sygnał o niedostępności czatu.

3. **[WYSOKI] Sonda guarda nie dowodzi, że właściwa baza jest używalna.**

   Guard wysyła wyłącznie preautoryzacyjny PostgreSQL SSLRequest i uznaje każdy bajt `N` lub `S` za `OK` ([railway_tunnel_guard.sh:52](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/scripts/railway_tunnel_guard.sh:52), [railway_tunnel_guard.sh:59](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/scripts/railway_tunnel_guard.sh:59)). Nie wykryje złych credentials, złej bazy, braku uprawnień ani endpointu wskazującego inny PostgreSQL. Dla porównania `/api/health` wykonuje uwierzytelnione `SELECT 1` ([PostgresConnection.php:408](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/standalone/src/Database/PostgresConnection.php:408)). Guard może więc raportować `OK`, gdy backend nadal nie działa.

4. **[WYSOKI] Nieweryfikowane dziedziczenie blokady `flock` może wyłączyć kolejne przebiegi guarda.**

   Skrypt otwiera i blokuje fd 9 ([railway_tunnel_guard.sh:45](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/scripts/railway_tunnel_guard.sh:45)), po czym uruchamia proces w tle bez jawnego `9>&-` ([railway_tunnel_guard.sh:94](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/scripts/railway_tunnel_guard.sh:94), [railway_tunnel_guard.sh:107](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/scripts/railway_tunnel_guard.sh:107)). Jeżeli docelowy `setsid`/OpenSSH 8.0 zachowa ten deskryptor, proces SSH będzie trzymał blokadę po zakończeniu guarda, a każdy kolejny cron zakończy się bez działania na linii 46–47. Emulator stanów sondy tego nie rozstrzyga.

5. **[WYSOKI] Klasyfikacja `EOF` i `INNY` może pozostawić naprawialną awarię bez restartu.**

   `EOF` technicznie oznacza tylko zamknięcie połączenia bez bajtu odpowiedzi ([railway_tunnel_guard.sh:57](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/scripts/railway_tunnel_guard.sh:57)), lecz handler zakłada jednoznacznie problem celu/`permitopen` i nigdy nie próbuje świeżego tunelu ([railway_tunnel_guard.sh:161](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/scripts/railway_tunnel_guard.sh:161)). Nieoczekiwany bajt również powoduje tylko log, bez zmiany stanu lub restartu ([railway_tunnel_guard.sh:171](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/scripts/railway_tunnel_guard.sh:171)). Pomiar RST→EOF potwierdza jeden przypadek, ale nie dowodzi, że każdy EOF ma tę samą przyczynę.

6. **[ŚREDNI] Uszkodzony `DATABASE_URL_DIRECT` może wyglądać dokładnie jak oczekiwany FAIL.**

   Każda niepusta wartość DIRECT jest wybierana bez walidacji, a wynik `parse_url()` nie jest sprawdzany; brakujące elementy są zastępowane domyślnym hostem, portem lub pustymi credentials ([railway_monitor.php:81](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/scripts/railway_monitor.php:81), [railway_monitor.php:82](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/scripts/railway_monitor.php:82), [railway_monitor.php:85](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/scripts/railway_monitor.php:85)). Plan oczekuje po wdrożeniu właśnie FAIL trasy bezpośredniej ([task:51](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_instances/integration/tasks/CHAT-T-195_INTEGRATION_tunel-railway-przez-mydevil.md:51)), więc literówka lub ucięcie URL może pozostać niewykryte i zniszczyć sygnał potrzebny do późniejszego wyjścia z tunelu.

7. **[ŚREDNI] Komunikaty monitora staną się semantycznie fałszywe.**

   Kod będzie mierzył trasę bezpośrednią ([railway_monitor.php:81](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/scripts/railway_monitor.php:81)), ale nagłówek nadal nazywa ją „TRASĄ PRODUKCYJNĄ” ([railway_monitor.php:4](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/scripts/railway_monitor.php:4)), podobnie treści maili degradacji i recovery ([railway_monitor.php:962](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/scripts/railway_monitor.php:962), [railway_monitor.php:990](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/scripts/railway_monitor.php:990)). Ograniczenie „jedna zmiana, nic więcej” ([task:25](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_instances/integration/tasks/CHAT-T-195_INTEGRATION_tunel-railway-przez-mydevil.md:25)) utrwala mylące alerty.

8. **[ŚREDNI] Test po restarcie tunelu nie pokrywa puli trwałych połączeń FPM.**

   Backend używa `PDO::ATTR_PERSISTENT` ([PostgresConnection.php:145](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/standalone/src/Database/PostgresConnection.php:145), [PostgresConnection.php:156](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/standalone/src/Database/PostgresConnection.php:156)), natomiast po zabiciu SSH plan wymaga tylko pojedynczego `/api/health` ([task:54](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_instances/integration/tasks/CHAT-T-195_INTEGRATION_tunel-railway-przez-mydevil.md:54)). Jedno żądanie może trafić do jednego workera i nie potwierdza, że wszystkie procesy FPM odrzuciły stare uchwyty i poprawnie się połączyły ponownie.

9. **[WYSOKI] Zgoda mydevil nie obejmuje potwierdzonego użycia hosta jako relay do Railway.**

   Task powołuje się na zgodę z CHAT-T-193 ([task:16](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_instances/integration/tasks/CHAT-T-195_INTEGRATION_tunel-railway-przez-mydevil.md:16)), ale ADR jawnie stwierdza, że zgoda dotyczyła tunelu do PostgreSQL mydevil i nie sprawdzono forwardowania do hosta zewnętrznego ([ADR-141:4689](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/10_decyzje_projektowe.md:4689)). To nierozstrzygnięte ryzyko regulaminowe i operacyjne dla konta s81.

10. **[ŚREDNI] Kryterium powrotu z tunelu sprawdza za mało.**

    ADR pozwala wrócić po 24 godzinach `railway_tcp OK` ([ADR-141:4691](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/10_decyzje_projektowe.md:4691)). Ta metryka potwierdza tylko zestawienie TCP ([railway_monitor.php:133](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/scripts/railway_monitor.php:133), [railway_monitor.php:927](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/scripts/railway_monitor.php:927)), mimo że monitor ma również uwierzytelnione metryki odczytu i zapisu ([railway_monitor.php:928](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/scripts/railway_monitor.php:928)). Sam otwarty port nie jest wystarczającą bramką do ponownego przepięcia produkcji.

11. **[NISKI] Brakuje polityki rotacji logów guarda.**

    Cron i SSH dopisują bezterminowo do stałych plików ([railway_tunnel_guard.sh:20](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/scripts/railway_tunnel_guard.sh:20), [railway_tunnel_guard.sh:37](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/scripts/railway_tunnel_guard.sh:37), [railway_tunnel_guard.sh:93](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/scripts/railway_tunnel_guard.sh:93)). Przy długim stanie CEL wpis może powstawać co minutę ([railway_tunnel_guard.sh:163](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/scripts/railway_tunnel_guard.sh:163)).

## Proponowane następne kroki

1. Zablokować deploy do czasu ograniczenia klucza również przed shell/exec i `-R`; dodać negatywne testy polecenia, PTY, alternatywnego `-L` i `-R`. Rozważyć także `from="<IP smarthosta>"`.
2. Dodać osobny, alertujący monitor trasy produkcyjnej: uwierzytelnione `SELECT 1` przez tunel albo lokalne wywołanie `/api/health`. Monitor bezpośredni zachować wyłącznie jako sygnał powrotu.
3. Jawnie zamknąć fd 9 w procesie potomnym i na docelowym RHEL sprawdzić `/proc/$SSH_PID/fd/9` oraz drugie uruchomienie guarda.
4. Uzupełnić automat stanów: kontrolowany restart diagnostyczny dla pierwszego `EOF`, obsługa `INNY` jako awarii oraz rate-limit alertów.
5. Przetestować guarda w środowisku cronowym `env -i PATH=/usr/bin:/bin`: dwie równoległe instancje, brak/stary pidfile, osierocony SSH, zajęty port, RST, blackhole, zły `permitopen`, DNS failure i nieoczekiwany bajt.
6. Przed zmianą `.env` porównać oba URL-e realną ścieżką parsera: muszą różnić się wyłącznie hostem i portem; sprawdzić niepuste user/database/password oraz `sslmode`, bez ujawniania sekretów. Zmianę wykonać atomowo.
7. Po przełączeniu i po zabiciu tunelu wykonać serię równoległych żądań, wystarczającą do objęcia wszystkich workerów FPM.
8. Uzyskać zgodę mydevil na external relay albo zapisać jawne zaakceptowanie tego ryzyka przed deployem.
9. Zmienić etykiety monitora na „trasa bezpośrednia” oraz wymagać przed powrotem 24 godzin wszystkich metryk PG OK, nie tylko TCP.
10. Dodać rotację obu logów oraz test odtworzenia po restarcie/cronie.

═══ CHAT-T-195 · REVIEW · STOP ═══