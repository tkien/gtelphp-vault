<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Auth;

/**
 * Immutable value object representing a Vault client token together with
 * the metadata needed to know when/whether it should be renewed.
 */
final class VaultToken
{
    public function __construct(
        public readonly string $clientToken,
        public readonly int $leaseDuration,
        public readonly bool $renewable,
        public readonly int $issuedAt,
        /** @var string[] */
        public readonly array $policies = [],
        public readonly ?string $accessor = null,
    ) {
    }

    /**
     * @param array<string, mixed> $authData The "auth" object from a Vault login/renew response.
     */
    public static function fromAuthResponse(array $authData): self
    {
        return new self(
            clientToken: (string) ($authData['client_token'] ?? ''),
            leaseDuration: (int) ($authData['lease_duration'] ?? 0),
            renewable: (bool) ($authData['renewable'] ?? false),
            issuedAt: time(),
            policies: array_map('strval', $authData['policies'] ?? $authData['token_policies'] ?? []),
            accessor: isset($authData['accessor']) ? (string) $authData['accessor'] : null,
        );
    }

    public function expiresAt(): int
    {
        if ($this->leaseDuration <= 0) {
            return PHP_INT_MAX; // root / non-expiring tokens
        }

        return $this->issuedAt + $this->leaseDuration;
    }

    public function isExpired(?int $now = null): bool
    {
        $now ??= time();

        return $now >= $this->expiresAt();
    }

    /**
     * True once the token has used up the given fraction of its TTL, e.g.
     * 0.7 means "renew once 70% of the lease has elapsed".
     */
    public function shouldRenew(float $thresholdRatio = 0.7, ?int $now = null): bool
    {
        if (!$this->renewable || $this->leaseDuration <= 0) {
            return false;
        }

        $now ??= time();
        $elapsed = $now - $this->issuedAt;

        return $elapsed >= ($this->leaseDuration * $thresholdRatio);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'client_token' => $this->clientToken,
            'lease_duration' => $this->leaseDuration,
            'renewable' => $this->renewable,
            'issued_at' => $this->issuedAt,
            'policies' => $this->policies,
            'accessor' => $this->accessor,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            clientToken: (string) ($data['client_token'] ?? ''),
            leaseDuration: (int) ($data['lease_duration'] ?? 0),
            renewable: (bool) ($data['renewable'] ?? false),
            issuedAt: (int) ($data['issued_at'] ?? time()),
            policies: array_map('strval', $data['policies'] ?? []),
            accessor: isset($data['accessor']) ? (string) $data['accessor'] : null,
        );
    }
}
