# GtelPHP Vault

A production-ready, framework-agnostic PHP SDK for [HashiCorp Vault](https://www.vaultproject.io/) and [OpenBao](https://openbao.org/) — with first-class Laravel support.

[![PHP Version](https://img.shields.io/badge/php-%5E8.2-777BB4)](https://www.php.net/)
[![PHPStan Level](https://img.shields.io/badge/phpstan-level%208-brightgreen)](phpstan.neon)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

```bash
composer require gtelphp/vault
```

## Why this package

- **Core has zero Laravel dependency.** Laravel is an adapter on top of a plain PHP SDK — use it in any PHP 8.2+ project.
- **Works with both Vault and OpenBao** transparently, since they share the same HTTP API.
- **AppRole auth with automatic token renewal**, backed by a pluggable token cache (in-memory or Redis).
- **KV v2, Transit, Database secrets and PKI engines** — the ones you actually use in production.
- **`Vault::bootstrap()`** loads secrets straight into `putenv()`/`$_ENV`/`$_SERVER`, before your framework's config is even loaded.
- **`JsonEncryptedCast`** for Eloquent — encrypt *individual keys* inside a `jsonb` column with Vault Transit, while keeping the column a real JSON object.
- PSR-4, PSR-12, PHPStan level 8, full PHPUnit test suite.

## Table of contents

- [Installation](#installation)
- [Quick start (plain PHP)](#quick-start-plain-php)
- [Laravel installation](#laravel-installation)
- [Configuration reference](#configuration-reference)
- [Caching (don't skip this in production)](#caching-dont-skip-this-in-production)
- [Auto-loading env + database credentials at boot (Laravel)](#auto-loading-env--database-credentials-at-boot-laravel)
- [KV v2 secrets](#kv-v2-secrets)
- [Transit (encrypt / decrypt / sign / hmac)](#transit-encrypt--decrypt--sign--hmac)
- [Database secrets engine](#database-secrets-engine)
- [PKI secrets engine](#pki-secrets-engine)
- [Env bootstrap (`Vault::bootstrap()`)](#env-bootstrap-vaultbootstrap)
- [JsonEncryptedCast — selective jsonb encryption](#jsonencryptedcast--selective-jsonb-encryption)
- [Multiple connections](#multiple-connections)
- [Token caching (memory vs Redis)](#token-caching-memory-vs-redis)
- [Exceptions](#exceptions)
- [Testing](#testing)
- [Best practices](#best-practices)
- [License](#license)

## Installation

```bash
composer require gtelphp/vault
```

Requires PHP `>= 8.2`. Works with Laravel `11.x` and `12.x` if you want the optional adapter — install `illuminate/support` yourself or just use the package inside a Laravel app, where it's already present.

## Quick start (plain PHP)

```php
use GtelPhp\Vault\Client;
use GtelPhp\Vault\Support\VaultConfig;

$config = new VaultConfig(
    address: 'https://vault.internal:8200',
    roleId: getenv('VAULT_ROLE_ID'),
    secretId: getenv('VAULT_SECRET_ID'),
);

$vault = Client::make($config);

// AppRole login + token caching + auto-renewal all happen transparently.
$database = $vault->kv()->get('database');

echo $database['username'];
```

You never have to call `login()` yourself — every secrets-engine call asks the internal `TokenManager` for a valid token, which logs in (or renews) as needed.

## Laravel installation

The package is auto-discovered. Publish the config file:

```bash
php artisan vendor:publish --tag=vault-config
```

Add these to your `.env`:

```env
VAULT_ADDR=https://vault.internal:8200
VAULT_ROLE_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
VAULT_SECRET_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
VAULT_DATABASE_ENABLED=true
VAULT_DATABASE_ROLE=role
VAULT_ENV_PATH=env
VAULT_ENV_OVERRIDE=true
VAULT_TOKEN_CACHE_DRIVER=redis
VAULT_REDIS_CONNECTION=default
VAULT_KV_MOUNT=kv-v2
VAULT_KV_CACHE_ENABLED=true
VAULT_KV_CACHE_TTL=3600
VAULT_AUTO_BOOTSTRAP=true
VAULT_DATABASE_READ_WRITE=true
```

Then use the facade anywhere:

```php
use GtelPhp\Vault\Laravel\Facades\Vault;

$secret = Vault::kv()->get('database');

Vault::transit()->encrypt('shipment', $plaintext);
```

Or inject `GtelPhp\Vault\Client` / `GtelPhp\Vault\Manager` via the container as you would with any other service.

## Configuration reference

`config/vault.php` (see the published file for full inline docs):

| Key | Description | Default |
|---|---|---|
| `default` | Name of the default connection | `default` |
| `connections.{name}.address` | Vault/OpenBao base URL | `http://127.0.0.1:8200` |
| `connections.{name}.role_id` / `secret_id` | AppRole credentials | — |
| `connections.{name}.kv_mount` / `transit_mount` / `database_mount` / `pki_mount` | Secrets engine mount paths | `secret`, `transit`, `database`, `pki` |
| `connections.{name}.namespace` | Vault Enterprise / HCP namespace header | `null` |
| `connections.{name}.token_cache.driver` | `memory` or `redis` | `redis` |
| `connections.{name}.token_renew_threshold` | Renew once this fraction of the TTL has elapsed | `0.7` |
| `connections.{name}.kv_cache.enabled` / `.ttl` | Read-through cache for `kv()->get()` (Redis) | `false` / `300` |
| `connections.{name}.database_cache.enabled` | Cache for `database()->credentials()`, TTL'd to the lease itself | `true` |
| `casts.transit_key` | Default Transit key used by `JsonEncryptedCast` | `app` |
| `env.path` | Default secret path for `Vault::bootstrap()` | `env` |
| `auto.enabled` | Auto-load env secrets + DB credentials during boot, no manual `bootstrap/app.php` edits needed | `false` |
| `auto.env_path` | KV path to load when `auto.enabled` is true | `env` |
| `auto.database` | Map of `{laravel_connection: vault_database_role}` to auto-inject | `[]` |

In plain PHP, build a `GtelPhp\Vault\Support\VaultConfig` directly (constructor or `VaultConfig::fromArray()`), no Laravel config required.

## Caching (don't skip this in production)

Two things are cached automatically once Redis is available, to avoid hammering Vault and (for database credentials) avoid minting a brand new database user on every single call:

- **`Vault::kv()->get()`** — opt-in via `VAULT_KV_CACHE_ENABLED=true` / `VAULT_KV_CACHE_TTL=300`. Any write (`put`/`patch`/`delete`/`destroy`/`undelete`) immediately invalidates that path's cache entry.
- **`Vault::database()->credentials($role)`** — **enabled by default**. Cached for the lease's own `lease_duration` (minus a small safety margin), never longer, so it's always refreshed before the underlying credentials actually expire. Disable with `VAULT_DATABASE_CACHE_ENABLED=false` if you really want a fresh lease on every call; use `Vault::database()->freshCredentials($role)` to force a refresh on demand instead, or `Vault::database()->forget($role)` to drop the cached entry.

```env
VAULT_KV_CACHE_ENABLED=true
VAULT_KV_CACHE_TTL=300
VAULT_DATABASE_CACHE_ENABLED=true
```

In plain PHP, pass a Redis client into `Client::make()`:

```php
$vault = Client::make($config, redis: $redisClient);
```

## Auto-loading env + database credentials at boot (Laravel)

Instead of manually editing `bootstrap/app.php`, you can have `VaultServiceProvider` pull a KV secret into the environment *and* inject dynamic database credentials automatically, controlled entirely by `.env`:

```env
VAULT_AUTO_BOOTSTRAP=true
VAULT_ENV_PATH=oms
```

```php
// config/vault.php
'auto' => [
    'enabled' => env('VAULT_AUTO_BOOTSTRAP', false),
    'env_path' => env('VAULT_ENV_PATH', 'env'),
    'database' => [
        'pgsql' => 'oms', // Laravel connection 'pgsql' <- Vault database role 'oms'
    ],
],
```

This runs during the provider's `register()` phase — after `config/database.php` has already been parsed, but before Laravel's `DatabaseManager` actually opens any connection (that happens lazily on the first query), so overriding `config('database.connections.pgsql.username'/'password')` here still takes effect. Database credentials go through the same caching described above, so this does **not** mint a new database user on every request.

If a Vault call fails during auto-bootstrap (e.g. Vault is briefly unreachable), the failure is logged (when a PSR-3 logger is bound) and swallowed — your app still boots, falling back to whatever's already in `.env`.

**Laravel Octane caveat:** under Octane (or other persistent-worker runtimes), providers only boot once per worker, not once per request. `VaultServiceProvider` detects Octane automatically and re-applies the auto-bootstrap on every `RequestReceived` event so rotated credentials are picked up live, purging any already-resolved DB connection so it reconnects with the new credentials.

If you only need the env-loading half (no DB credential auto-injection), you can still call this manually and skip the `auto.*` config entirely — see the next section.

## KV v2 secrets

```php
$vault->kv()->put('database', [
    'username' => 'app',
    'password' => 'super-secret',
]);

$secret = $vault->kv()->get('database');          // current version
$secret = $vault->kv()->get('database', version: 3); // a specific version

$vault->kv()->getValue('database', 'username', default: 'fallback');

$vault->kv()->patch('database', ['password' => 'rotated']); // merge, keeps other keys

$vault->kv()->delete('database');           // soft delete latest version
$vault->kv()->delete('database', [1, 2]);   // soft delete specific versions
$vault->kv()->destroy('database', [1]);     // permanent, irreversible
$vault->kv()->undelete('database', [1, 2]);

$vault->kv()->metadata('database');         // version history, CAS config, ...
$vault->kv()->list('');                     // list keys under a path
```

`KvV2` always talks to `<mount>/data/...` and `<mount>/metadata/...` under the hood and unwraps the `data.data` nesting for you — you only ever see plain arrays.

## Transit (encrypt / decrypt / sign / hmac)

```php
$ciphertext = $vault->transit()->encrypt('shipment', 'plain text payload');
$plaintext  = $vault->transit()->decrypt('shipment', $ciphertext);

// Batch operations in a single round trip
$ciphertexts = $vault->transit()->encryptBatch('shipment', ['a', 'b', 'c']);
$plaintexts  = $vault->transit()->decryptBatch('shipment', $ciphertexts);

// Re-wrap under the latest key version after rotation, without exposing plaintext
$rewrapped = $vault->transit()->rewrap('shipment', $ciphertext);

// Sign / verify
$signature = $vault->transit()->sign('shipment', $payload);
$isValid   = $vault->transit()->verify('shipment', $payload, $signature);

// HMAC
$hmac    = $vault->transit()->hmac('shipment', $payload);
$isValid = $vault->transit()->verifyHmac('shipment', $payload, $hmac);

// Key management
$vault->transit()->createKey('shipment', ['type' => 'aes256-gcm96']);
$vault->transit()->rotateKey('shipment');
```

You always pass and receive plain PHP strings — base64 encoding/decoding of `plaintext`/`ciphertext`/`input` is handled internally.

## Database secrets engine

```php
$creds = $vault->database()->credentials('postgres');

// $creds = ['username' => '...', 'password' => '...', 'lease_id' => '...', 'lease_duration' => 3600, 'renewable' => true]

$vault->database()->revokeLease($creds['lease_id']);
```

> ⚠️ `credentials()` is cached by default (TTL'd to the lease's own `lease_duration`) — see [Caching](#caching-dont-skip-this-in-production). Without a cache configured, **every single call mints a brand new database user**, which is slow and will quickly exhaust your database's connection/user limits in production.

```php
$vault->database()->freshCredentials('postgres'); // bypass cache, force a brand new lease
$vault->database()->forget('postgres');            // manually invalidate the cached entry
```

## PKI secrets engine

```php
$cert = $vault->pki()->issue('web-server', 'app.example.com', ['ttl' => '720h']);
// $cert['certificate'], $cert['private_key'], $cert['serial_number'], ...

$signed = $vault->pki()->sign('web-server', $csrPem);

$vault->pki()->revoke($cert['serial_number']);

$pem = $vault->pki()->readCertificate($cert['serial_number']);
```

## Env bootstrap (`Vault::bootstrap()`)

Load an entire KV v2 secret into the process environment, early enough that your framework's own config files (which usually read `env('SOME_KEY')`) pick the values up:

```php
// Plain PHP, very top of your entrypoint:
$vault->bootstrap('env'); // reads connections.default kv_mount + 'env' path
```

In Laravel, call this from `bootstrap/app.php` (or a custom bootstrapper that runs before config is cached) — **not** from a `ServiceProvider::boot()`, which runs too late for config files that call `env()` directly:

```php
// bootstrap/app.php
use GtelPhp\Vault\Laravel\Facades\Vault;

Vault::bootstrap(); // uses config('vault.env.path'), defaults to "env"
```

Options:

```php
Vault::bootstrap('env', [
    'override' => true,        // overwrite vars that are already set (default: false)
    'cache' => true,           // reuse the result for the lifetime of the process (default: true)
    'prefix' => 'APP_',        // only import keys with this prefix (stripped before applying)
    'mutateKey' => fn ($k) => strtoupper($k),
]);
```

## JsonEncryptedCast — selective jsonb encryption

Your PostgreSQL columns stay `jsonb`. Only the keys you list are ever encrypted — everything else in the structure (and the column's JSON type) is left untouched.

```php
use GtelPhp\Vault\Laravel\Casts\JsonEncryptedCast;

class Shipment extends Model
{
    protected $casts = [
        'sender_info' => JsonEncryptedCast::class . ':name,phone,address.street,address.detail',
        'receiver_info' => JsonEncryptedCast::class . ':name,phone,address.street,address.detail',
    ];
}
```

```php
$shipment->sender_info = [
    'name' => 'John',
    'phone' => '0909000000',
    'province_code' => '01',
    'address' => ['street' => '123 Main St', 'detail' => 'Floor 2'],
];
$shipment->save();
```

What actually lands in `jsonb`:

```json
{
  "name": "vault:v1:AAAAAQobNX...",
  "phone": "vault:v1:AAAAAQobNY...",
  "province_code": "01",
  "address": {
    "street": "vault:v1:AAAAAQobNZ...",
    "detail": "vault:v1:AAAAAQobNa..."
  }
}
```

Reading the attribute back transparently decrypts only those keys:

```php
$shipment->sender_info['name']; // "John"
$shipment->sender_info['province_code']; // "01" - was never touched
```

### Per-cast Transit key

By default every cast uses the single Transit key configured globally via `config('vault.casts.transit_key')` / `VAULT_CAST_TRANSIT_KEY` (default `app`). For data with different sensitivity levels or compliance requirements (e.g. payment data vs. general PII), give different casts their own Transit key with a `key=<transit-key-name>` token — it can appear anywhere in the argument list:

```php
class Shipment extends Model
{
    protected $casts = [
        'sender_info'  => JsonEncryptedCast::class . ':name,phone,address.street,key=oms-pii',
        'payment_info' => JsonEncryptedCast::class . ':card_number,cvv,key=oms-payments',
    ];
}
```

Each Transit key must exist in Vault before use:

```php
Vault::transit()->createKey('oms-pii');
Vault::transit()->createKey('oms-payments');
```

```bash
vault write -f transit/keys/oms-pii
vault write -f transit/keys/oms-payments
```

Separate keys let you scope AppRole policies per key (e.g. only the payments service can `encrypt`/`decrypt` with `oms-payments`) and rotate/revoke each one independently of the others.

Notes:

- Dot notation (`address.street`) targets nested keys; everything not listed is left exactly as-is.
- Values are recognised as already-encrypted by their `vault:v` ciphertext prefix, so re-saving a freshly-loaded model never double-encrypts.
- Without a `key=...` token, the cast falls back to `config('vault.casts.transit_key')` (default `app`).
- This cast requires the Laravel container (it resolves `GtelPhp\Vault\Client` via `app()`); it is not usable outside of Laravel.

## Multiple connections

```php
use GtelPhp\Vault\Manager;

$manager = new Manager(connections: config('vault.connections'));

$manager->connection('default')->kv()->get('database');
$manager->connection('payments')->kv()->get('stripe');
```

In Laravel, `Vault::connection('payments')->kv()->get(...)` works the same way through the facade.

## Token caching (memory vs Redis)

```php
use GtelPhp\Vault\Auth\MemoryTokenCache; // per-process only, fine for CLI/queue workers
use GtelPhp\Vault\Auth\RedisTokenCache;  // shared across web workers, recommended in production

$cache = new RedisTokenCache($redisClient); // accepts ext-redis \Redis or any PSR-16 CacheInterface

$vault = Client::make($config, tokenCache: $cache);
```

In Laravel, set `VAULT_TOKEN_CACHE_DRIVER=redis` and the `VaultServiceProvider` wires up your existing `redis` connection automatically.

## Exceptions

Every exception extends `GtelPhp\Vault\Exceptions\VaultException`, so you can always catch that as a fallback:

| Exception | When |
|---|---|
| `AuthenticationException` | AppRole login fails |
| `TokenExpiredException` | Token can't be renewed and needs a fresh login |
| `ConnectionException` | Network/transport failure reaching Vault/OpenBao |
| `KvException` | KV v2 operation failed |
| `TransitException` | Transit operation failed |
| `DatabaseSecretsException` | Database secrets engine operation failed |
| `PkiException` | PKI operation failed |

```php
use GtelPhp\Vault\Exceptions\VaultException;

try {
    $vault->kv()->get('database');
} catch (VaultException $e) {
    logger()->error($e->getMessage(), $e->context());
}
```

## Testing

```bash
composer install
composer test    # PHPUnit
composer stan     # PHPStan level 8
composer cs       # PHP_CodeSniffer (PSR-12)
```

The test suite uses a fake `HttpClientInterface` implementation, so it never touches the network or a real Vault/OpenBao server.

## Best practices

- **Always use Redis (or another shared) token cache in production web apps.** With `MemoryTokenCache`, every PHP-FPM worker logs in independently, which is wasteful and can exhaust AppRole secret ID usage limits.
- **Make sure Redis is actually reachable for the database credentials cache.** It's enabled by default, but silently falls back to "no cache" (a fresh lease every call) if Redis isn't bound — verify with `redis-cli KEYS "*gtelphp_vault*"` after a request.
- **Scope AppRole policies tightly.** Give each application only the KV paths / Transit keys / database roles it actually needs.
- **Use separate Transit keys for data with different sensitivity/compliance needs** (see `key=...` in [JsonEncryptedCast](#jsonencryptedcast--selective-jsonb-encryption)), so each can be rotated, revoked, and policy-scoped independently.
- **Call `Vault::bootstrap()` as early as possible** in your request lifecycle, before any config relying on those env vars is read — or use `auto.enabled` to have `VaultServiceProvider` do it for you.
- **Rotate Transit keys periodically** with `rotateKey()` and let `rewrap()` upgrade old ciphertexts lazily on read, rather than re-encrypting everything at once.
- **Prefer short TTLs with auto-renewal** over long-lived tokens — this SDK's `TokenManager` makes that essentially free.

## License

MIT
