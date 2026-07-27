<?php

declare(strict_types=1);

namespace DiveChat\Controller;

use DiveChat\Chip\ChipTreeService;
use DiveChat\Http\Request;
use DiveChat\Http\Response;

/**
 * Drzewo chipów dla widgetu (CHAT-T-088, ADR-096).
 * GET /api/chip-tree
 *
 * Zwraca całe aktywne drzewo zagnieżdżone od korzeni. Treść publiczna (jak nudge
 * BOOT) — BEZ auth. Cache'owalne (ETag + Cache-Control); widget pobiera raz na
 * starcie i renderuje lokalnie. Pusta tablica gdy drzewo niezaseedowane.
 */
final class ChipTreeController
{
    public function __construct(
        private readonly ChipTreeService $service,
    ) {}

    public function handle(Request $request): void
    {
        try {
            $tree = $this->service->getTree();
        } catch (\Throwable $e) {
            error_log("[DiveChat] chip-tree failed: {$e->getMessage()}");
            Response::error('Chip tree unavailable', 503);
        }

        // CHAT-T-107 (P36c/Sprawa 3): sygnalizuj zrodlo dla smoke — naglowkiem,
        // bez zmiany ksztaltu body (zero ryzyka dla widgetu/ETag). 'db' = swiezo
        // z Railway, 'cache' = degradacja (Railway niedostepne → cache/puste).
        // "Smoke OK" znaczy "z DB OK", nie "cache zamaskowal lezaca baze".
        $degraded = $this->service->wasDegraded();
        header('X-DiveChat-Chip-Source: ' . ($degraded ? 'cache' : 'db'));

        Response::jsonCached(
            ['tree' => $tree],
            $request->getHeader('if-none-match'),
        );
    }
}
