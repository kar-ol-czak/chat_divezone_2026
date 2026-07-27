# Handoff: Migracja panelu admin do PrestaShop (nowy czat)

**Data:** 2026-06-02 | **Powiazane:** ADR-070 (panel PS jako jedyny front), ADR-068 (kanal serwerowy).

## Cel programu
Przeniesc CALY panel admin ze standalone /admin (Basic Auth + .htpasswd) do modulu PrestaShop jako zakladki, kazda wolajaca backend przez kanal serwerowy (ServerHmacVerifier). Docelowo wylaczyc /admin. Decyzja Karola: wszystko w jednym miejscu (PS).

## Co jest dzis w starym /admin (standalone/public/admin/, single-page index.html, data-tab)
- KPI kosztow (admin.js loadKpi -> /api/admin/cost/kpi)
- Wykres trendu (admin-charts.js -> /api/admin/cost/trend)
- Tabele: top konwersacje + koszt per model (admin-tables.js -> /api/admin/conversations/top, /api/admin/cost/by-model)
- Szczegoly konwersacji (admin-conversation.js -> /api/admin/conversations/{id})
- Editorial picks (admin-editorial.js — rozbudowany: CRUD, autocomplete produktow, sortowanie -> /api/admin/editorial-picks)

## Stan auth endpointow (WAZNE dla migracji)
- /api/admin/cost/*, /api/admin/conversations/*, /api/admin/editorial-picks* -> dzis za AdminAuthMiddleware (Basic Auth + .htpasswd).
- Panel PS uzywa KANALU SERWEROWEGO (ServerHmacVerifier, HMAC employee_id, sekret DIVECHAT_SERVER_SECRET).
- => Migracja kazdej zakladki analityki wymaga NAJPIERW przelaczenia endpointu z Basic Auth na kanal serwerowy, POTEM UI w PS. Para: backend auth -> PS UI.
- /api/conversations/* (stary history.js) -> JUZ przelaczone na kanal serwerowy (CHAT-T-046, any-role). Backend gotowy, brakuje tylko UI w PS.

## Wzorzec wpinania zakladki w PS (ustalony w CHAT-T-045)
AdminDivezoneChatController.php: nowa stala TAB_* + warunek w initContent + sekcja renderXSection(). callBackend(endpoint, employeeId) wola backend z HMAC serwerowym (wzorzec GET dziala; POST dodany w CHAT-T-045). Render = PHP generuje HTML (jak renderRecommendationsSection / renderModelsSection).
Istniejace zakladki: Rekomendacje, Modele (+ whoami diagnostyka). Konfiguracja sekretow/IP zostaje OSOBNO w getContent() (divezone_chat.php) — lokalny zapis PS, NIE ruszac.

## Kolejnosc migracji (decyzja 103a — wg rosnacej trudnosci, kazda zakladka domknieta przed nastepna)
1. **Rozmowy** (PILOTAZ, NASTEPNY TASK = CHAT-T-047): backend GOTOWY (/api/conversations/* za kanalem serwerowym). Tylko UI w PS: lista rozmow + szczegoly. Ustala wzorzec migracji zakladki "z danymi z backendu".
2. **Analityka** (KPI + trend + tabele + szczegoly konwersacji): NAJPIERW przelaczyc /api/admin/cost/* + /api/admin/conversations/* z Basic Auth na kanal serwerowy (rola: admin? any? — do decyzji, koszty raczej admin), POTEM UI (wykresy w PS — Chart.js lub natywne). Wieksza.
3. **Editorial picks**: najwiekszy (CRUD + autocomplete + sortowanie). Przelaczyc /api/admin/editorial-picks na kanal serwerowy + duzy UI. Na koncu.
4. **Wylaczenie /admin** + .htpasswd, gdy wszystko dziala w PS.

## Pierwszy task do zlozenia w nowym czacie
CHAT-T-047 — FRONTEND/PS: zakladka "Rozmowy" (UI, backend gotowy). Lista rozmow (/api/conversations) + szczegoly (/api/conversations/{session_id}) przez callBackend (any-role). Pilotaz wzorca migracji. Po nim ocena i Etap 2 (Analityka, z praca backendowa).

## Zasady projektu (przypomnienie dla nowego czatu)
- Claude = architekt (ADR/specy/prompty), NIE koduje. CC implementuje przez SSH.
- Sciezka projektu (Desktop Commander): /Volumes/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026 (bash bez wolumenu — uzywac DC).
- Taski: prefiks CHAT-T-NNN, plik w _instances/<instancja>/tasks/, prompt CC w czacie (naglowek + KROK 0..N, KROK 0 = pull/read, ostatni = git + raport). Handoffy lokalne (.gitignore). Git: add per sciezka, commit wg konwencji, push origin main, osobny docs: commit.
- Kazde pytanie do Karola: numerowane + wlasna rekomendacja. Zwiezle, po polsku, bez polpauz.
- Bezpieczenstwo: kazdy endpoint wolany przez panel MUSI byc za kanalem serwerowym (przy okazji migracji sprawdzac auth — audyt CHAT-T-044 pokazal ze sie oplaca).

---

## AKTUALIZACJA 2026-06-02 (decyzje 117a / 118a — doprecyzowanie celu)

### Cel ostateczny: WSZYSTKO w jednym miejscu (decyzja 117a)
Wszystkie funkcje jako ZAKLADKI na jednym pasku w panelu roboczym "DiveZone Chat" (menu boczne), NIE rozproszone w 2 miejscach jak dzis (panel roboczy + stary /admin) i NIE jako podmenu. Jeden ekran, pasek zakladek u gory, przelaczanie po stronie widoku (wzorzec juz dziala: TAB_RECOMMENDATIONS/TAB_MODELS/TAB_CONFIG w AdminDivezoneChatController).

### Uklad zakladek wg CZESTOSCI uzycia (nie alfabetycznie, nie wg kolejnosci budowy)
Kolejnosc na pasku + domyslna zakladka odbijaja jak pracuje obsługa:
1. **Rozmowy** — DOMYSLNA zakladka (activeTab default). Uzywane CALY CZAS (obsługa widzi rozmowy klientow). Dzis domyslna jest Rekomendacje — ZMIENIC na Rozmowy.
2. **Analityka** — czesto.
3. **Rekomendacje** — kilka razy potem rzadko.
4. **Modele** — kilka razy potem rzadko.
5. **Drzewo chipow** — przy projektowaniu, potem bardzo rzadko (gdy powstanie, ADR-071).
6. **Konfiguracja** — prawie nigdy (sekrety/IP/drabina ustawiane raz). Najdalej z prawej.
Zasada: najczestsze blisko/domyslne (zero klikniec), najrzadsze dalej (jedno klikniecie). Rozmowy = zero klikniec po otwarciu panelu.

### Zasada kodu (wazne przy 6 zakladkach)
Kazda zakladka = osobna, dobrze wydzielona sekcja: osobny renderXSection() + osobny plik widoku/JS jesli ciezka (Analityka=wykresy, Editorial=CRUD+autocomplete). NIE upychac wszystkiego w jeden monolityczny blok — AdminDivezoneChatController ma pozostac czytelny mimo 6 zakladek. Ciezkie UI (wykresy, autocomplete) moga miec wlasne pliki views/js/.

### Kolejnosc BUDOWY (decyzja 118a — wg trudnosci, NIE wg ukladu na pasku)
Budujemy w innej kolejnosci niz uklad wizualny — wg rosnacej trudnosci, kazda domknieta przed nastepna:
1. **Rozmowy** (CHAT-T-048, NASTEPNY): backend GOTOWY (/api/conversations/* za kanalem serwerowym, CHAT-T-046). Tylko UI w PS. Pilotaz wzorca migracji ciezkiej zakladki. Przy okazji: ustaw Rozmowy jako domyslna zakladke (activeTab).
2. **Analityka**: NAJPIERW przelaczyc /api/admin/cost/* + /api/admin/conversations/* z Basic Auth (AdminAuthMiddleware) na kanal serwerowy (rola — koszty raczej admin-only, do decyzji), POTEM UI (wykresy — Chart.js lub natywne). Wieksza.
3. **Editorial picks**: najwiekszy (CRUD + autocomplete produktow + sortowanie). Przelaczyc /api/admin/editorial-picks na kanal serwerowy + duzy UI. Na koncu.
4. **Wylaczenie /admin** + .htpasswd, gdy wszystko w PS.
(Drzewo chipow dochodzi osobno, po sesji zespolu nad ADR-071 — nie czesc tej migracji, ale docelowo zakladka nr 5 na pasku.)

### Konwencja wdrazania (pamiec 116b — WAZNE dla kazdego taska na modul PS)
Zmiany w MODULE PrestaShop wgrywa KAROL recznie (kontrola nad zywym sklepem newtmp2), CC NIE wgrywa samo. CC w kazdym tasku na modul PS: przeczytac .env i podac KOMPLETNA komende rsync (port 5739, sciezka ~/public_html/newtmp2, --exclude config_pl.xml, bez --delete, BEZ placeholderow). Standalone backend chat.divezone.pl — CC wdraza samo.

### Pierwszy task nowego czatu
CHAT-T-048 — FRONTEND/PS: zakladka "Rozmowy" + ustawienie jej jako domyslnej. UI: lista rozmow (/api/conversations) + szczegoly (/api/conversations/{session_id}) przez callBackend (any-role, backend gotowy CHAT-T-046). Wzorzec jak renderModelsSection. Pilotaz — ustala jak migrowac ciezka zakladke z danymi z backendu.
