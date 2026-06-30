<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Auth;

use GtelPhp\Vault\Contracts\TokenCacheInterface;
use Psr\SimpleCache\CacheInterface;
use Redis;
use RedisException;
use Throwable;

/**
 * Persists Vault tokens in Redis so they survive across requests/workers.
 *
 * Accepts either a raw {@see \Redis} (ext-redis) instance or any PSR-16
 * {@see CacheInterface} implementation (e.g. symfony/cache, illuminate
 * cache's Redis store wrapped as PSR-16), whichever you already have
 * configured in your application.
 */
final class RedisTokenCache implements TokenCacheInterface
{
    public function __construct(
        private readonly Redis|CacheInterface $client,
        private readonly string $prefix = 'vault:token:',
    ) {
    }

    public function get(string $key): ?VaultToken
    {
        try {
            $raw = $this->client instanceof Redis
                ? $this->client->get($this->prefixed($key))
                : $this->client->get($this->prefixed($key));
        } catch (RedisException|Throwable) {
            return null;
        }

        if ($raw === false || $raw === null) {
            return null;
        }

        $decoded = json_decode((string) $raw, true);

        if (!is_array($decoded)) {
            return null;
        }

        return VaultToken::fromArray($decoded);
    }

    public function put(string $key, VaultToken $token): void
    {
        $payload = json_encode($token->toArray(), JSON_THROW_ON_ERROR);
        $ttl = max($token->leaseDuration, 60);

        if ($this->client instanceof Redis) {
            $this->client->set($this->prefixed($key), $payload, ['EX' => $ttl]);

            return;
        }

        $this->client->set($this->prefixed($key), $payload, $ttl);
    }

    public function forget(string $key): void
    {
        if ($this->client instanceof Redis) {
            $this->client->del($this->prefixed($key));

            return;
        }

        $this->client->delete($this->prefixed($key));
    }

    private function prefixed(string $key): string
    {
        return $this->prefix . $key;
    }
}
