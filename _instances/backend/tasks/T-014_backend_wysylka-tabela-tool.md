# T-014: Dane wysyłki z tabeli PG (fix hardcoded ShippingInfo)

**Instancja:** backend
**Powiązane:** ADR-059, testy pracowników 65/66/70/75, ADR-052 (pricing table wzorzec)
**Priorytet:** P1 (bot podaje BŁĘDNE stawki klientom)
**Czas estymowany:** ~2.5h CC

## Cel

Zastąpić hardcoded dane w `ShippingInfo.php` odczytem z tabeli `divechat_shipping_rates` + `divechat_shop_config` (PG, edytowalne online). Dane wg ADR-059.

## Kontekst

`ShippingInfo.php::execute()` ma hardcoded: DPD 15,99 / InPost 14,99 / Paczkomat 12,99 / próg 499 / "Odbiór Warszawa". Wszystko BŁĘDNE (sklep w Toruniu, prawidłowe stawki w ADR-059). Bot zwraca te dane klientom.

## KROK 0. Read

- `_docs/10_decyzje_projektowe.md` sekcja ADR-059 (schema + dane + logika)
- `standalone/src/Tools/ShippingInfo.php` (obecny tool — do przepisania)
- `standalone/src/Tools/GetShopSchedule.php` (wzorzec toola czytającego z PG — connection pattern)
- `sql/007_model_pricing_and_usage.sql` (wzorzec migracji + tabeli edytowalnej online, ADR-052)
- `standalone/src/AI/` lub gdzie jest PostgresConnection singleton (jak GetShopSchedule łączy się do PG)

## KROK 1. Migracja 013 — divechat_shipping_rates + divechat_shop_config

Plik: `sql/013_shipping_rates.sql` + rollback `sql/013_shipping_rates_rollback.sql`.

```sql
CREATE TABLE IF NOT EXISTS divechat_shipping_rates (
    id SERIAL PRIMARY KEY,
    carrier_name TEXT NOT NULL,
    zone TEXT NOT NULL DEFAULT 'PL',
    price NUMERIC(7,2) NOT NULL,
    cod_price NUMERIC(7,2),
    delivery_days TEXT NOT NULL DEFAULT '1-2 dni robocze',
    max_weight_kg INTEGER NOT NULL DEFAULT 31,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order INTEGER NOT NULL DEFAULT 0,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(carrier_name, zone)
);

CREATE TABLE IF NOT EXISTS divechat_shop_config (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    note TEXT,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

### Seed danych PL (wg ADR-059, dane Karola):

```sql
INSERT INTO divechat_shipping_rates (carrier_name, zone, price, cod_price, delivery_days, sort_order) VALUES
('Paczkomat InPost', 'PL', 13.00, NULL, '1-2 dni robocze', 1),
('Kurier InPost', 'PL', 13.00, 26.00, '1-2 dni robocze', 2),
('Kurier DPD', 'PL', 21.99, 26.00, '1-2 dni robocze', 3),
('Odbiór osobisty', 'PL', 0.00, NULL, 'Po umówieniu, ul. Storczykowa 5, Toruń', 4)
ON CONFLICT (carrier_name, zone) DO UPDATE SET
    price = EXCLUDED.price, cod_price = EXCLUDED.cod_price,
    delivery_days = EXCLUDED.delivery_days, updated_at = NOW();

INSERT INTO divechat_shop_config (key, value, note) VALUES
('free_shipping_threshold_pl', '299', 'Próg darmowej dostawy w PLN dla strefy PL')
ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW();
```

UWAGA: strefa EU NIE jest seedowana (Karol poda stawki EU później). Tool obsługuje brak danych EU gracefully (patrz KROK 2).

Apply na Railway. Weryfikacja: 4 wiersze PL + 1 config.

## KROK 2. Przepisanie ShippingInfo.php

Tool czyta z tabeli zamiast hardcoded. Zmiany:

### getParametersSchema — dodać `zone`:

```php
'properties' => [
    'cart_total' => [
        'type' => 'number',
        'description' => 'Wartość koszyka w PLN (do sprawdzenia progu darmowej dostawy)',
    ],
    'zone' => [
        'type' => 'string',
        'enum' => ['PL', 'EU'],
        'description' => 'Strefa dostawy: PL (Polska) lub EU (reszta Europy). Default PL.',
    ],
],
```

### execute():

```php
public function execute(array $params): array
{
    $cartTotal = isset($params['cart_total']) ? (float) $params['cart_total'] : null;
    $zone = ($params['zone'] ?? 'PL') === 'EU' ? 'EU' : 'PL';

    $pg = PostgresConnection::getInstance(); // wzorzec z GetShopSchedule

    $methods = $pg->fetchAll(
        "SELECT carrier_name, price, cod_price, delivery_days
         FROM divechat_shipping_rates
         WHERE zone = ? AND active = TRUE
         ORDER BY sort_order, price",
        [$zone]
    );

    // Brak danych dla strefy (np. EU jeszcze nie seedowane)
    if (empty($methods)) {
        return [
            'zone' => $zone,
            'methods' => [],
            'note' => $zone === 'EU'
                ? 'Dla wysyłki poza Polskę skontaktuj się: dive@divezone.pl lub 56 307 03 03 — podamy dokładny koszt dla Twojego kraju.'
                : 'Brak danych o dostawie. Kontakt: dive@divezone.pl',
        ];
    }

    $threshold = (float) ($pg->fetchOne(
        "SELECT value FROM divechat_shop_config WHERE key = ?",
        ['free_shipping_threshold_' . strtolower($zone)]
    )['value'] ?? 0);

    $freeShipping = $threshold > 0 && $cartTotal !== null && $cartTotal >= $threshold;

    return [
        'zone' => $zone,
        'methods' => $methods, // każdy: carrier_name, price, cod_price, delivery_days
        'free_shipping_threshold' => $threshold > 0 ? $threshold : null,
        'cart_total' => $cartTotal,
        'free_shipping' => $freeShipping,
        'note' => $this->buildNote($freeShipping, $cartTotal, $threshold),
    ];
}
```

`buildNote()` — helper generujący komunikat o darmowej dostawie (zachowaj logikę z obecnego MVP ale z dynamicznym threshold).

WAŻNE: usuń CAŁY hardcoded blok `$methods = [...]` i `$freeThreshold = 499.0`. Zostają tylko dane z tabeli.

## KROK 3. PostgresConnection w tool — DI lub singleton

Sprawdź jak GetShopSchedule dostaje połączenie PG (constructor injection czy singleton). Zastosuj ten sam wzorzec w ShippingInfo. Jeśli ShippingInfo jest tworzony w ToolRegistry bez PG — dodać wstrzyknięcie połączenia.

## KROK 4. PHP lint + smoke lokalny

```bash
php -l standalone/src/Tools/ShippingInfo.php
```

Jeśli PG dostępne lokalnie (DATABASE_URL w .env): test wykonania tool.

## KROK 5. STOP point — diff do review Karol

Status: "READY FOR REVIEW v1". Wklej:
- SQL migracji 013 (treść + seed)
- Diff ShippingInfo.php
- Wynik testu tool (jeśli PG lokalnie) lub plan smoke prod

NIE deploy bez akceptacji.

## KROK 6. Deploy

- Apply migracja 013 na Railway (idempotentna)
- scp ShippingInfo.php (+ ToolRegistry/config jeśli zmienione dla DI)
- php -l na prod
- Smoke prod:
  ```bash
  # przez chat: "Ile kosztuje wysyłka?" → bot zwraca 13/13/21.99 + pobranie 26 + darmowa od 299
  # lub direct tool test jeśli jest endpoint
  ```

## KROK 7. Git workflow

```bash
git status
git add sql/013_shipping_rates.sql sql/013_shipping_rates_rollback.sql
git add standalone/src/Tools/ShippingInfo.php
# jeśli zmienione: git add standalone/src/Tools/ToolRegistry.php standalone/config/*.php
git commit -m "T-014: dane wysyłki z tabeli PG (fix hardcoded ShippingInfo)

ShippingInfo tool czytał hardcoded BŁĘDNE stawki (DPD 15.99/InPost 14.99/
Paczkomat 12.99/próg 499/Odbiór Warszawa — sklep w Toruniu). Bot zwracał
to klientom. Fix: tabela divechat_shipping_rates + divechat_shop_config
(edytowalne online jak model pricing ADR-052).

Prawidłowe dane (ADR-059): Paczkomat/InPost 13 zł, DPD 21.99 zł,
pobranie 26 zł, darmowa od 299 zł, flat do 31 kg. Strefa EU obsługiwana
gracefully (kontakt gdy brak danych — Karol uzupełni).

Powiązany ADR: ADR-059"
git push origin main
```

## KROK 8. Smoke test produkcyjny dla Karola

1. Chat: "Ile kosztuje wysyłka kurierem?" → DPD 21,99 zł + InPost 13 zł, pobranie 26 zł, darmowa od 299 zł (NIE stare 15,99/499)
2. Chat: "Czy wysyłacie do paczkomatów?" → Paczkomat InPost 13 zł, 1-2 dni, darmowa od 299 zł
3. Chat (EN): "Do you ship to Germany?" → bot pyta o kraj LUB mówi "for EU contact dive@divezone.pl" (brak danych EU = graceful)

## KROK 9. Raport + status update

### `_instances/backend/handoff/T-014_done.md`:
- Migracja 013 applied + 4 wiersze PL + 1 config
- Diff ShippingInfo.php
- Smoke prod 3 scenariusze
- Git commit hash

### Update `_docs/21_STATUS_PROJEKTU.md`:
- T-014 DEPLOYED, dane wysyłki z tabeli
- Backlog: seed stawek EU (Karol poda), przyszłe UI do edycji shipping_rates

### Osobny commit "docs:":

```bash
git add _docs/21_STATUS_PROJEKTU.md _docs/10_decyzje_projektowe.md
git commit -m "docs: T-014 DEPLOYED + ADR-059"
git push origin main
```

## Out of scope

- Seed stawek EU (Karol poda osobno → potem SQL INSERT lub UI)
- UI do edycji shipping_rates (przyszły task, jak model pricing UI)
- Kalkulacja per koszyk waga (flat rate = niepotrzebne)
- Logika językowa PL/EU w odpowiedzi bota → T-016 (instrukcja SystemPrompt)
- ETL z pr_carrier (ADR-059 out of scope)
