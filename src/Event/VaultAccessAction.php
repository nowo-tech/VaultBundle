<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

enum VaultAccessAction: string
{
    case View    = 'view';
    case Edit    = 'edit';
    case Delete  = 'delete';
    case Restore = 'restore';
    case Share   = 'share';
    case Purge   = 'purge';

    public function isViewOnly(): bool
    {
        return $this === self::View;
    }
}
