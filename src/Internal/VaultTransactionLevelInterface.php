<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Internal;

use Nowo\VaultBundle\Doctrine\VaultEntityManager;

/**
 * The transaction nesting level of the vault's connection right now (2.1.0): 0 outside a transaction. Implemented by
 * {@see VaultEntityManager}; read by {@see DeferredVaultEventDispatcher}.
 *
 * @internal
 */
interface VaultTransactionLevelInterface
{
    public function transactionLevel(): int;
}
