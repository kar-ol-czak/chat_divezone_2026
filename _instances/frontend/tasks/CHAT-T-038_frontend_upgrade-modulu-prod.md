# CHAT-T-038 — FRONTEND: Wgranie + upgrade modulu 1.0.0->1.0.1 na PROD (widget etap 1)

**Data:** 2026-06-02
**Instancja:** frontend
**Wejscie:** CHAT-T-037 (widget zbudowany, wersja modulu 1.0.1, upgrade/upgrade-1.0.1.php gotowy). Decyzje 63 (na PROD), 64 (DEV/PROD osobne bazy — ale robimy PROD), 65c (upgrade preferencja CLI), 66a (sekret+IP wpisuje Karol w panelu).
**PROD:** PrestaShop docroot z .env PROD_PATH=~/public_html/newtmp2. SSH: SSH_HOST/PORT/USER/KEY_PATH z .env. To ZYWY sklep.

---

## SYTUACJA
Modul divezone_chat JEST zainstalowany na PROD (wersja 1.0.0, panel admin z CHAT-T-032..035 dziala, ma KEY_SERVER_SECRET + KEY_BACKEND_URL). CHAT-T-037 podbil go do 1.0.1 (dodaje widget front: hook displayFooter, nowe klucze KEY_CLIENT_SECRET + KEY_ALLOWED_IPS, upgrade-1.0.1.php). To NIE instalacja od zera — to UPGRADE 1.0.0->1.0.1. NIE reinstall, NIE uninstall (stracilby sekret serwerowy panelu).

## KROK 0 — ROZPOZNANIE (STOP po tym)
SSH na serwer (dane .env). PROD_PATH.
1. Potwierdz wersje modulu na PROD: `SELECT id_module, name, version, active FROM <prefix>module WHERE name='divezone_chat';` (oczekiwane: 1.0.0, active=1). Potwierdz wpis w <prefix>module_shop.
2. Potwierdz ze panel dziala: KEY_SERVER_SECRET ustawiony (NIE pokazuj wartosci, tylko czy niepusty), hook/tab admin obecny.
3. Sprawdz droge upgrade: czy `php bin/console` istnieje i czy `php bin/console list | grep module` pokazuje komende `prestashop:module upgrade` (PS 1.7.6 — install na pewno jest, upgrade DO POTWIERDZENIA). Jesli upgrade-CLI NIE istnieje — zaproponuj droge awaryjna (NIE wykonuj jeszcze): np. bump wersji w <prefix>module + wywolanie skryptu upgrade, albo cache clear + wykrycie przez panel. Opisz co znalazles.
4. Sprawdz aktualny stan plikow modulu na PROD vs lokalne repo: czy nowe pliki (upgrade/, views/js/, views/css/, zmienione divezone_chat.php, config.xml) sa juz wgrane czy trzeba je zsynchronizowac.
STOP. Zaraportuj: wersja na PROD, czy panel nietkniety, ktora droga upgrade dziala (CLI czy awaryjna), czy pliki wgrane. Czekaj na akceptacje Karola.

## KROK 1 — BACKUP (przed jakakolwiek zmiana, ZYWY sklep)
- Backup tabel: `<prefix>module`, `<prefix>module_shop`, `<prefix>tab`, `<prefix>module_lang` (mysqldump do pliku poza docrootem, np. ~/backups/divezone_chat_preupgrade_YYYYMMDD.sql). Pokaz sciezke backupu.

## KROK 2 — WGRANIE PLIKOW (jesli KROK 0.4 wykazal brak)
- Zsynchronizuj modules/divezone_chat/ z lokalnego repo na PROD_PATH/modules/divezone_chat/ (rsync/scp). Nowe: upgrade/upgrade-1.0.1.php, views/js/widget-loader.js, views/js/widget-bundle.js, views/js/transport.js, views/css/widget.css. Zmienione: divezone_chat.php (wersja 1.0.1), config.xml. NIE nadpisuj nic poza modulem.

## KROK 3 — UPGRADE 1.0.0->1.0.1 (droga z KROK 0.3)
- Preferencja: `cd <PROD_PATH> && php bin/console prestashop:module upgrade divezone_chat` (lub droga awaryjna zatwierdzona w KROK 0).
- Jesli blad — cache clear (`php bin/console cache:clear --env=prod` lub rm -rf var/cache/prod/*) i powtorz.
- Upgrade ma: zarejestrowac hook displayFooter, utworzyc puste klucze KEY_CLIENT_SECRET + KEY_ALLOWED_IPS (przez upgrade-1.0.1.php). NIE rusza KEY_SERVER_SECRET ani KEY_BACKEND_URL.

## KROK 4 — WERYFIKACJA (CC, bez wpisywania sekretow)
- `SELECT version FROM <prefix>module WHERE name='divezone_chat';` -> 1.0.1.
- Hook displayFooter zarejestrowany: `SELECT * FROM <prefix>hook_module WHERE id_module=<id> AND id_hook=(SELECT id_hook FROM <prefix>hook WHERE name='displayFooter');`
- Nowe klucze istnieja i sa PUSTE: Configuration DIVEZONE_CHAT_CLIENT_SECRET, DIVEZONE_CHAT_ALLOWED_IPS (puste = bezpieczny default, widget niewidoczny).
- Panel admin NIETKNIETY: KEY_SERVER_SECRET nadal niepusty, tab admin dziala.
- STOP. Zaraportuj stan. Reszta nalezy do Karola (KROK 5).

## KROK 5 — KAROL (recznie, po raporcie CC)
1. Panel PS -> Moduly -> DiveZone Chat -> Konfiguruj.
2. Pole "Sekret KLIENCKI (widget)" = DIVECHAT_SECRET z .env standalone (NIE _SERVER_). Badge powinien zmienic sie na [ustawiony].
3. Pole "IP dozwolone" = swoje IP (sprawdz curl -s https://api.ipify.org; jesli CF daje IPv6, wpisz tez IPv6 — sprawdz https://api64.ipify.org). Kilka IP po przecinku.
4. Otworz https://divezone.pl ze swojego IP -> teal launcher po ~1.5s -> klik -> "test" -> odpowiedz. Inne IP -> brak launchera.
5. Jesli brak launchera: DevTools Network -> czy widget-loader.js sie laduje. Brak -> IP nie matchuje (IPv4 vs IPv6) lub klucz pusty.

## GIT
- Brak nowego kodu (pliki juz w repo z CHAT-T-037). Jesli powstanie skrypt synca/backupu jako artefakt — NIE commituj (operacyjny). Po upgrade: osobny docs: commit ze statusem (CHAT-T-038 — widget etap 1 wdrozony na PROD, oczekuje konfiguracji sekret+IP Karola). Handoff LOKALNY.

## RAPORT
KROK 0: wersja PROD, droga upgrade, stan plikow, panel nietkniety -> STOP.
Po upgrade: wersja 1.0.1, hook zarejestrowany, klucze utworzone puste, panel dziala, sciezka backupu. Instrukcja KROK 5 dla Karola.
