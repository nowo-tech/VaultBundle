<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Enum;

enum GranteeType: string
{
    case User = 'user';
    case Team = 'team';
}
