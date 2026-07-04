<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Enum;

enum VaultResourceType: string
{
    case Item   = 'item';
    case Folder = 'folder';
}
