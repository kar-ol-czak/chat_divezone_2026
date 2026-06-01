---
number: T-031
title: Re-seed kuratorowanych rekomendacji ADR-065 (Faza B)
status: DONE
phase: B
architect: Karol (decyzja kuratorska), CC Opus 4.7 (wykonanie)
date: 2026-06-01
decisions_refs: [ADR-065, ADR-065-uzup.1, decyzja 172c]
preconditions: [T-029 backend MVP (tabela + tool + enrich), T-030 sales report PS, T-031 Faza A ranking Subiekt commit 547bebf]
unlocks: [bot wola get_curated_recommendations dla 3 kategorii doboru, ADR-065 status -> Wdrozony MVP]
commit_code: 612ed22
commit_docs: (drugi commit dodawany razem z tym handoffem)
---

## Cel
Wypelnic pusta tabele `divechat_curated_recommendations` realnymi bestsellerami dla automatow + komputerow. Po seedzie bot poleca konkretne, kuratorowane produkty przy pytaniach o dobor, NIE zgaduje.

## Stan wejsciowy
- Tabela `divechat_curated_recommendations` istnieje (sql/016, T-029), PUSTA.
- Narzedzie `get_curated_recommendations` zarejestrowane na prod (T-029 deploy).
- Ranking kandydatow z Subiekta 12mc gotowy (T-031 Faza A, commit 547bebf).
- Wybor produktow + `rationale_pl` per kategoria: decyzja Karola (172c).

## Co wykonano
1. **Apply seed** `sql/017_seed_curated_automaty_komputery.sql` na Railway PG (psql DATABASE_URL). INSERT 0 9 OK. Idempotentne `ON CONFLICT (category_key, product_id) DO UPDATE` refresh `verified_at`+`active=TRUE`.
2. **Weryfikacja schematu** post-seed:
   - 9 rzedow, 3 distinct `category_key`
   - Peregrine (5986) w 2 kategoriach komputerow z roznymi `rationale_pl` (UNIQUE per cat+prod pozwala)
   - `recheck_interval_days`: 180 dla automatow, 90 dla komputerow (zgodnie z taskiem)
3. **Smoke test `get_curated_recommendations`** przez inline PHP CLI na prod (ea-php84):
   - Enum z `getParametersSchema()` (generowany z DB live): 3/3 oczekiwane kategorie obecne + opisy z `category_label_pl` widoczne w description field
   - Execute kazdej kategorii: `status=ok`, `curated_count=3`, `available_count=3`, 0 skipped (wszystkie active+visible+!=unavailable)
   - Cena/availability/promocje z zywego MySQL przez `MysqlProductEnrichmentService` (T-029 refactor)
   - Kolejnosc wg `priority ASC`

## Wybor Karola (skrót)

### regulator_recreational (recheck=180)
| priority | id | model | rationale (skrot) |
|---|---|---|---|
| 1 | 2368 | APEKS ATX40/DS4 + zestaw | "najczesciej wybierany od lat, sprzedalismy w setkach, niezawodny zimnowodny" |
| 2 | 25   | APEKS DST 1.stopien    | "wyzsza polka, lepsza regulacja oddechu, zapas na lata" |
| 3 | 5983 | AQUALUNG Legend 3 + Octopus Legend | "topowy premium, legenda klasy" |

### computer_budget_first (recheck=90)
| priority | id | model | rationale (skrot) |
|---|---|---|---|
| 1 | 5060 | SUUNTO Zoop Novo Black | "najrozsadniejszy budzetowy start, mono ale czytelny klasyk" |
| 2 | 5706 | SUUNTO D5 All Black    | "kolorowy + forma zegarka, ladniejszy niz Zoop" |
| 3 | 5986 | SHEARWATER Peregrine   | "bestseller gdy budzet pozwala wiecej" |

### computer_polish_waters (recheck=90)
| priority | id | model | rationale (skrot) |
|---|---|---|---|
| 1 | 5986 | SHEARWATER Peregrine | "duzy kolorowy ekran, czytelny w ograniczonej widocznosci PL wod" |
| 2 | 7515 | SUUNTO NAUTIC S      | "najnowszy Suunto, swiezy hit (ADR-065 uzup.1: proof na marce mimo braku historii)" |
| 3 | 5463 | SUUNTO EON Core      | "duzy kolor + zapas na rozwoj nitrox/zaawansowane" |

## Smoke test — surowy wynik (kompletny)

### Enum (getParametersSchema)
```
Categories in enum: 3
  - computer_budget_first
  - computer_polish_waters
  - regulator_recreational
Expected categories present: YES (3/3)
```

### Execute per kategoria (status / cena z MySQL / availability)

**computer_budget_first** — status=ok, available=3/3:
```
priority=1  id=5060  price=1051.00                       availability=in_stock
priority=2  id=5706  price=1204.41 (przed promo 2106.00)  availability=in_stock
priority=3  id=5986  price=2400.33 (przed promo 2697.00)  availability=available_to_order
```

**computer_polish_waters** — status=ok, available=3/3:
```
priority=1  id=5986  price=2400.33 (przed promo 2697.00)  availability=available_to_order
priority=2  id=7515  price=2276.00 (przed promo 2399.00)  availability=available_to_order
priority=3  id=5463  price=1959.18 (przed promo 2739.00)  availability=in_stock
```

**regulator_recreational** — status=ok, available=3/3:
```
priority=1  id=2368  price=2103.02 (przed promo 2389.80)  availability=in_stock
priority=2  id=25    price=1105.10 (przed promo 1255.80)  availability=in_stock
priority=3  id=5983  price=3775.80                       availability=available_to_order
```

## Konsekwencje
- ADR-065 status: Zaakceptowany → **Wdrozony MVP** (backend tool + tabela + seed live, bot moze konsumowac live).
- Bot przy realnych pytaniach typu "jaki komputer dla osoby z budzetem do X" / "jaki automat do rekreacji" / "komputer do polskich wod" — wybiera odpowiednia kategorie z enum i prezentuje kuratorowana liste z `rationale_pl` + live cena/availability.

## Out of scope (kolejne kroki w pelnej wersji ADR-065)
- Panel admina PrestaShop z autocomplete (ADR-067 caly krgoslup panelu, osobny duzy strumien).
- Cykliczne przypomnienia per kategoria (miekki staleness wg `recheck_interval_days`, komunikat do obslugi).
- Alerty dostepnosci (twardy staleness pisany w execute juz dziala — pomija w runtime; alert do obslugi to nowa funkcja).
- Pole `proof_type` w schemacie (proven_model vs new_model) — dla rozroznienia jezyka rekomendacji bota. Na razie roznica zaszyta w `rationale_pl` recznie przez Karola (np. NAUTIC S: "marka bez ryzyka", Zoop Novo: "sprzedajemy od lat").
- Re-seed pozostalych kategorii (maski, pianki, pletwy, BCD, suche, ocieplacze, latarki, skrzydla) — analogiczna procedura A→B per kategoria.

## Rollback
`psql $DATABASE_URL -f sql/017_seed_curated_automaty_komputery_rollback.sql` — DELETE WHERE category_key IN tych 3 kluczy. Tabela zostaje pusta (lub z innymi kategoriami jesli zostaly dodane w miedzyczasie).
