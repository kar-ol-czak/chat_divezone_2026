# CHAT-T-171 — INTEGRATION — `trello.py`: deterministyczne zarządzanie kartami Chat przez REST API

**Instancja:** integration
**Plik do utworzenia:** `_diag_local/chat_verification/trello.py`
**Świat wdrożeniowy:** ŻADEN — narzędzie lokalne
**ADR:** brak nowego (narzędzie diagnostyczne)

---

## 1. Po co — problem udowodniony w sesji 2026-07-24/25

MCP Trello zawodzi jako kanał zarządzania kartami z sesji roboczej:
- nieoficjalny pakiet `@delorenj/mcp-server-trello` padł pod Node 25
  (`ERR_UNSUPPORTED_NODE_MODULES_TYPE_STRIPPING`), naprawa objawu nie usunęła
  klasy ryzyka (aktualizacje npm);
- narzędzia MCP Trello NIE ładują się do sesji w aplikacji desktopowej nawet po
  restarcie;
- oficjalny konektor (OAuth) żyje w kanale przeglądarki — sesja desktopowa,
  w której robimy diagnostykę, go NIE widzi (zweryfikowane `tool_search`
  2026-07-25: w sesji tylko Desktop Commander + claude-in-chrome).

`trello.py` idzie przez Desktop Commander — kanał, który w tej sesji nie zawiódł
ani razu (sql.py, mysql.py, check_deploy.py, replay.py działały). Działa
identycznie dla architekta i dla CC, niezależnie od klienta i od warstwy MCP.
To piąte narzędzie katalogu, wpina się do `_docs/46` (§2) — zrobi architekt.

**Współistnienie:** oficjalny konektor OAuth zostaje w przeglądarce do ręcznej
pracy Karola. trello.py obsługuje automatykę z sesji. Nie kolidują — inne kanały
(decyzja Karola 29c).

## 2. Pokrycie REST API — zweryfikowane wobec dokumentacji Atlassian

Wszystkie operacje mają endpoint (`developer.atlassian.com/cloud/trello/rest`):
- **utworzenie karty:** `POST /1/cards` z `idList`, `name`, `desc`. Zwraca PEŁNY
  obiekt karty z `idShort` — rozwiązuje bolączkę MCP (`add_card_to_list` nie
  zwracał `idShort`, wymagał drugiego zapytania na ślepo).
- **przeniesienie karty:** `PUT /1/cards/{id}` z `idList` listy docelowej.
  Czyste API potrzebuje TYLKO `idList` — znika pułapka MCP `move_card` wymagająca
  jawnego `boardId` i jego timeouty.
- **zmiana nazwy (prefiks `Chat - NN`):** `PUT /1/cards/{id}` z `name`.
- **checklisty:** `POST /1/cards/{id}/checklists`, `PUT|DEL /1/checklists/...`.
- każde zapytanie: `key` + `token` w query stringu.

## 3. Sekrety — MUSZĄ być w .env, DZIŚ ICH TAM NIE MA

`grep TRELLO .env` = pusto (2026-07-25). Klucze były tylko w
`claude_desktop_config.json`, usunięte razem z wpisem `trello`. Są w backupie
`~/Library/Application Support/Claude/claude_desktop_config.json.bak_20260724`.

**KROK dla Karola (nie dla CC):** dopisać do `.env` projektu:
```
TRELLO_API_KEY='...'
TRELLO_TOKEN='...'
TRELLO_BOARD_ID_PROJEKTY2026='6a55e07bc2193b7dfc53297e'
```
CC NIE wpisuje sekretów. Task zakłada, że Karol to zrobi przed KROKIEM 5.

Odczyt: `from _conn import load_env` (już istnieje, odporny na 1 zepsuty klucz,
ADR-088). NIE pisz własnego parsera .env. Sekret tylko w pamięci procesu,
nigdy do stdout/logów/repo. **Klucze w query stringu idą do api.trello.com —
to jedyny odbiorca, nie loguj URL-i z pełnym query.**

## 4. Interfejs CLI (wzorzec sql.py: argparse, --write jawne dla mutacji)

READ (domyślnie, bez flagi):
```
trello.py --list-cards <idList>              # karty listy
trello.py --list-lists                       # listy boardu Projekty2026
trello.py --card <idCard>                    # szczegóły karty
```

WRITE (wymaga `--write`, jak sql.py):
```
trello.py --write --new-card <idList> --name "opis" --desc "..."
    # tworzy, zwraca idShort, NIE nadaje jeszcze prefiksu
trello.py --write --rename <idCard> --name "Chat - NN - opis [T-NNN]"
trello.py --write --move <idCard> --to-list <idList>
trello.py --write --new-card <idList> --name "..." --chat-prefix
    # tworzy + od razu czyta idShort z odpowiedzi + rename na "Chat - <idShort> - ..."
    # (jedno wywolanie, bez drugiego zapytania na slepo)
```

Domyślny board: `TRELLO_BOARD_ID_PROJEKTY2026` z .env. Listy (Backlog,
W trakcie, Do weryfikacji, Zrobione) — pobieraj dynamicznie przez `--list-lists`,
NIE zaszywaj ich ID w kodzie (rozjeżdżają się cicho — zasada projektu).

## 5. Bezpieczniki

1. **`--write` obowiązkowe dla każdej mutacji.** Bez niego tylko odczyt.
2. **Board pinned.** Mutacje domyślnie na boardzie z .env. `--to-list` na listę
   spoza tego boardu → odmowa z komunikatem (ochrona przed pomyłką listy z cudzej
   tablicy). Sprawdź, że idList należy do boardu przed PUT.
3. **Bez batcha.** Jedna karta na wywołanie. Mnożenie kart to znany antywzorzec
   (instrukcja architekta).
4. **Rate limit Trello:** 100 req/10s na token. Przy read-modify (np. new-card +
   rename) rób sekwencyjnie, nie równolegle.

## KROK 0 — pull i lektura
```
git pull --rebase
```
Przeczytaj: `_diag_local/chat_verification/sql.py` (wzorzec argparse, --write,
docstring), `_conn.py` (`load_env`), `replay.py` (świeży wzorzec z tej samej
sesji — styl wyjścia, obsługa błędów HTTP).

**NIE RUSZAJ:** niczego w `standalone/`, `config/`, ADR-ów, `.env`
(sekrety dokłada Karol, nie CC).

## KROK 1 — moduł HTTP
Cienka warstwa nad `POST/GET/PUT/DEL` na `https://api.trello.com/1`.
Biblioteka: `urllib` ze stdlib (bez nowych zależności) albo `requests` jeśli już
w projekcie — sprawdź, nie dokładaj. Timeout 30 s. Błąd HTTP → czytelny komunikat
z kodem, BEZ pełnego URL-a (sekret w query).

## KROK 2 — odczyty
`--list-lists`, `--list-cards`, `--card`. Wyjście jak sql.py (tabelka wyrównana).

## KROK 3 — zapisy z `--write`
`--new-card`, `--rename`, `--move`, `--chat-prefix`. Walidacja przynależności
listy do boardu (bezpiecznik §5.2).

## KROK 4 — walidacja składni
```
python3 -m py_compile trello.py
```

## KROK 5 — test na żywo (po dodaniu sekretów przez Karola)
1. `trello.py --list-lists` — wypisz listy boardu Projekty2026 z ich ID
2. `trello.py --list-cards <idBacklog>` — karty Backlogu
3. `trello.py --write --new-card <idBacklog> --name "TEST T-171 delete me" --chat-prefix`
   — utwórz kartę testową, pokaż nadany `Chat - NN`
4. `trello.py --write --move <idTej karty> --to-list <idZrobione>` — przenieś
5. odczytaj kartę, potwierdź nową listę i nazwę
Kartę testową Karol usunie ręcznie (DEL karty świadomie POZA zakresem narzędzia —
kasowanie to nie jest operacja, którą chcemy mieć w automacie).

W raporcie wklej pełne wyjście wszystkich pięciu kroków.

## KROK 6 — dokumentacja
`_diag_local/chat_verification/README.md` — dopisz trello.py z przykładami.
`_docs/46` — NIE ruszaj, wpięcie zrobi architekt (zgłoś, że czeka).

## KROK 7 — git
```
git status
git add _diag_local/chat_verification/trello.py
git add _diag_local/chat_verification/README.md
git commit -m "CHAT-T-171 integration: trello.py - deterministyczne karty przez REST API"
git push origin main
```

---

## Kryterium akceptacji (weryfikuje architekt)
1. `--list-lists` zwraca 4 listy boardu z ID
2. `--new-card ... --chat-prefix` tworzy kartę i nadaje `Chat - <idShort>` w jednym wywołaniu
3. `--move` przenosi bez potrzeby podawania boardId (tylko idList)
4. bez `--write` żadna mutacja nie przechodzi
5. sekret nieobecny w wyjściu, logach, repo
6. próba `--move` na listę spoza boardu Projekty2026 → odmowa
