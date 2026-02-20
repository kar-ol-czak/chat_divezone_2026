# TASK-003: Wgranie nowych Q&A (031-037) + retest
# Data: 2026-02-19
# Instancja: embeddings
# Priorytet: ŚREDNI
# Zależności: TASK-002

## Zadania

### Krok 1: Wgraj QA-031 do QA-037
Z ../../_docs/04_qa_baza_wiedzy.md wgraj 7 nowych wpisów (QA-031 do QA-037).
Użyj ../../embeddings/generate_embeddings.py z OpenAI text-embedding-3-large (dim=1536).
Nie usuwaj istniejących 30 wpisów.

### Krok 2: Retest pytań z sekcji B
Uruchom test_search.py na pytaniach 16-21 z ../../_docs/08_testy_i_ewaluacja.md.
Szczególnie sprawdź:
- Pyt. 18 "Czy mogę nurkować z astmą?" (powinno trafić QA-031)
- Pyt. 19 "Różnica między nitroksem a powietrzem?" (powinno trafić QA-033)
- Pyt. 21 "Jak przechowywać piankę?" (powinno trafić QA-035)
Wyświetl similarity scores. Oczekiwanie: >0.65 dla trafnych par.

## Definition of Done
- [ ] 37 wpisów w divechat_knowledge
- [ ] Similarity >0.65 dla pytań 18, 19, 21
