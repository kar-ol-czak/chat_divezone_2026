═══ CHAT-T-186 · INTEGRATION · KROK 5 / STOP przed deployem ═══

# CHAT-T-186 — mtr z T-185 nie startował pod cronowym PATH

**Stan:** poprawka gotowa, **przetestowana w prawdziwym środowisku cronowym**, zacommitowana.
**Czeka na:** słowo „deployuj" (ADR-089). Na produkcji nic nie zmienione — md5 monitora
wciąż `0c778965584c0220b070d968414ce613`.
**KROK 0:** `git pull --rebase` odmówił (niezacommitowane zmiany innych sesji: `purge_litespeed.php`,
`routes.php` — nietknięte); `git fetch` + `git rev-list --left-right --count origin/main...HEAD` = `0 0`.

---

## 1. Potwierdzenie objawu i przyczyny — sprawdzone samodzielnie

**Objaw** (`~/_diag/mtr_baseline_20260909.txt`, wygenerowany automatem o 18:30:02 UTC):

```
=== mtr ICMP 66.33.22.230 (strata per hop) ===
/usr/sbin/mtr: Failure to start mtr-packet: Invalid argument

=== mtr TCP 66.33.22.230:14368 ... ===
/usr/sbin/mtr: Failure to start mtr-packet: Invalid argument
```

Cały blok zajął 0 s (`### czas: 18:30:02` i `=== BASELINE koniec ... 18:30:02`). Harmonogram
zadziałał (plik powstał o czasie, w logu jest `# MTR-BASELINE zlecony:`) — padł wyłącznie `mtr`.

**Przyczyna**, odczytana z żywego procesu, nie z domysłu:

```
/proc/927399/environ  ->  PATH=/usr/bin:/bin
ls /usr/bin/mtr-packet ->  nie ma takiego pliku      (jest tylko /usr/sbin/mtr-packet)
```

**Mechanizm potwierdzony trzema niezależnymi pomiarami na tym samym hoście:**

| próba | wynik |
|---|---|
| `env -i PATH=/usr/bin:/bin` + `/usr/sbin/mtr` | `Failure to start mtr-packet: Invalid argument` |
| `env -i PATH=/usr/sbin:/usr/bin:/bin` + `/usr/sbin/mtr` | pełny raport do hopa 11, 0,0% strat |
| `env -i PATH=/usr/bin:/bin` **+ `MTR_PACKET=/usr/sbin/mtr-packet`** | pełny raport do hopa 11 |

Trzeci wiersz jest tu kluczowy: dowodzi, że gubi się **proces pomocniczy**, a nie `mtr`.
Pełna ścieżka do `mtr` była poprawna i nic nie dawała.

**Dlaczego moja weryfikacja w T-185 tego nie złapała:** testowałem zrzut z powłoki
interaktywnej, której PATH zawiera `/usr/sbin`. Ścieżka produkcyjna (guard → cron → nohup)
ma `PATH=/usr/bin:/bin`. Test w złym środowisku przepuścił martwą funkcję.

## 2. Poprawka

W `mtrCommands()` **każde** z dwóch wywołań dostało przedrostek `PATH=/usr/sbin:/usr/bin:/bin`.
Pełna ścieżka do `mtr` zostaje. Docblock nad funkcją zastąpiony wyjaśnieniem zmierzonym
(z `/proc/<pid>/environ`, parą `env -i` i kontrolą `MTR_PACKET`, data 2026-09-10).

Realnie generowany łańcuch:

```
echo "=== mtr ICMP ... ==="; PATH=/usr/sbin:/usr/bin:/bin timeout 90 /usr/sbin/mtr --report ... -n 66.33.22.230; echo;
echo "=== mtr TCP ... ==="; PATH=/usr/sbin:/usr/bin:/bin timeout 90 /usr/sbin/mtr --report ... -T -P 14368 66.33.22.230; echo;
```

Sprawdziłem też, że przypisanie **nie wycieka** poza swoje polecenie:

```
env -i PATH=/usr/bin:/bin sh -c "PATH=/usr/sbin:/usr/bin:/bin timeout 5 /usr/sbin/mtr ...; echo PATH=$PATH"
  ->  po wywolaniu PATH=/usr/bin:/bin
```

czyli `ping`, `traceroute` i `date` w tym samym payloadzie działają na niezmienionym PATH.

**Przy okazji poprawione (finding 7 recenzji):** trzy komentarze mówiły o „~90 s" trwania zrzutu —
liczba sprzed dołożenia mtr. Zmierzone dwukrotnie jest **~100 s**, i tyle teraz mówi komentarz.

## 3. KROK 2 — audyt pozostałych wywołań zewnętrznych: nic więcej do naprawy

Przejrzałem wszystkie `shell_exec` / `timeout` / `nohup` w pliku i **sprawdziłem każdą komendę
pod cronowym PATH**, nie na oko:

| polecenie | pod `PATH=/usr/bin:/bin` | wniosek |
|---|---|---|
| `nohup`, `bash`, `flock`, `timeout`, `date` | `/usr/bin/...` | OK |
| `printf` | wbudowane w powłokę | OK |
| `ping` | `/usr/bin/ping` | OK — **kontrola dodatnia: realnie wykonane**, 2 pakiety, 0% strat |
| `traceroute` | `/usr/bin/traceroute` | OK — **kontrola dodatnia: realnie wykonane**, ścieżka do hopa 5 |
| `mtr`, `mtr-packet` | **BRAK** (oba w `/usr/sbin`) | to jedyna zależność od `/usr/sbin` |

Innych zależności od `/usr/sbin` w tym pliku nie ma.

## 4. TESTY AKCEPTACYJNE — w środowisku cronowym, nie interaktywnym

**Kontrola ujemna (stary, produkcyjny kod pod cronowym PATH):**
```
env -i PATH=/usr/bin:/bin ea-php84 -> shell_exec("timeout 90 /usr/sbin/mtr ...")
   /usr/sbin/mtr: Failure to start mtr-packet: Invalid argument
```

**Test 1 — wymuszony zrzut epizodu, `env -i PATH=/usr/bin:/bin`:**
```
PATH tego procesu PHP: /usr/bin:/bin | SAPI: cgi-fcgi
zrzut domkniety po 100 s
=== mtr ICMP ===  hopy 1-11, Snt=20 na kazdym, 11.|-- 66.33.22.230  0.0%  20  33.0 ms
=== mtr TCP  ===  hopy 1-11, Snt=10 na kazdym, 11.|-- 66.33.22.230  0.0%  10  33.1 ms
                  (na koncu znany ogon: "Unexpected mtr-packet error" — NIE filtrujemy)
```

**Test 2 — baseline, `env -i PATH=/usr/bin:/bin`:** gotowy po **45 s**, 37 linii,
22 wiersze hopów, hop 11 obecny w **obu** sekcjach (`Snt=20` i `Snt=10`).
Dla kontrastu produkcyjny baseline z 09.09 ma w tym miejscu 2 błędy `Failure to start mtr-packet`.

`ea-php84 -l` — czysty. Testy pisały wyłącznie do `/tmp/t186/`; `~/_diag` nietknięty.

## 5. RECENZJA KRZYŻOWA `/codex` (gpt-5.6-sol, xhigh) — **approve**

„Approve the PATH fix. It correctly addresses the production failure, and I found no blocking
issue in its scope, quoting, or interaction with `nohup`/`flock`."

Potwierdził niezależnie to, co zmierzyłem: zasięg przypisania kończy się na średniku (nie wycieka
na `ping`/`traceroute`/`date`), `escapeshellarg` przenosi grupę bez dodatkowej warstwy ewaluacji,
a przypisanie jest dziedziczone aż do wnuka `mtr-packet`. Zgłosił jedną poprawkę dokumentacyjną
(nieaktualne „~90 s") — wprowadzona.

### NIEROZSTRZYGNIĘTE — decyduje architekt (obie uwagi PRE-EXISTING, nie z tej poprawki)

- **Zakończenie procesu jest traktowane jak udana diagnostyka** (codex #8). Po obu `mtr` stoi
  `; echo`, a `=== DIAG koniec` pisze się bezwarunkowo — więc zrzut, w którym `mtr` zwrócił sam
  błąd, wygląda dla bramki mailowej na kompletny. **Dokładnie to się wydarzyło 09.09:** plik
  baseline miał marker końca i zero danych. Warto oprzeć bramkę na treści (obecność wiersza
  `HOST:` w obu sekcjach), nie tylko na markerze.
- **Nieudany baseline blokuje ponowienie w tej samej dobie** (codex #9). Przekierowanie tworzy
  plik zanim `mtr` cokolwiek zapisze, a nadrabianie po restarcie sprawdza tylko `file_exists()`.
  Po nieudanym przebiegu zostaje pusty plik i tego dnia nie będzie już próby.
- **`MTR_PACKET=/usr/sbin/mtr-packet`** byłoby węższym rozwiązaniem niż zmiana PATH (nie rusza
  wyszukiwania `timeout`). Zmierzyłem, że działa; zostawiam wariant z PATH, bo taki był zakres
  zlecenia. Do ewentualnej zmiany w osobnym zadaniu.
- **Teoretyczne przesłonięcie `timeout`** przez `/usr/sbin/timeout` (codex #5) — katalogi systemowe
  są własnością administratora, więc bez znaczenia praktycznego. Odnotowane.

## 6. CZEGO JESZCZE NIE WIEM

**Zadanie nie jest zamknięte.** Dowód końcowy przychodzi sam: baseline z **18:30 UTC dnia
po wdrożeniu** ma zawierać dwa pełne raporty mtr. Do tego czasu mam tylko dowód z ręcznego
uruchomienia w cronowym środowisku — mocny, ale nie tożsamy ze ścieżką produkcyjną
(guard → nohup → monitor → zrzut w tle).

Nie wiem też, czy `mtr` w zrzucie zachowa się tak samo **podczas realnego epizodu** — kod
nie chodził jeszcze na żadnej prawdziwej awarii, bo od wdrożenia T-185 epizodu nie było.

## 7. WDROŻENIE — CZEKA NA AUTORYZACJĘ (KROK 6, ADR-089)

Jeden plik `/home/divezone/_diag/railway_monitor.php`, backup `.bak_20260910`,
md5 przed `0c778965584c0220b070d968414ce613`, `ea-php84 -l`, md5 local==prod,
restart monitora + cztery dowody, plus **PATH nowego procesu z `/proc/<pid>/environ`**.

═══ CHAT-T-186 · INTEGRATION · KROK 5 / STOP przed deployem ═══
