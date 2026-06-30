<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Contracts;

use GtelPhp\Vault\Auth\VaultToken;

/**
 * Storage for the current Vault client token so we don't have to login on
 * every single request. Implementations: MemoryTokenCache, RedisTokenCache.
 */
interface TokenCacheInterface
{
    public function get(string $key): ?VaultToken;

    public function put(string $key, VaultToken $token): void;

    public function forget(string $key): void;
}
