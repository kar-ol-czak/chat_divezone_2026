<?php

declare(strict_types=1);

namespace DiveChat\Tools;

use DiveChat\AI\AIProviderInterface;
use DiveChat\Database\PostgresConnection;

/**
 * Wyszukiwanie semantyczne w bazie wiedzy eksperckiej.
 * Dane z divechat_knowledge (PostgreSQL/pgvector).
 */
final class ExpertKnowledge implements ToolInterface
{
    public function __construct(
        private readonly AIProviderInterface $aiProvider,
    ) {}

    public function getName(): string
    {
        return 'get_expert_knowledge';
    }

    public function getDescription(): string
    {
        return 'Przeszukuje bazę wiedzy eksperckiej o nurkowaniu i sprzęcie nurkowym. '
             . 'Zawiera poradniki doboru sprzętu, wyjaśnienia różnic między typami, porady dla nurków. '
             . 'Używaj gdy klient pyta o doradztwo, porównania typów sprzętu lub ogólne pytania o nurkowanie.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Pytanie lub temat do wyszukania, np. "jak dobrać maskę nurkową" lub "różnica między jacket a skrzydło BCD"',
                ],
                'category' => [
                    'type' => 'string',
                    'description' => 'Filtr kategorii wiedzy, np. "maski", "automaty", "komputery", "ogólne"',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $params): array
    {
        $query = $params['query'] ?? '';
        $category = $params['category'] ?? null;

        $embedding = $this->aiProvider->getEmbedding($query);
        $vectorStr = '[' . implode(',', $embedding) . ']';

        $conditions = ['active = true', '1 - (embedding <=> ?::vector) > 0.5'];
        $sqlParams = [$vectorStr]; // parametr 1: wektor

        if ($category !== null) {
            $sqlParams[] = $category;
            $conditions[] = 'category ILIKE \'%\' || ? || \'%\'';
        }

        $where = implode(' AND ', $conditions);

        $sql = "SELECT question, content, category,
                       1 - (embedding <=> ?::vector) AS similarity
                FROM divechat_knowledge
                WHERE {$where}
                ORDER BY embedding <=> ?::vector
                LIMIT 3";

        // Wektor potrzebny 3 razy (w WHERE, SELECT, ORDER BY) - ale PDO tworzy nowe parametry
        $sqlParams[] = $vectorStr; // dla SELECT
        $sqlParams[] = $vectorStr; // dla ORDER BY

        $db = PostgresConnection::getInstance();
        $results = $db->fetchAll($sql, $sqlParams);

        if (empty($results)) {
            return ['knowledge' => [], 'message' => 'Nie znaleziono wiedzy na ten temat.'];
        }

        return [
            'knowledge' => array_map(fn(array $row) => [
                'question' => $row['question'],
                'content' => $row['content'],
                'category' => $row['category'],
                'similarity' => round((float) $row['similarity'], 3),
            ], $results),
            'count' => count($results),
        ];
    }
}
