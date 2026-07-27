# TASK-009: Model registry + dynamiczne settings + effort config
# Data: 2026-02-20
# Instancja: backend + frontend
# Priorytet: ŚREDNI
# Zależności: TASK-006b (standalone API), TASK-008f (frontend panel)

## Cel
Jedno źródło prawdy o modelach AI (AIModel.php), dynamiczne ładowanie w panelu settings, obsługa reasoning effort per-model (nie per-tier).

## Kontekst
- AIModel.php częściowo naprawiony (sesja 5: gpt-5-mini fix, supportsTemperature)
- Effort wysyłany jest globalnie, a powinien być per-model
- supportsEffort() oparte na tierze, a powinno na typie modelu (reasoning vs nie)
- Settings ma jedno pole escalation_effort, potrzebne dwa: primary_effort + escalation_effort
- UI pokazuje effort tylko przy modelu eskalacyjnym, powinno przy każdym reasoning modelu
- AIProviderFactory nadal czyta provider z .env, ignoruje settings DB

## Zmiany

### KLUCZOWA ZMIANA: effort per-model (nie per-tier)

Stara logika: `supportsEffort()` === tier 'escalation'. Effort tylko na modelu eskalacyjnym.
Nowa logika: `isReasoning()` === true dla gpt-5-mini, gpt-5.2, claude-opus-4-6. Effort przy każdym reasoning modelu.

AIModel metody:
- `isReasoning(): bool` - gpt-5-mini, gpt-5.2 = true; gpt-4.1 = false
- `supportsTemperature(): bool` - odwrotność isReasoning (już istnieje)
- `effortParamName(): ?string` - reasoning_effort (OpenAI) | extended_thinking (Claude)
- `tier(): string` - primary | escalation (do UI, ale nie do decyzji o effort)

Settings DB: dwa pola effort:
- `primary_effort` (dla primary model jeśli reasoning)
- `escalation_effort` (dla escalation model jeśli reasoning)

ChatService: `loadSettings()` zwraca oba, `handle()` wysyła odpowiedni effort w zależności od aktualnie użytego modelu.

UI: kontrolka effort pojawia się pod dropdownem modelu jeśli wybrany model ma `isReasoning() === true`.

### 1. AIModel.php — pełna refaktoryzacja

Zastąp obecny enum nowym z metadanymi:

```php
enum AIModel: string
{
    // OpenAI primary
    case GPT_52_MINI = 'gpt-5.2-mini';
    case GPT_41 = 'gpt-4.1';

    // OpenAI escalation
    case GPT_52 = 'gpt-5.2';

    // Claude primary
    case CLAUDE_SONNET_46 = 'claude-sonnet-4-6';
    case CLAUDE_HAIKU_45 = 'claude-haiku-4-5';

    // Claude escalation
    case CLAUDE_OPUS_46 = 'claude-opus-4-6';

    public function provider(): string
    {
        return match ($this) {
            self::CLAUDE_SONNET_46, self::CLAUDE_HAIKU_45, self::CLAUDE_OPUS_46 => 'claude',
            default => 'openai',
        };
    }

    public function tier(): string
    {
        return match ($this) {
            self::GPT_52, self::CLAUDE_OPUS_46 => 'escalation',
            default => 'primary',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::GPT_52_MINI => 'GPT-5.2 Mini',
            self::GPT_41 => 'GPT-4.1',
            self::GPT_52 => 'GPT-5.2',
            self::CLAUDE_SONNET_46 => 'Claude Sonnet 4.6',
            self::CLAUDE_HAIKU_45 => 'Claude Haiku 4.5',
            self::CLAUDE_OPUS_46 => 'Claude Opus 4.6',
        };
    }

    public function supportsEffort(): bool
    {
        return $this->tier() === 'escalation';
    }

    public function effortParamName(): ?string
    {
        if (!$this->supportsEffort()) {
            return null;
        }
        return match ($this->provider()) {
            'openai' => 'reasoning_effort',
            'claude' => 'extended_thinking',
            default => null,
        };
    }

    /**
     * Zwraca wszystkie modele pogrupowane per provider + tier.
     * Do użycia w /api/settings.
     */
    public static function grouped(): array
    {
        $result = [];
        foreach (self::cases() as $model) {
            $result[$model->provider()][$model->tier()][] = [
                'value' => $model->value,
                'label' => $model->label(),
                'supports_effort' => $model->supportsEffort(),
                'effort_param' => $model->effortParamName(),
            ];
        }
        return $result;
    }
}
```

### 2. SettingsController.php — serwuj modele

GET /api/settings powinien zwracać dodatkowe pole `available_models`:

```json
{
    "settings": { ... },
    "available_models": {
        "openai": {
            "primary": [
                {"value": "gpt-5.2-mini", "label": "GPT-5.2 Mini", "supports_effort": false},
                {"value": "gpt-4.1", "label": "GPT-4.1", "supports_effort": false}
            ],
            "escalation": [
                {"value": "gpt-5.2", "label": "GPT-5.2", "supports_effort": true, "effort_param": "reasoning_effort"}
            ]
        },
        "claude": {
            "primary": [...],
            "escalation": [...]
        }
    }
}
```

### 3. SettingsStore.php — effort w ustawieniach

Dodaj do zapisywanych/odczytywanych settings:
- `escalation_effort` (string): wartość effort dla modelu eskalacyjnego
  - OpenAI: "low" | "medium" | "high"
  - Claude: budget_tokens jako int (np. 5000, 10000, 15000)

Domyślnie: "medium" (OpenAI) / 5000 (Claude).

### 4. settings.js — dynamiczne ładowanie

Usuń hardkodowany obiekt MODELS. Zamiast tego:

```javascript
function loadSettings() {
    fetch('/api/settings')
        .then(r => r.json())
        .then(data => {
            availableModels = data.available_models;
            applySettings(data.settings);
        });
}
```

Przy zmianie providera: `updateModelDropdowns()` buduje opcje z `availableModels`.

Dodaj UI dla effort:
- Widoczny TYLKO gdy wybrany model eskalacyjny ma `supports_effort: true`
- OpenAI: dropdown low/medium/high
- Claude: slider 1000-20000 (krok 1000) z labelem "Budget tokens"
- Chowany gdy provider nie ma modelu eskalacyjnego z effort

### 5. AIProviderInterface / ClaudeProvider / OpenAIProvider

Providers muszą uwzględniać effort w request do API:

**OpenAI (gpt-5.2):**
```json
{"model": "gpt-5.2", "reasoning_effort": "medium", ...}
```

**Claude (opus-4-6):**
```json
{"model": "claude-opus-4-6", "thinking": {"type": "enabled", "budget_tokens": 5000}, ...}
```

Effort przekazywany TYLKO gdy:
- Model jest eskalacyjny (tier = escalation)
- supportsEffort() = true
- Wartość pobrana z settings

Primary modele NIGDY nie dostają parametru effort.

## Pliki do modyfikacji
- standalone/src/Enum/AIModel.php (refaktoryzacja)
- standalone/src/Controller/SettingsController.php (dodaj available_models)
- standalone/src/Chat/SettingsStore.php (dodaj escalation_effort)
- standalone/src/AI/OpenAIProvider.php (reasoning_effort)
- standalone/src/AI/ClaudeProvider.php (extended_thinking)
- standalone/src/AI/AIProviderFactory.php (przekazywanie effort)
- standalone/public/js/settings.js (dynamiczne modele + effort UI)

## Definition of Done
- [ ] AIModel.php zawiera 6 modeli z poprawnymi tierami i effort info
- [ ] GET /api/settings zwraca available_models z AIModel::grouped()
- [ ] POST /api/settings zapisuje escalation_effort
- [ ] settings.js ładuje modele z API, zero hardkodu
- [ ] Effort UI widoczny tylko dla eskalacyjnych modeli
- [ ] OpenAIProvider wysyła reasoning_effort gdy model eskalacyjny
- [ ] ClaudeProvider wysyła extended_thinking gdy model eskalacyjny
- [ ] Primary modele nie dostają parametru effort
- [ ] Zmiana providera w panelu poprawnie przeładowuje listy modeli
