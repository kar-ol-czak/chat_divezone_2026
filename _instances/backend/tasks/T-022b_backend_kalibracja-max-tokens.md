# T-022b: Kalibracja AI_MAX_TOKENS (cache hit dla modeli reasoning)

Instancja: backend
Powiazane: T-022 (cache fix DEPLOYED), finding empiryczny: AI_MAX_TOKENS=50 -> cache 95.6%, =4096 (default) -> cache 0 dla gpt-5-mini
Priorytet: P1 (KRYTYCZNE -- bez tego cache fix z T-022 NIC NIE DAJE na produkcji, bo prod uzywa domyslnego 4096)
Czas: ~2h CC
Pliki: .env.example, standalone/src/AI/OpenAIProvider.php (jesli max_tokens param), ChatService (jesli stamtad przekazywany)

## Kontekst
T-022 naprawil ODCZYT cache, ale finding pokazal paradoks: przy AI_MAX_TOKENS=50 cache hit 95.6%, przy default 4096 cache hit 0 dla gpt-5-mini. Prod uzywa 4096 -> realna oszczednosc cache = ZERO mimo wdrozonego fixa. Trzeba zrozumiec MECHANIZM zanim ustawimy wartosc, bo 'max_tokens blokuje cache' to korelacja, nie potwierdzona przyczyna.

## CZESC A -- DIAGNOZA MECHANIZMU (KROK 1, NAJPIERW, nie zgaduj)
Hipotezy do sprawdzenia (gpt-5-mini to model REASONING):
- H1: reasoning_tokens wliczaja sie do completion. Przy duzym max_tokens model robi WIECEJ reasoningu -> dluzsza/inna odpowiedz -> ale to nie powinno ruszac cache INPUT. Sprawdzic czy to w ogole o cache, czy o cos innego.
- H2: OpenAI Responses API vs Chat Completions -- czy uzywamy Responses (gpt-5.5 guide zaleca Responses dla reasoning)? Cache na Responses moze zachowywac sie inaczej. Sprawdzic ktore API wola OpenAIProvider.
- H3: czy przy max_tokens=4096 i reasoning odpowiedz sie URYWA (finish_reason=length) -> rozmowa multi-turn dostaje uciety output -> NASTEPNA tura ma inny prefiks -> cache miss w kolejnych turach? To bylby problem wielotorowy, nie pojedynczego calla.
- H4: czy to artefakt probe (2 calle z identycznym promptem) vs realna rozmowa (rozne tury)? Finding 95.6% byl na deployed smoke -- z jakim promptem?
KROK 1: rozszerz scripts/t022_cache_probe.php (lub nowy t022b_probe.php) -- ten sam system prompt, 2 calle, ale TESTUJ macierz: max_tokens in {50, 512, 1024, 2048, 4096} x sprawdz cached_tokens + finish_reason + reasoning_tokens dla kazdego. Wypisz tabele.
STOP 1: pokaz tabele (max_tokens -> cached_tokens, finish_reason, reasoning_tokens, completion_tokens). Z tego zobaczymy CZY to monotoniczne (im wiecej max, tym mniej cache) czy progowe, i czy to reasoning zjada limit. Czekaj na moja interpretacje PRZED wyborem wartosci.

## CZESC B -- rozwiazanie (po STOP 1, zalezne od diagnozy)
Mozliwe kierunki (wybierzemy po danych):
- Jesli H3 (urywanie): zwiekszyc max_tokens DOSC by reasoning+odpowiedz sie zmiescily (paradoksalnie WIEKSZY limit naprawia), albo oddzielic reasoning budget.
- Jesli problem to przekazywanie stalego 4096 niezaleznie od modelu: max_tokens dynamiczny per model/per tura z UI (juz jest dual-control reasoning UI z ADR-052 -- moze podpiac).
- Jesli czysto konfiguracyjne: ustawic AI_MAX_TOKENS na zwalidowana wartosc w .env (prod) + .env.example + dokumentacja czemu.
KROK 2: implementacja wybranego kierunku (po akceptacji). NIE ruszaj live .env sam -- przygotuj wartosc + ja ustawie, LUB jesli to kod (dynamiczny max) to w OpenAIProvider/ChatService.

## KROK 3. Walidacja
Po zmianie: realna rozmowa multi-turn (3-5 tur) na DEV -> sprawdz w divechat_message_usage czy cache_read_tokens>0 w turach 2+ ORAZ czy odpowiedzi nie sa uciete (finish_reason != length, output kompletny). Cache I jakosc jednoczesnie.

## KROK 4. STOP 2 + deploy
Pokaz wynik walidacji (cache hit % w realnej rozmowie + brak uciec). Po akceptacji: jesli kod -> scp+lint+md5; jesli .env -> podaj mi wartosc do ustawienia na prod.

## KROK 5. Git + status
git add zmienione pliki (NIE live .env). commit 'T-022b: kalibracja AI_MAX_TOKENS dla cache hit modeli reasoning'. push. Osobny commit docs: status (T-022b DEPLOYED + realna oszczednosc cache potwierdzona w rozmowie multi-turn).

## Out of scope
- T-022c drift logging (osobny, P2)
- Zmiana modelu produkcyjnego (pytanie 100)
- Optymalizacja kolejnosci promptu pod cache (jesli wyjdzie przy diagnozie -> osobny task)