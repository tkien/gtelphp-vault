<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Auth;

use GtelPhp\Vault\Contracts\TokenCacheInterface;

/**
 * Simple in-memory token cache. Lives only for the duration of the current
 * PHP process/request, which is fine for short-lived CLI scripts but means
 * every web request will re-login unless paired with something persistent
 * like {@see RedisTokenCache}.
 */
final class MemoryTokenCache implements TokenCacheInterface
{
    /** @var array<string, VaultToken> */
    private array $tokens = [];

    public function get(string $key): ?VaultToken
    {
        return $this->tokens[$key] ?? null;
    }

    public function put(string $key, VaultToken $token): void
    {
        $this->tokens[$key] = $token;
    }

    public function forget(string $key): void
    {
        unset($this->tokens[$key]);
    }
}
