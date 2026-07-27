# CHAT-T-046 — BACKEND: Zabezpieczenie /api/conversations/* kanalem serwerowym (domkniecie luki audytu)

**Data:** 2026-06-02
**Instancja:** backend
**Wejscie:** audyt z CHAT-T-044 wykryl 3 luki: /api/conversations (GET, lista sesji), /api/conversations/{session_id} (GET, pelna rozmowa), /api/conversations/{session_id}/status (POST, zmiana statusu) — wszystkie BEZ autoryzacji. Decyzja 95b: ZABEZPIECZYC (nie usuwac) — stary widok historii bedzie przeniesiony do zakladki "Rozmowy" w panelu PS (kierunek: calosc /admin/ standalone -> Presta). Decyzja 96a: bezpieczenstwo najpierw, przed UI modeli.
**Uzycie dzis:** standalone/public/js/history.js wola te endpointy (stary widok historii). Nowy panel analityki uzywa INNYCH, chronionych /api/admin/conversations/* (AdminAuthMiddleware) — tych NIE ruszac.

---

## CEL
Domknac 3 luki: /api/conversations/* ma wymagac kanalu serwerowego (ServerHmacVerifier, jak settings/recommendations/whoami — ADR-068). Po tym tasku zaden z endpointow backendu zmieniajacych/ujawniajacych dane nie jest otwarty.

## WAZNE — ROLA (inna niz settings)
- settings/pricing = admin-only (konfiguracja silnika).
- conversations = dane operacyjne obslugi (rozmowy klientow, status) -> ANY ROLE (operator + admin), jak /api/admin/recommendations (decyzja 36a). Operator MUSI widziec rozmowy — to jego praca. Wiec wzorzec rol = recommendations (any role z wpisem w divechat_admin_roles), NIE admin-only.

## WZORZEC (NIE wymyslac)
ConversationsController dostaje ServerHmacVerifier + sprawdzenie roli jak AdminRecommendationsController (token X-DiveChat-Server-*, payload employee_id:timestamp, 401 brak/zly, 403 no_role). Wszystkie 3 metody (list, detail, updateStatus) za ta sama brama.

## ZAKRES
- ConversationsController.php: konstruktor + ServerHmacVerifier (+ PostgresConnection do sprawdzenia roli, jak recommendations). Kazda z 3 metod (list/detail/updateStatus) zaczyna od weryfikacji kanalu serwerowego + roli (any role). 401/403 jak recommendations.
- routes.php: wstrzyknij ServerHmacVerifier do ConversationsController (reuzyj istniejacej instancji $serverVerifier utworzonej w CHAT-T-044).
- POZA ZAKRESEM: przenoszenie history.js do PS (to przyszla zakladka "Rozmowy", osobny task po decyzji o migracji /admin->PS). NIE usuwac endpointow. NIE ruszac /api/admin/conversations/* (te juz chronione AdminAuthMiddleware). NIE ruszac history.js (stary panel przestanie dzialac bo nie wysyla naglowkow serwerowych — to OK, bedzie przeniesiony; odnotuj w raporcie ze stary widok przestanie dzialac do czasu migracji do PS).

## KROKI
KROK 0 — git pull. Przeczytaj ten task, raport CHAT-T-044 (wzorzec), AdminRecommendationsController.php (any-role wzorzec), ConversationsController.php (3 metody), routes.php (blok Admin: Conversations + gdzie tworzony $serverVerifier).
KROK 1 — ConversationsController: ServerHmacVerifier + role-check (any role) na poczatku list(), detail(), updateStatus(). Wzorzec 1:1 z recommendations.
KROK 2 — routes.php: wstrzyknij verifier (reuzyj $serverVerifier z CHAT-T-044).
KROK 3 — TEST (curl): (a) GET /api/conversations BEZ naglowkow -> 401 (luka domknieta); (b) z kanalem serwerowym (dowolna rola) -> 200; (c) GET /api/conversations/{sid} bez -> 401, z -> 200; (d) POST status bez -> 401, z -> 200. Uzyj realnego session_id z bazy (zamaskuj w raporcie).
KROK 4 — GIT: git add standalone/src/Controller/ConversationsController.php standalone/config/routes.php. commit "CHAT-T-046: zabezpieczenie /api/conversations/* kanalem serwerowym (any-role, domkniecie luki audytu CHAT-T-044)". push. docs: commit ze statusem. Handoff LOKALNY.

## RAPORT
Potwierdzenie 3x 401 bez tokena (luki domkniete), 200 z kanalem serwerowym (any role), odnotowanie ze stary history.js przestanie dzialac do migracji do PS (oczekiwane). Czy zostaly JESZCZE jakies otwarte POST/GET /api/* zmieniajace stan (finalna inwentaryzacja — powinno byc czysto po tym tasku).
