<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Tests\Unit\Transit;

use GtelPhp\Vault\Exceptions\TransitException;
use GtelPhp\Vault\Exceptions\VaultException;
use GtelPhp\Vault\Tests\Support\FakeHttpClient;
use GtelPhp\Vault\Transit\Transit;
use PHPUnit\Framework\TestCase;

final class TransitTest extends TestCase
{
    public function test_encrypt_base64_encodes_plaintext_and_returns_ciphertext(): void
    {
        $http = new FakeHttpClient();
        $http->queue('POST', 'v1/transit/encrypt/shipment', [
            'data' => ['ciphertext' => 'vault:v1:abc123'],
        ]);

        $transit = new Transit($http, 'transit');
        $ciphertext = $transit->encrypt('shipment', 'hello world');

        self::assertSame('vault:v1:abc123', $ciphertext);
        self::assertSame(base64_encode('hello world'), $http->calls[0]['payload']['plaintext']);
    }

    public function test_decrypt_base64_decodes_plaintext_back_to_original_string(): void
    {
        $http = new FakeHttpClient();
        $http->queue('POST', 'v1/transit/decrypt/shipment', [
            'data' => ['plaintext' => base64_encode('hello world')],
        ]);

        $transit = new Transit($http, 'transit');

        self::assertSame('hello world', $transit->decrypt('shipment', 'vault:v1:abc123'));
    }

    public function test_decrypt_throws_when_response_has_no_plaintext(): void
    {
        $http = new FakeHttpClient();
        $http->queue('POST', 'v1/transit/decrypt/shipment', ['data' => []]);

        $transit = new Transit($http, 'transit');

        $this->expectException(TransitException::class);
        $transit->decrypt('shipment', 'vault:v1:abc123');
    }

    public function test_encrypt_failure_is_wrapped_in_transit_exception(): void
    {
        $http = new FakeHttpClient();
        $http->queue('POST', 'v1/transit/encrypt/shipment', new VaultException('key not found'));

        $transit = new Transit($http, 'transit');

        $this->expectException(TransitException::class);
        $transit->encrypt('shipment', 'hello world');
    }

    public function test_encrypt_batch_returns_ciphertexts_in_order(): void
    {
        $http = new FakeHttpClient();
        $http->queue('POST', 'v1/transit/encrypt/shipment', [
            'data' => ['batch_results' => [
                ['ciphertext' => 'vault:v1:one'],
                ['ciphertext' => 'vault:v1:two'],
            ]],
        ]);

        $transit = new Transit($http, 'transit');
        $result = $transit->encryptBatch('shipment', ['one', 'two']);

        self::assertSame(['vault:v1:one', 'vault:v1:two'], $result);
    }

    public function test_decrypt_batch_returns_plaintexts_in_order(): void
    {
        $http = new FakeHttpClient();
        $http->queue('POST', 'v1/transit/decrypt/shipment', [
            'data' => ['batch_results' => [
                ['plaintext' => base64_encode('one')],
                ['plaintext' => base64_encode('two')],
            ]],
        ]);

        $transit = new Transit($http, 'transit');
        $result = $transit->decryptBatch('shipment', ['vault:v1:one', 'vault:v1:two']);

        self::assertSame(['one', 'two'], $result);
    }

    public function test_rewrap_returns_new_ciphertext(): void
    {
        $http = new FakeHttpClient();
        $http->queue('POST', 'v1/transit/rewrap/shipment', [
            'data' => ['ciphertext' => 'vault:v2:newcipher'],
        ]);

        $transit = new Transit($http, 'transit');

        self::assertSame('vault:v2:newcipher', $transit->rewrap('shipment', 'vault:v1:old'));
    }

    public function test_sign_returns_signature(): void
    {
        $http = new FakeHttpClient();
        $http->queue('POST', 'v1/transit/sign/shipment', [
            'data' => ['signature' => 'vault:v1:signature'],
        ]);

        $transit = new Transit($http, 'transit');

        self::assertSame('vault:v1:signature', $transit->sign('shipment', 'hello'));
    }

    public function test_verify_returns_boolean(): void
    {
        $http = new FakeHttpClient();
        $http->queue('POST', 'v1/transit/verify/shipment', ['data' => ['valid' => true]]);

        $transit = new Transit($http, 'transit');

        self::assertTrue($transit->verify('shipment', 'hello', 'vault:v1:signature'));
    }

    public function test_hmac_returns_value(): void
    {
        $http = new FakeHttpClient();
        $http->queue('POST', 'v1/transit/hmac/shipment', ['data' => ['hmac' => 'vault:v1:hmacvalue']]);

        $transit = new Transit($http, 'transit');

        self::assertSame('vault:v1:hmacvalue', $transit->hmac('shipment', 'hello'));
    }

    public function test_verify_hmac_returns_boolean(): void
    {
        $http = new FakeHttpClient();
        $http->queue('POST', 'v1/transit/verify/shipment', ['data' => ['valid' => false]]);

        $transit = new Transit($http, 'transit');

        self::assertFalse($transit->verifyHmac('shipment', 'hello', 'vault:v1:hmacvalue'));
    }

    public function test_rotate_key_posts_to_rotate_endpoint(): void
    {
        $http = new FakeHttpClient();
        $http->queue('POST', 'v1/transit/keys/shipment/rotate', []);

        $transit = new Transit($http, 'transit');
        $transit->rotateKey('shipment');

        self::assertSame('v1/transit/keys/shipment/rotate', $http->calls[0]['path']);
    }
}
