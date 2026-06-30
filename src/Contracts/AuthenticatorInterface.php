<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Contracts;

use GtelPhp\Vault\Auth\VaultToken;

/**
 * Anything that can produce a {@see VaultToken} given credentials known to
 * the implementation (AppRole role_id/secret_id today, other auth backends
 * in the future) implements this contract.
 */
interface AuthenticatorInterface
{
    /**
     * Perform a fresh login against the auth backend and return a token.
     */
    public function login(): VaultToken;

    /**
     * Renew an existing token. Implementations that do not support renewal
     * should throw a TokenExpiredException so the caller can re-login.
     */
    public function renew(VaultToken $token): VaultToken;

    /**
     * Unique name of the auth mount/method, e.g. "approle".
     */
    public function name(): string;
}
