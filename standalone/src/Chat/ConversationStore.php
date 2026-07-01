<?php

declare(strict_types=1);

namespace DiveChat\Chat;

use DiveChat\Chip\ChipButtonLabels;
use DiveChat\Database\PostgresConnection;

/**
 * Zapis i odczyt rozmów z divechat_conversations (PostgreSQL).
 */
final class ConversationStore
{
    private readonly PostgresConnection $db;

    public function __construct()
    {
        $this->db = PostgresConnection::getInstance();
    }

    /**
     * Wznawia lub tworzy nową sesję.
     *
     * CHAT-T-082 (sekcja 3 spec): front podaje sessionId (UUID v4) JUZ przy
     * ekspozycji nudge i przekazuje przy pierwszej wiadomosci. Backend
     * akceptuje client-supplied sessionId, ALE z modelem wlasnosci po HMAC:
     *  - sessionId istnieje + ps_customer_id pasuje do $customerId -> resume.
     *  - sessionId istnieje + ps_customer_id JEST CUDZE -> NIE resume,
     *    generujemy NOWY server-side sessionId i tworzymy nowa rozmowe
     *    (zapobiega podszyciu pod cudzy wpis). Klient dostanie zwrotnie
     *    nowy sessionId w odpowiedzi.
     *  - sessionId nie istnieje -> INSERT z TYM sessionId + $customerId
     *    (NOWE: akceptujemy client-supplied, NIE generujemy wlasnego).
     *
     * Format sessionId waliduje caller (ChatController) — store ufa wejsciu.
     *
     * Goscie (customerId=null/0): porownujemy 0==0. Sesje gosci sa
     * wzajemnie nieodroznialne po customerId; sessionId pelni role sekretu
     * (decyzja 145a, /api/chat/history). Spojnie z findActiveBySessionId.
     *
     * CHAT-T-085 (ADR-091): opcjonalny $nudgeSid (atrybucja zrodla). Zapis
     * TYLKO przy INSERT nowej rozmowy (gałąź "sessionId nie istnieje" oraz
     * ownership mismatch generujacy nowy effectiveSessionId). Przy resume
     * istniejacej NIE nadpisujemy — atrybucja nalezy do momentu powstania
     * rozmowy. Klient bez ekspozycji nudge wysle null -> kolumna NULL.
     *
     * CHAT-T-122 (ADR-110 pkt 3/4): opcjonalny $chipPath (strukturalna sciezka
     * chipow). Utrwalany RAZ na rozmowe: przy INSERT nowej rozmowy oraz — na
     * sciezce resume — gdy kolumna chip_path rozmowy jest jeszcze NULL (pierwsza
     * wiadomosc doslana przez chip). Idempotentny: warunek `chip_path IS NULL`
     * w UPDATE gwarantuje brak nadpisania na kolejnych turach. ROZLACZNY z
     * chip_context (string dla LLM), ktory pozostaje efemeryczny (ADR-097).
     *
     * @param ?list<array{node_key: string, label: string, level: int}> $chipPath
     * @return array{id: int, history: array, session_id: string} `id` =
     *   klucz PK z divechat_conversations (FK divechat_message_usage).
     *   `session_id` = EFEKTYWNY sessionId (moze sie roznic od wejsciowego
     *   przy ownership mismatch — caller MUSI uzyc tego pola dalej).
     */
    public function startOrResume(string $sessionId, ?int $customerId, ?string $nudgeSid = null, ?array $chipPath = null): array
    {
        $row = $this->db->fetchOne(
            'SELECT id, ps_customer_id, messages FROM divechat_conversations
             WHERE session_id = ? AND closed_at IS NULL
             ORDER BY started_at DESC LIMIT 1',
            [$sessionId],
        );

        $effectiveSessionId = $sessionId;

        if ($row) {
            $existingOwner = $row['ps_customer_id'] !== null ? (int) $row['ps_customer_id'] : 0;
            $requestOwner = $customerId ?? 0;

            if ($existingOwner === $requestOwner) {
                // Resume istniejacej — NIE nadpisujemy nudge_sid (atrybucja
                // nalezy do momentu powstania rozmowy, CHAT-T-085).
                // CHAT-T-122: utrwal chip_path TYLKO gdy dotad NULL (idempotent).
                // Typowo pierwsza wiadomosc chipowej tury = INSERT ponizej, ale
                // gdy rozmowa juz istnieje bez sciezki (np. restore) — dopisz raz.
                if ($chipPath !== null) {
                    $this->persistChipPathIfEmpty((int) $row['id'], $chipPath);
                }

                return [
                    'id' => (int) $row['id'],
                    'history' => json_decode($row['messages'], true) ?: [],
                    'session_id' => $effectiveSessionId,
                ];
            }

            // Ownership mismatch -> NIE resume, NIE nadpisuj cudzej rozmowy.
            // Generujemy nowy server-side UUID v4 (zachowanie spojne ze
            // {exists:false} w /api/chat/history). Klient dostanie nowy
            // sessionId w odpowiedzi (caller propaguje). To NOWA rozmowa,
            // wiec nudge_sid TEZ trafia do bazy (CHAT-T-085).
            $effectiveSessionId = self::generateServerSessionId();
        }

        // Nowa sesja – RETURNING id w jednym roundtripie.
        // CHAT-T-085: nudge_sid zapisany przy INSERT (null gdy klient nie
        // miał ekspozycji nudge — kolumna ma DEFAULT NULL, ale explicit
        // dla czytelnosci kontraktu).
        // CHAT-T-122 (ADR-110): chip_path zapisany przy INSERT (null gdy wolne
        // pisanie bez chipow). json_encode z JSON_UNESCAPED_UNICODE — polskie
        // etykiety (label) bez \uXXXX, spojnie z reszta zapisow jsonb w tym Store.
        $chipPathJson = $chipPath !== null
            ? json_encode($chipPath, JSON_UNESCAPED_UNICODE)
            : null;

        $newRow = $this->db->fetchOne(
            'INSERT INTO divechat_conversations (session_id, ps_customer_id, messages, nudge_sid, chip_path)
             VALUES (?, ?, ?::jsonb, ?, ?::jsonb)
             RETURNING id',
            [$effectiveSessionId, $customerId, '[]', $nudgeSid, $chipPathJson],
        );

        return [
            'id' => (int) ($newRow['id'] ?? 0),
            'history' => [],
            'session_id' => $effectiveSessionId,
        ];
    }

    /**
     * Utrwal chip_path rozmowy TYLKO gdy dotad NULL (CHAT-T-122, ADR-110 pkt 4).
     *
     * Idempotentny: warunek `chip_path IS NULL` w WHERE gwarantuje, ze kolejne
     * tury tej samej rozmowy NIE nadpisza raz zapisanej sciezki (moment utrwalenia
     * = pierwsza realna wiadomosc). Uzywany na sciezce resume; INSERT nowej rozmowy
     * zapisuje chip_path bezposrednio.
     *
     * @param list<array{node_key: string, label: string, level: int}> $chipPath
     */
    private function persistChipPathIfEmpty(int $conversationId, array $chipPath): void
    {
        $this->db->query(
            'UPDATE divechat_conversations
             SET chip_path = ?::jsonb
             WHERE id = ? AND chip_path IS NULL',
            [json_encode($chipPath, JSON_UNESCAPED_UNICODE), $conversationId],
        );
    }

    /**
     * UUID v4 generowany server-side (fallback przy ownership mismatch,
     * lub gdy ChatController dostaje malformed sessionId).
     *
     * Format spojny z crypto.randomUUID() na froncie (CHAT-T-083).
     */
    private static function generateServerSessionId(): string
    {
        $bytes = random_bytes(16);
        // RFC 4122 v4: ustaw wersje (4) i wariant (10xx).
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    /**
     * Zapisuje historię wiadomości + diagnostykę.
     * Tokeny i koszt aktualizuje UsageLogger – tu tylko payload tekstowy + meta.
     */
    public function save(
        string $sessionId,
        array $messages,
        array $toolsUsed,
        string $modelUsed = '',
        array $responseTimes = [],
        array $searchDiagnostics = [],
        bool $knowledgeGap = false,
    ): void {
        $this->db->query(
            'UPDATE divechat_conversations
             SET messages = ?::jsonb,
                 tools_used = ?::jsonb,
                 model_used = COALESCE(?, model_used),
                 response_times = ?::jsonb,
                 search_diagnostics = ?::jsonb,
                 knowledge_gap = (? ::boolean OR COALESCE(knowledge_gap, false)),
                 updated_at = NOW()
             WHERE session_id = ? AND closed_at IS NULL',
            [
                json_encode($messages, JSON_UNESCAPED_UNICODE),
                json_encode(array_values($toolsUsed), JSON_UNESCAPED_UNICODE),
                $modelUsed ?: null,
                json_encode($responseTimes, JSON_UNESCAPED_UNICODE),
                json_encode($searchDiagnostics, JSON_UNESCAPED_UNICODE),
                $knowledgeGap ? 'true' : 'false',
                $sessionId,
            ],
        );
    }

    /**
     * Lista rozmów z paginacją i filtrami (dla admin API).
     */
    public function list(int $page, int $perPage, ?string $search, ?bool $knowledgeGap, ?string $adminStatus): array
    {
        $conditions = [];
        $params = [];

        if ($search !== null && $search !== '') {
            $params[] = '%' . $search . '%';
            $conditions[] = 'messages::text ILIKE ?';
        }

        if ($knowledgeGap !== null) {
            $params[] = $knowledgeGap;
            $conditions[] = 'knowledge_gap = ?';
        }

        if ($adminStatus !== null && $adminStatus !== '') {
            $params[] = $adminStatus;
            $conditions[] = 'admin_status = ?';
        }

        $where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        // Policz total
        $countRow = $this->db->fetchOne(
            "SELECT COUNT(*) as total FROM divechat_conversations {$where}",
            $params,
        );
        $total = (int) ($countRow['total'] ?? 0);

        // Pobierz stronę
        $offset = ($page - 1) * $perPage;
        $params[] = $perPage;
        $params[] = $offset;

        // CHAT-T-051 (decyzja 112a): first_user_message skorelowane podzapytanie
        // wzorzec z CostAnalytics::topConversations(). Alias tabeli list() to bare
        // `divechat_conversations` (nie `c.` jak w topConversations) — podzapytanie
        // odnosi sie do divechat_conversations.id, reszta bez zmian.
        // CHAT-T-122 (ADR-110 pkt 5): pomijaj etykiety przyciskow chipow target:ai
        // (np. "Napisz czego szukasz") w wyborze tytulu — chroni STARE rozmowy.
        $excludeChipLabels = ChipButtonLabels::notInSql('m.content');
        $rows = $this->db->fetchAll(
            "SELECT id, session_id, ps_customer_id, model_used, tools_used,
                    tokens_input, tokens_output,
                    cache_read_tokens, cache_creation_tokens,
                    estimated_cost,
                    knowledge_gap, admin_status,
                    jsonb_array_length(COALESCE(messages, '[]'::jsonb)) as message_count,
                    started_at, updated_at,
                    (SELECT m.content FROM divechat_messages m
                     WHERE m.conversation_id = divechat_conversations.id AND m.role = 'user'
                       AND {$excludeChipLabels}
                     ORDER BY m.created_at, m.id LIMIT 1) AS first_user_message
             FROM divechat_conversations
             {$where}
             ORDER BY updated_at DESC
             LIMIT ? OFFSET ?",
            $params,
        );

        return [
            'conversations' => array_map(fn(array $row) => [
                'id' => (int) $row['id'],
                'session_id' => $row['session_id'],
                'customer_id' => (int) ($row['ps_customer_id'] ?? 0),
                'message_count' => (int) $row['message_count'],
                'model_used' => $row['model_used'],
                'tools_used' => array_values(json_decode($row['tools_used'] ?? '[]', true) ?: []),
                'tokens_input' => (int) $row['tokens_input'],
                'tokens_output' => (int) $row['tokens_output'],
                'cache_read_tokens' => (int) ($row['cache_read_tokens'] ?? 0),
                'cache_creation_tokens' => (int) ($row['cache_creation_tokens'] ?? 0),
                'estimated_cost' => (float) ($row['estimated_cost'] ?? 0),
                'knowledge_gap' => (bool) $row['knowledge_gap'],
                'admin_status' => $row['admin_status'] ?? 'new',
                'started_at' => $row['started_at'],
                'updated_at' => $row['updated_at'],
                'first_message' => $row['first_user_message'] ?: null,
            ], $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Szczegóły jednej rozmowy.
     */
    public function getBySessionId(string $sessionId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM divechat_conversations WHERE session_id = ? ORDER BY started_at DESC LIMIT 1',
            [$sessionId],
        );

        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'session_id' => $row['session_id'],
            'customer_id' => (int) ($row['ps_customer_id'] ?? 0),
            'messages' => json_decode($row['messages'] ?? '[]', true),
            'tools_used' => array_values(json_decode($row['tools_used'] ?? '[]', true) ?: []),
            'tokens_input' => (int) $row['tokens_input'],
            'tokens_output' => (int) $row['tokens_output'],
            'cache_read_tokens' => (int) ($row['cache_read_tokens'] ?? 0),
            'cache_creation_tokens' => (int) ($row['cache_creation_tokens'] ?? 0),
            'estimated_cost' => (float) ($row['estimated_cost'] ?? 0),
            'model_used' => $row['model_used'],
            'response_times' => json_decode($row['response_times'] ?? '{}', true),
            'search_diagnostics' => json_decode($row['search_diagnostics'] ?? '[]', true),
            'knowledge_gap' => (bool) $row['knowledge_gap'],
            'admin_status' => $row['admin_status'] ?? 'new',
            'admin_notes' => $row['admin_notes'],
            'started_at' => $row['started_at'],
            'updated_at' => $row['updated_at'],
            'closed_at' => $row['closed_at'],
        ];
    }

    /**
     * Aktualizuje admin_status i admin_notes.
     */
    public function updateAdminStatus(string $sessionId, string $status, ?string $notes): bool
    {
        $stmt = $this->db->query(
            'UPDATE divechat_conversations
             SET admin_status = ?, admin_notes = ?, updated_at = NOW()
             WHERE session_id = ?',
            [$status, $notes, $sessionId],
        );

        return $stmt->rowCount() > 0;
    }

    /**
     * Lekki odczyt aktywnej rozmowy do weryfikacji wlasciciela + odtworzenia
     * historii w widgecie (CHAT-T-059, GET /api/chat/history).
     *
     * Zwraca {id, ps_customer_id, messages} lub null gdy rozmowa nie istnieje
     * albo zostala zamknieta (closed_at IS NOT NULL). NIE tworzy nowej sesji —
     * dla zalozenia rozmowy uzywaj startOrResume() z petli /api/chat.
     *
     * Wzorzec query 1:1 z startOrResume (active session + ORDER BY started_at
     * DESC LIMIT 1) plus pole ps_customer_id potrzebne do weryfikacji
     * wlasciciela w ChatController::history.
     */
    public function findActiveBySessionId(string $sessionId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT id, ps_customer_id, messages FROM divechat_conversations
             WHERE session_id = ? AND closed_at IS NULL
             ORDER BY started_at DESC LIMIT 1',
            [$sessionId],
        );

        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'ps_customer_id' => $row['ps_customer_id'] !== null ? (int) $row['ps_customer_id'] : 0,
            'messages' => json_decode($row['messages'] ?? '[]', true) ?: [],
        ];
    }

    /**
     * Pobiera historię wiadomości z sesji.
     */
    public function getHistory(string $sessionId): array
    {
        $row = $this->db->fetchOne(
            'SELECT messages FROM divechat_conversations
             WHERE session_id = ? AND closed_at IS NULL
             ORDER BY started_at DESC LIMIT 1',
            [$sessionId],
        );

        return $row ? (json_decode($row['messages'], true) ?: []) : [];
    }

    /**
     * Zamyka sesję.
     */
    public function close(string $sessionId): void
    {
        $this->db->query(
            'UPDATE divechat_conversations SET closed_at = NOW() WHERE session_id = ?',
            [$sessionId],
        );
    }

    /**
     * Append wiadomości do `divechat_messages` (dual write – nadal jest też
     * historia w `divechat_conversations.messages` JSONB jako legacy).
     * Zwraca message_id potrzebny dla `UsageLogger::logMessage` (FK).
     *
     * @param array<int, array<string, mixed>>|null $toolCalls Lista wywołanych
     *   narzędzi (znormalizowana: `[{name, args}]`) – dla role='assistant' z
     *   tool_use; dla user/tool/system zwykle null.
     */
    public function appendMessage(
        int $conversationId,
        string $role,
        string $content,
        ?array $toolCalls = null,
    ): int {
        $row = $this->db->fetchOne(
            'INSERT INTO divechat_messages (conversation_id, role, content, tool_calls)
             VALUES (?, ?, ?, ?::jsonb)
             RETURNING id',
            [
                $conversationId,
                $role,
                $content,
                $toolCalls === null ? null : json_encode($toolCalls, JSON_UNESCAPED_UNICODE),
            ],
        );

        return (int) ($row['id'] ?? 0);
    }

    /**
     * Lista wiadomości per rozmowa (dla admin dashboardu / modala podglądu).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listMessages(int $conversationId): array
    {
        return $this->db->fetchAll(
            'SELECT id, role, content, tool_calls, rating, rating_at, created_at
             FROM divechat_messages
             WHERE conversation_id = ?
             ORDER BY created_at, id',
            [$conversationId],
        );
    }
}
