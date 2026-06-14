# CHAT-T-088d — BACKEND/SEED: rozdzielenie "Dobór sprzętu" / "Dobór rozmiaru" na Level 1

**Instancja:** backend (SQL seed). UPDATE + INSERT divechat_chip_nodes. Bez zmian kodu/struktury.
**Powiązane:** CHAT-T-088/088b/088c (drzewo na PROD), ADR-096. Decyzje: 57a (dwa osobne węzły, teksty deterministyczne), 58a (limit mobilny → 6, osobno we froncie).
**Status:** DONE (2026-06-14). Idempotentny; Karol zaaplikował na Railway. Migracja `sql/032_split_dobor.sql` (+rollback). Status v3.64.

## PROBLEM (test na żywo)
Węzeł `dobor` (id 5) ma label "Dobór sprzętu" ale bot_text o ROZMIARZE ("Czego rozmiar dobieramy?"). To dwie różne ścieżki zlepione w jeden węzeł:
- Dobór SPRZĘTU = doradztwo zakupowe (jaką maskę/automat/komputer kupić).
- Dobór ROZMIARU = pomiar (jaki rozmiar pianki/kaptura/skafandra na mnie).
Rozdzielić na DWA węzły Level 1.

## STAN (PROD, zweryfikowany)
- dobor: id=5, parent=1 (root), level=2, sort_order=4, label="Dobór sprzętu", bot_text=błędnie o rozmiarze, buttons=[{label:"Napisz czego szukasz",target:"ai"}].
- Level 1 (dzieci root): zwroty(sort1), serwis(sort2), wysylka(sort3), dobor(sort4). Po zmianie dojdzie dobor_rozmiar(sort5) = 5 chipów.

## ZAKRES — migracja sql/032_split_dobor.sql (+rollback), idempotentny
```sql
-- 1. Korekta istniejącego węzła dobor → CZYSTO sprzętowy (57a)
UPDATE divechat_chip_nodes
SET bot_text = 'Jasne, pomogę dobrać sprzęt. Co Cię interesuje — maska, płetwy, automat, komputer nurkowy, czy coś innego?',
    label = 'Dobór sprzętu',
    updated_at = NOW()
WHERE node_key = 'dobor';

-- 2. Nowy węzeł dobor_rozmiar (Level 1, po dobor). ON CONFLICT = idempotentny.
--    parent_id = id węzła root (pobrany podzapytaniem, NIE hardcode).
INSERT INTO divechat_chip_nodes (node_key, parent_id, level, sort_order, label, bot_text, buttons, active)
VALUES (
  'dobor_rozmiar',
  (SELECT id FROM divechat_chip_nodes WHERE node_key = 'root'),
  2,
  5,
  'Dobór rozmiaru',
  'Pomogę dobrać rozmiar. Czego rozmiar dobieramy i dla mężczyzny czy kobiety?',
  '[{"label":"Napisz czego szukasz","target":"ai"}]'::jsonb,
  TRUE
)
ON CONFLICT (node_key) DO UPDATE SET
  label = EXCLUDED.label, bot_text = EXCLUDED.bot_text, buttons = EXCLUDED.buttons,
  sort_order = EXCLUDED.sort_order, updated_at = NOW();
```
UWAGA: oba teksty są DETERMINISTYCZNE (klik → gotowy bot_text, ZERO LLM). Dopiero gdy klient NAPISZE swoje (przycisk "Napisz czego szukasz" → ai LUB wpisze w input) → idzie do LLM. To zgodne z decyzją 57a.

Rollback sql/032_split_dobor_rollback.sql:
- DELETE FROM divechat_chip_nodes WHERE node_key='dobor_rozmiar';
- UPDATE dobor SET bot_text='Pomogę dobrać sprzęt. Co Cię interesuje?' (przywróć poprzedni) WHERE node_key='dobor'.

## POWIĄZANE (front, OSOBNO — nie w tym tasku): limit mobilny 6 (58a)
Level 1 ma teraz 5 chipów. Limit mobilny CHIPS_MOBILE_LIMIT=4 → jeden się nie zmieści. Decyzja 58a: podnieść do 6. To JEDNOLINIJKOWA zmiana w widget-bundle.js (var CHIPS_MOBILE_LIMIT = 6;). Zgłoszone jako mini-zmiana frontowa — albo osobny mikro-commit, albo dołączyć do najbliższego deployu frontu (CHAT-T-088e/Level2). NIE w tym seedzie.

## KRYTERIA AKCEPTACJI
- [ ] Migracja 032 + rollback, idempotentna.
- [ ] dobor: label "Dobór sprzętu" + bot_text sprzętowy (maska/płetwy/automat/komputer).
- [ ] dobor_rozmiar: nowy węzeł Level 1, label "Dobór rozmiaru", bot_text o rozmiarze, sort 5.
- [ ] GET /api/chip-tree → root ma 5 dzieci, dwa osobne węzły doboru z różnymi tekstami.

## DEPLOY
Migracja 032 na Railway = Karol aplikuje (jak 028-031). Bez deployu kodu (seed). Smoke: curl /api/chip-tree | python3 -m json.tool | grep -A1 "dobor".

## RAPORT
Commit per ścieżka (sql/032*), wg konwencji "CHAT-T-088d backend: rozdzielenie Dobór sprzętu/rozmiaru na Level 1 (57a)", push, docs: status.
