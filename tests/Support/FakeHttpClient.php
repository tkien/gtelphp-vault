<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Tests\Support;

use GtelPhp\Vault\Contracts\HttpClientInterface;
use GtelPhp\Vault\Exceptions\VaultException;

/**
 * In-memory fake for {@see HttpClientInterface} used throughout the unit
 * test suite. Lets tests queue up canned responses (or exceptions) per
 * HTTP verb + path and assert on what was actually sent, without any
 * real network access or a running Vault server.
 */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var array<int, array{method: string, path: string, payload: array<string, mixed>, headers: array<string, string>}> */
    public array $calls = [];

    /** @var array<string, array<string, mixed>|VaultException> */
    private array $responses = [];

    /**
     * @param array<string, mixed>|VaultException $response
     */
    public function queue(string $method, string $path, array|VaultException $response): void
    {
        $this->responses[$this->key($method, $path)] = $response;
    }

    public function get(string $path, array $query = [], array $headers = []): array
    {
        return $this->resolve('GET', $path, $query, $headers);
    }

    public function post(string $path, array $payload = [], array $headers = []): array
    {
        return $this->resolve('POST', $path, $payload, $headers);
    }

    public function put(string $path, array $payload = [], array $headers = []): array
    {
        return $this->resolve('PUT', $path, $payload, $headers);
    }

    public function delete(string $path, array $query = [], array $headers = []): array
    {
        return $this->resolve('DELETE', $path, $query, $headers);
    }

    public function list(string $path, array $payload = [], array $headers = []): array
    {
        return $this->resolve('LIST', $path, $payload, $headers);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private function resolve(string $method, string $path, array $payload, array $headers): array
    {
        $this->calls[] = ['method' => $method, 'path' => $path, 'payload' => $payload, 'headers' => $headers];

        $key = $this->key($method, $path);

        if (!isset($this->responses[$key])) {
            throw new \RuntimeException(sprintf('No fake response queued for %s %s', $method, $path));
        }

        $response = $this->responses[$key];

        if ($response instanceof VaultException) {
            throw $response;
        }

        return $response;
    }

    private function key(string $method, string $path): string
    {
        return $method . ' ' . $path;
    }
}
