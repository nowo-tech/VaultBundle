<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\Worker;

use Nowo\VaultBundle\Doctrine\VaultEntityManager;

/**
 * What the test commands share with the test (no static state).
 */
final class ConsoleProbe
{
    public ?bool $kept = null;

    public function __construct(public readonly VaultEntityManager $manager)
    {
    }
}
