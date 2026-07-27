<?php

declare(strict_types=1);

/**
 * CHAT-T-083 weryfikacja korelacji (sanity-check punkt 5, decyzja 251a).
 * JEDNORAZOWY skrypt diagnostyczny — sprawdza, czy session_id z
 * divechat_nudge_events spina sie z divechat_conversations (fundament
 * wskaznika konwersji w przyszlym panelu CHAT-T-084).
 *
 * Reuzywa polaczenie backendu (PostgresConnection -> DATABASE_URL z .env,
 * Railway). NIE pisze do bazy — same SELECT-y. Wynik jako tekst na stdout.
 *
 * Uruchomienie na prod:
 *   /opt/cpanel/ea-php84/root/usr/bin/php scripts/verify_nudge_correlation.php
 *
 * Po weryfikacji skrypt mozna usunac (albo zostawic jako narzedzie diag).
 */

use DiveChat\Config;
use DiveChat\Database\PostgresConnection;

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

foreach ([dirname($basePath), $basePath] as $path) {
    if (is_file($path . '/.env')) {
        Config::load($path);
        break;
    }
}

$db = PostgresConnection::getInstance();

echo "=== CHAT-T-083 weryfikacja korelacji nudge <-> rozmowa ===\n";
echo "Baza: Railway (DATABASE_URL z .env). Tylko SELECT.\n\n";

if (!$db->isConnected()) {
    echo "[BLAD] Brak polaczenia z PG. Sprawdz DATABASE_URL w .env.\n";
    exit(1);
}

/* ── 1. Czy zdarzenia w ogole splywaja ─────────────────────────────── */
echo "--- 1. Zdarzenia w divechat_nudge_events (per typ/bucket/ab) ---\n";
$rows = $db->fetchAll(
    'SELECT event_type, bucket, ab_active, COUNT(*) AS n
       FROM divechat_nudge_events
      GROUP BY event_type, bucket, ab_active
      ORDER BY bucket, event_type, ab_active'
);
if (!$rows) {
    echo "  (brak zdarzen — beacon nie dochodzi LUB nikt jeszcze nie widzial nudge)\n";
} else {
    foreach ($rows as $r) {
        printf(
            "  %-16s bucket=%-3s ab=%-5s -> %s\n",
            $r['event_type'],
            $r['bucket'],
            $r['ab_active'] ? 'true' : 'false',
            $r['n']
        );
    }
}
echo "\n";

/* ── 2. SEDNO: klik -> czy powstala rozmowa o tym samym nudge_sid ─────
 * CHAT-T-085 (ADR-091): JOIN po events.session_id ↔ conversations.nudge_sid
 * (NIE c.session_id). session_id rozmowy moze byc nadpisany przez restore
 * (CHAT-T-059); nudge_sid trzyma atrybucje ekspozycji.
 */
echo "--- 2. Korelacja: nudge_cta_click vs divechat_conversations (JOIN po nudge_sid) ---\n";
$rows = $db->fetchAll(
    "SELECT
        e.session_id,
        e.bucket,
        e.ab_active,
        (c.nudge_sid IS NOT NULL)                        AS ma_rozmowe,
        COALESCE(jsonb_array_length(c.messages), 0)      AS liczba_wiadomosci
       FROM divechat_nudge_events e
       LEFT JOIN divechat_conversations c ON c.nudge_sid = e.session_id
      WHERE e.event_type = 'nudge_cta_click'
      ORDER BY e.session_id"
);
if (!$rows) {
    echo "  (brak klikniec CTA — kliknij dymek/karte i wyslij wiadomosc, potem odpal ponownie)\n";
} else {
    foreach ($rows as $r) {
        printf(
            "  sid=%s bucket=%s ab=%s | ma_rozmowe=%s wiadomosci=%s\n",
            substr((string) $r['session_id'], 0, 13) . '...',
            $r['bucket'],
            $r['ab_active'] ? 'T' : 'F',
            $r['ma_rozmowe'] ? 'TAK' : 'NIE',
            $r['liczba_wiadomosci']
        );
    }
    $ok = array_filter($rows, static fn ($r) => $r['ma_rozmowe'] && (int) $r['liczba_wiadomosci'] >= 1);
    echo "\n  -> klikniec z dopasowana rozmowa (>=1 wiadomosc): "
        . count($ok) . ' / ' . count($rows) . "\n";
}
echo "\n";

/* ── 3. Prototyp metryki panelu (CTR + konwersja per bucket) ─────────
 * CHAT-T-085 (ADR-091): konwersja JOIN-uje po conv.nudge_sid = s.session_id
 * (sid ekspozycji jest niezmienny, session_id rozmowy moze byc nadpisane
 * przez restore). DISTINCT po conv.nudge_sid liczy unikalne rozmowy
 * atrybuowane do ekspozycji.
 */
echo "--- 3. Prototyp raportu CTR (jak policzy panel CHAT-T-084) ---\n";
$rows = $db->fetchAll(
    "SELECT
        s.bucket,
        s.ab_active,
        COUNT(DISTINCT s.session_id)    AS ekspozycje,
        COUNT(DISTINCT k.session_id)    AS kliki,
        COUNT(DISTINCT conv.nudge_sid)  AS rozmowy
       FROM divechat_nudge_events s
       LEFT JOIN divechat_nudge_events k
              ON k.session_id = s.session_id AND k.event_type = 'nudge_cta_click'
       LEFT JOIN divechat_conversations conv
              ON conv.nudge_sid = s.session_id
             AND jsonb_array_length(conv.messages) >= 1
      WHERE s.event_type = 'nudge_shown'
      GROUP BY s.bucket, s.ab_active
      ORDER BY s.bucket, s.ab_active"
);
if (!$rows) {
    echo "  (brak ekspozycji)\n";
} else {
    foreach ($rows as $r) {
        $eksp = (int) $r['ekspozycje'];
        $ctr  = $eksp ? round(100 * (int) $r['kliki'] / $eksp, 1) : 0;
        $conv = $eksp ? round(100 * (int) $r['rozmowy'] / $eksp, 1) : 0;
        printf(
            "  bucket=%-3s ab=%-5s | ekspozycje=%-4s kliki=%-4s CTR=%-5s%% rozmowy=%-4s konwersja=%s%%\n",
            $r['bucket'],
            $r['ab_active'] ? 'true' : 'false',
            $eksp,
            $r['kliki'],
            $ctr,
            $r['rozmowy'],
            $conv
        );
    }
}
echo "\n";

/* ── 4. DIAGNOSTYKA A vs B (CHAT-T-083, decyzja 252a) ──────────────────
 * Rozstrzyga, czy ma_rozmowe=NIE to (A) klik bez wyslanej wiadomosci
 * (rekord nie powstal — naturalne) czy (B) rozjazd sessionId front<->backend
 * (rekord powstal pod innym sid/formacie — bug strukturalny).
 */
echo "--- 4a. Ostatnie rozmowy (divechat_conversations, 24h) ---\n";
echo "    Format sid + dlugosc + liczba wiadomosci. UUID v4 = 36 znakow z '-'.\n";
echo "    Legacy CHAT-T-059 = 32 hex bez '-'. Rozjazd formatu = trop B.\n";
$rows = $db->fetchAll(
    "SELECT
        session_id,
        length(session_id)                          AS dlugosc,
        (session_id ~ '^[0-9a-f-]{36}$')            AS wyglada_jak_uuid,
        COALESCE(jsonb_array_length(messages), 0)   AS wiadomosci,
        ps_customer_id,
        to_char(started_at, 'YYYY-MM-DD HH24:MI')   AS start
       FROM divechat_conversations
      WHERE started_at > NOW() - INTERVAL '24 hours'
      ORDER BY started_at DESC
      LIMIT 30"
);
if (!$rows) {
    echo "  (brak rozmow z ostatnich 24h)\n";
} else {
    foreach ($rows as $r) {
        printf(
            "  sid=%s len=%s uuid=%s wiad=%s cust=%s %s\n",
            substr((string) $r['session_id'], 0, 13) . '...',
            $r['dlugosc'],
            $r['wyglada_jak_uuid'] ? 'T' : 'F',
            $r['wiadomosci'],
            $r['ps_customer_id'] ?? 'null',
            $r['start']
        );
    }
}
echo "\n";

echo "--- 4b. Czy sid z klikow ISTNIEJE w rozmowach (dokladne dopasowanie) ---\n";
$rows = $db->fetchAll(
    "SELECT
        e.session_id,
        to_char(e.created_at, 'YYYY-MM-DD HH24:MI') AS klik_o,
        EXISTS (SELECT 1 FROM divechat_conversations c
                 WHERE c.session_id = e.session_id)  AS rekord_istnieje,
        (SELECT COALESCE(jsonb_array_length(c.messages),0)
           FROM divechat_conversations c
          WHERE c.session_id = e.session_id)          AS wiadomosci
       FROM divechat_nudge_events e
      WHERE e.event_type = 'nudge_cta_click'
      ORDER BY e.created_at DESC"
);
if (!$rows) {
    echo "  (brak klikniec)\n";
} else {
    foreach ($rows as $r) {
        printf(
            "  sid=%s klik=%s | rekord=%s wiadomosci=%s\n",
            substr((string) $r['session_id'], 0, 13) . '...',
            $r['klik_o'],
            $r['rekord_istnieje'] ? 'JEST' : 'BRAK',
            $r['wiadomosci'] ?? '0'
        );
    }
    echo "\n  INTERPRETACJA:\n";
    echo "   - rekord=JEST, wiadomosci>=1  -> korelacja OK (hipoteza A potwierdzona, panel zadziala)\n";
    echo "   - rekord=BRAK                 -> rozmowa nie powstala = klik bez wiadomosci (A, naturalne)\n";
    echo "   - rekord=JEST, wiadomosci=0   -> rozmowa pusta (user otworzyl, nic nie napisal — A)\n";
    echo "   - jesli w 4a SA swieze rozmowy z wiadomosciami, ktorych sid NIE pasuje\n";
    echo "     do zadnego klika z tej samej pory -> trop B (rozjazd sid front<->backend)\n";
}
echo "\n=== koniec ===\n";
