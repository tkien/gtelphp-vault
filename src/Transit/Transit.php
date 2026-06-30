<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Transit;

use GtelPhp\Vault\Contracts\HttpClientInterface;
use GtelPhp\Vault\Exceptions\TransitException;
use GtelPhp\Vault\Exceptions\VaultException;

/**
 * Client for the Transit secrets engine: encrypt, decrypt, rewrap, sign,
 * verify and hmac.
 *
 * Vault's Transit API speaks base64 for plaintext/ciphertext/input. This
 * class takes care of that encoding transparently so callers only ever
 * work with normal PHP strings.
 *
 * @see https://developer.hashicorp.com/vault/docs/secrets/transit
 */
final class Transit
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $mount = 'transit',
    ) {
    }

    /**
     * Encrypt plaintext with the named key. Returns the Vault ciphertext
     * string (e.g. "vault:v1:...").
     *
     * @param array<string, mixed> $context Extra options: key_version, context (for derived keys), nonce, etc.
     */
    public function encrypt(string $keyName, string $plaintext, array $context = []): string
    {
        $payload = array_merge($context, [
            'plaintext' => base64_encode($plaintext),
        ]);

        $response = $this->call('encrypt', $keyName, $payload, 'Failed to encrypt data with key "%s": %s');

        $ciphertext = $response['data']['ciphertext'] ?? null;

        if (!is_string($ciphertext)) {
            throw new TransitException(sprintf('Encrypt response for key "%s" did not contain ciphertext.', $keyName));
        }

        return $ciphertext;
    }

    /**
     * Decrypt a Vault ciphertext string back into the original plaintext.
     *
     * @param array<string, mixed> $context
     */
    public function decrypt(string $keyName, string $ciphertext, array $context = []): string
    {
        $payload = array_merge($context, [
            'ciphertext' => $ciphertext,
        ]);

        $response = $this->call('decrypt', $keyName, $payload, 'Failed to decrypt data with key "%s": %s');

        $plaintext = $response['data']['plaintext'] ?? null;

        if (!is_string($plaintext)) {
            throw new TransitException(sprintf('Decrypt response for key "%s" did not contain plaintext.', $keyName));
        }

        $decoded = base64_decode($plaintext, true);

        if ($decoded === false) {
            throw new TransitException(sprintf('Could not base64-decode plaintext returned for key "%s".', $keyName));
        }

        return $decoded;
    }

    /**
     * Encrypt many plaintexts in a single round trip.
     *
     * @param string[] $plaintexts
     *
     * @return string[] ciphertexts, in the same order as the input
     */
    public function encryptBatch(string $keyName, array $plaintexts): array
    {
        $batchInput = array_map(
            static fn (string $p) => ['plaintext' => base64_encode($p)],
            $plaintexts,
        );

        $response = $this->call('encrypt', $keyName, ['batch_input' => $batchInput], 'Failed to batch encrypt with key "%s": %s');

        return array_map(
            static fn (array $item) => $item['ciphertext'] ?? '',
            $response['data']['batch_results'] ?? [],
        );
    }

    /**
     * Decrypt many ciphertexts in a single round trip.
     *
     * @param string[] $ciphertexts
     *
     * @return string[] plaintexts, in the same order as the input
     */
    public function decryptBatch(string $keyName, array $ciphertexts): array
    {
        $batchInput = array_map(
            static fn (string $c) => ['ciphertext' => $c],
            $ciphertexts,
        );

        $response = $this->call('decrypt', $keyName, ['batch_input' => $batchInput], 'Failed to batch decrypt with key "%s": %s');

        return array_map(
            static fn (array $item) => base64_decode((string) ($item['plaintext'] ?? ''), true) ?: '',
            $response['data']['batch_results'] ?? [],
        );
    }

    /**
     * Re-encrypt a ciphertext under the latest key version, without
     * exposing the plaintext. Useful after key rotation.
     */
    public function rewrap(string $keyName, string $ciphertext, ?int $keyVersion = null): string
    {
        $payload = ['ciphertext' => $ciphertext];

        if ($keyVersion !== null) {
            $payload['key_version'] = $keyVersion;
        }

        $response = $this->call('rewrap', $keyName, $payload, 'Failed to rewrap ciphertext with key "%s": %s');

        $rewrapped = $response['data']['ciphertext'] ?? null;

        if (!is_string($rewrapped)) {
            throw new TransitException(sprintf('Rewrap response for key "%s" did not contain ciphertext.', $keyName));
        }

        return $rewrapped;
    }

    /**
     * Sign input data with the named signing key. Returns the Vault
     * signature string (e.g. "vault:v1:...").
     *
     * @param array<string, mixed> $options e.g. signature_algorithm, hash_algorithm
     */
    public function sign(string $keyName, string $input, array $options = []): string
    {
        $payload = array_merge($options, ['input' => base64_encode($input)]);

        $response = $this->call('sign', $keyName, $payload, 'Failed to sign data with key "%s": %s');

        $signature = $response['data']['signature'] ?? null;

        if (!is_string($signature)) {
            throw new TransitException(sprintf('Sign response for key "%s" did not contain a signature.', $keyName));
        }

        return $signature;
    }

    /**
     * Verify a signature against input data. Returns true/false rather
     * than throwing, since "invalid signature" is an expected outcome.
     *
     * @param array<string, mixed> $options
     */
    public function verify(string $keyName, string $input, string $signature, array $options = []): bool
    {
        $payload = array_merge($options, [
            'input' => base64_encode($input),
            'signature' => $signature,
        ]);

        $response = $this->call('verify', $keyName, $payload, 'Failed to verify signature with key "%s": %s');

        return (bool) ($response['data']['valid'] ?? false);
    }

    /**
     * Generate an HMAC for input data. Returns the Vault HMAC string
     * (e.g. "vault:v1:...").
     *
     * @param array<string, mixed> $options e.g. algorithm
     */
    public function hmac(string $keyName, string $input, array $options = []): string
    {
        $payload = array_merge($options, ['input' => base64_encode($input)]);

        $response = $this->call('hmac', $keyName, $payload, 'Failed to compute HMAC with key "%s": %s');

        $hmac = $response['data']['hmac'] ?? null;

        if (!is_string($hmac)) {
            throw new TransitException(sprintf('HMAC response for key "%s" did not contain a hmac value.', $keyName));
        }

        return $hmac;
    }

    /**
     * Verify an HMAC against input data.
     *
     * @param array<string, mixed> $options
     */
    public function verifyHmac(string $keyName, string $input, string $hmac, array $options = []): bool
    {
        $payload = array_merge($options, [
            'input' => base64_encode($input),
            'hmac' => $hmac,
        ]);

        $response = $this->call('verify', $keyName, $payload, 'Failed to verify HMAC with key "%s": %s');

        return (bool) ($response['data']['valid'] ?? false);
    }

    /**
     * Create a new named encryption/signing key.
     *
     * @param array<string, mixed> $options e.g. type, exportable, derived
     */
    public function createKey(string $keyName, array $options = []): void
    {
        try {
            $this->http->post(sprintf('v1/%s/keys/%s', trim($this->mount, '/'), $keyName), $options);
        } catch (VaultException $e) {
            throw new TransitException(
                sprintf('Failed to create transit key "%s": %s', $keyName, $e->getMessage()),
                $e->context(),
                $e->getCode(),
                $e,
            );
        }
    }

    /**
     * Rotate a named key, creating a new key version for future operations.
     */
    public function rotateKey(string $keyName): void
    {
        try {
            $this->http->post(sprintf('v1/%s/keys/%s/rotate', trim($this->mount, '/'), $keyName));
        } catch (VaultException $e) {
            throw new TransitException(
                sprintf('Failed to rotate transit key "%s": %s', $keyName, $e->getMessage()),
                $e->context(),
                $e->getCode(),
                $e,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function readKey(string $keyName): array
    {
        try {
            $response = $this->http->get(sprintf('v1/%s/keys/%s', trim($this->mount, '/'), $keyName));
        } catch (VaultException $e) {
            throw new TransitException(
                sprintf('Failed to read transit key "%s": %s', $keyName, $e->getMessage()),
                $e->context(),
                $e->getCode(),
                $e,
            );
        }

        return $response['data'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function call(string $operation, string $keyName, array $payload, string $errorTemplate): array
    {
        try {
            return $this->http->post(
                sprintf('v1/%s/%s/%s', trim($this->mount, '/'), $operation, $keyName),
                $payload,
            );
        } catch (VaultException $e) {
            throw new TransitException(
                sprintf($errorTemplate, $keyName, $e->getMessage()),
                $e->context(),
                $e->getCode(),
                $e,
            );
        }
    }
}
