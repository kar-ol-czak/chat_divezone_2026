<?php

declare(strict_types=1);

namespace DiveChat\Chip;

use DiveChat\Database\PostgresConnection;

/**
 * Dynamiczne zrodlo etykiet przyciskow target:ai z drzewa chipow (CHAT-T-122,
 * ADR-110 korekta 18a).
 *
 * Zastapilo stala liste (usunieta klasa ChipButtonLabels): labele target:ai
 * zmieniaja sie z rozwojem drzewa, a stala lista rozjezdzala sie cicho przy
 * kazdej zmianie seeda. Tu czytamy AKTUALNY stan z divechat_chip_nodes — panel
 * pomija realne, biezace etykiety przyciskow w wyborze tytulu rozmowy. Historyczne
 * rozmowy z wycofanymi labelami swiadomie poza zakresem (decyzja Karola 18a:
 * priorytet = odpornosc na przyszle zmiany, nie ochrona starych rekordow).
 *
 * Uzycie: pobrac RAZ na zadanie (fetchLabels), a wynik — jako parametr PG-array
 * `<> ALL(?::text[])` (toPgTextArray) — wstrzyknac do zapytania listujacego.
 * Pusta lista -> warunek wykluczenia pomijany (NIE generowac `ALL('{}')`).
 */
final class ChipAiLabelProvider
{
    /**
     * Zwraca DISTINCT etykiety przyciskow z target='ai' w calym drzewie chipow.
     * Odporne na buttons NULL / nie-tablice (filtr jsonb_typeof w podzapytaniu —
     * jsonb_array_elements na obiekcie/skalarze rzuca blad, wiec filtrujemy PRZED
     * rozbiciem tablicy).
     *
     * @return list<string>
     */
    public static function fetchLabels(PostgresConnection $db): array
    {
        $rows = $db->fetchAll(
            "SELECT DISTINCT btn->>'label' AS label
               FROM (SELECT buttons FROM divechat_chip_nodes
                      WHERE jsonb_typeof(buttons) = 'array') n,
                    jsonb_array_elements(n.buttons) btn
              WHERE btn->>'target' = 'ai'
                AND btn->>'label' IS NOT NULL",
        );

        $labels = [];
        foreach ($rows as $row) {
            $label = $row['label'] ?? null;
            if (is_string($label) && $label !== '') {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * Serializuje liste stringow do literalu tablicy tekstowej PostgreSQL
     * (`{"a","b"}`) — do zbindowania jako `?::text[]`. Kazdy element w cudzyslowie
     * z eskejpem `\` i `"` (bezpieczne dla dowolnej tresci etykiety, w tym przecinkow,
     * nawiasow klamrowych, polskich znakow).
     *
     * @param list<string> $labels
     */
    public static function toPgTextArray(array $labels): string
    {
        $quoted = array_map(
            static function (string $label): string {
                $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $label);
                return '"' . $escaped . '"';
            },
            $labels,
        );

        return '{' . implode(',', $quoted) . '}';
    }
}
