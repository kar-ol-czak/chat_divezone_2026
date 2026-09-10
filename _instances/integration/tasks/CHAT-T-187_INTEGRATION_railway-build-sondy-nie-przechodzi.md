# CHAT-T-187 — INTEGRATION: build sondy na Railway nie przechodzi (brak markera projektu Python)

**Instancja:** integration. Katalog `_diag_local/railway_reverse_probe/`.
**Powiązane:** CHAT-T-184 Faza B (materiały wdrożeniowe), karta Trello **Chat - 88**.
**Status:** DO ZROBIENIA. Blokuje pomiar trasy powrotnej, czyli jedyny brakujący dowód
w zgłoszeniu smarthost #167585.

---

## PRZYCZYNA — POTWIERDZONA LOGIEM BUILDA (2026-09-10 09:48)

```
using build driver railpack-v0.39.0
prepare railpack-v0.39.0

  Railpack 0.39.0
  Script start.sh not found
  Railpack could not determine how to build the app.

  The following languages are supported:
  Php, Golang, Java, Rust, Ruby, Elixir, Python, Deno,
  Dotnet, Node, Gleam, Cpp, Staticfile, Shell

  The app contents that Railpack analyzed contains:
  ./
  |- README.md
  |- probe.py

railpack prepare exited with an error
```

Dwie rzeczy są tu ustalone, nie zgadnięte:

1. **Root Directory działa poprawnie.** Builder analizuje dokładnie nasze dwa pliki,
   nic więcej. Ta część konfiguracji jest dobra i nie wymaga zmian.
2. **Builder nie rozpoznaje języka.** Python jest na liście wspieranych, ale sam plik `.py`
   o dowolnej nazwie nie jest markerem. Brakuje pliku, po którym Railpack wybiera providera.

## KOREKTA WOBEC PIERWSZEJ WERSJI TEGO ZLECENIA

Pierwsza wersja kazała dołożyć **`nixpacks.toml`**. To jest błąd i nie wolno tego zrobić.
Log pokazuje, że Railway używa **Railpack 0.39.0**, nie Nixpacks. Railpack czyta
**`railpack.json`**, a `nixpacks.toml` zignoruje. Gdyby ktoś wykonał tamtą wersję,
dołożyłby plik, którego builder nie czyta, i build padłby dalej tak samo.

Pierwsza wersja nazywała też przyczynę hipotezą. Teraz jest to ustalenie z logu.

---

## ZAKRES

### 1. Marker projektu Python

W `_diag_local/railway_reverse_probe/` dołóż **`requirements.txt`** — pusty albo z jednym
komentarzem. Sonda celowo nie ma zależności (wyłącznie biblioteka standardowa), a plik
istnieje po to, żeby Railpack wybrał providera Python. Napisz to w komentarzu w pliku,
żeby nikt go później nie „posprzątał" jako zbędnego.

### 2. Komenda startowa w repo, nie w panelu

Dołóż **`railpack.json`** z jawną komendą startową `python -u probe.py`.
Składnię pola sprawdź w dokumentacji Railpack (`https://railpack.com`) — **nie pisz jej
z pamięci**, to jest dokładnie ta klasa błędu, która zabrała nam poprzednie podejście.
W raporcie zacytuj fragment dokumentacji, na którym się oparłeś.

Powód, żeby to było w repo, a nie w polu Start Command w panelu: pole łatwo zgubić przy
odtwarzaniu serwisu, a plik jedzie z kodem.

Flaga `-u` zostaje mimo że sonda robi flush per linia. Logi Railway to jedyne miejsce,
gdzie te dane istnieją, więc buforowanie jest tu ryzykiem bez zysku.

### 3. Czego nie robić

Nie zmieniaj `probe.py`. Nie zmieniaj nazwy pliku na `main.py` — README odwołuje się do
`probe.py` w kilku miejscach, a przemianowanie rozjedzie dokumentację z kodem.
Nie dokładaj `nixpacks.toml` (patrz KOREKTA). Nie dokładaj `start.sh` — provider Shell
nie da nam gwarancji, że w obrazie będzie Python.

### 4. Uzupełnienie README

- że katalog musi zawierać `requirements.txt` i `railpack.json`, i po co one są
- **krok „Disconnect" po pierwszym udanym deployu.** Gałąź `main` jest podpięta
  z automatycznym deployem, a do repo wpada po kilka commitów dziennie. Każdy push
  przeładuje sondę i zrobi dziurę w pomiarze. Po pierwszym udanym uruchomieniu Karol ma
  kliknąć **Disconnect** przy „Branch connected to production" — serwis chodzi dalej
  na wdrożonym obrazie
- zastrzeżenie do pisma do smarthosta: serwis obliczeniowy Railway nie musi wychodzić tym
  samym łączem co proxy PostgreSQL, mimo że oba są w EU West (potwierdzone na zrzucie:
  region serwisu to EU West Amsterdam, 1 replika). Sonda wypisuje region i ID wdrożenia
  i to ma być zacytowane w piśmie

---

## PLAN B, GDYBY DALEJ PADAŁO

Jeżeli po tej zmianie build nadal nie przechodzi, przestajemy zgadywać za builder:
dołóż **`Dockerfile`** oparty na oficjalnym obrazie `python:3-slim`, kopiujący `probe.py`
i uruchamiający go. Railway buduje wtedy z Dockerfile i cała heurystyka Railpacka odpada.
To rozwiązanie deterministyczne i takie jest tu preferowane, jeśli wariant lekki zawiedzie.
**Nie rób tego od razu** — najpierw wariant z dwoma plikami, bo jest mniejszy.

## TESTY
Nie ma czego przetestować lokalnie: build wykonuje Railway. Sprawdź wyłącznie,
że oba pliki są poprawnie sformatowane (`python3 -c "import json; json.load(open(...))"`
na `railpack.json`) i że nic poza tymi dwoma plikami i README się nie zmieniło.

## RECENZJA KRZYŻOWA
Pomijamy. Zmiana to dwa pliki konfiguracyjne bez logiki.

## GIT
`git add` per ścieżka. Commit `fix(CHAT-T-187): ...` plus osobny `docs(CHAT-T-187): ...`
na README i status. `git push origin main`.

## NIE RUSZAĆ
`probe.py`, `railway_monitor.php`, `railway_summary_mail.php`, `railway_monitor_guard.sh`,
crontab, logi i zrzuty w `~/_diag/`, produkcyjna baza Railway,
`_ops/newtmp2_root/purge_litespeed.php`, `standalone/config/routes.php`,
`standalone/config/tools.php`, pliki ADR. Na serwer nic nie idzie — to zadanie
dotyczy wyłącznie repo.

## RAPORT KOŃCOWY
Zawartość obu dołożonych plików, cytat z dokumentacji Railpack dla pola komendy startowej,
fragment README z krokiem Disconnect, i jeden akapit z tym, co Karol ma zrobić i sprawdzić
po ponownym Deploy.
