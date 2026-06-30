<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Connection
    |--------------------------------------------------------------------------
    */
    'default' => env('VAULT_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | Define as many Vault/OpenBao connections as you need. Each one is
    | resolved lazily into a GtelPhp\Vault\Client by the GtelPhp\Vault\Manager.
    |
    */
    'connections' => [
        'default' => [
            'address' => env('VAULT_ADDR', 'http://127.0.0.1:8200'),
            'role_id' => env('VAULT_ROLE_ID', ''),
            'secret_id' => env('VAULT_SECRET_ID', ''),
            'auth_mount' => env('VAULT_AUTH_MOUNT', 'approle'),
            'kv_mount' => env('VAULT_KV_MOUNT', 'secret'),
            'transit_mount' => env('VAULT_TRANSIT_MOUNT', 'transit'),
            'database_mount' => env('VAULT_DATABASE_MOUNT', 'database'),
            'pki_mount' => env('VAULT_PKI_MOUNT', 'pki'),
            'namespace' => env('VAULT_NAMESPACE'),
            'timeout' => (float) env('VAULT_TIMEOUT', 5.0),
            'max_retries' => (int) env('VAULT_MAX_RETRIES', 3),
            'verify_tls' => (bool) env('VAULT_VERIFY_TLS', true),
            'token_renew_threshold' => (float) env('VAULT_TOKEN_RENEW_THRESHOLD', 0.7),
            'headers' => [],
            'token_cache' => [
                // "memory" or "redis"
                'driver' => env('VAULT_TOKEN_CACHE_DRIVER', 'redis'),
                'key' => env('VAULT_TOKEN_CACHE_KEY', 'default'),
            ],

            // Read-through cache for KV v2 reads (Vault::kv()->get()).
            // Uses the same Redis connection as the token cache above.
            // Writes (put/patch/delete/...) always invalidate immediately.
            'kv_cache' => [
                'enabled' => (bool) env('VAULT_KV_CACHE_ENABLED', false),
                'ttl' => (int) env('VAULT_KV_CACHE_TTL', 300),
            ],

            // Cache for Vault::database()->credentials($role). Enabled by
            // default - without this, every single call mints a brand new
            // dynamic database user. Cached for that lease's own
            // lease_duration (minus a safety margin), never longer.
            'database_cache' => [
                'enabled' => (bool) env('VAULT_DATABASE_CACHE_ENABLED', true),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis connection used for the token cache (and, if you choose, as a
    | PSR-16 cache store passed manually to GtelPhp\Vault\Client::make()).
    | Set to null to use Laravel's default "cache" Redis connection.
    |--------------------------------------------------------------------------
    */
    'redis_connection' => env('VAULT_REDIS_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Eloquent Casts
    |--------------------------------------------------------------------------
    |
    | The Transit key used by GtelPhp\Vault\Laravel\Casts\JsonEncryptedCast
    | when no key is otherwise specified.
    |
    */
    'casts' => [
        'transit_key' => env('VAULT_CAST_TRANSIT_KEY', 'app'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Env Bootstrap
    |--------------------------------------------------------------------------
    |
    | Path to the KV v2 secret that Vault::bootstrap()/Vault::loadEnv()
    | reads from when no explicit path is given.
    |
    */
    'env' => [
        'path' => env('VAULT_ENV_PATH', 'env'),
        'override' => (bool) env('VAULT_ENV_OVERRIDE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto Bootstrap
    |--------------------------------------------------------------------------
    |
    | When enabled, VaultServiceProvider pulls this KV secret during its own
    | register() and:
    |   1. putenv()/$_ENV/$_SERVER every key (covers any later env() calls)
    |   2. directly overrides config('database.connections.{connection}.*')
    |      for the keys you map below - this works even though config/database.php
    |      has *already* been loaded, because DB connections are resolved lazily.
    |
    | This does NOT help values that other config files already baked in via
    | env() in a way that's consumed before register() runs (rare). For those,
    | you still need to call Vault::bootstrap() yourself from bootstrap/app.php.
    |
    */
    'auto' => [
        'enabled' => (bool) env('VAULT_AUTO_BOOTSTRAP', false),
        // Maps a Vault database role to a Laravel DB connection name, so
        // dynamic credentials are injected straight into config('database...')
        // before the first query is made. Leave empty to skip.
        'database' => [
            'pgsql' => env('VAULT_DATABASE_ROLE', 'oms'),
        ],
        'read_write' => env('VAULT_DATABASE_READ_WRITE', false),
    ],

];
