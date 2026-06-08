# 22 — Specyfikacja: A/B test nudge v1/v2 + pomiar CTR (faza 2 ADR-090)

**Status:** SPEC | **Data:** 2026-06-08 | **Powiązane:** ADR-090, CHAT-T-081 (faza 1 DONE), decyzje 240b/241a/242c/243a/245a/246a/247a/248a
**Realizacja:** CHAT-T-082 (backend), CHAT-T-083 (frontend), CHAT-T-084 (panel). Kolejność: 082 → 083 → 084.
**Uzupełnienia po wdrożeniu:** CHAT-T-085/ADR-092 (fix korelacji — osobny nudge_sid). CHAT-T-086 (3. zdarzenie nudge_dismiss = klik X; decyzje 256b/257a). CHAT-T-084 rozszerzony o 4 metryki (ekspozycje/kliki/zamknięcia/zignorowane).
**Warunek startu:** v2 zaakceptowany wizualnie na PROD (sanity-check CHAT-T-081). Faza 2 NIE rusza, dopóki v2 nie jest "dobry wizualnie".

---

## 1. Cel i wskaźniki

Zmierzyć skuteczność wariantów zachęty (v1 dymek vs v2 karta) dwoma wskaźnikami per wariant (decyzja 242c):
- **CTR ekranu** = `nudge_cta_click` ÷ `nudge_shown` (czy karta/dymek skłania do kliknięcia).
- **Konwersja do rozmowy** = liczba `session_id` z ≥1 wiadomością użytkownika ÷ `nudge_shown` (czy skłania do realnej rozmowy).
- **Zamknięcia (X)** = `nudge_dismiss` ÷ `nudge_shown` (świadome odrzucenie; CHAT-T-086, 256b/257a).
- **Zignorowane** = `shown − clicks − dismissals` (reszta: brak reakcji w sesji; wyliczane, NIE osobne zdarzenie). Rozróżnienie "odrzucił" vs "zignorował" daje diagnozę: wysokie X + niski CTR = treść odpycha; niskie X + niski CTR + wysokie zignorowane = widget niezauważany.

Oba liczone per bucket (`v1`|`v2`). Panel pokazuje także liczności (mianowniki), żeby nie wyciągać wniosków z małej próby.

## 2. Architektura (decyzje 246a, 247a, 248a)

- **Endpoint zdarzeń: standalone backend** (chat.divezone.pl), zapis wprost do Railway PG (246a). Front woła cross-origin przez `navigator.sendBeacon` (fallback `fetch` z `keepalive:true`).
- **Identyfikacja: jeden sessionId przez całą ścieżkę** (247a). Front generuje `sessionId` (UUID v4) w MOMENCIE `nudge_shown`, trzyma go i przekazuje do czatu przy kliknięciu CTA. Ekspozycja → klik → rozmowa = to samo ID. Konwersja = JOIN `divechat_nudge_events.session_id` ↔ tabela rozmów.
- **Zakres pomiaru: zawsze** (248a). `nudge_shown`/`nudge_cta_click` wysyłane dla obu wariantów, niezależnie od trybu A/B. Baseline CTR działa też przy zwykłym przełączniku.
- **A/B przydział: client-side sticky** (241a). `localStorage dz_ab_bucket` (`v1`|`v2`), losowanie 50/50 przy pierwszej wizycie. Tryb A/B włączany kluczem `DIVEZONE_CHAT_NUDGE_AB` (default OFF). AB=ON → bucket nadpisuje `nudge.variant`. AB=OFF → obowiązuje `nudge.variant` z panelu (CHAT-T-081), a `bucket` w zdarzeniach = aktualny variant (żeby pomiar baseline działał).

## 3. KONTRAKT — zmiana cyklu życia sessionId (KRYTYCZNE dla 083)

**Stan obecny:** front startuje `state.sessionId = null`; backend tworzy sessionId przy pierwszym `/api/chat/stream` (`ConversationStore::resumeOrCreate`) i zwraca w `onDone`; front persystuje w localStorage (TTL, CHAT-T-059).

**Po zmianie (247a):** front generuje sessionId (UUID v4, `crypto.randomUUID()` z fallbackiem) JUŻ przy `nudge_shown` i przekazuje go backendowi przy pierwszej wiadomości. Backend MUSI zaakceptować client-supplied sessionId.

**Punkt bezpieczeństwa (do rozwiązania w 082/083):** backend nie może ślepo ufać dowolnemu sessionId z frontu (ryzyko podszycia pod cudzą rozmowę — istnieje już weryfikacja właściciela po HMAC customerId w `/api/chat/history`). Zasada: client-supplied sessionId jest akceptowany TYLKO dla NOWej rozmowy (brak wiersza) i wiązany z customerId z HMAC przy pierwszym zapisie; próba użycia sessionId należącego do innego customerId → backend tworzy nową sesję (jak dziś przy {exists:false}). To zachowuje istniejący model własności.

## 4. Schemat tabeli (CHAT-T-082) — Railway PG

`divechat_nudge_events`:
- `id` BIGSERIAL PK
- `session_id` TEXT NOT NULL (UUID z frontu; klucz korelacji z rozmową)
- `event_type` TEXT NOT NULL CHECK (`nudge_shown` | `nudge_cta_click` | `nudge_dismiss`) — trzecia wartość dodana przez CHAT-T-086 (migracja 027, DROP+ADD constraint).
- `bucket` TEXT NOT NULL (`v1` | `v2`)
- `ab_active` BOOLEAN NOT NULL (czy A/B był włączony w momencie zdarzenia — rozdziela ruch testowy od baseline)
- `created_at` TIMESTAMPTZ NOT NULL DEFAULT NOW()
- Indeksy: `(bucket, event_type, created_at)` (agregacje panelu), `(session_id)` (JOIN konwersji).
- Dedup: jedno zdarzenie każdego typu per session_id (nudge_shown / nudge_cta_click / nudge_dismiss). Front gwarantuje raz/sesję; backend defensywnie ON CONFLICT DO NOTHING na `(session_id, event_type)` — UNIQUE constraint (obejmuje też nudge_dismiss bez zmiany, CHAT-T-086).

## 5. Endpoint (CHAT-T-082)

`POST /api/widget/event` — publiczny, bez admina. Body JSON: `{session_id, event_type, bucket, ab_active}`.
- Ochrona lekka (zdarzenia fire-and-forget, nie LLM): walidacja whitelist (`event_type`∈{nudge_shown, nudge_cta_click, nudge_dismiss}, `bucket`∈{v1,v2}, `session_id` format UUID), RateLimiter per IP (reuse istniejącego, luźny limit), BEZ CostGuard, BEZ HMAC klienckiego (beacon nie niesie nagłówków auth łatwo; ryzyko zafałszowania CTR przez bota mitygowane: zdarzenia to nie akcja wrażliwa, a dane służą porównaniu v1↔v2 gdzie ewentualny szum dotyka obu wariantów równo). Whitelist rozszerzona o nudge_dismiss w CHAT-T-086.
- Odpowiedź: 204 No Content (beacon ignoruje body).
- Zapis przez nowy lekki store (np. `NudgeEventStore`) na wspólnym `PostgresConnection`.

## 6. Front (CHAT-T-083) — moduł PS

- **Bucket:** w loaderze, przy `setupNudge()`: jeśli `BOOT.nudge.ab` → odczyt/losowanie `dz_ab_bucket` (localStorage), bucket nadpisuje variant. Jeśli nie → bucket = variant z panelu.
- **sessionId at shown:** wygeneruj UUID przy faktycznym pokazaniu nudge (`renderNudge`/`renderNudgeCard`), zapisz w obiekcie współdzielonym z bundlem (przez `BOOT` lub globalny mostek), żeby bundle użył TEGO sessionId zamiast czekać na backend.
- **Beacony:** `nudge_shown` w momencie renderu; `nudge_cta_click` przy `openChatFlow()`. Funkcja `sendNudgeEvent(type)` → `navigator.sendBeacon(BOOT.backendUrl + '/api/widget/event', blob)`, fallback `fetch keepalive`.
- **Pułapka MIME (zweryfikowane smokem CHAT-T-082 2026-06-08):** LiteSpeed/ModSecurity na `chat.divezone.pl` BLOKUJE `Content-Type: text/plain` (i `text/plain;charset=UTF-8`) zwracając 403 PRZED PHP. Domyślny `navigator.sendBeacon(url, string)` wysyła `text/plain;charset=UTF-8` → odpadnie. Działają: `application/x-www-form-urlencoded`, `multipart/form-data`, brak Content-Type. Konieczne: użyć `new Blob([JSON.stringify(payload)], {type: 'application/x-www-form-urlencoded'})` — to nadal "simple request" CORS (brak preflight, bo MIME w whitelist), backend dalej parsuje JSON tolerancyjnie z `php://input` niezależnie od Content-Type.
- **Bundle:** przy starcie rozmowy użyj sessionId przekazanego z loadera (jeśli jest), zamiast null. Zachowaj graceful fallback (gdy brak — stare zachowanie).
- **Nowe pola BOOT.nudge:** `ab` (bool), `eventPath` (np. `/api/widget/event`). `variant` już jest (CHAT-T-081).
- **Klucz configu:** `DIVEZONE_CHAT_NUDGE_AB` (lazy default 0/OFF) + checkbox w panelu sekcja 5.

## 7. Panel raport CTR (CHAT-T-084) — standalone + PS

- Endpoint admin (kanał serwerowy, `ServerHmacVerifier`, wzorzec jak `AdminAnalyticsController`): `GET /api/admin/nudge-ctr` → per bucket: `shown`, `clicks`, `dismissals` (klik X), `ignored` (=shown−clicks−dismissals, clamp ≥0), `ctr`, `dismiss_rate`, `conversations` (nudge_sid z ≥1 user-msg, JOIN po conversations.nudge_sid wg ADR-092), `conversion_rate`, rozdzielone `ab_active` true/false.
- UI: nowa sekcja/zakładka w panelu PS (ADR-070, 243a) — tabela 2 wiersze (v1/v2) × kolumny (ekspozycje, kliki CTA, CTR, zamknięcia X, zamknięcia %, zignorowane, rozmowy, konwersja), z adnotacją o liczności / minimalnej próbie. Prosty wskaźnik istotności (flaga "za mała próba" gdy shown < próg, stała np. 100/wariant). Zignorowane z adnotacją: reszta bez reakcji w sesji, NIE twardy sygnał odrzucenia (257a).

## 8. Ograniczenia (z ADR-090 / pamięci)
- Railway PG, NIGDY Aiven.
- Deploy standalone wg ADR-089 (rsync + backup + STOP-point + smoke /api/health).
- Moduł PS wgrywa Karol ręcznie (116b).
- `.env` sekrety w pojedynczych cudzysłowach (ADR-088) — dotyczy gdyby endpoint potrzebował sekretu (nie potrzebuje w fazie 2).
- Cache-safe: nowe pola BOOT to stałe wartości, HTML identyczny dla wszystkich (ADR-087). Losowanie bucketa wyłącznie runtime client-side.
