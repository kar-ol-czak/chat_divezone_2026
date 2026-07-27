# CHAT-T-044 — BACKEND: Zabezpieczenie /api/settings kanalem serwerowym panelu (+ audyt /api/admin/pricing)

**Data:** 2026-06-02
**Instancja:** backend
**Wejscie:** decyzja 90a/91a/92a (UI modeli w panelu PS, kanal serwerowy). Rozpoznanie: /api/settings (GET+POST) jest dzis BEZ autoryzacji — SettingsController nie ma w srodku zadnego HMAC/middleware, w routes.php nie owijany. To LUKA: endpoint zmienia model AI / max_tokens / reasoning, dostepny publicznie. Wzorzec docelowy: AdminWhoamiController + AdminRecommendationsController uzywaja ServerHmacVerifier (ADR-068, kanal serwerowy panelu PS).

---

## CEL
Zabezpieczyc /api/settings kanalem serwerowym (ServerHmacVerifier), zeby (1) domknac luke — tylko panel PS z waznym employee_id moze czytac/zmieniac ustawienia, (2) umozliwic UI modeli w PS dostep do endpointu. Po tym tasku panel PS bedzie mogl czytac/zapisywac ustawienia, a anonimowy dostep zniknie.

## WZORZEC DO NASLADOWANIA (NIE wymyslac wlasnego)
AdminWhoamiController.php i AdminRecommendationsController.php:
- konstruktor przyjmuje ServerHmacVerifier
- czyta naglowki: X-DiveChat-Server-Token (HMAC sha256 hex, payload employee_id:timestamp), X-DiveChat-Server-Employee (int), X-DiveChat-Server-Time (unix, anti-replay ±300s)
- 401 gdy brak/zly token, 403 gdy employee bez roli (jesli dotyczy)
SettingsController ma dostac IDENTYCZNY mechanizm. Sekret: DIVECHAT_SERVER_SECRET (NIE kliencki).

## ZAKRES
- SettingsController: konstruktor dodaje ServerHmacVerifier (jak whoami). Metody get() i post() NAJPIERW weryfikuja kanal serwerowy; brak/zly -> 401, przed jakimkolwiek odczytem/zapisem.
- routes.php: wstrzyknac ServerHmacVerifier do SettingsController (jak przy whoami/recommendations — sprawdz jak tam tworzony verifier i to powtorz).
- Autoryzacja roli: sprawdz jak recommendations traktuje role (czy wymaga konkretnej roli z divechat_admin_roles, czy wystarczy poprawny employee). Settings to zmiana konfiguracji AI — powinna wymagac roli 'admin' (nie 'operator'), jesli system rol to wspiera. Jesli recommendations dopuszcza oba — settings ma byc STRICTER (tylko admin). Potwierdz wzorzec rol i zastosuj: zmiana modelu/ustawien = rola admin.
- POZA ZAKRESEM: zmiana logiki samych ustawien, dodawanie modeli do enuma (Gemini itp. — osobny temat), UI (to CHAT-T-045).

## AUDYT PRZY OKAZJI (zaznacz, NIE naprawiaj w tym tasku jesli wieksze)
- /api/admin/pricing (AdminPricingController) tez wyglada na NIEzabezpieczone w routes.php (brak middleware, w odroznieniu od /api/admin/cost/*). Sprawdz czy ma auth w srodku kontrolera. Jesli NIE — to ta sama luka (endpoint zmienia ceny modeli). Zaraportuj; jesli fix trywialny (ten sam ServerHmacVerifier) — mozesz dodac w tym tasku i odnotowac; jesli wymaga wiecej — osobny task.
- Sprawdz czy sa INNE trasy /api/* bez auth, ktore zmieniaja stan (POST). Wylistuj w raporcie (nie naprawiaj wszystkich — tylko inwentaryzacja do decyzji Karola).

## KROKI
KROK 0 — git pull. Przeczytaj ten task, ADR-068 (kanal serwerowy), AdminWhoamiController.php + AdminRecommendationsController.php (wzorzec ServerHmacVerifier + role), SettingsController.php, routes.php (jak wstrzykiwany verifier, blok Admin: Settings). Zaraportuj jak recommendations obsluguje role -> STOP jesli niejasne czy settings ma byc admin-only; jak jasne, kontynuuj.
KROK 1 — SettingsController: dodaj ServerHmacVerifier do konstruktora, weryfikacja na poczatku get() i post() (401 brak/zly token), kontrola roli admin (403 jak brak). Wzorzec 1:1 z whoami/recommendations.
KROK 2 — routes.php: wstrzyknij ServerHmacVerifier do SettingsController (jak przy innych admin-kontrolerach kanalu serwerowego).
KROK 3 — AUDYT: sprawdz /api/admin/pricing + inne POST /api/* bez auth. Jesli pricing to trywialny ten sam fix — dodaj; inaczej zaraportuj. Wylistuj inne luki (bez naprawy).
KROK 4 — TEST (curl): (a) GET /api/settings BEZ naglowkow serwerowych -> 401 (wczesniej 200 = luka domknieta). (b) z poprawnym kanalem serwerowym (employee admin) -> 200 z settings+available_models. (c) employee bez roli admin -> 403. (d) POST zmiana max_tokens przez kanal serwerowy -> 200, weryfikuj zapis w divechat_settings. Zamaskuj sekrety w raporcie.
KROK 5 — GIT: git add standalone/src/Controller/SettingsController.php standalone/config/routes.php (+ AdminPricingController jesli fix dodany). commit "CHAT-T-044: zabezpieczenie /api/settings kanalem serwerowym (ServerHmacVerifier, admin-only) + audyt pricing". push. docs: commit ze statusem. Handoff LOKALNY.

## RAPORT
KROK 0: jak recommendations obsluguje role (czy settings ma byc admin-only). Po wdrozeniu: potwierdzenie 401 bez tokena (luka domknieta), 200 z kanalem serwerowym, 403 bez roli admin, wynik audytu pricing + lista innych niezabezpieczonych POST /api/* (do decyzji Karola). Kontrakt dla CHAT-T-045 (jak panel PS ma wolac /api/settings — te same naglowki co recommendations).
