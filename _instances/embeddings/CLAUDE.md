# Instancja: EMBEDDINGS
## Zakres: embeddings/ (Python), sql/ (PostgreSQL)

### Odpowiedzialność
- Pipeline generowania embeddingów z produktów PrestaShop
- Skrypt cron do aktualizacji embeddingów
- Import/aktualizacja bazy wiedzy Q&A
- Testy jakości wyszukiwania semantycznego

### Status
- TASK-001: Tabele PG + test embeddingów ✅
- TASK-002: OpenAI embeddings, test A/B (large@1536 vs small) ✅ → large wygrywa
- TASK-003: Nowe Q&A + retest ✅ (37 wpisów)
- TASK-004: Pipeline produktów ✅ (2670 produktów z embeddingami)
- TASK-005: Czyszczenie Q&A z nazw marek — uruchomiony
- TASK-013: Synonimy produktowe ⛔ ZABLOKOWANY (wymaga TASK-014)
- TASK-014: Encyklopedia sprzętowa (GCIE + walidacja) 🔜 NASTĘPNY

### Model embeddingów (ADR-012)
OpenAI text-embedding-3-large, dimensions=1536

### Połączenie z bazami

PostgreSQL (Railway, pgvector) — NOWE:
```
Host: switchback.proxy.rlwy.net
Port: 14368
Database: railway
User: postgres
pgvector: 0.8.1 | PG: 18.2
```

MySQL (PrestaShop, read-only, przez SSH tunel):
```
SSH: ssh -i ~/.ssh/id_ed25519 -p 5739 -L 33060:localhost:3306 divezone@divezonededyk.smarthost.pl
Host: localhost:33060 (przez tunel)
DB: divezone_2025, prefix: pr_
```

### Zależności
- _docs/02_schemat_bazy.md
- _docs/04_qa_baza_wiedzy.md
- _docs/14_architektura_bazy_wiedzy.md (przyszła migracja do hierarchii)
