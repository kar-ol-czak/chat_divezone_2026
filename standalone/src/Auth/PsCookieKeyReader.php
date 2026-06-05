<?php

declare(strict_types=1);

namespace DiveChat\Auth;

/**
 * Odczyt _COOKIE_KEY_ z parameters.php PrestaShop 1.7 (CHAT-T-071, ADR-086).
 *
 * Sluzy WYLACZNIE legacy fallbackowi md5(_COOKIE_KEY_.password) dla kont
 * sprzed migracji 1.6 -> 1.7. Dzisiaj WSZYSCY pracownicy maja bcrypt
 * (zweryfikowane na PROD), wiec fallback jest defensywny — nie blokuje
 * MVP jesli readout padnie (np. open_basedir).
 *
 * Bezpieczenstwo:
 *  - cookie_key NIGDY nie loguje sie, nie wraca w API, nie jest cache'owany
 *    miedzy requestami (singleton zostaje w pamieci procesu PHP-FPM).
 *  - Pliku parameters.php nie czytamy regexem — uzywamy include i wyciagamy
 *    klucz z tablicy (PS 1.7 zwraca array z 'parameters' => [...]).
 *  - Brak pliku / open_basedir / brak klucza -> null (fallback nieaktywny).
 *  - Sciezka konfigurowalna przez PS_PARAMETERS_PATH (.env). Domyslnie
 *    derywujemy z PROJECT_ROOT — patrz resolveDefaultPath().
 */
final class PsCookieKeyReader
{
    private ?string $cachedKey = null;
    private bool $resolved = false;

    public function __construct(
        private readonly ?string $explicitPath = null,
        private readonly ?string $projectRoot = null,
    ) {}

    /**
     * Zwraca _COOKIE_KEY_ z parameters.php lub null gdy niedostepne.
     * Lazy — odczyt przy pierwszym wywolaniu, potem zwracamy cache.
     */
    public function getCookieKey(): ?string
    {
        if ($this->resolved) {
            return $this->cachedKey;
        }
        $this->resolved = true;

        $path = $this->explicitPath ?? $this->resolveDefaultPath();
        if ($path === null) {
            return null;
        }

        if (!is_file($path) || !is_readable($path)) {
            error_log('[PsCookieKeyReader] parameters.php niedostepny (fallback md5 nieaktywny): ' . $path);
            return null;
        }

        try {
            $data = @include $path;
        } catch (\Throwable $e) {
            error_log('[PsCookieKeyReader] include parameters.php padl: ' . $e->getMessage());
            return null;
        }

        if (!is_array($data)) {
            error_log('[PsCookieKeyReader] parameters.php nie zwrocil tablicy');
            return null;
        }

        $params = $data['parameters'] ?? null;
        if (!is_array($params)) {
            error_log('[PsCookieKeyReader] brak klucza "parameters" w parameters.php');
            return null;
        }

        $key = $params['cookie_key'] ?? null;
        if (!is_string($key) || $key === '') {
            error_log('[PsCookieKeyReader] brak / pusty cookie_key w parameters.php');
            return null;
        }

        $this->cachedKey = $key;
        return $this->cachedKey;
    }

    /**
     * Default na PROD: /home/<user>/public_html/newtmp2/app/config/parameters.php
     * (PS 1.7 w katalogu newtmp2 obok chat.divezone.pl). Sciezka derywowana
     * z projectRoot przekazanego w routes.php (dirname(dirname(__DIR__))).
     *
     * Lokalnie projectRoot to katalog repo — pliku nie ma, fallback nieaktywny
     * (zwroci null po is_file()). To OK: w sklepie ZERO kont z legacy md5,
     * realne logowania ida bcrypt.
     *
     * Gdy projectRoot nieznany — null (caller doloze explicitPath przez
     * PS_PARAMETERS_PATH w .env).
     */
    private function resolveDefaultPath(): ?string
    {
        if ($this->projectRoot === null) {
            return null;
        }
        return $this->projectRoot . '/newtmp2/app/config/parameters.php';
    }
}
