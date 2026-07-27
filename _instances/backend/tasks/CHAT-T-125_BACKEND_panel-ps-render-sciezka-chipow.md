# CHAT-T-125 — Panel recenzji (moduł PS): render ścieżki chipów w „Przebieg rozmowy"

**Instancja:** backend (endpoint) + frontend PS (render). **Powiązane:** ADR-110 (chip_path), ADR-070 (panel PS = jedyny front admin), CHAT-T-105 (panel recenzji), CHAT-T-123 (render w standalone admin — WYGASZANY panel, nie ten używany). **Decyzja:** 9a.

## Kontekst
CHAT-T-123 dodał render ścieżki chipów w standalone admin dashboard (`chat.divezone.pl/admin`, `/api/admin/conversations/:id`). ALE zgodnie z ADR-070 standalone /admin jest WYGASZANY — realny panel recenzji rozmów żyje w module PS (`AdminDivezoneChatController::renderConvMessages`, nagłówek „Przebieg rozmowy", endpoint `/api/conversations/{sid}`). Karol ogląda panel PS, nie standalone. Ścieżka chipów (chip_path, utrwalana od CHAT-T-122) NIE jest tam widoczna. Ten task przenosi funkcję do właściwego panelu. Dane testowe: rozmowa 29e2c4a8-085b-4402-a04d-84b5c3dcf1aa (id 551), chip_path [dobor L2 › komputer L3].

## Zakres
### Część A — backend (standalone, chat.divezone.pl)
1. Endpoint `/api/conversations/{sid}` (szczegół rozmowy dla panelu PS) MUSI zwracać `chip_path`. Sprawdź który kod obsługuje ten endpoint (prawdopodobnie `ConversationStore` metoda szczegółu / `ConversationViewer` — zweryfikuj routing w standalone/config/routes.php). Dołóż `chip_path` do SELECT i do odpowiedzi. Dekoduj jsonb (PG zwraca string) → lista {node_key,label,level}; null/pusty/niepoprawny → null. UWAGA: CHAT-T-123 dodał identyczny `decodeChipPath()` w `ConversationViewer` — jeśli to inny endpoint/klasa, NIE duplikuj: wydziel wspólny helper albo powiel minimalnie z komentarzem. Nie ruszać `/api/admin/conversations/:id` (standalone, CHAT-T-123).
2. STOP przed rsync (ADR-089). Deploy = rsync standalone/src → chat.divezone.pl/src.

### Część B — frontend PS (moduł, newtmp2)
3. `AdminDivezoneChatController.php`: przed nagłówkiem „Przebieg rozmowy" (linia ~1785) wstaw breadcrumb ścieżki z `$resp['chip_path']`. Format: „Ścieżka: Dobór sprzętu › Komputer nurkowy". Pusty/NULL/brak → nic nie renderuj. Etykiety przez `htmlspecialchars(..., ENT_QUOTES)`, separator „›" stały tekst UI.
4. Styl dyskretny (mały szary tekst) — wzorzec jak istniejące `$css` w kontrolerze (dz-conv-*). Bez nowych zależności.
5. Deploy = ręczny rsync Karola do newtmp2/modules/divezone_chat/. Po rsync: wyczyścić var/cache/prod + LSCache.

## Uwaga
- Dwa światy, dwa deploye: część A → chat.divezone.pl (rsync src), część B → newtmp2 (moduł). Kolejność: A przed B (render B zależy od chip_path z endpointu A).
- Kod z CHAT-T-123 (standalone) zostaje — nie wycofujemy, standalone dogorywa ale działa; po prostu funkcja jest teraz też w panelu PS (właściwym).

## Definicja ukończenia
- `/api/conversations/{sid}` zwraca chip_path.
- Panel recenzji w module PS („Przebieg rozmowy") pokazuje breadcrumb ścieżki dla rozmowy 29e2c4a8; rozmowa z wolnego pisania → brak breadcrumb.

## Wynik (2026-07-02)
**Status: ZDEPLOYOWANE na prod (A + B), 2026-07-02. Pozostaje wizualne potwierdzenie Karola w panelu BO dla rozmowy 29e2c4a8 (twardy refresh).**

### Deploy (wykonany 2026-07-02, autoryzacja Karola „deploy A zrób a potem deploy B")
- **A → chat.divezone.pl** (ADR-089): backup `_deploy_bak/CHAT-T-125/` (ConversationStore + ConversationViewer), rsync per-plik 3 plików (ChipPathCodec nowy + 2 zmienione). Weryfikacja: **md5 3/3 match** repo↔serwer, **`php -l` clean 3/3** (ea-php84 8.4.22), smoke **`/api/health` HTTP 200 / 0.29s**.
- **B → newtmp2/modules/divezone_chat** (za explicit zgodą, 116b): backup do `_deploy_bak/CHAT-T-125/module_*.bak` (md5 760bd9… = stary serwer), rsync 1 pliku bez `--delete`. Weryfikacja: **md5 match** (34ff8ab…), **`php -l` clean**, marker `CHAT-T-125` obecny 4×. Cache: **`var/cache/prod` wyczyszczony** (PrestaShop przebudował — store front `divezone.pl` HTTP 200 po czyszczeniu). LSCache: panel BO nie jest cache'owany przez LSCache (front-office only) → brak akcji.
- **Rollback** (gdyby regres): kopie z `~/public_html/chat.divezone.pl/_deploy_bak/CHAT-T-125/*.bak` z powrotem (standalone) + `module_AdminDivezoneChatController.php.bak` → newtmp2.

### Część A — backend (standalone)
- **Wspólny `DiveChat\Chip\ChipPathCodec::decode(mixed): ?array`** — wydzielony dekoder jsonb `chip_path` (null/pusty/niepoprawny/pusta tablica → null; string z PG lub już-tablica). Zamiast duplikować `decodeChipPath` z CHAT-T-123.
- **`ConversationStore::getBySessionId`** (obsługa `/api/conversations/{sid}` przez `ConversationsController::detail`) — dołożono `'chip_path' => ChipPathCodec::decode(...)`. SELECT to `SELECT *`, więc kolumna była już pobierana — bez zmiany zapytania.
- **`ConversationViewer`** (CHAT-T-123, `/api/admin/conversations/:id`) — zrefaktorowany: prywatny `decodeChipPath()` usunięty, deleguje do `ChipPathCodec`. Zachowanie bajtowo identyczne (zweryfikowane: get(551) dalej zwraca tę samą ścieżkę). Endpoint standalone nietknięty w kontrakcie.
- **NIE ruszono** `/api/admin/conversations/:id` (routing/kontrakt bez zmian).

### Część B — frontend PS (moduł)
- **`AdminDivezoneChatController::renderChipPathBreadcrumb($resp)`** — nowa metoda, wywołana PRZED nagłówkiem „Przebieg rozmowy" (~1785). Format „Ścieżka: A › B" (separator `&rsaquo;`); etykiety `htmlspecialchars(ENT_QUOTES,'UTF-8')`; brak/pusty/null/brak labeli → `''`. PHP 7.2-safe (bez typed props/enums/match).
- **CSS** `.dz-conv-chip-path` (mały szary tekst, wzorzec dz-conv-*) — dodany do `$css`.

### Weryfikacja (real Railway, rozmowa 551 = 29e2c4a8)
- `getBySessionId` → `chip_path=[{label:"Dobór sprzętu",level:2,node_key:"dobor"},{label:"Komputer nurkowy",level:3,node_key:"komputer"}]`.
- Breadcrumb HTML: `<div class="dz-conv-chip-path"><span class="label">Ścieżka:</span> Dobór sprzętu <span class="sep">›</span> Komputer nurkowy</div>`.
- Pusty resp / null chip_path → `''` (brak breadcrumbu). Codec edge cases (null/''/'[]'/garbage) → null.
- `ConversationViewer::get(551)` po refaktorze → ta sama ścieżka (regres OK). `php -l` czysty na 4 plikach.

### Deploy (STOP — dla Karola, kolejność A→B)
1. **A** — rsync `standalone/` → `chat.divezone.pl/` (ADR-089, backup+md5+`php -l`+smoke `/api/health`).
2. **B** — rsync `modules/divezone_chat/` → `newtmp2/modules/divezone_chat/` (bez `--delete`, `--exclude config_pl.xml`), potem skasować `var/cache/prod` + LSCache.
   B zależy od chip_path z endpointu A — dlatego A przed B.
