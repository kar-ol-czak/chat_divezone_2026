<?php

declare(strict_types=1);

namespace DiveChat\Admin;

use DiveChat\Database\PostgresConnection;
use DiveChat\Enum\ReviewStatus;
use DiveChat\Enum\ReviewVerdict;

/**
 * Warstwa dostepu do recenzji rozmow (CHAT-T-104, ADR-102).
 * Tabela divechat_conversation_review (migracja 037) na Railway PG.
 *
 * Model: brak wiersza = stan "nowy" implicytny (D3). Wiersz powstaje przy
 * pierwszej akcji recenzenta (upsert). Walidacja enumow status/verdict w JEDNYM
 * miejscu (validateFields) — nieznana wartosc rzuca InvalidReviewValueException
 * (kontroler -> 422). CHECK constraint w DB to druga linia obrony.
 */
final class ConversationReviewRepository
{
    /** Pola edytowalne przez upsert (poza updated_by/updated_at sterowanymi serwerowo). */
    private const UPDATABLE = ['status', 'verdict', 'note'];

    public function __construct(
        private readonly PostgresConnection $db,
    ) {}

    /**
     * Pelny wiersz recenzji dla rozmowy albo null (brak wiersza = stan "nowy", D3).
     *
     * @return array<string, mixed>|null
     */
    public function getByConversation(int $conversationId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT id, conversation_id, status, verdict, note, updated_by, created_at, updated_at
             FROM divechat_conversation_review
             WHERE conversation_id = ?',
            [$conversationId],
        );

        return $row !== null ? $this->normalizeRow($row) : null;
    }

    /**
     * Lista rozmow do recenzji o danym statusie + lekki skrot rozmowy. Paginacja
     * przez limit/offset.
     *
     * ADR-102 D3 — REWIZJA 2026-06-29 (workflow Karola, CHAT-T-105 feedback):
     * status 'nowy' = SKRZYNKA KATALOGU, nie tylko jawne wiersze. Pokazuje rozmowy
     * BEZ wiersza recenzji (stan "nowy" implicytny) ORAZ z jawnym status='nowy',
     * sort malejaco po started_at. Oznaczenie rozmowy dowolnym innym statusem
     * (upsert) tworzy wiersz i USUWA ja ze skrzynki 'nowy'. Pozostale statusy
     * (do_weryfikacji/w_trakcie/zamkniety) = TYLKO istniejace wiersze (kolejka
     * robocza), sort malejaco po updated_at recenzji.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function listByStatus(string $status, int $limit, int $offset): array
    {
        // Walidacja statusu filtra — nieznany -> 422 (spojnie z upsert).
        if (ReviewStatus::tryFromString($status) === null) {
            throw new InvalidReviewValueException("Nieznany status: {$status}");
        }

        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        if ($status === ReviewStatus::NOWY->value) {
            return $this->listNewInbox($limit, $offset);
        }

        $total = (int) $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM divechat_conversation_review WHERE status = ?',
            [$status],
        )['c'];

        // Lekki zestaw do listy: pola recenzji + skrot rozmowy (started_at,
        // model_used, liczba wiadomosci, pierwsza wiadomosc uzytkownika).
        // messages JSONB to kanoniczny store (jak w AdminNudgeCtrController).
        $rows = $this->db->fetchAll(
            "SELECT r.conversation_id, r.status, r.verdict, r.updated_by, r.updated_at,
                    c.session_id, c.started_at, c.model_used,
                    COALESCE(jsonb_array_length(c.messages), 0) AS message_count,
                    (SELECT m->>'content'
                       FROM jsonb_array_elements(c.messages) m
                      WHERE m->>'role' = 'user'
                      LIMIT 1) AS first_user_message
             FROM divechat_conversation_review r
             JOIN divechat_conversations c ON c.id = r.conversation_id
             WHERE r.status = ?
             ORDER BY r.updated_at DESC
             LIMIT ? OFFSET ?",
            [$status, $limit, $offset],
        );

        return [
            'items'  => array_map([$this, 'mapListRow'], $rows),
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Skrzynka 'nowy' (ADR-102 D3 rewizja): rozmowy bez wiersza recenzji LUB z
     * jawnym status='nowy'. LEFT JOIN z anti-warunkiem; sort malejaco po started_at
     * rozmowy (najnowsze czaty na gorze). status zawsze 'nowy' (COALESCE), verdict/
     * updated_by/updated_at z wiersza gdy istnieje (zwykle NULL dla swiezych).
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    private function listNewInbox(int $limit, int $offset): array
    {
        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(*) AS c
             FROM divechat_conversations c
             LEFT JOIN divechat_conversation_review r ON r.conversation_id = c.id
             WHERE r.id IS NULL OR r.status = 'nowy'",
        )['c'];

        $rows = $this->db->fetchAll(
            "SELECT c.id AS conversation_id,
                    COALESCE(r.status, 'nowy') AS status,
                    r.verdict, r.updated_by, r.updated_at,
                    c.session_id, c.started_at, c.model_used,
                    COALESCE(jsonb_array_length(c.messages), 0) AS message_count,
                    (SELECT m->>'content'
                       FROM jsonb_array_elements(c.messages) m
                      WHERE m->>'role' = 'user'
                      LIMIT 1) AS first_user_message
             FROM divechat_conversations c
             LEFT JOIN divechat_conversation_review r ON r.conversation_id = c.id
             WHERE r.id IS NULL OR r.status = 'nowy'
             ORDER BY c.started_at DESC
             LIMIT ? OFFSET ?",
            [$limit, $offset],
        );

        return [
            'items'  => array_map([$this, 'mapListRow'], $rows),
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Mapowanie wiersza listy (recenzja + skrot rozmowy) do ksztaltu API. Wspolne
     * dla kolejki roboczej i skrzynki 'nowy'.
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function mapListRow(array $r): array
    {
        $first = $r['first_user_message'];
        if (is_string($first) && mb_strlen($first) > 200) {
            $first = mb_substr($first, 0, 200) . '…';
        }

        return [
            'conversation_id'    => (int) $r['conversation_id'],
            'status'             => (string) $r['status'],
            'verdict'            => $r['verdict'] !== null ? (string) $r['verdict'] : null,
            'updated_by'         => $r['updated_by'] !== null ? (int) $r['updated_by'] : null,
            'updated_at'         => $r['updated_at'],
            'session_id'         => $r['session_id'],
            'started_at'         => $r['started_at'],
            'model_used'         => $r['model_used'],
            'message_count'      => (int) $r['message_count'],
            'first_user_message' => $first,
        ];
    }

    /**
     * Liczniki recenzji per status (CHAT-T-106) — jedno GROUP BY, zwracane
     * niezaleznie od aktywnego filtra (segmentowany przelacznik CHAT-T-107
     * pokazuje pelny obraz kolejki).
     *
     * Gwarantuje KOMPLET czterech kluczy (statusy bez wierszy → 0; front
     * potrzebuje wszystkich, by segment pokazal "0" zamiast zniknac). Klucze
     * seedowane z ReviewStatus::cases() (jedno zrodlo prawdy z enumem).
     *
     * ADR-102 D3 — REWIZJA 2026-06-29: licznik `nowy` = SKRZYNKA katalogu (rozmowy
     * bez wiersza recenzji + jawny status='nowy'), spojnie z listByStatus('nowy').
     * Pozostale statusy (do_weryfikacji/w_trakcie/zamkniety) = liczba istniejacych
     * wierszy (kolejka robocza). Dzieki temu segment 'nowy' (CHAT-T-110) pokazuje
     * realny rozmiar skrzynki, a nie ~0.
     *
     * @return array<string, int> Mapa status => liczba, zawsze 4 klucze.
     */
    public function countsByStatus(): array
    {
        // Wszystkie dozwolone statusy zerami — komplet kluczy dla frontu.
        $counts = [];
        foreach (ReviewStatus::cases() as $case) {
            $counts[$case->value] = 0;
        }

        // Kolejka robocza: liczba wierszy per status (w tym ewentualne jawne 'nowy').
        $rows = $this->db->fetchAll(
            'SELECT status, COUNT(*) AS c
             FROM divechat_conversation_review
             GROUP BY status',
        );

        foreach ($rows as $row) {
            $status = (string) $row['status'];
            // Nieznany status w bazie (poza enumem) NIE przecieka do API — pomijamy
            // i logujemy (CHECK constraint nie powinien na to pozwolic; defensywnie).
            if (!array_key_exists($status, $counts)) {
                error_log("[ConversationReviewRepository] countsByStatus: nieznany status w bazie pominiety: {$status}");
                continue;
            }
            $counts[$status] = (int) $row['c'];
        }

        // Nadpisz 'nowy' rozmiarem skrzynki katalogu (bez wiersza + jawne 'nowy').
        $counts[ReviewStatus::NOWY->value] = (int) $this->db->fetchOne(
            "SELECT COUNT(*) AS c
             FROM divechat_conversations c
             LEFT JOIN divechat_conversation_review r ON r.conversation_id = c.id
             WHERE r.id IS NULL OR r.status = 'nowy'",
        )['c'];

        return $counts;
    }

    /**
     * Upsert recenzji. Tworzy wiersz jesli nie istnieje (status default
     * 'do_weryfikacji' gdy nie podano), w przeciwnym razie aktualizuje TYLKO
     * podane pola. Zawsze ustawia updated_by=idEmployee, updated_at=now().
     *
     * @param array<string, mixed> $fields Podzbior {status, verdict, note}.
     * @return array<string, mixed> Pelny wiersz po zapisie.
     *
     * @throws InvalidReviewValueException gdy status/verdict spoza enumu.
     */
    public function upsert(int $conversationId, array $fields, int $idEmployee): array
    {
        $clean = $this->validateFields($fields);

        // Kolumny INSERT: zawsze conversation_id + updated_by; status z defaultem
        // gdy nie podany; verdict/note tylko gdy podane (reszta = DB default/NULL).
        $insertCols = ['conversation_id', 'updated_by'];
        $insertVals = [$conversationId, $idEmployee];

        $status = $clean['status'] ?? ReviewStatus::DEFAULT->value;
        $insertCols[] = 'status';
        $insertVals[] = $status;

        if (array_key_exists('verdict', $clean)) {
            $insertCols[] = 'verdict';
            $insertVals[] = $clean['verdict'];
        }
        if (array_key_exists('note', $clean)) {
            $insertCols[] = 'note';
            $insertVals[] = $clean['note'];
        }

        // ON CONFLICT DO UPDATE: aktualizuj TYLKO pola realnie podane w $fields
        // (status tylko gdy podany jawnie — NIE nadpisuj defaultem przy update).
        $setParts = ['updated_by = EXCLUDED.updated_by', 'updated_at = NOW()'];
        foreach (self::UPDATABLE as $col) {
            if (array_key_exists($col, $clean)) {
                $setParts[] = "{$col} = EXCLUDED.{$col}";
            }
        }

        $colList = implode(', ', $insertCols);
        $placeholders = implode(', ', array_fill(0, count($insertVals), '?'));
        $setList = implode(', ', $setParts);

        $sql = "INSERT INTO divechat_conversation_review ({$colList})
                VALUES ({$placeholders})
                ON CONFLICT (conversation_id) DO UPDATE SET {$setList}
                RETURNING id, conversation_id, status, verdict, note, updated_by, created_at, updated_at";

        $row = $this->db->query($sql, $insertVals)->fetch();

        return $this->normalizeRow($row);
    }

    /**
     * Walidacja + normalizacja podanych pol. Zwraca tylko pola realnie obecne
     * w wejsciu (klucz istnieje), znormalizowane. status/verdict spoza enumu
     * rzucaja wyjatek (-> 422). note: trim, pusty string -> null.
     *
     * @param array<string, mixed> $fields
     * @return array<string, string|null>
     */
    private function validateFields(array $fields): array
    {
        $out = [];

        if (array_key_exists('status', $fields)) {
            $raw = (string) $fields['status'];
            if (ReviewStatus::tryFromString($raw) === null) {
                throw new InvalidReviewValueException("Nieznany status: {$raw}");
            }
            $out['status'] = $raw;
        }

        if (array_key_exists('verdict', $fields)) {
            $val = $fields['verdict'];
            if ($val === null || $val === '') {
                // Jawne wyczyszczenie werdyktu (wraca do NULL).
                $out['verdict'] = null;
            } else {
                $raw = (string) $val;
                if (ReviewVerdict::tryFromString($raw) === null) {
                    throw new InvalidReviewValueException("Nieznany verdict: {$raw}");
                }
                $out['verdict'] = $raw;
            }
        }

        if (array_key_exists('note', $fields)) {
            $val = $fields['note'];
            if ($val === null) {
                $out['note'] = null;
            } else {
                $trimmed = trim((string) $val);
                $out['note'] = $trimmed === '' ? null : $trimmed;
            }
        }

        return $out;
    }

    /**
     * Normalizacja typow wiersza do JSON (PG przez PDO zwraca ints jako string).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        return [
            'id'              => (int) $row['id'],
            'conversation_id' => (int) $row['conversation_id'],
            'status'          => (string) $row['status'],
            'verdict'         => $row['verdict'] !== null ? (string) $row['verdict'] : null,
            'note'            => $row['note'] !== null ? (string) $row['note'] : null,
            'updated_by'      => $row['updated_by'] !== null ? (int) $row['updated_by'] : null,
            'created_at'      => $row['created_at'],
            'updated_at'      => $row['updated_at'],
        ];
    }
}
