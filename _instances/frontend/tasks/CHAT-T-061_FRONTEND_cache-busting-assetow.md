# CHAT-T-061 — FRONTEND/PS: cache-busting assetów widgetu (?v=md5)

**Instancja:** frontend (moduł PS). Plik: modules/divezone_chat/divezone_chat.php (hookDisplayFooter).
**Powiązane:** CHAT-T-037 (widget assets), powracający problem cache w T-053/056/058/060.
**Decyzja:** 148a (md5_file per asset, hash liczony tylko przy renderze widgetu). Karol wgrywa ręcznie (116b).

## Problem
Każda zmiana w assetach widgetu (widget-loader.js, widget-bundle.js, transport.js, widget.css) wymaga ręcznego czyszczenia cache przeglądarki — bo URL-e są stałe, przeglądarka serwuje starą wersję z cache. Powtarzało się w 4 kolejnych taskach. Rozwiązanie: dopisać `?v={hash}` do każdego URL assetu; hash = md5 treści pliku → zmiana pliku = nowy hash = przeglądarka pobiera świeżo. Bezobsługowo, na zawsze.

## Zakres (hookDisplayFooter, modules/divezone_chat/divezone_chat.php ~linie 521-528)
Obecnie 4 URL-e budowane jako stałe ścieżki:
- $loaderUrl   → views/js/widget-loader.js  (ładowany inline <script>)
- $bundleUrl   → views/js/widget-bundle.js  (w BOOT.assets)
- $cssUrl      → views/css/widget.css       (w BOOT.assets)
- $transportUrl→ views/js/transport.js      (w BOOT.assets)

WSZYSTKIE 4 ostemplować `?v={md5_8}` (NIE tylko loader — bundle/transport/css też się zmieniają, np. CHAT-T-059 dotyka bundle+transport; wersjonowanie tylko loadera zostawiłoby problem dla reszty).

### Implementacja
- Helper w module, np. private function assetUrl($relativePath): string
  - Ścieżka URL: rtrim(__PS_BASE_URI__,'/') . '/modules/' . $this->name . '/' . $relativePath
  - Ścieżka pliku do md5: _PS_MODULE_DIR_ . $this->name . '/' . $relativePath (POTWIERDZIĆ poprawną stałą — _PS_MODULE_DIR_ zwykle kończy się slashem; w razie potrzeby dirname(__FILE__) . '/' . $relativePath jako pewniejsze, bo __FILE__ jest w katalogu modułu).
  - Hash: $h = @md5_file($filePath); jeśli false (plik nieczytelny) → fallback bez ?v (NIE wywalać widgetu). $h8 = substr($h,0,8).
  - Zwróć: $url . '?v=' . $h8 (lub $url bez ?v gdy md5_file zawiódł).
- Zastąpić 4 budowania URL wywołaniami assetUrl('views/js/widget-loader.js') itd.
- WYDAJNOŚĆ (148a): md5_file liczony JEST już w sekcji po shouldShowWidget() (hookDisplayFooter zwraca '' wcześniej gdy widget się nie pokazuje — sprawdzić, że budowanie URL-i jest PO tym wczesnym returnie; jeśli tak, hash liczy się tylko dla wizyt z widgetem — zero marnowania I/O dla reszty). Potwierdzić w kodzie i NIE przesuwać tych obliczeń przed shouldShowWidget guard.

## Granice
- Tylko hookDisplayFooter (budowanie 4 URL). Bez zmian w loaderze/bundle/transport/css. Bez backendu.
- md5_file zawodzi → graceful (URL bez ?v), nie błąd.
- Hash liczony tylko przy faktycznym renderze widgetu (po shouldShowWidget).
- PHP 7.2 / PS 1.7.6.

## Kryteria akceptacji
1. 4 URL-e (loader, bundle, transport, css) mają ?v={8-znakowy md5 treści pliku}.
2. Zmiana treści dowolnego z 4 plików → inny hash w URL (test: zmodyfikować bajt, hash się zmienia).
3. md5_file nieczytelny → URL bez ?v, widget nadal działa (brak fatala).
4. Hash NIE liczony dla wizyt bez widgetu (obliczenia po shouldShowWidget guard).
5. php -l clean; PHP 7.2/PS 1.7.6.
6. Po wdrożeniu: zmiana assetu + wgranie = przeglądarka pobiera świeżo BEZ ręcznego czyszczenia cache.
