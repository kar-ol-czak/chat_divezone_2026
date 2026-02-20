# TASK-002: Przejście na OpenAI embeddings + wgranie pełnej bazy Q&A + test A/B
# Data: 2026-02-19
# Instancja: embeddings
# Priorytet: WYSOKI
# Zależności: TASK-001 (tabele istnieją, skrypty bazowe gotowe)

## Kontekst
TASK-001 użył Gemini embedding-001 tymczasowo. Wracamy na OpenAI.
Nowy klucz OpenAI jest w ../../.env (OPENAI_API_KEY).
Przeczytaj: ../../_docs/10_decyzje_projektowe.md (ADR-011, ADR-012)

## Zadania

### Krok 1: Aktualizacja generate_embeddings.py
Zmień provider z Gemini na OpenAI w ../../embeddings/generate_embeddings.py:
- Użyj biblioteki openai (from openai import OpenAI)
- Model: text-embedding-3-large z parametrem dimensions=1536
- Klucz z .env: OPENAI_API_KEY
- Usuń zależność od google-generativeai
- Zachowaj retry logic
- Dodaj parametr model jako argument (domyślnie text-embedding-3-large)

### Krok 2: Wyczyść i wgraj pełną bazę Q&A
- DELETE FROM divechat_knowledge; (wyczyść testowe 5 wpisów Gemini)
- Wgraj WSZYSTKIE 30 wpisów z ../../_docs/04_qa_baza_wiedzy.md
- Model: text-embedding-3-large, dimensions=1536
- Wyświetl potwierdzenie: ile wpisów, ile tokenów zużyto

### Krok 3: Test wyszukiwania na pełnej bazie
Zaktualizuj ../../embeddings/test_search.py na OpenAI.
Uruchom na pytaniach testowych z ../../_docs/08_testy_i_ewaluacja.md sekcja B (nr 16-21).
Wyświetl: pytanie -> top 3 wyniki (question, similarity score).

### Krok 4: Test A/B (small vs large)
Na tych samych 30 wpisach Q&A:
1. Wygeneruj embeddingi modelem text-embedding-3-small (1536 native)
2. Zapisz do TYMCZASOWEJ tabeli (CREATE TEMP TABLE lub osobna kolumna)
3. Uruchom te same 6 zapytań (nr 16-21) na obu zbiorach
4. Wyświetl porównanie: pytanie | large_top1_sim | small_top1_sim | large_top1_match | small_top1_match

### Krok 5: Zaktualizuj requirements.txt
- Dodaj openai (jeśli brak)
- Usuń google-generativeai (jeśli było)

## Definition of Done
- [ ] generate_embeddings.py używa OpenAI text-embedding-3-large (dim=1536)
- [ ] 30 wpisów Q&A w divechat_knowledge z embeddingami OpenAI
- [ ] Wyniki testu 6 pytań (sekcja B) z similarity scores
- [ ] Porównanie A/B: large(1536) vs small(1536) na tych samych zapytaniach
- [ ] requirements.txt zaktualizowany

## Uwagi
- dimensions=1536 to parametr API, nie truncation. OpenAI nativnie kompresuje.
- Po teście A/B zostaw w bazie embeddingi zwycięzcy (prawdopodobnie large).
- Raport z testu A/B wklej do handoff.
