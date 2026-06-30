<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Tests\Unit\Http;

use GtelPhp\Vault\Auth\AppRoleAuthenticator;
use GtelPhp\Vault\Auth\MemoryTokenCache;
use GtelPhp\Vault\Auth\TokenManager;
use GtelPhp\Vault\Http\HttpClient;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Regression test for a bug where attaching a token provider that itself
 * triggers a login (which reuses the same HttpClient instance) caused
 * infinite recursion: buildHeaders() -> tokenProvider() -> getToken() ->
 * login() -> http->post() -> buildHeaders() -> ... until the call stack
 * exhausted available memory.
 *
 * We can't easily hit a real Vault server here, but we can prove the
 * reentrancy guard works by attaching a token provider that calls back
 * into the very same HttpClient and asserting it terminates with a
 * bounded number of calls instead of recursing forever.
 */
final class HttpClientTokenProviderRecursionTest extends TestCase
{
    public function test_token_provider_that_triggers_a_request_does_not_recurse_infinitely(): void
    {
        $http = new HttpClient(baseUri: 'http://vault.invalid:8200', timeout: 0.01, maxRetries: 0);

        $callCount = 0;

        // Simulate TokenManager::getToken() calling back into the same
        // HttpClient (as Client does via AppRoleAuthenticator) - it should
        // be allowed to make ONE real request without looping forever.
        $http->withTokenProvider(function () use ($http, &$callCount): ?string {
            $callCount++;

            if ($callCount > 1) {
                self::fail('Token provider was invoked recursively - the reentrancy guard failed.');
            }

            try {
                // This goes through buildHeaders() again internally; with
                // the guard in place it must NOT call the token provider
                // a second time.
                $http->post('v1/auth/approle/login', ['role_id' => 'r', 'secret_id' => 's']);
            } catch (\Throwable) {
                // Expected: no real Vault server at vault.invalid. We only
                // care that this did not recurse / exhaust memory.
            }

            return 'irrelevant-because-call-count-check-happens-first';
        });

        try {
            $http->get('v1/secret/data/anything');
        } catch (\Throwable) {
            // Network call will fail (no real server) - that's fine, the
            // point of this test is purely the recursion guard.
        }

        self::assertSame(1, $callCount, 'Token provider should be invoked exactly once per outer request.');
    }

    public function test_is_resolving_token_flag_resets_after_resolution(): void
    {
        $http = new HttpClient(baseUri: 'http://vault.invalid:8200', timeout: 0.01, maxRetries: 0);
        $http->withTokenProvider(fn () => 'static-token');

        try {
            $http->get('v1/secret/data/a');
        } catch (\Throwable) {
        }

        $property = new ReflectionProperty(HttpClient::class, 'isResolvingToken');
        $property->setAccessible(true);

        self::assertFalse($property->getValue($http), 'Guard flag must be reset to false after resolving.');
    }

    public function test_full_login_flow_through_token_manager_terminates(): void
    {
        // End-to-end style check using the real collaborators (minus a real
        // Vault server) to make sure Client's wiring pattern - attaching a
        // token provider that calls TokenManager::getToken(), which in turn
        // calls AppRoleAuthenticator::login() using the *same* HttpClient -
        // terminates instead of hanging/exhausting memory.
        $http = new HttpClient(baseUri: 'http://vault.invalid:8200', timeout: 0.01, maxRetries: 0);
        $authenticator = new AppRoleAuthenticator($http, 'role-id', 'secret-id');
        $tokenManager = new TokenManager($authenticator, new MemoryTokenCache());

        $http->withTokenProvider(function () use ($tokenManager): ?string {
            try {
                return $tokenManager->getToken()->clientToken;
            } catch (\Throwable) {
                return null;
            }
        });

        $start = microtime(true);

        try {
            $http->get('v1/secret/data/anything');
        } catch (\Throwable) {
            // Expected without a real Vault server - the assertion below is what matters.
        }

        self::assertLessThan(5.0, microtime(true) - $start, 'Request should fail fast, not hang in infinite recursion.');
    }
}
