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
    /**
     * Creates a grantee choice (user or team) with its display label.
     */
    public function __construct(
        public GranteeType $type,
        public string $id,
        public string $label,
    ) {
    }

    /**
     * Returns the choice key in the form type:id.
     */
    public function getKey(): string
    {
        return self::buildKey($this->type, $this->id);
    }

    /**
     * Builds the type:id key for a grantee.
     */
    public static function buildKey(GranteeType $type, string $id): string
    {
        return $type->value . ':' . $id;
    }
}
