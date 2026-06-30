<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Http;

use GtelPhp\Vault\Contracts\HttpClientInterface;
use GtelPhp\Vault\Exceptions\ConnectionException;
use GtelPhp\Vault\Exceptions\VaultException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Default {@see HttpClientInterface} implementation backed by Guzzle.
 *
 * Handles base URI resolution, default + custom headers, an injectable
 * "current token" supplier (so callers never have to pass the token
 * themselves), retries with exponential backoff and structured logging.
 */
final class HttpClient implements HttpClientInterface
{
    private readonly GuzzleClient $guzzle;

    /** @var (callable(): ?string)|null */
    private ?\Closure $tokenProvider = null;

    /**
     * Reentrancy guard. AppRoleAuthenticator::login()/renew() use this same
     * HttpClient instance to call Vault's login/renew-self endpoints. Those
     * requests happen *while* the token provider closure (which calls
     * TokenManager::getToken()) is still resolving - without this guard,
     * buildHeaders() would call the token provider again for the login
     * request itself, which calls getToken() again, which logs in again,
     * forever. Login/renew requests never need a token header anyway, so
     * while a token is being resolved we simply skip looking one up.
     */
    private bool $isResolvingToken = false;

    /**
     * @param array<string, string> $defaultHeaders
     */
    public function __construct(
        private readonly string $baseUri,
        float $timeout = 5.0,
        int $maxRetries = 3,
        ?LoggerInterface $logger = null,
        private readonly array $defaultHeaders = [],
        ?GuzzleClient $guzzle = null,
        bool $verifyTls = true,
        private readonly ?string $namespace = null,
    ) {
        $logger ??= new NullLogger();

        if ($guzzle !== null) {
            $this->guzzle = $guzzle;

            return;
        }

        $stack = HandlerStack::create();
        $retry = new RetryMiddleware(maxRetries: $maxRetries, logger: $logger);
        $stack->push(Middleware::retry($retry->decider(), $retry->delay()));

        $this->guzzle = new GuzzleClient([
            'base_uri' => rtrim($baseUri, '/') . '/',
            'timeout' => $timeout,
            'connect_timeout' => $timeout,
            'handler' => $stack,
            'verify' => $verifyTls,
            'http_errors' => false,
        ]);
    }

    /**
     * Inject a callable returning the current Vault client token (or null
     * if unauthenticated yet). Called lazily on every request so token
     * renewal is always picked up automatically.
     *
     * @param callable(): ?string $provider
     */
    public function withTokenProvider(callable $provider): self
    {
        $this->tokenProvider = $provider instanceof \Closure ? $provider : \Closure::fromCallable($provider);

        return $this;
    }

    public function get(string $path, array $query = [], array $headers = []): array
    {
        return $this->request('GET', $path, ['query' => $query], $headers);
    }

    public function post(string $path, array $payload = [], array $headers = []): array
    {
        return $this->request('POST', $path, ['json' => $payload], $headers);
    }

    public function put(string $path, array $payload = [], array $headers = []): array
    {
        return $this->request('PUT', $path, ['json' => $payload], $headers);
    }

    public function delete(string $path, array $query = [], array $headers = []): array
    {
        return $this->request('DELETE', $path, ['query' => $query], $headers);
    }

    public function list(string $path, array $payload = [], array $headers = []): array
    {
        return $this->request('LIST', $path, ['json' => $payload], $headers);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options, array $headers): array
    {
        $options['headers'] = $this->buildHeaders($headers);

        try {
            $response = $this->guzzle->request($method, ltrim($path, '/'), $options);
        } catch (ConnectException $e) {
            throw new ConnectionException(
                sprintf('Could not connect to Vault/OpenBao at "%s": %s', $this->baseUri, $e->getMessage()),
                ['path' => $path],
                0,
                $e,
            );
        } catch (RequestException $e) {
            throw new ConnectionException(
                sprintf('HTTP transport error while calling Vault/OpenBao: %s', $e->getMessage()),
                ['path' => $path],
                0,
                $e,
            );
        } catch (GuzzleException $e) {
            throw new ConnectionException(
                sprintf('Unexpected transport error while calling Vault/OpenBao: %s', $e->getMessage()),
                ['path' => $path],
                0,
                $e,
            );
        }

        return $this->decode($response, $path);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response, string $path): array
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status === 204) {
            return [];
        }

        if ($status >= 400) {
            throw new VaultException(
                VaultErrorParser::message($body, $status),
                ['path' => $path, 'status' => $status, 'errors' => VaultErrorParser::parse($body)],
                $status,
            );
        }

        if ($body === '') {
            return [];
        }

        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new VaultException(
                sprintf('Vault returned a response that could not be decoded as JSON: %s', $e->getMessage()),
                ['path' => $path],
                0,
                $e,
            );
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private function buildHeaders(array $headers): array
    {
        $merged = array_merge([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], $this->defaultHeaders, $headers);

        $token = $this->resolveToken();

        if ($token !== null && $token !== '' && !isset($merged['X-Vault-Token'])) {
            $merged['X-Vault-Token'] = $token;
        }

        if ($this->namespace !== null && $this->namespace !== '' && !isset($merged['X-Vault-Namespace'])) {
            $merged['X-Vault-Namespace'] = $this->namespace;
        }

        return $merged;
    }

    private function resolveToken(): ?string
    {
        if ($this->tokenProvider === null || $this->isResolvingToken) {
            return null;
        }

        $this->isResolvingToken = true;

        try {
            return ($this->tokenProvider)();
        } finally {
            $this->isResolvingToken = false;
        }
    }
}
