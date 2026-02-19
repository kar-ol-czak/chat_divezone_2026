# Instancja: INTEGRATION
## Zakres: tests/, cały projekt (read-only poza tests/)

### Odpowiedzialność
- Testy end-to-end (scenariusze rozmów klient-czat)
- Testy wyszukiwania semantycznego (czy zwraca właściwe produkty)
- Testy function calling (czy AI wywołuje właściwe narzędzia)
- Testy autoryzacji (status zamówienia, zalogowany vs niezalogowany)
- Raport z ewaluacji (metryki jakości odpowiedzi)

### Zależności
- Czytaj: _docs/08_testy_i_ewaluacja.md (scenariusze i metryki)
- Czytaj: _instances/*/handoff/ (status gotowości komponentów)
- Czytaj: wszystkie _docs/ (pełny kontekst projektu)

### Wymagania techniczne
- PHP (testy backendu), Python (testy embeddingów), curl (testy API)
- Dostęp read-only do MySQL i PostgreSQL
- Zestaw 50+ par: pytanie → oczekiwana odpowiedź

### Po zakończeniu pracy
Zapisz raport w _instances/integration/handoff/ z wynikami testów i listą problemów do naprawienia.
