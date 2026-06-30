<?php

declare(strict_types=1);

namespace GtelPhp\Vault\PKI;

use GtelPhp\Vault\Contracts\HttpClientInterface;
use GtelPhp\Vault\Exceptions\PkiException;
use GtelPhp\Vault\Exceptions\VaultException;

/**
 * Client for the PKI secrets engine: issue, sign and revoke certificates.
 *
 * @see https://developer.hashicorp.com/vault/docs/secrets/pki
 */
final class Pki
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $mount = 'pki',
    ) {
    }

    /**
     * Issue a brand new certificate (Vault generates the private key for you).
     *
     * @param array<string, mixed> $options e.g. alt_names, ttl, ip_sans, format
     *
     * @return array<string, mixed> certificate, issuing_ca, private_key, serial_number, ...
     */
    public function issue(string $role, string $commonName, array $options = []): array
    {
        $payload = array_merge($options, ['common_name' => $commonName]);

        try {
            $response = $this->http->post(sprintf('v1/%s/issue/%s', trim($this->mount, '/'), $role), $payload);
        } catch (VaultException $e) {
            throw new PkiException(
                sprintf('Failed to issue certificate for "%s" with role "%s": %s', $commonName, $role, $e->getMessage()),
                $e->context(),
                $e->getCode(),
                $e,
            );
        }

        return $response['data'] ?? [];
    }

    /**
     * Sign a CSR generated client-side (the private key never leaves your
     * application).
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function sign(string $role, string $csr, array $options = []): array
    {
        $payload = array_merge($options, ['csr' => $csr]);

        try {
            $response = $this->http->post(sprintf('v1/%s/sign/%s', trim($this->mount, '/'), $role), $payload);
        } catch (VaultException $e) {
            throw new PkiException(
                sprintf('Failed to sign CSR with role "%s": %s', $role, $e->getMessage()),
                $e->context(),
                $e->getCode(),
                $e,
            );
        }

        return $response['data'] ?? [];
    }

    /**
     * Revoke a previously issued certificate by serial number.
     *
     * @return array<string, mixed>
     */
    public function revoke(string $serialNumber): array
    {
        try {
            $response = $this->http->post(sprintf('v1/%s/revoke', trim($this->mount, '/')), [
                'serial_number' => $serialNumber,
            ]);
        } catch (VaultException $e) {
            throw new PkiException(
                sprintf('Failed to revoke certificate "%s": %s', $serialNumber, $e->getMessage()),
                $e->context(),
                $e->getCode(),
                $e,
            );
        }

        return $response['data'] ?? [];
    }

    /**
     * Fetch a previously issued certificate by serial number (or "ca" for
     * the issuing CA certificate).
     */
    public function readCertificate(string $serialNumber): string
    {
        try {
            $response = $this->http->get(sprintf('v1/%s/cert/%s', trim($this->mount, '/'), $serialNumber));
        } catch (VaultException $e) {
            throw new PkiException(
                sprintf('Failed to read certificate "%s": %s', $serialNumber, $e->getMessage()),
                $e->context(),
                $e->getCode(),
                $e,
            );
        }

        return (string) ($response['data']['certificate'] ?? '');
    }

    /**
     * Generate a new root CA for this PKI mount.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function generateRoot(string $type, string $commonName, array $options = []): array
    {
        $payload = array_merge($options, ['common_name' => $commonName]);

        try {
            $response = $this->http->post(sprintf('v1/%s/root/generate/%s', trim($this->mount, '/'), $type), $payload);
        } catch (VaultException $e) {
            throw new PkiException(
                sprintf('Failed to generate root CA "%s": %s', $commonName, $e->getMessage()),
                $e->context(),
                $e->getCode(),
                $e,
            );
        }

        return $response['data'] ?? [];
    }

    /**
     * Create or update a PKI role that defines the policy constraints for
     * certificates issued under it.
     *
     * @param array<string, mixed> $options
     */
    public function createRole(string $role, array $options = []): void
    {
        try {
            $this->http->post(sprintf('v1/%s/roles/%s', trim($this->mount, '/'), $role), $options);
        } catch (VaultException $e) {
            throw new PkiException(
                sprintf('Failed to create/update PKI role "%s": %s', $role, $e->getMessage()),
                $e->context(),
                $e->getCode(),
                $e,
            );
        }
    }
}
