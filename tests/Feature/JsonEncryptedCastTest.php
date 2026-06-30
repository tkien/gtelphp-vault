<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Tests\Feature;

use GtelPhp\Vault\Client;
use GtelPhp\Vault\Contracts\HttpClientInterface;
use GtelPhp\Vault\Laravel\Casts\JsonEncryptedCast;
use GtelPhp\Vault\Support\VaultConfig;
use GtelPhp\Vault\Tests\Support\FakeHttpClient;
use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\TestCase;

/**
 * @covers \GtelPhp\Vault\Laravel\Casts\JsonEncryptedCast
 */
final class JsonEncryptedCastTest extends TestCase
{
    private FakeHttpClient $http;

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('vault.casts.transit_key', 'app');

        $this->http = new FakeHttpClient();

        $config = new VaultConfig(address: 'http://127.0.0.1:8200', roleId: 'r', secretId: 's');

        $client = new Client($config, $this->http);

        $app->instance(Client::class, $client);
        $app->bind(HttpClientInterface::class, fn () => $this->http);
    }

    public function test_set_encrypts_only_selected_keys_recursively(): void
    {
        $this->http->queue('POST', 'v1/transit/encrypt/app', ['data' => ['ciphertext' => 'vault:v1:enc-name']]);

        $cast = new JsonEncryptedCast('name', 'address.street');
        $model = new Model();

        $stored = $cast->set($model, 'sender_info', [
            'name' => 'John',
            'province_code' => '01',
            'address' => ['street' => 'placeholder', 'detail' => 'unit 5'],
        ], []);

        $decoded = json_decode($stored, true);

        self::assertSame('01', $decoded['province_code']);
        self::assertSame('unit 5', $decoded['address']['detail']);
        // Both encrypted fields hit the same fake response in this test.
        self::assertStringStartsWith('vault:v1:', $decoded['name']);
    }

    public function test_set_does_not_double_encrypt_already_encrypted_values(): void
    {
        $cast = new JsonEncryptedCast('name');
        $model = new Model();

        $stored = $cast->set($model, 'sender_info', [
            'name' => 'vault:v1:already-encrypted',
        ], []);

        $decoded = json_decode($stored, true);

        self::assertSame('vault:v1:already-encrypted', $decoded['name']);
        self::assertCount(0, $this->http->calls);
    }

    public function test_get_decrypts_only_ciphertext_values(): void
    {
        $this->http->queue('POST', 'v1/transit/decrypt/app', ['data' => ['plaintext' => base64_encode('John')]]);

        $cast = new JsonEncryptedCast('name', 'province_code');
        $model = new Model();

        $result = $cast->get($model, 'sender_info', json_encode([
            'name' => 'vault:v1:enc-name',
            'province_code' => '01', // not encrypted, should pass through untouched
        ]), []);

        self::assertSame('John', $result['name']);
        self::assertSame('01', $result['province_code']);
    }

    public function test_null_value_passes_through_untouched(): void
    {
        $cast = new JsonEncryptedCast('name');
        $model = new Model();

        self::assertNull($cast->get($model, 'sender_info', null, []));
        self::assertNull($cast->set($model, 'sender_info', null, []));
    }
}
