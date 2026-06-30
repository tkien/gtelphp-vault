<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Tests\Unit\KV;

use GtelPhp\Vault\Exceptions\KvException;
use GtelPhp\Vault\KV\KvV2;
use GtelPhp\Vault\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class KvV2Test extends TestCase
{
    public function test_get_unwraps_data_data(): void
    {
        $http = new FakeHttpClient();
        $http->queue('GET', 'v1/secret/data/database', [
            'data' => [
                'data' => ['username' => 'app', 'password' => 'secret'],
                'metadata' => ['version' => 2, 'deletion_time' => ''],
            ],
        ]);

        $kv = new KvV2($http, 'secret');

        self::assertSame(['username' => 'app', 'password' => 'secret'], $kv->get('database'));
    }

    public function test_get_throws_when_secret_has_been_deleted(): void
    {
        $http = new FakeHttpClient();
        $http->queue('GET', 'v1/secret/data/database', [
            'data' => [
                'data' => ['username' => 'app'],
                'metadata' => ['deletion_time' => '2024-01-01T00:00:00Z'],
            ],
        ]);

        $kv = new KvV2($http, 'secret');

        $this->expectException(KvException::class);
        $kv->get('database');
    }

    public function test_get_value_returns_default_when_secret_missing(): void
    {
        $http = new FakeHttpClient();
        $http->queue('GET', 'v1/secret/data/missing', new \GtelPhp\Vault\Exceptions\VaultException('not found'));

        $kv = new KvV2($http, 'secret');

        self::assertSame('fallback', $kv->getValue('missing', 'key', 'fallback'));
    }

    public function test_put_sends_data_wrapped_and_returns_metadata(): void
    {
        $http = new FakeHttpClient();
        $http->queue('POST', 'v1/secret/data/database', [
            'data' => ['version' => 3, 'created_time' => '2024-01-01T00:00:00Z'],
        ]);

        $kv = new KvV2($http, 'secret');
        $metadata = $kv->put('database', ['username' => 'app']);

        self::assertSame(['data' => ['username' => 'app']], $http->calls[0]['payload']);
        self::assertSame(3, $metadata['version']);
    }

    public function test_put_with_cas_includes_options(): void
    {
        $http = new FakeHttpClient();
        $http->queue('POST', 'v1/secret/data/database', ['data' => ['version' => 4]]);

        $kv = new KvV2($http, 'secret');
        $kv->put('database', ['username' => 'app'], casVersion: 3);

        self::assertSame(
            ['data' => ['username' => 'app'], 'options' => ['cas' => 3]],
            $http->calls[0]['payload'],
        );
    }

    public function test_delete_without_versions_uses_data_endpoint(): void
    {
        $http = new FakeHttpClient();
        $http->queue('DELETE', 'v1/secret/data/database', []);

        $kv = new KvV2($http, 'secret');
        $kv->delete('database');

        self::assertSame('DELETE', $http->calls[0]['method']);
        self::assertSame('v1/secret/data/database', $http->calls[0]['path']);
    }

    public function test_delete_with_versions_uses_delete_endpoint(): void
    {
        $http = new FakeHttpClient();
        $http->queue('POST', 'v1/secret/delete/database', []);

        $kv = new KvV2($http, 'secret');
        $kv->delete('database', [1, 2]);

        self::assertSame(['versions' => [1, 2]], $http->calls[0]['payload']);
    }

    public function test_list_returns_keys(): void
    {
        $http = new FakeHttpClient();
        $http->queue('LIST', 'v1/secret/metadata/', [
            'data' => ['keys' => ['database', 'api/']],
        ]);

        $kv = new KvV2($http, 'secret');

        self::assertSame(['database', 'api/'], $kv->list());
    }

    public function test_metadata_returns_data_section(): void
    {
        $http = new FakeHttpClient();
        $http->queue('GET', 'v1/secret/metadata/database', [
            'data' => ['current_version' => 3, 'max_versions' => 0],
        ]);

        $kv = new KvV2($http, 'secret');

        self::assertSame(['current_version' => 3, 'max_versions' => 0], $kv->metadata('database'));
    }

    public function test_kv_exceptions_wrap_underlying_vault_exceptions(): void
    {
        $http = new FakeHttpClient();
        $http->queue('POST', 'v1/secret/data/database', new \GtelPhp\Vault\Exceptions\VaultException('permission denied'));

        $kv = new KvV2($http, 'secret');

        $this->expectException(KvException::class);
        $this->expectExceptionMessageMatches('/permission denied/');
        $kv->put('database', ['a' => 'b']);
    }
}
