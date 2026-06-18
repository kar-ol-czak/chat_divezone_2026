<?php

declare(strict_types=1);

/**
 * CHAT-T-100 — sanity: recommend_wetsuit_size widoczne w pętli function calling OBU providerów.
 * Buduje REALNY rejestr z config/tools.php (jak index.php) i przepuszcza definicje przez
 * faktyczny kod formatujący narzędzia w ClaudeProvider i OpenAIProvider (reflection na private).
 * Uruchom: php tests/size_recommender_providers.php
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use DiveChat\AI\ClaudeProvider;
use DiveChat\AI\EmbeddingService;
use DiveChat\AI\OpenAIProvider;

// Wyłuskaj DATABASE_URL (lokalny .env nieparsowalny przez phpdotenv — defekt lokalny).
$envPath = dirname(__DIR__, 2) . '/.env';
if (is_readable($envPath) && preg_match('/^DATABASE_URL=(.*)$/m', (string) file_get_contents($envPath), $m)) {
    $_ENV['DATABASE_URL'] = trim($m[1], " \t\"'");
}
$_ENV['ANTHROPIC_API_KEY'] ??= 'sk-test-noop';
$_ENV['OPENAI_API_KEY'] ??= 'sk-test-noop';

$registerTools = require dirname(__DIR__) . '/config/tools.php';
$registry = $registerTools(new EmbeddingService());
$defs = $registry->getToolDefinitions();

$names = array_column($defs, 'name');
$has = in_array('recommend_wetsuit_size', $names, true);
echo 'Narzędzia w rejestrze: ' . implode(', ', $names) . "\n";
echo '  [' . ($has ? 'OK ' : 'FAIL') . "] recommend_wetsuit_size zarejestrowane (wspólne źródło dla obu providerów)\n";

$ok = $has;

// Claude: private formatTools(array $tools): array — natywny format Anthropic.
$claudeFmt = (new ReflectionMethod(ClaudeProvider::class, 'formatTools'))->invoke(new ClaudeProvider(), $defs);
$claudeNames = array_column($claudeFmt, 'name');
$cHas = in_array('recommend_wetsuit_size', $claudeNames, true);
$cEntry = $claudeFmt[array_search('recommend_wetsuit_size', $claudeNames, true)] ?? null;
$cSchema = $cHas && isset($cEntry['input_schema']['properties']['gender']);
$ok = $ok && $cHas && $cSchema;
echo '  [' . (($cHas && $cSchema) ? 'OK ' : 'FAIL') . "] ClaudeProvider::formatTools → tool + input_schema (gender/chest/height)\n";

// OpenAI: private formatTools(array $tools): array — natywny format OpenAI (function tools).
$openaiFmt = (new ReflectionMethod(OpenAIProvider::class, 'formatTools'))->invoke(new OpenAIProvider(), $defs);
// OpenAI zagnieżdża nazwę w ['function']['name'] albo ['name'] zależnie od implementacji — sprawdź oba.
$found = false;
$schemaOk = false;
foreach ($openaiFmt as $t) {
    $n = $t['function']['name'] ?? $t['name'] ?? null;
    if ($n === 'recommend_wetsuit_size') {
        $found = true;
        $params = $t['function']['parameters'] ?? $t['parameters'] ?? [];
        $schemaOk = isset($params['properties']['gender']);
    }
}
$ok = $ok && $found && $schemaOk;
echo '  [' . (($found && $schemaOk) ? 'OK ' : 'FAIL') . "] OpenAIProvider::formatTools → tool + parameters (gender/chest/height)\n";

echo 'WYNIK: ' . ($ok ? 'widoczne w OBU providerach' : 'PROBLEM Z WIDOCZNOŚCIĄ') . "\n";
exit($ok ? 0 : 1);
