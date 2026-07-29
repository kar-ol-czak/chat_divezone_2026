<?php

declare(strict_types=1);

/**
 * Test jednostkowy narzędzia resend_order_info (CHAT-T-180 część B, ADR-140).
 * Czysta logika + zamockowany HTTP (Guzzle MockHandler) — bez realnej sieci.
 * Pokrywa: budowę tokenu HMAC, parsowanie odpowiedzi modułu (sent/not_found/
 * rate_limited/error), walidację pustych danych oraz kształt żądania
 * (URL, nagłówki HMAC spójne z buildToken, pola POST).
 *
 * Uruchomienie: php standalone/tests/Tools/ResendOrderInfoTest.php
 */

require_once __DIR__ . '/../bootstrap.php';

use DiveChat\Tools\ResendOrderInfo;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\ConnectException;

$passed = 0;
$failed = 0;

function assertTrue(string $name, bool $cond): void
{
    global $passed, $failed;
    if ($cond) {
        echo "[OK] {$name}\n";
        $passed++;
    } else {
        echo "[FAIL] {$name}\n";
        $failed++;
    }
}

// Mock Config: narzędzie czyta DIVECHAT_SERVER_SECRET przez Config::get -> $_ENV.
$_ENV['DIVECHAT_SERVER_SECRET'] = 'unit_test_secret_ffff';

$tool = new ResendOrderInfo();

// --- Metadane narzędzia ---
assertTrue('getName == resend_order_info', $tool->getName() === 'resend_order_info');
$schema = $tool->getParametersSchema();
assertTrue('schema wymaga order_reference + customer_email',
    $schema['required'] === ['order_reference', 'customer_email']);

// --- buildToken: deterministyczny, zgodny z hash_hmac, wrażliwy na wejście ---
$ts = 1730000000;
$expected = hash_hmac('sha256', 'AODMYANNV|jan@example.com|' . $ts, 'unit_test_secret_ffff');
assertTrue('buildToken == hash_hmac(payload)',
    $tool->buildToken('AODMYANNV', 'jan@example.com', $ts, 'unit_test_secret_ffff') === $expected);
assertTrue('buildToken różny dla innego emaila',
    $tool->buildToken('AODMYANNV', 'inny@example.com', $ts, 'unit_test_secret_ffff') !== $expected);
assertTrue('buildToken różny dla innego ts',
    $tool->buildToken('AODMYANNV', 'jan@example.com', $ts + 1, 'unit_test_secret_ffff') !== $expected);

// --- interpretResponse: wszystkie gałęzie ---
assertTrue('200 {ok:true} => sent:true',
    $tool->interpretResponse(200, '{"ok":true}') === ['sent' => true]);
assertTrue('200 not_found => reason not_found',
    $tool->interpretResponse(200, '{"ok":false,"error":"not_found"}') === ['sent' => false, 'reason' => 'not_found']);
assertTrue('200 rate_limited => reason rate_limited',
    $tool->interpretResponse(200, '{"ok":false,"error":"rate_limited"}') === ['sent' => false, 'reason' => 'rate_limited']);
assertTrue('200 nieznany error => error',
    $tool->interpretResponse(200, '{"ok":false,"error":"config"}') === ['sent' => false, 'reason' => 'error']);
assertTrue('401 => error',
    $tool->interpretResponse(401, '{"ok":false,"error":"unauthorized"}') === ['sent' => false, 'reason' => 'error']);
assertTrue('500 => error',
    $tool->interpretResponse(500, '') === ['sent' => false, 'reason' => 'error']);
assertTrue('200 niepoprawny JSON => error',
    $tool->interpretResponse(200, 'not json') === ['sent' => false, 'reason' => 'error']);
assertTrue('200 pusty body => error',
    $tool->interpretResponse(200, '') === ['sent' => false, 'reason' => 'error']);

// --- execute(): walidacja pustych danych (bez sieci) ---
assertTrue('execute pusty reference => error',
    $tool->execute(['order_reference' => '', 'customer_email' => 'a@b.pl']) === ['sent' => false, 'reason' => 'error']);
assertTrue('execute pusty email => error',
    $tool->execute(['order_reference' => 'ABC', 'customer_email' => '  ']) === ['sent' => false, 'reason' => 'error']);

// --- execute() z zamockowanym HTTP: mapowanie odpowiedzi + kształt żądania ---
function makeToolWithMock(array $queue, array &$history): ResendOrderInfo
{
    $mock = new MockHandler($queue);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    return new ResendOrderInfo(new Client(['handler' => $stack]));
}

// sukces + inspekcja żądania
$history = [];
$t = makeToolWithMock([new Response(200, [], '{"ok":true}')], $history);
$res = $t->execute(['order_reference' => 'AODMYANNV', 'customer_email' => 'jan@example.com']);
assertTrue('execute mock 200 ok => sent:true', $res === ['sent' => true]);

/** @var Request $req */
$req = $history[0]['request'];
assertTrue('żądanie: metoda POST', $req->getMethod() === 'POST');
assertTrue('żądanie: URL endpointu modułu',
    (string) $req->getUri() === 'https://divezone.pl/module/divezone_chat/resend_order_info');
$sentTs = (int) $req->getHeaderLine('X-DiveChat-Resend-Time');
$sentToken = $req->getHeaderLine('X-DiveChat-Resend-Token');
assertTrue('żądanie: nagłówek Time obecny', $sentTs > 0);
assertTrue('żądanie: token spójny z buildToken(payload z wysłanego ts)',
    $sentToken === $t->buildToken('AODMYANNV', 'jan@example.com', $sentTs, 'unit_test_secret_ffff'));
$body = (string) $req->getBody();
assertTrue('żądanie: form_params zawiera order_reference i email',
    strpos($body, 'order_reference=AODMYANNV') !== false && strpos($body, 'email=jan%40example.com') !== false);

// not_found / rate_limited / 401 / timeout przez pełne execute()
$h = [];
assertTrue('execute mock not_found',
    makeToolWithMock([new Response(200, [], '{"ok":false,"error":"not_found"}')], $h)
        ->execute(['order_reference' => 'X', 'customer_email' => 'a@b.pl']) === ['sent' => false, 'reason' => 'not_found']);
$h = [];
assertTrue('execute mock rate_limited',
    makeToolWithMock([new Response(200, [], '{"ok":false,"error":"rate_limited"}')], $h)
        ->execute(['order_reference' => 'X', 'customer_email' => 'a@b.pl']) === ['sent' => false, 'reason' => 'rate_limited']);
$h = [];
assertTrue('execute mock 401 => error',
    makeToolWithMock([new Response(401, [], '{"ok":false,"error":"unauthorized"}')], $h)
        ->execute(['order_reference' => 'X', 'customer_email' => 'a@b.pl']) === ['sent' => false, 'reason' => 'error']);
$h = [];
assertTrue('execute mock timeout/ConnectException => error',
    makeToolWithMock([new ConnectException('timeout', new Request('POST', 'https://divezone.pl'))], $h)
        ->execute(['order_reference' => 'X', 'customer_email' => 'a@b.pl']) === ['sent' => false, 'reason' => 'error']);

// --- brak sekretu => error (bez sieci) ---
$saved = $_ENV['DIVECHAT_SERVER_SECRET'];
unset($_ENV['DIVECHAT_SERVER_SECRET']);
assertTrue('brak DIVECHAT_SERVER_SECRET => error',
    (new ResendOrderInfo())->execute(['order_reference' => 'X', 'customer_email' => 'a@b.pl']) === ['sent' => false, 'reason' => 'error']);
$_ENV['DIVECHAT_SERVER_SECRET'] = $saved;

echo "\n=== {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
