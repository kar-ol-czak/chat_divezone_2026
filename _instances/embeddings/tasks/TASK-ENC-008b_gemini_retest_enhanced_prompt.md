# TASK-ENC-008b: Retest Gemini z wzbogaconym promptem
# Data: 2026-03-05
# Status: DO ZROBIENIA
# Instancja: embeddings
# Zależność: TASK-ENC-008a DONE (wyniki w data/encyclopedia/v3/test/)

---

## CEL

Test Gemini 3.1 Pro z 3 nowymi zasadami w prompcie (#17, #18, #19) i stripem
markdown wrappera. Celem jest zamknięcie luki jakościowej Gemini→Claude Opus 4.6
przy 10× niższym koszcie.

Porównanie: nowy output Gemini vs istniejący output Claude Opus 4.6
(data/encyclopedia/v3/test/claude_test_3_concepts.md).

## KROK 1: Edycja promptu

Plik: `_docs/PROMPT_gemini_encyklopedia_v3.md`

Dodaj 3 nowe zasady PRZED sekcją "## PROCEDURA PRACY" (po zasadzie #16):

```markdown
### ZASADA #17: CROSS-SELL Z DANYMI SPRZEDAŻOWYMI
W sekcji "Powiązane produkty (Cross-selling)" ZAWSZE cytuj konkretne procenty
i liczby zamówień z dostarczonych danych sprzedażowych. Format:
"Kieszenie balastowe (43.5% klientów kupuje razem ze skrzydłem [dane sprzedażowe])"
"Wąż Si-Tech 80 cm — bestseller kategorii, 11 zamówień [dane sprzedażowe]"
Jeśli dane sprzedażowe nie zawierają statystyk dla danego produktu, napisz
rekomendację bez procentów, ale NIE wymyślaj liczb.

### ZASADA #18: FRAZY LONG-TAIL Z WYSZUKIWAREK
Po sekcji "Synonimy i słowa kluczowe" dodaj sekcję:
**Frazy long-tail z wyszukiwarek:**
Zbierz WSZYSTKIE frazy z autocomplete, PAA i all_keywords.csv które nie zmieściły się
w synonimach. Tagujesz źródło i wolumen: `automat nurkowy zestaw [AC]`,
`ile kosztuje serwis automatu [PAA]`, `automat oddechowy olx [GSC, 10 vol]`.
Minimum 5 fraz per hasło. To paliwo do semantic search — im więcej, tym lepiej.

### ZASADA #19: LINKOWANIE CONCEPT KEYS
W tekście hasła linkuj powiązane pojęcia ze spisu treści (FAZA1_concept_keys_v2.md)
w formacie `(→ CONCEPT_KEY)`. Stosuj w sekcjach: "Nie mylić z", "Cross-selling",
"FAQ klienta", "Uwagi dla sprzedawcy". Przykład:
"Octopus (→ OCTOPUS) to zapasowy drugi stopień..."
"Ocieplacz (→ OCIEPLACZ) jest absolutnie niezbędny pod trylaminat"
Linkuj TYLKO do istniejących concept keys ze spisu. Nie wymyślaj nowych.
```

## KROK 2: Strip markdown wrappera w skrypcie

Plik: `scripts/generate_encyclopedia.py`

Gemini wrappuje output w ```markdown ... ```. Dodaj funkcję strip i wywołaj ją
na result.content PRZED zapisem.

Dodaj helper (np. przy innych helperach na górze pliku):

```python
def strip_markdown_wrapper(text: str) -> str:
    """Usuwa ```markdown wrapper jesli Gemini go dodal."""
    text = text.strip()
    if text.startswith("```markdown"):
        text = text[len("```markdown"):].lstrip("\n")
    if text.startswith("```"):
        text = text[3:].lstrip("\n")
    if text.endswith("```"):
        text = text[:-3].rstrip("\n")
    return text.strip()
```

Wywołaj w DWÓCH miejscach:
1. W run_test_phase() — po uzyskaniu result.content, przed zapisem
2. W run_batch_phase() — po uzyskaniu result.content, przed zapisem

Szukaj linii z `result.content` i `write_text` / `f.write`.

## KROK 3: Uruchom test TYLKO Gemini

NIE uruchamiaj pełnego --phase test (to testuje 3 modele). Uruchom TYLKO Gemini.

Opcja A (preferowana): Dodaj tymczasowy arg --model do test phase:
```bash
python3 scripts/generate_encyclopedia.py --phase test --model gemini
```
Jeśli skrypt tego nie wspiera, zmodyfikuj run_test_phase() żeby akceptowała
opcjonalny filtr modelu.

Opcja B (fallback): Uruchom pełny test, ale wyniki Gemini to jedyne co nas interesuje.

## KROK 4: Zapisz wyniki

Output: `data/encyclopedia/v3/test_v2/gemini_test_3_concepts.md`

UWAGA: zapisz do `test_v2/`, NIE nadpisuj `test/`. Potrzebujemy obu wersji
do porównania (test/ = baseline, test_v2/ = enhanced prompt).

Wygeneruj krótki raport: `data/encyclopedia/v3/test_v2/test_v2_report.md`
z tokenami, czasem, kosztem.

## HASŁA TESTOWE (te same co w TASK-ENC-008a)

1. AUTOMAT ODDECHOWY / REGULATOR (concept #1)
2. JACKET (BCD) / JACKET (BCD) (concept #25)
3. SUCHY SKAFANDER / DRYSUIT (concept #63)

## KRYTERIA SUKCESU (co sprawdzi architekt)

1. ✅ Markdown wrapper usunięty (brak ``` na początku/końcu)
2. ✅ Sekcja "Frazy long-tail z wyszukiwarek" obecna w każdym haśle
3. ✅ Cross-sell zawiera konkretne % z danych sprzedażowych
4. ✅ Linkowanie (→ CONCEPT_KEY) obecne w sekcjach "Nie mylić z" i "Cross-selling"
5. ✅ Objętość wzrosła z ~4,300 chars/hasło do ~5,500-7,000 chars/hasło
6. ✅ Model string w raporcie: gemini-3.1-pro-preview

## NIE RÓB

- Nie modyfikuj wyników Claude ani GPT z test/
- Nie zmieniaj zasad #1-#16 (tylko DODAJ #17-#19)
- Nie uruchamiaj batch phase
- Nie zmieniaj model stringów (są poprawne od TASK-ENC-008a)
