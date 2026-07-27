<?php

declare(strict_types=1);

namespace DiveChat\Tools;

use DiveChat\AI\EmbeddingService;
use DiveChat\Database\PostgresConnection;

/**
 * Wyszukiwanie semantyczne w encyklopedii sprzętu nurkowego.
 * Dane z encyclopedia_chunks (PostgreSQL/pgvector, 525 chunków, 105 haseł).
 */
final class ExpertKnowledge implements ToolInterface
{
    private const EMBEDDING_DIM = 3072;

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
        return 'Przeszukuje encyklopedię sprzętu nurkowego (105 haseł). '
             . 'Zawiera definicje, podtypy, parametry zakupowe, FAQ klientów, cross-sell i porady sprzedawcy. '
             . 'UŻYWAJ PRZED search_products gdy klient pyta o porady, porównania lub "jaki sprzęt wybrać". '
             . 'Wynik daje kontekst ekspercki który pomaga lepiej dobrać produkty.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Pytanie lub temat do wyszukania w encyklopedii sprzętu nurkowego',
                ],
                'chunk_types' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'enum' => ['definition', 'synonyms', 'purchase', 'faq', 'seller'],
                    ],
                    'description' => 'Typy chunków do przeszukania. '
                        . 'definition: co to jest, jak działa. '
                        . 'synonyms: nazwy, slang, frazy wyszukiwania. '
                        . 'purchase: parametry zakupowe, cross-sell, porównania. '
                        . 'faq: odpowiedzi na typowe pytania klientów. '
                        . 'seller: wewnętrzne porady sprzedawcy (nie cytuj klientowi). '
                        . 'Domyślnie: ["definition", "faq", "purchase"].',
                ],
                'concept_key' => [
                    'type' => 'string',
                    'description' => 'Opcjonalny filtr na konkretne hasło, np. "AUTOMAT_ODDECHOWY". '
                        . 'Użyj gdy wiesz dokładnie jakiego sprzętu dotyczy pytanie.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $params): array
    {
        $query = $params['query'] ?? '';
        $chunkTypes = $params['chunk_types'] ?? ['definition', 'faq', 'purchase'];
        $conceptKey = $params['concept_key'] ?? null;

        $embedding = $this->embeddingService->getEmbedding($query, self::EMBEDDING_DIM);
        $vectorStr = '[' . implode(',', $embedding) . ']';

        // Budowanie warunków WHERE i parametrów
        $conditions = [];
        $sqlParams = [];

        // Filtr chunk_type
        if (!empty($chunkTypes)) {
            $placeholders = implode(',', array_fill(0, count($chunkTypes), '?'));
            $conditions[] = "chunk_type IN ({$placeholders})";
            $sqlParams = array_merge($sqlParams, $chunkTypes);
        }

        // Filtr concept_key
        if ($conceptKey !== null) {
            $conditions[] = 'concept_key = ?';
            $sqlParams[] = $conceptKey;
        }

        // Similarity threshold
        $conditions[] = '1 - (embedding <=> ?::vector) > 0.45';
        $sqlParams[] = $vectorStr;

        $where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        // Parametry: SELECT vector, filtry w WHERE (chunk_types + concept_key + threshold vector), ORDER BY vector
        $sql = "SELECT concept_key, chunk_type, content, name_pl,
                       1 - (embedding <=> ?::vector) AS similarity,
                       metadata
                FROM encyclopedia_chunks
                {$where}
                ORDER BY embedding <=> ?::vector
                LIMIT 5";

        // SELECT vector + WHERE params + ORDER BY vector
        $finalParams = [$vectorStr, ...$sqlParams, $vectorStr];

        $results = $this->db->fetchAll($sql, $finalParams);

        if (empty($results)) {
            return [
                'knowledge' => [],
                'message' => 'Nie znaleziono wiedzy na ten temat w encyklopedii.',
            ];
        }

        return [
            'knowledge' => array_map(fn(array $row) => [
                'concept_key' => $row['concept_key'],
                'name' => $row['name_pl'],
                'chunk_type' => $row['chunk_type'],
                'content' => $row['content'],
                'similarity' => round((float) $row['similarity'], 3),
            ], $results),
            'count' => count($results),
        ];
    }
}
