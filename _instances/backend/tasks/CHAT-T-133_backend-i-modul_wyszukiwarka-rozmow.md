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
