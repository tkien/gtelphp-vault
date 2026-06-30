<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base exception for every error raised by this SDK. Catch this type if you
 * want a single catch-all for anything Vault related.
 */
class VaultException extends RuntimeException
{
    /** @var array<string, mixed> */
    protected array $context;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(string $message = '', array $context = [], int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
