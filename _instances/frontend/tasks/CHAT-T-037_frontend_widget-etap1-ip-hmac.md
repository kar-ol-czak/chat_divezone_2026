# CHAT-T-037 — FRONTEND: Widget czatu etap 1 (MVP po IP, HMAC, Shadow DOM)

**Data:** 2026-06-02
**Instancja:** frontend
**Wejscie:** ADR-060 (osadzenie: modul PS, displayFooter, fasada), ADR-061 (Shadow DOM, mobile fullscreen, Visual Viewport), ADR-062 (model docelowy — NIE wdrazamy teraz), ADR-063 (RODO: pasywna nota, bez bramki), ADR-069 (etap 1 na HMAC, auth wymienialna warstwa), brief `_docs/24_brief_widget_claude_design.md`, projekt Claude Design (zrzut: zielony chrome #1a5e5a/grafit, launcher, chipy, nota RODO).

---

## CEL ETAPU 1
Dzialajacy widget czatu na ZYWYM sklepie divezone.pl, widoczny TYLKO po IP Karola. Czat tekstowy end-to-end: launcher -> okno -> wpis -> SSE -> odpowiedz bota renderowana. To pierwsza walidacja calego lancucha widget->backend na produkcji. Reszta (karty produktow, modal zamowienia, Turnstile, rate-limit, pelna dostepnosc) = pozniejsze etapy, NIE teraz.

## ZRODLO PRAWDY O WYGLADZIE (KRYTYCZNE)
Pixel-perfect handoff Claude Design: `_drafts/design_handoff_divezone_chat_widget/`
- `README.md` — design tokeny (pelna paleta hex), typografia (DM Sans), promienie, cienie, odstepy, copy, ikony SVG, opis komponentow. WARTOSCI WIAZACE.
- `Divezone Chat Widget - Hi-Fi.html` — prototyp hifi (React via Babel, paleta P1 = JEDYNA zatwierdzona; P2 ignorowac). Komponenty: BrandMark, ChatHeader, BotAvatar, BotBubble, ChipRow, PrivacyNote, InputBar, Launcher, WelcomeWidget, MobileConvoWidget, MarkdownText, MsgBubble.
- `Divezone Chat Widget - Wireframe.html` — stany do PRZYSZLYCH etapow (karty/zamowienie/fallback) — NIE w etapie 1.
ODTWARZAMY te projekty w vanilla JS + Shadow DOM (NIE kopiujemy React, NIE Babel; ADR-061 wyklucza framework). Z plikow bierzemy WARTOSCI (tokeny, wymiary, copy, inline-SVG, strukture), przepisujemy na czysty JS+CSS w shadow root. Tokeny -> CSS custom properties (--dz-*).

## KOREKTA KOLORU (wzgledem ADR-060)
Chrome marki = TEAL `#1e6363` (handoff Claude Design "Wariant 1", potwierdzony zrzutem Karola), NIE grafit #292b2d z ADR-060. Paleta w calosci z README (--dz-header-bg #1e6363, --dz-accent #e8a800 bursztyn, --dz-chat-bg #f3fafa itd.). Font DM Sans z Google Fonts (uwaga: @font-face do light DOM, znany bug fontow w shadow root — README/ADR-061; w razie problemu font systemowy fallback).

## ZAKRES — TYLKO TO
- Launcher (dymek 56-60px, prawy dolny rog) + okno czatu (desktop ~384x680, mobile fullscreen overlay).
- Wiadomosc powitalna + chipy szybkich odpowiedzi (z briefu copy PL).
- Pole wpisu + wysylka. Render wiadomosci uzytkownika i bota (markdown: bold, linki, listy).
- Streaming przez fetch+ReadableStream (NIE EventSource), wskaznik "Asystent pisze...".
- Pasywna nota RODO nad polem wpisu (z briefu, link do polityki). BEZ bramki zgody (decyzja Karola).
- Auth: kliencki HMAC (ADR-069). Shim PHP 7.2 w module generuje token (hash_hmac DIVECHAT_SECRET, payload "customerId:timestamp", anonim customerId=0). Widget woła POST /api/chat/stream z naglowkami X-DiveChat-Token/-Customer/-Time.
- Gating ekspozycji: widget wstrzykiwany przez displayFooter TYLKO gdy IP odwiedzajacego == skonfigurowane IP Karola. Inaczej hook nic nie renderuje.

## POZA ZAKRESEM (nie rob)
Karty produktow (struktura function-calling — ADR-064, pozniej), modal statusu zamowienia (ADR-063, pozniej), Turnstile + rate-limit (ADR-064, etap publiczny), JWT/sesja/merge (ADR-062, etap 2/3), pelna drabina ekspozycji (pracownicy/zalogowani/PL — etap 2), testy NVDA/VoiceOver (bramka etapu publicznego, nie teraz — ale NIE psuj semantyki: role="dialog", aria-modal w fullscreen, Esc zamyka).

## ARCHITEKTURA (z ADR — trzymac sie)
- Modul PS divezone_chat, hook displayFooter (dorejestrowac przy install — modul juz zainstalowany z panelem, dodajemy hook front + controller front).
- Fasada (ADR-060): maly stub renderuje launcher na requestIdleCallback; ciezki bundle widgetu dociaga po pierwszej interakcji. Cel stub <20KB gzip, bundle <150KB gzip.
- Shadow DOM open, caly widget w jednym shadow root, :host{all:initial}, font systemowy w MVP (ADR-061).
- Mobile: position:fixed inset:0, height:100dvh, font-size:16px na inpucie, Visual Viewport API -> zmienna CSS --vvh, overscroll-behavior:contain (ADR-061).
- **Auth jako WYMIENIALNA WARSTWA (ADR-069 warunek):** jeden modul/plik transport (np. transport.js) hermetyzuje auth+streaming. Reszta widgetu nie wie, czy to HMAC czy JWT. Wymiana na JWT (etap 2/3) = podmiana tego jednego pliku.
- IP gating: lista IP w konfiguracji modulu (Configuration::get, klucz np. DIVEZONE_CHAT_ALLOWED_IPS), NIE hardcode. Karol wprowadza swoje IP sam (jak sekret). Pusta lista -> widget niewidoczny dla nikogo (bezpieczny default).

## KROKI
KROK 0 — READ: git pull. Przeczytaj ten task, ADR-060/061/062/063/069, brief 24. Obejrzyj istniejacy modul (divezone_chat.php, controllers/admin — wzorzec; controllers/front PUSTY). Potwierdz kontrakt /api/chat/stream (ChatController::stream — naglowki HMAC, format SSE) i HmacVerifier (payload customerId:timestamp).
KROK 1 — Shim + hook + gating (PHP 7.2 w module): controller front lub hook displayFooter; generowanie tokenu HMAC; odczyt IP odwiedzajacego (uwaga na proxy/Cloudflare — naglowek CF-Connecting-IP jesli za Cloudflare, potwierdz); gating po DIVEZONE_CHAT_ALLOWED_IPS; wstrzykniecie stub fasady. Pole na IP + (jesli trzeba) URL backendu w getContent() konfiguracji modulu.
KROK 2 — Stub fasady + bundle widgetu (JS/CSS): Shadow DOM, launcher, okno, render, transport.js (auth+SSE wymienialny), nota RODO, chipy, mobile (Visual Viewport). Branding wg briefu/zrzutu (zielony chrome, grafit, logo divezone).
KROK 3 — Smoke: lokalnie/dev jak mozliwe. Na prod wymaga rak Karola (wgranie modulu + wpisanie IP). Przygotuj instrukcje: jak wgrac, gdzie wpisac IP, jak przetestowac (otworz sklep z dozwolonego IP -> launcher widoczny -> czat dziala; z innego IP -> brak launchera).
KROK 4 — GIT: git add konkretne sciezki modulu (modules/divezone_chat/controllers/front/*, views/js/*, views/css/*, views/templates/*, zmiany divezone_chat.php). NIE commituj IP ani sekretow. commit "CHAT-T-037: widget czatu etap 1 (MVP po IP, HMAC, Shadow DOM)". push. Osobny docs: commit ze statusem. ADR-069 commit osobno (patrz nizej). Handoff LOKALNY.

## UWAGI
- Modul divezone_chat JEST juz zainstalowany na prod (panel admin). Dodanie hooka front + controllera front wymaga albo reinstalacji, albo registerHook przy upgrade. Zaproponuj najczystsza droge (registerHook w methodzie upgrade modulu / przez panel "Resetuj"?) — opisz dla Karola, NIE zakladaj reinstalacji bez ostrzezenia (panel dziala, nie zepsuc).
- Cloudflare: sklep prawdopodobnie za Cloudflare (rate limiting/Turnstile w stacku per pamiec projektu). IP odwiedzajacego brac z CF-Connecting-IP, nie REMOTE_ADDR (bo to byloby IP Cloudflare). POTWIERDZIC w KROK 0/1.

## RAPORT
KROK 0: kontrakt /api/chat/stream + jak modul czyta IP za Cloudflare + droga dodania hooka front bez psucia panelu.
Po wdrozeniu: instrukcja dla Karola (wgranie, IP, test), potwierdzenie ze panel admin nietkniety, ze transport jest wymienialna warstwa.
