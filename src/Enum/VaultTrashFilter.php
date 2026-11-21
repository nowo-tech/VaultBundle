<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Enum;

use Nowo\VaultBundle\Service\VaultItemQueryInterface;

/**
 * Which items a {@see VaultItemQueryInterface} query returns (2.1.0): active, trashed or both.
 */
enum VaultTrashFilter: string
{
    /** Items not in the trash (the default). */
    case Active = 'active';

    /** Items in the trash only. */
    case Trashed = 'trashed';

    /** Active and trashed items. */
    case Any = 'any';
}
