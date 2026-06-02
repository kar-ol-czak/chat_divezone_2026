# CHAT-T-043 — FRONTEND: Modal statusu zamowienia (formularz, bez AI)

**Data:** 2026-06-02
**Instancja:** frontend
**Wejscie:** CHAT-T-042 (endpoint POST /api/order/status gotowy, kontrakt nizej), ADR-063 (status zamowienia walidowany PHP, numer+email NIE przez LLM), handoff Claude Design (_drafts/design_handoff_divezone_chat_widget/ — wireframe ma ekran formularza zamowienia), widget z CHAT-T-037 (Shadow DOM, transport.js generuje HMAC).

---

## CEL
Chip "Status zamowienia" otwiera MODAL z formularzem (numer zamowienia + email) zamiast wysylac wiadomosc do AI. Po submit: wywolanie /api/order/status, render statusu (status, historia, tracking). Calkowicie omija LLM (ADR-063).

## KONTRAKT ENDPOINTU (z CHAT-T-042)
POST https://chat.divezone.pl/api/order/status
Naglowki: Content-Type: application/json + HMAC (X-DiveChat-Token/-Customer/-Time) — IDENTYCZNE jak czat, transport.js juz to generuje.
Body: {"order_reference":"GBUQNGUCR", "email":"klient@example.com"}
Odpowiedzi:
- 200 {success:true, order:{reference, date, status, total, history:[{status,date}...], tracking?:{carrier,number,url}}}
  (tracking OPCJONALNE — moze nie byc; history sortowane DESC, najnowszy pierwszy)
- 400 {error:"Pola ... wymagane"}
- 401 {error:"Nieprawidlowy token"} (token wygasl >5min — patrz nizej)
- 404 {error:"Nie znaleziono zamowienia ... Sprawdz dane."} (jednolity: zly email I nieistniejacy numer = ten sam komunikat)
- 500 {error:"Wystapil blad. Sprobuj ponownie."}

## ZAKRES
- Komponent modala w istniejacym widgecie (ten sam Shadow DOM, overlay nad oknem czatu). Wyglad wg handoffu Claude Design (wireframe — ekran formularza zamowienia; jesli brak dokladnego ekranu, uzyc tokenow/stylu z README, spojnie z reszta widgetu).
- Chip "Status zamowienia" NIE wysyla wiadomosci do AI (jak inne chipy) — otwiera modal. Zmiana handlera tego jednego chipa.
- Formularz: pole "Numer zamowienia" (placeholder z przykladem formatu, np. GBUQNGUCR — ciag liter z maila potwierdzajacego) + pole "Email". Walidacja klienta: oba niepuste przed submit (email — podstawowy pattern). Przyciski: "Sprawdz" + "Zamknij/wstecz".
- Submit: NOWA metoda w transport.js (np. checkOrderStatus(reference, email)) — reuzyj generowania HMAC + fetch, ale to OSOBNE wywolanie (nie SSE, zwykly fetch JSON, nie /api/chat/stream). Warstwa transportu pozostaje wymienialna (ADR-069).
- Render wyniku (200): status zamowienia (wyrozniony), data, kwota, historia statusow (lista DESC), tracking jako KLIKALNY link (jesli jest) — "Sledz przesylke" -> tracking.url w nowej karcie. Format dat czytelny (PL).
- Render bledow: 404 -> komunikat z backendu ("Nie znaleziono ... Sprawdz dane.") przy formularzu, pozwol poprawic i sprobowac ponownie. 400 -> podswietl brakujace pole. 500 -> generyczny komunikat + sugestia ponow. 401 -> patrz nizej.
- Dostepnosc: modal role="dialog" aria-modal="true", focus trap, Esc zamyka, focus na pierwszym polu po otwarciu, aria-live na wyniku/bledzie.

## OBSLUGA WYGASNIECIA TOKENA (401) — wazne
Token HMAC zyje 5 min od zaladowania strony (BOOT token). Jesli klient otworzy modal pozno i dostanie 401: pokaz przyjazny komunikat "Sesja wygasla, odswiez strone i sprobuj ponownie" zamiast surowego "Nieprawidlowy token". To samo ograniczenie co czat (etap 2/JWT rozwiaze). NIE probuj sam odswiezac tokena — to poza zakresem etapu 1.

## RODO (ADR-063)
- Email i numer to dane wprowadzane przez klienta do walidacji — NIE loguj ich w konsoli, NIE wysylaj nigdzie poza /api/order/status. Brak analytics na tych polach.
- Krotka nota przy formularzu: dane sluza tylko do sprawdzenia statusu (spojnie z pasywna nota RODO widgetu).

## POZA ZAKRESEM
- Rate-limit (backend/CF, etap publiczny). Turnstile. Zapamietywanie danych zamowienia (zero persystencji — po zamknieciu modala znika). Autouzupelnianie z konta zalogowanego (etap 2 — na razie klient wpisuje recznie, nawet zalogowany).

## KROKI
KROK 0 — git pull. Przeczytaj ten task, ADR-063, handoff Claude Design (wireframe — szukaj ekranu zamowienia), widget-bundle.js + transport.js z CHAT-T-037 (jak zbudowane komponenty, jak chip wywoluje akcje, jak transport robi HMAC).
KROK 1 — transport.js: dodaj checkOrderStatus(reference, email, {onSuccess, onError}) — fetch POST /api/order/status z naglowkami HMAC (reuzyj istniejacego generatora), parsuj JSON, mapuj kody (200/400/401/404/500) na onSuccess/onError z czytelnym komunikatem. Warstwa pozostaje wymienialna.
KROK 2 — Modal w widget-bundle.js: komponent (overlay shadow DOM), formularz, walidacja klienta, render wyniku/bledu, dostepnosc (dialog/focus trap/Esc). Wyglad z handoffu.
KROK 3 — Podpiecie chipa: "Status zamowienia" otwiera modal zamiast sendUserMessage. Reszta chipow bez zmian.
KROK 4 — Test lokalny jesli mozliwe (lub instrukcja dla Karola): poprawny numer+email -> status; zly email -> 404 komunikat; puste pole -> walidacja klienta; stary token -> przyjazny 401.
KROK 5 — GIT: git add modules/divezone_chat/views/js/transport.js modules/divezone_chat/views/js/widget-bundle.js (+ css jesli trzeba). commit "CHAT-T-043: modal statusu zamowienia (formularz, bez AI, /api/order/status)". push. docs: commit ze statusem. Handoff LOKALNY. Pliki widgetu wymagaja wgrania na PROD (jak CHAT-T-037) — opisz w instrukcji dla Karola (rsync modulu, bez "Aktualizuj" bo to tylko assety JS, nie zmiana wersji modulu — POTWIERDZ czy assety JS lapane bez bumpa wersji czy trzeba czyscic cache).

## RAPORT
Co zbudowane, potwierdzenie: modal omija AI (osobny fetch, nie SSE), transport wymienialny, wyglad zgodny z handoffem, obsluga 401/404/400, RODO (brak logowania PII). Instrukcja wgrania assetow na PROD dla Karola.
