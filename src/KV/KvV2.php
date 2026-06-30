<?php

declare(strict_types=1);

namespace GtelPhp\Vault\KV;

use GtelPhp\Vault\Contracts\HttpClientInterface;
use GtelPhp\Vault\Exceptions\KvException;
use GtelPhp\Vault\Exceptions\VaultException;
use Psr\SimpleCache\CacheInterface;
use Redis;
use Throwable;

/**
 * Client for the KV version 2 secrets engine.
 *
 * KV v2 stores secrets under `<mount>/data/<path>` (with the actual values
 * nested in `data.data`) and metadata under `<mount>/metadata/<path>`. This
 * class hides that nesting so callers only ever deal with plain arrays.
 *
 * Optionally, pass a Redis/PSR-16 `$cache` to turn `get()` into a
 * read-through cache: repeated reads of the same path within `$cacheTtl`
 * seconds are served from cache instead of hitting Vault/OpenBao. Any
 * write (`put`, `patch`, `delete`, `destroy`, `undelete`,
 * `configureMetadata`) immediately invalidates that path's cache entry.
 * Leave `$cache` as `null` (the default) to keep the original, always-live
 * behaviour.
 *
 * @see https://developer.hashicorp.com/vault/docs/secrets/kv/kv-v2
 */
final class KvV2
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $mount = 'secret',
        private readonly Redis|CacheInterface|null $cache = null,
        private readonly int $cacheTtl = 300,
        private readonly string $cachePrefix = 'vault:kv:',
    ) {
    }

    /**
     * Read the current (or a specific) version of a secret.
     *
     * Versioned reads (`$version !== null`) are never cached, since a
     * pinned version is immutable anyway and caching it would be pointless.
     *
     * @return array<string, mixed>
     */
    public function get(string $path, ?int $version = null): array
    {
        if ($version !== null || $this->cache === null) {
            return $this->fetch($path, $version);
        }

        $key = $this->cacheKey($path);
        $cached = $this->readCache($key);

        if ($cached !== null) {
            return $cached;
        }

        $data = $this->fetch($path, null);
        $this->writeCache($key, $data);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $path, ?int $version): array
    {
        $query = $version !== null ? ['version' => $version] : [];

        try {
            $response = $this->http->get($this->dataPath($path), $query);
        } catch (VaultException $e) {
            throw new KvException(
                sprintf('Failed to read secret "%s": %s', $path, $e->getMessage()),
                $e->context(),
                $e->getCode(),
                $e,
            );
        }

        return $this->extractData($response, $path);
    }

    /**
     * Convenience helper returning a single key from a secret, or
     * $default if the secret/key doesn't exist.
     */
    public function getValue(string $path, string $key, mixed $default = null): mixed
    {
        try {
            $data = $this->get($path);
        } catch (KvException) {
            return $default;
        }

        return $data[$key] ?? $default;
    }

    /**
     * Write a brand new version of a secret.
     *
     * @param array<string, mixed> $data
     * @param int|null $casVersion When provided, the write only succeeds if the
     *                             current version matches (check-and-set).
     *
     * @return array<string, mixed> metadata about the newly created version
     */
    public function put(string $path, array $data, ?int $casVersion = null): array
    {
        $payload = ['data' => $data];

        if ($casVersion !== null) {
            $payload['options'] = ['cas' => $casVersion];
        }

        try {
            $response = $this->http->post($this->dataPath($path), $payload);
        } catch (VaultException $e) {
            throw new KvException(
                sprintf('Failed to write secret "%s": %s', $path, $e->getMessage()),
                $e->context(),
                $e->getCode(),
                $e,
            );
        }

        $this->forgetCache($path);

        return $response['data'] ?? [];
    }

    /**
     * Patch an existing secret, merging the given keys into the latest
     * version without clobbering keys you don't mention.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function patch(string $path, array $data): array
    {
        $current = $this->get($path);

        return $this->put($path, array_merge($current, $data));
    }

    /**
     * Soft-delete one or more versions (recoverable via undelete, subject
     * to the mount's configured `delete_version_after`).
     *
     * @param int[] $versions Empty array deletes only the current/latest version.
     */
    public function delete(string $path, array $versions = []): void
    {
        try {
            if ($versions === []) {
                $this->http->delete($this->dataPath($path));
                $this->forgetCache($path);

                return;
            }

            $this->http->post($this->path('delete', $path), ['versions' => $versions]);
            $this->forgetCache($path);
        } catch (VaultException $e) {
            throw new KvException(
                sprintf('Failed to delete secret "%s": %s', $path, $e->getMessage()),
                $e->context(),
                $e->getCode(),
                $e,
            );
        }
    }

    /**
     * Permanently destroy specific versions. This cannot be undone.
     *
     * @param int[] $versions
     */
    public function destroy(string $path, array $versions): void
    {
        try {
            $this->http->post($this->path('destroy', $path), ['versions' => $versions]);
            $this->forgetCache($path);
        } catch (VaultException $e) {
            throw new KvException(
                sprintf('Failed to destroy versions of secret "%s": %s', $path, $e->getMessage()),
                $e->context(),
                $e->getCode(),
                $e,
            );
        }
    }

    /**
     * Restore previously soft-deleted versions.
     *
     * @param int[] $versions
     */
    public function undelete(string $path, array $versions): void
    {
        try {
            $this->http->post($this->path('undelete', $path), ['versions' => $versions]);
            $this->forgetCache($path);
        } catch (VaultException $e) {
            throw new KvException(
                sprintf('Failed to undelete versions of secret "%s": %s', $path, $e->getMessage()),
                $e->context(),
                $e->getCode(),
                $e,
            );
        }
    }

    /**
     * Full metadata for a secret: current/oldest version numbers, per
     * version creation/deletion times, CAS config, etc.
     *
     * @return array<string, mixed>
     */
    public function metadata(string $path): array
    {
        try {
            $response = $this->http->get($this->path('metadata', $path));
        } catch (VaultException $e) {
            throw new KvException(
                sprintf('Failed to read metadata for secret "%s": %s', $path, $e->getMessage()),
                $e->context(),
                $e->getCode(),
                $e,
            );
        }

        return $response['data'] ?? [];
    }

    /**
     * List secret keys directly under a path (non-recursive), like `ls`.
     *
     * @return string[]
     */
    public function list(string $path = ''): array
    {
        try {
            $response = $this->http->list($this->path('metadata', $path));
        } catch (VaultException $e) {
            throw new KvException(
                sprintf('Failed to list secrets under "%s": %s', $path, $e->getMessage()),
                $e->context(),
                $e->getCode(),
                $e,
            );
        }

        return $response['data']['keys'] ?? [];
    }

    /**
     * Update only the metadata (max versions, CAS requirement, delete
     * version after) without touching secret data.
     *
     * @param array<string, mixed> $options
     */
    public function configureMetadata(string $path, array $options): void
    {
        try {
            $this->http->post($this->path('metadata', $path), $options);
            $this->forgetCache($path);
        } catch (VaultException $e) {
            throw new KvException(
                sprintf('Failed to update metadata for secret "%s": %s', $path, $e->getMessage()),
                $e->context(),
                $e->getCode(),
                $e,
            );
        }
    }

    /**
     * Manually invalidate the cached value for a path - useful if the
     * secret was changed by something other than this instance (another
     * process/pod, the Vault UI, `vault kv put` from the CLI, ...). No-op
     * when no cache was configured.
     */
    public function forget(string $path): void
    {
        $this->forgetCache($path);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCache(string $key): ?array
    {
        if ($this->cache === null) {
            return null;
        }

        try {
            $raw = $this->cache->get($key);
        } catch (Throwable) {
            return null;
        }

        if ($raw === false || $raw === null) {
            return null;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeCache(string $key, array $data): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            $payload = json_encode($data, JSON_THROW_ON_ERROR);

            if ($this->cache instanceof Redis) {
                $this->cache->set($key, $payload, ['EX' => $this->cacheTtl]);

                return;
            }

            $this->cache->set($key, $payload, $this->cacheTtl);
        } catch (Throwable) {
            // Best effort - if caching fails we simply fall back to calling
            // Vault every time, which is the original (uncached) behaviour.
        }
    }

    private function forgetCache(string $path): void
    {
        if ($this->cache === null) {
            return;
        }

        $key = $this->cacheKey($path);

        try {
            if ($this->cache instanceof Redis) {
                $this->cache->del($key);

                return;
            }

            $this->cache->delete($key);
        } catch (Throwable) {
            // Best effort - worst case the next read is stale until the TTL expires.
        }
    }

    private function cacheKey(string $path): string
    {
        return $this->cachePrefix . trim($path, '/');
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    private function extractData(array $response, string $path): array
    {
        $data = $response['data']['data'] ?? null;

        if ($data === null) {
            throw new KvException(sprintf('Secret "%s" not found or has no data.', $path), ['response' => $response]);
        }

        if (($response['data']['metadata']['deletion_time'] ?? '') !== '') {
            throw new KvException(sprintf('Secret "%s" has been deleted.', $path), ['response' => $response]);
        }

        return $data;
    }

    private function dataPath(string $path): string
    {
        return $this->path('data', $path);
    }

    private function path(string $segment, string $path): string
    {
        $path = trim($path, '/');

        return sprintf('v1/%s/%s/%s', trim($this->mount, '/'), $segment, $path);
    }
}
