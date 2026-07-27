# TASK-001: Tworzenie tabel PostgreSQL + pierwszy test embeddingów
# Data: 2026-02-19
# Instancja: embeddings
# Priorytet: WYSOKI
# Zależności: brak (pierwszy task)

## Kontekst
Root projektu: ../../ (względem tego pliku)
Przeczytaj przed rozpoczęciem:
- ../../_docs/02_schemat_bazy.md (definicje tabel, indeksy)
- ../../_docs/04_qa_baza_wiedzy.md (30 wpisów Q&A do wgrania)
- ../../.env (DATABASE_URL, OPENAI_API_KEY)

## Zadania

### Krok 1: Stworzenie tabel w PostgreSQL
Użyj MCP postgresql do wykonania SQL z ../../_docs/02_schemat_bazy.md:
- divechat_product_embeddings
- divechat_knowledge
- divechat_conversations
- Wszystkie indeksy (HNSW, btree)
Po stworzeniu zweryfikuj: SELECT tablename FROM pg_tables WHERE schemaname = 'public';

### Krok 2: Skrypt Python do generowania embeddingów
Stwórz plik: ../../embeddings/generate_embeddings.py
Funkcjonalność:
- Wczytaj ../../.env (python-dotenv)
- Połącz się z OpenAI API (openai library)
- Funkcja get_embedding(text: str, model: str) -> list[float]
- Na start obsłuż oba modele: text-embedding-3-small (1536 dim) i text-embedding-3-large (3072 dim)
- Dodaj retry logic (tenacity lub ręczny) na rate limit

### Krok 3: Wgranie 5 wpisów Q&A jako test
Z ../../_docs/04_qa_baza_wiedzy.md weź pierwsze 5 wpisów.
Dla każdego:
- Przygotuj tekst do embeddingu: "Pytanie: {question}\nOdpowiedź: {content}"
- Wygeneruj embedding modelem text-embedding-3-small
- INSERT do divechat_knowledge

### Krok 4: Test wyszukiwania semantycznego
Stwórz plik: ../../embeddings/test_search.py
- Weź 3 pytania testowe z ../../_docs/08_testy_i_ewaluacja.md (sekcja B: nr 16, 17, 20)
- Dla każdego wygeneruj embedding zapytania
- Wykonaj zapytanie wektorowe (cosine similarity) na divechat_knowledge
- Wyświetl top 3 wyniki z similarity score

### Krok 5: Raport
Wyświetl wyniki testu w konsoli:
- Pytanie -> Top 3 wyniki (question, similarity)
- Czy wyniki mają sens? (subiektywna ocena)

## Pliki do stworzenia
- ../../embeddings/generate_embeddings.py
- ../../embeddings/test_search.py
- ../../embeddings/requirements.txt (openai, psycopg2-binary, python-dotenv, numpy)
- ../../sql/001_create_tables.sql (kopia SQL z _docs/02_schemat_bazy.md jako migracja)

## Definition of Done
- [ ] 3 tabele istnieją w PostgreSQL
- [ ] 5 wpisów Q&A ma embeddingi w divechat_knowledge
- [ ] test_search.py zwraca sensowne wyniki dla 3 pytań testowych
- [ ] requirements.txt kompletny i przetestowany (pip install)

## Uwagi
- Wymiar wektora: na razie 3072 (large) jak w schemacie. Jeśli test wykaże że small wystarczy, zmienimy później.
- WAŻNE: nie commituj .env. Sprawdź czy jest w .gitignore (../../.gitignore).
- Jeśli MCP postgresql nie działa, użyj psycopg2 bezpośrednio w Pythonie.
