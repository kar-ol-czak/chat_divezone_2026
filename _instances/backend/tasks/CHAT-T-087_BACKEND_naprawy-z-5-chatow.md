# CHAT-T-087 — BACKEND: naprawy z 5 chatów (cena reduction_tax, voucher drill-down, dostawa 2b, linki/konto)

**Instancja:** backend (PHP) — kod + SystemPrompt + seed config + rejestr toola. Bez frontu.
**Powiązane:** ADR-093 (cena `reduction_tax`), ADR-094 (drill-down details), ADR-095 (dostawa + linki/konto). Diagnoza 5 chatów (logi Railway + MySQL PROD, sesja 2026-06-12).
**Status:** DONE (A+B+C wdrożone i zweryfikowane na PROD). Priorytet KROK A = P1 (dotyczy ~98 produktów). KROK B/C = P2.

## Wynik
- **KROK A** (fix ceny `reduction_tax`, ADR-093): wdrożony, PROD `enrich([5463])`=1900.00 (było 1707), `price_before_discount`=2739. Commit `cf86a52` + docs `0dd11c8` (status v3.58).
- **KROK B** (SystemPrompt: drill-down ADR-094, dostawa in_stock 15:00 ADR-095 dec.1, sekcja kont/linków ADR-095 dec.2) + **KROK C** (`get_shop_links` 14 kluczy + migracja `028` na Railway + rejestracja): wdrożone. PROD `get_shop_links(topic=payment)` → konta + kontakt + płatności. Commit `bbd2792` + docs `cd22662` (status v3.59).
- Testy: `ProductPriceTest` 10/10, `GetShopLinksTest` 31/31. Deploy ADR-089 (md5 match, php -l ea-php84, /api/health 200).

---

## CEL
Naprawić 4 klasy błędów wykryte w realnych rozmowach:
- **A (P1):** cena zaniżana dla rabatów kwotowych brutto — `enrich()` ignoruje `reduction_tax`. 98 produktów.
- **B1:** bot mówi „nie wiem” o atrybut produktu (ważność vouchera) bez drill-down do `get_product_details`.
- **B2:** komunikat dostawy „dziś→jutro” (cut-off 15:00) z asekuracją (poluzowanie 2b).
- **B3+C:** numery kont + kluczowe linki sklepu — nowe narzędzie `get_shop_links` + seed do `divechat_shop_config`.

## KONTEKST (z analizy kodu i PROD — NIE szukaj od nowa)
- **Cena:** `src/Shop/MysqlProductEnrichmentService.php`, metoda `enrich()` + `fetchSpecificPrices()`. Dla `reduction_type='amount'` odejmuje `reduction` od NETTO, potem ×VAT. Kolumna `reduction_tax` NIE jest pobierana ani używana. PROD: produkt 5463 ma `reduction=839, amount, reduction_tax=1` (brutto) → my liczymy 1707, PrestaShop 1900.
- **Drill-down:** `src/Chat/SystemPrompt.php`. `ProductDetails::execute()` zwraca `strip_tags(description)`. Vouchery (id 4649–4653) MAJĄ ważność w `description`. Bot w chacie ef24adba nie wywołał żadnego toola.
- **Dostawa:** SystemPrompt sekcja „WAŻNE rozróżnienie / NIGDY nie obiecuj…” (twardy zakaz dat doręczenia). Trzeba dodać wyjątek probabilistyczny dla in_stock + cut-off 15:00.
- **Linki/konto:** tabela `divechat_shop_config` (PG, kolumny: key text, value text, note text, updated_at). Dziś 1 wiersz `free_shipping_threshold_pl=299`. Wzorzec toola: `src/Tools/ShippingInfo.php` (czyta z PG, zwraca array). Rejestr tooli: `config/tools.php` + `src/Tools/ToolRegistry.php`.
- Migracje: pliki `sql/NNN_*.sql` aplikowane RĘCZNIE na Railway (brak tabeli migracji). Ostatnia 027. Następna wolna: **028** (tylko seed INSERT-y, bez zmian strukturalnych — `divechat_shop_config` już istnieje).

---

## ZAKRES

### KROK A (P1) — fix ceny `reduction_tax` w MysqlProductEnrichmentService
A1. `fetchSpecificPrices()`: dodać `sp.reduction_tax` do SELECT i do zwracanej struktury (`'reduction_tax' => (int) $row['reduction_tax']`).
A2. `enrich()`: dla `reduction_type='amount'` rozgałęzić wg `reduction_tax`:
   - `reduction_tax=1` (rabat BRUTTO): policz `baseBrutto = round(priceNetto*(1+rate/100),2)`, a `priceBrutto = round(baseBrutto - reduction, 2)`. (NIE odejmuj od netto, NIE mnóż rabatu przez VAT.)
   - `reduction_tax=0` (rabat NETTO): zachowanie dotychczasowe (odejmij od netto, potem ×VAT).
   - `percentage`: bez zmian.
   - Uwaga na `sp_price>0` (cena nadpisana) — zachowaj dotychczasową obsługę `$base`, rozgałęzienie dotyczy tylko części `reduction>0`.
A3. `price_before_discount` (baza brutto) bez zmian. Nie pokazuj `price_before_discount` gdy ≤ finalnej (już jest taki guard).
A4. Po fixie: sprawdzić, czy `get_curated_recommendations` używa świeżego `enrich()` czy snapshotu (`verified_at`). Jeśli snapshot cen jest w `divechat_curated_recommendations` — zgłosić w raporcie potrzebę reseedu (skrypt `reseed_curated_candidates.php`). NIE reseeduj w tym kroku bez potwierdzenia Karola.
A5. Test: napisać/rozszerzyć test w `tests/Shop/` — `enrich([5463])['price']` oczekiwane 1900.00; regresja dla `amount+reduction_tax=0` i `percentage` (ceny bez zmian). Jeśli test wymaga MySQL, oprzeć na istniejącym wzorcu testów Shop (mock/integration jak w repo).

### KROK B1 — SystemPrompt: drill-down do get_product_details (ADR-094)
Dodać sekcję „DRILL-DOWN DO SZCZEGÓŁÓW PRODUKTU”: pytanie o atrybut/cechę/warunek konkretnego produktu (ważność, wymiary, długość, ciśnienie robocze, materiał, gwarancja, zawartość zestawu, kompatybilność) ⇒ MUSISZ wywołać search_products → get_product_details ZANIM powiesz „nie mam informacji”. Dopiero gdy description/features faktycznie nie zawierają odpowiedzi → „nie znalazłem tej informacji w opisie, potwierdzę na dive@divezone.pl / 56 307 03 03”. Few-shot voucher: „ile ważny jest voucher?” → search_products(query=voucher, category="Vouchery prezentowe") → get_product_details → „jednorazowy, ważny 1 rok od daty zakupu”. NIE wpisywać ważności na sztywno. NIE robić drill-down przy pytaniach czysto edukacyjnych (te → get_expert_knowledge).

### KROK B2 — SystemPrompt: dostawa „dziś→jutro” 2b + cut-off 15:00 (ADR-095 dec.1)
W sekcji o doręczeniu dodać kontrolowany wyjątek dla produktów `in_stock`:
- WOLNO: „Zamówienia złożone do 15:00 w dni robocze zwykle wysyłamy tego samego dnia, a większość paczek dociera następnego dnia roboczego.” + asekuracja: „Nie gwarantujemy terminu — doręczenie jest po stronie kuriera. Jeśli potrzebujesz 100% pewności (np. przed wyjazdem), zadzwoń 56 307 03 03.”
- NADAL NIE WOLNO: twardej obietnicy konkretnej daty/godziny („na pewno w piątek”, „gwarantuję przed 12:00”). Różnica: „duża szansa, że zdążysz” = OK; „gwarantuję, że zdążysz” = ZAKAZ.
- Dotyczy TYLKO in_stock. Dla available_to_order/unavailable — reguły dotychczasowe (2–5 dni do magazynu) bez zmian.
- Zachować spójność z istniejącą regułą „Dostępność = czas do NAS, Doręczenie = proces kuriera”.

### KROK B3 — SystemPrompt: numery kont + linki (ADR-095 dec.2)
Dodać sekcję „NUMERY KONT I LINKI SKLEPU”: gdy klient pyta o przelew/numer konta/regulamin/politykę prywatności/blog/encyklopedię/zwroty/kontakt → wywołaj `get_shop_links`. Numer konta podawaj WPROST tylko przy pytaniu o płatność/przelew, ZAWSZE z linkiem do https://divezone.pl/kontakt-z-nami. Przy pytaniach ogólnych — sam link. NIE zmyślaj numerów ani linków — tylko z wyniku get_shop_links.

### KROK C — nowe narzędzie get_shop_links + seed config
C1. Migracja `sql/028_shop_links_seed.sql` (+ rollback): INSERT-y do `divechat_shop_config` (ON CONFLICT (key) DO UPDATE dla idempotencji):
   - `bank_account_pln` = `27 1600 1462 1829 3115 4000 0003`
   - `bank_account_eur` = `PL54 1600 1462 1829 3115 4000 0002`
   - `bank_swift` = `PPABPLPK`
   - `link_kontakt` = `https://divezone.pl/kontakt-z-nami`
   - `link_regulamin`, `link_polityka_prywatnosci`, `link_blog`, `link_encyklopedia`, `link_zwroty` = **URL-e do POTWIERDZENIA przez Karola** (patrz PYTANIA OTWARTE — NIE zgaduj, zostaw placeholder TODO lub dopytaj).
   - Rollback: DELETE WHERE key IN (...).
C2. `src/Tools/GetShopLinks.php` implements ToolInterface — wzorzec 1:1 z `ShippingInfo.php`. Czyta z `divechat_shop_config` po whiteliście kluczy (prefiks `bank_` i `link_`). Zwraca strukturę {accounts:{pln,eur,swift}, links:{...}}. Opcjonalny param `topic` (enum: payment|legal|content|contact) zawężający, ale domyślnie zwróć komplet.
C3. Zarejestrować w `config/tools.php` + ToolRegistry. Description toola: precyzyjny — „dane do przelewu (numery kont, SWIFT) oraz oficjalne linki sklepu (kontakt, regulamin, polityka prywatności, blog, encyklopedia, zwroty). Używaj gdy klient pyta o płatność/przelew/numer konta lub o którąś z tych stron.”
C4. Test w `tests/Tools/` — get_shop_links zwraca numery z configu; brak klucza → graceful (pomiń, nie błąd).

---

## KOLEJNOŚĆ I DEPLOY
1. KROK A najpierw (P1, samodzielny). Po A: lokalny test, potem deploy wg ADR-089 (rsync standalone/ → chat.divezone.pl/ + backup _deploy_bak/ + md5 + smoke /api/health + STOP przed rsync na approval Karola). Migracja 028 dopiero w KROKU C.
2. KROK B (SystemPrompt) — czysto tekstowy, niskie ryzyko, może iść z C.
3. KROK C — migracja 028 na Railway (Karol aplikuje lub zatwierdza), potem deploy kodu toola.
4. NIE łącz deployu A z C jeśli A ma iść szybciej (P1). Decyzja o jednym vs dwóch wdrożeniach — potwierdzić z Karolem w STOP.

## KRYTERIA AKCEPTACJI
- [ ] `enrich([5463])['price']` == 1900.00; `amount+reduction_tax=0` i `percentage` bez zmian (regresja).
- [ ] „ile ważny jest voucher?” → drill-down → „jednorazowy, ważny 1 rok od daty zakupu”.
- [ ] „czy dotrze do piątku?” (in_stock) → komunikat probabilistyczny + asekuracja + telefon; BEZ twardej obietnicy daty.
- [ ] „podaj numer konta” → nr PLN + link /kontakt-z-nami (z get_shop_links).
- [ ] get_shop_links zarejestrowany, description trafny, test zielony.
- [ ] Wszystkie testy w tests/ przechodzą.

## PYTANIA OTWARTE (DO KAROLA — przed KROKIEM C)
1. Dokładne URL-e: regulamin, polityka prywatności, blog, encyklopedia, zwroty (do seed 028). Bez nich zostaw TODO/placeholder, NIE zgaduj.
2. Czy `get_curated_recommendations` trzyma snapshot cen (reseed po fixie A) — potwierdzić w KROKU A4 i ewentualnie zlecić reseed osobno.

## RAPORT (ostatni krok)
Zaktualizuj `_docs/21_STATUS_PROJEKTU.md` (sekcja IN PROGRESS/DONE, ADR-093/094/095), dopisz wynik testów i ceny 5463 po fixie. Commit statusu osobno z prefiksem `docs:`.


---

## AKTUALIZACJA 2026-06-12 (po wdrożeniu KROKU A) — domknięcie B+C

**KROK A: WDROŻONY I ZWERYFIKOWANY NA PROD.** enrich([5463])→1900.00, deploy ADR-089 OK, commity cf86a52 (kod) + 0dd11c8 (docs). A4 rozstrzygnięte: get_curated_recommendations woła enrich() na żywo → reseed NIEpotrzebny. Pytanie otwarte 2 = zamknięte.

**Pytanie otwarte 1 = ZAMKNIĘTE.** Karol podał i zweryfikowano (HTTP 200) wszystkie URL-e. Migracja `sql/028_shop_links_seed.sql` + `_rollback.sql` są JUŻ zaktualizowane na dysku (realne URL-e, +5 dodatkowych linków). NIE nadpisuj — użyj wersji z dysku.

### DŁUG DO DOMKNIĘCIA przed deployem C (zgłoszony przez CC): GetShopLinks nie zna 5 nowych kluczy
Migracja 028 seeduje 14 kluczy, ale `GetShopLinks` obsługuje tylko 9 (3 konta + 6 linków). Brakuje: `link_platnosci`, `link_serwis`, `link_o_nas`, `link_b2b`, `link_filmy`. Bez dopięcia tool je pominie.

Do zrobienia w `src/Tools/GetShopLinks.php`:
1. `LINK_KEYS`: dodać `link_platnosci`, `link_serwis`, `link_o_nas`, `link_b2b`, `link_filmy`.
2. `buildResult()` mapowanie `links`: dodać klucze `platnosci`, `serwis`, `o_nas`, `b2b`, `filmy`.
3. Filtr `topic` — DECYZJA ARCHITEKTA (nie improwizuj): rozszerzyć enum o dwa nowe tematy:
   - `payment` → dodać `links.platnosci` (formy płatności logicznie z przelewem).
   - NOWY `service` → `links.serwis` (+ ewentualnie kontakt). Bot woła przy pytaniu o serwis/cennik serwisu.
   - NOWY `about` → `links.o_nas`, `links.b2b`, `links.filmy`.
   - `default` (komplet) zwraca wszystkie 11 linków + konta.
   Zaktualizować enum w getParametersSchema() i description toola o nowe tematy.
4. Rozszerzyć `tests/Tools/GetShopLinksTest.php` o nowe klucze i nowe topic (service/about).

### KROK B (SystemPrompt) + KROK C (tool+migracja) — wdrożenie
Po dopięciu długu LINK_KEYS: deploy ADR-089 dla B+C.
- Pliki rsync: `src/Chat/SystemPrompt.php`, `src/Tools/GetShopLinks.php`, `config/tools.php`.
- Migracja `sql/028_shop_links_seed.sql` na Railway = OSOBNY explicit STOP (ADR-089 pkt 6). Aplikować PRZED/RAZEM z deployem kodu C (inaczej tool zwraca graceful nulle — bot odsyła na /kontakt-z-nami bez błędu, ale konta/linki nie działają).
- Kolejność: migracja 028 → rsync 3 plików → md5 → php -l → smoke /api/health + szybki test get_shop_links na żywym torze (np. topic=payment zwraca nr konta PLN).

### Commit B+C (osobny od A, git add per ścieżka):
- git add standalone/src/Chat/SystemPrompt.php standalone/src/Tools/GetShopLinks.php standalone/config/tools.php standalone/tests/Tools/GetShopLinksTest.php sql/028_shop_links_seed.sql sql/028_shop_links_seed_rollback.sql
- commit: "CHAT-T-087 backend: SystemPrompt drill-down+dostawa+linki, get_shop_links + seed 028 (ADR-094/095)"
- push origin main, potem osobny commit "docs:" ze statusem.
- UWAGA: repo brudne z poprzednich sesji (CHAT-T-036/077/079, ENC-013, frontend, _drafts/, modules/divezone_chat.zip) — NIE wciągaj ich, add tylko ścieżki 087.
