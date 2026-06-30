<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Database;

use GtelPhp\Vault\Contracts\HttpClientInterface;
use GtelPhp\Vault\Exceptions\DatabaseSecretsException;
use GtelPhp\Vault\Exceptions\VaultException;
use Psr\SimpleCache\CacheInterface;
use Redis;
use Throwable;

/**
 * Client for the Database secrets engine: short-lived, dynamically
 * generated database credentials.
 *
 * IMPORTANT: every call to {@see self::credentials()} asks Vault for a
 * brand new dynamic database user by default - calling it on every request
 * will create (and leak) a new DB user per request, which is both slow and
 * a great way to exhaust your database's connection/user limits. Pass a
 * `$cache` (Redis or any PSR-16 store) to turn this into a read-through
 * cache: the same role's credentials are reused until shortly before their
 * lease actually expires, then transparently refreshed.
 *
 * @see https://developer.hashicorp.com/vault/docs/secrets/databases
 */
final class DatabaseSecrets
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $mount = 'database',
        private readonly ?CacheInterface $cache = null,
        private readonly int $cacheSafetyMarginSeconds = 30,
        private readonly string $cachePrefix = 'vault:db:',
    ) {
    }

    /**
     * Request dynamic credentials for the given role. Cached (when a
     * `$cache` was provided) for the lease's own `lease_duration` minus a
     * small safety margin, so repeated calls reuse the same DB user instead
     * of minting a new one every time.
     *
     * @return array{username: string, password: string, lease_id: string, lease_duration: int, renewable: bool}
     */
    public function credentials(string $role): array
    {
        if ($this->cache !== null) {
            $cached = $this->readCache($role);

            if ($cached !== null) {
                return $cached;
            }
        }

        $creds = $this->fetchCredentials($role);

        if ($this->cache !== null) {
            $this->writeCache($role, $creds);
        }

        return $creds;
    }

    /**
     * Force a brand new lease for $role right now, bypassing (and
     * refreshing) the cache. Use sparingly - see the class docblock.
     *
     * @return array{username: string, password: string, lease_id: string, lease_duration: int, renewable: bool}
     */
    public function freshCredentials(string $role): array
    {
        $creds = $this->fetchCredentials($role);

        if ($this->cache !== null) {
            $this->writeCache($role, $creds);
        }

        return $creds;
    }

    /**
     * Manually invalidate the cached credentials for a role, e.g. after
     * manually revoking its lease.
     */
    public function forget(string $role): void
    {
        if ($this->cache === null) {
            return;
        }

        $key = $this->cacheKey($role);

        try {
            if ($this->cache instanceof Redis) {
                $this->cache->del($key);

                return;
            }

            $this->cache->delete($key);
        } catch (Throwable) {
            // Best effort - worst case the stale entry just lives until its own TTL expires.
        }
    }

    /**
     * @return array{username: string, password: string, lease_id: string, lease_duration: int, renewable: bool}
     */
    private function fetchCredentials(string $role): array
    {
        try {
            $response = $this->http->get(sprintf('v1/%s/creds/%s', trim($this->mount, '/'), $role));
        } catch (VaultException $e) {
            throw new DatabaseSecretsException(
                sprintf('Failed to generate database credentials for role "%s": %s', $role, $e->getMessage()),
                $e->context(),
                $e->getCode(),
                $e,
            );
        }

        $data = $response['data'] ?? null;

        if (!is_array($data) || empty($data['username'])) {
            throw new DatabaseSecretsException(sprintf('No credentials returned for database role "%s".', $role), [
                'response' => $response,
            ]);
        }

        return [
            'username' => (string) $data['username'],
            'password' => (string) ($data['password'] ?? ''),
            'lease_id' => (string) ($response['lease_id'] ?? ''),
            'lease_duration' => (int) ($response['lease_duration'] ?? 0),
            'renewable' => (bool) ($response['renewable'] ?? false),
        ];
    }

    /**
     * Request "static" credentials (for roles backed by a pre-existing
     * database user whose password Vault rotates on a schedule).
     *
     * @return array<string, mixed>
     */
    public function staticCredentials(string $role): array
    {
        try {
            $response = $this->http->get(sprintf('v1/%s/static-creds/%s', trim($this->mount, '/'), $role));
        } catch (VaultException $e) {
            throw new DatabaseSecretsException(
                sprintf('Failed to read static database credentials for role "%s": %s', $role, $e->getMessage()),
                $e->context(),
                $e->getCode(),
                $e,
            );
        }

        return $response['data'] ?? [];
    }

    /**
     * Force-rotate the root credentials Vault uses to manage a database
     * connection. Use with care: this changes the password Vault itself
     * authenticates with.
     */
    public function rotateRootCredentials(string $connectionName): void
    {
        try {
            $this->http->post(sprintf('v1/%s/rotate-root/%s', trim($this->mount, '/'), $connectionName));
        } catch (VaultException $e) {
            throw new DatabaseSecretsException(
                sprintf('Failed to rotate root credentials for connection "%s": %s', $connectionName, $e->getMessage()),
                $e->context(),
                $e->getCode(),
                $e,
            );
        }
    }

    /**
     * Revoke a previously issued lease ahead of its natural expiry.
     */
    public function revokeLease(string $leaseId): void
    {
        try {
            $this->http->put('v1/sys/leases/revoke', ['lease_id' => $leaseId]);
        } catch (VaultException $e) {
            throw new DatabaseSecretsException(
                sprintf('Failed to revoke lease "%s": %s', $leaseId, $e->getMessage()),
                $e->context(),
                $e->getCode(),
                $e,
            );
        }
    }

    /**
     * @return array{username: string, password: string, lease_id: string, lease_duration: int, renewable: bool}|null
     */
    private function readCache(string $role): ?array
    {
        try {
            $raw = $this->cache->get($this->cacheKey($role));
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
     * @param array{username: string, password: string, lease_id: string, lease_duration: int, renewable: bool} $creds
     */
    private function writeCache(string $role, array $creds): void
    {
        // A lease_duration of 0 typically means "doesn't expire" for static
        // roles, or that Vault didn't return one - either way, don't cache
        // something we can't safely expire.
        if ($creds['lease_duration'] <= 0) {
            return;
        }

        $ttl = max($creds['lease_duration'] - $this->cacheSafetyMarginSeconds, 30);

        try {
            $payload = json_encode($creds, JSON_THROW_ON_ERROR);

            if ($this->cache instanceof Redis) {
                $this->cache->set($this->cacheKey($role), $payload, ['EX' => $ttl]);

                return;
            }

            $this->cache->set($this->cacheKey($role), $payload, $ttl);
        } catch (Throwable) {
            // Best effort - if caching fails we simply fall back to fetching
            // a fresh lease every time, which is the original behaviour.
        }
    }

    private function cacheKey(string $role): string
    {
        return $this->cachePrefix . $role;
    }
}
