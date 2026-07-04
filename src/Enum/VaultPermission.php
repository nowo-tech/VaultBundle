<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Enum;

enum VaultPermission: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';

    public function allowsWrite(): bool
    {
        return $this === self::Write || $this === self::Admin;
    }

    public function allowsAdmin(): bool
    {
        return $this === self::Admin;
    }
}
