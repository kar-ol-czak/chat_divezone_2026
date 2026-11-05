═══ CHAT-T-196 · INTEGRATION · alert mailowy strażnika tunelu + zamknięcie rozmowy testowej T-195 ═══

# CHAT-T-196 — strażnik tunelu Railway wysyła maila, gdy tunel nie wraca

Autor zlecenia: architekt. Data: 2026-11-05. Karta: Chat - 150. Decyzja: Karol 3a (2026-11-05). Kontekst: ADR-141, CHAT-T-195, recenzja Codex T-195 uwaga 2.

## 1. Stan

- Od 2026-11-05 14:12 czat łączy się z Railway wyłącznie przez tunel (`DATABASE_URL` = `127.0.0.1:14368`). Strażnik `~/_diag/railway_tunnel_guard.sh` (repo `_docs/scripts/railway_tunnel_guard.sh`, md5 prod `1259ea20890f95449ecce3e876ac0e4a`) pisze tylko do `~/_diag/railway_tunnel_guard.log`.
- `railway_monitor.php` mierzy trasę BEZPOŚREDNIĄ (`DATABASE_URL_DIRECT`), więc awarii tunelu nie zgłosi. Dziś nikt nie dostanie powiadomienia, gdy strażnik nie odtworzy tunelu.
- Wzorzec wysyłki istnieje: `embeddings/watchdog.sh` (`/usr/sbin/sendmail`, adres z `.env` kluczem `DIVECHAT_COST_ALERT_EMAIL`, bez hardkodu, brak klucza = wpis w logu i pominięcie, exit 0).

## 2. Docelowo

W strażniku, bez zmiany jego logiki naprawczej:
- **Mail ALARM**, gdy sonda przez tunel nie daje `OK` nieprzerwanie przez `ALERT_AFTER_S` (domyślnie 300 s). Liczysz od pierwszego nie-OK, czas w pliku stanu `~/_diag/railway_tunnel_alert.state`. Treść: stan sondy (`ODMOWA`/`EOF`/`CISZA`/brak pid/`CEL`), od kiedy, ostatnie 10 wpisów strażnika, ostatnia linia `railway_tunnel_ssh.out`, wynik `/api/health` ze smarthost.
- **Mail POWRÓT**, gdy po wysłanym ALARM sonda wraca do `OK`. Podaj czas trwania.
- **Jeden ALARM na epizod.** Przypomnienie najwcześniej co `ALERT_REPEAT_S` (domyślnie 6 h), twardy limit `ALERT_MAX_PER_DAY` (domyślnie 6) na wszystkie maile strażnika.
- Temat z prefiksem `[DiveChat]` i nazwą hosta, w stylu `watchdog.sh`. Mail nie zawiera żadnej wartości z `.env` poza adresem odbiorcy w nagłówku.
- Parametry nadpisywalne zmienną środowiskową, domyślne w skrypcie. Błąd wysyłki = wpis w logu, nie wywraca strażnika.

## 3. Rozmowa testowa z T-195

`divechat_conversations` id 1709, `divechat_conversation_review` id 535, `note` zawiera `[test CHAT-T-195, nie klient]`, status `do_weryfikacji`, verdict NULL. Ma zniknąć z kolejki recenzji Karola.
- Sprawdź, jakie wartości przyjmują `status` i `verdict` (constraint albo kod panelu `AdminDivezoneChatController.php` w module, tylko odczyt).
- Zapis według `_docs/42` §5: `status='zamkniety'`, verdict najbliższy znaczeniowo „bez problemu” z dozwolonych, albo NULL jeśli takiego nie ma (uzasadnij), `updated_by=NULL`, marker DOPISANY do `note`: `[zamkniete przez Claude, CHAT-T-196, 2026-11-05: rozmowa testowa T-195]`.
- Guard: `WHERE id=535 AND conversation_id=1709 AND status='do_weryfikacji'`. Wynik: dokładnie 1 wiersz.
- Z Maca Railway jest nieosiągalny (`_docs/44`, pułapka tunelu): zapis wykonujesz na smarthost przez aplikację (`DATABASE_URL`), nie przez `sql.py`.

## 4. KROKI

KROK 0. `git fetch`, `git rev-list --count HEAD..origin/main`. Gdy > 0, a drzewo ma cudze niezacommitowane zmiany: `git merge --ff-only origin/main` (nie stashuj, nie rebase'uj cudzych plików). Przeczytaj: ten plik, ADR-141, raport `_reports/CHAT-T-195_tunel-railway-przez-mydevil.md`, `_docs/scripts/railway_tunnel_guard.sh`, `embeddings/watchdog.sh`, `_docs/42` §5, `_docs/44` PUŁAPKI (zwłaszcza cronowy PATH, `rsync -a` a prawa pliku, `pgrep -f` łapiący własną komendę).
KROK 1. Lokalnie: zmiana strażnika. `bash -n`, shellcheck. Test logiki alertu na bashu serwera pod `env -i PATH=/usr/bin:/bin` z atrapą sondy i atrapą sendmaila (plik zamiast wysyłki): przypadki krótka awaria (< próg, brak maila), długa (1 ALARM), trwająca dalej (brak duplikatu), powrót (1 POWRÓT), limit dobowy. Test z Maca nie jest dowodem.
KROK 2. Odczyt dozwolonych `status`/`verdict` i bieżący wiersz 535 (read-only, na smarthost).
KROK 3. Recenzja `/codex` diffu strażnika. Uwagi do raportu bez rozstrzygania.
KROK 4. **STOP (ADR-089).** Pokaż diff, wyniki testów z KROKU 1, zapytanie UPDATE z §3 z wartościami ustalonymi w KROKU 2. Czekaj na „deployuj”.
KROK 5. Po „deployuj”:
 a) Kopia `~/_diag/railway_tunnel_guard.sh` do `_deploy_bak/CHAT-T-196_<data>/`.
 b) Wgranie przez plik tymczasowy i `mv`, prawa `-rwxr-xr-x` potwierdzone `ls -la`, md5 local == prod.
 c) Jeden przebieg crona bez zmian w logu poza normalnym (tunel OK, brak maila).
 d) Test realny jednym mailem: przebieg ręczny z `ALERT_AFTER_S=0` i tematem z dopiskiem `[TEST CHAT-T-196]` przy sztucznym stanie nie-OK (atrapa sondy albo zmienna testowa, bez zabijania tunelu produkcyjnego), potem POWRÓT. Pokaż wpis w logu i kod wyjścia sendmaila. Stan alertu po teście wyczyszczony.
 e) UPDATE rozmowy 535, wynik `RETURNING`.
KROK 6. Dokumentacja, osobny commit `docs(CHAT-T-196)`: wpis NA GÓRZE `_docs/21_STATUS_PROJEKTU.md`, w CLAUDE.md „Mapa infrastruktury” jedna linia o alercie strażnika (parametry, plik stanu).
KROK 7. Raport `_reports/CHAT-T-196_alert-straznika-tunelu.md`, commit, push. Status karty zostawiasz architektowi.

## 5. Git

`git status` przed commitem, `git add` per ścieżka, nigdy `git add .`. `fix(CHAT-T-196): …` dla kodu, `docs(CHAT-T-196): …` dla dokumentacji. `git push origin main`. Bez `--amend`.

## 6. NIE ruszać

Logika naprawcza strażnika (restarty, sonda, `CEL_RETRY_S`) poza dołożeniem alertu. `railway_monitor.php`, `railway_monitor_guard.sh`, `.env`, crontab (linia strażnika zostaje bez zmian), wpis `authorized_keys` na s81, `config/tools.php`, `config/routes.php`, `_ops/newtmp2_root/purge_litespeed.php` (SEKRET), ADR-y, `_docs/44`, `newtmp2` (moduł tylko do odczytu). Rotacja logów (uwaga Codex 11) poza zakresem. Odczyty crontab tylko z filtrem, bez wartości. Żadnych sekretów w logach, mailu, raporcie i rozmowie.

═══ CHAT-T-196 · INTEGRATION · alert mailowy strażnika tunelu + zamknięcie rozmowy testowej T-195 ═══
