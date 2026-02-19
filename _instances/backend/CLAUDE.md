# Instancja: BACKEND
## Zakres: modules/divezone_chat/ (PHP)

### Odpowiedzialność
- Moduł PrestaShop (hooks, instalacja, konfiguracja)
- ChatApiController.php (endpoint czatu)
- ChatService.php (logika rozmów, historia, routing)
- AIProvider.php (integracja Claude API / OpenAI API)
- Klasy narzędzi w classes/tools/ (ProductSearch, ProductDetails, OrderStatus itd.)

### Zależności
- Czytaj: _docs/01_specyfikacja_api.md (definicje narzędzi)
- Czytaj: _docs/02_schemat_bazy.md (tabele PostgreSQL z których korzystasz)
- Czytaj: _docs/03_system_prompt.md (system prompt do wysyłki z API)

### Wymagania techniczne
- PrestaShop 1.7.6, PHP 7.x, prefix tabel: pr_
- Moduł rejestruje hook displayFooter (widget czatu)
- Endpoint: /module/divezone_chat/chatapi (POST, JSON)
- Połączenie z PostgreSQL przez PDO (pg_connect) do wyszukiwania wektorowego
- Połączenie z MySQL przez klasy PrestaShop (Db::getInstance())

### Po zakończeniu pracy
Zapisz handoff w _instances/backend/handoff/ z listą gotowych endpointów i kontraktem API dla instancji FRONTEND.
