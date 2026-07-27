# CHAT-T-072 — FRONTEND: mobilny widok rozmów (3 ekrany, vanilla, pod /m/)

**Status:** DONE (2026-06-05). Pełny raport: `_instances/frontend/handoff/CHAT-T-072_done.md`. 12/12 kryteriów PASS na PROD (Karol potwierdził telefon + desktop). Bug znaleziony i naprawiony w smoke: `.view { display: flex }` nadpisywało UA `[hidden] { display: none }` — fix `.view[hidden] { display: none }`.

**Instancja:** frontend (serwowane z backendu standalone). CC deployuje SAM (część backendu chat.divezone.pl, statyki w public/). ZERO modułu PS.
**Powiązany ADR:** ADR-086. **Zależność:** CHAT-T-071 DONE (auth + endpointy /m/api/* działają na PROD, 12/12).
**To drugi z 3 tasków mobile admin** (T-071 auth DONE → T-072 front → T-073 PWA).

## Cel
Lekki, mobilny interfejs do podglądu rozmów i reagowania, dostępny z telefonu BEZ wchodzenia w panel PS. Vanilla JS + CSS, serwowany jako statyka pod /m/, konsumuje gotowe /m/api/* (cookie sesji z T-071).

## KRYTYCZNE — ścieżka serwowania (cookie Path=/m)
- Cookie sesji `dz_madmin` ma Path=/m (T-071). Front MUSI być pod /m/, inaczej przeglądarka NIE dołączy cookie do /m/api/* i wszystko zwróci 401.
- Pliki: public/m/index.html, public/m/app.js, public/m/styles.css (+ później manifest/ikony w T-073).
- URL produkcyjny: https://chat.divezone.pl/m/
- Sprawdzić .htaccess w public/ — czy /m/ (statyka) nie jest przechwytywane przez router PHP. Jeśli router łapie wszystko, dodać wyjątek dla /m/*.html|css|js (statyka serwowana wprost, NIE przez index.php). API /m/api/* dalej przez router. To rozdzielenie potwierdzić w smoke (GET /m/ zwraca HTML, nie JSON 404).
- Wszystkie fetch z credentials:'include' (cookie same-origin /m → /m/api, ten sam origin chat.divezone.pl, ale credentials jawnie dla pewności).

## Kontrakt API (zweryfikowany na PROD — buduj 1:1 pod to)
- POST /m/api/login {email, password} → 200 {ok:true, role} + cookie | 401 {ok:false, error} | 429
- POST /m/api/logout → {ok:true}
- GET /m/api/whoami → {employee_id, role} | 401
- GET /m/api/conversations?page&per_page&search&knowledge_gap&admin_status → {conversations:[...], total, page, per_page}
  - wiersz: {id, session_id, customer_id, message_count, model_used, admin_status, knowledge_gap, started_at, updated_at, first_message, estimated_cost, tokens_*}
- GET /m/api/conversations/{session_id} → {session_id, customer_id, messages:[{role, content}], admin_status, admin_notes, started_at, updated_at, closed_at, conversation_cost,...}
  - messages role ∈ {user, assistant, tool_result, tool, system}. RENDEROWAĆ tylko user + assistant. Resztę POMIJAĆ (jak widget T-059).
- POST /m/api/conversations/{session_id}/status {status, notes} → {success:true,...} | 400 | 404
  - status ∈ {new, reviewed, knowledge_created, ignored} (enum 1:1 z desktopem — NIE wymyślać innych).

## EKRAN 1 — Logowanie
- Prosty formularz: email + hasło + przycisk "Zaloguj". Info: "Zaloguj się danymi pracownika sklepu" (te same co panel PS).
- Submit → POST /m/api/login. Sukces → przejdź do listy. 401 → pokaż "Nieprawidłowy login lub hasło" (komunikat z API). 429 → "Za dużo prób, spróbuj za 15 minut".
- Przy starcie aplikacji: GET /m/api/whoami — jeśli 200, pomiń login, idź do listy (sesja jeszcze ważna).
- NIE używać <form> z natywnym submit jeśli powoduje reload — preventDefault, fetch.

## EKRAN 2 — Lista rozmów (domyślny po zalogowaniu)
- DOMYŚLNY FILTR "wymagające uwagi" (ADR-086 214c): ekran startowy pokazuje rozmowy admin_status=new (jako główny sygnał "do obsługi"). Przełącznik/zakładki u góry:
  - "Do obsługi" (admin_status=new) — DEFAULT
  - "Luki wiedzy" (knowledge_gap=true)
  - "Wszystkie" (bez filtra)
  (Filtr ustala FRONT przez query params — backend generyczny.)
- Pole wyszukiwania (search) — opcjonalne, debounce.
- Każdy wiersz: first_message jako tytuł (truncate), pod spodem: znacznik czasu (updated_at, format PL/relatywny "2 godz. temu"), badge admin_status, badge knowledge_gap (jeśli true), liczba wiadomości. Wiersz klikalny → EKRAN 3.
- Paginacja: prosty "Załaduj więcej" (page+1, append) albo infinite scroll. total z odpowiedzi.
- Odświeżanie RĘCZNE (ADR-086 216a): pull-to-refresh (gest pociągnięcia w dół) + przycisk odświeżania w nagłówku. ZERO auto-pollingu/timera.
- Header: tytuł + przycisk odświeżania + menu (wyloguj).

## EKRAN 3 — Szczegóły rozmowy + reagowanie
- GET detail po session_id. Renderuj messages jako dymki: user (jedna strona) vs assistant (druga), tool_result/system POMIŃ. Zachowaj kolejność.
- Nagłówek: data rozmowy, customer_id (jeśli >0 "klient #id", else "gość"), model, koszt (conversation_cost jeśli jest).
- Sekcja "Reagowanie":
  - wybór statusu (segmented/select): new / reviewed / knowledge_created / ignored.
  - pole admin_notes (textarea, prefill z istniejącego admin_notes).
  - przycisk "Zapisz" → POST .../status {status, notes}. Sukces → toast "Zapisano", wróć do listy lub zostań z aktualizacją. 404 → "Rozmowa nie znaleziona".
- Przycisk "wstecz" do listy (zachowaj pozycję/filtr listy jeśli się da, prosty stan w JS).

## Styl / UX
- Mobile-first, jeden ekran szerokości telefonu. Duże dotykalne cele (min 44px). Czytelne na słońcu (kontrast). Bez bibliotek (zero npm) — vanilla.
- Lekki, szybki. Inline CSS w styles.css. Bez frameworka.
- Stan w pamięci JS (bez localStorage dla danych rozmów — RODO; tylko ewentualnie ostatni filtr).
- Obsługa 401 w każdym fetchu: sesja wygasła → wróć na ekran logowania (cicho, bez crashy).
- PL interfejs. Daty po polsku.

## Granice
- ZERO modułu PS. ZERO zmian w backendzie PHP (endpointy gotowe z T-071) — chyba że .htaccess wymaga wyjątku dla statyki /m/ (to jedyna dopuszczalna zmiana serwerowa, opisać).
- Tylko user+assistant w widoku rozmowy (nie pokazywać tool_result/system).
- credentials:'include' wszędzie. Brak danych klienta w localStorage/URL.
- Status enum 1:1 z backendem.
- Brak auto-odświeżania (216a).

## Kryteria akceptacji
1. GET https://chat.divezone.pl/m/ → zwraca HTML aplikacji (nie JSON, nie 404). Statyka serwowana, nie przez router.
2. Niezalogowany → ekran logowania. Po whoami 200 (ważna sesja) → od razu lista.
3. Login realnym kontem → lista rozmów, domyślnie filtr "Do obsługi" (new).
4. Przełączniki filtrów (Do obsługi / Luki wiedzy / Wszystkie) zmieniają listę (query params).
5. Wiersz pokazuje first_message + datę + status + ew. knowledge_gap. Klik → szczegóły.
6. Szczegóły: dymki user/assistant w kolejności, tool_result/system pominięte.
7. Zmiana statusu + notatka → zapis (POST status), potwierdzenie, dane utrwalone (re-fetch pokazuje nowy status).
8. Pull-to-refresh + przycisk odświeżania działają. BRAK auto-timera (sprawdzić w kodzie — żadnego setInterval pollującego API).
9. 401 w trakcie (wygaśnięcie) → powrót na logowanie bez crasha.
10. Wyloguj → logout + powrót na ekran logowania; whoami=401.
11. Działa na realnym telefonie: Android (Chrome) i iPhone (Safari) — czytelne, dotykalne, bez poziomego scrolla.
12. credentials:'include' obecne we wszystkich wywołaniach; cookie dz_madmin dołączane do /m/api/*.

## KROK FINALNY — deploy + raport + status + git
- Deploy: statyki do public/m/ na chat.divezone.pl (jak deploy backendu, SCP/rsync). Jeśli .htaccess wyjątek dla /m/ — wdrożyć i opisać.
- STOP przed deployem: raport (pliki, lokalny test logiki), potem deploy, potem smoke 1-12 (w tym test na telefonie — Karol potwierdza na Android, wspólniczka/Karol na iPhone jeśli dostępny).
- Raport: _instances/frontend/handoff/CHAT-T-072_done.md.
- Status: dopisać CHAT-T-072 do _docs/21_STATUS_PROJEKTU.md.
- Git: git status; git add per ścieżka (public/m/*, ew. public/.htaccess, task, handoff); commit wg konwencji (git log); push origin main. Osobny commit "docs:" dla statusu.
- NIE zaczynać T-073 (PWA) — STOP po T-072. (PWA dołoży manifest+ikony do działającego /m/.)
