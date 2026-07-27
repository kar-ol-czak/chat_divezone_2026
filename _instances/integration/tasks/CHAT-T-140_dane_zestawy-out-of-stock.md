# CHAT-T-140 — zestawy rekreacyjne: zezwolic na zamowienia przy braku stanu (out_of_stock 0→2)

**Instancja:** integration.
**Swiat:** DANE — MySQL sklepu (`divezone_2025`). ZERO zmian w kodzie: ani backend standalone,
ani modul PS. Nie ma rsync, nie ma php -l.
**ADR:** nie wymaga nowego ADR (zmiana danych, nie architektury). Odnotuj w statusie projektu.
**Karta Trello:** utworz "Chat - Zestawy rekreacyjne: zezwolic na zamowienia (out_of_stock)",
`boardId=6a55e07bc2193b7dfc53297e`, na start "W trakcie" (`idList=6a55f9604c618d67699affa8`).
**Autoryzacja Karola:** "przestaw" + "daj task" (2026-07-15). Zmiana na PRODUKCJI sklepu →
STOP przed UPDATE (ADR-089).

## Problem (zdiagnozowany — nie diagnozowac ponownie)

Zestawy rekreacyjne (kat. 416) nie sa fizycznie skladane — sklep trzyma komponenty i kompletuje
po zamowieniu. Firmes (integracja z Subiektem) wiaze **SKU produktu z PS → pozycje w Subiekcie
literalnie**; zestawy maja sztuczne SKU sklejone z dwoch symboli (`AP1181MANO RQ129119`,
`RQ115118 + mano ter106`, `ATX40-ZESTAW`), ktorych w Subiekcie NIE MA → `quantity` zawsze 0.

Enrichment (`MysqlProductEnrichmentService`) liczy dostepnosc tak:
- `quantity > 0` → `in_stock`
- `quantity = 0` AND `out_of_stock = 1` → `available_to_order`
- `quantity = 0` AND `out_of_stock = 0` → **`unavailable`** ← bot ODSIEWA produkt
- `quantity = 0` AND `out_of_stock = 2` → wg globalnego `PS_ORDER_OUT_OF_STOCK`

**Zweryfikowane na PROD 2026-07-15:** `PS_ORDER_OUT_OF_STOCK` = **1** (przyjmuj zamowienia).
Z 13 zestawow w kat. 416: **7 ma `out_of_stock=2`** (bot je pokazuje — pracownicy ustawili
swiadomie i prawidlowo), **6 ma `out_of_stock=0`** → bot je ukrywa, w tym **2369 (APEKS ATX40
+ manometr), ktory sprzedal sie 34 razy w 6 miesiecy**.

## Zakres — zmiana DANYCH, dokladnie 6 wierszy

Zweryfikowane: kazdy produkt ma DOKLADNIE JEDEN wiersz w `pr_stock_available`
(`id_product_attribute=0`, `id_shop=1`, `out_of_stock=0`, brak kombinacji).

| id_stock_available | id_product | nazwa |
|---|---|---|
| 357 | 2369 | APEKS ATX40 / DS4 + Octopus ATX40 + Manometr (34 szt./6 mies.) |
| 187408 | 5189 | APEKS XTX50 / DST + Octopus XTX40 + Manometr |
| 201529 | 6082 | APEKS ATX40 / DS4 + Octopus + Manometr Apeks |
| 208375 | 6484 | APEKS MTX-RC Zestaw do pojedynczej butli |
| 209728 | 6650 | AQUALUNG Helix Pro + Octopus + Manometr + Torba |
| 214037 | 6992 | AQUALUNG Legend 3 + Octopus + Manometr |

## KROKI

### KROK 0 — polaczenie
**PDO na parametrach PrestaShop** z `~/public_html/newtmp2/app/config/parameters.php`.
**UWAGA (lekcja z CHAT-T-136):** klucz to `database_password`, NIE `database_pass`.
`mysql` CLI odrzuca to haslo (znaki specjalne) → uzyj PDO. User: `divezone_sklep_tmp2`.
NIE uzywaj `divezone_chat_reader` — to konto read-only.

### KROK 1 — BACKUP (zapisz wynik w raporcie, PRZED zmiana)
```sql
SELECT id_stock_available, id_product, id_product_attribute, id_shop, quantity, out_of_stock
FROM pr_stock_available
WHERE id_stock_available IN (357, 187408, 201529, 208375, 209728, 214037);
```
Oczekiwane: 6 wierszy, wszystkie `out_of_stock=0`, `quantity=0`, `id_shop=1`.
**Cokolwiek sie nie zgadza → STOP, zglos Karolowi.**

### KROK 2 — STOP
Pokaz Karolowi backup, czekaj na "wykonaj". Nie rob UPDATE bez potwierdzenia (ADR-089).

### KROK 3 — UPDATE (po kluczu glownym, guard idempotentny)
```sql
UPDATE pr_stock_available
SET out_of_stock = 2
WHERE id_stock_available IN (357, 187408, 201529, 208375, 209728, 214037)
  AND out_of_stock = 0;
```
Oczekiwane: 6 affected rows.

### KROK 4 — weryfikacja
```sql
SELECT sa.id_product, sa.out_of_stock, sa.quantity, LEFT(pl.name, 45) AS nazwa
FROM pr_stock_available sa
JOIN pr_product_lang pl ON pl.id_product = sa.id_product AND pl.id_lang = 1
WHERE sa.id_stock_available IN (357, 187408, 201529, 208375, 209728, 214037);
```
Oczekiwane: 6 wierszy, wszystkie `out_of_stock=2`.

### KROK 5 — cache PS
Wyczysc `var/cache/prod` w `~/public_html/newtmp2/` + flush LSCache (CLAUDE.md sekcja CACHE).
PS cache'uje dostepnosc — bez tego zmiana nie bedzie widoczna.

### KROK 6 — test przez realny czat (PROD)
Zapytaj bota o gotowy zestaw automatu z manometrem. Oczekiwane: pokazuje zestawy (m.in. ATX40)
jako "na zamowienie 2-5 dni", NIE pomija ich jako niedostepne.
**Regula 41b:** rozmowe testowa oznacz w `divechat_conversation_review.note` markerem
`[test CHAT-T-140, nie klient]` (dopisz do istniejacej notatki). **NIE nadawaj verdict** —
ocene robi Karol. `updated_by=NULL`.

## ROLLBACK
```sql
UPDATE pr_stock_available SET out_of_stock = 0
WHERE id_stock_available IN (357, 187408, 201529, 208375, 209728, 214037);
```
Po rollbacku rowniez wyczysc cache.

## Kryteria akceptacji
1. Backup zapisany w raporcie PRZED zmiana.
2. 6 affected rows; weryfikacja pokazuje `out_of_stock=2` na wszystkich szesciu.
3. Cache PS wyczyszczony, sklep 200.
4. Bot pokazuje zestawy jako "na zamowienie" (test przez czat).
5. Zaden inny wiersz `pr_stock_available` nie zmieniony.

## WAZNE — zglos Karolowi w raporcie
**Nie wiemy, czy Firmes nie nadpisuje `out_of_stock` przy synchronizacji** (pytanie do Janka,
bez odpowiedzi). Jesli nadpisuje — zmiana cofnie sie przy najblizszym sync.
**Zaproponuj Karolowi kontrole nastepnego dnia:** ponow zapytanie z KROKU 4. Jesli
`out_of_stock` wrocilo do 0 → Firmes nadpisuje, temat wraca do Karola (droga przez Firmes).

## Kontekst szerszy (NIE realizowac w tym tasku)
Rdzen PS zaklada "pozycja w `pr_order_detail` = jeden product_id + jego SKU", wiec zestawu
zlozonego z dwoch pozycji Subiekta nie da sie odwzorowac ani packiem PS, ani modulem
`wkbundleproduct` (zainstalowany, nieuzywany — bundle to osobny produkt z wlasnym SKU), ani
wlasnym modulem. Rozwiazanie docelowe = mapowanie po stronie Firmes (mail Karola).
Ten task = uczciwe odwzorowanie stanu: zestawu nie ma na polce, jest na zamowienie.
Szczegoly: `_docs/43_handoff_20260715.md` sekcja 4b/4b-bis.

## Git
`git add` per sciezka (task + status). Commit:
`CHAT-T-140 dane: zestawy rekreacyjne out_of_stock 0->2 (6 produktow, kat 416)`. Push.
**Dodatkowo:** w drzewie jest niezacommitowany `_docs/43_handoff_20260715.md` (architekt nie
mogl go zacommitowac — powtarzajacy sie blad SMB `unable to write new index file`).
Zacommituj przy okazji: `git add _docs/43_handoff_20260715.md` + commit
`docs: handoff 43 — poprawiona diagnoza zestawow (Firmes wiaze SKU literalnie)`.

## Domkniecie
Po weryfikacji i tescie: karta → "Do weryfikacji" (Karol potwierdza po kontroli nastepnego dnia,
czy Firmes nie cofnal zmiany), potem "Zrobione".

## Wynik (CC, 2026-07-15)

**Status: WYKONANE NA PROD. Karta Trello RCXL4DfH → „Do weryfikacji".**

Zmiana DANYCH w MySQL sklepu (`divezone_2025`), zero zmian w kodzie. Połączenie PDO
(user `divezone_sklep_tmp2`, klucz `database_password` z parameters.php — `mysql` CLI odrzuca
to hasło, potwierdzona lekcja z CHAT-T-136).

**KROK 1 — backup PRZED zmianą** (6 wierszy, wszystkie `out_of_stock=0`, `id_shop=1`,
`id_product_attribute=0`, `quantity=0` — zgodne z oczekiwaniem tasku):
| id_stock_available | id_product | out_of_stock (przed) |
|---|---|---|
| 357 | 2369 | 0 |
| 187408 | 5189 | 0 |
| 201529 | 6082 | 0 |
| 208375 | 6484 | 0 |
| 209728 | 6650 | 0 |
| 214037 | 6992 | 0 |

STOP przed UPDATE (ADR-089) → Karol potwierdził „wykonaj".

**KROK 3 — UPDATE** (guard `AND out_of_stock=0`): **6 affected rows** (dokładnie 6, żaden inny
wiersz nietknięty — kryt. akc. 5).

**KROK 4 — weryfikacja:** wszystkie 6 mają `out_of_stock=2`. Nazwy potwierdzone
(357=ATX40+manometr, 5189=XTX50, 6082=ATX40, 6484=MTX-RC, 6650=Helix Pro, 6992=Legend 3).

**KROK 5 — cache:** `var/cache/prod` skasowany + LSCache flush. Shop 200.

**KROK 6 — test przez realny czat na PROD** (token HMAC z .env, customer=0, `/api/chat`):
pytanie o gotowy zestaw ATX40 z manometrem → bot pokazuje zmienione zestawy jako
**„na zamówienie 2–5 dni"** (2369 i 6082 ATX40 + 5189 XTX50), NIE odsiewa ich jako niedostępne.
Kryt. akc. 4 spełnione. Rozmowa oznaczona (rule 41b): `divechat_conversation_review`
conversation_id=676, `note=[test CHAT-T-140, nie klient]`, `status=nowy`, `verdict=NULL`,
`updated_by=NULL` (BEZ oceny — robi Karol).

### ⚠️ DO KONTROLI NASTĘPNEGO DNIA (zgłoszone Karolowi)
Nie wiadomo, czy Firmes (sync z Subiektem) nie nadpisuje `out_of_stock` przy synchronizacji.
**Ponów KROK 4 następnego dnia** — jeśli `out_of_stock` wróciło do 0 na którymkolwiek z 6 →
Firmes nadpisuje, temat wraca do Karola (droga przez Firmes / mail do Janka). Jeśli zostało 2 →
zmiana trwała, kartę można przenieść na „Zrobione".

**ROLLBACK:** `UPDATE pr_stock_available SET out_of_stock=0 WHERE id_stock_available IN
(357,187408,201529,208375,209728,214037);` + czyszczenie cache.
