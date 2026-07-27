-- ============================================
-- DIVEZONE CHAT AI - Migracja 042
-- Fix knowledge_gap dla search_products: luka = BRAK WYNIKOW (CHAT-T-148, ADR-126 + nota nr 1)
-- Data: 2026-07-17
-- Decyzje Karola: 128b (regula: dla search_products gap = ZERO WYNIKOW, bez progu),
--                 129b (historie przeliczamy migracja SQL), 130b (zawezenie do
--                 rozmow z PELNA diagnostyka).
--
-- PROBLEM: buildSearchDiagnostic() stosowal jeden prog 0,5 do dwoch roznych skal.
-- Dla search_products pole `similarity` to `rrf_score` (sufit zmierzony ~0,123,
-- ADR-126) — prog 0,5 nieosiagalny → 237/237 rozmow z flaga true, filtr "Luki
-- wiedzy" w panelu nie znaczy nic. Dla get_expert_knowledge `similarity` to
-- prawdziwy cosine (0-1) i prog 0,5 dziala poprawnie — te sciezke zostawiamy.
--
-- NOWA REGULA (rozmowa ma knowledge_gap = true wtedy i tylko wtedy, gdy):
--   (a) istnieje wywolanie get_expert_knowledge z knowledge_gap=true  (stara regula, cosine), LUB
--   (b) istnieje wywolanie search_products z result_count=0            (nowa regula 128b).
-- To jest OR — rozmowa z trafionym produktem, ale nieodpowiedzianym pytaniem
-- encyklopedycznym, POZOSTAJE luka (i odwrotnie).
--
-- GRANICA — czego migracja NIE RUSZA (krytyczne, zasada: zero fabrykacji). Dwa
-- zbiory zostaja nietkniete, oba z tego samego powodu: nie da sie odtworzyc, czy
-- byla tam luka.
--   1. BRAK diagnostyki (94 rozmowy) — zabezpiecza `jsonb_array_length > 0`.
--   2. Diagnostyka NIEPELNA / MIGAWKA (86 rozmow) — ADR-126 nota nr 1, decyzja 130b.
--      `search_diagnostics` jest NADPISYWANY przy kazdej turze (migawka ostatniej),
--      a knowledge_gap jest STICKY. Rozmowa, gdzie tura 1 miala search_products bez
--      wynikow (flaga true), a tura 2 tylko encyklopedie z trafieniem, ma dzis w
--      diagnostyce WYLACZNIE ture 2. Przeliczenie z niej zgasiloby realny sygnal luki.
--      Warunek deterministyczny: dlugosc diagnostyki == liczba wywolan narzedzi
--      wyszukujacych w messages[].tool_calls[]. Bez niego migracja zgasilaby 80 flag
--      na niepewnych danych. UWAGA: messages ma format tool_calls[], NIE Anthropic
--      content[].type='tool_use'. COALESCE bo user/tool_result nie maja tego klucza.
--
-- SYMULACJA (Railway, 2026-07-17, dane produkcyjne — pokrywa sie z asercjami tasku):
--   scope migracji (pelna diagnostyka)  : 191
--   z tego true→false                    : 143
--   z tego false→true                    :   0
--   NIETKNIETE: niepelna diagnostyka     :  86
--   NIETKNIETE: brak diagnostyki         :  94
--   panel globalnie true: przed → po     : 339 → 196
--   kohorta search_products: true po     :  94  (14 pelna-diag + 80 niepelna-diag nietkniete)
-- ============================================

BEGIN;

UPDATE divechat_conversations c
SET knowledge_gap = (
    EXISTS (
        SELECT 1
        FROM jsonb_array_elements(c.search_diagnostics) AS e
        WHERE e->>'tool' = 'get_expert_knowledge'
          AND (e->>'knowledge_gap')::boolean = true
    )
    OR EXISTS (
        SELECT 1
        FROM jsonb_array_elements(c.search_diagnostics) AS e
        WHERE e->>'tool' = 'search_products'
          AND (e->>'result_count')::int = 0
    )
)
WHERE c.search_diagnostics IS NOT NULL
  AND jsonb_array_length(c.search_diagnostics) > 0
  -- Zawezenie 130b: tylko rozmowy, ktorych diagnostyka pokrywa CALA historie wywolan
  -- (nie jest migawka ostatniej tury). Chroni 86 rozmow / 80 flag na niepewnych danych.
  AND jsonb_array_length(c.search_diagnostics) = (
      SELECT count(*)
      FROM jsonb_array_elements(c.messages) AS m,
           jsonb_array_elements(COALESCE(m->'tool_calls', '[]'::jsonb)) AS tc
      WHERE tc->>'name' IN ('search_products', 'get_expert_knowledge')
  );

COMMIT;
