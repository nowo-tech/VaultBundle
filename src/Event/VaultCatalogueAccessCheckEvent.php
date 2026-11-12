<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use Nowo\VaultBundle\Support\VaultUserRefs;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
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
     *
     * @deprecated since 1.19.0, use getUserRef() / getUserId() (no Symfony type, REQ-ARCH-001 rule 3); removed in 2.0
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

    /**
     * The user as a framework-free reference (1.19.0, REQ-ARCH-001 rules 3 and 4).
     */
    public function getUserRef(): VaultUserRef
    {
        return VaultUserRefs::fromUser($this->user);
    }

    /**
     * The user's vault user id, or null when it has none (1.19.0).
     */
    public function getUserId(): ?string
    {
        return $this->getUserRef()->id;
    }
}
