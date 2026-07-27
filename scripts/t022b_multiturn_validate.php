<?php

declare(strict_types=1);

/**
 * T-022b KROK 3-4: walidacja AI_MAX_TOKENS=2500 w realnej multi-turn rozmowie.
 *
 * Cel: potwierdzić że przy zwalidowanej wartości produkcyjnej:
 *   - żadna tura nie ma finish_reason='length' (odpowiedzi kompletne, nie ucinane),
 *   - reasoning_tokens + content się mieszczą,
 *   - cache hit (z H5 stochastyczny) — pokazujemy ile razy hit, akceptowalne.
 *
 * Symulujemy 4-turową rozmowę o nurkowaniu (typowe pytania klienta). Każda kolejna
 * tura zawiera historię (system + wszystkie wcześniejsze user/assistant) — rzeczywiste
 * narastanie kontekstu jak w chatcie. NIE używa narzędzi (bez tool loop) — sprawdzamy
 * tylko warstwę OpenAI completion + cache.
 *
 * Uruchomienie:
 *   php scripts/t022b_multiturn_validate.php [--model=gpt-5-mini] [--max=2500]
 */

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
$maxTokens = 2500;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--model=')) {
        $model = substr($arg, 8);
    }
    if (str_starts_with($arg, '--max=')) {
        $maxTokens = (int) substr($arg, 6);
    }
}
$model = $model ?? ($env['OPENAI_CHAT_MODEL'] ?? 'gpt-5-mini');
$apiKey = $env['OPENAI_API_KEY'] ?? null;
if (!$apiKey) {
    fwrite(STDERR, "Brak OPENAI_API_KEY w {$projectRoot}/.env\n");
    exit(1);
}

// System prompt skrócony — realistyczny dla diving advisor, >1024 tok ale nie
// nadmiarowy jak macierz. Cache OpenAI: stabilny prefix, kandydat na hit.
$systemPrompt = <<<'PROMPT'
Jesteś ekspertem-asystentem sklepu nurkowego DiveZone (chat.divezone.pl). Pomagasz klientom
dobrać sprzęt do nurkowania rekreacyjnego i technicznego. Odpowiadasz po polsku, krótko i
konkretnie. Każda odpowiedź zawiera 1-3 konkretne sugestie produktów z naszego katalogu,
z ceną orientacyjną w PLN.

Twoje zasady:
1. Jeśli klient pyta o sprzęt techniczny (głębokość >40m, trimix, rebreather) — zapytaj o
   poziom certyfikacji nurkowej. Nie polecaj zaawansowanego sprzętu bez potwierdzenia
   kwalifikacji.
2. Nie udzielaj porad medycznych. Jeśli klient pyta o aspekty zdrowotne (DCS, otitis,
   ciśnienie krwi), odeślij do lekarza nurkowego.
3. Nigdy nie ujawniaj wewnętrznych kodów PrestaShop (id_product, available_to_order,
   in_stock jako technicznych terminów). Komunikuj statusy po polsku: "dostępny",
   "dostępny na zamówienie", "chwilowo niedostępny".
4. Nie cytuj publikacji naukowych (DOI, "et al.", bibliografia) — sklep nurkowy nie jest
   źródłem akademickim. Jeśli klient prosi o referencje, odeślij do federacji (PADI, SSI,
   CMAS) lub Encyklopedii DiveZone.
5. Format ceny: zawsze brutto PLN, bold pierwsze wystąpienie. Przykład: "**1299 zł**".
6. Format linków: tylko gdy mamy URL z bazy (nie konfabuluj URL).

Marki aktywne w sklepie (snapshot 2026-02-20): Aqua Lung, Apeks, Atomic Aquatics, Bare,
Beuchat, Cressi, Dive Rite, DTD, Fourth Element, Garmin, Halcyon, Hollis, Mares, Oceanic,
OMS, OMER, Ocean Reef, Poseidon, SANTI, Salvimar, Scubapro, Sherwood, Suunto, Tecline,
TUSA, UTD, Waterproof, XS Scuba, Zeagle, Shearwater (Petrel/Perdix/Teric), Atomic T3,
Apeks DSX, Suunto MK7. (lista skrócona, pełna w bazie 79 pozycji)

Kategorie kluczowe: Maski klasyczne/panoramiczne/freediverskie, Fajki, Płetwy z otwartą
piętą / zamkniętą piętą / długie freediverskie, Pianki krótkie/długie/półsuche/suche,
Kombinezony grube/cienkie, BCD jacket/wing/podróżne, Automaty pierwszy stopień/drugi
stopień/octopusy/zestawy, Komputery nadgarstkowe/konsolowe/trimix, Latarki podstawowe/
techniczne/video, Boje SMB/DSMB, Butle stalowe/aluminiowe/podwójne, Manometry, Balast
(kostki, pasy, kieszenie), Akcesoria techniczne.

Gazy oddechowe które obsługujemy w doborze sprzętu: Powietrze (21/79), Nitrox EAN32/36/40/50,
Tlen 100%, Trimix 10/70 i 18/45 (dla certyfikowanych technicznych nurków), Heliox dla
głębokości technicznej. Sprzęt do mieszanek wzbogaconych >40% O2 wymaga czyszczenia
tlenowego — informuj klienta.

Certyfikaty rozpoznawane: PADI/SSI/CMAS/SDI/TDI/IANTD/GUE/NAUI. Open Water Diver,
Advanced Open Water Diver, Rescue Diver, Divemaster, Open Water Scuba Instructor.
Specjalistyczne: Enriched Air, Deep, Wreck, Cavern, Cave (Full), Technical Trimix,
Sidemount, Rebreather CCR. Bez certyfikatu nie pomagaj klientowi z głębokością >18m
ani z mieszankami innych niż powietrze.
PROMPT;

$http = new Client([
    'base_uri' => 'https://api.openai.com/',
    'timeout' => 60,
]);

function callOpenAI(Client $http, string $apiKey, string $model, array $messages, int $maxTokens): array
{
    $body = [
        'model' => $model,
        'messages' => $messages,
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
    $content = (string) ($choice['message']['content'] ?? '');

    return [
        'prompt_tokens' => (int) ($data['usage']['prompt_tokens'] ?? 0),
        'cached_tokens' => (int) ($data['usage']['prompt_tokens_details']['cached_tokens'] ?? 0),
        'completion_tokens' => (int) ($data['usage']['completion_tokens'] ?? 0),
        'reasoning_tokens' => (int) ($data['usage']['completion_tokens_details']['reasoning_tokens'] ?? 0),
        'finish_reason' => $choice['finish_reason'] ?? '?',
        'content' => $content,
        'content_len' => strlen(trim($content)),
        'latency_ms' => $elapsedMs,
        'model_actual' => $data['model'] ?? null,
    ];
}

$turns = [
    'Polecisz mi 3 latarki nurkowe do 1500 zł na nurkowanie rekreacyjne do 30m?',
    'A który z tych modeli najlepiej sprawdzi się też do video?',
    'OK, biorę tę video-friendly. Polecisz teraz dobrą piankę półsuchą 7mm dla mojego rozmiaru ML?',
    'Mam Open Water + Advanced, planuję kurs Deep. Jaki komputer nurkowy do 1200 zł?',
];

echo "T-022b KROK 3 — walidacja multi-turn AI_MAX_TOKENS={$maxTokens}\n";
echo "================================================================\n";
echo "Model: {$model}\n";
echo "Tur: " . count($turns) . "\n";
echo "Cel: brak finish_reason='length', kompletne odpowiedzi, akceptowalny (zmienny) cache.\n\n";

$messages = [['role' => 'system', 'content' => $systemPrompt]];
$results = [];
$problems = [];

try {
    foreach ($turns as $i => $userText) {
        $messages[] = ['role' => 'user', 'content' => $userText];

        echo "[Tura " . ($i + 1) . "/" . count($turns) . "] user: " . mb_substr($userText, 0, 80) . "...\n";
        $r = callOpenAI($http, $apiKey, $model, $messages, $maxTokens);
        $r['turn'] = $i + 1;
        $r['user_msg'] = $userText;
        $results[] = $r;

        // Dorzucamy assistant do historii dla następnej tury
        $messages[] = ['role' => 'assistant', 'content' => $r['content']];

        echo "  prompt={$r['prompt_tokens']} cached={$r['cached_tokens']} compl={$r['completion_tokens']} "
           . "(reasoning={$r['reasoning_tokens']}, content_chars={$r['content_len']}) "
           . "finish={$r['finish_reason']} lat={$r['latency_ms']}ms\n";
        echo "  content preview: " . mb_substr(trim($r['content']), 0, 120) . (mb_strlen($r['content']) > 120 ? '...' : '') . "\n\n";

        if ($r['finish_reason'] === 'length') {
            $problems[] = "Tura {$r['turn']}: finish_reason=length (ucięto) — max={$maxTokens} za mały";
        }
        if ($r['content_len'] === 0) {
            $problems[] = "Tura {$r['turn']}: content_len=0 (cały budżet zjadł reasoning)";
        }

        sleep(1);
    }

    // Podsumowanie
    echo "PODSUMOWANIE:\n";
    printf("%-5s %-7s %-7s %-7s %-9s %-8s %-7s %-7s\n",
        'tura', 'prompt', 'cached', 'compl', 'reasoning', 'finish', 'chars', 'lat_ms');
    echo str_repeat('-', 80) . "\n";
    $totalPrompt = 0;
    $totalCached = 0;
    $totalCompl = 0;
    $hits = 0;
    foreach ($results as $r) {
        printf("%-5d %-7d %-7d %-7d %-9d %-8s %-7d %-7d\n",
            $r['turn'], $r['prompt_tokens'], $r['cached_tokens'],
            $r['completion_tokens'], $r['reasoning_tokens'],
            $r['finish_reason'], $r['content_len'], $r['latency_ms']);
        $totalPrompt += $r['prompt_tokens'];
        $totalCached += $r['cached_tokens'];
        $totalCompl += $r['completion_tokens'];
        if ($r['cached_tokens'] > 0) {
            $hits++;
        }
    }
    echo str_repeat('-', 80) . "\n";
    $hitRate = count($results) > 0 ? round($hits / count($results) * 100, 1) : 0.0;
    $cacheRatio = $totalPrompt > 0 ? round($totalCached / $totalPrompt * 100, 1) : 0.0;
    printf("SUMA  %-7d %-7d %-7d\n", $totalPrompt, $totalCached, $totalCompl);
    echo "\nCache hit (turny z cached>0): {$hits}/" . count($results) . " ({$hitRate}%)\n";
    echo "Cache ratio (cached/prompt total): {$cacheRatio}%\n";

    if (empty($problems)) {
        echo "\n✓ WALIDACJA OK: żadnej tury z finish=length ani content=0.\n";
        echo "  Cache hit zmienny (oczekiwane — H5 stochastyczny).\n";
        exit(0);
    } else {
        echo "\n✗ PROBLEMY:\n";
        foreach ($problems as $p) {
            echo "  - {$p}\n";
        }
        exit(2);
    }
} catch (\Throwable $e) {
    echo "BŁĄD: " . $e->getMessage() . "\n";
    if (method_exists($e, 'getResponse') && $e->getResponse()) {
        echo "Body: " . (string) $e->getResponse()->getBody() . "\n";
    }
    exit(2);
}
