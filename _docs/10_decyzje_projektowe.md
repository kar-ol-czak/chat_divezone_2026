# Log decyzji projektowych (ADR)
# Wersja: 1.0 | Data: 2026-02-18

---

### ADR-001 | 2026-02-18 | Forma implementacji
**Decyzja:** Moduł PrestaShop (nie osobny skrypt)
**Powód:** Dostęp do ORM PrestaShop, automatyczne hookowanie w footer, łatwa instalacja/deinstalacja.

### ADR-002 | 2026-02-18 | Historia rozmów
**Decyzja:** Zapisywana w bazie PostgreSQL (z polityką retencji RODO)
**Powód:** Możliwość analizy rozmów, identyfikacji luk w wiedzy, optymalizacji.

### ADR-003 | 2026-02-18 | Autoryzacja zamówień
**Decyzja:** Zalogowany = automatyczna identyfikacja, niezalogowany = nr zamówienia + email
**Powód:** Wygoda dla zalogowanych, bezpieczeństwo dla niezalogowanych.

### ADR-004 | 2026-02-18 | Baza wektorowa
**Decyzja:** pgvector (PostgreSQL)
**Status:** Oczekuje na potwierdzenie hostingu
**Powód:** Natywne SQL, hybrydowe wyszukiwanie w jednym zapytaniu, integracja PHP przez PDO, brak dodatkowego serwisu.
**Plan B:** Qdrant binary, SQLite-vss, lub osobny VPS.

### ADR-005 | 2026-02-18 | Wyszukiwanie semantyczne od MVP
**Decyzja:** Wyszukiwanie hybrydowe (wektorowe + SQL) od startu, nie w fazie 2
**Powód:** Poprzedni projekt (6 miesięcy temu) poległ na wyszukiwaniu czysto SQL-owym. Proste function calling z SQL nie radzi sobie z naturalnymi zapytaniami klientów.

### ADR-006 | 2026-02-18 | Wiedza ekspercka
**Decyzja:** Dynamiczna, z bazy wektorowej (nie w statycznym system prompt)
**Powód:** Skalowalność, łatwość dodawania, niższe koszty tokenów.

### ADR-007 | 2026-02-18 | Model embeddingów
**Decyzja:** Do przetestowania: OpenAI text-embedding-3-large vs small
**Status:** Oczekuje na test na realnych danych (100 produktów + 20-30 zapytań)
**Powód:** Potrzebna ewaluacja na polskojęzycznych danych o sprzęcie nurkowym.

### ADR-008 | 2026-02-18 | Model AI do czatu
**Decyzja:** Do przetestowania: Claude Sonnet 4 vs GPT-4o
**Status:** Oczekuje na test
**Powód:** Oba mają dobre function calling, porównanie na realnych scenariuszach doradczych.


### ADR-009 | 2026-02-19 | Infrastruktura bazy wektorowej
**Decyzja:** Neon (managed PostgreSQL + pgvector) zamiast lokalnego PG na serwerze
**Powód:** Serwer divezone.pl ma PostgreSQL 10 (system operacyjny nie obsługuje nowszych wersji). Upgrade wymagałby migracji całego serwera na nowy OS (+ ryzyko kompatybilności PS 1.7.6 z PHP 8.0). Neon daje managed PG 16 z pgvector, free tier (0.5 GB), datacenter Frankfurt, zero administracji.
**Koszt:** $0 na start (free tier), ewentualne $19/mies (Launch) jeśli przekroczymy limity.
**Plan B:** Supabase ($0 free tier), Railway (~$5/mies), lub VPS Hetzner (€4.5/mies) z ręczną instalacją PG 16.
**Ryzyko:** Scale-to-zero dodaje ~1-2s cold start na pierwszym requeście po idle. Akceptowalne przy czacie AI.


### ADR-009a | 2026-02-19 | Infrastruktura bazy wektorowej (aktualizacja)
**Decyzja:** Aiven Developer ($5/mies, pokryty z $300 kredytów startowych)
**Dane połączenia:**
- Host: <AIVEN_HOST_REDACTED>
- Port: 22367
- Database: defaultdb
- User: avnadmin
- SSL: require
- pgvector: 0.8.1
- PG: 17.8
- Region: DigitalOcean (przydzielony przez Aiven)
- Connection limit: 20
**Status:** Baza aktywna, pgvector 0.8.1 zainstalowany. Port 22367 otwarty na VPS divezone.pl (whitelist IP: 159.223.235.232). Połączenie przetestowane z Maca (psql) i VPS.


---

### ADR-010: Baza wiedzy eksperckiej - źródła i pipeline (2026-02-19)
**Status:** Zaplanowane (pełna implementacja po MVP produktowym)
**Cel:** Najlepsza na świecie baza wiedzy o sprzęcie nurkowym i nurkowaniu.
**Kontekst:** Blog divezone.pl działa na WordPress (kilkadziesiąt postów), wyświetlany w PrestaShop przez moduł. Dane blogowe pobieramy eksportem XML z WP, nie z tabel PS.
**Źródła wiedzy (priorytet wg jakości):**
1. Własny blog divezone.pl (WordPress, eksport XML)
2. Podręczniki nurkowe (PDF, dostarcza Karol)
3. Encyklopedia nurkowania nurkomania.pl (scraping)
4. Blogi sklepów i instruktorów (nautica.pl, nurkowo.pl, nurekamator.pl, jollydiver.pl)
5. Fora nurkowe (forum-nuras.com, scubaboard.com)
6. YouTube: recenzje sprzętu, poradniki (transkrypcja Whisper/youtube-transcript-api)
**Narzędzia:** Tavily API do wyszukiwania i ekstrakcji, BeautifulSoup do scrapingu, Whisper/youtube-transcript-api do transkrypcji.
**Pipeline:** Ekstrakcja -> czyszczenie -> chunking -> embedding -> divechat_knowledge z chunk_type ('blog', 'textbook', 'encyclopedia', 'forum_post', 'video_transcript') i source_url.
**Uwaga prawna:** Treści służą wyłącznie jako wewnętrzna baza wiedzy AI. Czat parafrazuje i doradza na podstawie wiedzy, nie reprodukuje treści dosłownie.


---

### ADR-011: Zmiana wymiaru wektora z 3072 na 1536 (2026-02-19)
**Status:** Zaakceptowane
**Kontekst:** pgvector 0.8.1 na Aiven ma limit 2000 wymiarów dla indeksów HNSW. Max wersja pgvector na Aiven to 0.8.1, niezależnie od planu. Oryginalny schemat zakładał vector(3072) dla text-embedding-3-large.
**Decyzja:** text-embedding-3-large z parametrem API dimensions=1536. Model trenowany techniką Matryoshka, więc 1536 dim zachowuje >98% jakości pełnych 3072. Rozważone i odrzucone alternatywy: Qdrant Cloud, Pinecone (dodatkowa złożoność za marginalny zysk przy 3000 produktach).
**Migracja:** Jeśli Aiven kiedyś podniesie pgvector, wystarczy przeembedować z dimensions=3072 i ALTER COLUMN.

---

### ADR-012: Model embeddingów: OpenAI text-embedding-3-large z dimensions=1536 (2026-02-19)
**Status:** Zaakceptowane (potwierdzony finalnie 2026-02-20, brak lepszej alternatywy na rynku)
**Kontekst:** Klucz OpenAI pierwotnie nie miał dostępu do embeddingów (wygenerowano nowy). TASK-001 tymczasowo użył Gemini embedding-001. Po analizie kosztów i jakości wracamy do OpenAI.
**Decyzja:** OpenAI text-embedding-3-large z parametrem dimensions=1536. Łączy najwyższą jakość modelu large (lepszy dla języków nie-angielskich) z wymiarem 1536 kompatybilnym z limitem HNSW na pgvector 0.8.1.
**Koszty:** ~$0.10 jednorazowo (batch, 3000 produktów), ~$0.06/mies runtime. Grosze.
**Odrzucone alternatywy:**
- text-embedding-3-small (1536 native): tańszy, ale gorsza jakość dla polskiego
- text-embedding-3-large (3072 native): nie przejdzie przez HNSW limit 2000 dim
- Gemini embedding-001: działa, darmowy, ale vendor lock-in i nieznane limity rate
**Test A/B:** Przed pełnym wgraniem zrobić test na 200-300 produktach: large(dim=1536) vs small(1536) na tych samych zapytaniach. Jeśli różnica <5% trafności, zostać przy small (tańszy). Jeśli >5%, large.

### ADR-013: Dynamiczny dobór produktów i mapa marek (2026-02-20)
**Status:** Zatwierdzony
**Kontekst:** Baza wiedzy (Q&A) nie powinna zawierać nazw konkretnych produktów. Produkty dobierane dynamicznie przez function calling na podstawie intencji klienta.

**Architektura dwuwarstwowa:**
1. **Warstwa wiedzy** (divechat_knowledge): jak dobrać maskę, na co zwracać uwagę, różnice między typami. BEZ nazw produktów.
2. **Warstwa produktowa** (function calling -> divechat_product_embeddings + MySQL PS): konkretne rekomendacje z ceną, zdjęciem, linkiem.

**Strategie doboru (rozpoznawane z intencji klienta przez AI):**
- BESTSELLER: "najlepsza maska" -> ORDER BY sold_quantity DESC, in_stock preferowane
- BUDGET: "tania maska" -> in_stock=true, ORDER BY price ASC
- RANGE: "jaka maska" (ogólne) -> 3 produkty: budget + mid + premium, in_stock preferowane
- SEMANTIC: "maska do freedivingu" -> embedding similarity + category filter
- SPECIFIC: "maska Scubapro Crystal Vu" -> exact match po nazwie/cechach

**Dostępność (z PrestaShop):**
- quantity > 0 = "od ręki" (preferowane w rekomendacjach)
- quantity = 0 AND out_of_stock IN (1,2) = "na zamówienie" (pokazuj z info z available_later)
- quantity = 0 AND out_of_stock = 0 = "niedostępny" (nie proponuj)
- Kategorie typu suche skafandry: większość na zamówienie, czat informuje o tym proaktywnie.

**Bestsellery:** Dane z pr_order_detail + pr_orders (valid=1). Kolumna sold_quantity w divechat_product_embeddings, aktualizowana periodycznie (cron/manual). Okres: ostatnie 12 miesięcy, rolling.

**Mapa marek - dwa mechanizmy:**
1. AUTOMAT (hard block): AI NIGDY nie wymienia marek spoza aktywnych w sklepie. Generowane z pr_manufacturer JOIN pr_product (active=1). Lista w system prompcie.
2. REKOMENDACJE (soft, konfigurowalny): JSON w ustawieniach modułu PS, edytowalny w panelu admina. Format: {category_id: [preferred_brand_ids]}. Gdy AI rekomenduje produkty z danej kategorii, preferuje marki z tej listy. Przykład: automaty -> [Apeks, Scubapro, Atomic, Aqualung, Tecline, XDeep].

**Pytania doprecyzowujące:** AI zadaje pytania gdy zapytanie zbyt ogólne:
- "Do jakiego nurkowania? (rekreacyjne/techniczne)"
- "Jaki budżet mniej więcej?"
- "Zimne czy ciepłe wody?"
- "Początkujący czy zaawansowany?"
Logika: jeśli zapytanie nie pozwala zawęzić do <10 produktów, AI pyta. Max 2 pytania doprecyzowujące, potem rekomenduje.

**Implementacja:** Function search_products(query, strategy, filters) w module PHP. Filters: category, price_range, in_stock, brand. Strategy wpływa na ORDER BY i limit.

### ADR-014: Konfigurowalny provider AI (2026-02-20)
**Status:** Zastąpiony przez ADR-020 (multi-model routing z eskalacją)
**Decyzja:** Moduł obsługuje wiele providerów AI (Anthropic Claude, OpenAI GPT). Wybór modelu w konfiguracji modułu (panel admina PrestaShop). Klasa abstrakcyjna DiveChatAIProvider z implementacjami DiveChatClaudeProvider i DiveChatOpenAIProvider.
**Modele startowe:** claude-sonnet-4, claude-sonnet-4.5, gpt-4.1, gpt-4o.
**Parametry konfigurowalne:** model, temperature, max_tokens. Extended thinking (Claude) i reasoning_effort (OpenAI) sterowane warunkowo w zależności od złożoności pytania.
**PHP 7.2:** Brak typed properties, brak arrow functions. Type hints tylko w parametrach metod i return types.
**Środowisko dev:** dev.divezone.pl (kopia produkcji).

### ADR-015: Bestsellery i sold_quantity (2026-02-20)
**Status:** Zatwierdzony
**Źródło danych:** pr_order_detail JOIN pr_orders (valid=1), ostatnie 12 miesięcy rolling.
**Implementacja:** Kolumna sold_quantity w divechat_product_embeddings, aktualizowana przez cron (embeddings pipeline) lub ręcznie. Używana w strategii BESTSELLER (ADR-013).

### ADR-016: Architektura hybrydowa - moduł PS (cienki) + standalone API (2026-02-20)
**Status:** Zatwierdzony
**Kontekst:** PS 1.7.6 wymusza PHP 7.2. Standalone na subdomenie pozwala na PHP 8.4.
**Decyzja:** Dwa komponenty:

**1. Moduł PrestaShop (modules/divezone_chat/, PHP 7.2, ~100 linii)**
- hook displayFooter: wstrzykuje widget JS + kontekst klienta (customer_id, HMAC token)
- getContent(): iframe z chat.divezone.pl/admin?token=HMAC(employee_id, timestamp, secret)
- install/uninstall: rejestracja hooków, zapis shared_secret w Configuration
- Konfiguracja minimalna: DIVECHAT_API_URL (default: https://chat.divezone.pl), DIVECHAT_SECRET

**2. Standalone API (chat.divezone.pl, PHP 8.4, Composer + PSR-4)**
- Cała logika: ChatService, AIProviders, Tools, pgvector, MySQL PS (read-only)
- Panel admina (chat.divezone.pl/admin): konfiguracja modelu, temperature, system prompt, mapa marek, podgląd rozmów, statystyki
- Endpoint: POST chat.divezone.pl/api/chat
- Composer: guzzlehttp/guzzle, vlucas/phpdotenv, monolog/monolog

**Autentykacja (widget -> API):**
- Moduł PS generuje: token = HMAC-SHA256(customer_id + ":" + timestamp, shared_secret)
- Widget JS wysyła: header X-DiveChat-Token: {token}, X-DiveChat-Customer: {customer_id}, X-DiveChat-Time: {timestamp}
- Standalone weryfikuje HMAC, sprawdza timestamp (max 5 min drift)
- Niezalogowany klient: customer_id = 0, token nadal wymagany (chroni przed botami)
- Dodatkowa ochrona: CORS allow-origin tylko divezone.pl i dev.divezone.pl

**Autentykacja (admin panel):**
- Moduł PS getContent() generuje: token = HMAC-SHA256(employee_id + ":" + timestamp, shared_secret)
- iframe src: chat.divezone.pl/admin?token={token}&employee={id}&ts={timestamp}
- Standalone weryfikuje HMAC, wyświetla panel admina
- Sesja admina: cookie na chat.divezone.pl, TTL 8h

**Deployment:**
- Moduł PS: rsync/scp do /var/www/divezone.pl/modules/divezone_chat/
- Standalone: rsync/scp do /var/www/chat.divezone.pl/ (lub analogiczny docroot)
- Oba repozytoria w jednym git repo (monorepo), osobne katalogi

**Zalety:**
- PHP 8.4: typed properties, enums, named args, match, fibers, nowoczesne biblioteki
- Niezależny deploy (standalone nie wymaga reinstalacji modułu)
- Łatwe testowanie standalone w izolacji (curl)
- Panel admina z autentykacją PS bez dodatkowego loginu
- Moduł PS minimalny, mało kodu = mało bugów

**Wady:**
- Dwa komponenty do deployowania (ale ten sam serwer)
- Shared secret musi być zsynchronizowany

### ADR-017: Reuse ze starego projektu chat.divezone.pl (2026-02-20)
**Status:** Zatwierdzony
**Kontekst:** Istnieje stary projekt czatu (Chat na Divezone/) z działającym backendem PHP. Architektura: god class ChatApp, MySQL only, GPT-4o-mini bez function calling, keyword matching na produktach.
**Decyzja:** Nie kopiujemy kodu. Bierzemy 3 koncepty:
1. **Detekcja kontekstu strony** (parsePageContext): widget JS wysyła aktualny URL, backend rozpoznaje typ strony (produkt/kategoria/producent/home) i wzbogaca kontekst dla AI. Implementacja w standalone jako middleware.
2. **Kontekstowe sugestie**: widget wyświetla podpowiedzi dopasowane do strony (np. na stronie masek: "Jak dobrać maskę?", "Jaka maska z korekcją?"). Dane z bazy lub config.
3. **Widget serving**: standalone serwuje chat.js z wstrzykniętą konfiguracją.
**UWAGA BEZPIECZEŃSTWA:** Stary config.php zawiera hardkodowane hasła i klucze API. Do zrotowania: klucz OpenAI, hasło MySQL divezone_sklep_tmp2, hasło MySQL divezone_chat_usr, klucz PrestaShop API.

### ADR-019: Migracja bazy z Aiven na Railway (2026-02-20)
**Status:** ✅ ZAKOŃCZONA (2026-02-20)
**Kontekst:** IP serwera Aiven (159.223.235.232, DigitalOcean) jest na blackliście AbuseIPDB. Hosting divezone.pl blokuje ruch wychodzący do tego IP. Aiven nie oferuje zmiany IP/regionu. Problem niezależny od nas.
**Decyzja:** Migracja do Railway (pgvector-pg18 template). PG 18.2, pgvector 0.8.1, infrastruktura GCP (czyste IP).
**Nowe dane połączenia:**
- Host: switchback.proxy.rlwy.net
- Port: 14368
- Database: railway
- User: postgres
- Password: <RAILWAY_PASSWORD_REDACTED>
- pgvector: 0.8.1 | PG: 18.2
- IP: 66.33.22.230 (czyste, brak wpisów w AbuseIPDB)
- **SSL: sslmode=disable** (Railway proxy nie obsługuje SSL)
**Koszt:** Hobby plan $5/mies z $5 kredytów (efektywnie darmowe przy niskim usage). Aiven: $5/mies z $300 kredytów (ale bezużyteczne z powodu blokady IP).
**Migracja:** pg_dump z Aiven → pg_restore na Railway. 2670 produktów + 37 Q&A + 6 rozmów + indeksy HNSW. Wykonana 2026-02-20.
**Blocker:** ~~Port 14368 TCP wychodzący do 66.33.22.230 czeka na odblokowanie przez admina hostingu.~~ ODBLOKOWANY 2026-02-20. Gotowe do migracji.
**Weryfikacja:** curl https://chat.divezone.pl/api/health → postgres: true, mysql: true, status: ok
**UWAGA BEZPIECZEŃSTWA:** Brak SSL na Railway proxy. Dane lecą nieszyfrowane. Akceptowalne na etapie dev (embeddingi nie są danymi wrażliwymi). Przed produkcją rozważyć Railway private networking lub tunel SSH.
**Aiven:** NIE kasować jeszcze. Backup na wypadek problemów z Railway.

### ADR-020: Multi-model routing z eskalacją (2026-02-20)
**Status:** Zatwierdzony (zastępuje ADR-014)
**Kontekst:** Jeden model to kompromis. Tani model obsłuży 90% rozmów (proste pytania, wyszukiwanie produktów). Trudne pytania (porównania 3+, kompatybilność, niezadowolony klient) eskalują do mocnego modelu.
**Architektura dwuwarstwowa:**
- **Tani model (primary):** szybki, tani, obsługuje typowe zapytania
- **Mocny model (escalation):** lepszy reasoning, trudne przypadki

**Zestawy providerów (przełączane w panelu admina):**
1. OpenAI: GPT-5 mini (primary) + GPT-5.2 (escalation)
2. Anthropic: Claude Sonnet 4.6 (primary) + Claude Opus 4.6 (escalation)

**Logika eskalacji (mix):**
- AI self-escalation: tani model może odpowiedzieć „nie wiem, potrzebuję więcej analizy"
- Rule-based triggers: porównanie 3+ produktów, pytanie o kompatybilność, reklamacja
- Fallback quality: jeśli similarity wyników < 0.5, retry z mocnym modelem
- Konfiguracja w panelu: progi eskalacji, włączanie/wyłączanie reguł

**Embedding model:** niezmienny, osobna warstwa. OpenAI text-embedding-3-large (1536 dim). Niezależny od choice chat modelu.

**Implementacja:**
- AIProviderInterface: dodanie parametru model tier per request
- Config w panelu admina: wybór zestawu, progi, parametry per model (temperature, max_tokens)
- Metryki: logowanie który model obsłużył, ile eskalacji, koszt per model

**Modele do testów (eval framework):**
- GPT-4.1 (tani baseline, do testów dev)
- GPT-5 mini, GPT-5.2 (zestaw OpenAI)
- Claude Sonnet 4.6, Claude Opus 4.6 (zestaw Anthropic)
- Claude Haiku 4.5 (ultra-tani, do sprawdzenia)

**MVP:** Oba zestawy (OpenAI + Anthropic) od razu, przełączane w panelu admina. Gemini (Vertex AI) jako planned, osobne SDK.

### ADR-022: Diagnostyka search quality i knowledge gaps (2026-02-20)
**Status:** Zatwierdzony
**Kontekst:** Potrzebujemy monitorować jakość wyszukiwania i identyfikować luki w bazie wiedzy.
**Decyzja:**
- Każdy tool call z search_products/get_expert_knowledge zapisuje diagnostykę: query_text, wyniki z similarity, matched_text
- knowledge_gap = true gdy: brak wyników LUB max similarity < konfigurowalny próg (domyślnie 0.5)
- Admin widzi rozmowy z lukami i może: tworzyć nowe chunki wiedzy (one-click draft z odpowiedzi AI), lub notować obserwacje
- Kolumna admin_status (new/reviewed/knowledge_created/ignored) do śledzenia przeglądanych rozmów
- Przyszłość: automatyczne raporty braków (grupowanie podobnych pytań bez wiedzy)
**Implementacja:** Nowe kolumny w divechat_conversations: search_diagnostics (JSONB), knowledge_gap (boolean), admin_status, admin_notes. Szczegóły w TASK-008.

### ADR-023: Filtrowanie produktów do embeddingów po drzewie kategorii (2026-02-20)
**Status:** Zatwierdzony
**Kontekst:** W indeksie embeddingów znalazły się produkty spoza oferty (maseczki COVID, opłaty, vouchery, kategorie "Niedostępne"). description_short zawiera CMS cruft zamiast opisów.
**Decyzja:**
- Produkty tylko z aktywnych kategorii potomnych id=2 ("Główna")
- 25 kategorii wykluczonych (z ich potomkami): 484, 458, 485, 486, 468, 368, 413, 451, 406, 409, 445, 447, 110, 396, 366, 448, 397, 482, 168, 461, 59, 457, 436, 462, 490
- description_short NIE używany nigdzie w pipeline. Tylko description (długi opis).
- Panel admina: drzewo kategorii z checkboxami wykluczeń (zamiast zaznaczania co wchodzi)
- Po poprawkach: pełny re-embedding
**Implementacja:** TASK-007 (embeddings)

### ADR-021: Eval framework do testowania modeli (2026-02-20)
**Status:** Zaplanowany
**Kontekst:** Potrzebujemy obiektywnie porównać modele na realnych scenariuszach divezone.pl przed uruchomieniem produkcji.
**Zakres:** 15-20 scenariuszy rozmów: wyszukiwanie produktów, doradztwo, porównania, zamówienia, edge cases (pytanie o Cressi, marki spoza oferty, pytania medyczne).
**Metryki:** jakość odpowiedzi (scoring 1-5), poprawność tool calling, trafność rekomendacji, latencja, koszt tokenów, respektowanie whitelisty marek.
**Wykonanie:** Instancja integration, automatyczne, bez ręcznego klikania. Wynik: tabela porównawcza z rekomendacją.
**Modele do testów:** GPT-4.1, GPT-5 mini, GPT-5.2, Claude Sonnet 4.6, Claude Opus 4.6, Claude Haiku 4.5.
**Zależności:** Wymaga działającego standalone API z narzędziami i bazą produktów.

### ADR-018: Hierarchiczna architektura bazy wiedzy (2026-02-20)
**Status:** Zatwierdzony
**Kontekst:** Baza wiedzy rośnie z wielu źródeł (ręczne, scraping, AI, podręczniki, YT). Płaska struktura nie skaluje się.
**Decyzja:** 4-poziomowa hierarchia: Dziedzina (7) -> Temat (30-60) -> Artykuł (jednostka redakcyjna) -> Chunk (jednostka embeddingu). Workflow redakcyjny: draft/imported -> review -> published. Tylko published jest embeddowany. AI writing assistant: model generuje draft, człowiek redaguje i zatwierdza.
**Szczegóły:** _docs/14_architektura_bazy_wiedzy.md
**Migracja:** Obecne 37 Q&A -> artykuły source_type=manual, status=published. Zachowana kompatybilność z divechat_knowledge (dodane pole article_id).


### ADR-024: Architektura wyszukiwania eksperckiego, 4 warstwy (2026-02-21)
**Status:** Zatwierdzony
**Kontekst:** AI nie potrafi efektywnie wyszukiwać produktów (vocabulary mismatch). Klient mówi "pianka", produkt to "BARE Velocity Semi-Dry 7mm Lady". Analiza branżowa: Amazon, Taobao, Zalando stosują query understanding + hybrid retrieval + re-ranking.
**Decyzja:** 4-warstwowa architektura:
1. LLM Product Enrichment (build time): silny LLM generuje 5-8 fraz per produkt, wzbogacone o realne dane z GSC/Luigi's Box/GA4. Walidacja przez drugi LLM.
2. Hybrid Search (query time): embedding + pg_trgm trigram, combined score 0.7/0.3.
3. Structured Query Rewriting (query time): parametr search_reasoning w tool schema wymusza chain-of-thought.
4. Category Filter (query time): semi-wymagany parametr category w tool schema.
**Modele enrichment:** GPT-5.2 generuje, Claude Opus 4.6 waliduje. Test na 30 produktach, potem pełny run.
**Odrzucone:** dedykowany re-ranker (przy 5-10 wynikach LLM re-rankuje naturalnie), fine-tuning modelu embeddingowego (za mały dataset), hardkodowane słowniki synonimów.
**Przed implementacją:** zebranie danych z GSC, Luigi's Box, GA4. Analiza zewnętrzna (brief do OpenAI/Gemini).
**Szczegóły:** _docs/14_architektura_wyszukiwania_rozwiazanie.md, _docs/15_brief_do_analizy_zewnetrznej.md, _docs/16_instrukcja_ekstrakcji_danych.md
**Implementacja:** TASK-011 (enrichment), TASK-012 (hybrid search), TASK-013 (query rewriting + category)


### ADR-025: Korekty po analizie zewnętrznej (OpenAI + Gemini) (2026-02-21)
**Status:** Zatwierdzony
**Kontekst:** Dwie niezależne analizy (GPT-5.2 i Gemini 2.5 Pro) zidentyfikowały 3 krytyczne błędy i 4 ulepszenia w architekturze z ADR-024.
**Źródła:** _docs/17_synteza_analiz_zewnetrznych.md, data/external_reviews/

**Krytyczne zmiany:**
1. **pg_trgm → Full-Text Search + pg_trgm:** pg_trgm zostaje TYLKO do fuzzy matching nazw własnych. Główny tor leksykalny: PostgreSQL native FTS (tsvector/tsquery) z dict_xsyn (słownik synonimów nurkowych), hunspell/lematyzacja PL, unaccent. Jeśli niewystarczające, upgrade do ParadeDB (BM25).
2. **Wagi liniowe 0.7/0.3 → RRF:** Reciprocal Rank Fusion (k=60) zamiast kombinacji liniowej. Skale wyników cosine i FTS są nieporównywalne, RRF operuje na rangach.
3. **Anti-phrases ZABRONIONE:** Frazy negatywne w embeddingach przesuwają wektor BLIŻEJ negowanego pojęcia. Negacja wyłącznie przez filtry SQL (WHERE ... != ...) w Warstwie 4.

**Ulepszenia (do implementacji po krytycznych):**
4. **Multi-Vector Retrieval:** 3 kolumny wektorowe: embedding_name, embedding_description, embedding_jargon. Izolacja sygnałów, brak rozmycia.
5. **Agentic Query Planning:** search_reasoning ewoluuje z string → strukturalny JSON (intent, semantic_query, exact_keywords, filters, routing weights).
6. **Polska lematyzacja:** unaccent + hunspell/stemmer w konfiguracji FTS.
7. **Golden Dataset + metryki:** NDCG@K, MRR, Zero Results Rate na 30-50 zapytaniach z GSC/Luigi's Box.

**Odrzucone (za wcześnie):**
- ColBERT (wymaga osobnej infrastruktury, quick wins dadzą więcej)
- GraphRAG (kompatybilność przez knowledge base + JSON metadata)
- Fine-tuning embeddingów (za mały dataset, syntetyczne dane na przyszłość)
- HyDE (dodatkowa latencja, enrichment po stronie bazy jest lepszy)
- Re-ranking cross-encoder (LLM w pętli + RRF wystarczą na start)

**Zaktualizowany plan tasków:**
- TASK-011: LLM Product Enrichment (bez zmian)
- TASK-012: Hybrid Search 3-torowy z RRF (zmieniony)
- TASK-012b: Multi-Vector Retrieval (nowy)
- TASK-013: Agentic Query Planning (zmieniony)
- TASK-014: Golden Dataset + Ewaluacja (nowy)


### ADR-026: Obsługa synonimów w FTS na Railway (2026-02-21)
**Status:** Do decyzji (pytanie 26)
**Kontekst:** dict_xsyn wymaga pliku .rules w $SHAREDIR/tsearch_data/. Railway PostgreSQL jest kontenerem Docker, ten katalog nie jest na wolumenie. Plik ginie po redeployu.

**Opcje:**

#### Opcja A: Fork obrazu Docker Railway
- Fork railwayapp-templates/postgres-ssl
- Dodanie pliku diving_synonyms.rules do obrazu
- Deploy custom image na Railway
- PRO: pełna kontrola, dict_xsyn działa natywnie
- CON: maintenance (update PostgreSQL = rebuild image), wyższy próg wejścia

#### Opcja B: Init script z wolumenu
- Trzymanie .rules na wolumenie (/var/lib/postgresql/data/)
- Script startowy kopiuje do /usr/share/postgresql/tsearch_data/ przy każdym deploy
- PRO: prostsze niż fork
- CON: wymaga custom entrypoint, może nie przeżyć automatycznego restartu

#### Opcja C: Synonimy w warstwie aplikacyjnej (REKOMENDOWANE)
- Rezygnacja z dict_xsyn w PostgreSQL
- Słownik synonimów jako tablica w bazie (divechat_synonyms)
- Python/PHP rozwija zapytanie PRZED wysłaniem do FTS
- Np. "pianka" → FTS query: "pianka | skafander | wetsuit | neopren"
- FTS używa konfiguracji 'simple' lub 'polish' (bez dict_xsyn)
- PRO: zero zależności od filesystemu, słownik edytowalny przez SQL, działa na każdym hostingu
- CON: dodatkowy query do bazy na rozwinięcie synonimów (trivialny koszt)

#### Opcja D: Synonimy niepotrzebne w FTS (mamy embeddingi)
- Warstwa 1 (LLM enrichment) już dodaje synonimy do document_text
- Embeddingi (Warstwa 2, tor semantyczny) już łapią "pianka" → "skafander"
- FTS szuka LITERALNIE w document_text który ZAWIERA synonimy
- Np. dokument ma "Szukaj też jako: pianka nurkowa, skafander mokry, wetsuit"
- FTS na "pianka" trafi w to pole bez żadnego słownika synonimów
- PRO: zero dodatkowej pracy, embeddingi + enriched text rozwiązują problem
- CON: działa TYLKO jeśli enrichment jest dobry, brak fallbacku

**Analiza:** Opcja D jest elegancka, bo synonimy z Warstwy 1 naturalnie trafiają do FTS.
Opcja C jest solidnym fallbackiem gdyby D nie wystarczyła.
Opcje A i B to overengineering na tym etapie.

**Rekomendacja:** Zaczynamy z D (zero pracy), monitorujemy jakość FTS na golden dataset.
Jeśli FTS nie łapie czegoś co powinien, implementujemy C (tabela synonimów + query expansion).


### ADR-027: Hierarchia kategorii w wyszukiwaniu (2026-02-21)
**Status:** Zatwierdzony
**Kontekst:** PrestaShop ma hierarchiczne kategorie. W niektórych gałęziach (Komputery Nurkowe, Automaty Oddechowe) podkategoriami są marki. Produkty są przypisane do podkategorii (np. "Komputery SHEARWATER"), nie do parent (np. "Komputery Nurkowe").

**Decyzja:** Podejście B (SQL po parent_id).
- Dodać kolumnę `parent_category_name` do divechat_product_embeddings
- Filtr category działa na OBU poziomach: `WHERE category_name ILIKE $1 OR parent_category_name ILIKE $1`
- System prompt zawiera TYLKO parent categories (krótszy prompt)
- LLM może filtrować po parent ("Automaty Oddechowe") lub child ("APEKS")

**Migracja:**
```sql
ALTER TABLE divechat_product_embeddings
    ADD COLUMN IF NOT EXISTS parent_category_name VARCHAR(255);

-- Wypełnienie z PrestaShop MySQL (jednorazowo, w extract_products.py)
```


### ADR-028: Security — Redis vs MySQL dla rate limitera (2026-02-23)
**Status:** Odłożony
**Kontekst:** Raport Gemini rekomenduje Redis dla rate limitingu (connection pool exhaustion). Raport GPT sugeruje przebudowę limitów.
**Decyzja:** Zostajemy przy MySQL z atomowym INSERT ON DUPLICATE KEY UPDATE.
**Dlaczego:** Przy ~100-500 czatów/dzień to jedno proste zapytanie SQL per request. Redis dodaje nową zależność infrastrukturalną (instalacja, monitoring, failover) za zerowy zwrot przy tej skali. Czat jest osadzony w sklepie za HMAC-em, nie jest publicznym API.
**Kiedy wrócimy:** Gdy średni ruch czatu przekroczy 5000 req/h lub gdy monitoring pokaże bottleneck MySQL.

### ADR-029: Security — sól rotacyjna IP hash vs stała (2026-02-23)
**Status:** Odłożony
**Kontekst:** Gemini argumentuje że stała sól + SHA-256 = rainbow table na IPv4 (~4.3 mld adresów).
**Decyzja:** Stała sól, przeniesiona z kodu do zmiennej środowiskowej (AICHAT_IP_SALT).
**Dlaczego:** Atak wymaga dostępu do bazy MySQL I soli jednocześnie. Jeśli atakujący ma oba — ma pełny dostęp do sklepu i IP hash jest najmniejszym problemem. Sól rotacyjna łamie korelację rate limitingu (stary hash ≠ nowy hash = reset limitu o północy).
**Kiedy wrócimy:** Przy audycie RODO lub gdy pojawi się wymóg prawny.

### ADR-030: Security — LLM-as-a-judge vs regex/listy (2026-02-23)
**Status:** Odłożony
**Kontekst:** Gemini proponuje dodatkowy model klasyfikacyjny zamiast regex/list do detekcji profanity, injection, off-topic.
**Decyzja:** V1 z regex + listami + system prompt jako główną barierą.
**Dlaczego:** Dodaje ~200-500ms latency, koszt API per request, nowy punkt awarii. System prompt zawiera silne instrukcje scope. Narzędzia mają ograniczoną sprawczość (read-only na danych publicznych). Regex łapie nisko wiszące owoce.
**Kiedy wrócimy:** Jeśli w logach security_log zobaczymy powtarzające się udane obejścia scope/injection.

### ADR-031: Security — ograniczenia regex injection guard i scope guard (2026-02-23)
**Status:** Akceptacja ryzyka
**Kontekst:** Oba raporty (GPT, Gemini) słusznie wskazują że regex i listy słów są łatwe do obejścia (FlipAttack, homoglify, low-resource languages, dopisanie słowa nurkowego).
**Decyzja:** Implementujemy jako tanie warstwy defense-in-depth z pełną świadomością ograniczeń.
**Dlaczego:** Główna ochrona to: (1) system prompt z silnymi instrukcjami, (2) narzędzia read-only na danych publicznych, (3) max_tokens=600 ogranicza wartość wyciągniętej odpowiedzi, (4) budżety per sesja. Regex i scope guard łapią nisko wiszące owoce (skrypt kiddies, ciekawscy). Koszt implementacji minimalny.
**Kiedy wrócimy:** Nigdy jako jedyna warstwa; LLM classifier jeśli logi pokażą potrzebę.

### ADR-032: Synonimy produktowe — generowanie i storage (2026-02-24)

**Kontekst:** Opisy produktów nie zawierają synonimów nazw produktowych (pianka = wetsuit = skafander mokry). To ogranicza zarówno SEO, wyszukiwarki AI, jak i nasz pgvector search.

**Decyzja:**
- Generowanie synonimów przez Claude API (batch, Sonnet 4.5) — PL i EN
- Realizacja w instancji embeddings jako `generate_synonyms.py`
- Storage: kolumny `synonyms_pl` i `synonyms_en` (JSONB) w `divechat_products`
- Synonimy dołączane do tekstu przed wektoryzacją
- Eksport CSV dla zewnętrznego projektu audytu opisów (flag `--export-csv`)
- Koszt: ~$8-12 za cały katalog (~2600 produktów)

**Alternatywy odrzucone:**
- Narzędzia SEO (Senuto/Semrush): słabe w niszy nurkowej, drogie, wymagają ręcznej weryfikacji
- Osobny projekt: duplikacja infrastruktury PostgreSQL, brak sensu skoro embeddings już ma pipeline

**Status:** Do implementacji (ZABLOKOWANY — wymaga TASK-014, encyklopedia sprzętowa)


### ADR-033: Encyklopedia sprzętowa — architektura GCIE + walidacja (2026-02-25)
**Status:** Zatwierdzony
**Kontekst:** AI generujący synonimy (TASK-013) halucynuje: myli kołowrotek ze szpulką, wymyśla "oddechówka", wrzuca "kiełbasa" zamiast "boja", klasyfikuje uprząż jako "aparat oddechowy". Potrzebna referencja terminologiczna jako Single Source of Truth. Analiza dwóch podejść: OpenAI Deep Research (evidence-first pipeline z claims layer) vs Gemini 3.1 Pro (GCIE z Context Caching). Cross-review przez oba modele potwierdził hybrydę.

**Decyzja:** Architektura hybrydowa: Gemini GCIE jako silnik generacji + OpenAI-style walidacja.

**Co bierzemy z Gemini GCIE:**
- Context Caching: cały korpus (~650k tokenów) w cache, 40 iteracyjnych zapytań
- Prompt Corpus-in-Context (bottom-heavy): dane na początku, instrukcje na końcu
- Chain-of-Dictionary dla anglicyzmów (BCD, Jacket, Octopus)
- thinking_level: HIGH
- JSON Schema enforcement via API

**Co bierzemy z OpenAI evidence-first:**
- 7 automatycznych gate'ów walidacji (schema, język, unikalność, symetria, min 2 sources, cross-field)
- Golden test set od dnia 1 (50+ par mylących, 100+ zapytań klientów)
- Deterministyczny merge per typ pola (nie globalnie)
- Testy regresji terminologicznej (kontrasty, PL/EN, wycieki synonimów)
- Wersjonowanie wpisów, regression testing

**Czego NIE bierzemy:**
- Claims layer (OpenAI): overengineering na 40 kategorii, opcjonalnie w przyszłości
- RAG/chunking/embeddingi (OpenAI): niepotrzebne przy full-context 1M
- LlamaIndex/LangChain: custom Python wystarczy
- Vendor lock-in na Gemini: fallback na Claude Opus z batchami po 5-10 kategorii

**Schemat rekordu pojęcia (rozszerzony po cross-review):**
- `concept_key`, `canonical_term_pl`, `canonical_term_en`
- `definition_operational_pl` (1-2 zdania odróżniające od najbliższego mylonego pojęcia, OBOWIĄZKOWE)
- `definition_pl`, `definition_en` (pełne definicje)
- Typowane synonimy: `exact_synonym`, `near_synonym`, `colloquial`, `legacy_name`, `brand_term`, `anglicyzm`, `misleading_term`
- Typowane relacje: `nie_mylic_z` (z `why` i `disambiguation_clues`), `nadrzedny`, `podrzedny`, `czesc_zestawu`, `wariant`
- `evidence[]` z cytatami i source_id
- `confidence`, `status`, `version`

**Hierarchia źródeł (per typ pola):**
- Definicje techniczne: PADI (EN) > IANTD OWD (PL) > CMAS (PL)
- Synonimy potoczne PL: nurkomania.pl > divezone logi wyszukiwania
- Nazwy handlowe: divezone kategorie i GSC

**Scope MVP:** ~40 kategorii sprzętowych + golden set ~50 par mylących + ~100 zapytań klientów
**Koszt:** ~$20-25 (Gemini API)
**Czas:** 4-5 dni
**Fallback:** Claude Opus z batchami po 5-10 kategorii jeśli >20% entries z needs_review

**Zależności:** TASK-013 (synonimy) ZABLOKOWANY do czasu ukończenia TASK-014 (encyklopedia)
**Implementacja:** TASK-014 (instancja embeddings)
**Źródła analizy:** `_docs/20_synteza_encyklopedia_openai_vs_gemini.md`, `_docs/research_attachments/`



---

## ADR-034: Przegenerowanie encyklopedii od zera + encyklopedia blogowa (2026-02-27)

**Kontekst:**
Adversarial review przez 3 modele (Claude Opus 4.6, GPT-5.2 thinking, Gemini 3.1 Pro) ujawnił że 85% z 46 definicji zawiera błędy (od drobnych po krytyczne). Główne przyczyny:
1. Za słaby model generujący (Gemini 2.5 Pro zamiast min. GPT-5.2/Opus 4.6)
2. Pipeline nastawiony na throughput (46 naraz) zamiast accuracy (pojęcie po pojęciu)
3. Walidacja sprawdzała strukturę, nie treść merytoryczną
4. Brak adversarial self-check w procesie generacji

Raport: `_docs/15_raport_adversarial_review.md`
Źródła: `_docs/adversarial_review_encyklopedia-{Claude,GPT,Gemini}.md`

**Decyzja:**
1. PRZEGENEROWAĆ encyklopedię od zera (~90 kategorii zamiast 46)
2. Nowy pipeline: generate → verify → challenge, pojęcie po pojęciu
3. Model: do uzgodnienia z Karolem (minimum GPT-5.2 thinking lub Opus 4.6 extended)
4. Adversarial review z 3 modeli służy jako "lista znanych błędów" w prompcie
5. DUAL OUTPUT: ten sam korpus wiedzy generuje:
   a) JSON dla AI czatu (definicje operacyjne, synonimy, relacje, misleading terms)
   b) Artykuły blogowe dla divezone.pl/blog (encyklopedia nurkowania SEO)

**Nowe kategorie do dodania (konsensus 2-3 modeli + decyzja Karola):**
- Węże: WAZ_HP, WAZ_LP, WAZ_INFLATORA_BCD, WAZ_SUCHACZA
- Suchy skafander: ZAWORY_SUCHEGO_SKAFANDRA, SYSTEM_SUCHYCH_REKAWIC
- Narzędzia: LINE_CUTTER/SEKATOR, ANALIZATOR_TLENU, TRANSMITER_CISNIENIA
- BCD: BP&W_SYSTEM (osobno od skrzydło=worek), DUMP_VALVE, GŁOWICA_INFLATORA
- Ochrona termiczna: PIANKA_SHORTY, KAMIZELKA_OCIEPLAJACA
- Balast: PAS_BALASTOWY, KIESZENIE_ZINTEGROWANE, TRYMOWKA
- Maski: MASKA_WIELOSZYBOWA, PASEK_DO_MASKI, ANTIFOG/PREP
- Automaty: ZESTAW_AUTOMATU (bundle SKU), ZESTAW_SERWISOWY
- Złącza: DIN_232, DIN_300, INSERT_DIN_YOKE
- Akcesoria: SZELKI_STAGE, SWIATLO_CHEMICZNE, KLEJ_NEOPRENOWY
- Inne: LIFT_BAG, NOZYCE, KAMIZELKA_SNORKELING, SUSZARKA, INFLATOR_Z_AUTOMATEM
- Pełna lista: ~90 kategorii (do zatwierdzenia w fazie 1 nowego pipeline)

**Encyklopedia blogowa — wstępna koncepcja:**
- Każda kategoria sprzętowa = artykuł na blogu divezone.pl
- Treść: rozbudowana definicja, jak wybrać, na co zwrócić uwagę, FAQ
- SEO: targetowanie fraz z GSC i Luigi's Box
- Cross-linking: powiązane produkty, kategorie sklepowe
- Format: WordPress/PrestaShop blog, Markdown → HTML
- Scope: osobny TASK (TASK-016), zależny od ukończenia nowej encyklopedii

**Zasada (NOWA, OBOWIĄZKOWA):**
Wybór modelu AI dla krytycznych elementów projektu ZAWSZE konsultowany z Karolem.
Minimum: GPT-5.2 thinking lub Opus 4.6 extended.
Nigdy nie używać modeli starszych niż 6 miesięcy.

**Odrzucone:**
- Łatanie punktowe 46 istniejących definicji (85% z błędami = nie warto)
- Zachowanie starych definicji dla 7 "czystych" (PASS 3/3) — dla spójności generujemy wszystko od zera

**Status:** Do realizacji. Blokuje: TASK-013 (synonimy), TASK-016 (blog).

---

## ADR-035: Integracja DataForSEO do wzbogacenia encyklopedii i bloga (2026-02-27)

**Kontekst:**
Encyklopedia sprzętowa (~90 kategorii) wymaga realnych danych o frazach klientów.
Modele AI znają terminologię techniczną ale nie wiedzą jakich potocznych fraz
używają klienci w Google PL. Dane behawioralne (wolumeny, powiązane frazy)
są niedostępne z żadnego innego źródła.

**Decyzja:**
1. Konto DataForSEO (pay-as-you-go, min. $50, saldo nie wygasa)
2. Credentials w .env (DATAFORSEO_API_LOGIN, DATAFORSEO_API_PASSWORD, DATAFORSEO_API_PASSWORD-BASE64)
3. Pobieramy dane PRZED generacją encyklopedii (wchodzą do promptu GPT-5.2)
4. Dwa endpointy:
   a) Keywords for Site (divezone.pl) — szeroki obraz, do 700 fraz, 1 request
   b) Keywords for Keywords — 90 seed keywords w 5 batchach po 20, 5 requestów
5. Łączny koszt: ~$7 z $50 budżetu

**API DataForSEO — format potwierdzone testem:**
- Endpoint: POST https://api.dataforseo.com/v3/keywords_data/google_ads/keywords_for_keywords/live
- Auth: Basic (base64 login:password)
- Poland: location_code=2616, language_code="pl"
- Limit: do 20 keywords per request (keywords_for_keywords), do 700 wyników (keywords_for_site)
- Koszt: $0.075 per request (keywords_for_keywords/live)
- Response: keyword, search_volume, cpc, competition, competition_index, monthly_searches[]
- Test "maska nurkowa": 4 wyniki, 170 vol/mies., dane sezonowe OK

**Zastosowania danych DataForSEO:**
1. Synonimy potoczne do encyklopedii AI (np. "maska nurkowa ze szkłami korekcyjnymi")
2. Priorytetyzacja artykułów blogowych wg wolumenu (TASK-016)
3. FAQ bazujące na realnych zapytaniach klientów
4. Wzbogacenie product descriptions dla embeddingów

**Rezerwa budżetowa ($43):**
- Audyt pozycji divezone.pl (Ranked Keywords)
- Analiza konkurencji (3-5 domen)
- SERP analysis dla kluczowych fraz
- Google Shopping / Merchant data
- People Also Ask

**Folder danych:** data/dataforseo/
**Skrypt:** TASK-017 (instancja embeddings)


---

## TASK-017 — COMPLETED (2026-02-27)

**Wyniki DataForSEO:**
- 6 requestów API, koszt: $0.45 z $50 (0.9%)
- 1404 unikalnych fraz (1111 z Keywords for Site, 293 nowych z batchów)
- Top frazy: "maska do nurkowania" 12.1k, "maska do snorkelingu" 5.4k, "butla do nurkowania" 1.6k, "komputer nurkowy" 880
- 39% fraz (549) nie zmatchowanych do kategorii heurystyką — dane kompletne w CSV/JSON

**Pliki wynikowe:**
- data/dataforseo/raw/ — 6 JSON-ów (3.3 MB)
- data/dataforseo/processed/all_keywords.csv — 1404 fraz
- data/dataforseo/processed/all_keywords.json
- data/dataforseo/processed/raport_keywords.md

**Obserwacje:**
- Keywords for Site dał 4× więcej fraz niż batche — główne źródło danych
- Klienci szukają "maska do nurkowania" (12.1k) nie "maska nurkowa" (170) — potoczne frazy z "do" dominują
- "okulary do nurkowania" (1.3k) — klasyczny synonim potoczny, ważny dla czatu
- "maska do nurkowania z tlenem" (720) — misleading term, maski nie mają tlenu
- "akwalung" (1.6k) — archaiczny termin, wciąż popularny

**Pozostały budżet DataForSEO: $49.55**


---

## FAZA 1 COMPLETED (2026-02-27)

**Lista concept keys v2.2 ZATWIERDZONA:** 105 pojęć w 13 grupach (A-M).
Plik: `_docs/FAZA1_concept_keys_v2.md`

Źródła: oryginalne 46, adversarial review 3 modeli, DataForSEO 1404 fraz,
weryfikacja vs pełna struktura kategorii divezone.pl (web fetch).

**FAZA 2:** Pilot na Grupie A (Oddychanie, 15 pojęć).
Generacja: GPT-5.2 thinking → Walidacja: Claude Opus 4.6 extended.

**Zasoby do wykorzystania w FAZIE 2:**
- `_docs/FAZA1_concept_keys_v2.md` — zatwierdzona lista 105 concept keys
- `_docs/11_mapa_marek-reviewed.md` — poprawiona mapa marek (do użycia w definicjach)
- `_docs/15_raport_adversarial_review.md` — znane błędy v1 (wchodzą do promptu)
- `data/dataforseo/processed/all_keywords.csv` — 1404 fraz z Google PL
- `_docs/wiedza_nurkowa/Review_GPT_i_Claude_Definicji/` — pełne review 3 modeli


---

## FAZA 2 PILOT: Prompty gotowe (2026-02-27)

**Prompt generacyjny:** `_docs/PROMPT_encyklopedia_grupa_A.md`
- Model: GPT-5.2 thinking (do uzgodnienia z Karolem)
- Zawiera: 15 concept keys, format JSON, znane błędy v1, frazy DataForSEO, mapa marek, self-check

**Prompt walidacyjny:** `_docs/PROMPT_walidacja_grupa_A.md`
- Model: Claude Opus 4.6 extended (do uzgodnienia z Karolem)
- Zawiera: kryteria walidacji (5 kategorii, 16 punktów), dane referencyjne, format werdyktów

**Workflow:**
1. Wklej PROMPT_encyklopedia_grupa_A.md do GPT-5.2 thinking → dostaniesz 15 JSON-ów
2. Wklej output + PROMPT_walidacja_grupa_A.md do Claude Opus 4.6 → dostaniesz werdykty
3. Popraw FAILe i powtórz walidację
4. Zatwierdzone definicje → `data/encyclopedia/grupa_A_oddychanie.json`


---

## ADR-036: DIN jako jedyny standard, INT archaiczny (2026-02-27)

**Kontekst:**
AI modele traktują DIN i INT jako równorzędne standardy przyłączy automatów do butli.
W rzeczywistości INT/yoke to martwy standard: nie produkowany od ~10 lat, w Europie
nigdy nie był powszechny. Nawet w Egipcie od 15+ lat jest tylko DIN.

**Decyzja:**
1. DIN to JEDYNY aktualny standard. Wszystkie definicje, prompty i AI czat muszą to odzwierciedlać.
2. INT wspominany wyłącznie jako archaiczny, z kontekstem "martwy standard, spotykany już tylko w egzotycznych lokalizacjach".
3. Nigdy nie prezentować "DIN vs INT" jako parametru wyboru zakupowego.
4. ADAPTER_DIN_INT zostaje w encyklopedii (sklep sprzedaje) ale z kontekstem "do starych butli".
5. ZLACZE_INT zostaje w encyklopedii (klienci mogą pytać) ale z kontekstem archaicznym.

**Dotyczy:** Wszystkich promptów generacyjnych, walidacyjnych, systemu czatu AI.


---

## ADR-037: Rewizja pipeline'u encyklopedii — deterministyczny Python + minimalny LLM (2026-02-28)

**Kontekst:**
Pipeline TASK-ENC-001 (4 warstwy LLM, „głuchy telefon") generował utratę danych:
synonimy znikały, relacje się psuły, kodowanie niespójne, FAQ trafiały do złych pojęć.
Prompt architektoniczny wysłany do 3 modeli: Claude Opus 4.6, OpenAI Deep Research,
Gemini 3.1 Pro Research. Wszystkie potwierdziły diagnozę i rekomendowały hybrydę
Python + minimalny LLM.

**Źródła analizy:** `_docs/pytanie_architektoniczne/` (prompt + 3 odpowiedzi)

**Konsensus 3/3 modeli:**
1. Warstwa 3 (GPT-5.2 generujący od zera z ignorowaniem v1) = główna przyczyna utraty danych
2. Deterministyczny Python powinien obsłużyć strukturę, relacje i walidację
3. LLM minimalnie, tylko do pól wymagających inteligencji językowej
4. Jedno wywołanie LLM per pojęcie zamiast kaskady czterech warstw
5. Automatyczna walidacja (schema, dwustronność, encoding, kolizje synonimów)
6. Redukcja kosztów ~85-90%

**Decyzja: 5-krokowy pipeline**

**Krok 1 — USUNIĘTY (ADR-037a, 2026-03-02):**
~~Transformacja v1→v2~~ — PORZUCONY. V1 to dane LLM-generated z wadliwego pipeline'u
(85% błędów w adversarial review). Bazowanie na nich propagowałoby te same błędy.

**Krok 2 — Python lookup (jedyny krok deterministyczny):**
Marki z `_docs/11_mapa_marek-reviewed.md` + baza MySQL.
Frazy klientów z DataForSEO/Luigi's Box/GSC jako kandydaci synonimów.
Wypełnia: marki_w_sklepie, kandydaci synonimów z danych klientów.
Output wchodzi do promptu LLM jako twarde dane wzbogacające.

**Krok 3 — LLM, jedno wywołanie per pojęcie:**
Wszystkie 106 pojęć jedną ścieżką: Opus 4.6 extended, generowanie bezpośrednio
ze źródeł ludzkich (PADI, IANTD, nurkomania) + dane z kroku 2 (marki, frazy).
V1 NIE wchodzi do promptu. 100% human review.

**Krok 4 — Python walidacja automatyczna:**
Schema validation, dwustronność nie_mylic_z, kolizje synonimów, encoding UTF-8,
brak samoodwołań, marki ⊂ whitelist, referencje → istniejące concept_key.
Raport PASS/FAIL per pojęcie.

**Krok 5 — Human review:**
Wszystkie FAILe + 100% ścieżki B + losowa próba 20% ścieżki A.
Focus: FAQ, podtypy, bledne_ale_popularne, uwagi_dla_ai.

**Korekty schematu v2 (ZATWIERDZONE):**

a) Evidence sidecar: osobny plik `encyclopedia_v2_evidence.json` mapujący
   concept_id + pole + wartość → źródło. Nie zmienia kontraktu v2, daje traceability.

b) Klucz `anglicyzmy` w `synonimy_pl`: nowy bucket na angielskie terminy
   używane w polskim kontekście (wing, backplate, jacket, BCD, LPI itp.).
   Reguła "zero English w polach PL" zmieniona na "anglicyzmy dopuszczalne
   i jawnie otagowane".

c) DUMP_VALVE split na DUMP_VALVE_BCD + DUMP_VALVE_DRYSUIT:
   Uzasadnienie domenowe: w jackecie/skrzydle zawór obsługiwany ręcznie (przycisk),
   w skafandrze suchym zawór sprężynowy z pokrętłem regulacji ciśnienia otwarcia.
   Dwa zupełnie różne urządzenia choć służą do tego samego (opróżnianie gazu).

**Modele LLM:**
- Wszystkie 106 pojęć: Claude Opus 4.6 extended
- Alternatywa/walidator: Gemini 3.1 Pro

**Estymacja:**
- Koszt LLM: ~$30-50 za całość (106 pojęć × Opus 4.6 extended)
- Dev Python (lookup + walidacja): 1-2 dni
- LLM execution: <2h
- Human review: 3-5 dni (106 pojęć, 100% review)

**Unikalne blind spoty z analizy 3 modeli (do adresowania):**
- 59 pojęć bez v1 wymaga agresywniejszej walidacji (Claude)
- Dual-purpose: baza AI vs encyklopedia publiczna to osobne produkty (OpenAI)
- Schema evolution / delta tracking przy aktualizacjach (Gemini)
- Ryzyko kanibalizacji SEO przy nakładających się wektorach (Gemini)
- Polska kultura nurkowa (DIR, jaskinie) wymaga wyższej wagi polskich źródeł (Gemini)

**Zastępuje:** ADR-033 (GCIE + walidacja), TASK-ENC-001 (stary pipeline do wyrzucenia)
**Blokuje:** TASK-013 (synonimy), TASK-016 (blog)
**Implementacja:** Nowy TASK-ENC-005 (instancja embeddings)


---

## ADR-038: Gemini 2.5 Pro jako generator encyklopedii (2026-03-03)

**Kontekst:** Porównanie jakości wyjść NotebookLM vs Gemini 2.5 Pro na haśle AUTOMAT ODDECHOWY.
Gemini daje: konkretne wartości (200/300 bar), FAQ w języku klienta ("Dlaczego automat sam wypuszcza bąble?"),
praktyczne analogie (zbalansowany = wspomaganie kierownicy), uczciwy kontekst cenowy.

**Decyzja:** Gemini 2.5 Pro zastępuje Opus 4.6 extended jako generator encyklopedii.
NotebookLM v2 (130 haseł) służy jako draft wejściowy, nie jako fundament.

**Ograniczenia Gemini do kontroli:**
- Uproszczone podtypy (cold/warm water zamiast membrane/piston) — korygujemy przez dual subtypes (ADR-041)
- Ryzyko halucynowanych synonimów — zamknięty uniwersum z tagami źródeł [GSC], [Luigi's Box], [DO WERYFIKACJI]
- "Brak danych w źródłach" może ukrywać legalne terminy z PADI/IANTD — kwestionariusz eksperta adresuje

**Zastępuje:** TASK-ENC-005 krok 3 (Opus 4.6 extended per pojęcie)

---

## ADR-039: Dane sprzedażowe MySQL jako kontekst dla encyklopedii (2026-03-03)

**Kontekst:** Encyklopedia generowana bez wiedzy o tym co sklep faktycznie sprzedaje to encyklopedia generyczna.
Dane z MySQL (8680 zamówień, 12 mies.) dają twarde fakty o cross-sellu i bestsellerach.

**Decyzja:** Dwa pliki wchodzą do kontekstu Gemini:
- `dane_sprzedazowe_crosssell_12m.md` — pary kategorii kupowane razem + % prawdopodobieństwa
- `dane_sprzedazowe_bestsellery_12m.md` — top 5 produktów per kategoria z nazwami

**Zastosowanie w haśle:**
- Sekcja Cross-selling: oparta na twardych danych ("43.5% kupujących skrzydło kupuje też balast")
- Sekcja FAQ: "Najpopularniejszy komputer w naszym sklepie to Shearwater Peregrine"
- Sekcja Uwagi dla sprzedawcy: wiedza o bestsellerach per kategoria

**Uzupełnienie na przyszłość:** TASK_sales_sync — cykliczna synchronizacja (CRON) danych
sprzedażowych do PostgreSQL czatu, udostępniane przez function calling.

---

## ADR-040: Honest parameters — nie listuj cech standardowych (2026-03-03)

**Kontekst:** 90%+ automatów w sklepie to: membranowe, zbalansowane, sucha komora, EN250A.
Listowanie tych cech jako "parametrów zakupowych" sugeruje że istnieją produkty bez nich.

**Decyzja:** Parametry zakupowe w encyklopedii zawierają TYLKO cechy które faktycznie
różnicują produkty w ofercie sklepu. Cechy które posiada 90%+ produktów to standard rynkowy,
nie parametr zakupowy. Mogą pojawić się w FAQ ("Czy wszystkie automaty mają suchą komorę?
Tak, praktycznie wszystkie współczesne automaty.").

**Przykłady zastosowania:**
- Automat: liczba portów, ACD, pokrętło regulacji = różnicują → parametry
- Automat: sucha komora, zbalansowanie, EN250A = standard → FAQ/notatka
- Maska: szkło hartowane = standard → nie listuj
- Pianka: grubość (3mm/5mm/7mm) = różnicuje → parametr

---

## ADR-041: Dual subtypes — klienckie + techniczne (2026-03-03)

**Kontekst:** Klasyfikacja techniczna (membranowy/tłokowy, zbalansowany/niezbalansowany)
opisuje rynek sprzed 10 lat. Klient w 2026 nie wybiera między tłokowym a membranowym,
bo 90%+ to membranowe. Klient wybiera: na zimne/ciepłe wody, rekreacyjny/techniczny.

**Decyzja:** Dwa poziomy podtypów w haśle encyklopedii:
1. **Podtypy klienckie (PRIMARY):** odzwierciedlają realne decyzje zakupowe
   (cold/warm water, recreational/technical, single/twin)
2. **Podtypy techniczne (SECONDARY):** w FAQ lub notatkach edukacyjnych
   ("Czy nadal produkowane są automaty tłokowe? Sporadycznie, ale...")

---

## ADR-042: DataForSEO zamiast Answer The Public (2026-03-03)

**Kontekst:** ATP nie ma API. DataForSEO ma endpointy Google Autocomplete,
People Also Ask, Related Searches. Konto DataForSEO już aktywne (saldo ~$49).

**Decyzja:** Skrypt Python (TASK-ENC-006) odpytuje DataForSEO:
- Google Autocomplete (pytania "jak...", "czy...", "jaki...")
- People Also Ask (PAA) — dokładnie to co daje ATP, ale programatycznie
- Related Searches — dodatkowe frazy

Wyniki: CSV z pytaniami per seed keyword, wchodzą do Gemini jako źródło FAQ.
Faza 1: test na 5 seedach, faza 2: pełne ~100 seedów.

**Koszt szacowany:** $2-5 za pełny run (vs $99/mies. ATP)

---

## ADR-043: Dane z czatu AI jako organiczne źródło wiedzy (2026-03-03)

**Kontekst:** Coraz więcej użytkowników szuka informacji przez ChatGPT/Perplexity
zamiast Google. Dane z tych narzędzi nie są dostępne (brak eksportu zapytań).

**Decyzja:** Po uruchomieniu czatu AI divezone.pl, każda rozmowa z klientem staje się
źródłem danych o pytaniach klientów. Admin panel z tagowaniem konwersacji
(wrong_product, wrong_info, common_question) tworzy organiczne "Answer The Public"
oparte na realnych klientach sklepu nurkowego.

**Implementacja:** Istniejący na roadmapie system tagowania (TASK-008 admin panel).
Dodać: eksport popularnych pytań, analitykę trendów, identyfikację luk w wiedzy.

Na teraz: DataForSEO + GSC + Luigi's Box + kwestionariusz eksperta wystarczą.


---

## ADR-044: Max 5 haseł na partię w Gemini (2026-03-05)

**Kontekst:** Empiryczny test w rozmowie z Gemini 3.1 Pro. Partia 8 haseł (automaty, 
I/II stopień, octopus, zestawy rek/twinset/stage/sidemount). Wynik:
- Hasła 1-3: dobra jakość, czytelny język
- Hasło 4: akceptowalne, lekka degradacja stylu
- Hasła 5-6: poważna degradacja, kwiecisty bełkot
- Hasła 7-8: nieczytelne, wymyślona terminologia ("ekskluzywna hybryda rutingowa")

**Decyzja:** Bezwzględny limit 5 haseł na partię. Zasada #16 w prompcie Gemini.
Po każdej partii: review + poprawki, dopiero potem następna.
22 partii × 5 haseł = ~106 haseł. Estymacja: 5-8 sesji Gemini.

**Dodatkowa obserwacja:** Wolna rozmowa (hasła 1 i 31 z początku sesji Gemini, 
bez promptu batchowego) dała LEPSZĄ jakość niż batched generation z promptem.
Rozważyć: wgranie pełnego kontekstu, ale generowanie 1-3 haseł per komenda.


---

### ADR-045: Gemini 3.1 Pro z enhanced promptem (#17-#20) jako generator encyklopedii
**Data:** 2026-03-05 | **Status:** PRZYJĘTA

**Kontekst:** Trzy rundy testów porównawczych na 3 hasłach (AUTOMAT, JACKET, SUCHY):
- Test v1 (TASK-ENC-008a): Gemini 3.1 Pro vs Claude Opus 4.6 vs GPT-5.2 na baseline prompcie
- Test v2 (TASK-ENC-008b): Gemini + zasady #17-#19 (cross-sell %, long-tail, concept keys)
- Test v3 (TASK-ENC-008c): Gemini + zasada #20 (minimalna objętość, więcej podtypów/FAQ)

Wyniki finalne (Gemini v3 vs Claude Opus 4.6):
- Jakość strukturalna: 21/21 vs 20.5/21 — Gemini lepszy w podtypach klienckich
- Objętość: ~6,000 vs ~10,883 chars/hasło — Gemini zwięźlejszy, bez paddingu
- Koszt batch 106 haseł: ~$3-5 vs ~$40-50 (10× taniej)
- Czas: ~40 min vs ~2h

**Decyzja:** Gemini 3.1 Pro z zasadami #1-#20 jako jedyny model generacji.
Prompt wzbogacony o 4 nowe zasady wynikające z review porównawczego:
- #17: cross-sell z konkretnymi % z danych sprzedażowych
- #18: sekcja fraz long-tail (min 8/hasło) po synonimach
- #19: linkowanie concept keys (→ KEY) w tekście
- #20: min 5,000-6,000 chars/hasło, min 5 FAQ, min 4 podtypy klienckie

**Odrzucone alternatywy:**
- Claude Opus 4.6: porównywalna jakość ale 10× droższy, 3× wolniejszy, padding ~40%
- GPT-5.2: zbyt ostrożny z synonimami ("Brak danych"), mniej naturalny FAQ


---

### ADR-046: Przebudowa pipeline na Evidence Registry + JSON Schema + Validator
**Data:** 2026-03-06 | **Status:** PRZYJĘTA

**Kontekst:** Pipeline v1 (TASK-ENC-009) wygenerował 105 haseł, ale review wykazał
krytyczny problem: ~80% haseł nie dostało danych z keywords/PAA (niekompletne mapowania
CONCEPT_TO_SEEDS i CONCEPT_TO_PAA_GROUP). Gemini sfabrykował tagi źródłowe [PAA], [AC],
[GSC, N vol] bez ostrzeżenia w ~80% haseł. Cross-validation z GPT-5.2 i Gemini 3.1 Pro
potwierdziła potrzebę przebudowy.

**Decyzja:** Nowy pipeline v2 (TASK-ENC-011):
1. Evidence Registry — zamknięty zbiór EV-IDs budowany deterministycznie z plików CSV/MD
2. 1 hasło per wywołanie API (eliminuje przeciek kontekstu między hasłami)
3. Gemini JSON Schema output (model nie pisze markdown, nie tworzy tagów)
4. Deterministic Validator — sprawdza każdy evidence_id, concept_key, reguły domenowe
5. Markdown Renderer — Python generuje tagi deterministycznie z evidence registry
6. Master Report z semaforami GREEN/YELLOW/RED

**Kluczowe zasady:**
- Model NIGDY nie tworzy tagów [GSC], [PAA], [AC] — zwraca tylko evidence_ids
- 0 sfabrykowanych evidence_ids = batch BLOCKED (fail closed)
- Tagi w markdownie budowane przez kod Python, nie przez LLM
- Hash plików źródłowych + prompt version w manifeście (reprodukowalność)
- quarantine/ folder dla RED haseł, oddzielony od final/

**Koszt:** ~$15 za 105 wywołań (vs ~$3-4 w v1), ~2.5h czas generacji.
Uzasadnienie: 5× droższe ale eliminuje 3 klasy błędów i daje deterministyczną
pewność tagów źródłowych.

**Źródło:** Cross-validation Gemini 3.1 Pro + GPT-5.2 (prompt_cross_validation_safeguards.md)
Konsensus obu modeli: evidence registry + JSON Schema + fail closed.


---

### ADR-047: Integracja encyklopedii przez aktualizację ExpertKnowledge tool
**Data:** 2026-03-06 | **Status:** PRZYJĘTA

**Kontekst:** Encyklopedia (105 haseł, 525 chunków w encyclopedia_chunks) gotowa do 
integracji z czatem AI. Istniejący ExpertKnowledge tool query'uje starą tabelę 
divechat_knowledge. Rozważano: (A) aktualizacja ExpertKnowledge, (B) nowy osobny tool, 
(C) merge z ProductSearch.

**Decyzja:** Opcja A — aktualizacja ExpertKnowledge na nową tabelę encyclopedia_chunks.
- Zachowuje obecny kontrakt (nazwa narzędzia, rejestracja w ToolRegistry)
- Dodaje filtrowanie po chunk_type (definition/synonyms/purchase/faq/seller)
- Dodaje opcjonalny filtr concept_key
- SystemPrompt rozszerzony o workflow: encyklopedia → produkty

**Uzasadnienie:**
- ExpertKnowledge jest już zarejestrowane w ToolRegistry i obsługiwane przez ChatService diagnostykę
- Osobny tool od ProductSearch bo inny cel (wiedza vs oferta)
- AI decyduje o kolejności: eksploracyjne pytania → najpierw encyklopedia → potem produkty
- chunk_types pozwala AI precyzyjnie wybrać typ wiedzy


---

### ADR-048: Real-time dane produktów z MySQL zamiast zamrożonych w pgvector
**Data:** 2026-03-09 | **Status:** PRZYJĘTA

**Kontekst:** ProductSearch zwracał ceny, stany i visibility z pgvector 
(divechat_product_embeddings), zamrożone od daty embeddingu (20 lutego 2026).
Zmiana in_stock_only na TRUE spowodowała 0 wyników bo stany były nieaktualne.
Klient mógł zobaczyć cenę sprzed 3 tygodni.

**Decyzja:** enrichWithMySQLData() — po RRF fusion, przed zwróceniem wyników,
jedno query do MySQL PrestaShop pobiera aktualne: cenę brutto (netto × stawka VAT),
quantity, active, visibility. Filtrowanie in_stock_only działa na real-time danych.
Fallback na pgvector jeśli MySQL niedostępny.

**Zasada:** pgvector = embeddingi + dane statyczne (nazwa, kategoria, marka).
MySQL = dane runtime (cena, stan, visibility, active). Zero synchronizacji stanów.


### ADR-049: Nie wysyłać search_debug do LLM + ukryć quantity (2026-03-10)
**Decyzja:** `search_debug` (w tym `quantity`, `mysql_enrichment`, `candidates_before_mysql`) jest usuwany
z tool result przed wysłaniem do modelu. Diagnostyka jest zbierana osobno w `buildSearchDiagnostic()`.
Dodana reguła w SystemPrompt: "NIGDY nie podawaj klientowi ilości sztuk na stanie".
**Powód:** Model widział `quantity` w `search_debug.mysql_enrichment` i podawał klientom dokładne ilości
sztuk na stanie, co jest informacją wewnętrzną. Ponadto `search_debug` to ~2-5KB zbędnych tokenów per tool call.

### ADR-050: Ceny promocyjne z pr_specific_price (2026-03-10)
**Decyzja:** `enrichWithMySQLData()` dołączy `pr_specific_price` do query MySQL, żeby zwracać cenę
po promocji/obniżce zamiast ceny bazowej. Logika: price override + reduction (percentage/amount)
z walidacją dat, shop, group, from_quantity.
**Ograniczenie:** Ceny na poziomie produktu (`id_product_attribute = 0`). Kombinacje z różnymi cenami
to znane ograniczenie, akceptowalne w pierwszej iteracji.
**Powód:** AI podawał cenę bazową sprzed obniżki, niezgodną z ceną widoczną na karcie produktu.
