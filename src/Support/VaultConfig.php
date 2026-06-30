<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Support;

final class VaultConfig
{
    public function __construct(
        public readonly string $address,
        public readonly string $roleId,
        public readonly string $secretId,
        public readonly string $authMount = 'approle',
        public readonly string $kvMount = 'secret',
        public readonly string $transitMount = 'transit',
        public readonly string $databaseMount = 'database',
        public readonly string $pkiMount = 'pki',
        public readonly float $timeout = 5.0,
        public readonly int $maxRetries = 3,
        public readonly bool $verifyTls = true,
        public readonly ?string $namespace = null,
        public readonly array $headers = [],
        public readonly float $tokenRenewThreshold = 0.7,
        public readonly string $tokenCacheDriver = 'memory',
        public readonly string $tokenCacheKey = 'default',
        public readonly bool $kvCacheEnabled = false,
        public readonly int $kvCacheTtl = 300,
        public readonly bool $databaseCacheEnabled = true,
    ) {
    }

    public static function fromArray(array $config): self
    {
        return new self(
            address: (string) ($config['address'] ?? 'http://127.0.0.1:8200'),
            roleId: (string) ($config['role_id'] ?? ''),
            secretId: (string) ($config['secret_id'] ?? ''),
            authMount: (string) ($config['auth_mount'] ?? 'approle'),
            kvMount: (string) ($config['kv_mount'] ?? 'secret'),
            transitMount: (string) ($config['transit_mount'] ?? 'transit'),
            databaseMount: (string) ($config['database_mount'] ?? 'database'),
            pkiMount: (string) ($config['pki_mount'] ?? 'pki'),
            timeout: (float) ($config['timeout'] ?? 5.0),
            maxRetries: (int) ($config['max_retries'] ?? 3),
            verifyTls: (bool) ($config['verify_tls'] ?? true),
            namespace: $config['namespace'] ?? null,
            headers: (array) ($config['headers'] ?? []),
            tokenRenewThreshold: (float) ($config['token_renew_threshold'] ?? 0.7),
            tokenCacheDriver: (string) ($config['token_cache']['driver'] ?? 'memory'),
            tokenCacheKey: (string) ($config['token_cache']['key'] ?? 'default'),
            kvCacheEnabled: (bool) ($config['kv_cache']['enabled'] ?? false),
            kvCacheTtl: (int) ($config['kv_cache']['ttl'] ?? 300),
            databaseCacheEnabled: (bool) ($config['database_cache']['enabled'] ?? true),
        );
    }
}
