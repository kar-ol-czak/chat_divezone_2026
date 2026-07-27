# Konwencje projektu
# Wersja: 1.0 | Data: 2026-02-19

## PHP (moduł PrestaShop)
- Standard: PSR-12
- Klasy PrestaShop: Product, Category, Order, Customer, Db
- Namespace: nie (PS 1.7.6 moduły bez namespace)
- Prefix klas modułu: DiveChat (np. DiveChatService, DiveChatAIProvider)
- Połączenie MySQL: Db::getInstance()
- Połączenie PostgreSQL: PDO (pdo_pgsql)
- Encoding: UTF-8

## Python (pipeline embeddingów)
- Standard: PEP 8
- Type hints: wymagane
- Biblioteki: openai, psycopg2-binary, pymysql
- Skrypty w embeddings/
- Config: plik .env (nie commitować!)

## SQL
- PostgreSQL (Aiven): tabele z prefixem divechat_
- MySQL (PrestaShop): tabele z prefixem pr_
- Nazwy tabel: snake_case
- Indeksy: idx_{tabela}_{kolumna}

## JavaScript
- jQuery (dostępny w PS 1.7)
- Vanilla JS gdzie możliwe
- Nazwy plików: kebab-case (chat-widget.js)
- CSS: BEM lub proste klasy z prefixem .divechat-

## Git
- Commit messages: po polsku, krótkie
- Branch naming: feature/{opis}, fix/{opis}
- .gitignore: .env, __pycache__/, node_modules/, *.pyc

## Pliki konfiguracyjne
- Hasła i klucze API: NIGDY w kodzie, zawsze w .env
- .env.example: wersja bez wartości do commitowania
