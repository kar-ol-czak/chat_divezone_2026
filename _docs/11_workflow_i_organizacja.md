# Workflow i organizacja pracy - Czat AI divezone.pl
# Wersja: 1.0 | Data: 2026-02-18

---

## 1. Architektura dokumentacji projektu

### Struktura katalogów

```
Chat_dla_klientow_2026/
├── _docs/                          # Dokumentacja projektowa
│   ├── 00_architektura_projektu.md  # Architektura systemu (główny dokument)
│   ├── 01_specyfikacja_api.md       # Definicje narzędzi (tools), endpointy, schematy JSON
│   ├── 02_schemat_bazy.md           # Tabele PostgreSQL, indeksy, migracje
│   ├── 03_system_prompt.md          # System prompt (stała część + zasady dynamicznej)
│   ├── 04_qa_baza_wiedzy.md         # Baza Q&A (już istnieje)
│   ├── 05_frontend_spec.md          # Widget czatu: UI/UX, HTML/CSS/JS, zachowania
│   ├── 06_pipeline_embeddingow.md   # Skrypt Python, cron, format dokumentów produktowych
│   ├── 07_bezpieczenstwo_rodo.md    # Autoryzacja, retencja danych, zgody RODO
│   ├── 08_testy_i_ewaluacja.md      # Scenariusze testowe, metryki jakości odpowiedzi
│   ├── 09_deployment.md             # Instrukcja wdrożenia, konfiguracja serwera
│   ├── 10_decyzje_projektowe.md     # Log decyzji (ADR - Architecture Decision Records)
│   └── CONVENTIONS.md               # Konwencje kodu, nazewnictwa, formatowania
│
├── _instances/                      # Instancje Claude Code (taski i handoff)
│   ├── backend/
│   │   ├── CLAUDE.md                # Instrukcje dla instancji backend
│   │   ├── tasks/                   # Taski do wykonania
│   │   └── handoff/                 # Pliki przekazania między instancjami
│   ├── embeddings/
│   │   ├── CLAUDE.md
│   │   ├── tasks/
│   │   └── handoff/
│   ├── frontend/
│   │   ├── CLAUDE.md
│   │   ├── tasks/
│   │   └── handoff/
│   └── integration/
│       ├── CLAUDE.md
│       ├── tasks/
│       └── handoff/
│
├── CLAUDE.md                        # Główny plik instrukcji projektu (root)
├── modules/
│   └── divezone_chat/               # Kod modułu PrestaShop
│       ├── divezone_chat.php
│       ├── controllers/
│       ├── classes/
│       └── views/
├── embeddings/                      # Skrypty Python (pipeline embeddingów)
├── sql/                             # Migracje SQL (PostgreSQL)
└── tests/                           # Testy
```

### Zasady dokumentacji

1. Każdy plik _docs/ max 300-400 linii. Jeśli rośnie, dziel na części (np. 01a_, 01b_).
2. Każdy plik zaczyna się od nagłówka z wersją i datą.
3. Zmiany w decyzjach zawsze odnotowywane w 10_decyzje_projektowe.md z datą i powodem.
4. Pliki _docs/ to dokumentacja dla ludzi, pliki CLAUDE.md to instrukcje dla AI.

---

## 2. Jak pracować z Claude aby unikać problemów z oknem kontekstowym

### Zasady ogólne

1. **Jedna rozmowa = jeden temat/zadanie.** Nie ciągnij rozmowy o architekturze, kodowaniu i testach w jednym czacie. Zamykaj rozmowę gdy temat jest wyczerpany.

2. **Zamiast wklejać długi kod, wskazuj pliki.** Claude Code czyta pliki z dysku. Claude.ai z komputerem (artifacts) też. Nie wklejaj 500 linii kodu w okno czatu.

3. **Dokumentacja jako "pamięć zewnętrzna".** Dlatego pliki _docs/ są małe i podzielone tematycznie. Gdy wracasz do tematu, wczytuj tylko relevantny plik _docs/, nie cały projekt.

4. **Handoff między sesjami.** Gdy kończysz sesję pracy, każ Claude zapisać podsumowanie w pliku handoff. Następna sesja zaczyna od wczytania tego pliku.

5. **CLAUDE.md jako stały kontekst.** Claude Code automatycznie czyta CLAUDE.md z katalogu projektu. To jest Twój "system prompt" dla Claude Code. Trzymaj go krótki (max 200 linii) z odwołaniami do plików _docs/.

6. **Nie powtarzaj kontekstu.** Jeśli coś jest zapisane w pliku, odwołuj się do pliku zamiast powtarzać treść w promptach.

### Sygnały ostrzegawcze

- Claude zaczyna "zapominać" wcześniejsze ustalenia → za długa rozmowa, zamknij i otwórz nową z handoffem
- Claude powtarza się lub daje sprzeczne odpowiedzi → context overflow, /compact w Claude Code
- Claude Code pisze "Context window is getting large" → natychmiast /compact lub nowa sesja

---

## 3. Instancje Claude Code

### Podział na 4 instancje

Każda instancja pracuje na własnym zakresie plików i ma własny CLAUDE.md z instrukcjami.

**Instancja 1: BACKEND**
- Zakres: modules/divezone_chat/ (PHP)
- Odpowiada za: moduł PS, kontrolery, serwisy, klasy narzędzi, AIProvider
- Zależności: czyta _docs/01_specyfikacja_api.md, _docs/02_schemat_bazy.md

**Instancja 2: EMBEDDINGS**
- Zakres: embeddings/ (Python), sql/ (PostgreSQL)
- Odpowiada za: pipeline embeddingów, skrypty cron, tabele PostgreSQL, testy wyszukiwania
- Zależności: czyta _docs/02_schemat_bazy.md, _docs/06_pipeline_embeddingow.md

**Instancja 3: FRONTEND**
- Zakres: modules/divezone_chat/views/ (JS/CSS/TPL)
- Odpowiada za: widget czatu, UI/UX, integracja AJAX z backendem
- Zależności: czyta _docs/05_frontend_spec.md, _docs/01_specyfikacja_api.md (endpointy)

**Instancja 4: INTEGRATION**
- Zakres: tests/, całość projektu (read-only)
- Odpowiada za: testy end-to-end, weryfikacja integracji, debug
- Zależności: czyta wszystkie _docs/

### Komunikacja między instancjami

Przez pliki w _instances/{nazwa}/handoff/:

```
_instances/backend/handoff/
├── 2026-02-20_api_endpoints_ready.md    # "Frontend: endpointy gotowe, oto kontrakt"
├── 2026-02-21_tool_schemas_updated.md   # "Embeddings: zaktualizowałem schemat search_products"
```

Format pliku handoff:
```markdown
# Handoff: [tytuł]
Data: YYYY-MM-DD
Od: [instancja źródłowa]
Do: [instancja docelowa]

## Co zostało zrobione
- ...

## Co jest potrzebne od adresata
- ...

## Pliki zmienione
- ...

## Uwagi
- ...
```

---

## 4. Workflow z Agent Teams (Swarm)

### Kiedy używać Agent Teams, a kiedy nie

Claude Code Agent Teams (swarm mode) to nowa funkcja (luty 2026, eksperymentalna). Pozwala na równoległą pracę wielu agentów, ale:

**Używaj Agent Teams gdy:**
- Masz kilka niezależnych tasków do zrobienia równolegle (np. backend endpoint + frontend widget + testy)
- Potrzebujesz "debaty" między agentami (np. debug z konkurencyjnymi hipotezami)
- Prace nie dotyczą tych samych plików

**NIE używaj Agent Teams gdy:**
- Taski są sekwencyjne (jeden zależy od wyniku drugiego)
- Pracujesz nad tym samym plikiem (konflikty)
- Potrzebujesz głębokiego kontekstu (agent teams mają mniejszy kontekst per agent)

### Rekomendowany workflow dla naszego projektu

Nasz projekt NIE jest idealnym kandydatem na swarm, bo etapy są w dużej mierze sekwencyjne (embeddingi → backend → frontend → integracja). Lepszy jest workflow etapowy z ręcznym handoff:

```
Ty (architekt w Claude.ai Opus 4.6)
│
├── Decyzje architektoniczne, review kodu, Q&A eksperckie
│
├── Zlecasz zadanie → Claude Code (instancja EMBEDDINGS)
│   └── Pipeline embeddingów, tabele PG, testy wyszukiwania
│   └── Handoff → instancja BACKEND
│
├── Zlecasz zadanie → Claude Code (instancja BACKEND)
│   └── Moduł PS, narzędzia, AIProvider
│   └── Handoff → instancja FRONTEND
│
├── Zlecasz zadanie → Claude Code (instancja FRONTEND)
│   └── Widget czatu, UI, AJAX
│   └── Handoff → instancja INTEGRATION
│
└── Zlecasz zadanie → Claude Code (instancja INTEGRATION)
    └── Testy end-to-end, debug
```

Agent Teams warto użyć punktowo, np.:
- Gdy backend jest gotowy, odpal 2 agentów: jeden pisze testy, drugi pisze dokumentację
- Debug: 3 agenty badają równolegle różne hipotezy dlaczego wyszukiwanie zwraca złe wyniki

### Jak włączyć Agent Teams

W Claude Code:
```bash
# W pliku settings.json lub jako zmienna środowiskowa:
CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1
```

---

## 5. Rekomendowany workflow narzędziowy

### Stack narzędzi

```
┌─────────────────────────────────────────────────────────┐
│  Claude.ai (Opus 4.6) - TY JAKO ARCHITEKT              │
│  Projekt: "Czat AI divezone.pl"                         │
│  Rola: decyzje, review, planowanie, Q&A eksperckie      │
│  Instrukcja projektu: wklejona (patrz punkt 6)          │
└──────────────┬──────────────────────────────────────────┘
               │ zlecasz zadania
               ▼
┌─────────────────────────────────────────────────────────┐
│  Claude Code (terminal na Twoim Mac)                    │
│  CLAUDE.md w root projektu                              │
│  MCP servers: PostgreSQL, Sequential Thinking           │
│  Rola: implementacja, kodowanie, testy                  │
│  Instancje: backend, embeddings, frontend, integration  │
└──────────────┬──────────────────────────────────────────┘
               │ opcjonalnie
               ▼
┌─────────────────────────────────────────────────────────┐
│  VS Code / Cursor (IDE)                                 │
│  Rola: przeglądanie kodu, ręczne poprawki, Git          │
│  Nie jest wymagane, ale przydatne do review              │
└─────────────────────────────────────────────────────────┘
```

### Dlaczego TEN stack, a nie inny

**Claude.ai Opus 4.6 jako architekt:** Najinteligentniejszy model, najlepszy do złożonych decyzji architektonicznych, planowania i review. Nie marnuj go na pisanie boilerplate. Używaj go do myślenia, nie do kodowania.

**Claude Code jako wykonawca:** Sonnet 4 (domyślny model w Claude Code) jest szybki, tani i świetny do implementacji. Czyta pliki, uruchamia komendy, testuje. Wykonuje to co architekt zaplanował.

**Cursor/VS Code:** Opcjonalny. Przydatny do przeglądania większych plików, porównywania diff, ręcznego Git. Claude Code sam obsługuje Git, ale Cursor ma lepszy UI do review. Cursor ma też natywne wsparcie MCP, więc może korzystać z tych samych serwerów co Claude Code.

**Czego NIE rekomendować:**
- Antigravity, Windsurf, Cline: rozwiązują podobne problemy co Claude Code, ale dodają warstwę abstrakcji. Skoro masz Claude Code z agent teams, to duplikacja.
- Botpress/Voiceflow: do budowania widgetu czatu, nie. Do budowania chatbota tak, ale Ty budujesz integrację z bazą danych, więc potrzebujesz pełnej kontroli.

---

## 6. Instrukcja do wklejenia w sekcji instrukcji Projektu Claude.ai

Tak, powinieneś stworzyć Projekt w Claude.ai i wkleić tam instrukcję. Oto ona:

```
Jesteś architektem i głównym inżynierem projektu czatu AI dla sklepu nurkowego divezone.pl (PrestaShop 1.7.6, prefix tabel: pr_).

PROJEKT:
Czat AI ze wyszukiwaniem semantycznym (pgvector), function calling (Claude/OpenAI API), bazą wiedzy eksperckiej o sprzęcie nurkowym. Moduł PrestaShop + pipeline embeddingów w Pythonie.

TWOJA ROLA:
- Podejmujesz decyzje architektoniczne
- Robisz review kodu i specyfikacji
- Planujesz taski dla Claude Code
- Odpowiadasz na pytania techniczne
- Piszesz specyfikacje i kontrakty między komponentami

NIE ROBISZ:
- Nie piszesz długich bloków kodu (to robi Claude Code)
- Nie powtarzasz kontekstu który jest w plikach projektu

DOKUMENTACJA:
Pliki dokumentacji projektu są w _docs/. Odwołuj się do nich po nazwie.
Gdy tworzysz nowy dokument, zapisz go w _docs/ z numerem porządkowym.
Decyzje architektoniczne zapisuj w _docs/10_decyzje_projektowe.md.

INSTANCJE CLAUDE CODE:
Projekt ma 4 instancje: backend (PHP), embeddings (Python), frontend (JS), integration (testy).
Taski dla instancji zapisuj w _instances/{nazwa}/tasks/.
Handoff między instancjami w _instances/{nazwa}/handoff/.

KONWENCJE:
- PHP: PSR-12, klasy PrestaShop (Product, Category, Order, Customer)
- Python: PEP 8, type hints
- SQL: PostgreSQL dla wektorów, MySQL dla PrestaShop
- Numeruj pytania (kontynuuj numerację z poprzednich rozmów)
- Odpowiadaj po polsku
- Bądź zwięzły, unikaj powtórzeń
```

---

## 7. MCP Servers rekomendowane dla projektu

### Niezbędne

1. **PostgreSQL MCP** - bezpośredni dostęp do bazy pgvector z Claude Code
   ```bash
   claude mcp add --transport stdio postgresql \
     -- npx -y @modelcontextprotocol/server-postgres \
     "postgresql://user:pass@localhost:5432/divechat"
   ```
   Pozwala Claude Code na testowanie zapytań wektorowych, sprawdzanie wyników embeddingów, debug wyszukiwania bez pisania skryptów.

2. **Sequential Thinking MCP** - strukturalne myślenie przy złożonych problemach
   ```bash
   claude mcp add --transport stdio sequential-thinking \
     -- npx -y @modelcontextprotocol/server-sequential-thinking
   ```
   Przydatny przy projektowaniu logiki wyszukiwania hybrydowego, optymalizacji system prompt, planowaniu architektury narzędzi.

### Przydatne

3. **Memory MCP (Claude-Mem)** - persistent memory między sesjami
   Alternatywa dla handoff plików, ale pliki handoff dają Ci większą kontrolę.

4. **Filesystem MCP** - zaawansowane operacje na plikach
   Claude Code ma natywny dostęp do plików, ale ten MCP dodaje wyszukiwanie, watch, batch operations.

### Niepotrzebne w tym projekcie

- GitHub MCP: o ile nie trzymasz projektu na GitHub (jeśli tak, to warto)
- Puppeteer/Playwright MCP: nie potrzebujesz automatyzacji przeglądarki
- Figma MCP: nie ma designu w Figma
- Docker MCP: nie masz Dockera na VPS

---

## 8. Co jeszcze powinieneś wiedzieć i zaplanować

### A. Koszty i budżet

Zaplanuj budżet na API:
- Embeddingi (jednorazowo + daily cron): ~$0.05-0.10/miesiąc
- Czat Claude/OpenAI (per rozmowa z klientem): ~$0.01-0.05
- Claude Code (Twoja praca deweloperska): zależy od planu subskrypcji
- Estymacja: przy 100 rozmowach klientów dziennie to ~$50-150/miesiąc na API czatu

### B. Monitoring i logi

Od dnia 1 loguj:
- Każdą rozmowę (pytanie klienta + odpowiedź AI + wywołane narzędzia)
- Czas odpowiedzi (latency)
- Koszty per rozmowa (tokeny input/output)
- Rozmowy bez dobrych wyników (AI nie znalazło produktu lub dało ogólnikową odpowiedź)

To pozwoli Ci: optymalizować koszty, identyfikować luki w wiedzy, mierzyć jakość.

### C. Plan B jeśli pgvector nie przejdzie

Jeśli hosting nie może zainstalować pgvector:
1. Qdrant bez Dockera (binary Linux, uruchamiasz jako daemon z supervisord)
2. SQLite-vss (Python, zero instalacji, plik na dysku)
3. Osobny tani VPS wyłącznie pod bazę wektorową (np. Hetzner 4 EUR/mies)

### D. Testowanie jakości odpowiedzi

Przed uruchomieniem czatu dla klientów potrzebujesz:
- 50+ par: pytanie klienta → oczekiwana odpowiedź (lub typ odpowiedzi)
- Automatyczna ewaluacja: czy AI użyło właściwego narzędzia, czy znalazło właściwy produkt
- Metryka: % pytań na które AI odpowiedziało trafnie (cel: >85% przed launch)

### E. Kolejność prac (zrewidowany plan)

```
FAZA 0: Przygotowanie
├── [x] Architektura dokumentacji
├── [x] Baza Q&A (draft, 30 wpisów)
├── [x] Baza PostgreSQL na Aiven (pgvector 0.8.1, PG 17.8)
├── [x] Port 22367 otwarty na VPS (whitelist IP)
├── [x] Stworzenie CLAUDE.md w root projektu
├── [x] Stworzenie _instances/ z CLAUDE.md per instancja
├── [x] Schemat bazy danych (_docs/02_schemat_bazy.md)
├── [x] .env.example
├── [ ] Git init
├── [ ] Zebranie 20-30 realnych pytań klientów do testów
├── [ ] Stworzenie Projektu w Claude.ai z instrukcją
├── [ ] Setup Claude Code + MCP servers

FAZA 1: Infrastruktura wektorowa (tydzień 1)
├── [ ] PostgreSQL + pgvector na serwerze
├── [ ] Tabele (migracje SQL)
├── [ ] Pipeline embeddingów (Python)
├── [ ] Test: embedding-3-small vs large na realnych danych
├── [ ] Wgranie embeddingów 3000 produktów
├── [ ] Wgranie bazy Q&A
├── [ ] Test wyszukiwania semantycznego (20-30 zapytań)

FAZA 2: Backend modułu PS (tydzień 2)
├── [ ] Szkielet modułu PrestaShop
├── [ ] Narzędzia (tools) - PHP
├── [ ] AIProvider (Claude + OpenAI)
├── [ ] ChatService (logika, historia, routing)
├── [ ] System prompt
├── [ ] Test function calling na scenariuszach

FAZA 3: Frontend + integracja (tydzień 3)
├── [ ] Widget czatu
├── [ ] Integracja AJAX
├── [ ] Testy end-to-end
├── [ ] Panel admina (podgląd rozmów, zarządzanie Q&A)

FAZA 4: QA + launch (tydzień 4)
├── [ ] Ewaluacja na 50+ scenariuszach
├── [ ] RODO (polityka, zgody)
├── [ ] Beta z 5-10 prawdziwymi klientami
├── [ ] Monitoring kosztów
├── [ ] Deploy
```

### F. Git

Jeśli jeszcze nie masz repo Git dla tego projektu, zrób to teraz. Agent teams w Claude Code używają Git worktree do izolacji pracy agentów. Bez Git nie ma swarmu.

```bash
cd /Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026
git init
echo "_instances/*/handoff/*.md" >> .gitignore
echo "*.pyc" >> .gitignore
echo "__pycache__/" >> .gitignore
git add .
git commit -m "Initial project structure"
```
