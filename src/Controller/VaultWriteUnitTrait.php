<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Controller;

use Nowo\VaultBundle\Doctrine\VaultWriteUnit;

/**
 * 1.20 (REQ-RUNTIME-003 rule 1): runs one user action of a vault page in one transaction (the vault's write unit), or
 * directly when the controller was built without one (a host that constructs it itself).
 *
 * Requires the using class to declare `?VaultWriteUnit $writeUnit`.
 */
trait VaultWriteUnitTrait
{
    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function inWriteUnit(callable $operation): mixed
    {
        return $this->writeUnit instanceof VaultWriteUnit ? $this->writeUnit->run($operation) : $operation();
    }
}
