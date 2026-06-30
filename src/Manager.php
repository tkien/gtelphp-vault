<?php

declare(strict_types=1);

namespace GtelPhp\Vault;

use GtelPhp\Vault\Contracts\TokenCacheInterface;
use GtelPhp\Vault\Support\VaultConfig;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Manages multiple named Vault connections (e.g. "default", "payments",
 * "another-cluster") the same way Laravel's DatabaseManager handles
 * multiple DB connections - lazily instantiated and cached for reuse.
 *
 * This class has no Laravel dependency; the Laravel ServiceProvider simply
 * registers one of these in the container and forwards `config('vault')`
 * into it.
 */
final class Manager
{
    /** @var array<string, Client> */
    private array $clients = [];

    /**
     * @param array<string, array<string, mixed>> $connections keyed by connection name
     */
    public function __construct(
        private readonly array $connections,
        private readonly string $defaultConnection = 'default',
        private readonly ?LoggerInterface $logger = null,
        private readonly ?TokenCacheInterface $tokenCache = null,
        private readonly ?CacheInterface $redis = null,
    ) {
    }

    public function connection(?string $name = null): Client
    {
        $name ??= $this->defaultConnection;

        return $this->clients[$name] ??= $this->resolve($name);
    }

    /**
     * Shortcut so `$manager->kv()` works on the default connection without
     * having to call `$manager->connection()->kv()` everywhere.
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->connection()->{$method}(...$arguments);
    }

    private function resolve(string $name): Client
    {
        if (!isset($this->connections[$name])) {
            throw new InvalidArgumentException(sprintf('Vault connection "%s" is not configured.', $name));
        }

        $config = VaultConfig::fromArray($this->connections[$name]);

        return Client::make(
            config: $config,
            logger: $this->logger,
            tokenCache: $this->tokenCache,
            redis: $this->redis,
        );
    }
}
