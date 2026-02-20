# TASK-005: Czyszczenie Q&A z nazw produktów i marek
# Data: 2026-02-20
# Instancja: embeddings
# Priorytet: WYSOKI
# Zależności: ADR-013

## Zasada (ADR-013)
Baza wiedzy divechat_knowledge zawiera TYLKO wiedzę ekspercką.
Konkretne produkty i marki jako rekomendacje -> USUNĄĆ.
Marki jako wiedza techniczna -> dozwolone, ale bez wartościowania.
Produkty dobiera AI dynamicznie przez function calling.

## Krok 1: Edytuj ../../_docs/04_qa_baza_wiedzy.md

### Zamień wszystkie [UZUPEŁNIJ: ...] na:
[PRODUKTY: dobierane dynamicznie przez AI na podstawie zapytania klienta]

Dotyczy QA: 001, 002, 003, 005, 006, 007, 008, 009, 010, 011, 012, 013,
014, 015, 017, 020, 025, 026, 028, 029

### QA-025 (linia ~213) - PRZEPISZ KOMPLETNIE:
OBECNA wersja wymienia marki w tym Cressi (której NIE MA w sklepie!).
NOWA wersja:
"Nie ma jednego najlepszego producenta automatów oddechowych. Kluczowe jest
dopasowanie do warunków nurkowania: do zimnej wody potrzebujesz automatu
membranowego z zabezpieczeniem środowiskowym, do ciepłych wód sprawdzi się
prostszy automat tłokowy. Ważniejsze od marki jest: regularny serwis (co 12
miesięcy), dopasowanie do typu nurkowania (rekreacyjne/techniczne) i komfort
oddychania. Mogę pomóc dobrać konkretny model jeśli powiesz mi w jakich
warunkach nurkujesz i jaki masz budżet."

### QA-009 (komputer zegarek vs klasyczny, linia ~91):
Usuń nazwy modeli (Suunto D5, Garmin Descent, Mares Puck Pro, Shearwater Peregrine).
Zamień na opis typów bez nazw.

### QA-026 (Suunto czy Shearwater, linia ~219):
Ten wpis jest problematyczny bo porównuje konkretne marki.
Przepisz na wiedzę o tym czym się różnią komputery rekreacyjne od technicznych,
BEZ faworyzowania marek. AI dobierze konkretne modele z oferty sklepu.

### QA-037 (konserwacja komputera, linia ~312):
Suunto i Shearwater jako przykłady typów baterii (wymienna vs serwisowa) - OK,
to wiedza techniczna. Ale dodaj: "Sprawdź instrukcję swojego modelu."

## Krok 2: Przeembeduj zmienione wpisy
Po edycji pliku MD:
1. Usuń zmienione wpisy z divechat_knowledge (DELETE WHERE question LIKE...)
2. Wgraj nowe wersje z embeddingami (generate_embeddings.py)
3. Sprawdź count: powinno być nadal 37

## Krok 3: Retest
Uruchom test_search.py na pytaniach 16-21 z sekcji B.
Similarity nie powinno spaść poniżej 0.65.

## Definition of Done
- [ ] Zero wystąpień "Cressi" w 04_qa_baza_wiedzy.md
- [ ] Zero placeholderów [UZUPEŁNIJ] w pliku
- [ ] 37 wpisów w divechat_knowledge z nowymi embeddingami
- [ ] Retest: similarity >0.65 dla pytań 16-21
