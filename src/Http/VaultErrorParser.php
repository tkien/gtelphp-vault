<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Http;

/**
 * Helper for translating raw Vault/OpenBao JSON error bodies into a clean
 * list of human-readable messages. Vault returns:
 *   { "errors": ["permission denied"] }
 */
final class VaultErrorParser
{
    /**
     * @return string[]
     */
    public static function parse(string $body): array
    {
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return $body !== '' ? [$body] : [];
        }

        if (isset($decoded['errors']) && is_array($decoded['errors'])) {
            return array_map(static fn ($e) => is_string($e) ? $e : json_encode($e), $decoded['errors']);
        }

        return [];
    }

    public static function message(string $body, int $statusCode): string
    {
        $errors = self::parse($body);

        if ($errors === []) {
            return sprintf('Vault request failed with HTTP status %d.', $statusCode);
        }

        return sprintf('Vault request failed with HTTP status %d: %s', $statusCode, implode('; ', $errors));
    }
}
