<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Tests\Unit\Database;

use GtelPhp\Vault\Database\DatabaseSecrets;
use GtelPhp\Vault\Exceptions\DatabaseSecretsException;
use GtelPhp\Vault\Exceptions\VaultException;
use GtelPhp\Vault\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class DatabaseSecretsTest extends TestCase
{
    public function test_credentials_returns_username_and_password(): void
    {
        $http = new FakeHttpClient();
        $http->queue('GET', 'v1/database/creds/postgres', [
            'lease_id' => 'database/creds/postgres/abc',
            'lease_duration' => 3600,
            'renewable' => true,
            'data' => ['username' => 'v-app-postgres-x', 'password' => 'super-secret'],
        ]);

        $db = new DatabaseSecrets($http, 'database');
        $creds = $db->credentials('postgres');

        self::assertSame('v-app-postgres-x', $creds['username']);
        self::assertSame('super-secret', $creds['password']);
        self::assertSame(3600, $creds['lease_duration']);
        self::assertTrue($creds['renewable']);
    }

    public function test_credentials_throws_when_username_missing(): void
    {
        $http = new FakeHttpClient();
        $http->queue('GET', 'v1/database/creds/postgres', ['data' => []]);

        $db = new DatabaseSecrets($http, 'database');

        $this->expectException(DatabaseSecretsException::class);
        $db->credentials('postgres');
    }

    public function test_credentials_failure_is_wrapped(): void
    {
        $http = new FakeHttpClient();
        $http->queue('GET', 'v1/database/creds/postgres', new VaultException('role not found'));

        $db = new DatabaseSecrets($http, 'database');

        $this->expectException(DatabaseSecretsException::class);
        $db->credentials('postgres');
    }

    public function test_revoke_lease_calls_sys_leases_revoke(): void
    {
        $http = new FakeHttpClient();
        $http->queue('PUT', 'v1/sys/leases/revoke', []);

        $db = new DatabaseSecrets($http, 'database');
        $db->revokeLease('database/creds/postgres/abc');

        self::assertSame(['lease_id' => 'database/creds/postgres/abc'], $http->calls[0]['payload']);
    }
}
