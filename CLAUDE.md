# Czat AI divezone.pl

## Opis projektu
Czat AI ze wyszukiwaniem semantycznym dla sklepu nurkowego divezone.pl (PrestaShop 1.7.6, prefix tabel: pr_). Wykorzystuje pgvector, function calling (Claude/OpenAI API), bazę wiedzy ekspercką. Architektura hybrydowa: moduł PS (widget + panel admina) + standalone API na chat.divezone.pl (PHP 8.4).

## ⚠️ MAPA INFRASTRUKTURY I WDROŻEŃ — dla architekta (czytaj PRZED pisaniem tasków i deployem)

**DWA OSOBNE ŚWIATY WDROŻENIOWE — nie mylić, każdy to inny rsync w inne miejsce:**

- **ŚWIAT 1 — BACKEND standalone.** Osobna domena `chat.divezone.pl`, PHP 8.4. Kod na serwerze w `~/public_html/chat.divezone.pl/src|public|config` (BEZ prefiksu `standalone/` — w repo lokalnym jest `standalone/`). Deploy = rsync `standalone/` → `chat.divezone.pl/` + backup `_deploy_bak/` + md5 + `php -l` + smoke `/api/health`, STOP przed rsync (ADR-089). Łączy się z Railway PG (`divechat_*`, `chip_path`) i MySQL PrestaShop (read-only).
- **ŚWIAT 2 — SKLEP + WIDGET + PANEL PS.** Cała instalacja PrestaShop w `~/public_html/newtmp2` (**newtmp2 TO PRODUKCJA sklepu**). Moduł czatu w `~/public_html/newtmp2/modules/divezone_chat/`. Widget: `modules/divezone_chat/views/js/widget-bundle.js` + `transport.js`. Panel admina/recenzji: `modules/divezone_chat/controllers/admin/AdminDivezoneChatController.php`. Deploy = ręczny rsync Karola (port 5739, `--exclude config_pl.xml`, bez `--delete`).

**Task frontend/widget/panel-PS → ŚWIAT 2. Task backend/API → ŚWIAT 1.** To dwa różne rsynce w dwa różne katalogi. Zmiana widgetu/panelu PS NIE działa dopóki nie trafi do `newtmp2` (weryfikować md5 + grep markerów taska w pliku na produkcji).

**DRYF `config/tools.php` (repo ≠ prod) — pułapka deployu (CHAT-T-132, incydent 500 2026-07-14):** repozytoryjny `tools.php` rejestruje WSZYSTKIE narzędzia, w tym `ProductCombinations` (CHAT-T-129 — zacommitowane, ale CELOWO niewdrożone, czeka na kolumnę `nazwa_pl` z projektu Atrybuty). Klasy nie ma na serwerze → rsync repo `tools.php` 1:1 daje fatal „Class not found" i `/api/health` 500. Zasada: przy KAŻDYM deployu dotykającym `tools.php` NAJPIERW `diff` z wersją produkcyjną i deploy wariantu produkcyjnego z własną zmianą (dopisać tylko nowe narzędzie), albo wdrożyć CHAT-T-129 w komplecie (co dryf zlikwiduje). Smoke `/api/health` po rsync jest bramką, która to łapie — nie pomijać.

**Hasło MySQL sklepu w `parameters.php`: klucz to `database_password`, NIE `database_pass` (CHAT-T-136).** `mysql` CLI potrafi odrzucić hasło wyciągnięte z tego pliku (znaki specjalne) — pewniejsze jest PDO na parametrach PS. Dotyczy operacji na MySQL sklepu z poziomu modułu/skryptu.

**Rejestracja hooka PS na żywym module (CHAT-T-136):** `install()` NIE wykona się ponownie na zainstalowanym module, więc nowy hook trzeba dodać `INSERT`em do `pr_hook_module` (potrzebne: `id_module` z `pr_module`, `id_hook` z `pr_hook`, `id_shop`, `position` = MAX+1). **Po rejestracji trzeba WYCZYŚCIĆ cache PS drugi raz** — PS cache'uje mapę hook→moduł i bez tego hook nie odpala mimo wpisu w bazie. Kolejność: rsync → cache → tabela/SQL → DOPIERO hook (hook odpala natychmiast po rejestracji). Rollback: `DELETE FROM pr_hook_module WHERE id_module=? AND id_hook=?`.

**DWA PANELE ADMINA (źródło realnej pomyłki):**
- **Panel recenzji rozmów = moduł PS** (`AdminDivezoneChatController`, nagłówek „Przebieg rozmowy", endpoint `/api/conversations/{sid}`). **TEN używa Karol.**
- Standalone `/admin` (`chat.divezone.pl/admin`, `admin-conversation.js`, `/api/admin/conversations/:id`) jest **WYGASZANY (ADR-070)** — panel PS to jedyny docelowy front administracyjny. NIE kierować tam nowych funkcji recenzji.

**CACHE po wdrożeniu do modułu (newtmp2):** po rsync ZAWSZE (1) skasować `var/cache/prod` w PrestaShop, (2) wyczyścić LSCache (LiteSpeed). Front trzyma bundle w cache przeglądarki (`?v=md5_8` pomaga, ale przy testach twardy refresh / incognito).

**PHP na serwerze:** domyślny CLI po SSH = PHP 8.3. Do `php -l` / PHP 8.4 używać `ea-php84` (stara ścieżka `/usr/local/php84/bin/php` NIE działa).

**REGUŁA BRZYTWY OKHAMA:** po każdym deployu widgetu/modułu NAJPIERW najprostsza hipoteza = cache (przeglądarka + sklep), zanim diagnozować kod/API. Nie rozbierać na części tego, co już zweryfikowane jako poprawne.

**GIT NA SMB (repo leży na sieciowym share `/Volumes/karol`):** operacje gita bywają wolne, a `git add` sporadycznie pada na `fatal: unable to write new index file` (błąd przejściowy — ponowienie zawsze pomaga, nic nie ginie; przyczyna niezdiagnozowana, sam zapis i rename działają w izolacji 20/20 i 30/30). Usprawnienie zmierzone 2026-07-15: `git config core.untrackedCache true` + `git config core.preloadIndex true` → `git status` z 0,98 s na ~0,07 s (13x). Ustawienia są lokalne dla klonu (`.git/config`, niewersjonowane) — po świeżym klonie trzeba włączyć ponownie. Gdy błąd zapisu wróci: po prostu ponowić.

## Status projektu (2026-02-20)

### Ukończone
- [x] Architektura: ADR-001 do ADR-018 w _docs/10_decyzje_projektowe.md
- [x] Baza Q&A: 37 wpisów z embeddingami (divechat_knowledge)
- [x] Embeddingi produktów: 2670 aktywnych produktów (divechat_product_embeddings)
- [x] Model embeddingów: text-embedding-3-large, dimensions=1536 (ADR-012)
- [x] Mapa 79 marek aktywnych w sklepie (_docs/11_mapa_marek.md)
- [x] TASK-006a: Standalone skeleton (routing, auth, DB connections) ✅
- [x] TASK-006b: AI Providers, Tools, ChatService (20 plików) ✅
- [x] Testy: Claude Sonnet 4 + GPT-5.2, tool loop z pgvector działa

### W toku
- [x] **Migracja bazy z Aiven → Railway** (ADR-019) — ZAKOŃCZONA. PROD czatu łączy się z Railway (zweryfikowane 2026-06-07: realny Config::load → DATABASE_URL = switchback.proxy.rlwy.net:14368/railway, dzisiejsze rozmowy w tej bazie). **Aiven = MARTWY, NIE używać** (zakomentowany w .env jako "STARE"). Wszystkie migracje SQL i połączenia → Railway.
- [ ] **Review kodu TASK-006b** (ChatService, Providers, Tools, SystemPrompt)
- [ ] TASK-006c: Cienki moduł PrestaShop (~100 linii PHP 7.2)

### Następne
- [ ] Widget JS (frontend instance)
- [ ] Panel admina (chat.divezone.pl/admin)
- [ ] Migracja bazy wiedzy do hierarchii 4-poziomowej (ADR-018)
- [ ] Testy modeli AI (_docs/12_plan_testow_modeli.md)

## Infrastruktura

### PostgreSQL (Railway, pgvector) — NOWE od 2026-02-20
```
Host: switchback.proxy.rlwy.net
Port: 14368
Database: railway
User: postgres
SSL: nie wymagane (TCP proxy)
pgvector: 0.8.1 | PG: 18.2
Connection string: postgresql://postgres:<RAILWAY_PASSWORD_REDACTED>@switchback.proxy.rlwy.net:14368/railway
```
**Status:** AKTYWNA i UŻYWANA NA PROD (zweryfikowane 2026-06-07). To JEDYNA baza PG czatu — wszystkie migracje i połączenia tutaj.
**Aiven:** MARTWY, porzucony (IP 159.223.235.232 blacklista AbuseIPDB). W .env zakomentowany jako "STARE, nie używać". NIE uruchamiać na nim migracji.

### Standalone API (chat.divezone.pl, PHP 8.4)
```
Docroot: /home/divezone/public_html/chat.divezone.pl/public/
PHP: ea-php84
Composer: 2.8.12
```

### PrestaShop (VPS divezone.pl)
```
PHP: 7.2 (domyślne CLI), ea-php84 dla subdomeny chat
MySQL prefix: pr_
DB: divezone_2025
```

## Struktura projektu
```
Chat_dla_klientow_2026/
├── _docs/                    # Dokumentacja (czytaj PRZED pracą)
├── _instances/               # Instancje Claude Code (taski, handoff)
│   ├── backend/              # PHP standalone
│   ├── embeddings/           # Python pipeline
│   ├── frontend/             # JS widget
│   └── integration/          # Testy
├── standalone/               # ← GŁÓWNY KOD BACKEND (PHP 8.4)
│   ├── public/index.php      # Front controller
│   ├── src/                  # PSR-4: DiveChat\
│   │   ├── AI/               # Providers (Claude, OpenAI)
│   │   ├── Chat/             # ChatService, SystemPrompt, ConversationStore
│   │   ├── Tools/            # 5 narzędzi (ProductSearch, ExpertKnowledge, ...)
│   │   ├── Enum/             # AIModel, SearchStrategy
│   │   ├── Auth/             # HmacVerifier
│   │   ├── Controller/       # Health, Chat
│   │   ├── Database/         # PostgresConnection, MysqlConnection
│   │   └── Http/             # Request, Response
│   ├── config/               # routes.php, tools.php
│   └── composer.json
├── modules/divezone_chat/    # Cienki moduł PS (TASK-006c, do zrobienia)
├── embeddings/               # Pipeline Python
└── sql/                      # Migracje PostgreSQL
```

## Dokumentacja (_docs/)
- `00_architektura_projektu.md` — architektura systemu
- `02_schemat_bazy.md` — tabele PostgreSQL (vector 1536 dim)
- `04_qa_baza_wiedzy.md` — baza Q&A (37 wpisów)
- `08_testy_i_ewaluacja.md` — pytania testowe, metryki
- `10_decyzje_projektowe.md` — ADR-001 do ADR-019
- `11_mapa_marek.md` — 79 marek aktywnych
- `11_workflow_i_organizacja.md` — workflow, instancje
- `12_plan_testow_modeli.md` — plan testów Claude/OpenAI
- `13_wymagania_panel_admina.md` — wymagania panelu admina
- `14_architektura_bazy_wiedzy.md` — hierarchia 4-poziomowa
- `42_weryfikacja_czatow_procedura.md` — **jak weryfikowac czaty do naprawy**
  (dane w Railway PG `divechat_conversation_review`, narzedzia w
  `_diag_local/chat_verification/`, backlog w Trello "Projekty 2026"). Czytaj to
  ZAWSZE przy zadaniu "przejrzyj/zweryfikuj czaty do naprawy".
- `CONVENTIONS.md` — konwencje kodu

## Konwencje
- Standalone PHP 8.4: typed properties, enums, match, readonly, PSR-4, namespace DiveChat\
- Moduł PS PHP 7.2: BRAK typed properties/enums/match, PSR-12, prefix DiveChat
- Python: PEP 8, type hints
- SQL: PostgreSQL prefix divechat_, MySQL prefix pr_
- Komentarze: po polsku. Zmienne/funkcje: po angielsku
- **Numeracja ADR (profilaktyka kolizji):** przed utworzeniem nowego ADR ZAWSZE sprawdź ostatni użyty numer w `_docs/10_decyzje_projektowe.md` (np. `grep '^### ADR-' _docs/10_decyzje_projektowe.md | tail`) i nadaj kolejny wolny. Plik jest współdzielony przez równoległe linie prac (czat CHAT-T-*, encyklopedia TASK-ENC-*) — dwa zadania pisane w zbliżonym czasie mogą sięgnąć po ten sam numer. Kolizja zdarzyła się raz (dwa ADR-091: TASK-ENC-014 wyporność + CHAT-T-085 nudge → renumerowane na ADR-092). Jeśli numer już zajęty: weź następny wolny i dopisz w nagłówku adnotację o renumeracji (commit/kod mogą wskazywać stary numer).
- **Git przy równoległych instancjach CC (ADR-103 dec. P44c):** gdy w jednym repo pracuje kilka instancji CC naraz, NIE wchodź w KROK-git (add/commit/push) jednocześnie z inną instancją — to powoduje wyścig o `index.lock` i mieszanie cudzych zastage'owanych plików pod swoim commitem (zdarzyło się 2026-06-29: CHAT-T-088f vs CHAT-T-109). Zasada: przed `git add` sprawdź `git status` pod kątem cudzych zmian w indeksie; jeśli widzisz pliki spoza swojego taska — NIE commituj ich, zrób soft-reset do czystego indeksu i `git add` wyłącznie własnych ścieżek. Jeśli inna instancja właśnie pushuje, poczekaj z własnym push do zwolnienia. NIGDY nie przepisuj opublikowanej historii (rebase/force-push) w repo z aktywnymi równoległymi pushami — ryzyko utraty cudzej pracy przewyższa zysk z czystej atrybucji.
