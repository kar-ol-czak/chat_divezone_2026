# Architektura bazy wiedzy czatu divezone.pl
# Wersja: 1.0 | Data: 2026-02-20

## Problem
Baza wiedzy rośnie z wielu źródeł (ręczne Q&A, scraping, podręczniki, YouTube).
Płaska struktura (chunk_type + category) nie skaluje się.
Potrzebna: hierarchia tematów, workflow redakcyjny, AI-assisted writing.

## Model danych

### Poziom 1: Dziedziny wiedzy (domains)
Najwyższy poziom organizacji. 5-7 dziedzin, rzadko się zmienia.

| ID | Dziedzina | Opis |
|----|-----------|------|
| 1  | sprzet    | Wszystko o sprzęcie nurkowym (dobór, porównania, cechy) |
| 2  | technika  | Technika nurkowania (wyporność, trym, procedury) |
| 3  | bezpieczenstwo | Bezpieczeństwo, zdrowie, fizjologia |
| 4  | szkolenia | Certyfikacje, kursy, wymagania |
| 5  | logistyka | Dostawa, płatności, serwis, reklamacje divezone.pl |
| 6  | lokalizacje | Bazy nurkowe, warunki, podróże z nurkowaniem |
| 7  | foto_video | Fotografia i wideo podwodne |

### Poziom 2: Tematy (topics)
Konkretne tematy w ramach dziedziny. 30-60 tematów, rośnie z czasem.

Przykłady dla dziedziny "sprzet":
- automaty_oddechowe
- komputery_nurkowe
- maski
- pianki_mokre
- skafandry_suche
- bcd_skrzydla
- pletwy
- latarki
- butle
- balast
- noze_narzedzia
- akcesoria

Przykłady dla "bezpieczenstwo":
- przeciwwskazania_zdrowotne
- choroba_dekompresyjna
- gazy_oddechowe
- pierwsza_pomoc
- planowanie_nurkowania

### Poziom 3: Artykuły (articles)
Jednostka redakcyjna. Jeden artykuł = jeden spójny temat.
Artykuł może mieć wiele chunków (embedowanych osobno).

Przykład: temat "automaty_oddechowe", artykuły:
- "Jak wybrać automat oddechowy?"
- "Membranowy vs tłokowy: różnice"
- "Serwis automatu: co, kiedy, ile kosztuje"
- "Automaty do zimnej wody: na co zwrócić uwagę"

### Poziom 4: Chunki (chunks)
Jednostka embeddingu. Fragment artykułu (300-800 tokenów).
To co trafia do pgvector i jest wyszukiwane semantycznie.

## Schemat bazy (rozszerzenie divechat_knowledge)

```sql
-- Nowa tabela: dziedziny
CREATE TABLE divechat_domains (
    id SERIAL PRIMARY KEY,
    slug VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    sort_order INT DEFAULT 0
);

-- Nowa tabela: tematy
CREATE TABLE divechat_topics (
    id SERIAL PRIMARY KEY,
    domain_id INT REFERENCES divechat_domains(id),
    slug VARCHAR(100) UNIQUE NOT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    sort_order INT DEFAULT 0
);

-- Nowa tabela: artykuły (jednostka redakcyjna)
CREATE TABLE divechat_articles (
    id SERIAL PRIMARY KEY,
    topic_id INT REFERENCES divechat_topics(id),
    title VARCHAR(300) NOT NULL,
    content TEXT NOT NULL,             -- pełna treść artykułu (markdown)
    source_type VARCHAR(30) NOT NULL,  -- patrz enum niżej
    source_url TEXT,                   -- URL źródła (scraping, blog, YT)
    source_title TEXT,                 -- tytuł źródła
    status VARCHAR(20) NOT NULL DEFAULT 'draft',  -- workflow
    author VARCHAR(100),               -- kto napisał/zredagował
    ai_model VARCHAR(50),              -- jeśli AI-generated: jaki model
    quality_score INT,                 -- 1-5, ocena redaktora
    notes TEXT,                        -- notatki redakcyjne
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    published_at TIMESTAMP             -- kiedy zatwierdzono
);

-- Istniejąca tabela rozszerzona: chunki (jednostka embeddingu)
-- divechat_knowledge zostaje, ale dostaje article_id
ALTER TABLE divechat_knowledge ADD COLUMN article_id INT REFERENCES divechat_articles(id);
ALTER TABLE divechat_knowledge ADD COLUMN chunk_index INT DEFAULT 0;
-- chunk_index: kolejność chunka w artykule (0, 1, 2...)
```

### Source types (enum source_type)
| Wartość | Opis | Workflow |
|---------|------|----------|
| manual | Napisany ręcznie | draft -> review -> published |
| ai_generated | Wygenerowany przez AI | draft -> human_review -> published |
| scraped_blog | Scraping bloga | imported -> review -> published |
| scraped_encyclopedia | Scraping encyklopedii | imported -> review -> published |
| scraped_forum | Scraping forum | imported -> review -> published |
| youtube_transcript | Transkrypcja YT | imported -> review -> published |
| textbook | Z podręcznika (PDF) | imported -> review -> published |
| own_blog | Blog divezone.pl | imported -> review -> published |

### Status workflow
```
                     ┌──────────┐
                     │  draft   │ (nowy, ręczny)
                     └────┬─────┘
                          │
     ┌──────────┐         │         ┌──────────────┐
     │ imported │─────────┼────────>│    review     │
     └──────────┘         │         └───────┬───────┘
     (scraping/AI)        │                 │
                          │           ┌─────┴──────┐
                          │           │            │
                     ┌────▼─────┐  ┌──▼───┐  ┌────▼────┐
                     │published │  │reject│  │ revise  │
                     └──────────┘  └──────┘  └─────────┘
                     (aktywny,      (odrzuc)   (do poprawy,
                      embeddingi              wraca do draft)
                      generowane)
```

Tylko artykuły ze statusem "published" są chunkowane i embeddowane.
Zmiana statusu na "published" triggeruje: chunking -> embedding -> zapis do divechat_knowledge.

## Chunking strategy

Artykuł (markdown) dzielony na chunki:
- Podział po nagłówkach H2/H3 (naturalny podział tematyczny)
- Max 800 tokenów na chunk, min 100
- Jeśli sekcja >800 tokenów: dziel po akapitach
- Overlap: 1-2 zdania z poprzedniego chunka (kontekst)
- Każdy chunk dziedziczy metadata z artykułu (domain, topic, source_type)

Stare Q&A (obecne 37 wpisów): migracja do artykułów.
Każde Q&A = 1 artykuł, 1 chunk, source_type=manual, status=published.

## Panel admina: widok bazy wiedzy

### Nawigacja (lewy panel)
```
📚 Baza wiedzy
├── 🔧 Sprzęt (15 tematów, 47 artykułów)
│   ├── Automaty oddechowe (8)
│   ├── Komputery nurkowe (6)
│   ├── Maski (4)
│   └── ...
├── 🏊 Technika (8 tematów, 23 artykuły)
├── ⚠️ Bezpieczeństwo (5 tematów, 18 artykułów)
├── 📋 Szkolenia (4 tematy, 12 artykułów)
├── 🚚 Logistyka (3 tematy, 8 artykułów)
├── 📍 Lokalizacje (4 tematy, 6 artykułów)
└── 📸 Foto/Video (3 tematy, 5 artykułów)

Filtr statusu: [Wszystkie] [Draft 12] [Review 5] [Published 89] [Rejected 3]
Filtr źródła: [Wszystkie] [Ręczne] [AI] [Scraping] [YouTube]
```

### Lista artykułów (główny panel)
Tabela: tytuł | temat | źródło | status | jakość | data | akcje
Akcje: edytuj, podgląd, zmień status, usuń, przeembeduj

### Edytor artykułu
- Tytuł
- Dziedzina -> Temat (dropdown cascade)
- Treść (markdown editor, podgląd live)
- Źródło: typ + URL + tytuł
- Status (dropdown z workflow)
- Ocena jakości (1-5 gwiazdek)
- Notatki redakcyjne
- Przycisk: "Generuj AI" (otwiera dialog z promptem)
- Przycisk: "Przeembeduj" (chunking + embedding)
- Podgląd chunków: jak artykuł zostanie podzielony

### AI Writing Assistant (w edytorze)
Dialog:
1. Wybierz temat (pre-filled z kontekstu)
2. Prompt: "Napisz artykuł o [temat]" (edytowalny)
3. Opcje: model (gpt-5.2 / opus-4-6), długość (krótki/średni/długi)
4. [Generuj] -> AI pisze, wynik wchodzi do edytora jako draft
5. Człowiek redaguje, poprawia, zatwierdza
6. [Publikuj] -> chunking + embedding

### Import masowy (scraping)
1. Podaj URL(e) źródłowe (lista)
2. Wybierz source_type i docelowy temat
3. [Importuj] -> scraping + czyszczenie + artykuły ze statusem "imported"
4. Redaktor przegląda, poprawia, publikuje lub odrzuca

## Migracja z obecnej struktury

Obecne 37 wpisów w divechat_knowledge:
1. Stwórz tabele domains, topics, articles
2. Wgraj 7 domen i ~30 tematów (seed)
3. Dla każdego obecnego Q&A: stwórz artykuł (source_type=manual, status=published)
4. Dodaj article_id do istniejących chunków w divechat_knowledge
5. Nowe artykuły: normalny workflow (draft -> review -> published -> chunk -> embed)

## Priorytet implementacji
1. MVP: tabele + seed domen/tematów + migracja Q&A (TASK embeddings)
2. Panel: CRUD artykułów z edytorem markdown (TASK admin)
3. Chunking pipeline: auto-chunking + embedding przy publikacji (TASK embeddings)
4. AI assistant: generowanie draftu z promptu (TASK admin v2)
5. Import: scraping + masowy import (TASK embeddings v2)
