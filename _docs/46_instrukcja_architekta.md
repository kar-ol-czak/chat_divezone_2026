# 46. Instrukcja architekta — od czego zacząć każde zadanie

**Dokument trwały. Czytany przez architekta (sesja czatu z Karolem), nie przez CC.**
CC ma swój odpowiednik: `CLAUDE.md`.

**Po co istnieje:** żeby nie robić rekonesansu przy każdym zadaniu i nie pisać
narzędzi, które już są. Powstał 2026-07-17, bo architekt cały dzień pisał SQL-e
od zera, nie wiedząc, że `_docs/42` + `_diag_local/chat_verification/` istnieją
od 2026-07-14 — odsyłacz do nich był tylko w `CLAUDE.md`, czyli w kanale CC.

**Ten plik jest CIENKI.** Mówi, **który dokument na które zadanie**. Nie kopiuje
ich treści — kopia rozjeżdża się cicho (to jest zasada projektu, nie stylistyka).

---

## 1. Zadanie → dokument (zacznij tutaj, zawsze)

| Karol mówi | Idź do | Co tam jest |
|---|---|---|
| „mam czaty do weryfikacji", „przejrzyj czaty" | **`_docs/42`** | procedura + gotowe narzędzia, patrz §2 |
| „dlaczego X nie działa", diagnoza pola/metryki | **`_docs/44`**, sekcja **PUŁAPKI** na końcu | inwentarz pól obu baz, skale metryk, rozjazdy kod↔ADR |
| „co dziś robimy", stan projektu | **Trello** (pobierz aktualny!) + `_docs/21` | tablica jest współdzielona, handoff bywa nieaktualny |
| „dlaczego zdecydowaliśmy X" | **`_docs/10`** | ADR-y. Korekta decyzji = nota w istniejącym ADR, nie nowy |
| infrastruktura, ścieżki, cache | **`CLAUDE.md`**, „Mapa infrastruktury" | jedno źródło prawdy, nie dubluj |
| pisanie tasku dla CC | `_instances/{nazwa}/tasks/` | konwencja: `CHAT-T-NNN_INSTANCJA_opis.md` |
| „wczytaj prompt startowy", start sesji | **`_docs/47`** | treść instrukcji projektu (rola, adresy, dyscyplina). Karol wkleja tylko odsyłacz. Aktualizuje **architekt** |

**Reguła pierwszeństwa:** gdy `_docs/02`, `_docs/04` lub `CLAUDE.md` mówią co innego
niż **`_docs/44`** — obowiązuje `44`, bo opisuje realny kod, tamte bywają projektowe.

---

## 2. Narzędzia — NIE pisz nowych, zanim nie sprawdzisz tych

**Katalog: `_diag_local/chat_verification/`** (wersjonowany w git; sekrety czyta
z `.env`, który jest ignorowany). Uruchamiaj **z tego katalogu**: `python3 <skrypt>`.

| skrypt | co robi |
|---|---|
| `_conn.py` | wspólne połączenie. Parser `.env` odporny na 1 zepsuty klucz (ADR-088). **Zero sekretów na sztywno** |
| `list_open_problems.py` | otwarte problemy + notatki recenzenta. `--full` = przebiegi, `--dump PLIK` = do pliku |
| `show_conversation.py <conv_id>` | pełny przebieg rozmowy. `--tools` = z `tool_result` |
| `sql.py` | **SQL na Railway** — z pliku, stdin, `-c`, albo `--file`. Omija pułapkę apostrofów (§4) |
| `check_deploy.py <ścieżka>` | **kontrola wdrożenia**: md5 local↔prod + `php -l` (ea-php84) + smoke `/api/health` |

`sql.py` i `check_deploy.py` powstały 2026-07-17 — to były dwie rzeczy pisane
tego dnia od zera po kilka razy.

**Zasada:** nowe narzędzie dokładaj do tego katalogu **i dopisz je do tej tabeli**.
Narzędzie poza katalogiem = narzędzie, które zginie po sesji.

---

## 3. Gdzie są dane i sekrety (nie szukaj, nie zgaduj)

**Sekrety: wyłącznie `.env` w katalogu projektu.** Nigdy w kodzie, nigdy w tym pliku.
`_conn.py` i `sql.py` czytają go same — nie parsuj `.env` ręcznie.

| co | gdzie |
|---|---|
| **Railway PG** (JEDYNA aktywna baza czatu) | `DATABASE_URL` w `.env`. Tabele `divechat_*`, `encyclopedia_chunks`. **Aiven = martwy** |
| **MySQL PrestaShop** (read-only) | `.env`, user `divezone_chat_reader`, baza `divezone_2025`. Ceny, dostępność, `pr_specific_price`. **Hasło w apostrofach** (ADR-088) |
| **SSH** | `ssh -p 5739 divezone@divezonededyk.smarthost.pl`. Domyślny CLI = PHP 8.3; do 8.4 użyj `ea-php84` |
| **Backend na serwerze** | `~/public_html/chat.divezone.pl/src\|public\|config` — **BEZ prefiksu `standalone/`** (w repo jest) |
| **Sklep + moduł + panel** | `~/public_html/newtmp2/` — **to PRODUKCJA**, mimo nazwy „tmp" |

**Railway bywa zawodny wieczorami** (straty pakietów ~15-22 CEST). Ponów albo tunel
przez smarthost. To znany objaw, nie nowa awaria.

---

## 4. Pułapki narzędziowe (kosztowały czas, nie powtarzaj)

- **Apostrofy giną w łańcuchu SSH→zsh→bash→psql.** Użyj `sql.py`. Ręcznie: zapisz SQL
  do pliku na serwerze (`cat > /tmp/q.sql << 'EOF'`), literały przez `chr()||`.
- **`psql -c` wielokrotne** rozwija `$$` w shellu. Użyj heredoc `psql -f -` albo `sql.py`.
- **MCP `postgresql__query` celuje w martwy Aiven** (ENOTFOUND). Nie używaj — `sql.py`.
- **`pg_dump` wymaga libpq ≥ 18.4** (homebrew 14 odrzuca mismatch z serwerem 18.3).
- **`mtime` na produkcji NIE mówi, kiedy był deploy** — `rsync -t` przenosi znacznik
  ze źródła. Chcesz czas deployu: `_deploy_bak/`, dump, commit.
- **Baza pisze UTC, serwer chodzi CEST (+2).** `06:55` w bazie = `08:55` na serwerze.
- **`timeout` nie istnieje w zsh na macOS.**
- **Desktop Commander:** ścieżka alternuje `/Users/karol/...` ↔ `/Volumes/karol/...`
  (ten sam share SMB). ENOENT na jednej → spróbuj drugiej. `view` nie działa na SMB,
  `read_file` działa. `edit_block` lepszy niż `str_replace` przy strzałkach (`→`).
- **Trello `move_card` wymaga jawnego `boardId`** (mimo że opis mówi „opcjonalny").
  Po timeoucie/błędzie: **najpierw** `get_cards_by_list_id`, dopiero potem ponowienie.

---

## 5. Dyscyplina (to, co ratuje najwięcej czasu)

1. **Sprawdź, zanim stwierdzisz.** Nie sprawdziłeś → napisz „nie sprawdziłem" albo
   nie pisz wcale. „X nie działa, bo Y" wymaga źródła: plik+linia / tabela+zapytanie / URL.
2. **Nazwa pola nie jest jego znaczeniem.** Dwa pytania: **co realnie zawiera** i
   **KTO to czyta**. Drugie ważniejsze — pole może mieć poprawną treść i być martwe
   (`divechat_knowledge`: 37 wpisów, zero odczytów). Lista: `_docs/44` PUŁAPKI.
3. **Zanim uznasz coś za niezrobione — sprawdź, czy nie jest już zrobione gdzie indziej.**
   Ta instrukcja istnieje, bo tej zasady nie zastosowałem do niej samej.
4. **Weryfikacja obala Twoją tezę → powiedz to wprost, osobnym zdaniem, ZANIM
   pójdziesz dalej:** „Myliłem się. Twierdziłem X. Jest Y, źródło Z." Nie chowaj
   korekty w środku dokumentu.
5. **Nie zamykaj karty bez własnej weryfikacji** (SSH, md5, zapytanie — **nie raport CC**).
   Raport CC to hipoteza do sprawdzenia, nie dowód.
6. **Dokument bez odsyłacza jest martwy.** Nowy dokument → od razu wepnij go tam, gdzie
   ktoś go znajdzie: dla architekta **tu (§1)**, dla CC w `CLAUDE.md`.

---

## 6. Wnioski z 2026-07-17 (dzień, który wygenerował ten plik)

**CC złapało trzy błędy specyfikacji architekta w jednym tasku** (CHAT-T-148):
skalę problemu migawki (5 → realnie 80 flag), błąd logiczny o dokładności rollbacku,
i niebezpieczny blanket-rsync (`config/tools.php` = fatal 500). **Raport CC czytaj
jak recenzję, nie jak sprawozdanie** — brama STOP (ADR-089) istnieje właśnie po to.

**Architekt złapał dwa własne:** sufit `rrf_score` cytowany z wyliczenia (0,065)
zamiast z pomiaru (**0,1230**), oraz `mtime` potraktowany jako dowód czasu deployu.
**Cytuj pomiar, nie wyliczenie.**

**Trzy dokumenty tego samego dnia zaczęły kłamać po wdrożeniu** (`_docs/44` mówił
„ZEPSUTE" o naprawionym `knowledge_gap`, `CLAUDE.md` reklamował martwą
`divechat_knowledge`). **Po każdym deployu sprawdź, czy dokumentacja nie stała się
nieaktualna** — zwłaszcza ta, która opisuje pułapki.
