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

### ADR-010: Zewnętrzne źródła wiedzy eksperckiej (2026-02-19)
**Status:** Zaplanowane (post-MVP)
**Kontekst:** Blogi sklepów nurkowych i instruktorów, kanały YouTube z recenzjami i poradami stanowią bogate źródło wiedzy eksperckiej, które może znacząco wzbogacić bazę wiedzy czatu. Aktualnie baza wiedzy (divechat_knowledge) zawiera 30 ręcznie napisanych wpisów Q&A. Docelowo chcemy ją rozbudować o treści z zewnętrznych źródeł.
**Decyzja:** W wersji post-MVP wdrożymy pipeline pozyskiwania wiedzy z zewnętrznych źródeł:
- Blogi sklepów nurkowych (nautica.pl, divefactory24.pl, nurkowo.pl, szpejownia.com i inne)
- Blogi instruktorów i portale (nurekamator.pl, nurkomania.pl, jollydiver.pl)
- Fora nurkowe (forum-nuras.com, scubaboard.com)
- Kanały YouTube z recenzjami sprzętu i poradami nurkowymi
- Tavily API (web search) jako narzędzie do wyszukiwania i ekstrakcji treści
**Implementacja:** Tavily ($5/mies plan Dev, klucz już w .env) do crawlowania i ekstrakcji tekstu z URL. Chunking + embedding tych treści do divechat_knowledge z odpowiednim chunk_type ('blog', 'video_transcript', 'forum_post') i source_url. Opcjonalnie: transkrypcja YouTube przez Whisper API lub youtube-transcript-api (Python).
**Priorytety źródeł:** Blogi producentów i sklepów > poradniki instruktorów > fora > YouTube.
**Uwaga:** Wyłącznie do wewnętrznej bazy wiedzy AI, nie do reprodukcji treści klientom. Szanujemy prawa autorskie.
