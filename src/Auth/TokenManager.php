<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Auth;

use GtelPhp\Vault\Contracts\AuthenticatorInterface;
use GtelPhp\Vault\Contracts\TokenCacheInterface;
use GtelPhp\Vault\Exceptions\TokenExpiredException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Sits between the {@see AuthenticatorInterface} and the rest of the SDK.
 *
 * Responsible for:
 *  - returning a cached token when it's still healthy
 *  - transparently renewing it once it crosses the renewal threshold
 *  - falling back to a fresh login when renewal isn't possible/fails
 *  - persisting the result back into the cache
 *
 * This is the single source of truth {@see \GtelPhp\Vault\Client} asks for
 * "give me a valid token" - callers never need to think about expiry.
 */
final class TokenManager
{
    public function __construct(
        private readonly AuthenticatorInterface $authenticator,
        private readonly TokenCacheInterface $cache,
        private readonly string $cacheKey = 'default',
        private readonly float $renewThreshold = 0.7,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Returns a valid (non-expired) client token, transparently logging in
     * or renewing as needed.
     */
    public function getToken(): VaultToken
    {
        $token = $this->cache->get($this->cacheKey);

        if ($token === null) {
            return $this->login();
        }

        if ($token->isExpired()) {
            $this->logger()->info('Cached Vault token expired, logging in again.');

            return $this->login();
        }

        if ($token->shouldRenew($this->renewThreshold)) {
            return $this->tryRenew($token);
        }

        return $token;
    }

    /**
     * Forces a fresh login, bypassing the cache entirely.
     */
    public function login(): VaultToken
    {
        $token = $this->authenticator->login();
        $this->cache->put($this->cacheKey, $token);
        $this->logger()->info('Logged in to Vault via ' . $this->authenticator->name() . '.');

        return $token;
    }

    /**
     * Invalidate the cached token, forcing the next getToken() call to log in again.
     */
    public function forget(): void
    {
        $this->cache->forget($this->cacheKey);
    }

    private function tryRenew(VaultToken $token): VaultToken
    {
        try {
            $renewed = $this->authenticator->renew($token);
            $this->cache->put($this->cacheKey, $renewed);
            $this->logger()->info('Renewed Vault token.');

            return $renewed;
        } catch (TokenExpiredException|Throwable $e) {
            $this->logger()->warning('Failed to renew Vault token, logging in again.', [
                'error' => $e->getMessage(),
            ]);

            return $this->login();
        }
    }

    private function logger(): LoggerInterface
    {
        return $this->logger ?? new NullLogger();
    }
}
