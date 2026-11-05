<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * CATALOGUE_ACCESS_CHECK (1.16.0): the host may grant or deny the metadata catalogue of a scope after the scope
 * resolver (`VaultScopeAccess::$catalogue`) decided. A non-member never reaches this event.
 */
final class VaultCatalogueAccessCheckEvent extends Event
{
    /**
     * Creates the event with the resolver's decision.
     */
    public function __construct(
        private readonly UserInterface $user,
        private readonly string $scope,
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
     * The scope key.
     */
    public function getScope(): string
    {
        return $this->scope;
    }

    /**
     * Allows the catalogue.
     */
    public function grant(): void
    {
        // @igor-ignore - Per-dispatch event object; not shared worker state.
        $this->granted = true;
    }

    /**
     * Refuses the catalogue.
     */
    public function deny(): void
    {
        // @igor-ignore - Per-dispatch event object; not shared worker state.
        $this->granted = false;
    }

    /**
     * Whether the viewer may browse the catalogue.
     */
    public function isGranted(): bool
    {
        return $this->granted;
    }
}
