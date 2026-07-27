# TASK-ENC-008a: Powtórka testu z prawidłowymi modelami
# Data: 2026-03-05
# Status: DO ZROBIENIA
# Instancja: embeddings
# Kontekst: Test z TASK-ENC-008 użył ZŁYCH modeli (2.5 Pro, Sonnet 4, GPT-4.1)

---

## CO SIĘ STAŁO

Pierwszy test (w test_old_wrong_models/) użył:
- gemini-2.5-pro zamiast gemini-3.1-pro-preview
- claude-sonnet-4-20250514 zamiast claude-opus-4-6
- gpt-4.1 zamiast gpt-5.2

## CO ZOSTAŁO POPRAWIONE (przez architekta, zweryfikuj!)

W `scripts/generate_encyclopedia.py` poprawiono 5 miejsc:
1. MODELS dict (linia ~57): model stringi na prawidłowe
2. call_gemini() default: gemini-3.1-pro-preview
3. call_claude() default: claude-opus-4-6
4. call_openai() default: gpt-5.2
5. Stare wyniki przeniesione do test_old_wrong_models/

## CO ZROBIĆ

1. Zweryfikuj poprawki w skrypcie (grep po starych model stringach, nie powinno być żadnych)
2. Sprawdź czy endpoint Gemini 3.1 działa z Google AI Studio key (nie Vertex)
3. Uruchom test: `python3 scripts/generate_encyclopedia.py --phase test`
4. Wyniki do `data/encyclopedia/v3/test/`
5. Raport porównawczy z kosztami i tokenami

## UWAGA NA ENDPOINT GEMINI

Google AI Studio endpoint dla 3.1:
```
https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-pro-preview:generateContent
```
Jeśli zwróci 404, spróbuj: `gemini-3.1-pro` (bez -preview).
