# Instancja: BACKEND
## Zakres: standalone/ (PHP 8.4) + modules/divezone_chat/ (PHP 7.2)

### Architektura (ADR-016: hybrydowa)
Dwa komponenty:
1. **Standalone API** (standalone/, PHP 8.4, Composer PSR-4, namespace DiveChat\)
   - Cała logika: ChatService, AIProviders, Tools, pgvector, MySQL PS (read-only)
   - Deploy: chat.divezone.pl, docroot: public/
   - PHP binary na serwerze: /opt/cpanel/ea-php84/root/usr/bin/php
2. **Cienki moduł PS** (modules/divezone_chat/, PHP 7.2, ~100 linii)
   - Hook displayFooter: wstrzykuje widget JS + HMAC token
   - getContent(): iframe do chat.divezone.pl/admin

### Status tasków
- TASK-006a: Standalone skeleton ✅
- TASK-006b: AI Providers, Tools, ChatService ✅ (20 plików)
- TASK-006c: Cienki moduł PS — DO ZROBIENIA
- Review kodu TASK-006b — DO ZROBIENIA

### Połączenia z bazami

PostgreSQL (Railway, pgvector):
```
Host: switchback.proxy.rlwy.net
Port: 14368
Database: railway
User: postgres
pgvector: 0.8.1 | PG: 18.2
```

MySQL (PrestaShop, read-only):
```
Host: localhost (na VPS)
DB: divezone_2025
Prefix: pr_
```

### Kontrakt API
Pełny kontrakt: _instances/backend/handoff/2026-02-20_TASK-006b_api_contract.md

### Zależności
- _docs/02_schemat_bazy.md (tabele PostgreSQL)
- _docs/10_decyzje_projektowe.md (ADR-016, ADR-019)
- _docs/11_mapa_marek.md (79 marek w SystemPrompt)
- _docs/13_wymagania_panel_admina.md (panel admina)
