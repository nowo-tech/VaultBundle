<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Support\RepoFactory;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * A filter that adds no constraint (audit NZ-L1 tests).
 */
final class FilterA extends SQLFilter
{
    /**
     * No constraint.
     */
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        return '';
    }
}
