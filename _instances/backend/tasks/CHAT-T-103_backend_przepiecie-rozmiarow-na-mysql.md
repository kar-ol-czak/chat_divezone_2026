# CHAT-T-103 — INSTANCJA: backend — Przepięcie SizeRecommender z Railway (PG) na MySQL PrestaShop

> **Powiązane:** rewizja CHAT-T-100 (tool `recommend_wetsuit_size`); ADR-100/101 (czat — źródło prawdy w PrestaShop); ATTR-T-001 (tabele `divezone_attr_*` na PROD `divezone_2025`, parytet 100%, reader widzi). Pozostaje w projekcie czata (dotyka standalone backendu).
> **Charakter:** chirurgiczna podmiana warstwy danych. Logika doboru BEZ ZMIAN. Zweryfikowane z kodu przed napisaniem (patrz niżej — fakty, nie założenia).

## ⚠️ ZAKRES ŚCISŁY — co wolno, czego NIE wolno
**Wolno (3 rzeczy):**
1. Zależność konstruktora `SizeRecommender`: `PostgresConnection` → `MysqlConnection`.
2. Nazwy tabel w 5 zapytaniach SQL (Railway `divechat_size_*` → PrestaShop `divezone_attr_*`).
3. Nazwy kolumn wg ADR-002 nowego schematu (klucz `id_chart`, nie `id`).

**NIE wolno (sprawdzone parytetem 960/0 — musi zostać identyczne):**
- Logika `matchSize`, `matchPointwise`, `buildSizes`, `isPointwise`, `inRange`, `distToRange`.
- Schemat parametrów (`getParametersSchema`), opis (`getDescription`), nazwa toola.
- Reguły zwracania (match/ambiguous/out_of_scale/boundary), aliasy, normalizeLabel/resolveLabel.

## FAKTY ZWERYFIKOWANE Z KODU (architekt, przed taskiem)
- `standalone/src/Database/MysqlConnection.php` ISTNIEJE. Read-only (bezpiecznik: tylko SELECT/SHOW/DESCRIBE), singleton `getInstance()`, lazy init, czyta `DB_*` z `.env` (DB_HOST/PORT/NAME_PROD/USER/PASSWORD = konto `divezone_chat_reader`).
- `MysqlConnection` ma IDENTYCZNE metody co `PostgresConnection`: `fetchAll(string $sql, array $params): array`, `fetchOne(string $sql, array $params): ?array`. → Wywołania `$this->db->fetchAll/fetchOne` w toolu NIE zmieniają się ani o znak.
- Placeholdery `?` — PDO MySQL z `ATTR_EMULATE_PREPARES=false` obsługuje pozycyjne `?`. Obecne zapytania już używają `?`. Bez zmian.
- ATTR-T-001: `divezone_chat_reader` widzi `divezone_attr_*` na PROD (SELECT OK, INSERT 1142). Zero nowych GRANT-ów (decyzja P89a).

## MAPOWANIE TABEL/KOLUMN (Railway → PrestaShop, wg ADR-002 projektu atrybutów)
| Railway (teraz) | PrestaShop (cel) | uwaga kolumn |
|---|---|---|
| `divechat_size_charts` (kol. `id`) | `divezone_attr_size_charts` (kol. `id_chart`) | JOIN/WHERE po `id_chart` |
| `divechat_size_chart_rows` (`chart_id`) | `divezone_attr_size_chart_rows` (`id_chart`) | FK kol. to `id_chart` |
| `divechat_product_size_chart` (`product_id`,`chart_id`) | `divezone_attr_product_chart` (`id_product`,`id_chart`) | |
| `divechat_size_label_alias` (`chart_id`) | `divezone_attr_size_label_alias` (`id_chart`) | |
| (brak) | `divezone_attr_size_chart_content` | NIE używane przez tool (chart_type='progowy') |

⚠️ Zweryfikuj realne nazwy kolumn przez `SHOW CREATE TABLE divezone_attr_*` na PROD (reader) PRZED edycją SQL — ADR-002 to projekt, potwierdź implementację z ATTR-T-001 (`sql/001_attr_schema.sql` w projekcie `Atrybuty_produktow_2026`). Nie zakładaj — sprawdź.

## KROKI

**KROK 0 — pull/read + weryfikacja schematu.**
- `git pull origin main`.
- Przeczytaj `standalone/src/Tools/SizeRecommender.php` (obecny, PG), `MysqlConnection.php`, `PostgresConnection.php` (porównaj interfejs).
- Sprawdź jak `SizeRecommender` jest konstruowany w `standalone/config/tools.php` (skąd bierze `PostgresConnection` — tam podmienisz na `MysqlConnection::getInstance()` lub wg wzorca DI w projekcie).
- `SHOW CREATE TABLE` dla 4 używanych tabel `divezone_attr_*` na PROD przez reader — potwierdź realne nazwy kolumn. Zapisz w raporcie.

**KROK 1 — podmiana w SizeRecommender.**
- Konstruktor: typ `PostgresConnection` → `MysqlConnection` (use + property).
- 5 zapytań: nazwy tabel + kolumn wg potwierdzonego schematu (KROK 0). Uwaga na `c.id` → `c.id_chart`, `pc.chart_id`/`r.chart_id` → `.id_chart`, `pc.product_id` → `pc.id_product`.
- Reszta pliku: NIE RUSZAĆ.

**KROK 2 — rejestracja.**
- W `standalone/config/tools.php` przekaż `MysqlConnection` zamiast `PostgresConnection` do `SizeRecommender`. Sprawdź, czy nic innego nie polega na tym, że ten tool dostawał PG.

**KROK 3 — walidacja parytetu (ten sam dowód co ATTR-T-001/CHAT-T-100).**
- Zestaw: chest 104/Sc M→L; chest 200→out_of_scale (4XL/3XL); dzieci 134→[S,M] graniczny, 140→M, 170→out_of_scale; bi-gender (4243/4244/6681) wybór po płci; alias 6 Plus→6+ / „M tall"→MT.
- Wynik MySQL MUSI być identyczny z dotychczasowym (PG). Rozbieżność = STOP, raportuj.
- Testuj REALNĄ ścieżką (tool przez ChatService / function calling), nie tylko izolowany unit — zasada projektu.

**KROK 4 — deploy (⚠️ STOP wg ADR-089).**
- Backup do `_deploy_bak/`, md5 verify, `php -l`, smoke `/api/health`, STOP przed rsync, czekaj na akceptację Karola. git push ≠ deploy.
- Po deploy: smoke na PROD — tool zwraca match dla chest=104/Sc M (jak CHAT-T-100), tym razem czytając z MySQL.

**KROK 5 — status + raport.**
- `git add` per ścieżka (SizeRecommender.php, tools.php, testy; BEZ handoff/gitignored) → commit wg konwencji (sprawdź `git log`) → `git push origin main`.
- Po deploy: osobny `docs:` commit ze statusem.
- Raport: potwierdzone nazwy kolumn, wynik parytetu MySQL vs PG, smoke PROD, punkt STOP deploy.

## PO TYM TASKU (NIE w nim)
- **Railway `product_sizing` → wygaszenie.** Dopiero PO potwierdzeniu, że bot na PROD działa z MySQL (smoke + obserwacja). Osobna, świadoma decyzja Karola — jak swego czasu Aiven. NIE wygaszaj w tym tasku.

## HARD STOP
- Deploy na produkcję `chat.divezone.pl` (KROK 4) — STOP przed rsync.
- Wygaszenie Railway — NIE w tym tasku.

## Wynik (CC, 2026-06-19) — DONE, zdeployowane

**Charakter:** chirurgiczna podmiana warstwy danych. Logika doboru (matchSize/matchPointwise/
buildSizes/isPointwise/inRange/distToRange), schemat parametrów, opis i nazwa toola — NIETKNIĘTE.

**Zmienione pliki (commit `337b055`):**
- `standalone/src/Tools/SizeRecommender.php` — konstruktor `PostgresConnection`→`MysqlConnection`;
  5 zapytań SQL na `divezone_attr_*`; alias `c.id_chart AS id` (kształt zwracany `['id',...]` nietknięty).
- `standalone/config/tools.php` — `SizeRecommender(MysqlConnection::getInstance())`.
- `standalone/tests/size_recommender_parity.php` — źródło = MySQL, + 3 scenariusze bi-gender (product_id) + alias.
- `standalone/tests/size_recommender_{providers,e2e}.php` — harnessy dociągają `DB_*` (getInstance czyta je przy budowie rejestru).

**Potwierdzone nazwy kolumn (SHOW CREATE TABLE na PROD, reader, READ-ONLY) — zgodne z ADR-002:**
- `divezone_attr_size_charts`: PK `id_chart`, `brand`, `gender` (ENUM M/K/DZIECI/UNISEX).
- `divezone_attr_size_chart_rows`: FK `id_chart`, `size_label`, `size_full`, `dimension`, `min_val`, `max_val`, `sort_order`.
- `divezone_attr_product_chart`: `id_product`, `id_chart`.
- `divezone_attr_size_label_alias`: `id_chart`, `alias_label`, `canonical_label`.
Dane obecne: 5 chartów (Scubapro M/K/DZIECI, Bare M/K), rows 80/60/80/70/6, bi-gender 4243/4244/6681 → charty 1+2, aliasy chart 1 (L short/L tall/XXL) i chart 2 (M tall→MT).

**Parytet MySQL vs PG = 14/14 IDENTYCZNE** (decision + sizes + size_full + graniczny + aliases),
sprawdzone na żywych danych MySQL przed deployem (staged probe, bez dotykania docroot):
- chest 104 Sc M → match [L] (L - 52); chest 200 → out_of_scale [4XL,3XL]; chest 88 → out_of_scale [XS,S].
- Bare K 88/h165 → 6. DZIECI: 134 → boundary [S,M] graniczny; 140 → M; 170 → oos [XL,L]; 100 → oos [XXS,XS].
- product 4243+M → L; product 4243+K → ambiguous [S,ST]; product 6681+M → ambiguous [LS,L,LT]; alias M tall→MT.
- brak płci / brak klatki → error (dopytanie). Wszystkie identyczne z baseline PG.

**Real-path (function calling przez Claude → tool → MySQL):** chest=104 Sc M → tool wołany z poprawnymi
argumentami → `match ["L"]` (L - 52). Sprawdzone pre-deploy (staged) ORAZ post-deploy (deployed code).

**Deploy (KROK 4, po akceptacji Karola):**
- HARD STOP przed rsync — akceptacja uzyskana.
- Backup PROD → `_deploy_bak/CHAT-T-103_2026-06-19/` (SizeRecommender md5 `1fa6c20d…`, tools.php `702370dc…`).
- Wgrane 2 pliki (testy NIE są na PROD): SizeRecommender md5 `9008f105…`, tools.php `a6871bbd…` — md5 zgodne local↔serwer.
- `php -l` OK na serwerze. `/api/health` przed i po: `{"status":"ok","postgres":true,"mysql":true}`.
- Post-deploy real-path smoke na zdeployowanym kodzie: OK (match L, czyta z MySQL).

**Railway `product_sizing`:** NIE wygaszone (świadoma osobna decyzja po obserwacji — jak Aiven).
