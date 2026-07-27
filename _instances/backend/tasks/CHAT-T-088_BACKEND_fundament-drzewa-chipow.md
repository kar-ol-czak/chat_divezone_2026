# CHAT-T-088 — BACKEND: fundament drzewa chipów (schemat PG + endpoint + seed gałęzi operacyjnej)

**Instancja:** backend (PHP). Migracja 029 (schemat) + endpoint GET + seed gałęzi operacyjnej. BEZ silnika widgetu (frontend, osobny task), BEZ panelu edycji (osobny task po schemacie).
**Powiązane:** ADR-096 (model węzła: hierarchia + inline + hybryda — OBOWIĄZUJĄCY), ADR-071 (zasady zachowane: context_hint, model_level, głębokość-za-danymi, pierwsza wiadomość 114a, Q231a deterministyczne fakty), `_docs/37_tresc_chipow_operacyjnych.md` (zatwierdzona treść zwroty+serwis), ADR-095 (treść wysyłki).
**Status:** DO ZROBIENIA. Schemat zaakceptowany (ADR-096). Migracja 029 + endpoint + seed.

---

## CEL
Fundament drzewa chipów wg ADR-096: tabela `divechat_chip_nodes` (hierarchiczna, węzeł hybrydowy) + endpoint GET dla widgetu + seed WĄSKIEJ gałęzi operacyjnej (zwroty, serwis, wysyłka) + Level 1 root z placeholderem doboru sprzętu. Gałąź doboru (Level 2/3) dochodzi PO sesji zespołu — NIE tutaj.

## ZAKRES
1. Migracja `sql/029_chip_nodes.sql` (+rollback): tabela wg schematu ADR-096.
2. `src/Tools/...` lub `src/Chip/ChipTreeService.php` — odczyt drzewa z PG (całe aktywne drzewo, zbudowane jako mapa/zagnieżdżenie po parent_id).
3. Endpoint `GET /api/chip-tree` — zwraca całe aktywne drzewo, cache'owalne (ETag/Cache-Control), bez auth (treść publiczna jak nudge BOOT).
4. Seed gałęzi operacyjnej (w migracji 029 lub osobnym 029_seed) — patrz niżej.
NIE w tym tasku: silnik widgetu (frontend), panel edycji chipów (zakładka PS), gałąź doboru sprzętu, routing modeli per liść.

## SCHEMAT (ADR-096 — wiążący, NIE zmieniaj bez nowego ADR)
```
divechat_chip_nodes:
  id           BIGSERIAL PK
  node_key     TEXT UNIQUE NOT NULL
  parent_id    BIGINT NULL REFERENCES divechat_chip_nodes(id) ON DELETE CASCADE
  level        INT NOT NULL DEFAULT 1
  sort_order   INT NOT NULL DEFAULT 0
  bot_text     TEXT NULL          -- Markdown, treść inline (18a/31a)
  buttons      JSONB NOT NULL DEFAULT '[]'  -- TYLKO akcje nie-nawigacyjne [{label,target}], target=link:<klucz>|curated:<kat>|modal:<typ>|ai
  context_hint TEXT NULL
  model_level  TEXT NULL          -- CHECK IN ('basic','primary','escalation') OR NULL
  active       BOOLEAN NOT NULL DEFAULT TRUE
  updated_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
```
Indeksy: (parent_id, sort_order) — budowa poddrzew; (active) — filtr. CHECK na model_level. COMMENT-y wg konwencji (patrz sql/025).
KLUCZOWE rozróżnienie: dzieci-podchipy = rekordy z parent_id wskazującym na węzeł (NIE w buttons). buttons = tylko akcje końcowe (link zewnętrzny / curated / modal / ai). Endpoint składa drzewo: dla każdego węzła dzieci = SELECT WHERE parent_id=id ORDER BY sort_order.

## ENDPOINT (kontrakt)
`GET /api/chip-tree` → JSON: drzewo od korzeni (parent_id IS NULL), każdy węzeł:
```
{ node_key, bot_text (md), buttons:[{label,target}], children:[...rekurencyjnie...], context_hint, model_level }
```
Tylko active=true. Cache'owalne. Widget pobiera raz na starcie, renderuje lokalnie. Bez auth.

## SEED GAŁĘZI OPERACYJNEJ (z _docs/37_ + ADR-095)
Poziom 1:
```
root (level 1, bot_text="W czym mogę pomóc?", buttons=[]):
  dzieci (parent_id=root):
    zwroty   (level 2, bot_text = treść zwrotów z _docs/37_ [WYGŁADŹ literówkę "14 dniowego prawa do zwrotu," — urwane zdanie], buttons=[{label:"Formularz i szczegóły", target:"link:link_zwroty"},{label:"Inne pytanie", target:"ai"}])
    serwis   (level 2, bot_text = treść serwisu z _docs/37_ [mail serwisowy: serwis@divezone.pl — decyzja 27], buttons=[{label:"Pełny cennik", target:"link:link_serwis"},{label:"Umów serwis", target:"link:link_kontakt"},{label:"Inne pytanie", target:"ai"}])
    wysylka  (level 2, bot_text = treść dostawy z ADR-095 dec.1: "Zamówienia złożone do 15:00 w dni robocze zwykle wysyłamy tego samego dnia, większość paczek dociera następnego dnia roboczego. Nie gwarantujemy terminu (kurier). Po 100% pewność: 56 307 03 03." [DOPRECYZUJ z get_shipping_info dla kosztów], buttons=[{label:"Koszty i metody dostawy", target:"ai"},{label:"Inne pytanie", target:"ai"}])
    dobor    (level 2, ai_entry — PLACEHOLDER: bot_text="Pomogę dobrać sprzęt. Co Cię interesuje?", buttons=[{label:"Napisz czego szukasz", target:"ai"}], context_hint=NULL) — pełna gałąź doboru PO sesji zespołu
```
UWAGA hybryda (32b): węzeł serwis/zwroty MA bot_text ORAZ buttons jednocześnie — to wzorcowy przypadek z ADR-096.
UWAGA Q231a: zwroty/serwis/wysylka to rdzeń deterministyczny (bot_text podawany WPROST, bez LLM). Tylko "Inne pytanie" schodzi do ai.

## DŁUG POWIĄZANY (osobno, nie blokuje 088): serwis@divezone.pl
Decyzja 27: serwis@divezone.pl = domyślny adres serwisowy. Wymaga: (a) seed configu `email_serwis` w divechat_shop_config, (b) SystemPrompt l.86 zmiana dive@→serwis@ dla serwisu. To osobny mikro-task po wdrożeniu CHAT-T-087 B+C (NIE mieszać do 088 ani do gotowego B+C).

## KRYTERIA AKCEPTACJI
- [ ] Migracja 029 + rollback, schemat dokładnie wg ADR-096 (parent_id, hybryda, CHECK model_level).
- [ ] GET /api/chip-tree zwraca zagnieżdżone drzewo (root → zwroty/serwis/wysylka/dobor), tylko active, cache'owalne.
- [ ] Seed: 4 węzły operacyjne + root, treść zwroty/serwis z _docs/37_ (wygładzona), wysylka z ADR-095.
- [ ] Węzeł serwis ma bot_text ORAZ buttons (test hybrydy).
- [ ] Test odczytu drzewa (struktura, kolejność po sort_order).

## PYTANIA OTWARTE (do Karola — niezablokujące startu schematu)
1. Czy seed treści idzie w migracji 029, czy osobnym pliku 029_seed.sql (rekomendacja: osobny seed, by errata treści nie wymagała re-migracji struktury).
2. Endpoint: prefiks `/api/chip-tree` OK czy inna konwencja routingu (sprawdź config/routes.php).

## RAPORT
Aktualizacja _docs/21_STATUS + ADR-071/096 (zrealizowano fundament). Commit per ścieżka (sql/029*, src/Chip/*, config/routes.php, tests/*), wg konwencji, push, osobny docs:.
