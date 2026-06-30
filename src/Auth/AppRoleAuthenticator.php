<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Auth;

use GtelPhp\Vault\Contracts\AuthenticatorInterface;
use GtelPhp\Vault\Contracts\HttpClientInterface;
use GtelPhp\Vault\Exceptions\AuthenticationException;
use GtelPhp\Vault\Exceptions\TokenExpiredException;
use GtelPhp\Vault\Exceptions\VaultException;

/**
 * Authenticates against Vault/OpenBao's AppRole auth method.
 *
 * @see https://developer.hashicorp.com/vault/docs/auth/approle
 */
final class AppRoleAuthenticator implements AuthenticatorInterface
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $roleId,
        private readonly string $secretId,
        private readonly string $mountPath = 'approle',
    ) {
    }

    public function name(): string
    {
        return 'approle';
    }

    public function login(): VaultToken
    {
        try {
            $response = $this->http->post(sprintf('v1/auth/%s/login', trim($this->mountPath, '/')), [
                'role_id' => $this->roleId,
                'secret_id' => $this->secretId,
            ]);
        } catch (VaultException $e) {
            throw new AuthenticationException(
                sprintf('AppRole login failed: %s', $e->getMessage()),
                $e->context(),
                $e->getCode(),
                $e,
            );
        }

        $auth = $response['auth'] ?? null;

        if (!is_array($auth) || empty($auth['client_token'])) {
            throw new AuthenticationException('AppRole login response did not contain a client token.', [
                'response' => $response,
            ]);
        }

        return VaultToken::fromAuthResponse($auth);
    }

    public function renew(VaultToken $token): VaultToken
    {
        if (!$token->renewable) {
            throw new TokenExpiredException('Current Vault token is not renewable; a fresh login is required.');
        }

        try {
            $payload = $token->leaseDuration > 0 ? ['increment' => $token->leaseDuration] : [];
            $response = $this->http->post('v1/auth/token/renew-self', $payload);
        } catch (VaultException $e) {
            throw new TokenExpiredException(
                sprintf('Failed to renew Vault token: %s', $e->getMessage()),
                $e->context(),
                $e->getCode(),
                $e,
            );
        }

        $auth = $response['auth'] ?? null;

        if (!is_array($auth) || empty($auth['client_token'])) {
            throw new TokenExpiredException('Token renewal response did not contain a client token.', [
                'response' => $response,
            ]);
        }

        return VaultToken::fromAuthResponse($auth);
    }
}
