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
