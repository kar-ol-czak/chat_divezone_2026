# CHAT-T-080 — DEPLOY CHAT-T-079 na produkcję (rsync) + ponowny test alertu DB

**Instancja:** backend
**Typ:** deploy (kod CHAT-T-079 już w repo, commit `9bf04d9` — brakuje TYLKO wgrania na serwer)
**Powiązane:** CHAT-T-079, ADR-088
**Priorytet:** P1 (alert DB nie działa na PROD, bo kod nie został wdrożony)

---

## Kontekst (dlaczego ten task istnieje)

CHAT-T-079 zaraportowano jako DONE, ale **kod nigdy nie trafił na serwer**. Diagnoza (sesja architekta 2026-06-07):
- Serwer `chat.divezone.pl` NIE jest repo git → `git push` ≠ deploy. CC pushnął do GitHub, pliki zostały w repo.
- Audyt rozjazdu repo↔serwer (md5 wszystkich 71 plików PHP): **dokładnie 3 pliki** różnią się / brakują na serwerze — wszystkie z CHAT-T-079:
  - `public/index.php` (ZMIENIONY — DI: tworzy DbHealthAlert, wstrzykuje do ChatService)
  - `src/Chat/ChatService.php` (ZMIENIONY — catch w executeTool woła maybeAlert)
  - `src/Usage/DbHealthAlert.php` (NOWY — klasa alertu)
- Reszta (68 plików) identyczna repo↔serwer. Tabela `divechat_db_alerts` na Railway JUŻ istnieje (migracja przeszła).
- Test w boju potwierdził brak alertu: realny 1045 wystąpił, `divechat_db_alerts`=0, brak `[DB-DOWN]` w error_log.

**GRANICA TWARDA:** ten task dotyczy WYŁĄCZNIE `chat.divezone.pl` (standalone). NIE dotykać `newtmp2` / żywego PrestaShop (decyzja 116b bez zmian).

---

## KROK 0 — pull/read (orientacja)
```
cd <repo>
git pull origin main
git log --oneline -3   # potwierdź że 9bf04d9 (CHAT-T-079) jest w historii
```
Przeczytaj: `standalone/src/Usage/DbHealthAlert.php`, `standalone/public/index.php` (linie ~45-60), `standalone/src/Chat/ChatService.php` (catch w executeTool, ~461). Potwierdź lokalnie, że te 3 pliki zawierają logikę alertu.

## KROK 1 — weryfikacja połączenia SSH
Dane z repo `.env` (sekcja SSH CONNECTION):
- host `divezonededyk.smarthost.pl`, port `5739`, user `divezone`
- klucz: w `.env` jest `/Users/karol/.ssh/id_ed25519` — UWAGA, realna ścieżka na tej maszynie to najpewniej `/Users/vm1-karol/.ssh/id_ed25519`. Zweryfikuj `ls -la ~/.ssh/id_ed25519` i użyj poprawnej.
```
ssh -p 5739 -i <klucz> divezone@divezonededyk.smarthost.pl 'echo OK; pwd'
```

## KROK 2 — BACKUP 3 plików na serwerze (rollback jednym ruchem)
Zdalna ścieżka backendu: `/home/divezone/public_html/chat.divezone.pl/`
```
ssh -p 5739 -i <klucz> divezone@divezonededyk.smarthost.pl '
  cd /home/divezone/public_html/chat.divezone.pl &&
  mkdir -p _deploy_bak/CHAT-T-080 &&
  cp -p public/index.php _deploy_bak/CHAT-T-080/index.php.bak 2>/dev/null;
  cp -p src/Chat/ChatService.php _deploy_bak/CHAT-T-080/ChatService.php.bak 2>/dev/null;
  echo "backup zrobiony (DbHealthAlert.php to nowy plik, brak backupu = OK)";
  ls -la _deploy_bak/CHAT-T-080/'
```
(`src/Usage/DbHealthAlert.php` nie istnieje na serwerze → nie ma czego backupować.)

## KROK 3 — STOP. Pokaż Karolowi komendę rsync i CZEKAJ na zgodę
Wygeneruj DOKŁADNĄ komendę rsync (3 pliki, bez `--delete`, z `standalone/` → root serwera). Pokaż ją Karolowi w czacie. NIE wykonuj przed jego "tak".

Komenda (zweryfikuj ścieżki u siebie):
```
rsync -avz -e "ssh -p 5739 -i <klucz>" \
  standalone/src/Usage/DbHealthAlert.php \
  divezone@divezonededyk.smarthost.pl:/home/divezone/public_html/chat.divezone.pl/src/Usage/

rsync -avz -e "ssh -p 5739 -i <klucz>" \
  standalone/public/index.php \
  divezone@divezonededyk.smarthost.pl:/home/divezone/public_html/chat.divezone.pl/public/

rsync -avz -e "ssh -p 5739 -i <klucz>" \
  standalone/src/Chat/ChatService.php \
  divezone@divezonededyk.smarthost.pl:/home/divezone/public_html/chat.divezone.pl/src/Chat/
```
UWAGA: katalog `src/Usage/` może nie istnieć na serwerze — rsync pliku NIE tworzy katalogu. Jeśli `DbHealthAlert.php` to jedyny plik w `Usage/`, najpierw:
```
ssh -p 5739 -i <klucz> divezone@divezonededyk.smarthost.pl 'mkdir -p /home/divezone/public_html/chat.divezone.pl/src/Usage'
```

## KROK 4 — (po zgodzie Karola) wykonaj rsync + weryfikacja md5
Po wgraniu potwierdź, że md5 serwera == md5 repo dla wszystkich 3 plików:
```
# lokalnie
md5 -q standalone/src/Usage/DbHealthAlert.php standalone/public/index.php standalone/src/Chat/ChatService.php
# serwer
ssh -p 5739 -i <klucz> divezone@divezonededyk.smarthost.pl 'cd /home/divezone/public_html/chat.divezone.pl && md5sum src/Usage/DbHealthAlert.php public/index.php src/Chat/ChatService.php'
```
Dodatkowo `php -l` na 3 plikach przez ea-php84 na serwerze (składnia OK):
```
ssh ... 'cd /home/divezone/public_html/chat.divezone.pl && for f in src/Usage/DbHealthAlert.php public/index.php src/Chat/ChatService.php; do /opt/cpanel/ea-php84/root/usr/bin/php -l "$f"; done'
```

## KROK 5 — smoke-test (że nic nie padło)
```
ssh ... 'curl -s -o /dev/null -w "%{http_code}" https://chat.divezone.pl/api/health'   # oczekiwane 200
```
Jeśli health zwraca !=200 → ROLLBACK natychmiast: skopiuj z `_deploy_bak/CHAT-T-080/*.bak` z powrotem, usuń `src/Usage/DbHealthAlert.php`, zaraportuj Karolowi. NIE kontynuuj.

## KROK 6 — ponowny test alertu DB (w boju, kontrolowany — STOP, wykonuje Karol z architektem)
NIE wykonuj sam. Zaraportuj Karolowi, że kod jest na PROD i gotowy do ponownego testu alertu (architekt poprowadzi test z backupem `.env`, jak poprzednio). Tu tylko potwierdź gotowość.

## KROK 7 — state update + commit docs
- Zaktualizuj `_docs/21_STATUS_PROJEKTU.md`: CHAT-T-080 deploy CHAT-T-079 na PROD (3 pliki, md5 match, health 200), status v3.51.
- Dopisz do `_docs/10_decyzje_projektowe.md` korektę procesu (patrz sekcja PROCES niżej).
- Git:
```
git add _docs/21_STATUS_PROJEKTU.md _docs/10_decyzje_projektowe.md _instances/backend/tasks/CHAT-T-080_BACKEND_deploy-079.md
git commit -m "docs: CHAT-T-080 deploy CHAT-T-079 na PROD (rsync 3 pliki) + korekta procesu deployu standalone — status v3.51"
git push origin main
```

---

## PROCES — korekta do ADR-089 (wpisz w KROK 7)

**ADR-089: Deploy standalone (chat.divezone.pl) — rsync z backupem do czasu przejścia na git; korekta błędnego "CC wdraża samo".**

Stan faktyczny (audyt 2026-06-07): serwer `chat.divezone.pl` NIE jest repo git. Dotychczasowe założenie "standalone backend — CC wdraża samo" było BŁĘDNE — CC robił tylko `git push` do GitHub, pliki nie trafiały na serwer (ostatni realny deploy plików = 5 czerwca; CHAT-T-079 z 6-7 czerwca utknął w repo, ~przez to alert nie działał).

Procedura przejściowa (obowiązuje TERAZ, dla `chat.divezone.pl` WYŁĄCZNIE; `newtmp2`/PrestaShop bez zmian — 116b):
1. Każdy task backendu kończący się zmianą plików → osobny KROK deploy: backup zmienianych plików na serwerze (`_deploy_bak/<task>/`), rsync konkretnych ścieżek `standalone/` → `chat.divezone.pl/` (port 5739, BEZ `--delete`, exclude `.env`/`vendor`/`public/error_log`), md5 match repo↔serwer, `php -l`, smoke-test `/api/health` 200, rollback z backupu przy błędzie.
2. STOP-point: CC pokazuje komendę rsync Karolowi i czeka na zgodę przed wykonaniem (Karol kontroluje moment deployu).
3. Dla zmian dotykających `.env`/auth/migracji DB → dodatkowy STOP i wyraźna zgoda Karola.

Cel docelowy (osobny task, gdy Karol doda Deploy Key na GitHub): serwer staje się git (sparse-checkout `standalone/`, `composer install`), deploy = `git pull` przez SSH, weryfikacja `git rev-parse HEAD` repo==serwer. Wtedy "CC wdraża samo" stanie się prawdą i bezpiecznie weryfikowalne.

Dług: 6 sierot na serwerze (`scripts/t015_smoke.php`, `t017_smoke.php`, `t020_smoke.php`, `t022_cache_probe.php`, `t022_smoke_provider.php`, `cron_editorial_picks_expire.php`) — pliki na serwerze bez odpowiednika w repo. Do przeglądu: dodać do repo albo usunąć z serwera. Osobny task.
