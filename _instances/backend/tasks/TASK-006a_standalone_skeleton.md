# TASK-006a: Standalone API - szkielet i infrastruktura
# Data: 2026-02-20
# Instancja: backend
# Priorytet: KRYTYCZNY
# Zależności: ADR-016

## Kontekst
Przeczytaj OBOWIĄZKOWO:
- ../../_docs/10_decyzje_projektowe.md (ADR-016: architektura hybrydowa)
- ../../_docs/CONVENTIONS.md
- ../../.env (klucze API, connection strings)

Standalone API na chat.divezone.pl, PHP 8.4, Composer + PSR-4.
To jest NOWY komponent, nie moduł PrestaShop.
Kod w: ../../standalone/

## WAŻNE: PHP 8.4
Serwer ma PHP 8.4. Używaj WSZYSTKICH nowoczesnych ficzerów:
- Typed properties, constructor promotion, readonly
- Enums, match, named arguments
- Union types, intersection types, null coalescing
- Return types, nullable types
- Arrow functions

## Deploy
Serwer: divezonededyk.smarthost.pl:5739 (SSH, user divezone, key ~/.ssh/id_ed25519)
Docroot Apache: ~/public_html/chat.divezone.pl/public/ (ustawiony w cPanel)
Projekt: ~/public_html/chat.divezone.pl/

```bash
# Backup starego
ssh -i /Users/karol/.ssh/id_ed25519 -p 5739 divezone@divezonededyk.smarthost.pl \
  "cd ~/public_html && zip -r chat_backup_$(date +%Y%m%d).zip chat.divezone.pl/"

# Deploy nowego
rsync -avz --delete --exclude='.env' --exclude='vendor/' \
  -e "ssh -i /Users/karol/.ssh/id_ed25519 -p 5739" \
  ../../standalone/ divezone@divezonededyk.smarthost.pl:~/public_html/chat.divezone.pl/

# Composer install na serwerze
ssh -i /Users/karol/.ssh/id_ed25519 -p 5739 divezone@divezonededyk.smarthost.pl \
  "cd ~/public_html/chat.divezone.pl && composer install --no-dev --optimize-autoloader"
```

Skopiuj .env na serwer (jednorazowo):
```bash
scp -i /Users/karol/.ssh/id_ed25519 -P 5739 \
  ../../.env divezone@divezonededyk.smarthost.pl:~/public_html/chat.divezone.pl/.env
```

## Struktura plików

```
standalone/
├── composer.json
├── .htaccess                    # Rewrite all to public/index.php
├── public/
│   └── index.php                # Entry point (front controller)
├── config/
│   ├── routes.php               # Definicje endpointów
│   └── tools.php                # Rejestracja narzędzi
├── src/
│   ├── Config.php               # Konfiguracja z .env + overrides z DB
│   ├── Router.php               # Prosty router (bez frameworka)
│   ├── Auth/
│   │   └── HmacVerifier.php     # Weryfikacja tokenów HMAC
│   ├── Database/
│   │   ├── PostgresConnection.php  # PDO pgvector (Aiven, SSL)
│   │   └── MysqlConnection.php     # PDO MySQL (PrestaShop, read-only)
│   ├── Http/
│   │   ├── Request.php          # Wrapper na $_SERVER, php://input
│   │   └── Response.php         # JSON response helper
│   ├── AI/                      # (TASK-006b)
│   ├── Tools/                   # (TASK-006b)
│   └── Chat/                    # (TASK-006b)
├── admin/
│   └── index.php                # Panel admina (LATER, nie w tym tasku)
└── tests/
    └── bootstrap.php
```

## Krok 1: composer.json + autoload

```json
{
    "name": "divezone/chat-api",
    "description": "AI Chat API for divezone.pl",
    "type": "project",
    "require": {
        "php": ">=8.2",
        "guzzlehttp/guzzle": "^7.0",
        "vlucas/phpdotenv": "^5.0",
        "monolog/monolog": "^3.0"
    },
    "autoload": {
        "psr-4": {
            "DiveChat\\": "src/"
        }
    }
}
```

Uruchom: cd ../../standalone && composer install

## Krok 2: public/index.php (front controller)

- Ładuj autoload
- Ładuj .env (z ../../.env LUB standalone/.env, ten który istnieje)
- Inicjalizuj Router
- Dispatch request

## Krok 3: Router.php

Prosty router, bez frameworka. Mapuje METHOD + path na handler.
Routes:
- POST /api/chat -> ChatController::handle
- GET /api/health -> HealthController::handle (status check)
- GET /admin -> AdminController::handle (LATER)

## Krok 4: Auth/HmacVerifier.php

```php
class HmacVerifier
{
    public function __construct(
        private readonly string $secret,
        private readonly int $maxAgeSec = 300 // 5 min
    ) {}

    public function verify(string $token, int $customerId, int $timestamp): bool
    {
        if (abs(time() - $timestamp) > $this->maxAgeSec) return false;
        $expected = hash_hmac('sha256', $customerId . ':' . $timestamp, $this->secret);
        return hash_equals($expected, $token);
    }
}
```

## Krok 5: Database connections

### PostgresConnection.php
- DSN z env: DATABASE_URL (Aiven, wymaga sslmode=require)
- PDO z opcjami: ERRMODE_EXCEPTION, FETCH_ASSOC
- Singleton pattern (lub lazy init)
- Metoda: query(string $sql, array $params = []): PDOStatement
- Metoda: fetchAll(string $sql, array $params = []): array
- Metoda: fetchOne(string $sql, array $params = []): ?array

### MysqlConnection.php
- DSN z env: DB_HOST, DB_PORT, DB_NAME_PROD, DB_USER, DB_PASSWORD
- Host: 127.0.0.1 (localhost, ten sam VPS)
- Charset: utf8mb4
- READ-ONLY: tylko SELECT. Nigdy nie modyfikuj danych PS.
- Analogiczne metody: query, fetchAll, fetchOne

## Krok 6: Http helpers

### Request.php
- Parsuje: method, path, JSON body, headers
- getHeader('X-DiveChat-Token'), getHeader('X-DiveChat-Customer'), etc.
- getJsonBody(): array

### Response.php
- json(array $data, int $status = 200): void
- error(string $message, int $status): void
- Ustawia headers: Content-Type: application/json, CORS

## Krok 7: Config.php

Ładuje z .env, dostępne globalnie:
- AI: ANTHROPIC_API_KEY, OPENAI_API_KEY, AI_MODEL, AI_TEMPERATURE, AI_MAX_TOKENS
- DB: DATABASE_URL (PG), DB_HOST/USER/PASSWORD/NAME_PROD (MySQL)
- Auth: DIVECHAT_SECRET (shared z modułem PS)
- App: APP_ENV (dev/production), APP_DEBUG (true/false)

## Krok 8: .htaccess

Docroot w cPanel ustawiony na ~/public_html/chat.divezone.pl/public/
Dzięki temu vendor/, src/, .env, config/ są POZA docroot (bezpieczne).

.htaccess w public/:
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

Struktura na serwerze:
```
chat.divezone.pl/           # projekt
├── .env                    # poza docroot, bezpieczne
├── composer.json
├── vendor/                 # poza docroot
├── config/                 # poza docroot
├── src/                    # poza docroot
├── admin/                  # poza docroot
└── public/                 # <-- DOCROOT Apache
    ├── .htaccess
    └── index.php
```

## Krok 9: Health endpoint + test

GET /api/health zwraca:
```json
{
    "status": "ok",
    "php": "8.4.x",
    "postgres": true/false,
    "mysql": true/false,
    "timestamp": "2026-02-20T..."
}
```

Test (loklany, nie na serwerze):
```bash
cd ../../standalone
php -S localhost:8080 -t public/
curl http://localhost:8080/api/health
```

## Definition of Done
- [ ] composer install działa bez błędów
- [ ] php -S localhost:8080 startuje
- [ ] GET /api/health zwraca JSON z postgres: true, mysql: true
- [ ] POST /api/chat bez tokenu zwraca 401
- [ ] POST /api/chat z poprawnym HMAC zwraca 200 (na razie placeholder response)
- [ ] Kod używa PHP 8.4 features (typed properties, constructor promotion)
