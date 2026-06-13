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

        Response::jsonCached(
            ['tree' => $tree],
            $request->getHeader('if-none-match'),
        );
    }
}
