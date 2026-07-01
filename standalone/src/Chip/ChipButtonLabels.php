<?php

declare(strict_types=1);

namespace DiveChat\Chip;

/**
 * Znane etykiety przyciskow chipow z target:ai (CHAT-T-122, ADR-110 pkt 5).
 *
 * Zrodlo: seed chipow (sql/029_chip_nodes_seed.sql, 032, 038 — buttons target='ai').
 * W STARYCH rozmowach klik przycisku target:ai zapisywal jego etykiete jako
 * pierwsza wiadomosc user, wiec panel recenzji bral ja za tytul rozmowy
 * ("Napisz czego szukasz" itp.) zamiast realnego pytania klienta (ADR-110 Problem).
 *
 * CELOWO stala lista, NIE odczyt z divechat_chip_nodes: ADR-110 pkt 2 usuwa te
 * przyciski z lisci w seedzie, wiec biezaca baza przestanie je zawierac — a fix
 * ma chronic STARE rozmowy (zero migracji danych messages). Statyczna lista jest
 * odporna na stan seeda i nie kosztuje round-tripu do Railway (istotne wg CHAT-T-113).
 */
final class ChipButtonLabels
{
    /**
     * Etykiety przyciskow target:ai wystepujace historycznie w seedzie chipow.
     * Zrodlo: sql/029_chip_nodes_seed.sql, sql/032_split_dobor.sql,
     * sql/038_chip_seed_level2_level3.sql (buttons target='ai').
     *
     * @var list<string>
     */
    public const TARGET_AI_LABELS = [
        'Napisz czego szukasz',
        'Inne pytanie',
        'Koszty i metody dostawy',
    ];

    /**
     * Buduje fragment SQL `<expr> NOT IN ('...','...')` wykluczajacy etykiety
     * target:ai z wyboru pierwszej wiadomosci user (fix tytulu panelu, ADR-110 pkt 5).
     *
     * Wartosci wstawiane jako LITERALY (nie parametry) celowo: unika przekladania
     * kolejnosci parametrow w kilku roznych zapytaniach (LIMIT/OFFSET po podzapytaniu).
     * Bezpieczne — to stale compile-time zdefiniowane w kodzie, NIE input uzytkownika;
     * apostrofy escapujemy dla higieny.
     *
     * @param string $contentExpr Wyrazenie SQL zwracajace tresc wiadomosci
     *   (np. "m->>'content'" dla jsonb albo "m.content" dla tabeli divechat_messages).
     */
    public static function notInSql(string $contentExpr): string
    {
        $quoted = array_map(
            static fn (string $label): string => "'" . str_replace("'", "''", $label) . "'",
            self::TARGET_AI_LABELS,
        );

        return $contentExpr . ' NOT IN (' . implode(', ', $quoted) . ')';
    }
}
