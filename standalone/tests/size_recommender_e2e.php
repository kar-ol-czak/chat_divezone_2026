<?php

declare(strict_types=1);

/**
 * CHAT-T-100 — REALNA ścieżka function calling przez Claude (pętla jak w ChatService).
 * Sprawdza, że model FAKTYCZNIE woła recommend_wetsuit_size z poprawnymi argumentami,
 * oraz że reguły SystemPrompt działają (pyta o płeć; suchy skafander → nie woła narzędzia).
 *
 * Uruchom: php tests/size_recommender_e2e.php
 * UWAGA: realne wywołania Claude API (koszt kilku tanich tur). Bez DB writes (nie ChatService).
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use DiveChat\AI\ClaudeProvider;
use DiveChat\AI\EmbeddingService;
use DiveChat\Chat\SystemPrompt;

// Wyłuskaj klucze z .env (lokalny .env nieparsowalny przez phpdotenv — defekt lokalny).
$envPath = dirname(__DIR__, 2) . '/.env';
$raw = is_readable($envPath) ? (string) file_get_contents($envPath) : '';
// CHAT-T-103: DB_* wymagane — SizeRecommender łączy się z MySQL (registry build + execute).
foreach (['DATABASE_URL', 'ANTHROPIC_API_KEY', 'ANTHROPIC_MODEL', 'AI_MAX_TOKENS',
          'DB_HOST', 'DB_PORT', 'DB_NAME_PROD', 'DB_USER', 'DB_PASSWORD'] as $k) {
    if (preg_match('/^' . $k . '=(.*)$/m', $raw, $mm)) {
        $v = trim($mm[1], " \t\"'");
        if ($v !== '') {
            $_ENV[$k] = $v;
        }
    }
}
if (empty($_ENV['ANTHROPIC_API_KEY'])) {
    fwrite(STDERR, "SKIP: brak ANTHROPIC_API_KEY w .env — test live pominięty.\n");
    exit(0);
}
// EmbeddingService wymaga OPENAI_API_KEY przy konstrukcji (nieużywane przez nasze narzędzie).
$_ENV['OPENAI_API_KEY'] = $_ENV['OPENAI_API_KEY'] ?? '';
if ($_ENV['OPENAI_API_KEY'] === '') {
    $_ENV['OPENAI_API_KEY'] = 'sk-test-noop';
}

$registerTools = require dirname(__DIR__) . '/config/tools.php';
$registry = $registerTools(new EmbeddingService());
$defs = $registry->getToolDefinitions();
$provider = new ClaudeProvider();
$system = SystemPrompt::build();

/**
 * Pętla function calling jak w ChatService: woła model, wykonuje narzędzia, dokłada wyniki.
 * Zwraca [finalContent, toolsUsed[], lastToolResultByName].
 */
function runConversation(ClaudeProvider $provider, array $defs, $registry, string $system, string $userMsg): array
{
    $messages = [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $userMsg],
    ];
    $toolsUsed = [];
    $results = [];
    for ($i = 0; $i < 5; $i++) {
        $resp = $provider->chat($messages, $defs, ['max_tokens' => 1500]);
        if (!$resp->hasToolCalls()) {
            return [(string) $resp->content, $toolsUsed, $results];
        }
        $messages[] = ['role' => 'assistant', 'content' => $resp->content, 'tool_calls' => $resp->toolCalls];
        foreach ($resp->toolCalls as $tc) {
            $toolsUsed[] = $tc->name;
            $res = $registry->has($tc->name) ? $registry->get($tc->name)->execute($tc->arguments) : ['error' => 'unknown'];
            $results[$tc->name] = ['args' => $tc->arguments, 'result' => $res];
            $messages[] = [
                'role' => 'tool_result',
                'tool_call_id' => $tc->id,
                'name' => $tc->name,
                'content' => json_encode($res, JSON_UNESCAPED_UNICODE),
            ];
        }
    }
    return ['(przekroczono limit tur)', $toolsUsed, $results];
}

$ok = true;

// SCENARIUSZ 1: pełne dane → model woła recommend_wetsuit_size (M, chest 104) → L.
[$c1, $used1, $res1] = runConversation($provider, $defs, $registry, $system,
    'Chcę dobrać rozmiar pianki mokrej Scubapro. Jestem mężczyzną, obwód klatki piersiowej 104 cm, '
    . 'wzrost 182 cm, waga 88 kg. Jaki rozmiar skafandra?');
$called1 = in_array('recommend_wetsuit_size', $used1, true);
$r1 = $res1['recommend_wetsuit_size']['result'] ?? [];
$gotL = ($r1['decision'] ?? null) === 'match' && in_array('L', $r1['sizes'] ?? [], true);
$s1 = $called1 && $gotL;
$ok = $ok && $s1;
echo '[' . ($s1 ? 'OK ' : 'FAIL') . "] Scenariusz 1 (pełne dane → tool → L)\n";
echo "      narzędzia: " . implode(', ', $used1) . ' | args: '
    . json_encode($res1['recommend_wetsuit_size']['args'] ?? [], JSON_UNESCAPED_UNICODE) . "\n";
echo "      wynik narzędzia: " . json_encode($r1['sizes'] ?? null, JSON_UNESCAPED_UNICODE)
    . ' decision=' . ($r1['decision'] ?? '?') . "\n";

// SCENARIUSZ 2: brak płci → model PYTA o płeć, NIE woła narzędzia z zgadniętą płcią.
[$c2, $used2, $res2] = runConversation($provider, $defs, $registry, $system,
    'Jaki rozmiar pianki mokrej Scubapro? Mam obwód klatki 104 cm.');
$asksGender = (bool) preg_match('/(kobiet|mężczyzn|męsk|damsk|płe)/iu', $c2);
$notCalled2 = !in_array('recommend_wetsuit_size', $used2, true);
$s2 = $asksGender && $notCalled2;
$ok = $ok && $s2;
echo '[' . ($s2 ? 'OK ' : 'FAIL') . "] Scenariusz 2 (brak płci → pyta, nie woła narzędzia)\n";
echo "      pyta_o_płeć=" . var_export($asksGender, true) . " nie_wołał_narzędzia=" . var_export($notCalled2, true) . "\n";
echo '      odpowiedź: ' . mb_substr(trim($c2), 0, 160) . "...\n";

// SCENARIUSZ 3: suchy skafander → NIE woła recommend_wetsuit_size (reguła suchych).
[$c3, $used3, $res3] = runConversation($provider, $defs, $registry, $system,
    'Jaki rozmiar suchego skafandra Santi dla mężczyzny, wzrost 182, klatka 104?');
$notCalled3 = !in_array('recommend_wetsuit_size', $used3, true);
$s3 = $notCalled3;
$ok = $ok && $s3;
echo '[' . ($s3 ? 'OK ' : 'FAIL') . "] Scenariusz 3 (suchy skafander → narzędzie NIE wołane)\n";
echo "      narzędzia: " . (implode(', ', $used3) ?: '(brak)') . "\n";

echo 'WYNIK E2E: ' . ($ok ? 'wszystkie OK' : 'SĄ BŁĘDY') . "\n";
exit($ok ? 0 : 1);
