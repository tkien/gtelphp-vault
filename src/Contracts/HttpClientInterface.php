<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Contracts;

/**
 * Low level HTTP transport contract used to talk to Vault / OpenBao.
 *
 * Implementations are responsible for retries, backoff, headers and
 * (de)serialisation of JSON payloads. Everything above this layer only
 * ever deals with plain PHP arrays.
 */
interface HttpClientInterface
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = [], array $headers = []): array;

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload = [], array $headers = []): array;

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    public function put(string $path, array $payload = [], array $headers = []): array;

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    public function delete(string $path, array $query = [], array $headers = []): array;

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    public function list(string $path, array $payload = [], array $headers = []): array;
}
