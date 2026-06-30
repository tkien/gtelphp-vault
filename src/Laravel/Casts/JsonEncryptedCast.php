<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Laravel\Casts;

use GtelPhp\Vault\Client;
use GtelPhp\Vault\Transit\Transit;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent cast that selectively encrypts individual keys inside a JSON /
 * jsonb column via Vault's Transit engine, leaving the rest of the
 * structure - and the column's jsonb type - completely intact.
 *
 *     protected $casts = [
 *         'sender_info' => JsonEncryptedCast::class . ':name,phone,address.street,address.detail',
 *     ];
 *
 * Only the listed keys (dot notation supported for nested paths) are ever
 * sent to Vault. Everything else in the structure passes through
 * untouched, and the column keeps being stored/read as a normal JSON
 * object - never as an opaque encrypted blob.
 *
 * Values already encrypted (i.e. already prefixed with the Vault
 * ciphertext marker "vault:v1:") are left as-is on write, so re-saving a
 * model that was just loaded from the database never double-encrypts.
 */
final class JsonEncryptedCast implements CastsAttributes
{
    private const CIPHERTEXT_PREFIX = 'vault:v';

    /** @var string[] */
    private readonly array $fields;

    public function __construct(string ...$fields)
    {
        $this->fields = $fields;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        $decoded = is_array($value) ? $value : json_decode((string) $value, true);

        if (!is_array($decoded)) {
            return null;
        }

        foreach ($this->fields as $path) {
            $current = $this->getByPath($decoded, $path);

            if (!is_string($current) || !$this->isCiphertext($current)) {
                continue;
            }

            $decoded = $this->setByPath($decoded, $path, $this->transit()->decrypt($this->keyName(), $current));
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $decoded = is_array($value) ? $value : json_decode((string) $value, true);

        if (!is_array($decoded)) {
            return is_string($value) ? $value : json_encode($value);
        }

        foreach ($this->fields as $path) {
            $current = $this->getByPath($decoded, $path);

            if ($current === null || $current === '') {
                continue;
            }

            if (!is_string($current)) {
                continue;
            }

            if ($this->isCiphertext($current)) {
                continue; // already encrypted, e.g. unchanged since last load
            }

            $decoded = $this->setByPath($decoded, $path, $this->transit()->encrypt($this->keyName(), $current));
        }

        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function isCiphertext(string $value): bool
    {
        return str_starts_with($value, self::CIPHERTEXT_PREFIX);
    }

    private function keyName(): string
    {
        return (string) (function_exists('config') ? config('vault.casts.transit_key', 'app') : 'app');
    }

    private function transit(): Transit
    {
        $client = function_exists('app') ? app(Client::class) : null;

        if (!$client instanceof Client) {
            throw new \RuntimeException(sprintf(
                '%s requires the Laravel container to resolve %s. Are you running outside of Laravel?',
                self::class,
                Client::class,
            ));
        }

        return $client->transit();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function getByPath(array $data, string $path): mixed
    {
        $segments = explode('.', $path);
        $cursor = $data;

        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function setByPath(array $data, string $path, mixed $value): array
    {
        $segments = explode('.', $path);
        $lastIndex = count($segments) - 1;
        $cursor = &$data;

        foreach ($segments as $i => $segment) {
            if ($i === $lastIndex) {
                $cursor[$segment] = $value;

                break;
            }

            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                // Path doesn't exist (or isn't traversable) - nothing to encrypt/decrypt.
                return $data;
            }

            $cursor = &$cursor[$segment];
        }

        return $data;
    }
}
