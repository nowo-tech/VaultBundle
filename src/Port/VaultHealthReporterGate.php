<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Port;

use Nowo\VaultBundle\Dto\VaultHealthReport;
use Nowo\VaultBundle\Dto\VaultPasswordPolicy;
use Nowo\VaultBundle\Service\VaultHealthReportProviderInterface;
use Nowo\VaultBundle\Support\VaultUserRefs;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The health report the scope pages ask: {@see VaultHealthReportProviderInterface} (the bundle's reporter, or your
 * alias of the interface).
 *
 * Since 2.0 the only interface asked (the deprecated `VaultHealthReporterInterface` was removed).
 *
 * @internal
 */
final readonly class VaultHealthReporterGate
{
    /**
     * Wraps the health report provider the build picked.
     */
    public function __construct(private VaultHealthReportProviderInterface $policy)
    {
    }

    /**
     * Health report of a scope vault for the viewer.
     */
    public function forScope(UserInterface $viewer, string $scope, ?VaultPasswordPolicy $policy = null): VaultHealthReport
    {
        return $this->policy->reportForScope(VaultUserRefs::fromUser($viewer), $scope, $policy);
    }

    /**
     * Health report of the personal vault of the owner.
     */
    public function forPersonalVault(UserInterface $owner, ?VaultPasswordPolicy $policy = null): VaultHealthReport
    {
        return $this->policy->reportForPersonalVault(VaultUserRefs::fromUser($owner), $policy);
    }
}
