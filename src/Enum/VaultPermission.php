<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Enum;

/**
 * Permission level granted on a shared vault resource.
 */
enum VaultPermission: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';

    /**
     * Whether the permission allows editing (write or admin).
     */
    public function allowsWrite(): bool
    {
        return $this === self::Write || $this === self::Admin;
    }

    /**
     * Whether the permission allows administration (admin only).
     */
    public function allowsAdmin(): bool
    {
        return $this === self::Admin;
    }
}
