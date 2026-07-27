# Wymagania panelu admina (chat.divezone.pl/admin)
# Wersja: 1.0 | Data: 2026-02-20
# Dostęp: iframe w module PS, auth HMAC z employee_id

## Sekcje

### 1. Konfiguracja AI
- Wybór providera (Claude / OpenAI)
- Wybór modelu (dropdown)
- Temperature, max_tokens
- System prompt (edytor tekstowy)
- Mapa rekomendowanych marek per kategoria (JSON editor lub UI)

### 2. Baza wiedzy
Szczegóły architektury: _docs/14_architektura_bazy_wiedzy.md

#### 2a. Nawigacja
- Drzewo: Dziedzina -> Temat -> Artykuły
- Liczniki artykułów per temat i dziedzina
- Filtr statusu: draft / imported / review / published / rejected
- Filtr źródła: manual / ai_generated / scraped_* / youtube / textbook
- Wyszukiwanie po tytule i treści

#### 2b. Edytor artykułów
- Markdown editor z podglądem live
- Dropdown cascade: dziedzina -> temat
- Metadata: źródło (typ + URL), ocena jakości (1-5), notatki redakcyjne
- Workflow: przyciski zmiany statusu z potwierdzeniem
- Podgląd chunków: jak artykuł zostanie podzielony do embeddingów
- Przycisk "Przeembeduj" (ręczny trigger)
- Auto-embedding przy zmianie statusu na "published"

#### 2c. AI Writing Assistant
- Dialog w edytorze: prompt + wybór modelu (GPT-5.2 / Opus 4.6)
- Generowanie draftu -> wchodzi do edytora do redakcji
- Opcje: długość, styl, referencje do istniejących artykułów
- Docelowo: "Rozwiń ten fragment", "Uprość język", "Dodaj przykłady"

#### 2d. Import masowy
- Podaj URL(e) do scrapowania
- Wybierz source_type i docelowy temat
- Podgląd przed importem (preview)
- Import -> artykuły ze statusem "imported" do przeglądu
- Batch operations: zaznacz wiele -> zmień status / temat / odrzuć

#### 2e. Test wyszukiwania
- Wpisz pytanie testowe
- Zobacz top 5 dopasowań z similarity score
- Pokaż z którego artykułu / chunka pochodzi wynik
- Pomocne do identyfikacji luk w bazie wiedzy

### 3. Rozmowy z klientami
- Lista sesji (data, customer_id, liczba wiadomości, czas trwania)
- Podgląd pełnej rozmowy (wiadomości + tool calls + wyniki)
- Filtrowanie: po dacie, kliencie, użytych narzędziach
- Eksport rozmów (CSV)
- Statystyki: średni czas odpowiedzi, najczęstsze pytania, satisfaction score

### 4. Statystyki
- Dzienne/tygodniowe/miesięczne: liczba rozmów, wiadomości, unikalnych klientów
- Koszty API (tokeny input/output, szacowany koszt per rozmowa)
- Najczęściej wywoływane narzędzia
- Pytania bez trafnych odpowiedzi (similarity < 0.5, do uzupełnienia w Q&A)

### 5. Produkty (read-only)
- Lista produktów w divechat_product_embeddings
- Status embeddingów (data ostatniej aktualizacji, liczba produktów)
- Przycisk "Odśwież embeddingi" (trigger pipeline)
- Test wyszukiwania: wpisz zapytanie, zobacz top 5 produktów z similarity
