<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Support;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Nowo\VaultBundle\Entity\VaultItem;

/**
 * A host SQL filter (tenant style) on the vault items' owner scope, for the private entity manager filter tests.
 */
final class OwnerScopeFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if ($targetEntity->getName() !== VaultItem::class || !$this->hasParameter('scope')) {
            return '';
        }

        return $targetTableAlias . '.owner_scope = ' . $this->getParameter('scope');
    }
}
