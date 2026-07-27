# CHAT-T-041 — BACKEND: Fala 1 optymalizacji wydajnosci czatu (bezpieczne, bez zmiany modelu)

**Data:** 2026-06-02
**Instancja:** backend
**Wejscie:** CHAT-T-040 (diagnostyka: ~95-100% czasu = LLM gpt-5-mini z reasoning; czas proporcjonalny do output_tokens ~50-70 t/s; max_tokens=4096; reasoning_effort=low). Decyzja Karola 81a (Fala 1 teraz, bez zmiany modelu — to osobna walidowana decyzja), 83a (umiarkowany limit).
**Charakter:** bezpieczne, niskie ryzyko. ZERO zmiany modelu (to Fala 2). Zysk: zbic najgorsze przypadki (25-37s) i p95 (~19s).

---

## USTALENIA Z DIAGNOSTYKI (CHAT-T-040)
- Czas znika w LLM (ai_ms ~95-100%), nie w narzedziach/cold starcie.
- Glowny mnoznik: dlugie odpowiedzi (output 1500-2000 tok = 25-37s).
- SystemPrompt JUZ ma regule zwiezlosci (linie ~695-698), ale gpt-5-mini jej nie respektuje wystarczajaco -> potrzebny TWARDY limit max_tokens, nie tylko instrukcja.
- embedding_ms=0 wszedzie (licznik niepodpiety — embedding liczy sie w ProductSearch, czas wpada do tool_ms).

## ZMIANY (Fala 1)

### 1. max_tokens 4096 -> 1500 (decyzja 83a, umiarkowany)
- Gdzie: divechat_settings (klucz max_tokens) jesli stamtad czytane, LUB miejsce gdzie ustawiany limit dla providera (sprawdz: SettingsStore / Config / OpenAIProvider). Ustal zrodlo prawdy max_tokens i zmien na 1500.
- 1500 tok ~ 1000 slow — dosc na rekomendacje z uzasadnieniem, ucina patologiczne 2000-tok odpowiedzi. NIE schodzic do 1024 (ryzyko uciecia uzasadnien doboru).

### 2. reasoning_effort low -> minimal (jesli OpenAI wspiera na gpt-5-mini)
- Gdzie: divechat_settings (reasoning_effort). NAJPIERW potwierdz ze gpt-5-mini akceptuje 'minimal' (API docs / test 1 wywolania). Jesli NIE wspiera 'minimal' — zostaw 'low', zaznacz w raporcie.
- Zysk 1-3s/wywolanie.

### 3. Wzmocnienie istniejacej reguly zwiezlosci w SystemPrompt (NIE nowa regula)
- Sekcja ~linia 695-698 ("Odpowiadaj zwiezle..."). Zaostrzyc, NIE duplikowac. Dodac konkret: domyslnie odpowiedz mpiesci sie w kilku zdaniach / krotkim akapicie; pelne uzasadnienie doboru max ~150-200 slow; rozwiniecie oferuj ("chcesz wiecej szczegolow?"), nie wrzucaj naraz.
- NIE ruszac innych sekcji promptu (polityki bezpieczenstwa, INT/yoke, jezyk, knowledge gap). Minimalna, chirurgiczna zmiana tylko w sekcji zwiezlosci.

### 4. Fix embedding_ms (czysta diagnostyka, zero ryzyka)
- ChatService::handle inicjalizuje $timings['embedding_ms']=0 ale nigdzie nie dodaje. Embedding liczy sie w ProductSearch::execute, czas wpada do tool_ms. Przekaz realny embedding_ms z toola do timings (zeby przyszla diagnostyka rozdzielala RAG od reszty narzedzi). Jesli wymaga zmiany sygnatury toola — opisac, ewentualnie pominac jako osobny drobiazg (nie blokuje Fali 1).

## OGRANICZENIA
- ZERO zmiany modelu (model_primary zostaje gpt-5-mini). Zmiana modelu = Fala 2, osobna decyzja po walidacji jakosci (82b: ocena na ~15 realnych pytaniach).
- Zmiany w divechat_settings: pokaz wartosci PRZED i PO (rollback trywialny — UPDATE z powrotem).
- SystemPrompt: minimalna zmiana, tylko sekcja zwiezlosci. Pokaz diff.

## TEST (po zmianach)
- Odpal 3-5 realnych pytan (dobor automatu, komputera, pytanie o wysylke, kompatybilnosc) przez /api/chat/stream lub bezposrednio ChatService. Zmierz total_ms / output_tokens PRZED (z CHAT-T-040 baseline) vs PO.
- Sprawdz ze odpowiedzi NIE sa uciete w polowie (max_tokens=1500 wystarcza na uzasadnienie). Jesli ucinane — zaraportuj, rozwazymy 1800.
- Potwierdz ze jakosc merytoryczna nie ucierpiala wizualnie (nie pelna ewaluacja — ta przy Fali 2/zmianie modelu).

## GIT
- git add: divechat_settings to DANE (zmiana przez SQL/skrypt — jesli powstanie migracja/skrypt, commit; jesli czysty UPDATE, opisz w raporcie). standalone/src/Chat/SystemPrompt.php (sekcja zwiezlosci). standalone/src/Chat/ChatService.php (+ ew. tool) jesli embedding_ms.
- commit "CHAT-T-041: Fala 1 wydajnosci — max_tokens 1500, reasoning minimal, zaostrzona zwiezlosc, fix embedding_ms"
- push. docs: commit ze statusem. Handoff LOKALNY.

## RAPORT
Wartosci settings przed/po, diff sekcji promptu, czy reasoning 'minimal' wspierany, wynik testu (total_ms/output PRZED vs PO na 3-5 pytaniach), czy odpowiedzi nieuciete. STOP — Karol oceni czy Fala 1 wystarcza, czy wchodzimy w Fale 2 (model).
