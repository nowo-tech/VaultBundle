<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App\Ports;

use LogicException;
use Nowo\VaultBundle\Dto\VaultHealthReport;
use Nowo\VaultBundle\Dto\VaultPasswordPolicy;
use Nowo\VaultBundle\Service\VaultHealthReportProviderInterface;
use Nowo\VaultBundle\ValueObject\VaultUserRef;

/**
 * A host replacement of the health report (a zero-knowledge client strategy, for example), for the wiring tests only:
 * never asked.
 */
final class HostHealthReports implements VaultHealthReportProviderInterface
{
    public function reportForScope(VaultUserRef $viewer, string $scope, ?VaultPasswordPolicy $policy = null): VaultHealthReport
    {
        throw new LogicException('Not asked by the wiring tests.');
    }

    public function reportForPersonalVault(VaultUserRef $owner, ?VaultPasswordPolicy $policy = null): VaultHealthReport
    {
        throw new LogicException('Not asked by the wiring tests.');
    }
}
