<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Laravel\Commands;

use GtelPhp\Vault\Manager;
use Illuminate\Console\Command;
use Throwable;

/**
 * `php artisan vault:env:pull`
 *
 * Fetches a KV v2 secret from Vault and either prints it (for inspection)
 * or writes it out as a `.env`-formatted file - handy for local
 * development against a real Vault/OpenBao dev server.
 */
final class VaultEnvPullCommand extends Command
{
    protected $signature = 'vault:env:pull
        {path? : The KV v2 secret path to read (defaults to vault.env.path config)}
        {--connection= : The Vault connection to use}
        {--write= : Write the result to this file instead of printing it}
        {--force : Overwrite the destination file if it already exists}';

    protected $description = 'Pull secrets from Vault and print or write them in .env format';

    public function handle(Manager $manager): int
    {
        $path = $this->argument('path') ?? config('vault.env.path', 'env');
        $connection = $this->option('connection');

        try {
            $secret = $manager->connection($connection)->kv()->get($path);
        } catch (Throwable $e) {
            $this->components->error(sprintf('Failed to read secret "%s": %s', $path, $e->getMessage()));

            return self::FAILURE;
        }

        $lines = [];
        foreach ($secret as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $lines[] = sprintf('%s=%s', $key, $this->quoteIfNeeded((string) $value));
        }
        $contents = implode(PHP_EOL, $lines) . PHP_EOL;

        $writeTo = $this->option('write');

        if ($writeTo === null) {
            $this->line($contents);

            return self::SUCCESS;
        }

        if (file_exists($writeTo) && !$this->option('force')) {
            $this->components->error(sprintf('File "%s" already exists. Use --force to overwrite.', $writeTo));

            return self::FAILURE;
        }

        file_put_contents($writeTo, $contents);
        $this->components->info(sprintf('Wrote %d variable(s) to %s', count($lines), $writeTo));

        return self::SUCCESS;
    }

    private function quoteIfNeeded(string $value): string
    {
        if ($value === '' || preg_match('/\s|#|"/', $value) === 1) {
            return '"' . str_replace('"', '\"', $value) . '"';
        }

        return $value;
    }
}
