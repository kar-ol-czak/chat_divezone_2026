<?php

declare(strict_types=1);

namespace DiveChat\Tools;

/**
 * Interfejs narzędzia AI (function calling).
 */
interface ToolInterface
{
    public function getName(): string;

    public function getDescription(): string;

    /**
     * JSON Schema parametrów narzędzia.
     */
    public function getParametersSchema(): array;

    /**
     * Wykonuje narzędzie i zwraca wynik jako tablicę.
     */
    public function execute(array $params): array;
}
