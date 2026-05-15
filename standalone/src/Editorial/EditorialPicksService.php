<?php

declare(strict_types=1);

namespace DiveChat\Editorial;

use DiveChat\Database\PostgresConnection;

/**
 * Editorial Picks (ADR-054): manualny boost rankingu produktów.
 *
 * Tabela `divechat_editorial_picks`. boost_factor 1.0-2.5 mnoży RRF score
 * w ProductSearch::mergeRRF() po fusion. Aktywne picki: active=TRUE oraz
 * (expires_at IS NULL OR expires_at > NOW()). Auto-expire przez cron (expireDue).
 */
final class EditorialPicksService
{
    public function __construct(
        private readonly PostgresConnection $db,
    ) {}

    /**
     * Zwraca mapę product_id => boost_factor dla aktywnych picków matchujących
     * przekazane productIds. Jeśli category podana, filtruje:
     *  - category_hint IS NULL (boost we wszystkich kategoriach), lub
     *  - category_hint matchuje (case-insensitive) przekazaną kategorię.
     *
     * @param list<int> $productIds
     * @return array<int, float>
     */
    public function getActiveBoosts(array $productIds, ?string $category = null): array
    {
        if (empty($productIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $sql = "SELECT product_id, boost_factor, category_hint
                FROM divechat_editorial_picks
                WHERE active = TRUE
                  AND (expires_at IS NULL OR expires_at > NOW())
                  AND product_id IN ({$placeholders})";

        $rows = $this->db->fetchAll($sql, $productIds);

        $result = [];
        $categoryLower = $category !== null ? mb_strtolower($category) : null;

        foreach ($rows as $row) {
            $hint = $row['category_hint'];
            if ($hint !== null && $categoryLower !== null) {
                if (mb_strtolower((string) $hint) !== $categoryLower) {
                    continue;
                }
            } elseif ($hint !== null && $categoryLower === null) {
                // Pick zawężony do konkretnej kategorii, ale search bez category — pomiń.
                continue;
            }

            $pid = (int) $row['product_id'];
            $boost = (float) $row['boost_factor'];

            // Jeśli kilka picków matchuje (np. NULL + konkretny match), wybierz wyższy boost.
            if (!isset($result[$pid]) || $boost > $result[$pid]) {
                $result[$pid] = $boost;
            }
        }

        return $result;
    }

    /**
     * Lista picków dla panelu admina.
     *
     * @return list<array<string, mixed>>
     */
    public function list(?bool $active = null, string $orderBy = 'added_at'): array
    {
        $allowedOrder = ['added_at', 'expires_at', 'boost_factor', 'product_name'];
        if (!in_array($orderBy, $allowedOrder, true)) {
            $orderBy = 'added_at';
        }

        $where = '1=1';
        $params = [];
        if ($active === true) {
            $where = 'active = TRUE AND (expires_at IS NULL OR expires_at > NOW())';
        } elseif ($active === false) {
            $where = '(active = FALSE OR (expires_at IS NOT NULL AND expires_at <= NOW()))';
        }

        $sql = "SELECT id, product_id, product_name, category_hint, boost_factor,
                       reason, added_by, added_at, expires_at, last_review_at, active
                FROM divechat_editorial_picks
                WHERE {$where}
                ORDER BY {$orderBy} DESC NULLS LAST";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * INSERT lub UPSERT pick. ttlDays=null → expires_at NULL (bezterminowo).
     * UNIQUE constraint po (product_id, category_hint) — duplikat aktualizuje istniejący wiersz.
     *
     * @return array<string, mixed> Wstawiony/zaktualizowany pick.
     */
    public function add(
        int $productId,
        string $productName,
        ?string $categoryHint,
        float $boostFactor,
        string $reason,
        string $addedBy,
        ?int $ttlDays,
    ): array {
        if ($boostFactor < 1.0 || $boostFactor > 2.5) {
            throw new \InvalidArgumentException("boost_factor musi być w zakresie 1.0-2.5, otrzymano {$boostFactor}");
        }

        $expiresAt = $ttlDays !== null
            ? (new \DateTimeImmutable('now'))->modify("+{$ttlDays} days")->format('Y-m-d H:i:sP')
            : null;

        // UPSERT po UNIQUE(product_id, category_hint).
        // Uwaga: PG traktuje NULL w UNIQUE jako "różne" (każdy NULL jest unique).
        // Dla picków z category_hint=NULL UPSERT przez UNIQUE nie zadziała na NULL —
        // używamy zatem ON CONFLICT tylko dla non-null category_hint, a dla NULL
        // sprawdzamy ręcznie czy istnieje i ewentualnie UPDATE.
        if ($categoryHint === null) {
            $existing = $this->db->fetchOne(
                "SELECT id FROM divechat_editorial_picks
                 WHERE product_id = ? AND category_hint IS NULL
                 LIMIT 1",
                [$productId],
            );
            if ($existing !== null) {
                $this->update((int) $existing['id'], [
                    'boost_factor' => $boostFactor,
                    'reason' => $reason,
                    'expires_at' => $expiresAt,
                    'active' => true,
                ]);
                $row = $this->db->fetchOne(
                    "SELECT * FROM divechat_editorial_picks WHERE id = ?",
                    [(int) $existing['id']],
                );
                return $row ?? [];
            }
        }

        $sql = "INSERT INTO divechat_editorial_picks
                    (product_id, product_name, category_hint, boost_factor, reason, added_by, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (product_id, category_hint) DO UPDATE
                SET boost_factor = EXCLUDED.boost_factor,
                    reason = EXCLUDED.reason,
                    expires_at = EXCLUDED.expires_at,
                    active = TRUE
                RETURNING *";

        $stmt = $this->db->query($sql, [
            $productId, $productName, $categoryHint, $boostFactor, $reason, $addedBy, $expiresAt,
        ]);

        return $stmt->fetch() ?: [];
    }

    /**
     * Aktualizacja picka. Dozwolone klucze w $changes:
     *  - boost_factor (float)
     *  - reason (string)
     *  - expires_at (string Y-m-d H:i:sP lub null)
     *  - active (bool)
     */
    public function update(int $id, array $changes): bool
    {
        $allowed = ['boost_factor', 'reason', 'expires_at', 'active'];
        $sets = [];
        $params = [];

        foreach ($allowed as $key) {
            if (!array_key_exists($key, $changes)) {
                continue;
            }
            $value = $changes[$key];

            if ($key === 'boost_factor') {
                if ($value < 1.0 || $value > 2.5) {
                    throw new \InvalidArgumentException("boost_factor musi być w zakresie 1.0-2.5");
                }
                $value = (float) $value;
            } elseif ($key === 'active') {
                $value = (bool) $value ? 'TRUE' : 'FALSE';
                // Boolean trzymany jako literal w SQL (nie placeholder) bo PDO emuluje string.
                $sets[] = "active = {$value}";
                continue;
            }

            $sets[] = "{$key} = ?";
            $params[] = $value;
        }

        if (empty($sets)) {
            return false;
        }

        $params[] = $id;
        $sql = "UPDATE divechat_editorial_picks SET " . implode(', ', $sets) . " WHERE id = ?";
        $stmt = $this->db->query($sql, $params);

        return $stmt->rowCount() > 0;
    }

    public function markReviewed(int $id): bool
    {
        $stmt = $this->db->query(
            "UPDATE divechat_editorial_picks SET last_review_at = NOW() WHERE id = ?",
            [$id],
        );
        return $stmt->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->query(
            "DELETE FROM divechat_editorial_picks WHERE id = ?",
            [$id],
        );
        return $stmt->rowCount() > 0;
    }

    /**
     * Cron: dezaktywuje wygasłe picki. Nie usuwa wierszy (audit trail).
     * Zwraca liczbę zdezaktywowanych.
     */
    public function expireDue(): int
    {
        $stmt = $this->db->query(
            "UPDATE divechat_editorial_picks
             SET active = FALSE
             WHERE active = TRUE
               AND expires_at IS NOT NULL
               AND expires_at <= NOW()"
        );
        return $stmt->rowCount();
    }
}
