# TASK-ENC-014 — Errata fizyki wyporu: dane bota + hasło metodyczne + reguła promptu

**Instancja:** backend
**Typ:** errata danych + nowe hasło bazy + reguła SystemPrompt + re-embed pgvector
**Priorytet:** P1 (bot cytuje błąd merytoryczny na PROD)
**Źródła prawdy:** `_docs/33_fizyka_wypornosci_zrodla.md`, `_docs/34_wsad_do_encyklopedii_wypornosc.md`, `_docs/35_metoda_doboru_wypornosci.md`, ADR-091

---

## KONTEKST

Bot błędnie wiązał suchy skafander z większą wypornością worka (pytanie: jacket do butli 18l + suchy). Diagnoza (sesja CC + weryfikacja źródeł forów nurkowych i Wikipedii) wykazała:
1. Odwróconą fizykę (suchy = przypadek MAŁEJ wyporności, gruba pianka DUŻEJ).
2. Brak metody liczenia w danych bota → model dopowiada liczby z wiedzy bazowej (pozostałość "suchy + duża butla → 20+ kg" NIE znika po samej poprawce danych).
3. Wcześniejsza propozycja liczb dla singla (16-20 kg) ZAWYŻONA (myliła single z twinem).

Lekarstwo (decyzja 50d): poprawa danych + nowe hasło metodyczne + reguła promptu blokująca ekstrapolację.

## STAN WYJŚCIOWY (z poprzedniej sesji CC, ZABLOKOWANEJ)

- Pliki raw JSON (JACKET, ZESTAW_SKRZYDLO_SINGLE, ZESTAW_DO_NURKOWANIA, ZESTAW_SKRZYDLO_TWIN) MAJĄ już edycje (7 zmian, uncommitted).
- Railway pgvector JUŻ re-embedowane tymi zmianami (20 chunków).
- Nic nie zacommitowane.
- UWAGA: część edycji wymaga KOREKTY liczb (single w dół do 13-16 l). KROK 1 to robi.

## ARCHITEKTURA (przypomnienie)

- Runtime bota czyta WYŁĄCZNIE `encyclopedia_chunks` w pgvector. Edycja raw JSON niewidoczna do re-embedu.
- BAZA = Railway (`DATABASE_URL` w .env, `switchback.proxy.rlwy.net`). NIE Aiven. Skrypt czyta `DATABASE_URL`, nie podmieniać.
- Chunking: hasło = 5 chunków [definition, synonyms, purchase, faq, seller]. `--mode changed` wykrywa zmiany po source_hash.

---

## ZAKRES

### CZĘŚĆ 1 — Korekta danych raw JSON (liczby + relikt "suchy")

**1a. ZESTAW_SKRZYDLO_SINGLE.json — KOREKTA LICZB (decyzja 51d):**
Poprzednia sesja wpisała worek 16-20 kg dla singla. ZAWYŻONE. Poprawne wg dok. 35 sekcja B:
- single 12-15 l + suchy → worek 13-16 l (13 l = najmniejszy produkowany worek, dolna granica rynkowa).
Skoryguj wszystkie pola single, gdzie jest 16-20 kg, na 13-16 l. Uzasadnienie: dla suchego rządzi ciężar gazu, nie kompresja.

**1b. WERYFIKACJA reliktu "suchy → większy worek" w 4 plikach:**
JACKET, ZESTAW_SKRZYDLO_SINGLE, ZESTAW_DO_NURKOWANIA, ZESTAW_SKRZYDLO_TWIN. Żaden zwrot nie może wiązać suchego z większą wypornością worka. Kierunek: dok. 33 sekcja 1 + dok. 35 sekcja C.

**1c. NIE RUSZAĆ:** KIESZENIE_ZINTEGROWANE (suchy → więcej BALASTU = prawda), TWINSET, DUMP_VALVE_DRYSUIT, ZESTAW_AUTOMATU_TWIN.

### CZĘŚĆ 2 — Nowe hasło metodyczne (decyzja 57a)

Nowy plik: `data/encyclopedia/v3/gen_v2/raw/DOBOR_WYPORNOSCI_WORKA.json`.
- Rozpisz pola wg FORMATU sąsiednich plików (podejrzyj SKRZYDLO.json / JACKET.json).
- TREŚĆ wyłącznie z dok. 35 sekcje A, B, C. Pełne liczby pomocniczo w dok. 33.
- Styl: PL, krótkie pola produktowe, ZERO półpauz, ZERO średników. NIE przeklejać całej fizyki z dok. 33.
- concept_key + concept_number: nadać kolejny wolny (sprawdź numerację sąsiednich plików).
- Musi zawierać: wzór gazu, rozróżnienie suchy vs gruba pianka, realne dobory (13-16 l single, ~18 l twin), regułę partnera, odesłanie do konsultacji przy grubej piance/nietypowej konfiguracji.

### CZĘŚĆ 3 — Reguła SystemPrompt (decyzja 58a)

Plik: `standalone/src/Chat/SystemPrompt.php`.
- Wstaw NOWĄ sekcję, brzmienie DOKŁADNIE wg dok. 35 sekcja D (blok między `---`).
- MIEJSCE: tuż PRZED sekcją "FAKTY DOMENOWE (nie myl:)", po akapicie o INT/DIN przy automatach (kończy się "...NIE dodawaj wzmianek o INT ani o konfiguracji z wężami DIN/INT.").
- Wcięcie dostosować do heredoc PROMPT (jak sąsiednie sekcje).
- W prompcie NIE ma wcześniejszej sekcji ostrożności wyporności. Wstawiasz od zera.

---

## STYL TREŚCI (twardo)

- Polski. ZERO półpauz. Myślnik tylko wg ortografii PL. Zero średników.
- Pola produktowe krótkie. Reguła promptu = instrukcja dla modelu, NIE tekst do wyrzucenia klientowi.
- Zero fabrykacji. Liczby wyłącznie z dok. 35. Spoza zakresu → STOP i pytanie do Karola.

---

## KRYTERIUM AKCEPTACJI

1. Single skorygowany na 13-16 l (1a). Relikt usunięty z 4 plików (1b).
2. Nowy plik DOBOR_WYPORNOSCI_WORKA.json wg dok. 35 i formatu sąsiednich.
3. Reguła wyporności w SystemPrompt.php we wskazanym miejscu, brzmienie wg dok. 35 D.
4. `--mode changed` re-embedował dotknięte + nowe hasło na Railway (log: ZMIENIONE + NOWE).
5. `--mode check` → exit 0.
6. Test bota PROD: wyporność jacketu do butli 18l + suchy. Bot NIE twierdzi że suchy wymaga większego worka.
7. SystemPrompt.php = standalone backend → deploy wg ADR-089 (rsync + backup + STOP) jeśli wymaga wdrożenia na chat.divezone.pl.
8. Dwa commity wg konwencji. git add wąsko po ścieżkach.

---

## STOP POINTY

- STOP 1 (przed re-embedem): po korekcie danych (cz. 1) + haśle (cz. 2) pokaż Karolowi diffy WSZYSTKICH zmian raw JSON + treść nowego hasła + fragment SystemPrompt z regułą. Czekaj na "wykonaj re-embed". Re-embed zużywa API i pisze na PROD Railway.
- STOP 2 (przed deployem SystemPrompt na PROD): jeśli reguła wymaga rsync na chat.divezone.pl (ADR-089), pokaż md5/diff, czekaj na zgodę przed rsync.

---

## KROKI (skrót, pełny prompt CC w czacie)

KROK 0: pull, read dok. 33/34/35 + ADR-091, potwierdź numer tasku w git log, potwierdź DATABASE_URL=Railway.
KROK 1: korekta danych (1a/1b), weryfikacja reliktu.
KROK 2: nowe hasło DOBOR_WYPORNOSCI_WORKA.json (cz. 2).
KROK 3: reguła SystemPrompt (cz. 3).
KROK 4: STOP 1 — diffy do Karola, czekaj na zgodę.
KROK 5: re-embed --mode changed (Railway), potem --mode check (exit 0).
KROK 6: test bota PROD (kryterium 6).
KROK 7: deploy SystemPrompt wg ADR-089 jeśli trzeba (STOP 2).
KROK 8: dwa commity + push, state update, raport.
