# Oryginalny prompt do OpenAI/Gemini (WADLIWY)
# Plik: prompty_research_encyklopedia.md, wersja 2.0
# Ten prompt ZAKŁADAŁ w pytaniach że odpowiedź to LLM pipeline.
# Kluczowy błąd: pytania prowadzące ("jak zaprojektować pipeline który syntetyzuje",
# "jakie LLM-owe podejście", "jakie narzędzia: LangChain, LlamaIndex, modele")
# Nigdy nie padło pytanie: "czy w ogóle potrzebuję LLM do tego?"
#
# Pełna treść poniżej:
# ---

# Prompty do konsultacji: architektura encyklopedii nurkowej
# Wersja 2.0 — po review

## Kontekst wspólny (wklej na początku obu promptów)

Jestem właścicielem największego polskiego sklepu internetowego ze sprzętem nurkowym (divezone.pl, ~2600 produktów). Buduję system AI chat dla klientów z wyszukiwaniem semantycznym (pgvector, embeddingi). 

### Problem
Mam bazę wiedzy z wielu źródeł. Chcę z niej zbudować:
1. **TERAZ (MVP):** ustrukturyzowaną encyklopedię sprzętową (~40 kategorii) jako referencję dla AI generującego synonimy produktowe.
2. **DOCELOWO:** własną encyklopedię nurkowania po polsku.

**Output będzie używany przez AI jako słownik referencyjny do mapowania zapytań klientów na kategorie/cechy produktów.**

### Przykłady realnych błędów AI (bez encyklopedii)
- "szpulka" wrzucona jako synonim kołowrotka (to dwa różne produkty)
- "oddechówka" wymyślona jako synonim automatu (termin nie istnieje)

### KLUCZOWE: Pytania które poszły do OpenAI Deep Research

1. "Jak zaprojektować **pipeline przetwarzania** który **syntetyzuje** wiedzę z wielu źródeł?"
2. "Jak zunifikować **chunking** heterogenicznych źródeł?"
3. "Jak **połączyć** fragment blogowy z technicznym? Jakie **LLM-owe** podejście minimalizuje halucynacje?"
4. "Jakie **narzędzia** (LangChain, LlamaIndex, custom Python) i **modele** (Claude, GPT-4o, Gemini) rekomendujecie?"

Wymagany format odpowiedzi: "Rekomendowana architektura", "Diagram etapów pipeline", "Stack narzędzi i modeli"

### Pytania które poszły do Gemini 3.1 Pro

1. "Czy realistyczne jest wrzucenie CAŁEGO korpusu i poproszenie o **wygenerowanie** encyklopedii w jednym przebiegu?"
2. "Gemini jako **indeksator**: czyta cały korpus i produkuje indeks, Claude **przetwarza** per temat?"
3. "Porównaj: Gemini cały korpus → jedna sesja, Claude per kategoria, Hybryda Gemini+Claude"

### Co oba modele odpowiedziały (streszczenie):

Oba zaprojektowały LLM pipeline z wieloma warstwami. Bo o to pytaliśmy.
Nikt nie powiedział: "Większość tego zadania to deterministyczna transformacja danych."

### Pełny prompt (260 linii) jest dostępny w pliku prompty_research_encyklopedia.md
### Pełne odpowiedzi OpenAI i Gemini są w _docs/20_synteza_encyklopedia_openai_vs_gemini.md
