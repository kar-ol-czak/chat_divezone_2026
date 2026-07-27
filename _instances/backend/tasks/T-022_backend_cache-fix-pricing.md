# T-022: Cache fix OpenAI + migracja pricing gpt-5.5 (warstwa kosztow/modeli)

Instancja: backend
Powiazane: decyzja 101b (cache fix odlozony po Fazie 0 -- teraz realizujemy), analiza kosztow z sesji (input=91% kosztu, cache_read=0 w danych), ADR-051/052 (roster + divechat_model_pricing)
Priorytet: P1 (odblokowuje prawdziwe dane kosztowe -> decyzja o modelu produkcyjnym czatu)
Czas: ~2h CC
Pliki: standalone/src/AI/OpenAIProvider.php, standalone/src/AI/ModelPrice.php (lub gdzie wycena), sql/ (migracja pricing)

## Kontekst (diagnoza z sesji architekta)
W danych divechat_message_usage cache_read_tokens=0 dla WSZYSTKICH wierszy. Root cause zdiagnozowany:
- OpenAIProvider.php linia ~23 (komentarz) + ~198: cache_read_tokens=0 USTAWIONE NA SZTYWNO z bledna adnotacja 'OpenAI nie eksponuje cache_*_tokens'. To NIEAKTUALNE -- OpenAI eksponuje usage.prompt_tokens_details.cached_tokens od konca 2024, a cache OpenAI dziala AUTOMATYCZNIE dla promptow >1024 tok (nasz input sr. 34k -> kwalifikuje sie). Czyli cache prawdopodobnie DZIALA po stronie OpenAI, ale my go nie czytamy ani nie wyceniamy -> nasze raporty kosztu sa ZAWYZONE.
- ClaudeProvider.php: cache OK (cache_control ephemeral + czyta cache_creation_input_tokens). Ale Claude nieuzywany w produkcji (dane: tylko gpt-4.1 + gpt-5-mini).

## CZESC A -- weryfikacja empiryczna (KROK 1, NAJPIERW)
Zanim cokolwiek zmienisz: potwierdz ze OpenAI faktycznie zwraca niezerowy cached_tokens przy naszym profilu (stabilny system prompt na poczatku).
- KROK 1a: jednorazowy skrypt diagnostyczny (scripts/t022_cache_probe.php) -- wywolaj OpenAIProvider 2x pod rzad z tym samym system promptem (>1024 tok) i wypisz pelne $data['usage'] z drugiej odpowiedzi (surowy JSON).
- KROK 1b: sprawdz czy usage.prompt_tokens_details.cached_tokens > 0 przy drugim wywolaniu.
- STOP 1: pokaz wynik surowego usage. Jesli cached_tokens>0 -> idziemy dalej (fix oczywisty). Jesli =0 -> WAZNIEJSZE odkrycie: prefiks niestabilny (cos dynamicznego na poczatku promptu) -> diagnoza kolejnosci promptu w ChatService PRZED fixem. Czekaj na moja ocene.

## CZESC B -- fix odczytu cache (po STOP 1, jesli cached_tokens>0)
KROK 2: OpenAIProvider.php parsuje usage.prompt_tokens_details.cached_tokens -> cache_read_tokens (zamiast 0). UWAGA: OpenAI raportuje cached_tokens jako CZESC prompt_tokens (nie osobno), wiec input_tokens 'swieze' = prompt_tokens - cached_tokens. Zachowaj sumy spojnie z tym jak ClaudeProvider/ModelPrice je traktuje (Anthropic raportuje cache osobno -- sprawdz konwencje w ConversationCost zeby nie liczyc podwojnie).
KROK 3: wycena cache w ModelPrice dla OpenAI -- OpenAI cached input = 90% taniej dla rodzin gpt-5.4/5.5 (websearch potwierdzony), tabela divechat_model_pricing ma juz cache_read_price_per_million. Upewnij sie ze wycena uzywa tej kolumny dla cache_read_tokens.

## CZESC C -- migracja pricing gpt-5.5 (KROK 4)
WAZNE rozroznienie: divechat_model_pricing sluzy NASZEMU czatowi (PHP, wybor modelu + logowanie). Red-team harness (Promptfoo) ma WLASNE ceny w _redteam/configs. Wiec gpt-5.5 w tabeli potrzebny TYLKO jesli nasz czat ma go uzywac (np. jako model eskalacyjny). To czesc decyzji o modelu produkcyjnym (pytanie 100, odlozone). DODAJEMY wpis zeby tabela byla kompletna i gotowa, ALE bot go nie uzywa dopoki nie zdecydujemy.
KROK 4: migracja sql/0NN_pricing_gpt55.sql -- INSERT gpt-5.5 (input 5.0, output 30.0, cache_read 0.5 czyli 10% inputu, supports_reasoning_effort=t, is_active=t, is_escalation=f). Zweryfikuj tez czy gpt-5.4 w tabeli ma poprawne ceny (2.5/15) -- jesli nie, UPDATE. Sprawdz aktualne wartosci przed migracja (SELECT).
KROK 5: AIModel enum (jesli istnieje, np. standalone/src/AI/AIModel.php lub w AIProviderFactory) -- dodaj gpt-5.5 case TYLKO jesli enum jest zrodlem prawdy dla dostepnych modeli. Jesli tabela wystarcza, pomin (zaznacz w raporcie).

## KROK 6. Backfill (opcjonalny, do decyzji)
Historyczne wiersze divechat_message_usage maja cache_read=0 i zawyzony koszt. NIE backfillujemy (nie znamy realnego cache wstecz). Zaznacz w raporcie ze dane sprzed T-022 maja zawyzony koszt OpenAI; od T-022 beda realne.

## KROK 7. Lint + STOP 2
php -l na zmienionych plikach. Status READY FOR REVIEW. Pokaz: diff OpenAIProvider, migracje SQL, wynik probe z KROK 1. NIE deploy bez akceptacji.

## KROK 8. Deploy (po akceptacji)
scp OpenAIProvider.php + ModelPrice (jesli zmieniony), php -l prod, md5 verify. Migracja SQL: uruchom na Railway PG (psql DATABASE_URL < migracja). Smoke: jedna rozmowa testowa -> sprawdz w divechat_message_usage czy cache_read_tokens>0 i czy cost_total spadl.

## KROK 9. Git
git add standalone/src/AI/OpenAIProvider.php [ModelPrice.php] sql/0NN_pricing_gpt55.sql scripts/t022_cache_probe.php
commit: "T-022: cache fix OpenAI (czyta prompt_tokens_details.cached_tokens) + wycena cache + migracja pricing gpt-5.5 [101b]"
git push origin main. Osobny commit docs: status.

## KROK 10. Raport + status
_instances/backend/handoff/T-022_done.md: wynik probe (cached_tokens przed/po), realna oszczednosc cache (porownaj koszt rozmowy przed/po), czy gpt-5.5 dodany. Update _docs/21 (T-022 DEPLOYED). Osobny commit docs:.

## Out of scope
- Zmiana modelu produkcyjnego czatu na gpt-5.5/tanszy -> osobna decyzja (pytanie 100) po zobaczeniu realnych danych cache
- Optymalizacja kolejnosci promptu pod cache -> jesli STOP 1 pokaze ze prefiks niestabilny, osobny task
- Red-team scenariusze -> T-023