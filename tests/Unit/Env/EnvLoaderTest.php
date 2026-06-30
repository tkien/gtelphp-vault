<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Tests\Unit\Env;

use GtelPhp\Vault\Env\EnvLoader;
use GtelPhp\Vault\KV\KvV2;
use GtelPhp\Vault\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class EnvLoaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        EnvLoader::clearCache();
        foreach (['APP_KEY', 'DB_PASSWORD', 'EXISTING_VAR'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach (['APP_KEY', 'DB_PASSWORD', 'EXISTING_VAR'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        EnvLoader::clearCache();
        parent::tearDown();
    }

    public function test_it_injects_secret_values_into_the_environment(): void
    {
        $http = new FakeHttpClient();
        $http->queue('GET', 'v1/secret/data/env', [
            'data' => [
                'data' => ['APP_KEY' => 'base64:xyz', 'DB_PASSWORD' => 's3cr3t'],
                'metadata' => ['deletion_time' => ''],
            ],
        ]);

        $loader = new EnvLoader(new KvV2($http, 'secret'));
        $applied = $loader->load('env', ['cache' => false]);

        self::assertSame('base64:xyz', getenv('APP_KEY'));
        self::assertSame('s3cr3t', getenv('DB_PASSWORD'));
        self::assertSame(['APP_KEY' => 'base64:xyz', 'DB_PASSWORD' => 's3cr3t'], $applied);
    }

    public function test_it_does_not_override_existing_variables_by_default(): void
    {
        putenv('EXISTING_VAR=already-set');
        $_ENV['EXISTING_VAR'] = 'already-set';

        $http = new FakeHttpClient();
        $http->queue('GET', 'v1/secret/data/env', [
            'data' => ['data' => ['EXISTING_VAR' => 'from-vault'], 'metadata' => ['deletion_time' => '']],
        ]);

        $loader = new EnvLoader(new KvV2($http, 'secret'));
        $applied = $loader->load('env', ['cache' => false]);

        self::assertSame('already-set', getenv('EXISTING_VAR'));
        self::assertSame([], $applied);
    }

    public function test_override_option_forces_overwrite(): void
    {
        putenv('EXISTING_VAR=already-set');
        $_ENV['EXISTING_VAR'] = 'already-set';

        $http = new FakeHttpClient();
        $http->queue('GET', 'v1/secret/data/env', [
            'data' => ['data' => ['EXISTING_VAR' => 'from-vault'], 'metadata' => ['deletion_time' => '']],
        ]);

        $loader = new EnvLoader(new KvV2($http, 'secret'));
        $applied = $loader->load('env', ['cache' => false, 'override' => true]);

        self::assertSame('from-vault', getenv('EXISTING_VAR'));
        self::assertSame(['EXISTING_VAR' => 'from-vault'], $applied);
    }

    public function test_prefix_option_filters_and_strips_keys(): void
    {
        $http = new FakeHttpClient();
        $http->queue('GET', 'v1/secret/data/env', [
            'data' => [
                'data' => ['APP_KEY' => 'value-a', 'OTHER_VAR' => 'value-b'],
                'metadata' => ['deletion_time' => ''],
            ],
        ]);

        $loader = new EnvLoader(new KvV2($http, 'secret'));
        $applied = $loader->load('env', ['cache' => false, 'prefix' => 'APP_']);

        self::assertSame(['KEY' => 'value-a'], $applied);
    }

    public function test_repeated_calls_use_in_process_cache_by_default(): void
    {
        $http = new FakeHttpClient();
        $http->queue('GET', 'v1/secret/data/env', [
            'data' => ['data' => ['APP_KEY' => 'value-a'], 'metadata' => ['deletion_time' => '']],
        ]);

        $loader = new EnvLoader(new KvV2($http, 'secret'));
        $loader->load('env');
        $loader->load('env');

        self::assertCount(1, $http->calls);
    }
}
