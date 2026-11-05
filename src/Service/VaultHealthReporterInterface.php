<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Nowo\VaultBundle\Dto\VaultHealthReport;
use Nowo\VaultBundle\Dto\VaultPasswordPolicy;
use Nowo\VaultBundle\Exception\VaultAccessDeniedException;
use Nowo\VaultBundle\Exception\VaultHealthTooLargeException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Password health report (1.16.0, gap 5). The server strategy decrypts in memory today; a zero-knowledge client
 * strategy can later compute the same report in the browser: the report DTO is the contract, not the computation.
 */
interface VaultHealthReporterInterface
{
    /**
     * Report over the scope's login items the viewer may View (reuse compared within the scope only).
     *
     * @throws VaultAccessDeniedException when the viewer has no `health` in the scope or HEALTH_ACCESS_CHECK denies
     * @throws VaultHealthTooLargeException above `health.max_items`
     */
    public function forScope(UserInterface $viewer, string $scope, ?VaultPasswordPolicy $policy = null): VaultHealthReport;

    /**
     * Report over the owner's personal login items.
     *
     * @throws VaultHealthTooLargeException above `health.max_items`
     */
    public function forPersonalVault(UserInterface $owner, ?VaultPasswordPolicy $policy = null): VaultHealthReport;
}
