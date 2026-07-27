# CHAT-T-151 — BACKEND: Narzędzie wysyłki zagranicznej DPD (`get_international_shipping`)

**Status:** DO WYKONANIA
**Instancja:** backend (PHP)
**Powiązane:** ADR-129, ADR-059 (`get_shipping_info` — NIE ruszać), ADR-106 (kurs EUR), ADR-089 (STOP-gate)
**Karta Trello:** Chat - 7

---

## ŚWIAT WDROŻENIOWY

**BACKEND `chat.divezone.pl`** (standalone API, PHP 8.4). Na serwerze BEZ prefiksu `standalone/`.
Dane: **MySQL PrestaShop read-only** (`divezone_chat_reader`). ZERO Railway PG, ZERO migracji.

**UWAGA — `config/tools.php` ma DRYF repo≠prod (R-5, T-129 GetProductCombinations).** Wypchnięcie wersji repo = fatal 500 (Class not found dla niewdrożonej klasy). Deploy tools.php TYLKO przez STOP-gate z Karolem, z porównaniem repo↔prod. NIE blanket-rsync `standalone/`.

---

## KONTEKST

`get_shipping_info` dla `zone=EU` zwraca pustą listę + notkę „skontaktuj się" (bug conv 638 Austria, conv 649 Hiszpania). Stawki zagraniczne SĄ w PrestaShop, ale w innym modelu danych niż tabela `divechat_shipping_rates` (którą czyta `get_shipping_info`). Dlatego OSOBNE narzędzie czytające żywe stawki z MySQL, nie duplikat w PG.

**Ustalenia architekta na PROD MySQL 2026-07-18 (pomiar, nie założenie):**
- Kurier zagraniczny = **WYŁĄCZNIE DPD (id_carrier 397)**. InPost Paczkomaty (399) = tylko PL. Reszta (biznesowa 380, pobrania) poza zakresem.
- DPD proguje **WARTOŚCIĄ koszyka**, nie wagą: `pr_carrier.shipping_method=2`, `pr_range_price` 3 wiersze (0-399 / 399-1283 / 1283-999999 PLN), `pr_range_weight` 0 wierszy.
- 22 strefy zagraniczne DPD + PL. Trzeci próg (1283+) = **darmowa wysyłka** (price 0).
- Limit wagi: `pr_carrier.max_weight` DPD=**29 kg** (czytaj z bazy, NIE hardcode).
- Kurs EUR: `pr_currency` id=2 `conversion_rate` (dziś 0.234915).
- Wyspy: kraje lądowe = „(bez wysp)" ze strefą + stawką; wyspy = strefa „Europe (non-EU)" id 7 lub bez strefy, `active=0`, strefa 7 ma **0 wierszy** stawek DPD.
- Waga produktu: `pr_product.weight`. LUKA: 439 z 2664 aktywnych ma `weight=0`.

---

## KROK 0 — PULL / READ

1. `git pull --rebase origin main`, `git status`, gałąź.
2. Przeczytaj **ADR-129** w `_docs/10_decyzje_projektowe.md` (koniec pliku).
3. Przeczytaj wzorce (NIE modyfikuj): `src/Tools/ShippingInfo.php` (interfejs `ToolInterface`, kształt zwrotki), `src/Tools/PopularProducts.php` (jak narzędzie czyta MySQL przez `MysqlConnection`, mapa stałych w klasie, statusy ok/error), `src/Database/MysqlConnection.php` (połączenie read-only).
4. `grep -n "ShippingInfo\|register" config/tools.php` — jak rejestruje się narzędzie.
5. `git log --oneline -5` — konwencja commitów.

## KROK 1 — Nowe narzędzie `src/Tools/InternationalShipping.php`

Klasa `InternationalShipping implements ToolInterface`, nazwa narzędzia `get_international_shipping`.

**Parametry:**
- `country` (string, wymagany) — nazwa kraju lub kod ISO (klient pisze „Austria"/„Hiszpania"/„DE”). Rozwiązuj przez `pr_country_lang` (id_lang=1) LIKE + `pr_country.iso_code`.
- `cart_total` (number, opcjonalny) — wartość koszyka PLN, do wyboru zakresu cenowego i progu darmowej wysyłki.
- `cart_weight` (number, opcjonalny) — sumaryczna waga koszyka kg, do sprawdzenia limitu.
- `weight_uncertain` (bool, opcjonalny, default false) — flaga że któryś produkt w koszyku ma `weight=0` (bot ustawia, gdy sumuje wagę z narzędzi produktowych).

**Logika (SQL na MySQL PrestaShop, placeholdery `?`):**
1. Rozwiąż `country` → `id_country` → `id_zone`. Kraj nieznaleziony → status `unknown_country`.
2. Sprawdź, czy strefa ma aktywne stawki DPD: `SELECT ... FROM pr_delivery d JOIN pr_range_price rp ... WHERE d.id_carrier=397 AND d.id_zone=? AND d.price>0`. Brak wierszy → status **`not_supported`** (wyspa / spoza zasięgu — decyzja 156a).
3. Wybierz stawkę wg `cart_total`: dopasuj do `pr_range_price` (delimiter1 <= cart_total < delimiter2). Gdy `cart_total` brak → zwróć WSZYSTKIE progi (bot pokaże „do 399 zł: X, powyżej: Y, powyżej 1283 zł: gratis”).
4. Limit wagi: `SELECT max_weight FROM pr_carrier WHERE id_carrier=397` (NIE hardcode 29). Gdy `cart_weight` podane i > max_weight → flaga `over_weight_limit: true` + `max_weight`.
5. Kurs EUR: `SELECT conversion_rate FROM pr_currency WHERE id_currency=2`. Przelicz stawkę: `price_eur = ceil(price_pln * rate)` (**w górę do pełnego euro**, decyzja 155a).
6. Próg darmowej wysyłki = górny zakres z price=0 (delimiter1 trzeciego zakresu, tu 1283 PLN) — czytaj z danych, nie hardcode.

**Zwrotka (status `ok`):**
```
{
  status: 'ok',
  country: 'Austria', zone_name: 'Austria',
  carrier: 'Kurier DPD',
  rate_pln: 97.0, rate_eur: 23,          // wg cart_total, gdy podane
  ranges: [ {from:0, to:399, pln:97, eur:23}, ... ],  // gdy cart_total brak
  free_shipping_threshold_pln: 1283, free_shipping_threshold_eur: 302,
  max_weight_kg: 29.0,
  over_weight_limit: false,              // gdy cart_weight > max_weight
  weight_uncertain: false,               // przepisane z parametru
  note: null
}
```
Statusy: `ok` / `not_supported` (wyspa/poza zasięgiem) / `unknown_country` / `error` (MySQL down — czytelny komunikat, bez fabrykacji).

**Zasady:**
- ZERO hardkodowanych stawek, limitów, progów, listy wysp — wszystko z MySQL.
- `not_supported` NIE proponuje ceny ani „wyceny indywidualnej" — tylko fakt + kontakt (obsłuży prompt).
- Cast NUMERIC→float jak w `ShippingInfo` (PDO zwraca string).

## KROK 2 — Rejestracja `config/tools.php`

`$registry->register(new InternationalShipping($mysql))` — wstrzyknięcie `MysqlConnection` jak w `PopularProducts`/`ProductSearch`.
**STOP przed deployem tools.php** (dryf R-5) — patrz KROK 5.

## KROK 3 — `SystemPrompt.php`, nowa reguła WYSYŁKA ZAGRANICZNA

- Klient pyta o koszt wysyłki poza PL → `get_international_shipping(country, cart_total?, cart_weight?)`.
- **Ceny podawaj w EUR** (kraj zagraniczny), stawka PLN informacyjnie jeśli pyta.
- **WYSPY / kraj `not_supported`**: „Niestety nie realizujemy wysyłki kurierskiej do [kraj]” + kontakt `dive@divezone.pl` / `56 307 03 03`. NIE obiecuj wyceny indywidualnej. Ceny DPD obejmują **tylko ląd stały** — wyspy hiszpańskie/portugalskie/duńskie itd. nie są objęte (narzędzie zwróci `not_supported` dla nich automatycznie).
- **LIMIT WAGI**: gdy `over_weight_limit: true` → „Przesyłka przekracza limit [max_weight] kg dla kuriera DPD, zamówienie trzeba podzielić — napisz do obsługi". Gdy `weight_uncertain: true` I waga blisko limitu → „wagi jednego z produktów nie mam pewnej, przy zamówieniu bliskim limitu potwierdź z obsługą" (decyzja 153b).
- **Darmowa wysyłka**: powyżej progu (z danych) — „Przy zamówieniu powyżej [próg] EUR wysyłka gratis".
- Reguła krajowa (`get_shipping_info`, zone=PL) BEZ ZMIAN — to osobne narzędzie.

## KROK 4 — Testy

`tests/Tools/InternationalShippingTest.php`:
- Austria (strefa ze stawką) → rate_pln 97, rate_eur 23, ranges 3.
- Czechy/Węgry → 87 PLN (inna stawka, dowód że czyta per strefa).
- Wyspa / Grenlandia / kraj strefy 7 → `not_supported`.
- Kraj nieznany („Blorpland") → `unknown_country`.
- cart_total=500 → wybiera środkowy zakres; cart_total=1500 → free (price 0).
- cart_weight=35 → `over_weight_limit: true`, max_weight z bazy.
- weight_uncertain przepisane do zwrotki.
- Widoczność w OBU providerach (`formatTools` Claude + OpenAI) — jak w testach innych narzędzi.
- REALNA pętla function calling (jeśli w konwencji testów): „ile wysyłka do Austrii” → model woła narzędzie; „wysyłka na Teneryfę” → not_supported.

## KROK 5 — STOP. Deploy (ADR-089)

**Nie wykonuj bez „deployuj".**
- Świat BACKEND `chat.divezone.pl`. Pliki: `src/Tools/InternationalShipping.php` (nowy), `config/tools.php`, `src/Chat/SystemPrompt.php`.
- **`config/tools.php` — DRYF R-5.** Przed wysłaniem: `diff` repo↔prod. NA PROD idzie wersja produkcyjna tools.php + WYŁĄCZNIE rejestracja `InternationalShipping` (jak przy T-132 z PopularProducts). NIE wypychać repo-wersji z `GetProductCombinations`, jeśli klasa niewdrożona → fatal 500.
- **NOWA klasa** → `composer dump-autoload -o` na serwerze (jak T-100 SizeRecommender).
- Backup `_deploy_bak/CHAT-T-151/` (md5 .bak==prod przed), rsync per ścieżka, md5 prod==local, `ea-php84 -l` clean, `/api/health` 200.
- Smoke: registry buduje, `get_international_shipping` obecny, live exec Austria→rate_eur.

## KROK 6 — Test PROD + reguła E

Realny czat (HMAC): „ile kosztuje wysyłka do Austrii” → EUR + progi; „wysyłka na Wyspy Kanaryjskie/Teneryfę” → not_supported + kontakt. Rozmowy oznacz `[test CHAT-T-151, nie klient]`, verdict/updated_by NULL.

## KROK 7 — Status + raport

1. `_docs/21_STATUS_PROJEKTU.md` — NA GÓRZE.
2. git: `git status`, `git add` per ścieżka, commit `CHAT-T-151 backend: get_international_shipping (ADR-129)`, `git push origin main`. Po deployu osobny commit `docs:`.
3. Raport jako recenzja: co zweryfikowane czym (polecenie+wynik), rozbieżności task↔kod.

---

## CZEGO NIE RUSZAĆ

- `src/Tools/ShippingInfo.php` — narzędzie krajowe, osobne (ADR-059). Dług „flat do 31 kg" w `buildNote` NIE naprawiamy tu (odnotowany w ADR-129).
- `divechat_shipping_rates`, `divechat_shop_config` — źródło narzędzia krajowego, nie dotykać.
- Railway PG — narzędzie nie używa PG. ZERO migracji.
- `standalone/` blanket-rsync; `config/routes.php` (cudze); `_ops/newtmp2_root/purge_litespeed.php` (SEKRET).
- ADR-y w `_docs/10` — pisze architekt.
- `newtmp2` — to nie ten świat.

---

**Instancja: BACKEND (PHP)**
