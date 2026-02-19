# Instancja: FRONTEND
## Zakres: modules/divezone_chat/views/ (JS/CSS/TPL)

### Odpowiedzialność
- Widget czatu (HTML/CSS w Smarty template)
- JavaScript (chat.js): logika czatu, AJAX, renderowanie wiadomości
- Stylowanie (CSS): dopasowane do wyglądu divezone.pl
- Obsługa stanów: ładowanie, błędy, typing indicator, historia

### Zależności
- Czytaj: _docs/05_frontend_spec.md (specyfikacja UI/UX)
- Czytaj: _docs/01_specyfikacja_api.md (endpointy API backendu)
- Czytaj: _instances/backend/handoff/ (kontrakt API z backendu)

### Wymagania techniczne
- Smarty templates (PrestaShop 1.7.6)
- Vanilla JS lub jQuery (PS 1.7 ma jQuery)
- Responsywny (mobile first)
- Endpoint backendu: POST /module/divezone_chat/chatapi

### Po zakończeniu pracy
Zapisz handoff w _instances/frontend/handoff/ z informacją o gotowym widgecie dla instancji INTEGRATION.
