<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\View;

use DateTimeImmutable;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Service\VaultGrantResult;

/**
 * Read model of one sharing grant for a host (2.1.0, REQ-ARCH-001 rules 1 and 3): ids, enums and dates only.
 *
 * `result` says what the call that returned it did (created, updated or unchanged); it is null in a listing.
 */
final readonly class VaultGrantView
{
    public function __construct(
        public string $id,
        public VaultResourceType $resourceType,
        public string $resourceId,
        public GranteeType $granteeType,
        public string $granteeId,
        public VaultPermission $permission,
        public ?DateTimeImmutable $expiresAt,
        public ?string $createdById,
        public DateTimeImmutable $createdAt,
        public ?VaultGrantResult $result = null,
    ) {
    }

    /**
     * Whether the grant is active at that moment (a permanent grant always is).
     */
    public function isActiveAt(DateTimeImmutable $now): bool
    {
        return !$this->expiresAt instanceof DateTimeImmutable || $this->expiresAt > $now;
    }
}
