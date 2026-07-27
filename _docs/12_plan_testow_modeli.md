# Plan testów modeli AI dla czatu divezone.pl
# Wersja: 1.0 | Data: 2026-02-20

## Cel
Wybrać optymalny model (lub ranking modeli) do czatu sklepu nurkowego.
Kryteria: jakość odpowiedzi, trafność function calling, latencja, koszt.

## Modele do testów

| # | Model | Provider | Endpoint | Uwagi |
|---|---|---|---|---|
| 1 | claude-sonnet-4-20250514 | Anthropic | messages | Dobry FC, tani |
| 2 | claude-sonnet-4-5-20250929 | Anthropic | messages | Nowszy, extended thinking |
| 3 | gpt-4.1 | OpenAI | chat/completions | Najnowszy OpenAI, tani FC |
| 4 | gpt-4o | OpenAI | chat/completions | Sprawdzony, multimodal |

## Parametry do testowania

### Wspólne
- temperature: 0.3, 0.5, 0.7 (niższa = bardziej deterministyczny)
- max_tokens: 1024 (standardowa odpowiedź czatu)

### Claude-specific
- extended thinking (Sonnet 4.5): budget_tokens 1024 vs wyłączone
  Hipoteza: thinking pomoże przy złożonych pytaniach doradczych,
  ale doda latencję. Dla prostych pytań ("jaka cena X") niepotrzebne.
  Implementacja: włączaj thinking warunkowo gdy pytanie jest doradcze.

### OpenAI-specific
- reasoning_effort (gpt-4.1): "low", "medium", "high"
  Analogicznie do thinking: high dla doradztwa, low dla prostych pytań.

## Scenariusze testowe (20 pytań)

### Grupa A: Proste produktowe (5 pytań) - oczekiwanie: szybko, 1 tool call
1. "Ile kosztuje maska Scubapro Crystal Vu?"
2. "Czy macie automat Apeks XTX50 na stanie?"
3. "Jaki jest czas dostawy?"
4. "Jaka jest różnica między rozmiarem M i L w piankach Bare?"
5. "Pokaż mi komputery nurkowe do 2000 zł"

### Grupa B: Doradcze (5 pytań) - oczekiwanie: 2-3 tool calls, dłuższa odpowiedź
6. "Jestem początkującym nurkiem, jaki automat kupić?"
7. "Chcę zacząć nurkować w Polsce, jaki sprzęt potrzebuję?"
8. "Mam budżet 5000 zł, co mi polecacie na start?"
9. "Jacket czy skrzydło dla nurka z 50 nurkowaniami?"
10. "Jaki komputer do nurkowania technicznego?"

### Grupa C: Złożone multi-tool (5 pytań) - oczekiwanie: 3+ tool calls
11. "Porównaj automat Apeks XTX50 z Scubapro MK25/A700"
12. "Szukam pianki do zimnej wody, ale nie wiem jaki rozmiar. Mam 180cm, 80kg"
13. "Chcę kupić kompletny zestaw do nurkowania w Egipcie do 8000 zł"
14. "Sprawdź moje zamówienie nr 12345 i powiedz czy mogę dokupić latarkę"
15. "Mam astmę, czy mogę nurkować? I jeśli tak, jaki sprzęt polecacie?"

### Grupa D: Edge cases (5 pytań) - oczekiwanie: poprawna odmowa/przekierowanie
16. "Czy macie sprzęt Cressi?" (marka spoza oferty)
17. "Jaki jest najlepszy sklep nurkowy w Gdańsku?" (nie nasz biznes)
18. "Napraw mi automat" (serwis, nie sprzedaż)
19. "Ile zarabiają pracownicy divezone?" (odmowa)
20. "Opowiedz mi dowcip o nurku" (off-topic, ale sympatycznie)

## Metryki

### Jakość (ocena ręczna 1-5 przez Karola i zespół)
- Trafność: czy odpowiedź jest merytorycznie poprawna?
- Kompletność: czy odpowiedź jest wystarczająco wyczerpująca?
- Ton: czy brzmi jak doświadczony doradca, nie jak bot?
- Bezpieczeństwo: czy nie rekomenduje marek spoza oferty? Czy odmawia gdy powinien?

### Function calling (automatyczne)
- Czy wywołał poprawne narzędzia?
- Czy parametry tool calls były prawidłowe?
- Ile niepotrzebnych tool calls? (mniej = lepiej)
- Czy poprawnie zinterpretował wyniki narzędzi?

### Wydajność (automatyczne)
- Latencja: czas do pierwszego tokenu (TTFT)
- Latencja: czas do pełnej odpowiedzi
- Tokens input / output
- Koszt per pytanie (input + output + embeddings)

## Metodyka

### Przygotowanie
1. Identyczny system prompt dla wszystkich modeli
2. Identyczne definicje narzędzi (tools/functions)
3. Mock tool responses (żeby wyniki nie zależały od stanu bazy)
4. Każde pytanie testowane 3x (sprawdzenie determinizmu)

### Execution
Skrypt Python: tests/model_benchmark.py
- Wysyła 20 pytań do każdego modelu z każdą kombinacją parametrów
- Zapisuje: pełny request/response, tool calls, timing, koszty
- Output: JSON z wynikami + CSV summary

### Kombinacje do testowania
| Model | temperature | extra param | Łącznie runs |
|---|---|---|---|
| claude-sonnet-4 | 0.3, 0.5, 0.7 | - | 3 |
| claude-sonnet-4.5 | 0.3, 0.5 | thinking off, thinking 1024 | 4 |
| gpt-4.1 | 0.3, 0.5, 0.7 | reasoning low, medium, high | 9 |
| gpt-4o | 0.3, 0.5, 0.7 | - | 3 |

19 kombinacji x 20 pytań x 3 powtórzenia = 1140 API calls
Szacunkowy koszt: ~$5-15 (głównie tokeny output)

## Output
- tests/results/model_benchmark_YYYY-MM-DD.json (surowe dane)
- tests/results/model_benchmark_summary.csv (metryki per model)
- _docs/12_wyniki_testow_modeli.md (analiza, rekomendacja)

## Decyzja
Na podstawie wyników: ranking modeli z rekomendacją domyślnego.
Moduł PS pozwala na zmianę modelu w konfiguracji (panel admina).
