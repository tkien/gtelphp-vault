<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Laravel;

use GtelPhp\Vault\Client;
use GtelPhp\Vault\Laravel\Commands\VaultEnvPullCommand;
use GtelPhp\Vault\Manager;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Wires the framework-agnostic Vault SDK into Laravel: config publishing,
 * the `Vault` facade, an `vault:env:pull` Artisan command, and (optionally)
 * a Redis-backed cache (token cache, KV cache, database credentials cache)
 * resolved from Laravel's own Redis manager.
 *
 * The Core package never requires this file to be loaded; everything here
 * is purely additive sugar for Laravel users.
 */
final class VaultServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/vault.php', 'vault');

        $this->app->singleton(Manager::class, function (Application $app) {
            /** @var array<string, mixed> $config */
            $config = $app->make('config')->get('vault', []);

            $redis = $this->resolveCache($app, $config);

            return new Manager(
                connections: $config['connections'] ?? [],
                defaultConnection: $config['default'] ?? 'default',
                logger: $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null,
                redis: $redis,
            );
        });

        $this->app->singleton(Client::class, function (Application $app) {
            /** @var Manager $manager */
            $manager = $app->make(Manager::class);

            return $manager->connection();
        });

        $this->app->alias(Client::class, 'vault');

        // Runs NOW, during register() - i.e. after config/database.php etc.
        // have already been loaded, but before any DB connection is actually
        // resolved (that happens lazily on first query). Overriding
        // config('database.connections.*') here still takes effect.
        // $this->autoBootstrap($this->app);
        $this->app->booted(function () {
            $this->autoBootstrap($this->app);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/vault.php' => $this->app->configPath('vault.php'),
            ], 'vault-config');

            $this->commands([
                VaultEnvPullCommand::class,
            ]);
        }

        // Laravel Octane (and similar persistent-worker runtimes like
        // Swoole/RoadRunner) only run register()/boot() ONCE per worker,
        // not once per request. Without this, dynamic DB credentials
        // injected in autoBootstrap() would go stale the moment Vault
        // rotates them, until the worker happens to restart. If Octane's
        // event dispatcher is available, re-run the auto-bootstrap on
        // every incoming request instead, so rotation is picked up live.
        if (class_exists(\Laravel\Octane\Events\RequestReceived::class)) {
            $this->app['events']->listen(
                \Laravel\Octane\Events\RequestReceived::class,
                function () {
                    $this->autoBootstrap($this->app);
                },
            );
        }
    }

    /**
     * @return array<int, mixed>
     */
    public function provides(): array
    {
        return [Manager::class, Client::class, 'vault'];
    }

    /**
     * Opt-in (config('vault.auto.enabled')) automatic loading of env secrets
     * and/or dynamic database credentials, with NO manual edits needed to
     * bootstrap/app.php - just .env flags. See config/vault.php for options.
     *
     * Failures here NEVER crash the app boot (Vault being briefly
     * unreachable shouldn't take your whole site down) - they're logged if
     * a logger is bound, and otherwise swallowed so the app boots with
     * whatever DB_USERNAME/DB_PASSWORD etc. are already in .env as fallback.
     */
    private function autoBootstrap(Application $app): void
    {
        /** @var array<string, mixed> $auto */
        $config = $app->make('config');
        $auto = $config->get('vault.auto', []);

        if (!($auto['enabled'] ?? false)) {
            return;
        }

        try {
            /** @var Client $client */
            $client = $app->make(Client::class);
        } catch (\Throwable $e) {
            $this->logWarning($app, 'Vault auto-bootstrap skipped: could not build Vault client.', $e);

            return;
        }

        try {
            $env = $config->get('vault.env', []);
            $client->bootstrap((string) ($env['path'] ?? 'env'), [
                'override' => (bool) ($env['override'] ?? false),
                'cache' => (bool) ($env['cache'] ?? true),
            ]);
        } catch (\Throwable $e) {
            $this->logWarning($app, 'Vault auto-bootstrap: failed to load env secrets.', $e);
        }

        /** @var array<string, string> $databaseMap */
        $databaseMap = $auto['database'] ?? [];

        foreach ($databaseMap as $connection => $role) {
            $this->injectDatabaseCredentials($app, $client, (string) $connection, (string) $role, (bool) ($auto['read_write'] ?? false));
        }
    }

    /**
     * Fetches dynamic DB credentials for $role (via {@see Client::database()},
     * which is itself cached per {@see \GtelPhp\Vault\Support\VaultConfig::$databaseCacheEnabled})
     * and overrides config('database.connections.{connection}.username'/'password').
     *
     * Also purges any already-resolved connection of that name. This
     * matters under Octane: on the 2nd+ request, Laravel's DatabaseManager
     * may already hold a live PDO connection from a previous request -
     * just changing config wouldn't make it reconnect. {@see DB::purge()}
     * forces it to drop that connection so the next query opens a fresh
     * one with the (possibly rotated) credentials we just set.
     */
    private function injectDatabaseCredentials(Application $app, Client $client, string $connection, string $role, bool $readWrite = false): void
    {
        try {
            $creds = $client->database()->credentials($role);
        } catch (\Throwable $e) {
            $this->logWarning($app, sprintf('Vault auto-bootstrap: failed to fetch DB credentials for role "%s".', $role), $e);
            return;
        }

        $config = $app->make('config');
        $previousUsername = $config->get("database.connections.{$connection}.username");

        if ($readWrite && isset($creds['username'], $creds['password'])) {
                $app->make('config')->set("database.connections.{$connection}.read.username", $creds['username']);
                $app->make('config')->set("database.connections.{$connection}.read.password", $creds['password']);
                $app->make('config')->set("database.connections.{$connection}.write.username", $creds['username']);
                $app->make('config')->set("database.connections.{$connection}.write.password", $creds['password']);
        } else {
            // If read_write is false
            if (isset($creds['username'], $creds['password'])) {
                $app->make('config')->set("database.connections.{$connection}.username", $creds['username']);
                $app->make('config')->set("database.connections.{$connection}.password", $creds['password']);
            }
        }

        // Only worth purging if the credentials actually changed (cheap
        // check) and the DB manager has already resolved a connection for
        // this name - avoids needlessly dropping a perfectly good
        // connection on every single request.
        if ($previousUsername !== $creds['username'] && $app->bound('db')) {
            try {
                $app->make('db')->purge($connection);
            } catch (\Throwable) {
                // No existing connection to purge, or purge failed - the
                // next query will simply open fresh with current config.
            }
        }
    }

    private function logWarning(Application $app, string $message, \Throwable $e): void
    {
        if ($app->bound(LoggerInterface::class)) {
            $app->make(LoggerInterface::class)->warning($message, ['error' => $e->getMessage()]);
        }
    }

    /**
     * Builds a PSR-16 cache backed by Laravel's Redis manager, wrapping
     * Illuminate's own {@see RedisStore} rather than reaching for the raw
     * `\Redis` (ext-redis) client directly.
     *
     * This matters: if your app uses the `predis` client (Laravel's
     * historical default, still very common when ext-redis isn't
     * installed), `connection(...)->client()` returns a `Predis\Client`,
     * NOT a `\Redis` instance - a naive `instanceof \Redis` check then
     * silently returns null, silently disabling every cache in this SDK
     * (token cache, KV cache, database credentials cache) with no error at
     * all. RedisStore handles both drivers correctly, so this works no
     * matter which one your app uses.
     *
     * @param array<string, mixed> $config
     */
    private function resolveCache(Application $app, array $config): ?CacheInterface
    {
        $needsCache = ($config['connections']['default']['token_cache']['driver'] ?? 'memory') === 'redis'
            || (bool) ($config['connections']['default']['kv_cache']['enabled'] ?? false)
            || (bool) ($config['connections']['default']['database_cache']['enabled'] ?? true);

        if (!$needsCache) {
            return null;
        }

        try {
            $connectionName = $config['redis_connection'] ?? 'default';
            $store = new RedisStore($app->make('redis'), '', $connectionName);

            return new CacheRepository($store);
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
