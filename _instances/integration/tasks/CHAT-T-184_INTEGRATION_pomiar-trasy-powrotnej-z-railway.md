# CHAT-T-184 — INTEGRATION: pomiar trasy POWROTNEJ Railway → serwer (dowód na zarzut asymetrii)

**Instancja:** integration.
**Powiązane:** karta Trello **Chat - 88**, pismo `_reports/20260904_eskalacja_smarthost_167585.md`
(sekcja 5 obiecuje ten pomiar smarthostowi), CHAT-T-119 (zrzuty od naszej strony),
CHAT-T-182/183 (raport dobowy).
**Status:** DO ZROBIENIA.

---

## PO CO TO ROBIMY

Smarthost odpowiedział 06.07.2026 (Albert Kruk): *„wynik polecenia traceroute pokazuje wyłącznie
ścieżkę z naszej strony do hosta docelowego. Nie daje natomiast informacji o trasie powrotnej,
która może przebiegać inną drogą. Konkretniej nazywane to jest trasowaniem asymetrycznym."*

**Ma rację i nie da się tego obejść pomiarem z jednej strony.** Cały nasz dotychczasowy materiał
(21 zrzutów z 02.09, traceroute z 03.07, 02.08, 02.09 i na żywo 03.09) mierzy kierunek
serwer → Railway. Dopóki nie zmierzymy kierunku Railway → serwer, rozmowa może się zapętlić.

Wynik ma rozstrzygnąć jedno pytanie:
- Railway → serwer **też traci** w tym samym oknie → problem jest na styku / dwukierunkowy,
  eskalacja do Arelion ma mocną podstawę.
- Railway → serwer **czysto**, a serwer → Railway traci → wina leży w kierunku wyjściowym,
  czyli po stronie trasy, którą wybiera nasz upstream.

Obie odpowiedzi są wartościowe. Nie ma tu wyniku „nieudanego".

---

## BLOKADA, KTÓREJ NIE ROZWIĄŻESZ SAM

Sprawdzone 2026-09-04: w `.env` **nie ma** żadnego klucza Railway (jest tylko `DATABASE_URL`),
a `railway` CLI nie jest zainstalowane lokalnie. Nie masz czym się zalogować do Railway.

Dlatego zadanie ma dwie fazy. **Faza A nie wymaga niczego i robisz ją od razu. Faza B wymaga
decyzji Karola** — albo dołoży `RAILWAY_TOKEN` do `.env`, albo sam wklika serwis w panelu
Railway z materiałów, które przygotujesz. Nie proś go o token w raporcie jako warunek —
przygotuj wszystko tak, żeby obie ścieżki były gotowe.

---

## FAZA A — to, co da się zmierzyć bez dostępu do Railway (rób od razu)

### A1. Looking glass Arelion (AS1299)
Arelion udostępnia publiczny looking glass. Sprawdź z niego:
- trasę do `193.93.88.95` (nasz serwer) — to jest kierunek POWROTNY widziany z sieci tranzytowej
- trasę do `66.33.22.230` (Railway)
- wpisy BGP dla obu prefiksów: `show route 193.93.88.0/24` i dla prefiksu Railway
Zapisz surowe wyjścia do `_reports/20260904_lg_arelion.txt`, z URL-em i znacznikiem czasu UTC.
Jeżeli looking glass wymaga rejestracji albo nie odpowiada — zapisz ten fakt, nie kombinuj.

### A2. Publiczne sondy w kierunku naszego serwera
Z dowolnych publicznych narzędzi typu „traceroute from" wykonaj traceroute do `193.93.88.95`
z węzłów w Amsterdamie i Frankfurcie (te same miasta co hopy 7-8 naszej trasy).
Cel: pokazać, jaką drogą ruch wraca do nas z okolic Railway.
Zapisz do `_reports/20260904_reverse_traceroute_publiczne.txt` z nazwą narzędzia i czasem UTC.

### A3. Kontrola po naszej stronie
Sprawdź na serwerze (READ-ONLY, SSH -p 5739), czy firewall/hosting nie odrzuca ICMP
przychodzącego — inaczej pomiar z Fazy B będzie mierzył nasz filtr, nie sieć.
Dowód wpisz do raportu. Jeżeli ICMP jest filtrowane, sonda z Fazy B ma się opierać
wyłącznie na TCP i to trzeba jawnie odnotować.

---

## FAZA B — sonda lustrzana na Railway

### B1. Kod sondy
Napisz `_diag_local/railway_reverse_probe/probe.py`:
- **wyłącznie biblioteka standardowa Pythona 3** (`socket`, `ssl`, `time`, `datetime`),
  żeby działała na dowolnym obrazie bazowym bez instalacji zależności
- pętla co **30 s** (nie 5 s — to ma być tanie i chodzić kilka dni)
- metryki na cykl, każda z osobnym pomiarem czasu i twardym timeoutem 5 s:
  1. `tcp443` — czysty TCP connect do `193.93.88.95:443`
  2. `tls443` — connect plus handshake TLS do `chat.divezone.pl:443` (SNI!)
  3. `tcp5739` — TCP connect do portu SSH, jako druga niezależna usługa na tym samym IP
  4. `ctrl_leaseweb` — TCP connect do `5.79.108.33:80`, kontrola: inny cel, inna trasa
- linia wyjścia na **stdout**, jedna na cykl, format wzorowany na naszym monitorze:
  `#NNNNN <YYYY-MM-DD HH:MM:SS> UTC | tcp443 OK 32ms | tls443 OK 84ms | tcp5739 OK 33ms | ctrl_leaseweb OK 12ms | errno=0`
- `flush` po każdej linii, inaczej logi Railway pokażą pustkę

**NIE odpytuj `/api/health`.** Ten endpoint sam łączy się z Railway PG, więc pomiar
biegłby tam i z powrotem po podejrzanej trasie i nic by nie znaczył. Mierzymy warstwę
transportową do naszego IP, nie aplikację.

### B2. Zapis wyników
Pierwszy przebieg loguje na **stdout** i czytamy go z logów Railway. **Nie zakładaj tabeli
w produkcyjnej bazie czatu** — to diagnostyka tymczasowa, nie warto migracji na PROD.
Jeżeli po pierwszym przebiegu okaże się, że retencja logów Railway jest za krótka, wróć
z propozycją tabeli — wtedy STOP przed migracją (ADR-089).

### B3. Materiały do wdrożenia, obie ścieżki
Przygotuj w `_diag_local/railway_reverse_probe/`:
- `probe.py`
- `README.md` z instrukcją **dla człowieka**: jak wkleić to jako nowy serwis w panelu Railway
  (repo albo pusty serwis z tym jednym plikiem), co ustawić jako start command, gdzie czytać logi,
  jak serwis usunąć. Krótko, krok po kroku, bez żargonu.
- jeżeli Karol dostarczy `RAILWAY_TOKEN` — komendy `railway` CLI do wdrożenia i usunięcia serwisu

### B4. Czas trwania i sprzątanie
Sonda ma chodzić przez **co najmniej trzy wieczory**, czyli objąć trzykrotnie okno 16-21 UTC.
W `README.md` wpisz **datę wyłączenia** i zaznacz, że to serwis tymczasowy, który generuje
koszt na koncie Railway. Nie zostawiamy go na stałe.

---

## ANALIZA (to jest właściwy produkt zadania)

Po zebraniu danych zestaw minuta w minutę:
- sondę z Railway (`tcp443` FAIL) z naszym monitorem (`railway_tcp` FAIL) z tych samych minut
- w oknach, gdzie nasz monitor widzi epizod, sprawdź co widziała sonda z drugiej strony
- kontrolę `ctrl_leaseweb` traktuj tak, jak my traktujemy nasze kontrole: jeżeli ona też traci,
  to problem jest po stronie Railway jako hosta, nie trasy

Wynik zapisz do `_reports/20260904_trasa_powrotna_wynik.md`, w formie gotowej do doklejenia
do pisma dla smarthosta: jedna tabela, jeden wniosek, źródła.

---

## RECENZJA KRZYŻOWA
`/codex` na `probe.py` przed commitem — szczególnie na twarde timeouty i na to, czy pętla
nie zawiesi się na wiszącym połączeniu (to dokładnie awaria, którą naprawiał CHAT-T-109
po naszej stronie). Niezgody NIEROZSTRZYGNIĘTE do architekta.

## GIT
`git add` per ścieżka, nigdy `git add .`. Commit `feat(CHAT-T-184): ...`, osobny `docs(CHAT-T-184): ...`.
`git push origin main`.

## NIE RUSZAĆ
Produkcyjna baza Railway (żadnych migracji, żadnych zapisów), `railway_monitor.php`,
`railway_summary_mail.php`, `railway_monitor_guard.sh`, crontab, logi i zrzuty w `~/_diag/`,
`_ops/newtmp2_root/purge_litespeed.php`, `standalone/config/routes.php`,
`standalone/config/tools.php`, pliki ADR. Serwer dotykasz wyłącznie do odczytu.

## RAPORT KOŃCOWY
Wynik Fazy A z surowymi wyjściami i URL-ami. Kod sondy plus `README.md` gotowy dla człowieka.
Jawnie napisz, czego NIE dało się zmierzyć i dlaczego. Jeżeli Faza B stoi na braku dostępu
do Railway — powiedz to jednym zdaniem i przekaż gotowe materiały, nie czekaj biernie.
