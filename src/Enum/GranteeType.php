<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Enum;

/**
 * Kind of grantee a vault resource can be shared with.
 */
enum GranteeType: string
{
    case User = 'user';
    case Team = 'team';
}
