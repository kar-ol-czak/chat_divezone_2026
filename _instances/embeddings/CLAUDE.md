# Instancja: EMBEDDINGS
## Zakres: embeddings/ (Python), sql/ (PostgreSQL)

### Odpowiedzialność
- Pipeline generowania embeddingów z produktów PrestaShop
- Skrypt cron do aktualizacji embeddingów
- Migracje SQL (tabele PostgreSQL + pgvector)
- Import bazy Q&A do tabeli divechat_knowledge
- Testy jakości wyszukiwania semantycznego

### Zależności
- Czytaj: _docs/02_schemat_bazy.md (schemat tabel, gotowy)
- Czytaj: _docs/06_pipeline_embeddingow.md (specyfikacja pipeline)
- Czytaj: _docs/04_qa_baza_wiedzy.md (dane Q&A do zaimportowania)

### Połączenie z bazami

PostgreSQL (Aiven, pgvector):
```
Host: <AIVEN_HOST_REDACTED>
Port: 22367
Database: defaultdb
User: avnadmin
SSL: require
```

MySQL (PrestaShop, read-only):
```
Host: localhost (na VPS divezone.pl)
Prefix tabel: pr_
Tabele: pr_product, pr_product_lang, pr_feature_value_lang, pr_category_lang, pr_manufacturer
```

### Wymagania techniczne
- Python 3.x, biblioteki: openai, psycopg2-binary, pymysql
- Model embeddingów: OpenAI text-embedding-3-large (3072 dim) lub small (1536 dim)
  Decyzja po teście, patrz _docs/10_decyzje_projektowe.md ADR-007

### Po zakończeniu pracy
Zapisz handoff w _instances/embeddings/handoff/ z informacją o gotowych tabelach i wynikach testu embeddingów dla instancji BACKEND.
