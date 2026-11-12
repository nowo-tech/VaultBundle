<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Support\VaultUserRefs;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched after listing vault items; listeners may filter the items and total.
 */
final class VaultItemListResultEvent extends Event
{
    /**
     * Creates the event with the listed items and total.
     *
     * @param list<VaultItem> $items
     */
    public function __construct(
        private readonly UserInterface $viewer,
        private array $items,
        private int $total,
    ) {
    }

    /**
     * Returns the user viewing the list.
     *
     * @deprecated since 1.19.0, use getViewerRef() / getViewerId() (no Symfony type, REQ-ARCH-001 rule 3); removed in 2.0
     */
    public function getViewer(): UserInterface
    {
        return $this->viewer;
    }

    /**
     * Returns the listed items.
     *
     * @return list<VaultItem>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * Replaces the listed items.
     *
     * @param list<VaultItem> $items
     */
    public function setItems(array $items): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->items = $items;
    }

    /**
     * Returns the total item count.
     */
    public function getTotal(): int
    {
        return $this->total;
    }

    /**
     * Replaces the total item count.
     */
    public function setTotal(int $total): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->total = $total;
    }

    /**
     * The viewer as a framework-free reference (1.19.0, REQ-ARCH-001 rules 3 and 4).
     */
    public function getViewerRef(): VaultUserRef
    {
        return VaultUserRefs::fromUser($this->viewer);
    }

    /**
     * The viewer's vault user id, or null when it has none (1.19.0).
     */
    public function getViewerId(): ?string
    {
        return $this->getViewerRef()->id;
    }

    /**
     * Ids of the listed items, in order (1.19.0, REQ-ARCH-001 rule 4).
     *
     * @return list<string>
     */
    public function getItemIds(): array
    {
        $ids = [];
        foreach ($this->items as $item) {
            $ids[] = $item->getId();
        }

        return $ids;
    }
}
