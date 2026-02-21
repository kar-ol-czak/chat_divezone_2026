# Prompt do nowego wątku: Architektura eksperckiego AI

Wklej poniższy tekst jako pierwszą wiadomość w nowym wątku w tym projekcie.

---

Kontynuujemy temat z sesji 5/6. Problem architektoniczny opisany w `_docs/13_architektura_ekspert_ai.md`.

KONTEKST W SKRÓCIE:
Czat AI dla divezone.pl (największy sklep nurkowy w PL). AI ma wiedzę o nurkowaniu na poziomie instruktora, ale nie potrafi jej użyć przy wyszukiwaniu produktów. Klient mówi "szukam pianki", AI szuka dosłownie "pianka" zamiast "Skafandry Na ZIMNE wody" (tak nazywa się kategoria w sklepie). Próbowaliśmy: system prompt z listą kategorii, instrukcję "tłumacz na język sklepu" w tool description. To pomaga ale nie rozwiązuje problemu fundamentalnie.

PYTANIE:
Jak zbudować architekturę, w której AI zachowuje się jak ekspert-instruktor-właściciel sklepu nurkowego? Niezależnie co powie klient, AI powinno:
1. Rozumieć co klient NAPRAWDĘ potrzebuje (wiedza nurkowa którą AI już ma)
2. Wiedzieć jak to się nazywa w TYM sklepie (nazwy kategorii, produktów, marek)
3. Efektywnie wyszukiwać w bazie embeddingów (query które matchują nazwy produktów)
4. Znać specyfikę oferty (marki, bestsellery, co na zamówienie)

OGRANICZENIA:
- Nie chcemy hardkodowanych słowników synonimów (to praca za AI)
- Rozwiązanie musi skalować się bez ręcznej pracy przy każdym nowym produkcie
- Mamy 2556 produktów, PostgreSQL z pgvector, OpenAI embeddings
- Budżet tokenów: system prompt nie powinien być gigantyczny

KIERUNKI DO ROZWAŻENIA (z _docs/13):
A. Wzbogacony embedding text (synonimy klienckie, tagi, ścieżka kategorii)
B. Dwuetapowe wyszukiwanie (brief ekspercki → query)
C. Kategoria jako filtr + embedding w ramach kategorii
D. "Profil sklepu" jako osobne narzędzie/wiedza
E. Hybrid: pełnotekstowy + semantyczny search

Przeczytaj `_docs/13_architektura_ekspert_ai.md` i `_docs/12_analiza_wyszukiwanie_wiedza.md`, potem zaproponuj architekturę. Numeracja pytań od 10.
