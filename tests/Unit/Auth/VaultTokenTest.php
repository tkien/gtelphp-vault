<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Tests\Unit\Auth;

use GtelPhp\Vault\Auth\VaultToken;
use PHPUnit\Framework\TestCase;

final class VaultTokenTest extends TestCase
{
    public function test_it_builds_from_an_auth_response(): void
    {
        $token = VaultToken::fromAuthResponse([
            'client_token' => 's.abc123',
            'lease_duration' => 3600,
            'renewable' => true,
            'policies' => ['default', 'app'],
            'accessor' => 'acc-1',
        ]);

        self::assertSame('s.abc123', $token->clientToken);
        self::assertSame(3600, $token->leaseDuration);
        self::assertTrue($token->renewable);
        self::assertSame(['default', 'app'], $token->policies);
        self::assertSame('acc-1', $token->accessor);
    }

    public function test_it_is_not_expired_immediately_after_issuance(): void
    {
        $token = VaultToken::fromAuthResponse(['client_token' => 'x', 'lease_duration' => 3600, 'renewable' => true]);

        self::assertFalse($token->isExpired());
    }

    public function test_it_is_expired_once_lease_duration_has_elapsed(): void
    {
        $token = VaultToken::fromAuthResponse(['client_token' => 'x', 'lease_duration' => 60, 'renewable' => true]);

        self::assertTrue($token->isExpired(time() + 61));
    }

    public function test_non_expiring_tokens_never_expire(): void
    {
        $token = VaultToken::fromAuthResponse(['client_token' => 'root', 'lease_duration' => 0, 'renewable' => false]);

        self::assertFalse($token->isExpired(time() + 1_000_000));
    }

    public function test_should_renew_respects_threshold_and_renewable_flag(): void
    {
        $token = VaultToken::fromAuthResponse(['client_token' => 'x', 'lease_duration' => 100, 'renewable' => true]);

        self::assertFalse($token->shouldRenew(0.7, time() + 50));
        self::assertTrue($token->shouldRenew(0.7, time() + 71));
    }

    public function test_non_renewable_tokens_never_report_should_renew(): void
    {
        $token = VaultToken::fromAuthResponse(['client_token' => 'x', 'lease_duration' => 100, 'renewable' => false]);

        self::assertFalse($token->shouldRenew(0.7, time() + 99));
    }

    public function test_round_trips_through_array(): void
    {
        $token = VaultToken::fromAuthResponse([
            'client_token' => 's.abc123',
            'lease_duration' => 3600,
            'renewable' => true,
            'policies' => ['default'],
        ]);

        $restored = VaultToken::fromArray($token->toArray());

        self::assertSame($token->clientToken, $restored->clientToken);
        self::assertSame($token->leaseDuration, $restored->leaseDuration);
        self::assertSame($token->renewable, $restored->renewable);
        self::assertSame($token->policies, $restored->policies);
    }
}
