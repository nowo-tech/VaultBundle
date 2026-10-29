<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use Nowo\VaultBundle\Entity\VaultItem;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched before listing vault items; listeners may change the list subject or override the result.
 */
final class VaultItemListQueryEvent extends Event
{
    private bool $hasOverride = false;

    /** @var list<VaultItem> */
    private array $overrideItems = [];

    private int $overrideTotal = 0;

    /**
     * Creates the event for a viewer and the list filters.
     */
    public function __construct(
        private readonly UserInterface $viewer,
        private UserInterface $listSubject,
        private readonly ?string $folderId = null,
        private readonly bool $trashOnly = false,
        private readonly bool $sharedOnly = false,
    ) {
    }

    /**
     * Returns the user viewing the list.
     */
    public function getViewer(): UserInterface
    {
        return $this->viewer;
    }

    /**
     * Returns the user whose items are listed.
     */
    public function getListSubject(): UserInterface
    {
        return $this->listSubject;
    }

    /**
     * Changes the user whose items are listed.
     */
    public function setListSubject(UserInterface $listSubject): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->listSubject = $listSubject;
    }

    /**
     * Returns the folder filter, or null.
     */
    public function getFolderId(): ?string
    {
        return $this->folderId;
    }

    /**
     * Whether only trashed items are listed.
     */
    public function isTrashOnly(): bool
    {
        return $this->trashOnly;
    }

    /**
     * Whether only items shared with the viewer are listed.
     */
    public function isSharedOnly(): bool
    {
        return $this->sharedOnly;
    }

    /**
     * Whether a listener overrode the result.
     */
    public function hasOverride(): bool
    {
        return $this->hasOverride;
    }

    /**
     * Returns the override items.
     *
     * @return list<VaultItem>
     */
    public function getOverrideItems(): array
    {
        return $this->overrideItems;
    }

    /**
     * Returns the override total.
     */
    public function getOverrideTotal(): int
    {
        return $this->overrideTotal;
    }

    /**
     * Overrides the list result with the given items and total.
     *
     * @param list<VaultItem> $items
     */
    public function overrideResult(array $items, int $total): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->hasOverride = true;
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->overrideItems = $items;
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->overrideTotal = $total;
    }
}
