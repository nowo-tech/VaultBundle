<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Support\RepoFactory;

use Doctrine\ORM\Mapping as ORM;

/**
 * A host entity mapped on the application's manager with a service repository (audit NZ-M1).
 */
#[ORM\Entity(repositoryClass: HostThingRepository::class)]
#[ORM\Table(name: 'nxrf_host_thing')]
class HostThing
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id = 1;

    /**
     * The identifier.
     */
    public function getId(): int
    {
        return $this->id;
    }
}
