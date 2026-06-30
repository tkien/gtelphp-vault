<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Tests\Unit\Auth;

use GtelPhp\Vault\Auth\MemoryTokenCache;
use GtelPhp\Vault\Auth\TokenManager;
use GtelPhp\Vault\Auth\VaultToken;
use GtelPhp\Vault\Contracts\AuthenticatorInterface;
use GtelPhp\Vault\Exceptions\TokenExpiredException;
use PHPUnit\Framework\TestCase;

final class TokenManagerTest extends TestCase
{
    public function test_it_logs_in_when_cache_is_empty(): void
    {
        $authenticator = $this->fakeAuthenticator(loginToken: $this->freshToken());
        $manager = new TokenManager($authenticator, new MemoryTokenCache());

        $token = $manager->getToken();

        self::assertSame('s.fresh', $token->clientToken);
    }

    public function test_it_returns_cached_token_when_still_healthy(): void
    {
        $cache = new MemoryTokenCache();
        $cache->put('default', $this->freshToken());

        $authenticator = $this->fakeAuthenticator(loginToken: null); // should never be called
        $manager = new TokenManager($authenticator, $cache);

        $token = $manager->getToken();

        self::assertSame('s.fresh', $token->clientToken);
    }

    public function test_it_renews_when_past_the_threshold(): void
    {
        $cache = new MemoryTokenCache();
        $cache->put('default', new VaultToken('s.old', 100, true, time() - 80));

        $renewed = new VaultToken('s.renewed', 100, true, time());
        $authenticator = $this->fakeAuthenticator(renewToken: $renewed);

        $manager = new TokenManager($authenticator, $cache, renewThreshold: 0.7);
        $token = $manager->getToken();

        self::assertSame('s.renewed', $token->clientToken);
        self::assertSame('s.renewed', $cache->get('default')?->clientToken);
    }

    public function test_it_falls_back_to_login_when_renew_fails(): void
    {
        $cache = new MemoryTokenCache();
        $cache->put('default', new VaultToken('s.old', 100, true, time() - 80));

        $authenticator = $this->fakeAuthenticator(
            renewThrows: new TokenExpiredException('renew failed'),
            loginToken: $this->freshToken(),
        );

        $manager = new TokenManager($authenticator, $cache, renewThreshold: 0.7);
        $token = $manager->getToken();

        self::assertSame('s.fresh', $token->clientToken);
    }

    public function test_it_logs_in_again_when_cached_token_already_expired(): void
    {
        $cache = new MemoryTokenCache();
        $cache->put('default', new VaultToken('s.expired', 10, true, time() - 100));

        $authenticator = $this->fakeAuthenticator(loginToken: $this->freshToken());
        $manager = new TokenManager($authenticator, $cache);

        $token = $manager->getToken();

        self::assertSame('s.fresh', $token->clientToken);
    }

    public function test_forget_clears_the_cache(): void
    {
        $cache = new MemoryTokenCache();
        $cache->put('default', $this->freshToken());

        $manager = new TokenManager($this->fakeAuthenticator(), $cache);
        $manager->forget();

        self::assertNull($cache->get('default'));
    }

    private function freshToken(): VaultToken
    {
        return new VaultToken('s.fresh', 3600, true, time());
    }

    private function fakeAuthenticator(
        ?VaultToken $loginToken = null,
        ?VaultToken $renewToken = null,
        ?\Throwable $renewThrows = null,
    ): AuthenticatorInterface {
        return new class ($loginToken, $renewToken, $renewThrows) implements AuthenticatorInterface {
            public function __construct(
                private readonly ?VaultToken $loginToken,
                private readonly ?VaultToken $renewToken,
                private readonly ?\Throwable $renewThrows,
            ) {
            }

            public function login(): VaultToken
            {
                if ($this->loginToken === null) {
                    throw new \LogicException('login() should not have been called');
                }

                return $this->loginToken;
            }

            public function renew(VaultToken $token): VaultToken
            {
                if ($this->renewThrows !== null) {
                    throw $this->renewThrows;
                }

                if ($this->renewToken === null) {
                    throw new \LogicException('renew() should not have been called');
                }

                return $this->renewToken;
            }

            public function name(): string
            {
                return 'fake';
            }
        };
    }
}
