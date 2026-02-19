# Architektura projektu: Czat AI dla divezone.pl
# Wersja: 1.0 | Data: 2026-02-18
# Status: W TRAKCIE USTALEŃ

---

## 1. Cel projektu

Czat AI na stronie divezone.pl (PrestaShop 1.7.6), który:
- odpowiada na pytania o produkty (specyfikacja, rozmiary, dostępność, ceny)
- prowadzi doradztwo sprzętowe na poziomie zaawansowanych agentów (styl Amazon)
- sprawdza statusy zamówień (po autoryzacji klienta)
- korzysta z bazy danych sklepu w czasie rzeczywistym
- wykorzystuje bazę wiedzy eksperckiej (wyszukiwanie semantyczne)

## 2. Decyzje podjęte

| Element | Decyzja | Status |
|---|---|---|
| Forma implementacji | Moduł PrestaShop | Potwierdzone |
| Historia rozmów | Zapisywana w bazie (z uwzględnieniem RODO) | Potwierdzone |
| Autoryzacja zamówień | Zalogowany = auto, niezalogowany = nr zamówienia + email | Potwierdzone |
| Język MVP | Polski | Potwierdzone |
| Języki docelowe | Polski + inne | Potwierdzone |
| Baza wektorowa | pgvector (PostgreSQL) | Oczekuje na potwierdzenie hostingu |
| Model AI (czat) | Claude Sonnet 4 lub GPT-4o | Do przetestowania |
| Model embeddingów | OpenAI text-embedding-3-large (3072 dim) vs small (1536 dim) | Do przetestowania na danych |
| Wiedza ekspercka | Dynamiczna, z bazy wektorowej (nie w system prompt) | Potwierdzone |
| Widget czatu | Własny (moduł PS) | Potwierdzone |

## 3. Architektura systemu

```
┌─────────────────────────────────────────────────────┐
│                   divezone.pl                        │
│                 PrestaShop 1.7.6                     │
│                                                      │
│  ┌─────────────────────────────────────────────┐    │
│  │         Moduł: divezone_chat                 │    │
│  │                                               │    │
│  │  Frontend (widget czatu)                      │    │
│  │  chat.js + chat_widget.tpl                    │    │
│  │         │                                     │    │
│  │         ▼                                     │    │
│  │  ChatApiController.php (endpoint)             │    │
│  │         │                                     │    │
│  │         ▼                                     │    │
│  │  ChatService.php (logika rozmowy)             │    │
│  │         │                                     │    │
│  │    ┌────┴────┐                                │    │
│  │    ▼         ▼                                │    │
│  │  AIProvider    Tools (function calling)        │    │
│  │  .php          ├── ProductSearch.php           │    │
│  │  (Claude/   ├── ProductDetails.php          │    │
│  │   OpenAI)   ├── ProductAvailability.php      │    │
│  │    │        ├── OrderStatus.php              │    │
│  │    │        ├── ShippingInfo.php              │    │
│  │    │        ├── CompareProducts.php           │    │
│  │    │        └── ExpertKnowledge.php           │    │
│  │    │              │                           │    │
│  │    │              ▼                           │    │
│  │    │        ┌──────────────┐                  │    │
│  │    │        │ PostgreSQL   │                  │    │
│  │    │        │ + pgvector   │                  │    │
│  │    │        │              │                  │    │
│  │    │        │ - embeddingi │                  │    │
│  │    │        │   produktów  │                  │    │
│  │    │        │ - baza Q&A   │                  │    │
│  │    │        │ - artykuły   │                  │    │
│  │    │        └──────────────┘                  │    │
│  │    │                                          │    │
│  │    ▼                                          │    │
│  │  MySQL (PrestaShop)                           │    │
│  │  pr_product, pr_orders, pr_customer...        │    │
│  └───────────────────────────────────────────────┘   │
└──────────────────────────────────────────────────────┘
         │                              │
         ▼                              ▼
   Claude/OpenAI API           OpenAI Embeddings API
   (function calling)          (generowanie wektorów)
```

## 4. Przepływ danych

### 4.1 Zapytanie produktowe/doradcze
1. Klient wpisuje pytanie w widgecie czatu
2. JS wysyła POST do ChatApiController
3. ChatService buduje request: system prompt (stały, ~700 tokenów) + historia rozmowy + wiadomość klienta
4. Request idzie do Claude/OpenAI API z definicją narzędzi (tools)
5. AI decyduje jakie narzędzia wywołać (może kilka po kolei):
   a. ExpertKnowledge: wyszukiwanie semantyczne w pgvector (wiedza doradcza)
   b. ProductSearch: wyszukiwanie hybrydowe (semantyczne + SQL filtry)
   c. ProductDetails: szczegóły konkretnego produktu z MySQL
6. Wyniki narzędzi wracają do AI jako kontekst
7. AI formułuje odpowiedź
8. Odpowiedź wraca do klienta

### 4.2 Wyszukiwanie hybrydowe (ProductSearch)
1. Zapytanie klienta zamieniane na wektor (OpenAI Embeddings API)
2. PostgreSQL: wyszukiwanie wektorowe (cosine similarity) + filtry SQL (cena, dostępność, kategoria)
3. Top N wyników wraca do AI

### 4.3 Zapytanie o zamówienie
1. Klient pyta o status zamówienia
2. AI wywołuje narzędzie OrderStatus
3. Jeśli klient zalogowany w PS: automatyczna identyfikacja
4. Jeśli niezalogowany: AI prosi o numer zamówienia + email
5. Backend weryfikuje parę (nr zamówienia, email) w pr_orders + pr_customer
6. Zwraca status, historię statusów, numer przesyłki

## 5. Baza wektorowa (PostgreSQL + pgvector)

### 5.1 Tabele

```sql
-- Embeddingi produktów
CREATE TABLE divechat_product_embeddings (
    id SERIAL PRIMARY KEY,
    id_product INTEGER NOT NULL,          -- FK do pr_product
    text_content TEXT NOT NULL,            -- źródło embeddingu (nazwa+opis+cechy)
    embedding vector(3072),               -- lub 1536, zależnie od wybranego modelu
    updated_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX ON divechat_product_embeddings 
    USING ivfflat (embedding vector_cosine_ops) WITH (lists = 50);

-- Baza wiedzy (Q&A, artykuły, notatki eksperckie)
CREATE TABLE divechat_knowledge (
    id SERIAL PRIMARY KEY,
    chunk_type VARCHAR(20) NOT NULL,      -- 'qa', 'article', 'faq', 'expert_note'
    question TEXT,                          -- NULL dla artykułów, wypełnione dla Q&A
    content TEXT NOT NULL,                  -- treść odpowiedzi / chunk artykułu
    category VARCHAR(100),                 -- 'automaty', 'komputery', 'ogólne', 'logistyka'
    embedding vector(3072),
    is_direct_answer BOOLEAN DEFAULT FALSE,-- true = może być zwrócone bez Claude
    source_url TEXT,                        -- URL artykułu źródłowego (jeśli dotyczy)
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX ON divechat_knowledge 
    USING ivfflat (embedding vector_cosine_ops) WITH (lists = 20);

-- Historia rozmów (RODO: polityka retencji)
CREATE TABLE divechat_conversations (
    id SERIAL PRIMARY KEY,
    session_id VARCHAR(64) NOT NULL,       -- identyfikator sesji
    id_customer INTEGER,                    -- NULL dla niezalogowanych
    messages JSONB NOT NULL,                -- historia wiadomości
    tools_used JSONB,                       -- logi wywołań narzędzi
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);
```

### 5.2 Pipeline embeddingów (cron, Python)

Skrypt uruchamiany codziennie (lub po aktualizacji produktów):
1. Łączy się z MySQL (PrestaShop)
2. Dla każdego aktywnego produktu buduje dokument tekstowy:
   - nazwa + opis krótki + opis długi + cechy (pr_feature_value_lang) + kategorie + marka + cena
3. Generuje embedding przez OpenAI Embeddings API
4. Zapisuje/aktualizuje w PostgreSQL (divechat_product_embeddings)
5. Analogicznie dla nowych/zmienionych wpisów w divechat_knowledge

## 6. System prompt (część stała)

Wysyłany z każdym requestem (~700 tokenów):

```
Jesteś ekspertem ds. sprzętu nurkowego w sklepie divezone.pl, największym 
sklepie nurkowym w Polsce. Pomagasz klientom dobrać sprzęt, odpowiadasz 
na pytania o produkty i zamówienia.

ZASADY:
- Odpowiadaj po polsku, profesjonalnie ale przystępnie
- Zawsze sprawdzaj dostępność i cenę w bazie przed rekomendacją
- Nie rekomenduj produktów których nie ma w ofercie sklepu
- Przy doradztwie ZAWSZE pytaj o: poziom doświadczenia nurka, 
  warunki nurkowania (temperatura wody), budżet
- Jeśli klient pyta o produkt którego nie mamy, zaproponuj alternatywę z oferty
- Nie udzielaj porad medycznych dotyczących nurkowania
- Przy pytaniach o zamówienie, zweryfikuj tożsamość klienta
- Przy porównaniach bądź obiektywny, wskazuj zalety i wady
- Jeśli nie znasz odpowiedzi, powiedz to i zaproponuj kontakt z obsługą

NARZĘDZIA:
Masz dostęp do narzędzi wyszukiwania produktów, sprawdzania szczegółów, 
dostępności, statusów zamówień i bazy wiedzy eksperckiej. Korzystaj z nich 
aktywnie, nie zgaduj.
```

## 7. Definicje narzędzi (tools / function calling)

### 7.1 search_products
Wyszukiwanie hybrydowe: semantyczne (pgvector) + filtry SQL
- Parametry: query (string), category (string, opcjonalny), min_price/max_price (number, opcjonalne), brand (string, opcjonalny), in_stock_only (boolean, domyślnie true)
- Zwraca: lista produktów (id, nazwa, cena, kategoria, krótki opis, dostępność, URL)

### 7.2 get_product_details
Pełna specyfikacja produktu z MySQL
- Parametry: product_id (int)
- Zwraca: nazwa, opis, cechy techniczne, warianty (rozmiary/kolory), cena, zdjęcia, dostępność, URL

### 7.3 get_product_availability
Stan magazynowy i warianty
- Parametry: product_id (int)
- Zwraca: lista wariantów (rozmiar, kolor, ilość na stanie)

### 7.4 check_order_status
Status zamówienia
- Parametry: order_reference (string), customer_email (string)
- Zwraca: status, data zamówienia, historia statusów, numer przesyłki, link do śledzenia

### 7.5 get_shipping_info
Informacje o dostawie
- Parametry: brak (ogólne) lub cart_total (number, do kalkulacji progu darmowej dostawy)
- Zwraca: metody dostawy, ceny, progi darmowej dostawy, czas dostawy

### 7.6 get_expert_knowledge
Wyszukiwanie semantyczne w bazie wiedzy
- Parametry: query (string), category (string, opcjonalny)
- Zwraca: relevantne fragmenty wiedzy eksperckiej (top 3-5 chunków)
- Uwaga: jeśli similarity > 0.92 i chunk ma is_direct_answer=true, może być zwrócony bez Claude

### 7.7 compare_products
Porównanie 2-3 produktów
- Parametry: product_ids (array of int)
- Zwraca: tabela porównawcza cech, cen, dostępności

## 8. Plan implementacji

### Etap 1: Infrastruktura (tydzień 1)
- [ ] Potwierdzenie pgvector na hostingu
- [ ] Instalacja PostgreSQL + pgvector
- [ ] Utworzenie tabel (embeddingi, wiedza, historia)
- [ ] Skrypt Python: pipeline embeddingów produktów
- [ ] Test embeddingów: porównanie small vs large na 100 produktach + 20-30 zapytaniach
- [ ] Decyzja o modelu embeddingów na podstawie testu

### Etap 2: Backend (tydzień 2)
- [ ] Szkielet modułu PrestaShop
- [ ] Implementacja narzędzi (ProductSearch, ProductDetails, ExpertKnowledge itd.)
- [ ] AIProvider: integracja z Claude API i OpenAI API
- [ ] ChatService: logika rozmowy, historia, routing
- [ ] Testy function calling na realnych scenariuszach

### Etap 3: Frontend + integracja (tydzień 3)
- [ ] Widget czatu (HTML/CSS/JS)
- [ ] Integracja z backendem (AJAX)
- [ ] System prompt (część stała)
- [ ] Wgranie bazy Q&A do pgvector
- [ ] Testy end-to-end

### Etap 4: Polish + launch (tydzień 4)
- [ ] Logowanie rozmów
- [ ] Panel admina (zarządzanie wiedzą, podgląd rozmów)
- [ ] RODO: polityka retencji, informacja dla klientów
- [ ] Testy z realnymi klientami (beta)
- [ ] Monitoring kosztów API
- [ ] Deploy

## 9. Otwarte kwestie

- [ ] Potwierdzenie możliwości instalacji pgvector na VPS (rozmowa z hostingiem)
- [ ] Wybór modelu AI do czatu (Claude Sonnet 4 vs GPT-4o) - test
- [ ] Wybór modelu embeddingów (small vs large) - test na danych
- [ ] Szczegóły polityki RODO (czas retencji rozmów, zgoda klienta)
- [ ] Alternatywa jeśli pgvector niedostępny na hostingu
