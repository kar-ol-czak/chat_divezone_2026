# CHAT-T-187 — INTEGRATION: build sondy na Railway nie przechodzi (brak markera projektu Python)

**Instancja:** integration. Katalog `_diag_local/railway_reverse_probe/`.
**Powiązane:** CHAT-T-184 Faza B (materiały wdrożeniowe), karta Trello **Chat - 88**.
**Status:** DO ZROBIENIA. Blokuje pomiar trasy powrotnej, czyli jedyny brakujący dowód
w zgłoszeniu smarthost #167585.

---

## OBJAW

Karol podpiął repo, ustawił Root Directory `_diag_local/railway_reverse_probe`, kliknął Deploy.
Railway odpowiedział: **„There was an error deploying from source."** Serwis `reverse-probe`,
projekt `pacific-prosperity`, środowisko `production`, region EU West (Amsterdam), 1 replika.

## HIPOTEZA (nie potwierdzona logiem builda, patrz KROK 1)

Zawartość katalogu na `origin/main`, sprawdzona 2026-09-10:

```
_diag_local/railway_reverse_probe/README.md
_diag_local/railway_reverse_probe/probe.py
```

To wszystko. Nie ma `requirements.txt`, `main.py`, `pyproject.toml`, `Pipfile`, `poetry.lock`,
`Procfile`, `nixpacks.toml` ani `Dockerfile`. Nixpacks rozpoznaje projekt Pythona po obecności
jednego z tych markerów. Sam plik `.py` o dowolnej nazwie markerem nie jest, więc builder
najpewniej nie potrafi zbudować planu i przerywa **przed** dojściem do Start Command.

`README.md` w kroku 5 każe ustawić Start Command `python -u probe.py` i to jest poprawne,
ale niewystarczające: Start Command dotyczy uruchomienia, nie wykrycia języka przy budowaniu.

**Nie widziałem logu builda z Railway.** Powyższe to hipoteza z mocną podstawą, nie ustalenie.

---

## KROK 1 — NAJPIERW DOWÓD, POTEM POPRAWKA

Poproś Karola o log nieudanego builda (Railway → serwis `reverse-probe` → **Deployments** →
kliknięcie w nieudany deploy → **View Logs**, zakładka Build). Ani Ty, ani architekt nie mamy
dostępu do Railway, więc to jedyne źródło.

Jeżeli log potwierdzi brak wykrycia języka — rób KROK 2. Jeżeli powie coś innego — **zatrzymaj
się i zgłoś**, nie naprawiaj hipotezy wbrew dowodowi.

Jeżeli Karol nie dostarczy logu w rozsądnym czasie, KROK 2 i tak jest bezpieczny (dokłada pliki,
niczego nie psuje), ale w raporcie napisz wprost, że wdrożono bez potwierdzenia przyczyny.

## KROK 2 — MINIMALNY MARKER PROJEKTU

W `_diag_local/railway_reverse_probe/` dołóż:

1. **`requirements.txt`** — pusty albo z jednym komentarzem. Sonda celowo nie ma zależności
   (tylko biblioteka standardowa), a plik istnieje wyłącznie po to, żeby builder rozpoznał
   Pythona. Napisz to w komentarzu w pliku, żeby nikt go później nie „posprzątał".
2. **`nixpacks.toml`** z jawną komendą startową, żeby uruchomienie nie zależało od pola
   w panelu, które łatwo zgubić przy odtwarzaniu serwisu. Komenda: `python -u probe.py`.
   Flaga `-u` zostaje mimo że sonda robi flush per linia — logi Railway to jedyne miejsce,
   gdzie te dane istnieją, więc buforowanie jest tu ryzykiem bez zysku.

Nie zmieniaj `probe.py`. Nie zmieniaj nazwy pliku na `main.py` — README odwołuje się do
`probe.py` w kilku miejscach, a przemianowanie rozjedzie dokumentację z kodem.

## KROK 3 — UZUPEŁNIENIE README

Dopisz do `README.md`:

- że katalog musi zawierać `requirements.txt` i `nixpacks.toml`, i po co one są
- **krok „Disconnect" po pierwszym udanym deployu.** Gałąź `main` jest podpięta z automatycznym
  deployem, a do repo wpada po kilka commitów dziennie. Każdy push przeładuje sondę i zrobi
  dziurę w pomiarze. Po pierwszym udanym uruchomieniu Karol ma kliknąć **Disconnect** przy
  „Branch connected to production" — serwis chodzi dalej na wdrożonym obrazie
- zastrzeżenie, które musi trafić do pisma do smarthosta razem z wynikiem: serwis obliczeniowy
  Railway nie musi wychodzić tym samym łączem co proxy PostgreSQL, mimo że oba są w EU West.
  Sonda wypisuje region i ID wdrożenia — to ma być zacytowane w piśmie

## KROK 4 — WERYFIKACJA, KTÓREJ NIE MOŻESZ ZROBIĆ SAM

Deploy klika Karol. W raporcie podaj, czego od niego potrzebujesz, jednym akapitem:
czy build przeszedł, czy w logach lecą linie sondy, i pierwsze trzy linie do wklejenia.

---

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
Treść logu builda (jeśli dostarczony) albo jawne „wdrożono bez potwierdzenia przyczyny",
lista dołożonych plików, fragment README z krokiem Disconnect, i jeden akapit z tym,
co Karol ma sprawdzić po ponownym Deploy.
