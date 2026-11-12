<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Port;

use LogicException;
use Nowo\VaultBundle\Dto\VaultHealthReport;
use Nowo\VaultBundle\Dto\VaultPasswordPolicy;
use Nowo\VaultBundle\Service\VaultHealthReporterInterface;
use Nowo\VaultBundle\Service\VaultHealthReportProviderInterface;
use Nowo\VaultBundle\Support\VaultUserRefs;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The health report the scope pages ask (1.19.0): {@see VaultHealthReportProviderInterface} (the bundle's reporter or
 * your replacement), or the deprecated {@see VaultHealthReporterInterface} when you replaced or decorated that one,
 * exactly as in 1.18. Never both: the build picks one.
 *
 * @internal
 */
final readonly class VaultHealthReporterGate implements VaultHealthReporterInterface
{
    public function __construct(
        private string $mode,
        private ?VaultHealthReporterInterface $legacy = null,
        private ?VaultHealthReportProviderInterface $policy = null,
    ) {
        VaultPortMode::assert($mode === VaultPortMode::BOTH ? '' : $mode, $legacy, $policy, 'health reporter');
    }

    public function forScope(UserInterface $viewer, string $scope, ?VaultPasswordPolicy $policy = null): VaultHealthReport
    {
        if ($this->legacy instanceof VaultHealthReporterInterface && $this->mode === VaultPortMode::LEGACY) {
            return $this->legacy->forScope($viewer, $scope, $policy);
        }

        return $this->provider()->reportForScope(VaultUserRefs::fromUser($viewer), $scope, $policy);
    }

    public function forPersonalVault(UserInterface $owner, ?VaultPasswordPolicy $policy = null): VaultHealthReport
    {
        if ($this->legacy instanceof VaultHealthReporterInterface && $this->mode === VaultPortMode::LEGACY) {
            return $this->legacy->forPersonalVault($owner, $policy);
        }

        return $this->provider()->reportForPersonalVault(VaultUserRefs::fromUser($owner), $policy);
    }

    private function provider(): VaultHealthReportProviderInterface
    {
        return $this->policy ?? throw new LogicException('No 1.19 health report provider is wired.'); // @codeCoverageIgnore
    }
}
