<?php

declare(strict_types=1);

namespace DiveChat\Tools;

use DiveChat\AI\EmbeddingService;
use DiveChat\Database\PostgresConnection;

/**
 * Wyszukiwanie semantyczne w bazie wiedzy eksperckiej.
 * Dane z divechat_knowledge (PostgreSQL/pgvector).
 */
final class ExpertKnowledge implements ToolInterface
{
    public function __construct(
        private readonly EmbeddingService $embeddingService,
        private readonly PostgresConnection $db,
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

        $embedding = $this->embeddingService->getEmbedding($query);
        $vectorStr = '[' . implode(',', $embedding) . ']';

        $conditions = ['active = true', '1 - (embedding <=> ?::vector) > 0.5'];
        $sqlParams = [$vectorStr];

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

        $sqlParams[] = $vectorStr; // dla SELECT
        $sqlParams[] = $vectorStr; // dla ORDER BY

        $results = $this->db->fetchAll($sql, $sqlParams);

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
