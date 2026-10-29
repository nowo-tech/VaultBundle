<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Enum;

/**
 * Kind of vault resource a grant applies to.
 */
enum VaultResourceType: string
{
    case Item   = 'item';
    case Folder = 'folder';
}
