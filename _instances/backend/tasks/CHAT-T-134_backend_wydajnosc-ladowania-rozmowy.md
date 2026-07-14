# CHAT-T-134 — wydajnosc ladowania rozmowy w panelu (10 s przy pierwszym otwarciu)

**Swiat:** do ustalenia po DIAGNOZIE (podejrzenie: BACKEND standalone; mozliwe PS).
**Instancja:** backend (diagnoza), potem wg wyniku.
**ADR:** ADR-117 (po 116; sprawdz ostatni numer przed zapisem).
**KROK 1 = DIAGNOZA. Fix dopiero po zmierzeniu przyczyny — NIE zgadywac.**

## Objaw (od Karola)

Panel recenzji, zakladka Rozmowy: klikniecie rozmowy, ktora NIE byla jeszcze
wyswietlona = ladowanie do ~10 s. Rozmowa juz raz obejrzana = laduje sie blyskawicznie.
"Tak nie da sie pracowac."

## Co JUZ zmierzone przez architekta (zawezone pole, oszczedza czas)

- `/api/health` PROD = 0.4 s (backend zdrowy).
- Polaczenie do Railway z serwera TERAZ = 0.2 s stabilne (ale Railway ma EPIZODYCZNE
  wieczorne straty pakietow 15-22 CEST — patrz CLAUDE.md / historia; objaw Karola byl 17:2x).
- `ConversationsController::detail()` robi tylko: `getBySessionId()` (lekki SELECT *)
  + `getConversationCost()` (2 proste SELECT + kurs USD→PLN Z BAZY, nie z NBP na zywo —
  zweryfikowane: ExchangeRateService::getUsdToPln() czyta divechat_exchange_rates,
  NIE uderza do NBP; NBP jest tylko w refreshFromNBP() wolanym osobno).
- `HTTP_TIMEOUT_SEC = 10` w module PS (AdminDivezoneChatController) — DOKLADNIE rowne
  obserwowanym 10 s. To znaczy: 10 s to prawdopodobnie TIMEOUT, nie normalny czas.
- PostgresConnection: singleton + lazy PDO; komentarz CHAT-T-107 wprost: "pierwsze
  zapytanie placi pelny koszt" polaczenia; retry SOFT 100/300 ms, connect_timeout HARD.

## Hipoteza wiodaca (do POTWIERDZENIA, nie zakladac)

"Pierwszy raz wolno, drugi blyskawicznie" = klasyczny koszt NAWIAZANIA polaczenia:
pierwsze zadanie w cyklu PHP-FPM tworzy `new PDO` do Railway; gdy Railway ma wieczorny
lag, ten connect czeka (retry/timeout) → do ~10 s. Drugie otwarcie: PDO singleton zyje
w tym samym procesie FPM → natychmiast. Alternatywy do wykluczenia: narzut file_get_contents
PS→backend z HMAC; cos w renderowaniu detalu po stronie PS; zimny autoloader.

## KROK 1 — DIAGNOZA (instrumentacja, zmierz PRZED fixem)

Cel: ustalic, GDZIE znika czas przy pierwszym otwarciu rozmowy. Zmierz kazdy odcinek
osobno, na PRODUKCJI, najlepiej w oknie wieczornym (15-22 CEST), gdy objaw wystepuje.

1. **Instrumentuj `ConversationsController::detail()`** — dodaj tymczasowy pomiar
   (microtime) wokol: (a) getBySessionId, (b) getConversationCost, (c) calosc.
   Loguj do error_log backendu (uwaga: ea-php84 pisze do chat.divezone.pl/public/error_log
   — patrz CLAUDE.md). Marker czasu np. "CHAT-T-134 detail sid=... total=Xms bySid=Yms cost=Zms".
2. **Zmierz sam PDO connect** — pierwszy `new PDO` do Railway w swiezym procesie CLI
   (`ea-php84`), kilka prob, w oknie wieczornym. Porownaj z 0.2 s zmierzonymi w dzien.
3. **Zmierz odcinek PS→backend** — w renderConversationDetail (modul) obloz pomiarem
   samo wywolanie file_get_contents (dzis timeout 10 s). Sprawdz, czy 10 s to czas tego
   wywolania (=backend wolny), czy cos przed/po nim (=PS wolny).
4. **Wniosek**: ktory odcinek dominuje. Zapisz zmierzone liczby w ADR-117 i w tasku.
   Dopiero teraz wybierz fix z KROK 2 pasujacy do PRZYCZYNY.

STOP po diagnozie — pokaz Karolowi zmierzona przyczyne + rekomendowany wariant fixu
PRZED implementacja. To karta z niepewna przyczyna, wiec bramka decyzji jest istotna.

## KROK 2 — FIX (wybierz wg zmierzonej przyczyny, NIE wszystkie naraz)

- **Jesli zimny PDO connect / lag Railway (hipoteza wiodaca):**
  - rozwazyc PDO::ATTR_PERSISTENT (persistent connection) — pierwsze zadanie nadal placi,
    ale kolejne procesy FPM wspoldziela; ostroznie z Railway (limit polaczen).
  - LUB skrocic connect_timeout i dodac szybki, czytelny fallback zamiast wiszenia 10 s
    (lepszy UX: 2 s + komunikat "baza chwilowo wolna" niz 10 s bialego ekranu).
  - LUB warm-up: lekki ping DB na starcie renderu listy, by connect byl gotowy przed klikiem.
  - Rozwazyc obnizenie HTTP_TIMEOUT_SEC w PS z 10 na np. 6 — szybszy, jawny blad zamiast
    dlugiego wiszenia (ale to leczy OBJAW, nie przyczyne — tylko jako uzupelnienie).
- **Jesli narzut PS→backend (HMAC/file_get_contents):** rozwazyc keep-alive / lzejszy transport.
- **Jesli render PS:** zoptymalizowac generowanie HTML detalu.

Dobor fixu = po KROKU 1. Nie implementowac spekulatywnie.

## Kryterium akceptacji
Pierwsze otwarcie NIEwyswietlanej wczesniej rozmowy < 2 s w typowych warunkach; w oknie
lagu Railway — zamiast 10 s wiszenia, szybki wynik lub czytelny komunikat < 3 s.
Zmierzona przyczyna udokumentowana w ADR-117.

## Deploy
Wg swiata ustalonego w diagnozie (ADR-089 dla backendu; reczny rsync + cache dla modulu PS).
NIE deployowac config/tools.php (dryf repo≠prod).

## Git
`git add` per sciezka (instrumentacja + fix + ADR); commit wg konwencji
`CHAT-T-134 backend: <opis wg przyczyny> (ADR-117)`; push. Osobny docs: po deployu.

## Domkniecie
Po zweryfikowanym fixie (pomiar PROD < 2 s): karta → "Zrobione". Instrumentacje czasowa
z KROKU 1 zostaw jako opcjonalny log (lub usun, jesli halasuje) — decyzja w tasku.

## Wynik — KROK 1 DIAGNOZA — DONE 2026-07-14 (pomiar 19:34-19:40 CEST, okno wieczorne)

Pomiar ZEWNETRZNY (CLI na VPS przez ea-php84, identyczny transport co modul PS:
file_get_contents+HTTPS+HMAC) — ZERO instrumentacji w kodzie prod, zero deployu.
Skrypty: `~/_diag/t134/t134_measure_{http,pdo}.php` (zostawione do weryfikacji fixu).

**Liczby:**
- RTT serwer→Railway: **~115 ms/zapytanie** (SELECT 1, stabilnie 114-116). PDO connect
  (TCP+TLS): **161 ms**. /api/health: 342 ms.
- GET detail: **900-980 ms** — NIEZALEZNIE od rozmiaru (1,2 KB vs 52 KB bez roznicy).
  = connect + 5-6 sekwencyjnych SELECT × 115 ms (rola, SELECT *, wiersz kosztow
  [DUPLIKAT — te same kolumny co SELECT *], COUNT usage, kurs USD).
- GET review-state: **441-466 ms** = connect + 2 SELECT. Model czas=round-tripy potwierdzony.
- Jedno otwarcie detalu = **2 sekwencyjne wywolania HTTP** (detail+review) = ~1,4 s
  backendu w ZDROWYCH warunkach.
- Monitor (railway_monitor_20260714.log): epizody egress smarthost 13:49-14:34 (148
  anomalii, zapytania 1,5-8 s lub FAIL) oraz **17:15-17:34 = okno objawu Karola**
  (TCP connect ~1 s SYN-retransmit, SELECT 1 do 2,3 s; github probe tez 1 s → siec
  wyjsciowa smarthost, nie Railway).

**Przyczyna:** ~10 round-tripow do Railway na jedno otwarcie × RTT chwilowe. W epizodzie
kazdy round-trip potrafi utknac 0,3-8 s → suma ~10 s (HTTP_TIMEOUT_SEC=10 obcina).
**Hipoteza wiodaca (zimny PDO w FPM) OBALONA** jako mechanizm per-rozmowa: "drugi raz
blyskawicznie" = prefetchCache JS panelu (CHAT-T-113, cache HTML per URL w przegladarce).
Wykluczone: rozmiar rozmowy, SQL, NBP na zywo, zimny autoloader.

Szczegoly + warianty fixu: ADR-117 (status PROPONOWANA, czeka na wybor wariantu).
STOP — zgodnie z taskiem czekam na decyzje Karola przed KROK 2.
