# CHAT-T-056 — FRONTEND/PS: proaktywne zaproszenie (nudge) przy launcherze

**Instancja:** frontend (widget + moduł PrestaShop)
**Powiązane:** CHAT-T-037 (widget/loader), hookDisplayFooter (boot payload), panel Konfiguracja modułu.
**Decyzje:** 133b (cały dymek klikalny + przycisk, X zamyka), 134a (sessionStorage, raz na sesję), 135a (3 pola w configu), 136a (prosty nudge; A/B/X + raport = osobny przyszły projekt, NIE teraz).
**Pliki:** modules/divezone_chat/divezone_chat.php (config + boot) ORAZ modules/divezone_chat/views/js/widget-loader.js (nudge). Karol wgrywa moduł ręcznie (116b).

## Cel
Mało widoczny launcher (bąbelek) → dodać proaktywny dymek („nudge"), który po ustawionym czasie (np. 15/30 s) wysuwa się przy launcherze z zachętą do rozmowy. Cel: zwiększyć otwieralność czatu.

## Zachowanie (decyzje 133b/134a)
- Po N sekundach od załadowania strony (N z configu) pokazuje się dymek nad/obok launchera: tekst zachęty + przycisk „Porozmawiajmy na czacie" + „×" (zamknij).
- Klik GDZIEKOLWIEK w dymek (tekst, tło, przycisk) — OPRÓCZ „×" — otwiera czat (dociąga bundle + otwiera panel, tak jak klik w launcher).
- Klik „×" — zamyka dymek, NIE otwiera czatu.
- Po zamknięciu (×) LUB po otwarciu czatu — nudge NIE pokazuje się ponownie w tej sesji (sessionStorage flag). Wraca przy następnej wizycie.
- Jeśli user już otworzył czat w tej sesji (z launchera albo nudge) — nudge się nie pokazuje (bez sensu zapraszać rozmawiającego).
- Nudge pojawia się TYLKO gdy launcher jest widoczny (czyli gdy shouldShowWidget przepuścił usera — nudge dziedziczy gating launchera, NIE dokłada własnego).

## Konfiguracja (135a — 3 pola w panelu Konfiguracja modułu)
Nowe stałe KEY_ + obsługa w getContent (render pól) i submit (updateValue), wzorzec 1:1 z istniejącymi (KEY_SHOW_*, KEY_FILTER_BOTS):
- KEY_NUDGE_ENABLED (DIVEZONE_CHAT_NUDGE_ENABLED) — checkbox on/off. Default OFF (bezpieczny, jak reszta).
- KEY_NUDGE_DELAY (DIVEZONE_CHAT_NUDGE_DELAY) — liczba sekund (int). Walidacja: zakres np. 3-300, default 20. Niepoprawne → default.
- KEY_NUDGE_TEXT (DIVEZONE_CHAT_NUDGE_TEXT) — treść zachęty (text/textarea). Default: "Hej! 🤿 Nie wiesz, jaki sprzęt wybrać? Zapytaj naszych specjalistów."
Pola w sekcji ustawień widgetu (obok grup wyświetlania). Krótkie opisy pod polami.

## Przekazanie configu do frontu (boot payload)
W hookDisplayFooter rozszerzyć tablicę $boot o gałąź nudge (czytane z Configuration::get):
```
'nudge' => array(
    'enabled' => (Configuration::get(KEY_NUDGE_ENABLED) === '1'),
    'delay'   => (int) Configuration::get(KEY_NUDGE_DELAY) ?: 20,
    'text'    => (string) Configuration::get(KEY_NUDGE_TEXT),
),
```
To samo miejsce, gdzie już lecą token/assets — żaden nowy endpoint. Jeśli enabled=false → gałąź może iść z enabled:false (loader sam zdecyduje, nie renderować nudge).

## Implementacja nudge (widget-loader.js — lekki stub, NIE bundle)
KLUCZOWE: nudge w loaderze, NIE w bundle. Bundle dociąga się dopiero po kliknięciu (w launcher LUB w nudge). Nudge ma się pokazać po N s BEZ pobierania bundla.
- Po utworzeniu launchera: odczytać BOOT.nudge. Jeśli !enabled → nic.
- Sprawdzić sessionStorage (np. klucz 'dz_nudge_dismissed' / 'dz_chat_opened'): jeśli ustawione → nie pokazuj.
- setTimeout(delay*1000): jeśli czat nie został w międzyczasie otwarty i nudge niezdismissowany → wyrenderuj dymek w root (obok launchera, w tym samym Shadow DOM/host co launcher).
- Dymek: kontener z tekstem (BOOT.nudge.text — ESCAPE jako textContent, NIE innerHTML, żeby uniknąć XSS z configu; emoji w treści jest OK jako tekst), przycisk „Porozmawiajmy na czacie", przycisk „×" (aria-label "Zamknij").
- Listenery: klik w kontener/przycisk-główny → openChat() (ta sama ścieżka co klik launcher: dociągnij bundle, ustaw flagę 'dz_chat_opened' w sessionStorage, otwórz panel). Klik „×" (stopPropagation) → ukryj dymek + ustaw 'dz_nudge_dismissed' w sessionStorage.
- a11y: dymek role="dialog" lub aria-live, przycisk × focusowalny. Respektować prefers-reduced-motion (bez animacji wjazdu).
- CSS dymka: dodać do baseStyle w loaderze (klasy dz-nudge*). Pozycja: nad launcherem (bottom-right stack), max-width ~300px, cień, zaokrąglenie, tło białe, tekst ciemny, przycisk w kolorze TEAL (jak launcher). Mobile: nie zasłaniać całego ekranu, mieścić się nad launcherem.

## Granice
- Bez nowego endpointu, bez tabeli, bez trackingu/A-B (136a — to osobny przyszły projekt).
- Nudge dziedziczy gating launchera (shouldShowWidget) — NIE dodaje własnej logiki geo/IP.
- Treść z configu renderowana jako textContent (escape), nie innerHTML.
- Nudge w loaderze, nie w bundle. Bez ładowania bundla przed kliknięciem.
- PHP 7.2 / PS 1.7.6 (moduł). Loader: czysty JS bez bibliotek (jak obecnie).

## Kryteria akceptacji
1. Konfiguracja: 3 pola (włącznik, opóźnienie sek, treść) zapisują się; default OFF.
2. Przy enabled=ON i widocznym launcherze: po N s pojawia się dymek z treścią z configu.
3. Klik w dymek (poza ×) otwiera czat (dociąga bundle, otwiera panel). Klik × zamyka dymek bez otwierania.
4. Po × lub otwarciu czatu: nudge nie wraca w tej sesji (sessionStorage). Po nowej wizycie/sesji wraca.
5. enabled=OFF → nudge nigdy się nie pokazuje.
6. Treść z configu jest escapowana (próba wstrzyknięcia HTML w treści nie wykonuje się).
7. Nudge nie pobiera bundla przed kliknięciem (sprawdzić w Network: bundle dociąga się dopiero po kliknięciu launcher/nudge).
8. Działa na mobile (dymek nad launcherem, nie zasłania całości); respektuje prefers-reduced-motion.
