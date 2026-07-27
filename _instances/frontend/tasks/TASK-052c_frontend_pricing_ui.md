# TASK-052c: Frontend – filtr providera, dual-control reasoning, widget kosztu

**Status:** TODO
**Instancja:** frontend
**Powiązany ADR:** ADR-051
**Zależności:** TASK-052b wykonane (endpointy z cenami i kosztem działają)

---

## Cel

Naprawić bug filtrowania modeli po providerze, dodać do dropdownu modeli ceny (in/out),
zaimplementować dual-control reasoning (slider temperature + dropdown effort) oraz widget
sumarycznego kosztu rozmowy w nagłówku panelu admina.

---

## Zakres

### 1. Bug fix: filtrowanie modeli po providerze

**Obecne zachowanie:** zmiana providera (Anthropic ↔ OpenAI) nie zmienia listy w dropdownie modeli.

**Oczekiwane:** po zmianie providera dropdown "Model" pokazuje tylko modele tego providera
(z `available_models[provider].primary` + `escalation`). Dropdown "Model eskalacji" analogicznie.

Dane wejściowe: `GET /api/settings` zwraca `available_models` pogrupowane per provider/tier
(już zaimplementowane w TASK-052b).

### 2. Dropdown modeli z cenami

Format opcji w dropdownie:

```
Claude Haiku 4.5 — $1.00 in / $5.00 out
Claude Opus 4.7 — $5.00 in / $25.00 out
GPT-4.1 — $2.00 in / $8.00 out
```

Cena pobierana z `available_models[provider][tier][i].input_price` i `output_price`
(struktura w TASK-052b sekcja 7).

Sortowanie wewnątrz tier: po `input_price` rosnąco. Tier "primary" przed "escalation",
escalation rozdzielone separatorem (`──── eskalacja ────` jako disabled option lub group label).

### 3. Dual-control reasoning

Layout settings (od góry):

```
[ Provider:    Anthropic ▼          ]
[ Model:       Claude Haiku 4.5 ▼   ]
[ Model esk.:  Claude Opus 4.7 ▼    ]
[ Temperature: ──●──── 0.6   ⓘ      ]   ← infobox dla modeli rozumujących
[ Reasoning:   medium ▼             ]   ← widoczne tylko gdy supports_reasoning_effort
[ Emoji:       [ON]                  ]
[ Knowledge gap: ──●── 0.5           ]
```

**Logika widoczności:**
- Slider `temperature` widoczny zawsze. Dla modelu z `supports_temperature = false`:
  - slider wyszarzony (disabled)
  - obok ikona ⓘ z tooltipem: "Wybrany model nie używa parametru temperature. Użyj kontroli Reasoning poniżej."
- Dropdown `reasoning_effort` widoczny tylko gdy `supports_reasoning_effort = true`.
  - Wartości: `minimal`, `low`, `medium`, `high`.
  - Domyślnie: `medium`.

**Reaktywność:** zmiana wybranego modelu (Model lub Model eskalacji – oba mogą się różnić providerem
i flagami) → re-render kontroli temperature/effort wg flag aktualnie wybranego modelu primary.

**Zapis ustawień:** `POST /api/settings` z `{settings: {temperature: 0.6, reasoning_effort: "medium",
model_primary: "claude-haiku-4-5", model_escalation: "claude-opus-4-7", ...}}`.

### 4. Widget sumarycznego kosztu rozmowy

**Lokalizacja:** w nagłówku panelu admina, obok aktywnej rozmowy.

**Format wyświetlania:**

```
Koszt rozmowy: $0.0234 (0.09 zł) · 1234 in / 567 out · 5 wiad.
```

**Źródło danych:**
- Po każdej odpowiedzi czatu, response zawiera pole `conversation_cost` (z TASK-052b sekcja 9).
- Dla wcześniej wczytanych rozmów: `GET /api/conversations/{id}` powinno zwracać `conversation_cost`
  (jeśli nie zwraca, dopisać do zakresu w TASK-052b – patrz pytanie do architekta).

**Aktualizacja na żywo:** po wysłaniu wiadomości i otrzymaniu response, widget odświeża się
z `conversation_cost` z odpowiedzi. Bez polling.

**Formatowanie liczb:**
- USD: 4 miejsca po przecinku do $0.01, 2 miejsca powyżej (np. `$0.0234`, `$1.23`).
- PLN: 2 miejsca po przecinku zawsze (np. `0.09 zł`, `5.18 zł`).
- Tokens: separator tysięcy (np. `1 234`).

### 5. Edycja cen przez admina (osobny widok)

Nowy panel "Cennik modeli" w admin sidebar.

Lista 8 modeli z polami edytowalnymi:
- Input price ($/1M)
- Output price ($/1M)
- Cache read ($/1M, opcjonalne)
- Cache creation ($/1M, opcjonalne)
- Aktywny (toggle)

Przycisk "Zapisz" wywołuje `POST /api/admin/pricing` per model (lub batch – ustal w trakcie).

Walidacja: wartości >= 0, max 6 miejsc po przecinku.

### 6. Stan początkowy ustawień

Jeśli `settings.model_primary` jest pusty (świeża instalacja po TASK-052a/b), pokazać prompt
"Wybierz model" zamiast pierwszego z listy. Nie wybierać domyślnie żadnego – operator decyduje.

---

## Kryteria akceptacji

1. Zmiana providera filtruje listę modeli na obu dropdownach (primary + escalation).
2. Każda opcja w dropdownie pokazuje cenę w formacie `$X.XX in / $Y.YY out`.
3. Wybór `gpt-4.1` → temperature aktywne, dropdown reasoning ukryty.
4. Wybór `claude-haiku-4-5` → temperature wyszarzone z tooltipem, dropdown reasoning widoczny.
5. Zapisanie settings z `reasoning_effort=high` na modelu `gpt-5.4` i wysłanie testowej wiadomości – w `divechat_message_usage` widać użyty model i niezerowy koszt.
6. Widget kosztu w nagłówku odświeża się po każdej odpowiedzi czatu, pokazuje USD i PLN.
7. Panel "Cennik modeli" pozwala zmienić cenę – po zapisie kolejna wiadomość używa nowej ceny.
8. Bug filtrowania providera nie występuje (manualny test: Anthropic→OpenAI→Anthropic).

---

## Czego NIE robić

- NIE zmieniać backendu – używać tylko endpointów z TASK-052b.
- NIE liczyć kosztu po stronie JS – używać `conversation_cost` z response.
- NIE pokazywać kosztu per pojedyncza wiadomość – ADR-051 mówi: tylko sumarycznie (decyzja 14b).
- NIE pokazywać emoji/PLN flagi – PLN z liczbą wystarczy.
- NIE robić polling kursu walut na frontendzie – kurs jest w `GET /api/settings.exchange_rate_usd_pln`.
- NIE używać localStorage do trzymania ustawień – source of truth to PG przez `/api/settings`.

---

## Pytania do architekta

Jeśli pojawi się rozbieżność między tym co potrzeba na froncie a tym co backend zwraca
(np. brakujące pole w response, niejasny format daty, brakujący endpoint), zapisać w
`_instances/frontend/handoff/052c_questions.md` i zatrzymać się.

Szczególnie zweryfikować:
- Czy `GET /api/conversations/{id}` zwraca `conversation_cost` (dla wczytania starej rozmowy).
  Jeśli nie – to jest gap w TASK-052b, zgłosić.
- Czy panel cennika ma mieć confirm dialog przed zapisem (z uwagi na wpływ na koszty).
