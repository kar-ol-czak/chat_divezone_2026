# CHAT-T-133 — wyszukiwarka rozmow w panelu recenzji (numer + tresc) + pokazanie conversation_id

**Swiaty:** DWA. Czesc A = BACKEND standalone (chat.divezone.pl). Czesc B = MODUL PS (newtmp2).
Kolejnosc: NAJPIERW A (backend), potem B (panel). Osobne rsync.
**Instancje:** backend (czesc A), frontend (czesc B).
**ADR:** ADR-116 (ostatni w pliku ADR-111; 112-115 zajete — bierz 116).
**Decyzje Karola:** 28c (numer + skok do numeru + tytul + pelnotekstowo po tresci),
29a (ta karta przed ton+porownania), 30a (ILIKE bez indeksu GIN — patrz nizej).

## Kontekst — co JUZ istnieje (zweryfikowane, NIE budowac od zera)

- `ConversationStore::list()` (standalone/src/Chat/ConversationStore.php, ~206) JUZ przyjmuje
  `$search` i robi `messages::text ILIKE ?` — **pelnotekstowe szukanie po CALEJ tresci
  rozmowy juz dziala po stronie backendu**. JUZ zwraca tytul (first_user_message).
- `ConversationsController::list()` (standalone/src/Controller/ConversationsController.php, ~40)
  JUZ czyta `search` z query param i przekazuje do store.
- `ConversationReviewRepository` (~165) JUZ zwraca `conversation_id` (c.id) do panelu.
- Panel PS (AdminDivezoneChatController, zakladka Rozmowy) ma dzis TYLKO filtr
  `Recenzja: <status>` + "POKAZ". NIE pokazuje conversation_id, NIE ma pola tekstowego.

Wniosek: brakuje (1) szukania po NUMERZE conversation_id w backendzie, (2) wystawienia
pola wyszukiwania + numeru w panelu PS. Reszta juz jest.

## Wydajnosc (decyzja 30a — zmierzone)

`messages::text ILIKE` na 620 rozmowach (cala tabela 5.96 MB) = ~235 ms. Akceptowalne
dla panelu admina. NIE dodawac indeksu GIN/tsvector teraz — to przedwczesna optymalizacja.
Zapisz w ADR-116 prog przejscia na GIN: gdy > ~3000 rozmow LUB wyszukiwanie > 500 ms.

## CZESC A — BACKEND (chat.divezone.pl) — rob NAJPIERW

Cel: `search` ma trafiac tez w `conversation_id` (numer), nie tylko w tresc.

Plik: `standalone/src/Chat/ConversationStore.php`, metoda `list()`, blok `if ($search...)`.
Dzis: `messages::text ILIKE ?`.
Zmiana: gdy `$search` jest liczba calkowita → warunek OR obejmuje rowniez `id = ?`
(dokladne dopasowanie numeru). Gdy tekst → jak dzis (`messages::text ILIKE`).
Przyklad logiki (nie kopiuj 1:1, dostosuj do stylu):
```
if ($search !== null && $search !== '') {
    if (ctype_digit($search)) {
        $conditions[] = '(id = ? OR messages::text ILIKE ?)';
        $params[] = (int) $search;
        $params[] = '%' . $search . '%';
    } else {
        $conditions[] = 'messages::text ILIKE ?';
        $params[] = '%' . $search . '%';
    }
}
```
Uwaga: `id` to bare kolumna `divechat_conversations` (alias jak w reszcie `list()`).
NIE ruszaj paginacji, podzapytania tytulu (CHAT-T-051), excludeSql chipow (CHAT-T-122).

Test backendu: przez realny endpoint (HMAC) lub bezposrednio store — `search='584'`
zwraca rozmowe o id=584; `search='pianka'` zwraca rozmowy z tym slowem w tresci.
`php -l` ea-php84 clean.

Deploy A (ADR-089, STOP przed rsync): rsync ConversationStore.php → chat.divezone.pl/src/Chat/
+ backup + md5 + php -l + smoke /api/health. **UWAGA: NIE deployowac config/tools.php**
(dryf repo≠prod, patrz CLAUDE.md — ProductCombinations niewdrozony).

## CZESC B — MODUL PS (newtmp2) — rob PO wdrozeniu czesci A

Plik: `modules/divezone_chat/controllers/admin/AdminDivezoneChatController.php`,
sekcja zakladki Rozmowy (renderConversationsSection + filtry `dz-conv-filters`).

1. **Pole wyszukiwania:** dodaj `input[type=text]` w `dz-conv-filters` (placeholder np.
   "Szukaj: numer lub slowo z rozmowy"). Po submit/POKAZ przekaz jego wartosc jako
   `search` do wywolania backendu `/api/conversations` (list). Backend juz filtruje.
2. **Skok do numeru:** ten sam input — gdy wpisana liczba, backend (czesc A) dopasuje
   po conversation_id. Osobny przycisk "Otworz" moze od razu ladowac detal, jesli 1 trafienie.
3. **Pokaz conversation_id na liscie:** przy kazdej pozycji listy rozmow wyswietl
   `#{conversation_id}` (przychodzi juz z backendu jako conversation_id). Maly, szary,
   obok daty/statusu. To daje wspolny numer, po ktorym Karol i architekt wskazuja rozmowe.
4. Zachowaj istniejacy filtr `Recenzja: <status>` — dodaj wyszukiwanie OBOK, nie zamiast.

Deploy B: reczny rsync Karola do newtmp2 (port 5739, --exclude config_pl.xml, bez --delete),
potem czyszczenie var/cache/prod + LSCache (patrz CLAUDE.md CACHE). Weryfikacja: md5 pliku
kontrolera na prod == local + grep markera CHAT-T-133 w pliku na newtmp2.

## Kryteria akceptacji
1. Wpisanie "584" w panelu → otwiera/pokazuje rozmowe o conversation_id 584.
2. Wpisanie slowa (np. "pianka") → lista rozmow zawierajacych to slowo w tresci.
3. Kazda pozycja listy pokazuje swoj numer #id.
4. Filtr statusu recenzji dziala jak dotad (brak regresji).

## Git
Czesc A: `git add` standalone/src/Chat/ConversationStore.php + ADR; commit
`CHAT-T-133 backend: search po conversation_id w liscie rozmow (ADR-116)`.
Czesc B: `git add` modules/divezone_chat/controllers/admin/AdminDivezoneChatController.php;
commit `CHAT-T-133 modul: wyszukiwarka rozmow + numer w panelu recenzji (ADR-116)`.
Per sciezka, NIE `git add .` (drzewo ma cudze pliki — Chip*, routes.php, sql 031/034).
Po kazdym deployu osobny `docs:` commit (status).

## Domkniecie
Po wdrozeniu obu czesci i weryfikacji: karta Trello → "Zrobione". Ta karta odblokowuje
Karola w dostepie do rozmow po numerze (rozwiazuje problem "nie mam jak dotrzec do 584").

## Wynik — czesc A (backend) — DONE 2026-07-14

- `ConversationStore::list()`: gdy `search` jest liczba (`ctype_digit`) → warunek
  `(id = ? OR messages::text ILIKE ?)`; tekst → jak dotad. Paginacja, podzapytanie
  tytulu (CHAT-T-051) i exclude chipow (CHAT-T-122) nietkniete.
- ADR-116 dopisany do `_docs/10_decyzje_projektowe.md` (prog GIN: >3000 rozmow lub >500 ms).
- Commit: `527d45b` (`CHAT-T-133 backend: search po conversation_id w liscie rozmow (ADR-116)`).
- Test lokalny (store na Railway): search='584' → id=584 na liscie (total 81, OR z trescia);
  search='pianka' → 115 trafien; bez filtra → 620 rozmow (brak regresji).
- Deploy: TYLKO `src/Chat/ConversationStore.php` → chat.divezone.pl (backup
  `_deploy_bak/20260714_T133/`, prod przed deployem == repo HEAD~1 — brak dryfu,
  ea-php84 -l clean, md5 zgodne, /api/health 200). `config/tools.php` NIE ruszony.
- Test PROD (realny endpoint, HMAC serwerowy, employee 2): `search=584` → total=81,
  id=584 OBECNE; `search=pianka` → total=115.

## Wynik — czesc B (modul PS) — WDROZONA 2026-07-14, czeka na test reczny Karola

- Pole wyszukiwania w pasku filtrow (`renderReviewFilterBar`, widoczne w KAZDYM trybie
  recenzji): placeholder "Szukaj: numer lub slowo z rozmowy". Niepusta fraza → lista
  przelacza sie na pelna (/api/conversations z search), bo /api/admin/review nie
  wspiera search; wyczyszczenie pola przywraca kolejke recenzji.
- Przycisk "Otworz" (`dzGoConv=1`): fraza-liczba → `findSessionIdByConvNumber()`
  pyta backend i otwiera detal dokladnego trafienia conversation_id od razu
  (best-effort: szuka na 1. stronie 100 wynikow).
- `#{conversation_id}` maly/szary przy dacie na pozycjach OBU list (pelna
  `renderConvListItem` + recenzje `renderReviewListItem`).
- Stary input search w `renderConvFilters` (tryb wszystkie) → hidden (pole
  przenioslo sie do gornego paska; filtr luk wiedzy nie gubi frazy).
- Filtr `Recenzja: <status>` nietkniety (wyszukiwanie OBOK).
- Commit: `10adce8` (`CHAT-T-133 modul: wyszukiwarka rozmow + numer w panelu recenzji (ADR-116)`).
- Deploy: rsync kontrolera → newtmp2 (backup `~/_deploy_bak/newtmp2_20260714_T133/`,
  prod przed deployem == repo HEAD~1 — brak dryfu; md5 zgodne, grep CHAT-T-133 = 7,
  php -l clean). Cache: `var/cache/prod` skasowany + LSCache pelny flush
  (`flush_all_litespeed.php` → OK), sklep HTTP 200.
- PENDING: test reczny Karola w panelu (twardy refresh): "584" → Otworz → detal 584;
  "pianka" → lista; #id widoczny; filtr recenzji bez regresji. Po tescie karta
  Trello → "Zrobione".
