<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use Nowo\VaultBundle\Enum\VaultPermission;

/**
 * A user's standing in a host scope (1.16.0), returned by {@see VaultScopeAccessResolverInterface::resolve()}.
 */
final readonly class VaultScopeAccess
{
    /**
     * @param VaultPermission|null $baseline permission on every item / folder of the scope; null = explicit grants only
     * @param bool $catalogue may browse the metadata-only catalogue
     * @param bool $health may run the password health report
     * @param bool $create may create items, folders and tags in the scope
     */
    public function __construct(
        public ?VaultPermission $baseline,
        public bool $catalogue = false,
        public bool $health = false,
        public bool $create = false,
    ) {
    }
}
