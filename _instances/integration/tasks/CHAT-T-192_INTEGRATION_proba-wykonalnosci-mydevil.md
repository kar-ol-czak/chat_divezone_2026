═══ CHAT-T-192 · INTEGRATION · próba wykonalności: Postgres na mydevil ═══

# CHAT-T-192 — czy baza czatu da się odtworzyć na mydevil

Autor zlecenia: architekt. Data: 2026-09-15.
Charakter: **próba wykonalności na kopii**. Produkcji nie dotykasz.
Railway czytasz wyłącznie do odczytu (`pg_dump`), niczego tam nie zmieniasz.

## 1. Po co to robimy

Epizody straty pakietów na trasie do Railway (karta Chat - 88) leżą na odcinku
Arelionu w Amsterdamie. Zmiana regionu Railway ich nie omija — zweryfikowane
2026-09-15: proxy dla US East (`66.33.22.223`) i dla EU West (`66.33.22.230`)
leżą w tym samym /24, mają identyczne RTT (32,8 wobec 32,8 ms) i identyczną
trasę hop po hopie, łącznie z hopami 9 i 10, na których ginie 95 % pakietów.

mydevil jest na zupełnie innej trasie. Pomiar z serwera sklepu, 2026-09-15 17:05:

```
  1.  193.93.88.254     0,1 ms   smarthost
  2.  195.149.232.111   6,6 ms
  3.  217.17.33.54      6,3 ms
  4.  212.91.10.53      7,2 ms
  6.  185.36.171.3      6,3 ms   s81.mydevil.net
```

Sześć hopów, w całości w Polsce, zero hopów `twelve99`. RTT 6,4 ms wobec 33 ms.
Port 5432 z serwera sklepu odpowiada.

To zadanie ma rozstrzygnąć JEDNO pytanie: czy baza da się tam przenieść.
Nie planujemy migracji, dopóki nie będzie odpowiedzi.

## 2. Stan zmierzony przed zleceniem

| | |
|---|---|
| Railway, serwer | PostgreSQL 18.3 |
| Railway, rozszerzenia | `vector` 0.8.1, `pg_trgm` 1.6, `unaccent` 1.1, `plpgsql` 1.0 |
| Railway, rozmiar bazy | 452 MB |
| mydevil, serwer | PostgreSQL 16.10 |
| mydevil, rozszerzenia | `vector` 0.8.0 (`vector.so` obecny), `pg_trgm`, `unaccent` |
| mydevil, `pg_dump` | 16.10 |
| Mac Karola | `pg_dump` 14.22, brak dockera |

Największe tabele na Railway:

```
divechat_product_embeddings   227 MB
divechat_nudge_events         129 MB
divechat_rate_limit            42 MB
encyclopedia_chunks            16 MB
divechat_conversations         13 MB
divechat_messages              10 MB
```

## 3. Główna przeszkoda

**Zejście o dwie wersje major, 18.3 → 16.10.** Do tego w całym łańcuchu nie ma
klienta w wersji 18, a `pg_dump` starszy od serwera odmawia pracy.

Spodziewaj się, że zrzut z osiemnastki będzie zawierał polecenia `SET` nieznane
szesnastce. **Nie zakładaj z góry których** — zanotuj każdy błąd dosłownie,
z numerem linii zrzutu. To jest główny wynik tego zadania, ważniejszy niż to,
czy odtworzenie się uda za pierwszym razem.

## 4. Kroki

### KROK 0 — klient PostgreSQL 18

`brew install postgresql@18` na Macu. Potwierdź wersję pomiarem, nie założeniem:
`/opt/homebrew/opt/postgresql@18/bin/pg_dump --version`.
Jeśli brew nie ma tej wersji, zgłoś i przerwij — nie kombinuj z obejściami.

### KROK 1 — baza testowa na mydevil

`devil pgsql db add divechat_test` przez SSH (`Host mydevil` w `~/.ssh/config`).
Polecenie pyta o hasło interaktywnie — spróbuj podać je przez stdin. Jeśli się
nie da, zgłoś i poproś Karola o ten jeden krok.

Hasło i parametry zapisz zgodnie z konwencją projektu, w `~/.config/divezone`
albo `.divezone_secrets`. **Nie wklejaj hasła do raportu ani do repo.**

### KROK 2 — zrzut SCHEMATU, bez danych

`pg_dump --schema-only --no-owner --no-privileges`, format plain.
To ma być pierwsza próba, bo trwa sekundy i natychmiast pokazuje niezgodności
wersji. Odtwórz na `divechat_test`. **Zanotuj każdy błąd dosłownie.**

Jeśli lecą błędy: dla każdego osobno ustal, czy to nagłówek zrzutu (do odfiltrowania),
czy realna cecha schematu niedostępna w 16 (wtedy blokada). Rozróżnienie
tych dwóch przypadków jest sednem zadania.

### KROK 3 — rozszerzenia

Na `divechat_test`: `CREATE EXTENSION vector; CREATE EXTENSION pg_trgm;
CREATE EXTENSION unaccent;`. Potwierdź wersje przez `SELECT extname, extversion
FROM pg_extension`. Sprawdź, czy indeksy wektorowe ze schematu (HNSW albo IVFFlat)
utworzyły się poprawnie — pgvector 0.8.0 wobec 0.8.1 to różnica minor, ale
sprawdzasz, nie zakładasz.

### KROK 4 — zrzut z danymi

Dopiero po przejściu kroków 2 i 3. Pełny zrzut, 452 MB.
Zmierz i podaj: czas zrzutu, rozmiar pliku, czas odtworzenia.

### KROK 5 — pomiar REALNĄ ścieżką

To ma biec **z serwera sklepu** (`divezone-dz` w `~/.ssh/config`), nie z Maca
i nie z mydevil. Chodzi o ścieżkę, którą realnie chodzi czat.

Wykonaj to samo zapytanie wektorowe (podobieństwo na `divechat_product_embeddings`,
`LIMIT 10`) po 30 razy przeciwko obu bazom i podaj p50 oraz p95 dla każdej:

- Railway: `switchback.proxy.rlwy.net:14368`
- mydevil: `s81.mydevil.net:5432`

Podaj też czas samego `SELECT 1` po 30 razy, osobno. To rozdziela koszt trasy
od kosztu zapytania.

### KROK 6 — sprzątanie

Bazę `divechat_test` ZOSTAW, będzie potrzebna do dalszych decyzji.
Zrzut z danymi skasuj z Maca po pomiarach, 452 MB.

## 5. Dowody wymagane w raporcie

1. Wersja `pg_dump` użytego do zrzutu, odczytana z `--version`.
2. Pełna lista błędów odtworzenia schematu, dosłownie, z rozróżnieniem
   „nagłówek do odfiltrowania" wobec „realna blokada".
3. `SELECT extname, extversion FROM pg_extension` na `divechat_test`.
4. Lista indeksów na `divechat_product_embeddings` po odtworzeniu, z potwierdzeniem,
   że indeks wektorowy istnieje i jest tego samego typu co na Railway.
5. Czasy z kroku 4: zrzut, rozmiar, odtworzenie.
6. Tabela p50 i p95 z kroku 5, cztery liczby: zapytanie wektorowe i `SELECT 1`,
   Railway i mydevil, wszystko mierzone z serwera sklepu.
7. Liczba wierszy w `divechat_product_embeddings` i `encyclopedia_chunks`
   po obu stronach, jako kontrola kompletności zrzutu.

## 6. Czego NIE robić

- Nie zmieniaj niczego na Railway. `pg_dump` czyta, i tylko tyle.
- Nie dotykaj `.env` na produkcji ani `config/` czatu. To zadanie nie przełącza
  aplikacji na nową bazę i nie ma prawa tego przygotować.
- Nie kasuj i nie czyść `divechat_nudge_events` ani `divechat_rate_limit`.
  Czyszczenie to osobna decyzja, po tym zadaniu.
- Nie wpisuj haseł do repo ani do raportu.
- Poza tym standardowo: `config/tools.php`, `config/routes.php`,
  `_ops/newtmp2_root/purge_litespeed.php`, ADR-y, cokolwiek w `newtmp2`.

## 7. Kryterium wyniku

Zadanie kończy się jednym z trzech werdyktów, wprost w raporcie:

- **PRZESZŁO** — schemat i dane odtworzone, rozszerzenia działają, pomiar z kroku 5
  gotowy. Wtedy architekt projektuje migrację.
- **PRZESZŁO Z ZASTRZEŻENIAMI** — odtworzone po odfiltrowaniu nagłówka zrzutu.
  Wypisz dokładnie, co odfiltrowałeś i dlaczego to bezpieczne.
- **NIE PRZESZŁO** — schemat używa czegoś, czego 16.10 nie ma. Wypisz co, z numerem
  linii zrzutu. Wtedy temat mydevil zamykamy i wracamy do Railway.

STOP po raporcie. Żadnych wniosków „to teraz migrujemy".

═══ CHAT-T-192 · INTEGRATION · próba wykonalności: Postgres na mydevil ═══
