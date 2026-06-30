<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Tests\Unit\PKI;

use GtelPhp\Vault\Exceptions\PkiException;
use GtelPhp\Vault\Exceptions\VaultException;
use GtelPhp\Vault\PKI\Pki;
use GtelPhp\Vault\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class PkiTest extends TestCase
{
    public function test_issue_returns_certificate_data(): void
    {
        $http = new FakeHttpClient();
        $http->queue('POST', 'v1/pki/issue/web-server', [
            'data' => ['certificate' => '-----BEGIN CERTIFICATE-----', 'serial_number' => '1a:2b'],
        ]);

        $pki = new Pki($http, 'pki');
        $result = $pki->issue('web-server', 'example.com', ['ttl' => '24h']);

        self::assertSame('1a:2b', $result['serial_number']);
        self::assertSame('example.com', $http->calls[0]['payload']['common_name']);
        self::assertSame('24h', $http->calls[0]['payload']['ttl']);
    }

    public function test_issue_failure_is_wrapped(): void
    {
        $http = new FakeHttpClient();
        $http->queue('POST', 'v1/pki/issue/web-server', new VaultException('role not found'));

        $pki = new Pki($http, 'pki');

        $this->expectException(PkiException::class);
        $pki->issue('web-server', 'example.com');
    }

    public function test_sign_returns_certificate_data(): void
    {
        $http = new FakeHttpClient();
        $http->queue('POST', 'v1/pki/sign/web-server', [
            'data' => ['certificate' => '-----BEGIN CERTIFICATE-----'],
        ]);

        $pki = new Pki($http, 'pki');
        $result = $pki->sign('web-server', '-----BEGIN CERTIFICATE REQUEST-----');

        self::assertStringContainsString('BEGIN CERTIFICATE', $result['certificate']);
    }

    public function test_revoke_returns_revocation_time(): void
    {
        $http = new FakeHttpClient();
        $http->queue('POST', 'v1/pki/revoke', ['data' => ['revocation_time' => 1700000000]]);

        $pki = new Pki($http, 'pki');
        $result = $pki->revoke('1a:2b');

        self::assertSame(1700000000, $result['revocation_time']);
        self::assertSame(['serial_number' => '1a:2b'], $http->calls[0]['payload']);
    }

    public function test_read_certificate_returns_pem_string(): void
    {
        $http = new FakeHttpClient();
        $http->queue('GET', 'v1/pki/cert/1a:2b', [
            'data' => ['certificate' => '-----BEGIN CERTIFICATE-----PEM-----END CERTIFICATE-----'],
        ]);

        $pki = new Pki($http, 'pki');

        self::assertStringContainsString('PEM', $pki->readCertificate('1a:2b'));
    }
}
