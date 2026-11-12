<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Support\RepoFactory;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The service repository of {@see HostThing}.
 *
 * @extends ServiceEntityRepository<HostThing>
 */
class HostThingRepository extends ServiceEntityRepository
{
    /**
     * Creates the repository.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HostThing::class);
    }
}
