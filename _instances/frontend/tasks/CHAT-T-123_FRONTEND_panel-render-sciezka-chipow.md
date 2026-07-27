# CHAT-T-123 — Panel recenzji: render ścieżki chipów w widoku rozmowy

**Status:** DONE (standalone admin — modal `/api/admin/conversations/:id`). Uwaga: panel recenzji w module PS (`/api/conversations/{sid}`) to ODRĘBNA powierzchnia — poza zakresem tego tasku (patrz Wynik).
**Instancja:** frontend (panel admina) | **Powiązane:** ADR-110, CHAT-T-122 (backend wystawia `chip_path`), CHAT-T-105 (panel recenzji). **Decyzje:** 9a.

## Kontekst
Po CHAT-T-122 rozmowa ma utrwaloną ścieżkę chipów `chip_path` (`[{node_key,label,level}]`). Panel recenzji ma ją pokazać, żeby recenzent wiedział, przez jaką ścieżkę klient trafił do rozmowy (np. „Dobór sprzętu › Komputer nurkowy"). Bez tego kontekst startowy jest niewidoczny (etykieta chipu nie jest już wiadomością — CHAT-T-121).

## Zakres
1. **Backend API panelu:** upewnij się, że endpoint szczegółu rozmowy zwraca `chip_path` (jeśli `ConversationReviewRepository`/detail go nie selektuje — dołóż do SELECT i do mapowania odpowiedzi). Mały dodatek do CHAT-T-122 jeśli pominięty.
2. **Render w `admin-conversation.js`:** nad listą wiadomości pokaż ścieżkę jako „ślad" (breadcrumb) `label › label › label` z `chip_path`. Gdy `chip_path` pusty/NULL → nie renderuj nic (rozmowa z wolnego pisania). Sanityzacja: `DiveAdmin.escHtml` na `label`.
3. **Styl:** dyskretny, nienachalny (mały tekst nad wątkiem, np. „Ścieżka: Dobór sprzętu › Komputer nurkowy"). Bez nowych zależności.
4. **Bez sendBeacon/JS-POST łamiącego ModSecurity** — to tylko render odczytu, brak zapisu.

## Uwaga
- ModSecurity blokuje `text/plain` (znany problem panelu) — tu tylko odczyt, ale trzymać wzorzec istniejących wywołań panelu.
- Nie mylić `chip_path` (nawigacja) z treścią wiadomości. To odrębny blok nad wątkiem.

## Definicja ukończenia
- Rozmowa startująca przez chip pokazuje ścieżkę nad wątkiem.
- Rozmowa z wolnego pisania nie pokazuje bloku ścieżki.
- Znaki polskie i HTML w `label` bezpiecznie zescapowane.

## Wynik (2026-07-02, CC)
Zaimplementowane w **standalone admin** (dashboard modal rozmowy, endpoint `/api/admin/conversations/:id`):

1. **Backend `ConversationViewer::get`** (`standalone/src/Admin/ConversationViewer.php`): `chip_path` dołożony do SELECT z `divechat_conversations` i do odpowiedzi. Nowy `decodeChipPath()` dekoduje jsonb (PG zwraca string) → lista `{node_key,label,level}`; null/pusty/niepoprawny JSON/nie-tablica → `null` (rozmowa z wolnego pisania). Zweryfikowane na Railway na rozmowie 551 (`29e2c4a8…`): chip_path = `[dobor L2, komputer L3]`.
2. **Render `admin-conversation.js`**: nowy `renderChipPath(data.chip_path)` wstawia breadcrumb NAD wątkiem (po `renderMessages`, `insertBefore` na początek `bodyEl`). Format: „**Ścieżka:** Dobór sprzętu › Komputer nurkowy". Pusty/NULL / brak etykiet → nic nie renderuje. Etykiety przez `DiveAdmin.escHtml`; separator „›" to stały tekst UI (nie dane).
3. **Styl** (`admin.css`): dyskretny `.conv-chip-path` (mały szary tekst, tło #f5f6f8, cienka ramka). Bez nowych zależności.
4. Brak zapisu/sendBeacon — tylko odczyt (zgodnie z pkt 4). Weryfikacja: `node --check` OK, `php -l` OK, logika decode+breadcrumb potwierdzona na realnym stringu jsonb.

**Rozbieżność do decyzji Karola (surfaced):** Tytuł tasku mówi „panel recenzji", ale realny panel recenzji rozmów (CHAT-T-105) żyje w **module PS** (`AdminDivezoneChatController::renderConvMessages`, `dz-conv-thread`) i pobiera szczegół z INNEGO endpointu — `/api/conversations/{sid}` (NIE `/api/admin/conversations/:id` = `ConversationViewer`). Zakres tego tasku (pkt 2 `admin-conversation.js`, OSTATNI KROK `standalone/public/admin/` + `standalone/src/Admin/`) dotyczy standalone admin — i to dostarczyłem. Jeśli ścieżka ma być widoczna dla recenzenta w module PS, potrzebny osobny task: (a) endpoint `/api/conversations/{sid}` musi zwracać `chip_path`, (b) `renderConvMessages`/`renderConvPanel` w module PS ma wyrenderować breadcrumb. Do decyzji.
