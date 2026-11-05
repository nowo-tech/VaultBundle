<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Dto;

use DateInterval;
use DateTimeImmutable;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultPermission;

/**
 * Data of the share form: grantee, permission and (1.16.0, `grants.temporary.ui`) an optional expiry.
 */
final class VaultShareFormData
{
    /** Expiry choices of the share form (ISO 8601 durations); `custom` uses {@see self::$expiresAt}. */
    public const EXPIRY_CHOICES = ['PT1H', 'P1D', 'P7D', 'custom'];

    public GranteeType $granteeType    = GranteeType::User;
    public string $granteeId           = '';
    public VaultPermission $permission = VaultPermission::Read;

    /** One of {@see self::EXPIRY_CHOICES}, or null / '' for a permanent grant (1.16.0). */
    public ?string $expiresIn = null;

    /** Custom expiry when $expiresIn is `custom` (1.16.0). */
    public ?DateTimeImmutable $expiresAt = null;

    /**
     * The requested expiry at `$now`, or null for a permanent grant.
     */
    public function resolveExpiresAt(DateTimeImmutable $now): ?DateTimeImmutable
    {
        if ($this->expiresIn === null || $this->expiresIn === '') {
            return null;
        }

        if ($this->expiresIn === 'custom') {
            return $this->expiresAt;
        }

        return $now->add(new DateInterval($this->expiresIn));
    }
}
