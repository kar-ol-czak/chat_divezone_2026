# CHAT-T-074 — BACKEND/OPS: zamknięcie publicznej konsoli testowej (PILNE)

**Status:** DONE (2026-06-05). Pełny raport: `_instances/backend/handoff/CHAT-T-074_done.md`. 10/10 kryteriów PASS. Lokalnie `git rm` 6 plików konsoli + nowy `index.html` (zaślepka 936 B). Na PROD `mv` do `_console_backup_2026-06-05/` (POZA webroot, backup) + `rm error_log`. Smoke: `/` → zaślepka bez ujawnień, `/js/*` + `/css/chat-test.css` → 404, `/api/*` 401, `/m/` + `/admin/` żywe. Karol potwierdził widget sklepu + mobilny user-flow.

**Instancja:** backend (standalone chat.divezone.pl, webroot public/). CC deployuje SAM. ZERO modułu PS.
**Priorytet:** P0 — ekspozycja na produkcji. Deweloperska konsola testowa serwowana publicznie pod chat.divezone.pl/.
**Powiązany ADR:** ADR-086 (kontekst mobile), ADR-064/082 (ochrona publiczna).

## Problem (zweryfikowany na PROD)
GET https://chat.divezone.pl/ → 200, serwuje "DiveChat Test Console" (public/index.html) — deweloperski panel z wyborem providera/modeli, cennikiem, debugiem, historią. To NIE produkt kliencki, to narzędzie testowe wystawione publicznie. API JEST chronione (POST /api/chat → 401, /api/conversations → 401, /m/api/* → 401, /admin/ → 401) — czyli ZERO wycieku danych/LLM. Problem = rekonesans (mapa systemu: providerzy, modele, endpointy, format JSON) + wizerunek.

## Mechanika (kluczowe — NIE zepsuć API)
public/.htaccess: `DirectoryIndex index.html index.php` + rewrite (nie-plik/nie-katalog → index.php). Stąd `/` serwuje index.html (konsola) PRZED index.php.
- index.php = FRONT CONTROLLER API (router, /api/*, /m/api/*). NIE RUSZAĆ.
- index.html + js/*.js + css/chat-test.css + root chat.js, chat-test.css = KONSOLA. Do zamknięcia.
- m/, admin/, .well-known/ = ZOSTAJĄ nietknięte.

## Pliki konsoli w public/ (zweryfikowane ls)
- index.html (konsola)
- js/chat.js, js/console.js, js/history.js, js/pricing.js, js/settings.js
- css/chat-test.css
- chat.js (root, duplikat), chat-test.css (root, duplikat)
- error_log (PUBLICZNY plik logu — usunąć/zabezpieczyć przy okazji, może zdradzać ślady)

## KROK 1 — rename/usunięcie plików konsoli (decyzja 220a)
- Przenieść pliki konsoli POZA webroot (do katalogu rodzica, np. ~/public_html/chat.divezone.pl/_console_backup/ — NIE w public/, NIE w gicie produkcyjnym webroot) ALBO rename na nazwy nieserwowane. REKOMENDACJA: przenieść poza public/ (czysto — znikają z webroot, zachowane jako backup gdyby konsola była potrzebna lokalnie).
  - index.html, js/ (cały katalog konsoli — UWAGA: js/ zawiera TYLKO pliki konsoli wg ls, więc cały katalog można przenieść), css/chat-test.css, root chat.js, root chat-test.css.
  - UWAGA: sprawdzić, czy m/ lub admin/ nie linkują do js/* lub css/chat-test.css z webroot (grep w public/m/ i public/admin/). Jeśli coś współdzielone — NIE przenosić tego pliku, tylko index.html. (Z architektury: m/ ma własne styles.css/app.js, admin/ własne — raczej niezależne, ale POTWIERDZIĆ przed przeniesieniem.)
- error_log: usunąć z webroot (lub przenieść poza public/). Dopisać do .gitignore jeśli śledzony.

## KROK 2 — nowy minimalny index.html (info o braku dostępu)
- Utworzyć public/index.html: prosty, statyczny, BEZ żadnych odwołań do API/modeli/wnętrza systemu. Zawartość: krótki neutralny komunikat, np. tytuł "DiveZone" + zdanie "Brak dostępu." lub "Ta strona nie jest publicznie dostępna." PL. Bez JS, bez linków do endpointów, bez nazw modeli/providerów. Minimalny HTML.
- Dzięki DirectoryIndex (index.html pierwszy) `/` zwróci tę zaślepkę, NIE API i NIE konsolę.
- Opcjonalnie: zwracać 403 zamiast 200 dla `/` (czystsze semantycznie). Jeśli prosto przez .htaccess/meta — OK, ale NIE kosztem ryzyka dla index.php/API. Jeśli ryzykowne — wystarczy statyczny info-HTML 200.

## Granice
- index.php (API front controller) NIETKNIĘTY.
- /m/ (mobilny panel), /api/, /m/api/, /admin/, /.well-known/ — DZIAŁAJĄ bez zmian.
- Nie ruszać .htaccess rewrite do API (tylko ewentualnie dodać, nie zmieniać istniejącego).
- Pliki konsoli przeniesione POZA webroot (backup), nie skasowane na ślepo.

## Kryteria akceptacji
1. GET https://chat.divezone.pl/ → zwraca minimalną zaślepkę (info o braku dostępu), NIE konsolę testową. Brak nazw modeli/providerów/endpointów w źródle.
2. GET /js/console.js, /js/settings.js, /js/pricing.js, /js/history.js, /css/chat-test.css → 404 (nie 200).
3. GET /index.html → zaślepka (nie konsola).
4. POST /api/chat (bez auth) → nadal 401 (API żyje).
5. GET /m/ → nadal 200 (mobilny front działa — kryteria T-072 niezłamane).
6. GET /m/api/whoami → nadal 401 (nie 404 — routing API nietknięty).
7. GET /admin/ → nadal 401.
8. GET /error_log → 404 (usunięty z webroot).
9. Widget kliencki na sklepie divezone.pl działa (nie korzysta z public/ konsoli — potwierdzić, że nic z przeniesionego nie jest ładowane przez moduł PS).
10. Mobilny panel /m/ pełny user-flow (login→lista→detail→status→logout) działa po zmianach.

## KROK FINALNY — deploy + raport + status + git
- Wykonać na PROD (przeniesienie plików + nowy index.html + usunięcie error_log). STOP przed: krótki raport co przenoszone, potem wykonać, potem smoke 1-10.
- Raport: _instances/backend/handoff/CHAT-T-074_done.md (lista przeniesionych plików + ich nowa lokalizacja backup + wyniki 1-10).
- Status: dopisać CHAT-T-074 do _docs/21_STATUS_PROJEKTU.md.
- Git: git status; git add per ścieżka (public/index.html nowy; usunięcia plików konsoli z webroot jeśli śledzone w gicie — git rm; .gitignore dla error_log; task; handoff). Commit wg konwencji. Push origin main. Osobny "docs:" dla statusu.
- UWAGA: jeśli pliki konsoli były w gicie (śledzone), git rm je usunie z repo — to OK (przenosimy poza webroot, backup lokalny na serwerze). Potwierdzić w raporcie co git śledził.
