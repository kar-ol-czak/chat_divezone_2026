# CHAT-T-059 — persystencja sesji czatu między stronami (backend + moduł + widget)

**Instancje:** backend (standalone) + frontend (moduł PS + widget). DWA obszary deploymentu.
**Powiązane:** CHAT-T-037 (widget), ChatController, ConversationStore (startOrResume już wznawia po sessionId), ADR-079 (token TTL).
**Decyzje:** 145a (sessionId zapamiętany + historia odtwarzana z backendu przez nowy endpoint), 146b→147a (trwałość localStorage z TTL + przycisk „Nowa rozmowa"), TTL 30 dni KONFIGUROWALNY w panelu.
**PROBLEM:** Nawigacja między stronami sklepu (pełny reload PrestaShop) resetuje czat — stan żyje tylko w pamięci JS, brak persystencji. Niedopuszczalne. Backend JUŻ trzyma historię po sessionId (startOrResume, closed_at IS NULL) — brak tylko: front zapamiętania sessionId + odtworzenia historii + front-facing endpointu historii.

## Architektura rozwiązania (145a)
Backend = źródło prawdy (trzyma messages per sessionId). Front zapamiętuje sessionId w localStorage (z TTL), po remoncie widgetu pobiera historię z NOWEGO endpointu i odtwarza widok. Zero trzymania treści rozmowy w przeglądarce (tylko sessionId + timestamp).

---

## CZĘŚĆ A — BACKEND (CC wdraża SAM): nowy endpoint historii

### A1. ChatController::history (lub nowy lekki handler)
- Trasa: GET /api/chat/history?session_id={id} (front-facing, HMAC jak /api/chat).
- Auth: identyczny wzorzec jak ChatController::handle — nagłówki x-divechat-token/customer/time, HmacVerifier (TTL z ADR-079). 401 brak/zły token.
- Logika:
  - Odczyt sessionId z query (walidacja niepusty).
  - Pobrać rozmowę: SELECT id, messages, ps_customer_id FROM divechat_conversations WHERE session_id = ? AND closed_at IS NULL ORDER BY started_at DESC LIMIT 1.
  - **WERYFIKACJA WŁAŚCICIELA (KRYTYCZNE bezpieczeństwo):** rozmowa należy do żądającego tylko jeśli ps_customer_id rozmowy == customerId z HMAC. Dla gościa (customerId=0): rozmowa z ps_customer_id=0/NULL — dostęp przez znajomość sessionId (sessionId jest sekretem; generowany losowo server-side, nieprzewidywalny). Jeśli ps_customer_id rozmowy ≠ customerId z tokenu (np. zalogowany user prosi o cudzą rozmowę) → 403/404 (NIE zwracać cudzej historii).
  - Jeśli rozmowa nie istnieje LUB closed_at not null → zwróć {exists:false, messages:[]} (200, NIE błąd — front gracefully startuje nową).
  - Jeśli istnieje → {exists:true, session_id, messages:[...]} (messages w formacie jak zapisane: role/content; tool_result pomijać po stronie frontu jak w renderze).
- NIE generować nowej rozmowy w tym endpoincie (tylko odczyt; INSERT robi dopiero /api/chat przy pierwszej wiadomości).

### A2. routes.php
- Dodać: $router->get('/api/chat/history', $chatController->history(...)); (lub osobny handler). Front-facing, przed/obok /api/chat.

---

## CZĘŚĆ B — MODUŁ PS (Karol wgrywa ręcznie 116b): config TTL + boot

### B1. Konfiguracja: KEY_PERSIST_TTL_DAYS (DIVEZONE_CHAT_PERSIST_TTL_DAYS)
- Pole number w panelu Konfiguracja (sekcja widgetu): „Czas pamiętania rozmowy (dni)", walidacja np. 1-365, default 30. Wzorzec 1:1 z nudge_delay (CHAT-T-056).
- getContent render + submit updateValue z walidacją.

### B2. Boot payload (hookDisplayFooter)
- Dodać do $boot: 'persist' => array('ttl_days' => (int) Configuration::get(KEY_PERSIST_TTL_DAYS) ?: 30, 'historyPath' => '/api/chat/history').
- (token/customerId/time już w boot — front użyje ich do wywołania history z HMAC, tak jak transport używa do /api/chat.)

---

## CZĘŚĆ C — WIDGET (Karol wgrywa ręcznie): localStorage + odtwarzanie + przycisk

### C1. Zapis sessionId (localStorage z TTL)
- Gdy backend zwróci session_id (onDone w sendUserMessage, state.sessionId): zapisać do localStorage obiekt {sessionId, ts: Date.now()} pod kluczem np. 'dz_chat_session'.
- TTL z BOOT.persist.ttl_days. Przy odczycie: jeśli Date.now() - ts > ttl_days*86400000 → traktować jako wygasłe (usunąć, nowa rozmowa).

### C2. Odtworzenie przy montażu widgetu
- Przy starcie (mount/pierwsze otwarcie): odczytać 'dz_chat_session'. Jeśli istnieje i nie wygasł:
  - state.sessionId = zapisany sessionId.
  - Wywołać GET BOOT.persist.historyPath?session_id=... z nagłówkami HMAC (token/customerId/time z BOOT — analogicznie jak transport buduje nagłówki dla /api/chat). Transport.js: dodać funkcję fetchHistory(sessionId, cb) wzorowaną na sendMessage (te same nagłówki, GET).
  - Jeśli {exists:true} → wyrenderować historię (pętla messages → bąble user/ai jak appendBotMessage/appendUserMessage; tool_result pominąć; ukryć chipy bo rozmowa już trwa). Jeśli {exists:false} → wyczyścić localStorage, świeży stan (chipy widoczne).
- Odtworzenie NIE może blokować UI — jeśli history fetch padnie (sieć/401), gracefully pokaż świeży czat (nie błąd).

### C3. Przycisk „Nowa rozmowa" (147a)
- W nagłówku panelu czatu dodać przycisk/link „Nowa rozmowa" (lub ikonę). Klik: state.sessionId=null, wyczyść localStorage 'dz_chat_session', wyczyść messagesEl (usuń bąble), pokaż welcome + chipy ponownie. Potwierdzenie (confirm) opcjonalne — rozmowa nie jest kasowana w backendzie, tylko front startuje nową sesję.
- a11y: przycisk focusowalny, aria-label „Rozpocznij nową rozmowę".

### C4. Spójność z nawigacją między stronami
- Po przejściu na inną stronę sklepu (nowy reload): widget montuje się, C2 odtwarza rozmowę z localStorage+backend → user widzi ciągłość. To rozwiązuje główny problem.
- Jeśli czat był OTWARTY przed nawigacją: rozważyć zapamiętanie stanu otwarcia (sessionStorage 'dz_chat_open') by po reloadzie panel był od razu otwarty. OPCJONALNE — jeśli proste, dodać; jeśli komplikuje, pominąć (priorytet: nie tracić TREŚCI, otwarcie drugorzędne). Do decyzji CC, odnotować w raporcie co wybrano.

---

## Granice
- Backend: tylko nowy endpoint history (odczyt). NIE zmieniać ChatService/logiki czatu/startOrResume.
- Bezpieczeństwo: endpoint MUSI weryfikować właściciela (ps_customer_id vs customerId z HMAC) — nie zwracać cudzej rozmowy.
- Front: sessionId+ts w localStorage, NIE cała treść rozmowy (treść z backendu).
- Moduł: jedno pole configu (TTL). PHP 7.2/PS 1.7.6.
- Pliki UTF-8.

## Deploy (dwuczęściowy)
- CZĘŚĆ A (backend standalone): CC wdraża SAM (history endpoint + routes).
- CZĘŚĆ B+C (moduł PS): Karol wgrywa ręcznie (rsync, 116b). CC podaje komendę rsync.

## Kryteria akceptacji
1. Nowy GET /api/chat/history: HMAC chroniony; zwraca messages dla sessionId należącego do customera; {exists:false} dla nieistniejącej/zamkniętej; 403/404 dla cudzej rozmowy (zalogowany user ≠ właściciel).
2. Przejście między stronami sklepu: rozmowa odtwarza się (user widzi poprzednie wiadomości). GŁÓWNY problem rozwiązany.
3. Zamknięcie i ponowne otwarcie przeglądarki w okresie TTL: rozmowa wraca. Po TTL (default 30 dni) lub po „Nowa rozmowa": świeży czat.
4. Przycisk „Nowa rozmowa" czyści front (localStorage + widok), pokazuje welcome+chipy.
5. TTL konfigurowalny w panelu (default 30, walidacja 1-365).
6. History fetch padł/401 → graceful świeży czat, nie błąd/biały ekran.
7. Endpoint NIE ujawnia cudzej rozmowy po podstawieniu sessionId (test: zalogowany user A prosi o session_id rozmowy usera B → odmowa).
8. php -l backend clean; widget UTF-8; PHP 7.2/PS 1.7.6 dla modułu.
