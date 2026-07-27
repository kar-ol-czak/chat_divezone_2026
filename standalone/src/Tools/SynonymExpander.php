<?php

declare(strict_types=1);

namespace DiveChat\Tools;

use DiveChat\Database\PostgresConnection;

/**
 * Ekspansja zapytań FTS o synonimy nurkowe z divechat_synonyms.
 * Lazy-load mapy synonimów (1 query per request, cache w property).
 */
final class SynonymExpander
{
    /** @var array<string, list<string>>|null mapa: słowo → lista wszystkich słów z grupy */
    private ?array $synonymMap = null;

    public function __construct(
        private readonly PostgresConnection $db,
    ) {}

    /**
     * Rozszerza zapytanie o synonimy i zwraca string tsquery.
     * Przykład: "pianka 7mm" → "(pianka | skafander | mokry | wetsuit | neopren) & 7mm"
     */
    public function expandForFts(string $query): string
    {
        $map = $this->getMap();
        $tokens = $this->tokenize($query);

        if (empty($tokens)) {
            return '';
        }

        $parts = [];
        $consumed = [];

        for ($i = 0; $i < count($tokens); $i++) {
            if (isset($consumed[$i])) {
                continue;
            }

            // Próbuj dopasować multi-word (2 słowa) z mapy
            $matchedGroup = null;
            if ($i + 1 < count($tokens)) {
                $bigram = $tokens[$i] . ' ' . $tokens[$i + 1];
                $bigramLower = mb_strtolower($bigram);
                if (isset($map[$bigramLower])) {
                    $matchedGroup = $map[$bigramLower];
                    $consumed[$i] = true;
                    $consumed[$i + 1] = true;
                }
            }

            // Próbuj single token
            if ($matchedGroup === null) {
                $tokenLower = mb_strtolower($tokens[$i]);
                if (isset($map[$tokenLower])) {
                    $matchedGroup = $map[$tokenLower];
                    $consumed[$i] = true;
                }
            }

            if ($matchedGroup !== null) {
                // Rozbij multi-word synonimy na osobne tokeny i zbierz unikalne
                $allWords = [];
                foreach ($matchedGroup as $phrase) {
                    foreach (explode(' ', $phrase) as $w) {
                        $w = trim($w);
                        if ($w !== '') {
                            $allWords[mb_strtolower($w)] = true;
                        }
                    }
                }
                $escaped = array_map([$this, 'escapeTsToken'], array_keys($allWords));
                $parts[] = '(' . implode(' | ', $escaped) . ')';
            } else {
                $consumed[$i] = true;
                $parts[] = $this->escapeTsToken($tokens[$i]);
            }
        }

        return implode(' & ', $parts);
    }

    /**
     * Tokenizuje zapytanie: rozdziela po spacjach, usuwa puste.
     * @return list<string>
     */
    private function tokenize(string $query): array
    {
        $tokens = preg_split('/\s+/', trim($query));
        return array_values(array_filter($tokens, fn(string $t) => $t !== ''));
    }

    /**
     * Escape tokenu dla tsquery — tylko alfanumeryczne + cyfry, reszta usunięta.
     */
    private function escapeTsToken(string $token): string
    {
        // Usuń znaki specjalne tsquery: &, |, !, (, ), :, *, <, >
        $clean = preg_replace('/[&|!():*<>\'"]/', '', $token);
        $clean = trim($clean);
        return $clean !== '' ? $clean : '';
    }

    /**
     * Lazy-load mapy synonimów.
     * Buduje mapę: każde słowo (canonical + synonym) → lista wszystkich słów w grupie.
     * @return array<string, list<string>>
     */
    private function getMap(): array
    {
        if ($this->synonymMap !== null) {
            return $this->synonymMap;
        }

        $rows = $this->db->fetchAll(
            'SELECT canonical_term, synonym FROM divechat_synonyms ORDER BY canonical_term'
        );

        // Grupuj: canonical → [canonical, syn1, syn2, ...]
        $groups = [];
        foreach ($rows as $row) {
            $canonical = mb_strtolower($row['canonical_term']);
            $synonym = mb_strtolower($row['synonym']);

            if (!isset($groups[$canonical])) {
                $groups[$canonical] = [$canonical];
            }
            if (!in_array($synonym, $groups[$canonical], true)) {
                $groups[$canonical][] = $synonym;
            }
        }

        // Buduj mapę: każde słowo/fraza z grupy → cała grupa
        $this->synonymMap = [];
        foreach ($groups as $groupWords) {
            foreach ($groupWords as $word) {
                $this->synonymMap[$word] = $groupWords;
            }
        }

        return $this->synonymMap;
    }
}
