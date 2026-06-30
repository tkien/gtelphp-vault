<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Laravel\Facades;

use GtelPhp\Vault\Client;
use GtelPhp\Vault\Database\DatabaseSecrets;
use GtelPhp\Vault\KV\KvV2;
use GtelPhp\Vault\Manager;
use GtelPhp\Vault\PKI\Pki;
use GtelPhp\Vault\Transit\Transit;
use Illuminate\Support\Facades\Facade;

/**
 * @method static KvV2 kv(?string $mount = null)
 * @method static Transit transit(?string $mount = null)
 * @method static DatabaseSecrets database(?string $mount = null)
 * @method static Pki pki(?string $mount = null)
 * @method static string login()
 * @method static array<string, string> loadEnv(string $secretPath = 'env', array $options = [])
 * @method static array<string, string> bootstrap(string $secretPath = 'env', array $options = [])
 * @method static Client connection(?string $name = null)
 *
 * @see Client
 * @see Manager
 */
final class Vault extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Manager::class;
    }
}
