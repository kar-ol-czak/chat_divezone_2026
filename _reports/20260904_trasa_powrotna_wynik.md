# CHAT-T-184 — trasa powrotna Railway → serwer: co już wiadomo, czego jeszcze nie

**Stan na 2026-09-04, 12:00 UTC.** Dokument w formie gotowej do doklejenia do zgłoszenia
smarthost **167585**. Faza A (pomiary bez dostępu do Railway) — **zrobiona**.
Faza B (sonda uruchomiona po stronie Railway) — **materiały gotowe, czeka na uruchomienie**,
patrz sekcja 4.

---

## 1. Jedno zdanie odpowiedzi na zarzut asymetrii

Zarzut jest **słuszny i potwierdzony publicznymi danymi BGP**: ruch wychodzący od nas
do Railway idzie przez Arelion (AS1299), a ruch wracający do nas **nie wraca przez Arelion**
— wchodzi do sieci Smarthost przez Orange Polska (AS29535) albo Exatel (AS20804).
Trasy są więc różne w obie strony i każdą trzeba mierzyć osobno.

## 2. Dowód — publiczne BGP (RIPE RIS, bez captchy, do zweryfikowania przez każdego)

Źródło: RIPEstat Data API (RIPE NCC), zapytania z 2026-09-04 11:41:50 UTC.
Surowe wyjścia: `_reports/20260904_lg_arelion.txt`.

| | nasz prefiks `193.93.88.0/22` (AS39566 Smarthost) | prefiks Railway `66.33.22.0/23` (AS400940) |
|---|---|---|
| ścieżek od peerów RIS | 382 | 330 |
| ścieżek zawierających AS1299 (Arelion) | **21 (5,5%)** | **126 (38,2%)** |
| kto oddaje ruch bezpośrednio do sieci docelowej | AS29535 Orange Polska **52,1%**, AS20804 Exatel **46,1%** | AS1299 Arelion **38,2%**, AS3356 Level3 **33,0%**, AS2914 NTT **19,7%** |

Czytanie tabeli: **do Railway** świat (w tym my) chodzi w dużej mierze przez Arelion —
i to zgadza się z naszymi traceroute'ami, które pokazują hopy Arelion w Amsterdamie
i Frankfurcie. **Do nas** ruch przez Arelion praktycznie nie wraca: AS1299 nie jest
upstreamem AS39566 w żadnej z 382 obserwowanych ścieżek.

To jest asymetria w czystej postaci i jest widoczna bez żadnego pomiaru z naszej strony.

## 3. Co zmierzyliśmy publicznie w kierunku „do nas" (i czego to nie dowodzi)

Surowe wyjścia: `_reports/20260904_reverse_traceroute_publiczne.txt`.
Węzły check-host.net w Amsterdamie, Frankfurcie, Langen i Norymberdze, 2026-09-04 11:43 UTC:

| pomiar | wynik |
|---|---|
| ICMP ping → 193.93.88.95 | de1 4/4, de4 4/4, de2 3/4, nl1 3/4 (RTT 16–30 ms) |
| TCP 443 → 193.93.88.95 | wszystkie 4 węzły connect OK, 20–27 ms |
| TCP 5739 → 193.93.88.95 | wszystkie 4 węzły connect OK, 16–30 ms |
| TCP 14368 → 66.33.22.230 (Railway, kontrola kierunku) | wszystkie 4 węzły connect OK, 3–16 ms |

**Uczciwie: to nie jest dowód na nic w sprawie epizodów.** Pomiar wypadł o 11:43 UTC,
czyli poza oknem awarii (te są 16–21 UTC). Pojedyncze zgubione pakiety ICMP (25% na dwóch
węzłach = 1 pakiet z 4) to za mało, żeby cokolwiek orzekać. Wniosek z tej sekcji jest jeden:
w chwili pomiaru trasa z AMS/FRA do nas była drożna na TCP.

**Czego nie dało się zdobyć narzędziem bezobsługowym:** pełnego traceroute z węzłów
w Amsterdamie i Frankfurcie do nas. Looking glass Arelion (`lg.twelve99.net`) jest
chroniony reCAPTCHA v3 i nie ma publicznego API; `api.hackertarget.com/mtr` odpowiada
`error valid key required`. Instrukcja ręcznego wyklikania trzech zapytań w LG Arelion
(traceroute AMS → 193.93.88.95, traceroute FRA → 193.93.88.95, wpisy BGP) jest w
`_reports/20260904_lg_arelion.txt`.

## 4. Faza B — pomiar ciągły z drugiego końca: GOTOWY, NIEURUCHOMIONY

Blokada jest jedna i nie leży po stronie kodu: **w `.env` nie ma żadnego klucza Railway,
a `railway` CLI nie jest zainstalowane** (sprawdzone 2026-09-04). Nie ma czym się zalogować.

Gotowe do użycia:
- `_diag_local/railway_reverse_probe/probe.py` — sonda, tylko biblioteka standardowa Pythona,
  cykl 30 s, cztery metryki (`tcp443`, `tls443` z SNI, `tcp5739`, kontrola `ctrl_leaseweb`),
  twarde budżety czasu, jedna linia na cykl na stdout w formacie zgodnym z naszym monitorem;
- `_diag_local/railway_reverse_probe/README.md` — instrukcja **dla człowieka**: wyklikanie
  serwisu w panelu Railway krok po kroku, gdzie czytać logi, jak serwis skasować,
  data wyłączenia **2026-09-08**, plus komendy `railway` CLI na wypadek tokenu.

**Tabela do wypełnienia po zebraniu danych** (to jest właściwy produkt zgłoszenia):

| okno epizodu (UTC) | nasz monitor: `railway_tcp` | sonda z Railway: `tcp443` | kontrola sondy `ctrl_leaseweb` | wniosek |
|---|---|---|---|---|
| (do wypełnienia) | | | | |

Reguła czytania, ustalona z góry, żeby nie dopasowywać wniosku do danych:
1. obie strony FAIL w tej samej minucie → problem dwukierunkowy / na styku, podstawa do
   eskalacji do Arelion;
2. nasz monitor FAIL, sonda OK → traci kierunek wychodzący od nas, czyli trasa wybierana
   przez nasz upstream;
3. sonda FAIL razem z `ctrl_leaseweb` FAIL → problem po stronie Railway jako hosta,
   nie trasy (analogicznie do naszych kontroli pingowych).

**Dwa zastrzeżenia, które trzeba wpisać w pismo razem z wynikiem:**
- nawiązanie połączenia TCP biegnie w obie strony, więc pojedynczy `FAIL` sondy nie
  wskazuje, która noga zgubiła pakiet — wartość ma dopiero **zestawienie czasów**
  z naszym monitorem;
- sonda uruchamia się jako serwis obliczeniowy Railway i **nie ma gwarancji**, że wychodzi
  w świat tym samym łączem, co publiczny proxy PostgreSQL (`switchback.proxy.rlwy.net`),
  z którym rozmawia czat. Sonda wypisuje na starcie region i identyfikator wdrożenia
  (`# srodowisko: ...`) i te linie idą do zgłoszenia razem z wynikiem.

## 5. Źródła

| co | gdzie |
|---|---|
| dane BGP (surowe + analiza) | `_reports/20260904_lg_arelion.txt`, API: `https://stat.ripe.net/data/looking-glass/data.json?resource=193.93.88.0/22` |
| publiczne sondy AMS/FRA | `_reports/20260904_reverse_traceroute_publiczne.txt`, trwałe linki check-host.net w środku |
| kod i instrukcja sondy | `_diag_local/railway_reverse_probe/` |
| recenzja krzyżowa sondy | `_docs/reviews/CODEX_REVIEW_20260904_CHAT-T-184_probe-trasa-powrotna.md` |
| pismo do smarthosta | `_reports/20260904_eskalacja_smarthost_167585.md` (sekcja 5 obiecuje ten pomiar) |
