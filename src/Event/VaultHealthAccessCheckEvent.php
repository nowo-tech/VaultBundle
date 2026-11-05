<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * HEALTH_ACCESS_CHECK (1.16.0): the host may grant or deny a scope health report after the scope resolver
 * (`VaultScopeAccess::$health`) decided.
 */
final class VaultHealthAccessCheckEvent extends Event
{
    /**
     * Creates the event with the resolver's decision.
     */
    public function __construct(
        private readonly UserInterface $user,
        private readonly string $ownerKey,
        private bool $granted,
    ) {
    }

    /**
     * The viewer.
     */
    public function getUser(): UserInterface
    {
        return $this->user;
    }

    /**
     * Owner key (`scope:<key>`).
     */
    public function getOwnerKey(): string
    {
        return $this->ownerKey;
    }

    /**
     * Allows the report.
     */
    public function grant(): void
    {
        // @igor-ignore - Per-dispatch event object; not shared worker state.
        $this->granted = true;
    }

    /**
     * Refuses the report.
     */
    public function deny(): void
    {
        // @igor-ignore - Per-dispatch event object; not shared worker state.
        $this->granted = false;
    }

    /**
     * Whether the viewer may run the report.
     */
    public function isGranted(): bool
    {
        return $this->granted;
    }
}
