# TASK-ENC-008c: Microtest Gemini z zasadą #20 (objętość)
# Data: 2026-03-05
# Status: DO ZROBIENIA
# Instancja: embeddings
# Zależność: TASK-ENC-008b DONE

---

## CEL

Szybki test 1 hasła (AUTOMAT ODDECHOWY) z nową zasadą #20 wymuszającą
większą objętość: więcej podtypów klienckich, więcej FAQ, więcej long-tail.
Porównanie z Claude Opus 4.6 na tych samych wymiarach.

Hasło AUTOMAT ODDECHOWY wybrane celowo — tam luka podtypów była największa:
- Gemini v2: 2 podtypy klienckie (rekreacyjny, techniczny)
- Claude Opus: 5 podtypów klienckich (rekreacyjny, twinset, stage, sidemount, travel)
- Claude miał też 6 FAQ vs Gemini 3 FAQ, i 12 fraz long-tail vs 6

## KROK 1: Dodaj zasadę #20 do promptu

Plik: `_docs/PROMPT_gemini_encyklopedia_v3.md`

Dodaj PO zasadzie #19, PRZED sekcją "## PROCEDURA PRACY":

```markdown
### ZASADA #20: MINIMALNA OBJĘTOŚĆ I KOMPLETNOŚĆ
Celuj w MINIMUM 5,000–6,000 znaków na hasło. Jeśli hasło jest krótsze, rozbuduj:

1. **Podtypy klienckie:** Wymień WSZYSTKIE realne konfiguracje zakupowe,
   nie tylko główne. Jeśli produkt występuje w wersjach: rekreacyjny, twinset,
   stage, sidemount, travel, damski, dziecięcy — wymień każdą jako osobny podtyp.
   Klienci szukają "automat do sidemount", "jacket podróżny", "płetwy dla dzieci"
   — każdy podtyp to osobna ścieżka wyszukiwania.

2. **FAQ:** Minimum 5 pytań na hasło. Pytania bierz z PAA, autocomplete i
   typowych zapytań klientów w sklepie. Odpowiedzi 2-3 zdania, język klienta.

3. **Frazy long-tail:** Minimum 8 fraz na hasło. Zbieraj WSZYSTKIE relevantne
   frazy z autocomplete, PAA, all_keywords.csv — to paliwo do semantic search.

NIE lej wody. Każde dodane zdanie musi nieść nową informację.
NIE powtarzaj tej samej informacji w różnych sekcjach.
```

## KROK 2: Test 1 hasła — TYLKO AUTOMAT ODDECHOWY

Uruchom generację TYLKO dla jednego hasła. Opcje:

Opcja A (preferowana): Zmodyfikuj skrypt żeby akceptował --concepts override:
```bash
python3 scripts/generate_encyclopedia.py --phase test --model gemini --concepts "AUTOMAT ODDECHOWY / REGULATOR"
```

Opcja B (fallback): Tymczasowo zmień TEST_CONCEPTS w skrypcie na tylko 1 hasło,
uruchom, przywróć oryginał.

Opcja C (najprostsza): Uruchom pełny test 3 haseł ale tylko Gemini.
Czas ~80s, koszt ~$0.07. Jeśli opcje A/B wymagają za dużo zmian, idź w C.

## KROK 3: Zapisz wynik

Output: `data/encyclopedia/v3/test_v3/gemini_test_automat.md`
(lub `gemini_test_3_concepts.md` jeśli opcja C)

Raport: `data/encyclopedia/v3/test_v3/test_v3_report.md`
Zawrzyj: tokeny, czas, chars total, chars/hasło, porównanie z v2.

## CO MIERZY ARCHITEKT

Hasło AUTOMAT ODDECHOWY — porównanie Gemini v3 vs Claude Opus:

| Wymiar | Gemini v2 | Claude Opus | Cel Gemini v3 |
|--------|-----------|-------------|---------------|
| Podtypy klienckie | 2 | 5 | ≥4 |
| FAQ | 3 | 6 | ≥5 |
| Frazy long-tail | 6 | 12 | ≥8 |
| Chars/hasło | ~4,500 | ~10,883 | 5,500-7,000 |

Jeśli Gemini v3 trafi w te cele → batch 106 haseł.

## NIE RÓB

- Nie modyfikuj zasad #1-#19
- Nie zmieniaj model stringów
- Nie nadpisuj test/ ani test_v2/
- Nie uruchamiaj batch
