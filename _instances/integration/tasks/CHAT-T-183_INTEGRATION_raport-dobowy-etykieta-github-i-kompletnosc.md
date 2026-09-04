# CHAT-T-183 — INTEGRATION: raport dobowy Railway, etykieta `github` kłamie + bramka kompletności doby

**Instancja:** integration. `_docs/scripts/railway_summary_mail.php` oraz punktowa poprawka
w `_docs/scripts/railway_monitor.php`. Wdrożenie do `~/_diag/` (trzeci świat, nie backend, nie newtmp2).
**Powiązane:** CHAT-T-182 (naprawa raportu, wdrożona 2026-09-04, md5 prod `7df010f467be2e424f98154044cbb83c`),
CHAT-T-119 (zrzuty incydentów), recenzja codex `_docs/reviews/CODEX_REVIEW_20260903_CHAT-T-182_raport-dobowy-railway.md`.
**Status:** DO ZROBIENIA.

---

## 1. ETYKIETA `github w tym oknie` STAWIA FAŁSZYWĄ TEZĘ (główny powód tego zadania)

`railway_summary_mail.php:300` wypisuje przy każdym oknie awarii:
```
$w[4] ? 'OK (Railway winny)' : 'TEZ FAIL (szerszy problem)'
```
W raporcie za dobę 2026-09-02 etykietę „TEZ FAIL (szerszy problem)" dostaje **10 z 13 okien**.
To jest nieprawda i mail sam sobie przeczy: dwie sekcje niżej wypisuje z tych samych sekund
`Railway 100% strat | Leaseweb 0% strat`. Wysłanie tego do hostingu jako „szerszy problem
u was" kończy się odbiciem w jednym zdaniu.

**Dowód, że `github FAIL` w epizodzie to artefakt własnych zrzutów, nie stan łącza.**
Zestawienie czasów `github FAIL` (UTC, linie pomiarowe) z czasami startu zrzutów
`incident_20260902_*.txt` (nazwa pliku = WAW, czyli UTC+2). Każdy zrzut odpala w tle
4 × `ping -c 15` plus `traceroute`, łącznie do ~90 s (`railway_monitor.php:119-128`).
```
start zrzutu 15:08:07 UTC -> github FAIL 15:11:18 (nastepny zrzut 15:11:07, +11 s)
start zrzutu 15:24:08 UTC -> github FAIL 15:24:38 (+30 s)
start zrzutu 15:27:07 UTC -> github FAIL 15:27:32 (+25 s), 15:27:52 (+45 s)
start zrzutu 15:29:07 UTC -> github FAIL 15:29:52 (+45 s)
start zrzutu 15:47:16 UTC -> github FAIL 15:48:17 (+61 s)
start zrzutu 15:58:07 UTC -> github FAIL 15:58:46 (+39 s)
start zrzutu 16:17:08 UTC -> github FAIL 16:17:34 (+26 s)
start zrzutu 16:20:07 UTC -> github FAIL 16:20:53 (+46 s)
start zrzutu 16:24:08 UTC -> github FAIL 16:24:21 (+13 s), 16:24:41 (+33 s)
start zrzutu 16:28:07 UTC -> github FAIL 16:28:37 (+30 s), 16:28:53 (+46 s)
start zrzutu 16:41:07 UTC -> github FAIL 16:41:32 (+25 s)
start zrzutu 16:44:07 UTC -> github FAIL 16:44:22 (+15 s), 16:45:08 (+61 s)
```
Wszystkie 40 `github FAIL` z okna epizodu mieszczą się 0-190 s po starcie któregoś zrzutu.
Poza epizodem `github FAIL` w tej dobie wystąpił 2 razy (13:17:33, 14:07:39) — pojedynczo,
bez zrzutu, bez korelacji z Railway.
Jedyny wyjątek: 15:04:55, czyli 15 s po starcie epizodu, przed pierwszym zrzutem.

To jest **korelacja, nie dowiedziona przyczynowość**. Alternatywę „epizod Railway psuje całe
łącze serwera" odrzucają kontrole pingowe w tych samych zrzutach: Leaseweb AMS, 1.1.1.1
i 8.8.8.8 mają 0% strat w każdym z 21 zrzutów.

### Co zrobić
Werdykt okna ma się opierać na **stratach pingu z pliku incydentu pokrywającego to okno**,
nie na `github`:
- kontrole (5.79.108.33 / 1.1.1.1 / 8.8.8.8) czyste, a Railway traci → `TRASA DO RAILWAY (kontrole czyste)`
- kontrole też tracą → `SZERSZY PROBLEM LACZA (kontrole tez traca)`
- brak zrzutu pokrywającego okno → `BRAK ZRZUTU — bez werdyktu`

`github` zostaje w raporcie jako osobna liczba w sekcji wskaźników, ale przy oknach
schodzi do formy przesłanki, bez słowa „problem", np.:
`github w oknie: N FAIL (uwaga: koreluje z wlasnymi zrzutami diagnostycznymi, nie traktuj jako dowodu)`.
Powyższą korelację wpisz w docblock funkcji budującej okna, z datą i podstawą (doba 2026-09-02).

---

## 2. BRAMKA KOMPLETNOŚCI DOBY (codex Finding 1, HIGH — przyjęte)

Dziś raport akceptuje dowolny plik z choć jedną linią pomiarową i potrafi ogłosić
`OCENA DOBY: CZYSTA` dla logu siedmiogodzinnego albo takiego, w którym monitor stał kilka godzin.
Licznik `$rejected` (linia 93) i „najwieksza przerwa" już są — brakuje progu i konsekwencji.

Wprowadź jawną ocenę pokrycia doby:
- oczekiwana liczba próbek = długość doby w sekundach / mediana odstępu (obie już liczone)
- pokrycie = próbki rzeczywiste / oczekiwane
- pokrycie < 95% ALBO największa przerwa > 15 min ALBO `$rejected` > 0
  → `OCENA DOBY: NIEPELNA (pokrycie X%, najwieksza przerwa Ym, linii nieparsowalnych Z)`
  i **zakaz** słowa `CZYSTA` w tej samej dobie
- `$rejected` > 0 dodatkowo wypisz jako ostrzeżenie o możliwej zmianie formatu logu
  (to jest dokładnie awaria, która ukryła się na 67 dni w CHAT-T-182)

Progi 95% i 15 min zapisz jako nazwane stałe u góry pliku, nie liczby w kodzie.

---

## 3. WYŚCIG O PÓŁNOCY W `railway_monitor.php` (codex Finding 2, MEDIUM — teraz w zakresie)

Monitor bierze znacznik WAW przed sondami (`railway_monitor.php:239`), znacznik UTC po nich
(`:252`, `:259`), a nazwę pliku docelowego wybiera dopiero przy zapisie (`:264`, `:75`).
Cykl przekraczający północ trafia więc do pliku doby D+1 ze znacznikiem WAW z doby D.
Skala: jedna próbka na dobę. Skutek: raport doby D gubi tę próbkę, doba D+1 dostaje obcą.

Popraw tak, żeby nazwa pliku wynikała z **tego samego** znacznika, który idzie w pole WAW linii.
Nie zmieniaj formatu linii, interwału ani logiki alertów. To ma być poprawka kilkulinijkowa.

Po zmianie monitor wymaga restartu — patrz sekcja WDROŻENIE.

---

## 4. POZA ZAKRESEM (świadomie odłożone)

- Kanoniczny znacznik próbki (codex Finding 5): etykiety okien z pola WAW, długości z UTC.
  Odstępstwo przyjęte przez architekta w CHAT-T-182, zostaje. Nie ruszaj.
- Dzielenie okien po restartach monitora (codex Finding 4): dziś raport podaje `max przerwa`
  w oknie, co wystarcza do odczytania, że okno zawiera nieobserwowany czas. Nie ruszaj.
- Stały harness testowy z wstrzykiwanym zegarem i stubem maila (codex 6-7): osobna decyzja,
  nie w tym zadaniu.
- Zależność parsera pingów od GNU ping (codex Finding 8): plik działa wyłącznie na tym
  serwerze, degraduje się do `n/d`. Nie ruszaj.

---

## TESTY AKCEPTACYJNE

Liczby zmierzone niezależnie 2026-09-04, muszą zostać BEZ ZMIAN po Twojej poprawce
(zmieniasz etykiety i ocenę doby, nie pomiar):

**`--dry-run 20260902`** — próbki 14331, railway_tcp 84, pg_select1 164, pg_settings 168,
pg_chiptree 171, pg_upsert 173, github 42, errno=110 84, alertów 21, zrzutów 21,
13 okien w przedziale 17:04-18:46 WAW, najdłuższe 18m 30s.
Po zmianie: żadne z 13 okien nie ma dostać etykiety sugerującej szerszy problem łącza,
bo wszystkie 21 zrzutów pokazuje Leaseweb 0% strat.

**`--dry-run 20260903`** — próbki 15251, railway_tcp 1, pg_select1 1, github 0,
errno=110 1, alertów 0, zrzutów 0, 1 okno 15:38:16 WAW.
Po zmianie: doba nadal `CZYSTA` (pokrycie ~100%, największa przerwa 1m 3s, `$rejected` 0),
a jedyne okno ma etykietę `BRAK ZRZUTU — bez werdyktu`.

**Test bramki kompletności:** przytnij kopię logu 20260903 do pierwszych 7 godzin
w katalogu tymczasowym i pokaż, że raport mówi `NIEPELNA`, a nie `CZYSTA`.
Kopia w /tmp, nie w `~/_diag/`.

## RECENZJA KRZYŻOWA
`/codex` na diffie przed commitem. Wynik i niezgody NIEROZSTRZYGNIĘTE do architekta.

## WDROŻENIE
Dwa pliki, oba do `/home/divezone/_diag/`, osobno, scp, port 5739. Bez rsync katalogu.
1. Backup obu plików z sufiksem `.bak_YYYYMMDD`.
2. **STOP — czekaj na słowo „deployuj" (ADR-089).**
3. `ea-php84 -l` na obu. md5 local == prod na obu.
4. Restart monitora: `pkill -9 -f "railway_monitor[.]php"`, guard wskrzesi w ~60 s.
   Dowód: wpis w `~/_diag/guard.log`, nowy nagłówek `# metryki:` w logu doby, rosnąca liczba
   linii w dwóch odczytach w odstępie ~30 s, żywy pid.
   Restart jest konieczny podwójnie: `railway_monitor.php` się zmienia ORAZ robi `require`
   `railway_summary_mail.php` przy starcie (linia 50).
5. `--dry-run` na obu dobach z serwera, porównanie z liczbami wyżej.
6. Ścieżki bez `--dry-run` NIE uruchamiaj.

Rollback: `cp ~/_diag/<plik>.bak_YYYYMMDD ~/_diag/<plik>` dla obu + pkill monitora.

## GIT
`git add` per ścieżka, nigdy `git add .`. Commit `fix(CHAT-T-183): ...`, osobny `docs(CHAT-T-183): ...`
na `_docs/21_STATUS_PROJEKTU.md` (dopisz NA GÓRZE). `git push origin main`.

## NIE RUSZAĆ
`railway_monitor_guard.sh`, crontab, logi i pliki incydentów w `~/_diag/`,
`_ops/newtmp2_root/purge_litespeed.php`, `standalone/config/routes.php`,
`standalone/config/tools.php`, pliki ADR, format linii logu monitora, interwał, logika alertów.

## RAPORT KOŃCOWY
Pełne wyjście obu `--dry-run` z serwera, wynik testu bramki kompletności, wynik `/codex`,
md5 przed i po dla obu plików, dowód restartu monitora.
