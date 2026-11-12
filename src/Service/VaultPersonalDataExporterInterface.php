<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

/**
 * GDPR access export of one user's vault metadata by id (1.19.0, REQ-ARCH-001 rule 1): never a payload value, a
 * ciphertext or a hash. Implemented by {@see VaultPersonalDataExporter}.
 */
interface VaultPersonalDataExporterInterface
{
    /**
     * The user's vault metadata.
     *
     * @return array<string, mixed>
     */
    public function export(string $userId): array;
}
