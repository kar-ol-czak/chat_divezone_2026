═══ CHAT-T-188 · INTEGRATION · DEPLOYED (mail do smarthosta WLACZONY) ═══

# CHAT-T-188 — bramka maila do smarthosta po TREŚCI, nie po markerze końca

**Stan:** WDROŻONE 2026-09-10 10:22 CEST (autoryzacja Karola, ADR-089), commit `a61cb19`.
**Automatyczna wysyłka do dostawcy WŁĄCZONA** (`hosting@smarthost.pl`). Dowody: sekcja 8.
**KROK 0:** `git pull --rebase` odmówił (niezacommitowane zmiany innych sesji: `purge_litespeed.php`,
`routes.php` — nietknięte); `git fetch` + `git rev-list --left-right --count origin/main...HEAD` = `0 0`.

---

## 1. Kryterium bramki — wyprowadzone z realnych plików

Ściągnąłem trzy wzorce z produkcji (READ-ONLY) i jeden z własnych testów. Wiersze, na których stoi kryterium:

**KOMPLETNY** (`/tmp/t186/incident_055459.txt`, zrzut z T-186 spod cronowego PATH), linie 119-122:
```
=== mtr ICMP 66.33.22.230 (strata per hop) ===
Start: 2026-09-10T07:55:59+0200
HOST: divezonededyk.smarthost.pl Loss%   Snt   Last   Avg  Best  Wrst StDev
  1.|-- 193.93.88.254               0.0%    20    0.1   0.1   0.1   0.2   0.0
```

**PUSTY** (`~/_diag/mtr_baseline_20260909.txt`, ten, który przeszedł starą bramkę), linie 7-11:
```
=== mtr ICMP 66.33.22.230 (strata per hop) ===
/usr/sbin/mtr: Failure to start mtr-packet: Invalid argument

=== mtr TCP 66.33.22.230:14368 (ta sama sciezka na porcie, ktory realnie pada) ===
/usr/sbin/mtr: Failure to start mtr-packet: Invalid argument
```

**PINGI** (`~/_diag/incident_20260902_172907.txt`, realny epizod), linia 13:
```
15 packets transmitted, 0 received, 100% packet loss, time 14323ms
```

**Kryterium:** blok dowodowy przechodzi, gdy ma **stratę pingu do Railway ORAZ do ≥1 kontroli**
(`--- <IP> ping statistics ---` + `% packet loss`) **oraz ≥1 sekcję mtr z wierszem `HOST:`**.

Rozstrzyga **`HOST:`**, a nie liczba hopów — i to jest decyzja z pomiaru, nie z gustu (sekcja 2).

## 2. ⚠️ Fałszywy negatyw, który znalazłem sam, zanim wrócił codex

Pierwsza wersja wymagała ≥1 wiersza hopa w każdej sekcji. Sprawdziłem, jak mtr zachowuje się
przy celu **całkowicie nieosiągalnym** (2026-09-10, produkcja):

```
env -i PATH=/usr/sbin:/usr/bin:/bin /usr/sbin/mtr --report --report-cycles 5 -n 198.51.100.1
   1  Start: 2026-09-10T09:53:19+0200
   2  HOST: divezonededyk.smarthost.pl Loss%   Snt   Last   Avg  Best  Wrst StDev
   (i nic wiecej — ZERO wierszy hopow)
```

Czyli **najcięższa awaria** (nic nie odpowiada) dałaby zrzut, który moja bramka by zablokowała —
zabrałaby dowód dokładnie wtedy, kiedy jest najbardziej potrzebny. Kryterium zmienione na `HOST:`:
ten wiersz pojawia się wyłącznie, gdy mtr **ruszył** i wypisał raport, a w pliku z 09.09 go nie ma.
Rozróżnienie zostaje, fałszywy negatyw znika.

## 3. Wybrany kanał powiadomienia (punkt 2 zlecenia) i uzasadnienie

**Wybrałem istniejący mail alertowy monitora** (`sendAlertMail`, ten sam nadawca i adresaci
co alerty epizodowe), a **nie** raport dobowy.

- **Kryterium „tego samego dnia" spełnia tylko alert.** Zrzut nie przechodzi bramki około
  3-10 minut po zakończeniu epizodu. Raport dobowy wychodzi o 07:00 **następnego dnia**
  i opisuje dobę poprzednią — Karol dowiedziałby się nazajutrz.
- **Nie mnoży kanałów:** to ten sam mechanizm i ci sami odbiorcy co dotychczasowe alerty,
  tylko nowy powód. Nie powstaje żadna nowa ścieżka do pilnowania.
- **`railway_summary_mail.php` NIE jest ruszony** — zero zmian w raporcie dobowym.
  Linia `### MAIL-SMARTHOST pominiety ...` i tak ląduje w logu głównym, więc ślad zostaje.

Alert idzie **raz na epizod**, dopiero gdy minie twardy termin (czyli zrzut na pewno się już
nie domknie), z werdyktem bramki w treści.

## 4. Wyniki testów (wszystko w `/tmp`, `~/_diag` nietknięty)

| # | przypadek | wynik | zgodne z oczekiwaniem |
|---|---|---|---|
| 1 | zrzut kompletny z mtr | **PASS** | tak |
| 2 | pusty baseline z produkcji 09.09 | **BLOK** | tak |
| 2b | zrzut produkcyjny 02.09 (pingi OK, brak sekcji mtr — sprzed T-185) | **BLOK** | tak |
| 3 | pingi OK, obie sekcje mtr = sam komunikat błędu | **BLOK** (`sekcji mtr z wierszem HOST: 0 z 2`) | tak |
| 4 | mtr OK, pingi ucięte (brak linii `packet loss`) | **BLOK** (`brak pomiaru strat pingu do Railway`) | tak |
| 4b | mtr szczątkowy z realnego incydentu (`Address in use`, 5 hopów) | **PASS** | tak |
| 5 | ponowienie baseline'u: pusty plik z dzisiejszą datą | **dokładnie 1 ponowienie**, potem cisza | tak |
| 6 | **całkowita awaria: mtr ruszył, zero hopów** | **PASS** | tak (patrz sekcja 2) |
| 7 | mtr ICMP OK + mtr TCP padnięty | **PASS** | tak (po recenzji) |
| 8 | baseline: nieudana próba + **dopisane** udane ponowienie | **PASS** | tak (po recenzji) |

**Test 4 — dlaczego blokada jest właściwa.** Bez linii `packet loss` centralna tabela maila
(„straty do Railway vs kontrole") jest pusta, a to jest cała teza wiadomości do dostawcy.
Sam mtr trafia do maila jako materiał uzupełniający, nie jako podstawa twierdzenia. Wysyłka
wiadomości, której główna tabela mówi cztery razy „brak pomiaru", byłaby dokładnie tym,
przed czym to zadanie ma chronić.

**Test 5 obejmuje też restart:** znacznik `mtr_retry_YYYYMMDD.done` leży na dysku, więc limit
„jedno ponowienie na dobę" przeżywa restart monitora (a ten restartuje się kilka razy dziennie).

## 5. RECENZJA `/codex` (gpt-5.6-sol, xhigh) — „request changes", 1× CRITICAL, 5× HIGH

Recenzent miał rację w rzeczach, które realnie gubiłyby dowody. **Przyjąłem siedem uwag:**

| # | uwaga | co zrobione |
|---|---|---|
| 1 CRITICAL | plik baseline jest **dopisywany** (`>>`), więc nieudana próba + udane ponowienie dalej dawały „pusty" — ponowienie nie mogło wyleczyć pliku | warunek: **≥1 sekcja z pomiarem**, nie „wszystkie sekcje dobre" (test 8) |
| 3 HIGH | jedna sekcja mtr dobra + druga padnięta była odrzucana, mimo że niesie dowód | to samo złagodzenie (test 7) |
| 4 HIGH | zrzut mający tylko blok powrotu był prezentowany jako „z chwili awarii"; a niekompletny blok startu blokował kompletny blok powrotu | bramka bierze pierwszy blok, który przechodzi (start ma pierwszeństwo), a etykieta w mailu mówi, **z którego bloku** jest tabela |
| 5 HIGH | nieudany `mail()` kasował epizod z kolejki | przy `mail-failed` epizod zostaje do twardego terminu |
| 6 HIGH | termin porzucenia liczył się od nowa po każdym restarcie — ostrzeżenie mogło nie pójść nigdy | termin jest **absolutny** (`deadlineAbs`) i przeżywa restarty |
| 2 HIGH | „jedno ponowienie na dobę" żyło tylko w pamięci procesu; sprawdzany plik odtwarzany z dzisiejszej daty | znacznik na dysku + zapamiętana nazwa sprawdzanego pliku |
| 7 MEDIUM | zwolnienie slotu po północy zdejmowało licznik z niewłaściwej doby | `smarthostRelease()` dostaje **dobę rezerwacji** |
| 8 MEDIUM | log mówił „wysylam" przed werdyktem bramki, a ostrzeżenie twierdziło „BEZ DANYCH" niezależnie od powodu | log mówi „probuje"; ostrzeżenie cytuje **werdykt bramki** i wymienia, czego bramka wymaga |

### NIEROZSTRZYGNIĘTE — decyduje architekt

- **Lokalizacja i nietypowe wyjścia `ping` (codex #3, część).** Bramka rozpoznaje angielskie
  `packet loss`. Na tym hoście `ping` pisze po angielsku nawet przy polskim `date` (widać
  w każdym zrzucie), a zrzut leci pod cronem bez `LANG`, więc ryzyko jest małe — ale wyjście
  typu `connect: Network is unreachable` (brak linii statystyk) bramka potraktuje jako brak
  pomiaru i **zablokuje wysyłkę**. Świadomie tego nie rozluźniam: taki zrzut i tak nie ma
  tabeli strat, a Karol dostanie o nim alert. Do decyzji, czy dokładać wariant awaryjny.
- **Gwarancje „dokładnie raz" dla ostrzeżenia (codex #5).** `mail()` plus plik JSON nie dają
  exactly-once. Dziś: ostrzeżenie idzie raz na epizod w normalnym przebiegu, ale awaria między
  wysłaniem a zapisem kolejki mogłaby je zdublować. Uznaję duplikat ostrzeżenia do Karola za
  mniejsze zło niż jego brak — do potwierdzenia.
- **`MTR_PACKET` zamiast `PATH`** (poza zakresem wg zlecenia) — nadal aktualna opcja na przyszłość.
- Propozycje recenzenta dotyczące pełnej maszyny stanów z gradacją „complete / meaningful-partial /
  tool-failure / still-writing" i testów z dwoma równoległymi monitorami — poza zakresem tego zadania.

## 6. CZEGO NIE SPRAWDZIŁEM

- Bramki na **realnym epizodzie** — od 09.09 żadnego nie było; wszystkie testy na zrzutach
  realnych, ale wywołanych ręcznie albo spreparowanych.
- Zachowania przy dwóch równoległych instancjach monitora (rezerwacja slotu jest pod `flock`,
  ale testu z dwoma procesami nie robiłem).
- Wysyłki na prawdziwy adres smarthosta — `.env` nietknięty, funkcja nadal wyłączona.

## 7. WDROŻENIE — CZEKA NA AUTORYZACJĘ

Jeden plik `/home/divezone/_diag/railway_monitor.php`, backup `.bak_YYYYMMDD`,
**md5 przed odczytam z produkcji w chwili deployu** (ostatnio widziane: `7290b4a0c2bdd924451f7192b7d3f0ec`),
`ea-php84 -l`, md5 local==prod, restart monitora i cztery dowody jak w CHAT-T-183.

Po wdrożeniu Karol może dopisać `SMARTHOST_TICKET_MAIL=hosting@smarthost.pl` do `.env`
i zrestartować monitor — bramka jest tym, co blokowało tę linijkę.

---

## 8. WDROŻENIE — WYKONANE 2026-09-10 (KROK 8)

```
Stan przed (08:20:10 UTC, odczytany Z PRODUKCJI):
  md5 monitora:        7290b4a0c2bdd924451f7192b7d3f0ec
  BASELINE nohup.out:  766 linii | 139 x Fatal error | 0 x Parse error
  .env:                109 linii, wystapien SMARTHOST: 0

Backup:  railway_monitor.php.bak_20260910b   md5 7290b4a0c2bdd924451f7192b7d3f0ec (zgodny)
Lint:    ea-php84 -l  ->  No syntax errors detected
md5 PO:  1215c89d60cf38d734c7caac3b5e63e6  ==  md5 lokalny  ==  wartosc zweryfikowana przez architekta
Kontrola tresci na produkcji: 3 funkcje bramki obecne w pliku
```

### Wlaczenie maila do dostawcy (`.env`)

```
backup:            .env.bak_20260910
dopisane klucze:   SMARTHOST_TICKET_MAIL, SMARTHOST_TICKET_ID     (wartosci NIE wypisywane)
linie:             109 -> 111  (dokladnie +2)
diff backup/nowy:  wylacznie te dwa klucze
uprawnienia:       644 divezone:divezone — bez zmian
```

Plik odtworzony deterministycznie z backupu (kopia + dwie linie), nie edytowany blokowo.
**Uwaga do wlasnej roboty:** pierwsze `printf` zaczynalo sie od `\n` i dalo +3 linie zamiast +2
(pusta linia separatora). Poprawione od razu — stan koncowy to dokladnie +2, zgodnie z kryterium.

### Cztery dowody restartu + piaty wymagany

```
(a) guard.log:  [guard] 2026-09-10 10:22:01 proces martwy (log 60s) -> restart pid=1156137
(b) nowy START 2026-09-10 10:22:01 WAW; naglowkow "# metryki:" 2 -> 3
(c) log rosnie: 6618 linii (10:22:08) -> 6625 (10:22:43), przyrost 7
(d) monitor_nohup.out: 766 / 139 Fatal / 0 Parse  ==  baseline  =>  zero nowych bledow
(e) linia startowa monitora:
    # CHAT-T-185: mtr 20 cykli do 66.33.22.230:14368 | baseline mtr @ 18:30 UTC
      | mail do smarthosta: wlaczony (hosting@smarthost.pl), max 3/dobe
```

Punkt (e) dowodzi, ze monitor odczytal `.env` przy starcie. Zaden testowy mail do dostawcy
**nie zostal wyslany** — brak plikow `smarthost_mail_*.count` / `smarthost_sent_*.list` to potwierdza.

## 9. ⚠️ KOREKTA MOJEGO WCZESNIEJSZEGO ZDANIA — i dowod bramki Z PRODUKCJI

W raportach T-186 i T-188 pisalem, ze od wdrozenia T-185 nie bylo zadnego epizodu.
**To nieprawda.** W logu z dzisiaj jest epizod:

```
### ALERT 22:05:07 UTC / 00:04:57 WAW | pg_select1 FAIL x3
### RECOVERY 22:10:33 UTC / 00:10:32 WAW
### OKNO NIEDOSTEPNOSCI: start 00:04:27, koniec 00:10:32, czas trwania 6m 5s
### MAIL-SMARTHOST pominiety | okno 22:04-22:10 UTC | brak SMARTHOST_TICKET_MAIL (funkcja wylaczona)
```

Epizod trwal **6m 5s**, Railway **100% strat**, wszystkie trzy kontrole **0%**. Wypadl w nocy
2026-09-10, czyli PRZED poranna poprawka PATH z CHAT-T-186 — dlatego zrzut
`incident_20260910_000427.txt` ma komplet pingow, ale obie sekcje mtr zawieraja sam
`Failure to start mtr-packet`.

**To jest idealny test bramki na realnych danych produkcyjnych.** Puscilem na tym pliku
**kod juz wdrozony** (funkcje wyciete z `/home/divezone/_diag/railway_monitor.php`):

```
blok [epizod-start pg_select1] ts=2026-09-09 22:05:07
   Railway=100.0% Leaseweb=0.0% -> BRAMKA: BLOKUJE (sekcji mtr z wierszem HOST: 0 z 2)
blok [epizod-koniec]           ts=2026-09-09 22:10:33
   Railway=0.0%   Leaseweb=0.0% -> BRAMKA: BLOKUJE (sekcji mtr z wierszem HOST: 0 z 2)
```

Czyli: **gdyby maila wlaczyc bez tej bramki, dostawca dostalby dzis wiadomosc z pustym mtr.**
Bramka to zatrzymala na realnym, nie spreparowanym materiale.

## 10. CO SIE WYDARZY SAMO

- **Pierwszy prawdziwy mail do smarthosta** pojdzie przy najblizszym epizodzie, po przejsciu
  bramki (limit 3/dobe, kopia do Karola, temat `[DIVEZONE #167585] ...`).
- Gdy zrzut bramki nie przejdzie, Karol dostanie **tego samego dnia** ostrzezenie
  `[DIVECHAT MONITOR] Zrzut nie przeszedl bramki` z werdyktem.
- **Dobowy mtr odniesienia o 18:30 UTC** — to wciaz otwarty dowod koncowy z CHAT-T-186.

### Rollback

```
cp ~/_diag/railway_monitor.php.bak_20260910b ~/_diag/railway_monitor.php
cp ~/public_html/chat.divezone.pl/.env.bak_20260910 ~/public_html/chat.divezone.pl/.env   # wylacza maila
pkill -9 -f "railway_monitor[.]php"
```

═══ CHAT-T-188 · INTEGRATION · DEPLOYED (mail do smarthosta WLACZONY) ═══
