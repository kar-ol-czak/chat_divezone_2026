# CHAT-T-073 — FRONTEND: PWA dla /m (manifest + ikony + instalacja na ekranie głównym)

**Status:** DONE (2026-06-05). Pełny raport: `_instances/frontend/handoff/CHAT-T-073_done.md`. 10/10 kryteriów PASS (K4 iPhone do potwierdzenia ad hoc gdy wspólniczka dostępna). Karol potwierdził Android Chrome: menu → "Zainstaluj aplikację" → ikona DiveZone na ekranie głównym, uruchamia się standalone. Mobile admin MVP KOMPLETNY (T-071+T-072+T-073+T-074+T-075). Push fazy 2 osobno gdy wolumen wzrośnie.

**Instancja:** frontend (standalone public/m/). CC deployuje SAM. ZERO modułu PS, ZERO backendu PHP.
**Powiązany ADR:** ADR-086. **Zależność:** CHAT-T-072/075 DONE (/m działa, markdown OK). Ostatni z 3 tasków mobile admin MVP.

## Cel
Zrobić z /m instalowalną PWA: ikona na ekranie głównym telefonu (zamiast wpisywania URL), tryb standalone (bez paska przeglądarki). Działa na Android (Karol) I iPhone (wspólniczka). Fundament pod push fazy 2 (iOS: push TYLKO z PWA na ekranie głównym — ADR-086 218b/219a).

## Stan obecny (zweryfikowany)
public/m/ ma: index.html (już z theme-color #1e6363 + viewport-fit=cover), app.js, styles.css. BRAK: manifest, ikony, apple-touch-icon, service worker.

## Źródło ikony (NIE generować logo od zera)
Sklep ma logo/favicon: ~/public_html/newtmp2/img/favicon128x128.png oraz logo-divezone-fv.png / divezone-logo*.jpg. Użyć istniejącego logo DiveZone jako źródła i PRZESKALOWAĆ do wymaganych rozmiarów (ImageMagick/skrypt). To przeskalowanie istniejącego assetu marki, NIE tworzenie nowego logo. Jeśli favicon128 za mały/słaby na duże ikony — użyć większego logo-divezone-fv.png jako źródła. Tło ikony: jeśli logo ma przezroczystość/nieregularny kształt, podłożyć tło marki (#1e6363 lub białe) dla wersji maskable.

## KROK 1 — ikony (public/m/icons/)
- Wygenerować z logo DiveZone:
  - icon-192.png (192×192) — wymagane Android.
  - icon-512.png (512×512) — wymagane (splash/instalacja).
  - icon-192-maskable.png + icon-512-maskable.png (purpose "maskable" — z marginesem bezpieczeństwa ~10% i tłem, by Android nie obciął logo).
  - apple-touch-icon.png (180×180) — iPhone (BEZ przezroczystości, iOS nie lubi alpha — płaskie tło).
- Umieścić w public/m/icons/.

## KROK 2 — manifest (public/m/manifest.webmanifest)
- Pola:
  - name: "DiveZone — rozmowy"
  - short_name: "DiveZone" (krótkie pod ikoną)
  - start_url: "/m/" (WAŻNE: z ukośnikiem, ten sam scope co cookie Path=/m)
  - scope: "/m/"
  - display: "standalone"
  - orientation: "portrait" (panel mobilny pionowy)
  - theme_color: "#1e6363", background_color: "#f3f5f6"
  - lang: "pl", dir: "ltr"
  - icons: 192, 512 (purpose "any") + 192-maskable, 512-maskable (purpose "maskable").
- Serwowanie: plik statyczny w public/m/, MIME application/manifest+json (jeśli serwer nie ustawia — rozszerzenie .webmanifest zwykle wystarcza; jeśli problem, użyć manifest.json). Sprawdzić w smoke że GET /m/manifest.webmanifest → 200 z poprawnym Content-Type.

## KROK 3 — linki w public/m/index.html <head>
- <link rel="manifest" href="manifest.webmanifest">
- <link rel="apple-touch-icon" href="icons/apple-touch-icon.png">
- iOS PWA meta (dla standalone i nazwy):
  - <meta name="apple-mobile-web-app-capable" content="yes">
  - <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"> (spójne z theme teal/viewport-fit cover)
  - <meta name="apple-mobile-web-app-title" content="DiveZone">
- theme-color już jest (#1e6363) — zostawić.
- Ścieżki RELATYWNE (manifest.webmanifest, icons/...) — bo /m/ to scope; nie używać absolutnych /manifest który wyszedłby poza scope.

## KROK 4 — service worker (public/m/sw.js) — MINIMALNY
- iOS wymaga SW do pełnej instalowalności/standalone; Android też lubi. MVP: SW minimalny, BEZ agresywnego cache (panel pokazuje DANE NA ŻYWO — rozmowy; NIE chcemy serwować stale’owanych rozmów z cache).
- Zakres SW MVP:
  - install: skip waiting.
  - activate: claim clients, czyść stare cache.
  - fetch: dla nawigacji (HTML) i statyk (app.js/styles.css/icons) — network-first z fallbackiem do cache (offline = pokaż powłokę aplikacji). Dla /m/api/* — ZAWSZE network, NIGDY cache (dane na żywo, auth). Wprost wykluczyć /m/api/ z cache.
- Rejestracja w app.js (lub index.html): if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js', {scope:'/m/'}). Po sukcesie nic nie blokuje UI.
- NIE implementować push w tym tasku (faza 2). SW tylko shell + poprawne pomijanie API.

## Granice
- TYLKO public/m/ (index.html, manifest, icons/, sw.js, ew. drobny dopisek w app.js do rejestracji SW). ZERO backendu, ZERO modułu PS, ZERO bibliotek.
- SW NIE cache’uje /m/api/* (dane na żywo + auth). NIE cache’uje rozmów.
- Ikony = przeskalowane logo DiveZone (nie nowe logo).
- Scope /m/ wszędzie (start_url, scope, rejestracja SW) — spójnie z cookie Path=/m.
- Nie ruszać logiki auth/listy/detalu/markdown z poprzednich tasków.

## Kryteria akceptacji
1. GET /m/manifest.webmanifest → 200, poprawny JSON, Content-Type manifest/json (lub application/json akceptowalne).
2. GET /m/icons/icon-192.png, icon-512.png, maskable, apple-touch-icon.png → 200.
3. Android Chrome: wejście na /m/ → przeglądarka oferuje "Dodaj do ekranu głównego" / "Zainstaluj"; po instalacji ikona DiveZone, uruchomienie w trybie standalone (bez paska URL).
4. iPhone Safari: Udostępnij → "Dodaj do ekranu głównego" pokazuje ikonę DiveZone (apple-touch-icon, nie zrzut strony) + nazwę "DiveZone"; uruchomienie standalone.
5. SW zarejestrowany (DevTools → Application → Service Workers: activated, scope /m/). Brak błędów konsoli.
6. SW NIE cache’uje /m/api/* — po zmianie statusu rozmowy i odświeżeniu dane są aktualne (nie ze stale cache). Sprawdzić: zmiana statusu → refresh → nowy status widoczny.
7. Offline (tryb samolotowy) po wcześniejszym wejściu: powłoka /m/ ładuje się (HTML/CSS/JS z cache), API pokazuje błąd połączenia gracefully (nie biały ekran).
8. Cały user-flow z T-072 nadal działa w trybie standalone (login → lista → detail → status → logout). Cookie dz_madmin działa w standalone (scope /m/ zgodny).
9. Markdown (T-075) renderuje się w standalone tak samo.
10. Brak regresji: GET /m/ w zwykłej przeglądarce dalej działa.

## KROK FINALNY — deploy + raport + status + git
- Deploy: public/m/{manifest.webmanifest, sw.js, icons/*, index.html zmieniony, app.js jeśli dotknięty} na chat.divezone.pl (SCP). STOP przed: raport (lista plików, rozmiary ikon, źródło logo), deploy, smoke 1-10 (KRYTYCZNE: realna instalacja na Android ORAZ iPhone — Karol + wspólniczka; jeśli iPhone niedostępny od razu, odnotować kryt. 4 jako do potwierdzenia).
- UWAGA SW: service worker bywa "lepki" (cache). W raporcie podać jak wymusić odświeżenie SW (DevTools → Unregister, lub wersjonowanie cache w sw.js — zalecane: CACHE_NAME z wersją, by przyszłe deploye czyściły stary shell).
- Raport: _instances/frontend/handoff/CHAT-T-073_done.md.
- Status: dopisać CHAT-T-073 do _docs/21_STATUS_PROJEKTU.md (+ odnotować: mobile admin MVP KOMPLETNY T-071/072/073/074/075; push = faza 2 osobno).
- Git: git status; git add per ścieżka (public/m/manifest.webmanifest, public/m/sw.js, public/m/icons/*, public/m/index.html, public/m/app.js jeśli zmieniony, task, handoff); commit wg konwencji; push origin main. Osobny "docs:" dla statusu.

---

## ANEKS A (Q223a) — poprawka Content-Type manifestu

**Problem (zweryfikowany z zewnątrz):** GET /m/manifest.webmanifest → 200, ale Content-Type = `application/octet-stream` (nie `application/manifest+json`). Chrome/Android to toleruje (instalacja działa), ale Safari/iOS potrafi ZIGNOROWAĆ manifest z błędnym MIME → ryzyko dla iPhone (wspólniczka, połowa odbiorców). Usuwamy ryzyko PRZED smoke iPhone (K4), nie po.

**Zakres:** dodać `public/m/.htaccess` z poprawnym MIME dla .webmanifest. TYLKO to. ZERO innych zmian.

Zawartość public/m/.htaccess:
```
AddType application/manifest+json .webmanifest
```
(opcjonalnie też: `AddType image/png .png` — zwykle zbędne, serwer już zwraca image/png; nie dodawać jeśli niepotrzebne.)

**KRYTYCZNE — nie zepsuć routingu:**
- Główny webroot .htaccess ma DirectoryIndex + rewrite (nie-plik/nie-katalog → index.php API). `.htaccess` w public/m/ z samym AddType jest NIEKONFLIKTOWY (nie dotyka RewriteEngine), ale POTWIERDZIĆ po wdrożeniu, że /m/ nadal serwuje statykę i NIE przechwytuje go router PHP.
- Jeśli AllowOverride wyłączone dla public/m/ (AddType nie zadziała) → fallback: rename manifestu na manifest.json + aktualizacja <link rel="manifest" href="manifest.json"> (manifest.json częściej ma poprawny MIME serwerowy). Wybrać .htaccess jako pierwsze podejście (główny .htaccess działa w tym webroot, więc override włączony).

**Kryteria akceptacji aneksu:**
A1. GET /m/manifest.webmanifest → Content-Type `application/manifest+json` (nie octet-stream).
A2. GET /m/ → 200, statyka (HTML), router NIE przechwycił (brak regresji T-072).
A3. GET /m/api/whoami → 401 (API nietknięte).
A4. (iPhone, gdy wspólniczka dostępna) K4: "Dodaj do ekranu głównego" łapie ikonę DiveZone + nazwę, uruchamia standalone.

**Deploy + git:** scp public/m/.htaccess (+ ew. rename manifestu) na PROD. Smoke A1-A3 (A4 ad hoc iPhone). git add public/m/.htaccess (+ ew. manifest.json, index.html); commit wg konwencji; push. Osobny "docs:" jeśli status aktualizowany.
