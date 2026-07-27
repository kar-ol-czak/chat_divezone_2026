# TASK-053: Bug fix - dropdown "Reasoning effort" nie pokazuje się

**Status:** TODO  
**Instancja:** backend + frontend (jeden CC ogarnie)  
**Powiązany ADR:** ADR-052 sekcja 5  
**Priorytet:** P1 (blokuje testowanie reasoning effort)

---

## Opis błędu

W panelu ustawień konsoli testowej (`chat.divezone.pl/`):
- Wybrany provider: Anthropic
- Wybrany model: Claude Sonnet 4.6 (`supports_reasoning_effort = TRUE` w bazie)
- **Oczekiwane:** widoczny dropdown "Reasoning effort" pod sliderem Temperature
- **Faktyczne:** dropdown ukryty (klasa `hidden` nadal aktywna)

Karol potwierdził wizualnie - patrz screenshot z 2026-04-30.

---

## Diagnoza wstępna

Kod frontendu (`standalone/public/js/settings.js`) używa data-attribute z dropdowna:

```js
opt.dataset.supportsReasoningEffort = m.supports_reasoning_effort ? '1' : '0';
```

Następnie:

```js
if (meta && meta.supports_reasoning_effort) {
    elEffortGroup.classList.remove('hidden');
}
```

Czyli `m.supports_reasoning_effort` musi być **truthy w JS**. Najprawdopodobniejsze przyczyny:

1. **PHP zwraca string `"t"`/`"f"`** (PostgreSQL native boolean format) zamiast `true`/`false`
   - JS: `Boolean("f")` → `true` (bo niepusty string), ale `'f' ? '1' : '0'` → `'1'` 
   - Czyli wszystkie modele dostają `supportsReasoningEffort = "1"` ALE faktycznie problem byłby odwrotny (wszędzie dropdown).
   - Prawdopodobnie nie to.

2. **PHP zwraca `null`** - niepoprawny mapping z PG do `ModelPrice`
   - `null ? '1' : '0'` → `'0'` - dropdown ukryty zawsze. **Najbardziej prawdopodobne.**

3. **JSON serialization gubi pole** - `available_models[provider].primary[i]` nie ma klucza `supports_reasoning_effort`
   - `undefined ? '1' : '0'` → `'0'` - dropdown ukryty zawsze.

4. **Frontend cache** - przeglądarka serwuje stary JS bez naprawy z TASK-052c
   - Hard refresh (`Cmd+Shift+R`) by pokazał. Ale jeśli kod jest na produkcji to powinno działać.

---

## Zakres naprawy

### Krok 1: Diagnoza (wykonaj PRZED fixem)

W terminalu lokalnym lub na produkcji:

```bash
curl -s https://chat.divezone.pl/api/settings | python3 -m json.tool > /tmp/settings_response.json
cat /tmp/settings_response.json | grep -A 3 "supports_reasoning_effort"
```

Oczekiwane: dla każdego modelu pole `"supports_reasoning_effort": true` (boolean, nie string).

Jeśli widzisz:
- `"supports_reasoning_effort": true/false` (boolean) → bug jest gdzie indziej (CSS? cache?)
- `"supports_reasoning_effort": "t"/"f"` (string) → bug w PHP, Krok 2A
- `"supports_reasoning_effort": null` → bug w mapping PG → PHP, Krok 2B
- Brak pola w odpowiedzi → bug w `SettingsController::get()`, Krok 2C

Zapisz wynik diagnozy w `_instances/backend/handoff/053_diagnosis.md`.

### Krok 2A: Fix dla string "t"/"f"

W `standalone/src/AI/PricingService.php` lub `ModelPrice` value object - wymusić cast:

```php
$price->supportsReasoningEffort = filter_var(
    $row['supports_reasoning_effort'], 
    FILTER_VALIDATE_BOOLEAN
);
```

Plus w `SettingsController::get()` zwrot do JSON:

```php
'supports_reasoning_effort' => (bool) $price->supportsReasoningEffort,
```

### Krok 2B: Fix dla null mapping

Sprawdź w `PricingService::getAllActive()` czy SELECT query zawiera kolumny
`supports_temperature`, `supports_reasoning_effort` (mogły być pominięte).

Sprawdź czy mapping z PDO row do `ModelPrice` używa właściwych nazw kolumn
(case-sensitive).

### Krok 2C: Fix dla brakującego pola w response

W `SettingsController::get()` array do JSON - dodać brakujące pole:

```php
'available_models' => [
    'claude' => [
        'primary' => array_map(fn($p) => [
            'value' => $p->modelId,
            'label' => $p->label,
            'input_price' => (float) $p->inputPricePerMillion,
            'output_price' => (float) $p->outputPricePerMillion,
            'supports_temperature' => (bool) $p->supportsTemperature,
            'supports_reasoning_effort' => (bool) $p->supportsReasoningEffort,  // <-- weryfikuj
        ], $claudePrimary),
        // ...
    ],
],
```

### Krok 3: Test po fixie

```bash
# Curl powinien pokazać true/false dla wszystkich modeli
curl -s https://chat.divezone.pl/api/settings | \
  python3 -c "import sys, json; d=json.load(sys.stdin); \
    [print(m['value'], m.get('supports_reasoning_effort')) \
     for p in d['available_models'].values() \
     for tier in p.values() for m in tier]"
```

Oczekiwane:
```
claude-haiku-4-5 True
claude-sonnet-4-6 True
claude-opus-4-7 True
gpt-4.1 False
gpt-5-mini True
gpt-5.4 True
gpt-5.4-mini True
o3-mini True
```

### Krok 4: Smoke test w UI

1. Otwórz `https://chat.divezone.pl/`
2. Hard refresh (`Cmd+Shift+R`)
3. Wybierz Anthropic + Claude Sonnet 4.6
4. **Oczekiwane:** dropdown "Reasoning effort" widoczny pod Temperature
5. Wybierz GPT-4.1
6. **Oczekiwane:** dropdown "Reasoning effort" ukryty, slider Temperature aktywny
7. Wybierz Claude Haiku 4.5
8. **Oczekiwane:** Temperature wyszarzony z tooltipem ⓘ, dropdown effort widoczny

Zapisz wynik smoke testu w `_instances/backend/handoff/053_smoke_test.md`.

### Krok 5: Commit i deploy

```bash
git add <zmodyfikowane pliki>
git commit -m "TASK-053: fix bug - effort dropdown nie pokazywal sie (rzutowanie PG bool)"
git push origin main

# Deploy
rsync -avz --exclude='.env' --exclude='vendor/' --exclude='.DS_Store' \
  -e "ssh -p 5739" \
  ./standalone/ \
  divezone@divezonededyk.smarthost.pl:/home/divezone/public_html/chat.divezone.pl/
```

---

## Kryteria akceptacji

1. `/api/settings` zwraca `supports_reasoning_effort` jako boolean true/false dla wszystkich 8 modeli
2. Wartości zgodne z bazą: GPT-4.1 = false, reszta = true
3. W UI dropdown "Reasoning effort" pojawia się dla modeli rozumujących, ukryty dla GPT-4.1
4. Hard refresh nie potrzebny po deployu (kod JS już istnieje od TASK-052c, fix jest po stronie backend)

---

## Czego NIE robić

- NIE modyfikuj logiki frontendu (`settings.js`) - jest poprawna
- NIE modyfikuj migracji 007 - schemat bazy jest OK
- NIE dodawaj nowych modeli ani kolumn
- NIE rozpoczynaj TASK-054/055 dopóki ten nie jest zmergowany
- NIE pytaj architekta - diagnoza jest jednoznaczna, idź krok po kroku
