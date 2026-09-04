# Sonda trasy powrotnej Railway → serwer (CHAT-T-184)

**Do czego to jest.** Nasz monitor puka zawsze z serwera do Railway. Smarthost odpowiedział
(zgłoszenie 167585), że to nie mówi nic o **trasie powrotnej**. Ta sonda uruchamia się
**po stronie Railway** i puka w naszą stronę, czyli patrzy na łącze z drugiego końca.
Wynik wkleimy do zgłoszenia.

**Uczciwie o tym, co ten pomiar znaczy** (poprawione po recenzji krzyżowej): nawiązanie
połączenia TCP leci tam **i** z powrotem, więc pojedynczy `FAIL` nie wskazuje, która noga
zgubiła pakiet. Wartość dowodowa jest w **zestawieniu czasów**: jeśli w tej samej minucie
nasz monitor widzi awarię, a sonda z Railway widzi `OK` (albo odwrotnie) — to jest fakt,
którego z jednej strony nie da się zobaczyć.

**Drugie zastrzeżenie, też do wpisania w pismo:** to jest serwis obliczeniowy Railway.
Nie ma gwarancji, że wychodzi w świat tym samym łączem co publiczny proxy PostgreSQL
(`switchback.proxy.rlwy.net`), z którym rozmawia czat. Sonda wypisuje na starcie region
i identyfikator wdrożenia (`# srodowisko: ...`) — **te linie trzeba dołączyć do wyniku**,
żeby było wiadomo, skąd dokładnie mierzyliśmy.

**Co dokładnie robi.** Co 30 sekund wypisuje jedną linię z czterema pomiarami:

| metryka | co mierzy |
|---|---|
| `tcp443` | zwykłe połączenie TCP do `193.93.88.95:443` |
| `tls443` | połączenie + uzgodnienie TLS dla `chat.divezone.pl` |
| `tcp5739` | połączenie TCP do portu SSH — druga usługa na tym samym IP |
| `ctrl_leaseweb` | połączenie do `5.79.108.33:80` — **kontrola**: inny cel, inna trasa |

Przykład linii w logach:

```
#00042 2026-09-04 18:21:00 UTC | tcp443 OK 32ms | tls443 OK 84ms | tcp5739 OK 33ms | ctrl_leaseweb OK 12ms | errno=0 | span=0.2s
```

- `errno=` to numer błędu **metryki `tcp443`** (tak samo jak w naszym monitorze). Błędy
  pozostałych metryk są w polach `err_*`.
- `span=` to czas całego cyklu. Cztery metryki mierzone są **po kolei**, więc przy dużym
  `span` ostatnia z nich jest o tyle późniejsza niż znacznik czasu z początku linii.
- Przy błędzie dochodzi pole diagnostyczne z powodem i momentem w cyklu, np.
  `| err_tcp443=110/ETIMEDOUT@0.0s`, `| err_tls443=SSL/CERTIFICATE_VERIFY_FAILED@5.1s`.
  Uwaga: `tls443` obejmuje też ważność certyfikatu — wygasły certyfikat da `FAIL`
  z powodem `SSL/...`, a to **nie jest** problem sieci.

**Czego NIE robi:** nie odpytuje `/api/health` (bo ten endpoint sam łączy się z Railway PG,
więc pomiar biegłby tam i z powrotem po podejrzanej trasie i nic by nie znaczył), nie zapisuje
niczego do bazy, nie instaluje żadnych bibliotek — tylko standardowy Python 3.

---

## ⏰ WAŻNE: to jest serwis TYMCZASOWY

- Ma chodzić **co najmniej trzy wieczory**, żeby trzy razy objąć okno 16:00–21:00 UTC
  (czyli 18:00–23:00 naszego czasu), bo tam wypadają epizody.
- **Data wyłączenia: 2026-09-08** (po trzech pełnych wieczorach: 04, 05, 06 i 07.09).
- Serwis **generuje koszt** na koncie Railway (choć groszowy — to jeden mały proces).
  Nie zostawiamy go na stałe. Instrukcja usunięcia jest na końcu.

---

## Ścieżka A — wyklikanie w panelu Railway (bez tokenu, bez CLI)

1. Wejdź na [railway.app](https://railway.app) i otwórz projekt, w którym stoi baza czatu.
2. Kliknij **New** (albo **+ Create**) → **Empty Service**. Nazwij go `reverse-probe`.
3. Wejdź w nowy serwis → zakładka **Settings**.
4. W sekcji **Source** wybierz **Connect Repo** i wskaż to repozytorium, a jako
   **Root Directory** wpisz:
   ```
   _diag_local/railway_reverse_probe
   ```
   *(Jeśli nie chcesz podpinać repo: w sekcji Source jest opcja wgrania plików —
   wystarczy jeden plik `probe.py`.)*
5. Dalej w **Settings**, w polu **Start Command**, wpisz dokładnie:
   ```
   python -u probe.py
   ```
   `-u` jest ważne: bez tego logi potrafią się nie pokazywać.
6. Zakładka **Variables** — **nic nie trzeba ustawiać**. Sonda działa na wartościach
   domyślnych (30 sekund, timeout 5 sekund).
7. Kliknij **Deploy**. Po chwili w zakładce **Deployments → View Logs** powinny lecieć
   linie zaczynające się od `#00001`, `#00002`, …

**Gdzie czytać wyniki:** serwis `reverse-probe` → zakładka **Deployments** → **View Logs**.
Linie da się zaznaczyć i skopiować. Przydatne: pole wyszukiwania w logach — wpisz `FAIL`,
żeby zobaczyć same problemy.

**Pierwsze sprawdzenie (1 minuta):** na początku logu mają być cztery linie `#` z konfiguracją
(`# START`, `# metryki`, `# srodowisko`, `# host`, `# dns`), a potem linie pomiarowe z `OK`
przy wszystkich czterech metrykach. Jeśli **wszystko** jest `FAIL` łącznie z `ctrl_leaseweb`, to znaczy że serwis
Railway nie ma wyjścia do sieci — wtedy sonda nic nie mierzy i trzeba to zgłosić, a nie
wyciągać wniosków o naszej trasie.

---

## Ścieżka B — z linii poleceń (gdy będzie `RAILWAY_TOKEN`)

Wymaga Railway CLI (`npm i -g @railway/cli` albo `brew install railway`) i tokenu
w `.env` jako `RAILWAY_TOKEN`. Token projektowy wystarczy.

```bash
cd _diag_local/railway_reverse_probe
export RAILWAY_TOKEN='<token z .env>'

railway link                       # wybierz projekt z bazą czatu
railway add --service reverse-probe
railway up --service reverse-probe --detach     # wgrywa katalog i startuje

railway logs --service reverse-probe            # podgląd na żywo
railway logs --service reverse-probe > ~/reverse_probe_$(date +%Y%m%d).log   # zrzut do pliku
```

Start command ustawia się raz:

```bash
railway variables --service reverse-probe --set 'RAILWAY_RUN_COMMAND=python -u probe.py'
```

---

## 🧹 Usunięcie po zakończeniu (do 2026-09-08)

**Panel:** serwis `reverse-probe` → **Settings** → na samym dole **Delete Service** →
potwierdź nazwą serwisu.

**CLI:**
```bash
railway down --service reverse-probe        # zatrzymuje wdrożenie
railway service delete reverse-probe        # kasuje serwis
```

Nic po sobie nie zostawia: żadnej tabeli, żadnego wpisu w bazie, żadnego pliku na serwerze.

---

## Zanim wyślemy wynik smarthostowi

Zrzuć logi do pliku i wrzuć go obok tego README albo do `_reports/`. Analiza polega na
zestawieniu **minuta w minutę** linii z tej sondy z naszym logiem monitora
(`~/_diag/railway_monitor_YYYYMMDD.log`) z tych samych minut:

- nasz monitor widzi `railway_tcp FAIL`, a sonda w tej samej minucie widzi `tcp443 FAIL`
  → **obie strony tracą**, problem jest na styku albo dwukierunkowy;
- nasz monitor widzi `railway_tcp FAIL`, a sonda ma `tcp443 OK`
  → **traci tylko kierunek wyjściowy** od nas, czyli trasa, którą wybiera nasz upstream;
- sonda ma `tcp443 FAIL` **i** `ctrl_leaseweb FAIL` jednocześnie
  → problem jest po stronie Railway jako hosta, nie naszej trasy (tak samo jak my
  traktujemy nasze kontrole pingowe).

Wynik trafia do `_reports/20260904_trasa_powrotna_wynik.md`.

---

## Uruchomienie lokalne (opcjonalne, do sprawdzenia że plik działa)

```bash
PROBE_INTERVAL_S=5 PROBE_MAX_CYCLES=3 python3 probe.py
```

Zmienne środowiskowe (wszystkie opcjonalne): `PROBE_INTERVAL_S` (domyślnie 30),
`PROBE_TIMEOUT_S` (domyślnie 5), `PROBE_MAX_CYCLES` (0 = bez końca).
