<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds the Guzzle "retry" middleware decider + delay callables used by
 * {@see HttpClient}. Retries on connection errors and on 5xx / 429
 * responses, using exponential backoff with jitter.
 */
final class RetryMiddleware
{
    public function __construct(
        private readonly int $maxRetries = 3,
        private readonly int $baseDelayMs = 200,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Returns the "decider" callable expected by GuzzleHttp\Middleware::retry().
     *
     * @return callable(int, RequestInterface, ?ResponseInterface, ?Throwable): bool
     */
    public function decider(): callable
    {
        return function (
            int $retries,
            RequestInterface $request,
            ?ResponseInterface $response = null,
            ?Throwable $exception = null,
        ): bool {
            if ($retries >= $this->maxRetries) {
                return false;
            }

            $shouldRetry = false;

            if ($exception !== null) {
                $shouldRetry = true;
            } elseif ($response !== null) {
                $status = $response->getStatusCode();
                $shouldRetry = $status === 429 || $status >= 500;
            }

            if ($shouldRetry) {
                $this->logger?->warning('Vault HTTP request failed, retrying.', [
                    'attempt' => $retries + 1,
                    'uri' => (string) $request->getUri(),
                    'status' => $response?->getStatusCode(),
                    'error' => $exception?->getMessage(),
                ]);
            }

            return $shouldRetry;
        };
    }

    /**
     * Returns the "delay" callable expected by GuzzleHttp\Middleware::retry().
     * Exponential backoff with +/-20% jitter, capped at 10 seconds.
     *
     * @return callable(int, ?ResponseInterface): int
     */
    public function delay(): callable
    {
        return function (int $retries, ?ResponseInterface $response = null): int {
            if ($response !== null && $response->hasHeader('Retry-After')) {
                $retryAfter = $response->getHeaderLine('Retry-After');
                if (is_numeric($retryAfter)) {
                    return (int) $retryAfter * 1000;
                }
            }

            $exponential = $this->baseDelayMs * (2 ** $retries);
            $jitter = (int) ($exponential * (mt_rand(-20, 20) / 100));

            return min($exponential + $jitter, 10_000);
        };
    }
}
