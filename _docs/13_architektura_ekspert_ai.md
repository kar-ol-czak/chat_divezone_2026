# Architektura eksperckiego AI dla DiveZone
# Data: 2026-02-21
# Status: DO ROZWIĄZANIA
# Kontekst: _docs/12_analiza_wyszukiwanie_wiedza.md

## Problem fundamentalny

AI (GPT-4.1, Claude) ma wiedzę o nurkowaniu na poziomie instruktora. Zna synonimy, parametry techniczne, warunki nurkowe w różnych regionach. Ale gdy klient mówi "szukam pianki", AI wysyła do search_products dosłowne słowa klienta zamiast przetłumaczyć je na terminologię sklepu.

To NIE jest problem braku wiedzy. To problem architektury: AI nie wie jak wygląda TEN konkretny sklep.

## Co wie AI (i powinno wykorzystywać)

- Pianka = skafander mokry/neoprenowy (synonimy branżowe)
- Polska = zimna woda 4-10°C = potrzeba 7mm semidry minimum
- Przy piankach nie pytaj o zaawansowanie, pytaj o płeć
- DIN/INT to archaiczny podział, w Europie standard to DIN
- Jacket vs wing to fundamentalna różnica w filozofii nurkowania

## Czego AI NIE wie (i musi się dowiedzieć)

- Jak nazywają się kategorie w divezone.pl (np. "Skafandry Na ZIMNE wody", nie "skafandry mokre 7mm")
- Jakie marki nosi sklep i w jakich kategoriach
- Jakie są bestsellery i produkty flagowe
- Jak wygląda asortyment: co jest "od ręki", co "na zamówienie"
- Specyfika sklepu: np. silna pozycja w technicznym, TECLINE jako house brand

## Dotychczasowe podejścia (niewystarczające)

### 1. System prompt z listą kategorii (obecne)
Wstawiono pełną listę kategorii sklepu do system prompta.
Problem: kategorie to za mało. AI wie że szukać w "Skafandry Na ZIMNE wody" ale embedding i tak może nie matchować bo nazwy produktów to "BARE Velocity Semi-Dry 7mm".

### 2. Tool description z instrukcją tłumaczenia (obecne)
search_products mówi "tłumacz na nazewnictwo sklepu".
Problem: AI nie zna nazw konkretnych produktów, więc tłumaczy na ogólniki.

### 3. Słownik synonimów (odrzucone)
Hardkodowany mapping "pianka" → "skafander mokry".
Problem: to praca za AI, nie skaluje się, wymaga utrzymania.

## Pytanie architektoniczne

Jak zbudować system, w którym AI zachowuje się jak ekspert-instruktor-właściciel sklepu?
Czyli: niezależnie co powie klient, AI:
1. Rozumie co klient NAPRAWDĘ potrzebuje (wiedza nurkowa)
2. Wie jak to się nazywa W TYM SKLEPIE (wiedza o asortymencie)
3. Potrafi efektywnie wyszukać (query do embeddingów)
4. Zna specyfikę oferty (marki, bestsellery, dostępność)

## Możliwe kierunki (do dyskusji)

### A. Wzbogacony kontekst produktowy
Embedding text produktu zawiera nie tylko nazwę i opis, ale też:
- Synonimy klienckie ("pianka 7mm", "wetsuit zimowy")
- Kategoria + ścieżka kategorii
- Tagi: typ_wody, grubość, płeć, poziom
Wtedy embedding naturalnie matchuje zapytania klienta.

### B. Dwuetapowe wyszukiwanie
1. AI generuje "brief ekspercki": co klient potrzebuje w języku technicznym
2. Osobny krok: tłumaczenie briefu na query do embeddingów
Może: osobny mały model lub reguły

### C. Kategoria jako filtr, nie embedding
AI wybiera kategorię z listy (dokładne dopasowanie), potem embedding szuka w ramach kategorii.
search_products dostaje category jako parametr, query jest wtedy prostsze.

### D. "Profil sklepu" jako wiedza
Osobny dokument/narzędzie: "profil asortymentowy divezone.pl"
Zawiera: per kategoria listę marek, zakres cenowy, bestsellery, specyfikę.
AI odpytuje go zanim szuka produktów.

### E. Hybrid: pełnotekstowy + semantyczny
Oprócz embedding similarity, dodaj trigram/fulltext search na nazwie produktu.
"pianka 7mm" matchuje trigramowo "7mm" w nazwie produktu.

## Stan techniczny

- PostgreSQL 17.8 z pgvector 0.8.1 (Railway)
- 2556 aktywnych produktów w divechat_product_embeddings
- Embedding: OpenAI text-embedding-3-large (1536 dim)
- Embedding text: nazwa + opis produktu (description)
- Brak: synonimów, tagów, ścieżki kategorii w tekście embeddingu
- in_stock: 1057 true, 1499 false
- in_stock_only zmieniony na false (domyślnie pokazuj wszystko)

## Pliki referencyjne

- _docs/10_decyzje_projektowe.md (ADR-010 embedding, ADR-013 rekomendacje, ADR-018 knowledge)
- _docs/12_analiza_wyszukiwanie_wiedza.md (analiza problemów z sesji testowej)
- _docs/04_qa_baza_wiedzy.md (wpisy QA-001 do QA-040)
- standalone/src/Tools/ProductSearch.php (obecna implementacja search)
- standalone/src/Chat/SystemPrompt.php (obecny system prompt)
- standalone/src/Chat/ChatService.php (flow: prompt → AI → tool call → AI)
- _instances/embeddings/tasks/TASK-010_knowledge_pipeline.md (pipeline wiedzy)
