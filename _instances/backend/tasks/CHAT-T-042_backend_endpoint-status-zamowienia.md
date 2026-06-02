# CHAT-T-042 — BACKEND: Endpoint statusu zamowienia (modal, bez AI)

**Data:** 2026-06-02
**Instancja:** backend
**Wejscie:** decyzja 87c (modal zamowienia pierwszy task), ADR-063 (status zamowienia walidowany PHP, numer+email NIE przez LLM). Istnieje standalone/src/Tools/OrderStatus.php (narzedzie AI, gotowa logika MySQL: order_reference+customer_email -> status+historia+tracking, weryfikuje tozsamosc po emailu).

---

## CEL
Endpoint HTTP, ktory pozwala widgetowi (modal "Status zamowienia") sprawdzic status BEZ udzialu AI. Modal wysle numer+email, backend zwroci status. Dane wrazliwe omijaja model (ADR-063).

## KLUCZOWE: reuzycie istniejacej logiki
OrderStatus::execute(['order_reference'=>..., 'customer_email'=>...]) JUZ robi cala robote: walidacja, weryfikacja tozsamosci (zamowienie musi pasowac do emaila), status, historia, tracking z URL przewoznika. NIE duplikowac tej logiki — endpoint ma ja WYWOLAC.

## ZAKRES
- Nowy controller: standalone/src/Controller/OrderStatusController.php (wzorzec jak istniejace kontrolery, np. ChatController — ten sam styl HMAC).
- Nowy route w config/routes.php: POST /api/order/status.
- Auth: kliencki HMAC, jak /api/chat/stream (X-DiveChat-Token/-Customer/-Time, HmacVerifier, sekret DIVECHAT_SECRET). Anonim customerId=0 dozwolony (klient sprawdza zamowienie bez logowania — tozsamosc weryfikuje email+numer, nie sesja).
- Body: {"order_reference":"...", "email":"..."}.
- Logika: wywolaj OrderStatus::execute z przemapowanymi parametrami (email -> customer_email, order_reference -> order_reference). Zwroc JSON 200 z wynikiem; jesli execute zwroci ['error'=>...], zwroc 404/422 z tym komunikatem (NIE 500 — to normalny przypadek "nie znaleziono").
- ZERO LLM. ZERO wywolania ChatService. Czysty lookup.

## BEZPIECZENSTWO (wazne, ale nie przesadzic na MVP)
- To lookup po emailu+numerze — potencjalny wektor enumeracji. Na MVP: komunikat bledu IDENTYCZNY niezaleznie czy nie ma takiego numeru, czy email nie pasuje (OrderStatus juz to robi — jeden komunikat "nie znaleziono"). Potwierdz, ze nie wyciekamy roznicy.
- Rate-limiting: ZAZNACZ w raporcie jako rekomendacje na etap publiczny (np. N prob/min/IP przez Cloudflare lub w backendzie), ale NIE wdrazaj teraz — etap 1 widgetu i tak po IP Karola. Nie blokuje MVP.
- NIE loguj emaila/numeru w plain (jesli jest logowanie requestow — zamaskuj). Sprawdz czy istniejacy logger tego nie zrzuca.

## KROKI
KROK 0 — git pull. Przeczytaj ten task, ADR-063, OrderStatus.php (logika), ChatController.php (wzorzec HMAC + JSON response), HmacVerifier, routes.php (jak rejestrowane trasy + jak wstrzykiwane zaleznosci).
KROK 1 — OrderStatusController: konstruktor z zaleznosciami (HmacVerifier, OrderStatus lub MysqlConnection wg wzorca DI w routes.php). Metoda handle: weryfikuj HMAC -> parsuj body -> wywolaj OrderStatus::execute -> zwroc JSON (200 sukces / 404 nie znaleziono / 400 brak pol / 401 zly HMAC).
KROK 2 — Route POST /api/order/status w routes.php (wzorzec jak inne POST, ten sam blok co chat).
KROK 3 — Test: curl z poprawnym HMAC + realnym numerem+emailem z bazy (znajdz jeden testowy w pr_orders) -> 200 ze statusem. Zly email -> 404 "nie znaleziono". Brak pol -> 400. Zly HMAC -> 401. Pokaz wyniki (zamaskuj realny email/numer w raporcie).
KROK 4 — GIT: git add standalone/src/Controller/OrderStatusController.php standalone/config/routes.php. commit "CHAT-T-042: endpoint statusu zamowienia (modal, bez AI, reuzycie OrderStatus)". push. docs: commit ze statusem. Handoff LOKALNY.

## RAPORT
KROK 0: potwierdz wzorzec DI z routes.php (jak wstrzykiwane kontrolery) + format odpowiedzi ChatController. Po wdrozeniu: kontrakt endpointu (body, naglowki, kody odpowiedzi, ksztalt JSON sukcesu — do uzycia przez modal w CHAT-T-043), wynik testow (zamaskowane dane), potwierdzenie ZERO LLM + jednolity komunikat bledu, rekomendacja rate-limit na pozniej.
