<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Internal;

/**
 * The transaction nesting level of the vault's connection right now (2.1.0): 0 outside a transaction. Implemented by
 * {@see \Nowo\VaultBundle\Doctrine\VaultEntityManager}; read by {@see DeferredVaultEventDispatcher}.
 *
 * @internal
 */
interface VaultTransactionLevelInterface
{
    public function transactionLevel(): int;
}
