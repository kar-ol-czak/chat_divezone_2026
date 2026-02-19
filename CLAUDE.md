# Czat AI divezone.pl

## Opis projektu
Czat AI ze wyszukiwaniem semantycznym dla sklepu nurkowego divezone.pl (PrestaShop 1.7.6, prefix tabel: pr_). Wykorzystuje pgvector, function calling (Claude/OpenAI API), bazę wiedzy eksperckiej.

## Status projektu (2026-02-19)
- [x] Architektura zaprojektowana
- [x] Baza Q&A (30 wpisów, draft)
- [x] Baza PostgreSQL na Aiven (pgvector 0.8.1 zainstalowany)
- [x] Port 22367 otwarty na VPS divezone.pl
- [x] Dokumentacja i workflow gotowe
- [ ] Tabele PostgreSQL (do stworzenia)
- [ ] Pipeline embeddingów (Python)
- [ ] Test embeddingów (small vs large)
- [ ] Moduł PrestaShop
- [ ] Widget czatu

## Infrastruktura

### PostgreSQL (Aiven, pgvector)
```
Host: <AIVEN_HOST_REDACTED>
Port: 22367
Database: defaultdb
User: avnadmin
SSL: require
pgvector: 0.8.1 | PG: 17.8
```

### PrestaShop (VPS divezone.pl)
```
PHP: 7.2
MySQL prefix: pr_
OS: starszy system (PG max 10, brak upgrade bez migracji serwera)
```

## Struktura
- `_docs/` - dokumentacja projektowa (czytaj przed pracą)
- `_instances/` - instancje Claude Code (taski, handoff)
- `modules/divezone_chat/` - moduł PrestaShop (PHP)
- `embeddings/` - pipeline embeddingów (Python)
- `sql/` - migracje PostgreSQL
- `tests/` - testy

## Dokumentacja (_docs/)
- `00_architektura_projektu.md` - architektura systemu
- `02_schemat_bazy.md` - tabele PostgreSQL, indeksy, zapytania
- `04_qa_baza_wiedzy.md` - baza Q&A (30 wpisów)
- `10_decyzje_projektowe.md` - log decyzji (ADR)
- `11_workflow_i_organizacja.md` - workflow, instancje, narzędzia

Pliki zarezerwowane (do stworzenia):
- `01_specyfikacja_api.md` - definicje narzędzi i endpointów
- `03_system_prompt.md` - system prompt
- `05_frontend_spec.md` - widget czatu UI/UX
- `06_pipeline_embeddingow.md` - pipeline Python
- `07_bezpieczenstwo_rodo.md` - autoryzacja, RODO
- `08_testy_i_ewaluacja.md` - scenariusze testowe
- `09_deployment.md` - instrukcja wdrożenia
- `CONVENTIONS.md` - konwencje kodu

## Instancje Claude Code
4 instancje w `_instances/`: backend, embeddings, frontend, integration.
Każda ma CLAUDE.md, tasks/, handoff/.

## Konwencje
- PHP: PSR-12, klasy PrestaShop (Product, Category, Order, Customer)
- Python: PEP 8, type hints
- PostgreSQL: pgvector dla embeddingów, MySQL (pr_) dla PrestaShop
- Komentarze w kodzie: po polsku
- Nazwy zmiennych/funkcji: po angielsku
