<?php

declare(strict_types=1);

namespace GtelPhp\Vault;

use GtelPhp\Vault\Auth\AppRoleAuthenticator;
use GtelPhp\Vault\Auth\MemoryTokenCache;
use GtelPhp\Vault\Auth\RedisTokenCache;
use GtelPhp\Vault\Auth\TokenManager;
use GtelPhp\Vault\Contracts\AuthenticatorInterface;
use GtelPhp\Vault\Contracts\HttpClientInterface;
use GtelPhp\Vault\Contracts\TokenCacheInterface;
use GtelPhp\Vault\Database\DatabaseSecrets;
use GtelPhp\Vault\Env\EnvLoader;
use GtelPhp\Vault\Http\HttpClient;
use GtelPhp\Vault\KV\KvV2;
use GtelPhp\Vault\PKI\Pki;
use GtelPhp\Vault\Support\VaultConfig;
use GtelPhp\Vault\Transit\Transit;
use Psr\Log\LoggerInterface;
use Redis;

final class Client
{
    private readonly TokenManager $tokenManager;

    private ?KvV2 $kv = null;
    private ?Transit $transit = null;
    private ?DatabaseSecrets $database = null;
    private ?Pki $pki = null;
    private ?EnvLoader $envLoader = null;

    public function __construct(
        private readonly VaultConfig $config,
        private readonly HttpClientInterface $http,
        ?AuthenticatorInterface $authenticator = null,
        ?TokenCacheInterface $tokenCache = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly Redis|\Psr\SimpleCache\CacheInterface|null $kvCache = null,
        private readonly Redis|\Psr\SimpleCache\CacheInterface|null $databaseCache = null,
    ) {
        $authenticator ??= new AppRoleAuthenticator(
            http: $this->http,
            roleId: $this->config->roleId,
            secretId: $this->config->secretId,
            mountPath: $this->config->authMount,
        );

        $tokenCache ??= new MemoryTokenCache();

        $this->tokenManager = new TokenManager(
            authenticator: $authenticator,
            cache: $tokenCache,
            cacheKey: $this->config->tokenCacheKey,
            renewThreshold: $this->config->tokenRenewThreshold,
            logger: $this->logger,
        );

        if ($this->http instanceof HttpClient) {
            $this->http->withTokenProvider(fn (): ?string => $this->tokenManager->getToken()->clientToken);
        }
    }

    public static function make(
        VaultConfig $config,
        ?LoggerInterface $logger = null,
        ?TokenCacheInterface $tokenCache = null,
        Redis|\Psr\SimpleCache\CacheInterface|null $redis = null,
    ): self {
        $http = new HttpClient(
            baseUri: $config->address,
            timeout: $config->timeout,
            maxRetries: $config->maxRetries,
            logger: $logger,
            defaultHeaders: $config->headers,
            verifyTls: $config->verifyTls,
            namespace: $config->namespace,
        );

        if ($tokenCache === null && $config->tokenCacheDriver === 'redis' && $redis !== null) {
            $tokenCache = new RedisTokenCache($redis);
        }

        $kvCache = $config->kvCacheEnabled ? $redis : null;
        $databaseCache = $config->databaseCacheEnabled ? $redis : null;

        return new self($config, $http, logger: $logger, tokenCache: $tokenCache, kvCache: $kvCache, databaseCache: $databaseCache);
    }

    public function kv(?string $mount = null): KvV2
    {
        if ($mount !== null) {
            return new KvV2($this->http, $mount, $this->kvCache, $this->config->kvCacheTtl);
        }

        return $this->kv ??= new KvV2($this->http, $this->config->kvMount, $this->kvCache, $this->config->kvCacheTtl);
    }

    public function transit(?string $mount = null): Transit
    {
        if ($mount !== null) {
            return new Transit($this->http, $mount);
        }

        return $this->transit ??= new Transit($this->http, $this->config->transitMount);
    }

    public function database(?string $mount = null): DatabaseSecrets
    {
        if ($mount !== null) {
            return new DatabaseSecrets($this->http, $mount, $this->databaseCache);
        }

        return $this->database ??= new DatabaseSecrets($this->http, $this->config->databaseMount, $this->databaseCache);
    }

    public function pki(?string $mount = null): Pki
    {
        if ($mount !== null) {
            return new Pki($this->http, $mount);
        }

        return $this->pki ??= new Pki($this->http, $this->config->pkiMount);
    }

    public function tokenManager(): TokenManager
    {
        return $this->tokenManager;
    }

    public function http(): HttpClientInterface
    {
        return $this->http;
    }

    public function login(): string
    {
        return $this->tokenManager->login()->clientToken;
    }

    public function loadEnv(string $secretPath = 'env', array $options = []): array
    {
        return $this->envLoader()->load($secretPath, $options);
    }

    public function bootstrap(string $secretPath = 'env', array $options = []): array
    {
        return $this->loadEnv($secretPath, $options);
    }

    private function envLoader(): EnvLoader
    {
        return $this->envLoader ??= new EnvLoader($this->kv(), $this->logger);
    }
}
