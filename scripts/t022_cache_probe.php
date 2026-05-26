<?php

declare(strict_types=1);

/**
 * T-022 KROK 1: empiryczna weryfikacja OpenAI prompt caching.
 *
 * Cel: zanim zaczniemy fixować OpenAIProvider, potwierdzić że OpenAI faktycznie
 * zwraca `usage.prompt_tokens_details.cached_tokens > 0` przy stabilnym prefiksie.
 *
 * Mechanizm:
 * - System prompt >= 1024 tokenów (warunek aktywacji cache OpenAI).
 * - 2 wywołania pod rząd, identyczny system prompt, różny user message.
 *   (User różny żeby wymusić nowy generation — sam cache prefiksu testujemy.)
 * - Print pełnego `data.usage` z obu odpowiedzi (surowy JSON).
 *
 * Uruchomienie na prod (gdzie .env ma realny OPENAI_API_KEY):
 *   php scripts/t022_cache_probe.php [--model=gpt-5-mini]
 *
 * Wyjście: surowy JSON usage1 i usage2 + diff + ocena.
 */

// Autoload: lokalnie vendor żyje w standalone/, na prod w root projektu (t020 smoke pattern).
$projectRoot = dirname(__DIR__, 1);
$autoload = null;
foreach ([$projectRoot . '/vendor/autoload.php', $projectRoot . '/standalone/vendor/autoload.php'] as $candidate) {
    if (file_exists($candidate)) {
        $autoload = $candidate;
        break;
    }
}
if ($autoload === null) {
    fwrite(STDERR, "Nie znalazłem vendor/autoload.php ani w {$projectRoot}/vendor/ ani w {$projectRoot}/standalone/vendor/.\n");
    exit(1);
}
require_once $autoload;

use GuzzleHttp\Client;

// Ręczne parsowanie .env — bypassujemy DiveChat\Config, bo phpdotenv odrzuca
// niektóre nazwy zmiennych (np. z hyphenem). Probe wymaga tylko OPENAI_API_KEY
// i opcjonalnie OPENAI_CHAT_MODEL — nie warto blokować się o tym.
function loadEnvFile(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }
    $vars = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/^([A-Z_][A-Z0-9_]*)\s*=\s*(.*)$/i', $line, $m)) {
            continue; // skip nieparsowalne nazwy
        }
        $val = $m[2];
        if ((str_starts_with($val, '"') && str_ends_with($val, '"'))
            || (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
            $val = substr($val, 1, -1);
        }
        $vars[$m[1]] = $val;
    }
    return $vars;
}
$env = loadEnvFile($projectRoot . '/.env');

$model = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--model=')) {
        $model = substr($arg, 8);
    }
}
$model = $model ?? ($env['OPENAI_CHAT_MODEL'] ?? 'gpt-4.1');
$apiKey = $env['OPENAI_API_KEY'] ?? null;
if (!$apiKey) {
    fwrite(STDERR, "Brak OPENAI_API_KEY w {$projectRoot}/.env\n");
    exit(1);
}

// Stabilny system prompt >= 1024 tokenów. ~4 znaki/token → potrzeba ~4100 znaków.
// Treść: opisowa, deterministyczna, BEZ wstawek dynamicznych (data/czas/UID).
$systemPrompt = <<<'PROMPT'
Jesteś asystentem testowym dla diagnostyki OpenAI prompt caching w projekcie DiveChat.
Twoje zadanie w tym konkretnym uruchomieniu to wyłącznie odpowiedzieć krótkim zdaniem na
pytanie użytkownika. Nie wyjaśniaj kontekstu, nie pytaj zwrotnie, nie korzystaj z narzędzi.

Poniżej znajduje się stabilna, deterministyczna tabela referencyjna sklepu nurkowego
DiveZone, dołączana do tego promptu wyłącznie po to, aby prefiks systemowy przekroczył
próg 1024 tokenów wymagany przez OpenAI dla aktywacji prompt caching. Treść tej tabeli
ma być identyczna pomiędzy wywołaniami probe — żadna zmienna runtime nie powinna trafić
do tego bloku, ponieważ unieważniłaby to klucz cache i sprawiło, że drugi request
zachowa się jak pierwszy (brak cache hit).

Tabela referencyjna marek aktywnych w sklepie DiveZone (snapshot 2026-02-20, 79 marek):
Aqua Lung, Apeks, Atomic Aquatics, Aqualung Sport, Bare, Beuchat, Cressi, Dive Rite,
DTD, ESS, Fourth Element, Garmin, Halcyon, Hollis, Mares, Mares Pure Instinct, Maverick,
Northern Diver, Oceanic, OMS, OMER, Ocean Reef, Poseidon, Pelagic, Reefnet, Riffe, Rofos,
SANTI, Salvimar, Scubapro, Sea Wolf, Sherwood, SHERWOOD SCUBA, Suunto, Si Tech, Subgear,
Spearfishing World, Submarine Manufacturing, Tecline, Tilos, TUSA, UTD, Waterproof,
XS Scuba, Zeagle, ZipSeal, Northern Diver, Light Monkey, Salvimar, Picasso, Aquatec,
Faber, Sopras, Underwater Kinetics, Princeton Tec, Cetacea, SeaLife, Nautilus Lifeline,
PCP, Ammonite, Beaver, Storm, Performance Diver, AP Diving, Kirby Morgan, Hammerhead,
Sea Hornet, Salvimar, Pathos, Cyklon, JBL, Aqualung Aquaracer, Mares Magellan,
Scubapro G2, Suunto D5, Suunto EON Steel, Garmin Descent Mk2, Shearwater Teric.

Tabela referencyjna kategorii produktów (snapshot 2026-02-20, około 60 kategorii):
Maski klasyczne, Maski panoramiczne, Maski jednoszybowe, Maski dwuszybowe, Maski freediverskie,
Fajki podstawowe, Fajki z zaworem, Fajki sportowe, Płetwy klasyczne, Płetwy z otwartą piętą,
Płetwy z zamkniętą piętą, Płetwy długie, Skarpety neoprenowe, Buty neoprenowe, Pianki krótkie,
Pianki długie, Pianki półsuche, Pianki suche, Kombinezony grube, Kombinezony cienkie,
Kaptury neoprenowe, Rękawice neoprenowe, BCD jacket, BCD wing, BCD podróżne, BCD specjalistyczne,
Automaty oddychania pierwszy stopień, Automaty oddychania drugi stopień, Automaty oddychania zestawy,
Octopusy, Komputery nurkowe nadgarstkowe, Komputery nurkowe konsolowe, Komputery do trimixu,
Komputery freediverskie, Smartwatche nurkowe, Latarki podstawowe, Latarki techniczne,
Latarki video, Akcesoria latarek, Boje SMB, Boje DSMB, Bojki pneumatyczne, Linki nawigacyjne,
Kompasy, Tablice nurkowe, Worki SAC, Torby suche, Torby na sprzęt, Walizki, Plecaki techniczne,
Butle stalowe, Butle aluminiowe, Butle podwójne, Zawory butlowe, Manometry, Kostki ołowiu,
Pasy balastowe, Kieszenie balastowe, Akcesoria ołowiowe, Manometry konsolowe, Suunto MK7,
Garmin Descent, Apeks DSX, Shearwater Petrel, Shearwater Perdix, Shearwater Teric, Atomic T3.

Tabela referencyjna gazów oddechowych w nurkowaniu rekreacyjnym i technicznym:
Powietrze 21% O2 i 79% N2, Nitrox EAN32 32% O2 i 68% N2, Nitrox EAN36 36% O2 i 64% N2,
Nitrox EAN40 40% O2 i 60% N2, Nitrox EAN50 50% O2 i 50% N2, Tlen 100%, Hel,
Trimix 10/70 czyli 10% O2 30% N2 60% He, Trimix 18/45 czyli 18% O2 37% N2 45% He,
Heliox 12/88 czyli 12% O2 88% He, Hipoksic Trimix dla głębokości technicznej.

Tabela referencyjna certyfikatów nurkowych standardów PADI SSI CMAS SDI TDI IANTD GUE NAUI:
Open Water Diver OWD, Advanced Open Water Diver AOWD, Rescue Diver RD, Divemaster DM,
Open Water Scuba Instructor OWSI, Instructor Development Course IDC, Master Scuba Diver,
Enriched Air Diver EAN, Deep Diver, Wreck Diver, Cavern Diver, Cave Diver Full,
Technical Diver Trimix, Sidemount Diver, Rebreather Diver CCR, Public Safety Diver,
Underwater Photographer, Search and Recovery, Equipment Specialist, Drift Diver,
Boat Diver, Night Diver, Peak Performance Buoyancy, Multilevel Diver, Project AWARE.

Koniec stabilnego prefiksu. Poniżej follow pytanie użytkownika do którego masz odpowiedzieć
jednym zdaniem.
PROMPT;

$systemTokensApprox = (int) ceil(strlen($systemPrompt) / 4);

echo "T-022 KROK 1 — OpenAI prompt cache probe\n";
echo "========================================\n";
echo "Model: {$model}\n";
echo "System prompt: " . strlen($systemPrompt) . " znaków (~{$systemTokensApprox} tokenów approx)\n";
echo "Próg cache OpenAI: 1024 tokenów. Status: " . ($systemTokensApprox >= 1024 ? 'OK' : 'POD PROGIEM — cache się NIE aktywuje!') . "\n";
echo "----\n\n";

$http = new Client([
    'base_uri' => 'https://api.openai.com/',
    'timeout' => 60,
]);

function callOpenAI(Client $http, string $apiKey, string $model, string $systemPrompt, string $userMessage): array
{
    $body = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage],
        ],
        'max_completion_tokens' => 50,
    ];

    $start = microtime(true);
    $response = $http->request('POST', 'v1/chat/completions', [
        'headers' => [
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type' => 'application/json',
        ],
        'json' => $body,
    ]);
    $elapsedMs = (int) ((microtime(true) - $start) * 1000);

    $data = json_decode($response->getBody()->getContents(), true);
    return [
        'usage' => $data['usage'] ?? [],
        'model_actual' => $data['model'] ?? null,
        'system_fingerprint' => $data['system_fingerprint'] ?? null,
        'content' => $data['choices'][0]['message']['content'] ?? null,
        'latency_ms' => $elapsedMs,
    ];
}

try {
    echo "[1/2] Pierwsze wywołanie (cache populate)...\n";
    $r1 = callOpenAI($http, $apiKey, $model, $systemPrompt, 'Powiedz "test pierwszy".');
    echo "  latency: {$r1['latency_ms']} ms\n";
    echo "  model_actual: {$r1['model_actual']}\n";
    echo "  system_fingerprint: " . ($r1['system_fingerprint'] ?? 'null') . "\n";
    echo "  content: " . trim($r1['content'] ?? '') . "\n";
    echo "  USAGE (surowy JSON):\n";
    echo '  ' . json_encode($r1['usage'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

    // Krótka pauza — OpenAI cache TTL ~5-10 min, więc 2s to bezpieczny minimum
    // żeby cache na pewno zarejestrował prefix po pierwszym requeście.
    sleep(2);

    echo "[2/2] Drugie wywołanie (cache hit?)...\n";
    $r2 = callOpenAI($http, $apiKey, $model, $systemPrompt, 'Powiedz "test drugi".');
    echo "  latency: {$r2['latency_ms']} ms\n";
    echo "  model_actual: {$r2['model_actual']}\n";
    echo "  system_fingerprint: " . ($r2['system_fingerprint'] ?? 'null') . "\n";
    echo "  content: " . trim($r2['content'] ?? '') . "\n";
    echo "  USAGE (surowy JSON):\n";
    echo '  ' . json_encode($r2['usage'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

    // Ocena
    $cached1 = (int) ($r1['usage']['prompt_tokens_details']['cached_tokens'] ?? 0);
    $cached2 = (int) ($r2['usage']['prompt_tokens_details']['cached_tokens'] ?? 0);
    $prompt2 = (int) ($r2['usage']['prompt_tokens'] ?? 0);

    echo "OCENA:\n";
    echo "  cached_tokens call 1: {$cached1}\n";
    echo "  cached_tokens call 2: {$cached2}\n";
    echo "  prompt_tokens call 2: {$prompt2}\n";

    if ($cached2 > 0) {
        $cacheRatio = $prompt2 > 0 ? round($cached2 / $prompt2 * 100, 1) : 0.0;
        echo "  WERDYKT: OpenAI ZWRACA cached_tokens > 0 ({$cacheRatio}% prompt cached).\n";
        echo "  → Fix oczywisty: OpenAIProvider.parseResponse() ma czytać prompt_tokens_details.cached_tokens.\n";
    } else {
        echo "  WERDYKT: cached_tokens = 0 w obu wywołaniach.\n";
        echo "  → Możliwe przyczyny:\n";
        echo "    (a) prefix systemowy < 1024 tok (ten probe ma ~{$systemTokensApprox}, więc raczej nie);\n";
        echo "    (b) model {$model} NIE wspiera prompt caching (sprawdź OpenAI docs);\n";
        echo "    (c) coś dynamicznego w prefiksie (timestamp, UID) — sprawdź ChatService kolejność promptu.\n";
        echo "  → Diagnoza ChatService PRZED fixem (zgodnie z task spec STOP 1 instrukcją).\n";
    }
} catch (\Throwable $e) {
    echo "BŁĄD: " . $e->getMessage() . "\n";
    if (method_exists($e, 'getResponse') && $e->getResponse()) {
        echo "Response body: " . (string) $e->getResponse()->getBody() . "\n";
    }
    exit(2);
}
