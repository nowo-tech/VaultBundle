<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Dto;

use Nowo\VaultBundle\Enum\GranteeType;

/**
 * A user or team/group that may receive access on an item or folder.
 *
 * Team and group of people are the same concept ({@see GranteeType::Team}).
 */
final readonly class VaultGranteeChoice
{
    public function __construct(
        public GranteeType $type,
        public string $id,
        public string $label,
    ) {
    }

    public function getKey(): string
    {
        return self::buildKey($this->type, $this->id);
    }

    public static function buildKey(GranteeType $type, string $id): string
    {
        return $type->value . ':' . $id;
    }
}
