<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Tests\Unit\Auth;

use GtelPhp\Vault\Auth\AppRoleAuthenticator;
use GtelPhp\Vault\Exceptions\AuthenticationException;
use GtelPhp\Vault\Exceptions\TokenExpiredException;
use GtelPhp\Vault\Exceptions\VaultException;
use GtelPhp\Vault\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class AppRoleAuthenticatorTest extends TestCase
{
    public function test_successful_login_returns_a_vault_token(): void
    {
        $http = new FakeHttpClient();
        $http->queue('POST', 'v1/auth/approle/login', [
            'auth' => [
                'client_token' => 's.abc123',
                'lease_duration' => 3600,
                'renewable' => true,
                'policies' => ['default'],
            ],
        ]);

        $auth = new AppRoleAuthenticator($http, 'role-id', 'secret-id');
        $token = $auth->login();

        self::assertSame('s.abc123', $token->clientToken);
        self::assertCount(1, $http->calls);
        self::assertSame(['role_id' => 'role-id', 'secret_id' => 'secret-id'], $http->calls[0]['payload']);
    }

    public function test_login_failure_is_wrapped_in_authentication_exception(): void
    {
        $http = new FakeHttpClient();
        $http->queue('POST', 'v1/auth/approle/login', new VaultException('invalid role or secret ID'));

        $auth = new AppRoleAuthenticator($http, 'bad-role', 'bad-secret');

        $this->expectException(AuthenticationException::class);
        $auth->login();
    }

    public function test_login_response_missing_client_token_throws(): void
    {
        $http = new FakeHttpClient();
        $http->queue('POST', 'v1/auth/approle/login', ['auth' => []]);

        $auth = new AppRoleAuthenticator($http, 'role-id', 'secret-id');

        $this->expectException(AuthenticationException::class);
        $auth->login();
    }

    public function test_renew_returns_a_fresh_token(): void
    {
        $http = new FakeHttpClient();
        $http->queue('POST', 'v1/auth/token/renew-self', [
            'auth' => ['client_token' => 's.abc123', 'lease_duration' => 3600, 'renewable' => true],
        ]);

        $auth = new AppRoleAuthenticator($http, 'role-id', 'secret-id');
        $original = \GtelPhp\Vault\Auth\VaultToken::fromAuthResponse([
            'client_token' => 's.old', 'lease_duration' => 3600, 'renewable' => true,
        ]);

        $renewed = $auth->renew($original);

        self::assertSame('s.abc123', $renewed->clientToken);
    }

    public function test_renew_throws_token_expired_when_not_renewable(): void
    {
        $http = new FakeHttpClient();
        $auth = new AppRoleAuthenticator($http, 'role-id', 'secret-id');

        $token = \GtelPhp\Vault\Auth\VaultToken::fromAuthResponse([
            'client_token' => 's.old', 'lease_duration' => 3600, 'renewable' => false,
        ]);

        $this->expectException(TokenExpiredException::class);
        $auth->renew($token);
    }

    public function test_custom_mount_path_is_used(): void
    {
        $http = new FakeHttpClient();
        $http->queue('POST', 'v1/auth/my-approle/login', [
            'auth' => ['client_token' => 's.abc', 'lease_duration' => 60, 'renewable' => true],
        ]);

        $auth = new AppRoleAuthenticator($http, 'r', 's', mountPath: 'my-approle');
        $token = $auth->login();

        self::assertSame('s.abc', $token->clientToken);
    }
}
