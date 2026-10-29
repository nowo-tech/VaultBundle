<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Dto;

use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultPermission;

/**
 * Form data object for sharing a vault item or folder with a grantee.
 */
final class VaultShareFormData
{
    public GranteeType $granteeType    = GranteeType::User;
    public string $granteeId           = '';
    public VaultPermission $permission = VaultPermission::Read;
}
