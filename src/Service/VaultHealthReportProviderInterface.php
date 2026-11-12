<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Nowo\VaultBundle\Dto\VaultHealthReport;
use Nowo\VaultBundle\Dto\VaultPasswordPolicy;
use Nowo\VaultBundle\Exception\VaultAccessDeniedException;
use Nowo\VaultBundle\Exception\VaultHealthThrottledException;
use Nowo\VaultBundle\Exception\VaultHealthTooLargeException;
use Nowo\VaultBundle\ValueObject\VaultUserRef;

/**
 * Password health report (1.19.0): the framework-free twin of the deprecated {@see VaultHealthReporterInterface}
 * (REQ-ARCH-001 rule 3). Implemented by the bundle ({@see VaultHealthReporter}); call it from your code with
 * {@see VaultUserRef} (e.g. `VaultUserRef::ofId($userId)`), or alias it to your own implementation (a zero-knowledge
 * client strategy).
 */
interface VaultHealthReportProviderInterface
{
    /**
     * Report over the scope's login items the viewer may View (reuse compared within the scope only).
     *
     * @throws VaultAccessDeniedException when the viewer has no `health` in the scope or HEALTH_ACCESS_CHECK denies
     * @throws VaultHealthTooLargeException above `health.max_items`
     * @throws VaultHealthThrottledException within `health.min_interval_seconds` of the viewer's last computed report
     */
    public function reportForScope(VaultUserRef $viewer, string $scope, ?VaultPasswordPolicy $policy = null): VaultHealthReport;

    /**
     * Report over the owner's personal login items.
     *
     * @throws VaultAccessDeniedException when the reference stands for no vault user
     * @throws VaultHealthTooLargeException above `health.max_items`
     * @throws VaultHealthThrottledException within `health.min_interval_seconds` of the owner's last computed report
     */
    public function reportForPersonalVault(VaultUserRef $owner, ?VaultPasswordPolicy $policy = null): VaultHealthReport;
}
