<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Env;

use GtelPhp\Vault\Exceptions\VaultException;
use GtelPhp\Vault\KV\KvV2;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Pulls a KV v2 secret from Vault and injects every key/value pair into
 * the process environment (putenv, $_ENV, $_SERVER), early enough that
 * frameworks reading `env()`/`getenv()` during their own boot sequence
 * pick the values up transparently.
 *
 * Designed to run *before* Laravel's config is cached/loaded - call
 * `Vault::bootstrap()` from `bootstrap/app.php` or a custom bootstrapper,
 * not from a ServiceProvider::register() (which already runs too late
 * for config files that read env() directly).
 */
final class EnvLoader
{
    /**
     * @var array<string, array<string, mixed>> simple in-process cache, keyed by secret path
     */
    private static array $cache = [];

    public function __construct(
        private readonly KvV2 $kv,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options {
     *     @var bool $override       Overwrite variables that are already set. Default false.
     *     @var bool $cache          Reuse the result for the lifetime of the process. Default true.
     *     @var string|null $prefix  Only import keys with this prefix (prefix is stripped).
     *     @var callable|null $mutateKey  Receives each key and returns the env var name to use.
     * }
     *
     * @return array<string, string> the variables that were actually applied
     */
    public function load(string $secretPath, array $options = []): array
    {
        $override = (bool) ($options['override'] ?? false);
        $useCache = (bool) ($options['cache'] ?? true);
        $prefix = $options['prefix'] ?? null;
        /** @var callable|null $mutateKey */
        $mutateKey = $options['mutateKey'] ?? null;

        $secret = $this->fetch($secretPath, $useCache);
        $applied = [];

        foreach ($secret as $key => $value) {
            if (!is_scalar($value) && $value !== null) {
                continue;
            }

            if ($prefix !== null) {
                if (!str_starts_with($key, $prefix)) {
                    continue;
                }

                $key = substr($key, strlen($prefix));
            }

            $envKey = $mutateKey !== null ? (string) $mutateKey($key) : $key;
            $envValue = (string) $value;

            if (!$override && $this->isAlreadySet($envKey)) {
                continue;
            }

            $this->apply($envKey, $envValue);
            $applied[$envKey] = $envValue;
        }

        $this->logger()->info(sprintf(
            'Loaded %d environment variable(s) from Vault secret "%s".',
            count($applied),
            $secretPath,
        ));

        return $applied;
    }

    /**
     * Clear the static in-process cache, e.g. between test cases.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $secretPath, bool $useCache): array
    {
        if ($useCache && isset(self::$cache[$secretPath])) {
            return self::$cache[$secretPath];
        }

        try {
            $secret = $this->kv->get($secretPath);
        } catch (VaultException $e) {
            $this->logger()->error(sprintf(
                'Failed to load environment secrets from Vault path "%s": %s',
                $secretPath,
                $e->getMessage(),
            ));

            throw $e;
        }

        if ($useCache) {
            self::$cache[$secretPath] = $secret;
        }

        return $secret;
    }

    private function isAlreadySet(string $key): bool
    {
        return getenv($key) !== false || array_key_exists($key, $_ENV) || array_key_exists($key, $_SERVER);
    }

    private function apply(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function logger(): LoggerInterface
    {
        return $this->logger ?? new NullLogger();
    }
}
