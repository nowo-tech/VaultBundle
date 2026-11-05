<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Exception;

use Nowo\VaultBundle\Service\VaultGrantService;
use RuntimeException;

/**
 * A grant request refused by {@see VaultGrantService::grantAs()} or revokeAs(). Carries a
 * reason code only (no titles, no ids of other tenants).
 */
final class VaultGrantRefusedException extends RuntimeException
{
    public const ACTOR_CANNOT_SHARE            = 'actor_cannot_share';
    public const RESOURCE_NOT_FOUND            = 'resource_not_found';
    public const SELF_GRANT                    = 'self_grant';
    public const GRANTEE_NOT_ALLOWED           = 'grantee_not_allowed';
    public const GRANTEE_NOT_IN_SCOPE          = 'grantee_not_in_scope';
    public const EXPIRY_OUT_OF_RANGE           = 'expiry_out_of_range';
    public const TEMPORARY_PERMISSION_TOO_HIGH = 'temporary_permission_too_high';
    public const WOULD_REPLACE_PERMANENT       = 'would_replace_permanent';
    public const POLICY_DENIED                 = 'policy_denied';

    /**
     * Creates the exception for a reason code.
     */
    public function __construct(private readonly string $reason)
    {
        parent::__construct('Vault grant refused: ' . $reason . '.');
    }

    /**
     * The reason code (one of the class constants, or a host code from GRANT_POLICY_CHECK).
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
