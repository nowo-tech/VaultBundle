<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

/**
 * Action checked by vault access events.
 */
enum VaultAccessAction: string
{
    case View    = 'view';
    case Edit    = 'edit';
    case Delete  = 'delete';
    case Restore = 'restore';
    case Share   = 'share';
    case Purge   = 'purge';

    /**
     * Whether the action is the read-only view action.
     */
    public function isViewOnly(): bool
    {
        return $this === self::View;
    }
}
