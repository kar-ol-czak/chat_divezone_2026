# Brief do analizy architektury wyszukiwania produktów w czacie AI
# Cel: niezależna ocena architektury i znalezienie pomysłów których nie mamy
# Wklej do: ChatGPT (GPT-5.2 lub o3) i Google Gemini 2.5 Pro
# Poproś o szczegółową, techniczną odpowiedź

---

## Kontekst projektu

Budujemy czat AI (conversational commerce chatbot) dla divezone.pl, największego
polskiego sklepu internetowego ze sprzętem nurkowym (~2556 aktywnych produktów,
~50 kategorii, ~70 marek). Klienci to polscy nurkowie, od początkujących po
instruktorów i nurków technicznych.

### Stack techniczny
- LLM do rozmowy: Claude API (Anthropic) z function calling / tool use
- Embeddings: OpenAI text-embedding-3-large, 1536 wymiarów
- Baza wektorowa: PostgreSQL 18.2 z pgvector 0.8.1
- E-commerce: PrestaShop 1.7.6 (MySQL, prefix pr_)
- Język interfejsu: polski

### Jak działa flow
1. Klient pisze wiadomość w czacie na stronie sklepu
2. Claude API otrzymuje wiadomość + system prompt + historię rozmowy
3. Claude decyduje czy wywołać narzędzie (function call) np. search_products
4. search_products: generuje embedding zapytania, szuka w PostgreSQL (cosine similarity)
5. Wyniki wracają do Claude, który formułuje odpowiedź z rekomendacjami

## Problem (vocabulary mismatch)

AI ma wiedzę o nurkowaniu na poziomie instruktora. Wie że:
- "pianka" = skafander mokry/neoprenowy/wetsuit
- Polska = zimna woda 4-10°C = potrzebny 7mm semidry minimum
- "automat" = regulator oddechowy (pierwszy + drugi stopień)
- "jacket" = kamizelka wyrównawcza BCD
- "skrzydło" = wing (backplate & wing system)

ALE: gdy klient mówi "szukam pianki", embedding zapytania "pianka" ma niski cosine
similarity z embeddingiem produktu "BARE Velocity Semi-Dry 7mm Lady", bo embedding
text produktu zawiera terminologię sklepową, nie potoczną.

### Obecny embedding text produktu (to co jest embedowane):
```
Produkt: BARE Velocity Semi-Dry 7mm Lady
Marka: BARE
Kategoria: Skafandry Na ZIMNE wody
Cena: 2499.00 PLN
Opis: [opis producenta z PrestaShop, często po angielsku lub ogólnikowy]
Cechy: Grubość: 7mm, Płeć: Damska
```

### Próbowane rozwiązania (częściowo działają)
1. System prompt z pełną listą kategorii sklepu + instrukcja "tłumacz na język sklepu"
2. Tool description z instrukcją "query musi zawierać terminologię produktową, NIE słowa klienta"
3. Sekcja "MYŚL ZANIM SZUKASZ" w system prompcie z przykładami tłumaczeń

Te rozwiązania pomagają, ale nie rozwiązują problemu fundamentalnie. AI nadal czasem
wysyła dosłowne słowa klienta lub ogólnikowe zapytania do embeddingu.

## Nasza proponowana architektura (4 warstwy)

### Warstwa 1: LLM Product Enrichment (build time, jednorazowo)
Dla każdego z 2556 produktów, silny LLM (GPT-5.2 lub Claude Opus 4.6) generuje
5-8 alternatywnych fraz wyszukiwania w języku polskim, uwzględniając:
- Synonimy potoczne i branżowe
- Kontekst użycia (zimna woda, Polska, Norwegia, Egipt)
- Parametry techniczne
- Disambiguację (latarka nurkowa vs latarka kempingowa)

Frazy dodawane do embedding text jako "Szukaj też jako: pianka nurkowa damska
na zimną wodę, skafander mokry semidry 7mm, wetsuit zimowy damski..."

Wzbogacone o REALNE DANE z:
- Google Search Console (zapytania prowadzące do sklepu)
- Luigi's Box (wewnętrzna wyszukiwarka sklepu, frazy klientów)
- Google Analytics 4 (site search terms)

LLM dostaje realne frazy klientów jako kontekst, więc nie "wymyśla" synonimów,
tylko mapuje realne zapytania na konkretne produkty.

Walidacja: drugi LLM sprawdza jakość wygenerowanych fraz.

### Warstwa 2: Hybrid Search (embedding + trigram, query time)
pg_trgm (PostgreSQL trigram extension) obok embeddingu.
Combined score: 0.7 * cosine_similarity + 0.3 * trigram_similarity
Łapie literalne dopasowania: "BARE 7mm", "Shearwater Teric", "3mm shorty".

### Warstwa 3: Structured Query Rewriting (query time, w LLM)
Dodatkowy parametr search_reasoning w tool schema. AI musi zapisać reasoning
zanim wywoła search: "klient szuka X → to znaczy Y → szukam Z w kategorii W".
Wymusza chain-of-thought, zero dodatkowego kosztu.

### Warstwa 4: Category Filter (query time, w LLM)
AI podaje kategorię sklepową jako filtr SQL. Semi-wymagane w tool schema.

### Pominięte: Re-ranking
Przy 5-10 wynikach z search i LLM w pętli czatu, AI naturalnie re-rankuje.
Dedykowany re-ranker (cross-encoder) ma sens od setek wyników.

## Pytania (proszę o szczegółowe, techniczne odpowiedzi)

### Architektura ogólna
1. Czy ta 4-warstwowa architektura prawidłowo adresuje vocabulary mismatch
   w e-commerce product search? Co byś zmienił lub dodał?

2. Przy ~2556 produktach (nie milionach): czy jest prostsze podejście które
   dałoby porównywalny efekt? Np. przeszukiwanie wszystkich produktów bez
   embeddingów, czysto przez LLM?

3. Czy znasz publikacje lub case studies z e-commerce chatbotów (nie search
   engines) rozwiązujących ten sam problem? Chatbot to inny kontekst niż
   search box, bo mamy LLM w pętli.


### LLM Product Enrichment (Warstwa 1)
4. Czy LLM-generated search phrases to najlepszy sposób na wzbogacenie
   embedding text? Jakie są alternatywy?
   - Fine-tuning modelu embeddingowego na parach (query→produkt)?
   - Matryb (Matryoshka) embeddings z różną granularnością?
   - Osobne embeddingi per aspekt (nazwa, kategoria, cechy, synonimy)?
   - Coś innego?

5. Prompt do generowania fraz: czy widzisz luki w naszym podejściu?
   Nasz prompt uwzględnia: synonimy potoczne/branżowe, kontekst użycia,
   parametry techniczne, disambiguację, regiony. Wzbogacony o realne
   frazy z GSC i internal search. Walidowany przez drugi LLM.

6. Czy warto rozważyć generowanie "anti-phrases" (frazy negatywne)?
   Np. dla latarki nurkowej: "to NIE jest latarka kempingowa".
   Czy embedding models w ogóle potrafią to wykorzystać?

### Hybrid Search (Warstwa 2)
7. Trigram (pg_trgm) vs BM25 vs SPLADE vs ColBERT: co jest najlepsze
   przy 2556 produktach w PostgreSQL? Czy trigram to zbyt prymitywne?

8. Wagi 0.7 embedding / 0.3 trigram: jak kalibrować? Czy jest
   systematyczna metoda, czy trial-and-error na test set?

### Query Rewriting (Warstwa 3)
9. Czy structured reasoning (search_reasoning parametr) to wystarczające
   podejście, czy warto rozważyć osobny "query planner" step?

10. Amazon i Taobao używają dedykowanych modeli do query rewriting
    (fine-tuned BERT, distilled LLM). Czy przy jednym LLM w pętli
    (czat) to w ogóle ma sens, czy prompt engineering wystarczy?

### Rzeczy których nie rozważyliśmy
11. Czy powinienem rozważyć multi-vector retrieval (osobny wektor
    per aspekt produktu)?

12. Czy GraphRAG lub knowledge graph byłby lepszy niż flat embeddings
    dla strukturalnych danych produktowych (kategoria→marka→model→cechy)?

13. Czy "late interaction" modele (ColBERT) mają sens przy 2556 produktach?

14. Jakie metryki powinienem śledzić żeby wiedzieć czy wyszukiwanie
    działa dobrze? (poza "zero results rate")

### Kontekst specyficzny
15. Język polski, niszowa branża (nurkowanie), ~50 kategorii, ~70 marek,
    dwujęzyczne nazwy produktów (polskie kategorie, angielskie nazwy
    produktów). Czy to zmienia rekomendacje?

16. Klienci pytają zarówno o konkretne produkty ("Shearwater Teric cena")
    jak i ogólnie ("jaki komputer do nurkowania dla początkującego").
    Czy te dwa tryby wymagają różnych strategii retrieval?

## Dane techniczne do kontekstu

- pgvector 0.8.1 wspiera: HNSW, IVFFlat, cosine/L2/inner product
- pg_trgm jest dostępne w naszym PostgreSQL
- Budżet: nie jest problemem dla jednorazowych operacji (enrichment, reembedding)
- Latencja: akceptowalne 2-4 sekundy na odpowiedź (to czat, nie search box)
- Nie mamy logów query→click (jeszcze, czat dopiero budujemy)

## Czego oczekuję od tej analizy

1. Krytyka: co jest złe lub suboptymalne w naszej architekturze
2. Alternatywy: podejścia których nie rozważyliśmy
3. Priorytety: gdybyś miał ograniczony czas, co zrobiłbyś najpierw
4. Quick wins: coś prostego co da duży efekt a co pomijamy
5. Referencje: konkretne papery, case studies, implementacje
