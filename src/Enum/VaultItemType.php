<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Enum;

/**
 * Supported vault item types.
 */
enum VaultItemType: string
{
    case Login          = 'login';
    case SecureNote     = 'secure_note';
    case CreditCard     = 'credit_card';
    case Contact        = 'contact';
    case IdCard         = 'id_card';
    case DriversLicense = 'drivers_license';
    case Passport       = 'passport';
    case Document       = 'document';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }

    /**
     * @return list<VaultItemType>
     */
    public static function documentTypes(): array
    {
        return [
            self::IdCard,
            self::DriversLicense,
            self::Passport,
            self::Document,
        ];
    }
}
