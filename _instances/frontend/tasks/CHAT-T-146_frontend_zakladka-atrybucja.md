# CHAT-T-146 (frontend/PS): zakładka „Atrybucja" w panelu — sprzedaż z czatu bez SSH

**Instancja:** frontend (moduł PS)
**ADR:** ADR-124 (`_docs/10_decyzje_projektowe.md`, commit 024889f)
**Karta Trello:** Chat - 17
**Swiat:** SHOP+WIDGET — `~/public_html/newtmp2/` (**to PRODUKCJA**, mimo nazwy „tmp").
NIE backend standalone. Deploy: **ręczny rsync Karola**, potem `var/cache/prod` + LSCache.
**Decyzje Karola:** 102c, 104b, 105a, 106a

---

## KONTEKST — mechanizm DZIAŁA, tylko nikt go nie widzi

CHAT-T-136 wdrożył atrybucję: cookie `divechat_session_id` → hook `actionValidateOrder` →
tabela `pr_divechat_order_attribution`. **Zweryfikowane na PROD 2026-07-15** przez architekta:
tabela istnieje, hook zarejestrowany (`actionValidateOrder` + `displayFooter`), cookie ma
`maxAge = 2592000` (30 dni).

**Mechanizm złapał 2 realne zamówienia klientów, bez żadnego testu:**

| zamówienie | id_order | kwota | rozmowa → zakup | stan |
|---|---|---|---|---|
| QETUBCWYS | 122970 | 3619,00 zł | 21:33 → 21:56 (**23 min**) | Zapłacone (Tpay) |
| UDRDJMBTG | 122952 | 312,80 zł | 15:28 → 15:32 (**4 min**) | Oczekiwanie na przelew |

**Problem:** dane siedzą w MySQL, zagląda tam tylko ktoś z SSH. Brak widoku = nikt nie wie,
ile czat sprzedaje.

**Struktura `pr_divechat_order_attribution`** (zweryfikowana):
```
id_attribution        int(11)
id_order              int(11)
chat_session_id       varchar(64)
attribution_type      enum('last_touch','assist')
conversation_last_at  datetime
date_add              datetime
```

---

## DECYZJE — czytaj, zanim zaczniesz

- **102c:** najpierw panel PS, GA4 **potem** (osobny task). Dane deterministyczne są
  dokładniejsze niż GA4 — nie zależą od zgody na cookies ani blokerów.
- **104b:** pokazujemy **wszystkie** ważne zamówienia, ze stanem płatności jako **osobną
  kolumną**. NIE tylko opłacone — UDRDJMBTG (312,80 zł, czeka na przelew) to realna sprzedaż
  z czatu. Podsumowanie ma **dwie sumy: zamówioną i opłaconą**.
- **KRYTYCZNE — liczysz `total_paid`, NIE `total_paid_real`.** Zweryfikowane na PROD:
  `total_paid_real` jest zawyżone **2x dla 1246 z 1259 zamówień Tpay** (99%) — moduł Tpay
  zapisuje płatność dwa razy (karta Sklep - 31). Tpay to dominująca metoda płatności.
  Raport na `total_paid_real` pokazałby ~2x przychód. **`total_paid` jest poprawne.**
- **KRYTYCZNE — „zapłacone" to NIE `pr_orders.valid`.** To flaga księgowa. Zapłata =
  `current_state` → `pr_order_state.paid = 1`. Dowód: QETUBCWYS ma `valid=0`, a jest
  zapłacone (stan „Zapłacone", `os.paid=1`, Tpay).
- **105a:** okno atrybucji 30 dni — **już wdrożone**, nie ruszasz (`widget-bundle.js`,
  `maxAge = 2592000`).
- **106a:** zakładka dla **wszystkich** z dostępem do panelu czatu. **NIE** admin-only —
  inaczej niż Analityka i CTR zachęty. Panel i tak pokazuje treść rozmów, więc kwoty
  zamówień nie są większą tajemnicą.

---

## KROK 0 — pull + czytaj

```
git pull origin main
```
Przeczytaj **ADR-124** (commit 024889f) — cały.
Otwórz `modules/divezone_chat/controllers/admin/AdminDivezoneChatController.php` (3579 linii):
- `const TAB_*` (~62-74) — wzorzec stałych
- `initContent()` (~105-210) — routing zakładek (`$activeTab`, `switch`)
- `renderTabsNav()` (~391-434) — **tu dokładasz link**; zwróć uwagę na `$isAdmin` — Analityka
  i CTR są admin-only, **Twoja zakładka NIE** (decyzja 106a)
- `renderAnalyticsSection()` (~2455+) — **wzorzec do naśladowania**: whitelist filtrów
  (`$days`, `in_array(..., array(7,30,90), true)`), render tabeli, podsumowanie
- `renderConversationsSection()` (~1103) — wzorzec linkowania do rozmowy

## KROK 1 — stała + routing

- `const TAB_ATTRIBUTION = 'attribution';` obok pozostałych (~62-74)
- w `initContent()` (~186-206) dodaj gałąź: `$tabContent = $this->renderAttributionSection($employeeId);`
- w `renderTabsNav()` dodaj link **poza blokiem `if ($isAdmin)`** (106a). Nazwa: `Atrybucja`.
  Kolejność: po „Rekomendacje", przed „Analityka".

## KROK 2 — `renderAttributionSection($employeeId)`

**Źródło danych: MySQL PrestaShop** (`Db::getInstance()`), nie Railway PG. Tabela atrybucji
i zamówienia są w tej samej bazie — jeden JOIN, zero round-tripów do PG.

Zapytanie (schemat, dostosuj do konwencji pliku):
```sql
SELECT a.id_order, a.chat_session_id, a.attribution_type, a.conversation_last_at, a.date_add,
       o.reference, o.total_paid, o.date_add AS order_date, o.current_state,
       osl.name AS stan_platnosci, os.paid AS czy_oplacone
FROM pr_divechat_order_attribution a
JOIN pr_orders o ON a.id_order = o.id_order
LEFT JOIN pr_order_state os ON o.current_state = os.id_order_state
LEFT JOIN pr_order_state_lang osl ON os.id_order_state = osl.id_order_state
     AND osl.id_lang = <id_lang kontekstu>
WHERE o.date_add >= DATE_SUB(NOW(), INTERVAL <days> DAY)
ORDER BY o.date_add DESC
```
Prefiks tabel bierz z `_DB_PREFIX_`, nie hardkoduj `pr_`.

**Kolumny w tabeli HTML:**
| kolumna | źródło |
|---|---|
| Zamówienie | `o.reference` + link do zamówienia w PS |
| Data | `o.date_add` |
| Kwota | `o.total_paid` (**NIE `total_paid_real`**) |
| Stan płatności | `osl.name` + wizualne odróżnienie `os.paid = 1` |
| Typ | `a.attribution_type` (`last_touch` / `assist`) |
| Rozmowa → zakup | `o.date_add` − `a.conversation_last_at`, format czytelny („23 min", „2 h 15 min", „3 dni") |
| Rozmowa | link do zakładki Rozmowy po `chat_session_id` (jeśli da się dopasować — jeśli nie, pomiń kolumnę i **zgłoś w raporcie**) |

**Filtr okresu:** 7 / 30 / 90 dni, domyślnie **30**. Whitelist jak w `renderAnalyticsSection`
(`in_array(..., array(7,30,90), true)`) — **nie wstawiaj `$days` do SQL bez walidacji**.

**Podsumowanie nad tabelą (decyzja 104b — dwie sumy):**
- liczba zamówień z czatu
- **suma zamówiona** = `SUM(total_paid)` wszystkich
- **suma opłacona** = `SUM(total_paid)` gdzie `os.paid = 1`
- mediana czasu rozmowa → zakup

## KROK 3 — bezpieczeństwo i konwencje

- Escapowanie: `htmlspecialchars(..., ENT_QUOTES)` jak w reszcie pliku
- SQL: `pSQL()` / `(int)` na parametrach — zero konkatenacji surowych wartości
- Link do zamówienia: `$this->context->link->getAdminLink('AdminOrders')` + `id_order` + `vieworder`
- Zero zapytań do Railway PG w tej zakładce
- Zero zmian w `hookActionValidateOrder` i w `widget-bundle.js`

## KROK 4 — weryfikacja lokalna

`php -l` na zmienionym pliku. Jeśli masz jak — sprawdź zapytanie na PROD przez SSH
(read-only SELECT, `divezone_chat_reader`) i porównaj z liczbami architekta:
- QETUBCWYS: 3619,00 zł, „Zapłacone", 23 min
- UDRDJMBTG: 312,80 zł, „Oczekiwanie na płatność przelewem bankowym", 4 min
- suma zamówiona: 3931,80 zł | suma opłacona: 3619,00 zł

## KROK 5 — STOP przed deployem

**Deploy robi Karol ręcznym rsync** (port 5739, `--exclude config_pl.xml`, bez `--delete`).
Ty **NIE deployujesz**. Zaraportuj: diff, `php -l`, wynik weryfikacji.
Po deployu Karola: **trzeba wyczyścić `var/cache/prod` + LSCache** — przypomnij o tym
w raporcie.

## KROK 6 — git

`git status`. `git pull --rebase origin main` (inne okna pracują równolegle).
`git add` PER ŚCIEŻKA:
```
git add modules/divezone_chat/controllers/admin/AdminDivezoneChatController.php
git add _instances/frontend/tasks/CHAT-T-146_frontend_zakladka-atrybucja.md
```
**NIE commituj:** `_docs/10_decyzje_projektowe.md` (ADR-y pisze architekt), `_backups/`,
`config_pl.xml`, `standalone/config/routes.php`.
Commit: `CHAT-T-146 frontend: zakladka Atrybucja w panelu PS (ADR-124)`
Push odrzucony → `git pull --rebase`, push ponownie.

## KROK 7 — status + raport

`_docs/21_STATUS_PROJEKTU.md` — **dopisz NA GÓRZE**, nie nadpisuj (inne okna też piszą).
Sprawdź `git diff` przed commitem.

---

## KRYTERIA AKCEPTACJI

1. Zakładka „Atrybucja" widoczna dla **wszystkich** ról (nie admin-only) — 106a.
2. Kwoty liczone z **`total_paid`**. Zero użycia `total_paid_real` w kodzie.
3. Stan płatności z `pr_order_state.paid`, **nie** z `pr_orders.valid`.
4. Dwie sumy w podsumowaniu: zamówiona i opłacona (104b).
5. Filtr 7/30/90 z whitelistą, domyślnie 30.
6. Liczby zgodne z weryfikacją architekta (3931,80 zamówione / 3619,00 opłacone).
7. Zero zapytań do Railway PG. Zero zmian w hooku i widgecie.
8. `php -l` czysty.

## POZA ZAKRESEM

- **Strumień GA4** (dataLayer + GTM) — druga połowa ADR-119, osobny task.
- Naprawa podwójnych płatności Tpay (karta Sklep - 31).
- Zmiany w cookie / oknie atrybucji (105a — wdrożone, nie ruszać).
- Rozbicie `assist` vs `last_touch` na kanały.
- Cokolwiek w świecie BACKEND (`chat.divezone.pl`).
