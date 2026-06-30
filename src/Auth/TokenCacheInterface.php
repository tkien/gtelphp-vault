<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Auth;

use GtelPhp\Vault\Contracts\TokenCacheInterface as BaseTokenCacheInterface;

/**
 * Convenience re-export so token cache implementations can be type-hinted
 * as `GtelPhp\Vault\Auth\TokenCacheInterface` (matching the rest of the
 * Auth/ namespace) while the canonical contract lives in Contracts/,
 * alongside the SDK's other interfaces.
 */
interface TokenCacheInterface extends BaseTokenCacheInterface
{
}
