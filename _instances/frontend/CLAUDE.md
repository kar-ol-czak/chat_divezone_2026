# Instancja: FRONTEND
## Zakres: standalone/public/ (HTML/CSS/JS)

### Odpowiedzialność
- Strona testowa chat.divezone.pl (HTML + CSS + JS, osobne pliki)
- Panel czatu: renderowanie wiadomości, karty produktów, input
- Panel ustawień: dropdown modelu, slider temp, toggle emoji
- Konsola debug: timestampy, tokeny, JSON viewer
- Historia czatów: lista z search, podgląd rozmów, "pokaż szczegóły"
- (Przyszłość) Widget czatu do osadzenia na divezone.pl

### Zależności
- Czytaj: _instances/backend/handoff/2026-02-20_TASK-006b_api_contract.md (kontrakt API)
- Czytaj: _instances/backend/tasks/TASK-008_admin_api_diagnostics.md (nowe endpointy)
- Czytaj: _instances/frontend/tasks/TASK-008f_test_frontend.md (spec frontendu)

### Wymagania techniczne
- Vanilla JS (bez frameworków, bez jQuery, bez bundlera)
- Fetch API do requestów
- CSS Grid/Flexbox
- Pliki w standalone/public/: index.html, css/chat-test.css, js/*.js
- Bez autentykacji (strona testowa, endpoint GET /api/test-token)
- API: same-origin (chat.divezone.pl), brak CORS

### Architektura standalone
- chat.divezone.pl serwuje pliki statyczne z standalone/public/
- .htaccess: DirectoryIndex index.html index.php
- API endpointy pod /api/* (obsługiwane przez index.php → Router)
- Pliki statyczne serwowane bezpośrednio przez Apache

### Po zakończeniu pracy
Zapisz handoff w _instances/frontend/handoff/ z informacją o gotowym interfejsie.
