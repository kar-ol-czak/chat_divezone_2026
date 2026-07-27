# TASK-CHAT-007b: ShopCalendar + tool get_shop_schedule (P0)

**Instancja:** backend
**Powiązany ADR:** ADR-053 pkt 6
**Priorytet:** P0
**Zależność:** może być rozwijany równolegle z TASK-CHAT-007a, ale wymaga rejestracji w ToolRegistry przed deployem 007a

## Cel

Dać czatowi świadomość kalendarza pracy sklepu: stałe godziny pon-pt 9:00-17:00, polskie święta (stałe + ruchome), oraz tabela override dla urlopów/inwentaryzacji edytowana z panelu admina.

Rozwiązuje case obietnica (bot obiecał klientowi reakcję 5 czerwca bez sprawdzenia czy to dzień roboczy).

## Komponenty

### 1. Klasa `ShopCalendar`

Lokalizacja: `standalone/src/Shop/ShopCalendar.php` (nowy katalog `Shop/`)

Kontrakt publiczny:

```php
namespace DiveChat\Shop;

final class ShopCalendar
{
    public function isWorkingDay(\DateTimeImmutable $date): bool;
    public function nextWorkingDay(\DateTimeImmutable $date): \DateTimeImmutable;
    public function currentlyOpen(?\DateTimeImmutable $now = null): bool;
    public function holidayName(\DateTimeImmutable $date): ?string;
    public function scheduleForDate(\DateTimeImmutable $date): ScheduleResult;
}
```

`ScheduleResult` — value object zwracany przez `scheduleForDate`:
- `bool $isOpen` (true jeśli teraz w godzinach pracy)
- `bool $workingDay` (true jeśli to dzień roboczy)
- `?string $holidayName` (np. "Boże Ciało", null jeśli to zwykły dzień)
- `string $opensAt` ("09:00")
- `string $closesAt` ("17:00")
- `?string $closedReason` ("święto", "weekend", "urlop pracowniczy", null jeśli otwarte)

### 2. Dane statyczne — święta

Tablica świąt stałych w klasie:

```php
private const FIXED_HOLIDAYS = [
    '01-01' => 'Nowy Rok',
    '01-06' => 'Trzech Króli',
    '05-01' => 'Święto Pracy',
    '05-03' => 'Święto Konstytucji 3 Maja',
    '08-15' => 'Wniebowzięcie Najświętszej Maryi Panny',
    '11-01' => 'Wszystkich Świętych',
    '11-11' => 'Narodowe Święto Niepodległości',
    '12-24' => 'Wigilia Bożego Narodzenia',
    '12-25' => 'Boże Narodzenie',
    '12-26' => 'Drugi Dzień Bożego Narodzenia',
];
```

Święta ruchome — wygenerować dynamicznie z daty Wielkanocy dla danego roku:
- Niedziela Wielkanocna (algorytm Gaussa lub `easter_date($year)` z PHP `ext-calendar` jeśli dostępne)
- Poniedziałek Wielkanocny (+ 1 dzień)
- Zielone Świątki (+ 49 dni od Wielkanocy)
- Boże Ciało (+ 60 dni od Wielkanocy)

Cache obliczonych świąt ruchomych dla roku w pamięci procesu (request-scoped). Nie ma potrzeby cache na dysk.

### 3. Override z bazy — tabela PG

Migracja: `standalone/db/migrations/00X_shop_calendar_overrides.sql` (kolejny numer)

```sql
CREATE TABLE divechat_shop_calendar_overrides (
    date DATE PRIMARY KEY,
    reason TEXT NOT NULL,
    is_working_day BOOLEAN NOT NULL,
    opens_at TIME,
    closes_at TIME,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

COMMENT ON TABLE divechat_shop_calendar_overrides IS 
    'Override dla ShopCalendar: urlopy, inwentaryzacje, przedłużone godziny przed świętami';
COMMENT ON COLUMN divechat_shop_calendar_overrides.is_working_day IS 
    'TRUE jeśli ta data ma być traktowana jako dzień roboczy (nawet jeśli to weekend), FALSE jeśli ma być zamknięte';
```

Override ma pierwszeństwo nad stałą logiką dni roboczych i listą świąt.

### 4. Tool `get_shop_schedule`

Lokalizacja: `standalone/src/Chat/Tools/GetShopSchedule.php`

Schema:

```php
public function name(): string { return 'get_shop_schedule'; }

public function description(): string {
    return 'Sprawdza godziny pracy sklepu dla podanej daty. '
         . 'Zwraca czy sklep będzie otwarty/zamknięty, nazwę święta jeśli dotyczy, '
         . 'i godziny pracy. Używaj gdy klient pyta o konkretną datę lub o "czy będzie otwarte" '
         . 'w przyszłości.';
}

public function parameters(): array {
    return [
        'date' => [
            'type' => 'string',
            'description' => 'Data w formacie YYYY-MM-DD. Jeśli null/pominięte, używa dzisiejszej daty.',
            'required' => false,
        ],
    ];
}
```

Zwraca JSON:

```json
{
  "date": "2026-06-06",
  "working_day": false,
  "is_currently_open": false,
  "holiday_name": null,
  "closed_reason": "weekend (sobota)",
  "opens_at": null,
  "closes_at": null,
  "next_working_day": "2026-06-08"
}
```

### 5. Rejestracja w ToolRegistry

W miejscu gdzie inne narzędzia są rejestrowane (sprawdź wzorzec w `standalone/src/Chat/Tools/ToolRegistry.php` lub równoważnym):

- dodać `GetShopSchedule` do listy narzędzi dostępnych dla modelu
- upewnić się że jest exposed w JSON schemy wysyłanej do LLM (Anthropic/OpenAI)

### 6. Workflow w SystemPrompt (uzupełnienie TASK-CHAT-007a)

W TASK-CHAT-007a sekcja DANE FIRMY zawiera odsyłacz do `get_shop_schedule`. Upewnić się że SystemPrompt po obu taskach zawiera spójną instrukcję:

```
Gdy klient pyta o godziny pracy na konkretną datę lub o to "czy zdążycie do X":
1. Wywołaj get_shop_schedule(date="YYYY-MM-DD")
2. Odpowiedz na podstawie wyniku
3. NIE obiecuj konkretnych terminów doręczenia, tylko informuj o godzinach sklepu
```

## STOP point 1 (po wykonaniu komponentów 1-3)

Wyprodukować:
- klasę ShopCalendar z testami jednostkowymi (PHPUnit, min. 8 przypadków: zwykły dzień roboczy, weekend, święto stałe, święto ruchome, override pracujący w weekend, override zamknięty w piątek, godziny przed otwarciem, godziny po zamknięciu)
- migrację SQL
- przykładowy SELECT/INSERT do tabeli override

Zatrzymać się, skierować do Karola. NIE rejestrować jeszcze toola.

## STOP point 2 (po wykonaniu komponentów 4-5)

Wyprodukować:
- klasę toola GetShopSchedule
- rejestrację w ToolRegistry
- test integracyjny: wywołanie toola dla daty "2026-06-06" zwraca `working_day=false, closed_reason="weekend (sobota)"`
- test: wywołanie dla daty "2026-04-06" (Poniedziałek Wielkanocny 2026) zwraca `working_day=false, holiday_name="Poniedziałek Wielkanocny"`

Zatrzymać się, skierować do Karola. Po review Karola Karol uruchomi czat ręcznie z pytaniem "Czy będziecie pracowali 6 czerwca?" i zweryfikuje że bot wywołuje get_shop_schedule i odpowiada poprawnie.

## Acceptance criteria

1. Test: bot pytany "czy zdążycie wysłać do soboty?" wywołuje `get_shop_schedule` i informuje że sobota to dzień wolny.
2. Test: bot pytany "czy będzie otwarte na Boże Ciało?" wywołuje tool i podaje że to święto.
3. Override w bazie powoduje że ShopCalendar zwraca prawidłowy wynik (test integracyjny).
4. Wielkanoc na 2026, 2027, 2028, 2029, 2030 jest poprawnie obliczona.

## Out of scope

- UI panelu admina do edycji override → osobny task w fazie panel-admina (po TASK-055 admin dashboard)
- Powiadamianie klienta o godzinach sklepu fizycznego (Toruń) — to ten sam plan godzin, ale gdyby sklep fizyczny miał inne godziny niż obsługa online, trzeba by oddzielić. Na razie zakładamy: jeden plan dla wszystkich kanałów.
- Inne kraje, strefy czasowe — wszystko Europe/Warsaw.
