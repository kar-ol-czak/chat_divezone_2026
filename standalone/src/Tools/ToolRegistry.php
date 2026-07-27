<?php

declare(strict_types=1);

namespace DiveChat\Tools;

/**
 * Rejestr narzędzi AI.
 * Przechowuje instancje i generuje definicje do wysłania do API.
 */
final class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
    }

    public function get(string $name): ToolInterface
    {
        return $this->tools[$name]
            ?? throw new \RuntimeException("Nieznane narzędzie: {$name}");
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * Zwraca definicje narzędzi w ujednoliconym formacie.
     * Providerzy konwertują na natywny format (Anthropic/OpenAI).
     *
     * @return array<array{name: string, description: string, parameters: array}>
     */
    public function getToolDefinitions(): array
    {
        return array_values(array_map(
            fn(ToolInterface $tool) => [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'parameters' => $tool->getParametersSchema(),
            ],
            $this->tools,
        ));
    }
}
