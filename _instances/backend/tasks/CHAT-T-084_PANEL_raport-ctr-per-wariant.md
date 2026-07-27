# CHAT-T-084 — PANEL: raport CTR per wariant (endpoint admin + zakładka w panelu PS)

**Instancja:** backend (endpoint) + frontend (UI panelu PS)
**Powiązane:** ADR-090 faza 2, `_docs/22_spec_ab_ctr_nudge.md` (sekcja 7 — kontrakt), CHAT-T-082 (tabela divechat_nudge_events), CHAT-T-085/ADR-092 (kolumna conversations.nudge_sid = konwersja), CHAT-T-086 (zdarzenie nudge_dismiss — idzie RAZEM, jedno wdrożenie modułu PS), ADR-070 (panel = jedyny front admin), ADR-068 (kanał serwerowy), 243a/256b/257a
**Status:** DO ZROBIENIA (faza 2, krok 3/3 — ostatni). Telemetria + korelacja DZIAŁAJĄ (CHAT-T-085, pierwsza konwersja zmierzona). Panel od początku z CZTEREMA metrykami (256b/257a): ekspozycje, kliki(CTA), zamknięcia(X), zignorowane(reszta).
**Koordynacja:** CHAT-T-086 dodaje event_type='nudge_dismiss' (migracja 027 + beacon X w loaderze). Ten task konsumuje dismiss w endpoincie/panelu. Backend obu (migracja+whitelist+endpoint) wdrażany razem; moduł PS (loader z CHAT-T-086 + AdminDivezoneChatController z tego tasku) jedną komendą rsync na końcu.

---

## CEL
Delegowalny raport CTR w panelu PS: dwa wskaźniki per wariant (CTR ekranu + konwersja) + liczności + flaga małej próby. Bez surowego SQL (243a, ADR-070).

## DANE — gotowe na PROD
- `divechat_nudge_events` (session_id, event_type, bucket, ab_active, created_at) — ekspozycje/kliki.
- `divechat_conversations.nudge_sid` (CHAT-T-085) — atrybucja konwersji. JOIN: events.session_id ↔ conversations.nudge_sid.
- Konwersja = rozmowy z ≥1 wiadomością UŻYTKOWNIKA. UWAGA: `messages` jsonb zawiera role user/assistant/tool — licz tylko `role='user'` (dokładniejsze niż `jsonb_array_length>=1`, które łapie też bota). Wzór: zobacz jak CostAnalytics/ConversationStore wyłuskują first_user_message.

## ZAKRES
1. BACKEND: endpoint `GET /api/admin/nudge-ctr` (kanał serwerowy, `ServerHmacVerifier` — wzór: `AdminAnalyticsController` w routes.php + src/Controller/). Nowy `AdminNudgeCtrController` (osobny, NIE mieszać z CostAnalytics).
2. FRONTEND (moduł PS): zakładka/sekcja "CTR zachęty" w `controllers/admin/AdminDivezoneChatController.php` (wzór: zakładka Analityka CHAT-T-050, stałe ENDPOINT_*, metoda callBackend). UI moduł PS wgrywa Karol (116b).

## SZCZEGÓŁY (sekcja 7 spec)

### Endpoint `GET /api/admin/nudge-ctr`
- Kanał serwerowy (DIVECHAT_SERVER_SECRET, jak cost/*). Rola admin-only (spójnie z AdminAnalyticsController).
- Query agreguje per bucket (v1, v2), ROZDZIELONE po `ab_active` (true=test A/B, false=baseline z przełącznika):
  - `shown` = COUNT DISTINCT session_id WHERE event_type='nudge_shown'
  - `clicks` = COUNT DISTINCT session_id WHERE event_type='nudge_cta_click'
  - `dismissals` = COUNT DISTINCT session_id WHERE event_type='nudge_dismiss' (CHAT-T-086, klik X)
  - `ignored` = shown − clicks − dismissals (reszta: brak reakcji w sesji — wyliczane, NIE osobne zdarzenie; 257a)
  - `ctr` = clicks/shown (null gdy shown=0)
  - `dismiss_rate` = dismissals/shown
  - `conversations` = COUNT DISTINCT c.nudge_sid gdzie rozmowa ma ≥1 wiadomość role='user' (JOIN events.session_id = c.nudge_sid)
  - `conversion_rate` = conversations/shown
- Param `?days=N` opcjonalny (default np. 30). Jeśli istnieje filtr botów (filter_bots) — rozważ, ale nie blokuj MVP.
- Zwraca JSON: tablica wierszy {bucket, ab_active, shown, clicks, dismissals, ignored, ctr, dismiss_rate, conversations, conversion_rate}.
- UWAGA spójności: clicks + dismissals + ignored = shown (z definicji). W rzadkich przypadkach (ten sam user kliknął CTA I X w różnych ekspozycjach — nie powinno przy dedupie per sesja, ale teoretycznie) ignored mogłoby wyjść ujemne → w endpoincie clampuj `ignored = max(0, shown − clicks − dismissals)` i NIE traktuj jako błąd.

### UI panel PS
- Tabela: wiersze v1/v2 (× baseline/A/B jeśli oba mają dane), kolumny: Ekspozycje | Kliki(CTA) | CTR % | Zamknięcia(X) | Zamknięcia % | Zignorowane | Rozmowy | Konwersja %.
- **Flaga małej próby:** gdy `shown` < próg (stała 100, sekcja 7 spec) → wizualne "za mała próba na wnioski" przy wierszu. NIE pokazuj CTR/wskaźników jako twardej liczby bez tej flagi gdy próba mała (np. "50% z 2 ekspozycji" myli).
- Krótki opis nad tabelą: CTR = klik CTA÷ekspozycja; zamknięcia = klik X÷ekspozycja (świadome odrzucenie); zignorowane = reszta (brak reakcji w sesji — NIE twardy sygnał odrzucenia, mogli przewinąć/wyjść; 257a); konwersja = rozmowa(≥1 wiad. usera)÷ekspozycja. Dane służą porównaniu v1↔v2. Wzmianka: stare rozmowy sprzed CHAT-T-085 mają nudge_sid=NULL (konwersja liczona od wdrożenia); zamknięcia liczone od wdrożenia CHAT-T-086.
- Wartość diagnostyczna (dla Karola, NIE musi być w UI): wysokie zamknięcia + niski CTR = treść odpycha; niskie zamknięcia + niski CTR + wysokie zignorowane = widget niezauważany (problem widoczności/timingu, nie treści).
- Styl spójny z istniejącymi zakładkami (Analityka, Rozmowy). Odczyt przez callBackend (kanał serwerowy).

### Istotność — MVP bez przeinżynierowania
- Faza 1 raportu: liczności + flaga małej próby WYSTARCZĄ. Pełny test istotności (z-test proporcji) NIE w tym tasku — dodaj najwyżej prostą adnotację. Prostota > kompletność statystyczna w MVP. Karol interpretuje liczby sam, flaga chroni przed wnioskami z szumu.

## KRYTERIA AKCEPTACJI
1. `GET /api/admin/nudge-ctr` z poprawnym podpisem serwerowym → JSON z metrykami per bucket/ab_active; bez podpisu → odrzucony jak inne admin endpointy.
2. CTR, zamknięcia, zignorowane i konwersja policzone poprawnie — zweryfikuj ręcznie na kilku wierszach (porównaj z verify_nudge_correlation.php; konwersja liczy tylko rozmowy z user-msg po nudge_sid; clicks+dismissals+ignored=shown).
3. Panel PS: zakładka "CTR zachęty" pokazuje tabelę v1/v2 z czterema metrykami (kliki/zamknięcia/zignorowane/konwersja); flaga małej próby przy shown<100.
4. Baseline (ab_active=false) i test (true) rozróżnione gdy oba mają dane.
5. Zero wpływu na resztę panelu i backendu; istniejące zakładki/endpointy działają.
6. php -l clean.

## DEPLOY
- Endpoint (standalone): ADR-089 (backup _deploy_bak/CHAT-T-084/, rsync per ścieżka, smoke /api/health + curl nudge-ctr z podpisem, STOP-point). CC wdraża sam.
- UI moduł PS: 116b (Karol ręcznie, CC podaje rsync port 5739 ~/public_html/newtmp2 --exclude config_pl.xml bez --delete). DRUGI STOP.

## GIT
`git add` per ścieżka (AdminNudgeCtrController, routes.php, AdminDivezoneChatController.php). Commit wg konwencji git log. Push origin main. Po deploy osobny commit `docs:` status + NOTATKA: faza 2 ADR-090 KOMPLETNA (telemetria + korelacja + panel).

## UWAGA — numeracja ADR
Ten task NIE tworzy nowego ADR (mieści się w ADR-090 faza 2). Gdyby jednak pojawiła się nowa decyzja architektoniczna — sprawdź ostatni wolny numer ADR w _docs/10_decyzje_projektowe.md PRZED nadaniem (konwencja CLAUDE.md; ostatni to ADR-092).
