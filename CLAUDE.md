# Czat AI divezone.pl

## Opis projektu
Czat AI ze wyszukiwaniem semantycznym dla sklepu nurkowego divezone.pl (PrestaShop 1.7.6, prefix tabel: pr_). Wykorzystuje pgvector, function calling (Claude/OpenAI API), bazę wiedzy ekspercką. Architektura hybrydowa: cienki moduł PS (PHP 7.2) + standalone API na chat.divezone.pl (PHP 8.4).

## Status projektu (2026-02-20)

### Ukończone
- [x] Architektura: ADR-001 do ADR-018 w _docs/10_decyzje_projektowe.md
- [x] Baza Q&A: 37 wpisów z embeddingami (divechat_knowledge)
- [x] Embeddingi produktów: 2670 aktywnych produktów (divechat_product_embeddings)
- [x] Model embeddingów: text-embedding-3-large, dimensions=1536 (ADR-012)
- [x] Mapa 79 marek aktywnych w sklepie (_docs/11_mapa_marek.md)
- [x] TASK-006a: Standalone skeleton (routing, auth, DB connections) ✅
- [x] TASK-006b: AI Providers, Tools, ChatService (20 plików) ✅
- [x] Testy: Claude Sonnet 4 + GPT-5.2, tool loop z pgvector działa

### W toku
- [ ] **Migracja bazy z Aiven → Railway** (ADR-019, port 14368 czeka na odblokowanie)
- [ ] **Review kodu TASK-006b** (ChatService, Providers, Tools, SystemPrompt)
- [ ] TASK-006c: Cienki moduł PrestaShop (~100 linii PHP 7.2)

### Następne
- [ ] Widget JS (frontend instance)
- [ ] Panel admina (chat.divezone.pl/admin)
- [ ] Migracja bazy wiedzy do hierarchii 4-poziomowej (ADR-018)
- [ ] Testy modeli AI (_docs/12_plan_testow_modeli.md)

## Infrastruktura

### PostgreSQL (Railway, pgvector) — NOWE od 2026-02-20
```
Host: switchback.proxy.rlwy.net
Port: 14368
Database: railway
User: postgres
SSL: nie wymagane (TCP proxy)
pgvector: 0.8.1 | PG: 18.2
Connection string: postgresql://postgres:<RAILWAY_PASSWORD_REDACTED>@switchback.proxy.rlwy.net:14368/railway
```
**Status:** Baza aktywna, pgvector zainstalowany. Port 14368 CZEKA na odblokowanie na VPS (firewall wychodzący).
**Poprzednio:** Aiven (IP 159.223.235.232 zablokowane przez hosting z powodu blacklisty AbuseIPDB).

### Standalone API (chat.divezone.pl, PHP 8.4)
```
Docroot: /home/divezone/public_html/chat.divezone.pl/public/
PHP: ea-php84
Composer: 2.8.12
```

### PrestaShop (VPS divezone.pl)
```
PHP: 7.2 (domyślne CLI), ea-php84 dla subdomeny chat
MySQL prefix: pr_
DB: divezone_2025
```

## Struktura projektu
```
Chat_dla_klientow_2026/
├── _docs/                    # Dokumentacja (czytaj PRZED pracą)
├── _instances/               # Instancje Claude Code (taski, handoff)
│   ├── backend/              # PHP standalone
│   ├── embeddings/           # Python pipeline
│   ├── frontend/             # JS widget
│   └── integration/          # Testy
├── standalone/               # ← GŁÓWNY KOD BACKEND (PHP 8.4)
│   ├── public/index.php      # Front controller
│   ├── src/                  # PSR-4: DiveChat\
│   │   ├── AI/               # Providers (Claude, OpenAI)
│   │   ├── Chat/             # ChatService, SystemPrompt, ConversationStore
│   │   ├── Tools/            # 5 narzędzi (ProductSearch, ExpertKnowledge, ...)
│   │   ├── Enum/             # AIModel, SearchStrategy
│   │   ├── Auth/             # HmacVerifier
│   │   ├── Controller/       # Health, Chat
│   │   ├── Database/         # PostgresConnection, MysqlConnection
│   │   └── Http/             # Request, Response
│   ├── config/               # routes.php, tools.php
│   └── composer.json
├── modules/divezone_chat/    # Cienki moduł PS (TASK-006c, do zrobienia)
├── embeddings/               # Pipeline Python
└── sql/                      # Migracje PostgreSQL
```

## Dokumentacja (_docs/)
- `00_architektura_projektu.md` — architektura systemu
- `02_schemat_bazy.md` — tabele PostgreSQL (vector 1536 dim)
- `04_qa_baza_wiedzy.md` — baza Q&A (37 wpisów)
- `08_testy_i_ewaluacja.md` — pytania testowe, metryki
- `10_decyzje_projektowe.md` — ADR-001 do ADR-019
- `11_mapa_marek.md` — 79 marek aktywnych
- `11_workflow_i_organizacja.md` — workflow, instancje
- `12_plan_testow_modeli.md` — plan testów Claude/OpenAI
- `13_wymagania_panel_admina.md` — wymagania panelu admina
- `14_architektura_bazy_wiedzy.md` — hierarchia 4-poziomowa
- `CONVENTIONS.md` — konwencje kodu

## Konwencje
- Standalone PHP 8.4: typed properties, enums, match, readonly, PSR-4, namespace DiveChat\
- Moduł PS PHP 7.2: BRAK typed properties/enums/match, PSR-12, prefix DiveChat
- Python: PEP 8, type hints
- SQL: PostgreSQL prefix divechat_, MySQL prefix pr_
- Komentarze: po polsku. Zmienne/funkcje: po angielsku
