<?php

declare(strict_types=1);

/**
 * T-022b KROK 1: macierz max_tokens → cache_tokens dla gpt-5-mini z reasoning.
 *
 * Cel: zrozumieć MECHANIZM dlaczego max=50 cache=95.6%, max=4096 cache=0. Macierz
 * NIE zgaduje — testuje deterministycznie czy:
 *   (a) max_tokens monotonicznie blokuje cache,
 *   (b) max_tokens > pewien próg = wyłączenie cache (progowe),
 *   (c) call z dużym max_tokens "psuje" cache na kolejne calle (cache invalidate),
 *   (d) coś o reasoning_tokens budgetcie zjada limit, max=50 to akurat reasoning-only.
 *
 * Metoda:
 *   1. Cache populate (max=50, user="warmup"). Daj ~3s żeby OpenAI zapisało prefix.
 *   2. Sekwencja hitów: NAJPIERW max=4096 (potencjalny "blocker"), POTEM max=50
 *      (control — czy cache nadal jest), potem reszta różnych max.
 *   3. Per hit zapisz: max_completion_tokens_sent, prompt_tokens (z usage),
 *      cached_tokens, completion_tokens, reasoning_tokens, finish_reason, latency.
 *
 * Wszystkie hity mają IDENTYCZNY system prompt (>1024 tok), różny user message.
 * Cache key OpenAI = (model + messages prefix). User jest po systemie więc cache
 * dla prefiksu systemowego powinien hitować niezależnie od usera.
 *
 * Uruchomienie (prod, jeden raz):
 *   php scripts/t022b_max_tokens_matrix.php [--model=gpt-5-mini]
 */

// Autoload + ręczny .env parser (jak w t022_cache_probe.php — phpdotenv odrzuca
// nazwy z hyphenem w live .env).
$projectRoot = dirname(__DIR__, 1);
$autoload = null;
foreach ([$projectRoot . '/vendor/autoload.php', $projectRoot . '/standalone/vendor/autoload.php'] as $candidate) {
    if (file_exists($candidate)) {
        $autoload = $candidate;
        break;
    }
}
if ($autoload === null) {
    fwrite(STDERR, "Nie znalazłem vendor/autoload.php.\n");
    exit(1);
}
require_once $autoload;

use GuzzleHttp\Client;

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
            continue;
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
$model = $model ?? ($env['OPENAI_CHAT_MODEL'] ?? 'gpt-5-mini');
$apiKey = $env['OPENAI_API_KEY'] ?? null;
if (!$apiKey) {
    fwrite(STDERR, "Brak OPENAI_API_KEY w {$projectRoot}/.env\n");
    exit(1);
}

// Ten sam system prompt co t022_cache_probe.php — wiemy że cache dla niego hituje
// (potwierdzone na prod: 1280/1351 = 94.7%).
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

$http = new Client([
    'base_uri' => 'https://api.openai.com/',
    'timeout' => 60,
]);

function callOpenAI(Client $http, string $apiKey, string $model, string $systemPrompt, string $userMessage, int $maxTokens): array
{
    $body = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage],
        ],
        'max_completion_tokens' => $maxTokens,
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
    $choice = $data['choices'][0] ?? [];

    return [
        'max_sent' => $maxTokens,
        'prompt_tokens' => (int) ($data['usage']['prompt_tokens'] ?? 0),
        'cached_tokens' => (int) ($data['usage']['prompt_tokens_details']['cached_tokens'] ?? 0),
        'completion_tokens' => (int) ($data['usage']['completion_tokens'] ?? 0),
        'reasoning_tokens' => (int) ($data['usage']['completion_tokens_details']['reasoning_tokens'] ?? 0),
        'finish_reason' => $choice['finish_reason'] ?? '?',
        'content_len' => strlen(trim((string) ($choice['message']['content'] ?? ''))),
        'latency_ms' => $elapsedMs,
        'model_actual' => $data['model'] ?? null,
    ];
}

echo "T-022b KROK 1 — macierz max_tokens → cache hit (gpt-5-mini reasoning)\n";
echo "======================================================================\n";
echo "Model: {$model}\n";
echo "System prompt: " . strlen($systemPrompt) . " znaków\n";
echo "Metoda: 1 populate (max=50) + sleep 3s + 6 hitów w róznej kolejności max_tokens.\n";
echo "Kolejność hitów: 4096 (potencjalny blocker) → 50 (control) → 1024 → 2048 → 256 → 50 (final control).\n\n";

try {
    // Cache populate
    echo "[populate] max=50 ... ";
    $populate = callOpenAI($http, $apiKey, $model, $systemPrompt, 'warmup', 50);
    echo "prompt={$populate['prompt_tokens']} cached={$populate['cached_tokens']} latency={$populate['latency_ms']}ms\n";
    echo "model_actual: {$populate['model_actual']}\n\n";

    sleep(3);

    // Macierz hitów (kolejność celowa: max=4096 najpierw, max=50 control po nim)
    $hits = [];
    $sequence = [
        ['max' => 4096, 'user' => 'hit-A-big'],
        ['max' => 50,   'user' => 'hit-B-small-after-big (control)'],
        ['max' => 1024, 'user' => 'hit-C-1024'],
        ['max' => 2048, 'user' => 'hit-D-2048'],
        ['max' => 256,  'user' => 'hit-E-256'],
        ['max' => 50,   'user' => 'hit-F-small-final (control)'],
    ];

    foreach ($sequence as $i => $step) {
        echo '[' . ($i + 1) . '/' . count($sequence) . "] max={$step['max']} user='{$step['user']}' ... ";
        $r = callOpenAI($http, $apiKey, $model, $systemPrompt, $step['user'], $step['max']);
        $r['label'] = $step['user'];
        $hits[] = $r;
        echo "cached={$r['cached_tokens']} compl={$r['completion_tokens']} (reasoning={$r['reasoning_tokens']}, content_len={$r['content_len']}) finish={$r['finish_reason']} lat={$r['latency_ms']}ms\n";
        sleep(1);
    }

    echo "\n";
    echo "MACIERZ (uporządkowana wg kolejności w sekwencji):\n";
    printf("%-3s %-37s %-5s %-7s %-7s %-7s %-9s %-8s %-9s\n",
        '#', 'label', 'max', 'prompt', 'cached', 'compl', 'reasoning', 'finish', 'lat_ms');
    echo str_repeat('-', 110) . "\n";
    foreach ($hits as $i => $r) {
        printf("%-3d %-37s %-5d %-7d %-7d %-7d %-9d %-8s %-9d\n",
            $i + 1, substr($r['label'], 0, 37),
            $r['max_sent'], $r['prompt_tokens'], $r['cached_tokens'],
            $r['completion_tokens'], $r['reasoning_tokens'], $r['finish_reason'], $r['latency_ms']);
    }

    echo "\nSygnały do interpretacji:\n";
    echo "  - prompt_tokens stały? Powinien być (system stały + user krótki). Jeśli skoki → coś z payloadem.\n";
    echo "  - cached_tokens vs max_sent: czy monotonicznie spada? Czy progowe? Czy hit 2 (small after big) wraca?\n";
    echo "  - finish_reason='length' → ucięcie. Przy gpt-5-mini reasoning może to się dziać przy małym max.\n";
    echo "  - reasoning_tokens vs completion_tokens: ile budgetu zjada reasoning, ile zostaje na content.\n";
    echo "  - content_len=0 i reasoning=max → model się zatrzymał na reasoning, brak tekstu (max za mały).\n";
} catch (\Throwable $e) {
    echo "BŁĄD: " . $e->getMessage() . "\n";
    if (method_exists($e, 'getResponse') && $e->getResponse()) {
        echo "Body: " . (string) $e->getResponse()->getBody() . "\n";
    }
    exit(2);
}
