<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Nowo\VaultBundle\Dto\VaultErasureResult;

/**
 * GDPR erasure of one user's vault data by id (1.19.0, REQ-ARCH-001 rule 1): the port your "user deleted" handler
 * calls. Implemented by {@see VaultPersonalDataEraser}.
 */
interface VaultPersonalDataEraserInterface
{
    /**
     * Erases the user's vault data (idempotent); call it before deleting the user row.
     */
    public function erase(string $userId, int $batchSize = 500): VaultErasureResult;
}
