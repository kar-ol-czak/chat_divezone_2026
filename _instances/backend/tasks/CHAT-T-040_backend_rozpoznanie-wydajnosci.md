# CHAT-T-040 — BACKEND: Rozpoznanie wydajnosci czatu (45s na ture — co zzera czas)

**Data:** 2026-06-02
**Instancja:** backend
**Wejscie:** zgloszenie Karola — czat odpowiada ~45s na ture (zaobserwowane na PROD po wdrozeniu widgetu CHAT-T-037/038). Trzeba ustalic, CO zzera czas, ZANIM cokolwiek optymalizujemy.
**Charakter:** DIAGNOSTYKA, read-only. Zero zmian w kodzie/configu/danych.

---

## CO JUZ WIADOMO (z analizy kodu ChatService.php)
Tura czatu = PETLA NARZEDZIOWA do 5 iteracji (MAX_TOOL_ITERATIONS=5). Kazda iteracja = 1 wywolanie LLM. Jesli LLM uzywa narzedzia: LLM -> narzedzie -> LLM -> ... sekwencyjnie. ChatService SAM mierzy czasy i zapisuje je:
- `divechat_conversations.diagnostics` (lub kolumna z response_times) — timings: ai_ms, tool_ms, embedding_ms, total_ms per tura.
- `divechat_message_usage` — latency_ms PER wywolanie LLM + model_id + tokeny + tool_calls.
Czyli dane do diagnozy SA w bazie. Trzeba je odczytac, nie zgadywac.

## HIPOTEZY DO SPRAWDZENIA (rozstrzygnac danymi)
1. MODEL: jaki model_primary jest ustawiony (divechat_settings)? Jesli Opus na kazda iteracje petli -> 3-4x Opus = 30-40s. Sprawdz model_id w divechat_message_usage dla wolnych tur.
2. LICZBA ITERACJI: ile wywolan LLM przypada na ture (ile wierszy message_usage per conversation/tura)? Proste pytanie nie powinno robic 4 iteracji.
3. PODZIAL CZASU: ai_ms vs tool_ms vs embedding_ms w total_ms — gdzie realnie znika czas (LLM? narzedzia/MySQL? embedding/RAG?).
4. COLD START: czy pierwsze zapytanie po przerwie jest drastycznie wolniejsze (Railway usypia?) — porownaj latency_ms pierwszej tury po przerwie vs kolejnych.

## KROKI (read-only)
KROK 0 — git pull. Przeczytaj ChatService.php (petla narzedziowa), divechat_settings (model_primary/escalation), schemat divechat_conversations + divechat_message_usage (gdzie timings/latency).
KROK 1 — Ustawienia: SELECT aktualne model_primary, model_escalation, temperature, reasoning_effort z divechat_settings. Jaki model realnie obsluguje ture.
KROK 2 — Ostatnie ~20 realnych tur (dzisiejsze, po wdrozeniu widgetu): dla kazdej pokaz total_ms, ai_ms, tool_ms, embedding_ms, liczbe iteracji LLM (wierszy message_usage), uzyte narzedzia, model_id. Tabela posortowana po total_ms malejaco.
KROK 3 — Per-wywolanie LLM dla 3-5 najwolniejszych tur: latency_ms + model_id + input/output tokens + czy byl tool_call. Pokaze czy czas to LLM czy narzedzia, i czy to jeden wolny model czy mnozenie iteracji.
KROK 4 — Cold start: porownaj latency_ms pierwszego wywolania w sesji vs kolejnych (czy jest skok sugerujacy usypianie backendu/Railway).
KROK 5 — SYNTEZA: gdzie znika 45s (rozbicie procentowe ai/tool/embedding), ile iteracji srednio, jaki model. REKOMENDACJE optymalizacji (NIE wdrazaj — tylko propozycje do decyzji Karola): np. szybszy model na ture, ograniczenie iteracji, indeks wektorowy, keep-alive. Kazda z szacowanym zyskiem.

## OGRANICZENIA
- ZERO zmian: nie dotykaj divechat_settings, nie zmieniaj modelu, nie ruszaj kodu. Tylko SELECT + analiza.
- Jesli brak realnych tur z dzisiaj (widget dopiero co wdrozony, malo ruchu) — uzyj dowolnych ostatnich tur z divechat_conversations, zaznacz date.

## GIT
Brak zmian kodu. Raport w czacie. Jesli powstanie skrypt analityczny (read-only SQL) — opcjonalnie commit do standalone/scripts/ jako "CHAT-T-040: skrypt diagnostyki wydajnosci (read-only)", ale NIE wymagane. Handoff LOKALNY.

## RAPORT
Tabela ~20 tur (total/ai/tool/embedding/iteracje/model), rozbicie 3-5 najwolniejszych per-wywolanie, werdykt cold start, SYNTEZA: gdzie znika czas + ranking rekomendacji optymalizacji z szacowanym zyskiem. STOP na rekomendacjach — Karol decyduje co optymalizujemy.
